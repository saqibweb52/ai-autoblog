<?php
// includes/class-author-style.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Author_Style {
    
    private $authors_file;
    
    public function __construct() {
        $this->authors_file = AIA_DATA_DIR . 'authors.json';
        $this->ensure_authors_file_exists();
    }
    
    private function ensure_authors_file_exists() {
        if (!file_exists($this->authors_file)) {
            // Create default authors from WordPress users
            $this->create_default_authors();
        }
    }
    
    private function create_default_authors() {
        $users = get_users([
            'role__in' => ['administrator', 'editor', 'author']
        ]);
        
        $authors = [];
        foreach ($users as $user) {
            $authors[] = [
                'author_id' => $user->ID,
                'name' => $user->display_name,
                'tone' => 'professional',
                'audience' => 'general readers',
                'writing_rules' => ['clear', 'concise', 'engaging']
            ];
        }
        
        // Add default if no users found
        if (empty($authors)) {
            $authors[] = [
                'author_id' => 1,
                'name' => 'Default Author',
                'tone' => 'professional',
                'audience' => 'general readers',
                'writing_rules' => ['clear', 'concise', 'engaging']
            ];
        }
        
        file_put_contents($this->authors_file, json_encode($authors, JSON_PRETTY_PRINT));
    }
    
    public function get_all_authors() {
        if (!file_exists($this->authors_file)) {
            return [];
        }
        $content = file_get_contents($this->authors_file);
        $authors = json_decode($content, true);
        
        // Filter to only include WordPress users that exist
        $valid_authors = [];
        foreach ($authors as $author) {
            $user = get_userdata($author['author_id']);
            if ($user) {
                $author['name'] = $user->display_name; // Update name from WP
                $valid_authors[] = $author;
            }
        }
        
        // If no valid authors, create defaults
        if (empty($valid_authors)) {
            $this->create_default_authors();
            $content = file_get_contents($this->authors_file);
            return json_decode($content, true) ?: [];
        }
        
        return $valid_authors;
    }
    
    public function get_author_by_id($author_id) {
        $authors = $this->get_all_authors();
        foreach ($authors as $author) {
            if ($author['author_id'] == $author_id) {
                return $author;
            }
        }
        
        // If author not found, try to create default for this user
        $user = get_userdata($author_id);
        if ($user) {
            $new_author = [
                'author_id' => $user->ID,
                'name' => $user->display_name,
                'tone' => 'professional',
                'audience' => 'general readers',
                'writing_rules' => ['clear', 'concise', 'engaging']
            ];
            $this->add_author($new_author);
            return $new_author;
        }
        
        return null;
    }
    
    public function add_author($author_data) {
        $authors = $this->get_all_authors();
        
        // Check if author exists
        foreach ($authors as &$author) {
            if ($author['author_id'] == $author_data['author_id']) {
                $author = $author_data;
                return $this->save_authors($authors);
            }
        }
        
        $authors[] = $author_data;
        return $this->save_authors($authors);
    }
    
    public function update_author($author_id, $data) {
        $authors = $this->get_all_authors();
        foreach ($authors as &$author) {
            if ($author['author_id'] == $author_id) {
                $author = array_merge($author, $data);
                return $this->save_authors($authors);
            }
        }
        return false;
    }
    
    public function get_author_style($author_id) {
        $author = $this->get_author_by_id($author_id);
        if ($author) {
            return [
                'tone' => $author['tone'],
                'audience' => $author['audience'],
                'writing_rules' => $author['writing_rules']
            ];
        }
        
        return [
            'tone' => 'professional',
            'audience' => 'general readers',
            'writing_rules' => ['clear', 'concise']
        ];
    }
    
    private function save_authors($authors) {
        return file_put_contents($this->authors_file, json_encode($authors, JSON_PRETTY_PRINT));
    }
    
    public function sync_with_wordpress_users() {
        $wp_users = get_users([
            'role__in' => ['administrator', 'editor', 'author']
        ]);
        
        $current_authors = $this->get_all_authors();
        $current_ids = array_column($current_authors, 'author_id');
        
        // Add new WordPress users
        foreach ($wp_users as $user) {
            if (!in_array($user->ID, $current_ids)) {
                $current_authors[] = [
                    'author_id' => $user->ID,
                    'name' => $user->display_name,
                    'tone' => 'professional',
                    'audience' => 'general readers',
                    'writing_rules' => ['clear', 'concise', 'engaging']
                ];
            } else {
                // Update name
                foreach ($current_authors as &$author) {
                    if ($author['author_id'] == $user->ID) {
                        $author['name'] = $user->display_name;
                    }
                }
            }
        }
        
        return $this->save_authors($current_authors);
    }
}