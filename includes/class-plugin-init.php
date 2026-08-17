<?php
// includes/class-plugin-init.php
if (!defined('ABSPATH')) exit;

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
            'class-generator.php',
            'class-linking.php',
            'class-images.php',
            'class-publisher.php',
            'class-cron.php',
            'class-cron-async.php',
            'class-logger.php',
            'class-indexnow.php',
            'class-process-logger.php'
        ];
        foreach ($includes as $file) {
            require_once AIA_PLUGIN_DIR . 'includes/' . $file;
        }

        $research_files = [
            'class-tavily-client.php',
            'class-query-planner.php',
            'class-search-executor.php',
            'class-research-analyzer.php',
            'class-research-engine.php'
        ];
        foreach ($research_files as $file) {
            require_once AIA_PLUGIN_DIR . 'includes/research/' . $file;
        }
    }

    private function init_admin_pages() {
        if (is_admin()) {
            require_once AIA_PLUGIN_DIR . 'admin/dashboard.php';
            require_once AIA_PLUGIN_DIR . 'admin/keywords-page.php';
            require_once AIA_PLUGIN_DIR . 'admin/link-settings.php';
            require_once AIA_PLUGIN_DIR . 'admin/authors-page.php';
            require_once AIA_PLUGIN_DIR . 'admin/logs-page.php';
            require_once AIA_PLUGIN_DIR . 'admin/settings/class-ai-settings.php';
            require_once AIA_PLUGIN_DIR . 'admin/settings/class-console-settings.php';
            require_once AIA_PLUGIN_DIR . 'admin/settings/class-image-settings.php';
            require_once AIA_PLUGIN_DIR . 'admin/settings/class-cron-settings.php';

            new AIA_Admin_Dashboard();
            new AIA_Keywords_Page();
            new AIA_Link_Settings();
            new AIA_Authors_Page();
            new AIA_Logs_Page();
            new AIA_AI_Settings();
            new AIA_Console_Settings();
            new AIA_Image_Settings();
            new AIA_Cron_Settings();
        }
    }

    private function init_cron() {
        // Register the dynamic generation interval before checking/scheduling the event.
        add_filter('cron_schedules', array($this, 'add_cron_interval'));

        $cron = new AIA_Cron_Handler_Async();

        add_action('aia_process_keywords', array($cron, 'process_keyword_queue'));
        add_action('aia_sync_sitemaps', 'aia_sync_sitemaps_callback');

        // Only schedule automatic generation when it is enabled in Cron Controls.
        self::sync_generation_schedule();

        if (!wp_next_scheduled('aia_sync_sitemaps')) {
            wp_schedule_event(time(), 'daily', 'aia_sync_sitemaps');
        }
    }

    /**
     * Schedule/unschedule the automatic keyword-generation event according to settings.
     */
    public static function sync_generation_schedule($force = false) {
        $enabled = (bool) get_option('aia_cron_enabled', 1);

        if (!$enabled) {
            wp_clear_scheduled_hook('aia_process_keywords');
            return false;
        }

        // Normal requests should not reset the next-run time. Force mode is used
        // when the admin changes the interval or enabled state.
        if ($force) {
            wp_clear_scheduled_hook('aia_process_keywords');
        }

        if (!wp_next_scheduled('aia_process_keywords')) {
            wp_schedule_event(time() + 60, 'aia_custom_interval', 'aia_process_keywords');
        }

        return true;
    }

    public function add_cron_interval($schedules) {
        $hours = max(1, min(24, intval(get_option('aia_cron_interval_hours', 2))));
        $seconds = $hours * HOUR_IN_SECONDS;

        $schedules['aia_custom_interval'] = array(
            'interval' => $seconds,
            'display' => sprintf(__('Every %d hour(s) - Blog Autom', 'blog-autom'), $hours)
        );

        // Keep legacy intervals available for existing installations/integrations.
        $schedules['every_120_minutes'] = array(
            'interval' => 7200,
            'display' => __('Every 120 Minutes (2 Hours)', 'blog-autom')
        );
        $schedules['every_5_minutes'] = array(
            'interval' => 300,
            'display' => __('Every 5 Minutes', 'blog-autom')
        );
        $schedules['every_30_minutes'] = array(
            'interval' => 1800,
            'display' => __('Every 30 Minutes', 'blog-autom')
        );
        $schedules['every_60_minutes'] = array(
            'interval' => 3600,
            'display' => __('Every 60 Minutes', 'blog-autom')
        );
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute', 'blog-autom')
        );

        return $schedules;
    }

    private function register_hooks() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_menu', array($this, 'reorder_admin_menu'), 999);
        
        // Track last cron run for monitoring
        add_action('aia_process_keywords', array($this, 'track_cron_run'), 1);
        
        // Admin notice for cron health
        add_action('admin_notices', array($this, 'cron_health_notice'));
    }

    public function reorder_admin_menu() {
        global $submenu;

        if (isset($submenu['blog-autom'])) {
            $order = array(
                'blog-autom',
                'blog-autom-keywords',
                'blog-autom-authors',
                'blog-autom-links',
                'blog-autom-indexnow',
                'blog-autom-ai-settings',
                'blog-autom-console-settings',
                'blog-autom-image-settings',
                'blog-autom-cron-controls',
                'blog-autom-logs'
            );

            $current_menu = $submenu['blog-autom'];
            $new_menu = array();

            foreach ($order as $slug) {
                foreach ($current_menu as $item) {
                    if (isset($item[2]) && $item[2] === $slug) {
                        $new_menu[] = $item;
                        break;
                    }
                }
            }

            foreach ($current_menu as $item) {
                if (!isset($item[2]) || !in_array($item[2], $order, true)) {
                    $new_menu[] = $item;
                }
            }

            $submenu['blog-autom'] = $new_menu;
        }
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'blog-autom') !== false) {
            wp_enqueue_style('aia-admin', AIA_PLUGIN_URL . 'assets/admin.css', array(), AIA_VERSION);
            wp_enqueue_script('aia-admin', AIA_PLUGIN_URL . 'assets/admin.js', array('jquery'), AIA_VERSION, true);
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
                            setTimeout(function() { location.reload(); }, 1000);
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
        <style>.aia-indexnow-sync{font-size:11px!important;padding:0 6px!important;line-height:1.5!important;min-height:20px!important;}</style>
        <?php
    }

    /**
     * Track when cron runs for monitoring
     */
    public function track_cron_run() {
        update_option('aia_last_cron_run', time());
    }

    /**
     * Show admin notice if cron hasn't run recently
     */
    public function cron_health_notice() {
        // Only show to admins
        if (!current_user_can('manage_options')) {
            return;
        }

        // Only show on plugin pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'blog-autom') === false) {
            return;
        }

        $last_run = get_option('aia_last_cron_run', 0);
        $interval_hours = max(1, min(24, intval(get_option('aia_cron_interval_hours', 2))));
        $warning_after = ($interval_hours + 2) * HOUR_IN_SECONDS;
        if (get_option('aia_cron_enabled', 1) && $last_run && (time() - $last_run) > $warning_after) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong>Blog Autom - Cron Warning:</strong> 
                    The content generation cron hasn't run within its expected interval. 
                    <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?>
                        Please check your system cron configuration.
                    <?php else: ?>
                        Make sure you have visitor traffic to trigger WP-Cron, or consider setting up a system cron.
                    <?php endif; ?>
                    <br>
                    <small>Last run: <?php echo $last_run ? date('Y-m-d H:i:s', $last_run) : 'Never'; ?></small>
                </p>
            </div>
            <?php
        }
    }
}

function aia_sync_sitemaps_callback() {
    if (class_exists('AIA_Link_Manager')) {
        $link_manager = new AIA_Link_Manager();
        $count = $link_manager->update_sitemap_cache();
        $logger = new AIA_Logger();
        $logger->log("Daily sitemap sync completed. {$count} links discovered.", 'info');
    }
}