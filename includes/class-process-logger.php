<?php
// includes/class-process-logger.php
if (!defined('ABSPATH')) exit;

class AIA_Process_Logger {

    private $log_key;
    private $max_entries = 200;

    public function __construct($process_id = null) {
        if ($process_id === null) {
            $process_id = 'generate_' . get_current_user_id() . '_' . time();
        }
        $this->log_key = 'aia_process_log_' . $process_id;
    }

    public function start_log($initial_message = 'Starting process...') {
        $log = [
            'started' => current_time('mysql'),
            'entries' => [],
            'completed' => false,
            'success' => false,
            'message' => ''
        ];
        $this->add_entry('info', $initial_message);
        // Store with 10 minute expiry
        set_transient($this->log_key, $log, 600);
        return $this->log_key;
    }

    public function add_entry($type, $message) {
        $log = get_transient($this->log_key);
        if (!$log || !is_array($log)) {
            $log = ['entries' => []];
        }
        if (!isset($log['entries'])) {
            $log['entries'] = [];
        }
        if (count($log['entries']) >= $this->max_entries) {
            array_shift($log['entries']);
        }
        $log['entries'][] = [
            'time' => current_time('mysql'),
            'type' => $type,
            'message' => $message
        ];
        set_transient($this->log_key, $log, 600);
    }

    public function complete($success = true, $message = '') {
        $log = get_transient($this->log_key);
        if ($log) {
            $log['completed'] = true;
            $log['success'] = $success;
            $log['message'] = $message;
            set_transient($this->log_key, $log, 600);
        }
    }

    public function get_log() {
        return get_transient($this->log_key);
    }

    public function clear_log() {
        delete_transient($this->log_key);
    }

    public function get_log_key() {
        return $this->log_key;
    }

    public function get_entries() {
        $log = $this->get_log();
        return $log ? $log['entries'] : [];
    }

    public function is_complete() {
        $log = $this->get_log();
        return $log && isset($log['completed']) && $log['completed'];
    }
}