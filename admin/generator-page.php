<?php
// admin/generator-page.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Generator_Page {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_submenu_page'));
    }
    
    public function add_submenu_page() {
        add_submenu_page(
            'ai-autoblog',
            'Manual Generator',
            'Manual Generate',
            'manage_options',
            'ai-autoblog-generator',
            array($this, 'render_page')
        );
    }
    
    public function render_page() {
        if (isset($_POST['aia_manual_generate'])) {
            $this->handle_manual_generate();
        }
        
        $keywords_manager = new AIA_Keywords_Manager();
        $authors_manager = new AIA_Author_Style();
        $pending_keywords = $keywords_manager->get_pending_keywords();
        $authors = $authors_manager->get_all_authors();
        
        ?>
        <div class="wrap">
            <h1>Manual Content Generation</h1>
            
            <div class="aia-manual-generator">
                <form method="post">
                    <table class="form-table">
                        <tr>
                            <th><label for="keyword">Keyword to Generate</label></th>
                            <td>
                                <?php if (!empty($pending_keywords)): ?>
                                    <select name="keyword_index" id="keyword_index" required>
                                        <option value="">Select a pending keyword</option>
                                        <?php foreach ($pending_keywords as $index => $keyword): ?>
                                            <option value="<?php echo $index; ?>">
                                                <?php echo esc_html($keyword['keyword']); ?> (Author ID: <?php echo $keyword['author_id']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <p class="description">No pending keywords available. <a href="?page=ai-autoblog-keywords">Add keywords first</a>.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    
                    <?php if (!empty($pending_keywords)): ?>
                        <?php submit_button('Generate and Publish', 'primary', 'aia_manual_generate'); ?>
                    <?php endif; ?>
                </form>
            </div>
            
            <div class="aia-generation-info">
                <h2>Generation Process</h2>
                <ol>
                    <li>Select a keyword from the list</li>
                    <li>The system will research the topic using AI grounding</li>
                    <li>Content will be generated following author style rules</li>
                    <li>Relevant images will be added</li>
                    <li>Internal and external links will be inserted</li>
                    <li>The post will be published automatically</li>
                </ol>
                
                <div class="notice notice-info">
                    <p><strong>Note:</strong> Manual generation processes the keyword immediately. For automatic generation, use the cron system which processes keywords every minute.</p>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function handle_manual_generate() {
        if (!isset($_POST['keyword_index'])) {
            return;
        }
        
        $index = intval($_POST['keyword_index']);
        $keywords_manager = new AIA_Keywords_Manager();
        $keywords = $keywords_manager->get_all_keywords();
        
        if (!isset($keywords[$index])) {
            echo '<div class="notice notice-error"><p>Invalid keyword selected.</p></div>';
            return;
        }
        
        $keyword_data = $keywords[$index];
        
        // Mark as processing
        $keywords_manager->update_keyword_status($index, 'processing');
        
        // Process the keyword
        $cron = new AIA_Cron_Handler();
        
        // Simulate the cron processing for this specific keyword
        $this->process_single_keyword($index, $keyword_data);
        
        echo '<div class="notice notice-success"><p>Content generation started for "' . esc_html($keyword_data['keyword']) . '". Check the posts page for results.</p></div>';
    }
    
    private function process_single_keyword($index, $keyword_data) {
        $generator = new AIA_Content_Generator();
        $publisher = new AIA_Publisher();
        $link_manager = new AIA_Link_Manager();
        $image_manager = new AIA_Image_Manager();
        $keywords_manager = new AIA_Keywords_Manager();
        
        try {
            // Generate post
            $generated = $generator->generate_post(
                $keyword_data['keyword'],
                $keyword_data['author_id']
            );
            
            if (!$generated) {
                throw new Exception('Failed to generate post content');
            }
            
            // Add links
            $generated['content'] = $link_manager->add_links(
                $generated['content'],
                $keyword_data['keyword']
            );
            
            // Add image
            $image_html = $image_manager->get_image_for_post(
                $keyword_data['keyword'],
                $generated['content']
            );
            
            $generated['content'] = $image_html . "\n\n" . $generated['content'];
            $generated['keyword'] = $keyword_data['keyword'];
            $generated['author_id'] = $keyword_data['author_id'];
            
            // Publish
            $post_id = $publisher->publish_post($generated);
            
            if ($post_id) {
                $keywords_manager->update_keyword_status($index, 'done');
            } else {
                throw new Exception('Failed to publish post');
            }
            
        } catch (Exception $e) {
            $keywords_manager->update_keyword_status($index, 'pending');
            $logger = new AIA_Logger();
            $logger->log("Manual generation error for '{$keyword_data['keyword']}': {$e->getMessage()}", 'error');
        }
    }
}