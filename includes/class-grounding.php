<?php
// includes/class-grounding.php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}

class AIA_Grounding_System {

    private $ai_provider;
    private $api_key;
    private $model;
    private $enable_grounding;

    public function __construct() {
        $this->ai_provider = get_option( 'aia_ai_provider', 'gemini' );
        $this->api_key = $this->get_api_key_for_provider();
        $this->model = $this->get_model_for_provider();
        $this->enable_grounding = get_option( 'aia_enable_grounding', 0 );
    }

    private function get_api_key_for_provider() {
        $provider = get_option( 'aia_ai_provider', 'gemini' );
        if ( $provider === 'gemini' ) {
            return get_option( 'aia_api_key', '' );
        } elseif ( $provider === 'glm' ) {
            return get_option( 'aia_glm_api_key', '' );
        }
        return '';
    }

    private function get_model_for_provider() {
        $provider = get_option( 'aia_ai_provider', 'gemini' );
        if ( $provider === 'gemini' ) {
            return get_option( 'aia_gemini_model', 'gemini-2.0-flash' );
        } elseif ( $provider === 'glm' ) {
            return get_option( 'aia_glm_model', 'glm-4-flash' );
        }
        return '';
    }

    public function research_topic( $keyword ) {
        $prompt = $this->build_research_prompt( $keyword );

        $response = $this->call_ai( $prompt );

        if ( $response && isset( $response[ 'success' ] ) && $response[ 'success' ] === true ) {
            // Parse the JSON response using a more robust method
            $parsed_content = $this->parse_json_response_robust( $response[ 'content' ] );
            
            $logger = new AIA_Logger();
            $logger->log( "Parsed content: seo_title=" . ($parsed_content['seo_title'] ?? 'NOT SET') . ", content_length=" . strlen($parsed_content['content'] ?? ''), 'debug' );
            
            if ( $parsed_content && isset($parsed_content['content']) && !empty($parsed_content['content']) ) {
                return [
                    'facts' => $this->extract_facts( $parsed_content ),
                    'summaries' => $parsed_content,
                    'insights' => $this->extract_insights( $parsed_content ),
                    'key_points' => $this->extract_key_points( $parsed_content ),
                    'sources' => $this->extract_sources( $response )
                ];
            }
            
            // Fallback: return raw content
            return [
                'facts' => $this->extract_facts( $response[ 'content' ] ),
                'summaries' => [ 'content' => $response[ 'content' ] ],
                'insights' => $this->extract_insights( $response[ 'content' ] ),
                'key_points' => $this->extract_key_points( $response[ 'content' ] ),
                'sources' => $this->extract_sources( $response )
            ];
        }

        $logger = new AIA_Logger();
        $error_message = isset( $response[ 'message' ] ) ? $response[ 'message' ] : 'Unknown error';
        $logger->log( "Research failed for keyword '{$keyword}'. Error: {$error_message}", 'error' );

        return false;
    }

    private function build_research_prompt( $keyword ) {
        $blog_instructions = $this->get_blog_instructions();
        $blog_instructions = str_replace( '[Generated from keyword]', $keyword, $blog_instructions );
        
        $json_requirement = "

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
CRITICAL OUTPUT FORMAT - READ CAREFULLY
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

You MUST return ONLY a valid JSON object with EXACTLY these three fields:

{
    \"seo_title\": \"Your SEO title here (under 60 characters)\",
    \"meta_description\": \"Your meta description here (under 160 characters)\",
    \"content\": \"THE COMPLETE HTML BLOG POST CONTENT HERE\"
}

IMPORTANT RULES FOR THE CONTENT FIELD:
1. The content field contains HTML with MANY double quotes for style attributes
2. You MUST escape ALL double quotes inside the content as \"
3. Example: style=\\\"color:red;\\\" becomes style=\\\"color:red;\\\"
4. Use \n for newlines inside the content
5. DO NOT include any text outside the JSON object
6. DO NOT include markdown code fences (```json)

Remember: Escape EVERY double quote inside the content field!";

        if ( $this->enable_grounding && $this->ai_provider === 'gemini' ) {
            $blog_instructions .= "\n\nUse Google Search to find the most recent and accurate information about the topic. Include specific facts, statistics, and real-world examples.";
        }

        return [
            'system' => $blog_instructions . "\n\n" . $json_requirement,
            'user' => "Write a complete blog post about: " . $keyword
        ];
    }

    private function get_blog_instructions() {
        $file = AIA_DATA_DIR . 'blog_instructions.txt';

        if ( file_exists( $file ) ) {
            $content = file_get_contents( $file );
            if ( $content !== false && !empty( $content ) ) {
                return trim( $content );
            }
        }

        return "You are a professional SEO blog writer. Write a high-quality blog post with proper HTML formatting.";
    }
    
    /**
     * ROBUST JSON PARSING - Handles unescaped quotes in content
     */
    private function parse_json_response_robust( $content ) {
        // Remove markdown code fences
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = trim($content);
        
        $logger = new AIA_Logger();
        $logger->log( "Parsing JSON response. Length: " . strlen($content), 'debug' );
        
        // METHOD 1: Try direct JSON decode
        $data = json_decode($content, true);
        if ($data && isset($data['seo_title']) && isset($data['content'])) {
            $logger->log( "JSON parsed successfully via direct decode", 'debug' );
            return $this->clean_parsed_data($data);
        }
        
        // METHOD 2: Try to extract using custom parsing (more robust)
        $parsed = $this->parse_json_custom($content);
        if ($parsed && isset($parsed['content'])) {
            $logger->log( "JSON parsed successfully via custom parser", 'debug' );
            return $parsed;
        }
        
        // METHOD 3: Try to fix and parse with json_decode
        $fixed = $this->fix_json_for_parsing($content);
        if ($fixed) {
            $data = json_decode($fixed, true);
            if ($data && isset($data['seo_title']) && isset($data['content'])) {
                $logger->log( "JSON parsed successfully after fixing", 'debug' );
                return $this->clean_parsed_data($data);
            }
        }
        
        $logger->log( "All JSON parsing attempts failed", 'debug' );
        return null;
    }
    
    /**
     * Custom JSON parser that handles unescaped quotes in the content field
     */
    private function parse_json_custom($content) {
        $result = [
            'seo_title' => '',
            'meta_description' => '',
            'content' => ''
        ];
        
        // Extract seo_title
        if (preg_match('/"seo_title"\s*:\s*"([^"]*)"/', $content, $matches)) {
            $result['seo_title'] = stripslashes(trim($matches[1]));
        }
        
        // Extract meta_description
        if (preg_match('/"meta_description"\s*:\s*"([^"]*)"/', $content, $matches)) {
            $result['meta_description'] = stripslashes(trim($matches[1]));
        }
        
        // Extract content - find the content field and extract everything until the closing brace
        // Find the position of "content": "
        $content_start = strpos($content, '"content"');
        if ($content_start === false) {
            return null;
        }
        
        // Find the colon after "content"
        $colon_pos = strpos($content, ':', $content_start);
        if ($colon_pos === false) {
            return null;
        }
        
        // Find the first quote after the colon
        $quote_start = strpos($content, '"', $colon_pos + 1);
        if ($quote_start === false) {
            return null;
        }
        
        // Now we need to find the matching closing quote for the content
        // The content contains HTML with many quotes, so we need to be careful
        $content_string = '';
        $in_quote = false;
        $escape = false;
        $brace_count = 0;
        $start_pos = $quote_start + 1;
        
        // We'll look for the pattern: "content": " ... " }
        // The content ends when we find a closing quote that is not escaped,
        // followed by } (with possible whitespace)
        
        $length = strlen($content);
        $found_end = false;
        
        for ($i = $start_pos; $i < $length; $i++) {
            $char = $content[$i];
            
            if ($escape) {
                $escape = false;
                $content_string .= $char;
                continue;
            }
            
            if ($char === '\\') {
                $escape = true;
                $content_string .= $char;
                continue;
            }
            
            if ($char === '"') {
                // Check if this is the end of the content field
                // Look ahead to see if there's a } after the quote
                $remaining = substr($content, $i + 1);
                $remaining_trimmed = ltrim($remaining);
                if (strpos($remaining_trimmed, '}') === 0) {
                    // Found the end of the content field
                    $found_end = true;
                    break;
                }
                $content_string .= $char;
                continue;
            }
            
            $content_string .= $char;
        }
        
        if (!$found_end) {
            // Try alternative: find the last quote before the closing brace
            $last_brace_pos = strrpos($content, '}');
            if ($last_brace_pos !== false) {
                $content_before_brace = substr($content, 0, $last_brace_pos);
                $last_quote_pos = strrpos($content_before_brace, '"');
                if ($last_quote_pos !== false) {
                    $content_string = substr($content, $start_pos, $last_quote_pos - $start_pos);
                    $found_end = true;
                }
            }
        }
        
        if ($found_end) {
            // Clean the content
            $result['content'] = stripslashes($content_string);
            $result['content'] = str_replace('\n', "\n", $result['content']);
            $result['content'] = str_replace('\"', '"', $result['content']);
            $result['content'] = str_replace('\t', "\t", $result['content']);
        }
        
        // If content is still empty, try another approach
        if (empty($result['content'])) {
            // Try to find the content using a more aggressive regex
            if (preg_match('/"content"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $content, $matches)) {
                $result['content'] = stripslashes($matches[1]);
                $result['content'] = str_replace('\n', "\n", $result['content']);
                $result['content'] = str_replace('\"', '"', $result['content']);
                $result['content'] = str_replace('\t', "\t", $result['content']);
            }
        }
        
        // If we have content, return the result
        if (!empty($result['content'])) {
            // Clean the content
            $result['content'] = $this->clean_content_string($result['content']);
            return $result;
        }
        
        return null;
    }
    
    /**
     * Fix JSON for parsing by properly escaping quotes in the content
     */
    private function fix_json_for_parsing($content) {
        // This is a complex operation - try to extract and fix the content field
        // Find the content field
        if (preg_match('/"content"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $content, $matches)) {
            $content_value = $matches[1];
            // Escape any unescaped double quotes in the content
            $content_value = str_replace('"', '\"', $content_value);
            // Replace the content in the original
            $fixed = str_replace($matches[1], $content_value, $content);
            return $fixed;
        }
        return $content;
    }
    
    private function clean_content_string($content) {
        // Remove any escaped newlines
        $content = str_replace('\\n', "\n", $content);
        $content = str_replace('\n', "\n", $content);
        $content = str_replace('\\"', '"', $content);
        $content = str_replace('\"', '"', $content);
        $content = str_replace('\\t', "\t", $content);
        $content = str_replace('\t', "\t", $content);
        $content = str_replace('\\/', '/', $content);
        $content = str_replace('\/', '/', $content);
        
        // Remove any remaining backslashes
        $content = stripslashes($content);
        
        return trim($content);
    }
    
    private function clean_parsed_data($data) {
        if (isset($data['seo_title'])) {
            $data['seo_title'] = stripslashes(trim($data['seo_title']));
        }
        
        if (isset($data['meta_description'])) {
            $data['meta_description'] = stripslashes(trim($data['meta_description']));
        }
        
        if (isset($data['content'])) {
            $data['content'] = $this->clean_content_string($data['content']);
        }
        
        return $data;
    }
    
    private function call_ai( $prompt ) {
        if ( empty( $this->api_key ) ) {
            $logger = new AIA_Logger();
            $logger->log( "API key is empty. Cannot call AI.", 'error' );
            return array( 'success' => false, 'content' => '', 'message' => 'API key is empty' );
        }
        
        if ( $this->ai_provider === 'gemini' ) {
            return $this->call_gemini( $prompt );
        } elseif ( $this->ai_provider === 'glm' ) {
            return $this->call_glm( $prompt );
        } else {
            return array( 'success' => false, 'content' => '', 'message' => 'Invalid AI provider' );
        }
    }
    
    /**
     * Call GLM (Zhipu AI) API
     */
    private function call_glm( $prompt ) {
        $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
        
        $messages = array(
            array( 'role' => 'system', 'content' => $prompt['system'] ),
            array( 'role' => 'user', 'content' => $prompt['user'] )
        );
        
        $body = array(
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 8000,
            'top_p' => 0.95
        );
        
        $response = wp_remote_post( $url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ),
            'body' => json_encode( $body ),
            'timeout' => 180
        ) );
        
        if ( is_wp_error( $response ) ) {
            $error_message = 'GLM API Error: ' . $response->get_error_message();
            $this->log_error( $error_message );
            return array( 'success' => false, 'content' => '', 'message' => $error_message );
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( isset( $data['error'] ) ) {
            $error_message = 'GLM Error: ' . ( $data['error']['message'] ?? 'Unknown error' );
            $this->log_error( $error_message );
            return array( 'success' => false, 'content' => '', 'message' => $error_message );
        }
        
        if ( isset( $data['choices'][0]['message']['content'] ) ) {
            return array(
                'success' => true,
                'content' => $data['choices'][0]['message']['content']
            );
        }
        
        $error_message = 'Unexpected response from GLM API';
        $this->log_error( $error_message );
        return array( 'success' => false, 'content' => '', 'message' => $error_message );
    }
    
    private function call_gemini( $prompt ) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->api_key}";
        
        $full_prompt = $prompt['system'] . "\n\n" . $prompt['user'];
        
        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array( 'text' => $full_prompt )
                    )
                )
            ),
            'generationConfig' => array(
                'temperature' => 0.7,
                'maxOutputTokens' => 8192,
                'topP' => 0.95,
                'topK' => 40
            )
        );
        
        if ( $this->enable_grounding && ( strpos( $this->model, '2.0' ) !== false || strpos( $this->model, '2.5' ) !== false ) ) {
            $body['tools'] = array(
                array( 'googleSearch' => new stdClass() )
            );
        }
        
        $response = wp_remote_post( $url, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body' => json_encode( $body ),
            'timeout' => 180
        ) );
        
        if ( is_wp_error( $response ) ) {
            $error_message = 'Gemini API Error: ' . $response->get_error_message();
            $this->log_error( $error_message );
            return array( 'success' => false, 'content' => '', 'message' => $error_message );
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( isset( $data['error'] ) ) {
            $error_message = 'Gemini Error: ' . ( $data['error']['message'] ?? 'Unknown error' );
            $this->log_error( $error_message );
            return array( 'success' => false, 'content' => '', 'message' => $error_message );
        }
        
        if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
            $content = $data['candidates'][0]['content']['parts'][0]['text'];
            
            $result = array(
                'success' => true,
                'content' => $content
            );
            
            if ( isset( $data['candidates'][0]['groundingMetadata'] ) ) {
                $result['sources'] = $data['candidates'][0]['groundingMetadata'];
            }
            
            return $result;
        }
        
        $error_message = 'Unexpected response from Gemini API';
        $this->log_error( $error_message );
        return array( 'success' => false, 'content' => '', 'message' => $error_message );
    }
    
    private function extract_facts( $content ) {
        $facts = array();
        
        if ( is_array( $content ) && isset($content['content']) ) {
            $text = $content['content'];
            $lines = explode( "\n", strip_tags( $text ) );
        } else {
            $lines = explode( "\n", strip_tags( $content ) );
        }
        
        foreach ( $lines as $line ) {
            $trimmed = trim($line);
            if ( !empty($trimmed) && (strpos( $line, '•' ) !== false || strpos( $line, '-' ) !== false || 
                strpos( $line, 'fact' ) !== false || strpos( $line, 'statistic' ) !== false || 
                strpos( $line, '%' ) !== false || strpos( $line, 'billion' ) !== false ||
                strpos( $line, 'million' ) !== false || preg_match('/\d+/', $line) ) ) {
                $facts[] = trim( $line );
            }
        }
        
        if ( empty( $facts ) ) {
            $text = is_array( $content ) && isset($content['content']) ? $content['content'] : $content;
            $facts = array( substr( strip_tags( $text ), 0, 200 ) . '...' );
        }
        
        return array_slice( $facts, 0, 10 );
    }
    
    private function extract_insights( $content ) {
        $insights = array();
        
        if ( is_array( $content ) && isset($content['content']) ) {
            $text = $content['content'];
        } else {
            $text = $content;
        }
        
        $sentences = explode( '.', strip_tags( $text ) );
        
        foreach ( $sentences as $sentence ) {
            $trimmed = trim($sentence);
            if ( !empty($trimmed) && (strpos( strtolower( $sentence ), 'insight' ) !== false || 
                strpos( strtolower( $sentence ), 'trend' ) !== false ||
                strpos( strtolower( $sentence ), 'important' ) !== false ||
                strpos( strtolower( $sentence ), 'key' ) !== false ||
                strpos( strtolower( $sentence ), 'future' ) !== false ||
                strpos( strtolower( $sentence ), 'new' ) !== false ) ) {
                $insights[] = trim( $sentence ) . '.';
            }
        }
        
        if ( empty( $insights ) ) {
            $insights = array( substr( strip_tags( $text ), 0, 150 ) . '...' );
        }
        
        return array_slice( $insights, 0, 5 );
    }
    
    private function extract_key_points( $content ) {
        $points = array();
        
        if ( is_array( $content ) && isset($content['content']) ) {
            $text = $content['content'];
        } else {
            $text = $content;
        }
        
        $lines = explode( "\n", strip_tags( $text ) );
        
        foreach ( $lines as $line ) {
            $trimmed = trim( $line );
            if ( !empty($trimmed) && (preg_match( '/^[ 0-9 ]+\./', $trimmed ) || 
                preg_match( '/^[ -•*✦◆▸▪◦]/', $trimmed ) ||
                preg_match( '/^( Key|Main|Important|Critical|Major|Top|Best|New)/i', $trimmed ) ) ) {
                $points[] = $trimmed;
            }
        }
        
        if ( empty( $points ) ) {
            $sentences = explode( '.', strip_tags( $text ) );
            $points = array_slice( array_filter( $sentences, function( $s ) {
                $s = trim($s);
                return strlen( $s ) > 30 && strlen( $s ) < 200 && !empty($s);
            } ), 0, 5 );
        }
        
        return $points;
    }
    
    private function extract_sources( $response ) {
        if ( isset( $response['sources'] ) && !empty( $response['sources'] ) ) {
            return $response['sources'];
        }
        return array();
    }
    
    private function log_error( $message ) {
        $logger = new AIA_Logger();
        $logger->log( $message, 'error' );
    }
}