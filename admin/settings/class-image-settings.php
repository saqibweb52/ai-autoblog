<?php
// admin/settings/class-image-settings.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Image_Settings {
    
    private $image_manager;
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_submenu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_aia_test_unsplash', array($this, 'test_unsplash_connection'));
        add_action('wp_ajax_aia_search_images', array($this, 'search_images'));
        add_action('wp_ajax_aia_set_featured_image', array($this, 'set_featured_image_ajax'));
        add_action('wp_ajax_aia_get_featured_image', array($this, 'get_featured_image'));
        
        // Initialize Image Manager
        $this->image_manager = new AIA_Image_Manager();
        
        // Add filter to allow external image uploads
        add_filter('upload_mimes', array($this, 'allow_unsplash_mime_types'));
        add_filter('wp_handle_upload_prefilter', array($this, 'fix_unsplash_filename'));
        add_filter('wp_check_filetype_and_ext', array($this, 'fix_unsplash_filetype'), 10, 4);
    }
    
    public function add_submenu_page() {
        add_submenu_page(
            'ai-autoblog',
            'Image Settings',
            'Image Settings',
            'manage_options',
            'ai-autoblog-image-settings',
            array($this, 'render_page')
        );
    }
    
    public function register_settings() {
        register_setting('aia_image_settings', 'aia_unsplash_access_key');
    }
    
    /**
     * Allow additional mime types for Unsplash images
     */
    public function allow_unsplash_mime_types($mimes) {
        $mimes['jpg'] = 'image/jpeg';
        $mimes['jpeg'] = 'image/jpeg';
        $mimes['png'] = 'image/png';
        $mimes['webp'] = 'image/webp';
        return $mimes;
    }
    
    /**
     * Fix filename for Unsplash images
     */
    public function fix_unsplash_filename($file) {
        if (!empty($file['name']) && !preg_match('/\.[a-zA-Z0-9]+$/', $file['name'])) {
            $file['name'] .= '.jpg';
        }
        return $file;
    }
    
    /**
     * Fix filetype detection for Unsplash images
     */
    public function fix_unsplash_filetype($data, $file, $filename, $mimes) {
        $wp_filetype = wp_check_filetype($filename, $mimes);
        
        if (!$wp_filetype['ext'] && !$wp_filetype['type']) {
            $file_content = @file_get_contents($file);
            if ($file_content !== false) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_buffer($finfo, $file_content);
                finfo_close($finfo);
                
                if ($mime_type) {
                    $extensions = array(
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp'
                    );
                    
                    if (isset($extensions[$mime_type])) {
                        $data['ext'] = $extensions[$mime_type];
                        $data['type'] = $mime_type;
                        $data['proper_filename'] = $filename;
                    }
                }
            }
        }
        
        return $data;
    }
    
    public function render_page() {
        $unsplash_access_key = get_option('aia_unsplash_access_key', '');
        $test_nonce = wp_create_nonce('aia_test_unsplash');
        
        // Get all posts for the dropdown
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        ?>
        <div class="wrap">
            <h1>🖼️ Image Settings</h1>
            <p class="description">Configure Unsplash API and manage featured images for your posts.</p>
            
            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success"><p>Settings saved successfully!</p></div>
            <?php endif; ?>
            
            <!-- Unsplash Configuration -->
            <div class="aia-settings-section">
                <h2>Unsplash Configuration</h2>
                
                <form method="post" action="options.php">
                    <?php settings_fields('aia_image_settings'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aia_unsplash_access_key">Unsplash Access Key</label>
                            </th>
                            <td>
                                <input type="password" 
                                       name="aia_unsplash_access_key" 
                                       id="aia_unsplash_access_key" 
                                       value="<?php echo esc_attr($unsplash_access_key); ?>"
                                       class="regular-text"
                                       autocomplete="off"
                                       placeholder="Enter your Unsplash Access Key">
                                <p class="description">
                                    <a href="https://unsplash.com/developers" target="_blank">Get your free Unsplash API Key</a>
                                </p>
                                <?php if (!empty($unsplash_access_key)): ?>
                                    <p style="color: #28a745; margin-top: 5px;">✅ API key configured</p>
                                <?php else: ?>
                                    <p style="color: #dc3545; margin-top: 5px;">❌ API key not configured</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label>Test Connection</label>
                            </th>
                            <td>
                                <button type="button" id="aia_test_unsplash" class="button button-secondary">
                                    Test Unsplash Connection
                                </button>
                                <span id="aia_unsplash_test_result" style="margin-left: 10px;"></span>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button('Save Image Settings', 'primary', 'submit'); ?>
                </form>
            </div>
            
            <!-- Unified Image Search & Management -->
            <div class="aia-settings-section">
                <h2>🔍 Image Search & Management</h2>
                <p class="description">Search for images manually or select a post to auto-search using its keyword.</p>
                
                <div class="aia-image-search">
                    <!-- Line 1: Image search field + search button -->
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aia_search_keyword">Search Images</label>
                            </th>
                            <td>
                                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <input type="text" 
                                           id="aia_search_keyword" 
                                           class="regular-text" 
                                           placeholder="Enter any keyword to search images..."
                                           style="width: 350px;">
                                    <button type="button" id="aia_search_images" class="button button-primary">
                                        🔍 Search
                                    </button>
                                    <span id="aia_search_status" style="margin-left: 5px; font-size: 13px;"></span>
                                </div>
                                <p class="description">Enter a keyword to manually search for images.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- Line 2: Post select dropdown with auto-image search + current featured image -->
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label>Select Post</label>
                            </th>
                            <td>
                                <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                                    <select id="aia_select_post" style="width: 350px;">
                                        <option value="">— Select a post —</option>
                                        <?php foreach ($posts as $post): 
                                            $post_keyword = get_post_meta($post->ID, '_aia_keyword', true);
                                            $keyword_display = !empty($post_keyword) ? ' (Keyword: ' . esc_html($post_keyword) . ')' : '';
                                        ?>
                                            <option value="<?php echo esc_attr($post->ID); ?>" data-keyword="<?php echo esc_attr($post_keyword); ?>" data-title="<?php echo esc_attr($post->post_title); ?>">
                                                <?php echo esc_html($post->post_title . $keyword_display); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    
                                    <!-- Current Featured Image -->
                                    <div id="aia_current_featured_container" style="display: flex; align-items: center; gap: 10px;">
                                        <span style="font-size: 12px; color: #666;">Current:</span>
                                        <a id="aia_current_featured_link" href="#" target="_blank" style="display: none; border-radius: 4px; border: 2px solid #ddd; overflow: hidden; width: 60px; height: 45px; flex-shrink: 0;">
                                            <img id="aia_current_featured_img" src="" alt="Current featured" style="width: 100%; height: 100%; object-fit: cover; display: block;">
                                        </a>
                                        <span id="aia_no_featured" style="font-size: 12px; color: #999; font-style: italic;">No image</span>
                                        <span id="aia_current_featured_status" style="font-size: 12px; color: #666;"></span>
                                    </div>
                                </div>
                                <p class="description">Select a post to automatically search for images using its keyword.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- Line 3: Searched keyword display -->
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label>Search Keyword</label>
                            </th>
                            <td>
                                <span id="aia_search_keyword_display" style="font-weight: 500; color: #2271b1; font-size: 14px;">—</span>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div id="aia_image_results" style="display: none; margin-top: 20px;">
                    <h3>Search Results</h3>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 10px;">
                        <span id="aia_result_count" style="color: #666;"></span>
                        <span id="aia_search_term_display" style="font-weight: 500; color: #2271b1;"></span>
                    </div>
                    <div id="aia_image_grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin-top: 10px;">
                        <!-- Images will be loaded here -->
                    </div>
                    <div id="aia_load_more_container" style="text-align: center; margin-top: 20px; display: none;">
                        <button type="button" id="aia_load_more" class="button button-secondary">Load More</button>
                    </div>
                </div>
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
            #aia_unsplash_test_result.success {
                color: #28a745;
                font-weight: 500;
            }
            #aia_unsplash_test_result.error {
                color: #dc3545;
                font-weight: 500;
            }
            #aia_unsplash_test_result.loading {
                color: #f0ad4e;
                font-weight: 500;
            }
            
            .aia-image-card {
                background: #fff;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                transition: transform 0.2s, box-shadow 0.2s;
                border: 2px solid transparent;
                cursor: pointer;
                position: relative;
            }
            .aia-image-card:hover {
                transform: translateY(-4px);
                box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            }
            .aia-image-card.selected {
                border-color: #2271b1;
                box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.3);
            }
            .aia-image-card img {
                width: 100%;
                height: 150px;
                object-fit: cover;
                display: block;
            }
            .aia-image-card .aia-image-info {
                padding: 10px;
                font-size: 12px;
                color: #666;
            }
            .aia-image-card .aia-image-info .author {
                font-weight: 500;
                color: #333;
            }
            .aia-image-card .aia-image-actions {
                padding: 8px 10px;
                border-top: 1px solid #f0f0f1;
                text-align: right;
            }
            .aia-image-card .aia-rank-badge {
                position: absolute;
                top: 8px;
                left: 8px;
                background: #2271b1;
                color: #fff;
                padding: 2px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 700;
            }
            .aia-image-card .aia-apply-btn {
                background: #2271b1;
                color: #fff;
                border: none;
                padding: 4px 12px;
                border-radius: 3px;
                cursor: pointer;
                font-size: 11px;
            }
            .aia-image-card .aia-apply-btn:hover {
                background: #135e96;
            }
            .aia-image-card .aia-apply-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            
            #aia_search_status.loading { color: #f0ad4e; }
            #aia_search_status.success { color: #28a745; }
            #aia_search_status.error { color: #dc3545; }
            
            #aia_current_featured_container img {
                transition: opacity 0.2s;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var testNonce = '<?php echo esc_js($test_nonce); ?>';
            var currentPage = 1;
            var currentQuery = '';
            var totalPages = 0;
            var currentPostId = 0;
            
            // ============== TEST CONNECTION ==============
            $('#aia_test_unsplash').on('click', function() {
                var api_key = $('#aia_unsplash_access_key').val();
                var resultSpan = $('#aia_unsplash_test_result');
                var button = $(this);
                
                resultSpan.removeClass().addClass('loading').text('Testing connection...');
                button.prop('disabled', true);
                
                if (!api_key) {
                    resultSpan.removeClass().addClass('error').text('Please enter your Unsplash Access Key first.');
                    button.prop('disabled', false);
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_test_unsplash',
                        api_key: api_key,
                        nonce: testNonce
                    },
                    success: function(response) {
                        button.prop('disabled', false);
                        if (response.success) {
                            resultSpan.removeClass().addClass('success').html('✅ ' + response.data.message);
                        } else {
                            resultSpan.removeClass().addClass('error').html('❌ ' + response.data.message);
                        }
                    },
                    error: function() {
                        button.prop('disabled', false);
                        resultSpan.removeClass().addClass('error').html('❌ Connection failed. Please try again.');
                    }
                });
            });
            
            // ============== GET CURRENT FEATURED IMAGE ==============
            function getCurrentFeatured(postId) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_get_featured_image',
                        post_id: postId,
                        nonce: testNonce
                    },
                    success: function(response) {
                        if (response.success && response.data.url) {
                            $('#aia_current_featured_img').attr('src', response.data.url);
                            $('#aia_current_featured_link').attr('href', response.data.url).show();
                            $('#aia_no_featured').hide();
                            $('#aia_current_featured_status').text('');
                        } else {
                            $('#aia_current_featured_link').hide();
                            $('#aia_no_featured').show();
                            $('#aia_current_featured_status').text('');
                        }
                    },
                    error: function() {
                        $('#aia_current_featured_link').hide();
                        $('#aia_no_featured').show();
                        $('#aia_current_featured_status').text('Error loading image');
                    }
                });
            }
            
            // ============== MANUAL SEARCH ==============
            $('#aia_search_images').on('click', function() {
                var keyword = $('#aia_search_keyword').val().trim();
                var statusSpan = $('#aia_search_status');
                
                if (!keyword) {
                    statusSpan.removeClass().addClass('error').text('Please enter a search keyword.');
                    return;
                }
                
                performSearch(keyword, statusSpan);
            });
            
            // Enter key support for search
            $('#aia_search_keyword').on('keypress', function(e) {
                if (e.which === 13) {
                    $('#aia_search_images').click();
                }
            });
            
            // ============== SELECT POST - Auto search ==============
            $('#aia_select_post').on('change', function() {
                var postId = $(this).val();
                var selectedOption = $(this).find('option:selected');
                var keyword = selectedOption.data('keyword');
                
                // Reset
                $('#aia_image_results').hide();
                $('#aia_image_grid').html('');
                $('#aia_search_keyword_display').text('—');
                $('#aia_search_status').removeClass().text('');
                $('#aia_current_featured_link').hide();
                $('#aia_no_featured').show();
                $('#aia_current_featured_status').text('');
                
                if (!postId) {
                    return;
                }
                
                // Show current featured image
                getCurrentFeatured(postId);
                
                // Check if post has a keyword
                if (!keyword) {
                    $('#aia_search_keyword_display').text('No keyword found');
                    $('#aia_search_status').removeClass().addClass('error').text('❌ No keyword');
                    return;
                }
                
                // Display the keyword
                $('#aia_search_keyword_display').text('"' + keyword + '"');
                $('#aia_search_status').removeClass().addClass('loading').text('Searching...');
                
                // Auto search with the post's keyword
                performSearch(keyword, $('#aia_search_status'), postId);
            });
            
            // ============== PERFORM SEARCH ==============
            function performSearch(keyword, statusSpan, postId) {
                currentQuery = keyword;
                currentPage = 1;
                currentPostId = postId || 0;
                
                statusSpan.removeClass().addClass('loading').text('Searching...');
                $('#aia_image_results').show();
                $('#aia_image_grid').html('');
                $('#aia_load_more_container').hide();
                $('#aia_result_count').text('');
                $('#aia_search_term_display').text('Searching for: "' + keyword + '"');
                
                searchImages(keyword, 1, statusSpan, currentPostId);
            }
            
            // ============== LOAD MORE ==============
            $('#aia_load_more').on('click', function() {
                currentPage++;
                var statusSpan = $('#aia_search_status');
                statusSpan.removeClass().addClass('loading').text('Loading more...');
                searchImages(currentQuery, currentPage, statusSpan, currentPostId);
            });
            
            // ============== SEARCH IMAGES FUNCTION ==============
            function searchImages(keyword, page, statusSpan, postId) {
                var api_key = $('#aia_unsplash_access_key').val();
                
                if (!api_key) {
                    statusSpan.removeClass().addClass('error').text('Please configure your Unsplash API key first.');
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_search_images',
                        keyword: keyword,
                        page: page,
                        per_page: 12,
                        api_key: api_key,
                        post_id: postId,
                        nonce: testNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            statusSpan.removeClass().addClass('success').text('✅ Found ' + response.data.total + ' images');
                            $('#aia_result_count').text(response.data.total + ' images found');
                            $('#aia_search_term_display').text('Search term: "' + keyword + '"');
                            renderImages(response.data.images, postId);
                            
                            totalPages = response.data.total_pages;
                            if (page < totalPages) {
                                $('#aia_load_more_container').show();
                            } else {
                                $('#aia_load_more_container').hide();
                            }
                        } else {
                            statusSpan.removeClass().addClass('error').text('❌ ' + response.data.message);
                            $('#aia_image_results').hide();
                        }
                    },
                    error: function() {
                        statusSpan.removeClass().addClass('error').text('❌ Search failed. Please try again.');
                    }
                });
            }
            
            // ============== RENDER IMAGES ==============
            function renderImages(images, postId) {
                var grid = $('#aia_image_grid');
                
                if (images.length === 0) {
                    grid.html('<p style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #999;">No images found. Try a different keyword.</p>');
                    return;
                }
                
                $.each(images, function(index, image) {
                    var rank = index + 1;
                    var score = image.score || 0;
                    var scoreColor = score > 70 ? '#28a745' : (score > 40 ? '#f0ad4e' : '#dc3545');
                    
                    var card = $('<div class="aia-image-card" data-image-url="' + image.url + '" data-image-id="' + image.id + '">');
                    card.html(`
                        <div class="aia-image-overlay"></div>
                        ${score ? '<div class="aia-rank-badge">#' + rank + '</div>' : ''}
                        <img src="${image.thumb}" alt="${image.alt}" loading="lazy" />
                        <div class="aia-image-info">
                            <span class="author">📸 ${image.author}</span>
                            <span style="float: right;">${image.width}×${image.height}</span>
                        </div>
                        ${score ? '<div style="padding: 0 10px; font-size: 12px; color: #666;">Relevance: <strong style="color: ' + scoreColor + ';">' + score + '%</strong><span style="margin-left: 10px;">' + (image.match_count || 0) + ' keywords matched</span></div>' : ''}
                        <div class="aia-image-actions">
                            <button class="aia-apply-btn" data-image-url="${image.url}" data-image-id="${image.id}">Apply as Featured</button>
                            <a href="${image.url}" target="_blank" style="margin-left: 5px; font-size: 11px;">View</a>
                        </div>
                    `);
                    
                    card.on('click', function(e) {
                        if (!$(e.target).closest('.aia-image-actions').length) {
                            $('.aia-image-card').removeClass('selected');
                            $(this).addClass('selected');
                        }
                    });
                    
                    card.find('.aia-apply-btn').on('click', function(e) {
                        e.stopPropagation();
                        var imageUrl = $(this).data('image-url');
                        var imageId = $(this).data('image-id');
                        var button = $(this);
                        
                        var targetPostId = postId || $('#aia_select_post').val();
                        
                        if (!targetPostId) {
                            alert('No post selected. Please select a post first.');
                            return;
                        }
                        
                        button.text('Applying...').prop('disabled', true);
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'aia_set_featured_image',
                                post_id: targetPostId,
                                image_url: imageUrl,
                                image_id: imageId,
                                nonce: testNonce
                            },
                            success: function(response) {
                                button.text('Apply as Featured').prop('disabled', false);
                                if (response.success) {
                                    alert('✅ Featured image set successfully with keyword as alt text!');
                                    getCurrentFeatured(targetPostId);
                                } else {
                                    alert('❌ ' + response.data.message);
                                }
                            },
                            error: function(xhr) {
                                button.text('Apply as Featured').prop('disabled', false);
                                var errorMsg = 'Failed to set featured image. Please try again.';
                                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                                    errorMsg = xhr.responseJSON.data.message;
                                }
                                alert('❌ ' + errorMsg);
                            }
                        });
                    });
                    
                    grid.append(card);
                });
            }
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Test Unsplash Connection
     */
    public function test_unsplash_connection() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_test_unsplash')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'API key is required'));
            return;
        }
        
        $url = 'https://api.unsplash.com/search/photos';
        $params = [
            'query' => 'test',
            'per_page' => 1
        ];
        
        $query_string = http_build_query($params);
        $full_url = $url . '?' . $query_string;
        
        $response = wp_remote_get($full_url, [
            'headers' => [
                'Authorization' => 'Client-ID ' . $api_key
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Connection failed: ' . $response->get_error_message()));
            return;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code === 401) {
            wp_send_json_error(array('message' => 'Invalid API key. Please check your Access Key.'));
            return;
        }
        
        if ($status_code === 403) {
            wp_send_json_error(array('message' => 'API key does not have permission. Please check your application settings.'));
            return;
        }
        
        if ($status_code === 429) {
            wp_send_json_error(array('message' => 'Rate limit exceeded. Please wait and try again later.'));
            return;
        }
        
        if ($status_code === 200 && isset($data['results'])) {
            wp_send_json_success(array(
                'message' => 'Connection successful! ' . count($data['results']) . ' test images found.'
            ));
            return;
        }
        
        if (isset($data['errors'])) {
            wp_send_json_error(array('message' => 'API Error: ' . implode(', ', $data['errors'])));
            return;
        }
        
        wp_send_json_error(array('message' => 'Unexpected response from Unsplash API. Status: ' . $status_code));
    }
    
    /**
     * AJAX: Unified Image Search
     */
    public function search_images() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_test_unsplash')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $keyword = isset($_POST['keyword']) ? sanitize_text_field($_POST['keyword']) : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 12;
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        if (empty($keyword)) {
            wp_send_json_error(array('message' => 'Keyword is required'));
            return;
        }
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'API key is required'));
            return;
        }
        
        // Search with the provided keyword
        $images = $this->image_manager->search_unsplash_with_keyword($keyword, 30);
        
        if (empty($images)) {
            wp_send_json_success(array(
                'images' => [],
                'total' => 0,
                'total_pages' => 0,
                'page' => $page
            ));
            return;
        }
        
        // Score images
        $scored_images = $this->image_manager->score_images($images, $keyword);
        
        // Sort by score (highest first)
        usort($scored_images, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Paginate results
        $start = ($page - 1) * $per_page;
        $paginated_images = array_slice($scored_images, $start, $per_page);
        $total = count($scored_images);
        $total_pages = ceil($total / $per_page);
        
        $images_response = array();
        foreach ($paginated_images as $item) {
            $image = $item['image'];
            $images_response[] = array(
                'id' => $image['id'],
                'url' => $image['urls']['raw'] ?? $image['urls']['full'] ?? $image['urls']['regular'],
                'thumb' => $image['urls']['thumb'] ?? $image['urls']['small'],
                'author' => $image['user']['name'] ?? 'Unknown',
                'alt' => $image['alt_description'] ?? $image['description'] ?? 'Image',
                'width' => $image['width'] ?? 0,
                'height' => $image['height'] ?? 0,
                'score' => $item['score'],
                'match_count' => $item['match_count'] ?? 0
            );
        }
        
        wp_send_json_success(array(
            'images' => $images_response,
            'total' => $total,
            'total_pages' => $total_pages,
            'page' => $page,
            'search_term' => $keyword
        ));
    }
    
    /**
     * AJAX: Set Featured Image
     */
    public function set_featured_image_ajax() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_test_unsplash')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $image_url = isset($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : '';
        $image_id = isset($_POST['image_id']) ? sanitize_text_field($_POST['image_id']) : '';
        
        if (!$post_id) {
            wp_send_json_error(array('message' => 'Invalid post ID'));
            return;
        }
        
        if (empty($image_url)) {
            wp_send_json_error(array('message' => 'Image URL is required'));
            return;
        }
        
        $result = $this->download_and_set_featured_image($post_id, $image_url, $image_id);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Featured image set successfully with keyword as alt text'));
        } else {
            wp_send_json_error(array('message' => 'Failed to set featured image'));
        }
    }
    
    /**
     * Download and set featured image with proper alt text
     */
    private function download_and_set_featured_image($post_id, $image_url, $image_id = '') {
        $logger = new AIA_Logger();
        
        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            $logger->log("Failed to download featured image: " . $tmp->get_error_message(), 'error');
            return false;
        }
        
        $filename = basename(parse_url($image_url, PHP_URL_PATH));
        if (empty($filename) || strpos($filename, '?') !== false || !preg_match('/\.[a-zA-Z0-9]+$/', $filename)) {
            $filename = 'featured-image-' . $post_id . '.jpg';
        }
        
        $file_array = array(
            'name' => $filename,
            'tmp_name' => $tmp
        );
        
        $attachment_id = media_handle_sideload($file_array, $post_id);
        
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            $logger->log("Failed to set featured image: " . $attachment_id->get_error_message(), 'error');
            return false;
        }
        
        // Set as featured image
        set_post_thumbnail($post_id, $attachment_id);
        
        // ============================================================
        // Get the post keyword and set as image alt text and title
        // ============================================================
        $post_keyword = get_post_meta($post_id, '_aia_keyword', true);
        $post_title = get_the_title($post_id);
        
        // Use keyword if available, otherwise use post title
        $alt_text = !empty($post_keyword) ? $post_keyword : $post_title;
        
        // Update attachment meta with alt text
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        
        // Update attachment post title and excerpt
        $attachment_data = array(
            'ID' => $attachment_id,
            'post_title' => sanitize_text_field($alt_text),
            'post_excerpt' => sanitize_text_field($alt_text),
        );
        wp_update_post($attachment_data);
        
        // Also store the Unsplash image ID if provided
        if (!empty($image_id)) {
            update_post_meta($attachment_id, '_aia_unsplash_image_id', $image_id);
        }
        
        $logger->log("Featured image set successfully for post ID: {$post_id} with alt text: '{$alt_text}'", 'success');
        
        return true;
    }
    
    /**
     * AJAX: Get Current Featured Image
     */
    public function get_featured_image() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_test_unsplash')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        if (!$post_id) {
            wp_send_json_error(array('message' => 'Invalid post ID'));
            return;
        }
        
        $thumbnail_id = get_post_thumbnail_id($post_id);
        
        if ($thumbnail_id) {
            $image_url = wp_get_attachment_image_url($thumbnail_id, 'large');
            if ($image_url) {
                wp_send_json_success(array('url' => $image_url));
                return;
            }
        }
        
        wp_send_json_error(array('message' => 'No featured image found'));
    }
}