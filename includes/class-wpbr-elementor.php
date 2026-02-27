<?php
if (!defined('ABSPATH')) {
    exit;
}

class WPBR_Elementor {

    /**
     * Get and decode Elementor data for a post
     */
    public function get_elementor_data($post_id) {
        $raw = get_post_meta($post_id, '_elementor_data', true);
        if (empty($raw)) {
            return new WP_Error('no_elementor_data', __('Esta página não possui dados do Elementor.', 'wp-backup-restorer'), array('status' => 404));
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_elementor_data', __('Dados do Elementor corrompidos.', 'wp-backup-restorer'), array('status' => 500));
        }

        return $data;
    }

    /**
     * Save Elementor data and clear caches
     */
    public function save_elementor_data($post_id, $data) {
        $json = wp_json_encode($data);
        if ($json === false) {
            return new WP_Error('encode_error', __('Falha ao codificar dados JSON.', 'wp-backup-restorer'), array('status' => 500));
        }

        // wp_slash is CRITICAL - WordPress runs stripslashes on meta values
        update_post_meta($post_id, '_elementor_data', wp_slash($json));

        // Update plain text for search indexing
        $plain = $this->extract_plain_text($data);
        wp_update_post(array('ID' => $post_id, 'post_content' => $plain));

        // Clear Elementor CSS cache
        $this->clear_elementor_cache($post_id);

        return true;
    }

    /**
     * Regenerate Elementor CSS for a post
     */
    public function clear_elementor_cache($post_id) {
        if (!did_action('elementor/loaded')) {
            return;
        }

        try {
            if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                $post_css = \Elementor\Core\Files\CSS\Post::create($post_id);
                $post_css->update();
            }

            if (class_exists('\Elementor\Plugin') && \Elementor\Plugin::instance()->files_manager) {
                \Elementor\Plugin::instance()->files_manager->clear_cache();
            }
        } catch (\Exception $e) {
            // CSS will regenerate on next page visit
        }
    }

    /**
     * Get all pages/posts with Elementor data
     */
    public function get_elementor_pages() {
        $query = new WP_Query(array(
            'post_type'      => array('page', 'post', 'elementor_library'),
            'posts_per_page' => -1,
            'meta_key'       => '_elementor_edit_mode',
            'meta_value'     => 'builder',
            'post_status'    => array('publish', 'draft', 'private'),
        ));

        $pages = array();
        foreach ($query->posts as $post) {
            $data = $this->get_elementor_data($post->ID);
            $widget_count = 0;
            if (!is_wp_error($data)) {
                $widgets = $this->flatten_widgets($data, '', true);
                $widget_count = count(array_filter($widgets, function ($w) {
                    return $w['elType'] === 'widget';
                }));
            }

            $pages[] = array(
                'id'            => $post->ID,
                'title'         => $post->post_title,
                'post_type'     => $post->post_type,
                'status'        => $post->post_status,
                'permalink'     => get_permalink($post->ID),
                'widget_count'  => $widget_count,
                'last_modified' => $post->post_modified,
            );
        }

        return $pages;
    }

    /**
     * Build page tree for AI visualization
     * @param int  $post_id   The post ID
     * @param bool $summary   If true, return only summarized settings; if false, return full settings
     */
    public function build_page_tree($post_id, $summary = false) {
        $data = $this->get_elementor_data($post_id);
        if (is_wp_error($data)) {
            return $data;
        }
        return $this->build_tree($data, $summary);
    }

    /**
     * Build Elementor tree with full or summarized settings
     */
    private function build_tree($elements, $summary = false) {
        $tree = array();
        foreach ($elements as $element) {
            $node = array(
                'id'     => $element['id'],
                'elType' => $element['elType'],
            );

            if (isset($element['widgetType'])) {
                $node['widgetType'] = $element['widgetType'];
            }

            if (!empty($element['settings'])) {
                if ($element['elType'] === 'widget') {
                    $node['settings'] = $summary
                        ? $this->summarize_settings($element['settings'])
                        : $element['settings'];
                } else {
                    // Containers/sections: return full settings (backgrounds, layout, etc.)
                    $node['settings'] = $summary
                        ? $this->summarize_container_settings($element['settings'])
                        : $element['settings'];
                }
            }

            if (!empty($element['elements'])) {
                $node['children'] = $this->build_tree($element['elements'], $summary);
            }

            $tree[] = $node;
        }
        return $tree;
    }

    /**
     * Flatten widget tree into a flat list
     * @param array  $elements    Elementor elements array
     * @param string $parent_path Path tracking for nesting
     * @param bool   $summary     If true, return only summarized settings; if false, return full settings
     */
    public function flatten_widgets($elements, $parent_path = '', $summary = false) {
        $result = array();
        foreach ($elements as $index => $element) {
            $path = $parent_path !== '' ? $parent_path . '.' . $index : (string) $index;

            $item = array(
                'id'     => $element['id'],
                'elType' => $element['elType'],
                'path'   => $path,
            );

            if (isset($element['widgetType'])) {
                $item['widgetType'] = $element['widgetType'];
            }

            if (!empty($element['settings'])) {
                if ($element['elType'] === 'widget') {
                    $item['settings'] = $summary
                        ? $this->summarize_settings($element['settings'])
                        : $element['settings'];
                } else {
                    $item['settings'] = $summary
                        ? $this->summarize_container_settings($element['settings'])
                        : $element['settings'];
                }
            }

            $result[] = $item;

            if (!empty($element['elements'])) {
                $children = $this->flatten_widgets($element['elements'], $path . '.elements', $summary);
                $result = array_merge($result, $children);
            }
        }
        return $result;
    }

    /**
     * Find widget by ID (returns by reference for in-place modification)
     */
    public function &find_widget_by_id(&$elements, $widget_id) {
        $null = null;
        foreach ($elements as &$element) {
            if ($element['id'] === $widget_id) {
                return $element;
            }
            if (!empty($element['elements'])) {
                $found = &$this->find_widget_by_id($element['elements'], $widget_id);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return $null;
    }

    /**
     * Update widget settings via deep merge
     */
    public function update_widget_settings(&$elements, $widget_id, $new_settings) {
        $widget = &$this->find_widget_by_id($elements, $widget_id);
        if ($widget === null) {
            return false;
        }

        if (!isset($widget['settings'])) {
            $widget['settings'] = array();
        }

        $widget['settings'] = $this->deep_merge($widget['settings'], $new_settings);
        return true;
    }

    /**
     * Add a new widget to a container
     */
    public function add_widget(&$elements, $target_id, $widget_data, $position = 'end') {
        $container = &$this->find_widget_by_id($elements, $target_id);
        if ($container === null) {
            return false;
        }

        if (!in_array($container['elType'], array('section', 'column', 'container'), true)) {
            return false;
        }

        if (!isset($container['elements'])) {
            $container['elements'] = array();
        }

        $new_widget = array(
            'id'         => isset($widget_data['id']) ? $widget_data['id'] : $this->generate_widget_id(),
            'elType'     => 'widget',
            'widgetType' => $widget_data['widgetType'],
            'settings'   => $widget_data['settings'] ?? array(),
            'elements'   => array(),
        );

        if ($position === 'start') {
            array_unshift($container['elements'], $new_widget);
        } elseif (strpos($position, 'after:') === 0) {
            $after_id = substr($position, 6);
            $inserted = false;
            foreach ($container['elements'] as $i => $el) {
                if ($el['id'] === $after_id) {
                    array_splice($container['elements'], $i + 1, 0, array($new_widget));
                    $inserted = true;
                    break;
                }
            }
            if (!$inserted) {
                $container['elements'][] = $new_widget;
            }
        } else {
            $container['elements'][] = $new_widget;
        }

        return $new_widget['id'];
    }

    /**
     * Remove a widget by ID
     */
    public function remove_widget(&$elements, $widget_id) {
        foreach ($elements as $index => &$element) {
            if ($element['id'] === $widget_id) {
                array_splice($elements, $index, 1);
                return true;
            }
            if (!empty($element['elements'])) {
                if ($this->remove_widget($element['elements'], $widget_id)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Update section/container settings
     */
    public function update_section_settings(&$elements, $section_id, $new_settings) {
        $section = &$this->find_widget_by_id($elements, $section_id);
        if ($section === null) {
            return false;
        }

        if (!in_array($section['elType'], array('section', 'container'), true)) {
            return false;
        }

        if (!isset($section['settings'])) {
            $section['settings'] = array();
        }

        $section['settings'] = $this->deep_merge($section['settings'], $new_settings);
        return true;
    }

    /**
     * Deep merge: new values override, existing values preserved
     */
    public function deep_merge($original, $new) {
        foreach ($new as $key => $value) {
            if (is_array($value) && isset($original[$key]) && is_array($original[$key])
                && $this->is_assoc($value) && $this->is_assoc($original[$key])) {
                $original[$key] = $this->deep_merge($original[$key], $value);
            } else {
                $original[$key] = $value;
            }
        }
        return $original;
    }

    private function is_assoc($arr) {
        if (!is_array($arr) || empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    public function generate_widget_id() {
        return substr(md5(uniqid(mt_rand(), true)), 0, 7);
    }

    /**
     * Summarize container/section settings (backgrounds, title, layout basics)
     */
    public function summarize_container_settings($settings) {
        $summary = array();
        $container_keys = array(
            '_title', 'background_background', 'background_image', 'background_color',
            'background_overlay_background', 'background_overlay_color',
            'background_position', 'background_size', 'background_repeat',
            'min_height', 'content_width', 'flex_direction', 'html_tag',
        );

        foreach ($container_keys as $key) {
            if (isset($settings[$key])) {
                $value = $settings[$key];
                if (is_string($value) && strlen($value) > 200) {
                    $value = substr($value, 0, 200) . '...';
                }
                $summary[$key] = $value;
            }
        }
        return $summary;
    }

    public function summarize_settings($settings) {
        $content_keys = array(
            'title', 'editor', 'text', 'title_text', 'description_text',
            'starting_number', 'ending_number', 'image', 'link', 'html',
            'testimonial_content', 'testimonial_name', 'testimonial_job',
            'icon', 'badge_title', 'ribbon_title', 'alert_title',
            'alert_description', 'tab_title', 'price', 'currency',
            'period', 'item_description', 'button_text',
        );

        $summary = array();
        foreach ($content_keys as $key) {
            if (isset($settings[$key])) {
                $value = $settings[$key];
                if (is_string($value) && strlen($value) > 200) {
                    $value = substr($value, 0, 200) . '...';
                }
                $summary[$key] = $value;
            }
        }
        return $summary;
    }

    private function extract_plain_text($elements) {
        $text_parts = array();
        foreach ($elements as $element) {
            if (!empty($element['settings'])) {
                $s = $element['settings'];
                foreach (array('title', 'editor', 'text', 'title_text', 'description_text') as $key) {
                    if (!empty($s[$key]) && is_string($s[$key])) {
                        $text_parts[] = wp_strip_all_tags($s[$key]);
                    }
                }
            }
            if (!empty($element['elements'])) {
                $child_text = $this->extract_plain_text($element['elements']);
                if (!empty($child_text)) {
                    $text_parts[] = $child_text;
                }
            }
        }
        return implode("\n\n", $text_parts);
    }
}
