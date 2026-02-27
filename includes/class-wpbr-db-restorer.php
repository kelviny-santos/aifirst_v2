<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles database restoration from SQL dump
 * with URL replacement, table prefix conversion, and user session preservation.
 *
 * WordPress permission system:
 * - Roles are in wp_options: option_name = '{prefix}user_roles'
 * - User caps in wp_usermeta: meta_key = '{prefix}capabilities'
 * - User level in wp_usermeta: meta_key = '{prefix}user_level'
 * - Session tokens in wp_usermeta: meta_key = 'session_tokens' (no prefix)
 * - Cookie names use md5(siteurl) as hash
 * - Auth cookies validated via AUTH_KEY/SALT constants in wp-config.php
 *
 * If ANY of these are mismatched after restore, user gets:
 * "Sorry, you are not allowed to access this page."
 */
class WPBR_DB_Restorer {

    private $wpdb;
    private $source_prefix;
    private $target_prefix;
    private $source_url;
    private $target_url;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->target_prefix = $wpdb->prefix;
    }

    /**
     * Restore database from SQL file
     */
    public function restore($sql_file, $options = array()) {
        @set_time_limit(600);
        wp_raise_memory_limit('admin');

        $this->source_url = rtrim($options['source_url'] ?? '', '/');
        $this->target_url = rtrim(home_url(), '/');
        $this->source_prefix = $options['source_prefix'] ?? '';
        $replace_urls = $options['replace_urls'] ?? true;
        $skip_users = $options['skip_users'] ?? false;

        // ============================================================
        // STEP 1: Save EVERYTHING about the current user BEFORE restore
        // ============================================================
        $saved = $this->save_current_state();

        // ============================================================
        // STEP 2: Execute SQL queries from the backup
        // ============================================================
        $handle = fopen($sql_file, 'r');
        if (!$handle) {
            return new WP_Error('file_error', 'Não foi possível abrir o arquivo SQL.');
        }

        $query = '';
        $tables_created = 0;
        $queries_run = 0;
        $errors = array();
        $in_string = false;

        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);

            // Skip pure comment lines and empty lines
            if (empty($trimmed) || strpos($trimmed, '--') === 0) {
                continue;
            }
            // Skip block comment lines (/* ... */)
            if (strpos($trimmed, '/*') === 0 && substr($trimmed, -2) === '*/') {
                continue;
            }

            $query .= $line;

            // Check if this line ends with a semicolon that's NOT inside a string
            if ($this->query_is_complete($query)) {
                // Replace table prefix ONLY in structural SQL positions
                if (!empty($this->source_prefix) && $this->source_prefix !== $this->target_prefix) {
                    $query = $this->replace_table_prefix($query);
                }

                // Skip ALL queries for users/usermeta tables if requested
                if ($skip_users && $this->is_users_table_query($query)) {
                    $query = '';
                    continue;
                }

                // Replace URLs
                if ($replace_urls && !empty($this->source_url) && $this->source_url !== $this->target_url) {
                    $query = $this->replace_urls_in_sql($query);
                }

                $result = $this->wpdb->query($query);
                if ($result === false) {
                    $err = $this->wpdb->last_error;
                    if (!empty($err)) {
                        $errors[] = substr($err, 0, 200);
                    }
                } else {
                    $queries_run++;
                    if (stripos($query, 'CREATE TABLE') !== false) {
                        $tables_created++;
                    }
                }

                $query = '';
            }
        }

        fclose($handle);

        // ============================================================
        // STEP 3: Post-restore fixes (critical order)
        // ============================================================

        // 3a. Fix prefix in option_name and meta_key
        if (!empty($this->source_prefix) && $this->source_prefix !== $this->target_prefix) {
            $this->fix_prefix_in_database();
        }

        // 3b. Fix serialized data broken by URL replacement
        if ($replace_urls && !empty($this->source_url) && $this->source_url !== $this->target_url) {
            $this->fix_serialized_data();
        }

        // 3c. Force siteurl and home to target URL (safety net)
        if ($replace_urls && !empty($this->target_url)) {
            $options_table = $this->target_prefix . 'options';
            $this->wpdb->update(
                $options_table,
                array('option_value' => $this->target_url),
                array('option_name' => 'siteurl')
            );
            $this->wpdb->update(
                $options_table,
                array('option_value' => $this->target_url),
                array('option_name' => 'home')
            );
        }

        // 3d. Ensure user_roles option exists and is valid
        $this->ensure_user_roles_exist();

        // 3e. Restore current user's admin access
        $this->restore_current_user($saved);

        // 3f. Ensure this plugin stays active
        $this->ensure_plugin_active();

        // 3g. Flush WordPress object cache
        wp_cache_flush();

        // 3h. Flush rewrite rules and regenerate .htaccess for new directory
        $this->flush_rewrite_rules_safe();

        return array(
            'tables_created' => $tables_created,
            'queries_run'    => $queries_run,
            'errors'         => array_slice($errors, 0, 20),
            'url_replaced'   => $replace_urls && $this->source_url !== $this->target_url,
            'prefix_changed' => $this->source_prefix !== $this->target_prefix,
        );
    }

    // ================================================================
    // SAVE / RESTORE STATE
    // ================================================================

    /**
     * Save current user + session + plugin state before DB overwrite
     */
    private function save_current_state() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return array('user' => null, 'plugin_file' => plugin_basename(WPBR_PLUGIN_DIR . 'wp-backup-restorer.php'));
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return array('user' => null, 'plugin_file' => plugin_basename(WPBR_PLUGIN_DIR . 'wp-backup-restorer.php'));
        }

        // Get raw session_tokens from DB (not from cache)
        $session_tokens = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta_value FROM `{$this->target_prefix}usermeta` WHERE user_id = %d AND meta_key = 'session_tokens'",
            $user_id
        ));

        return array(
            'user' => array(
                'ID'              => $user->ID,
                'user_login'      => $user->user_login,
                'user_pass'       => $user->user_pass,
                'user_email'      => $user->user_email,
                'user_nicename'   => $user->user_nicename,
                'display_name'    => $user->display_name,
                'user_registered' => $user->user_registered,
                'user_url'        => $user->user_url,
            ),
            'session_tokens' => $session_tokens,
            'plugin_file'    => plugin_basename(WPBR_PLUGIN_DIR . 'wp-backup-restorer.php'),
        );
    }

    /**
     * After DB restore, ensure the current user has admin access and valid session
     */
    private function restore_current_user($saved) {
        if (empty($saved['user'])) {
            return;
        }

        $user_data = $saved['user'];
        $user_id = $user_data['ID'];
        $users_table = $this->target_prefix . 'users';
        $usermeta_table = $this->target_prefix . 'usermeta';

        // --- Ensure user exists in wp_users ---
        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT ID FROM `{$users_table}` WHERE ID = %d", $user_id)
        );

        if (!$exists) {
            $this->wpdb->replace($users_table, array(
                'ID'                  => $user_id,
                'user_login'          => $user_data['user_login'],
                'user_pass'           => $user_data['user_pass'],
                'user_email'          => $user_data['user_email'],
                'user_nicename'       => $user_data['user_nicename'],
                'display_name'        => $user_data['display_name'],
                'user_registered'     => $user_data['user_registered'],
                'user_url'            => $user_data['user_url'],
                'user_status'         => 0,
                'user_activation_key' => '',
            ));
        }

        // --- Force admin capabilities with CORRECT prefix ---
        $cap_key = $this->target_prefix . 'capabilities';
        $level_key = $this->target_prefix . 'user_level';

        // Delete ALL capability-related meta for this user (any prefix variant)
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM `{$usermeta_table}` WHERE user_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s)",
            $user_id,
            '%capabilities',
            '%user_level'
        ));

        // Insert correct capabilities with target prefix
        $this->wpdb->insert($usermeta_table, array(
            'user_id'    => $user_id,
            'meta_key'   => $cap_key,
            'meta_value' => 'a:1:{s:13:"administrator";b:1;}',
        ));
        $this->wpdb->insert($usermeta_table, array(
            'user_id'    => $user_id,
            'meta_key'   => $level_key,
            'meta_value' => '10',
        ));

        // --- Restore session tokens so user stays logged in ---
        if (!empty($saved['session_tokens'])) {
            // Delete existing session_tokens
            $this->wpdb->delete($usermeta_table, array(
                'user_id'  => $user_id,
                'meta_key' => 'session_tokens',
            ));
            // Re-insert saved tokens
            $this->wpdb->insert($usermeta_table, array(
                'user_id'    => $user_id,
                'meta_key'   => 'session_tokens',
                'meta_value' => $saved['session_tokens'],
            ));
        }

        // --- Also restore other prefix-dependent user meta ---
        $other_keys = array('user-settings', 'user-settings-time', 'dashboard_quick_press_last_post_id');
        foreach ($other_keys as $key) {
            $full_key = $this->target_prefix . $key;
            $existing = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT umeta_id FROM `{$usermeta_table}` WHERE user_id = %d AND meta_key = %s",
                $user_id, $full_key
            ));
            // These are optional, just make sure they exist with the right prefix if they had the old prefix
            if (!$existing && !empty($this->source_prefix) && $this->source_prefix !== $this->target_prefix) {
                $old_key = $this->source_prefix . $key;
                $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE `{$usermeta_table}` SET meta_key = %s WHERE user_id = %d AND meta_key = %s",
                    $full_key, $user_id, $old_key
                ));
            }
        }
    }

    // ================================================================
    // PREFIX FIXES (post-restore)
    // ================================================================

    /**
     * Fix ALL prefix-dependent keys in the database after restore.
     * This is the CRITICAL fix: WordPress looks for '{prefix}user_roles' in options
     * and '{prefix}capabilities' in usermeta. If these have the wrong prefix,
     * all users get zero permissions.
     */
    private function fix_prefix_in_database() {
        $src = $this->source_prefix;
        $dst = $this->target_prefix;
        $options_table = $dst . 'options';
        $usermeta_table = $dst . 'usermeta';

        // ---- Fix wp_options: rename option_name ----

        // List of known prefix-dependent option names
        $option_suffixes = array(
            'user_roles',
        );

        foreach ($option_suffixes as $suffix) {
            $old_name = $src . $suffix;
            $new_name = $dst . $suffix;

            if ($old_name === $new_name) {
                continue;
            }

            // Check if old exists
            $old_exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT option_id FROM `{$options_table}` WHERE option_name = %s",
                $old_name
            ));

            if ($old_exists) {
                // Delete any existing target (avoid duplicate key)
                $this->wpdb->delete($options_table, array('option_name' => $new_name));
                // Rename
                $this->wpdb->update(
                    $options_table,
                    array('option_name' => $new_name),
                    array('option_name' => $old_name)
                );
            }
        }

        // ---- Fix wp_usermeta: rename meta_key ----

        // All usermeta keys that use the prefix:
        // {prefix}capabilities, {prefix}user_level, {prefix}user-settings,
        // {prefix}user-settings-time, {prefix}dashboard_quick_press_last_post_id
        $src_esc = $this->wpdb->esc_like($src);
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE `{$usermeta_table}` SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d))
             WHERE meta_key LIKE %s",
            $dst,
            strlen($src) + 1,
            $src_esc . '%'
        ));
    }

    /**
     * Ensure wp_user_roles option exists and contains valid data.
     * If it's missing or corrupted, insert WordPress defaults.
     */
    private function ensure_user_roles_exist() {
        $options_table = $this->target_prefix . 'options';
        $roles_key = $this->target_prefix . 'user_roles';

        $roles_value = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT option_value FROM `{$options_table}` WHERE option_name = %s",
            $roles_key
        ));

        $valid = false;
        if (!empty($roles_value)) {
            $test = @unserialize($roles_value);
            if (is_array($test) && isset($test['administrator'])) {
                $valid = true;
            }
        }

        if (!$valid) {
            // Delete any existing (possibly corrupted) entry
            $this->wpdb->delete($options_table, array('option_name' => $roles_key));

            // Insert default WordPress roles
            $this->wpdb->insert($options_table, array(
                'option_name'  => $roles_key,
                'option_value' => serialize($this->get_default_wp_roles()),
                'autoload'     => 'yes',
            ));
        }
    }

    // ================================================================
    // PLUGIN PERSISTENCE
    // ================================================================

    /**
     * Ensure wp-backup-restorer stays in active_plugins after DB overwrite.
     * Otherwise the results page won't render on the next request.
     */
    private function ensure_plugin_active() {
        $options_table = $this->target_prefix . 'options';
        $plugin_file = plugin_basename(WPBR_PLUGIN_DIR . 'wp-backup-restorer.php');

        $active = $this->wpdb->get_var(
            "SELECT option_value FROM `{$options_table}` WHERE option_name = 'active_plugins'"
        );

        $plugins = array();
        if (!empty($active)) {
            $plugins = @unserialize($active);
            if (!is_array($plugins)) {
                $plugins = array();
            }
        }

        if (!in_array($plugin_file, $plugins, true)) {
            $plugins[] = $plugin_file;
            // Use direct DB query (not update_option) since WP cache is stale
            $this->wpdb->update(
                $options_table,
                array('option_value' => serialize($plugins)),
                array('option_name' => 'active_plugins')
            );
        }
    }

    // ================================================================
    // REWRITE RULES / .HTACCESS
    // ================================================================

    private function flush_rewrite_rules_safe() {
        $options_table = $this->target_prefix . 'options';
        $this->wpdb->delete($options_table, array('option_name' => 'rewrite_rules'));

        if (!function_exists('save_mod_rewrite_rules')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        global $wp_rewrite;
        if ($wp_rewrite) {
            $wp_rewrite->init();
            $wp_rewrite->flush_rules(true);
        }
    }

    // ================================================================
    // SQL PARSING
    // ================================================================

    /**
     * Check if accumulated query ends with a real semicolon (not inside a string literal)
     */
    private function query_is_complete($query) {
        $trimmed = rtrim($query);
        if (empty($trimmed) || substr($trimmed, -1) !== ';') {
            return false;
        }

        // Count unescaped single quotes - if odd, we're inside a string
        $stripped = preg_replace("/\\\\./", '', $query); // remove escaped chars
        $count = substr_count($stripped, "'");
        return ($count % 2) === 0;
    }

    /**
     * Check if a query targets the users or usermeta tables
     */
    private function is_users_table_query($query) {
        $tp = preg_quote($this->target_prefix, '/');
        // Also check source prefix in case replacement hasn't happened
        $sp = preg_quote($this->source_prefix, '/');

        return (bool) preg_match('/[`\s](?:' . $tp . '|' . $sp . ')(?:users|usermeta)[`\s;]/i', $query);
    }

    /**
     * Replace table prefix ONLY in SQL structural positions.
     * NEVER touches data inside VALUES() strings - that would corrupt
     * serialized PHP data where string lengths are encoded.
     */
    private function replace_table_prefix($query) {
        $src = preg_quote($this->source_prefix, '/');
        $dst = $this->target_prefix;

        // 1. Backtick-quoted table names: `old_prefix_xxx` → `new_prefix_xxx`
        $query = preg_replace('/`' . $src . '(\w+)`/', '`' . $dst . '$1`', $query);

        // 2. Non-quoted table names in specific SQL keywords only
        $patterns = array(
            '/^(DROP\s+TABLE\s+(?:IF\s+EXISTS\s+))' . $src . '(\w+)/im',
            '/^(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)' . $src . '(\w+)/im',
            '/^(INSERT\s+(?:INTO\s+))' . $src . '(\w+)/im',
            '/(LOCK\s+TABLES\s+)' . $src . '(\w+)/im',
            '/(ALTER\s+TABLE\s+)' . $src . '(\w+)/im',
            '/(UNLOCK\s+TABLES)/i', // no replacement needed, just for completeness
        );
        $replacements = array(
            '$1' . $dst . '$2',
            '$1' . $dst . '$2',
            '$1' . $dst . '$2',
            '$1' . $dst . '$2',
            '$1' . $dst . '$2',
            '$1',
        );

        $query = preg_replace($patterns, $replacements, $query);

        return $query;
    }

    // ================================================================
    // URL REPLACEMENT
    // ================================================================

    /**
     * Replace URLs in SQL (for non-serialized and JSON data)
     */
    private function replace_urls_in_sql($query) {
        // Plain URL
        $query = str_replace($this->source_url, $this->target_url, $query);

        // JSON-escaped URL (Elementor data: \/ instead of /)
        $esc_src = str_replace('/', '\\/', $this->source_url);
        $esc_dst = str_replace('/', '\\/', $this->target_url);
        $query = str_replace($esc_src, $esc_dst, $query);

        // Double-escaped URL (doubly-serialized data)
        $dbl_src = str_replace('/', '\\\\/', $this->source_url);
        $dbl_dst = str_replace('/', '\\\\/', $this->target_url);
        $query = str_replace($dbl_src, $dbl_dst, $query);

        return $query;
    }

    // ================================================================
    // SERIALIZED DATA FIX
    // ================================================================

    /**
     * After URL replacement, fix broken serialized string lengths.
     * PHP serialize format: s:LENGTH:"STRING"; — if STRING changed length,
     * the LENGTH marker is now wrong and unserialize() fails.
     */
    private function fix_serialized_data() {
        $tables = array(
            $this->target_prefix . 'options'  => array('column' => 'option_value', 'pk' => 'option_id'),
            $this->target_prefix . 'postmeta' => array('column' => 'meta_value', 'pk' => 'meta_id'),
            $this->target_prefix . 'usermeta' => array('column' => 'meta_value', 'pk' => 'umeta_id'),
        );

        foreach ($tables as $table => $info) {
            $column = $info['column'];
            $pk = $info['pk'];

            // Find rows that contain the target URL AND look like serialized data
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT `{$pk}`, `{$column}` FROM `{$table}`
                     WHERE `{$column}` LIKE %s AND `{$column}` REGEXP '^[aOsibd]:'",
                    '%' . $this->wpdb->esc_like($this->target_url) . '%'
                )
            );

            if (empty($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                $value = $row->$column;

                // Skip if it's not serialized
                if (!$this->is_serialized($value)) {
                    continue;
                }

                // Skip if it already unserializes correctly
                $test = @unserialize($value);
                if ($test !== false || $value === 'b:0;') {
                    continue;
                }

                // Fix serialized string lengths
                $fixed = $this->fix_serialized_lengths($value);
                if ($fixed !== $value && $fixed !== false) {
                    $this->wpdb->update(
                        $table,
                        array($column => $fixed),
                        array($pk => $row->$pk)
                    );
                }
            }
        }
    }

    /**
     * Fix serialized string length markers after search-replace.
     * Uses a regex to find s:N:"..." patterns and recalculate N.
     */
    private function fix_serialized_lengths($data) {
        // Fix string lengths: s:OLD_LENGTH:"actual string";
        $fixed = preg_replace_callback(
            '/s:(\d+):"(.*?)";/s',
            function ($m) {
                return 's:' . strlen($m[2]) . ':"' . $m[2] . '";';
            },
            $data
        );

        // Verify the fix worked
        if ($fixed !== null) {
            $test = @unserialize($fixed);
            if ($test !== false || $fixed === 'b:0;') {
                return $fixed;
            }
        }

        return $data; // Return original if fix didn't help
    }

    private function is_serialized($data) {
        if (!is_string($data) || strlen($data) < 4) {
            return false;
        }
        return in_array($data[0], array('a', 'O', 's', 'i', 'b', 'd'), true) && $data[1] === ':';
    }

    // ================================================================
    // DEFAULT WORDPRESS ROLES
    // ================================================================

    private function get_default_wp_roles() {
        return array(
            'administrator' => array(
                'name' => 'Administrator',
                'capabilities' => array(
                    'switch_themes' => true, 'edit_themes' => true, 'activate_plugins' => true,
                    'edit_plugins' => true, 'edit_users' => true, 'edit_files' => true,
                    'manage_options' => true, 'moderate_comments' => true, 'manage_categories' => true,
                    'manage_links' => true, 'upload_files' => true, 'import' => true,
                    'unfiltered_html' => true, 'edit_posts' => true, 'edit_others_posts' => true,
                    'edit_published_posts' => true, 'publish_posts' => true, 'edit_pages' => true,
                    'read' => true, 'level_10' => true, 'level_9' => true, 'level_8' => true,
                    'level_7' => true, 'level_6' => true, 'level_5' => true, 'level_4' => true,
                    'level_3' => true, 'level_2' => true, 'level_1' => true, 'level_0' => true,
                    'edit_others_pages' => true, 'edit_published_pages' => true, 'publish_pages' => true,
                    'delete_pages' => true, 'delete_others_pages' => true, 'delete_published_pages' => true,
                    'delete_posts' => true, 'delete_others_posts' => true, 'delete_published_posts' => true,
                    'delete_private_posts' => true, 'edit_private_posts' => true, 'read_private_posts' => true,
                    'delete_private_pages' => true, 'edit_private_pages' => true, 'read_private_pages' => true,
                    'delete_users' => true, 'create_users' => true, 'unfiltered_upload' => true,
                    'edit_dashboard' => true, 'update_plugins' => true, 'delete_plugins' => true,
                    'install_plugins' => true, 'update_themes' => true, 'install_themes' => true,
                    'update_core' => true, 'list_users' => true, 'remove_users' => true,
                    'promote_users' => true, 'edit_theme_options' => true, 'delete_themes' => true,
                    'export' => true,
                ),
            ),
            'editor' => array(
                'name' => 'Editor',
                'capabilities' => array(
                    'moderate_comments' => true, 'manage_categories' => true, 'manage_links' => true,
                    'upload_files' => true, 'unfiltered_html' => true, 'edit_posts' => true,
                    'edit_others_posts' => true, 'edit_published_posts' => true, 'publish_posts' => true,
                    'edit_pages' => true, 'read' => true, 'level_7' => true, 'level_6' => true,
                    'level_5' => true, 'level_4' => true, 'level_3' => true, 'level_2' => true,
                    'level_1' => true, 'level_0' => true, 'edit_others_pages' => true,
                    'edit_published_pages' => true, 'publish_pages' => true, 'delete_pages' => true,
                    'delete_others_pages' => true, 'delete_published_pages' => true,
                    'delete_posts' => true, 'delete_others_posts' => true, 'delete_published_posts' => true,
                    'delete_private_posts' => true, 'edit_private_posts' => true, 'read_private_posts' => true,
                    'delete_private_pages' => true, 'edit_private_pages' => true, 'read_private_pages' => true,
                ),
            ),
            'author' => array(
                'name' => 'Author',
                'capabilities' => array(
                    'upload_files' => true, 'edit_posts' => true, 'edit_published_posts' => true,
                    'publish_posts' => true, 'read' => true, 'level_2' => true,
                    'level_1' => true, 'level_0' => true, 'delete_posts' => true,
                    'delete_published_posts' => true,
                ),
            ),
            'contributor' => array(
                'name' => 'Contributor',
                'capabilities' => array(
                    'edit_posts' => true, 'read' => true, 'level_1' => true,
                    'level_0' => true, 'delete_posts' => true,
                ),
            ),
            'subscriber' => array(
                'name' => 'Subscriber',
                'capabilities' => array('read' => true, 'level_0' => true),
            ),
        );
    }
}
