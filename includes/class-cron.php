<?php
// includes/class-cron.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Cron_Handler {
    
    private $keywords_manager;
    private $generator;
    private $publisher;
    private $link_manager;
    private $image_manager;
    private $logger;
    
    public function __construct() {
        $this->keywords_manager = new AIA_Keywords_Manager();
        $this->generator = new AIA_Content_Generator();
        $this->publisher = new AIA_Publisher();
        $this->link_manager = new AIA_Link_Manager();
        $this->image_manager = new AIA_Image_Manager();
        $this->logger = new AIA_Logger();
    }
    
    public function process_keyword_queue() {
        // Check if processing is allowed
        if (!$this->can_process()) {
            return;
        }
        
        // Get next pending keyword
        $next_keyword = $this->keywords_manager->get_next_pending_keyword();
        
        if (!$next_keyword) {
            $this->update_runtime_state('idle');
            return;
        }
        
        $index = $next_keyword['index'];
        $keyword_data = $next_keyword['data'];
        
        // Mark as processing
        $this->keywords_manager->update_keyword_status($index, 'processing');
        $this->update_runtime_state('processing', $keyword_data['keyword']);
        
        try {
            $this->logger->log("Processing keyword: '{$keyword_data['keyword']}' (Author ID: {$keyword_data['author_id']})", 'info');
            
            // Log categories if present
            if (!empty($keyword_data['categories'])) {
                $this->logger->log("Categories for keyword: " . implode(', ', $keyword_data['categories']), 'debug');
            }
            
            // Generate post
            $generated = $this->generator->generate_post(
                $keyword_data['keyword'],
                $keyword_data['author_id']
            );
            
            // If generation failed, mark as pending for retry
            if (!$generated || !is_array($generated)) {
                throw new Exception('Failed to generate post content - generation returned false');
            }
            
            // Validate content
            if (empty($generated['content']) || strlen(strip_tags($generated['content'])) < 300) {
                throw new Exception('Generated content is too short or empty');
            }
            
            // Add links
            $generated['content'] = $this->link_manager->add_links(
                $generated['content'],
                $keyword_data['keyword']
            );
            
            // Set required fields for publishing
            $generated['keyword'] = $keyword_data['keyword'];
            $generated['author_id'] = $keyword_data['author_id'];
            
            // Add categories to the generated data
            if (!empty($keyword_data['categories'])) {
                $generated['categories'] = $keyword_data['categories'];
            }
            
            // Ensure meta_description is set
            if (!isset($generated['meta_description']) || empty($generated['meta_description'])) {
                $generated['meta_description'] = $this->extract_meta_from_content($generated['content']);
            }
            
            // Ensure title is set
            if (!isset($generated['title']) || empty($generated['title'])) {
                $generated['title'] = $keyword_data['keyword'];
            }
            
            // Publish
            $post_id = $this->publisher->publish_post($generated);
            
            if ($post_id) {
                // Mark as done
                $this->keywords_manager->update_keyword_status($index, 'done');
                $this->increment_total_posts();
                $this->logger->log("Successfully published post for keyword '{$keyword_data['keyword']}' (Post ID: {$post_id})", 'success');
            } else {
                throw new Exception('Failed to publish post - publisher returned false');
            }
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $this->logger->log("Error processing keyword '{$keyword_data['keyword']}': {$error_message}", 'error');
            
            // Mark as pending again for retry
            $this->keywords_manager->update_keyword_status($index, 'pending');
        }
        
        $this->update_runtime_state('idle');
    }
    
    private function extract_meta_from_content($content) {
        // Try to find first paragraph
        if (preg_match('/<p[^>]*>(.{50,200})<\/p>/i', $content, $matches)) {
            $first_para = trim(strip_tags($matches[1]));
            if (strlen($first_para) > 50) {
                return substr($first_para, 0, 160);
            }
        }
        
        // Try to get plain text
        $plain_text = strip_tags($content);
        if (strlen($plain_text) > 50) {
            return substr($plain_text, 0, 160);
        }
        
        return '';
    }
    
    private function can_process() {
        $runtime = $this->get_runtime_state();
        
        // Don't process if already processing
        if ($runtime['status'] === 'processing') {
            $last_activity = $runtime['last_activity'] ?? 0;
            // Allow processing if last activity was more than 5 minutes ago (stuck process)
            if (time() - $last_activity < 300) {
                return false;
            }
        }
        
        // Check max posts per day setting
        $max_posts = get_option('aia_max_posts_per_day', 10);
        $today_posts = $this->get_today_posts_count();
        
        if ($today_posts >= $max_posts) {
            $this->logger->log("Daily limit reached. {$today_posts}/{$max_posts} posts today.", 'info');
            return false;
        }
        
        return true;
    }
    
    private function get_today_posts_count() {
        global $wpdb;
        
        $today = date('Y-m-d');
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND post_type = 'post' 
            AND DATE(post_date) = %s
            AND EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} 
                WHERE post_id = {$wpdb->posts}.ID 
                AND meta_key = '_aia_generated'
            )",
            $today
        ));
        
        return intval($count);
    }
    
    private function get_runtime_state() {
        $file = AIA_DATA_DIR . 'runtime_state.json';
        if (file_exists($file)) {
            $content = file_get_contents($file);
            return json_decode($content, true) ?: [];
        }
        return ['status' => 'idle', 'total_posts' => 0];
    }
    
    private function update_runtime_state($status, $keyword = null) {
        $state = $this->get_runtime_state();
        $state['status'] = $status;
        $state['last_activity'] = time();
        
        if ($keyword) {
            $state['current_keyword'] = $keyword;
        }
        
        file_put_contents(AIA_DATA_DIR . 'runtime_state.json', json_encode($state, JSON_PRETTY_PRINT));
    }
    
    private function increment_total_posts() {
        $state = $this->get_runtime_state();
        $state['total_posts'] = ($state['total_posts'] ?? 0) + 1;
        file_put_contents(AIA_DATA_DIR . 'runtime_state.json', json_encode($state, JSON_PRETTY_PRINT));
    }
}