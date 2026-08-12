<?php
// includes/class-cron-async.php
if (!defined('ABSPATH')) exit;

/**
 * Async Cron Handler - Extends the main cron handler to process keywords
 * asynchronously without blocking user requests.
 * Processes ONLY 1 keyword per cron run.
 */
class AIA_Cron_Handler_Async extends AIA_Cron_Handler {
    
    private $use_async = true;
    private $batch_size = 1; // Process ONLY 1 keyword per batch
    
    public function __construct() {
        parent::__construct();
        
        if ($this->use_async) {
            // AJAX handler for background processing
            add_action('wp_ajax_aia_background_process', array($this, 'background_processor'));
            add_action('wp_ajax_nopriv_aia_background_process', array($this, 'background_processor'));
            
            // Also handle direct requests (for debugging/system cron)
            add_action('init', array($this, 'handle_direct_request'));
        }
    }
    
    /**
     * Override parent method - trigger async instead of processing directly
     */
    public function process_keyword_queue() {
        if ($this->use_async) {
            $this->trigger_async_processing();
            return; // Return immediately - don't block
        }
        
        // Fallback to synchronous
        parent::process_keyword_queue();
    }
    
    /**
     * Trigger async processing via AJAX
     */
    private function trigger_async_processing() {
        // Check if we can process before triggering
        if (!$this->can_process()) {
            $this->logger->log('Skipping async trigger - cannot process (daily limit reached or processing)', 'info');
            return;
        }
        
        // Use WordPress AJAX for background processing
        $url = admin_url('admin-ajax.php');
        $args = array(
            'body' => array(
                'action' => 'aia_background_process',
                'nonce' => wp_create_nonce('aia_async_nonce'),
                'timestamp' => time()
            ),
            'timeout' => 0.01,      // Don't wait
            'blocking' => false,    // Fire and forget
            'sslverify' => false,
            'headers' => array('Connection' => 'close')
        );
        
        wp_remote_post($url, $args);
        
        $this->logger->log('Async processing triggered via AJAX', 'debug');
    }
    
    /**
     * Handle direct HTTP requests for processing (for debugging/system cron)
     */
    public function handle_direct_request() {
        if (isset($_GET['aia_process_async']) && $_GET['aia_process_async'] == '1') {
            // Security: Check for secret key
            $secret = get_option('aia_async_secret', '');
            if (empty($secret)) {
                $secret = wp_generate_password(32, false);
                update_option('aia_async_secret', $secret);
            }
            
            if (!isset($_GET['secret']) || $_GET['secret'] !== $secret) {
                wp_die('Invalid secret key', 403);
            }
            
            // Run processing directly
            $this->background_processor();
            die('Processing completed');
        }
    }
    
    /**
     * Background processor - runs asynchronously via AJAX
     * Processes ONLY 1 keyword at a time
     */
    public function background_processor() {
        // Security check for AJAX requests
        if (isset($_POST['nonce']) && !wp_verify_nonce($_POST['nonce'], 'aia_async_nonce')) {
            wp_die('Invalid nonce', 403);
        }
        
        // Set timeout for heavy processing
        set_time_limit(300);
        wp_raise_memory_limit('cron');
        
        // Check if we can process at all
        if (!$this->can_process()) {
            $this->logger->log('Background process skipped - cannot process (daily limit reached)', 'info');
            wp_die('cannot_process');
        }
        
        // Prevent multiple instances
        if (get_transient('aia_processing_lock')) {
            $this->logger->log('Processing already running, skipping', 'info');
            wp_die('already_running');
        }
        set_transient('aia_processing_lock', true, 300); // 5 minute lock
        
        $keywords_processed = 0;
        $posts_published = 0;
        
        try {
            $this->logger->log('Background processing started (1 keyword per run)', 'info');
            
            // Get pending keywords
            $pending = $this->keywords_manager->get_pending_keywords();
            
            if (empty($pending)) {
                $this->logger->log('No pending keywords found', 'info');
                $this->update_runtime_state('idle');
                delete_transient('aia_processing_lock');
                wp_die('no_pending');
            }
            
            $this->logger->log('Found ' . count($pending) . ' pending keywords, processing 1', 'info');
            
            // Process ONLY the FIRST pending keyword
            $first_keyword = reset($pending);
            $index = key($pending);
            
            // Check daily limit before processing
            if (!$this->can_process()) {
                $this->logger->log('Daily limit reached, stopping', 'info');
                delete_transient('aia_processing_lock');
                wp_die('daily_limit_reached');
            }
            
            // Process this single keyword
            $result = $this->process_single_keyword($index, $first_keyword);
            
            if ($result) {
                $keywords_processed = 1;
                $posts_published = 1;
                $this->logger->log('Successfully processed 1 keyword', 'success');
            } else {
                $this->logger->log('Failed to process keyword, will retry later', 'warning');
            }
            
            $this->logger->log('Background processing completed: ' . $keywords_processed . ' keyword processed, ' . $posts_published . ' post published', 'success');
            
            // Update runtime state
            $this->update_runtime_state('idle');
            
        } catch (Exception $e) {
            $this->logger->log('Background processing error: ' . $e->getMessage(), 'error');
            $this->update_runtime_state('idle');
        }
        
        delete_transient('aia_processing_lock');
        
        // Return JSON response for AJAX
        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_send_json_success(array(
                'keywords_processed' => $keywords_processed,
                'posts_published' => $posts_published
            ));
        }
        
        wp_die('done');
    }
    
    /**
     * Process a single keyword
     */
    private function process_single_keyword($index, $keyword_data) {
        $this->logger->log("Processing keyword: '{$keyword_data['keyword']}' (Author ID: {$keyword_data['author_id']})", 'info');
        
        // Mark as processing
        $this->keywords_manager->update_keyword_status($index, 'processing');
        $this->update_runtime_state('processing', $keyword_data['keyword']);
        
        try {
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
                return true;
            } else {
                throw new Exception('Failed to publish post - publisher returned false');
            }
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $this->logger->log("Error processing keyword '{$keyword_data['keyword']}': {$error_message}", 'error');
            
            // Mark as pending again for retry
            $this->keywords_manager->update_keyword_status($index, 'pending');
            return false;
        }
    }
    
    /**
     * Check if we can process - respects daily limit
     */
    protected function can_process() {
        // Check max posts per day setting
        $max_posts = get_option('aia_max_posts_per_day', 10);
        $today_posts = $this->get_today_posts_count();
        
        if ($today_posts >= $max_posts) {
            $this->logger->log("Daily limit reached. {$today_posts}/{$max_posts} posts today.", 'info');
            return false;
        }
        
        // Check if already processing (with timeout for stuck processes)
        $runtime = $this->get_runtime_state();
        if ($runtime['status'] === 'processing') {
            $last_activity = $runtime['last_activity'] ?? 0;
            // Allow processing if last activity was more than 5 minutes ago (stuck process)
            if (time() - $last_activity < 300) {
                return false;
            }
        }
        
        return true;
    }
}