<?php
/**
* Plugin Name: AI Autoblog
* Plugin URI: https://your-site.com/ai-autoblog
* Description: Automated blog post generation using AI (Gemini/ChatGPT) with IndexNow support
* Version: 1.0.0
* Author: Your Name
* License: GPL v2 or later
* Text Domain: ai-autoblog
*/

// Prevent direct access
if ( !defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'AIA_VERSION', '1.0.0' );
define( 'AIA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIA_DATA_DIR', AIA_PLUGIN_DIR . 'data/' );

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
        $default_instructions = 'You are a professional SEO blog writer and web designer. Write a high-quality, beautifully designed blog post.

TOPIC: [Generated from keyword]
FOCUS KEYWORD: [Generated from keyword]

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
YOUR ROLE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

You are both a writer AND a designer. You create the complete HTML blog post with all styling included. The design should match the content tone and topic.

CONTENT PRIORITY:
1. Write engaging, human-like content first
2. Design should enhance and support the content
3. Different topics need different design approaches
4. The design should feel natural and appropriate

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
WRITING REQUIREMENTS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

SEO TITLE:
- Include the focus keyword naturally
- Make it curious and thought-provoking
- Under 60 characters

META DESCRIPTION:
- Clear, human-written description
- Use the focus keyword naturally
- Under 160 characters

CONTENT STRUCTURE:
- H1 title (compelling, includes keyword)
- Engaging introduction with a hook (NEVER start with definitions)
- 3 to 5 H2 sections flowing naturally
- Short paragraphs (2-4 lines max)
- Bullet points where they improve clarity
- At least one real-life example or relatable scenario
- 2-3 external resource links embedded naturally
- Occasional conversational questions to the reader
- Bold text ONLY for important insights

WRITING STYLE:
- Write like a real person sharing thoughts and experience
- Avoid AI tone, templates, or robotic structure
- Use simple, clear English
- Natural flow like speaking to a friend
- Mix short and long sentences
- Vary rhythm and tone across paragraphs
- Include personal opinion or judgment where relevant

ANTI-AI RULES:
- Avoid repetitive sentence patterns
- Avoid robotic or corporate tone
- Avoid generic filler phrases
- Avoid predictable transitions
- Keep tone conversational and slightly imperfect

SEO RULES:
- Use focus keyword 2-3 times naturally
- Once in introduction, 1-2 in body
- No keyword stuffing

STRICT RULES:
- Never use the long dash symbol (—)
- Replace with commas, periods, or sentence breaks
- ALL styling must use inline CSS within HTML elements
- DO NOT use <style>, <html>, <body>, or <head> tags
- Ensure all divs are properly closed

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
DESIGN GUIDELINES (YOU DECIDE THE STYLE)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

You have complete freedom to design each post uniquely. Here are suggestions based on content type:

FOR TECHNICAL/EDUCATIONAL CONTENT:
- Clean, structured layout
- Professional colors (blues, grays, navy)
- Clear hierarchy
- Boxed examples and code
- Minimal decorations

FOR PERSONAL/STORYTELLING CONTENT:
- Warmer colors (warm grays, creams, soft colors)
- More whitespace
- Relaxed typography
- Pull quotes for emphasis
- Friendly, approachable feel

FOR BUSINESS/PROFESSIONAL CONTENT:
- Sophisticated colors (dark blues, charcoal, white)
- Clean, organized structure
- Professional typography
- Subtle accents
- Trustworthy feel

FOR CREATIVE/INNOVATION CONTENT:
- Vibrant accent colors
- Playful elements
- Unique layouts
- Visual variety
- Bold statements

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
FEATURED IMAGE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Include a relevant image using:
<figure>
  <img src="https://picsum.photos/id/[1-100]/800/400" alt="Description" />
  <figcaption>Caption text</figcaption>
</figure>

Pick an image ID that matches your content theme.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
OUTPUT FORMAT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Return ONLY a valid JSON object:
{
    "seo_title": "Your SEO Title",
    "meta_description": "Your meta description",
    "content": "Complete HTML with ALL styling"
}

CRITICAL JSON RULES:
1. content field must be a SINGLE string (NO + concatenation)
2. Escape ALL double quotes inside content as \"
3. Represent newlines as \n
4. All HTML must be valid and closed
5. The content is the complete blog post HTML

Now create an amazing, uniquely designed blog post about the keyword.';

        file_put_contents($instructions_file, $default_instructions);
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
}

// Add AJAX nonce for admin.js
add_action('admin_enqueue_scripts', function() {
    wp_localize_script('aia-admin', 'aia_ajax', array(
        'nonce' => wp_create_nonce('aia_ajax_nonce')
    ));
});

// Remove authors page from menu
add_action('admin_menu', function() {
    remove_submenu_page('ai-autoblog', 'ai-autoblog-authors');
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