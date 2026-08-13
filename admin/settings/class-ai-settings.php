<?php
// admin/settings/class-ai-settings.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_AI_Settings {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_submenu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_aia_test_api', array($this, 'test_api_connection'));
        add_action('wp_ajax_aia_test_tavily', array($this, 'test_tavily_connection'));
        add_action('wp_ajax_aia_save_txt', array($this, 'save_txt_file'));
        add_action('wp_ajax_aia_load_txt', array($this, 'load_txt_file'));
    }
    
    public function add_submenu_page() {
        add_submenu_page(
            'blog-autom',
            'AI Settings',
            'AI Settings',
            'manage_options',
            'blog-autom-ai-settings',
            array($this, 'render_page')
        );
    }
    
    public function register_settings() {
        // AI Provider
        register_setting('aia_ai_settings', 'aia_ai_provider');
        
        // Gemini Settings
        register_setting('aia_ai_settings', 'aia_api_key');
        register_setting('aia_ai_settings', 'aia_gemini_model');
        
        // GLM Settings
        register_setting('aia_ai_settings', 'aia_glm_api_key');
        register_setting('aia_ai_settings', 'aia_glm_model');
        
        // Tavily Settings
        register_setting('aia_ai_settings', 'aia_tavily_api_key');
        register_setting('aia_ai_settings', 'aia_tavily_search_depth');
        register_setting('aia_ai_settings', 'aia_tavily_max_results');
        
        // General Settings
        register_setting('aia_ai_settings', 'aia_max_posts_per_day');
        register_setting('aia_ai_settings', 'aia_enable_logging');
        register_setting('aia_ai_settings', 'aia_enable_grounding');
    }
    
    public function render_page() {
        $ai_provider = get_option('aia_ai_provider', 'gemini');
        
        // Gemini
        $api_key = get_option('aia_api_key', '');
        $gemini_model = get_option('aia_gemini_model', 'gemini-2.0-flash');
        
        // GLM
        $glm_api_key = get_option('aia_glm_api_key', '');
        $glm_model = get_option('aia_glm_model', 'glm-4-flash');
        
        // Tavily
        $tavily_api_key = get_option('aia_tavily_api_key', '');
        $tavily_search_depth = get_option('aia_tavily_search_depth', 'basic');
        $tavily_max_results = get_option('aia_tavily_max_results', 5);
        
        $max_posts = get_option('aia_max_posts_per_day', 10);
        $logging = get_option('aia_enable_logging', 1);
        $grounding = get_option('aia_enable_grounding', 0);
        
        $test_nonce = wp_create_nonce('aia_test_api');
        $txt_nonce = wp_create_nonce('aia_txt_editor');
        
        $blog_instructions_content = '';
        $blog_instructions_file = AIA_DATA_DIR . 'blog_instructions.txt';
        if (file_exists($blog_instructions_file)) {
            $blog_instructions_content = file_get_contents($blog_instructions_file);
        }
        
        ?>
        <div class="wrap">
            <h1>🤖 AI Settings</h1>
            <p class="description">Configure AI provider, API keys, content generation, and blog instructions.</p>
            
            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success"><p>Settings saved successfully!</p></div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('aia_ai_settings'); ?>
                
                <div class="aia-settings-section">
                    <h2>AI Provider Configuration</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aia_ai_provider">AI Provider</label>
                            </th>
                            <td>
                                <select name="aia_ai_provider" id="aia_ai_provider" class="regular-text">
                                    <option value="gemini" <?php selected($ai_provider, 'gemini'); ?>>Google Gemini</option>
                                    <option value="glm" <?php selected($ai_provider, 'glm'); ?>>GLM (Zhipu AI) - Free Tier</option>
                                </select>
                                <p class="description">Select which AI service to use for content generation</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
<!-- ============ GEMINI SETTINGS ============ -->
                <div class="aia-settings-section provider-section gemini-section" <?php echo $ai_provider !== 'gemini' ? 'style="display:none;"' : ''; ?>>
                    <h2>🔵 Google Gemini Settings</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aia_api_key">Gemini API Key</label>
                            </th>
                            <td>
                                <input type="password" 
                                       name="aia_api_key" 
                                       id="aia_api_key" 
                                       value="<?php echo esc_attr($api_key); ?>"
                                       class="regular-text"
                                       autocomplete="off"
                                       placeholder="Enter your Gemini API key">
                                <p class="description">
                                    <a href="https://aistudio.google.com/apikey" target="_blank">Get your free Gemini API key</a>
                                </p>
                                <?php if (!empty($api_key)): ?>
                                    <p style="color: #28a745; margin-top: 5px;">✅ API key configured</p>
                                <?php else: ?>
                                    <p style="color: #dc3545; margin-top: 5px;">❌ API key not configured</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aia_gemini_model">Gemini Model</label>
                            </th>
                            <td>
                                <select name="aia_gemini_model" id="aia_gemini_model">
                                    <option value="gemini-3.6-flash" <?php selected($gemini_model, 'gemini-3.6-flash'); ?>>Gemini 3.6 Flash</option>
                                    <option value="gemini-3.5-flash" <?php selected($gemini_model, 'gemini-3.5-flash'); ?>>Gemini 3.5 Flash</option>
                                    <option value="gemini-3.5-flash-lite" <?php selected($gemini_model, 'gemini-3.5-flash-lite'); ?>>Gemini 3.5 Flash-Lite</option>
                                    <option value="gemini-3.1-pro" <?php selected($gemini_model, 'gemini-3.1-pro'); ?>>Gemini 3.1 Pro</option>
                                    <option value="gemini-2.5-flash" <?php selected($gemini_model, 'gemini-2.5-flash'); ?>>Gemini 2.5 Flash</option>
                                    <option value="gemini-2.5-pro" <?php selected($gemini_model, 'gemini-2.5-pro'); ?>>Gemini 2.5 Pro</option>
                                    <option value="gemini-2.5-flash-lite" <?php selected($gemini_model, 'gemini-2.5-flash-lite'); ?>>Gemini 2.5 Flash-Lite</option>
                                </select>
                                <p class="description">Select Gemini model variant</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label>Test Connection</label>
                            </th>
                            <td>
                                <button type="button" id="aia_test_gemini" class="button button-secondary">Test Gemini Connection</button>
                                <span id="aia_gemini_test_result" style="margin-left: 10px;"></span>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- ============ GLM SETTINGS ============ -->
                <div class="aia-settings-section provider-section glm-section" <?php echo $ai_provider !== 'glm' ? 'style="display:none;"' : ''; ?>>
                    <h2>🟣 GLM (Zhipu AI) Settings</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aia_glm_api_key">GLM API Key</label>
                            </th>
                            <td>
                                <input type="password" 
                                    name="aia_glm_api_key" 
                                    id="aia_glm_api_key" 
                                    value="<?php echo esc_attr($glm_api_key); ?>"
                                    class="regular-text"
                                    autocomplete="off"
                                    placeholder="Enter your GLM API key">
                                <p class="description">
                                    <a href="https://open.bigmodel.cn/" target="_blank">Get your free GLM API key</a>
                                </p>
                                <?php if (!empty($glm_api_key)): ?>
                                    <p style="color: #28a745; margin-top: 5px;">✅ API key configured</p>
                                <?php else: ?>
                                    <p style="color: #dc3545; margin-top: 5px;">❌ API key not configured</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aia_glm_model">GLM Model</label>
                            </th>
                            <td>
                                <select name="aia_glm_model" id="aia_glm_model">
                                    <!-- Latest Models -->
                                    <option value="glm-4.7-flash" <?php selected($glm_model, 'glm-4.7-flash'); ?>>GLM-4.7-Flash (Latest)</option>
                                    <option value="glm-4.6-flash" <?php selected($glm_model, 'glm-4.6-flash'); ?>>GLM-4.6-Flash</option>
                                    
                                    <!-- Free Tier Models -->
                                    <option value="glm-4-flash" <?php selected($glm_model, 'glm-4-flash'); ?>>GLM-4-Flash (Free Tier)</option>
                                    <option value="glm-4-air" <?php selected($glm_model, 'glm-4-air'); ?>>GLM-4-Air (Balanced)</option>
                                    
                                    <!-- Premium Models -->
                                    <option value="glm-4-plus" <?php selected($glm_model, 'glm-4-plus'); ?>>GLM-4-Plus (Best)</option>
                                    <option value="glm-4-0520" <?php selected($glm_model, 'glm-4-0520'); ?>>GLM-4 (Stable)</option>
                                    <option value="glm-3-turbo" <?php selected($glm_model, 'glm-3-turbo'); ?>>GLM-3-Turbo (Legacy)</option>
                                </select>
                                <p class="description">
                                    Select GLM model variant. <strong>GLM-4-Flash is free tier</strong> with 50 requests/day.<br>
                                    <strong>GLM-4.7-Flash</strong> and <strong>GLM-4.6-Flash</strong> are the latest fast models.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label>Test Connection</label>
                            </th>
                            <td>
                                <button type="button" id="aia_test_glm" class="button button-secondary">Test GLM Connection</button>
                                <span id="aia_glm_test_result" style="margin-left: 10px;"></span>
                            </td>
                        </tr>
                    </table>
                </div>
                
            
                <div class="aia-settings-section">
                    <h2>🔍 Tavily Search API Settings</h2>
                    <p class="description">
                        Tavily provides web search capabilities for AI-powered research and content generation.
                        <strong>Search depth is automatically determined</strong> by the AI planner based on each query's complexity.
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aia_tavily_api_key">Tavily API Key</label>
                            </th>
                            <td>
                                <input type="password" 
                                    name="aia_tavily_api_key" 
                                    id="aia_tavily_api_key" 
                                    value="<?php echo esc_attr($tavily_api_key); ?>"
                                    class="regular-text"
                                    autocomplete="off"
                                    placeholder="Enter your Tavily API key">
                                <p class="description">
                                    <a href="https://app.tavily.com/sign-in" target="_blank">Get your Tavily API key</a>
                                </p>
                                <?php if (!empty($tavily_api_key)): ?>
                                    <p style="color: #28a745; margin-top: 5px;">✅ API key configured</p>
                                <?php else: ?>
                                    <p style="color: #dc3545; margin-top: 5px;">❌ API key not configured</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aia_tavily_max_results">Max Results Per Query</label>
                            </th>
                            <td>
                                <input type="number" 
                                    name="aia_tavily_max_results" 
                                    id="aia_tavily_max_results" 
                                    value="<?php echo esc_attr($tavily_max_results); ?>"
                                    min="1"
                                    max="20"
                                    class="small-text">
                                <p class="description">Maximum number of search results to return per query (1-20).</p>
                                <p class="description" style="color: #666; font-size: 12px; margin-top: 3px;">
                                    💡 The AI planner automatically decides which queries use <strong>basic</strong> (faster) vs <strong>advanced</strong> (more thorough) search depth.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label>Test Connection</label>
                            </th>
                            <td>
                                <button type="button" id="aia_test_tavily" class="button button-secondary">Test Tavily Connection</button>
                                <span id="aia_tavily_test_result" style="margin-left: 10px;"></span>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- ============ GENERAL POST SETTINGS ============ -->
                <div class="aia-settings-section">
                    <h2>📝 Post Settings</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aia_max_posts_per_day">Max Posts Per Day</label>
                            </th>
                            <td>
                                <input type="number" 
                                       name="aia_max_posts_per_day" 
                                       id="aia_max_posts_per_day" 
                                       value="<?php echo esc_attr($max_posts); ?>"
                                       min="1"
                                       max="100"
                                       class="small-text">
                                <p class="description">Maximum number of posts to generate per day</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="aia_enable_logging">Enable Logging</label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       name="aia_enable_logging" 
                                       id="aia_enable_logging" 
                                       value="1"
                                       <?php checked($logging, 1); ?>>
                                <p class="description">Enable detailed logging for debugging</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- ============ BLOG INSTRUCTIONS ============ -->
                <div class="aia-settings-section">
                    <h2>📄 Blog Instructions</h2>
                    <p class="description">Edit the blog instructions text file that guides the AI content generation.</p>
                    
                    <div class="aia-txt-editor">
                        <div id="txt_editor_container">
                            <textarea id="txt_editor" rows="15" class="large-text code" style="font-family: monospace; width: 100%;"><?php echo esc_textarea($blog_instructions_content); ?></textarea>
                            <div id="txt_validation_message" style="margin-top: 10px;"></div>
                            <button type="button" id="aia_save_txt" class="button button-secondary" style="margin-top: 10px;">
                                Save Instructions
                            </button>
                            <span id="txt_save_status" style="margin-left: 10px;"></span>
                        </div>
                    </div>
                </div>
                
                <?php submit_button('Save AI Settings', 'primary', 'submit'); ?>
            </form>
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
            .aia-txt-editor textarea {
                font-family: 'Courier New', monospace;
                background: #1e1e1e;
                color: #d4d4d4;
                padding: 15px;
                border-radius: 4px;
                min-height: 300px;
            }
            #aia_api_test_result, #aia_gemini_test_result, #aia_glm_test_result, #aia_tavily_test_result {
                padding: 8px 12px;
                border-radius: 4px;
                display: none;
            }
            #aia_api_test_result.success, #aia_gemini_test_result.success, #aia_glm_test_result.success, #aia_tavily_test_result.success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
                display: block;
            }
            #aia_api_test_result.error, #aia_gemini_test_result.error, #aia_glm_test_result.error, #aia_tavily_test_result.error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
                display: block;
            }
            #aia_api_test_result.loading, #aia_gemini_test_result.loading, #aia_glm_test_result.loading, #aia_tavily_test_result.loading {
                background: #e2e3e5;
                color: #383d41;
                border: 1px solid #d6d8db;
                display: block;
            }
            #txt_save_status.success {
                color: #28a745;
            }
            #txt_save_status.error {
                color: #dc3545;
            }
            .model-row td {
                padding: 15px 10px;
            }
            .provider-section {
                border-left: 4px solid #2271b1;
            }
            .glm-section {
                border-left-color: #8b5cf6;
            }
            .gemini-section {
                border-left-color: #4285f4;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var testNonce = '<?php echo esc_js($test_nonce); ?>';
            var txtNonce = '<?php echo esc_js($txt_nonce); ?>';
            
            // Toggle provider sections
            $('#aia_ai_provider').on('change', function() {
                var provider = $(this).val();
                $('.provider-section').hide();
                if (provider === 'gemini') {
                    $('.gemini-section').show();
                } else if (provider === 'glm') {
                    $('.glm-section').show();
                }
            });
            $('#aia_ai_provider').trigger('change');
            
            // Test Gemini Connection
            $('#aia_test_gemini').on('click', function() {
                var api_key = $('#aia_api_key').val();
                var model = $('#aia_gemini_model').val();
                var grounding = $('#aia_enable_grounding').is(':checked') ? 1 : 0;
                var resultSpan = $('#aia_gemini_test_result');
                var button = $(this);
                
                resultSpan.hide().removeClass().html('');
                if (!api_key) {
                    resultSpan.addClass('error').html('Please enter your Gemini API key.').show();
                    return;
                }
                
                button.prop('disabled', true).text('Testing...');
                resultSpan.addClass('loading').html('Testing connection...').show();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_test_api',
                        provider: 'gemini',
                        api_key: api_key,
                        model: model,
                        grounding: grounding,
                        nonce: testNonce
                    },
                    dataType: 'json',
                    timeout: 60000,
                    success: function(response) {
                        button.prop('disabled', false).text('Test Gemini Connection');
                        if (response.success) {
                            resultSpan.removeClass().addClass('success').html(response.data.message).show();
                        } else {
                            resultSpan.removeClass().addClass('error').html(response.data.message).show();
                        }
                    },
                    error: function() {
                        button.prop('disabled', false).text('Test Gemini Connection');
                        resultSpan.removeClass().addClass('error').html('Connection failed. Please try again.').show();
                    }
                });
            });
            
            // Test GLM Connection
            $('#aia_test_glm').on('click', function() {
                var api_key = $('#aia_glm_api_key').val();
                var model = $('#aia_glm_model').val();
                var resultSpan = $('#aia_glm_test_result');
                var button = $(this);
                
                resultSpan.hide().removeClass().html('');
                if (!api_key) {
                    resultSpan.addClass('error').html('Please enter your GLM API key.').show();
                    return;
                }
                
                button.prop('disabled', true).text('Testing...');
                resultSpan.addClass('loading').html('Testing connection...').show();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_test_api',
                        provider: 'glm',
                        api_key: api_key,
                        model: model,
                        nonce: testNonce
                    },
                    dataType: 'json',
                    timeout: 60000,
                    success: function(response) {
                        button.prop('disabled', false).text('Test GLM Connection');
                        if (response.success) {
                            resultSpan.removeClass().addClass('success').html(response.data.message).show();
                        } else {
                            resultSpan.removeClass().addClass('error').html(response.data.message).show();
                        }
                    },
                    error: function() {
                        button.prop('disabled', false).text('Test GLM Connection');
                        resultSpan.removeClass().addClass('error').html('Connection failed. Please try again.').show();
                    }
                });
            });
            
            // Test Tavily Connection
            $('#aia_test_tavily').on('click', function() {
                var api_key = $('#aia_tavily_api_key').val();
                var search_depth = $('#aia_tavily_search_depth').val();
                var max_results = $('#aia_tavily_max_results').val();
                var resultSpan = $('#aia_tavily_test_result');
                var button = $(this);
                
                resultSpan.hide().removeClass().html('');
                if (!api_key) {
                    resultSpan.addClass('error').html('Please enter your Tavily API key.').show();
                    return;
                }
                
                button.prop('disabled', true).text('Testing...');
                resultSpan.addClass('loading').html('Testing Tavily connection...').show();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_test_tavily',
                        api_key: api_key,
                        search_depth: search_depth,
                        max_results: max_results,
                        nonce: testNonce
                    },
                    dataType: 'json',
                    timeout: 60000,
                    success: function(response) {
                        button.prop('disabled', false).text('Test Tavily Connection');
                        if (response.success) {
                            resultSpan.removeClass().addClass('success').html(response.data.message).show();
                        } else {
                            resultSpan.removeClass().addClass('error').html(response.data.message).show();
                        }
                    },
                    error: function() {
                        button.prop('disabled', false).text('Test Tavily Connection');
                        resultSpan.removeClass().addClass('error').html('Connection failed. Please try again.').show();
                    }
                });
            });
            
            // Save TXT
            $('#aia_save_txt').on('click', function() {
                var content = $('#txt_editor').val();
                if (!content.trim()) {
                    $('#txt_validation_message').removeClass().addClass('error').html('Instructions cannot be empty.');
                    return;
                }
                $('#txt_save_status').text('Saving...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_save_txt',
                        file: 'blog_instructions.txt',
                        content: content,
                        nonce: txtNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#txt_save_status').removeClass().addClass('success').text('✅ ' + response.data.message);
                            $('#txt_validation_message').removeClass().html('');
                        } else {
                            var errorMsg = response.data && response.data.message ? response.data.message : 'Failed to save';
                            $('#txt_save_status').removeClass().addClass('error').text('❌ Error: ' + errorMsg);
                            $('#txt_validation_message').removeClass().addClass('error').html('❌ Failed to save: ' + errorMsg);
                        }
                    },
                    error: function() {
                        $('#txt_save_status').removeClass().addClass('error').text('❌ Failed to save file.');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function test_api_connection() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_test_api')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : '';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : '';
        $grounding = isset($_POST['grounding']) ? intval($_POST['grounding']) : 0;
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'API key is required'));
            return;
        }
        if (empty($model)) {
            wp_send_json_error(array('message' => 'Model is required'));
            return;
        }
        
        $result = $this->test_api($provider, $api_key, $model, $grounding);
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    private function test_api($provider, $api_key, $model, $grounding) {
        if ($provider === 'gemini') {
            return $this->test_gemini($api_key, $model, $grounding);
        } else if ($provider === 'glm') {
            return $this->test_glm($api_key, $model);
        } else {
            return ['success' => false, 'message' => 'Invalid provider selected'];
        }
    }
    
    private function test_gemini($api_key, $model, $grounding) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
        $body = [
            'contents' => [[
                'parts' => [['text' => 'Hello, please respond with a short greeting and confirm the connection is successful.']]
            ]]
        ];
        if ($grounding && (strpos($model, '2.0') !== false || strpos($model, '2.5') !== false)) {
            $body['tools'] = [['googleSearch' => new stdClass()]];
        }
        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($body),
            'timeout' => 30
        ]);
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => 'Connection failed: ' . $response->get_error_message()];
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (isset($data['error'])) {
            $error_msg = $data['error']['message'] ?? 'Unknown error';
            return ['success' => false, 'message' => 'API Error: ' . $error_msg];
        }
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            $response_text = $data['candidates'][0]['content']['parts'][0]['text'];
            $msg = '✅ Connected successfully to Gemini API! (Model: ' . $model . ')<br>';
            $msg .= '📝 Response: "' . esc_html($response_text) . '"';
            return ['success' => true, 'message' => $msg];
        }
        return ['success' => false, 'message' => 'Unexpected response from Gemini API'];
    }
    
    private function test_glm($api_key, $model) {
        $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
        
        $messages = [
            ['role' => 'user', 'content' => 'Hello, please respond with a short greeting and confirm the connection is successful.']
        ];
        
        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 50
        ];
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body' => json_encode($body),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => 'Connection failed: ' . $response->get_error_message()];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            $error_msg = $data['error']['message'] ?? 'Unknown error';
            return ['success' => false, 'message' => 'API Error: ' . $error_msg];
        }
        
        if (isset($data['choices'][0]['message']['content'])) {
            $response_text = $data['choices'][0]['message']['content'];
            $msg = '✅ Connected successfully to GLM API! (Model: ' . $model . ')<br>';
            $msg .= '📝 Response: "' . esc_html($response_text) . '"';
            return ['success' => true, 'message' => $msg];
        }
        
        return ['success' => false, 'message' => 'Unexpected response from GLM API'];
    }
    
    /**
     * Test Tavily API connection
     */
    public function test_tavily_connection() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_test_api')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $search_depth = isset($_POST['search_depth']) ? sanitize_text_field($_POST['search_depth']) : 'basic';
        $max_results = isset($_POST['max_results']) ? intval($_POST['max_results']) : 5;
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'Tavily API key is required'));
            return;
        }
        
        $result = $this->test_tavily($api_key, $search_depth, $max_results);
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * Test Tavily API with a simple search query
     */
    private function test_tavily($api_key, $search_depth, $max_results) {
        $url = 'https://api.tavily.com/search';
        
        $body = [
            'query' => 'What is the weather like today?',
            'search_depth' => $search_depth,
            'max_results' => $max_results,
            'include_answer' => true
        ];
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body' => json_encode($body),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => 'Connection failed: ' . $response->get_error_message()];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Check for rate limit or other errors
        if (isset($data['error'])) {
            $error_msg = $data['error'] . ' ' . ($data['message'] ?? '');
            return ['success' => false, 'message' => 'API Error: ' . $error_msg];
        }
        
        if (isset($data['results']) && is_array($data['results'])) {
            $result_count = count($data['results']);
            $answer = isset($data['answer']) && !empty($data['answer']) 
                ? '📝 Answer: "' . esc_html(substr($data['answer'], 0, 100)) . (strlen($data['answer']) > 100 ? '...' : '') . '"' 
                : '📝 No answer generated';
            
            $msg = '✅ Connected successfully to Tavily API!<br>';
            $msg .= '🔍 Found ' . $result_count . ' results for the search query.<br>';
            $msg .= $answer;
            return ['success' => true, 'message' => $msg];
        }
        
        return ['success' => false, 'message' => 'Unexpected response from Tavily API'];
    }
    
    public function save_txt_file() {
        if (!wp_verify_nonce($_POST['nonce'], 'aia_txt_editor')) {
            wp_send_json_error('Security check failed');
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $file = sanitize_file_name($_POST['file']);
        $content = stripslashes($_POST['content']);
        
        if ($file !== 'blog_instructions.txt') {
            wp_send_json_error('File not allowed');
            return;
        }
        
        $filepath = AIA_DATA_DIR . $file;
        if (file_put_contents($filepath, $content)) {
            wp_send_json_success(['message' => 'Instructions saved successfully!']);
        } else {
            wp_send_json_error('Failed to save file. Check file permissions.');
        }
    }
    
    public function load_txt_file() {
        if (!wp_verify_nonce($_POST['nonce'], 'aia_txt_editor')) {
            wp_send_json_error('Security check failed');
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $file = sanitize_file_name($_POST['file']);
        if ($file !== 'blog_instructions.txt') {
            wp_send_json_error('File not allowed');
            return;
        }
        
        $filepath = AIA_DATA_DIR . $file;
        if (!file_exists($filepath)) {
            wp_send_json_error('File does not exist');
            return;
        }
        
        $content = file_get_contents($filepath);
        if ($content === false) {
            wp_send_json_error('Failed to read file');
            return;
        }
        
        wp_send_json_success(['content' => $content]);
    }
}