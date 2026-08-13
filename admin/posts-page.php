<?php
// admin/posts-page.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Posts_Page {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_submenu_page'));
    }
    
    public function add_submenu_page() {
        add_submenu_page(
            'blog-autom',
            'Generated Posts',
            'Generated Posts',
            'manage_options',
            'blog-autom-posts',
            array($this, 'render_page')
        );
    }
    
    public function render_page() {
        global $wpdb;
        
        $generated_posts = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_date, pm.meta_value as keyword
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_aia_keyword'
            WHERE p.post_status = 'publish' 
            AND p.post_type = 'post'
            AND EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} 
                WHERE post_id = p.ID 
                AND meta_key = '_aia_generated'
            )
            ORDER BY p.post_date DESC"
        );
        
        ?>
        <div class="wrap">
            <h1>AI Generated Posts</h1>
            
            <div class="aia-posts-list">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Keyword</th>
                            <th>Date Published</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($generated_posts)): ?>
                            <tr>
                                <td colspan="5">No AI-generated posts yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($generated_posts as $post): ?>
                                <tr>
                                    <td><?php echo $post->ID; ?></td>
                                    <td>
                                        <a href="<?php echo get_permalink($post->ID); ?>" target="_blank">
                                            <?php echo esc_html($post->post_title); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html($post->keyword); ?></td>
                                    <td><?php echo get_the_date('F j, Y g:i a', $post->ID); ?></td>
                                    <td>
                                        <a href="<?php echo get_edit_post_link($post->ID); ?>" class="button button-small">
                                            Edit Post
                                        </a>
                                        <a href="<?php echo get_permalink($post->ID); ?>" class="button button-small" target="_blank">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}