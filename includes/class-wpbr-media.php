<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPBR_Media {

    /**
     * Upload image from base64 data
     */
    public function upload_from_base64($base64_data, $filename = '') {
        $file_data = base64_decode($base64_data, true);
        if ($file_data === false) {
            return new WP_Error('invalid_base64', __('Dados base64 inválidos.', 'wp-backup-restorer'), array('status' => 400));
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($file_data);

        $allowed_mimes = array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        );

        if (!isset($allowed_mimes[$mime])) {
            return new WP_Error('invalid_mime', __('Tipo de arquivo não suportado. Aceitos: jpg, png, gif, webp, svg.', 'wp-backup-restorer'), array('status' => 400));
        }

        if (empty($filename)) {
            $filename = 'ai-upload-' . time() . '.' . $allowed_mimes[$mime];
        }

        $filename = sanitize_file_name($filename);

        $upload = wp_upload_bits($filename, null, $file_data);
        if (!empty($upload['error'])) {
            return new WP_Error('upload_failed', $upload['error'], array('status' => 500));
        }

        return $this->create_attachment($upload['file'], $mime, $filename);
    }

    /**
     * Upload image from external URL
     */
    public function upload_from_url($url) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', __('URL inválida.', 'wp-backup-restorer'), array('status' => 400));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $temp = download_url($url, 30);
        if (is_wp_error($temp)) {
            return new WP_Error('download_failed', sprintf(__('Falha ao baixar: %s', 'wp-backup-restorer'), $temp->get_error_message()), array('status' => 400));
        }

        $filename = sanitize_file_name(basename(wp_parse_url($url, PHP_URL_PATH)));
        if (empty($filename)) {
            $filename = 'ai-download-' . time() . '.jpg';
        }

        $file_array = array(
            'name'     => $filename,
            'tmp_name' => $temp,
        );

        $attach_id = media_handle_sideload($file_array, 0);
        if (is_wp_error($attach_id)) {
            @unlink($temp);
            return $attach_id;
        }

        return array(
            'id'   => $attach_id,
            'url'  => wp_get_attachment_url($attach_id),
            'sizes' => $this->get_image_sizes($attach_id),
        );
    }

    private function create_attachment($file_path, $mime_type, $filename) {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment = array(
            'post_mime_type' => $mime_type,
            'post_title'     => pathinfo($filename, PATHINFO_FILENAME),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        $attach_id = wp_insert_attachment($attachment, $file_path);
        if (is_wp_error($attach_id)) {
            return $attach_id;
        }

        $metadata = wp_generate_attachment_metadata($attach_id, $file_path);
        wp_update_attachment_metadata($attach_id, $metadata);

        return array(
            'id'    => $attach_id,
            'url'   => wp_get_attachment_url($attach_id),
            'sizes' => $this->get_image_sizes($attach_id),
        );
    }

    private function get_image_sizes($attach_id) {
        $sizes = array();
        $registered = get_intermediate_image_sizes();
        foreach ($registered as $size) {
            $src = wp_get_attachment_image_src($attach_id, $size);
            if ($src) {
                $sizes[$size] = array(
                    'url'    => $src[0],
                    'width'  => $src[1],
                    'height' => $src[2],
                );
            }
        }
        return $sizes;
    }
}
