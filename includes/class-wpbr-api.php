<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPBR_API {

    private $elementor;
    private $media;
    private $schema;

    public function __construct(WPBR_Elementor $elementor, WPBR_Media $media, WPBR_Schema $schema) {
        $this->elementor = $elementor;
        $this->media = $media;
        $this->schema = $schema;
    }

    public function init() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register all REST routes
     */
    public function register_routes() {
        $ns = WPBR_API_NAMESPACE;
        $auth = array('permission_callback' => array('WPBR_Auth', 'check_permission'));

        // === Reading ===
        register_rest_route($ns, '/pages', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_pages'),
            'permission_callback' => $auth['permission_callback'],
        ));

        register_rest_route($ns, '/pages/(?P<id>\d+)', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_page'),
            'permission_callback' => $auth['permission_callback'],
        ));

        register_rest_route($ns, '/pages/(?P<id>\d+)/widgets', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_widgets'),
            'permission_callback' => $auth['permission_callback'],
        ));

        register_rest_route($ns, '/pages/(?P<id>\d+)/widgets/(?P<widget_id>[a-f0-9]+)', array(
            array(
                'methods'  => 'GET',
                'callback' => array($this, 'get_widget'),
                'permission_callback' => $auth['permission_callback'],
            ),
            array(
                'methods'  => 'PUT',
                'callback' => array($this, 'update_widget'),
                'permission_callback' => $auth['permission_callback'],
            ),
            array(
                'methods'  => 'DELETE',
                'callback' => array($this, 'delete_widget'),
                'permission_callback' => $auth['permission_callback'],
            ),
        ));

        // === Writing ===
        register_rest_route($ns, '/pages/(?P<id>\d+)/widgets', array(
            'methods'  => 'POST',
            'callback' => array($this, 'add_widget'),
            'permission_callback' => $auth['permission_callback'],
        ));

        register_rest_route($ns, '/pages/(?P<id>\d+)/sections/(?P<section_id>[a-f0-9]+)', array(
            'methods'  => 'PUT',
            'callback' => array($this, 'update_section'),
            'permission_callback' => $auth['permission_callback'],
        ));

        register_rest_route($ns, '/pages/(?P<id>\d+)/bulk-update', array(
            'methods'  => 'POST',
            'callback' => array($this, 'bulk_update'),
            'permission_callback' => $auth['permission_callback'],
        ));

        // === Media ===
        register_rest_route($ns, '/media/upload', array(
            'methods'  => 'POST',
            'callback' => array($this, 'upload_media'),
            'permission_callback' => $auth['permission_callback'],
        ));

        register_rest_route($ns, '/media/upload-from-url', array(
            'methods'  => 'POST',
            'callback' => array($this, 'upload_media_from_url'),
            'permission_callback' => $auth['permission_callback'],
        ));

        // === Backup ===
        register_rest_route($ns, '/backup/upload', array(
            'methods'  => 'POST',
            'callback' => array($this, 'backup_upload'),
            'permission_callback' => $auth['permission_callback'],
        ));

        register_rest_route($ns, '/backup/restore', array(
            'methods'  => 'POST',
            'callback' => array($this, 'backup_restore'),
            'permission_callback' => $auth['permission_callback'],
        ));

        register_rest_route($ns, '/backup/status', array(
            'methods'  => 'GET',
            'callback' => array($this, 'backup_status'),
            'permission_callback' => $auth['permission_callback'],
        ));

        // === Schema (public) ===
        register_rest_route($ns, '/schema', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_schema'),
            'permission_callback' => '__return_true',
        ));
    }

    // =============================================
    // READ HANDLERS
    // =============================================

    public function get_pages($request) {
        $pages = $this->elementor->get_elementor_pages();
        return rest_ensure_response($pages);
    }

    public function get_page($request) {
        $id = (int) $request->get_param('id');
        $summary = filter_var($request->get_param('summary'), FILTER_VALIDATE_BOOLEAN);
        $post = get_post($id);
        if (!$post) {
            return new WP_Error('not_found', __('Página não encontrada.', 'wp-backup-restorer'), array('status' => 404));
        }

        $tree = $this->elementor->build_page_tree($id, $summary);
        if (is_wp_error($tree)) {
            return $tree;
        }

        return rest_ensure_response(array(
            'id'        => $id,
            'title'     => $post->post_title,
            'post_type' => $post->post_type,
            'status'    => $post->post_status,
            'permalink' => get_permalink($id),
            'structure' => $tree,
        ));
    }

    public function get_widgets($request) {
        $id = (int) $request->get_param('id');
        $summary = filter_var($request->get_param('summary'), FILTER_VALIDATE_BOOLEAN);
        $data = $this->elementor->get_elementor_data($id);
        if (is_wp_error($data)) {
            return $data;
        }

        $all = $this->elementor->flatten_widgets($data, '', $summary);
        $widgets_only = array_values(array_filter($all, function ($w) {
            return $w['elType'] === 'widget';
        }));

        return rest_ensure_response(array(
            'page_id' => $id,
            'count'   => count($widgets_only),
            'widgets' => $widgets_only,
        ));
    }

    public function get_widget($request) {
        $id = (int) $request->get_param('id');
        $widget_id = $request->get_param('widget_id');

        $data = $this->elementor->get_elementor_data($id);
        if (is_wp_error($data)) {
            return $data;
        }

        $widget = $this->elementor->find_widget_by_id($data, $widget_id);
        if ($widget === null) {
            return new WP_Error('widget_not_found', __('Widget não encontrado.', 'wp-backup-restorer'), array('status' => 404));
        }

        return rest_ensure_response(array(
            'id'         => $widget['id'],
            'elType'     => $widget['elType'],
            'widgetType' => $widget['widgetType'] ?? null,
            'settings'   => $widget['settings'] ?? array(),
        ));
    }

    // =============================================
    // WRITE HANDLERS
    // =============================================

    public function update_widget($request) {
        $id = (int) $request->get_param('id');
        $widget_id = $request->get_param('widget_id');
        $new_settings = $request->get_json_params();

        if (empty($new_settings) || !is_array($new_settings)) {
            return new WP_Error('invalid_input', __('Body deve ser um JSON object com settings.', 'wp-backup-restorer'), array('status' => 400));
        }

        $new_settings = $this->sanitize_settings($new_settings);

        $data = $this->elementor->get_elementor_data($id);
        if (is_wp_error($data)) {
            return $data;
        }

        $success = $this->elementor->update_widget_settings($data, $widget_id, $new_settings);
        if (!$success) {
            return new WP_Error('widget_not_found', __('Widget não encontrado.', 'wp-backup-restorer'), array('status' => 404));
        }

        $result = $this->elementor->save_elementor_data($id, $data);
        if (is_wp_error($result)) {
            return $result;
        }

        $updated = $this->elementor->find_widget_by_id($data, $widget_id);
        return rest_ensure_response(array(
            'success' => true,
            'widget'  => array(
                'id'         => $updated['id'],
                'widgetType' => $updated['widgetType'] ?? null,
                'settings'   => $updated['settings'] ?? array(),
            ),
        ));
    }

    public function add_widget($request) {
        $id = (int) $request->get_param('id');
        $body = $request->get_json_params();

        $target_id = $body['target_id'] ?? '';
        $position = $body['position'] ?? 'end';
        $widget_data = $body['widget'] ?? array();

        if (empty($target_id) || empty($widget_data['widgetType'])) {
            return new WP_Error('invalid_input', __('Campos obrigatórios: target_id, widget.widgetType', 'wp-backup-restorer'), array('status' => 400));
        }

        if (isset($widget_data['settings'])) {
            $widget_data['settings'] = $this->sanitize_settings($widget_data['settings']);
        }

        $data = $this->elementor->get_elementor_data($id);
        if (is_wp_error($data)) {
            return $data;
        }

        $new_id = $this->elementor->add_widget($data, $target_id, $widget_data, $position);
        if ($new_id === false) {
            return new WP_Error('target_not_found', __('Container/seção alvo não encontrado.', 'wp-backup-restorer'), array('status' => 404));
        }

        $result = $this->elementor->save_elementor_data($id, $data);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success'   => true,
            'widget_id' => $new_id,
        ));
    }

    public function delete_widget($request) {
        $id = (int) $request->get_param('id');
        $widget_id = $request->get_param('widget_id');

        $data = $this->elementor->get_elementor_data($id);
        if (is_wp_error($data)) {
            return $data;
        }

        $success = $this->elementor->remove_widget($data, $widget_id);
        if (!$success) {
            return new WP_Error('widget_not_found', __('Widget não encontrado.', 'wp-backup-restorer'), array('status' => 404));
        }

        $result = $this->elementor->save_elementor_data($id, $data);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array('success' => true));
    }

    public function update_section($request) {
        $id = (int) $request->get_param('id');
        $section_id = $request->get_param('section_id');
        $new_settings = $request->get_json_params();

        if (empty($new_settings) || !is_array($new_settings)) {
            return new WP_Error('invalid_input', __('Body deve ser um JSON object com settings.', 'wp-backup-restorer'), array('status' => 400));
        }

        $new_settings = $this->sanitize_settings($new_settings);

        $data = $this->elementor->get_elementor_data($id);
        if (is_wp_error($data)) {
            return $data;
        }

        $success = $this->elementor->update_section_settings($data, $section_id, $new_settings);
        if (!$success) {
            return new WP_Error('section_not_found', __('Seção não encontrada ou tipo inválido.', 'wp-backup-restorer'), array('status' => 404));
        }

        $result = $this->elementor->save_elementor_data($id, $data);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array('success' => true));
    }

    public function bulk_update($request) {
        $id = (int) $request->get_param('id');
        $body = $request->get_json_params();

        $updates = $body['updates'] ?? array();
        if (empty($updates) || !is_array($updates)) {
            return new WP_Error('invalid_input', __('Campo "updates" deve ser um array de {widget_id, settings}.', 'wp-backup-restorer'), array('status' => 400));
        }

        $data = $this->elementor->get_elementor_data($id);
        if (is_wp_error($data)) {
            return $data;
        }

        $results = array();
        foreach ($updates as $update) {
            $wid = $update['widget_id'] ?? '';
            $settings = $update['settings'] ?? array();

            if (empty($wid) || empty($settings)) {
                $results[] = array('widget_id' => $wid, 'success' => false, 'error' => 'Missing widget_id or settings');
                continue;
            }

            $settings = $this->sanitize_settings($settings);
            $success = $this->elementor->update_widget_settings($data, $wid, $settings);
            $results[] = array(
                'widget_id' => $wid,
                'success'   => $success,
                'error'     => $success ? null : 'Widget not found',
            );
        }

        $save_result = $this->elementor->save_elementor_data($id, $data);
        if (is_wp_error($save_result)) {
            return $save_result;
        }

        return rest_ensure_response(array(
            'success' => true,
            'results' => $results,
        ));
    }

    // =============================================
    // MEDIA HANDLERS
    // =============================================

    public function upload_media($request) {
        $body = $request->get_json_params();
        $base64 = $body['data'] ?? '';
        $filename = $body['filename'] ?? '';

        if (empty($base64)) {
            return new WP_Error('missing_data', __('Campo "data" (base64) é obrigatório.', 'wp-backup-restorer'), array('status' => 400));
        }

        $result = $this->media->upload_from_base64($base64, $filename);
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 201);
    }

    public function upload_media_from_url($request) {
        $body = $request->get_json_params();
        $url = $body['url'] ?? '';

        if (empty($url)) {
            return new WP_Error('missing_url', __('Campo "url" é obrigatório.', 'wp-backup-restorer'), array('status' => 400));
        }

        $result = $this->media->upload_from_url(esc_url_raw($url));
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 201);
    }

    // =============================================
    // BACKUP HANDLERS
    // =============================================

    /**
     * Upload a WPVivid backup ZIP via REST API.
     * Accepts multipart/form-data with field "files[]" (multiple) or "file" (single),
     * OR JSON with "url" to download from.
     */
    public function backup_upload($request) {
        $files = $request->get_file_params();
        $zip_paths = array();

        $error_messages = array(
            UPLOAD_ERR_INI_SIZE   => 'Arquivo excede upload_max_filesize do PHP.',
            UPLOAD_ERR_FORM_SIZE  => 'Arquivo excede o limite do formulário.',
            UPLOAD_ERR_PARTIAL    => 'Upload incompleto.',
            UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo enviado.',
            UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário não encontrado.',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao escrever no disco.',
        );

        // Option 1a: Multiple files via "files[]"
        if (!empty($files['files']) && is_array($files['files']['name'])) {
            $dest_dir = sys_get_temp_dir() . '/wpbr_uploads';
            wp_mkdir_p($dest_dir);

            $file_count = count($files['files']['name']);
            for ($i = 0; $i < $file_count; $i++) {
                if ($files['files']['error'][$i] !== UPLOAD_ERR_OK) {
                    $msg = $error_messages[$files['files']['error'][$i]] ?? 'Erro de upload desconhecido.';
                    return new WP_Error('upload_error', $msg . ' (' . $files['files']['name'][$i] . ')', array('status' => 400));
                }

                $ext = strtolower(pathinfo($files['files']['name'][$i], PATHINFO_EXTENSION));
                if ($ext !== 'zip') {
                    return new WP_Error('invalid_file', __('Apenas arquivos .zip são aceitos.', 'wp-backup-restorer') . ' (' . $files['files']['name'][$i] . ')', array('status' => 400));
                }

                $dest_file = $dest_dir . '/' . sanitize_file_name($files['files']['name'][$i]);
                if (!move_uploaded_file($files['files']['tmp_name'][$i], $dest_file)) {
                    return new WP_Error('move_failed', __('Falha ao mover o arquivo.', 'wp-backup-restorer') . ' (' . $files['files']['name'][$i] . ')', array('status' => 500));
                }

                $zip_paths[] = $dest_file;
            }
        }
        // Option 1b: Single file via "file" (backwards compatibility)
        elseif (!empty($files['file'])) {
            $file = $files['file'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $msg = $error_messages[$file['error']] ?? 'Erro de upload desconhecido.';
                return new WP_Error('upload_error', $msg, array('status' => 400));
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'zip') {
                return new WP_Error('invalid_file', __('Apenas arquivos .zip são aceitos.', 'wp-backup-restorer'), array('status' => 400));
            }

            $dest_dir = sys_get_temp_dir() . '/wpbr_uploads';
            wp_mkdir_p($dest_dir);
            $dest_file = $dest_dir . '/' . sanitize_file_name($file['name']);
            if (!move_uploaded_file($file['tmp_name'], $dest_file)) {
                return new WP_Error('move_failed', __('Falha ao mover o arquivo.', 'wp-backup-restorer'), array('status' => 500));
            }

            $zip_paths[] = $dest_file;
        }
        // Option 2: Download from URL
        else {
            $body = $request->get_json_params();
            $url = $body['url'] ?? '';

            if (empty($url)) {
                return new WP_Error('no_file', __('Envie arquivos ZIP via multipart (files[] ou file) ou forneça {"url": "..."}.', 'wp-backup-restorer'), array('status' => 400));
            }

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return new WP_Error('invalid_url', __('URL inválida.', 'wp-backup-restorer'), array('status' => 400));
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            $temp = download_url(esc_url_raw($url), 300);
            if (is_wp_error($temp)) {
                return new WP_Error('download_failed', sprintf(__('Falha ao baixar: %s', 'wp-backup-restorer'), $temp->get_error_message()), array('status' => 400));
            }

            $zip_paths[] = $temp;
        }

        // Parse the backup (single or multi-part)
        $parser = new WPBR_Parser();
        $result = $parser->parse_multiple($zip_paths);

        if (is_wp_error($result)) {
            return $result;
        }

        // Store parsed info for restore step
        set_transient('wpbr_api_pending_restore', $result, 3600);

        // Build component summary
        $components = array();
        foreach ($result['components'] as $name => $info) {
            $components[] = $name;
        }

        return rest_ensure_response(array(
            'success'     => true,
            'message'     => 'Backup enviado e analisado. Use POST /backup/restore para restaurar.',
            'source_info' => $result['source_info'],
            'components'  => $components,
        ));
    }

    /**
     * Restore a previously uploaded backup.
     * Body: {"components": ["database","themes","plugins","uploads"], "replace_urls": true, "skip_users": true, "overwrite": true}
     */
    public function backup_restore($request) {
        $pending = get_transient('wpbr_api_pending_restore');
        if (!$pending) {
            return new WP_Error('no_pending', __('Nenhum backup pendente. Faça upload primeiro via POST /backup/upload.', 'wp-backup-restorer'), array('status' => 400));
        }

        $body = $request->get_json_params();
        $components = $body['components'] ?? array_keys($pending['components']);
        $replace_urls = $body['replace_urls'] ?? true;
        $skip_users = $body['skip_users'] ?? true;
        $overwrite = $body['overwrite'] ?? true;

        @set_time_limit(600);
        wp_raise_memory_limit('admin');

        // Mark as in progress
        set_transient('wpbr_restore_status', array(
            'status'  => 'running',
            'started' => time(),
            'step'    => 'starting',
        ), 3600);

        $results = array();

        // Restore database
        if (in_array('database', $components, true) && isset($pending['components']['database'])) {
            set_transient('wpbr_restore_status', array(
                'status' => 'running', 'started' => time(), 'step' => 'database',
            ), 3600);

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
                set_transient('wpbr_restore_status', array(
                    'status' => 'running', 'started' => time(), 'step' => $comp,
                ), 3600);

                $files = $pending['components'][$comp]['files'] ?? array($pending['components'][$comp]['file']);
                $file_result = $file_restorer->restore_multiple(
                    $files,
                    $comp,
                    array('overwrite' => $overwrite)
                );
                $results[$comp] = $file_result;
            }
        }

        // Cleanup
        $parser = new WPBR_Parser();
        $parser->cleanup($pending['temp_dir']);
        delete_transient('wpbr_api_pending_restore');

        // Store final status (use direct DB since WP cache may be stale after DB restore)
        global $wpdb;
        $options_table = $wpdb->prefix . 'options';

        $final_status = array(
            'status'    => 'completed',
            'completed' => time(),
            'results'   => $results,
        );

        // Clean up old status transients
        $wpdb->query("DELETE FROM `{$options_table}` WHERE option_name IN ('_transient_wpbr_restore_status', '_transient_timeout_wpbr_restore_status')");
        $wpdb->insert($options_table, array(
            'option_name'  => '_transient_wpbr_restore_status',
            'option_value' => serialize($final_status),
            'autoload'     => 'no',
        ));
        $wpdb->insert($options_table, array(
            'option_name'  => '_transient_timeout_wpbr_restore_status',
            'option_value' => time() + 3600,
            'autoload'     => 'no',
        ));

        // Ensure this plugin stays active after DB restore
        $plugin_file = plugin_basename(WPBR_PLUGIN_DIR . 'wp-backup-restorer.php');
        $active = $wpdb->get_var("SELECT option_value FROM `{$options_table}` WHERE option_name = 'active_plugins'");
        $plugins = $active ? @unserialize($active) : array();
        if (!is_array($plugins)) $plugins = array();
        if (!in_array($plugin_file, $plugins, true)) {
            $plugins[] = $plugin_file;
            $wpdb->update($options_table, array('option_value' => serialize($plugins)), array('option_name' => 'active_plugins'));
        }

        // Ensure API key survives the restore
        $api_key = get_option('wpbr_api_key');
        if (empty($api_key)) {
            $wpdb->query("DELETE FROM `{$options_table}` WHERE option_name = 'wpbr_api_key'");
            $wpdb->insert($options_table, array(
                'option_name'  => 'wpbr_api_key',
                'option_value' => wp_generate_password(32, false),
                'autoload'     => 'yes',
            ));
        }

        wp_cache_flush();

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Restore concluído.',
            'results' => $results,
        ));
    }

    /**
     * Get restore status
     */
    public function backup_status($request) {
        $status = get_transient('wpbr_restore_status');
        if (!$status) {
            // Check if there's a pending upload
            $pending = get_transient('wpbr_api_pending_restore');
            if ($pending) {
                return rest_ensure_response(array(
                    'status'     => 'pending',
                    'message'    => 'Backup enviado, aguardando restore.',
                    'components' => array_keys($pending['components']),
                    'source_info' => $pending['source_info'],
                ));
            }
            return rest_ensure_response(array(
                'status'  => 'idle',
                'message' => 'Nenhum restore em andamento.',
            ));
        }

        return rest_ensure_response($status);
    }

    // =============================================
    // SCHEMA
    // =============================================

    public function get_schema($request) {
        return rest_ensure_response($this->schema->generate());
    }

    // =============================================
    // HELPERS
    // =============================================

    private function sanitize_settings($settings, $depth = 0) {
        $sanitized = array();
        foreach ($settings as $key => $value) {
            $key = sanitize_text_field($key);

            // Only skip structural Elementor keys at top level
            if ($depth === 0 && in_array($key, array('id', 'elType', 'elements', 'isInner'), true)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize_settings($value, $depth + 1);
            } elseif (is_string($value)) {
                if (preg_match('/(url|link|href|src)$/i', $key)) {
                    $sanitized[$key] = esc_url_raw($value);
                } elseif ($key === 'html') {
                    $allowed = wp_kses_allowed_html('post');
                    $allowed['iframe'] = array(
                        'src'             => true,
                        'width'           => true,
                        'height'          => true,
                        'style'           => true,
                        'allowfullscreen' => true,
                        'loading'         => true,
                        'referrerpolicy'  => true,
                        'frameborder'     => true,
                        'title'           => true,
                    );
                    $sanitized[$key] = wp_kses($value, $allowed);
                } elseif (in_array($key, array('editor', 'description_text', 'testimonial_content'), true)) {
                    $sanitized[$key] = wp_kses_post($value);
                } else {
                    $sanitized[$key] = sanitize_text_field($value);
                }
            } elseif (is_numeric($value) || is_bool($value)) {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }
}
