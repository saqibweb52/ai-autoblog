<?php
// includes/class-publisher.php
if (!defined('ABSPATH')) exit;

class AIA_Publisher {

    private $image_manager;

    public function __construct() {
        $this->image_manager = new AIA_Image_Manager();
    }

    public function publish_post($post_data) {
        $logger = new AIA_Logger();
        $logger->log("Publishing post with title: " . ($post_data['title'] ?? 'NOT SET'), 'debug');

        if (empty($post_data['content']) || strlen(strip_tags($post_data['content'])) < 300) {
            $logger->log("Content too short to publish.", 'error');
            return false;
        }

        $content = $this->prepare_content($post_data['content']);

        // Add nofollow to external links
        $link_manager = new AIA_Link_Manager();
        $content = $link_manager->add_nofollow_to_external_links($content);

        $post_args = [
            'post_title' => sanitize_text_field($post_data['title']),
            'post_content' => $content,
            'post_author' => intval($post_data['author_id']),
            'post_status' => 'publish',
            'post_type' => 'post',
            'meta_input' => [
                '_aia_generated' => true,
                '_aia_keyword' => sanitize_text_field($post_data['keyword']),
            ]
        ];

        if (!empty($post_data['categories']) && is_array($post_data['categories'])) {
            $post_args['post_category'] = array_map('intval', $post_data['categories']);
        }

        if (!empty($post_data['meta_description'])) {
            $meta_description = sanitize_textarea_field($post_data['meta_description']);
            $post_args['meta_input']['_aia_meta_description'] = $meta_description;
            if (defined('WPSEO_VERSION')) {
                $post_args['meta_input']['_yoast_wpseo_metadesc'] = $meta_description;
            }
            if (defined('RANK_MATH_VERSION')) {
                $post_args['meta_input']['rank_math_description'] = $meta_description;
            }
            if (defined('AIOSEO_VERSION')) {
                $post_args['meta_input']['_aioseo_description'] = $meta_description;
            }
        }

        if (!empty($post_data['excerpt'])) {
            $post_args['post_excerpt'] = sanitize_textarea_field($post_data['excerpt']);
        }

        $post_id = wp_insert_post($post_args, true);

        if (is_wp_error($post_id)) {
            $logger->log("Failed to publish post: " . $post_id->get_error_message(), 'error');
            return false;
        }

        // Set featured image
        $image_data = $this->image_manager->get_image_for_post($post_data);
        if ($image_data && isset($image_data['url'])) {
            $this->set_featured_image($post_id, $image_data['url'], $post_data['keyword']);
        }

        if (!empty($post_data['keyword']) && defined('WPSEO_VERSION')) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($post_data['keyword']));
        }
        if (!empty($post_data['keyword']) && defined('RANK_MATH_VERSION')) {
            update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($post_data['keyword']));
        }

        $logger->log("Post published successfully. ID: {$post_id}", 'success');

        if (class_exists('AIA_IndexNow')) {
            try {
                $indexnow = new AIA_IndexNow();
                $indexnow->submit_post_to_indexnow($post_id, 'publish');
            } catch (Exception $e) {
                $logger->log("IndexNow notification failed: " . $e->getMessage(), 'error');
            }
        }

        return $post_id;
    }

    private function set_featured_image($post_id, $image_url, $keyword = '') {
        if (empty($image_url)) return false;

        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) return false;

        $filename = basename(parse_url($image_url, PHP_URL_PATH));
        if (empty($filename) || strpos($filename, '?') !== false) {
            $filename = 'featured-image-' . $post_id . '.jpg';
        }

        $file_array = ['name' => $filename, 'tmp_name' => $tmp];
        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            return false;
        }

        set_post_thumbnail($post_id, $attachment_id);
        if (!empty($keyword)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($keyword));
        }
        return true;
    }

    private function prepare_content($content) {
        $content = str_replace('\\n', "\n", $content);
        $content = str_replace('\n', "\n", $content);
        $content = str_replace('\\"', '"', $content);
        $content = str_replace('\"', '"', $content);
        $content = str_replace('\\t', "\t", $content);
        $content = str_replace('\t', "\t", $content);
        $content = str_replace('\\/', '/', $content);
        $content = str_replace('\/', '/', $content);
        $content = stripslashes($content);

        if (preg_match('/^{"seo_title":.*?"content":"(.*)"}$/s', $content, $matches)) {
            $content = $matches[1];
            $content = str_replace('\\n', "\n", $content);
            $content = str_replace('\\"', '"', $content);
            $content = str_replace('\\t', "\t", $content);
            $content = stripslashes($content);
        }

        $content = preg_replace('/\\\\([nrt"])/', '$1', $content);
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = preg_replace('/<figure[^>]*>.*?<\/figure>/s', '', $content);
        $content = preg_replace('/<img[^>]*>/i', '', $content);
        $content = preg_replace('/^nn\s*/m', '', $content);
        $content = preg_replace('/\snn\s/', ' ', $content);
        $content = preg_replace('/n\s*$/m', '', $content);
        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        $paragraphs = explode("\n\n", $content);
        $formatted = '';
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (empty($para)) continue;
            if (preg_match('/^<[a-z]/i', $para)) {
                $formatted .= $para . "\n\n";
            } else {
                $formatted .= '<p>' . nl2br($para) . '</p>' . "\n\n";
            }
        }
        $content = trim($formatted);

        $content = preg_replace('/^[\s]*[-•*]\s+(.*)$/m', '<li>$1</li>', $content);
        $content = preg_replace('/(<li>.*?<\/li>\s*)+/', '<ul style="display:flex;flex-direction:column;gap:6px;list-style:none;padding-left:0;">$0</ul>', $content);

        $content = preg_replace('/\s+n\s+/', ' ', $content);
        $content = preg_replace('/^n\s*/m', '', $content);
        $content = trim($content);

        if (!empty($content) && !preg_match('/^<[a-z]/i', $content)) {
            $content = '<div class="aia-post-content">' . $content . '</div>';
        }

        return $content;
    }

    public function update_post($post_id, $post_data) {
        if (empty($post_data['content'])) return false;

        $content = $this->prepare_content($post_data['content']);
        $link_manager = new AIA_Link_Manager();
        $content = $link_manager->add_nofollow_to_external_links($content);

        $post_args = [
            'ID' => $post_id,
            'post_title' => sanitize_text_field($post_data['title']),
            'post_content' => $content,
            'post_author' => intval($post_data['author_id']),
        ];

        if (!empty($post_data['categories']) && is_array($post_data['categories'])) {
            $post_args['post_category'] = array_map('intval', $post_data['categories']);
        }

        if (!empty($post_data['meta_description'])) {
            $meta_description = sanitize_textarea_field($post_data['meta_description']);
            update_post_meta($post_id, '_aia_meta_description', $meta_description);

            if (defined('WPSEO_VERSION')) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
            }
            if (defined('RANK_MATH_VERSION')) {
                update_post_meta($post_id, 'rank_math_description', $meta_description);
            }
            if (defined('AIOSEO_VERSION')) {
                update_post_meta($post_id, '_aioseo_description', $meta_description);
            }
        }

        if (!empty($post_data['keyword'])) {
            update_post_meta($post_id, '_aia_keyword', sanitize_text_field($post_data['keyword']));
        }

        if (!empty($post_data['featured_image'])) {
            $this->set_featured_image($post_id, $post_data['featured_image']);
        }

        $post_id = wp_update_post($post_args, true);
        if (is_wp_error($post_id)) return false;

        $logger = new AIA_Logger();
        $logger->log("Post updated successfully. ID: {$post_id}", 'success');
        return $post_id;
    }
}