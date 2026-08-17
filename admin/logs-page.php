<?php
// admin/logs-page.php
if (!defined('ABSPATH')) exit;

class AIA_Logs_Page {

    private $log_dir;

    public function __construct() {
        $this->log_dir = trailingslashit(AIA_DATA_DIR) . 'logs/';
        add_action('admin_menu', array($this, 'add_submenu_page'));
    }

    public function add_submenu_page() {
        add_submenu_page(
            'blog-autom',
            'Logs',
            'Logs',
            'manage_options',
            'blog-autom-logs',
            array($this, 'render_page')
        );
    }

    private function get_log_files() {
        // Logger stores files under data/logs/. Create the directory if it
        // does not exist yet so the page can recover cleanly on a fresh install.
        if (!is_dir($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }

        $files = glob(trailingslashit($this->log_dir) . '*.log');
        if (!is_array($files)) {
            return array();
        }

        $files = array_values(array_filter($files, function($file) {
            return is_file($file) && preg_match('/\.log$/i', $file);
        }));

        usort($files, function($a, $b) {
            return (int) @filemtime($b) <=> (int) @filemtime($a);
        });

        return $files;
    }

    private function safe_log_basename($file) {
        $file = sanitize_file_name(wp_unslash($file));
        if ($file === '' || !preg_match('/\.log$/i', $file)) {
            return '';
        }
        return $file;
    }

    private function get_selected_file($selected) {
        $selected = $this->safe_log_basename($selected);
        if ($selected === '') {
            return '';
        }
        $path = trailingslashit($this->log_dir) . $selected;
        return file_exists($path) && is_file($path) ? $path : '';
    }

    private function get_background_processes() {
        $processes = class_exists('AIA_Cron_Handler_Async')
            ? AIA_Cron_Handler_Async::get_background_processes()
            : array();

        if (!is_array($processes)) {
            $processes = array();
        }

        // Include the current WP-Cron schedule as useful diagnostic context.
        // This is not treated as an active worker and therefore cannot be
        // accidentally deleted from this page.
        $next_cron = wp_next_scheduled('aia_process_keywords');
        if ($next_cron) {
            $processes[] = array(
                'id' => 'wpcron-aia-process-keywords',
                'keyword' => 'Automatic keyword queue',
                'display_status' => 'Scheduled',
                'started_at' => 'Next run: ' . wp_date('Y-m-d H:i:s', $next_cron),
                'last_activity' => 0,
                'is_schedule' => true,
            );
        }

        return $processes;
    }

    private function cleanup_old_files($days = 7) {
        $deleted = 0;
        $cutoff = time() - ($days * DAY_IN_SECONDS);
        foreach ($this->get_log_files() as $file) {
            if (@filemtime($file) < $cutoff && @unlink($file)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    private function latest_filename() {
        $files = $this->get_log_files();
        return !empty($files) ? basename($files[0]) : '';
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view logs.', 'blog-autom'));
        }

        $notice = '';
        $notice_type = 'success';
        $selected = isset($_GET['log_file']) ? wp_unslash($_GET['log_file']) : '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('aia_logs_action', 'aia_logs_nonce');
            $action = isset($_POST['aia_log_action']) ? sanitize_key(wp_unslash($_POST['aia_log_action'])) : '';
            $selected = isset($_POST['log_file']) ? wp_unslash($_POST['log_file']) : $selected;
            $path = $this->get_selected_file($selected);
            $process_id = isset($_POST['process_id']) ? sanitize_text_field(wp_unslash($_POST['process_id'])) : '';

            if ($action === 'delete_process' && $process_id) {
                if ($process_id === 'wpcron-aia-process-keywords') {
                    $notice = 'The recurring WP-Cron schedule was not deleted. Delete the schedule only from the plugin scheduling settings if you want automatic generation disabled.';
                    $notice_type = 'warning';
                } elseif (class_exists('AIA_Cron_Handler_Async') && AIA_Cron_Handler_Async::delete_background_process($process_id)) {
                    $notice = 'Background process removed and its processing state was reset.';
                } else {
                    $notice = 'Background process was not found.';
                    $notice_type = 'error';
                }
            } elseif ($action === 'clear' && $path) {
                if (@file_put_contents($path, '') !== false) {
                    $notice = 'Selected log file was cleared.';
                } else {
                    $notice = 'Could not clear the selected log file.';
                    $notice_type = 'error';
                }
            } elseif ($action === 'delete' && $path) {
                $deleted_name = basename($path);
                if (@unlink($path)) {
                    $notice = sprintf('Log file %s was deleted.', $deleted_name);
                    $selected = '';
                } else {
                    $notice = 'Could not delete the selected log file.';
                    $notice_type = 'error';
                }
            } elseif ($action === 'cleanup') {
                $deleted = $this->cleanup_old_files(7);
                $notice = sprintf('Cleanup completed. %d old log file(s) deleted. Logs newer than 7 days were kept.', $deleted);
                $selected = '';
            }
        }

        $files = $this->get_log_files();
        $background_processes = $this->get_background_processes();
        $latest = !empty($files) ? $files[0] : '';
        $selected_path = $this->get_selected_file($selected ?? '');
        if (!$selected_path) {
            $selected_path = $latest;
        }
        $selected_name = $selected_path ? basename($selected_path) : '';

        // Always show the newest file by default.
        $contents = '';
        if ($selected_path && file_exists($selected_path)) {
            $contents = file_get_contents($selected_path);
            if ($contents === false) {
                $contents = '';
            }
        }
        ?>
        <div class="wrap aia-logs-page">
            <h1>Logs</h1>
            <p class="description">The latest log file is shown automatically. Select an older file when you need to inspect previous activity.</p>

            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <div class="aia-background-processes">
                <div class="aia-processes-header">
                    <div>
                        <h2>Background Processes</h2>
                        <p class="description">Live background workers created by Blog Autom, plus the next automatic WP-Cron run.</p>
                    </div>
                    <span class="aia-process-count"><?php echo esc_html(count($background_processes)); ?></span>
                </div>

                <?php if (empty($background_processes)): ?>
                    <p class="aia-no-processes">No background process is currently registered.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr><th>Process</th><th>Keyword</th><th>Status</th><th>Started / Next Run</th><th>Last Activity</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($background_processes as $process): ?>
                            <?php $is_schedule = !empty($process['is_schedule']); ?>
                            <tr>
                                <td><code><?php echo esc_html($process['id'] ?? ''); ?></code></td>
                                <td><?php echo esc_html($process['keyword'] ?? '—'); ?></td>
                                <td><span class="aia-process-status aia-process-status-<?php echo esc_attr(strtolower($process['display_status'] ?? 'unknown')); ?>"><?php echo esc_html($process['display_status'] ?? 'Unknown'); ?></span></td>
                                <td><?php echo esc_html($process['started_at'] ?? '—'); ?></td>
                                <td>
                                    <?php $last = isset($process['last_activity']) ? intval($process['last_activity']) : 0; ?>
                                    <?php echo $last ? esc_html(human_time_diff($last, time()) . ' ago') : '—'; ?>
                                </td>
                                <td>
                                    <?php if (!$is_schedule): ?>
                                        <form method="post" style="margin:0">
                                            <?php wp_nonce_field('aia_logs_action', 'aia_logs_nonce'); ?>
                                            <input type="hidden" name="process_id" value="<?php echo esc_attr($process['id'] ?? ''); ?>">
                                            <button type="submit" name="aia_log_action" value="delete_process" class="button button-small button-link-delete" onclick="return confirm('Delete this process and reset its keyword to pending?');">Delete</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="description">Recurring schedule</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="aia-log-toolbar">
                <form method="post" class="aia-log-select-form">
                    <?php wp_nonce_field('aia_logs_action', 'aia_logs_nonce'); ?>
                    <label for="aia-log-file"><strong>Log file:</strong></label>
                    <select name="log_file" id="aia-log-file">
                        <?php if (empty($files)): ?>
                            <option value="">No log files found</option>
                        <?php else: ?>
                            <?php foreach ($files as $file): ?>
                                <?php $name = basename($file); ?>
                                <option value="<?php echo esc_attr($name); ?>" <?php selected($name, $selected_name); ?>>
                                    <?php echo esc_html($name); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <button type="submit" class="button">View Log</button>
                    <input type="hidden" name="aia_log_action" value="view">
                </form>

                <?php if ($selected_name): ?>
                    <form method="post" style="display:inline-flex; gap:8px; align-items:center;">
                        <?php wp_nonce_field('aia_logs_action', 'aia_logs_nonce'); ?>
                        <input type="hidden" name="log_file" value="<?php echo esc_attr($selected_name); ?>">
                        <button type="submit" name="aia_log_action" value="clear" class="button" onclick="return confirm('Clear this log file?');">Clear File</button>
                        <button type="submit" name="aia_log_action" value="delete" class="button button-link-delete" onclick="return confirm('Delete this log file permanently?');">Delete File</button>
                    </form>
                <?php endif; ?>

                <form method="post" style="display:inline-flex;">
                    <?php wp_nonce_field('aia_logs_action', 'aia_logs_nonce'); ?>
                    <button type="submit" name="aia_log_action" value="cleanup" class="button" onclick="return confirm('Delete log files older than 7 days?');">Cleanup Old Files</button>
                </form>
            </div>

            <div class="aia-log-viewer">
                <div class="aia-log-viewer-header">
                    <strong><?php echo $selected_name ? esc_html($selected_name) : 'No log file'; ?></strong>
                    <?php if ($selected_path && file_exists($selected_path)): ?>
                        <span><?php echo esc_html(size_format(filesize($selected_path))); ?></span>
                    <?php endif; ?>
                </div>
                <pre><?php echo esc_html($contents ?: 'No log entries yet.'); ?></pre>
            </div>
        </div>

        <style>
            .aia-background-processes { background:#fff; padding:20px; margin:20px 0; border:1px solid #dcdcde; border-radius:6px; }
            .aia-processes-header { display:flex; justify-content:space-between; gap:20px; align-items:flex-start; margin-bottom:15px; }
            .aia-processes-header h2 { margin:0 0 5px; }
            .aia-process-count { background:#f0f0f1; border-radius:20px; padding:5px 12px; font-weight:600; }
            .aia-no-processes { color:#646970; }
            .aia-process-status { display:inline-block; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:600; }
            .aia-process-status-running { background:#d7f0db; color:#176b2c; }
            .aia-process-status-stale { background:#f8d7da; color:#842029; }
            .aia-process-status-queued { background:#fff3cd; color:#664d03; }
            .aia-process-status-scheduled { background:#e2e3e5; color:#41464b; }
            .aia-log-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; background:#fff; padding:16px; margin:20px 0; border:1px solid #dcdcde; border-radius:6px; }
            .aia-log-toolbar form { margin:0; }
            .aia-log-select-form { display:flex; gap:8px; align-items:center; }
            .aia-log-select-form select { min-width:280px; }
            .aia-log-viewer { background:#1e1e1e; border-radius:6px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.08); }
            .aia-log-viewer-header { background:#2b2b2b; color:#fff; padding:12px 16px; display:flex; justify-content:space-between; gap:20px; }
            .aia-log-viewer-header span { color:#aaa; }
            .aia-log-viewer pre { color:#d4d4d4; margin:0; padding:18px; min-height:420px; max-height:70vh; overflow:auto; white-space:pre-wrap; word-break:break-word; font:13px/1.6 Consolas,Monaco,monospace; }
            @media (max-width:782px) { .aia-log-select-form { width:100%; flex-wrap:wrap; } .aia-log-select-form select { min-width:0; width:100%; } }
        </style>
        <?php
    }
}