<?php
// includes/class-logger.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Logger {
    
    private $log_file;
    
    public function __construct() {
        // Use plugin data directory instead of uploads
        $log_dir = AIA_DATA_DIR . 'logs/';
        
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        
        $this->log_file = $log_dir . 'ai-autoblog-' . date('Y-m-d') . '.log';
    }
    
    public function log($message, $type = 'info') {
        // Check if logging is enabled
        $logging_enabled = get_option('aia_enable_logging', 1);
        if (!$logging_enabled) {
            return;
        }
        
        // Map type to uppercase
        $type_map = [
            'info' => 'INFO',
            'warning' => 'WARNING',
            'error' => 'ERROR',
            'success' => 'SUCCESS',
            'debug' => 'DEBUG'
        ];
        
        $log_type = $type_map[$type] ?? strtoupper($type);
        
        $log_entry = sprintf(
            "[%s] [%s] %s\n",
            current_time('mysql'),
            $log_type,
            $message
        );
        
        error_log($log_entry, 3, $this->log_file);
    }
    
    public function get_logs($lines = 50) {
        if (!file_exists($this->log_file)) {
            return [];
        }
        
        $logs = file($this->log_file);
        return array_slice($logs, -$lines);
    }
    
    public function clear_logs() {
        if (file_exists($this->log_file)) {
            unlink($this->log_file);
        }
    }
    
    public function get_log_file_path() {
        return $this->log_file;
    }
}