<?php
/**
 * OpenAI API integration
 */

class Shaheen_Blog_Wizard_OpenAI {

    private $api_key;
    private $api_url = 'https://api.openai.com/v1/chat/completions';
    private $model = 'gpt-3.5-turbo';

    public function __construct() {
        $this->api_key = Shaheen_Blog_Wizard_Database::get_setting('openai_api_key', '');

        if (empty($this->api_key)) {
            throw new Exception('OpenAI API key is not configured');
        }
    }

    /**
     * Generate headlines with keywords
     */
    public function generate_headlines($topic, $keywords = array(), $count = 5) {
        if (empty($keywords)) {
            $keywords_text = 'No specific keywords provided.';
        } else {
            $keywords_text = 'Must include these keywords: ' . implode(', ', $keywords) . '. Ensure at least 2-3 of these keywords appear in each headline.';
        }

        $prompt = "Generate {$count} compelling and SEO-friendly headlines for a blog post about: {$topic}.
{$keywords_text}

Requirements:
- Each headline should be unique and attention-grabbing
- Keep headlines between 50-70 characters
- Headlines should be click-worthy and relevant to the topic
- Return only the headlines, one per line, numbered 1-{$count}
- Do NOT use markdown formatting or asterisks
- Use simple, clear language";

        $response = $this->call_api($prompt);

        if (!$response['success']) {
            return $response;
        }

        // Parse headlines
        $content = $response['content'];
        $lines = array_filter(array_map('trim', explode("\n", $content)));
        $headlines = array();

        foreach ($lines as $line) {
            // Remove numbering like "1.", "2.", etc.
            $headline = preg_replace('/^\d+\.\s*/', '', $line);
            $headline = trim($headline);

            if (!empty($headline)) {
                $headlines[] = $headline;
            }
        }

        return array(
            'success' => true,
            'headlines' => array_slice($headlines, 0, $count),
        );
    }

    /**
     * Generate subtitle
     */
    public function generate_subtitle($topic, $headline) {
        $prompt = "Generate a compelling subtitle for a blog post with the following:
Topic: {$topic}
Main Headline: {$headline}

Requirements:
- Keep the subtitle between 60-100 characters
- It should complement the headline and provide additional context
- Make it interesting and relevant to readers
- Return only the subtitle text, nothing else";

        $response = $this->call_api($prompt);

        if (!$response['success']) {
            return $response;
        }

        return array(
            'success' => true,
            'subtitle' => trim($response['content']),
        );
    }

    /**
     * Generate meta description
     */
    public function generate_meta_description($topic, $headline, $excerpt = '') {
        $excerpt_text = !empty($excerpt) ? "Preview: {$excerpt}\n" : '';

        $prompt = "Generate a compelling meta description for a blog post:
Topic: {$topic}
Headline: {$headline}
{$excerpt_text}

Requirements:
- Keep the meta description between 150-160 characters (Google's ideal length)
- It should summarize the content and include the main keyword
- Make it compelling to encourage clicks from search results
- Return only the meta description text, nothing else";

        $response = $this->call_api($prompt);

        if (!$response['success']) {
            return $response;
        }

        return array(
            'success' => true,
            'meta_description' => trim($response['content']),
        );
    }

    /**
     * Generate full article
     */
    public function generate_full_article($topic, $headline, $keywords = array(), $outline = '') {
        $keywords_text = !empty($keywords) ? "Key topics to cover: " . implode(', ', $keywords) . "\n" : '';
        $outline_text = !empty($outline) ? "Outline to follow: {$outline}\n" : '';

        $prompt = "Write a comprehensive, well-structured blog article:
Topic: {$topic}
Headline: {$headline}
{$keywords_text}{$outline_text}

Requirements:
- Write 1500-2000 words of high-quality content
- Use clear headings (h2 and h3 tags are optional in output)
- Structure with introduction, body sections, and conclusion
- Make it informative, engaging, and SEO-friendly
- Include practical examples and actionable insights
- Use simple, clear language that's easy to understand
- Return the complete article text";

        $response = $this->call_api($prompt, 4000);

        if (!$response['success']) {
            return $response;
        }

        return array(
            'success' => true,
            'article' => $response['content'],
        );
    }

    /**
     * Generate summary
     */
    public function generate_summary($article_content, $max_length = 300) {
        $prompt = "Summarize the following article in a clear and concise way. The summary should be no more than {$max_length} characters.

Article:
{$article_content}

Requirements:
- Capture the main points and key takeaways
- Keep it concise and readable
- Make it suitable for social media or article excerpt
- Return only the summary text, nothing else";

        $response = $this->call_api($prompt);

        if (!$response['success']) {
            return $response;
        }

        return array(
            'success' => true,
            'summary' => trim($response['content']),
        );
    }

    /**
     * Make API call to OpenAI
     */
    private function call_api($prompt, $max_tokens = 2000) {
        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->api_key,
        );

        $data = array(
            'model' => $this->model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt,
                )
            ),
            'max_tokens' => $max_tokens,
            'temperature' => 0.7,
        );

        $args = array(
            'body' => json_encode($data),
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => true,
        );

        $response = wp_remote_post($this->api_url, $args);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'API Error: ' . $response->get_error_message(),
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            $error_message = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error';
            return array(
                'success' => false,
                'message' => 'OpenAI API Error: ' . $error_message,
            );
        }

        if (!isset($body['choices'][0]['message']['content'])) {
            return array(
                'success' => false,
                'message' => 'Invalid API response',
            );
        }

        return array(
            'success' => true,
            'content' => $body['choices'][0]['message']['content'],
        );
    }
}
