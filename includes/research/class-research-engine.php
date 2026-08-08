<?php
// includes/research/class-research-engine.php
if (!defined('ABSPATH')) exit;

class AIA_Research_Engine {

    private $planner;
    private $executor;
    private $analyzer;
    private $logger;

    public function __construct() {
        $this->logger = new AIA_Logger();
        $tavily_client = new AIA_Tavily_Client();
        $this->planner  = new AIA_Query_Planner();
        $this->executor = new AIA_Search_Executor($tavily_client);
        $this->analyzer = new AIA_Research_Analyzer();
    }

    /**
     * Main entry: perform research on a keyword.
     *
     * @param string $keyword
     * @param int    $max_queries
     * @param string $search_depth  'basic' or 'advanced'
     * @param int    $max_results_per_query
     * @return array|false  Research package or false on failure.
     */
    public function research($keyword, $max_queries = 6, $search_depth = 'basic', $max_results_per_query = 5) {
        $this->logger->log("Starting research for keyword: '{$keyword}'", 'info');

        // 1. Generate queries
        $queries = $this->planner->generate_queries($keyword, $max_queries);
        if (empty($queries)) {
            $this->logger->log("No search queries generated for '{$keyword}'", 'error');
            return false;
        }

        // 2. Execute
        $responses = $this->executor->execute($queries, $search_depth, $max_results_per_query);
        if (empty($responses)) {
            $this->logger->log("No search results for '{$keyword}'", 'error');
            return false;
        }

        // 3. Analyze
        $package = $this->analyzer->analyze($responses, $keyword);
        if (empty($package)) {
            $this->logger->log("Analysis failed for '{$keyword}'", 'error');
            return false;
        }

        $this->logger->log("Research complete. Facts: " . count($package['facts']) . ", Sources: " . count($package['sources']), 'success');
        return $package;
    }

    /**
     * Check if Tavily is configured and ready.
     */
    public static function is_available() {
        return !empty(get_option('aia_tavily_api_key', ''));
    }
}