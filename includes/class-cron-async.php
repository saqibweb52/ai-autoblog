<?php
// includes/class-cron-async.php
if (!defined('ABSPATH')) exit;

/**
 * Background Cron Handler.
 *
 * IMPORTANT:
 * WordPress Cron already runs callbacks inside a separate wp-cron.php request.
 * The old implementation made a second HTTP request to admin-ajax.php with a
 * 0.01 second timeout. That is unreliable on localhost and can silently fail.
 *
 * We therefore process the queue directly inside the WP-Cron request.
 * The visitor request only triggers WordPress' non-blocking WP-Cron spawn;
 * it does NOT wait for the AI generation to finish.
 */
class AIA_Cron_Handler_Async extends AIA_Cron_Handler {

    public function __construct() {
        parent::__construct();

        // Keep the AJAX endpoint for manual/debug compatibility.
        add_action('wp_ajax_aia_background_process', array($this, 'background_processor'));
        add_action('wp_ajax_nopriv_aia_background_process', array($this, 'background_processor'));

        // Direct endpoint for debugging/system cron.
        add_action('init', array($this, 'handle_direct_request'));
    }

    /**
     * Called by the aia_process_keywords WP-Cron event.
     * No localhost HTTP request is made here.
     */
    public function process_keyword_queue() {
        $this->logger->log('WP-Cron callback aia_process_keywords started.', 'debug');

        // Parent method processes exactly one pending keyword.
        parent::process_keyword_queue();

        $this->logger->log('WP-Cron callback aia_process_keywords finished.', 'debug');
    }

    /**
     * Optional AJAX endpoint retained for manual testing.
     */
    public function background_processor() {
        if (!defined('DOING_AJAX') || !DOING_AJAX) {
            // Direct endpoint is authenticated separately by handle_direct_request().
        } else {
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aia_async_nonce')) {
                wp_send_json_error(array('message' => 'Invalid nonce.'), 403);
            }
        }

        set_time_limit(300);
        wp_raise_memory_limit('cron');

        $result = $this->run_one_background_job();

        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_send_json_success($result);
        }

        wp_die('Blog Autom processing completed.');
    }

    /**
     * Direct secured endpoint for localhost debugging or a system cron.
     * Example:
     * /?aia_process_async=1&secret=YOUR_SECRET
     */
    public function handle_direct_request() {
        if (!isset($_GET['aia_process_async']) || $_GET['aia_process_async'] !== '1') {
            return;
        }

        $secret = get_option('aia_async_secret', '');

        if (empty($secret)) {
            $secret = wp_generate_password(32, false, false);
            update_option('aia_async_secret', $secret, false);
        }

        $provided_secret = isset($_GET['secret'])
            ? sanitize_text_field(wp_unslash($_GET['secret']))
            : '';

        if (!hash_equals((string) $secret, (string) $provided_secret)) {
            status_header(403);
            wp_die('Invalid secret key.');
        }

        nocache_headers();
        set_time_limit(300);
        wp_raise_memory_limit('cron');

        $result = $this->run_one_background_job();

        wp_die(
            '<pre>' . esc_html(wp_json_encode($result, JSON_PRETTY_PRINT)) . '</pre>',
            'Blog Autom Cron Test',
            array('response' => 200)
        );
    }

    /**
     * Execute one queue item with a lock so two cron requests cannot process
     * the same queue at the same time.
     */
    private function run_one_background_job() {
        $lock_key = 'aia_processing_lock';
        $lock_value = wp_generate_uuid4();

        if (get_transient($lock_key)) {
            $this->logger->log('Background job skipped because another job is already running.', 'warning');
            return array(
                'status' => 'already_running',
                'keywords_processed' => 0,
                'posts_published' => 0
            );
        }

        set_transient($lock_key, $lock_value, 10 * MINUTE_IN_SECONDS);

        $keywords_processed = 0;
        $posts_published = 0;

        try {
            if (!$this->can_process()) {
                $this->logger->log('Background job skipped: daily limit reached or another process is active.', 'info');
                return array(
                    'status' => 'cannot_process',
                    'keywords_processed' => 0,
                    'posts_published' => 0
                );
            }

            $next_keyword = $this->keywords_manager->get_next_pending_keyword();

            if (!$next_keyword) {
                $this->update_runtime_state('idle');
                $this->logger->log('Background job found no pending keywords.', 'info');

                return array(
                    'status' => 'no_pending',
                    'keywords_processed' => 0,
                    'posts_published' => 0
                );
            }

            $index = $next_keyword['index'];
            $keyword_data = $next_keyword['data'];

            $this->logger->log(
                "Background job processing keyword: '{$keyword_data['keyword']}'",
                'info'
            );

            $result = $this->process_single_keyword_public($index, $keyword_data);

            if ($result) {
                $keywords_processed = 1;
                $posts_published = 1;
            }

            $this->update_runtime_state('idle');

            return array(
                'status' => $result ? 'success' : 'failed',
                'keywords_processed' => $keywords_processed,
                'posts_published' => $posts_published
            );

        } catch (Throwable $e) {
            $this->logger->log(
                'Background job fatal error: ' . $e->getMessage(),
                'error'
            );

            $this->update_runtime_state('idle');

            return array(
                'status' => 'error',
                'message' => $e->getMessage(),
                'keywords_processed' => $keywords_processed,
                'posts_published' => $posts_published
            );

        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * The parent class keeps its processing method private, so this method
     * contains the same single-keyword workflow while remaining callable here.
     */
    private function process_single_keyword_public($index, $keyword_data) {
        $this->keywords_manager->update_keyword_status($index, 'processing');
        $this->update_runtime_state('processing', $keyword_data['keyword']);

        try {
            $generated = $this->generator->generate_post(
                $keyword_data['keyword'],
                $keyword_data['author_id']
            );

            if (!$generated || !is_array($generated)) {
                throw new Exception('Failed to generate post content.');
            }

            if (empty($generated['content']) || strlen(strip_tags($generated['content'])) < 300) {
                throw new Exception('Generated content is too short or empty.');
            }

            $generated['content'] = $this->link_manager->add_links(
                $generated['content'],
                $keyword_data['keyword']
            );

            $generated['keyword'] = $keyword_data['keyword'];
            $generated['author_id'] = $keyword_data['author_id'];

            if (!empty($keyword_data['categories'])) {
                $generated['categories'] = $keyword_data['categories'];
            }

            if (empty($generated['meta_description'])) {
                $generated['meta_description'] = $this->extract_meta_from_content($generated['content']);
            }

            if (empty($generated['title'])) {
                $generated['title'] = $keyword_data['keyword'];
            }

            $post_id = $this->publisher->publish_post($generated);

            if (!$post_id) {
                throw new Exception('Failed to publish post.');
            }

            $this->keywords_manager->update_keyword_status($index, 'done');
            $this->increment_total_posts();

            $this->logger->log(
                "Successfully published post for '{$keyword_data['keyword']}' (Post ID: {$post_id}).",
                'success'
            );

            return true;

        } catch (Throwable $e) {
            $this->logger->log(
                "Error processing '{$keyword_data['keyword']}': " . $e->getMessage(),
                'error'
            );

            // Retry later.
            $this->keywords_manager->update_keyword_status($index, 'pending');
            return false;
        }
    }
}
