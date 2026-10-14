<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Social box: Like/Follow buttons plus a rotating slider of the latest posts.
 *  - YouTube: latest videos, read automatically from the channel's public RSS feed.
 *  - Facebook: the page's own timeline plugin (shows the latest posts, no token needed).
 *  - Instagram: profile follow card, plus any post/reel links the owner adds (Instagram has no free public feed).
 * Shown inside posts (start / middle / end) or anywhere with [shaheen_social].
 */
function sb_social_defaults() {
    return array(
        'fb' => '', 'yt' => '', 'ig' => '',
        'fb_label' => '👍 ফেসবুকে লাইক দিন', 'yt_label' => '▶️ ইউটিউবে সাবস্ক্রাইব করুন', 'ig_label' => '📸 ইনস্টাগ্রামে ফলো করুন',
        'title' => '📣 আমাদের সাথে থাকুন',
        'fb_embed' => 1, 'yt_count' => 3, 'items' => '', 'pos' => 'middle', 'interval' => 8,
    );
}

function sb_social_get() {
    $s = get_option('sb_social', array());
    return array_merge(sb_social_defaults(), is_array($s) ? $s : array());
}

/** Only these sites may be linked or embedded. */
function sb_social_host($url) {
    $h = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $h = preg_replace('/^(www|m|web|mbasic)\./', '', $h);
    if (in_array($h, array('facebook.com', 'fb.com', 'fb.watch'), true)) {
        return 'fb';
    }
    if (in_array($h, array('youtube.com', 'youtu.be', 'youtube-nocookie.com'), true)) {
        return 'yt';
    }
    if ($h === 'instagram.com') {
        return 'ig';
    }
    return '';
}

function sb_social_clean_url($url, $only = '') {
    $url = esc_url_raw(trim((string) $url), array('http', 'https'));
    if ($url === '' || ($kind = sb_social_host($url)) === '' || ($only && $kind !== $only)) {
        return '';
    }
    return $url;
}

add_action('admin_post_sb_save_social', function () {
    if (!current_user_can('manage_options')) {
        wp_die('অনুমতি নেই।', 403);
    }
    check_admin_referer('sb_save_social');
    $in = function ($k, $d = '') {
        return isset($_POST[$k]) ? wp_unslash($_POST[$k]) : $d; // phpcs:ignore WordPress.Security.NonceVerification
    };
    $yt = trim($in('yt'));
    if ($yt !== '' && !preg_match('#^https?://#i', $yt)) {
        $yt = 'https://www.youtube.com/' . ltrim($yt, '/');
    }
    $items = array();
    foreach (preg_split('/\R+/', $in('items')) as $line) {
        $u = sb_social_clean_url($line);
        if ($u) {
            $items[] = $u;
        }
    }
    $pos = $in('pos', 'middle');
    update_option('sb_social', array(
        'fb' => sb_social_clean_url($in('fb'), 'fb'), 'yt' => sb_social_clean_url($yt, 'yt'), 'ig' => sb_social_clean_url($in('ig'), 'ig'),
        'fb_label' => sanitize_text_field($in('fb_label')), 'yt_label' => sanitize_text_field($in('yt_label')), 'ig_label' => sanitize_text_field($in('ig_label')),
        'title' => sanitize_text_field($in('title')),
        'fb_embed' => $in('fb_embed') ? 1 : 0, 'yt_count' => max(0, min(5, (int) $in('yt_count', 3))),
        'items' => implode("\n", array_slice(array_unique($items), 0, 12)),
        'pos' => in_array($pos, array('off', 'start', 'middle', 'end'), true) ? $pos : 'middle',
        'interval' => max(4, min(30, (int) $in('interval', 8))),
    ), false);
    delete_transient('sb_yt_feed');
    wp_safe_redirect(admin_url('admin.php?page=sb-social&saved=1'));
    exit;
});

/* ----------------------------------------------------------- YouTube feed */
function sb_yt_channel_id($input) {
    $input = trim($input);
    if (preg_match('/(UC[\w-]{22})/', $input, $m)) {
        return $m[1];
    }
    $key = 'sb_ytid_' . md5($input);
    $id = get_option($key);
    if ($id) {
        return $id;
    }
    $r = wp_remote_get($input, array('timeout' => 12, 'headers' => array('Accept-Language' => 'en')));
    if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
        $b = wp_remote_retrieve_body($r);
        if (preg_match('#channel/(UC[\w-]{22})#', $b, $m) || preg_match('/"channelId":"(UC[\w-]{22})"/', $b, $m) || preg_match('/"externalId":"(UC[\w-]{22})"/', $b, $m)) {
            update_option($key, $m[1], false);
            return $m[1];
        }
    }
    return '';
}

function sb_yt_latest($input, $n) {
    if ($input === '' || $n < 1) {
        return array();
    }
    $tkey = 'sb_yt_feed';
    $cache = get_transient($tkey);
    if (is_array($cache) && isset($cache['for']) && $cache['for'] === $input) {
        return array_slice($cache['items'], 0, $n);
    }
    $items = array();
    $id = sb_yt_channel_id($input);
    if ($id) {
        $r = wp_remote_get('https://www.youtube.com/feeds/videos.xml?channel_id=' . $id, array('timeout' => 12));
        if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200 && function_exists('simplexml_load_string')) {
            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string(wp_remote_retrieve_body($r));
            libxml_use_internal_errors($prev);
            if ($xml && isset($xml->entry)) {
                foreach ($xml->entry as $e) {
                    $yt = $e->children('http://www.youtube.com/xml/schemas/2015');
                    $vid = (string) $yt->videoId;
                    if (preg_match('/^[\w-]{11}$/', $vid)) {
                        $items[] = array('id' => $vid, 'title' => sanitize_text_field((string) $e->title));
                    }
                    if (count($items) >= 5) {
                        break;
                    }
                }
            }
        }
    }
    set_transient($tkey, array('for' => $input, 'items' => $items), $items ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS);
    return array_slice($items, 0, $n);
}

/* ----------------------------------------------------------------- slides */
function sb_social_slides($s) {
    $slides = array();
    if ($s['fb'] && $s['fb_embed']) {
        $slides[] = array('kind' => 'fb', 'src' => 'https://www.facebook.com/plugins/page.php?href=' . rawurlencode($s['fb'])
            . '&tabs=timeline&width=500&height=430&small_header=true&adapt_container_width=true&hide_cover=false&show_facepile=true', 'h' => 430, 'title' => 'ফেসবুক পেজের সর্বশেষ পোস্ট');
    }
    foreach (sb_yt_latest($s['yt'], (int) $s['yt_count']) as $v) {
        $slides[] = array('kind' => 'yt', 'src' => 'https://www.youtube-nocookie.com/embed/' . $v['id'], 'thumb' => 'https://i.ytimg.com/vi/' . $v['id'] . '/hqdefault.jpg', 'title' => $v['title']);
    }
    foreach (preg_split('/\R+/', (string) $s['items']) as $u) {
        $u = sb_social_clean_url($u);
        if (!$u) {
            continue;
        }
        $kind = sb_social_host($u);
        if ($kind === 'fb') {
            $slides[] = array('kind' => 'fb', 'src' => 'https://www.facebook.com/plugins/post.php?href=' . rawurlencode($u) . '&width=500&show_text=true', 'h' => 520, 'title' => 'ফেসবুক পোস্ট');
        } elseif ($kind === 'yt') {
            if (preg_match('#(?:v=|youtu\.be/|shorts/|embed/)([\w-]{11})#', $u, $m)) {
                $slides[] = array('kind' => 'yt', 'src' => 'https://www.youtube-nocookie.com/embed/' . $m[1], 'thumb' => 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg', 'title' => 'ইউটিউব ভিডিও');
            }
        } elseif ($kind === 'ig' && preg_match('#instagram\.com/(p|reel|tv)/([\w-]+)#', $u, $m)) {
            $slides[] = array('kind' => 'ig', 'src' => 'https://www.instagram.com/' . $m[1] . '/' . $m[2] . '/embed/', 'h' => 560, 'title' => 'ইনস্টাগ্রাম পোস্ট');
        }
    }
    if ($s['ig'] && !array_filter($slides, function ($x) { return $x['kind'] === 'ig'; })) {
        $slides[] = array('kind' => 'igcard', 'url' => $s['ig'], 'title' => 'ইনস্টাগ্রামে আমাদের ফলো করুন');
    }
    return array_slice($slides, 0, 14);
}

function sb_social_html() {
    $s = sb_social_get();
    if (!$s['fb'] && !$s['yt'] && !$s['ig']) {
        return '';
    }
    $slides = sb_social_slides($s);
    ob_start();
    ?>
    <aside class="sb-social" data-interval="<?php echo (int) $s['interval']; ?>" aria-label="সোশ্যাল মিডিয়া">
        <div class="sb-soc-head"><?php echo esc_html($s['title']); ?></div>
        <?php if ($slides) : ?>
        <div class="sb-soc-stage">
            <?php foreach ($slides as $i => $sl) : ?>
                <div class="sb-soc-slide" data-kind="<?php echo esc_attr($sl['kind']); ?>"<?php echo isset($sl['src']) ? ' data-src="' . esc_url($sl['src']) . '"' : ''; ?> data-h="<?php echo isset($sl['h']) ? (int) $sl['h'] : 0; ?>" data-title="<?php echo esc_attr($sl['title']); ?>"<?php echo $i ? ' hidden' : ''; ?>>
                    <?php if ($sl['kind'] === 'yt') : ?>
                        <button type="button" class="sb-soc-yt" aria-label="ভিডিও চালান">
                            <img src="<?php echo esc_url($sl['thumb']); ?>" alt="" loading="lazy"><span class="sb-soc-play">▶</span>
                        </button>
                        <div class="sb-soc-cap"><?php echo esc_html($sl['title']); ?></div>
                    <?php elseif ($sl['kind'] === 'igcard') : ?>
                        <a class="sb-soc-card" href="<?php echo esc_url($sl['url']); ?>" target="_blank" rel="noopener"><span>📸</span><strong><?php echo esc_html($sl['title']); ?></strong><small>ইনস্টাগ্রাম পেজ দেখুন ↗</small></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($slides) > 1) : ?>
        <div class="sb-soc-nav">
            <button type="button" class="sb-soc-prev" aria-label="আগেরটি">‹</button>
            <span class="sb-soc-dots"></span>
            <button type="button" class="sb-soc-next" aria-label="পরেরটি">›</button>
        </div>
        <?php endif; endif; ?>
        <div class="sb-soc-btns">
            <?php if ($s['fb']) : ?><a class="sb-soc-btn is-fb" href="<?php echo esc_url($s['fb']); ?>" target="_blank" rel="noopener"><?php echo esc_html($s['fb_label']); ?></a><?php endif; ?>
            <?php if ($s['yt']) : ?><a class="sb-soc-btn is-yt" href="<?php echo esc_url($s['yt']); ?>" target="_blank" rel="noopener"><?php echo esc_html($s['yt_label']); ?></a><?php endif; ?>
            <?php if ($s['ig']) : ?><a class="sb-soc-btn is-ig" href="<?php echo esc_url($s['ig']); ?>" target="_blank" rel="noopener"><?php echo esc_html($s['ig_label']); ?></a><?php endif; ?>
        </div>
    </aside>
    <?php
    return ob_get_clean();
}

add_shortcode('shaheen_social', function () {
    if (function_exists('sb_enqueue_front')) {
        sb_enqueue_front(true);
    }
    return sb_social_html();
});
