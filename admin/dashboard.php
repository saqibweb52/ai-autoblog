<?php
// admin/dashboard.php
if (!defined('ABSPATH')) exit;

class AIA_Admin_Dashboard {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_aia_manual_generate', array($this, 'ajax_manual_generate'));
        add_action('wp_ajax_aia_regenerate_post', array($this, 'ajax_regenerate_post'));
    }

    public function add_admin_menu() {
    add_menu_page(
        'Blog Autom',
        'Blog Autom',
        'manage_options',
        'ai-autoblog',
        array($this, 'render_dashboard'),
        'data:image/svg+xml;base64,' . base64_encode(file_get_contents(AIA_PLUGIN_DIR . 'assets/icon.svg')),
        30
    );
    }

    public function render_dashboard() {
        $keywords_manager = new AIA_Keywords_Manager();
        $stats = $this->get_statistics();
        $pending_keywords = $keywords_manager->get_pending_keywords();

        ?>
        <div class="wrap aia-dashboard">
            <h1>Dashboard</h1>

            <div class="aia-stats-grid">
                <div class="aia-stat-card">
                    <h3>Total Posts</h3>
                    <div class="stat-number"><?php echo isset($stats['total_posts']) ? $stats['total_posts'] : 0; ?></div>
                </div>
                <div class="aia-stat-card">
                    <h3>Pending Keywords</h3>
                    <div class="stat-number"><?php echo count($pending_keywords); ?></div>
                </div>
                <div class="aia-stat-card">
                    <h3>Posts Today</h3>
                    <div class="stat-number"><?php echo isset($stats['posts_today']) ? $stats['posts_today'] : 0; ?></div>
                </div>
            </div>

            <div class="aia-quick-actions">
                <h2>Quick Actions</h2>
                <a href="?page=ai-autoblog-keywords" class="button button-primary">Add Keywords</a>

                <?php if (!empty($pending_keywords)): ?>
                    <button id="aia-manual-generate" class="button button-secondary">Generate Next Post Now</button>
                    <span id="aia-generate-status" style="margin-left: 10px;"></span>
                    <p class="description" style="margin-top: 10px;">
                        <?php echo count($pending_keywords); ?> keyword(s) waiting in queue
                    </p>
                <?php else: ?>
                    <button class="button button-secondary" disabled>No Pending Keywords</button>
                    <p class="description" style="margin-top: 10px;">Add keywords to start generating content</p>
                <?php endif; ?>
            </div>

            <div class="aia-generated-posts">
                <h2>Recently Generated Posts</h2>
                <?php $this->render_generated_posts(); ?>
            </div>
        </div>

        <style>
            .aia-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            .aia-stat-card {
                background: #fff;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                text-align: center;
            }
            .stat-number {
                font-size: 32px;
                font-weight: bold;
                color: #2271b1;
                margin: 10px 0;
            }
            .aia-quick-actions {
                background: #fff;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .aia-quick-actions .button { margin-right: 10px; }
            .aia-generated-posts {
                background: #fff;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .aia-generated-posts h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 2px solid #f0f0f1;
            }
            .aia-generated-posts table { margin-top: 10px; }
            .aia-generated-posts .post-status {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
            }
            .post-status.publish { background: #d4edda; color: #155724; }
            .post-status.draft { background: #fff3cd; color: #856404; }
            .post-status.pending { background: #f0ad4e; color: #fff; }

            .aia-regenerate-btn {
                color: #f0ad4e !important;
                border-color: #f0ad4e !important;
            }
            .aia-regenerate-btn:hover {
                background: #f0ad4e !important;
                color: #fff !important;
            }
            .aia-regenerate-status {
                font-size: 12px;
                margin-left: 5px;
            }
            .aia-regenerate-status.success { color: #28a745; }
            .aia-regenerate-status.error { color: #dc3545; }
            .aia-regenerate-status.loading { color: #f0ad4e; }

            #aia-generate-status.success { color: #28a745; }
            #aia-generate-status.error { color: #dc3545; }
            #aia-generate-status.loading { color: #f0ad4e; }

            .aia-keyword-tag {
                display: inline-block;
                background: #e9ecef;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 12px;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Manual generate
            $('#aia-manual-generate').on('click', function() {
                var button = $(this);
                var status = $('#aia-generate-status');
                button.prop('disabled', true);
                status.removeClass().addClass('loading').text('Processing...');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_manual_generate',
                        nonce: '<?php echo wp_create_nonce('aia_manual_generate'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            status.removeClass().addClass('success').text('✅ Success: ' + response.data.message);
                            setTimeout(function() { location.reload(); }, 3000);
                        } else {
                            status.removeClass().addClass('error').text('❌ Error: ' + response.data.message);
                            button.prop('disabled', false);
                        }
                    },
                    error: function() {
                        status.removeClass().addClass('error').text('❌ Failed to process.');
                        button.prop('disabled', false);
                    }
                });
            });

            // ========== REGENERATE POST ==========
            $('.aia-regenerate-btn').on('click', function() {
                var button = $(this);
                var postId = button.data('post-id');
                var postTitle = button.data('post-title');
                var statusSpan = button.siblings('.aia-regenerate-status');

                if (!postId) return;

                // Ask for confirmation
                if (!confirm('Are you sure you want to regenerate this post?\n\nTitle: "' + postTitle + '"\n\nThis will replace the content with a new version while keeping the same title, keyword, and meta description.')) {
                    return;
                }

                button.prop('disabled', true).text('Regenerating...');
                statusSpan.removeClass().addClass('loading').text('⏳ Processing...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_regenerate_post',
                        post_id: postId,
                        nonce: '<?php echo wp_create_nonce('aia_regenerate_post'); ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            statusSpan.removeClass().addClass('success').text('✅ ' + response.data.message);
                            button.text('✅ Regenerated').prop('disabled', true);
                            // Reload after 2 seconds to show updated content
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            statusSpan.removeClass().addClass('error').text('❌ ' + response.data.message);
                            button.prop('disabled', false).text('🔄 Regenerate');
                        }
                    },
                    error: function(xhr) {
                        var errorMsg = 'Failed to regenerate.';
                        if (xhr.responseJSON && xhr.responseJSON.data) {
                            errorMsg = xhr.responseJSON.data.message || errorMsg;
                        }
                        statusSpan.removeClass().addClass('error').text('❌ ' + errorMsg);
                        button.prop('disabled', false).text('🔄 Regenerate');
                    }
                });
            });
        });
        </script>
        <?php
    }

    private function get_statistics() {
        global $wpdb;
        $keywords_manager = new AIA_Keywords_Manager();

        $today = date('Y-m-d');
        $posts_today = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND DATE(post_date) = %s
            AND post_type = 'post'",
            $today
        ));

        if ($posts_today === null) $posts_today = 0;

        $total_posts = wp_count_posts()->publish;
        if ($total_posts === null) $total_posts = 0;

        $pending_keywords = count($keywords_manager->get_pending_keywords());

        return [
            'total_posts' => $total_posts,
            'pending_keywords' => $pending_keywords,
            'posts_today' => $posts_today
        ];
    }

    private function render_generated_posts() {
        global $wpdb;

        $generated_posts = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_date, p.post_status, pm.meta_value as keyword,
                    pm2.meta_value as meta_description
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_aia_keyword'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_aia_meta_description'
            WHERE p.post_type = 'post'
            AND EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} 
                WHERE post_id = p.ID 
                AND meta_key = '_aia_generated'
            )
            ORDER BY p.post_date DESC
            LIMIT 20"
        );

        if ($generated_posts && !empty($generated_posts)) {
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="50">ID</th>
                        <th>Title</th>
                        <th>Keyword</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th width="180">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($generated_posts as $post): ?>
                        <tr>
                            <td><?php echo esc_html($post->ID); ?></td>
                            <td>
                                <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" target="_blank">
                                    <?php echo esc_html($post->post_title); ?>
                                </a>
                            </td>
                            <td>
                                <span class="aia-keyword-tag"><?php echo esc_html($post->keyword); ?></span>
                            </td>
                            <td>
                                <span class="post-status <?php echo esc_attr($post->post_status); ?>">
                                    <?php echo esc_html(ucfirst($post->post_status)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(get_the_date('F j, Y', $post->ID)); ?></td>
                            <td>
                                <a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>" class="button button-small">
                                    Edit
                                </a>
                                <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" class="button button-small" target="_blank">
                                    View
                                </a>
                                <button type="button" 
                                        class="button button-small aia-regenerate-btn" 
                                        data-post-id="<?php echo esc_attr($post->ID); ?>"
                                        data-post-title="<?php echo esc_attr($post->post_title); ?>">
                                    🔄 Regen
                                </button>
                                <span class="aia-regenerate-status"></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        } else {
            echo '<p>No AI-generated posts yet. Add keywords to start generating content.</p>';
        }
    }

    public function ajax_manual_generate() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_manual_generate')) {
            wp_send_json_error('Security check failed');
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $keywords_manager = new AIA_Keywords_Manager();
        $pending = $keywords_manager->get_pending_keywords();

        if (empty($pending)) {
            wp_send_json_error('No pending keywords available.');
        }

        reset($pending);
        $index = key($pending);
        $keyword_data = $pending[$index];

        $result = $this->process_single_keyword($index, $keyword_data);

        if ($result) {
            wp_send_json_success(['message' => 'Post generated successfully for "' . $keyword_data['keyword'] . '"']);
        } else {
            wp_send_json_error('Failed to generate post. Check logs for details.');
        }
    }

    private function process_single_keyword($index, $keyword_data) {
        $logger = new AIA_Logger();

        if (!class_exists('AIA_Content_Generator')) {
            $logger->log('AIA_Content_Generator class not found', 'error');
            return false;
        }
        if (!class_exists('AIA_Publisher')) {
            $logger->log('AIA_Publisher class not found', 'error');
            return false;
        }
        if (!class_exists('AIA_Link_Manager')) {
            $logger->log('AIA_Link_Manager class not found', 'error');
            return false;
        }
        if (!class_exists('AIA_Image_Manager')) {
            $logger->log('AIA_Image_Manager class not found', 'error');
            return false;
        }

        $generator = new AIA_Content_Generator();
        $publisher = new AIA_Publisher();
        $link_manager = new AIA_Link_Manager();
        $image_manager = new AIA_Image_Manager();
        $keywords_manager = new AIA_Keywords_Manager();

        try {
            $keywords_manager->update_keyword_status($index, 'processing');
            $logger->log("Processing keyword: '" . $keyword_data['keyword'] . "' (Author ID: {$keyword_data['author_id']})", 'info');

            $generated = $generator->generate_post(
                $keyword_data['keyword'],
                isset($keyword_data['author_id']) ? $keyword_data['author_id'] : 1,
                isset($keyword_data['categories']) ? $keyword_data['categories'] : array()
            );

            if (!$generated || !is_array($generated)) {
                throw new Exception('Failed to generate post content');
            }

            if (!isset($generated['content'])) {
                throw new Exception('Generated content is missing');
            }

            $logger->log("Content generated successfully. Length: " . strlen($generated['content']), 'debug');

            $generated['content'] = $link_manager->add_links(
                $generated['content'],
                $keyword_data['keyword']
            );

            $image_data = $image_manager->get_image_for_post(array(
                'keyword' => $keyword_data['keyword'],
                'title' => $generated['title'] ?? $keyword_data['keyword']
            ));

            if ($image_data && isset($image_data['url'])) {
                $image_html = '<figure><img src="' . esc_url($image_data['url']) . '" alt="' . esc_attr($keyword_data['keyword']) . '" /></figure>';
                $generated['content'] = $image_html . "\n\n" . $generated['content'];
                $generated['featured_image'] = $image_data['url'];
                $logger->log("Image added to content", 'info');
            }

            $generated['keyword'] = $keyword_data['keyword'];
            $generated['author_id'] = isset($keyword_data['author_id']) ? $keyword_data['author_id'] : 1;

            if (!empty($keyword_data['categories'])) {
                $generated['categories'] = $keyword_data['categories'];
            }

            $post_id = $publisher->publish_post($generated);

            if ($post_id) {
                $keywords_manager->update_keyword_status($index, 'done');
                $logger->log("Post published successfully. Post ID: {$post_id}", 'success');
                return true;
            } else {
                throw new Exception('Failed to publish post');
            }

        } catch (Exception $e) {
            $keywords_manager->update_keyword_status($index, 'pending');
            $logger->log("Manual generation error for '{$keyword_data['keyword']}': {$e->getMessage()}", 'error');
            return false;
        }
    }

    // ========== AJAX: REGENERATE POST ==========
    public function ajax_regenerate_post() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_regenerate_post')) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error(['message' => 'Invalid post ID']);
            return;
        }

        $logger = new AIA_Logger();
        $logger->log("Starting regeneration for post ID: {$post_id}", 'info');

        // Get the existing post
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') {
            wp_send_json_error(['message' => 'Post not found']);
            return;
        }

        // Get the original keyword
        $keyword = get_post_meta($post_id, '_aia_keyword', true);
        if (empty($keyword)) {
            wp_send_json_error(['message' => 'No keyword found for this post']);
            return;
        }

        // Get the author ID
        $author_id = $post->post_author;

        // Get the original meta description
        $meta_description = get_post_meta($post_id, '_aia_meta_description', true);

        // Get categories
        $categories = wp_get_post_categories($post_id, array('fields' => 'ids'));

        $logger->log("Regenerating post for keyword: '{$keyword}'", 'info');

        // ========== REGENERATE CONTENT ==========
        $generator = new AIA_Content_Generator();
        $link_manager = new AIA_Link_Manager();
        $image_manager = new AIA_Image_Manager();
        $publisher = new AIA_Publisher();

        try {
            // Generate new content
            $generated = $generator->generate_post(
                $keyword,
                $author_id,
                $categories
            );

            if (!$generated || !is_array($generated) || empty($generated['content'])) {
                throw new Exception('Failed to generate new content');
            }

            // Keep the original title
            $generated['title'] = $post->post_title;

            // Keep the original meta description if it exists
            if (!empty($meta_description)) {
                $generated['meta_description'] = $meta_description;
            }

            // Keep the original keyword
            $generated['keyword'] = $keyword;

            // Keep the author
            $generated['author_id'] = $author_id;

            // Keep categories
            $generated['categories'] = $categories;

            // Add links
            $generated['content'] = $link_manager->add_links(
                $generated['content'],
                $keyword
            );

            // Add image
            $image_data = $image_manager->get_image_for_post(array(
                'keyword' => $keyword,
                'title' => $post->post_title
            ));

            if ($image_data && isset($image_data['url'])) {
                $image_html = '<figure><img src="' . esc_url($image_data['url']) . '" alt="' . esc_attr($keyword) . '" /></figure>';
                $generated['content'] = $image_html . "\n\n" . $generated['content'];
                $generated['featured_image'] = $image_data['url'];
                $logger->log("Image added to regenerated content", 'info');
            }

            // Update the post
            $updated = $publisher->update_post($post_id, $generated);

            if ($updated) {
                // Add a meta flag to indicate it was regenerated
                $regenerate_count = get_post_meta($post_id, '_aia_regenerate_count', true);
                $regenerate_count = intval($regenerate_count) + 1;
                update_post_meta($post_id, '_aia_regenerate_count', $regenerate_count);
                update_post_meta($post_id, '_aia_last_regenerated', current_time('mysql'));

                $logger->log("Post regenerated successfully. Post ID: {$post_id}", 'success');
                wp_send_json_success([
                    'message' => 'Post regenerated successfully! (Regenerated ' . $regenerate_count . ' times)',
                    'regenerate_count' => $regenerate_count
                ]);
            } else {
                throw new Exception('Failed to update post');
            }

        } catch (Exception $e) {
            $logger->log("Regeneration error for post {$post_id}: {$e->getMessage()}", 'error');
            wp_send_json_error(['message' => 'Failed to regenerate: ' . $e->getMessage()]);
        }
    }
}