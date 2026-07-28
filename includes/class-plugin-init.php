<?php
// includes/class-plugin-init.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Plugin_Init {
    
    public function init() {
        $this->load_dependencies();
        $this->init_admin_pages();
        $this->init_cron();
        $this->register_hooks();
    }
    
    private function load_dependencies() {
        $includes = [
            'class-keywords.php',
            'class-author-style.php',
            'class-grounding.php',
            'class-generator.php',
            'class-linking.php',
            'class-images.php',
            'class-publisher.php',
            'class-cron.php',
            'class-logger.php',
            'class-indexnow.php'
        ];
        
        foreach ($includes as $file) {
            require_once AIA_PLUGIN_DIR . 'includes/' . $file;
        }
    }
    
    private function init_admin_pages() {
        if (is_admin()) {
            // Main admin files
            require_once AIA_PLUGIN_DIR . 'admin/dashboard.php';
            require_once AIA_PLUGIN_DIR . 'admin/keywords-page.php';
            require_once AIA_PLUGIN_DIR . 'admin/link-settings.php';
            require_once AIA_PLUGIN_DIR . 'admin/generator-page.php';
            require_once AIA_PLUGIN_DIR . 'admin/posts-page.php';
            require_once AIA_PLUGIN_DIR . 'admin/authors-page.php';
            
            // Settings files
            require_once AIA_PLUGIN_DIR . 'admin/settings/class-ai-settings.php';
            require_once AIA_PLUGIN_DIR . 'admin/settings/class-console-settings.php';
            require_once AIA_PLUGIN_DIR . 'admin/settings/class-image-settings.php';
            
            // Initialize admin pages
            new AIA_Admin_Dashboard();
            new AIA_Keywords_Page();
            new AIA_Link_Settings();
            new AIA_Generator_Page();
            new AIA_Posts_Page();
            new AIA_Authors_Page();
            
            // Initialize settings pages
            new AIA_AI_Settings();
            new AIA_Console_Settings();
            new AIA_Image_Settings();
        }
    }
    
    private function init_cron() {
        $cron = new AIA_Cron_Handler();
        add_action('aia_process_keywords', array($cron, 'process_keyword_queue'));
        add_action('aia_sync_sitemaps', 'aia_sync_sitemaps_callback');
        
        if (!wp_next_scheduled('aia_process_keywords')) {
            wp_schedule_event(time(), 'every_minute', 'aia_process_keywords');
        }
        
        if (!wp_next_scheduled('aia_sync_sitemaps')) {
            wp_schedule_event(time(), 'daily', 'aia_sync_sitemaps');
        }
        
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
    }
    
    public function add_cron_interval($schedules) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute', 'ai-autoblog')
        );
        return $schedules;
    }
    
    private function register_hooks() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_menu', array($this, 'reorder_admin_menu'), 999);
    }
    
    /**
     * Reorder admin menu for better organization
     */
    public function reorder_admin_menu() {
        global $submenu;
        
        if (isset($submenu['ai-autoblog'])) {
            // Define the desired order
            $order = array(
                'ai-autoblog',
                'ai-autoblog-keywords',
                'ai-autoblog-generator',
                'ai-autoblog-posts',
                'ai-autoblog-authors',
                'ai-autoblog-links',
                'ai-autoblog-indexnow',
                'ai-autoblog-ai-settings',
                'ai-autoblog-console-settings',
                'ai-autoblog-image-settings'
            );
            
            // Get current submenu items
            $current_menu = $submenu['ai-autoblog'];
            $new_menu = array();
            
            // Add items in the desired order
            foreach ($order as $slug) {
                foreach ($current_menu as $item) {
                    if ($item[2] === $slug) {
                        $new_menu[] = $item;
                        break;
                    }
                }
            }
            
            // Add any remaining items not in the order
            foreach ($current_menu as $item) {
                if (!in_array($item[2], $order)) {
                    $new_menu[] = $item;
                }
            }
            
            $submenu['ai-autoblog'] = $new_menu;
        }
    }
    
    public function enqueue_admin_assets($hook) {
        // Check if this is an AI Autoblog admin page
        if (strpos($hook, 'ai-autoblog') !== false) {
            wp_enqueue_style('aia-admin', AIA_PLUGIN_URL . 'assets/admin.css', array(), AIA_VERSION);
            wp_enqueue_script('aia-admin', AIA_PLUGIN_URL . 'assets/admin.js', array('jquery'), AIA_VERSION, true);
            
            // Add IndexNow script for post list
            if ($hook === 'edit.php') {
                add_action('admin_footer', array($this, 'add_indexnow_quick_sync_script'));
            }
            
            wp_localize_script('aia-admin', 'aia_ajax', array(
                'nonce' => wp_create_nonce('aia_ajax_nonce')
            ));
        }
    }
    
    public function add_indexnow_quick_sync_script() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('.aia-indexnow-sync').on('click', function() {
                var button = $(this);
                var postId = button.data('post-id');
                
                button.prop('disabled', true).text('Syncing...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_indexnow_sync',
                        post_id: postId,
                        nonce: '<?php echo wp_create_nonce('aia_indexnow_sync'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            button.text('✅ Synced').css('color', '#28a745');
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            button.text('❌ Failed').css('color', '#dc3545');
                            button.prop('disabled', false);
                        }
                    },
                    error: function() {
                        button.text('❌ Error').css('color', '#dc3545');
                        button.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <style>
            .aia-indexnow-sync {
                font-size: 11px !important;
                padding: 0 6px !important;
                line-height: 1.5 !important;
                min-height: 20px !important;
            }
        </style>
        <?php
    }
}

// ============================================================
// GLOBAL CALLBACK FUNCTIONS (Outside the class)
// ============================================================

/**
 * Sitemap sync callback function
 */
function aia_sync_sitemaps_callback() {
    if (class_exists('AIA_Link_Manager')) {
        $link_manager = new AIA_Link_Manager();
        $count = $link_manager->update_sitemap_cache();
        
        $logger = new AIA_Logger();
        $logger->log("Daily sitemap sync completed. {$count} links discovered.", 'info');
    }
}