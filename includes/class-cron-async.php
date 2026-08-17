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
            // Admin action used by the Generate button on the Keywords page.
            add_action('wp_ajax_aia_generate_keyword', array($this, 'ajax_generate_keyword'));
            
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
    private function trigger_async_processing($keyword_id = '') {
        if (!$this->can_process()) {
            $this->logger->log('Skipping async trigger - cannot process (daily limit reached or processing)', 'info');
            return false;
        }

        if (!empty($keyword_id)) {
            // Tell the worker which exact keyword the admin requested.
            set_transient('aia_manual_keyword_id', sanitize_text_field($keyword_id), 10 * MINUTE_IN_SECONDS);
        }

        $url = admin_url('admin-ajax.php');
        $args = array(
            'body' => array(
                'action' => 'aia_background_process',
                'nonce' => wp_create_nonce('aia_async_nonce'),
                'timestamp' => time()
            ),
            // Give live servers enough time to establish the loopback connection,
            // while keeping the request non-blocking for the visitor/admin.
            'timeout' => 5,
            'blocking' => false,
            'sslverify' => false,
            'headers' => array('Connection' => 'close')
        );

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            if (!empty($keyword_id)) {
                delete_transient('aia_manual_keyword_id');
            }

            $this->logger->log(
                'Async processing request failed: ' . $response->get_error_message(),
                'error'
            );
            return false;
        }

        $this->logger->log(
            !empty($keyword_id)
                ? "Async processing triggered for keyword ID: {$keyword_id}"
                : 'Async processing triggered via AJAX',
            'debug'
        );

        return true;
    }

    /**
     * Queue one specific pending keyword from the Keywords admin page.
     */
    public function ajax_generate_keyword() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You do not have permission to generate posts.'), 403);
        }

        check_ajax_referer('aia_generate_keyword', 'nonce');

        $keyword_id = isset($_POST['keyword_id'])
            ? sanitize_text_field(wp_unslash($_POST['keyword_id']))
            : '';

        if (empty($keyword_id)) {
            wp_send_json_error(array('message' => 'Invalid keyword.'));
        }

        $keywords = $this->keywords_manager->get_all_keywords();
        $found_index = null;
        $found_keyword = null;

        foreach ($keywords as $index => $keyword) {
            if (isset($keyword['id']) && $keyword['id'] === $keyword_id) {
                $found_index = $index;
                $found_keyword = $keyword;
                break;
            }
        }

        if ($found_keyword === null) {
            wp_send_json_error(array('message' => 'Keyword not found.'));
        }

        if (!isset($found_keyword['status']) || $found_keyword['status'] !== 'pending') {
            wp_send_json_error(array('message' => 'This keyword is already processing or has already been generated.'));
        }

        // Manual generation is intentionally synchronous. It does NOT call
        // trigger_async_processing(), and it does NOT use the automatic daily limit.
        $result = $this->process_single_keyword_direct($found_index, $found_keyword);

        if ($result['success']) {
            wp_send_json_success(array(
                'message' => 'Post generated successfully for: ' . $found_keyword['keyword'],
                'post_id' => $result['post_id']
            ));
        }

        wp_send_json_error(array(
            'message' => $result['message'] ?: 'Failed to generate the post.'
        ));
    }

    /**
     * Generate one keyword directly during the current admin request.
     * This is used by manual admin actions and deliberately bypasses the
     * automatic daily limit and background queue.
     */
    public function process_single_keyword_direct($index, $keyword_data) {
        set_time_limit(300);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $keyword = isset($keyword_data['keyword']) ? trim((string) $keyword_data['keyword']) : '';
        $author_id = isset($keyword_data['author_id']) ? intval($keyword_data['author_id']) : 1;
        $categories = isset($keyword_data['categories']) && is_array($keyword_data['categories'])
            ? array_map('intval', $keyword_data['categories'])
            : array();

        if ($keyword === '') {
            return array('success' => false, 'post_id' => 0, 'message' => 'Keyword is empty.');
        }

        // Manual generation bypasses the DAILY LIMIT, but it must not overlap
        // an actually running generation request.
        $runtime = $this->get_runtime_state();
        if (($runtime['status'] ?? 'idle') === 'processing') {
            $last_activity = isset($runtime['last_activity']) ? intval($runtime['last_activity']) : 0;
            if ($last_activity && (time() - $last_activity) < 300) {
                return array(
                    'success' => false,
                    'post_id' => 0,
                    'message' => 'Another post is currently being generated. Please wait for it to finish.'
                );
            }
            // Recover a stale runtime lock before manual generation.
            $this->update_runtime_state('idle');
        }

        if (!$this->keywords_manager->update_keyword_status($index, 'processing')) {
            return array('success' => false, 'post_id' => 0, 'message' => 'Could not update keyword status.');
        }

        $this->logger->log("Starting DIRECT manual generation for keyword: '{$keyword}' (daily limit bypassed)", 'info');
        $this->update_runtime_state('processing', $keyword);

        try {
            if (!class_exists('AIA_Content_Generator')) {
                throw new Exception('Content generator is not available.');
            }
            if (!class_exists('AIA_Publisher')) {
                throw new Exception('Publisher is not available.');
            }
            if (!class_exists('AIA_Link_Manager')) {
                throw new Exception('Link manager is not available.');
            }

            $this->logger->log("Generating content for '{$keyword}'", 'info');
            $generated = $this->generator->generate_post($keyword, $author_id, $categories);

            if (!$generated || !is_array($generated)) {
                throw new Exception('Content generator returned no valid result.');
            }
            if (empty($generated['content'])) {
                throw new Exception('Generated content is empty.');
            }
            if (strlen(strip_tags($generated['content'])) < 300) {
                throw new Exception('Generated content is too short.');
            }

            $this->logger->log("Content generated successfully for '{$keyword}'. Length: " . strlen($generated['content']), 'debug');

            $generated['content'] = $this->link_manager->add_links(
                $generated['content'],
                $keyword,
                null,
                $categories
            );

            $generated['keyword'] = $keyword;
            $generated['author_id'] = $author_id;
            if (!empty($categories)) {
                $generated['categories'] = $categories;
            }

            if (empty($generated['meta_description'])) {
                $generated['meta_description'] = $this->extract_meta_from_content($generated['content']);
            }
            if (empty($generated['title'])) {
                $generated['title'] = $keyword;
            }

            // Do NOT fetch an image here. AIA_Publisher already handles the
            // featured image. Fetching it twice caused manual generations to
            // make two external image requests and could make the request fail.
            $this->logger->log("Publishing post for '{$keyword}'", 'info');
            $post_id = $this->publisher->publish_post($generated);

            if (!$post_id) {
                throw new Exception('Publisher failed to create the post.');
            }

            $this->keywords_manager->update_keyword_status($index, 'done');
            $this->increment_total_posts();
            $this->logger->log("DIRECT manual generation completed for '{$keyword}' (Post ID: {$post_id})", 'success');

            return array('success' => true, 'post_id' => intval($post_id), 'message' => '');
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if ($message === '') {
                $message = 'Unknown generation error (' . get_class($e) . ').';
            }

            $this->keywords_manager->update_keyword_status($index, 'pending');
            $this->logger->log("DIRECT manual generation FAILED for '{$keyword}': {$message}", 'error');

            return array('success' => false, 'post_id' => 0, 'message' => $message);
        } finally {
            $this->update_runtime_state('idle');
        }
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
        $background_process_id = 'bg_' . wp_generate_uuid4();
        $this->register_background_process($background_process_id);
        
        $keywords_processed = 0;
        $posts_published = 0;
        
        try {
            $this->logger->log('Background processing started (1 keyword per run)', 'info');
            
            // If an administrator selected a specific keyword, process that one first.
            $manual_keyword_id = get_transient('aia_manual_keyword_id');
            $index = null;
            $first_keyword = null;

            if ($manual_keyword_id) {
                $all_keywords = $this->keywords_manager->get_all_keywords();

                foreach ($all_keywords as $candidate_index => $candidate_keyword) {
                    if (
                        isset($candidate_keyword['id'], $candidate_keyword['status']) &&
                        $candidate_keyword['id'] === $manual_keyword_id &&
                        $candidate_keyword['status'] === 'processing'
                    ) {
                        $index = $candidate_index;
                        $first_keyword = $candidate_keyword;
                        break;
                    }
                }

                delete_transient('aia_manual_keyword_id');

                if ($first_keyword) {
                    $this->logger->log(
                        "Manual generation selected keyword: '{$first_keyword['keyword']}'",
                        'info'
                    );
                }
            }

            // Normal cron processing: take the first pending keyword.
            if ($first_keyword === null) {
                $pending = $this->keywords_manager->get_pending_keywords();

                if (empty($pending)) {
                    $this->logger->log('No pending keywords found', 'info');
                    $this->update_runtime_state('idle');
                    delete_transient('aia_processing_lock');
                    wp_die('no_pending');
                }

                $this->logger->log('Found ' . count($pending) . ' pending keywords, processing 1', 'info');

                $first_keyword = reset($pending);
                $index = key($pending);
            }
            
            $this->touch_background_process(
                $background_process_id,
                $first_keyword['keyword'],
                isset($first_keyword['id']) ? $first_keyword['id'] : ''
            );

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
        
        if (isset($background_process_id)) {
            $this->unregister_background_process($background_process_id);
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
                $keyword_data['keyword'],
                null,
                isset($keyword_data['categories']) ? $keyword_data['categories'] : array()
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
     * Background process registry used by the Logs page.
     */
    public static function get_background_processes() {
        $processes = get_option('aia_background_processes', array());
        return is_array($processes) ? $processes : array();
    }

    private function register_background_process($process_id, $keyword = '', $keyword_id = '') {
        $processes = self::get_background_processes();
        $processes[$process_id] = array(
            'id' => $process_id,
            'type' => 'WP-Cron background generation',
            'keyword' => $keyword,
            'keyword_id' => $keyword_id,
            'status' => 'processing',
            'started_at' => current_time('mysql'),
            'last_activity' => time()
        );
        update_option('aia_background_processes', $processes, false);
    }

    private function touch_background_process($process_id, $keyword = '', $keyword_id = '') {
        $processes = self::get_background_processes();
        if (!isset($processes[$process_id])) {
            return;
        }
        $processes[$process_id]['last_activity'] = time();
        if ($keyword !== '') {
            $processes[$process_id]['keyword'] = $keyword;
        }
        if ($keyword_id !== '') {
            $processes[$process_id]['keyword_id'] = $keyword_id;
        }
        update_option('aia_background_processes', $processes, false);
    }

    private function unregister_background_process($process_id) {
        $processes = self::get_background_processes();
        if (isset($processes[$process_id])) {
            unset($processes[$process_id]);
            update_option('aia_background_processes', $processes, false);
        }
    }

    public static function delete_background_process($process_id) {
        $processes = self::get_background_processes();
        if (!isset($processes[$process_id])) {
            return false;
        }

        $process = $processes[$process_id];
        unset($processes[$process_id]);
        update_option('aia_background_processes', $processes, false);

        if (!empty($process['keyword'])) {
            $manager = new AIA_Keywords_Manager();
            $keywords = $manager->get_all_keywords();
            foreach ($keywords as $index => $keyword) {
                $matches = ($keyword['keyword'] ?? '') === $process['keyword']
                    || (!empty($keyword['id']) && !empty($process['keyword_id']) && $keyword['id'] === $process['keyword_id']);
                if ($matches && ($keyword['status'] ?? '') === 'processing') {
                    $manager->update_keyword_status($index, 'pending');
                    break;
                }
            }
        }

        delete_transient('aia_processing_lock');
        delete_transient('aia_manual_keyword_id');

        $file = AIA_DATA_DIR . 'runtime_state.json';
        if (file_exists($file)) {
            $state = json_decode(file_get_contents($file), true);
            if (is_array($state)) {
                $state['status'] = 'idle';
                $state['last_activity'] = time();
                unset($state['current_keyword']);
                file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
            }
        }

        return true;
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