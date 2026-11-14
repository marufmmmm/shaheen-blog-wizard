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
    return "📌 <b>শাহীন ডেস্ক</b>\n\n/menu — কী করবেন বেছে নিন (ধাপে ধাপে প্রশ্ন করব)\n/cancel — চলমান কাজ বাতিল\n\n<b>শর্টকাট:</b>\n<code>/blog বিষয় বা কীওয়ার্ড</code> — সরাসরি ব্লগ লিখে প্রকাশ\n<code>/notice লেখা</code> — সরাসরি নোটিশ\nসংবাদের লিংক একাই পাঠালে (বা <code>/news লিংক</code>) সংবাদ-কার্ড হবে।\nনোটিশের ছবি সরাসরি পাঠালে নোটিশের ধাপ শুরু হবে।";
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
        array(sb_desk_btn('✏️ নোটিশ সম্পাদনা', 'm:edit')),
        array(sb_desk_btn('📰 সংবাদ (লিংক দিন)', 'm:news'), sb_desk_btn('⭐ স্পেলিং স্টার (শীঘ্রই)', 'm:soon')),
    ));
}

/** Flow definitions: ordered steps. kind: choice | text | collect. */
function sb_desk_steps($flow, $d) {
    if ('edit' === $flow) {
        return array('pick', 'act', 'value', 'confirm');
    }
    if ('news' === $flow) {
        return array('links', 'confirm');
    }
    if ('notice' === $flow) {
        return array('type', 'details', 'confirm');
    }
    $st = array('topic', 'title', 'keywords', 'body');
    if (in_array($d['body'] ?? '', array('paste', 'desc'), true)) {
        $st[] = 'bodytext';
    }
    return array_merge($st, array('length', 'image', 'confirm'));
}

/** The ONE question for a notice: a fill-in list, built from what the site's old notices really contain. */
function sb_desk_template($type) {
    $tail = "\n\n📎 ছবি/পোস্টার বা PDF থাকলে একই সাথে (এই লেখার আগে বা পরে) পাঠান। যে ঘরের তথ্য নেই সেটা ফাঁকা রাখুন, AI বানিয়ে লিখবে না। চাইলে ফেসবুক পোস্টের লেখা সরাসরি পেস্ট করলেও চলবে।\nসব পাঠানো হলে নিচের <b>✅ দেওয়া শেষ</b> চাপুন।";
    $t = array(
        'niyog' => "শাখা: \nপদ ও বছর: (যেমন সহকারী শিক্ষক, ২০২৭)\nআবেদনের শেষ সময়: (তারিখ ও সময়)\nলিখিত পরীক্ষার সময়: \nপরীক্ষার ভেন্যু (ঠিকানা): \nশর্তসমূহ: (প্রতি লাইনে একটি)\nGoogle Form লিংক: \nFacebook পোস্টের লিংক: \nফোন নম্বর: ",
        'niyog_result' => "শাখা: \nফলাফল PDF/Drive লিংক: \nমৌখিক পরীক্ষার তারিখ ও সময়: \nপরবর্তী ধাপ: \nবিশেষ দ্রষ্টব্য/শর্ত: (প্রতি লাইনে একটি)\nফোন নম্বর: ",
        'britti' => "শিরোনাম: (যেমন বৃত্তি পরীক্ষা ২০২৭)\nআবেদনের শেষ সময়: \nপরীক্ষার তারিখ: \nপরীক্ষার সময়সূচি: \nপরীক্ষার ভেন্যু: \nসিলেবাস/বিজ্ঞপ্তি PDF লিংক: \nআবেদনের লিংক: \nশর্তসমূহ: (প্রতি লাইনে একটি)",
        'britti_result' => "শিরোনাম: \nফলাফল PDF/লিংক: \nফলাফল প্রকাশের তারিখ: \nবৃত্তির পরিমাণ: \nবিশেষ দ্রষ্টব্য: ",
        'form' => "শিরোনাম: \nআবেদনের শেষ সময়: \nফরমের লিংক (Google Form হলে পাতায় বসে যাবে): \nশর্তসমূহ: ",
    );
    return isset($t[$type]) ? "<code>" . esc_html($t[$type]) . "</code>" . $tail : "<code>" . esc_html("শিরোনাম: \nশাখা (থাকলে): \nমূল তথ্য ও তারিখ: \nশর্তসমূহ: (প্রতি লাইনে একটি)\nআবেদন/ফরমের লিংক: \nডাউনলোড (PDF) লিংক: \nফোন নম্বর: ") . "</code>" . $tail;
}

function sb_desk_ask($m, $s) {
    $steps = sb_desk_steps($s['flow'], $s['d']);
    $k     = $steps[$s['i']];
    $skip  = sb_desk_btn('⏭️ বাদ', 'skip');
    $x     = sb_desk_btn('❌ বাতিল', 'cancel');
    switch ($k) {
        case 'links':
            return sb_desk_say($m, "📰 <b>সংবাদের লিংক পাঠান।</b> একাধিক হলে প্রতি লাইনে একটি (সর্বোচ্চ ৫টি)।\nআমি পাতাটা পড়ব, নেতিবাচক বা সন্দেহজনক হলে আটকে দেব, আর ঠিক থাকলে সংবাদ-কার্ড (শিরোনাম, পত্রিকা, তারিখ, ছবি, সারাংশ) বানিয়ে সাইটে বসাব।\n<i>ফেসবুক/ইউটিউব লিংক সংবাদ নয়: ওগুলো নোটিশ বা ইভেন্টের অংশ।</i>", array(array($x)));
        case 'pick':
            $rows = array();
            foreach (sb_desk_edit_list() as $pid) {
                $rows[] = array(sb_desk_btn(mb_substr(get_the_title($pid), 0, 38) . ' · ' . get_the_date('d/m', $pid), 'p:' . $pid));
            }
            $rows[] = array($x);
            return sb_desk_say($m, "✏️ <b>কোন নোটিশটি বদলাবেন?</b> (সর্বশেষ ৫টি)", $rows);
        case 'act':
            return sb_desk_say($m, "🛠️ <b>কী বদলাবেন?</b>
" . esc_html(get_the_title((int) $s['d']['id'])), array(
                array(sb_desk_btn('📘 Facebook পোস্ট যোগ', 'a:fb'), sb_desk_btn('📅 আবেদনের সময় বাড়ান', 'a:deadline')),
                array(sb_desk_btn('📞 ফোন নম্বর', 'a:phones'), sb_desk_btn('🔗 আবেদন/ফরম লিংক', 'a:apply')),
                array(sb_desk_btn('📎 PDF/ডাউনলোড', 'a:pdf'), sb_desk_btn('🖼️ ছবি বদলান', 'a:img')),
                array(sb_desk_btn('✍️ অন্য বদল (লিখে বলুন)', 'a:free')),
                array($x),
            ));
        case 'value':
            $q = array(
                'fb' => "📘 <b>Facebook পোস্টের লিংক পাঠান।</b> শেয়ার-লিংক (facebook.com/share/…) হলেও চলবে। পোস্ট অবশ্যই পাবলিক হতে হবে।",
                'deadline' => "📅 <b>নতুন শেষ সময় লিখুন</b> (যেমন: ৩০ ডিসেম্বর ২০২৬, রাত ১০:০০টা)।",
                'phones' => "📞 <b>নতুন ফোন নম্বর পাঠান</b> (এক লাইনে একটি বা কমা দিয়ে)। এগুলো আগের নম্বরের জায়গায় বসবে।",
                'apply' => "🔗 <b>আবেদনের লিংক বা Google Form-এর লিংক পাঠান।</b>",
                'pdf' => "📎 <b>PDF সরাসরি পাঠান, অথবা ডাউনলোড লিংক লিখুন।</b>",
                'img' => "🖼️ <b>নতুন ছবি/পোস্টার পাঠান।</b>",
                'free' => "✍️ <b>কী বদলাতে হবে লিখে বলুন।</b> যেমন: ‘পরীক্ষার ভেন্যু বদলে ঢাকা শাখা করো’।",
            );
            return sb_desk_say($m, $q[$s['d']['act']], array(array($x)));
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
            $tl = ($s['d']['type'] ?? '') !== '' ? (csa_sn_opt('types')[$s['d']['type']]['label'] ?? '') : 'সাধারণ';
            return sb_desk_say($m, "📝 <b>" . esc_html($tl) . " নোটিশের তথ্য</b> — নিচের তালিকাটি কপি করে ভরে একটি বার্তায় পাঠান:\n\n" . sb_desk_template($s['d']['type'] ?? '') . ($n ? "\n\nএ পর্যন্ত: " . $n . 'টি ছবি' . (!empty($s['d']['text']) ? ' + লেখা' : '') : ''), array(array(sb_desk_btn('✅ দেওয়া শেষ', 'done'), sb_desk_btn('❌ বাতিল', 'cancel'))));
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
            if ('news' === $s['flow']) {
                $ls = '';
                foreach ((array) $d['urls'] as $u) {
                    $ls .= "\n• " . esc_html(mb_substr($u, 0, 90));
                }
                return sb_desk_say($m, "📰 <b>সংবাদ-কার্ড</b> (" . count((array) $d['urls']) . "টি লিংক)" . $ls . "\n\nপ্রতিটি যাচাই করে (নেতিবাচক হলে আটকে দেব) সংবাদ-কার্ড বানাব। কী করব?", array(array(sb_desk_btn('✅ যাচাই করে কার্ড বানাও', 'go:publish'), $x)));
            }
            if ('edit' === $s['flow']) {
                return sb_desk_say($m, "✏️ <b>এই বদল করব?</b>\n\n" . $d['desc'], array(array(sb_desk_btn('✅ প্রয়োগ করো', 'go:apply'), $x)));
            }
            if ('notice' === $s['flow']) {
                $tl = ($d['type'] ?? '') !== '' ? (csa_sn_opt('types')[$d['type']]['label'] ?? $d['type']) : 'সাধারণ';
                $sum = "📢 <b>নোটিশ</b>\nধরন: " . esc_html($tl) . "\nছবি: " . count($d['aids'] ?? array()) . "টি; PDF: " . (!empty($d['pdf_aid']) ? 'আছে' : 'নেই') . "; লেখা: " . (!empty($d['text']) ? mb_strlen($d['text']) . ' অক্ষর' : 'নেই') . "\n\nপ্রকাশের আগে AI তারিখ, ফোন, লিংক ও শর্ত আলাদা করে সাজাবে।";
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
        $newslink = !$s && '' === $cmd && !$fid && sb_desk_urls($text) && !preg_match('#facebook\.com|fb\.watch|fb\.me|youtube\.com|youtu\.be#i', $text) && mb_strlen(trim(preg_replace('#https?://\S+#i', '', $text))) < 12;
        if (in_array($cmd, array('menu', 'help', 'cancel'), true) || ('' === $cmd && !$s && !$fid && !$newslink)) {
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
        if ('news' === $cmd || $newslink) { // news link(s)
            sb_desk_unlock($lock);
            $us = sb_desk_urls('news' === $cmd ? $rest : $text);
            if ($us) {
                $s2 = array('flow' => 'news', 'i' => 1, 'd' => array('urls' => array_slice($us, 0, 5)));
                sb_desk_save($m, $s2);
                sb_desk_ask($m, $s2);
            } else {
                sb_desk_start($m, 'news', array('urls' => array()));
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
    $cb   = isset($in['cb']) ? $in['cb'] : '';
    if (0 === strpos($cb, 'm:')) { // menu choice
        sb_desk_unlock($lock);
        $f = substr($cb, 2);
        if ('soon' === $f) {
            sb_desk_say($m, 'এটা পরের ধাপে আসছে। এখন নোটিশ ও ব্লগ চলছে।');
        } else {
            if ('edit' === $f) {
                if (!sb_desk_edit_list()) {
                    sb_desk_say($m, 'সম্পাদনার মতো নতুন-ডিজাইনের নোটিশ এখনো নেই। আগে /menu থেকে একটি নোটিশ বানান।');
                    return;
                }
                sb_desk_start($m, 'edit', array());
                return;
            }
            sb_desk_start($m, $f, 'notice' === $f ? array('aids' => array(), 'text' => '', 'type' => '', 'branch' => '') : ('news' === $f ? array('urls' => array()) : array('aids' => array())));
        }
        return;
    }
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
    switch ($k) {
        case 'links':
            $us = sb_desk_urls($t);
            if ('' === $cb && $us) {
                $d['urls'] = array_slice($us, 0, 5);
            } else {
                $stay = true;
            }
            break;
        case 'pick':
            if (0 === strpos($cb, 'p:') && in_array((int) substr($cb, 2), sb_desk_edit_list(), true)) {
                $d['id'] = (int) substr($cb, 2);
            } else {
                $stay = true;
            }
            break;
        case 'act':
            if (0 === strpos($cb, 'a:')) {
                $d['act'] = substr($cb, 2);
            } else {
                $stay = true;
            }
            break;
        case 'value':
            $val = array('text' => $t, 'file' => $in['file'] ?? 0, 'img' => $in['img'] ?? 0);
            $ok  = ('' !== $t && '' === $cb) || ($val['file'] && 'pdf' === $d['act']) || ($val['img'] && 'img' === $d['act']);
            if (!$ok) {
                $stay = true;
                break;
            }
            sb_desk_unlock($lock);
            $r = sb_desk_edit_propose((int) $d['id'], $d['act'], $val);
            if (is_wp_error($r)) {
                sb_desk_say($m, '⚠️ ' . esc_html($r->get_error_message()) . "\nআবার পাঠান বা ❌ বাতিল চাপুন।");
                return;
            }
            $lock = sb_desk_lock($m);
            $d = array_merge($d, $r);
            break;
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
            if (!empty($in['file'])) {
                $d['pdf_aid'] = (int) $in['file'];
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
                if ($now && count($now['d']['aids'] ?? array()) === count($d2['aids'] ?? array()) && ($now['d']['pdf_aid'] ?? 0) === ($d2['pdf_aid'] ?? 0) && ($now['d']['text'] ?? '') === ($d2['text'] ?? '') && empty($now['ack'])) {
                    $now['ack'] = 1;
                    sb_desk_save($m, $now);
                    sb_desk_say($m, '👍 পেয়েছি (' . count($d2['aids']) . 'টি ছবি' . (!empty($d2['pdf_aid']) ? ' + PDF' : '') . (!empty($d2['text']) ? ' + লেখা' : '') . '). আরও থাকলে পাঠান, নইলে "✅ দেওয়া শেষ" চাপুন।', array(array(sb_desk_btn('✅ দেওয়া শেষ', 'done'), sb_desk_btn('❌ বাতিল', 'cancel'))));
                    $now['ack'] = 0;
                    sb_desk_save($m, $now);
                }
                return;
            }
            if (!$d['aids'] && empty($d['pdf_aid']) && '' === trim((string) ($d['text'] ?? ''))) {
                $stay = true;
                sb_desk_say($m, 'কিছু পাঠাননি — তালিকা ভরে লেখা, বা ছবি/PDF পাঠান।');
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
            if ('edit' === $s['flow'] && 'go:apply' === $cb) {
                sb_desk_clear($m);
                sb_desk_unlock($lock);
                $r = sb_desk_edit_apply((int) $d['id'], $d);
                sb_desk_say($m, is_wp_error($r) ? '⚠️ ' . esc_html($r->get_error_message()) : $r);
                return;
            }
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
    if ('news' === $flow) {
        return sb_desk_news_run($m, (array) ($d['urls'] ?? array()));
    }
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
    if (preg_match('~^\s*(/?)(blog|ব্লগ|notice|নোটিশ|news|সংবাদ|help|সাহায্য|start|menu|মেনু|cancel|বাতিল)(?:@\w+)?(?=[\s:：\-–]|$)[\s:：\-–]*(.*)$~isu', $text, $mm) && ('/' === $mm[1] || !in_array(mb_strtolower($mm[2]), array('start', 'menu', 'cancel', 'help'), true))) {
        $k   = mb_strtolower($mm[2]);
        $map = array('ব্লগ' => 'blog', 'নোটিশ' => 'notice', 'সংবাদ' => 'news', 'সাহায্য' => 'help', 'start' => 'menu', 'মেনু' => 'menu', 'বাতিল' => 'cancel');
        return array(isset($map[$k]) ? $map[$k] : $k, trim($mm[3]));
    }
    return array('', trim($text));
}

/* ------------------------------------------------------------ news links -> press cards */
/** http(s) addresses found in a text, unique */
function sb_desk_urls($text) {
    if (!preg_match_all('#https?://[^\s<>"\']+#iu', (string) $text, $mm)) {
        return array();
    }
    return array_values(array_unique(array_map(function ($u) { return esc_url_raw(rtrim($u, '.,;)')); }, $mm[0])));
}

/** reads one news page into the shape the collector's judge expects, or WP_Error */
function sb_desk_news_item($url) {
    $r = wp_remote_get($url, array('timeout' => 14, 'redirection' => 5, 'limit_response_size' => 600000,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'headers' => array('Accept-Language' => 'bn,en;q=0.8')));
    if (is_wp_error($r)) {
        return new WP_Error('sb_news', 'পাতা খোলা যায়নি।');
    }
    $code = (int) wp_remote_retrieve_response_code($r);
    if (200 !== $code) {
        return new WP_Error('sb_news', 'পাতা খোলেনি (কোড ' . $code . ')। সাইটটি সার্ভারকে আটকাতে পারে।');
    }
    $body = (string) wp_remote_retrieve_body($r);
    $meta = function_exists('csa_ev_og_meta') ? csa_ev_og_meta($body) : array();
    $title = (string) ($meta['og:title'] ?? '');
    if ('' === $title && preg_match('#<title[^>]*>(.*?)</title>#is', $body, $tm)) {
        $title = html_entity_decode(wp_strip_all_tags($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $plain = preg_replace('#<(script|style|noscript|nav|header|footer|aside)\b.*?</\1>#is', ' ', $body);
    $plain = html_entity_decode(wp_strip_all_tags(preg_replace('#</(p|div|h\d|li|br)>#i', "\n", (string) $plain)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\s*\n\s*/', "\n", $plain)));
    $img   = (string) ($meta['og:image'] ?? ($meta['twitter:image'] ?? ''));
    if ($img && 0 === strpos($img, '//')) {
        $img = 'https:' . $img;
    }
    $desc = (string) ($meta['og:description'] ?? ($meta['description'] ?? ''));
    return array('id' => substr(md5($url . microtime(true)), 0, 10), 't' => time(), 'url' => $url, 'host' => (string) wp_parse_url($url, PHP_URL_HOST),
        'title' => mb_substr(sanitize_text_field($title), 0, 300), 'text' => mb_substr(sanitize_textarea_field(($desc !== '' ? $desc . "\n" : '') . $plain), 0, 8000),
        'images' => ($img && preg_match('#^https?://#i', $img)) ? array(esc_url_raw($img)) : array(), 'videos' => array());
}

function sb_desk_news_run($m, $urls) {
    if (!function_exists('sb_ca_process')) {
        return sb_desk_say($m, '⚠️ সংবাদ সংগ্রহের অংশ চালু নেই।');
    }
    sb_desk_say($m, '⏳ ' . count($urls) . 'টি লিংক পড়ছি ও যাচাই করছি…');
    $out = array();
    foreach (array_slice($urls, 0, 5) as $u) {
        if (preg_match('#facebook\.com|fb\.watch|fb\.me|youtube\.com|youtu\.be#i', $u)) {
            $out[] = '⏭️ ' . esc_html($u) . "\n   ফেসবুক/ইউটিউব সংবাদ নয়, বাদ দিলাম।";
            continue;
        }
        $it = sb_desk_news_item($u);
        if (is_wp_error($it)) {
            $out[] = '⚠️ ' . esc_html($u) . "\n   " . esc_html($it->get_error_message());
            continue;
        }
        $r  = sb_ca_process($it, true);
        $ic = array('filed' => '✅ বসানো হয়েছে', 'blocked' => '🛑 আটকানো হয়েছে', 'skipped' => '⏭️ বাদ', 'dup' => '♻️ আগেই আছে', 'error' => '⚠️ হয়নি');
        $out[] = ($ic[$r[0]] ?? $r[0]) . ': ' . esc_html(mb_substr($it['title'] ?: $u, 0, 80)) . "\n   " . esc_html($r[1]);
    }
    sb_desk_say($m, "📰 <b>সংবাদের ফল</b>\n\n" . implode("\n\n", $out) . "\n\n🛑 আটকানো খবর আপনাকে আলাদা করে জানানো হয় (ব্লগ → সংবাদ সংগ্রহ)।\n/menu — আরেকটি কাজ");
}

/* ------------------------------------------------------------ editing a notice made with the notice design */
function sb_desk_edit_list() {
    return array_map('intval', get_posts(array('post_type' => 'post', 'post_status' => array('publish', 'draft'), 'numberposts' => 5, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids',
        'meta_query' => array('relation' => 'AND', array('key' => '_sn_is', 'value' => '1'), array('key' => '_sn_link', 'compare' => 'NOT EXISTS')))));
}

/** A Facebook share link -> the real post address (embedding does not work with facebook.com/share/…). */
function sb_desk_fb_canon($url) {
    $url = esc_url_raw(trim($url));
    if (!preg_match('#^https?://([a-z0-9.-]+\.)?(facebook\.com|fb\.watch|fb\.me)/#i', $url)) {
        return new WP_Error('sb_fb', 'এটা ফেসবুকের লিংক মনে হচ্ছে না।');
    }
    $final = $url;
    if (preg_match('#/share/|fb\.watch|fb\.me#i', $url)) {
        $r = wp_remote_get($url, array('timeout' => 15, 'redirection' => 6, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36'));
        if (!is_wp_error($r) && !empty($r['http_response']) && method_exists($r['http_response'], 'get_response_object')) {
            $ro = $r['http_response']->get_response_object();
            if (!empty($ro->url)) {
                $final = $ro->url;
            }
        }
    }
    $q = array();
    parse_str((string) wp_parse_url($final, PHP_URL_QUERY), $q);
    if (empty($q['story_fbid']) && !preg_match('#facebook\.com/[^/?]+/(posts|videos)/#i', $final) && preg_match('#/share/|fb\.watch|fb\.me#i', $url)) {
        // Facebook does not send browsers' redirect to servers, but it tells its own link-preview robot the real address
        $r2 = wp_remote_get($url, array('timeout' => 15, 'redirection' => 6, 'user-agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'));
        if (!is_wp_error($r2)) {
            $body = (string) wp_remote_retrieve_body($r2);
            if (preg_match('#<meta[^>]+property=["\']og:url["\'][^>]+content=["\']([^"\']+)#i', $body, $mm) || preg_match('#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)#i', $body, $mm)) {
                $final = html_entity_decode($mm[1]);
                parse_str((string) wp_parse_url($final, PHP_URL_QUERY), $q);
            }
            if (!empty($r2['http_response']) && method_exists($r2['http_response'], 'get_response_object') && !empty($r2['http_response']->get_response_object()->url) && !preg_match('#/share/#', $r2['http_response']->get_response_object()->url)) {
                $final = $r2['http_response']->get_response_object()->url;
                parse_str((string) wp_parse_url($final, PHP_URL_QUERY), $q);
            }
        }
    }
    if (!empty($q['story_fbid']) && !empty($q['id'])) {
        return 'https://www.facebook.com/permalink.php?story_fbid=' . rawurlencode($q['story_fbid']) . '&id=' . rawurlencode($q['id']);
    }
    if (preg_match('#facebook\.com/[^/?]+/(posts|videos)/#i', $final) || preg_match('#facebook\.com/(reel|watch)#i', $final)) {
        return strtok($final, '?');
    }
    return new WP_Error('sb_fb', 'এই লিংক থেকে আসল পোস্টের ঠিকানা পাইনি (ফেসবুক সার্ভারকে আটকাতে পারে)। ফেসবুক পোস্টের "…" মেনু → "Embed"/লিংক কপি করে পোস্টের সরাসরি লিংক পাঠান (…/posts/… বা permalink.php?story_fbid=… ধরনের)।');
}

function sb_desk_fb_html($canon) {
    return '<div class="sn-fb" style="max-width:520px;margin:12px auto;text-align:center"><iframe src="https://www.facebook.com/plugins/post.php?href=' . rawurlencode($canon) . '&width=500&show_text=true" width="500" height="720" style="border:none;overflow:hidden;max-width:100%" scrolling="no" frameborder="0" allowfullscreen="true" loading="lazy" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"></iframe></div>';
}

/** Builds the proposed new notice data. Returns array(new, desc, thumb) or WP_Error. */
function sb_desk_edit_propose($id, $act, $val) {
    $d = csa_sn_get($id);
    if (!$d) {
        return new WP_Error('sb_ed', 'নোটিশের তথ্য পাইনি।');
    }
    $new   = $d;
    $new['blocks'] = isset($d['blocks']) ? array_values((array) $d['blocks']) : array();
    $thumb = 0;
    $t     = trim((string) $val['text']);
    $find  = function ($blocks, $type) {
        foreach ($blocks as $i => $b) {
            if (($b['t'] ?? '') === $type) {
                return $i;
            }
        }
        return -1;
    };
    switch ($act) {
        case 'fb':
            $c = sb_desk_fb_canon($t);
            if (is_wp_error($c)) {
                $raw = esc_url_raw(trim($t));
                if (!preg_match('#^https?://([a-z0-9.-]+\.)?(facebook\.com|fb\.watch|fb\.me)/#i', $raw)) {
                    return $c;
                }
                // fallback: pass the share link as a button, and say how to get an embeddable post
                $it = array('label' => 'ফেসবুক পোস্ট দেখুন', 'url' => $raw, 'kind' => 'oth');
                foreach ($new['blocks'] as $k => $b) {
                    if ('buttons' === ($b['t'] ?? '')) {
                        $new['blocks'][$k]['items'] = array_values(array_filter((array) $b['items'], function ($x) { return 'ফেসবুক পোস্ট দেখুন' !== ($x['label'] ?? ''); }));
                    }
                }
                $bi = $find($new['blocks'], 'buttons');
                if ($bi >= 0) {
                    $new['blocks'][$bi]['items'][] = $it;
                } else {
                    $new['blocks'][] = array('t' => 'buttons', 'items' => array($it));
                }
                $desc = "📘 এই লিংক থেকে আসল পোস্টের ঠিকানা পাইনি, তাই নোটিশে <b>\"ফেসবুক পোস্ট দেখুন\" বোতাম</b> বসবে:\n" . esc_url($raw) . "\n\n💡 পোস্টটা নোটিশের ভেতরেই দেখাতে চাইলে পরে ডেস্কটপে ফেসবুকে পোস্টের সময়ের লেখায় ক্লিক করে ঠিকানা কপি করে আবার ফেসবুক পোস্ট যোগ করুন।";
                break;
            }
            $html = sb_desk_fb_html($c);
            $i    = -1;
            foreach ($new['blocks'] as $k => $b) {
                if ('html' === ($b['t'] ?? '') && false !== strpos((string) ($b['code'] ?? ''), 'class="sn-fb"')) {
                    $i = $k;
                }
            }
            if ($i >= 0) {
                $new['blocks'][$i]['code'] = $html;
            } else {
                $new['blocks'][] = array('t' => 'html', 'code' => $html);
            }
            $desc = "📘 নোটিশের নিচে এই ফেসবুক পোস্টটি পোস্ট আকারে বসবে:\n" . esc_url($c);
            break;
        case 'deadline':
            $cur = (string) ($d['expiry'] ?? '');
            $r   = csa_jobs_ai_json('Convert the Bangla/English date text into JSON {"date":"YYYY-MM-DD"} (the calendar date only; use the year in the text; if unsure return empty). Today is ' . wp_date('Y-m-d') . '.', $t, 'Desk: date', 120);
            $dt  = !is_wp_error($r) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['date'] ?? '')) ? $r['date'] : '';
            if ('' === $dt) {
                return new WP_Error('sb_ed', 'তারিখটা বুঝতে পারিনি। আবার লিখুন (যেমন ৩০ ডিসেম্বর ২০২৬)।');
            }
            $done = false;
            foreach ($new['blocks'] as $k => $b) {
                if ('cards' === ($b['t'] ?? '')) {
                    foreach ($b['rows'] as $j => $row) {
                        if (preg_match('/শেষ\s*(সময়|তারিখ)/u', (string) $row['l'])) {
                            $new['blocks'][$k]['rows'][$j]['v'] = $t;
                            $done = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$done) {
                $ci = $find($new['blocks'], 'cards');
                if ($ci < 0) {
                    array_unshift($new['blocks'], array('t' => 'cards', 'rows' => array()));
                    $ci = 0;
                }
                array_unshift($new['blocks'][$ci]['rows'], array('l' => 'আবেদনের শেষ সময়', 'v' => $t, 'm' => ''));
            }
            $new['expiry'] = $dt;
            $desc = '📅 আবেদনের শেষ সময়: ' . esc_html($cur ?: 'আগের কার্ডের তারিখ') . ' → <b>' . esc_html($t) . '</b> (' . esc_html($dt) . ")\nনোটিশ আবার \"চলমান\" দেখাবে।";
            break;
        case 'phones':
            $nums = array_values(array_filter(array_map('trim', preg_split('/[\n,;]+/u', $t))));
            if (!$nums) {
                return new WP_Error('sb_ed', 'কোনো নম্বর পাইনি।');
            }
            $i = $find($new['blocks'], 'phones');
            if ($i >= 0) {
                $new['blocks'][$i]['nums'] = implode("\n", $nums);
            } else {
                $new['blocks'][] = array('t' => 'phones', 'h' => 'যোগাযোগ', 'nums' => implode("\n", $nums));
            }
            $desc = '📞 ফোন নম্বর হবে: ' . esc_html(implode(', ', $nums));
            break;
        case 'apply':
            if (!preg_match('#^https?://\S+$#i', $t)) {
                return new WP_Error('sb_ed', 'পুরো লিংক (https://…) পাঠান।');
            }
            $isf = false !== strpos($t, 'docs.google.com/forms') || false !== strpos($t, 'forms.gle');
            foreach ($new['blocks'] as $k => $b) { // drop the old form and old apply buttons
                if ('gform' === ($b['t'] ?? '')) {
                    unset($new['blocks'][$k]);
                } elseif ('buttons' === ($b['t'] ?? '')) {
                    $new['blocks'][$k]['items'] = array_values(array_filter((array) $b['items'], function ($x) { return 'apply' !== ($x['kind'] ?? ''); }));
                    if (!$new['blocks'][$k]['items']) {
                        unset($new['blocks'][$k]);
                    }
                }
            }
            $new['blocks'] = array_values($new['blocks']);
            if ($isf) {
                $new['blocks'][] = array('t' => 'gform', 'h' => 'অনলাইনে আবেদন করুন', 'url' => esc_url_raw($t), 'height' => 900);
            } else {
                $bi = $find($new['blocks'], 'buttons');
                if ($bi >= 0) {
                    $new['blocks'][$bi]['items'][] = array('label' => 'আবেদন করুন', 'url' => esc_url_raw($t), 'kind' => 'apply');
                } else {
                    $new['blocks'][] = array('t' => 'buttons', 'items' => array(array('label' => 'আবেদন করুন', 'url' => esc_url_raw($t), 'kind' => 'apply')));
                }
            }
            $desc = '🔗 নতুন ' . ($isf ? 'Google Form (পাতায় বসবে)' : 'আবেদন বোতাম') . ":\n" . esc_url($t) . "\nআগের ফরম/আবেদন বোতাম সরে যাবে।";
            break;
        case 'pdf':
            $u = !empty($val['file']) ? wp_get_attachment_url((int) $val['file']) : (preg_match('#^https?://\S+$#i', $t) ? esc_url_raw($t) : '');
            if (!$u) {
                return new WP_Error('sb_ed', 'PDF বা ডাউনলোড লিংক পাইনি।');
            }
            foreach ($new['blocks'] as $k => $b) {
                if ('buttons' === ($b['t'] ?? '')) {
                    $new['blocks'][$k]['items'] = array_values(array_filter((array) $b['items'], function ($x) { return 'pdf' !== ($x['kind'] ?? ''); }));
                }
            }
            $bi = $find($new['blocks'], 'buttons');
            $it = array('label' => 'ডাউনলোড করুন', 'url' => $u, 'kind' => 'pdf');
            if ($bi >= 0) {
                $new['blocks'][$bi]['items'][] = $it;
            } else {
                $new['blocks'][] = array('t' => 'buttons', 'items' => array($it));
            }
            $desc = "📎 ডাউনলোড বোতাম:\n" . esc_url($u);
            break;
        case 'img':
            $a = (int) $val['img'];
            if (!$a) {
                return new WP_Error('sb_ed', 'ছবি পাইনি।');
            }
            $i = $find($new['blocks'], 'image');
            if ($i >= 0) {
                $new['blocks'][$i]['ids'] = array($a);
            } else {
                array_unshift($new['blocks'], array('t' => 'image', 'ids' => array($a), 'cap' => ''));
            }
            $thumb = $a;
            $desc  = '🖼️ নোটিশের মূল ছবি বদলে নতুন ছবি বসবে।';
            break;
        case 'free':
            $sys = 'You edit a school notice stored as JSON. Apply ONLY the requested change; keep everything else exactly as it is. Return ONLY JSON: {"notice":<the full updated notice object, same keys and block structure>,"changes":["each change as one short Bangla line: আগে → পরে"]}. Never invent dates, numbers, names or links; if the request cannot be done with the data given, return the notice unchanged and an empty changes list.';
            $r   = csa_jobs_ai_json($sys, "Notice JSON:\n" . wp_json_encode($new, JSON_UNESCAPED_UNICODE) . "\n\nRequested change:\n" . $t, 'Desk: free edit', 3500);
            if (is_wp_error($r) || empty($r['notice']) || !is_array($r['notice'])) {
                return new WP_Error('sb_ed', 'AI বদলটি বুঝে করতে পারেনি। অন্যভাবে লিখে দেখুন।');
            }
            $chg = array_values(array_filter((array) ($r['changes'] ?? array())));
            if (!$chg) {
                return new WP_Error('sb_ed', 'কোনো বদল করা যায়নি (তথ্য বাড়তি লাগতে পারে)। আরও স্পষ্ট করে লিখুন।');
            }
            $cl = csa_sn_clean(array_merge($d, $r['notice']));
            $new = array_merge($new, $cl);
            $desc = "✍️ বদলগুলো:\n• " . implode("\n• ", array_map('esc_html', array_map('strval', array_slice($chg, 0, 8))));
            break;
        default:
            return new WP_Error('sb_ed', 'অজানা কাজ।');
    }
    $new['blocks'] = array_values($new['blocks']);
    return array('new' => $new, 'desc' => $desc . "\n\n(নোটিশ: " . esc_html(get_the_title($id)) . ')', 'thumb' => $thumb);
}

function sb_desk_edit_apply($id, $st) {
    if (!in_array($id, sb_desk_edit_list(), true) || empty($st['new'])) {
        return new WP_Error('sb_ed', 'নোটিশটি আর তালিকায় নেই, আবার /menu থেকে শুরু করুন।');
    }
    $old = csa_sn_get($id);
    $new = $st['new'];
    $d   = csa_sn_clean($new);
    $d['detached'] = !empty($old['detached']) ? 1 : 0;
    foreach (array('seo_t', 'seo_d', 'seo_k', 'slug', 'title', 'summary') as $k) {
        if (empty($d[$k]) && !empty($old[$k])) {
            $d[$k] = $old[$k];
        }
    }
    if (in_array($st['act'], array('deadline', 'free'), true)) { // refresh the summary and the Google description
        $s = csa_sn_sum_run($d);
        if (!is_wp_error($s)) {
            foreach (array('summary', 'seo_d', 'seo_t') as $k) {
                if ('' !== $s[$k]) {
                    $d[$k] = $s[$k];
                }
            }
        }
    }
    update_post_meta($id, '_sn', wp_slash($d));
    update_post_meta($id, '_sn_type', $d['type']);
    update_post_meta($id, '_sn_branch', $d['branch']);
    update_post_meta($id, '_sn_expiry', $d['expiry']);
    if (!empty($st['thumb'])) {
        set_post_thumbnail($id, (int) $st['thumb']);
    }
    if (!empty($d['summary'])) {
        wp_update_post(array('ID' => $id, 'post_excerpt' => wp_slash($d['summary'])));
    }
    if (function_exists('csa_seo_set') && function_exists('csa_seo_plugin') && csa_seo_plugin()) {
        csa_seo_set($id, array('title' => $d['seo_t'], 'description' => $d['seo_d'], 'keyword' => $d['seo_k']), 'Notice SEO');
    }
    clean_post_cache($id);
    $url = 'publish' === get_post_status($id) ? get_permalink($id) : admin_url('post.php?post=' . $id . '&action=edit');
    return "✅ বদল হয়েছে:\n<b>" . esc_html(get_the_title($id)) . "</b>\n" . esc_url($url) . "\n\n/menu — আরেকটি কাজ";
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
        if (!empty($l['url']) && preg_match('#^https?://#i', $l['url']) && (false !== strpos($l['url'], 'docs.google.com/forms') || false !== strpos($l['url'], 'forms.gle')) && 'apply' === ($l['kind'] ?? 'apply')) {
            $o['apply'] = $l['url'];
            continue;
        }
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
    $b   = csa_jobs_ai_json($sys, mb_substr((string) $text, 0, 700), 'Desk: blog brief', 1400);   // only the short topic goes through the brief; long rules go to the writer below
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
        $kw = array_slice(array_values(array_filter(array_map('trim', preg_split('/[,،;]+/u', sanitize_text_field($o['keywords']))))), 0, 15);
    }
    $len = in_array($b['length'] ?? '', array('short', 'medium', 'long'), true) ? $b['length'] : 'medium';
    if (!empty($o['length']) && in_array($o['length'], array('short', 'medium', 'long'), true)) {
        $len = $o['length'];
    }
    $ctx = array('title' => $title, 'topic' => sanitize_text_field($b['topic'] ?? ''), 'excerpt' => '', 'content' => '', 'keywords' => $kw, 'length' => $len,
        'research' => true, 'source' => '', 'instruction' => trim(sanitize_textarea_field($b['instruction'] ?? '') . (!empty($o['instruction']) ? "\n" . sanitize_textarea_field($o['instruction']) : '')), 'faq_count' => 5, 'post_id' => 0, 'tier' => 1, 'model' => '',
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
    if (!empty($o['meta'])) {   // the owner's own meta description wins
        $meta = sanitize_textarea_field($o['meta']);
    }
    $ctx['excerpt'] = $meta;

    $postarr = array('post_type' => 'sb_post', 'post_title' => $title, 'post_excerpt' => $meta, 'post_content' => $html, 'post_author' => $uid,
        'post_status' => ($missing || 'publish' !== $status) ? 'draft' : 'publish');
    $id = wp_insert_post(wp_slash($postarr), true);
    if (is_wp_error($id)) {
        return $id;
    }
    $g = !empty($o['slug']) ? sanitize_title($o['slug']) : sb_slug_guess($title, $kw);
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
            update_post_meta($id, '_sb_summary', !empty($o['summary']) ? sanitize_textarea_field($o['summary']) : sanitize_textarea_field($r['text']));
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
    if ('who' === $op) {   // which bot does the saved token belong to (the secret part is never shown)
        $tk  = (string) $n['tg_token'];
        $me  = sb_tg_api('getMe');
        $pre = '' === $tk ? '(নেই)' : (false !== strpos($tk, ':') ? explode(':', $tk)[0] : '(কোলন নেই)');
        wp_send_json_success(array('msg' => 'চ্যানেল-ঘরে সেভ: ' . $n['tg_chat'] . ' | টোকেনের বট-নম্বর: ' . $pre . ', দৈর্ঘ্য: ' . strlen($tk) . ' → ' . (is_wp_error($me) ? $me->get_error_message() : '@' . $me['username'])));
    }
    if ('find' === $op) {   // finds the private channel's number from what Telegram told the bot, and saves it
        $u = sb_tg_api('getUpdates', array('limit' => 100, 'timeout' => 0, 'allowed_updates' => wp_json_encode(array('channel_post', 'my_chat_member', 'message'))));
        if (is_wp_error($u)) {
            wp_send_json_error(array('message' => $u->get_error_message() . ' (ওয়েবহুক চালু থাকলে আগে "বন্ধ" চাপুন)'));
        }
        $found = array();
        foreach ((array) $u as $x) {
            $ch = isset($x['channel_post']['chat']) ? $x['channel_post']['chat'] : (isset($x['my_chat_member']['chat']) ? $x['my_chat_member']['chat'] : (isset($x['message']['chat']) ? $x['message']['chat'] : null));
            if (is_array($ch) && 'channel' === ($ch['type'] ?? '')) {
                $found[(string) $ch['id']] = (string) ($ch['title'] ?? '');
            }
        }
        if (1 === count($found)) {
            $n['tg_chat'] = (string) key($found);
            update_option('sb_notify', $n, false);
            wp_send_json_success(array('msg' => 'চ্যানেল পেয়েছি ও সেভ করেছি: ' . key($found) . ' (' . current($found) . ')'));
        }
        wp_send_json_success(array('msg' => $found ? 'একাধিক চ্যানেল: ' . wp_json_encode($found, JSON_UNESCAPED_UNICODE) : 'কোনো চ্যানেল পাইনি। প্রাইভেট চ্যানেলে কিছু একটা লিখে পোস্ট করে আবার চাপুন।', 'current' => $n['tg_chat']));
    }
    if ('audit' === $op) {   // which fields do the notices already on the site really use?
        $ids = get_posts(array('post_type' => 'post', 'post_status' => array('publish', 'draft', 'private'), 'numberposts' => 300, 'fields' => 'ids', 'meta_key' => '_sn_is', 'meta_value' => '1'));
        $st  = array('n' => count($ids), 'types' => array(), 'blocks' => array(), 'card_labels' => array(), 'list_heads' => array(), 'btn_kinds' => array(), 'btn_labels' => array(), 'detached' => 0, 'with_expiry' => 0, 'with_branch' => 0, 'with_year' => 0, 'with_thumb' => 0, 'with_br' => 0, 'samples' => array());
        $inc = function (&$a, $k) { $k = trim((string) $k); if ('' !== $k) { $a[$k] = ($a[$k] ?? 0) + 1; } };
        foreach ($ids as $id) {
            $d = get_post_meta($id, '_sn', true);
            if (!is_array($d)) {
                continue;
            }
            $inc($st['types'], $d['type'] ?? '');
            $st['detached'] += !empty($d['detached']) ? 1 : 0;
            $st['with_expiry'] += !empty($d['expiry']) ? 1 : 0;
            $st['with_branch'] += !empty($d['branch']) ? 1 : 0;
            $st['with_year'] += !empty($d['year']) ? 1 : 0;
            $st['with_br'] += !empty($d['br']) ? 1 : 0;
            $st['with_thumb'] += has_post_thumbnail($id) ? 1 : 0;
            $bl = array();
            foreach ((array) ($d['blocks'] ?? array()) as $b) {
                $t = $b['t'] ?? '?';
                $bl[] = $t;
                $inc($st['blocks'], $t);
                if ('cards' === $t) { foreach ((array) $b['rows'] as $r) { $inc($st['card_labels'], $r['l'] ?? ''); } }
                if ('list' === $t || 'text' === $t || 'phones' === $t) { $inc($st['list_heads'], ($b['h'] ?? '') . ' [' . $t . ']'); }
                if ('buttons' === $t) { foreach ((array) $b['items'] as $x) { $inc($st['btn_kinds'], $x['kind'] ?? ''); $inc($st['btn_labels'], $x['label'] ?? ''); } }
            }
            if (count($st['samples']) < 8 && !empty($d['blocks'])) {
                $st['samples'][] = array(get_the_title($id), $d['type'] ?? '', $d['branch'] ?? '', implode(',', $bl));
            }
        }
        foreach (array('types', 'blocks', 'card_labels', 'list_heads', 'btn_kinds', 'btn_labels') as $k) {
            arsort($st[$k]);
            $st[$k] = array_slice($st[$k], 0, 25, true);
        }
        wp_send_json_success(array('msg' => 'audit', 'st' => $st, 'types_cfg' => array_map(function ($t) { return $t['label'] . ' | cards: ' . str_replace("\n", ' / ', $t['cards'] ?? ''); }, (array) csa_sn_opt('types'))));
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
    if ('patch' === $op) {   // owner-requested correction of one blog draft/post: title, body, picture
        $pid = (int) sb_req('id', 0);
        if (!$pid || 'sb_post' !== get_post_type($pid)) {
            wp_send_json_error(array('message' => 'ব্লগ পোস্ট পাইনি।'));
        }
        $a = array('ID' => $pid);
        if ('' !== (string) sb_req('title')) {
            $a['post_title'] = sanitize_text_field((string) sb_req('title'));
        }
        if ('' !== (string) wp_unslash($_POST['content'] ?? '')) {
            $a['post_content'] = wp_kses_post(wp_unslash($_POST['content']));
        }
        if (in_array((string) sb_req('status'), array('draft', 'publish'), true)) {
            $a['post_status'] = (string) sb_req('status');
        }
        $r = wp_update_post(wp_slash($a), true);
        if (is_wp_error($r)) {
            wp_send_json_error(array('message' => $r->get_error_message()));
        }
        if ((int) sb_req('thumb', 0)) {
            set_post_thumbnail($pid, (int) sb_req('thumb'));
        }
        if ('' !== (string) sb_req('promo_ids')) {
            update_post_meta($pid, '_sb_promo_ids', array_values(array_filter(array_map('sanitize_key', explode(',', (string) sb_req('promo_ids'))))));
        }
        if ('' !== (string) sb_req('no_social')) {
            if ('1' === (string) sb_req('no_social')) {
                update_post_meta($pid, '_sb_no_social', 1);
            } else {
                delete_post_meta($pid, '_sb_no_social');
            }
        }
        if ('' !== (string) sb_req('embed_url')) {   // one video / post embed with a caption, at a chosen place
            $e = sb_get_extras($pid);
            $e['embeds'] = array(array('url' => (string) sb_req('embed_url'), 'label' => (string) sb_req('embed_label'), 'pos' => (string) sb_req('embed_pos', 'p:1'), 'r' => (string) sb_req('embed_r')));
            update_post_meta($pid, '_sb_extras', sb_clean_extras($e));
        }
        if ('' !== (string) sb_req('summary')) {
            update_post_meta($pid, '_sb_summary', sanitize_textarea_field((string) sb_req('summary')));
        }
        if ('' !== (string) sb_req('meta')) {
            update_post_meta($pid, '_sb_meta_desc', sanitize_textarea_field((string) sb_req('meta')));
            wp_update_post(array('ID' => $pid, 'post_excerpt' => sanitize_textarea_field((string) sb_req('meta'))));
        }
        if ('' !== (string) sb_req('seo_title')) {
            update_post_meta($pid, '_sb_seo_title', sanitize_text_field((string) sb_req('seo_title')));
        }
        sb_seo_sync($pid);
        wp_send_json_success(array('msg' => 'ঠিক করা হয়েছে', 'status' => get_post_status($pid), 'slug' => get_post_field('post_name', $pid)));
    }
    if ('promo_save' === $op) {   // the promo-card library, as JSON
        $j = json_decode((string) wp_unslash($_POST['promos'] ?? ''), true);
        $c = sb_promo_clean($j);
        update_option('sb_promos', $c, false);
        wp_send_json_success(array('msg' => count($c) . ' কার্ড সংরক্ষিত', 'ids' => array_map(function ($x) { return $x['id']; }, $c)));
    }
    if ('test_notice' === $op || 'test_blog' === $op) {   // writes a DRAFT only
        wp_set_current_user(sb_desk_uid());
        $r = 'test_notice' === $op
            ? sb_desk_notice("আবেদনের নোটিশ\nশাহীন স্কুল এন্ড ক্যাডেট কোচিং, মাদারীপুর শাখায় সহকারী শিক্ষক নিয়োগ ২০২৭। আবেদনের শেষ তারিখ ২০ ডিসেম্বর ২০২৬। যোগ্যতা: স্নাতক পাস। যোগাযোগ: 01700000000", array(), 'draft')
            : sb_desk_blog(sanitize_textarea_field((string) sb_req('text')) ?: 'শিশুর বানান শেখার সহজ ৫টি উপায়', array(), 'draft', array_filter(array('title' => (string) sb_req('title'), 'keywords' => (string) sb_req('keywords'), 'slug' => (string) sb_req('slug'), 'meta' => (string) sb_req('meta'), 'summary' => (string) sb_req('summary'), 'length' => (string) sb_req('length'), 'instruction' => (string) sb_req('instruction'))));
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
        <p><button type="button" class="button button-primary" id="dk-on"><?php echo $c['on'] ? '🔄 আবার সংযোগ দিন' : '▶️ ডেস্ক চালু করুন'; ?></button> <button type="button" class="button" id="dk-off">⏹️ বন্ধ</button> <button type="button" class="button" id="dk-info">ℹ️ অবস্থা</button> <button type="button" class="button" id="dk-who">🔎 টোকেন কার?</button> <button type="button" class="button" id="dk-find">📡 চ্যানেল খুঁজুক</button> <button type="button" class="button" id="dk-tn">🧪 নোটিশ টেস্ট (খসড়া)</button> <button type="button" class="button" id="dk-tb">🧪 ব্লগ টেস্ট (খসড়া)</button> <span id="dk-msg" class="sba-msg"></span></p>
    </div>
    <script>
    (function(){var $=function(s){return document.querySelector(s);},aj=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nn=<?php echo wp_json_encode(wp_create_nonce('sb_admin')); ?>;
        function call(op,x){$('#dk-msg').textContent='… (AI কাজ হলে ১-৩ মিনিট লাগতে পারে)';return fetch(aj,{method:'POST',credentials:'same-origin',body:new URLSearchParams(Object.assign({action:'sb_desk_op',nonce:nn,op:op},x||{}))}).then(function(r){return r.json();}).then(function(j){$('#dk-msg').textContent=j.success?j.data.msg:((j.data&&j.data.message)||'ত্রুটি');});}
        $('#dk-on').onclick=function(){call('save',{on:'1',publish:$('#dk-pub').checked?'1':'0'}).then(function(){return call('hook');});};
        $('#dk-off').onclick=function(){call('unhook');};
        $('#dk-info').onclick=function(){call('info');};
        $('#dk-who').onclick=function(){call('who');};
        $('#dk-find').onclick=function(){call('find');};
        $('#dk-tn').onclick=function(){call('test_notice');};
        $('#dk-tb').onclick=function(){call('test_blog');};
    })();
    </script>
    <?php
}
