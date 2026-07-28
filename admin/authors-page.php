<?php
// admin/authors-page.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Authors_Page {
    
    private $authors_manager;
    
    public function __construct() {
        $this->authors_manager = new AIA_Author_Style();
        add_action('admin_menu', array($this, 'add_submenu_page'));
    }
    
    public function add_submenu_page() {
        add_submenu_page(
            'ai-autoblog',
            'Author Styles',
            'Author Styles',
            'manage_options',
            'ai-autoblog-authors',
            array($this, 'render_page')
        );
    }
    
    public function render_page() {
        // Handle style updates
        if (isset($_POST['aia_update_style'])) {
            $this->handle_update_style();
        }
        
        $users = $this->authors_manager->get_all_authors();
        
        ?>
        <div class="wrap">
            <h1>Author Style Management</h1>
            
            <div class="notice notice-info">
                <p><strong>Note:</strong> Author styles are applied to WordPress system users (Administrators, Editors, Authors).</p>
            </div>
            
            <div class="aia-authors-list">
                <h2>WordPress Users with Publishing Rights</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Display Name</th>
                            <th>Role</th>
                            <th>Style Settings</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6">No users with publishing capabilities found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): 
                                $style = $this->authors_manager->get_author_style($user->ID);
                            ?>
                                <tr>
                                    <td><?php echo $user->ID; ?></td>
                                    <td><strong><?php echo esc_html($user->user_login); ?></strong></td>
                                    <td><?php echo esc_html($user->display_name); ?></td>
                                    <td>
                                        <span class="aia-role-badge">
                                            <?php echo esc_html($this->authors_manager->get_user_role($user)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <details>
                                            <summary>View Style</summary>
                                            <div class="aia-style-details">
                                                <p><strong>Tone:</strong> <?php echo esc_html($style['tone']); ?></p>
                                                <p><strong>Audience:</strong> <?php echo esc_html($style['audience']); ?></p>
                                                <p><strong>Rules:</strong> <?php echo implode(', ', $style['writing_rules']); ?></p>
                                            </div>
                                        </details>
                                    </td>
                                    <td>
                                        <button type="button" 
                                                class="button aia-edit-style" 
                                                data-user-id="<?php echo $user->ID; ?>"
                                                data-user-name="<?php echo esc_attr($user->display_name); ?>"
                                                data-tone="<?php echo esc_attr($style['tone']); ?>"
                                                data-audience="<?php echo esc_attr($style['audience']); ?>"
                                                data-rules="<?php echo esc_attr(implode("\n", $style['writing_rules'])); ?>">
                                            Edit Style
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Edit Style Modal -->
        <div id="aia-edit-style-modal" style="display:none;">
            <div class="aia-modal-overlay">
                <div class="aia-modal-content">
                    <h2>Edit Author Style</h2>
                    <p>Configure writing style for: <strong id="aia-modal-user-name"></strong></p>
                    
                    <form method="post" id="aia-edit-style-form">
                        <input type="hidden" name="user_id" id="aia-modal-user-id">
                        
                        <table class="form-table">
                            <tr>
                                <th><label for="modal_tone">Writing Tone</label></th>
                                <td>
                                    <select name="tone" id="modal_tone">
                                        <option value="professional">Professional & Authoritative</option>
                                        <option value="casual">Casual & Conversational</option>
                                        <option value="technical">Technical & Detailed</option>
                                        <option value="educational">Educational & Explanatory</option>
                                        <option value="persuasive">Persuasive & Engaging</option>
                                        <option value="journalistic">Journalistic & Storytelling</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="modal_audience">Target Audience</label></th>
                                <td>
                                    <input type="text" name="audience" id="modal_audience" class="regular-text">
                                    <p class="description">Describe who the content is for (e.g., "experienced developers", "business executives")</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="modal_rules">Writing Rules</label></th>
                                <td>
                                    <textarea name="writing_rules" id="modal_rules" rows="4" class="large-text"></textarea>
                                    <p class="description">One rule per line (e.g., "use active voice", "include practical examples")</p>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" name="aia_update_style" class="button button-primary">Save Style</button>
                            <button type="button" class="button aia-modal-close">Cancel</button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        
        <style>
            .aia-role-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
                background: #f0f0f1;
                color: #2c3338;
                border: 1px solid #dcdcde;
            }
            
            .aia-style-details {
                background: #f8f9fa;
                padding: 10px;
                border-radius: 4px;
                margin-top: 5px;
            }
            
            .aia-style-details p {
                margin: 5px 0;
            }
            
            .aia-modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 10000;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .aia-modal-content {
                background: #fff;
                padding: 30px;
                border-radius: 8px;
                max-width: 600px;
                width: 90%;
                max-height: 90vh;
                overflow-y: auto;
                box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            }
            
            .aia-modal-content h2 {
                margin-top: 0;
            }
            
            .aia-modal-content .submit {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #ddd;
            }
            
            .aia-authors-list details {
                cursor: pointer;
            }
            
            .aia-authors-list details summary {
                color: #2271b1;
                font-weight: 500;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Open modal on edit button click
            $('.aia-edit-style').on('click', function() {
                var userId = $(this).data('user-id');
                var userName = $(this).data('user-name');
                var tone = $(this).data('tone');
                var audience = $(this).data('audience');
                var rules = $(this).data('rules');
                
                $('#aia-modal-user-id').val(userId);
                $('#aia-modal-user-name').text(userName);
                $('#modal_tone').val(tone);
                $('#modal_audience').val(audience);
                $('#modal_rules').val(rules);
                
                $('#aia-edit-style-modal').show();
                $('body').css('overflow', 'hidden');
            });
            
            // Close modal
            $('.aia-modal-close, .aia-modal-overlay').on('click', function(e) {
                if (e.target === this || $(this).hasClass('aia-modal-close')) {
                    $('#aia-edit-style-modal').hide();
                    $('body').css('overflow', 'auto');
                }
            });
            
            // Prevent modal close when clicking inside content
            $('.aia-modal-content').on('click', function(e) {
                e.stopPropagation();
            });
            
            // Close modal on escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    $('#aia-edit-style-modal').hide();
                    $('body').css('overflow', 'auto');
                }
            });
        });
        </script>
        <?php
    }
    
    private function handle_update_style() {
        $user_id = intval($_POST['user_id']);
        $tone = sanitize_text_field($_POST['tone']);
        $audience = sanitize_text_field($_POST['audience']);
        $rules_text = sanitize_textarea_field($_POST['writing_rules']);
        $rules = explode("\n", $rules_text);
        $rules = array_map('trim', $rules);
        $rules = array_filter($rules);
        
        $authors_manager = new AIA_Author_Style();
        
        if ($authors_manager->update_author_style($user_id, $tone, $audience, $rules)) {
            echo '<div class="notice notice-success"><p>Author style updated successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to update author style.</p></div>';
        }
        
        // Redirect to refresh the page
        echo '<script>window.location.href = "?page=ai-autoblog-authors";</script>';
    }
}