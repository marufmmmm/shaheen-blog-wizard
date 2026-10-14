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
                    $html = preg_replace('#<(script|style|noscript|svg)[^>]*>.*?</\\1>#is', ' ', $html);
                    $txt = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($html)));
                    $txt = mb_substr($txt, 0, 3500);
                }
                set_transient($key, $txt, $txt === '' ? 10 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS);
            }
            if ($txt !== '') {
                $out[] = "[{$u}] " . $txt;
            }
        }
        return implode("\n\n", $out);
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
                $out[] = '• ' . get_the_title($p) . ': ' . mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($p->post_content)))), 0, 500);
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
        return implode("\n\n", $parts);
    }
}
