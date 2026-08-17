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

        // The featured image is NEVER taken from the AI article JSON. The image
        // manager must search the exact blog keyword, download 10 real images,
        // send all 10 pixels to the vision model, and return one winner.
        // There is no Picsum, random, metadata, or URL fallback.
        $image_data = $this->image_manager->get_image_for_post($post_data);
        if (empty($image_data) || empty($image_data['url'])) {
            $logger->log("Image selection failed for keyword '" . ($post_data['keyword'] ?? '') . "'. The generated post will be saved as DRAFT; no fallback image will be used.", 'error');
            return false;
        }
        $image_url = $image_data['url'];
        $logger->log("Publishing will use AI-selected Unsplash image: Candidate #" . ($image_data['candidate_id'] ?? '?') . "; score=" . ($image_data['score'] ?? '?') . "/100; ID=" . ($image_data['id'] ?? 'unknown'), 'success');

        // Add nofollow to external links
        $link_manager = new AIA_Link_Manager();
        $content = $link_manager->add_nofollow_to_external_links($content);

        $post_args = [
            'post_title' => sanitize_text_field($post_data['title']),
            'post_content' => $content,
            'post_author' => intval($post_data['author_id']),
            // Create as draft first. The post is only published after the
            // required AI-selected featured image has been successfully
            // downloaded and attached. If image selection/attachment fails,
            // this draft is intentionally kept for manual review/retry.
            'post_status' => 'draft',
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

        // Attach the already AI-selected Unsplash image. If the actual image
        // cannot be attached, KEEP the generated post as a draft rather than
        // deleting it or publishing without a relevant featured image.
        if (!$this->set_featured_image($post_id, $image_url, $post_data['keyword'])) {
            $logger->log("Featured image download/attachment failed. Keeping post {$post_id} as DRAFT; no fallback image will be used.", 'error');
            return false;
        }

        // Only publish after the AI-selected image has been successfully
        // attached. This prevents an image failure from ever producing a
        // published post.
        $status_update = wp_update_post(array(
            'ID' => $post_id,
            'post_status' => 'publish',
        ), true);

        if (is_wp_error($status_update)) {
            $logger->log("Featured image was attached, but post {$post_id} could not be published. Keeping it as DRAFT: " . $status_update->get_error_message(), 'error');
            return false;
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
        if (empty($image_url)) {
            return false;
        }

        $tmp = download_url($image_url, 60);
        if (is_wp_error($tmp) || !file_exists($tmp) || filesize($tmp) < 100) {
            if (is_string($tmp) && file_exists($tmp)) {
                @unlink($tmp);
            }
            return false;
        }

        // Do not trust the Unsplash URL filename. Unsplash image URLs often do
        // not contain a real extension, while WordPress/Elementor expects a
        // valid attachment file + metadata. Determine the actual image type.
        $image_info = @getimagesize($tmp);
        if (!is_array($image_info) || empty($image_info['mime'])) {
            @unlink($tmp);
            return false;
        }

        $mime_to_extension = array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        );

        $mime = strtolower((string) $image_info['mime']);
        if (!isset($mime_to_extension[$mime])) {
            @unlink($tmp);
            return false;
        }

        $extension = $mime_to_extension[$mime];
        $filename = 'featured-image-' . absint($post_id) . '.' . $extension;

        $file_array = array(
            'name'     => sanitize_file_name($filename),
            'tmp_name' => $tmp,
            'type'     => $mime,
            'size'     => (int) filesize($tmp),
        );

        // media_handle_sideload creates the attachment and normally generates
        // metadata. Supplying a real extension/type here prevents Elementor's
        // BFI thumbnail library from receiving an attachment without an
        // extension.
        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return false;
        }

        // Repair/verify the attachment metadata immediately. This is important
        // for Elementor's BFI thumbnail code, which reads the image metadata.
        $attached_file = get_attached_file($attachment_id);
        if (!$attached_file || !file_exists($attached_file)) {
            wp_delete_attachment($attachment_id, true);
            return false;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!is_array($metadata) || empty($metadata['file']) || empty($metadata['width']) || empty($metadata['height'])) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata($attachment_id, $attached_file);
            if (is_array($metadata) && !empty($metadata)) {
                wp_update_attachment_metadata($attachment_id, $metadata);
            }
        }

        // Ensure the attachment always has a valid MIME type and attached file.
        update_post_meta($attachment_id, '_wp_attachment_metadata_version', '1');
        wp_update_post(array(
            'ID'             => $attachment_id,
            'post_mime_type' => $mime,
        ));

        set_post_thumbnail($post_id, $attachment_id);

        if (!empty($keyword)) {
            update_post_meta(
                $attachment_id,
                '_wp_attachment_image_alt',
                sanitize_text_field($keyword)
            );
        }

        return true;
    }

    private function extract_json_article_content($raw) {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '' || strpos($raw, '{') !== 0 || stripos($raw, 'content') === false) {
            return '';
        }

        $normalized = str_replace(array('“', '”'), '"', $raw);
        $normalized = str_replace(array('‘', '’'), "'", $normalized);

        // Escape literal control characters only while inside JSON strings.
        $out = '';
        $in_string = false;
        $escaped = false;
        $length = strlen($normalized);
        for ($i = 0; $i < $length; $i++) {
            $char = $normalized[$i];
            if ($in_string) {
                if ($escaped) {
                    $out .= $char;
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $out .= $char;
                    $escaped = true;
                    continue;
                }
                if ($char === '"') {
                    $in_string = false;
                    $out .= $char;
                    continue;
                }
                if ($char === "\n") { $out .= '\\n'; continue; }
                if ($char === "\r") { $out .= '\\r'; continue; }
                if ($char === "\t") { $out .= '\\t'; continue; }
                $out .= $char;
            } else {
                if ($char === '"') $in_string = true;
                $out .= $char;
            }
        }

        $decoded = json_decode($out, true);
        if (is_array($decoded) && !empty($decoded['content'])) {
            return (string) $decoded['content'];
        }

        // Last-resort extraction for a malformed object. Never return the whole
        // JSON payload as article content.
        if (preg_match('/["“]content["”]\s*:\s*["“]/i', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $tail = substr($raw, $start);
            $tail = preg_replace('/\s*["”]\s*}\s*$/s', '', $tail);
            $tail = str_replace(array('“', '”'), '"', $tail);
            $tail = str_replace(array('\\r\\n', '\\n', '\\t', '\\/', '\\"'), array("\n", "\n", "    ", '/', '"'), $tail);
            return trim($tail);
        }

        return '';
    }

    private function prepare_content($content) {
        $content = is_string($content) ? $content : '';
        $content = trim($content);

        // Decode only known JSON/AI escapes. Never call stripslashes() here:
        // it converts \n into the literal letter "n" and breaks article layout.
        $content = str_replace('\\r\\n', "\r\n", $content);
        $content = str_replace('\\n', "\n", $content);
        $content = str_replace('\\t', "\t", $content);
        $content = str_replace('\\/', '/', $content);
        $content = str_replace('\\"', '"', $content);

        $content = preg_replace('/^```(?:html|HTML|json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = trim($content);

        // Remove accidental image markup from the article body. Featured-image
        // handling is done separately by the publisher.
        $content = preg_replace('/<figure[^>]*>.*?<\/figure>/is', '', $content);
        $content = preg_replace('/<img\b[^>]*>/i', '', $content);

        // Recover malformed AI JSON before WordPress ever sees it. This also
        // handles smart/curly quotes and literal newlines inside the content field.
        $json_content = $this->extract_json_article_content($content);
        if ($json_content !== '') {
            $content = $json_content;
        }

        // Normalize line endings and blank lines.
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace("/\n{3,}/", "\n\n", $content);
        $content = trim($content);

        // If the AI returned valid HTML, preserve the HTML structure exactly.
        // Only plain-text content is wrapped into paragraphs.
        if (preg_match('/<\/?(p|h[1-6]|ul|ol|table|div|blockquote|section|figure|pre|hr)\b/i', $content)) {
            return trim($content);
        }

        $paragraphs = preg_split('/\n\s*\n/', $content);
        $formatted = '';
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            $formatted .= '<p>' . nl2br(esc_html($para)) . '</p>\n\n';
        }

        return trim($formatted);
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

        // update_post() intentionally does NOT change the featured image.
        // Regeneration must preserve the existing featured image/media.

        $post_id = wp_update_post($post_args, true);
        if (is_wp_error($post_id)) return false;

        $logger = new AIA_Logger();
        $logger->log("Post updated successfully. ID: {$post_id}", 'success');
        return $post_id;
    }
}