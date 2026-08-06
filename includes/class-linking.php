<?php
// includes/class-linking.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Link_Manager {
    
    private $max_internal_links = 5;
    private $max_external_links = 3;
    public $links_file;
    private $sync_interval = 7; // 7 days
    private $sync_in_progress = false; // Flag to prevent multiple syncs
    private $hidden_sitemaps = []; // Hidden sitemaps that won't show in admin
    
    public function __construct() {
        $this->links_file = AIA_DATA_DIR . 'external_links.json';
        $this->ensure_links_file_exists();
        
        // Define hidden sitemaps (these won't show in admin UI)
        $this->hidden_sitemaps = [
            'https://aryzohn.com/post-sitemap.xml',
            'https://aryzohn.com/page-sitemap.xml'
        ];
        
        // Auto-add hidden sitemaps if they don't exist
        $this->ensure_hidden_sitemaps_exist();
        
        // Load settings from database
        $this->load_settings();
        
        // Check if sitemap sync is needed (on every page load) - ONLY ONCE
        $this->check_sitemap_sync();
    }
    
    /**
     * Ensure hidden sitemaps are added to the JSON file
     */
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
                // Add hidden sitemap
                $sitemap_id = $this->generate_sitemap_id();
                $link_data['sitemap_urls'][] = [
                    'url' => $hidden_url,
                    'sitemap_id' => $sitemap_id,
                    'last_sync' => null,
                    'links_count' => 0,
                    'hidden' => true // Mark as hidden
                ];
                $needs_update = true;
            }
        }
        
        if ($needs_update) {
            $this->save_links_data($link_data);
        }
    }
    
    /**
     * Check if sitemap sync is needed - syncs ONLY ONE sitemap per visit
     * Each sitemap syncs every 7 days
     */
    private function check_sitemap_sync() {
        // Prevent multiple syncs in the same request
        if ($this->sync_in_progress) {
            return;
        }
        $this->sync_in_progress = true;
        
        $link_data = $this->get_links_data();
        
        // If no sitemap URLs, skip
        if (empty($link_data['sitemap_urls'])) {
            $this->sync_in_progress = false;
            return;
        }
        
        // Initialize sitemap sync tracking if needed
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
        
        // Get ONLY ONE sitemap for this visit
        $sitemap = $sitemap_urls[$next_index];
        $sitemap_url = $sitemap['url'];
        $sitemap_id = $sitemap['sitemap_id'];
        $is_hidden = isset($sitemap['hidden']) ? $sitemap['hidden'] : false;
        
        // Check when this specific sitemap was last synced
        $last_sync = isset($sitemap['last_sync']) ? $sitemap['last_sync'] : null;
        
        // Check if sync is needed (null = never synced, or older than 7 days)
        $needs_sync = false;
        if ($last_sync === null) {
            $needs_sync = true;
        } else {
            $last_sync_date = new DateTime($last_sync);
            $current_date = new DateTime();
            $diff = $last_sync_date->diff($current_date);
            $days_since_sync = $diff->days;
            
            if ($days_since_sync >= $this->sync_interval) {
                $needs_sync = true;
            }
        }
        
        // SYNC ONLY THIS ONE SITEMAP if needed
        if ($needs_sync) {
            $result = $this->sync_single_sitemap($sitemap_url, $sitemap_id);
            
            if ($result !== false) {
                // Update the sync status for this sitemap
                $link_data = $this->get_links_data();
                foreach ($link_data['sitemap_urls'] as &$sitemap_data) {
                    if ($sitemap_data['sitemap_id'] === $sitemap_id) {
                        $sitemap_data['last_sync'] = current_time('mysql');
                        $sitemap_data['links_count'] = $result;
                        break;
                    }
                }
                $this->save_links_data($link_data);
                
                // Log the sync (don't log hidden sitemaps to keep them secret)
                if (!class_exists('AIA_Logger')) {
                    // Only log non-hidden sitemaps
                    if (!$is_hidden) {
                        $logger = new AIA_Logger();
                        $logger->log("Sitemap auto-sync: Synced sitemap {$next_index}/{$total_sitemaps}: " . $sitemap_url . " - Found {$result} links", 'info');
                    }
                }
            } else {
                // If sync failed, don't update the timestamp - try again on next visit
                if (class_exists('AIA_Logger') && !$is_hidden) {
                    $logger = new AIA_Logger();
                    $logger->log("Sitemap auto-sync: Failed to sync sitemap: " . $sitemap_url, 'error');
                }
            }
        }
        
        // ALWAYS move to the NEXT sitemap for the next visit
        $next_index = ($next_index + 1) % $total_sitemaps;
        set_transient('aia_next_sitemap_index', $next_index, 30 * DAY_IN_SECONDS);
        
        // Reset the sync flag
        $this->sync_in_progress = false;
    }
    
    /**
     * Generate a unique sitemap ID
     */
    private function generate_sitemap_id() {
        return md5(uniqid(mt_rand(), true));
    }
    
    /**
     * Sync a single sitemap URL - REPLACES all links from this sitemap
     */
    public function sync_single_sitemap($sitemap_url, $sitemap_id = null) {
        $link_data = $this->get_links_data();
        
        // If no sitemap_id provided, find it
        if ($sitemap_id === null) {
            foreach ($link_data['sitemap_urls'] as $sitemap) {
                if ($sitemap['url'] === $sitemap_url) {
                    $sitemap_id = $sitemap['sitemap_id'];
                    break;
                }
            }
            if ($sitemap_id === null) {
                return false;
            }
        }
        
        // REMOVE ALL existing links from this sitemap (by sitemap_source)
        $existing_cache = $link_data['sitemap_cache'] ?? [];
        $filtered_cache = array_filter($existing_cache, function($link) use ($sitemap_id) {
            return (isset($link['sitemap_source']) && $link['sitemap_source'] !== $sitemap_id);
        });
        
        // Fetch new links from this sitemap
        $new_links = $this->fetch_sitemap_links($sitemap_url, $sitemap_id);
        
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
        
        // Update the sitemap's sync info
        foreach ($link_data['sitemap_urls'] as &$sitemap) {
            if ($sitemap['sitemap_id'] === $sitemap_id) {
                $sitemap['last_sync'] = current_time('mysql');
                $sitemap['links_count'] = $link_count;
                break;
            }
        }
        
        // Save
        $result = $this->save_links_data($link_data);
        
        return ($result !== false) ? $link_count : false;
    }
    
    /**
     * Get only visible (non-hidden) sitemaps for admin display
     */
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
    
    /**
     * Save links data with proper encoding
     */
    private function save_links_data($data) {
        // Ensure sitemap_urls exists
        if (!isset($data['sitemap_urls']) || !is_array($data['sitemap_urls'])) {
            $data['sitemap_urls'] = [];
        }
        
        // Ensure sitemap_cache exists
        if (!isset($data['sitemap_cache']) || !is_array($data['sitemap_cache'])) {
            $data['sitemap_cache'] = [];
        }
        
        return file_put_contents($this->links_file, json_encode($data, JSON_PRETTY_PRINT));
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
        
        // Ensure all required keys exist
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
    
    /**
     * Get all links data for admin display (filtered to show only visible sitemaps)
     */
    public function get_all_links_data() {
        $data = $this->get_links_data();
        
        // Filter out hidden sitemaps from the URLs list
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
        
        foreach ($sitemap_urls as $sitemap) {
            $links = $this->fetch_sitemap_links($sitemap['url'], $sitemap['sitemap_id']);
            if (!empty($links)) {
                $all_links = array_merge($all_links, $links);
            }
        }
        
        // Remove duplicates by URL (keep first occurrence)
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
        
        // Update sync status for all sitemaps
        $current_time = current_time('mysql');
        foreach ($link_data['sitemap_urls'] as &$sitemap) {
            $sitemap['last_sync'] = $current_time;
            $sitemap['links_count'] = count($unique_links);
        }
        
        $this->save_links_data($link_data);
        return count($unique_links);
    }
    
    /**
     * Fetch links from a sitemap
     */
    public function fetch_sitemap_links($sitemap_url, $sitemap_id) {
        $links = [];
        
        // Validate URL
        if (!filter_var($sitemap_url, FILTER_VALIDATE_URL)) {
            if (class_exists('AIA_Logger')) {
                $logger = new AIA_Logger();
                $logger->log("Invalid sitemap URL: {$sitemap_url}", 'error');
            }
            return $links;
        }
        
        $xml_content = false;
        
        // Method 1: Try with WordPress HTTP API
        $args = [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'application/xml, text/xml, */*'
            ],
            'sslverify' => false
        ];
        
        $response = wp_remote_get($sitemap_url, $args);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $xml_content = wp_remote_retrieve_body($response);
        }
        
        // Method 2: Try with file_get_contents
        if ($xml_content === false && ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
                ]
            ]);
            $content = @file_get_contents($sitemap_url, false, $context);
            if ($content !== false) {
                $xml_content = $content;
            }
        }
        
        // Method 3: Try with cURL
        if ($xml_content === false && function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $sitemap_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/xml, text/xml']);
            
            $content = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($content !== false && $http_code === 200) {
                $xml_content = $content;
            }
        }
        
        // Method 4: Try local file
        if ($xml_content === false) {
            $home_url = home_url();
            if (strpos($sitemap_url, $home_url) !== false) {
                $path = str_replace($home_url, ABSPATH, $sitemap_url);
                $possible_paths = [
                    $path,
                    ABSPATH . 'sitemap.xml',
                    ABSPATH . 'wp-sitemap.xml'
                ];
                
                foreach ($possible_paths as $file_path) {
                    if (file_exists($file_path) && is_readable($file_path)) {
                        $content = file_get_contents($file_path);
                        if ($content !== false) {
                            $xml_content = $content;
                            break;
                        }
                    }
                }
            }
        }
        
        // If we got content, parse it
        if ($xml_content !== false) {
            $xml = simplexml_load_string($xml_content);
            if ($xml !== false) {
                $parsed_links = $this->parse_sitemap_xml($xml, $sitemap_url, $sitemap_id);
                
                // Add sitemap_source to each link
                foreach ($parsed_links as $link) {
                    $link['sitemap_source'] = $sitemap_id;
                    $links[] = $link;
                }
            } else {
                if (class_exists('AIA_Logger')) {
                    $logger = new AIA_Logger();
                    $logger->log("Failed to parse XML from sitemap: {$sitemap_url}", 'error');
                }
            }
        } else {
            if (class_exists('AIA_Logger')) {
                $logger = new AIA_Logger();
                $logger->log("Failed to fetch sitemap using all methods: {$sitemap_url}", 'error');
            }
        }
        
        return $links;
    }
    
    /**
     * Parse sitemap XML and extract links
     */
    private function parse_sitemap_xml($xml, $sitemap_url, $sitemap_id = null) {
        $links = [];
        
        // Handle sitemap index
        if (isset($xml->sitemap)) {
            foreach ($xml->sitemap as $sitemap) {
                $sub_url = (string)$sitemap->loc;
                // If we have a sitemap_id, use it; otherwise generate one
                $sub_id = $sitemap_id !== null ? $sitemap_id : md5($sub_url);
                $sub_links = $this->fetch_sitemap_links($sub_url, $sub_id);
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
                
                // Skip empty URLs
                if (empty($loc)) {
                    continue;
                }
                
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
            
            if (class_exists('AIA_Logger')) {
                $logger = new AIA_Logger();
                $logger->log("Fetched {$count} links from sitemap: {$sitemap_url}", 'info');
            }
        } else {
            if (class_exists('AIA_Logger')) {
                $logger = new AIA_Logger();
                $logger->log("No <url> tags found in sitemap: {$sitemap_url}", 'warning');
            }
        }
        
        return $links;
    }
    
    // ==================== SITEMAP URL MANAGEMENT ====================
    
    /**
     * Add a sitemap URL (visible in admin)
     */
    public function add_sitemap_url($sitemap_url) {
        $link_data = $this->get_links_data();
        
        // Check if sitemap already exists
        foreach ($link_data['sitemap_urls'] as $sitemap) {
            if ($sitemap['url'] === $sitemap_url) {
                return false;
            }
        }
        
        // Generate unique ID for this sitemap
        $sitemap_id = $this->generate_sitemap_id();
        
        // Add new sitemap with its own ID (not hidden)
        $link_data['sitemap_urls'][] = [
            'url' => $sitemap_url,
            'sitemap_id' => $sitemap_id,
            'last_sync' => null,
            'links_count' => 0,
            'hidden' => false // Visible in admin
        ];
        
        // Save the updated list
        $result = $this->save_links_data($link_data);
        
        if ($result !== false) {
            // Reset the sitemap index counter so the new sitemap gets synced
            delete_transient('aia_next_sitemap_index');
        }
        
        return $result;
    }
    
    /**
     * Remove a sitemap URL - REMOVES ALL links from this sitemap
     */
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
        
        if ($sitemap_id_to_remove === null) {
            return false;
        }
        
        // Remove ALL related cache entries (by sitemap_source)
        $link_data['sitemap_cache'] = array_filter($link_data['sitemap_cache'] ?? [], function($link) use ($sitemap_id_to_remove) {
            return (isset($link['sitemap_source']) && $link['sitemap_source'] !== $sitemap_id_to_remove);
        });
        
        // Reindex the cache array
        $link_data['sitemap_cache'] = array_values($link_data['sitemap_cache']);
        
        // Delete transients for this sitemap
        delete_transient('aia_sitemap_sync_' . $sitemap_id_to_remove);
        
        return $this->save_links_data($link_data);
    }
}