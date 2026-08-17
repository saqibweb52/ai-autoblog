<?php
// includes/class-images.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Image_Manager {

    private $unsplash_access_key;
    private $logger;

    public function __construct() {
        $this->unsplash_access_key = get_option('aia_unsplash_access_key', '');
        $this->logger = new AIA_Logger();
    }

    /**
     * Find the best Unsplash image for the complete article context.
     *
     * Flow:
     * 1. Build an article-aware search query from title + article text.
     * 2. Search Unsplash for 10 landscape candidates.
     * 3. Download the 10 candidates at up to 1080px wide.
     * 4. Send all 10 images + article context to Gemini Vision.
     * 5. Select the highest-ranked image.
     */
    public function get_image_for_post($post_data) {
        if (empty($this->unsplash_access_key)) {
            $this->logger->log('IMAGE FAILED: Unsplash API key is not configured.', 'error');
            return false;
        }

        if (is_string($post_data)) {
            $post_data = array('keyword' => $post_data, 'title' => $post_data);
        }

        if (!is_array($post_data)) {
            $this->logger->log('IMAGE FAILED: Invalid post data.', 'error');
            return false;
        }

        $keyword = trim((string) ($post_data['keyword'] ?? ''));
        $title = trim((string) ($post_data['title'] ?? $keyword));
        $article = trim((string) ($post_data['content'] ?? ''));

        if ($keyword === '' && $title === '') {
            $this->logger->log('IMAGE FAILED: No keyword/title available.', 'error');
            return false;
        }

        /*
         * Use the actual blog keyword as the Unsplash search query.
         * Do not let AI rewrite the keyword into a different search query.
         *
         * Unsplash finds the candidates for the exact SEO topic first.
         * The multimodal AI then looks at the actual image pixels and ranks
         * those candidates by visual relevance to the article.
         */
        $query = $keyword !== '' ? $keyword : $title;
        $query = trim(preg_replace('/\\s+/', ' ', wp_strip_all_tags($query)));

        if ($query === '') {
            $this->logger->log('IMAGE FAILED: Empty blog keyword/search query.', 'error');
            return false;
        }

        $this->logger->log('IMAGE: Unsplash search using blog keyword: ' . $query, 'info');

        // Fetch up to 10 candidates for visual AI ranking.
        $images = $this->search_unsplash_with_keyword($query, 10);

        if (empty($images)) {
            $this->logger->log("IMAGE FAILED: Unsplash returned no candidates for keyword '{$query}'.", 'error');
            return false;
        }

        $images = array_slice($images, 0, 10);
        if (count($images) !== 10) {
            $this->logger->log('IMAGE FAILED: Unsplash returned only ' . count($images) . ' candidates; exactly 10 are required. No fallback image will be used.', 'error');
            return false;
        }

        foreach ($images as $i => $candidate) {
            $this->logger->log('IMAGE CANDIDATE #' . ($i + 1) . ': Unsplash ID=' . ($candidate['id'] ?? 'unknown') . '; alt=' . sanitize_text_field($candidate['alt_description'] ?? ''), 'debug');
        }

        $scored = $this->score_images_with_ai($images, $keyword, $title, $article);

        if (empty($scored)) {
            // Visual AI failed. Use ONLY the same 10 Unsplash candidates and
            // choose the best match by alt-text/description relevance. This is
            // a controlled textual fallback, never a random or external image.
            $this->logger->log('IMAGE AI: Visual selection failed. Falling back to alt-text matching across the same 10 Unsplash candidates.', 'warning');
            $scored = $this->select_by_alt_text_match($images, $keyword, $title);

            if (empty($scored)) {
                $this->logger->log('IMAGE FAILED: Alt-text fallback could not select a candidate from the 10 Unsplash images.', 'error');
                return false;
            }
        }

        if (empty($scored) || empty($scored[0]['image'])) {
            $this->logger->log('IMAGE FAILED: No suitable image after scoring.', 'error');
            return false;
        }

        $selected = $scored[0]['image'];
        $image_url = $this->build_1080_url($selected['urls']['regular'] ?? ($selected['urls']['full'] ?? ''));

        if ($image_url === '') {
            $this->logger->log('IMAGE FAILED: Selected Unsplash image has no usable URL.', 'error');
            return false;
        }

        $score = isset($scored[0]['score']) ? (int) $scored[0]['score'] : 0;
        $reason = isset($scored[0]['reason']) ? sanitize_text_field($scored[0]['reason']) : '';

        $this->logger->log("IMAGE: Selected candidate #" . ((int) ($scored[0]['index'] ?? 0) + 1) . " with AI score {$score}/100" . ($reason ? " ({$reason})" : ''), 'success');

        // Unsplash requires a download-location request when an image is used.
        if (!empty($selected['links']['download_location'])) {
            wp_remote_get($selected['links']['download_location'], array(
                'headers' => array(
                    'Authorization' => 'Client-ID ' . $this->unsplash_access_key
                ),
                'timeout' => 10
            ));
        }

        return array(
            'url' => $image_url,
            'alt' => $title !== '' ? $title : $keyword,
            'credit' => $selected['user']['name'] ?? 'Unsplash',
            'id' => $selected['id'] ?? '',
            'candidate_id' => (int) ($scored[0]['candidate_id'] ?? ((int) ($scored[0]['index'] ?? 0) + 1)),
            'score' => $score,
            'source' => 'Unsplash',
            'query' => $query,
            'reason' => $reason,
        );
    }

    /**
     * Build a visual search query from the article rather than searching only
     * the raw SEO keyword. Unsplash works better with concrete visual concepts.
     */
    /**
     * Ask the currently selected AI provider for 3 concrete Unsplash queries.
     * The queries describe what should literally be visible in the photograph,
     * not abstract SEO terms.
     */
    private function build_ai_visual_queries($keyword, $title, $article) {
        $provider = strtolower(trim((string) get_option('aia_ai_provider', 'gemini')));
        $article_text = wp_strip_all_tags($article);
        $article_text = preg_replace('/\s+/', ' ', $article_text);
        $article_text = trim($article_text);

        $prompt = "Create exactly 3 highly specific photo-search queries for Unsplash for this blog article.\n\n" .
            "Focus keyword: {$keyword}\n" .
            "Title: {$title}\n" .
            "Article: " . substr($article_text, 0, 5000) . "\n\n" .
            "Rules:\n" .
            "- Describe the MAIN SUBJECT that must be visibly present in the photograph.\n" .
            "- Prefer concrete nouns, objects, places, people, animals, activities, or technology.\n" .
            "- Do not use broad SEO words such as guide, tips, business, success, modern, strategy, solution, future, article, concept, technology unless they are literally the subject.\n" .
            "- Do not create metaphorical queries.\n" .
            "- Do not request text overlays, illustrations, logos, or screenshots.\n" .
            "- Each query should be 3-8 words.\n" .
            "Return ONLY JSON in this form: {\"queries\":[\"query 1\",\"query 2\",\"query 3\"]}";

        $text = '';
        if ($provider === 'gemini') {
            $text = $this->text_generate_with_gemini($prompt);
        } elseif ($provider === 'glm') {
            $text = $this->text_generate_with_glm($prompt);
        }

        if ($text !== '') {
            $data = json_decode(trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text))), true);
            if (is_array($data) && isset($data['queries']) && is_array($data['queries'])) {
                $queries = array();
                foreach ($data['queries'] as $q) {
                    $q = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $q)));
                    if ($q !== '' && !in_array(strtolower($q), array_map('strtolower', $queries), true)) {
                        $queries[] = substr($q, 0, 100);
                    }
                    if (count($queries) >= 3) break;
                }
                if (!empty($queries)) return $queries;
            }
        }

        return array();
    }

    private function text_generate_with_gemini($prompt) {
        $api_key = get_option('aia_api_key', '');
        $model = get_option('aia_gemini_model', 'gemini-2.5-flash');
        if (!$api_key || !$model) return '';

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($api_key);
        $body = array(
            'contents' => array(array('parts' => array(array('text' => $prompt)))),
            'generationConfig' => array('temperature' => 0.1, 'maxOutputTokens' => 512, 'responseMimeType' => 'application/json')
        );
        $response = wp_remote_post($url, array('headers' => array('Content-Type' => 'application/json'), 'body' => wp_json_encode($body), 'timeout' => 60));
        if (is_wp_error($response)) return '';
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    private function text_generate_with_glm($prompt) {
        $api_key = get_option('aia_glm_api_key', '');
        $model = get_option('aia_glm_model', 'glm-4.5-flash');
        if (!$api_key || !$model) return '';

        $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
        $body = array(
            'model' => $model,
            'messages' => array(array('role' => 'user', 'content' => $prompt)),
            'temperature' => 0.1,
            'max_tokens' => 512,
            'response_format' => array('type' => 'json_object')
        );
        $response = wp_remote_post($url, array('headers' => array('Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key), 'body' => wp_json_encode($body), 'timeout' => 60));
        if (is_wp_error($response)) return '';
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return (string) ($data['choices'][0]['message']['content'] ?? '');
    }

    /** Deterministic fallback query if the selected AI cannot create queries. */
    private function build_image_query($keyword, $title, $article) {
        $base = trim($keyword !== '' ? $keyword : $title);
        $base = preg_replace('/\s+/', ' ', wp_strip_all_tags($base));
        $base = preg_replace('/\b(guide|tips|best|complete|ultimate|how to|everything|learn|2026)\b/i', '', $base);
        $base = trim(preg_replace('/\s+/', ' ', $base));
        return substr($base, 0, 100);
    }

    /** Search Unsplash and return landscape candidates. */
    public function search_unsplash_with_keyword($keyword, $per_page = 10) {
        if (empty($this->unsplash_access_key)) {
            return array();
        }

        $per_page = min(10, max(1, intval($per_page)));
        $url = add_query_arg(array(
            'query' => $keyword,
            'per_page' => $per_page,
            'orientation' => 'landscape',
            'order_by' => 'relevance'
        ), 'https://api.unsplash.com/search/photos');

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Client-ID ' . $this->unsplash_access_key,
                'Accept-Version' => 'v1'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            $this->logger->log('UNSPLASH FAILED: ' . $response->get_error_message(), 'error');
            return array();
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200 || !isset($data['results']) || !is_array($data['results'])) {
            $this->logger->log('UNSPLASH FAILED: HTTP ' . $status, 'error');
            return array();
        }

        $this->logger->log('UNSPLASH: Found ' . count($data['results']) . ' candidates.', 'debug');
        return $data['results'];
    }

    /**
     * Send the 10 candidate images to Gemini as inline image data.
     * The article context is included so Gemini scores visual relevance,
     * not merely whether Unsplash metadata contains the keyword.
     */
    /**
     * Send the candidate images to the AI provider selected in the main AI settings.
     *
     * Supported providers:
     * - Gemini: multimodal generateContent with inline image data.
     * - GLM: multimodal chat completions with data:image/* base64 image URLs.
     *
     * IMPORTANT: The selected provider is read from aia_ai_provider, so image
     * recognition does not always go through Gemini.
     */
    private function score_images_with_ai($images, $keyword, $title, $article) {
        $provider = strtolower(trim((string) get_option('aia_ai_provider', 'gemini')));

        $article_text = wp_strip_all_tags($article);
        $article_text = preg_replace('/\s+/', ' ', $article_text);
        $article_excerpt = substr(trim($article_text), 0, 5000);

        // The automatic pipeline is strict: exactly 10 real image files must be
        // downloaded and sent to the multimodal model. There is no metadata,
        // random-image, Picsum, or single-image fallback.
        $this->logger->log(
            "IMAGE AI: Preparing exactly 10 REAL image candidates for visual ranking. Provider={$provider}; keyword='{$keyword}'",
            'info'
        );

        $candidates = array();
        $attempted = 0;

        foreach ($images as $index => $image) {
            if (count($candidates) >= 10) {
                break;
            }

            $attempted++;
            $image_url = $this->build_ai_image_url($image['urls']['regular'] ?? ($image['urls']['full'] ?? ''));
            if ($image_url === '') {
                $this->logger->log("IMAGE AI: Candidate #" . ($index + 1) . " has no usable Unsplash URL.", 'warning');
                continue;
            }

            $this->logger->log(
                "IMAGE AI: Candidate #" . ($index + 1) . " download URL: {$image_url}",
                'debug'
            );

            $download = wp_remote_get($image_url, array(
                'timeout' => 30,
                'redirection' => 5,
                'headers' => array('Accept' => 'image/jpeg,image/webp,image/*;q=0.8')
            ));

            if (is_wp_error($download)) {
                $this->logger->log(
                    "IMAGE AI: Candidate #" . ($index + 1) . " download failed: " . $download->get_error_message(),
                    'warning'
                );
                continue;
            }

            $status = wp_remote_retrieve_response_code($download);
            $binary = wp_remote_retrieve_body($download);
            $mime = wp_remote_retrieve_header($download, 'content-type');

            if ($status < 200 || $status >= 300 || $binary === '' || strlen($binary) < 1000) {
                $this->logger->log(
                    "IMAGE AI: Candidate #" . ($index + 1) . " invalid image response. HTTP={$status}; bytes=" . strlen($binary),
                    'warning'
                );
                continue;
            }

            if (!is_string($mime) || strpos(strtolower($mime), 'image/') !== 0) {
                $mime = 'image/jpeg';
            } else {
                $mime = strtolower(trim(explode(';', $mime)[0]));
            }

            // If Unsplash still returns a very large file, do not silently skip
            // it. Try once more with a smaller JPEG delivery URL so the actual
            // photograph can still be sent to the vision model.
            if (strlen($binary) > 3 * 1024 * 1024) {
                $smaller_url = $this->build_ai_image_url($image['urls']['regular'] ?? ($image['urls']['full'] ?? ''), 640, 60);
                $smaller = wp_remote_get($smaller_url, array('timeout' => 30, 'redirection' => 5));
                if (!is_wp_error($smaller) && wp_remote_retrieve_response_code($smaller) >= 200 && wp_remote_retrieve_response_code($smaller) < 300) {
                    $smaller_binary = wp_remote_retrieve_body($smaller);
                    if (strlen($smaller_binary) >= 1000 && strlen($smaller_binary) < strlen($binary)) {
                        $binary = $smaller_binary;
                        $mime = 'image/jpeg';
                    }
                }
            }

            $candidate_id = count($candidates) + 1;
            $candidates[] = array(
                'candidate_id' => $candidate_id,
                'original_index' => (int) $index,
                'binary' => $binary,
                'mime' => $mime,
                'bytes' => strlen($binary),
                'image' => $image
            );

            $this->logger->log(
                "IMAGE AI: Candidate #{$candidate_id} READY — real image downloaded; " .
                "Unsplash ID=" . ($image['id'] ?? 'unknown') . "; bytes=" . strlen($binary),
                'success'
            );
        }

        $this->logger->log(
            'IMAGE AI: Real-image candidate preparation complete. Requested=10; searched=' . count($images) . '; attempted=' . $attempted . '; ready=' . count($candidates),
            'info'
        );

        if (count($candidates) !== 10) {
            $this->logger->log(
                'IMAGE FAILED: Exactly 10 real images could not be prepared for AI. No fallback image will be used.',
                'error'
            );
            return array();
        }

        $prompt =
            "You are choosing ONE featured image for a blog article.\n\n" .
            "Focus keyword: {$keyword}\n" .
            "Article title: {$title}\n" .
            "Article excerpt: {$article_excerpt}\n\n" .
            "You will receive EXACTLY 10 real photographs. Candidate #1 through #10 correspond to the images in the same order.\n" .
            "You MUST inspect the actual pixels of ALL 10 images before deciding. Ignore filenames, URLs, Unsplash metadata, image IDs, candidate order, and aesthetics that are unrelated to the topic.\n\n" .
            "Ranking rules:\n" .
            "1. Direct visual relevance to the actual article subject: 60 points.\n" .
            "2. Clearly shows the main subject or activity discussed in the article: 25 points.\n" .
            "3. Suitable professional featured-image composition: 10 points.\n" .
            "4. Clean image with no distracting watermark/logo/text: 5 points.\n\n" .
            "Generic stock photos must score very low when the actual subject is not visible. A generic laptop, office, business meeting, person, handshake, abstract technology, city, or nature photograph is NOT relevant unless the article is specifically about that subject.\n\n" .
            "You MUST score ALL 10 candidates and then select EXACTLY ONE winner from those same 10 candidates. Do not invent an image and do not return an index outside 1-10.\n" .
            "Return ONLY this JSON object:\n" .
            "{\"selected_index\":7,\"scores\":[{\"index\":1,\"score\":20,\"reason\":\"brief visual reason\"},{\"index\":2,\"score\":85,\"reason\":\"brief visual reason\"}]}\n" .
            "The scores array MUST contain exactly 10 entries, one for every index 1 through 10.";

        $this->logger->log('IMAGE AI: Sending exactly 10 REAL image files to ' . strtoupper($provider) . ' for visual inspection.', 'info');

        if ($provider === 'gemini') {
            return $this->score_images_with_gemini($candidates, $images, $prompt);
        }

        if ($provider === 'glm') {
            return $this->score_images_with_glm($candidates, $images, $prompt);
        }

        $this->logger->log("IMAGE FAILED: Unsupported visual AI provider '{$provider}'. No fallback image will be used.", 'error');
        return array();
    }

    private function build_ai_image_url($url, $width = 768, $quality = 65) {
        if (empty($url)) {
            return '';
        }

        return add_query_arg(array(
            'auto' => 'format',
            'fit' => 'max',
            'w' => absint($width),
            'q' => absint($quality),
            'fm' => 'jpg'
        ), $url);
    }

    private function score_images_with_gemini($candidates, $images, $prompt) {
        $api_key = get_option('aia_api_key', '');
        $model = get_option('aia_gemini_model', 'gemini-2.0-flash');

        if (empty($api_key) || empty($model)) {
            $this->logger->log('IMAGE AI FAILED: Gemini API key/model is not configured.', 'error');
            return array();
        }

        $parts = array(array('text' => $prompt));
        foreach ($candidates as $candidate) {
            $parts[] = array('text' => 'Candidate image #' . $candidate['candidate_id'] . '. Inspect this actual image.');
            $parts[] = array(
                'inline_data' => array(
                    'mime_type' => $candidate['mime'],
                    'data' => base64_encode($candidate['binary'])
                )
            );
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($api_key);
        $body = array(
            'contents' => array(array('parts' => $parts)),
            'generationConfig' => array(
                'responseMimeType' => 'application/json',
                'temperature' => 0.1,
                'maxOutputTokens' => 4096
            )
        );

        $this->logger->log('GEMINI IMAGE AI: Uploading 10 real image files in one multimodal request. Model=' . $model, 'debug');
        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($body),
            'timeout' => 180
        ));

        if (is_wp_error($response)) {
            $this->logger->log('GEMINI IMAGE SCORING FAILED: ' . $response->get_error_message(), 'error');
            return array();
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || isset($data['error'])) {
            $message = $data['error']['message'] ?? ('HTTP ' . $status);
            $this->logger->log('GEMINI IMAGE SCORING FAILED: ' . $message, 'error');
            return array();
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return $this->parse_ai_image_scores($text, $images, 'Gemini');
    }

    private function score_images_with_glm($candidates, $images, $prompt) {
        $api_key = get_option('aia_glm_api_key', '');
        $model = get_option('aia_glm_model', 'glm-4-flash');

        if (empty($api_key) || empty($model)) {
            $this->logger->log('IMAGE AI FAILED: GLM API key/model is not configured.', 'error');
            return array();
        }

        $content = array(array('type' => 'text', 'text' => $prompt));
        foreach ($candidates as $candidate) {
            $content[] = array('type' => 'text', 'text' => 'Candidate image #' . $candidate['candidate_id'] . '. Inspect this actual image.');
            $content[] = array(
                'type' => 'image_url',
                'image_url' => array(
                    'url' => 'data:' . $candidate['mime'] . ';base64,' . base64_encode($candidate['binary'])
                )
            );
        }

        $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
        $body = array(
            'model' => $model,
            'messages' => array(array('role' => 'user', 'content' => $content)),
            'response_format' => array('type' => 'json_object'),
            'temperature' => 0.1,
            'max_tokens' => 4096
        );

        $this->logger->log('GLM IMAGE AI: Uploading 10 real image files in one multimodal request. Model=' . $model, 'debug');
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => wp_json_encode($body),
            'timeout' => 180
        ));

        if (is_wp_error($response)) {
            $this->logger->log('GLM IMAGE SCORING FAILED: ' . $response->get_error_message(), 'error');
            return array();
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || isset($data['error'])) {
            $message = $data['error']['message'] ?? ('HTTP ' . $status);
            $this->logger->log('GLM IMAGE SCORING FAILED: ' . $message, 'error');
            return array();
        }

        $text = $data['choices'][0]['message']['content'] ?? '';
        return $this->parse_ai_image_scores($text, $images, 'GLM');
    }

    /**
     * Textual fallback: rank only the 10 already-searched Unsplash candidates
     * using their alt text/description against the blog keyword and title.
     * No new search and no random/Picsum image is ever introduced here.
     */
    private function select_by_alt_text_match($images, $keyword, $title) {
        $query_text = strtolower(trim($keyword . ' ' . $title));
        $query_text = preg_replace('/[^a-z0-9\s]+/i', ' ', $query_text);
        $query_terms = array_values(array_unique(array_filter(preg_split('/\s+/', $query_text), function ($term) {
            return strlen($term) >= 3;
        })));

        if (empty($query_terms)) {
            return array();
        }

        $ranked = array();
        foreach (array_slice($images, 0, 10) as $index => $image) {
            $alt = strtolower(wp_strip_all_tags((string) ($image['alt_description'] ?? '')));
            $description = strtolower(wp_strip_all_tags((string) ($image['description'] ?? '')));
            $text = trim($alt . ' ' . $description);
            $score = 0;
            $matched = array();

            foreach ($query_terms as $term) {
                if ($alt !== '' && preg_match('/\b' . preg_quote($term, '/') . '\b/i', $alt)) {
                    $score += 20;
                    $matched[] = $term;
                } elseif ($description !== '' && preg_match('/\b' . preg_quote($term, '/') . '\b/i', $description)) {
                    $score += 10;
                    $matched[] = $term;
                }
            }

            // Small bonus for exact multi-word keyword phrase in alt text.
            $keyword_clean = strtolower(trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($keyword))));
            if ($keyword_clean !== '' && $alt !== '' && strpos($alt, $keyword_clean) !== false) {
                $score += 30;
            }

            $ranked[] = array(
                'image' => $image,
                'score' => min(100, $score),
                'reason' => $matched ? 'Alt-text match: ' . implode(', ', array_unique($matched)) : 'No strong alt-text match; best available candidate from the 10 Unsplash results.',
                'index' => (int) $index,
                'candidate_id' => (int) $index + 1
            );
        }

        usort($ranked, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['candidate_id'] <=> $b['candidate_id'];
            }
            return $b['score'] <=> $a['score'];
        });

        if (empty($ranked)) {
            return array();
        }

        $this->logger->log('IMAGE ALT FALLBACK: Ranked all 10 Unsplash candidates by alt-text/description match.', 'info');
        foreach ($ranked as $row) {
            $alt = sanitize_text_field((string) ($row['image']['alt_description'] ?? ''));
            $this->logger->log(
                'IMAGE ALT SCORE: Candidate #' . $row['candidate_id'] . ' = ' . $row['score'] . '/100; alt=' . $alt,
                'debug'
            );
        }
        $this->logger->log(
            'IMAGE ALT FALLBACK SELECTED: Candidate #' . $ranked[0]['candidate_id'] .
            '; score=' . $ranked[0]['score'] . '/100; Unsplash ID=' . ($ranked[0]['image']['id'] ?? 'unknown'),
            'success'
        );

        return $ranked;
    }

    /**
     * Parse and rank the multimodal provider response.
     */
    private function parse_ai_image_scores($text, $images, $provider_name) {
        if (!is_string($text) || trim($text) === '') {
            $this->logger->log($provider_name . ' IMAGE SCORING FAILED: AI returned an empty response.', 'error');
            return array();
        }

        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);
        $data = json_decode($text, true);

        if (!is_array($data)) {
            $this->logger->log($provider_name . ' IMAGE SCORING FAILED: AI returned invalid JSON.', 'error');
            return array();
        }

        // GLM may wrap JSON under a property. Normalize to the same structure.
        if (isset($data[0])) {
            $scores = $data;
            $selected_index = null;
        } else {
            $scores = $data['scores'] ?? $data['results'] ?? $data['rankings'] ?? array();
            $selected_index = isset($data['selected_index']) ? intval($data['selected_index']) : null;
        }

        if (!is_array($scores) || count($scores) !== 10) {
            $this->logger->log(
                $provider_name . ' IMAGE SCORING FAILED: AI did not score all 10 candidates. Received ' . count($scores),
                'error'
            );
            return array();
        }

        if ($selected_index === null || $selected_index < 1 || $selected_index > 10) {
            $this->logger->log($provider_name . ' IMAGE SCORING FAILED: AI did not explicitly select exactly one valid winner from candidates #1-#10.', 'error');
            return array();
        }

        $ranked = array();
        $seen = array();

        foreach ($scores as $item) {
            if (!is_array($item) || !isset($item['index'])) {
                continue;
            }

            $candidate_id = intval($item['index']);
            if ($candidate_id < 1 || $candidate_id > 10 || isset($seen[$candidate_id])) {
                continue;
            }
            $seen[$candidate_id] = true;

            $score = max(0, min(100, intval($item['score'] ?? 0)));
            $image_index = $candidate_id - 1;

            if (!isset($images[$image_index])) {
                continue;
            }

            $ranked[] = array(
                'image' => $images[$image_index],
                'score' => $score,
                'reason' => sanitize_text_field((string) ($item['reason'] ?? '')),
                'index' => $image_index,
                'candidate_id' => $candidate_id
            );
        }

        if (count($seen) !== 10 || count($ranked) !== 10) {
            $this->logger->log($provider_name . ' IMAGE SCORING FAILED: AI response did not contain each candidate #1-#10 exactly once.', 'error');
            return array();
        }

        usort($ranked, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        if ($selected_index !== null) {
            foreach ($ranked as $key => $row) {
                if ($row['candidate_id'] === $selected_index) {
                    $winner = $row;
                    unset($ranked[$key]);
                    array_unshift($ranked, $winner);
                    $ranked = array_values($ranked);
                    break;
                }
            }
        }

        $this->logger->log($provider_name . ' IMAGE AI: All 10 real images scored successfully.', 'success');
        foreach ($ranked as $row) {
            $this->logger->log(
                'IMAGE AI SCORE: Candidate #' . $row['candidate_id'] . ' = ' . $row['score'] . '/100' .
                ($row['reason'] !== '' ? ' — ' . $row['reason'] : ''),
                'debug'
            );
        }
        $this->logger->log(
            $provider_name . ' IMAGE AI SELECTED: Candidate #' . $ranked[0]['candidate_id'] .
            ' from all 10 real images; score=' . $ranked[0]['score'] . '/100; Unsplash ID=' . ($ranked[0]['image']['id'] ?? 'unknown'),
            'success'
        );

        return $ranked;
    }

    /** Convert an Unsplash image URL to the requested 1080px delivery size. */
    private function build_1080_url($url) {
        if (empty($url)) {
            return '';
        }
        return add_query_arg(array(
            'auto' => 'format',
            'fit' => 'max',
            'w' => 1080,
            'q' => 82
        ), $url);
    }

    /** Deterministic fallback if Gemini vision is unavailable. */
    public function score_images($images, $keyword) {
        $keyword = is_array($keyword) ? ($keyword['keyword'] ?? '') : (string) $keyword;
        $terms = array_values(array_filter(preg_split('/\s+/', strtolower($keyword)), function ($term) {
            return strlen($term) > 2;
        }));

        $ranked = array();
        foreach ($images as $index => $image) {
            $text = strtolower(($image['description'] ?? '') . ' ' . ($image['alt_description'] ?? ''));
            $score = 0;
            foreach ($terms as $term) {
                if (strpos($text, $term) !== false) {
                    $score += 15;
                }
            }
            if (($image['width'] ?? 0) > ($image['height'] ?? 0)) {
                $score += 10;
            }
            if (($image['likes'] ?? 0) > 100) {
                $score += 5;
            }
            $ranked[] = array('image' => $image, 'score' => $score, 'index' => $index, 'reason' => 'metadata fallback');
        }

        usort($ranked, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        return $ranked;
    }
}