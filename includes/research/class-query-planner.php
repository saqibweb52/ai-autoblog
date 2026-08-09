<?php
// includes/research/class-query-planner.php
if (!defined('ABSPATH')) exit;

class AIA_Query_Planner {

    private $logger;
    private $cache_time = 3600; // 1 hour

    public function __construct() {
        $this->logger = new AIA_Logger();
    }

    /**
     * Generate a list of search queries from a keyword.
     * Uses AI to generate queries with examples.
     *
     * @param string $keyword
     * @param int    $max_queries
     * @return array Array of ['query' => string, 'depth' => 'basic'|'advanced']
     */
    public function generate_queries_with_depth($keyword, $max_queries = 8) {
        $cache_key = 'aia_query_cache_' . md5($keyword . '_' . $max_queries);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            $this->logger->log("Using cached queries for '{$keyword}'", 'debug');
            return $cached;
        }

        $queries = $this->generate_queries_with_ai($keyword, $max_queries);
        
        if (empty($queries)) {
            // Fallback: extract core topic and use simple variations
            $queries = $this->fallback_queries($keyword, $max_queries);
        }

        // Ensure we have at least the original keyword
        if (!empty($keyword) && !$this->is_duplicate_query($keyword, $queries)) {
            array_unshift($queries, ['query' => $keyword, 'depth' => 'basic']);
        }

        // Limit and cache
        $queries = array_slice($queries, 0, $max_queries);
        set_transient($cache_key, $queries, $this->cache_time);
        
        $this->logger->log("Generated " . count($queries) . " queries for '{$keyword}' via AI", 'debug');
        return $queries;
    }

    /**
     * Generate queries using the AI (Gemini or GLM)
     */
    private function generate_queries_with_ai($keyword, $max_queries) {
        $prompt = $this->build_query_prompt($keyword, $max_queries);
        $response = $this->call_ai($prompt);
        
        if (!$response) {
            $this->logger->log("AI query generation failed for '{$keyword}'", 'error');
            return [];
        }

        // Parse the response to extract queries
        $queries = $this->parse_ai_response($response);
        
        // Assign depths
        $result = [];
        foreach ($queries as $query) {
            if (empty($query) || strlen($query) < 3) continue;
            $depth = $this->determine_depth($query);
            $result[] = [
                'query' => $query,
                'depth' => $depth
            ];
        }
        
        return $result;
    }

    /**
     * Build the prompt for the AI with examples
     */
    private function build_query_prompt($keyword, $max_queries) {
        $examples = [
            'Keyword: "AI content generation"',
            'Queries:',
            '1. "AI content generation"',
            '2. "benefits of AI content generation"',
            '3. "how to use AI for content generation"',
            '4. "AI content generation tools"',
            '5. "AI content generation examples"',
            '---',
            'Keyword: "how to rank on Google"',
            'Queries:',
            '1. "how to rank on Google"',
            '2. "Google ranking factors"',
            '3. "SEO best practices for ranking"',
            '4. "how to improve Google ranking"',
            '5. "Google search ranking algorithm"',
            '---',
            'Keyword: "benefits of meditation"',
            'Queries:',
            '1. "benefits of meditation"',
            '2. "meditation advantages"',
            '3. "why meditate"',
            '4. "science behind meditation"',
            '5. "meditation for mental health"',
            '---',
        ];

        $prompt = "You are a search query generation expert. Generate {$max_queries} diverse, grammatically correct search queries for the keyword: \"{$keyword}\".\n\n";
        $prompt .= "The queries should cover different angles: definitions, benefits, how-to, comparisons, trends, etc.\n";
        $prompt .= "Do NOT include malformed queries like 'what is should you use AI'.\n";
        $prompt .= "Only output the queries, one per line, without numbering or bullet points.\n\n";
        $prompt .= "Here are some examples (they are just examples, not strict rules):\n";
        $prompt .= $examples;
        $prompt .= "\nNow generate queries for: \"{$keyword}\"\n";
        $prompt .= "Output only the queries, one per line, no extra text.";

        return $prompt;
    }

    /**
     * Call the AI (using the configured provider)
     */
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
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            $this->logger->log("Gemini query API error: " . $response->get_error_message(), 'error');
            return false;
        }
        
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
            'max_tokens' => 300
        ];
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body' => json_encode($body),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            $this->logger->log("GLM query API error: " . $response->get_error_message(), 'error');
            return false;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['choices'][0]['message']['content'] ?? false;
    }

    /**
     * Parse the AI response to extract queries (one per line)
     */
    private function parse_ai_response($response) {
        $lines = explode("\n", $response);
        $queries = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            // Remove numbering or bullet points
            $line = preg_replace('/^\s*[\d]+\.\s*/', '', $line);
            $line = preg_replace('/^\s*[-•*]\s*/', '', $line);
            $line = trim($line);
            
            if (!empty($line) && strlen($line) > 3) {
                // Remove quotes if present
                $line = trim($line, '"');
                $queries[] = $line;
            }
        }
        
        return $queries;
    }

    /**
     * Fallback method if AI fails
     */
    private function fallback_queries($keyword, $max_queries) {
        $core = $this->extract_core_topic($keyword);
        $type = $this->detect_type($keyword);
        $queries = [];
        
        // Always include original
        $queries[] = ['query' => $keyword, 'depth' => 'basic'];
        
        if (!empty($core) && $core !== $keyword) {
            $queries[] = ['query' => $core, 'depth' => 'basic'];
        }
        
        // Simple variations based on type
        $variations = [];
        switch ($type) {
            case 'question':
                $variations = [
                    $core . ' explained',
                    'understanding ' . $core,
                    $core . ' overview'
                ];
                break;
            case 'howto':
                $variations = [
                    $core . ' tutorial',
                    $core . ' step by step',
                    $core . ' guide'
                ];
                break;
            case 'comparison':
                $variations = [
                    $core . ' comparison',
                    $core . ' vs',
                    'best ' . $core
                ];
                break;
            case 'benefit':
                $variations = [
                    'benefits of ' . $core,
                    'advantages of ' . $core,
                    'why use ' . $core
                ];
                break;
            case 'problem':
                $variations = [
                    'common ' . $core . ' problems',
                    'how to fix ' . $core . ' issues',
                    $core . ' troubleshooting'
                ];
                break;
            case 'trend':
                $variations = [
                    'latest ' . $core . ' trends',
                    'future of ' . $core,
                    'emerging ' . $core
                ];
                break;
            default:
                $variations = [
                    $core . ' guide',
                    $core . ' overview',
                    $core . ' basics'
                ];
                break;
        }
        
        foreach ($variations as $var) {
            if (count($queries) >= $max_queries) break;
            if (!empty($var) && !$this->is_duplicate_query($var, $queries)) {
                $queries[] = ['query' => $var, 'depth' => 'basic'];
            }
        }
        
        return $queries;
    }

    /**
     * Extract core topic (same as before)
     */
    private function extract_core_topic($keyword) {
        $prefixes = [
            'what is ', 'what are ', 'what\'s ',
            'why is ', 'why are ', 'why\'s ',
            'how to ', 'how do ', 'how does ', 'how\'s ',
            'is it ', 'are there ',
            'should you ', 'should i ', 'should we ',
            'do you ', 'does it ', 'can you ', 'can i ',
            'when is ', 'where is ', 'which is ',
            'who is ', 'who are ',
            'what does ', 'what do ',
            'why does ', 'why do ',
        ];
        
        $cleaned = $keyword;
        foreach ($prefixes as $prefix) {
            if (strpos($cleaned, $prefix) === 0) {
                $cleaned = substr($cleaned, strlen($prefix));
                break;
            }
        }
        
        $cleaned = rtrim($cleaned, '?');
        $stop_prefixes = ['for ', 'to ', 'with ', 'about ', 'on ', 'at ', 'in ', 'of '];
        foreach ($stop_prefixes as $prefix) {
            if (strpos($cleaned, $prefix) === 0 && str_word_count($cleaned) > 2) {
                $cleaned = substr($cleaned, strlen($prefix));
                break;
            }
        }
        
        return trim($cleaned);
    }

    private function detect_type($keyword) {
        if (preg_match('/\b(vs|versus|compare|difference|better|best|worst|alternative)\b/', $keyword)) {
            return 'comparison';
        }
        if (strpos($keyword, 'how to') !== false) {
            return 'howto';
        }
        if (preg_match('/\b(benefit|advantage|value|important|should)\b/', $keyword)) {
            return 'benefit';
        }
        if (preg_match('/\b(problem|issue|challenge|mistake|error|fix|solve|avoid)\b/', $keyword)) {
            return 'problem';
        }
        if (preg_match('/\b(trend|future|new|latest|emerging|upcoming|next)\b/', $keyword)) {
            return 'trend';
        }
        if (preg_match('/\b(definition|meaning|explain|understand)\b/', $keyword)) {
            return 'definition';
        }
        if (preg_match('/^(what|why|how|when|where|who|which|is|are|do|does|did|will|would|could|should|may|might|must)\b/', $keyword)) {
            return 'question';
        }
        return 'general';
    }

    /**
     * Check if a query is duplicate
     */
    private function is_duplicate_query($query, $existing_queries) {
        $query_normalized = strtolower(trim($query));
        foreach ($existing_queries as $existing) {
            $existing_normalized = strtolower(trim($existing['query']));
            if ($query_normalized === $existing_normalized) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determine depth (same as before)
     */
    private function determine_depth($query) {
        $query_lower = strtolower($query);
        $word_count = str_word_count($query);
        
        if ($word_count <= 2) return 'basic';
        if ($word_count >= 7) return 'advanced';
        
        $advanced_terms = [
            'benefits', 'advantages', 'disadvantages', 'comparison',
            'best practices', 'strategy', 'methodology', 'framework',
            'trends', 'future', 'prediction', 'emerging',
            'advanced', 'comprehensive', 'in-depth', 'detailed',
            'architecture', 'infrastructure', 'ecosystem',
            'alternative', 'versus', 'difference between',
            'optimization', 'implementation', 'integration',
            'tutorial', 'step by step', 'guide', 'solutions'
        ];
        
        foreach ($advanced_terms as $term) {
            if (strpos($query_lower, $term) !== false) {
                return 'advanced';
            }
        }
        
        $basic_terms = [
            'what is', 'definition', 'meaning',
            'examples', 'list of', 'types of',
            'overview', 'basics', 'essentials'
        ];
        
        foreach ($basic_terms as $term) {
            if (strpos($query_lower, $term) !== false) {
                return 'basic';
            }
        }
        
        return 'basic';
    }

    /**
     * Legacy method
     */
    public function generate_queries($keyword, $max_queries = 8) {
        $queries_with_depth = $this->generate_queries_with_depth($keyword, $max_queries);
        return array_column($queries_with_depth, 'query');
    }

    /**
     * Get queries with depths
     */
    public function get_queries_with_depth($keyword, $max_queries = 8) {
        return $this->generate_queries_with_depth($keyword, $max_queries);
    }
}