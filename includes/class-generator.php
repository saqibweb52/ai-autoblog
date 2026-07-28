<?php
// includes/class-generator.php

if (!defined('ABSPATH')) {
    exit;
}

class AIA_Content_Generator {
    
    private $grounding_system;
    
    public function __construct() {
        $this->grounding_system = new AIA_Grounding_System();
    }
    
    public function generate_post($keyword, $author_id, $categories = array()) {
        // Get author data from WordPress user
        $user = get_userdata($author_id);
        if (!$user) {
            return false;
        }
        
        // Get author style
        $author_style = new AIA_Author_Style();
        $author = $author_style->get_author_by_id($author_id);
        
        if (!$author) {
            return false;
        }
        
        // Get research data - will return false if failed
        $research = $this->grounding_system->research_topic($keyword);
        
        // If research failed, return false - no post will be created
        if ($research === false) {
            $logger = new AIA_Logger();
            $logger->log("Research failed for keyword '{$keyword}'. Skipping post generation.", 'error');
            return false;
        }
        
        // Extract content from research
        $content_data = $this->extract_content_from_research($research);
        
        // Add categories to the result
        $content_data['categories'] = $categories;
        
        // Debug logging
        $logger = new AIA_Logger();
        $logger->log("Extracted content: title=" . ($content_data['title'] ?? 'NOT SET') . ", content_length=" . strlen($content_data['content'] ?? ''), 'debug');
        $logger->log("Featured image: " . ($content_data['featured_image'] ?? 'NOT SET'), 'debug');
        if (!empty($categories)) {
            $logger->log("Categories: " . implode(', ', $categories), 'debug');
        }
        
        // Validate content before returning
        if (empty($content_data['content']) || strlen(strip_tags($content_data['content'])) < 300) {
            $logger = new AIA_Logger();
            $logger->log("Generated content for '{$keyword}' is too short or empty. Word count: " . str_word_count(strip_tags($content_data['content'] ?? '')), 'error');
            return false;
        }
        
        return $content_data;
    }
    
    private function extract_content_from_research($research) {
        $summaries = $research['summaries'] ?? '';
        $featured_image = '';
        
        // If summaries is an array (JSON data), extract fields
        if (is_array($summaries)) {
            // Check if we have the parsed JSON structure
            if (isset($summaries['seo_title']) && isset($summaries['content'])) {
                $seo_title = $summaries['seo_title'] ?? '';
                $meta_description = $summaries['meta_description'] ?? '';
                $content = $summaries['content'] ?? '';
                $featured_image = $summaries['featured_image_url'] ?? '';
                
                // Clean up the content
                $content = $this->clean_content($content);
                
                // If no SEO title found, generate one
                if (empty($seo_title)) {
                    $seo_title = $this->extract_title_from_content($content);
                }
                
                // If no meta description found, generate one
                if (empty($meta_description)) {
                    $meta_description = $this->extract_meta_from_content($content);
                }
                
                // If no featured image, generate one
                if (empty($featured_image)) {
                    $image_id = rand(1, 100);
                    $featured_image = "https://picsum.photos/id/{$image_id}/800/400";
                }
                
                return [
                    'title' => $seo_title,
                    'meta_description' => $meta_description,
                    'content' => $content,
                    'featured_image' => $featured_image,
                    'excerpt' => wp_trim_words(strip_tags($content), 55)
                ];
            }
            
            // If we have a 'content' field but no 'seo_title', try to extract
            if (isset($summaries['content'])) {
                $content = $summaries['content'];
                $content = $this->clean_content($content);
                
                // Try to extract title from content
                $seo_title = $this->extract_title_from_content($content);
                $meta_description = $this->extract_meta_from_content($content);
                
                // Generate featured image
                $image_id = rand(1, 100);
                $featured_image = "https://picsum.photos/id/{$image_id}/800/400";
                
                return [
                    'title' => $seo_title,
                    'meta_description' => $meta_description,
                    'content' => $content,
                    'featured_image' => $featured_image,
                    'excerpt' => wp_trim_words(strip_tags($content), 55)
                ];
            }
        }
        
        // Fallback: try to extract from raw content (string)
        if (is_string($summaries)) {
            // Try to parse as JSON
            $parsed = $this->parse_json_response($summaries);
            
            if ($parsed && isset($parsed['seo_title']) && isset($parsed['content'])) {
                $seo_title = $parsed['seo_title'] ?? '';
                $meta_description = $parsed['meta_description'] ?? '';
                $content = $parsed['content'] ?? '';
                $featured_image = $parsed['featured_image_url'] ?? '';
                
                // Clean up the content
                $content = $this->clean_content($content);
                
                // If no featured image, generate one
                if (empty($featured_image)) {
                    $image_id = rand(1, 100);
                    $featured_image = "https://picsum.photos/id/{$image_id}/800/400";
                }
                
                return [
                    'title' => $seo_title,
                    'meta_description' => $meta_description,
                    'content' => $content,
                    'featured_image' => $featured_image,
                    'excerpt' => wp_trim_words(strip_tags($content), 55)
                ];
            }
            
            // If JSON parsing failed, try to extract manually
            $seo_title = $this->extract_seo_title($summaries);
            $meta_description = $this->extract_meta_description($summaries);
            $content = $this->extract_content($summaries);
            $featured_image = $this->extract_featured_image($summaries);
            
            // Clean up the content
            $content = $this->clean_content($content);
            
            // If no featured image, generate one
            if (empty($featured_image)) {
                $image_id = rand(1, 100);
                $featured_image = "https://picsum.photos/id/{$image_id}/800/400";
            }
            
            return [
                'title' => $seo_title,
                'meta_description' => $meta_description,
                'content' => $content,
                'featured_image' => $featured_image,
                'excerpt' => wp_trim_words(strip_tags($content), 55)
            ];
        }
        
        // Ultimate fallback
        $image_id = rand(1, 100);
        return [
            'title' => 'Blog Post',
            'meta_description' => '',
            'content' => is_string($summaries) ? $summaries : '',
            'featured_image' => "https://picsum.photos/id/{$image_id}/800/400",
            'excerpt' => ''
        ];
    }
    
    /**
     * Extract featured image from JSON
     */
    private function extract_featured_image($content) {
        if (preg_match('/"featured_image_url":\s*"([^"]+)"/', $content, $matches)) {
            return $matches[1];
        }
        return '';
    }
    
    /**
     * Parse JSON response from AI
     */
    private function parse_json_response($content) {
        // Clean the content - remove any markdown code fences
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = trim($content);
        
        // Try to parse the entire response as JSON
        $data = json_decode($content, true);
        if ($data && isset($data['seo_title']) && isset($data['content'])) {
            return $this->clean_parsed_data($data);
        }
        
        // Try with more flexible pattern
        if (preg_match('/\{("seo_title"|"content"|"meta_description")[^}]*\}/s', $content, $matches)) {
            $json_string = $this->fix_json_string($matches[0]);
            $data = json_decode($json_string, true);
            if ($data && isset($data['seo_title']) && isset($data['content'])) {
                return $this->clean_parsed_data($data);
            }
        }
        
        return null;
    }
    
    private function clean_parsed_data($data) {
        // Clean seo_title
        if (isset($data['seo_title'])) {
            $data['seo_title'] = stripslashes(trim($data['seo_title']));
        }
        
        // Clean meta_description
        if (isset($data['meta_description'])) {
            $data['meta_description'] = stripslashes(trim($data['meta_description']));
        }
        
        // Clean content - handle all escaping cases
        if (isset($data['content'])) {
            $content = $data['content'];
            
            // Handle double escaping
            $content = str_replace('\\n', "\n", $content);
            $content = str_replace('\n', "\n", $content);
            $content = str_replace('\\"', '"', $content);
            $content = str_replace('\"', '"', $content);
            $content = str_replace('\\t', "\t", $content);
            $content = str_replace('\t', "\t", $content);
            $content = str_replace('\\/', '/', $content);
            $content = str_replace('\/', '/', $content);
            
            // Remove any remaining backslashes
            $content = stripslashes($content);
            
            // Trim
            $content = trim($content);
            
            $data['content'] = $content;
        }
        
        return $data;
    }
    
    private function fix_json_string($json) {
        // Remove any trailing commas
        $json = preg_replace('/,\s*}/', '}', $json);
        $json = preg_replace('/,\s*\]/', ']', $json);
        
        return $json;
    }
    
    private function clean_content($content) {
        // Remove any escaped newlines
        $content = str_replace('\\n', "\n", $content);
        $content = str_replace('\n', "\n", $content);
        $content = str_replace('\\t', "    ", $content);
        $content = str_replace('\t', "    ", $content);
        $content = str_replace('\\"', '"', $content);
        $content = str_replace('\"', '"', $content);
        
        // Remove any markdown code fences
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        
        // Remove any figure/image tags from content (they should be featured images)
        $content = preg_replace('/<figure[^>]*>.*?<\/figure>/s', '', $content);
        
        // If content looks like it has JSON wrapper, try to extract just the HTML
        if (preg_match('/"content":\s*"([^"]+)"\s*}/s', $content, $matches)) {
            $content = stripslashes($matches[1]);
            $content = str_replace('\\n', "\n", $content);
            $content = str_replace('\n', "\n", $content);
            $content = str_replace('\\t', "    ", $content);
            $content = str_replace('\t', "    ", $content);
            $content = str_replace('\\"', '"', $content);
            $content = str_replace('\"', '"', $content);
        }
        
        return trim($content);
    }
    
    private function extract_title_from_content($content) {
        // Try to find H1
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $content, $matches)) {
            return trim(strip_tags($matches[1]));
        }
        
        // Try to find any heading
        if (preg_match('/<h[2-6][^>]*>(.*?)<\/h[2-6]>/i', $content, $matches)) {
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
    
    private function extract_meta_from_content($content) {
        // Try to find first paragraph
        if (preg_match('/<p[^>]*>(.{50,200})<\/p>/i', $content, $matches)) {
            $first_para = trim(strip_tags($matches[1]));
            if (strlen($first_para) > 50) {
                return substr($first_para, 0, 160);
            }
        }
        
        return '';
    }
    
    private function extract_seo_title($content) {
        // Try to find SEO title pattern
        if (preg_match('/"seo_title":\s*"([^"]+)"/', $content, $matches)) {
            return stripslashes($matches[1]);
        }
        
        // Try to find H1
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $content, $matches)) {
            return trim(strip_tags($matches[1]));
        }
        
        return 'Blog Post';
    }
    
    private function extract_meta_description($content) {
        // Try to find meta description pattern
        if (preg_match('/"meta_description":\s*"([^"]+)"/', $content, $matches)) {
            return stripslashes($matches[1]);
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
    
    private function extract_content($content) {
        // Try to find content pattern
        if (preg_match('/"content":\s*"((?:[^"\\\\]|\\\\.)*)"/s', $content, $matches)) {
            $content = stripslashes($matches[1]);
            $content = str_replace('\n', "\n", $content);
            $content = str_replace('\t', "    ", $content);
            $content = str_replace('\"', '"', $content);
            return $content;
        }
        
        // If content already has HTML, return it
        if (strpos($content, '<') !== false && strpos($content, '>') !== false) {
            return $content;
        }
        
        return $content;
    }
}