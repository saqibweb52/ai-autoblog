<?php
// admin/generator-page.php
if (!defined('ABSPATH')) exit;

class AIA_Generator_Page {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_submenu_page'));
        add_action('wp_ajax_aia_start_generation', array($this, 'ajax_start_generation'));
        add_action('wp_ajax_aia_get_generation_log', array($this, 'ajax_get_generation_log'));
        add_action('wp_ajax_aia_background_generate', array($this, 'ajax_background_generate'));
        add_action('wp_ajax_aia_check_generation_status', array($this, 'ajax_check_generation_status'));
    }

    public function add_submenu_page() {
        add_submenu_page(
            'ai-autoblog',
            'Manual Generator',
            'Manual Generate',
            'manage_options',
            'ai-autoblog-generator',
            array($this, 'render_page')
        );
    }

    public function render_page() {
        $keywords_manager = new AIA_Keywords_Manager();
        $pending_keywords = $keywords_manager->get_pending_keywords();

        ?>
        <div class="wrap">
            <h1>Manual Content Generation</h1>

            <div class="aia-manual-generator">
                <form method="post" id="aia-generate-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="keyword_index">Keyword to Generate</label></th>
                            <td>
                                <?php if (!empty($pending_keywords)): ?>
                                    <select name="keyword_index" id="keyword_index" required>
                                        <option value="">Select a pending keyword</option>
                                        <?php foreach ($pending_keywords as $index => $keyword): ?>
                                            <option value="<?php echo $index; ?>">
                                                <?php echo esc_html($keyword['keyword']); ?> (Author ID: <?php echo $keyword['author_id']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <p class="description">No pending keywords available. <a href="?page=ai-autoblog-keywords">Add keywords first</a>.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <?php if (!empty($pending_keywords)): ?>
                        <button type="button" id="aia-start-generate" class="button button-primary">Generate and Publish</button>
                        <span id="aia-generate-status" style="margin-left: 10px;"></span>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Live Log Modal -->
            <div id="aia-log-modal" style="display:none;">
                <div class="aia-modal-overlay">
                    <div class="aia-modal-content aia-modal-large">
                        <div class="aia-modal-header">
                            <h2>📊 Generation Progress</h2>
                            <button type="button" class="aia-modal-close" id="aia-log-close">&times;</button>
                        </div>
                        <div class="aia-modal-body">
                            <div id="aia-log-container" style="background:#1e1e1e; color:#d4d4d4; padding:15px; border-radius:4px; font-family:monospace; max-height:500px; overflow-y:auto; white-space:pre-wrap; font-size:13px; line-height:1.6;">
                                <div id="aia-log-output">⏳ Waiting to start...</div>
                            </div>
                            <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center;">
                                <span id="aia-log-status" style="font-weight:bold;"></span>
                                <span id="aia-log-timestamp" style="color:#666; font-size:12px;"></span>
                            </div>
                            <div style="margin-top:10px; display:none;" id="aia-log-error-detail">
                                <div style="background:#f8d7da; padding:10px; border-radius:4px; color:#721c24; font-size:13px; border:1px solid #f5c6cb;">
                                    <strong>Error Details:</strong>
                                    <div id="aia-log-error-message"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="aia-generation-info">
                <h2>Generation Process</h2>
                <ol>
                    <li>Select a keyword from the list</li>
                    <li>The system will research the topic using Tavily</li>
                    <li>Content will be generated following author style rules</li>
                    <li>Relevant images will be added</li>
                    <li>Internal and external links will be inserted naturally</li>
                    <li>The post will be published automatically</li>
                </ol>
                <div class="notice notice-info">
                    <p><strong>Note:</strong> A live log window will show progress in real-time.</p>
                </div>
            </div>
        </div>

        <style>
            .aia-modal-overlay {
                position: fixed;
                top:0; left:0; width:100%; height:100%;
                background: rgba(0,0,0,0.7);
                z-index:100000;
                display:flex;
                align-items:center;
                justify-content:center;
            }
            .aia-modal-content {
                background:#fff;
                border-radius:8px;
                max-width:900px;
                width:95%;
                max-height:90vh;
                display:flex;
                flex-direction:column;
                box-shadow:0 4px 30px rgba(0,0,0,0.3);
            }
            .aia-modal-header {
                display:flex;
                justify-content:space-between;
                align-items:center;
                padding:15px 20px;
                border-bottom:1px solid #ddd;
                flex-shrink:0;
            }
            .aia-modal-header h2 { margin:0; }
            .aia-modal-close {
                background:none; border:none; font-size:28px; cursor:pointer; color:#999; padding:0 10px;
            }
            .aia-modal-close:hover { color:#333; }
            .aia-modal-body { padding:20px; overflow-y:auto; flex:1; }
            #aia-log-container {
                background:#1e1e1e; color:#d4d4d4; padding:15px; border-radius:4px;
                font-family:monospace; max-height:500px; overflow-y:auto;
                white-space:pre-wrap; font-size:13px; line-height:1.6;
            }
            .log-info { color:#8ab4f8; }
            .log-success { color:#81c995; }
            .log-error { color:#f28b82; }
            .log-warning { color:#f9ab00; }
            .log-debug { color:#9aa0a6; }
            #aia-log-status.success { color:#28a745; }
            #aia-log-status.error { color:#dc3545; }
            #aia-log-status.loading { color:#f0ad4e; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var pollInterval = null;
            var logKey = '';
            var lastEntryCount = 0;
            var startTime = null;

            $('#aia-start-generate').on('click', function() {
                var button = $(this);
                var status = $('#aia-generate-status');
                var keywordIndex = $('#keyword_index').val();

                if (!keywordIndex) {
                    alert('Please select a keyword.');
                    return;
                }

                button.prop('disabled', true).text('Generating...');
                status.removeClass().addClass('loading').text('⏳ Starting...');

                $('#aia-log-modal').show();
                $('body').css('overflow', 'hidden');
                $('#aia-log-output').html('⏳ Initializing...');
                $('#aia-log-status').text('Starting process...').addClass('loading');
                $('#aia-log-error-detail').hide();
                lastEntryCount = 0;
                startTime = new Date();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_start_generation',
                        keyword_index: keywordIndex,
                        nonce: '<?php echo wp_create_nonce('aia_start_generation'); ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            logKey = response.data.log_key;
                            $('#aia-log-status').text('🔄 Processing...').addClass('loading');
                            $('#aia-log-timestamp').text('Started: ' + new Date().toLocaleTimeString());
                            
                            if (pollInterval) clearInterval(pollInterval);
                            pollInterval = setInterval(function() {
                                pollLog(logKey);
                            }, 1000);
                            pollLog(logKey);
                        } else {
                            $('#aia-log-output').html('❌ Error: ' + response.data.message);
                            $('#aia-log-status').text('❌ Failed to start').removeClass('loading').addClass('error');
                            button.prop('disabled', false).text('Generate and Publish');
                            status.removeClass().addClass('error').text('❌ Error: ' + response.data.message);
                        }
                    },
                    error: function(xhr) {
                        var errorMsg = 'AJAX error. Check console.';
                        if (xhr.responseJSON && xhr.responseJSON.data) {
                            errorMsg = xhr.responseJSON.data.message || errorMsg;
                        }
                        $('#aia-log-output').html('❌ ' + errorMsg);
                        $('#aia-log-status').text('❌ Error').removeClass('loading').addClass('error');
                        button.prop('disabled', false).text('Generate and Publish');
                        status.removeClass().addClass('error').text('❌ ' + errorMsg);
                    }
                });
            });

            function pollLog(key) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_get_generation_log',
                        log_key: key,
                        nonce: '<?php echo wp_create_nonce('aia_get_generation_log'); ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            var log = response.data;
                            
                            if (log && log.entries) {
                                if (log.entries.length > lastEntryCount) {
                                    var output = '';
                                    $.each(log.entries, function(i, entry) {
                                        var typeClass = 'log-' + entry.type;
                                        var timeDisplay = entry.time.replace('T', ' ').substring(0, 19);
                                        output += '<div class="' + typeClass + '">[' + timeDisplay + '] ' + entry.message + '</div>';
                                    });
                                    $('#aia-log-output').html(output);
                                    var container = document.getElementById('aia-log-container');
                                    container.scrollTop = container.scrollHeight;
                                    lastEntryCount = log.entries.length;
                                }
                                
                                if (log.entries.length > 0) {
                                    var lastEntry = log.entries[log.entries.length - 1];
                                    var elapsed = Math.floor((new Date() - startTime) / 1000);
                                    var timeDisplay = new Date().toLocaleTimeString();
                                    $('#aia-log-timestamp').text('Updated: ' + timeDisplay + ' | Elapsed: ' + elapsed + 's');
                                }
                            }
                            
                            if (log.completed) {
                                clearInterval(pollInterval);
                                $('#aia-start-generate').prop('disabled', false).text('Generate and Publish');
                                var elapsed = Math.floor((new Date() - startTime) / 1000);
                                $('#aia-log-timestamp').text('Completed after ' + elapsed + ' seconds');
                                
                                if (log.success) {
                                    $('#aia-log-status').text('✅ ' + log.message).removeClass('loading').addClass('success');
                                    $('#aia-generate-status').removeClass().addClass('success').text('✅ Success: ' + log.message);
                                    setTimeout(function() {
                                        $('#aia-log-modal').hide();
                                        $('body').css('overflow', 'auto');
                                        location.reload();
                                    }, 5000);
                                } else {
                                    $('#aia-log-status').text('❌ Failed: ' + log.message).removeClass('loading').addClass('error');
                                    $('#aia-generate-status').removeClass().addClass('error').text('❌ Error: ' + log.message);
                                    $('#aia-log-error-detail').show();
                                    $('#aia-log-error-message').text(log.message);
                                }
                            }
                        }
                    },
                    error: function(xhr) {
                        console.log('Poll error:', xhr);
                    }
                });
            }

            $('#aia-log-close, .aia-modal-overlay').on('click', function(e) {
                if (e.target === this || $(this).hasClass('aia-modal-close')) {
                    $('#aia-log-modal').hide();
                    $('body').css('overflow', 'auto');
                    if (pollInterval) clearInterval(pollInterval);
                    $('#aia-start-generate').prop('disabled', false).text('Generate and Publish');
                }
            });

            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    $('#aia-log-modal').hide();
                    $('body').css('overflow', 'auto');
                    if (pollInterval) clearInterval(pollInterval);
                    $('#aia-start-generate').prop('disabled', false).text('Generate and Publish');
                }
            });
        });
        </script>
        <?php
    }

    // ==================== AJAX HANDLERS ====================

    public function ajax_start_generation() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_start_generation')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $keyword_index = isset($_POST['keyword_index']) ? intval($_POST['keyword_index']) : -1;
        if ($keyword_index < 0) {
            wp_send_json_error(['message' => 'Invalid keyword index']);
        }

        $keywords_manager = new AIA_Keywords_Manager();
        $keywords = $keywords_manager->get_all_keywords();
        if (!isset($keywords[$keyword_index])) {
            wp_send_json_error(['message' => 'Keyword not found']);
        }

        $keyword_data = $keywords[$keyword_index];

        $logger = new AIA_Process_Logger();
        $log_key = $logger->start_log("Starting generation for keyword: " . $keyword_data['keyword']);

        $args = [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => false,
            'body' => [
                'action' => 'aia_background_generate',
                'keyword_index' => $keyword_index,
                'log_key' => $log_key,
                'nonce' => wp_create_nonce('aia_background_generate')
            ]
        ];
        wp_remote_post(admin_url('admin-ajax.php'), $args);

        wp_send_json_success(['log_key' => $log_key]);
    }

    public function ajax_get_generation_log() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_get_generation_log')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $log_key = isset($_POST['log_key']) ? sanitize_text_field($_POST['log_key']) : '';
        if (empty($log_key)) {
            wp_send_json_error(['message' => 'Missing log key']);
        }

        $logger = new AIA_Process_Logger($log_key);
        $log = $logger->get_log();
        if (!$log) {
            wp_send_json_error(['message' => 'Log not found or expired']);
        }

        wp_send_json_success($log);
    }

    public function ajax_check_generation_status() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_check_generation_status')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $log_key = isset($_POST['log_key']) ? sanitize_text_field($_POST['log_key']) : '';
        if (empty($log_key)) {
            wp_send_json_error(['message' => 'Missing log key']);
        }

        $logger = new AIA_Process_Logger($log_key);
        $log = $logger->get_log();
        if (!$log) {
            wp_send_json_error(['message' => 'Log not found']);
        }

        wp_send_json_success([
            'completed' => isset($log['completed']) && $log['completed'],
            'success' => $log['success'] ?? false,
            'message' => $log['message'] ?? '',
            'entry_count' => count($log['entries'] ?? [])
        ]);
    }

    public function ajax_background_generate() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_background_generate')) {
            die('Security check failed');
        }
        if (!current_user_can('manage_options')) {
            die('Insufficient permissions');
        }

        $keyword_index = isset($_POST['keyword_index']) ? intval($_POST['keyword_index']) : -1;
        $log_key = isset($_POST['log_key']) ? sanitize_text_field($_POST['log_key']) : '';

        if ($keyword_index < 0 || empty($log_key)) {
            $logger = new AIA_Process_Logger($log_key);
            $logger->add_entry('error', 'Invalid parameters');
            $logger->complete(false, 'Invalid parameters');
            die();
        }

        $keywords_manager = new AIA_Keywords_Manager();
        $keywords = $keywords_manager->get_all_keywords();
        
        if (!isset($keywords[$keyword_index])) {
            $logger = new AIA_Process_Logger($log_key);
            $logger->add_entry('error', 'Keyword not found');
            $logger->complete(false, 'Keyword not found');
            die();
        }

        $keyword_data = $keywords[$keyword_index];
        $exact_keyword = trim($keyword_data['keyword']);
        
        $logger = new AIA_Process_Logger($log_key);
        $logger->add_entry('info', "📝 Background process started for keyword: '{$exact_keyword}'");

        $keywords_manager->update_keyword_status($keyword_index, 'processing');
        $logger->add_entry('info', "✅ Keyword marked as processing");

        // ============================================================
        // STEP 1: Generate Content
        // ============================================================
        $logger->add_entry('info', "🤖 Initializing content generator...");
        $generator = new AIA_Content_Generator();
        $logger->add_entry('info', "📝 Generating content for: '{$exact_keyword}'");
        
        $generated = $generator->generate_post(
            $exact_keyword,
            $keyword_data['author_id'],
            isset($keyword_data['categories']) ? $keyword_data['categories'] : array(),
            $logger
        );

        if (!$generated) {
            $logger->add_entry('error', '❌ Content generation failed');
            $keywords_manager->update_keyword_status($keyword_index, 'pending');
            $logger->complete(false, 'Content generation failed');
            die();
        }
        $logger->add_entry('success', '✅ Content generated successfully!');

        // ============================================================
        // STEP 2: Ensure Keyword Consistency
        // ============================================================
        $logger->add_entry('info', "🔧 Ensuring keyword consistency...");
        
        if (!empty($generated['title']) && stripos($generated['title'], $exact_keyword) === false) {
            $generated['title'] = $this->add_keyword_to_title($generated['title'], $exact_keyword);
            $logger->add_entry('info', "Added keyword to title: {$generated['title']}");
        }
        
        if (!empty($generated['meta_description']) && stripos($generated['meta_description'], $exact_keyword) === false) {
            $generated['meta_description'] = $this->add_keyword_to_meta($generated['meta_description'], $exact_keyword);
            $logger->add_entry('info', "Added keyword to meta description");
        }
        
        if (stripos($generated['content'], $exact_keyword) === false) {
            $generated['content'] = $this->add_keyword_to_content($generated['content'], $exact_keyword);
            $logger->add_entry('info', "Added keyword to content body");
        }
        
        $generated['keyword'] = $exact_keyword;

        // ============================================================
        // STEP 3: Add Internal Links
        // ============================================================
        $logger->add_entry('info', "🔗 Adding internal links to content...");
        $link_manager = new AIA_Link_Manager();
        
        $all_content = $link_manager->get_all_published_content(null);
        $logger->add_entry('debug', "Found " . count($all_content) . " published posts/pages");
        
        $matches = $link_manager->find_relevant_internal_links($exact_keyword, $all_content, null);
        $logger->add_entry('debug', "Found " . count($matches) . " relevant internal links");
        
        if (!empty($matches)) {
            $candidates = [];
            foreach ($matches as $match) {
                $anchor = $link_manager->get_internal_link_anchor($match['post'], $exact_keyword);
                $candidates[] = [
                    'anchor' => $anchor,
                    'url' => $match['url'],
                    'relevance' => $match['relevance']
                ];
            }
            
            $max_internal = $link_manager->get_max_internal_links();
            $content_with_links = $link_manager->insert_links_naturally(
                $generated['content'],
                $candidates,
                $max_internal,
                'internal',
                $logger
            );
            
            if ($content_with_links !== $generated['content']) {
                $logger->add_entry('success', "✅ Internal links were added to content");
                $generated['content'] = $content_with_links;
            } else {
                $logger->add_entry('warning', "⚠️ No internal links were added to content");
            }
        }

        // ============================================================
        // STEP 4: Add External Links
        // ============================================================
        $logger->add_entry('info', "🔗 Adding external links to content...");
        
        $external_links = $link_manager->get_external_links_for_keyword($exact_keyword);
        $logger->add_entry('debug', "Found " . count($external_links) . " external links");
        
        if (!empty($external_links)) {
            $candidates = [];
            foreach ($external_links as $link) {
                $candidates[] = [
                    'anchor' => $link['anchor'],
                    'url' => $link['url'],
                    'relevance' => $link['relevance']
                ];
            }
            
            $max_external = $link_manager->get_max_external_links();
            $content_with_links = $link_manager->insert_links_naturally(
                $generated['content'],
                $candidates,
                $max_external,
                'external',
                $logger
            );
            
            if ($content_with_links !== $generated['content']) {
                $logger->add_entry('success', "✅ External links were added to content");
                $generated['content'] = $content_with_links;
            } else {
                $logger->add_entry('warning', "⚠️ No external links were added to content");
            }
        }

        // ============================================================
        // STEP 5: Add Nofollow to External Links
        // ============================================================
        $logger->add_entry('info', "🔒 Processing nofollow for external links...");
        $content_with_nofollow = $link_manager->add_nofollow_to_external_links($generated['content']);
        if ($content_with_nofollow !== $generated['content']) {
            $logger->add_entry('success', "✅ Nofollow attributes added to external links");
            $generated['content'] = $content_with_nofollow;
        }

        // ============================================================
        // STEP 6: Publish Post
        // ============================================================
        $logger->add_entry('info', "📤 Publishing post...");
        $publisher = new AIA_Publisher();
        $post_id = $publisher->publish_post($generated);

        if ($post_id) {
            $keywords_manager->update_keyword_status($keyword_index, 'done');
            $logger->add_entry('success', "✅ Post published successfully! Post ID: {$post_id}");
            $logger->add_entry('info', "🔗 View post: " . get_permalink($post_id));
            delete_transient('aia_query_cache_' . md5($exact_keyword . '_8'));
            $logger->complete(true, "Post published successfully! ID: {$post_id}");
        } else {
            $keywords_manager->update_keyword_status($keyword_index, 'pending');
            $logger->add_entry('error', "❌ Failed to publish post.");
            $logger->complete(false, "Failed to publish post.");
        }

        die();
    }

    // ============================================================
    // Helper Functions for Keyword Consistency
    // ============================================================

    private function add_keyword_to_title($title, $keyword) {
        if (empty($title)) {
            return ucwords($keyword);
        }
        if (stripos($title, $keyword) !== false) {
            return $title;
        }
        $separator = ' - ';
        return trim($title) . $separator . ucwords($keyword);
    }

    private function add_keyword_to_meta($meta, $keyword) {
        if (empty($meta)) {
            return "Learn everything about " . ucwords($keyword) . ". Discover insights, tips, and best practices.";
        }
        if (stripos($meta, $keyword) !== false) {
            return $meta;
        }
        return ucwords($keyword) . " - " . $meta;
    }

    private function add_keyword_to_content($content, $keyword) {
        if (stripos($content, $keyword) !== false) {
            return $content;
        }
        
        $pattern = '/<p[^>]*>(.*?)<\/p>/i';
        if (preg_match($pattern, $content, $matches)) {
            $first_para = $matches[0];
            $new_para = preg_replace(
                '/<p[^>]*>(.*?)<\/p>/i',
                '<p>$1 ' . esc_html($keyword) . '.</p>',
                $first_para,
                1
            );
            return str_replace($first_para, $new_para, $content);
        }
        
        return '<p>' . esc_html($keyword) . '. ' . strip_tags($content) . '</p>';
    }
}