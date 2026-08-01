<?php
// includes/class-linking.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Link_Manager {
    
    private $max_internal_links = 5;
    private $max_external_links = 3;
    public $links_file;
    
    public function __construct() {
        $this->links_file = AIA_DATA_DIR . 'external_links.json';
        $this->ensure_links_file_exists();
        
        // Load settings from database
        $this->load_settings();
        
        // Check if sitemap sync is needed (on every page load)
        $this->check_sitemap_sync();
    }
    
    /**
     * Check if sitemap sync is needed - syncs ONE sitemap per visit
     */
    private function check_sitemap_sync() {
        $link_data = $this->get_links_data();
        
        // If no sitemap URLs, skip
        if (empty($link_data['sitemap_urls'])) {
            return;
        }
        
        // Get the next sitemap index to sync
        $next_index = get_transient('aia_next_sitemap_index');
        if ($next_index === false) {
            $next_index = 0;
        }
        
        $sitemap_urls = $link_data['sitemap_urls'];
        $total_sitemaps = count($sitemap_urls);
        
        // Make sure index is valid (in case sitemaps were removed)
        if ($next_index >= $total_sitemaps) {
            $next_index = 0;
        }
        
        // Get the sitemap URL for this visit
        $sitemap_index = $next_index % $total_sitemaps;
        $sitemap_url = $sitemap_urls[$sitemap_index];
        
        // Check when this specific sitemap was last synced
        $last_sync_key = 'aia_sitemap_sync_' . md5($sitemap_url);
        $last_sync = get_transient($last_sync_key);
        
        // Only sync if more than 24 hours have passed for this sitemap
        if ($last_sync === false) {
            // Sync this sitemap
            $result = $this->sync_single_sitemap($sitemap_url);
            
            if ($result !== false) {
                // Set transient for this sitemap (24 hours)
                set_transient($last_sync_key, time(), 24 * HOUR_IN_SECONDS);
                
                // Log the sync
                $logger = new AIA_Logger();
                $logger->log("Sitemap auto-sync: Synced sitemap {$sitemap_index}/{$total_sitemaps}: " . $sitemap_url . " - Found {$result} links", 'info');
            } else {
                // If sync failed, don't set the transient - try again on next visit
                $logger = new AIA_Logger();
                $logger->log("Sitemap auto-sync: Failed to sync sitemap: " . $sitemap_url, 'error');
            }
        }
        
        // Move to next sitemap for the next visit
        set_transient('aia_next_sitemap_index', ($next_index + 1) % $total_sitemaps, 30 * DAY_IN_SECONDS);
    }
    
    /**
     * Sync a single sitemap URL - REPLACES all links from this sitemap
     */
    public function sync_single_sitemap($sitemap_url) {
        $link_data = $this->get_links_data();
        
        // Get the host of this sitemap to identify its links
        $sitemap_host = parse_url($sitemap_url, PHP_URL_HOST);
        
        // REMOVE ALL existing links from this sitemap (by host)
        $existing_cache = $link_data['sitemap_cache'] ?? [];
        $filtered_cache = array_filter($existing_cache, function($link) use ($sitemap_host) {
            $link_host = parse_url($link['url'], PHP_URL_HOST);
            return ($link_host !== $sitemap_host);
        });
        
        // Fetch new links from this sitemap
        $new_links = $this->fetch_sitemap_links($sitemap_url);
        
        if (!empty($new_links)) {
            // Merge with filtered cache (other sitemaps' links)
            $link_data['sitemap_cache'] = array_merge(array_values($filtered_cache), $new_links);
            $link_count = count($new_links);
        } else {
            // If no new links found, keep filtered cache
            $link_data['sitemap_cache'] = array_values($filtered_cache);
            $link_count = 0;
        }
        
        // Update last sync time
        $link_data['last_sitemap_update'] = current_time('mysql');
        
        // Save
        $result = file_put_contents($this->links_file, json_encode($link_data, JSON_PRETTY_PRINT));
        
        return ($result !== false) ? $link_count : false;
    }
    
    /**
     * Load settings from WordPress options
     */
    private function load_settings() {
        $max_internal = get_option('aia_max_internal_links', 5);
        $max_external = get_option('aia_max_external_links', 3);
        
        $this->max_internal_links = max(0, intval($max_internal));
        $this->max_external_links = max(0, intval($max_external));
    }
    
    /**
     * Ensure the external links file exists
     */
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
    
    // ==================== PUBLIC GETTER/SETTER METHODS ====================
    
    /**
     * Get max internal links per post
     */
    public function get_max_internal_links() {
        return $this->max_internal_links;
    }
    
    /**
     * Get max external links per post
     */
    public function get_max_external_links() {
        return $this->max_external_links;
    }
    
    /**
     * Set max internal links
     */
    public function set_max_internal_links($count) {
        $this->max_internal_links = max(0, intval($count));
        update_option('aia_max_internal_links', $this->max_internal_links);
    }
    
    /**
     * Set max external links
     */
    public function set_max_external_links($count) {
        $this->max_external_links = max(0, intval($count));
        update_option('aia_max_external_links', $this->max_external_links);
    }
    
    /**
     * Check if internal linking is enabled
     */
    public function is_internal_linking_enabled() {
        return (bool) get_option('aia_internal_linking_enabled', 1);
    }
    
    // ==================== MAIN LINK ADDING METHOD ====================
    
    /**
     * Add links to content
     */
    public function add_links($content, $keyword, $post_id = null) {
        // Check if internal linking is enabled
        $internal_enabled = $this->is_internal_linking_enabled();
        
        // Add internal links only if enabled and max > 0
        if ($internal_enabled && $this->max_internal_links > 0) {
            $content = $this->add_internal_links($content, $keyword, $post_id);
        }
        
        // Add external links only if max > 0
        if ($this->max_external_links > 0) {
            $content = $this->add_external_links($content, $keyword);
        }
        
        return $content;
    }
    
    // ==================== INTERNAL LINKING ====================
    
    /**
     * Add internal links to content
     */
    private function add_internal_links($content, $keyword, $current_post_id) {
        // Get all published posts and pages
        $all_content = $this->get_all_published_content($current_post_id);
        
        if (empty($all_content)) {
            return $content;
        }
        
        // Find relevant matches based on keyword
        $matches = $this->find_relevant_internal_links($keyword, $all_content, $current_post_id);
        
        if (empty($matches)) {
            return $content;
        }
        
        $links_added = 0;
        
        foreach ($matches as $match) {
            if ($links_added >= $this->max_internal_links) {
                break;
            }
            
            // Use custom anchor if available, otherwise use post title
            $anchor_text = $this->get_internal_link_anchor($match['post'], $keyword);
            
            $link_html = sprintf(
                '<a href="%s" title="%s" class="aia-internal-link">%s</a>',
                esc_url($match['url']),
                esc_attr($match['post']->post_title),
                esc_html($anchor_text)
            );
            
            // Try to insert link naturally
            $content = $this->insert_link_naturally($content, $anchor_text, $link_html, 'internal');
            $links_added++;
        }
        
        return $content;
    }
    
    /**
     * Get all published content (posts and pages)
     */
    private function get_all_published_content($exclude_id = null) {
        global $wpdb;
        
        $query = $wpdb->prepare(
            "SELECT ID, post_title, post_content, post_type 
            FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND post_type IN ('post', 'page')
            AND ID != %d
            ORDER BY post_date DESC",
            $exclude_id ? $exclude_id : 0
        );
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Find relevant internal links based on keyword matching
     */
    private function find_relevant_internal_links($keyword, $all_content, $current_post_id) {
        $matches = [];
        $keyword_terms = explode(' ', strtolower($keyword));
        $keyword_terms = array_filter($keyword_terms, function($term) {
            return strlen($term) > 2;
        });
        
        foreach ($all_content as $post) {
            $relevance_score = 0;
            
            // Check title for keyword matches
            $title_lower = strtolower($post->post_title);
            foreach ($keyword_terms as $term) {
                if (strpos($title_lower, $term) !== false) {
                    $relevance_score += 3;
                }
            }
            
            // Check content for keyword matches
            $content_lower = strtolower(strip_tags($post->post_content));
            foreach ($keyword_terms as $term) {
                if (strpos($content_lower, $term) !== false) {
                    $relevance_score += 1;
                }
            }
            
            // Check for exact keyword match
            if (strpos($title_lower, strtolower($keyword)) !== false) {
                $relevance_score += 5;
            }
            
            // Only include if relevance score is above threshold
            if ($relevance_score >= 3) {
                $matches[] = [
                    'post' => $post,
                    'url' => get_permalink($post->ID),
                    'relevance' => $relevance_score
                ];
            }
        }
        
        // Sort by relevance (highest first)
        usort($matches, function($a, $b) {
            return $b['relevance'] - $a['relevance'];
        });
        
        return array_slice($matches, 0, $this->max_internal_links);
    }
    
    /**
     * Get custom anchor text for internal link
     */
    private function get_internal_link_anchor($post, $keyword) {
        // Try to find keyword in post title
        $title = $post->post_title;
        $keyword_lower = strtolower($keyword);
        $title_lower = strtolower($title);
        
        // If keyword appears in title, use a shorter version
        if (strpos($title_lower, $keyword_lower) !== false) {
            return $title;
        }
        
        // Check if any keyword term appears in title
        $keyword_terms = explode(' ', $keyword);
        foreach ($keyword_terms as $term) {
            if (strlen($term) > 2 && strpos($title_lower, strtolower($term)) !== false) {
                return $title;
            }
        }
        
        // Default: return the title
        return $title;
    }
    
    // ==================== EXTERNAL LINKING ====================
    
    /**
     * Add external links to content
     */
    private function add_external_links($content, $keyword) {
        $external_links = $this->get_external_links_for_keyword($keyword);
        
        if (empty($external_links)) {
            return $content;
        }
        
        $links_added = 0;
        
        foreach ($external_links as $link) {
            if ($links_added >= $this->max_external_links) {
                break;
            }
            
            $link_html = sprintf(
                '<a href="%s" rel="nofollow noopener" target="_blank" class="aia-external-link">%s</a>',
                esc_url($link['url']),
                esc_html($link['anchor'])
            );
            
            $content = $this->insert_link_naturally($content, $link['anchor'], $link_html, 'external');
            $links_added++;
        }
        
        return $content;
    }
    
    /**
     * Get external links from sitemap only - NO topic_keywords needed
     */
    public function get_external_links_for_keyword($keyword) {
        $links = [];
        $link_data = $this->get_links_data();
        
        // Check sitemap cache for relevant links - match by keyword in URL or anchor
        if (!empty($link_data['sitemap_cache'])) {
            $keyword_lower = strtolower($keyword);
            $keyword_terms = explode(' ', $keyword_lower);
            $keyword_terms = array_filter($keyword_terms, function($term) {
                return strlen($term) > 2;
            });
            
            foreach ($link_data['sitemap_cache'] as $sitemap_link) {
                // Check if keyword appears in URL or anchor text
                $url_lower = strtolower($sitemap_link['url']);
                $anchor_lower = strtolower($sitemap_link['anchor']);
                
                $relevance = 0;
                
                // Check for full keyword match in URL or anchor
                if (strpos($url_lower, $keyword_lower) !== false) {
                    $relevance += 5;
                }
                if (strpos($anchor_lower, $keyword_lower) !== false) {
                    $relevance += 5;
                }
                
                // Check for individual terms
                foreach ($keyword_terms as $term) {
                    if (strpos($url_lower, $term) !== false) {
                        $relevance += 2;
                    }
                    if (strpos($anchor_lower, $term) !== false) {
                        $relevance += 2;
                    }
                }
                
                // Only include if relevance > 0
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
        
        // Sort by relevance (highest first)
        usort($links, function($a, $b) {
            return $b['relevance'] - $a['relevance'];
        });
        
        // Remove duplicates by URL
        $unique_links = [];
        $seen_urls = [];
        foreach ($links as $link) {
            if (!in_array($link['url'], $seen_urls)) {
                $unique_links[] = $link;
                $seen_urls[] = $link['url'];
            }
        }
        
        return array_slice($unique_links, 0, $this->max_external_links);
    }
    
    /**
     * Extract anchor text from URL
     */
    private function extract_anchor_from_url($url) {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path) {
            $segments = explode('/', trim($path, '/'));
            $last = end($segments);
            if ($last) {
                // Remove file extension and replace hyphens/underscores with spaces
                $anchor = preg_replace('/\.[^.]+$/', '', $last);
                $anchor = str_replace(['-', '_'], ' ', $anchor);
                return ucwords($anchor);
            }
        }
        
        // Fallback: use domain name
        $domain = parse_url($url, PHP_URL_HOST);
        if ($domain) {
            return ucwords(str_replace(['www.', '.'], ' ', $domain));
        }
        
        return 'learn more';
    }
    
    // ==================== LINK INSERTION ====================
    
    /**
     * Insert link naturally into content
     */
    private function insert_link_naturally($content, $link_text, $link_html, $type = 'internal') {
        // Try to find exact match of link text
        if (strpos($content, $link_text) !== false) {
            return str_replace($link_text, $link_html, $content);
        }
        
        // Try to find partial match for internal links
        if ($type === 'internal') {
            $words = explode(' ', $link_text);
            if (count($words) > 2) {
                $partial = implode(' ', array_slice($words, 0, 2));
                if (strpos($content, $partial) !== false) {
                    return str_replace($partial, $link_html, $content);
                }
            }
        }
        
        // Insert in first paragraph for external links
        if ($type === 'external') {
            $first_para_end = strpos($content, '</p>');
            if ($first_para_end !== false) {
                // Insert before the closing </p> of first paragraph
                $insert_pos = $first_para_end;
                $link_with_space = ' ' . $link_html;
                return substr_replace($content, $link_with_space, $insert_pos, 0);
            }
        }
        
        // If no suitable place found, insert near the end
        $last_para_end = strrpos($content, '</p>');
        if ($last_para_end !== false) {
            $insert_pos = $last_para_end;
            $link_with_text = ' ' . $link_html;
            return substr_replace($content, $link_with_text, $insert_pos, 0);
        }
        
        // Fallback: append to content
        return $content . ' ' . $link_html;
    }
    
    // ==================== DATA MANAGEMENT ====================
    
    /**
     * Get all links data
     */
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
        
        return $data ?: [];
    }
    
    /**
     * Get all links data for admin display
     */
    public function get_all_links_data() {
        return $this->get_links_data();
    }
    
    // ==================== SITEMAP MANAGEMENT ====================
    
    /**
     * Update ALL sitemap caches (manual/forced sync) - REPLACES all links
     */
    public function update_sitemap_cache() {
        $link_data = $this->get_links_data();
        $sitemap_urls = $link_data['sitemap_urls'] ?? [];
        $all_links = [];
        
        if (empty($sitemap_urls)) {
            return 0;
        }
        
        foreach ($sitemap_urls as $sitemap_url) {
            $links = $this->fetch_sitemap_links($sitemap_url);
            if (!empty($links)) {
                $all_links = array_merge($all_links, $links);
            }
        }
        
        // Remove duplicates by URL
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
        
        file_put_contents($this->links_file, json_encode($link_data, JSON_PRETTY_PRINT));
        return count($unique_links);
    }
    
    /**
     * Fetch links from a sitemap - NO topic_keywords
     */
    public function fetch_sitemap_links($sitemap_url) {
        $links = [];
        
        // Validate URL
        if (!filter_var($sitemap_url, FILTER_VALIDATE_URL)) {
            $logger = new AIA_Logger();
            $logger->log("Invalid sitemap URL: {$sitemap_url}", 'error');
            return $links;
        }
        
        // Fetch sitemap
        $response = wp_remote_get($sitemap_url, ['timeout' => 30]);
        
        if (is_wp_error($response)) {
            $logger = new AIA_Logger();
            $logger->log("Failed to fetch sitemap: {$sitemap_url} - " . $response->get_error_message(), 'error');
            return $links;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $logger = new AIA_Logger();
            $logger->log("Sitemap returned status {$status_code}: {$sitemap_url}", 'error');
            return $links;
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // Parse XML
        $xml = simplexml_load_string($body);
        if ($xml === false) {
            $logger = new AIA_Logger();
            $logger->log("Failed to parse sitemap XML: {$sitemap_url}", 'error');
            return $links;
        }
        
        // Handle sitemap index
        if (isset($xml->sitemap)) {
            foreach ($xml->sitemap as $sitemap) {
                $sub_links = $this->fetch_sitemap_links((string)$sitemap->loc);
                $links = array_merge($links, $sub_links);
            }
            return $links;
        }
        
        // Handle URL set
        if (isset($xml->url)) {
            $count = 0;
            foreach ($xml->url as $url) {
                $loc = (string)$url->loc;
                $lastmod = (string)$url->lastmod;
                
                // Extract anchor from URL
                $anchor = $this->extract_anchor_from_url($loc);
                
                // Store without topic_keywords
                $links[] = [
                    'url' => $loc,
                    'anchor' => $anchor,
                    'lastmod' => $lastmod,
                    'source' => 'sitemap'
                ];
                $count++;
            }
            
            $logger = new AIA_Logger();
            $logger->log("Fetched {$count} links from sitemap: {$sitemap_url}", 'info');
        } else {
            $logger = new AIA_Logger();
            $logger->log("No <url> tags found in sitemap: {$sitemap_url}", 'warning');
        }
        
        return $links;
    }
    
    // ==================== SITEMAP URL MANAGEMENT ====================
    
    /**
     * Add a sitemap URL
     */
    public function add_sitemap_url($sitemap_url) {
        $link_data = $this->get_links_data();
        
        if (in_array($sitemap_url, $link_data['sitemap_urls'])) {
            return false;
        }
        
        $link_data['sitemap_urls'][] = $sitemap_url;
        
        // Save the updated list
        $result = file_put_contents($this->links_file, json_encode($link_data, JSON_PRETTY_PRINT));
        
        if ($result !== false) {
            // Reset the sitemap index counter so the new sitemap gets synced
            delete_transient('aia_next_sitemap_index');
            // Also delete the sync transient for this sitemap so it syncs immediately
            delete_transient('aia_sitemap_sync_' . md5($sitemap_url));
        }
        
        return $result;
    }
    
    /**
     * Remove a sitemap URL - REMOVES ALL links from this sitemap
     */
    public function remove_sitemap_url($sitemap_url) {
        $link_data = $this->get_links_data();
        
        foreach ($link_data['sitemap_urls'] as $index => $url) {
            if ($url === $sitemap_url) {
                unset($link_data['sitemap_urls'][$index]);
                $link_data['sitemap_urls'] = array_values($link_data['sitemap_urls']);
                
                // Remove ALL related cache entries (by host)
                $sitemap_host = parse_url($sitemap_url, PHP_URL_HOST);
                
                $link_data['sitemap_cache'] = array_filter($link_data['sitemap_cache'] ?? [], function($link) use ($sitemap_host) {
                    $link_host = parse_url($link['url'], PHP_URL_HOST);
                    return ($link_host !== $sitemap_host);
                });
                
                // Reindex the cache array
                $link_data['sitemap_cache'] = array_values($link_data['sitemap_cache']);
                
                // Delete transients for this sitemap
                delete_transient('aia_sitemap_sync_' . md5($sitemap_url));
                
                return file_put_contents($this->links_file, json_encode($link_data, JSON_PRETTY_PRINT));
            }
        }
        
        return false;
    }
}