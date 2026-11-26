<?php
/**
 * Public Facebook video link -> YouTube.
 *
 * The owner sends a link (Telegram desk, or the box in the YouTube panel). The site opens the public page the way
 * Facebook's own link-preview robot does, finds the video file, and puts the video in the same queue the page auto-share
 * uses (sb_share_run): AI title / description / hook, upload to YouTube, thumbnail, Telegram note. Nothing goes to Instagram.
 * Only public links work. Nothing is kept on the server.
 */
if (!defined('ABSPATH')) {
    exit;
}

function sb_fl_is_fb($url) {
    return (bool) preg_match('#^https?://([a-z0-9-]+\.)*(facebook\.com|fb\.watch|fb\.me)(/|$)#i', (string) $url);
}

/** one page fetch; returns array(body, final_url) or WP_Error */
function sb_fl_fetch($url, $ua) {
    $r = wp_remote_get($url, array('timeout' => 25, 'redirection' => 8, 'user-agent' => $ua, 'headers' => array('Accept-Language' => 'en-US,en;q=0.8', 'Accept' => 'text/html,application/xhtml+xml')));
    if (is_wp_error($r)) {
        return $r;
    }
    $final = $url;
    if (!empty($r['http_response']) && method_exists($r['http_response'], 'get_response_object')) {
        $ro = $r['http_response']->get_response_object();
        if (!empty($ro->url)) {
            $final = (string) $ro->url;
        }
    }
    return array((string) wp_remote_retrieve_body($r), $final);
}

function sb_fl_meta($html, $prop) {
    $p = preg_quote($prop, '#');
    if (preg_match('#<meta[^>]+(?:property|name)=["\']' . $p . '["\'][^>]+content=["\']([^"\']*)#i', $html, $m) || preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']' . $p . '["\']#i', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '';
}

function sb_fl_unjson($s) {
    $s = str_replace('\\/', '/', $s);
    $s = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($m) {
        return html_entity_decode('&#x' . $m[1] . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }, $s);
    return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** finds the video file of a public Facebook link. Returns array(src, img, title, desc, url, how) or WP_Error. */
function sb_fl_resolve($link) {
    $link = esc_url_raw(trim((string) $link));
    if (!sb_fl_is_fb($link)) {
        return new WP_Error('sb_fl', 'এটা ফেসবুকের লিংক মনে হচ্ছে না।');
    }
    $out = array('src' => '', 'img' => '', 'title' => '', 'desc' => '', 'url' => $link, 'how' => '');
    // 1) the link-preview robot gets the open-graph tags (often with the video file)
    $a = sb_fl_fetch($link, 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)');
    if (!is_wp_error($a)) {
        list($h, $fin) = $a;
        $out['url']   = sb_fl_meta($h, 'og:url') ?: $fin;
        $out['img']   = sb_fl_meta($h, 'og:image');
        $out['title'] = sb_fl_meta($h, 'og:title');
        $out['desc']  = sb_fl_meta($h, 'og:description');
        foreach (array('og:video:secure_url', 'og:video:url', 'og:video') as $k) {
            $v = sb_fl_meta($h, $k);
            if ('' !== $v && preg_match('#\.mp4|fbcdn|video#i', $v)) {
                $out['src'] = $v;
                $out['how'] = 'og';
                break;
            }
        }
    }
    // 2) the normal page: HD / SD file addresses are inside the page data
    $b = sb_fl_fetch($out['url'] ?: $link, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36');
    if (!is_wp_error($b)) {
        list($h2) = $b;
        foreach (array('browser_native_hd_url', 'playable_url_quality_hd', 'hd_src', 'browser_native_sd_url', 'playable_url', 'sd_src') as $k) {
            if (preg_match('#"' . $k . '"\s*:\s*"(https:[^"]+)"#', $h2, $m)) {
                $u = sb_fl_unjson($m[1]);
                if ('' !== $u) {
                    if ('' === $out['src'] || 0 === strpos($k, 'browser_native_hd') || 'playable_url_quality_hd' === $k || 'hd_src' === $k) {
                        $out['src'] = $u;
                        $out['how'] = 'page:' . $k;
                    }
                    break;
                }
            }
        }
        if ('' === $out['img']) {
            $out['img'] = sb_fl_meta($h2, 'og:image');
        }
        if ('' === $out['desc']) {
            $out['desc'] = sb_fl_meta($h2, 'og:description');
        }
        if ('' === $out['title']) {
            $out['title'] = sb_fl_meta($h2, 'og:title');
        }
    }
    if ('' === $out['src']) {
        return new WP_Error('sb_fl', 'ভিডিওর ফাইল পাওয়া যায়নি। লিংকটা পাবলিক ভিডিওর কি না দেখুন (ক্লোজড বা প্রাইভেট হলে সাইট দেখতে পায় না)।');
    }
    return $out;
}

/** puts one link in the share queue. Returns array(ok(bool), text) */
function sb_fl_enqueue($link, $n = 0) {
    $link = esc_url_raw(trim((string) $link));
    $r    = sb_fl_resolve($link);
    if (is_wp_error($r)) {
        return array(false, $r->get_error_message());
    }
    $id = 'lnk-' . substr(md5(preg_replace('#[?&](fbclid|mibextid|__tn__|__cft__\[0\]|__xts__\[0\])=[^&]*#', '', $r['url'])), 0, 16);
    $q  = sb_share_queue();
    if (isset($q[$id]) && in_array($q[$id]['status'], array('new', 'working', 'done'), true)) {
        return array(false, 'এই ভিডিও আগেই লাইনে দেওয়া হয়েছে (অবস্থা: ' . $q[$id]['status'] . ')।');
    }
    $msg = trim((string) $r['desc']);
    if ('' === $msg) {
        $msg = trim((string) $r['title']);
    }
    $q[$id] = array(
        't' => time(), 'fb' => $link, 'link' => $link, 'msg' => mb_substr($msg, 0, 1500), 'kind' => 'video', 'vid' => '', 'img' => (string) $r['img'],
        'src' => (string) $r['src'], 'status' => 'new', 'st' => 'link', 'steps' => array(), 'pgid' => '', 'yt_only' => 1,
    );
    sb_share_queue_save($q);
    wp_schedule_single_event(time() + 5 + 20 * $n, 'sb_share_run', array($id));
    if (function_exists('spawn_cron')) {
        spawn_cron();
    }
    return array(true, 'লাইনে বসানো হয়েছে (' . ($r['how'] ?: 'ok') . ')।');
}

/** all Facebook links of a text -> queue; returns the lines to show */
function sb_fl_enqueue_many($text, $max = 10) {
    $L = array();
    $n = 0;
    if (preg_match_all('#https?://[^\s<>"\']+#iu', (string) $text, $mm)) {
        foreach (array_slice(array_unique($mm[0]), 0, $max) as $u) {
            $u = rtrim($u, '.,;)');
            if (!sb_fl_is_fb($u)) {
                continue;
            }
            list($ok, $t) = sb_fl_enqueue($u, $n);
            if ($ok) {
                $n++;
            }
            $L[] = ($ok ? '✅ ' : '⚠️ ') . esc_html($t) . ' — ' . esc_html(mb_substr($u, 0, 70));
        }
    }
    return array($n, $L);
}
