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
    if (!empty($GLOBALS['sb_desk_sim'])) {   // dry run from the admin panel: nothing is sent to Telegram
        $GLOBALS['sb_desk_out'][] = array($method, $args);
        return array('message_id' => 1);
    }
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
function sb_desk_fetch_image($file_id, $title = '', $kind = 'img') {
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
    $cb = isset($u['callback_query']) && is_array($u['callback_query']) ? $u['callback_query'] : null;
    $m  = $cb ? (isset($cb['message']) ? $cb['message'] : null) : (isset($u['channel_post']) ? $u['channel_post'] : (isset($u['message']) ? $u['message'] : null));
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
        if ($cb) {
            sb_tg_api('answerCallbackQuery', array('callback_query_id' => $cb['id']));
            sb_desk_step($m, array('cb' => (string) ($cb['data'] ?? '')));
        } else {
            sb_desk_message($m);
        }
    } catch (Throwable $e) {
        update_option('sb_desk_err', mb_substr($e->getMessage(), 0, 300), false);
        sb_desk_say($m, '⚠️ কাজটি শেষ হয়নি: ' . esc_html(mb_substr($e->getMessage(), 0, 200)));
    }
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sb_desk_u_%%' AND option_value < %d", time() - DAY_IN_SECONDS)); // phpcs:ignore
    exit;
}

/* ------------------------------------------------------------ conversation engine */
function sb_desk_help() {
    return "📌 <b>শাহীন ডেস্ক</b>\n\n/menu — কী করবেন বেছে নিন (ধাপে ধাপে প্রশ্ন করব)\n/cancel — চলমান কাজ বাতিল\n\n<b>শর্টকাট:</b>\n<code>/blog বিষয় বা কীওয়ার্ড</code> — সরাসরি ব্লগ লিখে প্রকাশ\n<code>/notice লেখা</code> — সরাসরি নোটিশ\nনোটিশের ছবি সরাসরি পাঠালে নোটিশের ধাপ শুরু হবে।";
}

function sb_desk_say($m, $html, $kb = array()) {
    $a = array('chat_id' => $m['chat']['id'], 'text' => mb_substr($html, 0, 3900), 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true');
    if ($kb) {
        $a['reply_markup'] = wp_json_encode(array('inline_keyboard' => $kb));
    }
    return sb_tg_api('sendMessage', $a);
}

function sb_desk_btn($label, $data) {
    return array('text' => $label, 'callback_data' => mb_substr($data, 0, 60));
}

function sb_desk_stkey($m) {
    return 'sb_desk_st_' . md5((string) $m['chat']['id']);
}

function sb_desk_state($m) {
    $s = get_option(sb_desk_stkey($m), array());
    if (!is_array($s) || empty($s['flow']) || (int) ($s['t'] ?? 0) < time() - 3 * HOUR_IN_SECONDS) {
        return array();
    }
    return $s;
}

function sb_desk_save($m, $s) {
    $s['t'] = time();
    update_option(sb_desk_stkey($m), $s, false);
}

function sb_desk_clear($m) {
    delete_option(sb_desk_stkey($m));
}

/** A small lock so photos sent together do not overwrite each other's answers. */
function sb_desk_lock($m) {
    $k = 'sb_desk_lock_' . md5((string) $m['chat']['id']);
    for ($i = 0; $i < 60; $i++) {
        if (add_option($k, time(), '', 'no')) {
            return $k;
        }
        $t = (int) get_option($k, 0);
        if ($t && $t < time() - 90) {
            delete_option($k);
        }
        usleep(500000);
    }
    return $k;
}

function sb_desk_unlock($k) {
    delete_option($k);
}

function sb_desk_menu($m) {
    sb_desk_clear($m);
    sb_desk_say($m, "🗂️ <b>কী করতে চান?</b>", array(
        array(sb_desk_btn('📢 নোটিশ', 'm:notice'), sb_desk_btn('✍️ ব্লগ', 'm:blog')),
        array(sb_desk_btn('📰 সংবাদ (শীঘ্রই)', 'm:soon'), sb_desk_btn('⭐ স্পেলিং স্টার (শীঘ্রই)', 'm:soon')),
    ));
}

/** Flow definitions: ordered steps. kind: choice | text | collect. */
function sb_desk_steps($flow, $d) {
    if ('notice' === $flow) {
        return array('type', 'branch', 'details', 'pdf', 'apply', 'confirm');
    }
    $st = array('topic', 'title', 'keywords', 'body');
    if (in_array($d['body'] ?? '', array('paste', 'desc'), true)) {
        $st[] = 'bodytext';
    }
    return array_merge($st, array('length', 'image', 'confirm'));
}

function sb_desk_ask($m, $s) {
    $steps = sb_desk_steps($s['flow'], $s['d']);
    $k     = $steps[$s['i']];
    $skip  = sb_desk_btn('⏭️ বাদ', 'skip');
    $x     = sb_desk_btn('❌ বাতিল', 'cancel');
    switch ($k) {
        case 'type':
            $row = array();
            foreach ((array) csa_sn_opt('types') as $key => $t) {
                $row[] = array(sb_desk_btn($t['label'], 't:' . $key));
            }
            $row[] = array(sb_desk_btn('📄 অন্য / সাধারণ নোটিশ', 't:'), $x);
            return sb_desk_say($m, "📢 <b>নোটিশের ধরন কী?</b>", $row);
        case 'branch':
            return sb_desk_say($m, "📍 <b>কোন শাখা বা জায়গা?</b> (যেমন: মাদারীপুর শাখা)\nলিখে পাঠান, অথবা নোটিশ থেকে AI নিক।", array(array(sb_desk_btn('🤖 AI নিক / সবার জন্য', 'skip'), $x)));
        case 'details':
            $n = count($s['d']['aids'] ?? array());
            return sb_desk_say($m, "📝 <b>নোটিশের তথ্য দিন</b> — ছবি (পোস্টার), লেখা বা দুটোই পাঠান। একাধিক হলে পরপর পাঠান।" . ($n ? "\nএ পর্যন্ত: " . $n . 'টি ছবি' . (!empty($s['d']['text']) ? ' + লেখা' : '') : '') . "\nশেষ হলে নিচের বোতাম চাপুন।", array(array(sb_desk_btn('✅ দেওয়া শেষ', 'done'), $x)));
        case 'pdf':
            return sb_desk_say($m, "📎 <b>ডাউনলোডের PDF/ফাইল আছে?</b>\nPDF সরাসরি পাঠান, অথবা ডাউনলোড লিংক লিখে পাঠান।", array(array($skip, $x)));
        case 'apply':
            return sb_desk_say($m, "🔗 <b>আবেদনের লিংক বা Google Form আছে?</b>\nলিংক পাঠান। Google Form হলে নোটিশের পাতায় ফরম বসে যাবে।", array(array($skip, $x)));
        case 'topic':
            return sb_desk_say($m, "✍️ <b>ব্লগের বিষয় কী?</b> সংক্ষেপে লিখে পাঠান (এটা বাধ্যতামূলক, বাকি সবকিছু AI করতে পারে)।", array(array($x)));
        case 'title':
            return sb_desk_say($m, "🏷️ <b>শিরোনাম?</b> লিখে পাঠান, অথবা AI বানাক।", array(array(sb_desk_btn('🤖 AI বানাক', 'ai'), $x)));
        case 'keywords':
            return sb_desk_say($m, "🔑 <b>কীওয়ার্ড?</b> কমা দিয়ে লিখুন, অথবা AI ঠিক করুক।", array(array(sb_desk_btn('🤖 AI ঠিক করুক', 'ai'), $x)));
        case 'body':
            return sb_desk_say($m, "📄 <b>লেখা কীভাবে হবে?</b>", array(
                array(sb_desk_btn('🤖 AI পুরো লিখুক', 'b:ai')),
                array(sb_desk_btn('📝 আমি সংক্ষিপ্ত বিবরণ দেব, AI বিস্তারিত লিখুক', 'b:desc')),
                array(sb_desk_btn('📋 আমার পুরো লেখা তৈরি আছে', 'b:paste')),
                array($x),
            ));
        case 'bodytext':
            return sb_desk_say($m, ('paste' === $s['d']['body'] ? "📋 <b>পুরো লেখাটি পাঠান</b> (এক বার্তায়)।" : "📝 <b>বিবরণটি লিখে পাঠান।</b> এর ওপর ভিত্তি করে AI বিস্তারিত লিখবে।"), array(array($x)));
        case 'length':
            return sb_desk_say($m, "📏 <b>লেখা কত বড় হবে?</b>", array(array(sb_desk_btn('ছোট', 'l:short'), sb_desk_btn('মাঝারি', 'l:medium'), sb_desk_btn('বড়', 'l:long')), array($x)));
        case 'image':
            return sb_desk_say($m, "🖼️ <b>ছবি?</b> আপনার ছবি এখনই পাঠাতে পারেন (প্রথমটি মূল ছবি হবে)।\n<i>AI দিয়ে ছবি বানানো পরের ধাপে আসছে।</i>", array(array(sb_desk_btn('⏭️ ছবি ছাড়া', 'skip'), sb_desk_btn('✅ ছবি দিয়েছি', 'done')), array($x)));
        case 'confirm':
            $d = $s['d'];
            if ('notice' === $s['flow']) {
                $tl = ($d['type'] ?? '') !== '' ? (csa_sn_opt('types')[$d['type']]['label'] ?? $d['type']) : 'সাধারণ';
                $sum = "📢 <b>নোটিশ</b>\nধরন: " . esc_html($tl) . "\nশাখা: " . esc_html($d['branch'] ?: 'AI নেবে') . "\nছবি: " . count($d['aids'] ?? array()) . "টি; লেখা: " . (!empty($d['text']) ? 'আছে' : 'নেই') . "\nPDF/ডাউনলোড: " . (!empty($d['pdf_aid']) || !empty($d['pdf_url']) ? 'আছে' : 'নেই') . "\nআবেদন/ফরম: " . (!empty($d['apply']) ? esc_html($d['apply']) : 'নেই');
            } else {
                $bm = array('ai' => 'AI পুরো লিখবে', 'desc' => 'আপনার বিবরণ থেকে AI', 'paste' => 'আপনার তৈরি লেখা');
                $sum = "✍️ <b>ব্লগ</b>\nবিষয়: " . esc_html($d['topic']) . "\nশিরোনাম: " . esc_html(($d['title'] ?? '') ?: 'AI') . "\nকীওয়ার্ড: " . esc_html(($d['keywords'] ?? '') ?: 'AI') . "\nলেখা: " . $bm[$d['body'] ?? 'ai'] . "\nদৈর্ঘ্য: " . esc_html($d['length'] ?? 'medium') . "\nছবি: " . count($d['aids'] ?? array()) . 'টি';
            }
            return sb_desk_say($m, $sum . "\n\nপ্রকাশের আগে এটাই শেষ দেখা। কী করব?", array(array(sb_desk_btn('✅ প্রকাশ করো', 'go:publish')), array(sb_desk_btn('📝 শুধু খসড়া', 'go:draft'), $x)));
    }
}

function sb_desk_start($m, $flow, $d = array()) {
    $s = array('flow' => $flow, 'i' => 0, 'd' => $d);
    if ('notice' === $flow && !function_exists('csa_sn_opt')) {
        return sb_desk_say($m, '⚠️ নোটিশ সিস্টেম চালু নেই।');
    }
    sb_desk_save($m, $s);
    sb_desk_ask($m, $s);
}

/** Plain messages: commands, shortcuts, or an answer to the current question. */
function sb_desk_message($m) {
    $text = trim((string) (isset($m['text']) ? $m['text'] : (isset($m['caption']) ? $m['caption'] : '')));
    list($cmd, $rest) = sb_desk_cmd($text);
    $in = array('t' => $text);
    // attached files
    $fid = '';
    $kind = '';
    if (!empty($m['photo']) && is_array($m['photo'])) {
        $last = end($m['photo']);
        $fid  = (string) $last['file_id'];
        $kind = 'img';
    } elseif (!empty($m['document']['file_id'])) {
        $fid  = (string) $m['document']['file_id'];
        $kind = 0 === strpos((string) ($m['document']['mime_type'] ?? ''), 'image/') ? 'img' : 'file';
        $in['fname'] = (string) ($m['document']['file_name'] ?? '');
    }
    $lock = sb_desk_lock($m);
    try {
        if ($fid) {
            $a = sb_desk_fetch_image($fid, $rest ? mb_substr($rest, 0, 60) : 'Telegram', $kind);
            if (is_wp_error($a)) {
                sb_desk_say($m, '⚠️ ' . esc_html($a->get_error_message()));
                sb_desk_unlock($lock);
                return;
            }
            $in[$kind] = (int) $a;
        }
        $s = sb_desk_state($m);
        if (in_array($cmd, array('menu', 'help', 'cancel'), true) || ('' === $cmd && !$s && !$fid)) {
            sb_desk_unlock($lock);
            if ('help' === $cmd) {
                sb_desk_say($m, sb_desk_help());
            } elseif ('cancel' === $cmd) {
                sb_desk_clear($m);
                sb_desk_say($m, 'বাতিল ✓');
            } else {
                sb_desk_menu($m);
            }
            return;
        }
        if ('blog' === $cmd || ('notice' === $cmd && !$s)) { // direct shortcuts
            sb_desk_unlock($lock);
            sb_desk_quick($m, $cmd, $rest, !empty($in['img']) ? array($in['img']) : array());
            return;
        }
        if (!$s) { // a photo with no running flow: start a notice with it
            sb_desk_start($m, 'notice', array('aids' => array_values(array_filter(array((int) ($in['img'] ?? 0)))), 'text' => $rest, 'type' => '', 'branch' => ''));
            $s = sb_desk_state($m);
            sb_desk_save($m, array_merge($s, array('i' => 0)));
            sb_desk_unlock($lock);
            return;
        }
    } catch (Throwable $e) {
        sb_desk_unlock($lock);
        throw $e;
    }
    sb_desk_unlock($lock);
    sb_desk_step($m, $in);
}

/** One answer (text, file or button) for the current question. */
function sb_desk_step($m, $in) {
    $lock = sb_desk_lock($m);
    $s    = sb_desk_state($m);
    if (!$s) {
        sb_desk_unlock($lock);
        sb_desk_say($m, 'কোনো কাজ চলছে না। শুরু করতে /menu লিখুন।');
        return;
    }
    $steps = sb_desk_steps($s['flow'], $s['d']);
    $k     = $steps[$s['i']];
    $cb    = isset($in['cb']) ? $in['cb'] : '';
    $t     = isset($in['t']) ? trim($in['t']) : '';
    $stay  = false;
    $d     = $s['d'];
    if ('cancel' === $cb) {
        sb_desk_clear($m);
        sb_desk_unlock($lock);
        sb_desk_say($m, 'বাতিল ✓ (/menu দিয়ে আবার শুরু করুন)');
        return;
    }
    if (0 === strpos($cb, 'm:')) { // menu choice
        sb_desk_unlock($lock);
        $f = substr($cb, 2);
        if ('soon' === $f) {
            sb_desk_say($m, 'এটা পরের ধাপে আসছে। এখন নোটিশ ও ব্লগ চলছে।');
        } else {
            sb_desk_start($m, $f, 'notice' === $f ? array('aids' => array(), 'text' => '', 'type' => '', 'branch' => '') : array('aids' => array()));
        }
        return;
    }
    switch ($k) {
        case 'type':
            if (0 === strpos($cb, 't:')) {
                $d['type'] = substr($cb, 2);
            } else {
                $stay = true;
            }
            break;
        case 'branch':
            if ('skip' === $cb) {
                $d['branch'] = '';
            } elseif ('' !== $t && '' === $cb) {
                $d['branch'] = sanitize_text_field($t);
            } else {
                $stay = true;
            }
            break;
        case 'details':
            if (!empty($in['img'])) {
                $d['aids'][] = (int) $in['img'];
            }
            if ('' !== $t && '' === $cb) {
                $d['text'] = trim(($d['text'] ?? '') . "\n" . $t);
            }
            if ('done' !== $cb) {
                $stay = true;
                $s['d'] = $d;
                sb_desk_save($m, $s);
                sb_desk_unlock($lock);
                // many photos arrive together: show the "got it" note only for the last one (debounce)
                $d2 = $d;
                usleep(1200000);
                $now = sb_desk_state($m);
                if ($now && count($now['d']['aids'] ?? array()) === count($d2['aids'] ?? array()) && ($now['d']['text'] ?? '') === ($d2['text'] ?? '') && empty($now['ack'])) {
                    $now['ack'] = 1;
                    sb_desk_save($m, $now);
                    sb_desk_say($m, '👍 পেয়েছি (' . count($d2['aids']) . 'টি ছবি' . (!empty($d2['text']) ? ' + লেখা' : '') . '). আরও থাকলে পাঠান, নইলে "✅ দেওয়া শেষ" চাপুন।', array(array(sb_desk_btn('✅ দেওয়া শেষ', 'done'), sb_desk_btn('❌ বাতিল', 'cancel'))));
                    $now['ack'] = 0;
                    sb_desk_save($m, $now);
                }
                return;
            }
            if (!$d['aids'] && '' === trim((string) ($d['text'] ?? ''))) {
                $stay = true;
                sb_desk_say($m, 'কিছু পাঠাননি — ছবি বা লেখা পাঠান।');
            }
            break;
        case 'pdf':
            if ('skip' === $cb) {
                break;
            }
            if (!empty($in['file'])) {
                $d['pdf_aid'] = (int) $in['file'];
            } elseif (preg_match('#^https?://\S+$#i', $t)) {
                $d['pdf_url'] = esc_url_raw($t);
            } else {
                $stay = true;
            }
            break;
        case 'apply':
            if ('skip' === $cb) {
                break;
            }
            if (preg_match('#^https?://\S+$#i', $t)) {
                $d['apply'] = esc_url_raw($t);
            } else {
                $stay = true;
            }
            break;
        case 'topic':
            if ('' !== $t && '' === $cb) {
                $d['topic'] = sanitize_text_field(mb_substr($t, 0, 400));
            } else {
                $stay = true;
            }
            break;
        case 'title':
            if ('ai' === $cb) {
                $d['title'] = '';
            } elseif ('' !== $t && '' === $cb) {
                $d['title'] = sanitize_text_field(mb_substr($t, 0, 150));
            } else {
                $stay = true;
            }
            break;
        case 'keywords':
            if ('ai' === $cb) {
                $d['keywords'] = '';
            } elseif ('' !== $t && '' === $cb) {
                $d['keywords'] = sanitize_text_field(mb_substr($t, 0, 300));
            } else {
                $stay = true;
            }
            break;
        case 'body':
            if (0 === strpos($cb, 'b:')) {
                $d['body'] = substr($cb, 2);
            } else {
                $stay = true;
            }
            break;
        case 'bodytext':
            if ('' !== $t && '' === $cb) {
                $d['bodytext'] = sanitize_textarea_field($t);
            } else {
                $stay = true;
            }
            break;
        case 'length':
            if (0 === strpos($cb, 'l:')) {
                $d['length'] = substr($cb, 2);
            } else {
                $stay = true;
            }
            break;
        case 'image':
            if (!empty($in['img'])) {
                $d['aids'][] = (int) $in['img'];
                $s['d'] = $d;
                sb_desk_save($m, $s);
                sb_desk_unlock($lock);
                sb_desk_say($m, '👍 ছবি পেয়েছি। আরও থাকলে পাঠান, নইলে "✅ ছবি দিয়েছি" চাপুন।', array(array(sb_desk_btn('✅ ছবি দিয়েছি', 'done'), sb_desk_btn('❌ বাতিল', 'cancel'))));
                return;
            }
            if ('skip' !== $cb && 'done' !== $cb) {
                $stay = true;
            }
            break;
        case 'confirm':
            if (0 === strpos($cb, 'go:')) {
                $mode = substr($cb, 3);
                sb_desk_clear($m);
                sb_desk_unlock($lock);
                sb_desk_run($m, $s['flow'], $d, 'publish' === $mode && sb_desk_cfg()['publish'] ? 'publish' : 'draft');
                return;
            }
            $stay = true;
            break;
    }
    if (!$stay) {
        $s['i']++;
    }
    $s['d'] = $d;
    sb_desk_save($m, $s);
    sb_desk_unlock($lock);
    if ($stay && 'details' !== $k) {
        sb_desk_say($m, 'এই ধাপে নিচের বোতাম বা সঠিক উত্তর দিন 🙂');
    }
    sb_desk_ask($m, $s);
}

/** Runs the finished request in the background and reports the link. */
function sb_desk_run($m, $flow, $d, $status) {
    sb_desk_say($m, 'notice' === $flow ? '⏳ নোটিশ পড়ছি ও সাজাচ্ছি…' : '⏳ ব্লগ লিখছি ও SEO করছি (সাধারণত ১–৩ মিনিট)…');
    if ('notice' === $flow) {
        $r = sb_desk_notice((string) ($d['text'] ?? ''), (array) ($d['aids'] ?? array()), $status, $d);
    } else {
        $r = sb_desk_blog($d['topic'], (array) ($d['aids'] ?? array()), $status, $d);
    }
    if (is_wp_error($r)) {
        sb_desk_say($m, '⚠️ ' . esc_html($r->get_error_message()));
        return;
    }
    sb_desk_say($m, $r['msg'] . "\n\n/menu — আরেকটি কাজ");
}

/** /blog text and /notice text typed directly (no questions). */
function sb_desk_quick($m, $cmd, $rest, $aids) {
    if ('notice' === $cmd) {
        sb_desk_say($m, '⏳ নোটিশ পড়ছি ও সাজাচ্ছি…');
        $r = sb_desk_notice($rest, $aids, sb_desk_cfg()['publish'] ? 'publish' : 'draft');
    } else {
        if (mb_strlen($rest) < 4) {
            sb_desk_say($m, 'ব্লগের বিষয় বা কীওয়ার্ড লিখুন, যেমন: <code>/blog স্পেলিং স্টার প্রস্তুতি</code>');
            return;
        }
        sb_desk_say($m, '⏳ ব্লগ লিখছি (সাধারণত ১–৩ মিনিট)…');
        $r = sb_desk_blog($rest, $aids, sb_desk_cfg()['publish'] ? 'publish' : 'draft');
    }
    sb_desk_say($m, is_wp_error($r) ? '⚠️ ' . esc_html($r->get_error_message()) : $r['msg']);
}

/** Splits "/blog text" style commands. Returns array(cmd, rest). */
function sb_desk_cmd($text) {
    if (preg_match('~^\s*(/?)(blog|ব্লগ|notice|নোটিশ|help|সাহায্য|start|menu|মেনু|cancel|বাতিল)(?:@\w+)?(?=[\s:：\-–]|$)[\s:：\-–]*(.*)$~isu', $text, $mm) && ('/' === $mm[1] || !in_array(mb_strtolower($mm[2]), array('start', 'menu', 'cancel', 'help'), true))) {
        $k   = mb_strtolower($mm[2]);
        $map = array('ব্লগ' => 'blog', 'নোটিশ' => 'notice', 'সাহায্য' => 'help', 'start' => 'menu', 'মেনু' => 'menu', 'বাতিল' => 'cancel');
        return array(isset($map[$k]) ? $map[$k] : $k, trim($mm[3]));
    }
    return array('', trim($text));
}

/* ------------------------------------------------------------ notice */
function sb_desk_notice($text, $aids, $status = 'publish', $o = array()) {
    if (!function_exists('csa_sn_ai_fill')) {
        return new WP_Error('sb_desk', 'নোটিশ সিস্টেম (Site Agent) চালু নেই।');
    }
    $r = csa_sn_ai_fill($text, $aids);
    if (is_wp_error($r)) {
        return $r;
    }
    $types  = csa_sn_opt('types');
    $type   = isset($r['type']) && isset($types[$r['type']]) ? $r['type'] : '';
    if (!empty($o['type']) && isset($types[$o['type']])) {
        $type = $o['type'];
    }
    if (!empty($o['branch'])) {
        $r['branch'] = $o['branch'];
    }
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
    $pdf = !empty($o['pdf_aid']) ? wp_get_attachment_url((int) $o['pdf_aid']) : (!empty($o['pdf_url']) ? $o['pdf_url'] : '');
    if ($pdf) {
        $btn[] = array('label' => 'ডাউনলোড করুন', 'url' => $pdf, 'kind' => 'pdf');
    }
    $gform = '';
    if (!empty($o['apply'])) {
        if (false !== strpos($o['apply'], 'docs.google.com/forms') || false !== strpos($o['apply'], 'forms.gle')) {
            $gform = $o['apply'];
        } else {
            $btn[] = array('label' => 'আবেদন করুন', 'url' => $o['apply'], 'kind' => 'apply');
        }
    }
    if ($btn) {
        $blocks[] = array('t' => 'buttons', 'items' => $btn);
    }
    if ($gform) {
        $blocks[] = array('t' => 'gform', 'h' => 'অনলাইনে আবেদন করুন', 'url' => $gform, 'height' => 900);
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
function sb_desk_blog($text, $aids, $status = 'publish', $o = array()) {
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
    if (!empty($o['title'])) {
        $title = sanitize_text_field($o['title']);
    }
    $kw  = array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) ($b['keywords'] ?? array())))), 0, 6);
    if (!empty($o['keywords'])) {
        $kw = array_slice(array_values(array_filter(array_map('trim', preg_split('/[,،;]+/u', sanitize_text_field($o['keywords']))))), 0, 6);
    }
    $len = in_array($b['length'] ?? '', array('short', 'medium', 'long'), true) ? $b['length'] : 'medium';
    if (!empty($o['length']) && in_array($o['length'], array('short', 'medium', 'long'), true)) {
        $len = $o['length'];
    }
    $ctx = array('title' => $title, 'topic' => sanitize_text_field($b['topic'] ?? ''), 'excerpt' => '', 'content' => '', 'keywords' => $kw, 'length' => $len,
        'research' => true, 'source' => '', 'instruction' => sanitize_textarea_field($b['instruction'] ?? ''), 'faq_count' => 5, 'post_id' => 0, 'tier' => 1, 'model' => '',
        'ansi' => false, 'style' => 'para', 'words' => 0, 'mode' => 'both', 'paste' => '', 'ptype' => 'general', 'aud' => 'all', 'tone' => 'warm', 'brief' => '', 'beat_urls' => '');
    $ready = ('paste' === ($o['body'] ?? '') && !empty($o['bodytext']));
    if ('desc' === ($o['body'] ?? '') && !empty($o['bodytext'])) {
        $ctx['paste'] = $o['bodytext'];
        $ctx['mode']  = 'paste';
        $ctx['instruction'] = trim($ctx['instruction'] . "\nমালিকের বিবরণ থেকে বিস্তারিত লেখো; বিবরণের বাইরে তথ্য বানাবে না।");
    }
    $cost = sb_feature_cost('article');
    if ($ready) {
        $cost = 0;
    }
    $need = $cost + max(0, (int) sb_get('cost_research')) + sb_feature_cost('meta') + sb_feature_cost('summary') + sb_feature_cost('faq');
    $st   = SB_Credits::state($uid);
    if ($st['remaining'] < $need) {
        return new WP_Error('sb_desk', 'ব্যালেন্স যথেষ্ট নয় (দরকার ' . sb_money($need) . ', আছে ' . sb_money($st['remaining']) . ')।');
    }
    $notes = array();
    if ($ready) {
        $res  = array('text' => '', 'missing' => array());
        $html = wp_kses_post(wpautop(esc_html($o['bodytext'])));
    } else {
        $out = sb_ai_execute($uid, 'article', $ctx, $cost, true, $notes);
        if (empty($out['ok'])) { // web research can fail: try once without it
            $ctx['research'] = false;
            $out = sb_ai_execute($uid, 'article', $ctx, $cost, false, $notes);
        }
        if (empty($out['ok'])) {
            return new WP_Error('sb_desk', $out['message']);
        }
        $res  = $out['data']['result'];
        $html = (string) $res['text'];
    }
    if (!$ready && mb_strlen(wp_strip_all_tags($html)) < 300) {
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
            $u = wp_get_attachment_url((int) $x);
            if ($u) {
                wp_update_post(array('ID' => $id, 'post_content' => wp_slash(get_post_field('post_content', $id) . "\n<figure><img src=\"" . esc_url($u) . "\" alt=\"" . esc_attr($title) . "\"></figure>")));
            }
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
        $r = sb_tg_api('setWebhook', array('url' => rest_url('sb/v1/tg'), 'secret_token' => $c['secret'], 'allowed_updates' => wp_json_encode(array('channel_post', 'message', 'callback_query')), 'drop_pending_updates' => 'true'));
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
    if ('sim' === $op) {   // dry run of a whole conversation; replies are returned, not sent
        wp_set_current_user(sb_desk_uid());
        $GLOBALS['sb_desk_sim'] = 1;
        $GLOBALS['sb_desk_out'] = array();
        $m  = array('chat' => array('id' => 999000111, 'title' => 'sim'), 'message_id' => 1);
        delete_option(sb_desk_stkey($m));
        $seq = json_decode(wp_unslash((string) sb_req('seq')), true);
        foreach ((array) $seq as $it) {
            $GLOBALS['sb_desk_out'][] = array('>>', isset($it['cb']) ? 'BUTTON ' . $it['cb'] : 'TEXT ' . ($it['text'] ?? ''));
            if (isset($it['cb'])) {
                sb_desk_step($m, array('cb' => (string) $it['cb']));
            } else {
                $mm         = $m;
                $mm['text'] = (string) ($it['text'] ?? '');
                sb_desk_message($mm);
            }
        }
        $out = array();
        foreach ($GLOBALS['sb_desk_out'] as $o2) {
            $out[] = '>>' === $o2[0] ? $o2[1] : preg_replace('/\s+/', ' ', wp_strip_all_tags((string) ($o2[1]['text'] ?? '')) . (!empty($o2[1]['reply_markup']) ? ' [' . implode(' | ', array_map(function ($b) { return $b['text']; }, call_user_func_array('array_merge', json_decode($o2[1]['reply_markup'], true)['inline_keyboard']))) . ']' : ''));
        }
        delete_option(sb_desk_stkey($m));
        wp_send_json_success(array('msg' => 'sim', 'out' => $out));
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
        <p class="sba-muted" style="margin:4px 0 8px">উপরের বাক্সে টোকেন ও চ্যানেল সেভ করার পর এখানে "চালু করুন" চাপুন। তারপর প্রাইভেট চ্যানেলে <code>/menu</code> লিখলে বট ধাপে ধাপে প্রশ্ন করবে (নোটিশ বা ব্লগ বেছে নিন)। শর্টকাট: নোটিশের ছবি পাঠানো, বা <code>/blog বিষয়</code>। <b>শুধু ওই চ্যানেলের পোস্টই নেওয়া হয়।</b></p>
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
