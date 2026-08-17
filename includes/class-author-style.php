<?php
// includes/class-author-style.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Author_Style {
    
    /**
     * Get all users with publishing rights
     */
    public function get_all_authors() {
        $users = get_users([
            'role__in' => ['administrator', 'editor', 'author'],
            'orderby' => 'display_name',
            'order' => 'ASC'
        ]);
        
        $authors = [];
        foreach ($users as $user) {
            $authors[] = [
                'author_id' => $user->ID,
                'name' => $user->display_name,
                'user_login' => $user->user_login,
                'user_email' => $user->user_email,
                'tone' => get_user_meta($user->ID, '_aia_author_tone', true) ?: 'professional',
                'audience' => get_user_meta($user->ID, '_aia_author_audience', true) ?: 'general readers',
                'writing_rules' => get_user_meta($user->ID, '_aia_author_writing_rules', true) ?: ['clear', 'concise', 'engaging']
            ];
        }
        
        return $authors;
    }
    
    /**
     * Get author by ID
     */
    public function get_author_by_id($author_id) {
        $user = get_userdata($author_id);
        if (!$user) {
            return null;
        }
        
        // Check if user has publishing rights
        if (!user_can($user, 'publish_posts')) {
            return null;
        }
        
        return [
            'author_id' => $user->ID,
            'name' => $user->display_name,
            'user_login' => $user->user_login,
            'user_email' => $user->user_email,
            'tone' => get_user_meta($user->ID, '_aia_author_tone', true) ?: 'professional',
            'audience' => get_user_meta($user->ID, '_aia_author_audience', true) ?: 'general readers',
            'writing_rules' => get_user_meta($user->ID, '_aia_author_writing_rules', true) ?: ['clear', 'concise', 'engaging']
        ];
    }
    
    /**
     * Get author style for content generation
     */
    public function get_author_style($author_id) {
        $user = get_userdata($author_id);
        if (!$user) {
            return [
                'tone' => 'professional',
                'audience' => 'general readers',
                'writing_rules' => ['clear', 'concise']
            ];
        }
        
        return [
            'tone' => get_user_meta($user->ID, '_aia_author_tone', true) ?: 'professional',
            'audience' => get_user_meta($user->ID, '_aia_author_audience', true) ?: 'general readers',
            'writing_rules' => get_user_meta($user->ID, '_aia_author_writing_rules', true) ?: ['clear', 'concise']
        ];
    }
    
    /**
     * Update author style in user meta
     */
    public function update_author_style($author_id, $tone, $audience, $writing_rules) {
        $user = get_userdata($author_id);
        if (!$user) {
            return false;
        }
        
        update_user_meta($author_id, '_aia_author_tone', sanitize_text_field($tone));
        update_user_meta($author_id, '_aia_author_audience', sanitize_text_field($audience));
        update_user_meta($author_id, '_aia_author_writing_rules', array_map('sanitize_text_field', $writing_rules));
        
        return true;
    }
    
    /**
     * Get user role
     */
    public function get_user_role($user) {
        if (is_object($user) && isset($user->roles)) {
            $roles = $user->roles;
            return !empty($roles) ? $roles[0] : 'subscriber';
        }
        if (is_numeric($user)) {
            $user_obj = get_userdata($user);
            if ($user_obj && isset($user_obj->roles)) {
                $roles = $user_obj->roles;
                return !empty($roles) ? $roles[0] : 'subscriber';
            }
        }
        return 'subscriber';
    }
}