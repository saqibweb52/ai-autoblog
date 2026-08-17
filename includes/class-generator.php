<?php
// includes/class-generator.php
if (!defined('ABSPATH')) exit;

class AIA_Content_Generator {

    public function generate_post($keyword, $author_id, $categories = array(), $process_logger = null) {
        $logger = new AIA_Logger();
        if ($process_logger === null) {
            $process_logger = new AIA_Process_Logger();
        }

        // Store the exact keyword for consistency
        $exact_keyword = trim($keyword);
        $process_logger->add_entry('info', "🚀 Starting generation for keyword: '{$exact_keyword}'");

        // Get author data
        $user = get_userdata($author_id);
        if (!$user) {
            $process_logger->add_entry('error', "❌ Invalid author ID: {$author_id}");
            $logger->log("Invalid author ID: {$author_id}", 'error');
            return false;
        }
        $process_logger->add_entry('info', "👤 Author found: " . $user->display_name);

        // Get author style
        $author_style = new AIA_Author_Style();
        $author = $author_style->get_author_by_id($author_id);
        if (!$author) {
            $process_logger->add_entry('error', "❌ Author style not found for ID: {$author_id}");
            $logger->log("Author style not found for ID: {$author_id}", 'error');
            return false;
        }
        $process_logger->add_entry('info', "📝 Author style loaded: tone={$author['tone']}, audience={$author['audience']}");

        // ========== RESEARCH (Tavily) ==========
        $research_package = null;
        if (AIA_Research_Engine::is_available()) {
            $process_logger->add_entry('info', "🔍 Tavily is configured. Starting research...");
            $research_engine = new AIA_Research_Engine();
            $research_package = $research_engine->research($exact_keyword);
            if ($research_package === false) {
                $process_logger->add_entry('warning', "⚠️ Tavily research failed for '{$exact_keyword}'");
                $logger->log("Tavily research failed for '{$exact_keyword}'", 'warning');
            } else {
                $process_logger->add_entry('success', "✅ Research complete. Found " . count($research_package['facts']) . " facts, " . count($research_package['sources']) . " sources.");
            }
        } else {
            $process_logger->add_entry('warning', "⚠️ Tavily not configured. Skipping research.");
        }

        // ========== BUILD PROMPT ==========
        $process_logger->add_entry('info', "📄 Loading blog instructions...");
        $instructions = $this->get_blog_instructions();
        if (empty($instructions)) {
            $process_logger->add_entry('error', "❌ No blog instructions found");
            return false;
        }

        // Build prompt with keyword consistency enforced
        $process_logger->add_entry('info', "🔨 Building AI prompt with consistent keyword...");
        $prompt = $this->build_prompt($instructions, $exact_keyword, $research_package);
        $process_logger->add_entry('debug', "📝 Prompt built. Length: " . strlen($prompt));

        // ========== CALL AI ==========
        $process_logger->add_entry('info', "🤖 Calling AI provider...");
        $response = $this->call_ai($prompt);
        if (!$response) {
            $process_logger->add_entry('error', "❌ AI call failed for keyword '{$exact_keyword}'");
            $logger->log("AI call failed for keyword '{$exact_keyword}'", 'error');
            return false;
        }
        $process_logger->add_entry('success', "✅ AI response received. Length: " . strlen($response));

        // ========== PARSE RESPONSE ==========
        $process_logger->add_entry('info', "🔍 Parsing AI response...");
        $content_data = $this->extract_content_from_response($response);
        
        if (empty($content_data['content']) || strlen(strip_tags($content_data['content'])) < 300) {
            $process_logger->add_entry('error', "❌ Generated content too short or empty.");
            return false;
        }
        
        // ========== ENSURE KEYWORD CONSISTENCY ==========
        $process_logger->add_entry('info', "🔧 Ensuring keyword consistency...");
        
        // 1. Ensure title contains the exact keyword
        if (!empty($content_data['title']) && stripos($content_data['title'], $exact_keyword) === false) {
            // Add keyword to title if missing
            $content_data['title'] = $this->add_keyword_to_title($content_data['title'], $exact_keyword);
            $process_logger->add_entry('info', "Added keyword to title: {$content_data['title']}");
        }
        
        // 2. Ensure meta description contains the exact keyword
        if (!empty($content_data['meta_description']) && stripos($content_data['meta_description'], $exact_keyword) === false) {
            $content_data['meta_description'] = $this->add_keyword_to_meta($content_data['meta_description'], $exact_keyword);
            $process_logger->add_entry('info', "Added keyword to meta description");
        }
        
        // 3. Ensure content contains the exact keyword
        if (stripos($content_data['content'], $exact_keyword) === false) {
            // Insert keyword naturally into content
            $content_data['content'] = $this->add_keyword_to_content($content_data['content'], $exact_keyword);
            $process_logger->add_entry('info', "Added keyword to content body");
        }
        
        $process_logger->add_entry('success', "✅ Content parsed. Title: " . $content_data['title']);
        $process_logger->add_entry('debug', "📄 Content length: " . strlen($content_data['content']) . " characters");

        // Add categories
        $content_data['categories'] = $categories;
        $content_data['keyword'] = $exact_keyword; // Store exact keyword for later use

        $logger->log("Content generated successfully for '{$exact_keyword}'. Length: " . strlen($content_data['content']), 'debug');
        $process_logger->add_entry('success', "✅ Generation complete! Ready for publishing.");

        return $content_data;
    }

    /**
     * Build the AI prompt with keyword consistency enforced
     */
    private function build_prompt($instructions, $keyword, $research_package = null) {
        // Replace keyword placeholders
        $prompt = str_replace('[Generated from keyword]', $keyword, $instructions);
        $prompt = str_replace('[Generated from keyword]', $keyword, $prompt);
        
        // Add explicit instruction for keyword consistency
        $keyword_instruction = "\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $keyword_instruction .= "CRITICAL KEYWORD CONSISTENCY RULES:\n";
        $keyword_instruction .= "1. The focus keyword is: \"{$keyword}\"\n";
        $keyword_instruction .= "2. You MUST use this EXACT keyword in the SEO title\n";
        $keyword_instruction .= "3. You MUST use this EXACT keyword in the meta description\n";
        $keyword_instruction .= "4. You MUST use this EXACT keyword at least twice in the content body\n";
        $keyword_instruction .= "5. The keyword should appear naturally, not forced\n";
        $keyword_instruction .= "6. Do NOT change, modify, or shorten the keyword\n";
        $keyword_instruction .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        $prompt = $keyword_instruction . $prompt;
        
        // Inject research facts if available
        if ($research_package && !empty($research_package['facts'])) {
            $facts_text = "RESEARCH FACTS (use these to write the article, do not invent facts):\n";
            $facts_text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            foreach ($research_package['facts'] as $fact) {
                $facts_text .= "• " . $fact['text'] . "\n";
                $facts_text .= "  Source: " . $fact['source'] . "\n\n";
            }
            $facts_text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            
            if (strpos($prompt, '[RESEARCH_FACTS]') !== false) {
                $prompt = str_replace('[RESEARCH_FACTS]', $facts_text, $prompt);
            } else {
                $prompt = $facts_text . "\n\n" . $prompt;
            }
            
            if (!empty($research_package['suggested_title'])) {
                $prompt .= "\n\nSuggested title hint (you can improve it, but keep the keyword): " . $research_package['suggested_title'];
            }
        }
        
        // Add word count & no-bold instruction
        $prompt .= "\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
        $prompt .= "\nIMPORTANT INSTRUCTIONS:";
        $prompt .= "\n• Write a blog post that is between 800 and 1200 words in length.";
        $prompt .= "\n• Do NOT bold the focus keyword or any keyword.";
        $prompt .= "\n• Use bold sparingly for emphasis of important concepts, not for SEO.";
        $prompt .= "\n• Return ONLY valid JSON. No text before or after the JSON.";
        $prompt .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
        
        return $prompt;
    }

    /**
     * Add keyword to title if missing
     */
    private function add_keyword_to_title($title, $keyword) {
        // If title is empty, use keyword as title
        if (empty($title)) {
            return ucwords($keyword);
        }
        
        // Check if keyword already exists (case-insensitive)
        if (stripos($title, $keyword) !== false) {
            return $title;
        }
        
        // Add keyword at the end with a separator
        $separator = ' - ';
        return trim($title) . $separator . ucwords($keyword);
    }

    /**
     * Add keyword to meta description if missing
     */
    private function add_keyword_to_meta($meta, $keyword) {
        // If meta is empty, create one with keyword
        if (empty($meta)) {
            return "Learn everything about " . ucwords($keyword) . ". Discover insights, tips, and best practices.";
        }
        
        // Check if keyword already exists
        if (stripos($meta, $keyword) !== false) {
            return $meta;
        }
        
        // Add keyword at the beginning
        return ucwords($keyword) . " - " . $meta;
    }

    /**
     * Add keyword to content if missing
     */
    private function add_keyword_to_content($content, $keyword) {
        // Check if keyword already exists
        if (stripos($content, $keyword) !== false) {
            return $content;
        }
        
        // Try to add keyword in the first paragraph
        $pattern = '/<p[^>]*>(.*?)<\/p>/i';
        if (preg_match($pattern, $content, $matches)) {
            $first_para = $matches[0];
            $new_para = preg_replace(
                '/<p[^>]*>(.*?)<\/p>/i',
                '<p>$1 ' . esc_html($keyword) . '.</p>',
                $first_para,
                1
            );
            $content = str_replace($first_para, $new_para, $content);
        } else {
            // If no paragraph found, add at the beginning
            $content = '<p>' . esc_html($keyword) . '. ' . strip_tags($content) . '</p>';
        }
        
        return $content;
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
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.6,
                'maxOutputTokens' => 8192
            ]
        ];
        
        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($body),
            'timeout' => 120
        ]);
        
        if (is_wp_error($response)) {
            $logger = new AIA_Logger();
            $logger->log("Gemini API error: " . $response->get_error_message(), 'error');
            return false;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['error'])) {
            $logger = new AIA_Logger();
            $logger->log("Gemini API error: " . ($data['error']['message'] ?? 'Unknown error'), 'error');
            return false;
        }
        
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
            'response_format' => ['type' => 'json_object'],
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
        
        if (is_wp_error($response)) {
            $logger = new AIA_Logger();
            $logger->log("GLM API error: " . $response->get_error_message(), 'error');
            return false;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['error'])) {
            $logger = new AIA_Logger();
            $logger->log("GLM API error: " . ($data['error']['message'] ?? 'Unknown error'), 'error');
            return false;
        }
        
        return $data['choices'][0]['message']['content'] ?? false;
    }

    private function extract_content_from_response($response) {
        // First try strict/tolerant JSON parsing. Never fall back to publishing
        // the complete AI response when it looks like a JSON payload.
        $json = $this->extract_json($response);
        if ($json && isset($json['seo_title']) && isset($json['content'])) {
            return [
                'title' => $json['seo_title'],
                'meta_description' => $json['meta_description'] ?? '',
                'content' => $this->clean_content($json['content']),
            ];
        }

        // Last-resort field extraction for malformed JSON returned by an AI.
        // This is deliberately used instead of treating the whole JSON object
        // as the article body.
        $title = $this->extract_title($response);
        $meta = $this->extract_meta($response);
        $content = $this->extract_content_html($response);
        $image = $this->extract_featured_image($response);

        return [
            'title' => $title,
            'meta_description' => $meta,
            'content' => $this->clean_content($content),
        ];
    }

    private function extract_json($content) {
        $content = trim($content);

        // Remove markdown fences and normalize common smart quotes generated by AI.
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = trim($content);
        $content = str_replace(["“", "”"], '"', $content);
        $content = str_replace(["‘", "’"], "'", $content);

        // Normal JSON response.
        $data = json_decode($content, true);
        if (is_array($data) && isset($data['content'])) {
            return $data;
        }

        // Find the outer JSON object using balanced braces while respecting strings.
        $candidate = $this->find_json_object($content);
        if ($candidate !== null) {
            $candidate = $this->normalize_json_candidate($candidate);
            $data = json_decode($candidate, true);
            if (is_array($data) && isset($data['content'])) {
                return $data;
            }
        }

        // AI sometimes returns structurally broken JSON (especially curly quotes,
        // literal newlines, or unescaped quotes inside the article). Extract the
        // known fields safely instead of returning the JSON itself as post content.
        $fallback = $this->extract_malformed_json_fields($content);
        if ($fallback && !empty($fallback['content'])) {
            return $fallback;
        }

        return null;
    }

    private function find_json_object($content) {
        $start = strpos($content, '{');
        if ($start === false) return null;

        $depth = 0;
        $in_string = false;
        $escaped = false;
        $length = strlen($content);

        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];

            if ($in_string) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $in_string = false;
                }
                continue;
            }

            if ($char === '"') {
                $in_string = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function normalize_json_candidate($json) {
        // Normalize smart punctuation used instead of JSON's required double quotes.
        $json = str_replace(["“", "”"], '"', $json);
        $json = str_replace(["‘", "’"], "'", $json);

        // JSON strings cannot contain literal control characters. Escape them while
        // preserving already escaped sequences.
        $out = '';
        $in_string = false;
        $escaped = false;
        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($in_string) {
                if ($escaped) {
                    $out .= $char;
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $out .= $char;
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $in_string = false;
                    $out .= $char;
                    continue;
                }

                if ($char === "\n") {
                    $out .= '\\n';
                    continue;
                }
                if ($char === "\r") {
                    $out .= '\\r';
                    continue;
                }
                if ($char === "\t") {
                    $out .= '\\t';
                    continue;
                }

                $out .= $char;
                continue;
            }

            if ($char === '"') {
                $in_string = true;
            }
            $out .= $char;
        }

        // Remove trailing commas before closing braces/arrays.
        $out = preg_replace('/,\s*}/', '}', $out);
        $out = preg_replace('/,\s*]/', ']', $out);

        return $out;
    }

    private function extract_malformed_json_fields($content) {
        $result = [];

        if (preg_match('/["“]seo_title["”]\s*:\s*["“](.*?)["”]\s*,/s', $content, $m)) {
            $result['title'] = $this->decode_ai_string($m[1]);
        }

        if (preg_match('/["“]meta_description["”]\s*:\s*["“](.*?)["”]\s*,/s', $content, $m)) {
            $result['meta_description'] = $this->decode_ai_string($m[1]);
        }

        if (preg_match('/["“]featured_image_url["”]\s*:\s*["“](.*?)["”]\s*,/s', $content, $m)) {
            $result['featured_image'] = $this->decode_ai_string($m[1]);
        }

        // Content is normally the final large JSON field. Take everything after
        // its opening quote up to the final quote before the closing object.
        $marker_pattern = '/["“]content["”]\s*:\s*["“]/s';
        if (preg_match($marker_pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $tail = substr($content, $start);
            $end = strrpos($tail, '}');
            if ($end !== false) {
                $tail = substr($tail, 0, $end);
            }
            $tail = rtrim($tail);
            if (substr($tail, -1) === '"' || substr($tail, -1) === '”') {
                $tail = substr($tail, 0, -1);
            }
            $result['content'] = $this->decode_ai_string($tail);
        }

        return !empty($result['content']) ? $result : null;
    }

    /**
     * Normalize AI-generated article HTML/content before validation and publishing.
     * The generator previously called this helper without defining it, which
     * caused direct/manual generation to fail with an undefined-method error.
     */
    private function clean_content($content) {
        $content = is_string($content) ? $content : '';
        $content = trim($content);

        // Remove common markdown code fences around HTML returned by AI.
        $content = preg_replace('/^```(?:html|HTML)?\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        // Decode common escaped JSON/AI line breaks without decoding arbitrary HTML entities.
        $content = str_replace(array('\\r\\n', '\\n', '\\t'), array("\r\n", "\n", "    "), $content);
        $content = str_replace('\\/', '/', $content);

        // Remove accidental null bytes and normalize excessive blank lines.
        $content = str_replace("\0", '', $content);
        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        return trim($content);
    }

    private function decode_ai_string($value) {
        $value = is_string($value) ? $value : '';
        $value = str_replace(["“", "”"], '"', $value);
        $value = str_replace(["‘", "’"], "'", $value);

        // IMPORTANT: do not use stripslashes() here. PHP's stripslashes() turns
        // a JSON newline escape (\\n) into a literal 'n', which was the source
        // of posts containing lines such as "nn<p>" and "nImagine".
        $value = str_replace('\\r\\n', "\n", $value);
        $value = str_replace('\\n', "\n", $value);
        $value = str_replace('\\t', "    ", $value);
        $value = str_replace('\\/', '/', $value);
        $value = str_replace('\\"', '"', $value);

        return trim($value);
    }

    private function fix_json($json) {
        return $this->normalize_json_candidate($json);
    }

    private function extract_title($content) {
        // Try to find SEO title pattern
        if (preg_match('/"seo_title":\s*"([^"]+)"/', $content, $matches)) {
            return $this->decode_ai_string($matches[1]);
        }
        
        // Try to find H1
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $content, $matches)) {
            return trim(strip_tags($matches[1]));
        }
        
        // Try to find title-like text in first paragraph
        if (preg_match('/<p[^>]*>(.{10,100})<\/p>/i', $content, $matches)) {
            $text = trim(strip_tags($matches[1]));
            if (strlen($text) > 10 && strlen($text) < 100) {
                return $text;
            }
        }
        
        return 'Blog Post';
    }

    private function extract_meta($content) {
        // Try to find meta description pattern
        if (preg_match('/"meta_description":\s*"([^"]+)"/', $content, $matches)) {
            return $this->decode_ai_string($matches[1]);
        }
        
        // Try to find first paragraph
        if (preg_match('/<p[^>]*>(.{50,200})<\/p>/i', $content, $matches)) {
            $first_para = trim(strip_tags($matches[1]));
            if (strlen($first_para) > 50) {
                return substr($first_para, 0, 160);
            }
        }
        
        return '';
    }

    private function extract_content_html($content) {
        // Try to find content pattern
        if (preg_match('/"content":\s*"((?:[^"\\\\]|\\\\.)*)"/s', $content, $matches)) {
            $html = $this->decode_ai_string($matches[1]);
            return $html;
        }
        
        // If content already has HTML, return it
        if (strpos($content, '<div') !== false && strpos($content, '>') !== false) {
            return $content;
        }
        
        return $content;
    }

    private function extract_featured_image($content) {
        if (preg_match('/"featured_image_url":\s*"([^"]+)"/', $content, $matches)) {
            return $matches[1];
        }
        return '';
    }
}