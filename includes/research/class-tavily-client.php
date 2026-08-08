<?php
// includes/research/class-tavily-client.php
if (!defined('ABSPATH')) exit;

class AIA_Tavily_Client {

    private $api_key;
    private $logger;

    public function __construct($api_key = '') {
        $this->api_key = $api_key ?: get_option('aia_tavily_api_key', '');
        $this->logger  = new AIA_Logger();
    }

    /**
     * Perform a single search query.
     */
    public function search($query, $search_depth = 'basic', $max_results = 5, $include_answer = true) {
        if (empty($this->api_key)) {
            $this->logger->log('Tavily API key missing.', 'error');
            return false;
        }

        $url = 'https://api.tavily.com/search';
        $body = [
            'query'          => $query,
            'search_depth'   => $search_depth,
            'max_results'    => (int) $max_results,
            'include_answer' => (bool) $include_answer,
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'body'    => json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->logger->log('Tavily search error: ' . $response->get_error_message(), 'error');
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200) {
            $msg = isset($data['error']) ? $data['error'] : 'Unknown error';
            $this->logger->log("Tavily API error ({$code}): {$msg}", 'error');
            return false;
        }

        return $data;
    }

    /**
     * Execute multiple queries sequentially (with a small delay).
     */
    public function multi_search($queries, $search_depth = 'basic', $max_results = 5, $include_answer = true) {
        $results = [];
        foreach ($queries as $query) {
            $result = $this->search($query, $search_depth, $max_results, $include_answer);
            if ($result !== false) {
                $results[] = $result;
            }
            usleep(500000); // 0.5 sec to respect rate limits
        }
        return $results;
    }
}