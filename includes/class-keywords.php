<?php
// includes/class-keywords.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Keywords_Manager {
    
    private $keywords_file;
    
    public function __construct() {
        $this->keywords_file = AIA_DATA_DIR . 'keywords.json';
        // Migrate old keywords to have IDs
        $this->migrate_keywords();
    }
    
    /**
     * Migrate old keywords to have unique IDs
     */
    private function migrate_keywords() {
        $keywords = $this->get_all_keywords_raw();
        $needs_save = false;
        
        foreach ($keywords as &$keyword) {
            if (!isset($keyword['id'])) {
                $keyword['id'] = uniqid('kw_', true);
                $needs_save = true;
            }
        }
        
        if ($needs_save) {
            $this->save_keywords($keywords);
        }
    }
    
    /**
     * Get raw keywords without migration
     */
    private function get_all_keywords_raw() {
        if (!file_exists($this->keywords_file)) {
            return [];
        }
        $content = file_get_contents($this->keywords_file);
        return json_decode($content, true) ?: [];
    }
    
    public function get_all_keywords() {
        return $this->get_all_keywords_raw();
    }
    
    public function add_keyword($keyword, $author_id, $categories = array()) {
        $keywords = $this->get_all_keywords();
        
        $new_keyword = [
            'id' => uniqid('kw_', true),
            'keyword' => sanitize_text_field($keyword),
            'author_id' => intval($author_id),
            'categories' => array_map('intval', $categories),
            'status' => 'pending',
            'status_updated_at' => current_time('timestamp'),
            'created_at' => current_time('mysql')
        ];
        
        $keywords[] = $new_keyword;
        return $this->save_keywords($keywords);
    }
    
    /**
     * Update keyword status by ID or numeric index
     */
    public function update_keyword_status($identifier, $status) {
        $keywords = $this->get_all_keywords();
        
        foreach ($keywords as &$keyword) {
            // Check by ID if it's a string, or by numeric index
            if ((is_string($identifier) && isset($keyword['id']) && $keyword['id'] === $identifier) ||
                (is_int($identifier) && $keyword === $keywords[$identifier])) {
                $keyword['status'] = $status;
                $keyword['status_updated_at'] = current_time('timestamp');
                return $this->save_keywords($keywords);
            }
        }
        
        return false;
    }
    
    /**
     * Reset keywords that have been stuck in processing for too long.
     * This prevents a failed/fatal background request from leaving a keyword
     * in processing forever.
     */
    public function recover_stuck_processing_keywords($timeout = 300) {
        $keywords = $this->get_all_keywords();
        $changed = false;
        $now = current_time('timestamp');

        foreach ($keywords as &$keyword) {
            if (!isset($keyword['status']) || $keyword['status'] !== 'processing') {
                continue;
            }

            $updated = isset($keyword['status_updated_at'])
                ? intval($keyword['status_updated_at'])
                : 0;

            // Old installations may not have a timestamp. Treat those as stale
            // so they can recover automatically instead of remaining stuck.
            if ($updated <= 0 || ($now - $updated) >= intval($timeout)) {
                $keyword['status'] = 'pending';
                $keyword['status_updated_at'] = $now;
                $changed = true;
            }
        }
        unset($keyword);

        if ($changed) {
            $this->save_keywords($keywords);
            return true;
        }

        return false;
    }

    public function get_pending_keywords() {
        $keywords = $this->get_all_keywords();
        return array_filter($keywords, function($k) {
            return $k['status'] === 'pending';
        });
    }
    
    public function get_processing_keywords() {
        $keywords = $this->get_all_keywords();
        return array_filter($keywords, function($k) {
            return $k['status'] === 'processing';
        });
    }
    
    public function get_next_pending_keyword() {
        $pending = $this->get_pending_keywords();
        if (!empty($pending)) {
            $first = reset($pending);
            return [
                'index' => key($pending),
                'data' => $first
            ];
        }
        return null;
    }
    
    /**
     * Delete keyword by ID (preferred) or numeric index (backward compatible)
     */
    public function delete_keyword($identifier) {
        $keywords = $this->get_all_keywords();
        $found = false;
        
        // Check if identifier is a numeric index (backward compatibility)
        if (is_numeric($identifier)) {
            $index = intval($identifier);
            if (isset($keywords[$index])) {
                unset($keywords[$index]);
                $found = true;
            }
        } else {
            // Check by ID (string identifier)
            foreach ($keywords as $index => $keyword) {
                if (isset($keyword['id']) && $keyword['id'] === $identifier) {
                    unset($keywords[$index]);
                    $found = true;
                    break;
                }
            }
        }
        
        if ($found) {
            // Reindex the array
            $keywords = array_values($keywords);
            $result = $this->save_keywords($keywords);
            
            if ($result === false) {
                $logger = new AIA_Logger();
                $logger->log("Failed to save keywords file after deletion. File: " . $this->keywords_file, 'error');
                return false;
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Get keyword by ID or numeric index
     */
    public function get_keyword($identifier) {
        $keywords = $this->get_all_keywords();
        
        // Check if identifier is a numeric index
        if (is_numeric($identifier) && isset($keywords[intval($identifier)])) {
            return $keywords[intval($identifier)];
        }
        
        // Check by ID
        foreach ($keywords as $keyword) {
            if (isset($keyword['id']) && $keyword['id'] === $identifier) {
                return $keyword;
            }
        }
        
        return null;
    }
    
    /**
     * Get keyword by ID only
     */
    public function get_keyword_by_id($id) {
        $keywords = $this->get_all_keywords();
        foreach ($keywords as $keyword) {
            if (isset($keyword['id']) && $keyword['id'] === $id) {
                return $keyword;
            }
        }
        return null;
    }
    
    private function save_keywords($keywords) {
        // Ensure the data directory exists
        $data_dir = dirname($this->keywords_file);
        if (!file_exists($data_dir)) {
            if (!mkdir($data_dir, 0755, true)) {
                $logger = new AIA_Logger();
                $logger->log("Failed to create data directory: " . $data_dir, 'error');
                return false;
            }
        }
        
        // Check if directory is writable
        if (!is_writable($data_dir)) {
            $logger = new AIA_Logger();
            $logger->log("Data directory is not writable: " . $data_dir, 'error');
            return false;
        }
        
        $result = file_put_contents($this->keywords_file, json_encode($keywords, JSON_PRETTY_PRINT));
        
        if ($result === false) {
            $logger = new AIA_Logger();
            $logger->log("Failed to write to keywords file: " . $this->keywords_file, 'error');
            return false;
        }
        
        return $result;
    }
}