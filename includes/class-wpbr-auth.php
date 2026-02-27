<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPBR_Auth {

    const OPTION_KEY = 'wpbr_api_key';
    const RATE_LIMIT = 60; // requests per minute

    public function init() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_wpbr_regenerate_key', array($this, 'ajax_regenerate_key'));
    }

    /**
     * Permission callback for REST routes
     */
    public static function check_permission($request) {
        // 1. Check custom API key
        $api_key = $request->get_header('x-wpbr-api-key');
        if (empty($api_key)) {
            $api_key = $request->get_param('api_key');
        }

        if (!empty($api_key)) {
            $stored_key = get_option(self::OPTION_KEY);
            if ($stored_key && hash_equals($stored_key, $api_key)) {
                if (!self::rate_limit_check('key_' . md5($api_key))) {
                    return new WP_Error('rate_limited', __('Rate limit excedido. Tente novamente em 1 minuto.', 'wp-backup-restorer'), array('status' => 429));
                }
                return true;
            }
            return new WP_Error('invalid_api_key', __('API key inválida.', 'wp-backup-restorer'), array('status' => 401));
        }

        // 2. Fall back to WordPress Application Passwords (Basic Auth)
        if (current_user_can('manage_options')) {
            if (!self::rate_limit_check('user_' . get_current_user_id())) {
                return new WP_Error('rate_limited', __('Rate limit excedido. Tente novamente em 1 minuto.', 'wp-backup-restorer'), array('status' => 429));
            }
            return true;
        }

        if (is_user_logged_in()) {
            return new WP_Error('rest_forbidden', __('Acesso requer permissão de administrador.', 'wp-backup-restorer'), array('status' => 403));
        }

        return new WP_Error('rest_not_logged_in', __('Autenticação necessária. Use API key ou Application Password.', 'wp-backup-restorer'), array('status' => 401));
    }

    private static function rate_limit_check($identifier) {
        $key = 'wpbr_rate_' . md5($identifier);
        $count = (int) get_transient($key);

        if ($count >= self::RATE_LIMIT) {
            return false;
        }

        set_transient($key, $count + 1, 60);
        return true;
    }

    /**
     * Register submenu under Backup Restorer
     */
    public function register_menu() {
        add_submenu_page(
            'wp-backup-restorer',
            __('AI Editor', 'wp-backup-restorer'),
            __('AI Editor', 'wp-backup-restorer'),
            'manage_options',
            'wpbr-ai-editor',
            array($this, 'render_page')
        );
    }

    public function register_settings() {
        register_setting('wpbr_ai_settings_group', self::OPTION_KEY, array(
            'sanitize_callback' => 'sanitize_text_field',
        ));
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        include WPBR_PLUGIN_DIR . 'admin/views/ai-settings.php';
    }

    /**
     * AJAX: regenerate API key
     */
    public function ajax_regenerate_key() {
        check_ajax_referer('wpbr_regenerate_key', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $new_key = wp_generate_password(32, false);
        update_option(self::OPTION_KEY, $new_key);

        wp_send_json_success(array('key' => $new_key));
    }
}
