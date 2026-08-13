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
            'blog-autom',
            'Console Settings',
            'Console Settings',
            'manage_options',
            'blog-autom-console-settings',
            array($this, 'render_page')
        );
    }
    
    public function register_settings() {
        register_setting('aia_console_settings', 'aia_console_enabled');
        register_setting('aia_console_settings', 'aia_console_bing_api_key');
        register_setting('aia_console_settings', 'aia_console_auto_submit');
    }
    
    public function render_page() {
        $console_enabled = get_option('aia_console_enabled', 1);
        $bing_api_key = get_option('aia_console_bing_api_key', '');
        $auto_submit = get_option('aia_console_auto_submit', 1);
        $console_nonce = wp_create_nonce('aia_test_console');
        
        ?>
        <div class="wrap">
            <h1>🔍 IndexNow Console Integration</h1>
            <p class="description">Configure IndexNow API for automatic indexing across multiple search engines.</p>
            
            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success"><p>Settings saved successfully!</p></div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('aia_console_settings'); ?>
                
                <div class="aia-settings-section">
                    <h2>IndexNow Configuration</h2>     
                    <p class="description">
                        IndexNow is supported by Bing, Yandex, Naver, Seznam, and Yep.
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label>Enable IndexNow</label>
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
                                <p class="description">When enabled, posts will be automatically submitted to search engines via IndexNow.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="aia_console_bing_api_key">IndexNow API Key</label>
                            </th>
                            <td>
                                <input type="password" 
                                       name="aia_console_bing_api_key" 
                                       id="aia_console_bing_api_key" 
                                       value="<?php echo esc_attr($bing_api_key); ?>"
                                       class="regular-text"
                                       autocomplete="off"
                                       placeholder="Enter your IndexNow API key">
                                <button type="button" id="aia_test_bing" class="button button-secondary" style="margin-top: 5px;">
                                    Test Connection
                                </button>
                                <div id="aia_bing_test_result" style="margin-top: 5px;"></div>
                                <p class="description">
                                    <a href="https://www.indexnow.org/" target="_blank">Get your IndexNow API key</a>
                                </p>
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
            #aia_bing_test_result {
                padding: 8px 12px;
                border-radius: 4px;
                display: none;
            }
            #aia_bing_test_result.success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
                display: block;
            }
            #aia_bing_test_result.error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
                display: block;
            }
            #aia_bing_test_result.loading {
                background: #e2e3e5;
                color: #383d41;
                border: 1px solid #d6d8db;
                display: block;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var consoleNonce = '<?php echo esc_js($console_nonce); ?>';
            
            // Test IndexNow Connection
            $('#aia_test_bing').on('click', function() {
                var api_key = $('#aia_console_bing_api_key').val();
                var resultDiv = $('#aia_bing_test_result');
                
                resultDiv.hide().removeClass().html('');
                if (!api_key) {
                    resultDiv.addClass('error').html('Please enter your IndexNow API key first.').show();
                    return;
                }
                
                var testButton = $(this);
                testButton.prop('disabled', true).text('Testing Connection...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_test_console',
                        api_key: api_key,
                        nonce: consoleNonce
                    },
                    success: function(response) {
                        testButton.prop('disabled', false).text('Test Connection');
                        if (response.success) {
                            resultDiv.removeClass().addClass('success').html('✅ ' + response.data.message).show();
                        } else {
                            resultDiv.removeClass().addClass('error').html('❌ ' + response.data.message).show();
                        }
                    },
                    error: function() {
                        testButton.prop('disabled', false).text('Test Connection');
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
        
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'API key is required'));
            return;
        }
        
        $result = $this->test_indexnow_api($api_key);
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    private function test_indexnow_api($api_key) {
        $test_url = get_site_url();
        $host = parse_url($test_url, PHP_URL_HOST);
        
        $data = [
            'host' => $host,
            'key' => $api_key,
            'keyLocation' => trailingslashit($test_url) . 'indexnow-key.txt',
            'urlList' => [$test_url]
        ];
        
        // IndexNow endpoint (works for all supported search engines)
        $endpoint = 'https://www.bing.com/indexnow';
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
            return ['success' => true, 'message' => 'Connection successful! Your IndexNow API key is valid. It will work with Bing, Yandex, Naver, Seznam, and Swisscows.'];
        } elseif ($status_code === 403) {
            return ['success' => false, 'message' => 'Invalid API key. Please check and try again.'];
        } else {
            return ['success' => false, 'message' => 'Connection test failed. Status code: ' . $status_code];
        }
    }
}