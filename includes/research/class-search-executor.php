<?php
// includes/research/class-search-executor.php
if (!defined('ABSPATH')) exit;

class AIA_Search_Executor {

    private $tavily_client;
    private $logger;

    public function __construct(AIA_Tavily_Client $client) {
        $this->tavily_client = $client;
        $this->logger = new AIA_Logger();
    }

    /**
     * Execute a list of queries and return raw responses.
     */
    public function execute($queries, $search_depth = 'basic', $max_results = 5) {
        return $this->tavily_client->multi_search($queries, $search_depth, $max_results, true);
    }

    /**
     * Extract all unique URLs from search responses.
     */
    public function extract_unique_urls($responses) {
        $urls = [];
        foreach ($responses as $resp) {
            if (isset($resp['results']) && is_array($resp['results'])) {
                foreach ($resp['results'] as $result) {
                    if (!empty($result['url']) && !in_array($result['url'], $urls)) {
                        $urls[] = $result['url'];
                    }
                }
            }
        }
        return $urls;
    }
}