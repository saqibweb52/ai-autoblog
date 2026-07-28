<?php
// includes/class-keywords.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Keywords_Manager {
    
    private $keywords_file;
    
    public function __construct() {
        $this->keywords_file = AIA_DATA_DIR . 'keywords.json';
    }
    
    public function get_all_keywords() {
        if (!file_exists($this->keywords_file)) {
            return [];
        }
        $content = file_get_contents($this->keywords_file);
        return json_decode($content, true) ?: [];
    }
    
    public function add_keyword($keyword, $author_id) {
        $keywords = $this->get_all_keywords();
        
        $new_keyword = [
            'keyword' => sanitize_text_field($keyword),
            'author_id' => intval($author_id),
            'status' => 'pending',
            'created_at' => current_time('mysql')
        ];
        
        $keywords[] = $new_keyword;
        return $this->save_keywords($keywords);
    }
    
    public function update_keyword_status($keyword_index, $status) {
        $keywords = $this->get_all_keywords();
        
        if (isset($keywords[$keyword_index])) {
            $keywords[$keyword_index]['status'] = $status;
            return $this->save_keywords($keywords);
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
            reset($pending);
            $index = key($pending);
            return [
                'index' => $index,
                'data' => $pending[$index]
            ];
        }
        return null;
    }
    
    public function delete_keyword($index) {
        $keywords = $this->get_all_keywords();
        if (isset($keywords[$index])) {
            array_splice($keywords, $index, 1);
            return $this->save_keywords($keywords);
        }
        return false;
    }
    
    private function save_keywords($keywords) {
        return file_put_contents($this->keywords_file, json_encode($keywords, JSON_PRETTY_PRINT));
    }
}