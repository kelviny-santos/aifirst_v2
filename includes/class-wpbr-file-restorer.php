<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles file restoration (themes, plugins, uploads, content)
 */
class WPBR_File_Restorer {

    private $log = array();

    /**
     * Restore files from a component ZIP
     *
     * @param string $zip_file  Path to the component ZIP
     * @param string $component Type: themes, plugins, uploads, content, core
     * @param array  $options   {
     *   'overwrite'   => bool - overwrite existing files
     *   'skip_active' => bool - don't overwrite currently active theme/plugins
     * }
     */
    public function restore($zip_file, $component, $options = array()) {
        @set_time_limit(300);

        $overwrite = $options['overwrite'] ?? true;

        $zip = new ZipArchive();
        if ($zip->open($zip_file) !== true) {
            return new WP_Error('zip_error', "Não foi possível abrir o ZIP de {$component}.");
        }

        $target_dir = $this->get_target_dir($component);
        if (is_wp_error($target_dir)) {
            $zip->close();
            return $target_dir;
        }

        $files_restored = 0;
        $files_skipped = 0;
        $errors = array();

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);

            // Skip directories
            if (substr($entry, -1) === '/') {
                continue;
            }

            // Strip the component prefix from the path
            // e.g., "themes/hello-elementor/style.css" → "hello-elementor/style.css"
            $relative_path = $this->strip_component_prefix($entry, $component);
            if ($relative_path === null) {
                $relative_path = $entry;
            }

            $dest_file = $target_dir . '/' . $relative_path;

            // Skip if file exists and overwrite is off
            if (!$overwrite && file_exists($dest_file)) {
                $files_skipped++;
                continue;
            }

            // Create directory
            $dest_dir = dirname($dest_file);
            if (!is_dir($dest_dir)) {
                if (!wp_mkdir_p($dest_dir)) {
                    $errors[] = "Não foi possível criar diretório: " . $relative_path;
                    continue;
                }
            }

            // Extract file
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                $errors[] = "Falha ao ler: " . $entry;
                continue;
            }

            if (file_put_contents($dest_file, $content) !== false) {
                $files_restored++;
            } else {
                $errors[] = "Falha ao escrever: " . $relative_path;
            }
        }

        $zip->close();

        return array(
            'component'      => $component,
            'files_restored' => $files_restored,
            'files_skipped'  => $files_skipped,
            'errors'         => array_slice($errors, 0, 20),
        );
    }

    /**
     * Restore files from multiple component ZIPs (multi-part support).
     * Each ZIP is extracted to the same target directory.
     *
     * @param array  $zip_files Array of paths to component ZIPs
     * @param string $component Type: themes, plugins, uploads, content, core
     * @param array  $options   Same as restore()
     */
    public function restore_multiple(array $zip_files, $component, $options = array()) {
        $combined = array(
            'component'      => $component,
            'files_restored' => 0,
            'files_skipped'  => 0,
            'errors'         => array(),
        );

        foreach ($zip_files as $zip_file) {
            $result = $this->restore($zip_file, $component, $options);
            if (is_wp_error($result)) {
                $combined['errors'][] = $result->get_error_message();
                continue;
            }
            $combined['files_restored'] += $result['files_restored'];
            $combined['files_skipped']  += $result['files_skipped'];
            $combined['errors'] = array_merge($combined['errors'], $result['errors']);
        }

        $combined['errors'] = array_slice($combined['errors'], 0, 20);
        return $combined;
    }

    /**
     * Get the target directory for a component
     */
    private function get_target_dir($component) {
        $wp_content = WP_CONTENT_DIR;

        switch ($component) {
            case 'themes':
                return $wp_content . '/themes';
            case 'plugins':
                return $wp_content . '/plugins';
            case 'uploads':
                $upload_dir = wp_upload_dir();
                return $upload_dir['basedir'];
            case 'content':
                return $wp_content;
            case 'core':
                return ABSPATH;
            default:
                return new WP_Error('unknown_component', "Componente desconhecido: {$component}");
        }
    }

    /**
     * Strip the component prefix from entry path
     * "themes/hello-elementor/style.css" → "hello-elementor/style.css"
     * "plugins/elementor/elementor.php" → "elementor/elementor.php"
     * "uploads/2024/11/image.jpg" → "2024/11/image.jpg"
     */
    private function strip_component_prefix($entry, $component) {
        $prefixes = array(
            'themes'  => 'themes/',
            'plugins' => 'plugins/',
            'uploads' => 'uploads/',
            'content' => '',
            'core'    => '',
        );

        $prefix = $prefixes[$component] ?? '';
        if (!empty($prefix) && strpos($entry, $prefix) === 0) {
            return substr($entry, strlen($prefix));
        }

        return null;
    }

    /**
     * List contents of a component ZIP (for preview)
     */
    public function list_contents($zip_file, $component) {
        $zip = new ZipArchive();
        if ($zip->open($zip_file) !== true) {
            return new WP_Error('zip_error', 'Não foi possível abrir o ZIP.');
        }

        $items = array();
        $total_size = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat && substr($stat['name'], -1) !== '/') {
                $total_size += $stat['size'];
                // Get top-level items only
                $relative = $this->strip_component_prefix($stat['name'], $component);
                if ($relative === null) {
                    $relative = $stat['name'];
                }
                $top_level = explode('/', $relative)[0];
                if (!in_array($top_level, $items, true)) {
                    $items[] = $top_level;
                }
            }
        }

        $zip->close();

        return array(
            'items'      => $items,
            'file_count' => $zip->numFiles,
            'total_size' => $total_size,
        );
    }
}
