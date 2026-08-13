<?php
// includes/class-indexnow.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_IndexNow {
    
    private $bing_api_key;
    private $google_api_key;
    private $search_engine;
    private $host;
    private $enabled;
    private $auto_submit;
    
    public function __construct() {
        $this->bing_api_key = get_option('aia_console_bing_api_key', '');
        $this->google_api_key = get_option('aia_console_google_api_key', '');
        $this->search_engine = get_option('aia_console_search_engine', 'both');
        $this->enabled = get_option('aia_console_enabled', 1);
        $this->auto_submit = get_option('aia_console_auto_submit', 1);
        $this->host = parse_url(get_site_url(), PHP_URL_HOST);
        
        // Only hook if enabled and auto_submit is on
        if ($this->enabled && $this->auto_submit) {
            add_action('publish_post', array($this, 'notify_post_published'), 10, 2);
            add_action('post_updated', array($this, 'notify_post_updated'), 10, 3);
            add_action('before_delete_post', array($this, 'notify_post_deleted'), 10, 1);
        }
        
        // Admin hooks (always available)
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // AJAX actions
        add_action('wp_ajax_aia_indexnow_sync', array($this, 'ajax_sync_post'));
        add_action('wp_ajax_aia_indexnow_bulk_sync', array($this, 'ajax_bulk_sync'));
        
        // Add status column to posts list
        add_filter('manage_posts_columns', array($this, 'add_index_status_column'), 10, 2);
        add_action('manage_posts_custom_column', array($this, 'render_index_status_column'), 10, 2);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'blog-autom',
            'IndexNow Status',
            'IndexNow Status',
            'manage_options',
            'blog-autom-indexnow',
            array($this, 'render_status_page')
        );
    }
    
    /**
     * Render status page
     */
    public function render_status_page() {
        $stats = $this->get_indexing_stats();
        $bing_key = get_option('aia_console_bing_api_key', '');
        $google_key = get_option('aia_console_google_api_key', '');
        $enabled = get_option('aia_console_enabled', 1);
        $auto_submit = get_option('aia_console_auto_submit', 1);
        $search_engine = get_option('aia_console_search_engine', 'both');
        
        ?>
        <div class="wrap">
            <h1>IndexNow Status</h1>
            
            <div class="aia-settings-section">
                <h2>Current Status</h2>
                
                <div class="aia-status-grid">
                    <div class="aia-status-item">
                        <span class="aia-status-label">Status:</span>
                        <span class="console-status <?php echo $enabled ? 'active' : 'inactive'; ?>">
                            <?php echo $enabled ? '✅ Active' : '❌ Disabled'; ?>
                        </span>
                    </div>
                    <div class="aia-status-item">
                        <span class="aia-status-label">Auto Submit:</span>
                        <span class="console-status <?php echo $auto_submit ? 'active' : 'inactive'; ?>">
                            <?php echo $auto_submit ? '✅ Enabled' : '❌ Disabled'; ?>
                        </span>
                    </div>
                    <div class="aia-status-item">
                        <span class="aia-status-label">Search Engines:</span>
                        <span><?php echo ucfirst($search_engine); ?></span>
                    </div>
                    <div class="aia-status-item">
                        <span class="aia-status-label">Bing API Key:</span>
                        <span><?php echo !empty($bing_key) ? '✅ Configured' : '❌ Not Configured'; ?></span>
                    </div>
                    <div class="aia-status-item">
                        <span class="aia-status-label">Google API Key:</span>
                        <span><?php echo !empty($google_key) ? '✅ Configured' : '❌ Not Configured'; ?></span>
                    </div>
                    <div class="aia-status-item">
                        <span class="aia-status-label">Host:</span>
                        <span><code><?php echo esc_html($this->host); ?></code></span>
                    </div>
                </div>
            </div>
            
            <div class="aia-settings-section">
                <h2>Indexing Statistics</h2>
                
                <div class="aia-indexnow-stats">
                    <div class="aia-stat-grid">
                        <div class="aia-stat-card">
                            <h3>Total Indexed</h3>
                            <div class="stat-number" style="color: #28a745;"><?php echo $stats['indexed']; ?></div>
                        </div>
                        <div class="aia-stat-card">
                            <h3>Pending</h3>
                            <div class="stat-number" style="color: #f0ad4e;"><?php echo $stats['pending']; ?></div>
                        </div>
                        <div class="aia-stat-card">
                            <h3>Failed</h3>
                            <div class="stat-number" style="color: #dc3545;"><?php echo $stats['failed']; ?></div>
                        </div>
                        <div class="aia-stat-card">
                            <h3>Total</h3>
                            <div class="stat-number" style="color: #2271b1;"><?php echo $stats['total']; ?></div>
                        </div>
                    </div>
                </div>
                
                <?php if ($enabled && (!empty($bing_key) || !empty($google_key))): ?>
                    <div style="margin-top: 20px;">
                        <button type="button" id="aia_indexnow_bulk_sync" class="button button-primary">
                            Sync All Pending Posts
                        </button>
                        <span id="aia_indexnow_bulk_status" style="margin-left: 10px;"></span>
                        <p class="description">Submit all pending posts to search engines.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="aia-settings-section">
                <h2>Recent Activity</h2>
                <?php $this->render_recent_activity(); ?>
            </div>
        </div>
        
        <style>
            .aia-settings-section {
                background: #fff;
                padding: 20px;
                margin: 20px 0;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .aia-settings-section h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 2px solid #f0f0f1;
            }
            .aia-status-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 10px;
            }
            .aia-status-item {
                padding: 10px;
                background: #f8f9fa;
                border-radius: 4px;
            }
            .aia-status-label {
                font-weight: bold;
                color: #666;
            }
            .aia-indexnow-stats {
                margin: 20px 0;
            }
            .aia-stat-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
            }
            .aia-stat-card {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 6px;
                text-align: center;
            }
            .aia-stat-card h3 {
                margin: 0 0 5px 0;
                font-size: 14px;
                color: #666;
            }
            .stat-number {
                font-size: 28px;
                font-weight: bold;
            }
            .console-status {
                display: inline-block;
                padding: 2px 10px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
            }
            .console-status.active {
                background: #d4edda;
                color: #155724;
            }
            .console-status.inactive {
                background: #f8d7da;
                color: #721c24;
            }
            #aia_indexnow_bulk_status {
                font-weight: 500;
            }
            #aia_indexnow_bulk_status.success {
                color: #28a745;
            }
            #aia_indexnow_bulk_status.error {
                color: #dc3545;
            }
            #aia_indexnow_bulk_status.loading {
                color: #f0ad4e;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#aia_indexnow_bulk_sync').on('click', function() {
                var button = $(this);
                var statusSpan = $('#aia_indexnow_bulk_status');
                
                button.prop('disabled', true);
                statusSpan.removeClass().addClass('loading').text('Processing...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_indexnow_bulk_sync',
                        nonce: '<?php echo wp_create_nonce('aia_indexnow_bulk_sync'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            statusSpan.removeClass().addClass('success').text('✅ ' + response.data.message);
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            statusSpan.removeClass().addClass('error').text('❌ ' + response.data.message);
                            button.prop('disabled', false);
                        }
                    },
                    error: function() {
                        statusSpan.removeClass().addClass('error').text('❌ Failed to process. Please try again.');
                        button.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render recent activity
     */
    private function render_recent_activity() {
        global $wpdb;
        
        $recent = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_date, pm.meta_value as status, pm2.meta_value as last_attempt
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_aia_indexnow_status'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_aia_indexnow_last_attempt'
            WHERE p.post_type = 'post'
            AND p.post_status = 'publish'
            AND pm.meta_key IS NOT NULL
            ORDER BY p.post_date DESC
            LIMIT 10"
        );
        
        if ($recent && !empty($recent)): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Post</th>
                        <th>Status</th>
                        <th>Last Attempt</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $post): 
                        $status = $post->status ? $post->status : 'pending';
                        $colors = [
                            'indexed' => '#28a745',
                            'pending' => '#f0ad4e',
                            'failed' => '#dc3545'
                        ];
                        $labels = [
                            'indexed' => '✅ Indexed',
                            'pending' => '⏳ Pending',
                            'failed' => '❌ Failed'
                        ];
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo get_edit_post_link($post->ID); ?>">
                                    <?php echo esc_html($post->post_title); ?>
                                </a>
                            </td>
                            <td>
                                <span style="color: <?php echo $colors[$status] ?? '#999'; ?>; font-weight: bold;">
                                    <?php echo $labels[$status] ?? $status; ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $post->last_attempt ? esc_html($post->last_attempt) : 'Never'; ?>
                            </td>
                            <td>
                                <?php if ($status === 'pending' || $status === 'failed'): ?>
                                    <button type="button" class="button button-small aia-indexnow-sync" data-post-id="<?php echo esc_attr($post->ID); ?>">
                                        Sync
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No indexed posts yet.</p>
        <?php endif;
    }
    
    /**
     * Get indexing statistics
     */
    private function get_indexing_stats() {
        global $wpdb;
        
        $indexed = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
            WHERE meta_key = '_aia_indexnow_status' 
            AND meta_value = 'indexed'"
        );
        
        $pending = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
            WHERE meta_key = '_aia_indexnow_status' 
            AND meta_value = 'pending'"
        );
        
        $failed = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
            WHERE meta_key = '_aia_indexnow_status' 
            AND meta_value = 'failed'"
        );
        
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
            WHERE meta_key = '_aia_indexnow_status'"
        );
        
        return [
            'indexed' => intval($indexed),
            'pending' => intval($pending),
            'failed' => intval($failed),
            'total' => intval($total)
        ];
    }
    
    /**
     * Add index status column to posts list
     */
    public function add_index_status_column($columns, $post_type) {
        if ($post_type === 'post') {
            $columns['aia_index_status'] = 'Index Status';
        }
        return $columns;
    }
    
    /**
     * Render index status column
     */
    public function render_index_status_column($column_name, $post_id) {
        if ($column_name !== 'aia_index_status') {
            return;
        }
        
        $status = get_post_meta($post_id, '_aia_indexnow_status', true);
        $status = $status ? $status : 'pending';
        
        $colors = [
            'indexed' => '#28a745',
            'pending' => '#f0ad4e',
            'failed' => '#dc3545'
        ];
        
        $labels = [
            'indexed' => '✅ Indexed',
            'pending' => '⏳ Pending',
            'failed' => '❌ Failed'
        ];
        
        $color = $colors[$status] ?? '#999';
        $label = $labels[$status] ?? 'Unknown';
        
        echo '<span style="color:' . $color . ';font-weight:bold;">' . $label . '</span>';
        
        if ($status === 'pending' || $status === 'failed') {
            echo ' <button type="button" class="button button-small aia-indexnow-sync" data-post-id="' . esc_attr($post_id) . '">Sync</button>';
        }
    }
    
    // ==================== CORE INDEXNOW FUNCTIONS ====================
    
    /**
     * Get API key for the specified engine
     */
    private function get_api_key($engine) {
        if ($engine === 'bing') {
            return $this->bing_api_key;
        } elseif ($engine === 'google') {
            return $this->google_api_key;
        }
        return '';
    }
    
    /**
     * Check if post should be processed
     */
    private function should_process_post($post) {
        if (!$this->enabled) {
            return false;
        }
        
        if ($post->post_status !== 'publish') {
            return false;
        }
        
        if ($post->post_type !== 'post') {
            return false;
        }
        
        // Check if at least one API key is configured
        if (empty($this->bing_api_key) && empty($this->google_api_key)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Notify when post is published
     */
    public function notify_post_published($post_id, $post) {
        if (!$this->should_process_post($post)) {
            return;
        }
        $this->submit_post_to_indexnow($post_id, 'publish');
    }
    
    /**
     * Notify when post is updated
     */
    public function notify_post_updated($post_id, $post_after, $post_before) {
        if (!$this->should_process_post($post_after)) {
            return;
        }
        
        if ($post_after->post_content === $post_before->post_content && 
            $post_after->post_title === $post_before->post_title) {
            return;
        }
        
        $this->submit_post_to_indexnow($post_id, 'update');
    }
    
    /**
     * Notify when post is deleted
     */
    public function notify_post_deleted($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') {
            return;
        }
        $this->delete_post_from_indexnow($post_id);
    }
    
    /**
     * Submit post to IndexNow
     */
    public function submit_post_to_indexnow($post_id, $action = 'publish') {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return false;
        }
        
        $url = get_permalink($post_id);
        $full_url = $this->get_full_url($url);
        
        $results = [];
        $engines_to_submit = $this->get_engines_to_submit();
        
        foreach ($engines_to_submit as $engine) {
            $api_key = $this->get_api_key($engine);
            if (empty($api_key)) {
                continue;
            }
            
            $data = [
                'host' => $this->host,
                'key' => $api_key,
                'keyLocation' => $this->get_key_location(),
                'urlList' => [$full_url]
            ];
            
            $results[$engine] = $this->send_to_indexnow($engine, $data);
        }
        
        // Update post meta with status
        $success = false;
        foreach ($results as $engine => $result) {
            if ($result['success']) {
                $success = true;
                break;
            }
        }
        
        $status = $success ? 'indexed' : 'failed';
        update_post_meta($post_id, '_aia_indexnow_status', $status);
        update_post_meta($post_id, '_aia_indexnow_last_attempt', current_time('mysql'));
        update_post_meta($post_id, '_aia_indexnow_url', $full_url);
        
        // Log the result
        $logger = new AIA_Logger();
        if ($success) {
            $logger->log("IndexNow: Post {$post_id} submitted successfully. URL: {$full_url}", 'success');
        } else {
            $errors = [];
            foreach ($results as $engine => $result) {
                if (!$result['success']) {
                    $errors[] = $engine . ': ' . ($result['message'] ?? 'Unknown error');
                }
            }
            $logger->log("IndexNow: Post {$post_id} submission failed. Errors: " . implode(', ', $errors), 'error');
        }
        
        return $success;
    }
    
    /**
     * Get engines to submit based on settings
     */
    private function get_engines_to_submit() {
        $engines = [];
        if ($this->search_engine === 'both' || $this->search_engine === 'bing') {
            $engines[] = 'bing';
        }
        if ($this->search_engine === 'both' || $this->search_engine === 'google') {
            $engines[] = 'google';
        }
        return $engines;
    }
    
    /**
     * Delete post from IndexNow
     */
    private function delete_post_from_indexnow($post_id) {
        $url = get_permalink($post_id);
        $full_url = $this->get_full_url($url);
        
        $engines_to_submit = $this->get_engines_to_submit();
        
        foreach ($engines_to_submit as $engine) {
            $api_key = $this->get_api_key($engine);
            if (empty($api_key)) {
                continue;
            }
            
            $data = [
                'host' => $this->host,
                'key' => $api_key,
                'keyLocation' => $this->get_key_location(),
                'urlList' => [$full_url]
            ];
            
            $this->send_to_indexnow($engine, $data, 'delete');
        }
        
        delete_post_meta($post_id, '_aia_indexnow_status');
        delete_post_meta($post_id, '_aia_indexnow_last_attempt');
        delete_post_meta($post_id, '_aia_indexnow_url');
        
        $logger = new AIA_Logger();
        $logger->log("IndexNow: Post {$post_id} deleted from search engines.", 'info');
        
        return true;
    }
    
    /**
     * Send to IndexNow endpoint
     */
    private function send_to_indexnow($engine, $data, $action = 'publish') {
        $endpoints = [
            'bing' => 'https://www.bing.com/indexnow',
            'google' => 'https://www.google.com/indexnow'
        ];
        
        $url = $endpoints[$engine] ?? $endpoints['bing'];
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept' => 'application/json'
            ],
            'body' => json_encode($data),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message()
            ];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 200 || $status_code === 202) {
            return [
                'success' => true,
                'message' => 'Successfully submitted to ' . ucfirst($engine)
            ];
        } elseif ($status_code === 400) {
            return [
                'success' => false,
                'message' => 'Bad request. Please check your API key and URL format.'
            ];
        } elseif ($status_code === 403) {
            return [
                'success' => false,
                'message' => 'Forbidden. Invalid API key.'
            ];
        } elseif ($status_code === 429) {
            return [
                'success' => false,
                'message' => 'Too many requests. Please wait and try again.'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Error ' . $status_code . ': ' . $body
            ];
        }
    }
    
    /**
     * Get full URL
     */
    private function get_full_url($url) {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
        return trailingslashit(get_site_url()) . ltrim($url, '/');
    }
    
    /**
     * Get key location URL
     */
    private function get_key_location() {
        return trailingslashit(get_site_url()) . 'indexnow-key.txt';
    }
    
    // ==================== AJAX METHODS ====================
    
    /**
     * AJAX: Sync single post
     */
    public function ajax_sync_post() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_indexnow_sync')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        if (!$post_id) {
            wp_send_json_error(['message' => 'Invalid post ID']);
        }
        
        $result = $this->submit_post_to_indexnow($post_id, 'sync');
        
        if ($result) {
            wp_send_json_success(['message' => 'Post submitted successfully.']);
        } else {
            wp_send_json_error(['message' => 'Failed to submit post. Check logs for details.']);
        }
    }
    
    /**
     * AJAX: Bulk sync all pending posts
     */
    public function ajax_bulk_sync() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_indexnow_bulk_sync')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        global $wpdb;
        
        $pending_posts = $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_aia_indexnow_status' 
            AND meta_value = 'pending'"
        );
        
        if (empty($pending_posts)) {
            wp_send_json_success(['message' => 'No pending posts found.']);
        }
        
        $synced = 0;
        $failed = 0;
        
        foreach ($pending_posts as $post_id) {
            $result = $this->submit_post_to_indexnow($post_id, 'bulk_sync');
            if ($result) {
                $synced++;
            } else {
                $failed++;
            }
            usleep(500000);
        }
        
        wp_send_json_success([
            'message' => "Sync complete! {$synced} posts synced, {$failed} failed."
        ]);
    }
}