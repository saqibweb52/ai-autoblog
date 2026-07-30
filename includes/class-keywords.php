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
                return $this->save_keywords($keywords);
            }
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
     * Delete keyword by ID or numeric index (backward compatible)
     */
    public function delete_keyword($identifier) {
        $keywords = $this->get_all_keywords();
        $found = false;
        
        // Check if identifier is a numeric index
        if (is_numeric($identifier) && isset($keywords[intval($identifier)])) {
            unset($keywords[intval($identifier)]);
            $found = true;
        } else {
            // Check by ID
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
            return $this->save_keywords($keywords);
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
    
    private function save_keywords($keywords) {
        return file_put_contents($this->keywords_file, json_encode($keywords, JSON_PRETTY_PRINT));
    }
}