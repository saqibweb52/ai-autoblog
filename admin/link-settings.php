<?php
// admin/link-settings.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Link_Settings {
    
    private $link_manager;
    
    public function __construct() {
        $this->link_manager = new AIA_Link_Manager();
        add_action('admin_menu', array($this, 'add_submenu_page'));
        add_action('wp_ajax_aia_sync_sitemap', array($this, 'ajax_sync_sitemap'));
        add_action('wp_ajax_aia_update_settings', array($this, 'ajax_update_settings'));
        add_action('aia_sync_sitemaps', array($this, 'cron_sync_sitemaps'));
        
        // Process form submissions BEFORE any HTML is output
        add_action('admin_init', array($this, 'process_forms'));
    }
    
    public function add_submenu_page() {
        add_submenu_page(
            'ai-autoblog',
            'Link Management',
            'Link Management',
            'manage_options',
            'ai-autoblog-links',
            array($this, 'render_page')
        );
    }
    
    /**
     * Process form submissions before any output
     */
    public function process_forms() {
        // Only process on our page
        if (!isset($_GET['page']) || $_GET['page'] !== 'ai-autoblog-links') {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle settings save
        if (isset($_POST['aia_save_settings'])) {
            $this->handle_save_settings();
        }
        
        // Handle direct link add
        if (isset($_POST['aia_add_direct_link'])) {
            $this->handle_add_direct_link();
        }
        
        // Handle direct link remove
        if (isset($_POST['aia_remove_direct_link'])) {
            $this->handle_remove_direct_link();
        }
        
        // Handle sitemap add
        if (isset($_POST['aia_add_sitemap'])) {
            $this->handle_add_sitemap();
        }
        
        // Handle sitemap remove
        if (isset($_POST['aia_remove_sitemap'])) {
            $this->handle_remove_sitemap();
        }
        
        // Handle sitemap sync
        if (isset($_POST['aia_sync_now'])) {
            $this->handle_sync_sitemaps();
        }
    }
    
    public function render_page() {
        $link_data = $this->link_manager->get_all_links_data();
        
        // Get settings
        $max_internal_links = get_option('aia_max_internal_links', 5);
        $max_external_links = get_option('aia_max_external_links', 3);
        
        ?>
        <div class="wrap">
            <h1>Link Management</h1>
            
            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($_GET['updated']); ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Link Settings -->
            <div class="aia-settings-section">
                <h2>Link Settings</h2>
                <p class="description">Configure link settings for your posts.</p>
                
                <form method="post">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="aia_max_internal_links">Max Internal Links Per Post</label>
                            </th>
                            <td>
                                <input type="number" 
                                       name="max_internal_links" 
                                       id="aia_max_internal_links" 
                                       value="<?php echo esc_attr($max_internal_links); ?>"
                                       min="0"
                                       max="20"
                                       class="small-text">
                                <p class="description">Maximum number of internal links to add per post. Set to <strong>0</strong> to disable internal linking.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="aia_max_external_links">Max External Links Per Post</label>
                            </th>
                            <td>
                                <input type="number" 
                                       name="max_external_links" 
                                       id="aia_max_external_links" 
                                       value="<?php echo esc_attr($max_external_links); ?>"
                                       min="0"
                                       max="20"
                                       class="small-text">
                                <p class="description">Maximum number of external links to add per post. Set to <strong>0</strong> to disable external linking.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label>Total Publishable Content</label>
                            </th>
                            <td>
                                <strong><?php echo $this->get_total_content_count(); ?></strong> posts and pages available for internal linking
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Save Settings', 'primary', 'aia_save_settings'); ?>
                </form>
            </div>
            
            <!-- External Linking - Direct Links -->
            <div class="aia-settings-section">
                <h2>External Links - Direct</h2>
                <p class="description">Add custom external links with topic keywords for matching.</p>
                
                <form method="post" style="margin-bottom: 20px;">
                    <table class="form-table">
                        <tr>
                            <th><label for="direct_url">URL</label></th>
                            <td>
                                <input type="url" name="direct_url" id="direct_url" class="regular-text" required placeholder="https://example.com/resource">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="direct_anchor">Anchor Text</label></th>
                            <td>
                                <input type="text" name="direct_anchor" id="direct_anchor" class="regular-text" required placeholder="Learn more about...">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="direct_keywords">Topic Keywords</label></th>
                            <td>
                                <input type="text" name="direct_keywords" id="direct_keywords" class="regular-text" placeholder="ai, machine learning, technology">
                                <p class="description">Comma-separated keywords for relevance matching</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Add Direct Link', 'secondary', 'aia_add_direct_link'); ?>
                </form>
                
                <h3>Existing Direct Links</h3>
                <?php if (!empty($link_data['direct_links'])): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>URL</th>
                                <th>Anchor</th>
                                <th>Keywords</th>
                                <th>Added</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($link_data['direct_links'] as $link): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url($link['url']); ?>" target="_blank">
                                            <?php echo esc_html($link['url']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html($link['anchor']); ?></td>
                                    <td>
                                        <?php if (!empty($link['topic_keywords'])): ?>
                                            <?php foreach ($link['topic_keywords'] as $keyword): ?>
                                                <span class="aia-keyword-tag"><?php echo esc_html($keyword); ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="aia-no-keywords">No keywords</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($link['added_at'] ?? 'N/A'); ?></td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="remove_url" value="<?php echo esc_attr($link['url']); ?>">
                                            <button type="submit" name="aia_remove_direct_link" class="button button-small delete" onclick="return confirm('Remove this link?');">
                                                Remove
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No direct links added yet.</p>
                <?php endif; ?>
            </div>
            
            <!-- External Linking - Sitemaps -->
            <div class="aia-settings-section">
                <h2>External Links - Sitemaps</h2>
                <p class="description">Add sitemap URLs to automatically discover external resources.</p>
                
                <form method="post" style="margin-bottom: 20px;">
                    <table class="form-table">
                        <tr>
                            <th><label for="sitemap_url">Sitemap URL</label></th>
                            <td>
                                <input type="url" name="sitemap_url" id="sitemap_url" class="regular-text" placeholder="https://example.com/sitemap.xml">
                                <p class="description">Only XML sitemaps are supported (WordPress sitemaps work best)</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Add Sitemap', 'secondary', 'aia_add_sitemap'); ?>
                </form>
                
                <h3>Active Sitemaps</h3>
                <?php if (!empty($link_data['sitemap_urls'])): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Sitemap URL</th>
                                <th>Status</th>
                                <th>Links Found</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($link_data['sitemap_urls'] as $sitemap_url): 
                                $cached = array_filter($link_data['sitemap_cache'] ?? [], function($link) use ($sitemap_url) {
                                    return strpos($link['url'], parse_url($sitemap_url, PHP_URL_HOST) ?? '') !== false;
                                });
                            ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url($sitemap_url); ?>" target="_blank">
                                            <?php echo esc_html($sitemap_url); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if (count($cached) > 0): ?>
                                            <span class="aia-status aia-status-done">Cached</span>
                                        <?php else: ?>
                                            <span class="aia-status aia-status-pending">Pending Sync</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo count($cached); ?> links</td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="remove_sitemap" value="<?php echo esc_attr($sitemap_url); ?>">
                                            <button type="submit" name="aia_remove_sitemap" class="button button-small delete" onclick="return confirm('Remove this sitemap?');">
                                                Remove
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No sitemaps added. Add a sitemap URL above.</p>
                <?php endif; ?>
                
                <div class="aia-sync-actions" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                    <form method="post" style="display:inline;">
                        <button type="submit" name="aia_sync_now" class="button button-primary">
                            Sync Sitemaps Now
                        </button>
                    </form>
                    <span class="description" style="margin-left: 10px;">
                        Last synced: <?php echo !empty($link_data['last_sitemap_update']) ? esc_html($link_data['last_sitemap_update']) : 'Never'; ?>
                    </span>
                    <p class="description" style="margin-top: 5px;">Sitemaps are automatically synced daily via cron job.</p>
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
            .aia-keyword-tag {
                display: inline-block;
                background: #e9ecef;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                margin: 2px;
            }
            .aia-no-keywords {
                color: #999;
                font-style: italic;
            }
            .aia-status {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
            }
            .aia-status-done { background: #5cb85c; color: #fff; }
            .aia-status-pending { background: #f0ad4e; color: #fff; }
            .delete { color: #dc3232; }
            .delete:hover { color: #b71c1c; }
            .aia-sync-actions {
                background: #f8f9fa;
                border-radius: 4px;
            }
            .aia-settings-section .form-table th {
                width: 200px;
            }
        </style>
        
        <?php
    }
    
    // ==================== HANDLERS ====================
    
    private function handle_save_settings() {
        $max_internal = isset($_POST['max_internal_links']) ? intval($_POST['max_internal_links']) : 5;
        $max_external = isset($_POST['max_external_links']) ? intval($_POST['max_external_links']) : 3;
        
        update_option('aia_max_internal_links', $max_internal);
        update_option('aia_max_external_links', $max_external);
        
        // Also update the link manager properties
        $this->link_manager->set_max_internal_links($max_internal);
        $this->link_manager->set_max_external_links($max_external);
        
        wp_redirect(add_query_arg('updated', 'Settings saved successfully.', remove_query_arg('updated')));
        exit;
    }
    
    private function handle_add_direct_link() {
        if (!isset($_POST['direct_url']) || !isset($_POST['direct_anchor'])) {
            wp_redirect(add_query_arg('updated', 'Missing required fields.', remove_query_arg('updated')));
            exit;
        }
        
        $url = sanitize_url($_POST['direct_url']);
        $anchor = sanitize_text_field($_POST['direct_anchor']);
        $keywords = isset($_POST['direct_keywords']) ? sanitize_text_field($_POST['direct_keywords']) : '';
        $topic_keywords = array_map('trim', explode(',', $keywords));
        $topic_keywords = array_filter($topic_keywords);
        
        if ($this->link_manager->add_direct_link($url, $anchor, $topic_keywords)) {
            wp_redirect(add_query_arg('updated', 'Direct link added successfully.', remove_query_arg('updated')));
            exit;
        } else {
            wp_redirect(add_query_arg('updated', 'Failed to add link. URL may already exist.', remove_query_arg('updated')));
            exit;
        }
    }
    
    private function handle_remove_direct_link() {
        if (!isset($_POST['remove_url'])) {
            wp_redirect(add_query_arg('updated', 'No URL specified.', remove_query_arg('updated')));
            exit;
        }
        
        $url = sanitize_url($_POST['remove_url']);
        if ($this->link_manager->remove_direct_link($url)) {
            wp_redirect(add_query_arg('updated', 'Direct link removed successfully.', remove_query_arg('updated')));
            exit;
        } else {
            wp_redirect(add_query_arg('updated', 'Failed to remove link.', remove_query_arg('updated')));
            exit;
        }
    }
    
    private function handle_add_sitemap() {
        if (!isset($_POST['sitemap_url'])) {
            wp_redirect(add_query_arg('updated', 'No sitemap URL specified.', remove_query_arg('updated')));
            exit;
        }
        
        $sitemap_url = sanitize_url($_POST['sitemap_url']);
        if ($this->link_manager->add_sitemap_url($sitemap_url)) {
            wp_redirect(add_query_arg('updated', 'Sitemap added successfully.', remove_query_arg('updated')));
            exit;
        } else {
            wp_redirect(add_query_arg('updated', 'Failed to add sitemap. URL may already exist.', remove_query_arg('updated')));
            exit;
        }
    }
    
    private function handle_remove_sitemap() {
        if (!isset($_POST['remove_sitemap'])) {
            wp_redirect(add_query_arg('updated', 'No sitemap URL specified.', remove_query_arg('updated')));
            exit;
        }
        
        $sitemap_url = sanitize_url($_POST['remove_sitemap']);
        if ($this->link_manager->remove_sitemap_url($sitemap_url)) {
            wp_redirect(add_query_arg('updated', 'Sitemap removed successfully.', remove_query_arg('updated')));
            exit;
        } else {
            wp_redirect(add_query_arg('updated', 'Failed to remove sitemap.', remove_query_arg('updated')));
            exit;
        }
    }
    
    private function handle_sync_sitemaps() {
        $count = $this->link_manager->update_sitemap_cache();
        wp_redirect(add_query_arg('updated', "Sitemap sync complete! {$count} links discovered.", remove_query_arg('updated')));
        exit;
    }
    
    // ==================== AJAX METHODS ====================
    
    public function ajax_update_settings() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_update_settings')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $max_internal = isset($_POST['max_internal_links']) ? intval($_POST['max_internal_links']) : 5;
        $max_external = isset($_POST['max_external_links']) ? intval($_POST['max_external_links']) : 3;
        
        update_option('aia_max_internal_links', $max_internal);
        update_option('aia_max_external_links', $max_external);
        
        wp_send_json_success(['message' => 'Settings updated successfully.']);
    }
    
    public function ajax_sync_sitemap() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_sync_sitemap')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $count = $this->link_manager->update_sitemap_cache();
        wp_send_json_success(['message' => "Sitemap sync complete! {$count} links discovered."]);
    }
    
    public function cron_sync_sitemaps() {
        $link_manager = new AIA_Link_Manager();
        $count = $link_manager->update_sitemap_cache();
        
        $logger = new AIA_Logger();
        $logger->log("Daily sitemap sync completed. {$count} links discovered.", 'info');
    }
    
    private function get_total_content_count() {
        global $wpdb;
        return $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND post_type IN ('post', 'page')"
        );
    }
}