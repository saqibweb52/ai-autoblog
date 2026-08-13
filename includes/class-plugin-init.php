<?php
// includes/class-plugin-init.php
if (!defined('ABSPATH')) exit;

class AIA_Plugin_Init {

    public function init() {
        $this->load_dependencies();
        $this->register_cron_schedules();
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

    /**
     * Register custom schedules before ANY scheduling attempt.
     * This is important because activation hooks run before plugins_loaded.
     */
    private function register_cron_schedules() {
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
    }

    private function init_admin_pages() {
        if (is_admin()) {
            require_once AIA_PLUGIN_DIR . 'admin/dashboard.php';
            require_once AIA_PLUGIN_DIR . 'admin/keywords-page.php';
            require_once AIA_PLUGIN_DIR . 'admin/link-settings.php';
            require_once AIA_PLUGIN_DIR . 'admin/generator-page.php';
            require_once AIA_PLUGIN_DIR . 'admin/posts-page.php';
            require_once AIA_PLUGIN_DIR . 'admin/authors-page.php';
            require_once AIA_PLUGIN_DIR . 'admin/settings/class-ai-settings.php';
            require_once AIA_PLUGIN_DIR . 'admin/settings/class-console-settings.php';
            require_once AIA_PLUGIN_DIR . 'admin/settings/class-image-settings.php';

            new AIA_Admin_Dashboard();
            new AIA_Keywords_Page();
            new AIA_Link_Settings();
            new AIA_Generator_Page();
            new AIA_Posts_Page();
            new AIA_Authors_Page();
            new AIA_AI_Settings();
            new AIA_Console_Settings();
            new AIA_Image_Settings();
        }
    }

    private function init_cron() {
        // IMPORTANT:
        // The cron callback itself runs in the WP-Cron request. We do NOT make
        // another localhost HTTP request from here. WP-Cron is already a
        // separate/background request when spawned by WordPress.
        $cron = new AIA_Cron_Handler_Async();

        add_action('aia_process_keywords', array($cron, 'process_keyword_queue'));
        add_action('aia_sync_sitemaps', 'aia_sync_sitemaps_callback');

        $this->ensure_cron_event('aia_process_keywords', 'every_5_minutes');
        $this->ensure_cron_event('aia_sync_sitemaps', 'daily');
    }

    /**
     * Ensure an event exists with the correct recurrence.
     * This also fixes installations that already have the old 120-minute event.
     */
    private function ensure_cron_event($hook, $recurrence) {
        $event = wp_get_scheduled_event($hook);

        if ($event && isset($event->schedule) && $event->schedule === $recurrence) {
            return;
        }

        if ($event) {
            wp_clear_scheduled_hook($hook);
        }

        $timestamp = time() + 60;
        $result = wp_schedule_event($timestamp, $recurrence, $hook);

        if (is_wp_error($result)) {
            error_log('Blog Autom cron scheduling failed for ' . $hook . ': ' . $result->get_error_message());
        }
    }

    public function add_cron_interval($schedules) {
        $schedules['every_120_minutes'] = array(
            'interval' => 7200,
            'display'  => __('Every 120 Minutes (2 Hours)', 'blog-autom')
        );

        $schedules['every_60_minutes'] = array(
            'interval' => 3600,
            'display'  => __('Every 60 Minutes', 'blog-autom')
        );

        $schedules['every_30_minutes'] = array(
            'interval' => 1800,
            'display'  => __('Every 30 Minutes', 'blog-autom')
        );

        $schedules['every_5_minutes'] = array(
            'interval' => 300,
            'display'  => __('Every 5 Minutes', 'blog-autom')
        );

        $schedules['every_minute'] = array(
            'interval' => 60,
            'display'  => __('Every Minute', 'blog-autom')
        );

        return $schedules;
    }

    private function register_hooks() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_menu', array($this, 'reorder_admin_menu'), 999);

        // Track actual execution of the cron callback.
        add_action('aia_process_keywords', array($this, 'track_cron_run'), 1);

        add_action('admin_notices', array($this, 'cron_health_notice'));
    }

    public function reorder_admin_menu() {
        global $submenu;

        if (isset($submenu['blog-autom'])) {
            $order = [
                'blog-autom',
                'blog-autom-keywords',
                'blog-autom-generator',
                'blog-autom-posts',
                'blog-autom-authors',
                'blog-autom-links',
                'blog-autom-indexnow',
                'blog-autom-ai-settings',
                'blog-autom-console-settings',
                'blog-autom-image-settings'
            ];

            $current_menu = $submenu['blog-autom'];
            $new_menu = [];

            foreach ($order as $slug) {
                foreach ($current_menu as $item) {
                    if ($item[2] === $slug) {
                        $new_menu[] = $item;
                        break;
                    }
                }
            }

            foreach ($current_menu as $item) {
                if (!in_array($item[2], $order, true)) {
                    $new_menu[] = $item;
                }
            }

            $submenu['blog-autom'] = $new_menu;
        }
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'blog-autom') !== false) {
            wp_enqueue_style(
                'aia-admin',
                AIA_PLUGIN_URL . 'assets/admin.css',
                array(),
                AIA_VERSION
            );

            wp_enqueue_script(
                'aia-admin',
                AIA_PLUGIN_URL . 'assets/admin.js',
                array('jquery'),
                AIA_VERSION,
                true
            );

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
                        nonce: '<?php echo esc_js(wp_create_nonce('aia_indexnow_sync')); ?>'
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

    public function track_cron_run() {
        update_option('aia_last_cron_run', time(), false);
        update_option('aia_last_cron_run_date', current_time('mysql'), false);
    }

    public function cron_health_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'blog-autom') === false) {
            return;
        }

        $event = wp_get_scheduled_event('aia_process_keywords');
        $next_run = $event ? $event->timestamp : 0;
        $last_run = (int) get_option('aia_last_cron_run', 0);

        if (!$next_run) {
            echo '<div class="notice notice-error"><p><strong>Blog Autom:</strong> The keyword cron event is not scheduled. Deactivate and reactivate the plugin once, or reload the plugin page.</p></div>';
            return;
        }

        // Only warn if the scheduled event is actually overdue by more than 10 minutes.
        if ($next_run < time() - 600) {
            $next = wp_date('Y-m-d H:i:s', $next_run);
            $last = $last_run ? wp_date('Y-m-d H:i:s', $last_run) : 'Never';

            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>Blog Autom - Cron Warning:</strong> The keyword cron is overdue.</p>';
            echo '<p><small>Last execution: ' . esc_html($last) . ' | Scheduled time: ' . esc_html($next) . '</small></p>';
            echo '</div>';
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
