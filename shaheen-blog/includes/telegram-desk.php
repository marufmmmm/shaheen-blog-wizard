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
        return new WP_Error('sb_tg', 'The Telegram token is not set.');
    }
    $r = wp_remote_post('https://api.telegram.org/bot' . $o['tg_token'] . '/' . $method, array('timeout' => $timeout, 'body' => $args));
    if (is_wp_error($r)) {
        return $r;
    }
    $j = json_decode(wp_remote_retrieve_body($r), true);
    if (empty($j['ok'])) {
        return new WP_Error('sb_tg', 'Telegram: ' . (is_array($j) && !empty($j['description']) ? $j['description'] : 'HTTP ' . (int) wp_remote_retrieve_response_code($r)));
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
        return new WP_Error('sb_tg', 'Could not download the picture.');
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
        sb_desk_say($m, '⚠️ The task did not finish: ' . esc_html(mb_substr($e->getMessage(), 0, 200)));
    }
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sb_desk_u_%%' AND option_value < %d", time() - DAY_IN_SECONDS)); // phpcs:ignore
    exit;
}

/* ------------------------------------------------------------ conversation engine */
function sb_desk_help() {
    return "📌 <b>Shaheen Desk</b>\n\n/menu — choose what to do (I will ask step by step)\n/cancel — cancel the current task\n\n<b>Shortcuts:</b>\n<code>/blog topic or keyword</code> — write and publish a blog directly\n<code>/notice text</code> — a notice directly\nSend a news link on its own (or <code>/news link</code>) to make a news card.\nSend a notice picture on its own to start the notice steps.\n<code>/dress</code> — add a dress sample photo (caption: <code>/dress cadet college girl</code>).\n<code>/held</code> — held-back news: keep or drop by number.";
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
    sb_desk_say($m, "🗂️ <b>What would you like to do?</b>", array(
        array(sb_desk_btn('📢 Notice', 'm:notice'), sb_desk_btn('✍️ Blog', 'm:blog')),
        array(sb_desk_btn('✏️ Edit notice', 'm:edit')),
        array(sb_desk_btn('✏️ Edit blog', 'm:eblog'), sb_desk_btn('✏️ Edit news', 'm:enews')),
        array(sb_desk_btn('📰 News (send link)', 'm:news'), sb_desk_btn('🛑 Held-back news', 'm:held')),
        array(sb_desk_btn('⭐ Spelling Star', 'm:soon')),
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
    return array_merge($st, array('length', 'image'), 'pick' === ($d['imgmode'] ?? '') ? array_merge(array('dress'), sb_desk_needs_who($d['uniform'] ?? '') ? array('who') : array()) : array(), array('confirm'));
}

/** The ONE question for a notice: a fill-in list, built from what the site's old notices really contain. */
function sb_desk_template($type) {
    $tail = "\n\n📎 Have a poster, picture or PDF? Send it too (before or after this message).\n💡 Leave a line empty if you do not know it. The AI will not make up anything.\n📞 No need to write the phone number. It is added from the branch page.\n\nWhen you have sent everything, press <b>✅ Done</b>.";
    $t = array(
        'niyog' => "Branch: (e.g. Madaripur)\nJob and year: (e.g. Assistant Teacher, 2027)\nLast date to apply: (date and time)\nWritten test date and time: \nTest place (address): \nRules: (one per line)\nGoogle Form link: \nFacebook post link: ",
        'niyog_result' => "Branch: (e.g. Madaripur)\nResult PDF or Drive link: \nViva date and time: \nWhat to do next: \nImportant notes: (one per line)",
        'britti' => "Title: (e.g. Scholarship Exam 2027)\nLast date to apply: \nExam date: \nExam time: \nExam place: \nSyllabus or circular PDF link: \nApply link: \nRules: (one per line)",
        'britti_result' => "Title: \nResult PDF or link: \nResult date: \nScholarship amount: \nImportant notes: ",
        'form' => "Title: \nLast date to apply: \nForm link (a Google Form shows inside the page): \nRules: ",
    );
    return isset($t[$type]) ? "<code>" . esc_html($t[$type]) . "</code>" . $tail : "<code>" . esc_html("Title: \nBranch (if any): \nMain facts and dates: \nRules: (one per line)\nApply or form link: \nPDF download link: ") . "</code>" . $tail;
}

function sb_desk_ask($m, $s) {
    $steps = sb_desk_steps($s['flow'], $s['d']);
    $k     = $steps[$s['i']];
    $skip  = sb_desk_btn('⏭️ Skip', 'skip');
    $x     = sb_desk_btn('❌ Cancel', 'cancel');
    switch ($k) {
        case 'links':
            return sb_desk_say($m, "📰 <b>Send the news link(s).</b> One per line if more than one (max 5).\nI will read the page, hold it back if it is negative or doubtful, and otherwise make a news card (title, newspaper, date, picture, summary) on the site.\n<i>Facebook/YouTube links are not news: they belong to notices or events.</i>", array(array($x)));
        case 'pick':
            $rows = array();
            $kd = $s['d']['kind'] ?? 'notice';
            foreach (sb_desk_kind_list($kd) as $pid) {
                $rows[] = array(sb_desk_btn(mb_substr(get_the_title($pid), 0, 38) . ' · ' . get_the_date('d/m', $pid) . ('draft' === get_post_status($pid) ? ' · draft' : ''), 'p:' . $pid));
            }
            $rows[] = array($x);
            return sb_desk_say($m, '✏️ <b>Which ' . ('blog' === $kd ? 'blog' : ('news' === $kd ? 'news card' : 'notice')) . ' do you want to change?</b> (latest 5)', $rows);
        case 'act':
            $kd = $s['d']['kind'] ?? 'notice';
            if ('blog' === $kd || 'news' === $kd) {
                $pub = 'publish' === get_post_status((int) $s['d']['id']);
                $tt  = esc_html(get_the_title((int) $s['d']['id']));
                $st  = array(sb_desk_btn($pub ? '📥 Make draft' : '🌐 Publish', $pub ? 'a:draft' : 'a:pub'));
                if ('news' === $kd) {
                    return sb_desk_say($m, "🛠️ <b>What do you want to change?</b>\n" . $tt, array(
                        array(sb_desk_btn('🏷️ Title', 'a:title'), sb_desk_btn('📝 Summary', 'a:summary')),
                        array(sb_desk_btn('🔗 News link', 'a:nlink'), sb_desk_btn('📰 Newspaper name', 'a:paper')),
                        array(sb_desk_btn('🖼️ Change picture', 'a:img'), $st[0]),
                        array($x),
                    ));
                }
                return sb_desk_say($m, "🛠️ <b>What do you want to change?</b>\n" . $tt, array(
                    array(sb_desk_btn('🏷️ Title', 'a:title'), sb_desk_btn('📝 Summary', 'a:summary')),
                    array(sb_desk_btn('🔎 Google description', 'a:meta'), sb_desk_btn('🔑 Keywords', 'a:kw')),
                    array(sb_desk_btn('🎬 Video/Facebook post', 'a:embed'), sb_desk_btn('📣 Promo card', 'a:promo')),
                    array(sb_desk_btn('🔤 English title', 'a:entitle'), sb_desk_btn('🔗 Slug (link)', 'a:slug')),
                    array(sb_desk_btn('🖼️ Change picture', 'a:img'), sb_desk_btn('🎨 New AI picture', 'a:aiimg')),
                    array(sb_desk_btn('🏷️ Picture alt text', 'a:alt'), $st[0]),
                    array(sb_desk_btn('✍️ Change the text (describe it)', 'a:free')),
                    array($x),
                ));
            }
            return sb_desk_say($m, "🛠️ <b>What do you want to change?</b>
" . esc_html(get_the_title((int) $s['d']['id'])), array(
                array(sb_desk_btn('📘 Add Facebook post', 'a:fb'), sb_desk_btn('📅 Extend deadline', 'a:deadline')),
                array(sb_desk_btn('📞 Phone numbers', 'a:phones'), sb_desk_btn('🔗 Apply/form link', 'a:apply')),
                array(sb_desk_btn('📎 PDF/download', 'a:pdf'), sb_desk_btn('🖼️ Change picture', 'a:img')),
                array(sb_desk_btn('✍️ Other change (describe it)', 'a:free')),
                array($x),
            ));
        case 'value':
            if (in_array($s['d']['kind'] ?? 'notice', array('blog', 'news'), true)) {
                $pl = array();
                foreach (sb_promos() as $p) {
                    $pl[] = '<code>' . esc_html($p['id']) . '</code> = ' . esc_html($p['title']);
                }
                $qq = array(
                    'title' => '🏷️ <b>Write the new title.</b>',
                    'summary' => '📝 <b>Write the new summary</b> (2–3 sentences; the Google description is made from it too).',
                    'meta' => '🔎 <b>Write the Google description</b> (max 160 characters).',
                    'kw' => '🔑 <b>Write the keywords</b> (comma separated, max 15). They replace the old ones.',
                    'embed' => "🎬 <b>Send the video or Facebook post link.</b>\nLink on the first line, caption on the second (optional). It goes after the first paragraph.",
                    'promo' => "📣 <b>Which promo card?</b> Write the name (comma separated if several), or <code>none</code> to remove.\n" . implode("\n", $pl),
                    'img' => '🖼️ <b>Send the new picture.</b>',
                    'entitle' => '🔤 <b>Write the English title</b> (4–9 words). It stays in the back end; readers do not see it. Write <code>auto</code> and the AI makes it.',
                    'slug' => '🔗 <b>Write the end of the link (slug)</b> in English, e.g. <code>cadet-college-admission-tips</code>. Write <code>auto</code> to make a simple slug from the English title.',
                    'aiimg' => "🎨 <b>Which dress for the picture?</b> Write <code>auto</code> (from the text), <code>none</code> (no dress), or the kind of dress.\nCost about 7 cents. The old picture stays in Media.",
                    'alt' => '🏷️ <b>Write the alt text of the main picture</b> (what is in it, one sentence). Write <code>auto</code> and the AI looks at the picture.',
                    'nlink' => '🔗 <b>Send the full link of the news.</b>',
                    'paper' => '📰 <b>Write the newspaper name.</b>',
                    'free' => '✍️ <b>Describe what to change in the text.</b> For example: "add the branch phone number to the last paragraph".',
                );
                return sb_desk_say($m, $qq[$s['d']['act']] ?? 'Send it as text.', array(array($x)));
            }
            $q = array(
                'fb' => "📘 <b>Send the Facebook post link.</b> A share link (facebook.com/share/…) works too. The post must be public.",
                'deadline' => "📅 <b>Write the new deadline</b> (e.g. 30 December 2026, 10:00 PM).",
                'phones' => "📞 <b>Send the new phone numbers</b> (one per line or comma separated). They replace the old numbers.",
                'apply' => "🔗 <b>Send the application link or the Google Form link.</b>",
                'pdf' => "📎 <b>Send the PDF directly, or write the download link.</b>",
                'img' => "🖼️ <b>Send the new picture/poster.</b>",
                'free' => "✍️ <b>Describe what to change.</b> For example: \"change the exam venue to the Dhaka branch\".",
            );
            return sb_desk_say($m, $q[$s['d']['act']], array(array($x)));
        case 'type':
            $row = array();
            foreach ((array) csa_sn_opt('types') as $key => $t) {
                $row[] = array(sb_desk_btn($t['label'], 't:' . $key));
            }
            $row[] = array(sb_desk_btn('📄 Something else', 't:'), $x);
            return sb_desk_say($m, "📢 <b>What is this notice about?</b>\nTap one button.", $row);
        case 'branch':
            return sb_desk_say($m, "📍 <b>Which branch or place?</b> (e.g. Madaripur branch)\nType it, or let the AI take it from the notice.", array(array(sb_desk_btn('🤖 AI decides / for everyone', 'skip'), $x)));
        case 'details':
            $n = count($s['d']['aids'] ?? array());
            $tl = ($s['d']['type'] ?? '') !== '' ? (csa_sn_opt('types')[$s['d']['type']]['label'] ?? '') : 'General';
            return sb_desk_say($m, "📝 <b>" . esc_html($tl) . " notice</b>\nStep 1: Copy the list below.\nStep 2: Fill it in and send it as one message.\n\n" . sb_desk_template($s['d']['type'] ?? '') . ($n ? "\n\n✅ Received so far: " . $n . ' picture(s)' . (!empty($s['d']['text']) ? ' and your text' : '') : ''), array(array(sb_desk_btn('✅ Done', 'done'), sb_desk_btn('❌ Cancel', 'cancel'))));
        case 'pdf':
            return sb_desk_say($m, "📎 <b>Is there a PDF/file to download?</b>\nSend the PDF directly, or send the download link.", array(array($skip, $x)));
        case 'apply':
            return sb_desk_say($m, "🔗 <b>Is there an application link or Google Form?</b>\nSend the link. A Google Form is embedded in the notice page.", array(array($skip, $x)));
        case 'topic':
            return sb_desk_say($m, "✍️ <b>What is the blog topic?</b> Send it in a few words (required; the AI can do everything else).", array(array($x)));
        case 'title':
            return sb_desk_say($m, "🏷️ <b>Title?</b> Type it, or let the AI make it.", array(array(sb_desk_btn('🤖 AI makes it', 'ai'), $x)));
        case 'keywords':
            return sb_desk_say($m, "🔑 <b>Keywords?</b> Write them comma separated, or let the AI decide.", array(array(sb_desk_btn('🤖 AI decides', 'ai'), $x)));
        case 'body':
            return sb_desk_say($m, "📄 <b>How should the article be written?</b>", array(
                array(sb_desk_btn('🤖 AI writes it all', 'b:ai')),
                array(sb_desk_btn('📝 I give a short description, AI expands it', 'b:desc')),
                array(sb_desk_btn('📋 My full text is ready', 'b:paste')),
                array($x),
            ));
        case 'bodytext':
            return sb_desk_say($m, ('paste' === $s['d']['body'] ? "📋 <b>Send the full text</b> (in one message)." : "📝 <b>Send your description.</b> The AI will write the full article from it."), array(array($x)));
        case 'length':
            return sb_desk_say($m, "📏 <b>How long should the article be?</b>", array(array(sb_desk_btn('Short', 'l:short'), sb_desk_btn('Medium', 'l:medium'), sb_desk_btn('Long', 'l:long')), array($x)));
        case 'image':
            return sb_desk_say($m, "🖼️ <b>How should the picture be?</b>\n• If you have your own pictures, send them now (several are fine; the first is the main picture), then press \"✅ Pictures sent\".\n• Otherwise press \"AI picture\". I will then ask which dress the people wear.", array(
                array(sb_desk_btn('🎨 Make an AI picture', 'img:pick')),
                array(sb_desk_btn('✅ Pictures sent', 'done'), sb_desk_btn('🚫 No picture', 'img:none')),
                array($x),
            ));
        case 'dress':
            list($txt, $rows) = sb_desk_dress_kb('u:');
            $rows[] = array($x);
            return sb_desk_say($m, $txt, $rows);
        case 'who':
            return sb_desk_say($m, "👥 <b>Who should be in the picture?</b>\nDress: " . esc_html(sb_desk_dress_label(preg_replace('/:[bgm]$/', '', (string) ($s['d']['uniform'] ?? '')))), array_merge(sb_desk_who_kb('g:'), array(array($x))));
        case 'confirm':
            $d = $s['d'];
            if ('news' === $s['flow']) {
                $ls = '';
                foreach ((array) $d['urls'] as $u) {
                    $ls .= "\n• " . esc_html(mb_substr($u, 0, 90));
                }
                return sb_desk_say($m, "📰 <b>News card</b> (" . count((array) $d['urls']) . " link(s))" . $ls . "\n\nI will check each one (and hold back negative ones) and make news cards. Go ahead?", array(array(sb_desk_btn('✅ Check and make cards', 'go:publish'), $x)));
            }
            if ('edit' === $s['flow']) {
                return sb_desk_say($m, "✏️ <b>Make this change?</b>\n\n" . $d['desc'], array(array(sb_desk_btn('✅ Apply', 'go:apply'), $x)));
            }
            if ('notice' === $s['flow']) {
                $tl = ($d['type'] ?? '') !== '' ? (csa_sn_opt('types')[$d['type']]['label'] ?? $d['type']) : 'General';
                $sum = "📢 <b>Notice</b>\nType: " . esc_html($tl) . "\nPictures: " . count($d['aids'] ?? array()) . "; PDF: " . (!empty($d['pdf_aid']) ? 'yes' : 'no') . "; Text: " . (!empty($d['text']) ? mb_strlen($d['text']) . ' characters' : 'none') . "\n\nThe AI will arrange the dates, links and rules neatly. The phone numbers come from the branch page.";
            } else {
                $bm = array('ai' => 'AI writes it all', 'desc' => 'AI from your description', 'paste' => 'Your own text');
                $sum = "✍️ <b>Blog</b>\nTopic: " . esc_html($d['topic']) . "\nTitle: " . esc_html(($d['title'] ?? '') ?: 'AI') . "\nKeywords: " . esc_html(($d['keywords'] ?? '') ?: 'AI') . "\nText: " . $bm[$d['body'] ?? 'ai'] . "\nLength: " . esc_html($d['length'] ?? 'medium') . "\nPicture: " . (count($d['aids'] ?? array()) ? count($d['aids']) . ' (yours)' : ('none' === ($d['imgmode'] ?? 'auto') ? 'not needed' : 'AI makes it, dress: ' . esc_html(sb_desk_dress_label(($d['uniform'] ?? 'auto') ?: 'auto')))) . "\nSEO: English title, simple slug, alt text, meta and keywords are all automatic";
            }
            return sb_desk_say($m, $sum . "\n\nWhat do you want to do?\n✅ Publish = goes live now.\n📝 Draft only = saved, not shown to the public.", array(array(sb_desk_btn('✅ Publish', 'go:publish')), array(sb_desk_btn('📝 Draft only', 'go:draft'), $x)));
    }
}

function sb_desk_start($m, $flow, $d = array()) {
    $s = array('flow' => $flow, 'i' => 0, 'd' => $d);
    if ('notice' === $flow && !function_exists('csa_sn_opt')) {
        return sb_desk_say($m, '⚠️ The notice system is not active.');
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
        if ('held' === $cmd) {   // held-back news
            sb_desk_unlock($lock);
            sb_desk_held_show($m, 0);
            return;
        }
        if ('dress' === $cmd) {   // a dress photo for the Uniform Library
            sb_desk_unlock($lock);
            if (empty($in['img'])) {
                sb_desk_say($m, "👕 <b>Add a dress sample photo</b>\nSend a photo and write this in its caption:\n<code>/dress cadet college girl</code>\n\nKinds: Bangla medium, English version, Islamic, cadet college, teacher. Add boy or girl too. For best results send a clear photo of one person in the full dress.");
            } else {
                sb_desk_say($m, sb_desk_dress_add((int) $in['img'], $rest));
            }
            return;
        }
        $s = sb_desk_state($m);
        if ($s && 'held' === $s['flow'] && 'cancel' === $cmd) {   // "বাতিল 2" is an answer in the held-news list, not a cancel
            $cmd = '';
        }
        $newslink = !$s && '' === $cmd && !$fid && sb_desk_urls($text) && mb_strlen(trim(preg_replace('#https?://\S+#i', '', $text))) < 12;
        if (in_array($cmd, array('menu', 'help', 'cancel'), true) || ('' === $cmd && !$s && !$fid && !$newslink)) {
            sb_desk_unlock($lock);
            if ('help' === $cmd) {
                sb_desk_say($m, sb_desk_help());
            } elseif ('cancel' === $cmd) {
                sb_desk_clear($m);
                sb_desk_say($m, 'Cancelled ✓');
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
    if (0 === strpos($cb, 'sh:') && function_exists('sb_share_cb')) { // share approval buttons
        sb_desk_unlock($lock);
        sb_share_cb($m, $cb);
        return;
    }
    if (0 === strpos($cb, 'm:')) { // menu choice
        sb_desk_unlock($lock);
        $f = substr($cb, 2);
        if ('held' === $f) {
            sb_desk_held_show($m, 0);
            return;
        }
        if ('soon' === $f) {
            sb_desk_say($m, '⭐ There is no separate Spelling Star step yet. Notice, blog and news work now. To write about Spelling Star use <code>/blog Spelling Star ...</code>.');
        } else {
            if ('eblog' === $f || 'enews' === $f) {
                $kd = 'eblog' === $f ? 'blog' : 'news';
                if (!sb_desk_post_list($kd)) {
                    sb_desk_say($m, 'There is no ' . ('blog' === $kd ? 'blog' : 'news card') . ' to edit yet.');
                    return;
                }
                sb_desk_start($m, 'edit', array('kind' => $kd));
                return;
            }
            if ('edit' === $f) {
                if (!sb_desk_edit_list()) {
                    sb_desk_say($m, 'There is no new-design notice to edit yet. First make one from /menu.');
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
        sb_desk_say($m, 'Nothing is running. Write /menu to start.');
        return;
    }
    $steps = sb_desk_steps($s['flow'], $s['d']);
    $k     = $steps[$s['i']];
    $cb    = isset($in['cb']) ? $in['cb'] : '';
    $t     = isset($in['t']) ? trim($in['t']) : '';
    $stay  = false;
    $d     = $s['d'];
    if ('held' === $s['flow']) {   // numbered list of held-back news: keep / drop by number
        sb_desk_unlock($lock);
        sb_desk_held_step($m, $s, $in);
        return;
    }
    if ('qblog' === $s['flow']) {   // /blog shortcut waiting for the dress choice
        $qc = isset($in['cb']) ? (string) $in['cb'] : '';
        if (0 !== strpos($qc, 'qd:')) {
            sb_desk_unlock($lock);
            if ('cancel' === $qc) {
                sb_desk_clear($m);
                sb_desk_say($m, 'Cancelled ✓ (start again with /menu)');
            } else {
                sb_desk_say($m, 'Choose a dress with the buttons above, or press ❌ Cancel.');
            }
            return;
        }
        $qd = array('topic' => (string) $s['d']['topic'], 'aids' => array());
        if ('qd:imgnone' === $qc) {
            $qd['imgmode'] = 'none';
        } elseif (preg_match('/^qd:(cat:[a-z]+)$/', $qc, $qm) && sb_desk_needs_who($qm[1])) {   // a kind of dress: ask who is shown
            $s['d']['uniform'] = $qm[1];
            sb_desk_save($m, $s);
            sb_desk_unlock($lock);
            $kb = sb_desk_who_kb('qd:' . $qm[1] . ':');
            $kb[] = array(sb_desk_btn('❌ Cancel', 'cancel'));
            sb_desk_say($m, "👥 <b>Who should be in the picture?</b>\nDress: " . esc_html(sb_desk_dress_label($qm[1])), $kb);
            return;
        } else {
            $qd['imgmode'] = 'pick';
            $qd['uniform'] = substr($qc, 3);
        }
        sb_desk_clear($m);
        sb_desk_unlock($lock);
        sb_desk_run($m, 'blog', $qd, sb_desk_cfg()['publish'] ? 'publish' : 'draft');
        return;
    }
    if (!empty($in['img']) && 'blog' === $s['flow'] && 'image' !== $k) {   // a photo sent before the picture step is kept for it
        $d['aids'] = isset($d['aids']) && is_array($d['aids']) ? $d['aids'] : array();
        $d['aids'][] = (int) $in['img'];
        $s['d'] = $d;
        sb_desk_save($m, $s);
        sb_desk_unlock($lock);
        sb_desk_album_say($m, '👍 Picture saved (' . count($d['aids']) . ' so far). They will be used in the picture step. Now answer the current question.');
        return;
    }
    if ('cancel' === $cb) {
        sb_desk_clear($m);
        sb_desk_unlock($lock);
        sb_desk_say($m, 'Cancelled ✓ (start again with /menu)');
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
            if (0 === strpos($cb, 'p:') && in_array((int) substr($cb, 2), sb_desk_kind_list($d['kind'] ?? 'notice'), true)) {
                $d['id'] = (int) substr($cb, 2);
            } else {
                $stay = true;
            }
            break;
        case 'act':
            if (0 === strpos($cb, 'a:')) {
                $d['act'] = substr($cb, 2);
                if (in_array($d['act'], array('pub', 'draft'), true) && in_array($d['kind'] ?? 'notice', array('blog', 'news'), true)) {
                    $r = sb_desk_post_propose((int) $d['id'], $d['kind'], $d['act'], array());
                    if (!is_wp_error($r)) {
                        $d = array_merge($d, $r);
                        $s['i']++;   // no value step for publish / draft
                    }
                }
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
            $r = in_array($d['kind'] ?? 'notice', array('blog', 'news'), true) ? sb_desk_post_propose((int) $d['id'], $d['kind'], $d['act'], $val) : sb_desk_edit_propose((int) $d['id'], $d['act'], $val);
            if (is_wp_error($r)) {
                sb_desk_say($m, '⚠️ ' . esc_html($r->get_error_message()) . "\nSend again or press ❌ Cancel.");
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
                    sb_desk_say($m, '👍 Got it (' . count($d2['aids']) . ' picture(s)' . (!empty($d2['pdf_aid']) ? ', PDF' : '') . (!empty($d2['text']) ? ', text' : '') . '). Send more if you have it. If not, press "✅ Done".', array(array(sb_desk_btn('✅ Done', 'done'), sb_desk_btn('❌ Cancel', 'cancel'))));
                    $now['ack'] = 0;
                    sb_desk_save($m, $now);
                }
                return;
            }
            if (!$d['aids'] && empty($d['pdf_aid']) && '' === trim((string) ($d['text'] ?? ''))) {
                $stay = true;
                sb_desk_say($m, 'You sent nothing — send the filled list, or a picture/PDF.');
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
                sb_desk_album_say($m, '👍 Picture received (' . count($d['aids']) . ' so far). Send more if there is more, otherwise press "✅ Pictures sent".', array(array(sb_desk_btn('✅ Pictures sent', 'done'), sb_desk_btn('❌ Cancel', 'cancel'))));
                return;
            }
            if (0 === strpos($cb, 'img:')) {
                $d['imgmode'] = substr($cb, 4);   // pick | auto | none
                if ('auto' === $d['imgmode']) {
                    $d['uniform'] = 'auto';
                }
            } elseif ('done' === $cb) {
                $d['imgmode'] = 'sent';
            } elseif ('skip' === $cb) {
                $d['imgmode'] = 'auto';
            } else {
                $stay = true;
            }
            break;
        case 'dress':
            if (0 === strpos($cb, 'u:')) {
                $d['uniform'] = substr($cb, 2);
            } else {
                $stay = true;
            }
            break;
        case 'who':
            if (preg_match('/^g:([bgm])$/', $cb, $gm)) {
                $d['uniform'] = preg_replace('/:[bgm]$/', '', (string) ($d['uniform'] ?? '')) . ':' . $gm[1];
            } else {
                $stay = true;
            }
            break;
        case 'confirm':
            if ('edit' === $s['flow'] && 'go:apply' === $cb) {
                sb_desk_clear($m);
                sb_desk_unlock($lock);
                $r = in_array($d['kind'] ?? 'notice', array('blog', 'news'), true) ? sb_desk_post_apply((int) $d['id'], $d['kind'], $d) : sb_desk_edit_apply((int) $d['id'], $d);
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
        sb_desk_say($m, 'For this step use the buttons below or give a proper answer 🙂');
    }
    sb_desk_ask($m, $s);
}

/** Runs the finished request in the background and reports the link. */
function sb_desk_run($m, $flow, $d, $status) {
    if ('news' === $flow) {
        return sb_desk_news_run($m, (array) ($d['urls'] ?? array()));
    }
    sb_desk_say($m, 'notice' === $flow ? '⏳ Reading and arranging the notice…' : '⏳ Writing the blog and doing SEO (usually 1–3 minutes)…');
    if ('notice' === $flow) {
        $r = sb_desk_notice((string) ($d['text'] ?? ''), (array) ($d['aids'] ?? array()), $status, $d);
    } else {
        $r = sb_desk_blog($d['topic'], (array) ($d['aids'] ?? array()), $status, $d);
    }
    if (is_wp_error($r)) {
        sb_desk_say($m, '⚠️ ' . esc_html($r->get_error_message()));
        return;
    }
    sb_desk_say($m, $r['msg'] . "\n\n/menu — another task");
}

/** /blog text and /notice text typed directly (no questions). */
function sb_desk_quick($m, $cmd, $rest, $aids) {
    if ('notice' === $cmd) {
        sb_desk_say($m, '⏳ Reading and arranging the notice…');
        $r = sb_desk_notice($rest, $aids, sb_desk_cfg()['publish'] ? 'publish' : 'draft');
    } else {
        if (mb_strlen($rest) < 4) {
            sb_desk_say($m, 'Write the blog topic or keyword, e.g. <code>/blog Spelling Star preparation</code>');
            return;
        }
        if (!$aids) {   // no own photo: ask which dress the AI picture should show
            sb_desk_save($m, array('flow' => 'qblog', 'i' => 0, 'd' => array('topic' => $rest, 'aids' => array())));
            list($txt, $rows) = sb_desk_dress_kb('qd:');
            $rows[] = array(sb_desk_btn('🚫 No picture', 'qd:imgnone'), sb_desk_btn('❌ Cancel', 'cancel'));
            sb_desk_say($m, "✍️ Topic: " . esc_html(mb_substr($rest, 0, 120)) . "\n\n" . $txt, $rows);
            return;
        }
        sb_desk_say($m, '⏳ Writing the blog (usually 1–3 minutes)…');
        $r = sb_desk_blog($rest, $aids, sb_desk_cfg()['publish'] ? 'publish' : 'draft');
    }
    sb_desk_say($m, is_wp_error($r) ? '⚠️ ' . esc_html($r->get_error_message()) : $r['msg']);
}

/** Splits "/blog text" style commands. Returns array(cmd, rest). */
function sb_desk_cmd($text) {
    if (preg_match('~^\s*(/?)(blog|ব্লগ|notice|নোটিশ|news|সংবাদ|dress|ড্রেস|held|আটকানো|help|সাহায্য|start|menu|মেনু|cancel|বাতিল)(?:@\w+)?(?=[\s:：\-–]|$)[\s:：\-–]*(.*)$~isu', $text, $mm) && ('/' === $mm[1] || !in_array(mb_strtolower($mm[2]), array('start', 'menu', 'cancel', 'help'), true))) {
        $k   = mb_strtolower($mm[2]);
        $map = array('ব্লগ' => 'blog', 'নোটিশ' => 'notice', 'সংবাদ' => 'news', 'ড্রেস' => 'dress', 'আটকানো' => 'held', 'সাহায্য' => 'help', 'start' => 'menu', 'মেনু' => 'menu', 'বাতিল' => 'cancel');
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
    $fb = (bool) preg_match('#facebook\.com|fb\.watch|fb\.me#i', $url);   // public Facebook posts: Facebook's own link-preview robot is let in
    $r = wp_remote_get($url, array('timeout' => 14, 'redirection' => 5, 'limit_response_size' => 600000,
        'user-agent' => $fb ? 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)' : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'headers' => array('Accept-Language' => 'bn,en;q=0.8')));
    if (is_wp_error($r)) {
        return new WP_Error('sb_news', 'The page could not be opened.');
    }
    $code = (int) wp_remote_retrieve_response_code($r);
    if (200 !== $code) {
        return new WP_Error('sb_news', 'The page did not open (code ' . $code . '). The site may be blocking the server.');
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
    if ($fb && '' === $desc && '' === $title) {
        return new WP_Error('sb_news', 'The Facebook post is not public or gave no page data. Make the post "Public", or send the text and picture directly.');
    }
    return array('id' => substr(md5($url . microtime(true)), 0, 10), 't' => time(), 'url' => $url, 'host' => $fb ? 'ফেসবুক' : (string) wp_parse_url($url, PHP_URL_HOST), 'fb' => $fb,
        'title' => mb_substr(sanitize_text_field($title), 0, 300), 'text' => mb_substr(sanitize_textarea_field(($desc !== '' ? $desc . "\n" : '') . $plain), 0, 8000),
        'images' => ($img && preg_match('#^https?://#i', $img)) ? array(esc_url_raw($img)) : array(), 'videos' => array());
}

function sb_desk_news_run($m, $urls) {
    if (!function_exists('sb_ca_process')) {
        return sb_desk_say($m, '⚠️ The news collector is not active.');
    }
    sb_desk_say($m, '⏳ Reading and checking ' . count($urls) . ' link(s)…');
    $out    = array();
    $offers = array();
    foreach (array_slice($urls, 0, 5) as $u) {
        if (preg_match('#facebook\.com|fb\.watch|fb\.me|youtube\.com|youtu\.be#i', $u)) {   // picture = the post's / video's own thumbnail
            list($st, $note, $ti) = sb_ca_social_card($u);
            $ic = array('filed' => '✅ Published', 'blocked' => '🛑 Held back', 'skipped' => '⏭️ Skipped', 'dup' => '♻️ Already exists', 'error' => '⚠️ Failed');
            $out[] = ($ic[$st] ?? $st) . ': ' . esc_html(mb_substr($ti ?: $u, 0, 80)) . "\n   " . esc_html($note);
            if ('filed' === $st && function_exists('sb_share_offer')) {
                $offers[] = array(sb_ca_canon_social(esc_url_raw($u)), (string) $ti);
            }
            continue;
        }
        $it = sb_desk_news_item($u);
        if (is_wp_error($it)) {
            $out[] = '⚠️ ' . esc_html($u) . "\n   " . esc_html($it->get_error_message());
            continue;
        }
        $r  = sb_ca_process($it, true);
        $ic = array('filed' => '✅ Published', 'blocked' => '🛑 Held back', 'skipped' => '⏭️ Skipped', 'dup' => '♻️ Already exists', 'error' => '⚠️ Failed');
        $out[] = ($ic[$r[0]] ?? $r[0]) . ': ' . esc_html(mb_substr($it['title'] ?: $u, 0, 80)) . "\n   " . esc_html($r[1]);
    }
    update_option('sb_desk_lastrun', array('t' => time(), 'out' => $out), false);
    sb_desk_say($m, "📰 <b>News results</b>\n\n" . implode("\n\n", $out) . "\n\n🛑 Held-back news is reported to you separately (Blog → News collector).\n/menu — another task");
    foreach ($offers as $of) {   // ask before sharing anything: Instagram/YouTube drafts need a tap to go
        sb_share_offer($m, $of[0], $of[1]);
    }
}



/** Friendly English slug from the English title (a few plain words), else from keywords / title. */
function sb_friendly_slug($en, $kw, $title = '') {
    $stop = array('a', 'an', 'the', 'of', 'and', 'for', 'in', 'on', 'to', 'with', 'by', 'at', 'from', 'is', 'are');
    $en = trim((string) $en);
    if ('' !== $en && preg_match('/[A-Za-z]{3,}/', $en)) {
        $w = preg_split('/[^a-z0-9]+/', strtolower(remove_accents($en)), -1, PREG_SPLIT_NO_EMPTY);
        $w = array_values(array_filter($w, function ($x) use ($stop) { return !in_array($x, $stop, true); }));
        $s = '';
        foreach (array_slice($w, 0, 7) as $x) {
            if (strlen($s . '-' . $x) > 55) {
                break;
            }
            $s .= ('' === $s ? '' : '-') . $x;
        }
        if (strlen($s) >= 3) {
            return $s;
        }
    }
    return sb_slug_guess($title, $kw);
}

class SB_Desk_Ajax_Done extends Exception {
}

/** Runs one of the plugin's own admin-ajax handlers from the webhook (as the desk user) and returns its JSON. */
function sb_desk_ajax($action, $params) {
    wp_set_current_user(sb_desk_uid());
    $keep = array($_POST, $_REQUEST);
    $p = array_map('wp_slash', array_map('strval', $params));
    $p['nonce'] = wp_create_nonce('sb_admin');
    $_POST = $p;
    $_REQUEST = $p;
    $f1 = function () { return true; };
    $f2 = function () { return function () { throw new SB_Desk_Ajax_Done(); }; };
    add_filter('wp_doing_ajax', $f1);
    add_filter('wp_die_ajax_handler', $f2);
    ob_start();
    try {
        do_action('wp_ajax_' . $action);
    } catch (SB_Desk_Ajax_Done $e) {
        // the handler answered
    }
    $out = (string) ob_get_clean();
    remove_filter('wp_doing_ajax', $f1);
    remove_filter('wp_die_ajax_handler', $f2);
    $_POST = $keep[0];
    $_REQUEST = $keep[1];
    $a = strpos($out, '{"success"');
    $j = false !== $a ? json_decode(substr($out, $a), true) : null;
    return is_array($j) ? $j : array('success' => false, 'data' => array('message' => 'No reply received'));
}


/** The dress question: one button per kind of dress, each with the number of sample photos in the Uniform Library. Returns array(text, rows). */
function sb_desk_dress_kb($pre) {
    $n = array();
    foreach (sb_uniforms(true) as $r) {
        $n[$r['cat']] = ($n[$r['cat']] ?? 0) + 1;
    }
    $kinds = array(
        array('cat:general', '👦 Bangla medium', 'white shirt, green shoulder strap, tie (boys) / green-white (girls)'),
        array('cat:english', '🧒 English version', 'blue pinafore and white blouse (girls) / white shirt, blue tie and trousers (boys)'),
        array('cat:madrasha', '🕌 Islamic school', 'white panjabi and cap (boys) / white dress and hijab (girls)'),
        array('cat:cadet', '🎖️ Cadet college', 'cadet college uniform'),
        array('cat:teacher', '👩‍🏫 Teacher', 'teacher\'s clothes'),
        array('none', '⛔ No dress', 'ordinary clothes, no dress sample needed'),
        array('auto', '🎲 Auto', 'the AI chooses from the text'),
    );
    $txt  = "👕 <b>Which dress should the picture have?</b> Press one:\n\n";
    $rows = array();
    $row  = array();
    foreach ($kinds as $k) {
        $c = 0 === strpos($k[0], 'cat:') ? (int) ($n[substr($k[0], 4)] ?? 0) : -1;
        $txt .= $k[1] . ' — ' . $k[2] . ($c >= 0 ? ($c ? " ({$c} sample(s))" : ' ⚠️ no sample photo') : '') . "\n";
        $row[] = sb_desk_btn($k[1], $pre . $k[0]);
        if (2 === count($row)) {
            $rows[] = $row;
            $row = array();
        }
    }
    if ($row) {
        $rows[] = $row;
    }
    return array($txt, $rows);
}

/** Does this dress choice need the boy / girl / mix question? (only the kinds with sample photos of people) */
function sb_desk_needs_who($uni) {
    $b = preg_replace('/:[bgm]$/', '', (string) $uni);
    return 0 === strpos($b, 'cat:') && 'cat:teacher' !== $b;
}

function sb_desk_who_kb($pre) {
    return array(
        array(sb_desk_btn('👦 Boys', $pre . 'b'), sb_desk_btn('👧 Girls', $pre . 'g')),
        array(sb_desk_btn('👫 Boys and girls (mix)', $pre . 'm')),
    );
}

/** Adds a photo (already in the Media Library) to the Uniform Library from the caption "ক্যাডেট কলেজ মেয়ে ..." */
function sb_desk_dress_add($att, $cap) {
    $cap = trim((string) $cap);
    $cat = 'general';
    $cn  = 'বাংলা মাধ্যম';
    foreach (array('cadet' => array('ক্যাডেট', 'cadet', 'ক্যাডেট কলেজ'), 'english' => array('ইংলিশ', 'ইংরেজি', 'english'), 'madrasha' => array('ইসলামিক', 'মাদ্রাসা', 'মাদরাসা', 'madrasa'), 'teacher' => array('শিক্ষক', 'শিক্ষিকা', 'teacher')) as $k => $ws) {
        foreach ($ws as $w) {
            if (false !== mb_stripos($cap, $w)) {
                $cat = $k;
                break 2;
            }
        }
    }
    $names = array('general' => 'বাংলা মাধ্যম', 'english' => 'ইংলিশ ভার্সন', 'madrasha' => 'ইসলামিক স্কুল', 'cadet' => 'ক্যাডেট কলেজ', 'teacher' => 'শিক্ষক');
    $cn    = $names[$cat];
    $boy   = (bool) preg_match('/ছেলে|boy/iu', $cap);
    $girl  = (bool) preg_match('/মেয়ে|girl/iu', $cap);
    $who   = ($boy && !$girl) ? ' — ছেলে' : (($girl && !$boy) ? ' — মেয়ে' : '');
    $rest  = trim(preg_replace('/ক্যাডেট\s*কলেজ|ক্যাডেট|ইংলিশ\s*ভার্সন|ইংলিশ|ইংরেজি|ইসলামিক\s*স্কুল|ইসলামিক|মাদ্রাসা|মাদরাসা|শিক্ষক|শিক্ষিকা|ছেলে|মেয়ে|cadet|english|madrasa|teacher|boy|girl/iu', '', $cap), " \t-—:,।");
    $name  = $cn . $who . ('' !== $rest ? ' (' . mb_substr($rest, 0, 60) . ')' : '');
    $desc  = 'Dress exactly as worn in the reference photo (' . $cn . ('' !== $who ? ($boy ? ', boy' : ', girl') : '') . '). Copy garments, colours, badges, epaulettes and ties; do not copy the face, background or number of people.' . ('' !== $rest ? ' Note from the school: ' . $rest . '.' : '');
    $u = get_option('sb_uniforms', array());
    $u = is_array($u) ? $u : array();
    $u[] = array('id' => $att, 'att' => $att, 'cat' => $cat, 'name' => $name, 'desc' => $desc, 'active' => 1, 'default' => 0);
    update_option('sb_uniforms', $u, false);
    $n = 0;
    foreach ($u as $r) {
        if ($r['cat'] === $cat && !empty($r['active'])) {
            $n++;
        }
    }
    return "✅ <b>Dress sample added</b>\nKind: " . esc_html($cn) . ($who ? "\n" . esc_html(trim($who, ' —')) : "\n(you did not write boy/girl, so it works for both)") . "\nName: " . esc_html($name) . "\nThe " . esc_html($cn) . ' kind now has ' . $n . ' sample(s) in total.' . "\nTo change the name or description: WordPress → Uniform Library.";
}

/* ------------------------------------------------------------ held-back news (blocked / skipped by the news robot) */
/** Items the robot did not file, newest first. Blocked ones (negative / doubtful) and "not news" ones; other-institution matches are left out. */
function sb_desk_held_items() {
    $out = array();
    foreach ((array) sb_collect_items() as $x) {
        $st = (string) ($x['st'] ?? '');
        if ('blocked' === $st || ('skipped' === $st && false === mb_strpos((string) ($x['note'] ?? ''), 'অন্য প্রতিষ্ঠান'))) {
            $out[] = $x;
        }
        if (count($out) >= 60) {
            break;
        }
    }
    return $out;
}

function sb_desk_held_show($m, $page) {
    $all = sb_desk_held_items();
    if (!$all) {
        sb_desk_clear($m);
        sb_desk_say($m, '🛑 There is no held-back or skipped news in the list right now.');
        return;
    }
    $per   = 10;
    $pages = (int) ceil(count($all) / $per);
    $page  = max(0, min((int) $page, $pages - 1));
    $t = "🛑 <b>Held-back / skipped news</b> (" . count($all) . " in total, page " . ($page + 1) . '/' . $pages . ")\n\n";
    foreach (array_slice($all, $page * $per, $per, true) as $i => $x) {
        $t .= ($i + 1) . '. ' . ('blocked' === $x['st'] ? '🛑' : '⏭️') . ' <a href="' . esc_url($x['url']) . '">' . esc_html(mb_substr((string) ($x['title'] ?: $x['url']), 0, 80)) . '</a>'
            . "\n    " . esc_html(mb_substr((string) ($x['host'] ?? ''), 0, 30)) . ' — ' . esc_html(mb_substr((string) ($x['note'] ?? ''), 0, 90)) . "\n";
    }
    $t .= "\n✅ To keep: <code>keep 1 3</code>\n❌ To drop: <code>drop 2 4</code>\nYou can also write several lines at once. Next page: <code>more</code>. Finish: <code>done</code>\n(🛑 = held back as negative/doubtful, ⏭️ = not news or off-topic)";
    sb_desk_save($m, array('flow' => 'held', 'i' => 0, 'd' => array('ids' => array_map(function ($x) { return (string) $x['id']; }, $all), 'page' => $page)));
    sb_desk_say($m, $t, array(array(sb_desk_btn('➡️ More', 'held:more'), sb_desk_btn('✖️ Done', 'held:end'))));
}

function sb_desk_held_step($m, $s, $in) {
    $cb = (string) ($in['cb'] ?? '');
    $tx = trim((string) ($in['t'] ?? ''));
    $d  = $s['d'];
    $ids = (array) ($d['ids'] ?? array());
    if ('held:end' === $cb || preg_match('/^\s*(শেষ|done|exit|cancel|বাতিল সব শেষ)\s*$/iu', $tx)) {
        sb_desk_clear($m);
        sb_desk_say($m, 'OK ✓ (start again with /menu)');
        return;
    }
    if ('held:more' === $cb || preg_match('/^\s*(আরও|আরো|more|next)\s*$/iu', $tx)) {
        $per = 10;
        $np  = ((int) $d['page'] + 1) * $per < count($ids) ? (int) $d['page'] + 1 : 0;
        sb_desk_held_show($m, $np);
        return;
    }
    $tx = strtr($tx, array('০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'));
    $do = array();   // number => keep|drop
    foreach (preg_split('/\n+/u', $tx) as $line) {   // several actions may share a line: "keep 10 drop 1 2 3"
        $cur = '';
        foreach (preg_split('/[\s,;:]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (preg_match('/^(রাখ|keep|okay|ok$|yes|হ্যাঁ|✅)/iu', $w)) {
                $cur = 'keep';
            } elseif (preg_match('/^(বাতিল|বাদ|drop|skip|no$|না$|❌)/iu', $w)) {
                $cur = 'drop';
            } elseif ('' !== $cur && preg_match('/^(সব|all)$/iu', $w)) {
                $from = (int) $d['page'] * 10;
                foreach (range($from + 1, min(count($ids), $from + 10)) as $n) {
                    $do[$n] = $cur;
                }
            } elseif ('' !== $cur && preg_match('/^(\d+)(?:[-–](\d+))?$/', $w, $nm)) {
                $lo = (int) $nm[1];
                $hi = isset($nm[2]) && '' !== $nm[2] ? (int) $nm[2] : $lo;
                for ($n = $lo; $n <= min($hi, $lo + 60); $n++) {
                    if ($n >= 1 && $n <= count($ids)) {
                        $do[$n] = $cur;
                    }
                }
            }
        }
    }
    if (!$do) {
        sb_desk_say($m, "I did not understand 🙂 Write it like this: <code>keep 1 3</code> or <code>drop 2</code>. Next page: <code>more</code>, finish: <code>done</code>");
        return;
    }
    @set_time_limit(300);
    $report = '';
    $items = array();
    foreach ((array) sb_collect_items() as $x) {
        $items[(string) $x['id']] = $x;
    }
    foreach ($do as $n => $act) {
        $it = $items[(string) ($ids[$n - 1] ?? '')] ?? null;
        if (!$it) {
            $report .= $n . '. ⚠️ This news is no longer in the list' . "\n";
            continue;
        }
        $ttl = esc_html(mb_substr((string) ($it['title'] ?: $it['url']), 0, 60));
        if ('drop' === $act) {
            sb_ca_mark($it['id'], 'dismissed', 'You dropped it');
            $report .= $n . '. ❌ Dropped — ' . $ttl . "\n";
            continue;
        }
        $fresh = sb_ca_read($it['url']);
        $item  = $fresh ? $fresh : array('url' => $it['url'], 'host' => (string) ($it['host'] ?? ''), 'title' => (string) $it['title'], 'text' => (string) ($it['text'] ?? ''), 'images' => (array) ($it['images'] ?? array()), 'videos' => array());
        $item['id']       = (string) $it['id'];
        $item['override'] = 1;
        $item['force']    = 1;
        list($st, $note) = sb_ca_process($item, true);
        if ('filed' === $st) {
            sb_ca_mark($it['id'], 'filed', 'You kept it · ' . $note);
            $report .= $n . '. ✅ Kept — ' . $ttl . "\n";
        } elseif ('dup' === $st) {
            sb_ca_mark($it['id'], 'dismissed', 'Already exists');
            $report .= $n . '. ♻️ This link is already on the blog — ' . $ttl . "\n";
        } else {
            $report .= $n . '. ⚠️ Failed (' . esc_html(mb_substr($note, 0, 80)) . ') — ' . $ttl . "\n";
        }
    }
    sb_desk_say($m, $report);
    sb_desk_held_show($m, 0);
}

/** One reply for a whole album (Telegram sends each photo of an album as its own message). */
function sb_desk_album_say($m, $html, $kb = array()) {
    $g = isset($m['media_group_id']) ? (string) $m['media_group_id'] : '';
    if ('' !== $g) {
        $k = 'sb_desk_mg_' . md5($g);
        if (get_transient($k)) {
            return;
        }
        set_transient($k, 1, 30);
    }
    sb_desk_say($m, $html, $kb);
}

/** Readable name of a dress choice (button id) for notices. */
function sb_desk_dress_label($uni) {
    if (preg_match('/^(.*):([bgm])$/', (string) $uni, $gm)) {
        return sb_desk_dress_label($gm[1]) . ' — ' . array('b' => 'boys', 'g' => 'girls', 'm' => 'boys and girls')[$gm[2]];
    }
    foreach (sb_img_opts()['uniforms'] as $u) {
        if ((string) $u[0] === (string) $uni) {
            return preg_replace('/^ধরন:\s*/u', '', $u[1]);
        }
    }
    return 'auto' === $uni ? 'automatic (from the text)' : (string) $uni;
}

/** Notice to the Telegram channel (skipped in a dry run). */
function sb_desk_tgnote($html) {
    if (!empty($GLOBALS['sb_desk_sim']) || !function_exists('sb_tg_send')) {
        return;
    }
    sb_tg_send($html);
}

/** AI picture for a blog post: prompt from the article (+ the chosen dress), image, alt / title / caption, set as the main picture. Returns attachment id or WP_Error. */
function sb_desk_autoimage($post_id, $title, $slug, $o, $html, $uniform = '') {
    @set_time_limit(600);
    $uni = '' !== $uniform ? $uniform : (($o['uniform'] ?? '') !== '' ? (string) $o['uniform'] : 'auto');
    sb_desk_tgnote("🎨 <b>AI picture started</b>\nPost: " . esc_html(mb_substr((string) $title, 0, 90)) . "\nDress: " . esc_html(sb_desk_dress_label($uni)) . "\n⏳ Takes 1–2 minutes; I will tell you when it is done. Cost about 7 cents.");
    $p = sb_desk_ajax('sb_img_prompt', array('title' => $title, 'content' => wp_strip_all_tags((string) $html), 'category' => '', 'subject' => 'auto',
        'uniform' => $uni, 'logo' => (string) ($o['logo'] ?? 'none'), 'logo_at' => 'chest', 'size' => '1536x1024', 'post_id' => (int) $post_id));
    if (empty($p['success'])) {
        $msg = (string) ($p['data']['message'] ?? 'The picture prompt failed');
        sb_desk_tgnote("⚠️ <b>AI picture failed</b> (prompt step)\n" . esc_html($msg));
        return new WP_Error('sb_img', $msg);
    }
    $d = $p['data'];
    $m = sb_desk_ajax('sb_img_make', array('job' => 'd' . (int) $post_id . wp_generate_password(6, false, false), 'prompt' => $d['prompt'], 'size' => '1536x1024', 'quality' => 'medium',
        'title' => $title, 'content' => wp_strip_all_tags((string) $html), 'category' => '', 'subject' => 'auto', 'uniform' => $uni, 'logo' => (string) ($o['logo'] ?? 'none'), 'logo_at' => 'chest',
        'alt' => (string) $d['alt'], 'imgtitle' => (string) $d['title'], 'caption' => (string) $d['caption'], 'details' => (string) $d['details'], 'file_slug' => (string) $slug, 'post_id' => (int) $post_id));
    if (empty($m['success']) || empty($m['data']['id'])) {
        $msg = (string) ($m['data']['message'] ?? 'The picture was not created');
        sb_desk_tgnote("⚠️ <b>AI picture failed</b>\n" . esc_html($msg));
        return new WP_Error('sb_img', $msg);
    }
    set_post_thumbnail((int) $post_id, (int) $m['data']['id']);
    $note = !empty($p['data']['note']) ? "\n⚠️ " . esc_html((string) $p['data']['note']) : '';
    sb_desk_tgnote("✅ <b>AI picture created</b>\nPost: " . esc_html(mb_substr((string) $title, 0, 90)) . "\nDress: " . esc_html(sb_desk_dress_label($uni)) . ((int) ($p['data']['uniforms'] ?? 0) ? '' : "\n(no dress sample photo was used)") . "\nAlt: " . esc_html(mb_substr((string) $d['alt'], 0, 140)) . $note . "\n" . esc_url(get_edit_post_link((int) $post_id, 'raw')));
    return (int) $m['data']['id'];
}

/** One edit of a blog post / press card: only the keys given are changed. */
function sb_desk_patch_post($pid, $f) {
    $pid = (int) $pid;
    if (!$pid || 'sb_post' !== get_post_type($pid)) {
        return new WP_Error('sb_ed', 'Blog post not found.');
    }
    $g = function ($k) use ($f) {
        return isset($f[$k]) ? (string) $f[$k] : '';
    };
    $a = array('ID' => $pid);
    if ('' !== $g('title')) {
        $a['post_title'] = sanitize_text_field($g('title'));
    }
    if ('' !== $g('content')) {
        $a['post_content'] = wp_kses_post($g('content'));
    }
    if (in_array($g('status'), array('draft', 'publish'), true)) {
        $a['post_status'] = $g('status');
    }
    $r = wp_update_post(wp_slash($a), true);
    if (is_wp_error($r)) {
        return $r;
    }
    if ((int) $g('thumb')) {
        set_post_thumbnail($pid, (int) $g('thumb'));
    }
    if ('' !== $g('promo_ids')) {
        $ids = 'none' === $g('promo_ids') ? array() : array_values(array_filter(array_map('sanitize_key', explode(',', $g('promo_ids')))));
        update_post_meta($pid, '_sb_promo_ids', $ids);
    }
    if ('' !== $g('no_social')) {
        if ('1' === $g('no_social')) {
            update_post_meta($pid, '_sb_no_social', 1);
        } else {
            delete_post_meta($pid, '_sb_no_social');
        }
    }
    if ('' !== $g('embed_url')) {   // one video / post embed with a caption, at a chosen place
        $e = sb_get_extras($pid);
        $e['embeds'] = array(array('url' => $g('embed_url'), 'label' => $g('embed_label'), 'pos' => '' !== $g('embed_pos') ? $g('embed_pos') : 'p:1', 'r' => $g('embed_r')));
        update_post_meta($pid, '_sb_extras', sb_clean_extras($e));
    }
    if ('' !== $g('summary')) {
        update_post_meta($pid, '_sb_summary', sanitize_textarea_field($g('summary')));
    }
    if (preg_match('/^(top|middle|end)$/', $g('summary_pos'))) {
        update_post_meta($pid, '_sb_summary_pos', $g('summary_pos'));
    }
    if ('' !== $g('meta')) {
        update_post_meta($pid, '_sb_meta_desc', sanitize_textarea_field($g('meta')));
        wp_update_post(array('ID' => $pid, 'post_excerpt' => sanitize_textarea_field($g('meta'))));
    }
    if ('' !== $g('seo_title')) {
        update_post_meta($pid, '_sb_seo_title', sanitize_text_field($g('seo_title')));
    }
    if ('' !== $g('keywords')) {
        update_post_meta($pid, '_sb_keywords', array_slice(array_values(array_filter(array_map('sanitize_text_field', preg_split('/[,\n،]+/u', $g('keywords'))))), 0, 15));
    }
    if ('' !== $g('en_title')) {
        update_post_meta($pid, '_sb_en_title', mb_substr(sanitize_text_field($g('en_title')), 0, 120));
    }
    if ('' !== $g('slug')) {
        $sl = wp_unique_post_slug(sanitize_title($g('slug')), $pid, get_post_status($pid), 'sb_post', 0);
        wp_update_post(array('ID' => $pid, 'post_name' => $sl));
    }
    if ('' !== $g('img_alt')) {
        $tid = (int) get_post_thumbnail_id($pid);
        if ($tid) {
            $alt = $g('img_alt');
            if ('auto' === $alt) {
                $r = sb_desk_ajax('sb_img_describe', array('id' => $tid, 'title' => get_the_title($pid), 'content' => wp_strip_all_tags((string) get_post_field('post_content', $pid))));
                $alt = !empty($r['success']) ? sanitize_text_field($r['data']['alt'] ?? '') : '';
            }
            if ('' !== $alt) {
                update_post_meta($tid, '_wp_attachment_image_alt', $alt);
            }
        }
    }
    if ('' !== $g('aiimg')) {
        $u = 'auto' === strtolower($g('aiimg')) ? 'auto' : $g('aiimg');
        $ai = sb_desk_autoimage($pid, get_the_title($pid), get_post_field('post_name', $pid), array(), (string) get_post_field('post_content', $pid), $u);
        if (is_wp_error($ai)) {
            return $ai;
        }
    }
    if ('' !== $g('press_url')) {
        update_post_meta($pid, '_sb_press_url', esc_url_raw($g('press_url')));
    }
    if ('' !== $g('press_paper')) {
        update_post_meta($pid, '_sb_press_paper', sanitize_text_field($g('press_paper')));
    }
    sb_seo_sync($pid);
    clean_post_cache($pid);
    return true;
}

/** Blogs (kind blog) or press/news cards (kind news) the owner may edit from Telegram: latest 5. */
function sb_desk_post_list($kind) {
    return array_map('intval', get_posts(array('post_type' => 'sb_post', 'post_status' => array('publish', 'draft'), 'numberposts' => 5, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids',
        'meta_query' => array(array('key' => '_sb_press_url', 'compare' => 'news' === $kind ? 'EXISTS' : 'NOT EXISTS')))));
}

function sb_desk_kind_list($kind) {
    return 'notice' === $kind || '' === $kind ? sb_desk_edit_list() : sb_desk_post_list($kind);
}

/** Proposal for a blog / news edit: array(new => array(fields), desc, thumb) or WP_Error. */
function sb_desk_post_propose($id, $kind, $act, $val) {
    $t = trim((string) ($val['text'] ?? ''));
    $f = array();
    $thumb = 0;
    switch ($act) {
        case 'title':
            $f['title'] = $t;
            $desc = '🏷️ New title: <b>' . esc_html($t) . '</b>';
            break;
        case 'summary':
            $f['summary'] = $t;
            $f['meta'] = mb_substr($t, 0, 160);
            $desc = '📝 New summary:' . "\n" . esc_html($t);
            break;
        case 'meta':
            $f['meta'] = mb_substr($t, 0, 160);
            $desc = '🔎 New Google description (max 160 characters):' . "\n" . esc_html($f['meta']);
            break;
        case 'kw':
            $f['keywords'] = $t;
            $desc = '🔑 Keywords: ' . esc_html($t);
            break;
        case 'embed':
            $ls = array_values(array_filter(array_map('trim', preg_split('/\R/u', $t))));
            $url = $ls ? $ls[0] : '';
            if (!preg_match('#^https?://\S+$#i', $url)) {
                return new WP_Error('sb_ed', 'Put the full video/post link on the first line and the caption on the second (optional).');
            }
            if (preg_match('#^https?://([a-z0-9.-]+\.)?(facebook\.com|fb\.watch|fb\.me)/#i', $url)) {
                $c = sb_desk_fb_canon($url);
                if (is_wp_error($c)) {
                    return $c;
                }
                $url = $c;
            }
            $f['embed_url'] = $url;
            $f['embed_label'] = implode(' ', array_slice($ls, 1));
            $f['embed_pos'] = 'p:1';
            $desc = "🎬 This video/post will go after the first paragraph:\n" . esc_url($url) . ($f['embed_label'] ? "\nCaption: " . esc_html($f['embed_label']) : '');
            break;
        case 'promo':
            $have = array();
            foreach (sb_promos() as $p) {
                $have[$p['id']] = $p['title'];
            }
            $want = 'none' === strtolower($t) || 'নেই' === $t ? array() : array_values(array_filter(array_map('sanitize_key', preg_split('/[\s,]+/u', $t))));
            foreach ($want as $w) {
                if (!isset($have[$w])) {
                    return new WP_Error('sb_ed', 'No promo card with this name: ' . esc_html($w));
                }
            }
            $f['promo_ids'] = $want ? implode(',', $want) : 'none';
            $desc = '📣 Promo card: ' . ($want ? esc_html(implode(', ', array_map(function ($w) use ($have) { return $have[$w]; }, $want))) : 'no card');
            break;
        case 'img':
            $a = (int) ($val['img'] ?? 0);
            if (!$a) {
                return new WP_Error('sb_ed', 'No picture received.');
            }
            $thumb = $a;
            $f['thumb'] = $a;
            $desc = '🖼️ The main picture will be replaced with the new one.';
            break;
        case 'entitle':
            if ('auto' === strtolower($t)) {
                $r = csa_jobs_ai_json('Return ONLY JSON {"en":"the title in natural English, 4 to 9 words, plain words"}.', get_the_title($id), 'Desk: en title', 200);
                $t = !is_wp_error($r) ? sanitize_text_field($r['en'] ?? '') : '';
                if ('' === $t) {
                    return new WP_Error('sb_ed', 'I could not make an English title; please write it yourself.');
                }
            }
            $f['en_title'] = $t;
            $desc = '🔤 English title: <b>' . esc_html($t) . '</b>';
            break;
        case 'slug':
            if ('auto' === strtolower($t)) {
                $t = sb_friendly_slug((string) get_post_meta($id, '_sb_en_title', true), (array) get_post_meta($id, '_sb_keywords', true), get_the_title($id));
                if ('' === $t) {
                    return new WP_Error('sb_ed', 'There is no English title, so I could not make a slug. Give an English title first or write the slug yourself.');
                }
            }
            $t = sanitize_title($t);
            if (strlen($t) < 3 || !preg_match('/^[a-z0-9-]+$/', $t)) {
                return new WP_Error('sb_ed', 'Write the slug with English letters, numbers and hyphens (e.g. cadet-college-tips).');
            }
            $f['slug'] = $t;
            $desc = '🔗 New link: ' . esc_html(home_url('/blog/' . $t . '/')) . "\n(The old link may stop working.)";
            break;
        case 'aiimg':
            $f['aiimg'] = '' !== $t ? $t : 'auto';
            $desc = '🎨 A new AI picture will be made from the text and set as the main picture. Dress: ' . esc_html($f['aiimg']);
            break;
        case 'alt':
            if ('auto' === strtolower($t)) {
                $f['img_alt'] = 'auto';
                $desc = '🏷️ The AI will look at the main picture and write the alt text.';
            } else {
                $f['img_alt'] = mb_substr($t, 0, 200);
                $desc = '🏷️ Alt text: ' . esc_html($f['img_alt']);
            }
            break;
        case 'nlink':
            if (!preg_match('#^https?://\S+$#i', $t)) {
                return new WP_Error('sb_ed', 'Send the full news link (https://…).');
            }
            $f['press_url'] = $t;
            $desc = '🔗 News link: ' . esc_url($t);
            break;
        case 'paper':
            $f['press_paper'] = $t;
            $desc = '📰 Newspaper name: <b>' . esc_html($t) . '</b>';
            break;
        case 'pub':
            $f['status'] = 'publish';
            $desc = '🌐 The post will be <b>published</b> (everyone can see it).';
            break;
        case 'draft':
            $f['status'] = 'draft';
            $desc = '📥 The post will become a <b>draft</b> (no longer visible on the site).';
            break;
        case 'free':
            $post = get_post($id);
            $sys = 'You edit a Bangla blog article stored as HTML. Apply ONLY the requested change; keep everything else exactly as it is; do not invent facts, dates, fees or links. Return ONLY JSON: {"html":"<the full updated HTML>","changes":["each change as one short Bangla line"]}.';
            $r = csa_jobs_ai_json($sys, "Article HTML:\n" . $post->post_content . "\n\nRequested change:\n" . $t, 'Desk: free blog edit', 7000);
            if (is_wp_error($r) || empty($r['html']) || !is_string($r['html'])) {
                return new WP_Error('sb_ed', 'The AI could not understand the change. Try wording it differently.');
            }
            $chg = array_values(array_filter((array) ($r['changes'] ?? array())));
            if (!$chg) {
                return new WP_Error('sb_ed', 'No change could be made. Please be more specific.');
            }
            $f['content'] = $r['html'];
            $desc = "✍️ Changes:\n• " . implode("\n• ", array_map('esc_html', array_map('strval', array_slice($chg, 0, 8))));
            break;
        default:
            return new WP_Error('sb_ed', 'Unknown action.');
    }
    return array('new' => array('fields' => $f), 'desc' => $desc . "\n\n(" . ('news' === $kind ? 'news' : 'blog') . ': ' . esc_html(get_the_title($id)) . ')', 'thumb' => $thumb);
}

function sb_desk_post_apply($id, $kind, $st) {
    if (!in_array($id, sb_desk_post_list($kind), true) || empty($st['new']['fields'])) {
        return new WP_Error('sb_ed', 'The post is no longer in the list; start again from /menu.');
    }
    $r = sb_desk_patch_post($id, $st['new']['fields']);
    if (is_wp_error($r)) {
        return $r;
    }
    $url = 'publish' === get_post_status($id) ? get_permalink($id) : home_url('/?post_type=sb_post&p=' . $id . '&preview=true');
    return "✅ Changed:\n<b>" . esc_html(get_the_title($id)) . "</b>\n" . esc_url($url) . ('publish' === get_post_status($id) ? '' : "\n(Draft: you can preview it at this link while logged in as admin)") . "\n\n/menu — another task";
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
        return new WP_Error('sb_fb', 'This does not look like a Facebook link.');
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
    return new WP_Error('sb_fb', 'I could not get the real post address from this link (Facebook may be blocking the server). Use the "…" menu of the Facebook post → "Embed"/copy link and send the direct post link (like …/posts/… or permalink.php?story_fbid=…).');
}

function sb_desk_fb_html($canon) {
    return '<div class="sn-fb" style="max-width:520px;margin:12px auto;text-align:center"><iframe src="https://www.facebook.com/plugins/post.php?href=' . rawurlencode($canon) . '&width=500&show_text=true" width="500" height="720" style="border:none;overflow:hidden;max-width:100%" scrolling="no" frameborder="0" allowfullscreen="true" loading="lazy" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"></iframe></div>';
}

/** Builds the proposed new notice data. Returns array(new, desc, thumb) or WP_Error. */
function sb_desk_edit_propose($id, $act, $val) {
    $d = csa_sn_get($id);
    if (!$d) {
        return new WP_Error('sb_ed', 'Notice data not found.');
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
                $desc = "📘 I could not get the real post address from this link, so the notice will get a <b>\"View Facebook post\" button</b>:\n" . esc_url($raw) . "\n\n💡 To show the post inside the notice itself, later open Facebook on a desktop, click the post time text, copy the address and add the Facebook post again.";
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
            $desc = "📘 This Facebook post will be embedded below the notice:\n" . esc_url($c);
            break;
        case 'deadline':
            $cur = (string) ($d['expiry'] ?? '');
            $r   = csa_jobs_ai_json('Convert the Bangla/English date text into JSON {"date":"YYYY-MM-DD"} (the calendar date only; use the year in the text; if unsure return empty). Today is ' . wp_date('Y-m-d') . '.', $t, 'Desk: date', 120);
            $dt  = !is_wp_error($r) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['date'] ?? '')) ? $r['date'] : '';
            if ('' === $dt) {
                return new WP_Error('sb_ed', 'I could not understand the date. Write it again (e.g. 30 December 2026).');
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
            $desc = '📅 Application deadline: ' . esc_html($cur ?: 'date on the old card') . ' → <b>' . esc_html($t) . '</b> (' . esc_html($dt) . ")\nThe notice will show as \"ongoing\" again.";
            break;
        case 'phones':
            $nums = array_values(array_filter(array_map('trim', preg_split('/[\n,;]+/u', $t))));
            if (!$nums) {
                return new WP_Error('sb_ed', 'No number found.');
            }
            $i = $find($new['blocks'], 'phones');
            if ($i >= 0) {
                $new['blocks'][$i]['nums'] = implode("\n", $nums);
            } else {
                $new['blocks'][] = array('t' => 'phones', 'h' => 'যোগাযোগ', 'nums' => implode("\n", $nums));
            }
            $desc = '📞 Phone numbers will be: ' . esc_html(implode(', ', $nums));
            break;
        case 'apply':
            if (!preg_match('#^https?://\S+$#i', $t)) {
                return new WP_Error('sb_ed', 'Send the full link (https://…).');
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
            $desc = '🔗 New ' . ($isf ? 'Google Form (embedded in the page)' : 'apply button') . ":\n" . esc_url($t) . "\nThe old form/apply button will be removed.";
            break;
        case 'pdf':
            $u = !empty($val['file']) ? wp_get_attachment_url((int) $val['file']) : (preg_match('#^https?://\S+$#i', $t) ? esc_url_raw($t) : '');
            if (!$u) {
                return new WP_Error('sb_ed', 'No PDF or download link found.');
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
            $desc = "📎 Download button:\n" . esc_url($u);
            break;
        case 'img':
            $a = (int) $val['img'];
            if (!$a) {
                return new WP_Error('sb_ed', 'No picture received.');
            }
            $i = $find($new['blocks'], 'image');
            if ($i >= 0) {
                $new['blocks'][$i]['ids'] = array($a);
            } else {
                array_unshift($new['blocks'], array('t' => 'image', 'ids' => array($a), 'cap' => ''));
            }
            $thumb = $a;
            $desc  = '🖼️ The notice main picture will be replaced with the new one.';
            break;
        case 'free':
            $sys = 'You edit a school notice stored as JSON. Apply ONLY the requested change; keep everything else exactly as it is. Return ONLY JSON: {"notice":<the full updated notice object, same keys and block structure>,"changes":["each change as one short Bangla line: আগে → পরে"]}. Never invent dates, numbers, names or links; if the request cannot be done with the data given, return the notice unchanged and an empty changes list.';
            $r   = csa_jobs_ai_json($sys, "Notice JSON:\n" . wp_json_encode($new, JSON_UNESCAPED_UNICODE) . "\n\nRequested change:\n" . $t, 'Desk: free edit', 3500);
            if (is_wp_error($r) || empty($r['notice']) || !is_array($r['notice'])) {
                return new WP_Error('sb_ed', 'The AI could not understand the change. Try wording it differently.');
            }
            $chg = array_values(array_filter((array) ($r['changes'] ?? array())));
            if (!$chg) {
                return new WP_Error('sb_ed', 'No change could be made (more information may be needed). Please be more specific.');
            }
            $cl = csa_sn_clean(array_merge($d, $r['notice']));
            $new = array_merge($new, $cl);
            $desc = "✍️ Changes:\n• " . implode("\n• ", array_map('esc_html', array_map('strval', array_slice($chg, 0, 8))));
            break;
        default:
            return new WP_Error('sb_ed', 'Unknown action.');
    }
    $new['blocks'] = array_values($new['blocks']);
    return array('new' => $new, 'desc' => $desc . "\n\n(Notice: " . esc_html(get_the_title($id)) . ')', 'thumb' => $thumb);
}

function sb_desk_edit_apply($id, $st) {
    if (!in_array($id, sb_desk_edit_list(), true) || empty($st['new'])) {
        return new WP_Error('sb_ed', 'The notice is no longer in the list; start again from /menu.');
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
    return "✅ Changed:\n<b>" . esc_html(get_the_title($id)) . "</b>\n" . esc_url($url) . "\n\n/menu — another task";
}

/* ------------------------------------------------------------ notice */
function sb_desk_notice($text, $aids, $status = 'publish', $o = array()) {
    if (!function_exists('csa_sn_ai_fill')) {
        return new WP_Error('sb_desk', 'The notice system (Site Agent) is not active.');
    }
    $pdfurl = !empty($o['pdf_aid']) ? (string) wp_get_attachment_url((int) $o['pdf_aid']) : (!empty($o['pdf_url']) ? (string) $o['pdf_url'] : '');
    $bh0    = function_exists('csa_sn_branch_hit') ? csa_sn_branch_hit(array($o['branch'] ?? '')) : null;
    $bn0    = $bh0 ? trim(wp_strip_all_tags((string) $bh0['name'])) : '';
    $pr     = ('' !== $pdfurl && function_exists('csa_sn_pdf_read')) ? csa_sn_pdf_read($pdfurl, $bn0) : null;
    if ($pr && !$aids && mb_strlen(trim((string) $text)) < 15) {   // only a PDF was sent: what Gemini read from it is the text
        $facts = array();
        foreach ($pr['facts'] as $f) {
            $facts[] = $f['l'] . ': ' . $f['v'];
        }
        $text = trim((string) $text . "\n" . $pr['summary'] . "\n" . implode("\n", $facts));
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
    // The branch name and its phones come from the branch page (the directory), whatever spelling the message used.
    $hit = function_exists('csa_sn_branch_hit') ? csa_sn_branch_hit(array($r['branch'] ?? '', $o['branch'] ?? '')) : null;
    if ($hit) {
        $r['branch'] = trim(wp_strip_all_tags((string) $hit['name']));
    } elseif (!empty($o['branch'])) {
        $r['branch'] = $o['branch'];
    }
    $blocks = array();
    // A PDF (typed link, uploaded file or a link the AI found) is read once by Gemini: short description + the facts it states.
    if ('' === $pdfurl) {
        foreach ((array) ($r['links'] ?? array()) as $l) {
            if (!empty($l['url']) && 'pdf' === ($l['kind'] ?? '') && preg_match('#^https?://#i', $l['url'])) {
                $pdfurl = $l['url'];
                break;
            }
        }
    }
    if (!$pr && '' !== $pdfurl && function_exists('csa_sn_pdf_read')) {
        $pr = csa_sn_pdf_read($pdfurl, !empty($hit) ? trim(wp_strip_all_tags((string) $hit['name'])) : '');
    }
    if ($pr && '' !== $pr['summary']) {
        $r['summary'] = $pr['summary'];
        $blocks[] = array('t' => 'text', 'h' => '', 'body' => $pr['summary']);
    }
    if ($aids) {
        $blocks[] = array('t' => 'image', 'ids' => array_map('intval', $aids), 'cap' => '');
    }
    if ($pr && $pr['facts']) {
        $have = array();
        foreach ((array) ($r['cards'] ?? array()) as $c) {
            $have[] = (string) ($c['l'] ?? '');
        }
        foreach ($pr['facts'] as $f) {
            if (!in_array($f['l'], $have, true)) {
                $r['cards'][] = $f;
            }
        }
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
    if ($hit && function_exists('csa_sn_branch_phones') && csa_sn_branch_phones($hit)) {
        $ph = csa_sn_branch_phones($hit);   // the branch page's numbers win over numbers typed or read from the notice
    } elseif ($pr && !$ph) {
        $ph = $pr['phones'];
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
        $btn[] = array('label' => 'PDF ডাউনলোড করুন', 'url' => $pdf, 'kind' => 'pdf');
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
    if ($ph) {
        $blocks[] = array('t' => 'phones', 'h' => 'বিস্তারিত জানতে যোগাযোগ করুন:', 'nums' => implode("\n", $ph));   // contact box last
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
    $arr   = array('post_type' => 'post', 'post_status' => $status, 'post_title' => $title, 'post_excerpt' => $d['summary'], 'post_author' => sb_team_author_id());
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
    return array('id' => $id, 'url' => $url, 'msg' => ('publish' === $status ? '✅ Notice published' : '📝 Notice saved as a draft (not published)') . ":\n<b>" . esc_html($title) . "</b>\n" . esc_url($url) . ($d['expiry'] ? "\nDeadline: " . esc_html($d['expiry']) : '') . "\n\n💡 The AI can misread things, so please double-check dates and numbers.");
}

/* ------------------------------------------------------------ blog */
function sb_desk_blog($text, $aids, $status = 'publish', $o = array()) {
    if (!function_exists('csa_jobs_ai_json') || !class_exists('SB_AI')) {
        return new WP_Error('sb_desk', 'The blog or Site Agent is not active.');
    }
    $uid  = get_current_user_id();
    $cats = array();
    foreach ((array) get_terms(array('taxonomy' => 'sb_category', 'hide_empty' => false)) as $t) {
        if (!is_wp_error($t) && isset($t->name)) {
            $cats[(int) $t->term_id] = $t->name;
        }
    }
    $sys = 'You turn a short request into a blog brief for a school group website (Bangla). Return ONLY JSON: {"title":"Bangla blog title, max 70 characters (use the requester\'s title if one is given)","en_title":"the same title in natural English, 4 to 9 words, plain words (use the requester\'s English title if one is given)","topic":"1-2 sentences: what the article covers","keywords":["3 to 6 search keywords, first is the main focus keyword"],"length":"short|medium|long","instruction":"extra instructions from the requester or empty","category":"exactly one of: ' . implode(' | ', $cats) . ' (or empty)"}. Never invent facts.';
    $b   = csa_jobs_ai_json($sys, mb_substr((string) $text, 0, 700), 'Desk: blog brief', 1400);   // only the short topic goes through the brief; long rules go to the writer below
    if (is_wp_error($b)) {
        return $b;
    }
    $title = sanitize_text_field($b['title'] ?? '');
    if ('' === $title) {
        return new WP_Error('sb_desk', 'I could not make a title; please write a bit more clearly and send again.');
    }
    if (!empty($o['title'])) {
        $title = sanitize_text_field($o['title']);
    }
    $kw  = array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) ($b['keywords'] ?? array())))), 0, 6);
    if (!empty($o['keywords'])) {
        $kw = array_slice(array_values(array_filter(array_map('trim', preg_split('/[,،;]+/u', sanitize_text_field($o['keywords']))))), 0, 15);
    }
    $en = sanitize_text_field(!empty($o['en_title']) ? $o['en_title'] : ($b['en_title'] ?? ''));
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
        return new WP_Error('sb_desk', 'Balance is not enough (needed ' . sb_money($need) . ', available ' . sb_money($st['remaining']) . ').');
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
        return new WP_Error('sb_desk', 'The AI did not give the full text; try again.');
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

    $postarr = array('post_type' => 'sb_post', 'post_title' => $title, 'post_excerpt' => $meta, 'post_content' => $html, 'post_author' => sb_team_author_id(),
        'post_status' => ($missing || 'publish' !== $status) ? 'draft' : 'publish');
    $id = wp_insert_post(wp_slash($postarr), true);
    if (is_wp_error($id)) {
        return $id;
    }
    $g = !empty($o['slug']) ? sanitize_title($o['slug']) : sb_friendly_slug($en, $kw, $title);
    $g = $g !== '' ? wp_unique_post_slug($g, $id, 'draft', 'sb_post', 0) : '';
    wp_update_post(array('ID' => $id, 'post_name' => $g !== '' ? $g : 'post-' . $id));
    update_post_meta($id, '_sb_meta_desc', $meta);
    update_post_meta($id, '_sb_keywords', $kw);
    update_post_meta($id, '_sb_topic', $ctx['topic']);
    update_post_meta($id, '_sb_seo_title', mb_substr($title, 0, 70));
    if ('' !== $en) {
        update_post_meta($id, '_sb_en_title', mb_substr($en, 0, 120));
    }
    update_post_meta($id, '_sb_summary_pos', 'middle');
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
    $imgnote = '';
    if (!$aids && 'none' !== ($o['imgmode'] ?? 'auto')) {   // no picture sent: make one from the article, with the chosen dress
        $ai = sb_desk_autoimage($id, $title, get_post_field('post_name', $id), $o, $html);
        if (is_wp_error($ai)) {
            $imgnote = "\n\n🖼️ The AI picture could not be made: " . $ai->get_error_message() . "\nAdd a picture later via Edit blog → Change picture, or from the blog editor.";
        } elseif ($ai) {
            $imgnote = "\n\n🖼️ The picture was made by AI (with alt text, title and caption).";
        }
    }
    if ($aids) {
        set_post_thumbnail($id, (int) $aids[0]);
        foreach ($aids as $ax) {
            if ('' === trim((string) get_post_meta((int) $ax, '_wp_attachment_image_alt', true))) {
                update_post_meta((int) $ax, '_wp_attachment_image_alt', mb_substr($title, 0, 120));
            }
        }
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
    $msg  = ($live ? '✅ Blog published' : '📝 Blog saved as a draft') . ":\n<b>" . esc_html($title) . "</b>\n" . esc_url($url) . "\nKeywords: " . esc_html(implode(', ', $kw)) . "\nSEO description, summary, FAQ and related posts were added too.";
    if ($missing) {
        $msg .= "\n\n⚠️ The text has " . count($missing) . ' [তথ্য দরকার: …] marker(s), so I kept it as a draft instead of publishing. Fill in the information and publish.';
    }
    $msg .= $imgnote;
    if (!$aids && '' === $imgnote) {
        $msg .= "\n\n🖼️ Kept without a picture (you said no picture is needed).";
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
    if ('lastrun' === $op) {
        wp_send_json_success(array('run' => get_option('sb_desk_lastrun', array()), 'err' => get_option('sb_desk_err', '')));
    }
    if ('sim' === $op) {   // owner test: send a message into the desk exactly as if typed in the channel (answers arrive in Telegram)
        $txt = trim((string) sb_req('text'));
        $go  = '1' === (string) sb_req('go');
        $chat = (string) $n['tg_chat'];
        if ('' === $txt || '' === $chat) {
            wp_send_json_error(array('message' => 'text বা চ্যানেল নেই'));
        }
        $m = array('message_id' => time(), 'chat' => array('id' => $chat, 'type' => 'channel'), 'text' => $txt, 'date' => time());
        wp_set_current_user(sb_desk_uid());
        try {
            sb_desk_message($m);
            if ($go) {
                sb_desk_step($m, array('cb' => 'go:publish'));
            }
        } catch (Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
        wp_send_json_success(array('msg' => 'sent'));
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
    if ('fix_authors' === $op) {   // every blog post and notice shows the team name
        $uid = sb_team_author_id();
        $n = 0;
        $ids = get_posts(array('post_type' => array('sb_post', 'post'), 'post_status' => array('publish', 'draft', 'pending', 'future'), 'numberposts' => -1, 'fields' => 'ids', 'author__not_in' => array($uid),
            'meta_query' => array('relation' => 'OR', array('key' => '_sn_is', 'value' => '1'), array('key' => '_sb_keywords', 'compare' => 'EXISTS'), array('key' => '_sb_press_url', 'compare' => 'EXISTS'))));
        foreach ($ids as $pid) {
            if ('sb_post' === get_post_type($pid) || get_post_meta($pid, '_sn_is', true)) {
                wp_update_post(array('ID' => $pid, 'post_author' => $uid));
                $n++;
            }
        }
        wp_send_json_success(array('msg' => $n . ' পোস্টের লেখক বদলে SEF IT Team', 'uid' => $uid, 'name' => get_the_author_meta('display_name', $uid)));
    }
    if ('test_img' === $op) {   // make the AI picture for one existing blog post (owner test)
        $pid = (int) sb_req('id', 0);
        if (!$pid || 'sb_post' !== get_post_type($pid)) {
            wp_send_json_error(array('message' => 'ব্লগ পোস্ট পাইনি।'));
        }
        $r = sb_desk_autoimage($pid, get_the_title($pid), get_post_field('post_name', $pid), array(), (string) get_post_field('post_content', $pid), (string) sb_req('uniform', 'auto'));
        if (is_wp_error($r)) {
            wp_send_json_error(array('message' => $r->get_error_message()));
        }
        wp_send_json_success(array('id' => $r, 'alt' => get_post_meta($r, '_wp_attachment_image_alt', true), 'url' => wp_get_attachment_url($r)));
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
        $f = array();
        foreach (array('title', 'status', 'thumb', 'promo_ids', 'no_social', 'embed_url', 'embed_label', 'embed_pos', 'embed_r', 'summary', 'meta', 'seo_title') as $k) {
            $f[$k] = (string) sb_req($k);
        }
        $f['content'] = (string) wp_unslash($_POST['content'] ?? '');
        $r = sb_desk_patch_post($pid, $f);
        if (is_wp_error($r)) {
            wp_send_json_error(array('message' => $r->get_error_message()));
        }
        wp_send_json_success(array('msg' => 'ঠিক করা হয়েছে', 'status' => get_post_status($pid), 'slug' => get_post_field('post_name', $pid)));
    }
    if ('import_blog' === $op) {   // owner hands over a finished article: becomes a blog post (draft/publish) with all the SEO fields
        wp_set_current_user(sb_desk_uid());
        $title = sanitize_text_field((string) sb_req('title'));
        if ('' === $title) {
            wp_send_json_error(array('message' => 'শিরোনাম দরকার।'));
        }
        $pid = wp_insert_post(wp_slash(array('post_type' => 'sb_post', 'post_status' => 'draft', 'post_title' => $title, 'post_author' => sb_team_author_id())), true);
        if (is_wp_error($pid)) {
            wp_send_json_error(array('message' => $pid->get_error_message()));
        }
        $f = array('content' => (string) wp_unslash($_POST['content'] ?? ''));
        foreach (array('status', 'no_social', 'summary', 'meta', 'seo_title', 'keywords', 'en_title', 'slug') as $k) {
            $f[$k] = (string) sb_req($k);
        }
        $r = sb_desk_patch_post($pid, $f);
        if (is_wp_error($r)) {
            wp_send_json_error(array('message' => $r->get_error_message()));
        }
        wp_send_json_success(array('msg' => 'ব্লগ পোস্ট তৈরি', 'id' => $pid, 'status' => get_post_status($pid), 'url' => get_permalink($pid)));
    }
    if ('get_blog' === $op) {   // read one blog post as stored (owner use, to fix wording)
        $pid = (int) sb_req('id', 0);
        if (!$pid || 'sb_post' !== get_post_type($pid)) {
            wp_send_json_error(array('message' => 'ব্লগ পোস্ট পাইনি।'));
        }
        wp_send_json_success(array('title' => get_the_title($pid), 'content' => (string) get_post_field('post_content', $pid), 'status' => get_post_status($pid),
            'meta' => (string) get_post_meta($pid, '_sb_meta_desc', true), 'seo_title' => (string) get_post_meta($pid, '_sb_seo_title', true), 'en_title' => (string) get_post_meta($pid, '_sb_en_title', true), 'slug' => get_post_field('post_name', $pid)));
    }
    if ('adopt_post' === $op) {   // owner: turn an old regular post (same link) into the blog look, with new text + SEO
        $pid = (int) sb_req('id', 0);
        $t = get_post_type($pid);
        if (!$pid || !in_array($t, array('post', 'page'), true)) {
            wp_send_json_error(array('message' => 'পোস্ট পাইনি।'));
        }
        $a = array('ID' => $pid);
        $c = (string) wp_unslash($_POST['content'] ?? '');
        if ('' !== $c) {
            $a['post_content'] = wp_kses_post($c);
        }
        if ('' !== (string) sb_req('title')) {
            $a['post_title'] = sanitize_text_field((string) sb_req('title'));
        }
        if ('' !== (string) sb_req('excerpt')) {
            $a['post_excerpt'] = sanitize_textarea_field((string) sb_req('excerpt'));
        }
        $r = wp_update_post(wp_slash($a), true);
        if (is_wp_error($r)) {
            wp_send_json_error(array('message' => $r->get_error_message()));
        }
        update_post_meta($pid, '_sb_as_blog', '1');
        update_post_meta($pid, '_elementor_edit_mode', '');   // text now comes from the editor, not the old Elementor layout
        foreach (array('summary' => '_sb_summary', 'summary_pos' => '_sb_summary_pos', 'en_title' => '_sb_en_title', 'seo_title' => '_sb_seo_title', 'meta' => '_sb_meta_desc') as $k => $mk) {
            if ('' !== (string) sb_req($k)) {
                update_post_meta($pid, $mk, sanitize_textarea_field((string) sb_req($k)));
            }
        }
        if ('' !== (string) sb_req('meta')) {
            update_post_meta($pid, '_yoast_wpseo_metadesc', sanitize_textarea_field((string) sb_req('meta')));
        }
        if ('' !== (string) sb_req('seo_title')) {
            update_post_meta($pid, '_yoast_wpseo_title', sanitize_text_field((string) sb_req('seo_title')));
        }
        if ('' !== (string) sb_req('focus')) {
            update_post_meta($pid, '_yoast_wpseo_focuskw', sanitize_text_field((string) sb_req('focus')));
        }
        if ((int) sb_req('thumb')) {
            set_post_thumbnail($pid, (int) sb_req('thumb'));
        }
        delete_post_meta($pid, '_sb_no_social');
        wp_send_json_success(array('msg' => 'ব্লগ-ডিজাইনে আনা হয়েছে', 'url' => get_permalink($pid), 'slug' => get_post_field('post_name', $pid)));
    }
    if ('promo_get' === $op) {
        wp_send_json_success(array('promos' => sb_promos()));
    }
    if ('patch_more' === $op) {   // the same patcher, with the SEO / picture keys too
        $pid = (int) sb_req('id', 0);
        $f = array();
        foreach (array('title', 'status', 'thumb', 'summary', 'summary_pos', 'meta', 'seo_title', 'keywords', 'en_title', 'slug', 'img_alt', 'aiimg', 'no_social', 'promo_ids') as $k) {
            $f[$k] = (string) sb_req($k);
        }
        $r = sb_desk_patch_post($pid, $f);
        if (is_wp_error($r)) {
            wp_send_json_error(array('message' => $r->get_error_message()));
        }
        wp_send_json_success(array('msg' => 'ঠিক করা হয়েছে', 'status' => get_post_status($pid), 'thumb' => (int) get_post_thumbnail_id($pid), 'url' => get_permalink($pid)));
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
    wp_send_json_error(array('message' => 'Unknown action.'));
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
