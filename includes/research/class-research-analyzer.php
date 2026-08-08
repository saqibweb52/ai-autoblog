<?php
// includes/research/class-research-analyzer.php
if (!defined('ABSPATH')) exit;

class AIA_Research_Analyzer {

    private $logger;

    public function __construct() {
        $this->logger = new AIA_Logger();
    }

    /**
     * Process raw search responses and build a structured Research Package.
     */
    public function analyze($responses, $keyword) {
        $this->logger->log("Analyzing " . count($responses) . " search responses", 'info');

        // 1. Collect all unique results
        $all_results = [];
        $seen_urls = [];
        foreach ($responses as $resp) {
            if (isset($resp['results']) && is_array($resp['results'])) {
                foreach ($resp['results'] as $result) {
                    $url = $result['url'] ?? '';
                    if ($url && !in_array($url, $seen_urls)) {
                        $seen_urls[] = $url;
                        $all_results[] = $result;
                    }
                }
            }
        }

        // 2. Extract facts (first sentence of snippet)
        $facts = [];
        foreach ($all_results as $result) {
            $snippet = $result['content'] ?? $result['snippet'] ?? '';
            $title   = $result['title'] ?? '';
            if (!empty($snippet)) {
                $first_sentence = $this->get_first_sentence($snippet);
                if (strlen($first_sentence) > 20 && strlen($first_sentence) < 300) {
                    $facts[] = [
                        'text'   => $first_sentence,
                        'source' => $result['url'] ?? '',
                        'title'  => $title,
                    ];
                }
            }
        }

        // 3. Deduplicate facts
        $unique_facts = [];
        $seen_texts = [];
        foreach ($facts as $fact) {
            $text = trim($fact['text']);
            if (!in_array($text, $seen_texts)) {
                $seen_texts[] = $text;
                $unique_facts[] = $fact;
            }
        }

        // 4. Build outline
        $outline = $this->generate_outline($keyword, $unique_facts);

        // 5. Suggest title & meta
        $title = $this->suggest_title($keyword, $unique_facts);
        $meta  = $this->suggest_meta($keyword, $unique_facts);

        return [
            'keyword'          => $keyword,
            'facts'            => $unique_facts,
            'sources'          => $seen_urls,
            'outline'          => $outline,
            'suggested_title'  => $title,
            'suggested_meta'   => $meta,
        ];
    }

    private function get_first_sentence($text) {
        $text = strip_tags($text);
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, 2);
        return trim($sentences[0] ?? $text);
    }

    private function generate_outline($keyword, $facts) {
        return [
            'Introduction' => "Introduce the topic of {$keyword} and why it matters.",
            'What is ' . $keyword . '?' => "Define and explain the core concept.",
            'Key Benefits' => "List the main advantages.",
            'How to Get Started' => "Provide actionable steps.",
            'Common Mistakes' => "Highlight pitfalls to avoid.",
            'Conclusion' => "Summarize key takeaways and encourage action.",
        ];
    }

    private function suggest_title($keyword, $facts) {
        return 'The Ultimate Guide to ' . ucwords($keyword);
    }

    private function suggest_meta($keyword, $facts) {
        return 'Discover everything you need to know about ' . $keyword . ' – from basics to advanced tips.';
    }
}