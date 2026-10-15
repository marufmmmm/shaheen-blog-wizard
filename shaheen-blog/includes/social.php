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
        'fb_embed' => 1, 'fb_mode' => 'card', 'codes' => '', 'hide_btns' => 0, 'layout' => 'rotate', 'yt_count' => 3, 'items' => '', 'pos' => 'middle', 'interval' => 8,
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

/** Box shapes the owner can choose for a post/video (value => label). '' = follow the post itself. */
function sb_ratio_options() {
    return array('' => 'স্বয়ংক্রিয় (পোস্ট যেমন)', '16:9' => '১৬:৯ — চওড়া ভিডিও', '4:3' => '৪:৩ — ছোট চওড়া', '1:1' => '১:১ — চৌকো', '4:5' => '৪:৫ — লম্বা পোস্ট/ছবি', '9:16' => '৯:১৬ — রিল/শর্টস/স্টোরি');
}

function sb_ratio_clean($r) {
    $r = is_string($r) ? trim($r) : '';
    return array_key_exists($r, sb_ratio_options()) ? $r : '';
}

function sb_social_clean_url($url, $only = '') {
    $url = esc_url_raw(trim((string) $url), array('http', 'https'));
    if ($url === '' || ($kind = sb_social_host($url)) === '' || ($only && $kind !== $only)) {
        return '';
    }
    return $url;
}


/* ------------------------------------------------------------ dynamic items */
function sb_social_item_types() {
    return array(
        'fb_like'     => 'ফেসবুক — শুধু লাইক বাটন',
        'fb_card'     => 'ফেসবুক — পেজ কার্ড (কভার ছবি + ফলো বাটন)',
        'fb_timeline' => 'ফেসবুক — সর্বশেষ পোস্টসহ বড় বক্স',
        'fb_post'     => 'ফেসবুক — নির্দিষ্ট পোস্ট/ভিডিও (লিংক)',
        'yt_latest'   => 'ইউটিউব — চ্যানেলের সর্বশেষ ভিডিও',
        'yt_video'    => 'ইউটিউব — একটি নির্দিষ্ট ভিডিও (লিংক)',
        'ig'          => 'ইনস্টাগ্রাম — পোস্ট/রিল (লিংক)',
        'ig_card'     => 'ইনস্টাগ্রাম — প্রোফাইল ফলো কার্ড',
        'code'        => 'এমবেড কোড (ফেসবুক/ইউটিউব/ইনস্টাগ্রাম থেকে কপি করা)',
    );
}

/** Starting height (px) for each type; the owner can change it per item. */
function sb_social_default_h($type) {
    $h = array('fb_like' => 40, 'fb_card' => 135, 'fb_timeline' => 500, 'fb_post' => 560, 'ig' => 620, 'code' => 0);
    return isset($h[$type]) ? $h[$type] : 0;
}

function sb_social_clean_items($raw) {
    $out = array();
    $types = sb_social_item_types();
    foreach (array_slice(is_array($raw) ? $raw : array(), 0, 12) as $r) {
        if (!is_array($r) || !isset($r['type'], $types[$r['type']])) {
            continue;
        }
        $src = trim(wp_check_invalid_utf8(isset($r['src']) ? (string) $r['src'] : ''));
        $src = mb_substr($src, 0, 4000);
        if (in_array($r['type'], array('fb_post', 'yt_video', 'ig', 'code'), true) && !sb_embed_parse($src)) {
            continue;   // nothing usable pasted
        }
        $id = (isset($r['id']) && preg_match('/^[a-z0-9]{6,20}$/', $r['id'])) ? $r['id'] : strtolower(wp_generate_password(8, false, false));
        $out[] = array(
            'id' => $id, 'on' => !empty($r['on']) ? 1 : 0, 'type' => $r['type'],
            'name' => sanitize_text_field(isset($r['name']) ? $r['name'] : ''),
            'src' => in_array($r['type'], array('fb_post', 'yt_video', 'ig', 'code'), true) ? $src : esc_url_raw($src, array('http', 'https')),
            'h' => max(0, min(1400, (int) (isset($r['h']) ? $r['h'] : 0))),
            'r' => in_array($r['type'], array('fb_post', 'yt_video', 'ig', 'yt_latest'), true) ? sb_ratio_clean(isset($r['r']) ? $r['r'] : '') : '',
            'n' => max(1, min(5, (int) (isset($r['n']) ? $r['n'] : 3))),
        );
    }
    return $out;
}

/** Saved items, or items derived from the older settings so nothing disappears after updating. */
function sb_social_items() {
    $saved = get_option('sb_social_items', null);
    if (is_array($saved)) {
        return sb_social_clean_items($saved);
    }
    $s = sb_social_get();
    $mk = function ($type, $src = '', $extra = array()) {
        return array_merge(array('id' => strtolower(wp_generate_password(8, false, false)), 'on' => 1, 'type' => $type, 'name' => '', 'src' => $src, 'h' => 0, 'n' => 3), $extra);
    };
    $items = array();
    foreach (preg_split('/\R\s*\R/', trim((string) $s['codes'])) as $block) {
        if (trim($block) !== '') {
            $items[] = $mk('code', trim($block));
        }
    }
    if ($s['fb'] && $s['fb_embed']) {
        $items[] = $mk($s['fb_mode'] === 'timeline' ? 'fb_timeline' : 'fb_card');
    }
    if ($s['yt'] && (int) $s['yt_count'] > 0) {
        $items[] = $mk('yt_latest', '', array('n' => (int) $s['yt_count']));
    }
    foreach (preg_split('/\R+/', (string) $s['items']) as $u) {
        $u = trim($u);
        $k = $u !== '' ? sb_social_host($u) : '';
        if ($k) {
            $items[] = $mk($k === 'fb' ? 'fb_post' : ($k === 'yt' ? 'yt_video' : 'ig'), $u);
        }
    }
    if ($s['ig'] && !array_filter($items, function ($x) { return $x['type'] === 'ig'; })) {
        $items[] = $mk('ig_card');
    }
    return sb_social_clean_items($items);
}

/** Slides (what is shown) for one item. */
function sb_social_item_slides($it, $s) {
    $h = $it['h'] ?: sb_social_default_h($it['type']);
    $fb = $it['src'] !== '' ? $it['src'] : $s['fb'];
    $page = function ($tabs, $hh) use ($fb) {
        return 'https://www.facebook.com/plugins/page.php?href=' . rawurlencode($fb) . ($tabs ? '&tabs=' . $tabs : '')
            . '&width=500&height=' . $hh . '&small_header=false&adapt_container_width=true&hide_cover=false&show_facepile=false';
    };
    switch ($it['type']) {
        case 'fb_like':
            if (!$fb) { return array(); }
            return array(array('kind' => 'fb', 'wa' => 1, 'h' => $h, 'title' => 'ফেসবুক লাইক',
                'src' => 'https://www.facebook.com/plugins/like.php?href=' . rawurlencode($fb) . '&layout=button_count&action=like&show_faces=false&share=false&width=500&height=' . $h));
        case 'fb_card':
            return $fb ? array(array('kind' => 'fb', 'wa' => 1, 'h' => $h, 'title' => 'ফেসবুক পেজ', 'src' => $page('', $h))) : array();
        case 'fb_timeline':
            return $fb ? array(array('kind' => 'fb', 'wa' => 1, 'auto' => empty($it['h']) ? 1 : 0, 'h' => $h, 'title' => 'ফেসবুক পেজ', 'src' => $page('timeline', $h))) : array();
        case 'yt_latest':
            $out = array();
            foreach (sb_yt_latest($it['src'] !== '' ? $it['src'] : $s['yt'], (int) $it['n']) as $v) {
                $out[] = array('kind' => 'yt', 'r' => sb_ratio_clean(isset($it['r']) ? $it['r'] : ''), 'src' => 'https://www.youtube-nocookie.com/embed/' . $v['id'], 'thumb' => 'https://i.ytimg.com/vi/' . $v['id'] . '/hqdefault.jpg', 'title' => $v['title']);
            }
            return $out;
        case 'ig_card':
            $u = $it['src'] !== '' ? $it['src'] : $s['ig'];
            return $u ? array(array('kind' => 'igcard', 'url' => $u, 'title' => 'ইনস্টাগ্রামে আমাদের ফলো করুন')) : array();
        default: // fb_post, yt_video, ig, code
            if (in_array($it['type'], array('fb_post', 'yt_video', 'ig'), true) && preg_match('/\R\s*\S/', $it['src'])) {
                $out = array();
                foreach (array_slice(preg_split('/\R+/', $it['src']), 0, 8) as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $one = $it;
                        $one['src'] = $line;
                        $out = array_merge($out, sb_social_item_slides($one, $s));
                    }
                }
                return $out;
            }
            $e = sb_embed_parse($it['src']);
            if (!$e || isset($e['link'])) {
                return array();
            }
            if ($e['kind'] === 'yt' && preg_match('#/embed/([\w-]{11})#', $e['src'], $m)) {
                return array(array('kind' => 'yt', 'r' => sb_ratio_clean(isset($it['r']) ? $it['r'] : ''), 'src' => 'https://www.youtube-nocookie.com/embed/' . $m[1], 'thumb' => 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg', 'title' => 'ইউটিউব ভিডিও'));
            }
            $hh = $it['h'] ?: ($e['h'] ?: 420);
            return array(array('kind' => $e['kind'] === 'ig' ? 'ig' : 'fb', 'r' => sb_ratio_clean(isset($it['r']) ? $it['r'] : ''), 'wa' => preg_match('#facebook\.com/plugins/(page|like)\.php#', $e['src']) ? 1 : 0, 'src' => $e['src'], 'h' => $hh, 'title' => 'সোশ্যাল মিডিয়া'));
    }
}

add_action('wp_ajax_sb_social_preview', function () {
    sb_ajax_guard('manage_options');
    $it = sb_social_clean_items(array(json_decode((string) sb_req('item', '{}'), true)));
    if (!$it) {
        wp_send_json_error(array('message' => 'এই আইটেমে এখনো কিছু বসানো হয়নি বা কোডটি বোঝা যায়নি।'));
    }
    $slides = sb_social_item_slides($it[0], sb_social_get());
    if (!$slides) {
        wp_send_json_error(array('message' => 'দেখানোর মতো কিছু পাওয়া যায়নি। (ফেসবুক/ইউটিউব/ইনস্টাগ্রাম পেজের লিংক সেটিংসের ওপরে দিয়েছেন তো?)'));
    }
    wp_send_json_success(array('slides' => $slides));
});

/** Pasted embed codes / links, separated by blank lines. Only ones we can safely understand are kept. */
function sb_social_clean_codes($raw) {
    $keep = array();
    foreach (preg_split('/\R\s*\R/', trim((string) $raw)) as $block) {
        $block = trim(wp_check_invalid_utf8($block));
        if ($block !== '' && sb_embed_parse($block)) {
            $keep[] = mb_substr($block, 0, 4000);
        }
    }
    return implode("\n\n", array_slice($keep, 0, 8));
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
    $pos = $in('pos', 'middle');
    update_option('sb_social', array(
        'fb' => sb_social_clean_url($in('fb'), 'fb'), 'yt' => sb_social_clean_url($yt, 'yt'), 'ig' => sb_social_clean_url($in('ig'), 'ig'),
        'fb_label' => sanitize_text_field($in('fb_label')), 'yt_label' => sanitize_text_field($in('yt_label')), 'ig_label' => sanitize_text_field($in('ig_label')),
        'title' => sanitize_text_field($in('title')),
        'hide_btns' => $in('hide_btns') ? 1 : 0, 'layout' => $in('layout') === 'stack' ? 'stack' : 'rotate',
        'pos' => in_array($pos, array('off', 'start', 'middle', 'end'), true) ? $pos : 'middle',
        'interval' => max(4, min(30, (int) $in('interval', 8))),
    ), false);
    $json = json_decode((string) $in('items_json', '[]'), true);
    update_option('sb_social_items', sb_social_clean_items(is_array($json) ? $json : array()), false);
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
function sb_social_slides($s, $ids = null) {
    $slides = array();
    foreach (sb_social_items() as $it) {
        if (!$it['on'] || (is_array($ids) && !in_array($it['id'], $ids, true))) {
            continue;
        }
        $slides = array_merge($slides, sb_social_item_slides($it, $s));
    }
    return array_slice($slides, 0, 14);
}

function sb_social_html($ids = null) {
    $s = sb_social_get();
    $slides = sb_social_slides($s, $ids);
    if (!$slides && !$s['fb'] && !$s['yt'] && !$s['ig']) {
        return '';
    }
    $stack = $s['layout'] === 'stack';
    // The Facebook plugin already has its own Like/Follow button, so a second "Like" button would be redundant.
    $fb_plugin = (bool) array_filter($slides, function ($x) { return isset($x['src']) && preg_match('#plugins/(page|like)\.php#', $x['src']); });
    $show_btns = !$s['hide_btns'];
    ob_start();
    ?>
    <aside class="sb-social<?php echo $stack ? ' is-stack' : ''; ?>" data-layout="<?php echo $stack ? 'stack' : 'rotate'; ?>" data-interval="<?php echo (int) $s['interval']; ?>" aria-label="সোশ্যাল মিডিয়া">
        <div class="sb-soc-head"><?php echo esc_html($s['title']); ?></div>
        <?php if ($slides) : ?>
        <div class="sb-soc-stage">
            <?php foreach ($slides as $i => $sl) : ?>
                <div class="sb-soc-slide" data-kind="<?php echo esc_attr($sl['kind']); ?>"<?php echo !empty($sl['wa']) ? ' data-wa="1"' : ''; ?><?php echo isset($sl['src']) ? ' data-src="' . esc_url($sl['src']) . '"' : ''; ?> data-h="<?php echo isset($sl['h']) ? (int) $sl['h'] : 0; ?>"<?php echo !empty($sl['auto']) ? ' data-auto="1"' : ''; ?><?php echo !empty($sl['r']) ? ' data-r="' . esc_attr($sl['r']) . '"' : ''; ?> data-title="<?php echo esc_attr($sl['title']); ?>"<?php echo ($i && !$stack) ? ' hidden' : ''; ?>>
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
        <?php if (count($slides) > 1 && !$stack) : ?>
        <div class="sb-soc-nav">
            <button type="button" class="sb-soc-prev" aria-label="আগেরটি">‹</button>
            <span class="sb-soc-dots"></span>
            <button type="button" class="sb-soc-next" aria-label="পরেরটি">›</button>
        </div>
        <?php endif; endif; ?>
        <?php if ($show_btns && (($s['fb'] && !$fb_plugin) || $s['yt'] || $s['ig'])) : ?>
        <div class="sb-soc-btns">
            <?php if ($s['fb'] && !$fb_plugin) : ?><a class="sb-soc-btn is-fb" href="<?php echo esc_url($s['fb']); ?>" target="_blank" rel="noopener"><?php echo esc_html($s['fb_label']); ?></a><?php endif; ?>
            <?php if ($s['yt']) : ?><a class="sb-soc-btn is-yt" href="<?php echo esc_url($s['yt']); ?>" target="_blank" rel="noopener"><?php echo esc_html($s['yt_label']); ?></a><?php endif; ?>
            <?php if ($s['ig']) : ?><a class="sb-soc-btn is-ig" href="<?php echo esc_url($s['ig']); ?>" target="_blank" rel="noopener"><?php echo esc_html($s['ig_label']); ?></a><?php endif; ?>
        </div>
        <?php endif; ?>
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
