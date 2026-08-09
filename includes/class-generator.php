<?php
// includes/class-generator.php
if (!defined('ABSPATH')) exit;

class AIA_Content_Generator {

    public function __construct() {
        // No grounding system
    }

    public function generate_post($keyword, $author_id, $categories = array()) {
        $logger = new AIA_Logger();

        $user = get_userdata($author_id);
        if (!$user) {
            $logger->log("Invalid author ID: {$author_id}", 'error');
            return false;
        }

        $author_style = new AIA_Author_Style();
        $author = $author_style->get_author_by_id($author_id);
        if (!$author) {
            $logger->log("Author style not found for ID: {$author_id}", 'error');
            return false;
        }

        // ========== RESEARCH (Tavily) ==========
        $research_package = null;
        if (AIA_Research_Engine::is_available()) {
            $research_engine = new AIA_Research_Engine();
            $research_package = $research_engine->research($keyword);
            if ($research_package === false) {
                $logger->log("Tavily research failed for '{$keyword}'", 'warning');
            }
        } else {
            $logger->log("Tavily not configured. Skipping research for '{$keyword}'", 'warning');
        }

        // ========== BUILD PROMPT ==========
        $instructions = $this->get_blog_instructions();
        if (empty($instructions)) {
            $logger->log("No blog instructions found", 'error');
            return false;
        }

        // Inject research facts
        if ($research_package && !empty($research_package['facts'])) {
            $facts_text = "RESEARCH FACTS (use these to write the article, do not invent facts):\n";
            foreach ($research_package['facts'] as $fact) {
                $facts_text .= "- " . $fact['text'] . " (source: " . $fact['source'] . ")\n";
            }
            if (strpos($instructions, '[RESEARCH_FACTS]') !== false) {
                $instructions = str_replace('[RESEARCH_FACTS]', $facts_text, $instructions);
            } else {
                $instructions = $facts_text . "\n\n" . $instructions;
            }
            if (!empty($research_package['suggested_title'])) {
                $instructions .= "\n\nSuggested title hint: " . $research_package['suggested_title'];
            }
        }

        // Replace keyword placeholders
        $prompt = str_replace('[Generated from keyword]', $keyword, $instructions);
        $prompt = str_replace('[Generated from keyword]', $keyword, $prompt);

        // ========== ADD WORD COUNT INSTRUCTION ==========
        $prompt .= "\n\nIMPORTANT: Write a blog post that is between 800 and 1200 words in length. Count the words carefully.";

        // ========== CALL AI ==========
        $response = $this->call_ai($prompt);
        if (!$response) {
            $logger->log("AI call failed for keyword '{$keyword}'", 'error');
            return false;
        }

        // ========== PARSE RESPONSE ==========
        $content_data = $this->extract_content_from_response($response);
        if (empty($content_data['content']) || strlen(strip_tags($content_data['content'])) < 300) {
            $logger->log("Generated content too short for '{$keyword}'", 'error');
            return false;
        }

        $content_data['categories'] = $categories;
        $logger->log("Content generated successfully for '{$keyword}'. Length: " . strlen($content_data['content']), 'debug');

        return $content_data;
    }

    private function get_blog_instructions() {
        $instructions_file = AIA_DATA_DIR . 'blog_instructions.txt';
        if (!file_exists($instructions_file)) {
            return false;
        }
        return file_get_contents($instructions_file);
    }

    private function call_ai($prompt) {
        $provider = get_option('aia_ai_provider', 'gemini');
        if ($provider === 'gemini') {
            return $this->call_gemini($prompt);
        } else {
            return $this->call_glm($prompt);
        }
    }

    private function call_gemini($prompt) {
        $api_key = get_option('aia_api_key', '');
        $model = get_option('aia_gemini_model', 'gemini-2.0-flash');
        if (empty($api_key)) return false;

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
        $body = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ]
        ];
        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($body),
            'timeout' => 120
        ]);
        if (is_wp_error($response)) return false;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? false;
    }

    private function call_glm($prompt) {
        $api_key = get_option('aia_glm_api_key', '');
        $model = get_option('aia_glm_model', 'glm-4-flash');
        if (empty($api_key)) return false;

        $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
        $body = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.7,
            'max_tokens' => 4096
        ];
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body' => json_encode($body),
            'timeout' => 120
        ]);
        if (is_wp_error($response)) return false;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['choices'][0]['message']['content'] ?? false;
    }

    private function extract_content_from_response($response) {
        $json = $this->extract_json($response);
        if ($json && isset($json['seo_title']) && isset($json['content'])) {
            return [
                'title' => $json['seo_title'],
                'meta_description' => $json['meta_description'] ?? '',
                'content' => $this->clean_content($json['content']),
                'featured_image' => $json['featured_image_url'] ?? '',
            ];
        }

        // Fallback extraction
        $title = $this->extract_title($response);
        $meta = $this->extract_meta($response);
        $content = $this->extract_content_html($response);
        $image = $this->extract_featured_image($response);

        return [
            'title' => $title,
            'meta_description' => $meta,
            'content' => $this->clean_content($content),
            'featured_image' => $image,
        ];
    }

    private function extract_json($content) {
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $data = json_decode($content, true);
        if ($data && is_array($data)) return $data;

        if (preg_match('/\{[^{}]*"seo_title"[^{}]*"content"[^{}]*\}/s', $content, $matches)) {
            $json = $this->fix_json($matches[0]);
            $data = json_decode($json, true);
            if ($data && is_array($data)) return $data;
        }
        return null;
    }

    private function fix_json($json) {
        $json = preg_replace('/,\s*}/', '}', $json);
        $json = preg_replace('/,\s*\]/', ']', $json);
        return str_replace('\"', '"', $json);
    }

    private function clean_content($content) {
        $content = str_replace('\\n', "\n", $content);
        $content = str_replace('\n', "\n", $content);
        $content = str_replace('\\"', '"', $content);
        $content = str_replace('\"', '"', $content);
        $content = stripslashes($content);
        $content = preg_replace('/<figure[^>]*>.*?<\/figure>/s', '', $content);
        return trim($content);
    }

    private function extract_title($content) {
        if (preg_match('/"seo_title":\s*"([^"]+)"/', $content, $m)) return $m[1];
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $content, $m)) return strip_tags($m[1]);
        return 'Blog Post';
    }

    private function extract_meta($content) {
        if (preg_match('/"meta_description":\s*"([^"]+)"/', $content, $m)) return $m[1];
        if (preg_match('/<p[^>]*>(.{50,200})<\/p>/i', $content, $m)) {
            $text = strip_tags($m[1]);
            return substr($text, 0, 160);
        }
        return '';
    }

    private function extract_content_html($content) {
        if (preg_match('/"content":\s*"((?:[^"\\\\]|\\\\.)*)"/s', $content, $m)) {
            $html = stripslashes($m[1]);
            return str_replace('\n', "\n", $html);
        }
        return $content;
    }

    private function extract_featured_image($content) {
        if (preg_match('/"featured_image_url":\s*"([^"]+)"/', $content, $m)) return $m[1];
        return '';
    }
}