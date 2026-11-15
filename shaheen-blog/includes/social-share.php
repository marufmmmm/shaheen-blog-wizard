<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Share a filed Facebook/YouTube card to Instagram (and YouTube, later) from the Telegram desk.
 *
 *  1. A Facebook/YouTube link is filed as a blog card (existing flow).
 *  2. If sharing is ON, AI drafts a YouTube title+description and an Instagram caption, and the desk asks:  ✅ অনুমোদন / ❌ বাদ.
 *  3. On approval the post is queued and goes out after a delay (default 45 minutes), Instagram and YouTube together.
 *  4. The result (or the exact error) is sent back to the same Telegram chat. Nothing is posted without the approval tap.
 *
 * Tokens live only in the option `sb_share` and are never printed back.
 * Instagram: system-user token + Instagram account id (Instagram API with Facebook Login for Business).
 * YouTube: not connected yet - the queue reports that clearly instead of failing silently.
 */
function sb_share_cfg() {
    $o = get_option('sb_share', array());
    return array_merge(array('on' => 0, 'delay' => 45, 'ig_id' => '', 'ig_token' => '', 'api_ver' => 'v23.0', 'yt_on' => 0), is_array($o) ? $o : array());
}

function sb_share_queue() {
    $q = get_option('sb_share_q', array());
    return is_array($q) ? $q : array();
}

function sb_share_queue_save($q) {
    if (count($q) > 60) {   // keep the newest 60
        $q = array_slice($q, -60, null, true);
    }
    update_option('sb_share_q', $q, false);
}

/** AI draft; falls back to a plain template when the AI call fails, so the owner always gets something to approve or reject. */
function sb_share_draft($title, $desc, $url) {
    $title = trim((string) $title);
    $desc  = trim(wp_strip_all_tags((string) $desc));
    $plain = array(
        'yt_title' => mb_substr($title, 0, 95),
        'yt_desc'  => trim($desc . "\n\n" . 'শাহীন শিক্ষা পরিবার — বিস্তারিত: https://shaheen.edu.bd'),
        'ig'       => mb_substr(trim(($desc !== '' ? mb_substr($desc, 0, 300) : $title) . "\n\n#শাহীনশিক্ষাপরিবার #ShaheenEducationFamily"), 0, 2000),
        'ai'       => 0,
    );
    $key = sb_get('api_key');
    if ('' === (string) $key) {
        return $plain;
    }
    $sys = 'তুমি শাহীন শিক্ষা পরিবারের (বাংলাদেশের স্কুল ও ক্যাডেট কোচিং নেটওয়ার্ক) সোশ্যাল মিডিয়া সম্পাদক। শুধু দেওয়া তথ্যের ভিতরে থাকবে; কোনো তথ্য, সংখ্যা, নাম বা উদ্ধৃতি বানাবে না। বাংলায়, সহজ ও আন্তরিক ভাষায় লিখবে। '
         . 'শুধু এই JSON দেবে: {"yt_title":"সর্বোচ্চ ৯০ অক্ষরের শিরোনাম","yt_desc":"৩-৫ লাইনের বিবরণ, শেষে লিংকহীন ধন্যবাদ","ig":"ইনস্টাগ্রামের ক্যাপশন, ২-৪ লাইন, শেষে ৪-৬টি হ্যাশট্যাগ"}';
    $usr = "পোস্টের শিরোনাম: {$title}\nপোস্টের লেখা: " . mb_substr($desc, 0, 1500) . "\nউৎস লিংক: {$url}";
    $r   = wp_remote_post(SB_AI::ENDPOINT, array('timeout' => 40, 'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
        'body' => wp_json_encode(array('model' => sb_model_for('custom'), 'response_format' => array('type' => 'json_object'), 'messages' => array(array('role' => 'system', 'content' => $sys), array('role' => 'user', 'content' => $usr))))));
    if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r)) {
        return $plain;
    }
    $j = json_decode((string) wp_remote_retrieve_body($r), true);
    $d = json_decode((string) ($j['choices'][0]['message']['content'] ?? ''), true);
    if (!is_array($d) || empty($d['ig'])) {
        return $plain;
    }
    return array(
        'yt_title' => mb_substr(sanitize_text_field((string) ($d['yt_title'] ?? $title)), 0, 95),
        'yt_desc'  => mb_substr(sanitize_textarea_field((string) ($d['yt_desc'] ?? $plain['yt_desc'])), 0, 4500),
        'ig'       => mb_substr(sanitize_textarea_field((string) $d['ig']), 0, 2000),
        'ai'       => 1,
    );
}

/** Called from the desk after a Facebook/YouTube card was filed. Asks for approval; posts nothing. */
function sb_share_offer($m, $url, $title, $img = '') {
    $c = sb_share_cfg();
    if (empty($c['on'])) {
        return;
    }
    $dr = sb_share_draft($title, '', $url);
    $pid = 0;
    $q = get_posts(array('post_type' => 'sb_post', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_sb_press_url', 'meta_value' => $url));
    if ($q) {
        $pid = (int) $q[0];
        if ('' === $img) {
            $img = (string) get_the_post_thumbnail_url($pid, 'large');
        }
        $ex = trim((string) get_post_field('post_excerpt', $pid));
        if ('' !== $ex) {
            $dr = sb_share_draft($title, $ex, $url);
        }
    }
    $id = substr(md5($url . microtime(true)), 0, 8);
    $qq = sb_share_queue();
    $qq[$id] = array('url' => $url, 'title' => $title, 'img' => $img, 'chat' => (string) $m['chat']['id'], 'draft' => $dr, 'status' => 'draft', 't' => time(), 'pid' => $pid);
    sb_share_queue_save($qq);
    $mins = (int) $c['delay'];
    $msg  = "📣 <b>ইনস্টাগ্রাম ও ইউটিউবে দেবেন?</b>\n\n<b>ইউটিউব শিরোনাম:</b>\n" . esc_html($dr['yt_title']) . "\n\n<b>ইউটিউব বিবরণ:</b>\n" . esc_html(mb_substr($dr['yt_desc'], 0, 700))
          . "\n\n<b>ইনস্টাগ্রাম ক্যাপশন:</b>\n" . esc_html(mb_substr($dr['ig'], 0, 700))
          . "\n\n" . ($img ? '🖼️ ছবি পোস্ট হিসেবে যাবে (ভিডিও ফাইল এখনো পাওয়া যায় না)।' : '⚠️ ছবি নেই — ইনস্টাগ্রামে যাবে না।')
          . "\n⏱️ অনুমোদন দিলে <b>" . $mins . " মিনিট পর</b> একসাথে যাবে।" . ($dr['ai'] ? '' : "\n(AI বন্ধ ছিল, সরল খসড়া)");
    sb_desk_say($m, $msg, array(array(sb_desk_btn('✅ অনুমোদন', 'sh:ok:' . $id), sb_desk_btn('❌ বাদ', 'sh:no:' . $id))));
}

/** Button taps: sh:ok:<id> / sh:no:<id> / sh:now:<id> */
function sb_share_cb($m, $cb) {
    $p = explode(':', (string) $cb);
    $act = $p[1] ?? '';
    $id  = $p[2] ?? '';
    $qq  = sb_share_queue();
    if (!isset($qq[$id])) {
        sb_desk_say($m, 'এই খসড়াটি আর পাওয়া যায়নি।');
        return;
    }
    $e = $qq[$id];
    if ('draft' !== $e['status'] && 'now' !== $act) {
        sb_desk_say($m, 'এটার সিদ্ধান্ত আগেই হয়ে গেছে (' . esc_html($e['status']) . ')।');
        return;
    }
    if ('no' === $act) {
        $qq[$id]['status'] = 'rejected';
        sb_share_queue_save($qq);
        sb_desk_say($m, '❌ বাদ দিলাম। কিছু পোস্ট হয়নি।');
        return;
    }
    if ('ok' === $act) {
        $c  = sb_share_cfg();
        $at = time() + max(0, (int) $c['delay']) * 60;
        $qq[$id]['status'] = 'wait';
        $qq[$id]['at']     = $at;
        sb_share_queue_save($qq);
        wp_schedule_single_event($at, 'sb_share_publish', array($id));
        sb_desk_say($m, '✅ অনুমোদিত। <b>' . esc_html(wp_date('h:i A', $at)) . '</b>-এ যাবে।', array(array(sb_desk_btn('⚡ এখনই দিন', 'sh:now:' . $id), sb_desk_btn('🛑 থামান', 'sh:no:' . $id))));
        return;
    }
    if ('now' === $act && in_array($e['status'], array('draft', 'wait'), true)) {
        sb_share_publish($id);
        return;
    }
    sb_desk_say($m, 'এটার সিদ্ধান্ত আগেই হয়ে গেছে (' . esc_html($e['status']) . ')।');
}

add_action('sb_share_publish', 'sb_share_publish');

function sb_share_publish($id) {
    $qq = sb_share_queue();
    if (!isset($qq[$id]) || 'wait' !== $qq[$id]['status'] && 'draft' !== $qq[$id]['status']) {
        return;
    }
    $e = $qq[$id];
    $qq[$id]['status'] = 'sending';   // guards against a double run (cron + the "now" button)
    sb_share_queue_save($qq);
    $c   = sb_share_cfg();
    $out = array();
    $ok  = 0;
    // Instagram
    if ('' === $c['ig_id'] || '' === $c['ig_token']) {
        $out[] = '📸 ইনস্টাগ্রাম: সংযোগের তথ্য (আইডি/টোকেন) বসানো নেই।';
    } elseif ('' === (string) $e['img']) {
        $out[] = '📸 ইনস্টাগ্রাম: পোস্টের ছবি নেই, তাই পাঠানো হয়নি।';
    } else {
        $r = sb_share_ig_image($c, (string) $e['img'], (string) $e['draft']['ig']);
        if (is_wp_error($r)) {
            $out[] = '📸 ইনস্টাগ্রাম ব্যর্থ: ' . esc_html($r->get_error_message());
        } else {
            $ok++;
            $out[] = '📸 ইনস্টাগ্রামে গেছে ✅ (মিডিয়া আইডি ' . esc_html($r) . ')';
        }
    }
    // YouTube
    $out[] = empty($c['yt_on']) ? '▶️ ইউটিউব: এখনো সংযুক্ত নয় (খসড়া বিবরণ ওপরের বার্তায় আছে, চাইলে হাতে দিন)।' : '▶️ ইউটিউব: সংযোগ প্রস্তুত নয়।';
    $qq = sb_share_queue();
    if (isset($qq[$id])) {
        $qq[$id]['status'] = $ok ? 'done' : 'failed';
        $qq[$id]['res']    = $out;
        sb_share_queue_save($qq);
    }
    sb_tg_api('sendMessage', array('chat_id' => $e['chat'], 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true', 'text' => "📣 <b>শেয়ারের ফল</b>\n" . esc_html(mb_substr((string) $e['title'], 0, 80)) . "\n\n" . implode("\n", $out)));
}

/** Two Graph API calls: make the media container, then publish it. Returns the media id or WP_Error with Instagram's own message. */
function sb_share_ig_image($c, $img, $caption) {
    $base = 'https://graph.facebook.com/' . $c['api_ver'] . '/' . rawurlencode($c['ig_id']);
    $r = wp_remote_post($base . '/media', array('timeout' => 40, 'body' => array('image_url' => $img, 'caption' => $caption, 'access_token' => $c['ig_token'])));
    $j = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
    if (is_wp_error($r)) {
        return $r;
    }
    if (empty($j['id'])) {
        return new WP_Error('sb_ig', (string) ($j['error']['message'] ?? 'অজানা ত্রুটি (কোড ' . (int) wp_remote_retrieve_response_code($r) . ')'));
    }
    $r2 = wp_remote_post($base . '/media_publish', array('timeout' => 40, 'body' => array('creation_id' => $j['id'], 'access_token' => $c['ig_token'])));
    if (is_wp_error($r2)) {
        return $r2;
    }
    $j2 = json_decode((string) wp_remote_retrieve_body($r2), true);
    if (empty($j2['id'])) {
        return new WP_Error('sb_ig', (string) ($j2['error']['message'] ?? 'প্রকাশ হয়নি'));
    }
    return (string) $j2['id'];
}

/* ---- settings: admin only, token is write-only (never sent back) ---- */
add_action('wp_ajax_sb_share_cfg', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('অনুমতি নেই।');
    }
    $c = sb_share_cfg();
    if (isset($_POST['set'])) {
        $n = json_decode(wp_unslash((string) $_POST['set']), true);
        if (is_array($n)) {
            if (isset($n['on'])) {
                $c['on'] = (int) (bool) $n['on'];
            }
            if (isset($n['delay'])) {
                $c['delay'] = max(0, min(720, (int) $n['delay']));
            }
            if (isset($n['ig_id'])) {
                $c['ig_id'] = preg_replace('/\D/', '', (string) $n['ig_id']);
            }
            if (!empty($n['ig_token'])) {
                $c['ig_token'] = trim((string) $n['ig_token']);
            }
            if (isset($n['api_ver']) && preg_match('/^v\d+\.\d+$/', (string) $n['api_ver'])) {
                $c['api_ver'] = (string) $n['api_ver'];
            }
            update_option('sb_share', $c, false);
        }
    }
    wp_send_json_success(array('on' => (int) $c['on'], 'delay' => (int) $c['delay'], 'ig_id' => $c['ig_id'], 'token_set' => '' !== $c['ig_token'], 'api_ver' => $c['api_ver'], 'queue' => array_map(function ($e) { return array('t' => $e['title'], 's' => $e['status'], 'at' => $e['at'] ?? 0, 'res' => $e['res'] ?? array()); }, array_slice(sb_share_queue(), -8, null, true))));
});
