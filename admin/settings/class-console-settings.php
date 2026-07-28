<?php
// admin/settings/class-console-settings.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Console_Settings {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_submenu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_aia_test_console', array($this, 'test_console_connection'));
    }
    
    public function add_submenu_page() {
        add_submenu_page(
            'ai-autoblog',
            'Console Settings',
            'Console Settings',
            'manage_options',
            'ai-autoblog-console-settings',
            array($this, 'render_page')
        );
    }
    
    public function register_settings() {
        register_setting('aia_console_settings', 'aia_console_enabled');
        register_setting('aia_console_settings', 'aia_console_bing_api_key');
        register_setting('aia_console_settings', 'aia_console_google_api_key');
        register_setting('aia_console_settings', 'aia_console_search_engine');
        register_setting('aia_console_settings', 'aia_console_auto_submit');
    }
    
    public function render_page() {
        $console_enabled = get_option('aia_console_enabled', 1);
        $bing_api_key = get_option('aia_console_bing_api_key', '');
        $google_api_key = get_option('aia_console_google_api_key', '');
        $search_engine = get_option('aia_console_search_engine', 'both');
        $auto_submit = get_option('aia_console_auto_submit', 1);
        $console_nonce = wp_create_nonce('aia_test_console');
        
        ?>
        <div class="wrap">
            <h1>🔍 Console API Integrations</h1>
            <p class="description">Configure search engine console APIs for automatic indexing (IndexNow).</p>
            
            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success"><p>Settings saved successfully!</p></div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('aia_console_settings'); ?>
                
                <div class="aia-settings-section">
                    <h2>IndexNow Configuration</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label>Enable Console API</label>
                            </th>
                            <td>
                                <label>
                                    <input type="radio" name="aia_console_enabled" value="1" <?php checked($console_enabled, 1); ?>>
                                    Enabled
                                </label>
                                <label style="margin-left: 15px;">
                                    <input type="radio" name="aia_console_enabled" value="0" <?php checked($console_enabled, 0); ?>>
                                    Disabled
                                </label>
                                <p class="description">When enabled, posts will be automatically submitted to search engine consoles.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="aia_console_bing_api_key">Bing IndexNow API Key</label>
                            </th>
                            <td>
                                <input type="password" 
                                       name="aia_console_bing_api_key" 
                                       id="aia_console_bing_api_key" 
                                       value="<?php echo esc_attr($bing_api_key); ?>"
                                       class="regular-text"
                                       autocomplete="off"
                                       placeholder="Enter your Bing IndexNow API key">
                                <button type="button" id="aia_test_bing" class="button button-secondary" style="margin-top: 5px;">
                                    Test Bing Connection
                                </button>
                                <div id="aia_bing_test_result" style="margin-top: 5px;"></div>
                                <p class="description">
                                    <a href="https://www.indexnow.org/" target="_blank">Get your Bing IndexNow API key</a>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="aia_console_google_api_key">Google IndexNow API Key</label>
                            </th>
                            <td>
                                <input type="password" 
                                       name="aia_console_google_api_key" 
                                       id="aia_console_google_api_key" 
                                       value="<?php echo esc_attr($google_api_key); ?>"
                                       class="regular-text"
                                       autocomplete="off"
                                       placeholder="Enter your Google IndexNow API key">
                                <button type="button" id="aia_test_google" class="button button-secondary" style="margin-top: 5px;">
                                    Test Google Connection
                                </button>
                                <div id="aia_google_test_result" style="margin-top: 5px;"></div>
                                <p class="description">
                                    <a href="https://www.indexnow.org/" target="_blank">Get your Google IndexNow API key</a>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="aia_console_search_engine">Search Engines</label>
                            </th>
                            <td>
                                <select name="aia_console_search_engine" id="aia_console_search_engine">
                                    <option value="both" <?php selected($search_engine, 'both'); ?>>Both (Bing + Google)</option>
                                    <option value="bing" <?php selected($search_engine, 'bing'); ?>>Bing Only</option>
                                    <option value="google" <?php selected($search_engine, 'google'); ?>>Google Only</option>
                                </select>
                                <p class="description">Select which search engines to notify when posts are published.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="aia_console_auto_submit">Auto Submit</label>
                            </th>
                            <td>
                                <label>
                                    <input type="radio" name="aia_console_auto_submit" value="1" <?php checked($auto_submit, 1); ?>>
                                    Auto Submit
                                </label>
                                <label style="margin-left: 15px;">
                                    <input type="radio" name="aia_console_auto_submit" value="0" <?php checked($auto_submit, 0); ?>>
                                    Manual Only
                                </label>
                                <p class="description">Automatically submit posts to search engines when published or updated.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label>Host</label>
                            </th>
                            <td>
                                <code><?php echo esc_html(parse_url(get_site_url(), PHP_URL_HOST)); ?></code>
                                <p class="description">Your website domain (automatically detected).</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button('Save Console Settings', 'primary', 'submit'); ?>
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
            #aia_bing_test_result, #aia_google_test_result {
                padding: 8px 12px;
                border-radius: 4px;
                display: none;
            }
            #aia_bing_test_result.success, #aia_google_test_result.success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
                display: block;
            }
            #aia_bing_test_result.error, #aia_google_test_result.error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
                display: block;
            }
            #aia_bing_test_result.loading, #aia_google_test_result.loading {
                background: #e2e3e5;
                color: #383d41;
                border: 1px solid #d6d8db;
                display: block;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var consoleNonce = '<?php echo esc_js($console_nonce); ?>';
            
            // Test Bing Connection
            $('#aia_test_bing').on('click', function() {
                var api_key = $('#aia_console_bing_api_key').val();
                var resultDiv = $('#aia_bing_test_result');
                
                resultDiv.hide().removeClass().html('');
                if (!api_key) {
                    resultDiv.addClass('error').html('Please enter your Bing API key first.').show();
                    return;
                }
                
                var testButton = $(this);
                testButton.prop('disabled', true).text('Testing Bing...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_test_console',
                        engine: 'bing',
                        api_key: api_key,
                        nonce: consoleNonce
                    },
                    success: function(response) {
                        testButton.prop('disabled', false).text('Test Bing Connection');
                        if (response.success) {
                            resultDiv.removeClass().addClass('success').html('✅ ' + response.data.message).show();
                        } else {
                            resultDiv.removeClass().addClass('error').html('❌ ' + response.data.message).show();
                        }
                    },
                    error: function() {
                        testButton.prop('disabled', false).text('Test Bing Connection');
                        resultDiv.removeClass().addClass('error').html('❌ Connection failed. Please try again.').show();
                    }
                });
            });
            
            // Test Google Connection
            $('#aia_test_google').on('click', function() {
                var api_key = $('#aia_console_google_api_key').val();
                var resultDiv = $('#aia_google_test_result');
                
                resultDiv.hide().removeClass().html('');
                if (!api_key) {
                    resultDiv.addClass('error').html('Please enter your Google API key first.').show();
                    return;
                }
                
                var testButton = $(this);
                testButton.prop('disabled', true).text('Testing Google...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_test_console',
                        engine: 'google',
                        api_key: api_key,
                        nonce: consoleNonce
                    },
                    success: function(response) {
                        testButton.prop('disabled', false).text('Test Google Connection');
                        if (response.success) {
                            resultDiv.removeClass().addClass('success').html('✅ ' + response.data.message).show();
                        } else {
                            resultDiv.removeClass().addClass('error').html('❌ ' + response.data.message).show();
                        }
                    },
                    error: function() {
                        testButton.prop('disabled', false).text('Test Google Connection');
                        resultDiv.removeClass().addClass('error').html('❌ Connection failed. Please try again.').show();
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function test_console_connection() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_test_console')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $engine = isset($_POST['engine']) ? sanitize_text_field($_POST['engine']) : '';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'API key is required'));
            return;
        }
        
        $result = $this->test_console_api($engine, $api_key);
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    private function test_console_api($engine, $api_key) {
        $test_url = get_site_url();
        $host = parse_url($test_url, PHP_URL_HOST);
        
        $data = [
            'host' => $host,
            'key' => $api_key,
            'keyLocation' => trailingslashit($test_url) . 'indexnow-key.txt',
            'urlList' => [$test_url]
        ];
        
        $endpoint = 'https://www.' . ($engine === 'bing' ? 'bing' : 'google') . '.com/indexnow';
        $response = wp_remote_post($endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($data),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => 'Connection failed: ' . $response->get_error_message()];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 200 || $status_code === 202) {
            return ['success' => true, 'message' => 'Connection successful! API key is valid for ' . ucfirst($engine) . '.'];
        } elseif ($status_code === 403) {
            return ['success' => false, 'message' => 'Invalid API key. Please check and try again.'];
        } else {
            return ['success' => false, 'message' => 'Connection test failed. Status code: ' . $status_code];
        }
    }
}