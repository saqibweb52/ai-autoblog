<?php
// includes/class-images.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Image_Manager {
    
    private $unsplash_access_key;
    private $ai_provider;
    private $api_key;
    private $model;
    
    public function __construct() {
        $this->unsplash_access_key = get_option('aia_unsplash_access_key', '');
        $this->ai_provider = get_option('aia_ai_provider', 'gemini');
        $this->api_key = $this->get_api_key_for_provider();
        $this->model = $this->get_model_for_provider();
    }
    
    private function get_api_key_for_provider() {
        $provider = get_option('aia_ai_provider', 'gemini');
        if ($provider === 'gemini') {
            return get_option('aia_api_key', '');
        } elseif ($provider === 'glm') {
            return get_option('aia_glm_api_key', '');
        }
        return '';
    }
    
    private function get_model_for_provider() {
        $provider = get_option('aia_ai_provider', 'gemini');
        if ($provider === 'gemini') {
            return get_option('aia_gemini_model', 'gemini-2.0-flash');
        } elseif ($provider === 'glm') {
            return get_option('aia_glm_model', 'glm-4-flash');
        }
        return '';
    }
    
    /**
     * Main method to get image for post - AI Powered
     * NO FALLBACKS - returns false if anything fails
     */
    public function get_image_for_post($post_data) {
        $logger = new AIA_Logger();
        $logger->log("Starting AI-powered image search for post: " . ($post_data['title'] ?? 'NOT SET'), 'info');
        
        // Step 1: Generate search keyword using AI
        $search_keyword = $this->generate_ai_search_keyword($post_data);
        
        // If no keyword generated, fail immediately
        if (empty($search_keyword) || $search_keyword === false) {
            $logger->log("❌ AI FAILED: Could not generate a search keyword. Image search aborted.", 'error');
            return false;
        }
        
        $logger->log("✅ AI generated search keyword: " . $search_keyword, 'info');
        
        // Step 2: Search Unsplash with the keyword
        $images = $this->search_unsplash_with_keyword($search_keyword);
        
        if (empty($images) || !is_array($images)) {
            $logger->log("❌ AI FAILED: No images found for keyword: '" . $search_keyword . "'", 'error');
            return false;
        }
        
        // Step 3: Score and select the best image
        $selected_image = $this->select_best_image($images, $post_data);
        
        if (!$selected_image) {
            $logger->log("❌ AI FAILED: No suitable image found after scoring", 'error');
            return false;
        }
        
        $image_url = $selected_image['urls']['raw'] ?? 
                    $selected_image['urls']['full'] ?? 
                    $selected_image['urls']['regular'] ?? 
                    $selected_image['urls']['small'] ?? '';
        
        if (empty($image_url)) {
            $logger->log("❌ AI FAILED: Selected image has no valid URL", 'error');
            return false;
        }
        
        $logger->log("✅ AI SUCCESS: Selected image for post: " . substr($image_url, 0, 100) . '...', 'info');
        
        // Track download for Unsplash (required by their terms)
        if (isset($selected_image['links']['download_location'])) {
            wp_remote_get($selected_image['links']['download_location'], [
                'headers' => [
                    'Authorization' => 'Client-ID ' . $this->unsplash_access_key
                ],
                'timeout' => 10
            ]);
        }
        
        return [
            'url' => $image_url,
            'alt' => $selected_image['alt_description'] ?? $post_data['keyword'] ?? 'Featured image',
            'credit' => $selected_image['user']['name'] ?? 'Unsplash',
            'id' => $selected_image['id'] ?? ''
        ];
    }
    
    /**
     * Generate AI search keyword for the post
     * ONLY uses Title and Keyword - NOT the full content
     * NO FALLBACK - returns false if AI fails
     */
    public function generate_ai_search_keyword($post_data) {
        $logger = new AIA_Logger();
        
        // Check if AI API key is available
        if (empty($this->api_key)) {
            $logger->log("❌ AI FAILED: No AI API key configured", 'error');
            return false;
        }
        
        $title = $post_data['title'] ?? '';
        $keyword = $post_data['keyword'] ?? '';
        $meta_description = $post_data['meta_description'] ?? '';
        
        // If no title, fail
        if (empty($title)) {
            $logger->log("❌ AI FAILED: No title provided", 'error');
            return false;
        }
        
        // Build the prompt - ONLY use title and keyword
        $system_prompt = "You are an expert at finding the perfect image search keyword for blog posts.

Generate a SHORT, SPECIFIC search keyword (2-3 words) for finding the most relevant image on Unsplash.

CRITICAL RULES:
1. Return ONLY the search keyword, nothing else
2. Use 2-3 words maximum
3. Focus on the MAIN VISUAL SUBJECT
4. Use CONCRETE, VISUAL words
5. Complete your keyword - do NOT cut off
6. DO NOT return the blog title
7. DO NOT return more than 3 words

EXAMPLES:
- Blog about 'Chinese AI Technology' → 'chinese ai technology'
- Blog about 'Smart Chatbots' → 'smart chatbot interface'
- Blog about 'Quantum Physics' → 'quantum physics concept'
- Blog about 'Next Gen Technology' → 'future technology concept'

Return ONLY the 2-3 word search keyword, no explanation, no quotes, no extra text.";
        
        $user_prompt = "Blog Title: " . $title;
        if (!empty($keyword)) {
            $user_prompt .= "\nFocus Keyword: " . $keyword;
        }
        if (!empty($meta_description)) {
            $user_prompt .= "\nBlog Description: " . $meta_description;
        }
        $user_prompt .= "\n\nGenerate a 2-3 word image search keyword for this blog post:";
        
        // Call AI
        if ($this->ai_provider === 'gemini') {
            $result = $this->call_gemini_for_keyword($system_prompt, $user_prompt);
        } elseif ($this->ai_provider === 'glm') {
            $result = $this->call_glm_for_keyword($system_prompt, $user_prompt);
        } else {
            $logger->log("❌ AI FAILED: Invalid AI provider", 'error');
            return false;
        }
        
        // Check if we got a response
        if (empty($result)) {
            $logger->log("❌ AI FAILED: No response from AI", 'error');
            return false;
        }
        
        // Clean the result
        $result = trim($result);
        $result = preg_replace('/[^a-zA-Z0-9\s]/', '', $result);
        $result = preg_replace('/^(search keyword|keyword|search term):\s*/i', '', $result);
        $result = trim($result);
        
        // Check if the result is the blog title (too long or matches title)
        if (strlen($result) > 30 || strpos(strtolower($title), strtolower($result)) !== false) {
            $logger->log("❌ AI FAILED: Returned blog title or too long: '" . $result . "'", 'error');
            return false;
        }
        
        // Split into words
        $words = explode(' ', $result);
        $words = array_filter($words, function($w) {
            return strlen($w) > 1;
        });
        $words = array_values($words);
        
        // Must have at least 2 words
        if (count($words) < 2) {
            $logger->log("❌ AI FAILED: Generated less than 2 words: '" . $result . "'", 'error');
            return false;
        }
        
        // Must have at most 4 words
        if (count($words) > 4) {
            $logger->log("❌ AI FAILED: Generated too many words (" . count($words) . "): '" . $result . "'", 'error');
            return false;
        }
        
        // Check if any word is incomplete
        $incomplete_patterns = array('chines$', 'process$', 'technolo$', 'artifici$', 'intellig$', 'comput$', 'generat$');
        foreach ($words as $word) {
            foreach ($incomplete_patterns as $pattern) {
                if (preg_match('/' . $pattern . '/i', $word)) {
                    $logger->log("❌ AI FAILED: Incomplete word detected: '" . $word . "' in '" . $result . "'", 'error');
                    return false;
                }
            }
        }
        
        // Success
        $result_string = implode(' ', $words);
        $logger->log("✅ AI generated keyword: '" . $result_string . "'", 'debug');
        return $result_string;
    }
    
    /**
     * Call Gemini for keyword generation
     */
    private function call_gemini_for_keyword($system_prompt, $user_prompt) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->api_key}";
        
        $full_prompt = $system_prompt . "\n\n" . $user_prompt;
        
        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array('text' => $full_prompt)
                    )
                )
            ),
            'generationConfig' => array(
                'temperature' => 0.3,
                'maxOutputTokens' => 50,
                'topP' => 0.95
            )
        );
        
        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return trim($data['candidates'][0]['content']['parts'][0]['text']);
        }
        
        return null;
    }
    
    /**
     * Call GLM for keyword generation
     */
    private function call_glm_for_keyword($system_prompt, $user_prompt) {
        $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
        
        $messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => $user_prompt)
        );
        
        $body = array(
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.3,
            'max_tokens' => 50
        );
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }
        
        return null;
    }
    
    /**
     * Search Unsplash with a specific keyword
     */
    public function search_unsplash_with_keyword($keyword) {
        $logger = new AIA_Logger();
        
        if (empty($this->unsplash_access_key)) {
            $logger->log("❌ FAILED: Unsplash API key not configured", 'error');
            return array();
        }
        
        $encoded_keyword = urlencode($keyword);
        
        $url = 'https://api.unsplash.com/search/photos';
        $params = array(
            'query' => $encoded_keyword,
            'per_page' => 30,
            'orientation' => 'landscape',
            'order_by' => 'relevance'
        );
        
        $query_string = http_build_query($params);
        $full_url = $url . '?' . $query_string;
        
        $response = wp_remote_get($full_url, array(
            'headers' => array(
                'Authorization' => 'Client-ID ' . $this->unsplash_access_key
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $logger->log("❌ FAILED: Unsplash search error: " . $response->get_error_message(), 'error');
            return array();
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code === 429) {
            $logger->log("❌ FAILED: Unsplash rate limit reached", 'error');
            return array();
        }
        
        if ($status_code !== 200 || !isset($data['results'])) {
            $logger->log("❌ FAILED: Unsplash returned status: " . $status_code, 'error');
            return array();
        }
        
        $logger->log("✅ Found " . count($data['results']) . " images for keyword: {$keyword}", 'debug');
        
        return $data['results'];
    }
    
    /**
     * Select the best image from search results
     */
    public function select_best_image($images, $post_data) {
        $logger = new AIA_Logger();
        
        if (empty($images)) {
            $logger->log("❌ FAILED: No images to score", 'error');
            return null;
        }
        
        // Score each image
        $scored_images = $this->score_images($images, $post_data);
        
        if (empty($scored_images)) {
            $logger->log("❌ FAILED: No images scored", 'error');
            return null;
        }
        
        // Sort by score (highest first)
        usort($scored_images, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Log top 3 scores
        $logger->log("Top 3 image scores:", 'debug');
        foreach (array_slice($scored_images, 0, 3) as $idx => $item) {
            $score = $item['score'];
            $desc = substr($item['description'] ?? $item['alt'] ?? 'No description', 0, 50);
            $logger->log("  #" . ($idx + 1) . " Score: {$score} - {$desc}", 'debug');
        }
        
        $best_image = $scored_images[0]['image'] ?? null;
        
        if ($best_image) {
            $logger->log("✅ Best image selected with score: " . $scored_images[0]['score'], 'debug');
        } else {
            $logger->log("❌ FAILED: No best image found", 'error');
        }
        
        return $best_image;
    }
    
    /**
     * Score images based on relevance to post
     */
    public function score_images($images, $post_data) {
        $title = strtolower($post_data['title'] ?? '');
        $keyword = strtolower($post_data['keyword'] ?? '');
        $content = strtolower(strip_tags($post_data['content'] ?? ''));
        $content = substr($content, 0, 500);
        
        // Extract keywords
        $stop_words = array('the', 'and', 'for', 'with', 'your', 'what', 'from', 'this', 'that', 'have', 'are', 'you', 'can', 'will', 'about', 'they', 'their', 'would', 'could', 'should', 'been', 'were', 'does', 'has', 'had', 'when', 'where', 'why', 'how', 'which', 'who', 'whom', 'whose', 'beyond', 'blue', 'links', 'future', 'finding', 'hype', 'unpacking', 'rise', 'new');
        
        $title_keywords = array_unique(array_filter(explode(' ', $title), function($w) use ($stop_words) {
            return strlen($w) > 3 && !in_array($w, $stop_words);
        }));
        
        $keyword_terms = array_unique(array_filter(explode(' ', $keyword), function($w) {
            return strlen($w) > 2;
        }));
        
        $content_keywords = array_unique(array_filter(explode(' ', $content), function($w) use ($stop_words) {
            return strlen($w) > 4 && !in_array($w, $stop_words);
        }));
        $content_keywords = array_slice($content_keywords, 0, 20);
        
        $all_keywords = array_merge($title_keywords, $keyword_terms, $content_keywords);
        $all_keywords = array_unique($all_keywords);
        
        $scored_images = array();
        
        foreach ($images as $index => $image) {
            $score = 0;
            $match_count = 0;
            
            $description = strtolower($image['description'] ?? '');
            $alt = strtolower($image['alt_description'] ?? '');
            
            $tags = array();
            if (isset($image['tags']) && is_array($image['tags'])) {
                foreach ($image['tags'] as $tag) {
                    $tags[] = strtolower($tag['title'] ?? $tag);
                }
            }
            
            foreach ($all_keywords as $kw) {
                if (!empty($kw) && strlen($kw) > 2) {
                    // Exact match in description
                    if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $description)) {
                        $score += 12;
                        $match_count++;
                    } elseif (strpos($description, $kw) !== false) {
                        $score += 6;
                        $match_count++;
                    }
                    
                    // Exact match in alt
                    if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $alt)) {
                        $score += 10;
                        $match_count++;
                    } elseif (strpos($alt, $kw) !== false) {
                        $score += 5;
                        $match_count++;
                    }
                    
                    // Match in tags
                    foreach ($tags as $tag) {
                        if (strpos($tag, $kw) !== false) {
                            $score += 3;
                            $match_count++;
                        }
                    }
                }
            }
            
            // Position bonus (first images are more relevant)
            if ($index < 5) {
                $score += (5 - $index) * 2;
            }
            
            // Quality bonuses
            if ($image['width'] > $image['height']) {
                $score += 3;
            }
            if ($image['width'] >= 1200 && $image['height'] >= 600) {
                $score += 3;
            }
            if (!empty($description) && !empty($alt)) {
                $score += 5;
            }
            if (isset($image['likes']) && $image['likes'] > 50) {
                $score += 2;
            }
            
            $scored_images[] = array(
                'image' => $image,
                'score' => $score,
                'match_count' => $match_count,
                'description' => $description,
                'alt' => $alt,
                'index' => $index
            );
        }
        
        return $scored_images;
    }
}