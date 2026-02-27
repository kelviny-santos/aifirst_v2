<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parses WPVivid backup ZIP structure without any integrity checks
 */
class WPBR_Parser {

    private $temp_dir;
    private $package_info;
    private $inner_files = array();

    /**
     * Parse the main backup ZIP
     * Returns structured info about what's inside
     */
    public function parse($zip_path) {
        $this->temp_dir = sys_get_temp_dir() . '/wpbr_' . uniqid();
        wp_mkdir_p($this->temp_dir);

        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return new WP_Error('zip_error', 'Não foi possível abrir o arquivo ZIP.');
        }

        $zip->extractTo($this->temp_dir);
        $zip->close();

        // Read package info (if exists - we don't require it)
        $info_file = $this->temp_dir . '/wpvivid_package_info.json';
        if (file_exists($info_file)) {
            $raw = file_get_contents($info_file);
            $this->package_info = json_decode($raw, true);
        }

        // Discover inner files
        $this->discover_inner_files();

        return array(
            'temp_dir'     => $this->temp_dir,
            'package_info' => $this->package_info,
            'components'   => $this->inner_files,
            'source_info'  => $this->extract_source_info(),
        );
    }

    /**
     * Parse multiple WPVivid backup parts (multi-part support).
     * Extracts all ZIPs into a shared temp directory then discovers components.
     *
     * @param array $zip_paths Array of paths to ZIP part files.
     * @return array|WP_Error Parsed result identical to parse().
     */
    public function parse_multiple(array $zip_paths) {
        if (count($zip_paths) === 1) {
            return $this->parse($zip_paths[0]);
        }

        $this->temp_dir = sys_get_temp_dir() . '/wpbr_' . uniqid();
        wp_mkdir_p($this->temp_dir);

        foreach ($zip_paths as $zip_path) {
            $zip = new ZipArchive();
            if ($zip->open($zip_path) !== true) {
                $this->cleanup($this->temp_dir);
                return new WP_Error('zip_error', 'Não foi possível abrir o arquivo ZIP: ' . basename($zip_path));
            }

            $zip->extractTo($this->temp_dir);
            $zip->close();
        }

        // Read package info (if exists - only present in one part, usually part001)
        $info_file = $this->temp_dir . '/wpvivid_package_info.json';
        if (file_exists($info_file)) {
            $raw = file_get_contents($info_file);
            $this->package_info = json_decode($raw, true);
        }

        // Discover inner files from the merged directory
        $this->discover_inner_files();

        return array(
            'temp_dir'     => $this->temp_dir,
            'package_info' => $this->package_info,
            'components'   => $this->inner_files,
            'source_info'  => $this->extract_source_info(),
        );
    }

    /**
     * Discover inner ZIP files and SQL files.
     * Components may be split across multiple inner ZIPs (e.g. backup_plugin.part001.zip,
     * backup_plugin.part002.zip). We accumulate ALL parts into a 'files' array so that
     * every part gets extracted during restore.
     */
    private function discover_inner_files() {
        $files = glob($this->temp_dir . '/*');

        foreach ($files as $file) {
            $basename = basename($file);

            // Skip package info
            if ($basename === 'wpvivid_package_info.json') {
                continue;
            }

            // Detect type from filename
            if (strpos($basename, 'backup_db') !== false) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                    $this->inner_files['database'] = array(
                        'files' => array($file),
                        'type'  => 'sql',
                    );
                } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                    // Extract inner DB zip to get the SQL
                    $sql_file = $this->extract_inner_zip($file, 'sql');
                    if ($sql_file) {
                        $this->inner_files['database'] = array(
                            'files' => array($sql_file),
                            'type'  => 'sql',
                        );
                    }
                }
            } elseif (strpos($basename, 'backup_themes') !== false) {
                if (!isset($this->inner_files['themes'])) {
                    $this->inner_files['themes'] = array('files' => array(), 'type' => 'zip');
                }
                $this->inner_files['themes']['files'][] = $file;
            } elseif (strpos($basename, 'backup_plugin') !== false) {
                if (!isset($this->inner_files['plugins'])) {
                    $this->inner_files['plugins'] = array('files' => array(), 'type' => 'zip');
                }
                $this->inner_files['plugins']['files'][] = $file;
            } elseif (strpos($basename, 'backup_uploads') !== false) {
                if (!isset($this->inner_files['uploads'])) {
                    $this->inner_files['uploads'] = array('files' => array(), 'type' => 'zip');
                }
                $this->inner_files['uploads']['files'][] = $file;
            } elseif (strpos($basename, 'backup_content') !== false) {
                if (!isset($this->inner_files['content'])) {
                    $this->inner_files['content'] = array('files' => array(), 'type' => 'zip');
                }
                $this->inner_files['content']['files'][] = $file;
            } elseif (strpos($basename, 'backup_core') !== false) {
                if (!isset($this->inner_files['core'])) {
                    $this->inner_files['core'] = array('files' => array(), 'type' => 'zip');
                }
                $this->inner_files['core']['files'][] = $file;
            }
        }

        // Add convenience 'file' key pointing to the first file in each component,
        // so existing code that reads $component['file'] doesn't break.
        foreach ($this->inner_files as $key => &$comp) {
            if (!empty($comp['files'])) {
                $comp['file'] = $comp['files'][0];
            }
        }
        unset($comp);
    }

    /**
     * Extract an inner ZIP to find a specific file type
     */
    private function extract_inner_zip($zip_file, $find_extension) {
        $inner_dir = $this->temp_dir . '/inner_' . uniqid();
        wp_mkdir_p($inner_dir);

        $zip = new ZipArchive();
        if ($zip->open($zip_file) !== true) {
            return null;
        }

        $zip->extractTo($inner_dir);
        $zip->close();

        // Find file with matching extension
        $files = glob($inner_dir . '/*.' . $find_extension);
        if (!empty($files)) {
            return $files[0];
        }

        return null;
    }

    /**
     * Extract source site info from SQL comments or package info
     */
    private function extract_source_info() {
        $info = array(
            'site_url'     => '',
            'home_url'     => '',
            'table_prefix' => '',
            'wp_version'   => '',
        );

        // From package info
        if ($this->package_info) {
            $info['home_url'] = $this->package_info['home_url'] ?? '';
            $info['site_url'] = $info['home_url'];
            $info['wp_version'] = $this->package_info['wp_version'] ?? '';
        }

        // From SQL header comments (more reliable after edits)
        if (isset($this->inner_files['database'])) {
            $sql_file = $this->inner_files['database']['file'];
            $header = file_get_contents($sql_file, false, null, 0, 2048);

            if (preg_match('/site_url:\s*(https?:\/\/[^\s*]+)/', $header, $m)) {
                $info['site_url'] = rtrim($m[1], '/');
            }
            if (preg_match('/home_url:\s*(https?:\/\/[^\s*]+)/', $header, $m)) {
                $info['home_url'] = rtrim($m[1], '/');
            }
            if (preg_match('/table_prefix:\s*(\S+)/', $header, $m)) {
                $info['table_prefix'] = $m[1];
            }
        }

        // Fallback: extract table_prefix from package_info table names
        if (empty($info['table_prefix']) && !empty($this->package_info['child_file'])) {
            foreach ($this->package_info['child_file'] as $child) {
                if (($child['file_type'] ?? '') === 'databases' && !empty($child['tables'])) {
                    $first_table = $child['tables'][0]['name'] ?? '';
                    if (preg_match('/^(.+_)\w+$/', $first_table, $m)) {
                        $info['table_prefix'] = $m[1];
                    }
                    break;
                }
            }
        }

        return $info;
    }

    /**
     * Cleanup temp directory
     */
    public function cleanup($temp_dir) {
        if (empty($temp_dir) || !is_dir($temp_dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temp_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }
        @rmdir($temp_dir);
    }
}
