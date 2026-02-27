<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPBR_Admin {

    public function init() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'handle_upload'));
        add_action('admin_init', array($this, 'handle_restore'));
    }

    public function register_menu() {
        add_menu_page(
            'Backup Restorer',
            'Backup Restorer',
            'manage_options',
            'wp-backup-restorer',
            array($this, 'render_page'),
            'dashicons-database-import',
            80
        );
    }

    public function enqueue_assets($hook) {
        // Load CSS on both Backup Restorer and AI Editor pages
        if (strpos($hook, 'wp-backup-restorer') === false && strpos($hook, 'wpbr-ai-editor') === false) {
            return;
        }
        wp_enqueue_style('wpbr-admin', WPBR_PLUGIN_URL . 'admin/css/admin-style.css', array(), WPBR_VERSION);
    }

    /**
     * Main page render
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $step = isset($_GET['step']) ? sanitize_text_field($_GET['step']) : 'upload';

        echo '<div class="wrap">';
        echo '<h1>WP Backup Restorer</h1>';

        $this->display_notices();

        switch ($step) {
            case 'preview':
                include WPBR_PLUGIN_DIR . 'admin/views/preview.php';
                break;
            case 'restoring':
                include WPBR_PLUGIN_DIR . 'admin/views/result.php';
                break;
            default:
                include WPBR_PLUGIN_DIR . 'admin/views/upload.php';
                break;
        }

        echo '</div>';
    }

    /**
     * Handle backup ZIP upload
     */
    public function handle_upload() {
        if (!isset($_POST['wpbr_upload_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['wpbr_upload_nonce'], 'wpbr_upload')) {
            wp_die('Verificação de segurança falhou.');
        }
        if (!current_user_can('manage_options')) {
            wp_die('Permissão negada.');
        }

        if (!isset($_FILES['wpbr_backup_files']) || empty($_FILES['wpbr_backup_files']['name'][0])) {
            $this->set_notice('error', 'Erro no upload. Verifique o tamanho do arquivo.');
            wp_safe_redirect(admin_url('admin.php?page=wp-backup-restorer'));
            exit;
        }

        $files = $_FILES['wpbr_backup_files'];
        $file_count = count($files['name']);
        $tmp_paths = array();

        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $this->set_notice('error', 'Erro no upload do arquivo: ' . $files['name'][$i]);
                wp_safe_redirect(admin_url('admin.php?page=wp-backup-restorer'));
                exit;
            }

            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if ($ext !== 'zip') {
                $this->set_notice('error', 'Apenas arquivos .zip são aceitos. Arquivo inválido: ' . $files['name'][$i]);
                wp_safe_redirect(admin_url('admin.php?page=wp-backup-restorer'));
                exit;
            }

            $tmp_paths[] = $files['tmp_name'][$i];
        }

        // Parse the backup (single or multi-part)
        $parser = new WPBR_Parser();
        $result = $parser->parse_multiple($tmp_paths);

        if (is_wp_error($result)) {
            $this->set_notice('error', $result->get_error_message());
            wp_safe_redirect(admin_url('admin.php?page=wp-backup-restorer'));
            exit;
        }

        // Store parsed info in transient for next step
        set_transient('wpbr_pending_restore', $result, 3600);

        wp_safe_redirect(admin_url('admin.php?page=wp-backup-restorer&step=preview'));
        exit;
    }

    /**
     * Handle the actual restore
     */
    public function handle_restore() {
        if (!isset($_POST['wpbr_restore_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['wpbr_restore_nonce'], 'wpbr_restore')) {
            wp_die('Verificação de segurança falhou.');
        }
        if (!current_user_can('manage_options')) {
            wp_die('Permissão negada.');
        }

        $pending = get_transient('wpbr_pending_restore');
        if (!$pending) {
            $this->set_notice('error', 'Sessão expirada. Faça upload novamente.');
            wp_safe_redirect(admin_url('admin.php?page=wp-backup-restorer'));
            exit;
        }

        @set_time_limit(600);
        wp_raise_memory_limit('admin');

        // Compute redirect URL BEFORE the restore overwrites wp_options
        // (admin_url reads siteurl from cache which becomes stale after DB restore)
        $redirect_url = admin_url('admin.php?page=wp-backup-restorer&step=restoring');

        $components = isset($_POST['wpbr_components']) ? array_map('sanitize_text_field', $_POST['wpbr_components']) : array();
        $replace_urls = isset($_POST['wpbr_replace_urls']);
        $skip_users = isset($_POST['wpbr_skip_users']);
        $overwrite = isset($_POST['wpbr_overwrite']);

        $results = array();

        // Restore database
        if (in_array('database', $components, true) && isset($pending['components']['database'])) {
            $db_restorer = new WPBR_DB_Restorer();
            $db_result = $db_restorer->restore($pending['components']['database']['file'], array(
                'source_url'    => $pending['source_info']['site_url'],
                'source_prefix' => $pending['source_info']['table_prefix'],
                'replace_urls'  => $replace_urls,
                'skip_users'    => $skip_users,
            ));
            $results['database'] = $db_result;
        }

        // Restore file components
        $file_restorer = new WPBR_File_Restorer();
        $file_components = array('themes', 'plugins', 'uploads', 'content');

        foreach ($file_components as $comp) {
            if (in_array($comp, $components, true) && isset($pending['components'][$comp])) {
                $files = $pending['components'][$comp]['files'] ?? array($pending['components'][$comp]['file']);
                $file_result = $file_restorer->restore_multiple(
                    $files,
                    $comp,
                    array('overwrite' => $overwrite)
                );
                $results[$comp] = $file_result;
            }
        }

        // Cleanup temp files
        $parser = new WPBR_Parser();
        $parser->cleanup($pending['temp_dir']);

        // Store results using direct DB query (WP cache is stale after restore)
        global $wpdb;
        $options_table = $wpdb->prefix . 'options';

        // Delete old transients first
        $wpdb->query("DELETE FROM `{$options_table}` WHERE option_name IN ('_transient_wpbr_restore_results', '_transient_timeout_wpbr_restore_results', '_transient_wpbr_pending_restore', '_transient_timeout_wpbr_pending_restore')");

        // Insert results transient directly
        $wpdb->insert($options_table, array(
            'option_name'  => '_transient_wpbr_restore_results',
            'option_value' => serialize($results),
            'autoload'     => 'no',
        ));
        $wpdb->insert($options_table, array(
            'option_name'  => '_transient_timeout_wpbr_restore_results',
            'option_value' => time() + 3600,
            'autoload'     => 'no',
        ));

        // Use wp_redirect (not wp_safe_redirect) since the URL is hardcoded by us
        wp_redirect($redirect_url);
        exit;
    }

    private function display_notices() {
        $notice = get_transient('wpbr_admin_notice');
        if ($notice) {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($notice['type']),
                esc_html($notice['message'])
            );
            delete_transient('wpbr_admin_notice');
        }
    }

    private function set_notice($type, $message) {
        set_transient('wpbr_admin_notice', array('type' => $type, 'message' => $message), 30);
    }
}
