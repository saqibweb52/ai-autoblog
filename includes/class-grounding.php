<?php
// includes/class-grounding.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Grounding_System {
    
    private $logger;
    private $provider;
    private $api_key;
    private $model;
    private $grounding_enabled;
    
    public function __construct() {
        $this->logger = new AIA_Logger();
        $this->provider = get_option('aia_ai_provider', 'gemini');
        $this->grounding_enabled = get_option('aia_enable_grounding', 0);
        
        if ($this->provider === 'gemini') {
            $this->api_key = get_option('aia_api_key', '');
            $this->model = get_option('aia_gemini_model', 'gemini-2.0-flash');
        } else {
            $this->api_key = get_option('aia_glm_api_key', '');
            $this->model = get_option('aia_glm_model', 'glm-4-flash');
        }
    }
    
    /**
     * Research a topic using the configured AI provider
     */
    public function research_topic($keyword) {
        if (empty($this->api_key)) {
            $this->logger->log("Research failed: API key not configured for provider: " . $this->provider, 'error');
            return false;
        }
        
        $this->logger->log("Researching topic: '" . $keyword . "' using " . $this->provider, 'info');
        
        // Get blog instructions
        $instructions = $this->get_blog_instructions();
        if (empty($instructions)) {
            $this->logger->log("Research failed: No blog instructions found", 'error');
            return false;
        }
        
        // Build the prompt
        $prompt = $this->build_prompt($keyword, $instructions);
        
        // Call the AI API
        $response = $this->call_api($prompt);
        
        if ($response === false) {
            $this->logger->log("Research failed: API call returned false for keyword: '" . $keyword . "'", 'error');
            return false;
        }
        
        // Parse the response
        $parsed_response = $this->parse_response($response);
        
        if ($parsed_response === false) {
            $this->logger->log("Research failed: Failed to parse API response for keyword: '" . $keyword . "'", 'error');
            return false;
        }
        
        $this->logger->log("Research completed successfully for keyword: '" . $keyword . "'", 'success');
        
        return [
            'keyword' => $keyword,
            'summaries' => $parsed_response,
            'timestamp' => current_time('mysql')
        ];
    }
    
    /**
     * Get blog instructions from the txt file
     */
    private function get_blog_instructions() {
        $instructions_file = AIA_DATA_DIR . 'blog_instructions.txt';
        
        if (!file_exists($instructions_file)) {
            $this->logger->log("Blog instructions file not found: " . $instructions_file, 'error');
            return false;
        }
        
        return file_get_contents($instructions_file);
    }
    
    /**
     * Build the prompt for the AI
     */
    private function build_prompt($keyword, $instructions) {
        // Replace placeholders
        $prompt = str_replace('[Generated from keyword]', $keyword, $instructions);
        $prompt = str_replace('[Generated from keyword]', $keyword, $prompt);
        
        return $prompt;
    }
    
    /**
     * Call the AI API
     */
    private function call_api($prompt) {
        if ($this->provider === 'gemini') {
            return $this->call_gemini($prompt);
        } else {
            return $this->call_glm($prompt);
        }
    }
    
    /**
     * Call Gemini API
     */
    private function call_gemini($prompt) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->api_key}";
        
        $body = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ]
        ];
        
        // Enable grounding if configured
        if ($this->grounding_enabled && (strpos($this->model, '2.0') !== false || strpos($this->model, '2.5') !== false || strpos($this->model, '3.') !== false)) {
            $body['tools'] = [['googleSearch' => new stdClass()]];
            $this->logger->log("Grounding enabled for Gemini", 'debug');
        }
        
        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($body),
            'timeout' => 120
        ]);
        
        if (is_wp_error($response)) {
            $this->logger->log("Gemini API error: " . $response->get_error_message(), 'error');
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            $error_msg = $data['error']['message'] ?? 'Unknown error';
            $this->logger->log("Gemini API error: " . $error_msg, 'error');
            return false;
        }
        
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return $data['candidates'][0]['content']['parts'][0]['text'];
        }
        
        $this->logger->log("Gemini API: Unexpected response structure", 'error');
        return false;
    }
    
    /**
     * Call GLM API
     */
    private function call_glm($prompt) {
        $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
        
        $body = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.7,
            'max_tokens' => 4096
        ];
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ],
            'body' => json_encode($body),
            'timeout' => 120
        ]);
        
        if (is_wp_error($response)) {
            $this->logger->log("GLM API error: " . $response->get_error_message(), 'error');
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            $error_msg = $data['error']['message'] ?? 'Unknown error';
            $this->logger->log("GLM API error: " . $error_msg, 'error');
            return false;
        }
        
        if (isset($data['choices'][0]['message']['content'])) {
            return $data['choices'][0]['message']['content'];
        }
        
        $this->logger->log("GLM API: Unexpected response structure", 'error');
        return false;
    }
    
    /**
     * Parse the AI response
     */
    private function parse_response($response) {
        // Try to extract JSON
        $json = $this->extract_json($response);
        
        if ($json) {
            return $json;
        }
        
        // If no JSON found, return the raw response
        return $response;
    }
    
    /**
     * Extract JSON from the response
     */
    private function extract_json($content) {
        // Remove markdown code fences
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = trim($content);
        
        // Try to parse as JSON
        $data = json_decode($content, true);
        if ($data && is_array($data)) {
            return $data;
        }
        
        // Try to find JSON in the content
        if (preg_match('/\{[^{}]*"seo_title"[^{}]*"content"[^{}]*\}/s', $content, $matches)) {
            $json_string = $this->fix_json_string($matches[0]);
            $data = json_decode($json_string, true);
            if ($data && is_array($data)) {
                return $data;
            }
        }
        
        // Try to find any JSON object
        if (preg_match('/\{[^{}]*\}/s', $content, $matches)) {
            $json_string = $this->fix_json_string($matches[0]);
            $data = json_decode($json_string, true);
            if ($data && is_array($data)) {
                return $data;
            }
        }
        
        return null;
    }
    
    /**
     * Fix common JSON issues
     */
    private function fix_json_string($json) {
        // Remove trailing commas
        $json = preg_replace('/,\s*}/', '}', $json);
        $json = preg_replace('/,\s*\]/', ']', $json);
        
        // Unescape double quotes inside the JSON
        $json = str_replace('\\"', '"', $json);
        $json = str_replace('\"', '"', $json);
        
        return $json;
    }
}