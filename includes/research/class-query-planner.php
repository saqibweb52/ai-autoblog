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
     */
    public function generate_queries($keyword, $max_queries = 8) {
        $queries = [];

        $patterns = [
            '%s',
            'what is %s',
            'benefits of %s',
            'how to %s',
            '%s examples',
            '%s best practices',
            '%s guide',
            'latest %s trends',
            '%s vs',
            'why %s',
            'top %s',
        ];

        foreach ($patterns as $pattern) {
            if (count($queries) >= $max_queries) break;
            $q = trim(sprintf($pattern, $keyword));
            if (!empty($q) && !in_array($q, $queries)) {
                $queries[] = $q;
            }
        }

        // Fallback extras
        $extras = [
            $keyword . ' explained',
            $keyword . ' for beginners',
            $keyword . ' advanced',
            'common mistakes with ' . $keyword,
        ];
        foreach ($extras as $q) {
            if (count($queries) >= $max_queries) break;
            if (!in_array($q, $queries)) {
                $queries[] = $q;
            }
        }

        $this->logger->log("Generated " . count($queries) . " search queries for '{$keyword}'", 'debug');
        return $queries;
    }
}