<?php
/**
* Plugin Name: Blog Autom
* Plugin URI: https://designzeros.com/blog-autom
* Description: Automated blog post generation using AI Gemini with IndexNow support
* Version: 5.6.0
* Author: Shahab Saqib
* License: GPL v2 or later
* Text Domain: designzeros.com
*/

// Prevent direct access
if ( !defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// OPTIONAL: Disable WP-Cron for system cron (UNCOMMENT IF USING SYSTEM CRON)
// If you enable this, you MUST set up a system cron job:
// */5 * * * * php /path/to/wordpress/wp-cron.php
// ============================================================
// define('DISABLE_WP_CRON', true);

// Define plugin constants
define( 'AIA_VERSION', '5.4.0' );
define( 'AIA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIA_DATA_DIR', AIA_PLUGIN_DIR . 'data/' );

// Register custom cron schedules immediately so they are available during
// plugin activation as well as normal plugin initialization.
function aia_register_cron_schedules( $schedules ) {
    $cron_hours = max( 1, min( 24, intval( get_option( 'aia_cron_interval_hours', 2 ) ) ) );
    $schedules['aia_custom_interval'] = array(
        'interval' => $cron_hours * HOUR_IN_SECONDS,
        'display'  => sprintf( __( 'Every %d hour(s) - Blog Autom', 'blog-autom' ), $cron_hours ),
    );
    $schedules['every_120_minutes'] = array(
        'interval' => 7200,
        'display'  => __( 'Every 120 Minutes (2 Hours)', 'blog-autom' ),
    );
    $schedules['every_60_minutes'] = array(
        'interval' => 3600,
        'display'  => __( 'Every 60 Minutes', 'blog-autom' ),
    );
    $schedules['every_30_minutes'] = array(
        'interval' => 1800,
        'display'  => __( 'Every 30 Minutes', 'blog-autom' ),
    );
    $schedules['every_5_minutes'] = array(
        'interval' => 300,
        'display'  => __( 'Every 5 Minutes', 'blog-autom' ),
    );
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display'  => __( 'Every Minute', 'blog-autom' ),
    );
    return $schedules;
}
add_filter( 'cron_schedules', 'aia_register_cron_schedules' );

// Initialize plugin
require_once AIA_PLUGIN_DIR . 'includes/class-plugin-init.php';

function aia_init_plugin() {
    $init = new AIA_Plugin_Init();
    $init->init();
}

add_action( 'plugins_loaded', 'aia_init_plugin' );

// Activation hook
register_activation_hook( __FILE__, 'aia_activate_plugin' );

function aia_activate_plugin() {
    // Create data directory if not exists
    if ( !file_exists( AIA_DATA_DIR ) ) {
        mkdir( AIA_DATA_DIR, 0755, true );
    }

    // Create default JSON files
    $default_files = [
        'system_prompts.json' => [
            'system_prompt' => 'You are an expert blog writer creating SEO-optimized content. Write in clear, engaging English. Focus on providing value to readers while maintaining search engine best practices.',
            'writing_rules' => [
                'use_active_voice',
                'avoid_repetition',
                'include_examples',
                'cite_sources'
            ]
        ],
        'blog_instructions.json' => [
            'seo_rules' => [
                'keyword_in_title' => true,
                'keyword_in_first_paragraph' => true,
                'keyword_density' => '1-2%',
                'min_words' => 800,
                'max_words' => 1500
            ],
            'structure' => [
                'introduction',
                'main_points',
                'examples',
                'conclusion'
            ]
        ],
        'keywords.json' => [],
        'external_links.json' => [
            'direct_links' => [],
            'sitemap_urls' => [],
            'sitemap_cache' => [],
            'last_sitemap_update' => null
        ],
        'runtime_state.json' => [
            'status' => 'idle',
            'last_processed' => null,
            'total_posts' => 0
        ]
    ];

    foreach ( $default_files as $filename => $content ) {
        $filepath = AIA_DATA_DIR . $filename;
        if ( !file_exists( $filepath ) ) {
            file_put_contents( $filepath, json_encode( $content, JSON_PRETTY_PRINT ) );
        }
    }

    // Create blog_instructions.txt - AI handles all design
    $instructions_file = AIA_DATA_DIR . 'blog_instructions.txt';
    if (!file_exists($instructions_file)) {
        $default_instructions = file_get_contents(__DIR__ . '/blog_instructions.txt');
        file_put_contents($instructions_file, $default_instructions);
    }

    // Generate async secret key
    if (!get_option('aia_async_secret')) {
        update_option('aia_async_secret', wp_generate_password(32, false));
    }

    // ==================== INDEXNOW SETUP ====================
    
    // Generate API keys if not already set
    if (!get_option('aia_console_bing_api_key')) {
        $bing_key = wp_generate_password(32, false);
        update_option('aia_console_bing_api_key', $bing_key);
    }
    
    if (!get_option('aia_console_google_api_key')) {
        $google_key = wp_generate_password(32, false);
        update_option('aia_console_google_api_key', $google_key);
    }
    
    // Set default settings
    if (!get_option('aia_console_enabled')) {
        update_option('aia_console_enabled', 1);
    }
    if (!get_option('aia_console_search_engine')) {
        update_option('aia_console_search_engine', 'both');
    }
    if (!get_option('aia_console_auto_submit')) {
        update_option('aia_console_auto_submit', 1);
    }
    
    // Automatic generation defaults. Manual generation is independent of these settings.
    if ( get_option( 'aia_cron_enabled', null ) === null ) {
        update_option( 'aia_cron_enabled', 1 );
    }
    if ( get_option( 'aia_cron_interval_hours', null ) === null ) {
        update_option( 'aia_cron_interval_hours', 2 );
    }

    // Schedule the cron events.
    wp_clear_scheduled_hook('aia_process_keywords');
    wp_clear_scheduled_hook('aia_sync_sitemaps');

    if ( get_option( 'aia_cron_enabled', 1 ) ) {
        wp_schedule_event(time() + 60, 'aia_custom_interval', 'aia_process_keywords');
    }
    wp_schedule_event(time() + 60, 'daily', 'aia_sync_sitemaps');
}

// Deactivation hook
register_deactivation_hook( __FILE__, 'aia_deactivate_plugin' );

function aia_deactivate_plugin() {
    // Clear scheduled cron events
    wp_clear_scheduled_hook('aia_process_keywords');
    wp_clear_scheduled_hook('aia_sync_sitemaps');
}

// Add AJAX nonce for admin.js
add_action('admin_enqueue_scripts', function() {
    wp_localize_script('aia-admin', 'aia_ajax', array(
        'nonce' => wp_create_nonce('aia_ajax_nonce')
    ));
});

// Remove authors page from menu
add_action('admin_menu', function() {
    remove_submenu_page('blog-autom', 'blog-autom-authors');
}, 99);

// Serve indexnow-key.txt from database
add_action('init', function() {
    if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/indexnow-key.txt') {
        // Use Bing API key as the primary key for verification
        $api_key = get_option('aia_console_bing_api_key', '');
        if (empty($api_key)) {
            $api_key = get_option('aia_console_google_api_key', '');
        }
        
        if (!empty($api_key)) {
            header('Content-Type: text/plain');
            header('Cache-Control: no-cache, must-revalidate');
            echo $api_key;
        } else {
            status_header(404);
            echo 'Not Found';
        }
        exit;
    }
});

// ============================================================
// SYSTEM CRON ENDPOINT (for use with system cron if WP-Cron is disabled)
// Usage: https://yourdomain.com/?aia_process_async=1&secret=YOUR_SECRET_KEY
// ============================================================
// The secret key is automatically generated and stored as 'aia_async_secret'
// You can see it in the WordPress options 