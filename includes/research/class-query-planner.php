<?php
// includes/research/class-query-planner.php
if (!defined('ABSPATH')) exit;

class AIA_Query_Planner {

    private $logger;

    public function __construct() {
        $this->logger = new AIA_Logger();
    }

    /**
     * Generate a list of search queries from a keyword.
     * Each query includes a suggested search depth (basic or advanced).
     *
     * @param string $keyword
     * @param int    $max_queries
     * @return array Array of ['query' => string, 'depth' => 'basic'|'advanced']
     */
    public function generate_queries_with_depth($keyword, $max_queries = 8) {
        $queries = [];
        $patterns = [
            'what is %s'           => 'basic',
            'benefits of %s'       => 'advanced',
            'how to %s'            => 'advanced',
            '%s examples'          => 'basic',
            '%s best practices'    => 'advanced',
            '%s guide'             => 'advanced',
            'latest %s trends'     => 'advanced',
            '%s vs'                => 'advanced',
            'why %s'               => 'advanced',
            'top %s'               => 'basic',
            '%s explained'         => 'basic',
            '%s for beginners'     => 'basic',
            '%s advanced'          => 'advanced',
            'common mistakes with %s' => 'advanced',
        ];

        foreach ($patterns as $pattern => $default_depth) {
            if (count($queries) >= $max_queries) break;
            $query = trim(sprintf($pattern, $keyword));
            if (!empty($query)) {
                $depth = $this->determine_search_depth($query, $keyword, $default_depth);
                $queries[] = [
                    'query' => $query,
                    'depth' => $depth
                ];
            }
        }

        // Add the core keyword as a basic query if we have room
        if (count($queries) < $max_queries) {
            $depth = $this->determine_search_depth($keyword, $keyword, 'basic');
            $queries[] = [
                'query' => $keyword,
                'depth' => $depth
            ];
        }

        $this->logger->log("Generated " . count($queries) . " search queries for '{$keyword}'", 'debug');
        return $queries;
    }

    /**
     * Determine if a query should use 'advanced' or 'basic' search depth.
     * Advanced = complex, ambiguous, or broad topics that need more context.
     * Basic = simple, direct, or factual queries.
     *
     * @param string $query
     * @param string $keyword
     * @param string $default
     * @return string 'basic' or 'advanced'
     */
    private function determine_search_depth($query, $keyword, $default = 'basic') {
        $query_lower = strtolower($query);
        $keyword_lower = strtolower($keyword);

        // === TRIGGER ADVANCED FOR THESE PATTERNS ===
        $advanced_triggers = [
            // Comparison & evaluation
            ' vs ', 'versus', 'compare', 'difference between',
            // Complex analysis
            'benefits', 'advantages', 'disadvantages', 'pros and cons',
            // Strategic/planning
            'best practices', 'guide', 'strategy', 'framework', 'methodology',
            // Trends & future
            'trends', 'future', 'prediction', 'forecast', 'emerging',
            // Deep understanding
            'advanced', 'comprehensive', 'in-depth', 'detailed',
            // Problem solving
            'how to solve', 'how to fix', 'troubleshooting', 'solution',
            // Decision making
            'why', 'should you', 'is it worth', 'do you need',
            // Complex topics with multiple subtopics
            'architecture', 'infrastructure', 'ecosystem', 'landscape',
            // Industry/domain specific
            'enterprise', 'business', 'professional', 'production',
        ];

        foreach ($advanced_triggers as $trigger) {
            if (strpos($query_lower, $trigger) !== false) {
                return 'advanced';
            }
        }

        // === TRIGGER BASIC FOR THESE PATTERNS ===
        $basic_triggers = [
            // Simple definitions
            'what is', 'definition', 'meaning',
            // Simple lists
            'examples', 'list of', 'types of',
            // Simple facts
            'top', 'best', 'most popular', 'common',
            // Quick answers
            'for beginners', 'simple', 'easy', 'quick',
            // Direct keywords (exact match or short)
            'explained', 'overview',
        ];

        foreach ($basic_triggers as $trigger) {
            if (strpos($query_lower, $trigger) !== false) {
                return 'basic';
            }
        }

        // === LENGTH-BASED DECISION ===
        // Very short queries (1-2 words) = basic
        $word_count = str_word_count($query);
        if ($word_count <= 2) {
            return 'basic';
        }

        // Long, complex-sounding queries = advanced
        if ($word_count >= 6) {
            return 'advanced';
        }

        // === FALLBACK: return default
        return $default;
    }

    /**
     * Legacy method for backward compatibility - returns only queries without depth.
     * Calls the new method but strips depth.
     */
    public function generate_queries($keyword, $max_queries = 8) {
        $queries_with_depth = $this->generate_queries_with_depth($keyword, $max_queries);
        return array_column($queries_with_depth, 'query');
    }

    /**
     * Get queries with their assigned depths.
     * Used by the executor.
     */
    public function get_queries_with_depth($keyword, $max_queries = 8) {
        return $this->generate_queries_with_depth($keyword, $max_queries);
    }
}