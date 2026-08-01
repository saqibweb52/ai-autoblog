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
        add_action('wp_ajax_aia_get_sitemap_links', array($this, 'ajax_get_sitemap_links'));
        add_action('wp_ajax_aia_edit_sitemap_link', array($this, 'ajax_edit_sitemap_link'));
        add_action('wp_ajax_aia_delete_sitemap_link', array($this, 'ajax_delete_sitemap_link'));
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
        
        // Debug output if needed
        if (isset($_GET['debug'])) {
            echo '<div class="notice notice-info"><p><strong>Debug Info:</strong></p>';
            echo '<pre>Sitemap URLs: ' . print_r($link_data['sitemap_urls'] ?? [], true) . '</pre>';
            echo '<pre>Sitemap Cache Count: ' . count($link_data['sitemap_cache'] ?? []) . '</pre>';
            echo '<pre>Last Update: ' . ($link_data['last_sitemap_update'] ?? 'Never') . '</pre>';
            echo '</div>';
        }
        
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
                                <th>Last Sync</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($link_data['sitemap_urls'] as $sitemap_url): 
                                // Get the sitemap host for matching
                                $sitemap_host = parse_url($sitemap_url, PHP_URL_HOST);
                                
                                // FIXED: Get links for this sitemap - match by host only
                                $sitemap_links = array_filter($link_data['sitemap_cache'] ?? [], function($link) use ($sitemap_host) {
                                    $link_host = parse_url($link['url'], PHP_URL_HOST);
                                    return ($link_host === $sitemap_host);
                                });
                                
                                // Reindex the filtered links
                                $sitemap_links = array_values($sitemap_links);
                                $link_count = count($sitemap_links);
                                
                                // Get last sync time from transient
                                $last_sync_key = 'aia_sitemap_sync_' . md5($sitemap_url);
                                $last_sync = get_transient($last_sync_key);
                                
                                // Get sync status
                                if ($last_sync !== false) {
                                    $last_sync_date = date_i18n('Y-m-d H:i:s', $last_sync);
                                    $status = '<span class="aia-status aia-status-done">Synced</span>';
                                } else {
                                    // Check if we have links but no sync transient
                                    if ($link_count > 0) {
                                        $last_sync_date = 'Unknown (has links)';
                                        $status = '<span class="aia-status aia-status-done">Cached</span>';
                                    } else {
                                        $last_sync_date = 'Never';
                                        $status = '<span class="aia-status aia-status-pending">Pending</span>';
                                    }
                                }
                            ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url($sitemap_url); ?>" target="_blank">
                                            <?php echo esc_html($sitemap_url); ?>
                                        </a>
                                    </td>
                                    <td><?php echo $status; ?></td>
                                    <td>
                                        <button type="button" class="button button-small aia-view-links" 
                                                data-sitemap="<?php echo esc_attr($sitemap_url); ?>"
                                                data-count="<?php echo $link_count; ?>">
                                            <?php echo $link_count; ?> links
                                        </button>
                                    </td>
                                    <td><?php echo esc_html($last_sync_date); ?></td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="remove_sitemap" value="<?php echo esc_attr($sitemap_url); ?>">
                                            <button type="submit" name="aia_remove_sitemap" class="button button-small delete" onclick="return confirm('Remove this sitemap? This will delete all its links.');">
                                                Remove
                                            </button>
                                        </form>
                                        <button type="button" class="button button-small aia-sync-single" data-sitemap="<?php echo esc_attr($sitemap_url); ?>">
                                            Sync Now
                                        </button>
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
                            Sync All Sitemaps Now
                        </button>
                    </form>
                    <span class="description" style="margin-left: 10px;">
                        Last full sync: <?php echo !empty($link_data['last_sitemap_update']) ? esc_html($link_data['last_sitemap_update']) : 'Never'; ?>
                    </span>
                    <p class="description" style="margin-top: 5px;">Sitemaps are automatically synced one per visit (rotating through all sitemaps).</p>
                </div>
            </div>
        </div>
        
        <!-- Links Popup Modal -->
        <div id="aia-links-modal" style="display:none;">
            <div class="aia-modal-overlay">
                <div class="aia-modal-content aia-modal-large">
                    <div class="aia-modal-header">
                        <h2>Sitemap Links</h2>
                        <button type="button" class="aia-modal-close">&times;</button>
                    </div>
                    <div class="aia-modal-body">
                        <p><strong>Sitemap:</strong> <span id="aia-modal-sitemap-url"></span></p>
                        <p><strong>Total Links:</strong> <span id="aia-modal-link-count">0</span></p>
                        <div id="aia-modal-links-list">
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th width="40">#</th>
                                        <th>URL</th>
                                        <th>Anchor Text</th>
                                        <th>Keywords</th>
                                        <th width="120">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="aia-modal-links-tbody">
                                    <!-- Links will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                        <div id="aia-modal-loading" style="text-align:center;padding:40px;display:none;">
                            <span class="spinner is-active"></span> Loading links...
                        </div>
                        <div id="aia-modal-no-links" style="text-align:center;padding:40px;display:none;color:#999;">
                            No links found in this sitemap.
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Edit Link Modal -->
        <div id="aia-edit-link-modal" style="display:none;">
            <div class="aia-modal-overlay">
                <div class="aia-modal-content">
                    <div class="aia-modal-header">
                        <h2>Edit Link</h2>
                        <button type="button" class="aia-modal-close">&times;</button>
                    </div>
                    <div class="aia-modal-body">
                        <form id="aia-edit-link-form">
                            <input type="hidden" id="aia-edit-link-original-url">
                            <input type="hidden" id="aia-edit-link-sitemap">
                            
                            <table class="form-table">
                                <tr>
                                    <th><label for="aia-edit-link-url">URL</label></th>
                                    <td>
                                        <input type="url" id="aia-edit-link-url" class="regular-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="aia-edit-link-anchor">Anchor Text</label></th>
                                    <td>
                                        <input type="text" id="aia-edit-link-anchor" class="regular-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="aia-edit-link-keywords">Keywords</label></th>
                                    <td>
                                        <input type="text" id="aia-edit-link-keywords" class="regular-text" placeholder="keyword1, keyword2, keyword3">
                                        <p class="description">Comma-separated keywords for relevance matching</p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <button type="submit" class="button button-primary">Save Changes</button>
                                <button type="button" class="button aia-modal-close">Cancel</button>
                            </p>
                        </form>
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
            
            /* Modal Styles */
            .aia-modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.6);
                z-index: 100000;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .aia-modal-content {
                background: #fff;
                border-radius: 8px;
                max-width: 800px;
                width: 95%;
                max-height: 90vh;
                display: flex;
                flex-direction: column;
                box-shadow: 0 4px 30px rgba(0,0,0,0.3);
            }
            
            .aia-modal-large {
                max-width: 1000px;
            }
            
            .aia-modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 15px 20px;
                border-bottom: 1px solid #ddd;
                flex-shrink: 0;
            }
            
            .aia-modal-header h2 {
                margin: 0;
            }
            
            .aia-modal-close {
                background: none;
                border: none;
                font-size: 28px;
                cursor: pointer;
                color: #999;
                padding: 0 10px;
                line-height: 1;
            }
            
            .aia-modal-close:hover {
                color: #333;
            }
            
            .aia-modal-body {
                padding: 20px;
                overflow-y: auto;
                flex: 1;
            }
            
            .aia-modal-body table {
                margin-top: 10px;
            }
            
            .aia-modal-body .wp-list-table td {
                vertical-align: middle;
            }
            
            .aia-modal-body .wp-list-table .actions-cell {
                white-space: nowrap;
            }
            
            .aia-edit-btn, .aia-delete-link-btn {
                font-size: 11px !important;
                padding: 0 6px !important;
                line-height: 1.5 !important;
                min-height: 20px !important;
            }
            
            .aia-edit-btn {
                margin-right: 3px;
            }
            
            .aia-delete-link-btn {
                color: #dc3232;
            }
            
            .aia-delete-link-btn:hover {
                color: #b71c1c;
            }
            
            .aia-link-tags {
                display: flex;
                flex-wrap: wrap;
                gap: 3px;
            }
            
            .aia-link-tag {
                display: inline-block;
                background: #e9ecef;
                padding: 1px 6px;
                border-radius: 3px;
                font-size: 10px;
                color: #555;
                white-space: nowrap;
            }
            
            #aia-modal-loading .spinner {
                float: none;
                margin: 0 10px 0 0;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var ajaxNonce = '<?php echo wp_create_nonce('aia_link_management'); ?>';
            
            // ========== VIEW LINKS ==========
            $('.aia-view-links').on('click', function() {
                var sitemapUrl = $(this).data('sitemap');
                var linkCount = $(this).data('count');
                
                $('#aia-modal-sitemap-url').text(sitemapUrl);
                $('#aia-modal-link-count').text(linkCount);
                $('#aia-modal-links-tbody').html('');
                $('#aia-modal-loading').show();
                $('#aia-modal-no-links').hide();
                $('#aia-links-modal').show();
                $('body').css('overflow', 'hidden');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_get_sitemap_links',
                        sitemap: sitemapUrl,
                        nonce: ajaxNonce
                    },
                    success: function(response) {
                        $('#aia-modal-loading').hide();
                        if (response.success) {
                            if (response.data.links && response.data.links.length > 0) {
                                renderLinks(response.data.links);
                            } else {
                                $('#aia-modal-no-links').show();
                                $('#aia-modal-links-tbody').html('<tr><td colspan="5" style="text-align:center;color:#999;padding:20px;">No links found in this sitemap.</td></tr>');
                            }
                        } else {
                            $('#aia-modal-links-tbody').html('<tr><td colspan="5" style="text-align:center;color:#dc3545;padding:20px;">Error: ' + response.data.message + '</td></tr>');
                        }
                    },
                    error: function() {
                        $('#aia-modal-loading').hide();
                        $('#aia-modal-links-tbody').html('<tr><td colspan="5" style="text-align:center;color:#dc3545;padding:20px;">Failed to load links.</td></tr>');
                    }
                });
            });
            
            // ========== RENDER LINKS ==========
            function renderLinks(links) {
                var tbody = $('#aia-modal-links-tbody');
                tbody.empty();
                
                if (!links || links.length === 0) {
                    tbody.html('<tr><td colspan="5" style="text-align:center;color:#999;padding:20px;">No links found in this sitemap.</td></tr>');
                    return;
                }
                
                $.each(links, function(index, link) {
                    var keywords = link.topic_keywords || [];
                    var keywordsHtml = '';
                    if (keywords.length > 0) {
                        keywordsHtml = '<div class="aia-link-tags">';
                        $.each(keywords, function(i, kw) {
                            keywordsHtml += '<span class="aia-link-tag">' + escHtml(kw) + '</span>';
                        });
                        keywordsHtml += '</div>';
                    } else {
                        keywordsHtml = '<span class="aia-no-keywords">No keywords</span>';
                    }
                    
                    var row = $('<tr>');
                    row.html(`
                        <td>${index + 1}</td>
                        <td><a href="${escHtml(link.url)}" target="_blank">${escHtml(link.url)}</a></td>
                        <td>${escHtml(link.anchor)}</td>
                        <td>${keywordsHtml}</td>
                        <td class="actions-cell">
                            <button class="button button-small aia-edit-btn" data-link='${JSON.stringify(link)}'>Edit</button>
                            <button class="button button-small aia-delete-link-btn" data-url="${escHtml(link.url)}" data-sitemap="${escHtml(link.sitemap || '')}">Delete</button>
                        </td>
                    `);
                    tbody.append(row);
                });
            }
            
            // ========== ESCAPE HTML ==========
            function escHtml(str) {
                if (!str) return '';
                return String(str).replace(/[&<>"]/g, function(m) {
                    if (m === '&') return '&amp;';
                    if (m === '<') return '&lt;';
                    if (m === '>') return '&gt;';
                    if (m === '"') return '&quot;';
                    return m;
                });
            }
            
            // ========== CLOSE MODALS ==========
            $('.aia-modal-close, .aia-modal-overlay').on('click', function(e) {
                if (e.target === this || $(this).hasClass('aia-modal-close')) {
                    $('#aia-links-modal').hide();
                    $('#aia-edit-link-modal').hide();
                    $('body').css('overflow', 'auto');
                }
            });
            
            // ========== ESC KEY ==========
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    $('#aia-links-modal').hide();
                    $('#aia-edit-link-modal').hide();
                    $('body').css('overflow', 'auto');
                }
            });
            
            // ========== EDIT LINK ==========
            $(document).on('click', '.aia-edit-btn', function() {
                var linkData = $(this).data('link');
                
                $('#aia-edit-link-original-url').val(linkData.url);
                $('#aia-edit-link-sitemap').val(linkData.sitemap || '');
                $('#aia-edit-link-url').val(linkData.url);
                $('#aia-edit-link-anchor').val(linkData.anchor);
                $('#aia-edit-link-keywords').val((linkData.topic_keywords || []).join(', '));
                
                $('#aia-edit-link-modal').show();
                $('body').css('overflow', 'hidden');
            });
            
            // ========== SAVE EDIT ==========
            $('#aia-edit-link-form').on('submit', function(e) {
                e.preventDefault();
                
                var originalUrl = $('#aia-edit-link-original-url').val();
                var sitemap = $('#aia-edit-link-sitemap').val();
                var url = $('#aia-edit-link-url').val();
                var anchor = $('#aia-edit-link-anchor').val();
                var keywords = $('#aia-edit-link-keywords').val();
                var topicKeywords = keywords.split(',').map(function(k) { return k.trim(); }).filter(function(k) { return k; });
                
                var submitBtn = $(this).find('button[type="submit"]');
                submitBtn.prop('disabled', true).text('Saving...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_edit_sitemap_link',
                        original_url: originalUrl,
                        sitemap: sitemap,
                        url: url,
                        anchor: anchor,
                        topic_keywords: topicKeywords,
                        nonce: ajaxNonce
                    },
                    success: function(response) {
                        submitBtn.prop('disabled', false).text('Save Changes');
                        if (response.success) {
                            alert('Link updated successfully!');
                            $('#aia-edit-link-modal').hide();
                            $('body').css('overflow', 'auto');
                            // Refresh the links list
                            var currentSitemap = $('#aia-modal-sitemap-url').text();
                            if (currentSitemap) {
                                $('.aia-view-links[data-sitemap="' + currentSitemap + '"]').click();
                            }
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    },
                    error: function() {
                        submitBtn.prop('disabled', false).text('Save Changes');
                        alert('Failed to save changes. Please try again.');
                    }
                });
            });
            
            // ========== DELETE LINK ==========
            $(document).on('click', '.aia-delete-link-btn', function() {
                var url = $(this).data('url');
                var sitemap = $(this).data('sitemap');
                
                if (!confirm('Delete this link?\n\nURL: ' + url)) {
                    return;
                }
                
                var btn = $(this);
                btn.prop('disabled', true).text('Deleting...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_delete_sitemap_link',
                        url: url,
                        sitemap: sitemap,
                        nonce: ajaxNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Link deleted successfully!');
                            // Refresh the links list
                            var currentSitemap = $('#aia-modal-sitemap-url').text();
                            if (currentSitemap) {
                                $('.aia-view-links[data-sitemap="' + currentSitemap + '"]').click();
                            }
                        } else {
                            alert('Error: ' + response.data.message);
                            btn.prop('disabled', false).text('Delete');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Delete');
                        alert('Failed to delete link. Please try again.');
                    }
                });
            });
            
            // ========== SYNC SINGLE SITEMAP ==========
            $('.aia-sync-single').on('click', function() {
                var sitemapUrl = $(this).data('sitemap');
                var btn = $(this);
                
                btn.prop('disabled', true).text('Syncing...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_sync_sitemap',
                        sitemap: sitemapUrl,
                        nonce: ajaxNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Sitemap synced successfully! ' + response.data.message);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                            btn.prop('disabled', false).text('Sync Now');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Sync Now');
                        alert('Failed to sync sitemap. Please try again.');
                    }
                });
            });
        });
        </script>
        
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
            wp_redirect(add_query_arg('updated', 'Sitemap removed successfully. All its links have been deleted.', remove_query_arg('updated')));
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
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_link_management')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $sitemap_url = isset($_POST['sitemap']) ? sanitize_url($_POST['sitemap']) : '';
        
        if (empty($sitemap_url)) {
            wp_send_json_error(['message' => 'No sitemap URL specified']);
        }
        
        // Sync just this sitemap
        $link_data = $this->link_manager->get_links_data();
        $links = $this->link_manager->fetch_sitemap_links($sitemap_url);
        $link_count = count($links);
        
        if ($link_count > 0) {
            // Remove old links from this sitemap - match by host only
            $sitemap_host = parse_url($sitemap_url, PHP_URL_HOST);
            
            $existing_cache = $link_data['sitemap_cache'] ?? [];
            $filtered_cache = array_filter($existing_cache, function($link) use ($sitemap_host) {
                $link_host = parse_url($link['url'], PHP_URL_HOST);
                return ($link_host !== $sitemap_host);
            });
            
            $link_data['sitemap_cache'] = array_merge(array_values($filtered_cache), $links);
            $link_data['last_sitemap_update'] = current_time('mysql');
            
            file_put_contents($this->link_manager->links_file, json_encode($link_data, JSON_PRETTY_PRINT));
            
            // Update the transient
            $last_sync_key = 'aia_sitemap_sync_' . md5($sitemap_url);
            set_transient($last_sync_key, time(), 24 * HOUR_IN_SECONDS);
            
            wp_send_json_success(['message' => "Synced {$link_count} links from sitemap."]);
        } else {
            wp_send_json_error(['message' => 'No links found in sitemap or failed to fetch.']);
        }
    }
    
    public function ajax_get_sitemap_links() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_link_management')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $sitemap_url = isset($_POST['sitemap']) ? sanitize_url($_POST['sitemap']) : '';
        
        if (empty($sitemap_url)) {
            wp_send_json_error(['message' => 'No sitemap URL specified']);
        }
        
        $link_data = $this->link_manager->get_links_data();
        $sitemap_host = parse_url($sitemap_url, PHP_URL_HOST);
        
        // Match by host only
        $links = array_filter($link_data['sitemap_cache'] ?? [], function($link) use ($sitemap_host) {
            $link_host = parse_url($link['url'], PHP_URL_HOST);
            return ($link_host === $sitemap_host);
        });
        
        // Add sitemap info to each link
        $links_with_sitemap = array_map(function($link) use ($sitemap_url) {
            $link['sitemap'] = $sitemap_url;
            return $link;
        }, array_values($links));
        
        wp_send_json_success(['links' => $links_with_sitemap]);
    }
    
    public function ajax_edit_sitemap_link() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_link_management')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $original_url = isset($_POST['original_url']) ? sanitize_url($_POST['original_url']) : '';
        $sitemap_url = isset($_POST['sitemap']) ? sanitize_url($_POST['sitemap']) : '';
        $new_url = isset($_POST['url']) ? sanitize_url($_POST['url']) : '';
        $new_anchor = isset($_POST['anchor']) ? sanitize_text_field($_POST['anchor']) : '';
        $topic_keywords = isset($_POST['topic_keywords']) ? array_map('sanitize_text_field', $_POST['topic_keywords']) : [];
        
        if (empty($original_url) || empty($sitemap_url) || empty($new_url) || empty($new_anchor)) {
            wp_send_json_error(['message' => 'Missing required fields']);
        }
        
        $link_data = $this->link_manager->get_links_data();
        $found = false;
        $sitemap_host = parse_url($sitemap_url, PHP_URL_HOST);
        
        foreach ($link_data['sitemap_cache'] as &$link) {
            if ($link['url'] === $original_url) {
                // Check if the link belongs to this sitemap (match by host)
                $link_host = parse_url($link['url'], PHP_URL_HOST);
                if ($link_host === $sitemap_host) {
                    $link['url'] = $new_url;
                    $link['anchor'] = $new_anchor;
                    $link['topic_keywords'] = $topic_keywords;
                    $found = true;
                    break;
                }
            }
        }
        
        if ($found) {
            file_put_contents($this->link_manager->links_file, json_encode($link_data, JSON_PRETTY_PRINT));
            wp_send_json_success(['message' => 'Link updated successfully']);
        } else {
            wp_send_json_error(['message' => 'Link not found']);
        }
    }
    
    public function ajax_delete_sitemap_link() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aia_link_management')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $url = isset($_POST['url']) ? sanitize_url($_POST['url']) : '';
        $sitemap_url = isset($_POST['sitemap']) ? sanitize_url($_POST['sitemap']) : '';
        
        if (empty($url) || empty($sitemap_url)) {
            wp_send_json_error(['message' => 'Missing required fields']);
        }
        
        $link_data = $this->link_manager->get_links_data();
        $found = false;
        $sitemap_host = parse_url($sitemap_url, PHP_URL_HOST);
        
        foreach ($link_data['sitemap_cache'] as $index => $link) {
            if ($link['url'] === $url) {
                // Check if the link belongs to this sitemap (match by host)
                $link_host = parse_url($link['url'], PHP_URL_HOST);
                if ($link_host === $sitemap_host) {
                    unset($link_data['sitemap_cache'][$index]);
                    $found = true;
                    break;
                }
            }
        }
        
        if ($found) {
            $link_data['sitemap_cache'] = array_values($link_data['sitemap_cache']);
            file_put_contents($this->link_manager->links_file, json_encode($link_data, JSON_PRETTY_PRINT));
            wp_send_json_success(['message' => 'Link deleted successfully']);
        } else {
            wp_send_json_error(['message' => 'Link not found']);
        }
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