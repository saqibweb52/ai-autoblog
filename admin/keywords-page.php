<?php
// admin/keywords-page.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Keywords_Page {
    
    private $keywords_manager;
    private $authors_manager;
    private $items_per_page = 20;
    
    public function __construct() {
        $this->keywords_manager = new AIA_Keywords_Manager();
        $this->authors_manager = new AIA_Author_Style();
        add_action('admin_menu', array($this, 'add_submenu_page'));
    }
    
    public function add_submenu_page() {
        add_submenu_page(
            'blog-autom',
            'Keywords Manager',
            'Keywords',
            'manage_options',
            'blog-autom-keywords',
            array($this, 'render_page')
        );
    }
    
    public function render_page() {
        // Handle form submissions
        if (isset($_POST['aia_add_keywords'])) {
            $this->handle_add_keywords();
        }
        
        if (isset($_POST['aia_delete_keyword'])) {
            $this->handle_delete_keyword();
        }
        
        if (isset($_POST['aia_bulk_delete'])) {
            $this->handle_bulk_delete();
        }
        
        if (isset($_POST['aia_sync_authors'])) {
            $this->handle_sync_authors();
        }
        
        // Get search query
        $search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Get all keywords and sort by created_at (newest first)
        $all_keywords = $this->keywords_manager->get_all_keywords();
        
        // Filter by search query
        if (!empty($search_query)) {
            $all_keywords = array_filter($all_keywords, function($keyword) use ($search_query) {
                return stripos($keyword['keyword'], $search_query) !== false;
            });
        }
        
        // Sort by created_at (newest first)
        usort($all_keywords, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        $total_keywords = count($all_keywords);
        $total_pages = ceil($total_keywords / $this->items_per_page);
        
        // Get current page
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $this->items_per_page;
        $keywords = array_slice($all_keywords, $offset, $this->items_per_page, true);
        
        $authors = $this->authors_manager->get_all_authors();
        
        // Get all categories
        $categories = get_categories(array(
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        
        ?>
        <div class="wrap">
            <h1>Manage Keywords</h1>
            
            <?php if (!empty($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($_GET['updated']); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="aia-add-keyword-form">
                <h2>Add New Keywords</h2>
                <form method="post">
                    <table class="form-table">
                        <tr>
                            <th><label for="keywords">Keywords</label></th>
                            <td>
                                <textarea name="keywords" id="keywords" class="large-text" rows="8" required placeholder="Enter keywords separated by commas&#10;Example: wordpress tips, seo guide, content marketing, blog writing"></textarea>
                                <p class="description">
                                    Enter multiple keywords separated by commas. Each keyword will be added as a separate entry.
                                    <br>Maximum 500 keywords per batch.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="author_id">Assign Author (All Keywords)</label></th>
                            <td>
                                <select name="author_id" id="author_id" required>
                                    <option value="">Select Author</option>
                                    <?php foreach ($authors as $author): ?>
                                        <option value="<?php echo esc_attr($author['author_id']); ?>">
                                            <?php echo esc_html($author['name']); ?> 
                                            (ID: <?php echo esc_html($author['author_id']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">All keywords will be assigned to this author</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Assign Categories (Optional)</label></th>
                            <td>
                                <div class="aia-category-grid">
                                    <?php if (!empty($categories)): ?>
                                        <?php foreach ($categories as $category): ?>
                                            <label class="aia-category-checkbox">
                                                <input type="checkbox" name="categories[]" value="<?php echo esc_attr($category->term_id); ?>">
                                                <span class="aia-cat-name"><?php echo esc_html($category->name); ?></span>
                                                <span class="aia-cat-count">(<?php echo $category->count; ?>)</span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="description">No categories found. Please create categories first.</p>
                                    <?php endif; ?>
                                </div>
                                <p class="description">Select categories to assign to all keywords (optional).</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Add Keywords', 'primary', 'aia_add_keywords'); ?>
                </form>
            </div>
            
            <div class="aia-keywords-list">
                <h2>Current Keywords (<?php echo $total_keywords; ?>)</h2>
                
                <!-- Search Form -->
                <div class="aia-search-box">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="blog-autom-keywords">
                        <input type="text" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="Search keywords..." class="aia-search-input">
                        <button type="submit" class="button">Search</button>
                        <?php if (!empty($search_query)): ?>
                            <a href="?page=blog-autom-keywords" class="button">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <?php if (!empty($keywords)): ?>
                    <form method="post" id="aia-bulk-delete-form">
                        <div class="aia-bulk-actions">
                            <select name="bulk_action" id="bulk_action">
                                <option value="">Bulk Actions</option>
                                <option value="delete">Delete Selected</option>
                            </select>
                            <button type="submit" name="aia_bulk_delete" class="button button-secondary" onclick="return confirmBulkDelete();">
                                Apply
                            </button>
                            <span class="description" style="margin-left: 10px;">
                                <span id="selected-count">0</span> keyword(s) selected
                            </span>
                        </div>
                        
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th width="40">
                                        <input type="checkbox" id="select-all" onclick="toggleAllCheckboxes(this);">
                                    </th>
                                    <th width="50">#</th>
                                    <th>Keyword</th>
                                    <th>Author</th>
                                    <th>Categories</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th width="100">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $counter = $offset + 1; ?>
                                <?php foreach ($keywords as $keyword): 
                                    $author = $this->authors_manager->get_author_by_id($keyword['author_id']);
                                    $author_name = $author ? $author['name'] : 'Unknown';
                                    
                                    // Get categories for this keyword
                                    $keyword_categories = isset($keyword['categories']) ? $keyword['categories'] : array();
                                    $category_names = array();
                                    if (!empty($keyword_categories)) {
                                        foreach ($keyword_categories as $cat_id) {
                                            $cat = get_term($cat_id, 'category');
                                            if ($cat && !is_wp_error($cat)) {
                                                $category_names[] = $cat->name;
                                            }
                                        }
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="delete_ids[]" value="<?php echo esc_attr($keyword['id']); ?>" class="keyword-checkbox" onchange="updateSelectedCount();">
                                        </td>
                                        <td><?php echo $counter++; ?></td>
                                        <td><strong><?php echo esc_html($keyword['keyword']); ?></strong></td>
                                        <td>
                                            <?php echo esc_html($author_name); ?>
                                            <span class="aia-user-id">(ID: <?php echo esc_html($keyword['author_id']); ?>)</span>
                                        </td>
                                        <td>
                                            <?php if (!empty($category_names)): ?>
                                                <div class="aia-category-tags">
                                                    <?php foreach ($category_names as $cat_name): ?>
                                                        <span class="aia-category-tag"><?php echo esc_html($cat_name); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="aia-no-categories">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="aia-status aia-status-<?php echo esc_attr($keyword['status']); ?>">
                                                <?php echo ucfirst(esc_html($keyword['status'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html($keyword['created_at']); ?></td>
                                        <td>
                                            <?php if ($keyword['status'] === 'pending'): ?>
                                                <button
                                                    type="button"
                                                    class="button button-small aia-generate-keyword"
                                                    data-keyword-id="<?php echo esc_attr($keyword['id']); ?>"
                                                >
                                                    Generate
                                                </button>
                                            <?php elseif ($keyword['status'] === 'processing'): ?>
                                                <button type="button" class="button button-small" disabled>
                                                    Processing...
                                                </button>
                                            <?php endif; ?>

                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="delete_id" value="<?php echo esc_attr($keyword['id']); ?>">
                                                <button type="submit" name="aia_delete_keyword" class="button button-small delete" onclick="return confirm('Delete this keyword?');">
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                    
                    <?php if ($total_pages > 1): ?>
                        <div class="aia-pagination">
                            <div class="tablenav">
                                <div class="tablenav-pages">
                                    <span class="displaying-num"><?php echo $total_keywords; ?> items</span>
                                    <span class="pagination-links">
                                        <?php if ($current_page > 1): ?>
                                            <a class="first-page button" href="<?php echo add_query_arg(array('paged' => 1, 's' => $search_query)); ?>">&laquo;</a>
                                            <a class="prev-page button" href="<?php echo add_query_arg(array('paged' => $current_page - 1, 's' => $search_query)); ?>">&lsaquo;</a>
                                        <?php else: ?>
                                            <span class="button disabled">&laquo;</span>
                                            <span class="button disabled">&lsaquo;</span>
                                        <?php endif; ?>
                                        
                                        <span class="paging-input">
                                            <span class="total-pages"><?php echo $current_page; ?> of <?php echo $total_pages; ?></span>
                                        </span>
                                        
                                        <?php if ($current_page < $total_pages): ?>
                                            <a class="next-page button" href="<?php echo add_query_arg(array('paged' => $current_page + 1, 's' => $search_query)); ?>">&rsaquo;</a>
                                            <a class="last-page button" href="<?php echo add_query_arg(array('paged' => $total_pages, 's' => $search_query)); ?>">&raquo;</a>
                                        <?php else: ?>
                                            <span class="button disabled">&rsaquo;</span>
                                            <span class="button disabled">&raquo;</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <p>No keywords found. <?php if (!empty($search_query)): ?>Try a different search term or <a href="?page=blog-autom-keywords">clear the search</a>.<?php else: ?>Add keywords using the form above.<?php endif; ?></p>
                <?php endif; ?>
            </div>
            
            <div class="aia-sync-section">
                <form method="post">
                    <button type="submit" name="aia_sync_authors" class="button button-secondary">
                        Sync Authors with WordPress Users
                    </button>
                    <p class="description">Add new WordPress users with publishing rights to authors list</p>
                </form>
            </div>
        </div>
        
        <style>
            /* ===== Status Styles ===== */
            .aia-status {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
            }
            .aia-status-pending { background: #f0ad4e; color: #fff; }
            .aia-status-processing { background: #5bc0de; color: #fff; }
            .aia-status-done { background: #5cb85c; color: #fff; }
            
            /* ===== Keyword Form ===== */
            .aia-add-keyword-form,
            .aia-keywords-list {
                background: #fff;
                padding: 20px;
                margin: 20px 0;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            .aia-add-keyword-form textarea {
                font-family: monospace;
                width: 100%;
                max-width: 600px;
            }
            
            .aia-add-keyword-form textarea:focus {
                border-color: #2271b1;
                box-shadow: 0 0 0 1px #2271b1;
            }
            
            /* ===== Category Grid ===== */
            .aia-category-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
                gap: 8px;
                padding: 10px;
                background: #f8f9fa;
                border-radius: 4px;
                border: 1px solid #ddd;
                max-height: 300px;
                overflow-y: auto;
            }
            
            .aia-category-checkbox {
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 4px 8px;
                border-radius: 3px;
                cursor: pointer;
                transition: background 0.2s;
                font-size: 13px;
                white-space: nowrap;
            }
            
            .aia-category-checkbox:hover {
                background: #e9ecef;
            }
            
            .aia-category-checkbox input[type="checkbox"] {
                margin: 0;
                flex-shrink: 0;
            }
            
            .aia-cat-name {
                font-weight: 500;
                color: #333;
            }
            
            .aia-cat-count {
                font-size: 11px;
                color: #999;
            }
            
            /* ===== Search Box ===== */
            .aia-search-box {
                margin-bottom: 15px;
                padding: 10px 0;
            }
            
            .aia-search-box form {
                display: flex;
                gap: 10px;
                align-items: center;
                flex-wrap: wrap;
            }
            
            .aia-search-input {
                padding: 6px 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                min-width: 250px;
                font-size: 14px;
            }
            
            .aia-search-input:focus {
                border-color: #2271b1;
                box-shadow: 0 0 0 1px #2271b1;
            }
            
            /* ===== Category Tags ===== */
            .aia-category-tags {
                display: flex;
                flex-wrap: wrap;
                gap: 4px;
            }
            
            .aia-category-tag {
                display: inline-block;
                background: #e9ecef;
                padding: 1px 8px;
                border-radius: 10px;
                font-size: 11px;
                color: #333;
                white-space: nowrap;
            }
            
            .aia-no-categories {
                color: #999;
                font-style: italic;
                font-size: 12px;
            }
            
            /* ===== Bulk Actions ===== */
            .aia-bulk-actions {
                margin-bottom: 15px;
                padding: 10px;
                background: #f8f9fa;
                border-radius: 4px;
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }
            
            .aia-bulk-actions select {
                min-width: 150px;
            }
            
            #selected-count {
                font-weight: bold;
                color: #2271b1;
            }
            
            /* ===== Pagination ===== */
            .aia-pagination {
                margin-top: 20px;
                padding: 10px 0;
            }
            
            .aia-pagination .tablenav {
                display: flex;
                justify-content: flex-end;
                align-items: center;
            }
            
            .aia-pagination .tablenav-pages {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .aia-pagination .pagination-links {
                display: flex;
                align-items: center;
                gap: 5px;
            }
            
            .aia-pagination .button {
                min-width: 30px;
                text-align: center;
                padding: 0 8px;
            }
            
            .aia-pagination .button.disabled {
                opacity: 0.5;
                pointer-events: none;
            }
            
            .aia-pagination .paging-input {
                padding: 0 10px;
            }
            
            .aia-pagination .displaying-num {
                color: #50575e;
                font-size: 13px;
            }
            
            /* ===== Table ===== */
            .wp-list-table .delete {
                color: #dc3232;
            }
            
            .wp-list-table .delete:hover {
                color: #b71c1c;
            }
            
            .wp-list-table th#cb {
                width: 40px;
            }
            
            .wp-list-table .check-column {
                padding: 8px 0 8px 8px;
            }
            
            .aia-user-id {
                font-size: 11px;
                color: #999;
            }
            
            /* ===== Sync Section ===== */
            .aia-sync-section {
                background: #f8f9fa;
                padding: 15px 20px;
                margin: 20px 0;
                border-radius: 8px;
            }
            
            /* ===== Responsive ===== */
            @media screen and (max-width: 782px) {
                .aia-category-grid {
                    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                    max-height: 200px;
                }
                
                .aia-search-input {
                    min-width: 150px;
                    width: 100%;
                }
                
                .aia-search-box form {
                    flex-direction: column;
                    align-items: stretch;
                }
                
                .aia-bulk-actions {
                    flex-direction: column;
                    align-items: stretch;
                }
                
                .aia-bulk-actions select {
                    width: 100%;
                }
                
                .wp-list-table .aia-category-tags {
                    flex-direction: column;
                    gap: 2px;
                }
                
                .aia-pagination .tablenav {
                    flex-direction: column;
                    align-items: center;
                }
                
                .aia-pagination .tablenav-pages {
                    flex-wrap: wrap;
                    justify-content: center;
                }
            }
            
            @media screen and (max-width: 480px) {
                .aia-category-grid {
                    grid-template-columns: 1fr 1fr;
                    max-height: 150px;
                }
                
                .aia-category-checkbox {
                    font-size: 12px;
                    padding: 2px 4px;
                }
                
                .wp-list-table th,
                .wp-list-table td {
                    font-size: 12px;
                }
            }
        </style>
        
        <script>
        function toggleAllCheckboxes(masterCheckbox) {
            var checkboxes = document.getElementsByClassName('keyword-checkbox');
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = masterCheckbox.checked;
            }
            updateSelectedCount();
        }
        
        function updateSelectedCount() {
            var checkboxes = document.getElementsByClassName('keyword-checkbox');
            var checkedCount = 0;
            for (var i = 0; i < checkboxes.length; i++) {
                if (checkboxes[i].checked) {
                    checkedCount++;
                }
            }
            document.getElementById('selected-count').textContent = checkedCount;
        }
        
        function confirmBulkDelete() {
            var action = document.getElementById('bulk_action').value;
            if (action !== 'delete') {
                alert('Please select a bulk action.');
                return false;
            }
            
            var checkboxes = document.getElementsByClassName('keyword-checkbox');
            var checkedCount = 0;
            for (var i = 0; i < checkboxes.length; i++) {
                if (checkboxes[i].checked) {
                    checkedCount++;
                }
            }
            
            if (checkedCount === 0) {
                alert('Please select at least one keyword to delete.');
                return false;
            }
            
            return confirm('Are you sure you want to delete ' + checkedCount + ' selected keyword(s)? This action cannot be undone.');
        }
        
        jQuery(document).ready(function($) {
            $('.aia-generate-keyword').on('click', function() {
                var button = $(this);
                var keywordId = button.data('keyword-id');

                if (!keywordId) {
                    alert('Invalid keyword.');
                    return;
                }

                if (!confirm('Start generating a post for this keyword now?')) {
                    return;
                }

                button.prop('disabled', true).text('Generating...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aia_generate_keyword',
                        keyword_id: keywordId,
                        nonce: '<?php echo esc_js(wp_create_nonce('aia_generate_keyword')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            button.text('Generated!');
                            setTimeout(function() {
                                window.location.reload();
                            }, 700);
                        } else {
                            var message = response.data && response.data.message
                                ? response.data.message
                                : 'Could not start generation.';
                            alert(message);
                            button.prop('disabled', false).text('Generate');
                        }
                    },
                    error: function(xhr) {
                        var message = 'Could not generate the post.';
                        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            message = xhr.responseJSON.data.message;
                        } else if (xhr.responseText) {
                            try {
                                var parsed = JSON.parse(xhr.responseText);
                                if (parsed.data && parsed.data.message) message = parsed.data.message;
                            } catch (e) {}
                        }
                        alert(message);
                        button.prop('disabled', false).text('Generate');
                    }
                });
            });

            $('#keywords').on('keydown', function(e) {
                if (e.ctrlKey && e.keyCode === 13) {
                    $('form[method="post"]').first().submit();
                }
            });
            
            $('#keywords').on('input', function() {
                var text = $(this).val();
                var count = text.split(',').filter(function(k) { 
                    return k.trim() !== ''; 
                }).length;
                
                $('.aia-keyword-count').remove();
                if (count > 0) {
                    var notice = $('<p class="description aia-keyword-count">' + count + ' keyword(s) will be added</p>');
                    $(this).closest('td').append(notice);
                    
                    if (count > 500) {
                        notice.css('color', '#dc3232');
                        notice.text('⚠️ ' + count + ' keywords exceeds the limit of 500. Please reduce the number.');
                    } else {
                        notice.css('color', '#46b450');
                    }
                }
            });
        });
        </script>
        <?php
    }
    
    private function handle_sync_authors() {
        $authors_manager = new AIA_Author_Style();
        if ($authors_manager->sync_with_wordpress_users()) {
            echo '<div class="notice notice-success"><p>Authors synced successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to sync authors. Check logs for details.</p></div>';
        }
    }
    
    private function handle_add_keywords() {
        if (!isset($_POST['keywords']) || !isset($_POST['author_id'])) {
            return;
        }
        
        $keywords_input = sanitize_textarea_field($_POST['keywords']);
        $author_id = intval($_POST['author_id']);
        $selected_categories = isset($_POST['categories']) ? array_map('intval', $_POST['categories']) : array();
        
        $authors_manager = new AIA_Author_Style();
        $author = $authors_manager->get_author_by_id($author_id);
        if (!$author) {
            echo '<div class="notice notice-error"><p>Invalid author selected.</p></div>';
            return;
        }
        
        $keyword_array = array_map('trim', explode(',', $keywords_input));
        $keyword_array = array_filter($keyword_array, function($k) {
            return !empty($k);
        });
        
        if (empty($keyword_array)) {
            echo '<div class="notice notice-error"><p>No valid keywords found. Please enter at least one keyword.</p></div>';
            return;
        }
        
        if (count($keyword_array) > 500) {
            echo '<div class="notice notice-error"><p>Maximum 500 keywords per batch. You entered ' . count($keyword_array) . ' keywords.</p></div>';
            return;
        }
        
        $added = 0;
        $failed = 0;
        $errors = array();
        
        foreach ($keyword_array as $keyword) {
            if ($this->keywords_manager->add_keyword($keyword, $author_id, $selected_categories)) {
                $added++;
            } else {
                $failed++;
                $errors[] = $keyword;
            }
        }
        
        if ($added > 0 && $failed === 0) {
            echo '<div class="notice notice-success"><p>Successfully added ' . $added . ' keyword(s)!</p></div>';
        } elseif ($added > 0 && $failed > 0) {
            echo '<div class="notice notice-warning"><p>Added ' . $added . ' keyword(s). Failed to add ' . $failed . ' keyword(s): ' . esc_html(implode(', ', $errors)) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to add keywords. Please try again.</p></div>';
        }
    }
    
    private function handle_delete_keyword() {
        if (isset($_POST['delete_id'])) {
            $keyword_id = sanitize_text_field($_POST['delete_id']);
            
            if ($this->keywords_manager->delete_keyword($keyword_id)) {
                echo '<div class="notice notice-success"><p>Keyword deleted successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to delete keyword. Please check file permissions.</p></div>';
            }
        }
    }
    
    private function handle_bulk_delete() {
        if (!isset($_POST['delete_ids']) || !is_array($_POST['delete_ids'])) {
            echo '<div class="notice notice-error"><p>No keywords selected for deletion.</p></div>';
            return;
        }
        
        $ids = array_map('sanitize_text_field', $_POST['delete_ids']);
        
        $deleted = 0;
        $failed = 0;
        
        foreach ($ids as $keyword_id) {
            if ($this->keywords_manager->delete_keyword($keyword_id)) {
                $deleted++;
            } else {
                $failed++;
            }
        }
        
        if ($deleted > 0 && $failed === 0) {
            echo '<div class="notice notice-success"><p>Successfully deleted ' . $deleted . ' keyword(s)!</p></div>';
        } elseif ($deleted > 0 && $failed > 0) {
            echo '<div class="notice notice-warning"><p>Deleted ' . $deleted . ' keyword(s). Failed to delete ' . $failed . ' keyword(s).</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to delete keywords.</p></div>';
        }
    }
}