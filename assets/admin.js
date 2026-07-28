// assets/admin.js

jQuery(document).ready(function($) {
    // Confirm delete actions
    $('.aia-keywords-list form, .aia-authors-list form').on('submit', function(e) {
        if (!confirm('Are you sure you want to delete this item?')) {
            e.preventDefault();
            return false;
        }
    });
    
    // Toggle model rows based on provider selection
    $('#aia_ai_provider').on('change', function() {
        var provider = $(this).val();
        if (provider === 'gemini') {
            $('.gemini-models').show();
            $('.openai-models').hide();
            $('#aia_api_key').attr('placeholder', 'Enter your Gemini API key from Google AI Studio');
        } else {
            $('.gemini-models').hide();
            $('.openai-models').show();
            $('#aia_api_key').attr('placeholder', 'Enter your OpenAI API key from platform.openai.com');
        }
    });
    
    // Trigger initial state
    $('#aia_ai_provider').trigger('change');
    
    // Test API connection
    $('#aia_test_api').on('click', function() {
        var provider = $('#aia_ai_provider').val();
        var api_key = $('#aia_api_key').val();
        var model = provider === 'gemini' ? $('#aia_gemini_model').val() : $('#aia_openai_model').val();
        
        if (!api_key) {
            $('#aia_api_test_result')
                .removeClass()
                .addClass('error')
                .html('❌ Please enter your API key first.')
                .show();
            return;
        }
        
        $('#aia_api_test_result')
            .removeClass()
            .addClass('loading')
            .html('⏳ Testing connection...')
            .show();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'aia_test_api',
                provider: provider,
                api_key: api_key,
                model: model,
                nonce: aia_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#aia_api_test_result')
                        .removeClass()
                        .addClass('success')
                        .html('✅ ' + response.data.message)
                        .show();
                } else {
                    $('#aia_api_test_result')
                        .removeClass()
                        .addClass('error')
                        .html('❌ ' + response.data.message)
                        .show();
                }
            },
            error: function() {
                $('#aia_api_test_result')
                    .removeClass()
                    .addClass('error')
                    .html('❌ Connection failed. Please check your API key and try again.')
                    .show();
            }
        });
    });
    
    // Auto-refresh dashboard stats (every 30 seconds)
    if ($('.aia-dashboard').length) {
        setInterval(function() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'aia_refresh_stats',
                    nonce: aia_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('.stat-number').each(function(index) {
                            // Update stats dynamically
                        });
                    }
                }
            });
        }, 30000);
    }
    
    // Real-time keyword validation
    $('#keyword').on('input', function() {
        var keyword = $(this).val();
        if (keyword.length < 3) {
            $(this).css('border-color', '#f0ad4e');
        } else {
            $(this).css('border-color', '#5cb85c');
        }
    });
    
    // Form validation for author rules
    $('#writing_rules').on('input', function() {
        var rules = $(this).val();
        var lines = rules.split('\n');
        if (lines.length < 2) {
            $(this).css('border-color', '#f0ad4e');
            $(this).next('.description').text('Add at least 2 writing rules');
        } else {
            $(this).css('border-color', '#5cb85c');
            $(this).next('.description').text('Good! Multiple rules will help maintain consistency');
        }
    });
    
    // Display processing status
    function checkProcessingStatus() {
        if ($('.stat-status').hasClass('processing')) {
            setTimeout(checkProcessingStatus, 5000);
        }
    }
    
    checkProcessingStatus();
});