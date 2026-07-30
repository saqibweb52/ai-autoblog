<?php
// includes/class-images.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Image_Manager {
    
    private $unsplash_access_key;
    
    public function __construct() {
        $this->unsplash_access_key = get_option('aia_unsplash_access_key', '');
    }
    
    /**
     * Main method to get image for post - uses blog keyword directly
     * NO FALLBACKS - returns false if anything fails
     */
    public function get_image_for_post($post_data) {
        $logger = new AIA_Logger();
        
        // Debug: log what we received
        $logger->log("get_image_for_post received post_data: " . json_encode(array_keys($post_data)), 'debug');
        
        // Step 1: Extract keyword
        $keyword = '';
        if (is_array($post_data)) {
            $keyword = $post_data['keyword'] ?? '';
        } elseif (is_string($post_data)) {
            $keyword = $post_data;
        } else {
            $logger->log("FAILED: Post data is not an array or string. Type: " . gettype($post_data), 'error');
            return false;
        }
        
        // If keyword is empty or invalid
        if (empty($keyword) || $keyword === 'Array') {
            $logger->log("FAILED: No valid keyword provided for image search. Received: '" . print_r($post_data, true) . "'", 'error');
            return false;
        }
        
        $logger->log("Using blog keyword for image search: '" . $keyword . "'", 'info');
        
        // Step 2: Search Unsplash with the keyword - get 30 images for better selection
        $images = $this->search_unsplash_with_keyword($keyword, 30);
        
        if (empty($images) || !is_array($images)) {
            $logger->log("FAILED: No images found for keyword: '" . $keyword . "'", 'error');
            return false;
        }
        
        $logger->log("Found " . count($images) . " images for keyword: '" . $keyword . "'", 'debug');
        
        // Step 3: Score images
        $scored_images = $this->score_images($images, $keyword);
        
        if (empty($scored_images)) {
            $logger->log("FAILED: No scored images available", 'error');
            return false;
        }
        
        // Step 4: Select the highest scoring image
        $selected_image = $scored_images[0]['image'] ?? null;
        
        if (!$selected_image) {
            $logger->log("FAILED: No suitable image found from " . count($scored_images) . " scored results", 'error');
            return false;
        }
        
        $image_url = $selected_image['urls']['raw'] ?? 
                    $selected_image['urls']['full'] ?? 
                    $selected_image['urls']['regular'] ?? 
                    $selected_image['urls']['small'] ?? '';
        
        if (empty($image_url)) {
            $logger->log("FAILED: Selected image has no valid URL. Image data: " . json_encode(array_keys($selected_image)), 'error');
            return false;
        }
        
        $logger->log("SUCCESS: Selected image with score: " . $scored_images[0]['score'] . " for post", 'info');
        $logger->log("Image URL: " . substr($image_url, 0, 100) . '...', 'debug');
        
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
            'alt' => $keyword,
            'credit' => $selected_image['user']['name'] ?? 'Unsplash',
            'id' => $selected_image['id'] ?? '',
            'score' => $scored_images[0]['score'] ?? 0
        ];
    }
    
    /**
     * Search Unsplash with a specific keyword
     */
    public function search_unsplash_with_keyword($keyword, $per_page = 10) {
        $logger = new AIA_Logger();
        
        if (empty($this->unsplash_access_key)) {
            $logger->log("UNSPLASH FAILED: API key not configured", 'error');
            return array();
        }
        
        // Limit per_page to max 30 (Unsplash limit)
        $per_page = min(30, max(1, intval($per_page)));
        
        $encoded_keyword = urlencode($keyword);
        
        $url = 'https://api.unsplash.com/search/photos';
        $params = array(
            'query' => $encoded_keyword,
            'per_page' => $per_page,
            'orientation' => 'landscape',
            'order_by' => 'relevance'
        );
        
        $query_string = http_build_query($params);
        $full_url = $url . '?' . $query_string;
        
        $logger->log("Searching Unsplash with query: '" . $keyword . "' (per_page: " . $per_page . ")", 'debug');
        
        $response = wp_remote_get($full_url, array(
            'headers' => array(
                'Authorization' => 'Client-ID ' . $this->unsplash_access_key
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $logger->log("UNSPLASH FAILED: Request error - " . $response->get_error_message(), 'error');
            return array();
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code === 401) {
            $logger->log("UNSPLASH FAILED: Invalid API key (401)", 'error');
            return array();
        }
        
        if ($status_code === 403) {
            $logger->log("UNSPLASH FAILED: Forbidden - check API key permissions (403)", 'error');
            return array();
        }
        
        if ($status_code === 429) {
            $logger->log("UNSPLASH FAILED: Rate limit exceeded (429)", 'error');
            return array();
        }
        
        if ($status_code !== 200) {
            $logger->log("UNSPLASH FAILED: HTTP " . $status_code . " - " . substr($body, 0, 200), 'error');
            return array();
        }
        
        if (!isset($data['results'])) {
            $logger->log("UNSPLASH FAILED: No 'results' field in response", 'error');
            return array();
        }
        
        if (empty($data['results'])) {
            $logger->log("UNSPLASH FAILED: No results found for keyword: '" . $keyword . "'", 'error');
            return array();
        }
        
        $logger->log("UNSPLASH SUCCESS: Found " . count($data['results']) . " images", 'info');
        return $data['results'];
    }
    
    /**
     * Score images based on relevance to keyword
     */
    public function score_images($images, $keyword) {
        $logger = new AIA_Logger();
        
        if (empty($images)) {
            $logger->log("SCORE FAILED: No images to score", 'error');
            return array();
        }
        
        if (is_array($keyword)) {
            $keyword = $keyword['keyword'] ?? $keyword['title'] ?? '';
            $logger->log("Keyword was array, extracted: '" . $keyword . "'", 'debug');
        }
        
        if (empty($keyword)) {
            $logger->log("SCORE FAILED: No keyword provided for scoring", 'error');
            return array();
        }
        
        $keyword_lower = strtolower($keyword);
        $keyword_terms = array_unique(array_filter(explode(' ', $keyword_lower), function($w) {
            return strlen($w) > 2;
        }));
        
        // Also split by common separators (hyphens, underscores)
        $keyword_terms_extra = array();
        foreach ($keyword_terms as $term) {
            $split = preg_split('/[-_]/', $term);
            foreach ($split as $part) {
                if (strlen($part) > 2) {
                    $keyword_terms_extra[] = $part;
                }
            }
        }
        $keyword_terms = array_unique(array_merge($keyword_terms, $keyword_terms_extra));
        
        $scored_images = array();
        
        foreach ($images as $index => $image) {
            $score = 0;
            $match_count = 0;
            $matched_terms = array();
            
            $description = strtolower($image['description'] ?? '');
            $alt = strtolower($image['alt_description'] ?? '');
            
            // Extract tags from Unsplash
            $tags = array();
            if (isset($image['tags']) && is_array($image['tags'])) {
                foreach ($image['tags'] as $tag) {
                    $tag_text = strtolower($tag['title'] ?? $tag);
                    $tags[] = $tag_text;
                    // Split tag by spaces for partial matching
                    $tag_parts = explode(' ', $tag_text);
                    foreach ($tag_parts as $part) {
                        if (strlen($part) > 2) {
                            $tags[] = $part;
                        }
                    }
                }
                $tags = array_unique($tags);
            }
            
            // Score based on keyword terms
            foreach ($keyword_terms as $term) {
                if (!empty($term) && strlen($term) > 2) {
                    $term_lower = strtolower($term);
                    $found = false;
                    
                    // Check full keyword match in description (highest weight)
                    if (strpos($description, $keyword_lower) !== false) {
                        $score += 20;
                        $match_count++;
                        $matched_terms[] = $keyword_lower;
                        $found = true;
                    }
                    
                    // Check term in description
                    if (!$found && strpos($description, $term_lower) !== false) {
                        $score += 10;
                        $match_count++;
                        $matched_terms[] = $term_lower;
                        $found = true;
                    }
                    
                    // Check full keyword match in alt
                    if (!$found && strpos($alt, $keyword_lower) !== false) {
                        $score += 15;
                        $match_count++;
                        $matched_terms[] = $keyword_lower;
                        $found = true;
                    }
                    
                    // Check term in alt
                    if (!$found && strpos($alt, $term_lower) !== false) {
                        $score += 8;
                        $match_count++;
                        $matched_terms[] = $term_lower;
                        $found = true;
                    }
                    
                    // Check in tags
                    if (!$found) {
                        foreach ($tags as $tag) {
                            if (strpos($tag, $term_lower) !== false) {
                                $score += 5;
                                $match_count++;
                                $matched_terms[] = $term_lower;
                                $found = true;
                                break;
                            }
                        }
                    }
                }
            }
            
            // Boost score for images with good description and alt text
            if (!empty($description) && strlen($description) > 10) {
                $score += 5;
            }
            if (!empty($alt) && strlen($alt) > 5) {
                $score += 3;
            }
            
            // Position bonus (first images are more relevant)
            if ($index < 5) {
                $score += (5 - $index) * 3;
            }
            
            // Quality bonuses
            if ($image['width'] > $image['height']) {
                $score += 3; // Landscape is preferred
            }
            if ($image['width'] >= 1200 && $image['height'] >= 600) {
                $score += 5; // High resolution
            }
            if (isset($image['likes']) && $image['likes'] > 100) {
                $score += 3; // Popular images
            }
            if (isset($image['likes']) && $image['likes'] > 500) {
                $score += 5; // Very popular images
            }
            
            // Color diversity bonus (Unsplash provides color data)
            if (!empty($image['color'])) {
                $score += 2;
            }
            
            $scored_images[] = array(
                'image' => $image,
                'score' => $score,
                'match_count' => $match_count,
                'matched_terms' => $matched_terms,
                'description' => $description,
                'alt' => $alt,
                'index' => $index
            );
        }
        
        // Sort by score descending
        usort($scored_images, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Log top 3 scores
        $logger->log("SCORE SUCCESS: Scored " . count($scored_images) . " images", 'debug');
        if (count($scored_images) > 0) {
            $logger->log("Top score: " . $scored_images[0]['score'] . " (matches: " . $scored_images[0]['match_count'] . ")", 'debug');
        }
        
        return $scored_images;
    }
}