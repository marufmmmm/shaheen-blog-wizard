<?php
if (!defined('ABSPATH')) {
    exit;
}

/** What the AI may know about Shaheen: the owner's notes, the linked website, and the site's own pages. */
class SB_Know {

    /** Plain text of the linked website pages (cached 12 hours). */
    protected static function site_text() {
        $urls = preg_split('/\R+/', (string) sb_get('site_urls'));
        $out = array();
        foreach (array_slice(array_filter(array_map('trim', $urls)), 0, 4) as $u) {
            $u = esc_url_raw($u, array('http', 'https'));
            if (!$u) {
                continue;
            }
            $key = 'sb_site_' . md5($u);
            $txt = get_transient($key);
            if ($txt === false) {
                $txt = '';
                $r = wp_remote_get($u, array('timeout' => 12));
                if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
                    $html = wp_remote_retrieve_body($r);
                    $html = preg_replace('#<(script|style|noscript|svg|nav|header|footer|form)\\b[^>]*>.*?</\\1>#is', ' ', $html);
                    $txt = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($html)));
                    $txt = mb_substr($txt, 0, 7000);
                }
                set_transient($key, $txt, $txt === '' ? 10 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS);
            }
            if ($txt !== '') {
                $out[] = "[{$u}] " . $txt;
            }
        }
        return implode("\n\n", $out);
    }

    /** Readable text of a page, including Elementor pages whose content is not in post_content. */
    protected static function page_text($p) {
        $t = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($p->post_content))));
        if (mb_strlen($t) < 120) {
            $el = get_post_meta($p->ID, '_elementor_data', true);
            $j = is_string($el) ? json_decode($el, true) : null;
            if (is_array($j)) {
                $bits = array();
                $walk = function ($n) use (&$walk, &$bits) {
                    foreach ((array) $n as $k => $v) {
                        if (is_array($v)) {
                            $walk($v);
                        } elseif (is_string($v) && in_array($k, array('editor', 'title', 'text', 'description', 'content', 'heading', 'tab_title', 'tab_content'), true)) {
                            $bits[] = wp_strip_all_tags($v);
                        }
                    }
                };
                $walk($j);
                $t = trim(preg_replace('/\s+/u', ' ', implode(' ', $bits)));
            }
        }
        return $t;
    }

    /** What the AI will (not) know about Shaheen, as short status lines for the owner. */
    public static function status(array $keywords) {
        $lines = array();
        $about = trim((string) sb_get('about'));
        $lines[] = $about !== '' ? '✓ শাহীনের নিজস্ব তথ্য (সেটিংস): ' . sb_bn_digits(mb_strlen($about)) . ' অক্ষর' : '⚠️ সেটিংসে "শাহীন সম্পর্কে নিজস্ব তথ্য" ফাঁকা, তাই ফলাফল/কোচিংয়ের তথ্য AI পাবে না। সেটিংসে লিখুন অথবা প্রম্পটের নিচের ওয়েবসাইট লিংক ঘরে শাহীনের পেজ দিন';
        $n = count(array_filter(array_map('trim', preg_split('/\R+/', (string) sb_get('site_urls')))));
        $lines[] = $n ? '✓ সেটিংসের ওয়েবসাইট লিংক: ' . sb_bn_digits($n) . 'টি পেজ পড়া হয়েছে (যেগুলো পড়া গেছে)' : 'ℹ️ সেটিংসে শাহীনের ওয়েবসাইট লিংক দেওয়া নেই';
        $loc = self::local_text($keywords);
        $lines[] = $loc !== '' ? '✓ এই সাইটের মিলে যাওয়া পেজ/পোস্ট থেকে তথ্য নেওয়া হয়েছে' : 'ℹ️ এই সাইটে কীওয়ার্ডের সাথে মেলে এমন পেজ/পোস্ট পাওয়া যায়নি';
        return $lines;
    }

    /** Pages/posts on this site that match the keywords. */
    protected static function local_text(array $keywords) {
        $out = array();
        $seen = array();
        foreach (array_slice($keywords, 0, 4) as $k) {
            $found = get_posts(array('post_type' => array('page', 'post', 'sb_post'), 'post_status' => 'publish', 's' => $k,
                'posts_per_page' => 3, 'no_found_rows' => true));
            foreach ($found as $p) {
                if (isset($seen[$p->ID]) || sb_is_press($p->ID)) {
                    continue;
                }
                $seen[$p->ID] = 1;
                $out[] = '• ' . get_the_title($p) . ': ' . mb_substr(self::page_text($p), 0, 800);
            }
        }
        return implode("\n", array_slice($out, 0, 6));
    }

    public static function context(array $keywords) {
        $parts = array();
        $about = trim((string) sb_get('about'));
        if ($about !== '') {
            $parts[] = "শাহীন শিক্ষা পরিবার সম্পর্কে আমাদের নিজস্ব তথ্য (সবচেয়ে নির্ভরযোগ্য):\n" . mb_substr($about, 0, 4000);
        }
        $site = self::site_text();
        if ($site !== '') {
            $parts[] = "আমাদের ওয়েবসাইট থেকে:\n" . $site;
        }
        $local = self::local_text($keywords);
        if ($local !== '') {
            $parts[] = "আমাদের সাইটের প্রাসঙ্গিক পেজ/পোস্ট:\n" . $local;
        }
        $mem = function_exists('sb_site_mem_context') ? sb_site_mem_context($keywords) : '';
        if ($mem !== '') {
            $parts[] = "মনে রাখা ওয়েবসাইট (আগে পড়ে রাখা) থেকে:\n" . $mem;
        }
        return implode("\n\n", $parts);
    }
}
