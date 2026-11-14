<?php
/**
 * Telegram input desk.
 * The owner posts into a PRIVATE Telegram channel (the bot is an admin there):
 *   - a notice (poster image and/or text)            -> AI fills the notice form, notice is published
 *   - /blog <keywords / title / subject>             -> AI writes the blog post, SEO + summary + FAQ, published
 * Only posts from the configured channel are accepted (nobody else can write into a private channel,
 * and the webhook is protected by Telegram's secret token).
 */
if (!defined('ABSPATH')) {
    exit;
}

function sb_desk_cfg() {
    $o = get_option('sb_desk', array());
    return array_merge(array('on' => 0, 'secret' => '', 'uid' => 0, 'publish' => 1), is_array($o) ? $o : array());
}

function sb_desk_uid() {
    $c = sb_desk_cfg();
    if ($c['uid'] && get_userdata((int) $c['uid'])) {
        return (int) $c['uid'];
    }
    $u = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID'));
    return $u ? (int) $u[0] : 1;
}

/* ------------------------------------------------------------ Telegram helpers */
function sb_tg_api($method, $args = array(), $timeout = 20) {
    $o = sb_notify_cfg();
    if ('' === $o['tg_token']) {
        return new WP_Error('sb_tg', 'টেলিগ্রামের টোকেন দেওয়া নেই।');
    }
    $r = wp_remote_post('https://api.telegram.org/bot' . $o['tg_token'] . '/' . $method, array('timeout' => $timeout, 'body' => $args));
    if (is_wp_error($r)) {
        return $r;
    }
    $j = json_decode(wp_remote_retrieve_body($r), true);
    if (empty($j['ok'])) {
        return new WP_Error('sb_tg', 'টেলিগ্রাম: ' . (is_array($j) && !empty($j['description']) ? $j['description'] : 'HTTP ' . (int) wp_remote_retrieve_response_code($r)));
    }
    return $j['result'];
}

function sb_desk_reply($m, $html) {
    sb_tg_api('sendMessage', array(
        'chat_id' => $m['chat']['id'], 'text' => mb_substr($html, 0, 3900), 'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true', 'reply_to_message_id' => (int) $m['message_id'],
    ));
}

/** Downloads a Telegram file into the Media Library; returns attachment id or WP_Error. */
function sb_desk_fetch_image($file_id, $title = '') {
    $f = sb_tg_api('getFile', array('file_id' => $file_id));
    if (is_wp_error($f)) {
        return $f;
    }
    $o   = sb_notify_cfg();
    $url = 'https://api.telegram.org/file/bot' . $o['tg_token'] . '/' . $f['file_path'];
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $tmp = download_url($url, 60);
    if (is_wp_error($tmp)) {
        return new WP_Error('sb_tg', 'ছবি নামানো যায়নি।');
    }
    $ext  = strtolower(pathinfo($f['file_path'], PATHINFO_EXTENSION)) ?: 'jpg';
    $name = 'telegram-' . gmdate('Ymd-His') . '-' . wp_generate_password(4, false) . '.' . $ext;
    $id   = media_handle_sideload(array('name' => $name, 'tmp_name' => $tmp), 0, $title);
    if (is_wp_error($id)) {
        @unlink($tmp); // phpcs:ignore
    }
    return $id;
}

/* ------------------------------------------------------------ webhook */
add_action('rest_api_init', function () {
    register_rest_route('sb/v1', '/tg', array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sb_desk_hook'));
});

function sb_desk_ack() {
    ignore_user_abort(true);
    if (function_exists('set_time_limit')) {
        @set_time_limit(420); // phpcs:ignore
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    $b = '{"ok":true}';
    header('Content-Type: application/json');
    header('Content-Length: ' . strlen($b));
    header('Connection: close');
    echo $b; // phpcs:ignore
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        flush();
    }
}

function sb_desk_chat_ok($chat) {
    $want = (string) sb_notify_cfg()['tg_chat'];
    if ('' === $want || !is_array($chat)) {
        return false;
    }
    if ('@' === $want[0]) {
        return !empty($chat['username']) && 0 === strcasecmp('@' . $chat['username'], $want);
    }
    return (string) $chat['id'] === $want;
}

function sb_desk_hook(WP_REST_Request $req) {
    $c   = sb_desk_cfg();
    $sec = (string) $req->get_header('x_telegram_bot_api_secret_token');
    if (!$c['on'] || '' === $c['secret'] || !hash_equals($c['secret'], $sec)) {
        return new WP_REST_Response(array('ok' => false), 403);
    }
    $u = $req->get_json_params();
    if (!is_array($u) || empty($u['update_id'])) {
        return new WP_REST_Response(array('ok' => true));
    }
    // Telegram may resend an update when the answer is slow: do each one once
    if (!add_option('sb_desk_u_' . (int) $u['update_id'], time(), '', 'no')) {
        return new WP_REST_Response(array('ok' => true));
    }
    $m = isset($u['channel_post']) ? $u['channel_post'] : (isset($u['message']) ? $u['message'] : null);
    if (!is_array($m) || empty($m['chat'])) {
        return new WP_REST_Response(array('ok' => true));
    }
    if (!sb_desk_chat_ok($m['chat'])) {
        update_option('sb_desk_last_chat', array('id' => (string) $m['chat']['id'], 'title' => (string) ($m['chat']['title'] ?? ''), 't' => time()), false);
        return new WP_REST_Response(array('ok' => true));
    }
    sb_desk_ack();
    try {
        wp_set_current_user(sb_desk_uid());
        sb_desk_process($m);
    } catch (Throwable $e) {
        update_option('sb_desk_err', mb_substr($e->getMessage(), 0, 300), false);
        sb_desk_reply($m, '⚠️ কাজটি শেষ হয়নি: ' . esc_html(mb_substr($e->getMessage(), 0, 200)));
    }
    // forget old update marks (keep the options table small)
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sb_desk_u_%%' AND option_value < %d", time() - DAY_IN_SECONDS)); // phpcs:ignore
    exit;
}

function sb_desk_help() {
    return "📌 <b>শাহীন ডেস্ক — কীভাবে পাঠাবেন</b>\n\n"
        . "<b>নোটিশ:</b> নোটিশের ছবি (ক্যাপশনে চাইলে লেখা) পাঠান, অথবা লেখার শুরুতে <code>/notice</code> দিয়ে লেখা পাঠান। একসাথে কয়েকটি ছবি পাঠালে একই নোটিশে যাবে।\n\n"
        . "<b>ব্লগ:</b> <code>/blog</code> লিখে কীওয়ার্ড/শিরোনাম/বিষয় লিখুন। যেমন:\n<code>/blog স্পেলিং স্টার ২০২৬ কীভাবে প্রস্তুতি নেবেন | কীওয়ার্ড: স্পেলিং স্টার, বানান প্রতিযোগিতা</code>\nছবি লাগলে একই বার্তায় ছবি জুড়ে দিন।\n\n"
        . "প্রকাশ হলেই লিংক পাঠাব।";
}

/** Splits "/blog text" style commands. Returns array(cmd, rest). */
function sb_desk_cmd($text) {
    if (preg_match('~^\s*(/?)(blog|ব্লগ|notice|নোটিশ|help|সাহায্য|start)(?:@\w+)?(?=[\s:：\-–]|$)[\s:：\-–]*(.*)$~isu', $text, $mm) && ('/' === $mm[1] || mb_strtolower($mm[2]) !== 'start')) {
        $k = mb_strtolower($mm[2]);
        $map = array('ব্লগ' => 'blog', 'নোটিশ' => 'notice', 'সাহায্য' => 'help', 'start' => 'help');
        return array(isset($map[$k]) ? $map[$k] : $k, trim($mm[3]));
    }
    return array('', trim($text));
}

function sb_desk_process($m) {
    $text = trim((string) (isset($m['text']) ? $m['text'] : (isset($m['caption']) ? $m['caption'] : '')));
    $fid  = '';
    if (!empty($m['photo']) && is_array($m['photo'])) {
        $last = end($m['photo']);
        $fid  = (string) $last['file_id'];
    } elseif (!empty($m['document']['file_id']) && 0 === strpos((string) ($m['document']['mime_type'] ?? ''), 'image/')) {
        $fid = (string) $m['document']['file_id'];
    }
    list($cmd, $rest) = sb_desk_cmd($text);
    if ('help' === $cmd) {
        sb_desk_reply($m, sb_desk_help());
        return;
    }
    if ('' === $cmd) {
        $cmd = $fid ? 'notice' : '';
    }
    if ('' === $cmd) {
        sb_desk_reply($m, "বুঝতে পারিনি। নোটিশ হলে ছবি পাঠান বা <code>/notice</code> দিয়ে লিখুন; ব্লগ হলে <code>/blog</code> দিয়ে লিখুন। বিস্তারিত: <code>/help</code>");
        return;
    }

    $gid = isset($m['media_group_id']) ? (string) $m['media_group_id'] : '';
    $aid = 0;
    if ($fid) {
        $aid = sb_desk_fetch_image($fid, $rest ? mb_substr($rest, 0, 60) : 'Telegram');
        if (is_wp_error($aid)) {
            sb_desk_reply($m, '⚠️ ' . esc_html($aid->get_error_message()));
            return;
        }
    }

    // several photos sent together: the first one does the work, the others join its post
    if ($gid) {
        $k = md5($gid);
        if (!add_option('sb_desk_lk_' . $k, time(), '', 'no')) {
            for ($i = 0; $i < 45; $i++) {
                $pid = (int) get_option('sb_desk_gp_' . $k, 0);
                if ($pid) {
                    break;
                }
                sleep(2);
            }
            if ($pid && $aid) {
                sb_desk_attach($pid, (int) $aid);
            }
            return;
        }
    }

    $status = sb_desk_cfg()['publish'] ? 'publish' : 'draft';
    $aids   = $aid ? array((int) $aid) : array();
    if ('notice' === $cmd) {
        sb_desk_reply($m, '⏳ নোটিশ পড়ছি ও সাজাচ্ছি…');
        $r = sb_desk_notice($rest, $aids, $status);
    } else {
        if (mb_strlen($rest) < 4) {
            sb_desk_reply($m, 'ব্লগের বিষয় বা কীওয়ার্ড লিখুন, যেমন: <code>/blog স্পেলিং স্টার প্রস্তুতি</code>');
            return;
        }
        sb_desk_reply($m, '⏳ ব্লগ লিখছি (সাধারণত ১–৩ মিনিট)…');
        $r = sb_desk_blog($rest, $aids, $status);
    }
    if ($gid && !is_wp_error($r)) {
        update_option('sb_desk_gp_' . md5($gid), (int) $r['id'], false);
    }
    if (is_wp_error($r)) {
        sb_desk_reply($m, '⚠️ ' . esc_html($r->get_error_message()));
        return;
    }
    sb_desk_reply($m, $r['msg']);
}

/** An extra image of an album joins the post (notice: image block; blog: end of article). */
function sb_desk_attach($pid, $aid) {
    if (get_post_type($pid) === 'sb_post') {
        $u = wp_get_attachment_url($aid);
        if ($u) {
            wp_update_post(array('ID' => $pid, 'post_content' => wp_slash(get_post_field('post_content', $pid) . "\n<figure><img src=\"" . esc_url($u) . "\" alt=\"" . esc_attr(get_the_title($pid)) . "\"></figure>")));
        }
        return;
    }
    $d = get_post_meta($pid, '_sn', true);
    if (is_array($d)) {
        foreach ($d['blocks'] as $i => $b) {
            if ('image' === ($b['t'] ?? '')) {
                $d['blocks'][$i]['ids'][] = $aid;
                update_post_meta($pid, '_sn', wp_slash($d));
                return;
            }
        }
    }
}

/* ------------------------------------------------------------ notice */
function sb_desk_notice($text, $aids, $status = 'publish') {
    if (!function_exists('csa_sn_ai_fill')) {
        return new WP_Error('sb_desk', 'নোটিশ সিস্টেম (Site Agent) চালু নেই।');
    }
    $r = csa_sn_ai_fill($text, $aids);
    if (is_wp_error($r)) {
        return $r;
    }
    $types  = csa_sn_opt('types');
    $type   = isset($r['type']) && isset($types[$r['type']]) ? $r['type'] : '';
    $blocks = array();
    if ($aids) {
        $blocks[] = array('t' => 'image', 'ids' => array_map('intval', $aids), 'cap' => '');
    }
    $rows = array();
    foreach ((array) ($r['cards'] ?? array()) as $c) {
        if (!empty($c['l']) && !empty($c['v'])) {
            $rows[] = array('l' => $c['l'], 'v' => $c['v'], 'm' => '');
        }
    }
    if ($rows) {
        $blocks[] = array('t' => 'cards', 'rows' => $rows);
    }
    $cond = array_values(array_filter(array_map('trim', (array) ($r['conditions'] ?? array()))));
    if ($cond) {
        $blocks[] = array('t' => 'list', 'h' => 'শর্ত ও নিয়ম', 'lines' => implode("\n", $cond));
    }
    if (!empty($r['note'])) {
        $blocks[] = array('t' => 'note', 'body' => $r['note']);
    }
    $ph = array_values(array_filter(array_map('trim', (array) ($r['phones'] ?? array()))));
    if ($ph) {
        $blocks[] = array('t' => 'phones', 'h' => 'যোগাযোগ', 'nums' => implode("\n", $ph));
    }
    $btn = array();
    foreach ((array) ($r['links'] ?? array()) as $l) {
        if (!empty($l['url']) && preg_match('#^https?://#i', $l['url'])) {
            $btn[] = array('label' => !empty($l['label']) ? $l['label'] : 'লিংক', 'url' => $l['url'], 'kind' => !empty($l['kind']) ? $l['kind'] : 'oth');
        }
    }
    if ($btn) {
        $blocks[] = array('t' => 'buttons', 'items' => $btn);
    }
    $d = csa_sn_clean(array('type' => $type, 'branch' => $r['branch'] ?? '', 'topic' => $r['topic'] ?? '', 'year' => $r['year'] ?? '', 'expiry' => $r['expiry'] ?? '', 'summary' => $r['summary'] ?? '', 'blocks' => $blocks));
    $s = csa_sn_sum_run($d);
    if (!is_wp_error($s)) {
        foreach (array('summary', 'seo_t', 'seo_d', 'seo_k', 'slug') as $k) {
            if ('' !== $s[$k]) {
                $d[$k] = $s[$k];
            }
        }
    }
    $d     = csa_sn_autoseo(0, $d);
    $title = (!is_wp_error($s) && $s['title']) ? $s['title'] : ($d['topic'] ?: 'নোটিশ');
    $arr   = array('post_type' => 'post', 'post_status' => $status, 'post_title' => $title, 'post_excerpt' => $d['summary'], 'post_author' => get_current_user_id());
    if (!empty($d['slug'])) {
        $arr['post_name'] = $d['slug'];
    }
    $id = wp_insert_post(wp_slash($arr), true);
    if (is_wp_error($id)) {
        return $id;
    }
    update_post_meta($id, '_sn_is', 1);
    update_post_meta($id, '_sn', wp_slash($d));
    update_post_meta($id, '_sn_type', $d['type']);
    update_post_meta($id, '_sn_branch', $d['branch']);
    update_post_meta($id, '_sn_expiry', $d['expiry']);
    $tm = term_exists('নোটিশ', 'category') ?: wp_insert_term('নোটিশ', 'category');
    if (!is_wp_error($tm)) {
        wp_set_post_categories($id, array((int) (is_array($tm) ? $tm['term_id'] : $tm)));
    }
    if ($aids) {
        set_post_thumbnail($id, (int) $aids[0]);
    }
    if (function_exists('csa_seo_set') && function_exists('csa_seo_plugin') && csa_seo_plugin()) {
        csa_seo_set($id, array('title' => $d['seo_t'], 'description' => $d['seo_d'], 'keyword' => $d['seo_k']), 'Notice SEO');
    }
    $url = 'publish' === $status ? get_permalink($id) : admin_url('post.php?post=' . $id . '&action=edit');
    return array('id' => $id, 'url' => $url, 'msg' => ('publish' === $status ? '✅ নোটিশ প্রকাশ হয়েছে' : '📝 নোটিশ খসড়া হয়েছে (প্রকাশ হয়নি)') . ":\n<b>" . esc_html($title) . "</b>\n" . esc_url($url) . ($d['expiry'] ? "\nশেষ তারিখ: " . esc_html($d['expiry']) : '') . "\n\n💡 AI ভুল পড়তে পারে, তাই তারিখ/নম্বর একবার মিলিয়ে নিন।");
}

/* ------------------------------------------------------------ blog */
function sb_desk_blog($text, $aids, $status = 'publish') {
    if (!function_exists('csa_jobs_ai_json') || !class_exists('SB_AI')) {
        return new WP_Error('sb_desk', 'ব্লগ বা Site Agent চালু নেই।');
    }
    $uid  = get_current_user_id();
    $cats = array();
    foreach ((array) get_terms(array('taxonomy' => 'sb_category', 'hide_empty' => false)) as $t) {
        if (!is_wp_error($t) && isset($t->name)) {
            $cats[(int) $t->term_id] = $t->name;
        }
    }
    $sys = 'You turn a short request into a blog brief for a school group website (Bangla). Return ONLY JSON: {"title":"Bangla blog title, max 70 characters (use the requester\'s title if one is given)","topic":"1-2 sentences: what the article covers","keywords":["3 to 6 search keywords, first is the main focus keyword"],"length":"short|medium|long","instruction":"extra instructions from the requester or empty","category":"exactly one of: ' . implode(' | ', $cats) . ' (or empty)"}. Never invent facts.';
    $b   = csa_jobs_ai_json($sys, $text, 'Desk: blog brief', 700);
    if (is_wp_error($b)) {
        return $b;
    }
    $title = sanitize_text_field($b['title'] ?? '');
    if ('' === $title) {
        return new WP_Error('sb_desk', 'শিরোনাম বানাতে পারিনি, আরেকটু স্পষ্ট করে লিখে আবার পাঠান।');
    }
    $kw  = array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) ($b['keywords'] ?? array())))), 0, 6);
    $len = in_array($b['length'] ?? '', array('short', 'medium', 'long'), true) ? $b['length'] : 'medium';
    $ctx = array('title' => $title, 'topic' => sanitize_text_field($b['topic'] ?? ''), 'excerpt' => '', 'content' => '', 'keywords' => $kw, 'length' => $len,
        'research' => true, 'source' => '', 'instruction' => sanitize_textarea_field($b['instruction'] ?? ''), 'faq_count' => 5, 'post_id' => 0, 'tier' => 1, 'model' => '',
        'ansi' => false, 'style' => 'para', 'words' => 0, 'mode' => 'both', 'paste' => '', 'ptype' => 'general', 'aud' => 'all', 'tone' => 'warm', 'brief' => '', 'beat_urls' => '');
    $cost = sb_feature_cost('article');
    $need = $cost + max(0, (int) sb_get('cost_research')) + sb_feature_cost('meta') + sb_feature_cost('summary') + sb_feature_cost('faq');
    $st   = SB_Credits::state($uid);
    if ($st['remaining'] < $need) {
        return new WP_Error('sb_desk', 'ব্যালেন্স যথেষ্ট নয় (দরকার ' . sb_money($need) . ', আছে ' . sb_money($st['remaining']) . ')।');
    }
    $notes = array();
    $out   = sb_ai_execute($uid, 'article', $ctx, $cost, true, $notes);
    if (empty($out['ok'])) { // web research can fail: try once without it
        $ctx['research'] = false;
        $out = sb_ai_execute($uid, 'article', $ctx, $cost, false, $notes);
    }
    if (empty($out['ok'])) {
        return new WP_Error('sb_desk', $out['message']);
    }
    $res  = $out['data']['result'];
    $html = (string) $res['text'];
    if (mb_strlen(wp_strip_all_tags($html)) < 300) {
        return new WP_Error('sb_desk', 'AI পুরো লেখা দেয়নি, আবার চেষ্টা করুন।');
    }
    $missing = !empty($res['missing']) ? (array) $res['missing'] : array();
    $ctx['content'] = $html;
    $meta = SB_AI::generate('meta', $ctx);
    $meta = is_wp_error($meta) ? '' : sanitize_textarea_field($meta['text']);
    if ($meta) {
        SB_Credits::charge($uid, 'meta', sb_feature_cost('meta'), 0);
    }
    $ctx['excerpt'] = $meta;

    $postarr = array('post_type' => 'sb_post', 'post_title' => $title, 'post_excerpt' => $meta, 'post_content' => $html, 'post_author' => $uid,
        'post_status' => ($missing || 'publish' !== $status) ? 'draft' : 'publish');
    $id = wp_insert_post(wp_slash($postarr), true);
    if (is_wp_error($id)) {
        return $id;
    }
    $g = sb_slug_guess($title, $kw);
    wp_update_post(array('ID' => $id, 'post_name' => $g !== '' ? $g : 'post-' . $id));
    update_post_meta($id, '_sb_meta_desc', $meta);
    update_post_meta($id, '_sb_keywords', $kw);
    update_post_meta($id, '_sb_topic', $ctx['topic']);
    update_post_meta($id, '_sb_seo_title', mb_substr($title, 0, 70));
    update_post_meta($id, '_sb_summary_pos', 'top');
    $ctx['post_id'] = $id;
    foreach (array('summary', 'faq', 'related') as $part) {
        $r = SB_AI::generate($part, $ctx);
        if (is_wp_error($r)) {
            continue;
        }
        if ('summary' === $part) {
            update_post_meta($id, '_sb_summary', sanitize_textarea_field($r['text']));
            update_post_meta($id, '_sb_inc_summary', '1');
        } elseif ('faq' === $part) {
            update_post_meta($id, '_sb_faq', $r['faq']);
            update_post_meta($id, '_sb_inc_faq', '1');
        } else {
            if (empty($r['items'])) {
                continue;
            }
            $ids = array();
            $why = array();
            foreach ($r['items'] as $it) {
                $ids[] = (int) $it['id'];
                $why[(int) $it['id']] = $it['why'];
            }
            update_post_meta($id, '_sb_related', $ids);
            update_post_meta($id, '_sb_related_why', $why);
        }
        SB_Credits::charge($uid, $part, sb_feature_cost($part), $id);
    }
    $cid = (int) array_search((string) ($b['category'] ?? ''), $cats, true);
    if ($cid) {
        sb_set_post_categories($id, $cid, array());
    }
    if ($aids) {
        set_post_thumbnail($id, (int) $aids[0]);
        foreach (array_slice($aids, 1) as $x) {
            sb_desk_attach($id, (int) $x);
        }
    }
    sb_seo_sync($id);
    sb_init_views($id);
    $live = 'publish' === get_post_status($id);
    $url  = $live ? get_permalink($id) : admin_url('admin.php?page=sb-new&post=' . $id);
    $msg  = ($live ? '✅ ব্লগ প্রকাশ হয়েছে' : '📝 ব্লগ খসড়া রাখা হয়েছে') . ":\n<b>" . esc_html($title) . "</b>\n" . esc_url($url) . "\nকীওয়ার্ড: " . esc_html(implode(', ', $kw)) . "\nসাথে SEO বর্ণনা, সারসংক্ষেপ, FAQ ও সম্পর্কিত লেখা যোগ হয়েছে।";
    if ($missing) {
        $msg .= "\n\n⚠️ লেখায় " . count($missing) . 'টি [তথ্য দরকার: …] চিহ্ন আছে, তাই প্রকাশ না করে খসড়ায় রেখেছি। তথ্য বসিয়ে প্রকাশ করুন।';
    }
    if (!$aids) {
        $msg .= "\n\n🖼️ ছবি দেননি, তাই লেখাটি ছবি ছাড়া। ছবি যোগ করতে ব্লগ এডিটর খুলুন।";
    }
    return array('id' => $id, 'url' => $url, 'msg' => $msg);
}

/* ------------------------------------------------------------ admin panel */
add_action('wp_ajax_sb_desk_op', function () {
    sb_ajax_guard('manage_options');
    $op = sanitize_key((string) sb_req('op'));
    $c  = sb_desk_cfg();
    $n  = sb_notify_cfg();
    if ('save' === $op) {
        $c['on']      = '1' === (string) sb_req('on') ? 1 : 0;
        $c['publish'] = '1' === (string) sb_req('publish') ? 1 : 0;
        update_option('sb_desk', $c, false);
        wp_send_json_success(array('msg' => 'সংরক্ষিত ✓'));
    }
    if ('hook' === $op || 'unhook' === $op) {
        if ('' === $n['tg_token']) {
            wp_send_json_error(array('message' => 'আগে উপরের বাক্সে বটের টোকেন সেভ করুন।'));
        }
        if ('unhook' === $op) {
            $r = sb_tg_api('deleteWebhook');
            $c['on'] = 0;
            update_option('sb_desk', $c, false);
            wp_send_json_success(array('msg' => is_wp_error($r) ? $r->get_error_message() : 'ডেস্ক বন্ধ ✓'));
        }
        if ('' === $c['secret']) {
            $c['secret'] = wp_generate_password(40, false, false);
        }
        $c['on'] = 1;
        update_option('sb_desk', $c, false);
        $r = sb_tg_api('setWebhook', array('url' => rest_url('sb/v1/tg'), 'secret_token' => $c['secret'], 'allowed_updates' => wp_json_encode(array('channel_post', 'message')), 'drop_pending_updates' => 'true'));
        if (is_wp_error($r)) {
            wp_send_json_error(array('message' => $r->get_error_message()));
        }
        $i = sb_tg_api('getWebhookInfo');
        wp_send_json_success(array('msg' => 'চালু ✓ ' . (is_array($i) && !empty($i['last_error_message']) ? '(টেলিগ্রামের অভিযোগ: ' . $i['last_error_message'] . ')' : '')));
    }
    if ('info' === $op) {
        $i = sb_tg_api('getWebhookInfo');
        $l = get_option('sb_desk_last_chat', array());
        wp_send_json_success(array('msg' => is_wp_error($i) ? $i->get_error_message() : ('ওয়েবহুক: ' . (!empty($i['url']) ? 'সেট' : 'নেই') . '; অপেক্ষায় ' . (int) ($i['pending_update_count'] ?? 0) . ($i['last_error_message'] ?? '' ? '; শেষ ত্রুটি: ' . $i['last_error_message'] : '')) . (!empty($l['id']) ? ' | অন্য চ্যাট থেকে বার্তা এসেছিল: ' . $l['id'] . ' ' . $l['title'] : '') . (get_option('sb_desk_err') ? ' | ডেস্ক ত্রুটি: ' . get_option('sb_desk_err') : '')));
    }
    if ('test_notice' === $op || 'test_blog' === $op) {   // writes a DRAFT only
        wp_set_current_user(sb_desk_uid());
        $r = 'test_notice' === $op
            ? sb_desk_notice("আবেদনের নোটিশ\nশাহীন স্কুল এন্ড ক্যাডেট কোচিং, মাদারীপুর শাখায় সহকারী শিক্ষক নিয়োগ ২০২৭। আবেদনের শেষ তারিখ ২০ ডিসেম্বর ২০২৬। যোগ্যতা: স্নাতক পাস। যোগাযোগ: 01700000000", array(), 'draft')
            : sb_desk_blog(sanitize_textarea_field((string) sb_req('text')) ?: 'শিশুর বানান শেখার সহজ ৫টি উপায়', array(), 'draft');
        wp_send_json_success(array('msg' => is_wp_error($r) ? 'ত্রুটি: ' . $r->get_error_message() : 'খসড়া তৈরি: ' . $r['url'], 'raw' => is_wp_error($r) ? '' : wp_strip_all_tags($r['msg'])));
    }
    wp_send_json_error(array('message' => 'অজানা কাজ।'));
});

function sb_desk_panel() {
    $c = sb_desk_cfg();
    ?>
    <div id="sb-dk" style="border:1px solid #d1d5db;border-radius:8px;padding:12px;margin:12px 0;background:#eff6ff">
        <b>🗂️ টেলিগ্রাম ইনপুট ডেস্ক (নোটিশ ও ব্লগ লেখা)</b>
        <p class="sba-muted" style="margin:4px 0 8px">উপরের বাক্সে টোকেন ও চ্যানেল সেভ করার পর এখানে "চালু করুন" চাপুন। তারপর প্রাইভেট চ্যানেলে নোটিশের ছবি পাঠালে নোটিশ হবে; <code>/blog বিষয়…</code> লিখলে ব্লগ হবে। <b>শুধু ওই চ্যানেলের পোস্টই নেওয়া হয়।</b></p>
        <label><input type="checkbox" id="dk-pub" <?php checked($c['publish'], 1); ?>> সরাসরি প্রকাশ করো (বন্ধ থাকলে সব খসড়া হয়ে থাকবে)</label>
        <p><button type="button" class="button button-primary" id="dk-on"><?php echo $c['on'] ? '🔄 আবার সংযোগ দিন' : '▶️ ডেস্ক চালু করুন'; ?></button> <button type="button" class="button" id="dk-off">⏹️ বন্ধ</button> <button type="button" class="button" id="dk-info">ℹ️ অবস্থা</button> <button type="button" class="button" id="dk-tn">🧪 নোটিশ টেস্ট (খসড়া)</button> <button type="button" class="button" id="dk-tb">🧪 ব্লগ টেস্ট (খসড়া)</button> <span id="dk-msg" class="sba-msg"></span></p>
    </div>
    <script>
    (function(){var $=function(s){return document.querySelector(s);},aj=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nn=<?php echo wp_json_encode(wp_create_nonce('sb_admin')); ?>;
        function call(op,x){$('#dk-msg').textContent='… (AI কাজ হলে ১-৩ মিনিট লাগতে পারে)';return fetch(aj,{method:'POST',credentials:'same-origin',body:new URLSearchParams(Object.assign({action:'sb_desk_op',nonce:nn,op:op},x||{}))}).then(function(r){return r.json();}).then(function(j){$('#dk-msg').textContent=j.success?j.data.msg:((j.data&&j.data.message)||'ত্রুটি');});}
        $('#dk-on').onclick=function(){call('save',{on:'1',publish:$('#dk-pub').checked?'1':'0'}).then(function(){return call('hook');});};
        $('#dk-off').onclick=function(){call('unhook');};
        $('#dk-info').onclick=function(){call('info');};
        $('#dk-tn').onclick=function(){call('test_notice');};
        $('#dk-tb').onclick=function(){call('test_blog');};
    })();
    </script>
    <?php
}
