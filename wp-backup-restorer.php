<?php
/**
 * Plugin Name: WP Backup Restorer
 * Plugin URI:  https://github.com/wp-backup-restorer
 * Description: Restaura backups WPVivid + API REST para IA editar templates Elementor (trocar textos, imagens, seções e widgets via HTTP). Inclui schema OpenAPI para GPT Actions.
 * Version:     1.1.0
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Author:      WP Backup Restorer
 * License:     GPL-2.0+
 * Text Domain: wp-backup-restorer
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPBR_VERSION', '1.1.0');
define('WPBR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPBR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPBR_API_NAMESPACE', 'wpbr/v1');

// Backup Restorer classes
require_once WPBR_PLUGIN_DIR . 'includes/class-wpbr-parser.php';
require_once WPBR_PLUGIN_DIR . 'includes/class-wpbr-db-restorer.php';
require_once WPBR_PLUGIN_DIR . 'includes/class-wpbr-file-restorer.php';
require_once WPBR_PLUGIN_DIR . 'includes/class-wpbr-admin.php';

// AI Editor classes
require_once WPBR_PLUGIN_DIR . 'includes/class-wpbr-auth.php';
require_once WPBR_PLUGIN_DIR . 'includes/class-wpbr-elementor.php';
require_once WPBR_PLUGIN_DIR . 'includes/class-wpbr-media.php';
require_once WPBR_PLUGIN_DIR . 'includes/class-wpbr-api.php';
require_once WPBR_PLUGIN_DIR . 'includes/class-wpbr-schema.php';

/**
 * Check if Elementor is active (soft dependency - only shows notice)
 */
function wpbr_check_elementor() {
    if (!did_action('elementor/loaded')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo esc_html__('WP Backup Restorer: O módulo AI Editor requer o Elementor ativo para editar templates. A funcionalidade de restore continua funcionando normalmente.', 'wp-backup-restorer');
            echo '</p></div>';
        });
        return false;
    }
    return true;
}

/**
 * Initialize plugin
 */
function wpbr_init() {
    // Backup Restorer admin
    $admin = new WPBR_Admin();
    $admin->init();

    // AI Editor auth (always loads - provides settings page)
    $auth = new WPBR_Auth();
    $auth->init();

    // AI Editor REST API (only if Elementor is present)
    if (wpbr_check_elementor()) {
        $elementor = new WPBR_Elementor();
        $media = new WPBR_Media();
        $schema = new WPBR_Schema();
        $api = new WPBR_API($elementor, $media, $schema);
        $api->init();
    }
}
add_action('plugins_loaded', 'wpbr_init');

/**
 * Activation
 */
function wpbr_activate() {
    if (!class_exists('ZipArchive')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('WP Backup Restorer requer a extensão PHP ZipArchive.', 'Erro', array('back_link' => true));
    }

    // Generate API key for AI Editor
    if (get_option('wpbr_api_key') === false) {
        // Migrate from standalone WP AI Editor if it was previously installed
        $old_key = get_option('wpai_api_key');
        if ($old_key) {
            add_option('wpbr_api_key', $old_key);
        } else {
            add_option('wpbr_api_key', wp_generate_password(32, false));
        }
    }

    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'wpbr_activate');

/**
 * Deactivation
 */
function wpbr_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'wpbr_deactivate');
