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
     * Execute a list of queries with per-query depth settings.
     *
     * @param array $queries_with_depth Array of ['query' => string, 'depth' => 'basic'|'advanced']
     * @param int   $max_results_per_query
     * @return array Raw search responses
     */
    public function execute_with_depth($queries_with_depth, $max_results_per_query = 5) {
        $responses = [];
        $advanced_count = 0;
        $basic_count = 0;

        foreach ($queries_with_depth as $item) {
            $query = $item['query'];
            $depth = isset($item['depth']) ? $item['depth'] : 'basic';

            if ($depth === 'advanced') {
                $advanced_count++;
            } else {
                $basic_count++;
            }

            $this->logger->log("Searching: '{$query}' (depth: {$depth})", 'debug');

            $result = $this->tavily_client->search($query, $depth, $max_results_per_query, true);
            if ($result !== false) {
                $responses[] = $result;
            }
            usleep(400000); // 0.4 sec to respect rate limits
        }

        $this->logger->log("Executed " . count($responses) . " searches ({$advanced_count} advanced, {$basic_count} basic)", 'info');
        return $responses;
    }

    /**
     * Legacy method for backward compatibility (all queries use same depth).
     */
    public function execute($queries, $search_depth = 'basic', $max_results = 5) {
        $queries_with_depth = [];
        foreach ($queries as $query) {
            $queries_with_depth[] = [
                'query' => $query,
                'depth' => $search_depth
            ];
        }
        return $this->execute_with_depth($queries_with_depth, $max_results);
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