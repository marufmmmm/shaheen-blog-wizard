<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI content generation (Bengali). Five features: headlines, subtitle,
 * meta, article, summary. Keywords drive every prompt.
 */
class SB_AI {

    const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    protected static function system_prompt() {
        return 'তুমি শাহীন শিক্ষা পরিবারের (বাংলাদেশের একটি শিক্ষা প্রতিষ্ঠান-নেটওয়ার্ক, স্কুল ও ক্যাডেট কোচিং) ব্লগের জন্য একজন অভিজ্ঞ বাংলা কনটেন্ট লেখক। '
            . 'সবসময় শুদ্ধ, সহজ ও প্রাঞ্জল বাংলায় লিখবে। শিক্ষার্থী, অভিভাবক ও শিক্ষকরা পাঠক। কোনো মিথ্যা তথ্য, ভুয়া পরিসংখ্যান বা ভুয়া উদ্ধৃতি বানাবে না। নিশ্চিত না হলে সাধারণ ও নিরাপদ কথা লিখবে।';
    }

    protected static function kw_text($keywords) {
        return implode(', ', array_map('strval', $keywords));
    }

    /**
     * @param string $feature headlines|subtitle|meta|article|summary
     * @param array  $c       title, topic, keywords[], excerpt, content, length
     * @return array|WP_Error
     */
    public static function generate($feature, array $c) {
        $key = sb_get('api_key');
        if (empty($key)) {
            return new WP_Error('sb_no_key', 'OpenAI API কী দেওয়া হয়নি। ব্লগ সেটিংসে কী যোগ করুন।');
        }
        $title    = isset($c['title']) ? $c['title'] : '';
        $topic    = isset($c['topic']) ? $c['topic'] : '';
        $keywords = isset($c['keywords']) && is_array($c['keywords']) ? array_values(array_filter($c['keywords'])) : array();
        $kw       = self::kw_text($keywords);
        $json     = false;
        $max      = 400;

        switch ($feature) {
            case 'headlines':
                if (count($keywords) < 2) {
                    return new WP_Error('sb_kw', 'শিরোনামের জন্য কমপক্ষে ২টি কীওয়ার্ড দিন।');
                }
                $user = "একটি ব্লগ পোস্টের জন্য ঠিক ৫টি আলাদা বাংলা শিরোনাম লেখো।\n"
                    . "কীওয়ার্ড (বাধ্যতামূলক): {$kw}\n"
                    . ($topic ? "বিষয়/প্রসঙ্গ: {$topic}\n" : '')
                    . ($title ? "খসড়া শিরোনাম: {$title}\n" : '')
                    . "নিয়ম:\n- প্রতিটি শিরোনামে উপরের কীওয়ার্ডগুলোর অন্তত ২টি স্বাভাবিকভাবে থাকবে।\n"
                    . "- ৮ থেকে ১৪ শব্দ, আকর্ষণীয় কিন্তু ক্লিকবেইট নয়।\n- পাঁচটি শিরোনাম একটি থেকে অন্যটি স্পষ্ট আলাদা হবে।\n"
                    . "শুধু JSON দাও: {\"headlines\":[\"...\",\"...\",\"...\",\"...\",\"...\"]}";
                $json = true;
                $max  = 500;
                break;

            case 'subtitle':
                $user = "নিচের পোস্টের জন্য একটি সাবটাইটেল লেখো (সর্বোচ্চ ১৪০ অক্ষর)।\nশিরোনাম: {$title}\n"
                    . ($kw ? "কীওয়ার্ড: {$kw}\n" : '') . ($topic ? "প্রসঙ্গ: {$topic}\n" : '')
                    . 'শুধু সাবটাইটেলটি দাও, কোনো ব্যাখ্যা বা উদ্ধৃতিচিহ্ন ছাড়া।';
                $max = 150;
                break;

            case 'meta':
                $user = "নিচের পোস্টের জন্য SEO মেটা বর্ণনা লেখো (১২০ থেকে ১৬০ অক্ষর), যাতে মূল কীওয়ার্ড থাকবে।\nশিরোনাম: {$title}\n"
                    . ($kw ? "কীওয়ার্ড: {$kw}\n" : '') . (!empty($c['excerpt']) ? "সারাংশ: {$c['excerpt']}\n" : '')
                    . 'শুধু বর্ণনাটি দাও।';
                $max = 220;
                break;

            case 'article':
                $len = isset($c['length']) ? $c['length'] : 'medium';
                $words = array('short' => '৫০০ থেকে ৮০০', 'medium' => '৮০০ থেকে ১২০০', 'long' => '১৫০০ বা তার বেশি');
                $w = isset($words[$len]) ? $words[$len] : $words['medium'];
                $user = "একটি সম্পূর্ণ বাংলা ব্লগ আর্টিকেল লেখো।\nশিরোনাম: {$title}\n"
                    . ($kw ? "কীওয়ার্ড (স্বাভাবিকভাবে ব্যবহার করবে): {$kw}\n" : '') . ($topic ? "প্রসঙ্গ: {$topic}\n" : '')
                    . "দৈর্ঘ্য: প্রায় {$w} শব্দ।\nনিয়ম:\n- শুরুতে একটি আকর্ষণীয় ভূমিকা (গল্প বা প্রশ্ন দিয়ে)।\n"
                    . "- প্রায় প্রতি ১৫০ শব্দে একটি উপশিরোনাম (<h2>)।\n- ছোট অনুচ্ছেদ, সহজ ভাষা, সক্রিয় বাক্য।\n"
                    . "- বাস্তবসম্মত উদাহরণ দাও, তবে ভুয়া সংখ্যা বা উদ্ধৃতি বানাবে না।\n- শেষে একটি উপসংহার ও পাঠকের জন্য করণীয় (call to action)।\n"
                    . "আউটপুট শুধু HTML: <h2>, <h3>, <p>, <ul>, <li>, <strong> ব্যবহার করো। <h1>, <html>, <body> বা কোড-ব্লক দেবে না।";
                $max = 3800;
                break;

            case 'summary':
                $body = isset($c['content']) ? wp_strip_all_tags($c['content']) : '';
                if (mb_strlen($body) < 80) {
                    return new WP_Error('sb_short', 'সামারি বানাতে আগে আর্টিকেলের লেখা দিন বা তৈরি করুন।');
                }
                $user = "নিচের আর্টিকেলের একটি সংক্ষিপ্ত সামারি লেখো (প্রায় ১০০ থেকে ১৫০ শব্দ)। শুধু সামারি দাও।\n\nশিরোনাম: {$title}\n\n" . mb_substr($body, 0, 6000);
                $max = 500;
                break;

            default:
                return new WP_Error('sb_feature', 'অজানা ফিচার।');
        }

        $body = array(
            'model'       => sb_get('model'),
            'temperature' => 0.7,
            'max_tokens'  => $max,
            'messages'    => array(
                array('role' => 'system', 'content' => self::system_prompt()),
                array('role' => 'user', 'content' => $user),
            ),
        );
        if ($json) {
            $body['response_format'] = array('type' => 'json_object');
        }

        $resp = wp_remote_post(self::ENDPOINT, array(
            'timeout' => 120,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode($body),
        ));
        if (is_wp_error($resp)) {
            return new WP_Error('sb_http', 'OpenAI-তে সংযোগ হয়নি: ' . $resp->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
            if ($code === 401) {
                $msg = 'API কী ভুল বা বাতিল।';
            } elseif ($code === 429) {
                $msg = 'OpenAI ব্যালেন্স শেষ বা অনুরোধ বেশি হয়েছে। (' . $msg . ')';
            }
            return new WP_Error('sb_api', $msg);
        }
        $text = isset($data['choices'][0]['message']['content']) ? trim($data['choices'][0]['message']['content']) : '';
        if ($text === '') {
            return new WP_Error('sb_empty', 'AI কোনো লেখা দেয়নি। আবার চেষ্টা করুন।');
        }

        if ($feature === 'headlines') {
            return self::parse_headlines($text, $keywords);
        }
        if ($feature === 'article') {
            $text = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text);
            return array('text' => wp_kses_post($text));
        }
        return array('text' => trim(wp_strip_all_tags($text), " \t\n\r\0\x0B\"'“”"));
    }

    protected static function parse_headlines($text, $keywords) {
        $list = array();
        $j = json_decode($text, true);
        if (is_array($j) && isset($j['headlines']) && is_array($j['headlines'])) {
            $list = $j['headlines'];
        } else {
            foreach (preg_split('/\R/u', $text) as $line) {
                $line = trim(preg_replace('/^\s*[\d০-৯]+[\.\)\-]\s*/u', '', $line), " \t\"'“”-*");
                if ($line !== '') {
                    $list[] = $line;
                }
            }
        }
        $out = array();
        foreach (array_slice($list, 0, 5) as $h) {
            $h = sanitize_text_field((string) $h);
            if ($h === '') {
                continue;
            }
            $used = 0;
            foreach ($keywords as $k) {
                if ($k !== '' && mb_stripos($h, $k) !== false) {
                    $used++;
                }
            }
            $out[] = array('text' => $h, 'keywords_used' => $used);
        }
        if (!$out) {
            return new WP_Error('sb_empty', 'শিরোনাম পাওয়া যায়নি। আবার চেষ্টা করুন।');
        }
        return array('headlines' => $out);
    }
}
