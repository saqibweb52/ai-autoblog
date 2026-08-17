<?php
// admin/settings/class-cron-settings.php
if (!defined('ABSPATH')) exit;

class AIA_Cron_Settings {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_submenu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('update_option_aia_cron_enabled', array($this, 'settings_changed'), 10, 3);
        add_action('update_option_aia_cron_interval_hours', array($this, 'settings_changed'), 10, 3);
        add_action('add_option_aia_cron_enabled', array($this, 'option_added'), 10, 2);
        add_action('add_option_aia_cron_interval_hours', array($this, 'option_added'), 10, 2);
    }

    public function add_submenu_page() {
        add_submenu_page(
            'blog-autom',
            'Cron Controls',
            'Cron Controls',
            'manage_options',
            'blog-autom-cron-controls',
            array($this, 'render_page')
        );
    }

    public function register_settings() {
        register_setting('aia_cron_settings', 'aia_cron_enabled', array(
            'type' => 'boolean',
            'sanitize_callback' => function($value) { return !empty($value) ? 1 : 0; },
            'default' => 1,
        ));

        register_setting('aia_cron_settings', 'aia_cron_interval_hours', array(
            'type' => 'integer',
            'sanitize_callback' => function($value) {
                $value = intval($value);
                return max(1, min(24, $value));
            },
            'default' => 2,
        ));
    }

    public function settings_changed($old_value, $value, $option) {
        if (class_exists('AIA_Plugin_Init')) {
            AIA_Plugin_Init::sync_generation_schedule(true);
        }
    }

    public function option_added($option, $value) {
        if (class_exists('AIA_Plugin_Init')) {
            AIA_Plugin_Init::sync_generation_schedule(true);
        }
    }

    public function render_page() {
        $enabled = (bool) get_option('aia_cron_enabled', 1);
        $hours = max(1, min(24, intval(get_option('aia_cron_interval_hours', 2))));
        $next = wp_next_scheduled('aia_process_keywords');
        $last = intval(get_option('aia_last_cron_run', 0));
        $runs_per_day = max(1, floor(24 / $hours));
        ?>
        <div class="wrap">
            <h1>⏰ Cron Controls</h1>
            <p class="description">Control automatic Blog Autom keyword generation. Manual generation from the Dashboard and Keywords pages is not affected by these settings.</p>

            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible"><p>Cron settings saved and the generation schedule was updated.</p></div>
            <?php endif; ?>

            <div class="aia-settings-section" style="max-width:900px; background:#fff; padding:20px; border:1px solid #dcdcde; border-radius:6px; margin-top:20px;">
                <form method="post" action="options.php">
                    <?php settings_fields('aia_cron_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="aia_cron_enabled">Automatic Generation</label></th>
                            <td>
                                <label style="display:flex;gap:10px;align-items:center;">
                                    <input type="checkbox" name="aia_cron_enabled" id="aia_cron_enabled" value="1" <?php checked($enabled, true); ?>>
                                    <strong>Enable automatic keyword generation</strong>
                                </label>
                                <p class="description">When disabled, Blog Autom will not run its automatic keyword-generation WP-Cron event. Manual Generate buttons continue to work.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="aia_cron_interval_hours">Request Interval</label></th>
                            <td>
                                <input type="number" min="1" max="24" step="1" name="aia_cron_interval_hours" id="aia_cron_interval_hours" value="<?php echo esc_attr($hours); ?>" class="small-text">
                                <span>hour(s)</span>
                                <p class="description">Run the automatic generation check every X hours. Allowed range: 1–24 hours. At <?php echo esc_html($hours); ?> hour(s), this is approximately <?php echo esc_html($runs_per_day); ?> check(s) per day.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Save Cron Settings'); ?>
                </form>
            </div>

            <div class="aia-settings-section" style="max-width:900px; background:#fff; padding:20px; border:1px solid #dcdcde; border-radius:6px; margin-top:20px;">
                <h2>Current Cron Status</h2>
                <table class="widefat striped" style="max-width:700px;">
                    <tbody>
                        <tr><td><strong>Status</strong></td><td><?php echo $enabled ? '<span style="color:#16823b;font-weight:600;">Enabled</span>' : '<span style="color:#b32d2e;font-weight:600;">Disabled</span>'; ?></td></tr>
                        <tr><td><strong>Interval</strong></td><td>Every <?php echo esc_html($hours); ?> hour(s)</td></tr>
                        <tr><td><strong>Next scheduled run</strong></td><td><?php echo $next ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next)) : 'Not scheduled'; ?></td></tr>
                        <tr><td><strong>Last cron run</strong></td><td><?php echo $last ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last)) : 'Never'; ?></td></tr>
                    </tbody>
                </table>
                <p class="description" style="margin-top:12px;">WP-Cron is triggered by WordPress requests unless you have configured a system cron. The interval controls when Blog Autom's generation event becomes due; it cannot force WP-Cron itself to execute if WordPress is never triggered.</p>
            </div>
        </div>
        <?php
    }
}