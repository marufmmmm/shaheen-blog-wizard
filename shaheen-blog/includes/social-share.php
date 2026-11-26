<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Automatic sharing: a new post on the Facebook page goes to Instagram; a video also goes to YouTube.
 *
 *  - Every 2 hours a cron tick reads the page's newest posts (Graph API, page token). Posts made before sharing was switched on are never touched.
 *  - Photo post  -> AI writes the caption from the picture + post text -> Instagram photo.
 *  - Video post  -> the video is downloaded to a TEMPORARY file, AI (Gemini) watches/listens and writes an SEO title, description, hashtags
 *                   -> Instagram Reel (resumable upload, no public copy) + YouTube upload (private) -> YouTube is made public after `yt_delay` minutes.
 *  - The temporary file is deleted as soon as the uploads finish (and on any error). Nothing is kept on the server; only a small log row.
 *  - A Telegram message tells the owner what went out. It is a notice only; nothing waits for approval.
 *  - AI work is billed at real cost x 1.8 (SB_SHARE_MULT, ledger feature "share_ai").
 *
 * Secrets live only in option `sb_share` and are never printed back.
 */
function sb_share_cfg() {
    $o = get_option('sb_share', array());
    return array_merge(array(
        'on' => 0, 'api_ver' => 'v23.0',
        'page_id' => '', 'page_token' => '',
        'ig_id' => '', 'ig_token' => '',
        'gem_key' => '', 'gem_model' => 'gemini-3.8-flash', 'or_key' => '', 'or_model' => 'google/gemini-2.5-flash',
        'yt_on' => 0, 'yt_cid' => '', 'yt_sec' => '', 'yt_rt' => '', 'yt_delay' => 60,
        'max_mb' => 200,
    ), is_array($o) ? $o : array());
}

function sb_share_queue() {
    $q = get_option('sb_share_q', array());
    return is_array($q) ? $q : array();
}

function sb_share_queue_save($q) {
    if (count($q) > 80) {
        $q = array_slice($q, -80, null, true);
    }
    update_option('sb_share_q', $q, false);
}

function sb_share_set($id, $patch) {
    $q = sb_share_queue();
    if (isset($q[$id])) {
        $q[$id] = array_merge($q[$id], $patch);
        sb_share_queue_save($q);
    }
}

/* ------------------------------------------------------------ cron: look at the page every 2 hours */
add_filter('cron_schedules', function ($s) {
    $s['sb_5min'] = array('interval' => 300, 'display' => 'Every 5 minutes');
    $s['sb_2hr']  = array('interval' => 2 * HOUR_IN_SECONDS, 'display' => 'Every 2 hours');
    return $s;
});
add_action('init', function () {
    $c  = sb_share_cfg();
    $ev = wp_next_scheduled('sb_share_poll');
    if (!empty($c['on']) && $ev && 'sb_2hr' !== wp_get_schedule('sb_share_poll')) {
        wp_clear_scheduled_hook('sb_share_poll');   // old 5-minute schedule -> every 2 hours
        $ev = false;
    }
    if (!empty($c['on']) && !$ev) {
        wp_schedule_event(time() + 60, 'sb_2hr', 'sb_share_poll');
    } elseif (empty($c['on']) && $ev) {
        wp_clear_scheduled_hook('sb_share_poll');
    }
});
add_action('sb_share_poll', 'sb_share_poll');
add_action('sb_share_ytpub', 'sb_share_yt_public');

function sb_share_graph($c, $path, $args = array(), $token = '') {
    $args['access_token'] = '' !== $token ? $token : $c['page_token'];
    $r = wp_remote_get('https://graph.facebook.com/' . $c['api_ver'] . '/' . ltrim($path, '/') . '?' . http_build_query($args), array('timeout' => 40));
    if (is_wp_error($r)) {
        return $r;
    }
    $j = json_decode((string) wp_remote_retrieve_body($r), true);
    if (!is_array($j) || isset($j['error'])) {
        return new WP_Error('sb_fb', is_array($j) && isset($j['error']['message']) ? (string) $j['error']['message'] : 'Facebook উত্তর দেয়নি (HTTP ' . (int) wp_remote_retrieve_response_code($r) . ')');
    }
    return $j;
}

/** Find new page posts and queue them. Returns a short report (used by the admin "check now" button too). */
function sb_share_poll($force = false) {
    $c = sb_share_cfg();
    if ((empty($c['on']) && !$force) || '' === $c['page_id'] || '' === $c['page_token']) {
        return 'পেজের আইডি/টোকেন বসানো নেই।';
    }
    $since = (int) get_option('sb_share_since', 0);
    if ($since < 1) {
        $since = time();
        update_option('sb_share_since', $since, false);
    }
    $q = sb_share_queue();
    $n = 0;
    $err = '';
    $pages = array(array($c['page_id'], ''));
    foreach ((array) get_option('sb_share_pages', array()) as $xid => $xp) {
        if (!empty($xp['token'])) {
            $pages[] = array((string) $xid, (string) $xp['token']);
        }
    }
    foreach ($pages as $pg) {
        $j = sb_share_graph($c, $pg[0] . '/published_posts', array(
            'fields' => 'id,created_time,message,permalink_url,full_picture,status_type,attachments{media_type,target{id}}',
            'limit'  => 25,
        ), $pg[1]);
        if (is_wp_error($j)) {
            $err .= ' [' . $pg[0] . '] ' . $j->get_error_message();
            continue;
        }
        foreach (array_reverse((array) ($j['data'] ?? array())) as $p) {
            $pid = (string) ($p['id'] ?? '');
            if ('' === $pid || isset($q[$pid]) || strtotime((string) ($p['created_time'] ?? '')) <= $since) {
                continue;
            }
            $att   = $p['attachments']['data'][0] ?? array();
            $mt    = strtolower((string) ($att['media_type'] ?? ''));
            $video = ('video' === $mt) ? (string) ($att['target']['id'] ?? '') : '';
            $st    = (string) ($p['status_type'] ?? '');
            // only the page's OWN uploads are shared; "shared_story" posts carry someone else's video/picture (copyright)
            $kind  = ('' !== $video && 'added_video' === $st) ? 'video' : (('photo' === $mt && 'added_photos' === $st && !empty($p['full_picture'])) ? 'photo' : '');
            $q[$pid] = array(
                't' => time(), 'fb' => (string) ($p['permalink_url'] ?? ''), 'msg' => (string) ($p['message'] ?? ''), 'kind' => $kind ?: 'skip',
                'vid' => $video, 'img' => (string) ($p['full_picture'] ?? ''), 'status' => $kind ? 'new' : 'skipped', 'st' => $st, 'steps' => array(),
                'pgid' => $pg[1] !== '' ? $pg[0] : '',
            );
            $n++;
            if ($kind) {
                wp_schedule_single_event(time() + 5 * $n, 'sb_share_run', array($pid));
            }
        }
    }
    sb_share_queue_save($q);
    // anything stuck in "working" for more than 20 minutes is retried once
    foreach (sb_share_queue() as $id => $e) {
        if ('working' === $e['status'] && time() - (int) ($e['wt'] ?? 0) > 1200 && empty($e['retried'])) {
            sb_share_set($id, array('status' => 'new', 'retried' => 1));
            wp_schedule_single_event(time() + 5, 'sb_share_run', array($id));
        }
    }
    sb_share_sweep();
    return ($n ? $n . 'টি নতুন পোস্ট লাইনে বসালাম।' : 'নতুন পোস্ট নেই।') . $err;
}

function sb_share_sweep() {
    foreach ((array) glob(get_temp_dir() . 'sbshare-*') as $f) {
        if (is_file($f) && time() - filemtime($f) > 3 * HOUR_IN_SECONDS) {
            @unlink($f);
        }
    }
}

add_action('sb_share_run', 'sb_share_run');

function sb_share_tmp($id) {
    return get_temp_dir() . 'sbshare-' . preg_replace('/\W/', '', $id) . '.bin';
}

/** One queued post, start to finish. Each step is saved so a time-out resumes from where it stopped. */
function sb_share_run($id) {
    $q = sb_share_queue();
    if (!isset($q[$id]) || !in_array($q[$id]['status'], array('new', 'working'), true)) {
        return;
    }
    if (get_transient('sb_share_lock')) {
        wp_schedule_single_event(time() + 60, 'sb_share_run', array($id));
        return;
    }
    set_transient('sb_share_lock', 1, 15 * MINUTE_IN_SECONDS);
    @set_time_limit(600);
    @ignore_user_abort(true);
    $file = sb_share_tmp($id);
    register_shutdown_function(function () use ($file) {
        if (is_file($file)) {
            @unlink($file);
        }
    });
    sb_share_set($id, array('status' => 'working', 'wt' => time()));
    $c = sb_share_cfg();
    $e = sb_share_queue()[$id];
    $S = (array) $e['steps'];
    $video = 'video' === $e['kind'];

    // 1. download (video: temporary file; photo: the picture, only to let the AI see it)
    if (empty($S['ai']) || ($video && (empty($S['ig']) || (!empty($c['yt_on']) && empty($S['yt'])))) ) {
        $src = $e['img'];
        if ($video) {
            $xp = !empty($e['pgid']) ? get_option('sb_share_pages', array()) : array();
            $v = sb_share_graph($c, $e['vid'], array('fields' => 'source,length'), !empty($xp[$e['pgid']]['token']) ? $xp[$e['pgid']]['token'] : '');
            if (is_wp_error($v) || empty($v['source'])) {
                $S['dl'] = 'Could not find the video file link: ' . (is_wp_error($v) ? $v->get_error_message() : 'source is empty');
                sb_share_finish($id, $S, $file);
                return;
            }
            $src = $v['source'];
            sb_share_set($id, array('src' => $src, 'len' => (float) ($v['length'] ?? 0)));
        }
        $r = wp_remote_get($src, array('timeout' => 600, 'stream' => true, 'filename' => $file, 'redirection' => 5));
        if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r) || !is_file($file) || filesize($file) < 1000) {
            $S['dl'] = 'Download failed' . (is_wp_error($r) ? ': ' . $r->get_error_message() : '');
            if ($video) {
                sb_share_finish($id, $S, $file);
                return;
            }
            @unlink($file);   // a photo still goes out, only without the AI looking at it
        } elseif (filesize($file) > (int) $c['max_mb'] * 1048576) {
            $S['dl'] = 'File is over ' . (int) $c['max_mb'] . ' MB, so it was skipped.';
            sb_share_finish($id, $S, $file);
            return;
        }
    }

    // 2. AI title / description / caption
    $dr = $e['draft'] ?? null;
    if (empty($S['ai'])) {
        $dr = sb_share_ai($c, $video, $file, $e['msg'], $e['fb']);
        $S['ai'] = !empty($dr['ai']) ? 'ok' : 'simple draft (the AI gave none)';
        sb_share_set($id, array('draft' => $dr, 'steps' => $S));
    }

    // 3. Instagram
    if (empty($S['ig'])) {
        if ('' === $c['ig_id'] || '' === $c['ig_token']) {
            $S['ig'] = 'The Instagram ID/token is missing.';
        } else {
            $r = $video ? sb_share_ig_reel($c, $file, $dr['ig'], (string) (sb_share_queue()[$id]['src'] ?? '')) : sb_share_ig_image($c, $e['img'], $dr['ig']);
            $S['ig'] = is_wp_error($r) ? 'Failed: ' . $r->get_error_message() : 'ok:' . $r;
        }
        sb_share_set($id, array('steps' => $S));
    }

    // 4. YouTube (videos only), uploaded private, made public later
    if ($video && !empty($c['yt_on']) && empty($S['yt'])) {
        $r = sb_share_yt_upload($c, $file, $dr);
        if (is_wp_error($r)) {
            $S['yt'] = 'Failed: ' . $r->get_error_message();
        } else {
            $S['yt'] = 'ok:' . $r;
            wp_schedule_single_event(time() + max(1, (int) $c['yt_delay']) * 60, 'sb_share_ytpub', array($id));
        }
        sb_share_set($id, array('steps' => $S));
    }
    sb_share_finish($id, $S, $file);
}

function sb_share_finish($id, $S, $file) {
    if (is_file($file)) {
        @unlink($file);   // nothing is kept on the server
    }
    delete_transient('sb_share_lock');
    $e  = sb_share_queue()[$id] ?? array();
    $ok = false;
    foreach (array('ig', 'yt') as $k) {
        if (isset($S[$k]) && 0 === strpos($S[$k], 'ok:')) {
            $ok = true;
        }
    }
    sb_share_set($id, array('steps' => $S, 'status' => $ok ? 'done' : 'failed', 'draft' => $e['draft'] ?? null));
    $L = array();
    $L[] = '📸 Instagram: ' . (isset($S['ig']) ? (0 === strpos($S['ig'], 'ok:') ? 'sent ✅' : esc_html($S['ig'])) : '—');
    if ('video' === ($e['kind'] ?? '')) {
        $yt = $S['yt'] ?? (empty(sb_share_cfg()['yt_on']) ? 'not connected yet' : '—');
        $L[] = '▶️ YouTube: ' . (0 === strpos($yt, 'ok:') ? 'uploaded ✅ (private), will go public after ' . (int) sb_share_cfg()['yt_delay'] . ' minutes' : esc_html($yt));
    }
    if (!empty($S['yt']) && false !== stripos($S['yt'], 'expired or revoked')) {
        $L[] = '🔑 YouTube token needs renewing: WordPress → Facebook → auto-share → "ইউটিউব সংযোগ করুন", press Allow once.';
    }
    if (!empty($S['dl'])) {
        $L[] = '⚠️ ' . esc_html($S['dl']);
    }
    $cfg = function_exists('sb_notify_cfg') ? sb_notify_cfg() : array('tg_chat' => '');
    if ('' !== (string) $cfg['tg_chat']) {
        $t = trim((string) ($e['draft']['yt_title'] ?? '')) ?: mb_substr(wp_strip_all_tags((string) ($e['msg'] ?? '')), 0, 70);
        sb_tg_api('sendMessage', array('chat_id' => $cfg['tg_chat'], 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true',
            'text' => "📣 <b>Facebook post auto-shared</b>\n" . esc_html($t) . "\n\n" . implode("\n", $L) . "\n\n🗑️ The video/picture was deleted from the server." . (!empty($e['fb']) ? "\n" . esc_url_raw($e['fb']) : '')));
    }
}

/* ------------------------------------------------------------ AI: watches the video / sees the picture */
function sb_share_footer() {
    return "\n\n🌐 https://shaheen.edu.bd/\n📞 +8801622222111 | +8801785003344\n👍 https://www.facebook.com/shaheen.edu.bd/";
}

/** $feature 'share_vid' = Gemini looking at the video/picture (real cost x 1.5); 'share_ai' = plain text caption by OpenAI (x 1.8). */
function sb_share_bill($real, $model, $feature = 'share_ai') {
    if ($real > 0 && function_exists('sb_ledger_add')) {
        $GLOBALS['sb_ledger_model'] = $model;
        $mult = 'share_vid' === $feature ? SB_SHARE_VID_MULT : SB_SHARE_MULT;
        sb_ledger_add(0, $feature, max(1, (int) ceil($real * $mult / sb_unit_billed())), 0, $real);
    }
}

function sb_share_ai($c, $video, $file, $msg, $fb) {
    $msg = trim(wp_strip_all_tags((string) $msg));
    $plain = array(
        'yt_title' => mb_substr($msg !== '' ? strtok($msg, "\n") : 'শাহীন স্কুল ও ক্যাডেট কোচিং', 0, 95),
        'yt_desc'  => trim($msg . sb_share_footer()),
        'ig'       => mb_substr(trim($msg . "\n\n#শাহীনশিক্ষাপরিবার #ShaheenEducationFamily"), 0, 2000),
        'tags'     => array('ShaheenEducationFamily'),
        'ai'       => 0,
    );
    $sys = 'তুমি শাহীন শিক্ষা পরিবারের (বাংলাদেশের স্কুল ও ক্যাডেট কোচিং নেটওয়ার্ক) সোশ্যাল মিডিয়া ও SEO সম্পাদক। শুধু ' . ($video ? 'ভিডিওতে যা দেখা ও শোনা যায়' : 'ছবিতে যা দেখা যায়') . ' এবং দেওয়া পোস্টের লেখার ভিতরে থাকবে; কোনো তথ্য, সংখ্যা, নাম, তারিখ বা উদ্ধৃতি বানাবে না। '
         . 'বাংলায় (প্রয়োজনে সহজ ইংরেজি কীওয়ার্ডসহ), আন্তরিক ও সার্চ-বান্ধব ভাষায় লিখবে। শুধু "শাহীন" একা লিখবে না: স্কুল/কোচিং হলে "শাহীন স্কুল ও ক্যাডেট কোচিং", পুরো প্রতিষ্ঠান হলে "শাহীন শিক্ষা পরিবার"। '
         . 'শুধু এই JSON দেবে: {"yt_title":"সর্বোচ্চ ৯০ অক্ষর, মূল কীওয়ার্ড শুরুতে","yt_desc":"প্রথম দুই লাইনে মূল কথা ও কীওয়ার্ড, মোট ৪-৮ লাইন, লিংক ছাড়া","ig":"ইনস্টাগ্রাম ক্যাপশন ২-৪ লাইন, শেষে ৫-৮টি হ্যাশট্যাগ","tags":["৬-১০টি ট্যাগ, # ছাড়া"]}';
    $usr = "ফেসবুক পোস্টের লেখা: " . mb_substr($msg, 0, 1500);
    $d = null;
    if ('' !== $c['gem_key'] && is_file($file)) {
        $d = sb_share_gemini($c, $sys, $usr, $file, $video);
    } elseif ('' !== sb_share_or_key($c)[0] && is_file($file)) {
        $d = sb_share_openrouter($c, $sys, $usr, $file, $video);   // Gemini through OpenRouter
    }
    if (!is_array($d)) {
        $d = sb_share_openai_text($sys, $usr);
    }
    if (!is_array($d) || empty($d['ig'])) {
        return $plain;
    }
    $tags = array();
    foreach ((array) ($d['tags'] ?? array()) as $t) {
        $t = trim(str_replace('#', '', sanitize_text_field((string) $t)));
        if ('' !== $t && count($tags) < 12) {
            $tags[] = $t;
        }
    }
    $desc = mb_substr(sanitize_textarea_field((string) ($d['yt_desc'] ?? '')), 0, 3800);
    return array(
        'yt_title' => mb_substr(sanitize_text_field((string) ($d['yt_title'] ?? $plain['yt_title'])), 0, 95),
        'yt_desc'  => trim($desc . sb_share_footer() . ($tags ? "\n\n#" . implode(' #', array_slice($tags, 0, 8)) : '')),
        'ig'       => mb_substr(sanitize_textarea_field((string) $d['ig']), 0, 2000),
        'tags'     => $tags ?: $plain['tags'],
        'ai'       => 1,
    );
}

function sb_share_openai_text($sys, $usr) {
    $key = function_exists('sb_get') ? sb_get('api_key') : '';
    if ('' === (string) $key) {
        return null;
    }
    $model = sb_model_for('bulk');
    sb_meter_take();
    $r = wp_remote_post(SB_AI::ENDPOINT, array('timeout' => 60, 'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
        'body' => wp_json_encode(array('model' => $model, 'response_format' => array('type' => 'json_object'), 'messages' => array(array('role' => 'system', 'content' => $sys), array('role' => 'user', 'content' => $usr))))));
    $real = sb_meter_take();
    if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r)) {
        return null;
    }
    sb_share_bill($real, $model);
    $j = json_decode((string) wp_remote_retrieve_body($r), true);
    return json_decode((string) ($j['choices'][0]['message']['content'] ?? ''), true);
}

/** Gemini sees/hears the file. Video goes through the Files API (deleted right after), a picture goes inline. */
function sb_share_gemini($c, $sys, $usr, $file, $video, $mime_in = '') {
    $key  = $c['gem_key'];
    $base = 'https://generativelanguage.googleapis.com';
    $mime = $video ? 'video/mp4' : ('' !== $mime_in ? $mime_in : 'image/jpeg');
    $part = null;
    $name = '';
    if ($video) {
        $st = sb_share_stream('POST', $base . '/upload/v1beta/files?key=' . rawurlencode($key), array(
            'X-Goog-Upload-Protocol: resumable', 'X-Goog-Upload-Command: start', 'X-Goog-Upload-Header-Content-Length: ' . filesize($file),
            'X-Goog-Upload-Header-Content-Type: ' . $mime, 'Content-Type: application/json',
        ), null, '{"file":{"display_name":"sbshare"}}');
        $url = $st['h']['x-goog-upload-url'] ?? '';
        if ('' === $url) {
            return null;
        }
        $up = sb_share_stream('POST', $url, array('X-Goog-Upload-Offset: 0', 'X-Goog-Upload-Command: upload, finalize'), $file);
        $f  = json_decode($up['b'], true)['file'] ?? array();
        if (empty($f['uri'])) {
            return null;
        }
        $name = (string) $f['name'];
        for ($i = 0; $i < 40 && 'ACTIVE' !== ($f['state'] ?? ''); $i++) {   // wait until Gemini has processed the video
            if ('FAILED' === ($f['state'] ?? '')) {
                break;
            }
            sleep(4);
            $g = wp_remote_get($base . '/v1beta/' . $name . '?key=' . rawurlencode($key), array('timeout' => 30));
            $f = is_wp_error($g) ? $f : (json_decode((string) wp_remote_retrieve_body($g), true) ?: $f);
        }
        if ('ACTIVE' !== ($f['state'] ?? '')) {
            sb_share_gemini_del($base, $name, $key);
            return null;
        }
        $part = array('file_data' => array('mime_type' => $mime, 'file_uri' => $f['uri']));
    } else {
        $part = array('inline_data' => array('mime_type' => $mime, 'data' => base64_encode((string) file_get_contents($file))));
    }
    $body = wp_json_encode(array(
        'system_instruction' => array('parts' => array(array('text' => $sys))),
        'contents' => array(array('role' => 'user', 'parts' => array($part, array('text' => $usr)))),
        'generationConfig' => array('response_mime_type' => 'application/json', 'temperature' => 0.4),
    ));
    // The chosen model first; when Google says "busy" (429/500/503) retry once, then fall back to the next model.
    $models = array_values(array_unique(array(preg_replace('/[^\w.\-]/', '', $c['gem_model']), 'gemini-3.5-flash')));
    $r = null;
    $model = '';
    foreach ($models as $m) {
        for ($try = 0; $try < 2; $try++) {
            $r = wp_remote_post($base . '/v1beta/models/' . $m . ':generateContent?key=' . rawurlencode($key), array('timeout' => 180, 'headers' => array('Content-Type' => 'application/json'), 'body' => $body));
            $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
            if (200 === $code) {
                $model = $m;
                break 2;
            }
            if (!in_array($code, array(0, 429, 500, 503), true)) {
                break 2;   // a real error (bad key, bad request): another model will not help
            }
            sleep(3);
        }
    }
    if ('' !== $name) {
        sb_share_gemini_del($base, $name, $key);   // the copy at Google is deleted too
    }
    if ('' === $model) {
        return null;
    }
    $j = json_decode((string) wp_remote_retrieve_body($r), true);
    $u = $j['usageMetadata'] ?? array();
    // List prices, USD per 1M tokens (input / output incl. thinking). 3.8 Flash: 0.75 / 3.75 until 31 Dec 2026, doubles from 1 Jan 2027. 3.5 Flash: 1.50 / 9.00. Unknown model: priced as 3.5 Flash (the dearer, so never under-billed). Change here if Google changes it.
    $prices = array('gemini-3.8-flash' => array(0.75, 3.75), 'gemini-3.5-flash' => array(1.50, 9.00));
    $pr  = $prices[$model] ?? $prices['gemini-3.5-flash'];
    $dbl = ('gemini-3.8-flash' === $model && time() >= 1798761600) ? 2 : 1;   // 2027-01-01 00:00 UTC
    $real = ((int) ($u['promptTokenCount'] ?? 0) * $pr[0] * $dbl + ((int) ($u['candidatesTokenCount'] ?? 0) + (int) ($u['thoughtsTokenCount'] ?? 0)) * $pr[1] * $dbl) / 1000000;
    sb_share_bill($real, $model, 'share_vid');
    $txt = '';
    foreach ((array) ($j['candidates'][0]['content']['parts'] ?? array()) as $p) {
        $txt .= $p['text'] ?? '';
    }
    return json_decode(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($txt)), true);
}

/** The OpenRouter key: this panel's own field first, then the plugin's existing OpenRouter key (Site Agent settings), then the main API key when it is an OpenRouter key (sk-or-...). Returns array(key, where-it-came-from). */
function sb_share_or_key($c) {
    if ('' !== (string) $c['or_key']) {
        return array((string) $c['or_key'], 'এই বক্সের key');
    }
    if (function_exists('csa_opt')) {
        $k = trim((string) csa_opt('key_openrouter'));
        if ('' !== $k) {
            return array($k, 'প্লাগইনের আগের OpenRouter key');
        }
    }
    $k = function_exists('sb_get') ? trim((string) sb_get('api_key')) : '';
    if (0 === strpos($k, 'sk-or-')) {
        return array($k, 'প্লাগইনের মূল API key (OpenRouter)');
    }
    return array('', '');
}

/** Gemini through OpenRouter. The file travels inline (base64), so videos above 25 MB are not sent this way: use the direct Gemini key for big files. */
function sb_share_openrouter($c, $sys, $usr, $file, $video) {
    if (filesize($file) > 25 * 1048576) {
        return null;
    }
    $mime = $video ? 'video/mp4' : 'image/jpeg';
    $b64  = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($file));
    $part = $video ? array('type' => 'video_url', 'video_url' => array('url' => $b64)) : array('type' => 'image_url', 'image_url' => array('url' => $b64));
    $model = preg_replace('/[^\w.\-\/:]/', '', $c['or_model']);
    $r = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', array('timeout' => 240,
        'headers' => array('Authorization' => 'Bearer ' . sb_share_or_key($c)[0], 'Content-Type' => 'application/json', 'HTTP-Referer' => home_url('/'), 'X-Title' => 'Shaheen Blog'),
        'body' => wp_json_encode(array('model' => $model, 'usage' => array('include' => true), 'response_format' => array('type' => 'json_object'), 'messages' => array(
            array('role' => 'system', 'content' => $sys),
            array('role' => 'user', 'content' => array(array('type' => 'text', 'text' => $usr), $part)),
        )))));
    unset($b64, $part);
    if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r)) {
        return null;
    }
    $j = json_decode((string) wp_remote_retrieve_body($r), true);
    $u = $j['usage'] ?? array();
    $real = isset($u['cost']) ? (float) $u['cost'] : (((int) ($u['prompt_tokens'] ?? 0)) * 0.30 + ((int) ($u['completion_tokens'] ?? 0)) * 2.50) / 1000000;
    sb_share_bill($real, $model, 'share_vid');
    return json_decode(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim((string) ($j['choices'][0]['message']['content'] ?? ''))), true);
}

function sb_share_gemini_del($base, $name, $key) {
    wp_remote_request($base . '/v1beta/' . $name . '?key=' . rawurlencode($key), array('method' => 'DELETE', 'timeout' => 20));
}

/* ------------------------------------------------------------ HTTP: streams a file from disk (no big file in memory) */
function sb_share_stream($method, $url, $headers, $path = null, $body = null) {
    $ch  = curl_init($url);
    $fh  = null;
    $opt = array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 900, CURLOPT_CONNECTTIMEOUT => 30);
    if (null !== $path) {
        $fh = fopen($path, 'rb');
        $opt[CURLOPT_UPLOAD]     = true;
        $opt[CURLOPT_INFILE]     = $fh;
        $opt[CURLOPT_INFILESIZE] = filesize($path);
    } elseif (null !== $body) {
        $opt[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch);
    $hs  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fh) {
        fclose($fh);
    }
    $h = array();
    foreach (explode("\n", (string) substr((string) $raw, 0, $hs)) as $line) {
        if (false !== strpos($line, ':')) {
            list($k, $v) = explode(':', $line, 2);
            $h[strtolower(trim($k))] = trim($v);
        }
    }
    return array('c' => $code, 'h' => $h, 'b' => (string) substr((string) $raw, $hs), 'e' => $err);
}

/* ------------------------------------------------------------ Instagram */
function sb_share_ig_publish($c, $creation) {
    $base = 'https://graph.facebook.com/' . $c['api_ver'] . '/' . rawurlencode($c['ig_id']);
    for ($i = 0; $i < 45; $i++) {   // video: wait until Instagram has processed it
        $s = sb_share_graph($c, $creation, array('fields' => 'status_code,status'), $c['ig_token']);
        $code = is_wp_error($s) ? '' : (string) ($s['status_code'] ?? '');
        if ('FINISHED' === $code || ($i === 0 && '' === $code)) {
            break;
        }
        if ('ERROR' === $code || 'EXPIRED' === $code) {
            return new WP_Error('sb_ig', 'Instagram could not process it: ' . (string) ($s['status'] ?? $code));
        }
        sleep(6);
    }
    $r2 = wp_remote_post($base . '/media_publish', array('timeout' => 60, 'body' => array('creation_id' => $creation, 'access_token' => $c['ig_token'])));
    if (is_wp_error($r2)) {
        return $r2;
    }
    $j2 = json_decode((string) wp_remote_retrieve_body($r2), true);
    return empty($j2['id']) ? new WP_Error('sb_ig', (string) ($j2['error']['message'] ?? 'Not published')) : (string) $j2['id'];
}

function sb_share_ig_image($c, $img, $caption) {
    $base = 'https://graph.facebook.com/' . $c['api_ver'] . '/' . rawurlencode($c['ig_id']);
    $r = wp_remote_post($base . '/media', array('timeout' => 60, 'body' => array('image_url' => $img, 'caption' => $caption, 'access_token' => $c['ig_token'])));
    if (is_wp_error($r)) {
        return $r;
    }
    $j = json_decode((string) wp_remote_retrieve_body($r), true);
    if (empty($j['id'])) {
        return new WP_Error('sb_ig', (string) ($j['error']['message'] ?? 'অজানা ত্রুটি (HTTP ' . (int) wp_remote_retrieve_response_code($r) . ')'));
    }
    return sb_share_ig_publish($c, (string) $j['id']);
}

/** Reel. First the resumable upload (the file goes straight to Instagram); if Instagram refuses it, Instagram is given the video's link and fetches it itself. */
function sb_share_ig_reel($c, $file, $caption, $src = '') {
    $base = 'https://graph.facebook.com/' . $c['api_ver'] . '/' . rawurlencode($c['ig_id']);
    $why  = '';
    $r = wp_remote_post($base . '/media', array('timeout' => 60, 'body' => array('media_type' => 'REELS', 'upload_type' => 'resumable', 'caption' => $caption, 'share_to_feed' => 'true', 'access_token' => $c['ig_token'])));
    $j = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
    if (empty($j['id'])) {
        $why = is_wp_error($r) ? $r->get_error_message() : (string) ($j['error']['message'] ?? 'রিলের কন্টেইনার হয়নি');
    } else {
        $uri = !empty($j['uri']) ? (string) $j['uri'] : 'https://rupload.facebook.com/ig-api-upload/' . $c['api_ver'] . '/' . $j['id'];
        $up  = sb_share_stream('POST', $uri, array('Authorization: OAuth ' . $c['ig_token'], 'offset: 0', 'file_size: ' . filesize($file), 'Expect:'), $file);
        $uj  = json_decode($up['b'], true);
        if (!empty($uj['success'])) {
            $pub = sb_share_ig_publish($c, (string) $j['id']);
            if (!is_wp_error($pub)) {
                return $pub;
            }
            $why = $pub->get_error_message();
        } else {
            $why = 'আপলোড: ' . (is_array($uj) && isset($uj['debug_info']['message']) ? $uj['debug_info']['message'] : ($up['e'] ?: 'HTTP ' . $up['c'])) . ' [HTTP ' . $up['c'] . ', ' . filesize($file) . ' bytes]';
        }
    }
    if ('' === $src) {
        return new WP_Error('sb_ig', 'Video upload failed: ' . $why);
    }
    // second route: Instagram downloads the video from the link itself
    $r = wp_remote_post($base . '/media', array('timeout' => 60, 'body' => array('media_type' => 'REELS', 'video_url' => $src, 'caption' => $caption, 'share_to_feed' => 'true', 'access_token' => $c['ig_token'])));
    $j = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
    if (empty($j['id'])) {
        return new WP_Error('sb_ig', 'Video upload failed: ' . $why . ' | link method: ' . (is_wp_error($r) ? $r->get_error_message() : (string) ($j['error']['message'] ?? 'কন্টেইনার হয়নি')));
    }
    $pub = sb_share_ig_publish($c, (string) $j['id']);
    return is_wp_error($pub) ? new WP_Error('sb_ig', 'Video upload failed: ' . $why . ' | link method: ' . $pub->get_error_message()) : $pub;
}

/* ------------------------------------------------------------ YouTube */
function sb_share_yt_token($c) {
    $r = wp_remote_post('https://oauth2.googleapis.com/token', array('timeout' => 30, 'body' => array(
        'client_id' => $c['yt_cid'], 'client_secret' => $c['yt_sec'], 'refresh_token' => $c['yt_rt'], 'grant_type' => 'refresh_token')));
    $j = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
    return empty($j['access_token']) ? new WP_Error('sb_yt', 'Google টোকেন: ' . (is_array($j) && isset($j['error_description']) ? $j['error_description'] : 'পাওয়া যায়নি')) : (string) $j['access_token'];
}

function sb_share_yt_upload($c, $file, $dr) {
    if ('' === $c['yt_cid'] || '' === $c['yt_sec'] || '' === $c['yt_rt']) {
        return new WP_Error('sb_yt', 'ইউটিউবের OAuth তথ্য বসানো নেই।');
    }
    $tok = sb_share_yt_token($c);
    if (is_wp_error($tok)) {
        return $tok;
    }
    $meta = wp_json_encode(array(
        'snippet' => array('title' => $dr['yt_title'], 'description' => $dr['yt_desc'], 'tags' => array_slice((array) $dr['tags'], 0, 10), 'categoryId' => '27', 'defaultLanguage' => 'bn'),
        'status'  => array('privacyStatus' => 'private', 'selfDeclaredMadeForKids' => false),
    ), JSON_UNESCAPED_UNICODE);
    $a = sb_share_stream('POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', array(
        'Authorization: Bearer ' . $tok, 'Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Length: ' . filesize($file), 'X-Upload-Content-Type: video/*'), null, $meta);
    $loc = $a['h']['location'] ?? '';
    if ('' === $loc) {
        $e = json_decode($a['b'], true);
        return new WP_Error('sb_yt', 'Upload did not start: ' . ($e['error']['message'] ?? 'HTTP ' . $a['c']));
    }
    $u = sb_share_stream('PUT', $loc, array('Authorization: Bearer ' . $tok, 'Content-Type: video/*'), $file);
    $j = json_decode($u['b'], true);
    return empty($j['id']) ? new WP_Error('sb_yt', 'Upload failed: ' . ($j['error']['message'] ?? ($u['e'] ?: 'HTTP ' . $u['c']))) : (string) $j['id'];
}

/** After the delay: private -> public. Google keeps videos from unaudited API projects private; then the owner is told to press Public in Studio. */
function sb_share_yt_public($id) {
    $e = sb_share_queue()[$id] ?? array();
    $yt = (string) ($e['steps']['yt'] ?? '');
    if (0 !== strpos($yt, 'ok:')) {
        return;
    }
    $vid = substr($yt, 3);
    $c   = sb_share_cfg();
    $tok = sb_share_yt_token($c);
    $msg = '';
    if (is_wp_error($tok)) {
        $msg = 'Token problem: ' . $tok->get_error_message();
    } else {
        $r = wp_remote_request('https://www.googleapis.com/youtube/v3/videos?part=status', array('method' => 'PUT', 'timeout' => 40,
            'headers' => array('Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('id' => $vid, 'status' => array('privacyStatus' => 'public', 'selfDeclaredMadeForKids' => false)))));
        $chk = wp_remote_get('https://www.googleapis.com/youtube/v3/videos?part=status&id=' . rawurlencode($vid), array('timeout' => 30, 'headers' => array('Authorization' => 'Bearer ' . $tok)));
        $st  = is_wp_error($chk) ? '' : (string) (json_decode((string) wp_remote_retrieve_body($chk), true)['items'][0]['status']['privacyStatus'] ?? '');
        $msg = 'public' === $st ? 'now public ✅' : 'still private (this happens until Google\'s API audit is finished). Make it "Public" from YouTube Studio.';
    }
    sb_share_set($id, array('yt_public' => $msg));
    $cfg = function_exists('sb_notify_cfg') ? sb_notify_cfg() : array('tg_chat' => '');
    if ('' !== (string) $cfg['tg_chat']) {
        sb_tg_api('sendMessage', array('chat_id' => $cfg['tg_chat'], 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true',
            'text' => "▶️ <b>YouTube video</b>\n" . esc_html(mb_substr((string) ($e['draft']['yt_title'] ?? ''), 0, 90)) . "\n" . esc_html($msg) . "\nhttps://youtu.be/" . $vid));
    }
}

/* ------------------------------------------------------------ old Telegram-approval hooks: the flow is automatic now */
function sb_share_offer($m, $url, $title, $img = '') {
    return;
}
function sb_share_cb($m, $cb) {
    sb_desk_say($m, 'Sharing now runs automatically — whatever you post on the Facebook page goes to Instagram/YouTube, no approval needed.');
}

/* ------------------------------------------------------------ settings: admin only, secrets are write-only */
add_action('wp_ajax_sb_share_cfg', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('অনুমতি নেই।');
    }
    $c   = sb_share_cfg();
    $msg = '';
    if (isset($_POST['set'])) {
        $n = json_decode(wp_unslash((string) $_POST['set']), true);
        if (is_array($n)) {
            if (isset($n['on'])) {
                $was = !empty($c['on']);
                $c['on'] = (int) (bool) $n['on'];
                if ($c['on'] && !$was) {
                    update_option('sb_share_since', time(), false);   // only posts made from now on are shared
                }
            }
            foreach (array('ig_id', 'page_id') as $k) {
                if (isset($n[$k])) {
                    $c[$k] = preg_replace('/\D/', '', (string) $n[$k]);
                }
            }
            foreach (array('ig_token', 'page_token', 'gem_key', 'or_key', 'yt_cid', 'yt_sec', 'yt_rt') as $k) {
                if (!empty($n[$k])) {
                    $c[$k] = '-' === trim((string) $n[$k]) ? '' : trim((string) $n[$k]);   // "-" clears a saved secret
                }
            }
            if (isset($n['yt_on'])) {
                $c['yt_on'] = (int) (bool) $n['yt_on'];
            }
            if (isset($n['yt_delay'])) {
                $c['yt_delay'] = max(1, min(1440, (int) $n['yt_delay']));
            }
            if (isset($n['max_mb'])) {
                $c['max_mb'] = max(5, min(500, (int) $n['max_mb']));
            }
            if (isset($n['gem_model']) && preg_match('/^[\w.\-]+$/', (string) $n['gem_model'])) {
                $c['gem_model'] = (string) $n['gem_model'];
            }
            if (isset($n['or_model']) && preg_match('/^[\w.\-\/:]+$/', (string) $n['or_model'])) {
                $c['or_model'] = (string) $n['or_model'];
            }
            if (isset($n['api_ver']) && preg_match('/^v\d+\.\d+$/', (string) $n['api_ver'])) {
                $c['api_ver'] = (string) $n['api_ver'];
            }
            update_option('sb_share', $c, false);
        }
    }
    if (!empty($_POST['check'])) {
        $msg = sb_share_poll(true);
    }
    wp_send_json_success(array(
        'on' => (int) $c['on'], 'page_id' => $c['page_id'], 'ig_id' => $c['ig_id'], 'api_ver' => $c['api_ver'], 'yt_on' => (int) $c['yt_on'], 'yt_delay' => (int) $c['yt_delay'], 'max_mb' => (int) $c['max_mb'], 'gem_model' => $c['gem_model'], 'or_model' => $c['or_model'],
        'set' => array('page_token' => '' !== $c['page_token'], 'ig_token' => '' !== $c['ig_token'], 'gem_key' => '' !== $c['gem_key'], 'or_key' => '' !== sb_share_or_key($c)[0], 'or_src' => sb_share_or_key($c)[1], 'yt' => '' !== $c['yt_cid'] && '' !== $c['yt_sec'] && '' !== $c['yt_rt']),
        'msg' => $msg,
        'queue' => array_map(function ($e) { return array('k' => $e['kind'], 's' => $e['status'], 'steps' => $e['steps'] ?? array(), 'yt_public' => $e['yt_public'] ?? ''); }, array_slice(sb_share_queue(), -8, null, true)),
    ));
});

/** Add more Facebook Pages: one user token (never stored) is used to fetch each page's own token. */
add_action('wp_ajax_sb_share_addpages', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('অনুমতি নেই।');
    }
    $c   = sb_share_cfg();
    $ut  = trim((string) ($_POST['user_token'] ?? ''));
    $ref = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) wp_unslash($_POST['refs'] ?? ''))));
    if ('' === $ut || !$ref) {
        wp_send_json_error('ইউজার টোকেন ও পেজের লিংক দিন।');
    }
    $acc = sb_share_graph($c, 'me/accounts', array('fields' => 'id,name,username,access_token', 'limit' => 100), $ut);
    if (is_wp_error($acc)) {
        wp_send_json_error($acc->get_error_message());
    }
    $mine = (array) ($acc['data'] ?? array());
    $have = get_option('sb_share_pages', array());
    $have = is_array($have) ? $have : array();
    $rep  = array();
    foreach ($ref as $r) {
        $key = preg_match('#facebook\.com/profile\.php\?id=(\d+)#', $r, $m) ? $m[1] : (preg_match('#facebook\.com/([^/?&\s]+)#', $r, $m) ? $m[1] : $r);
        $hit = null;
        foreach ($mine as $a) {
            if ((string) $a['id'] === $key || (isset($a['username']) && 0 === strcasecmp((string) $a['username'], $key))) {
                $hit = $a;
                break;
            }
        }
        if (!$hit) {   // ask for the page directly (works even when me/accounts is empty, e.g. pages granted one by one)
            $dr = sb_share_graph($c, $key, array('fields' => 'id,name,access_token'), $ut);
            if (!is_wp_error($dr) && !empty($dr['id']) && !empty($dr['access_token'])) {
                $hit = $dr;
            }
        }
        if (!$hit) {   // slug that me/accounts does not show by username: look the id up
            $lk = sb_share_graph($c, $key, array('fields' => 'id,name'), $ut);
            if (!is_wp_error($lk) && !empty($lk['id'])) {
                foreach ($mine as $a) {
                    if ((string) $a['id'] === (string) $lk['id']) {
                        $hit = $a;
                    }
                }
            }
        }
        if ($hit && !empty($hit['access_token'])) {
            if ((string) $hit['id'] !== (string) $c['page_id']) {
                $have[(string) $hit['id']] = array('name' => (string) ($hit['name'] ?? ''), 'token' => (string) $hit['access_token']);
            } else {   // the main page: replace its saved token with the new one (which can post)
                $o2 = get_option('sb_share', array());
                $o2 = is_array($o2) ? $o2 : array();
                $o2['page_token'] = (string) $hit['access_token'];
                update_option('sb_share', $o2, false);
                $c['page_token'] = $o2['page_token'];
            }
            $rep[] = '✅ ' . ($hit['name'] ?? $hit['id']);
        } else {
            $rep[] = '❌ ' . $key . ' (এই টোকেনের অ্যাকাউন্ট পেজটির অ্যাডমিন নয়, বা পেজ নয়)';
        }
    }
    update_option('sb_share_pages', $have, false);
    wp_send_json_success(array('report' => $rep, 'pages' => count($have)));
});

/* ------------------------------------------------------------ manual: list the page's latest posts, share one right now (admin only) */
function sb_share_kind($p) {
    $att   = $p['attachments']['data'][0] ?? array();
    $mt    = strtolower((string) ($att['media_type'] ?? ''));
    $video = ('video' === $mt) ? (string) ($att['target']['id'] ?? '') : '';
    $st    = (string) ($p['status_type'] ?? '');
    $kind  = ('' !== $video && 'added_video' === $st) ? 'video' : (('photo' === $mt && 'added_photos' === $st && !empty($p['full_picture'])) ? 'photo' : '');
    return array($kind, $video, $st);
}
add_action('wp_ajax_sb_share_pick', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('অনুমতি নেই।');
    }
    $c = sb_share_cfg();
    if ('' === $c['page_id'] || '' === $c['page_token']) {
        wp_send_json_error('পেজের আইডি/টোকেন বসানো নেই।');
    }
    $fields = 'id,created_time,message,permalink_url,full_picture,status_type,attachments{media_type,target{id}}';
    $id = isset($_POST['share']) ? preg_replace('/[^\d_]/', '', (string) $_POST['share']) : '';
    if ('' === $id) {   // list
        $j = sb_share_graph($c, $c['page_id'] . '/published_posts', array('fields' => $fields, 'limit' => 15));
        if (is_wp_error($j)) {
            wp_send_json_error($j->get_error_message());
        }
        $q = sb_share_queue();
        $out = array();
        foreach ((array) ($j['data'] ?? array()) as $p) {
            list($kind) = sb_share_kind($p);
            $out[] = array('id' => $p['id'], 'time' => $p['created_time'] ?? '', 'kind' => $kind ?: 'skip', 'st' => $p['status_type'] ?? '', 'msg' => mb_substr(wp_strip_all_tags((string) ($p['message'] ?? '')), 0, 90), 'state' => $q[$p['id']]['status'] ?? '', 'len' => $q[$p['id']]['len'] ?? 0);
        }
        wp_send_json_success(array('posts' => $out));
    }
    // share this one now (even when it is older than the moment sharing was switched on)
    $p = sb_share_graph($c, $id, array('fields' => $fields));
    if (is_wp_error($p)) {
        wp_send_json_error($p->get_error_message());
    }
    list($kind, $video, $st) = sb_share_kind($p);
    if ('' === $kind) {
        wp_send_json_error('এই পোস্টটা শেয়ার করা যায় না (পেজের নিজের ছবি/ভিডিও নয়)।');
    }
    $q = sb_share_queue();
    if (isset($q[$id]) && in_array($q[$id]['status'], array('working', 'done'), true) && empty($_POST['again'])) {
        wp_send_json_error('এটা আগেই ' . ('done' === $q[$id]['status'] ? 'শেয়ার হয়ে গেছে' : 'চলছে') . '।');
    }
    $keep = array();   // a retry keeps the steps that already worked (e.g. the YouTube upload), only failed ones run again
    $dr   = null;
    if (isset($q[$id])) {
        foreach ((array) ($q[$id]['steps'] ?? array()) as $k => $v) {
            if ('ai' === $k || 0 === strpos((string) $v, 'ok:')) {
                $keep[$k] = $v;
            }
        }
        $dr = $q[$id]['draft'] ?? null;
    }
    $q[$id] = array(
        't' => time(), 'fb' => (string) ($p['permalink_url'] ?? ''), 'msg' => (string) ($p['message'] ?? ''), 'kind' => $kind,
        'vid' => $video, 'img' => (string) ($p['full_picture'] ?? ''), 'status' => 'new', 'st' => $st, 'steps' => $keep, 'draft' => $dr,
    );
    sb_share_queue_save($q);
    delete_transient('sb_share_lock');
    @ignore_user_abort(true);
    sb_share_run($id);   // runs now; the page may stop waiting, the work carries on
    $e = sb_share_queue()[$id] ?? array();
    wp_send_json_success(array('status' => $e['status'] ?? '', 'steps' => $e['steps'] ?? array(), 'yt_public' => $e['yt_public'] ?? '', 'len' => $e['len'] ?? 0));
});

/* ------------------------------------------------------------ admin panel (shown under the notification panel on the Research page) */
function sb_share_panel() {
    $c = sb_share_cfg();
    $ph = function ($set) { return $set ? 'সেট আছে — বদলাতে নতুনটা লিখুন' : 'বসানো নেই'; };
    ?>
    <div id="sb-sh" style="border:1px solid #d1d5db;border-radius:8px;padding:12px;margin:12px 0;background:#f0f9ff">
        <b>📣 অটো-শেয়ার: ফেসবুক পেজ → ইনস্টাগ্রাম / ইউটিউব</b>
        <p class="sba-muted" style="margin:4px 0 8px">পেজে নিজের ছবি/ভিডিও পোস্ট হলে AI ক্যাপশন লিখে ইনস্টাগ্রামে (ভিডিও হলে ইউটিউবেও) দেবে। ফাইল সার্ভারে রাখা হয় না। টেলিগ্রামে শুধু জানানো হয়। AI-র খরচ আলাদা হিসাবে ধরা হয়।</p>
        <p><label><input type="checkbox" id="sh-on" <?php checked($c['on'], 1); ?>> <b>চালু</b></label> <span class="description">(চালু করার পরের পোস্ট থেকে কাজ শুরু; পুরোনো পোস্ট ছোঁবে না)</span></p>
        <p><label>ফেসবুক পেজ আইডি<br><input type="text" id="sh-page" value="<?php echo esc_attr($c['page_id']); ?>" style="width:100%"></label></p>
        <p><label>পেজ টোকেন (লেখা যায়, দেখা যায় না)<br><input type="password" id="sh-pt" autocomplete="off" placeholder="<?php echo esc_attr($ph('' !== $c['page_token'])); ?>" style="width:100%"></label></p>
        <p><label>ইনস্টাগ্রাম অ্যাকাউন্ট আইডি<br><input type="text" id="sh-ig" value="<?php echo esc_attr($c['ig_id']); ?>" style="width:100%"></label></p>
        <p><label>ইনস্টাগ্রাম টোকেন<br><input type="password" id="sh-it" autocomplete="off" placeholder="<?php echo esc_attr($ph('' !== $c['ig_token'])); ?>" style="width:100%"></label></p>
        <p><label>Gemini API key (ভিডিও দেখে বোঝার জন্য; সরাসরি Google থেকে, বড় ফাইলও চলে)<br><input type="password" id="sh-gk" autocomplete="off" placeholder="<?php echo esc_attr($ph('' !== $c['gem_key'])); ?>" style="width:100%"></label></p>
        <p><label>OpenRouter key (খালি রাখলে প্লাগইনের আগের OpenRouter key নেবে; Gemini মডেল দিয়ে চলে, ২৫ MB-র ছোট ফাইল)<br><input type="password" id="sh-ok" autocomplete="off" placeholder="<?php echo esc_attr($ph('' !== $c['or_key'])); ?>" style="width:100%"></label></p>
        <p><label>OpenRouter মডেল<br><input type="text" id="sh-om" value="<?php echo esc_attr($c['or_model']); ?>" style="width:100%"></label></p>
        <p><label><input type="checkbox" id="sh-yt" <?php checked($c['yt_on'], 1); ?>> ইউটিউবে আপলোড (OAuth বসানোর পর)</label> &nbsp; <label>পাবলিক হবে <input type="number" id="sh-yd" min="1" max="1440" value="<?php echo (int) $c['yt_delay']; ?>" style="width:70px"> মিনিট পর</label></p>
        <?php $yt_redirect = admin_url('admin-post.php?action=sb_yt_cb'); $yt_ready = '' !== $c['yt_cid'] && '' !== $c['yt_sec']; ?>
        <p>🔗 <b>ইউটিউব সংযোগ (নতুন টোকেন এক ক্লিকে)</b><br>
            <?php if ($yt_ready) : ?>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sb_yt_connect'), 'sb_yt_connect')); ?>">ইউটিউব সংযোগ করুন / নতুন টোকেন নিন</a>
                <span class="description">গুগলে "Allow" চাপলেই নতুন টোকেন নিজে সেভ হবে, কিছু কপি করতে হবে না।</span><br>
                <span class="description">Google Cloud → Credentials → আপনার OAuth client → "Authorized redirect URIs"-এ এই ঠিকানা যোগ করা থাকতে হবে: <code><?php echo esc_html($yt_redirect); ?></code> আর OAuth consent screen "In production" করে নিন, নইলে টোকেন ৭ দিনে মরে যায়।</span>
            <?php else : ?>
                <span class="description">আগে ইউটিউবের Client ID ও Client secret সেভ করুন, তারপর এখানে সংযোগ বোতাম আসবে।</span>
            <?php endif; ?>
            <?php if (!empty($_GET['yt'])) : // phpcs:ignore WordPress.Security.NonceVerification ?><br><b><?php echo 'ok' === $_GET['yt'] ? '✅ ইউটিউব সংযুক্ত হয়েছে, নতুন টোকেন সেভ হয়েছে।' : '⚠️ ইউটিউব সংযোগ হয়নি: ' . esc_html(sanitize_text_field(wp_unslash((string) ($_GET['why'] ?? '')))); ?></b><?php endif; // phpcs:ignore ?>
        </p>
        <p><b>➕ আরও ফেসবুক পেজ</b> <span class="description">(আপনি যে পেজগুলোর অ্যাডমিন; যুক্ত আছে: <?php echo (int) count((array) get_option('sb_share_pages', array())); ?>টি)</span><br>
            <textarea id="sh-xp" rows="3" style="width:100%" placeholder="প্রতি লাইনে একটি পেজের লিংক"></textarea><br>
            <input type="password" id="sh-xt" autocomplete="off" placeholder="আপনার ফেসবুক ইউজার টোকেন (সেভ হয় না, শুধু পেজ-টোকেন আনতে লাগে)" style="width:70%"> <button type="button" class="button" id="sh-xadd">যুক্ত করুন</button></p>
        <p><button type="button" class="button button-primary" id="sh-save">💾 সেভ</button> <button type="button" class="button" id="sh-check">🔎 এখনই পেজ দেখুন</button> <span id="sh-msg" class="description"></span></p>
    </div>
    <script>
    (function(){var $=function(s){return document.querySelector(s);},aj=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nn=<?php echo wp_json_encode(wp_create_nonce('sb_admin')); ?>;
        function call(x){$('#sh-msg').textContent='…';return fetch(aj,{method:'POST',credentials:'same-origin',body:new URLSearchParams(Object.assign({action:'sb_share_cfg',nonce:nn},x))}).then(function(r){return r.json();}).then(function(j){
            if(!j.success){$('#sh-msg').textContent='❌ '+(j.data||'ত্রুটি');return;}var d=j.data,s=d.set||{};
            $('#sh-msg').textContent=(d.msg?d.msg+' · ':'')+'চালু: '+(d.on?'হ্যাঁ':'না')+' · পেজ টোকেন: '+(s.page_token?'✅':'—')+' · ইনস্টাগ্রাম: '+(s.ig_token?'✅':'—')+' · Gemini: '+(s.gem_key?'✅':'—')+' · OpenRouter: '+(s.or_key?'✅ ('+(s.or_src||'')+')':'—')+' · ইউটিউব: '+(s.yt?'✅':'—');
            ['#sh-pt','#sh-it','#sh-gk','#sh-ok'].forEach(function(i){$(i).value='';});});}
        $('#sh-save').onclick=function(){call({set:JSON.stringify({on:$('#sh-on').checked?1:0,page_id:$('#sh-page').value,page_token:$('#sh-pt').value,ig_id:$('#sh-ig').value,ig_token:$('#sh-it').value,gem_key:$('#sh-gk').value,or_key:$('#sh-ok').value,or_model:$('#sh-om').value,yt_on:$('#sh-yt').checked?1:0,yt_delay:$('#sh-yd').value})});};
        $('#sh-check').onclick=function(){call({check:'1'});};
        $('#sh-xadd').onclick=function(){$('#sh-msg').textContent='…';fetch(aj,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'sb_share_addpages',nonce:nn,refs:$('#sh-xp').value,user_token:$('#sh-xt').value})}).then(function(r){return r.json();}).then(function(j){$('#sh-xt').value='';$('#sh-msg').textContent=j.success?j.data.report.join(' · ')+' · মোট '+j.data.pages+'টি':'❌ '+j.data;});};
    })();
    </script>
    <?php
}


/* ------------------------------------------------------------ YouTube: one-click connect (Google asks the owner to press Allow; the new refresh token is saved by the site) */
add_action('admin_post_sb_yt_connect', function () {
    if (!current_user_can('manage_options')) {
        wp_die('অনুমতি নেই।');
    }
    check_admin_referer('sb_yt_connect');
    $c = sb_share_cfg();
    if ('' === $c['yt_cid'] || '' === $c['yt_sec']) {
        wp_safe_redirect(admin_url('admin.php?page=sb-facebook&tab=share&yt=err&why=' . rawurlencode('Client ID/secret নেই')));
        exit;
    }
    $state = wp_generate_password(24, false, false);
    set_transient('sb_yt_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS);
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(array(
        'client_id' => $c['yt_cid'], 'redirect_uri' => admin_url('admin-post.php?action=sb_yt_cb'), 'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state));
    wp_redirect($url); // phpcs:ignore WordPress.Security.SafeRedirect
    exit;
});

add_action('admin_post_sb_yt_cb', function () {
    if (!current_user_can('manage_options')) {
        wp_die('অনুমতি নেই।');
    }
    $back = function ($ok, $why = '') {
        wp_safe_redirect(admin_url('admin.php?page=sb-facebook&tab=share&yt=' . ($ok ? 'ok' : 'err') . ($why !== '' ? '&why=' . rawurlencode($why) : '')));
        exit;
    };
    $uid   = get_current_user_id();
    $state = (string) get_transient('sb_yt_state_' . $uid);
    delete_transient('sb_yt_state_' . $uid);
    $got   = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
    if ($state === '' || !hash_equals($state, $got)) {
        $back(false, 'নিরাপত্তা যাচাই মেলেনি, আবার চেষ্টা করুন');
    }
    if (!empty($_GET['error'])) { // phpcs:ignore WordPress.Security.NonceVerification
        $back(false, sanitize_text_field(wp_unslash($_GET['error']))); // phpcs:ignore WordPress.Security.NonceVerification
    }
    $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
    $c    = sb_share_cfg();
    $r = wp_remote_post('https://oauth2.googleapis.com/token', array('timeout' => 30, 'body' => array(
        'code' => $code, 'client_id' => $c['yt_cid'], 'client_secret' => $c['yt_sec'], 'redirect_uri' => admin_url('admin-post.php?action=sb_yt_cb'), 'grant_type' => 'authorization_code')));
    $j = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
    if (empty($j['refresh_token'])) {
        $back(false, is_array($j) && isset($j['error_description']) ? (string) $j['error_description'] : 'Google টোকেন দেয়নি');
    }
    $o = get_option('sb_share', array());
    $o = is_array($o) ? $o : array();
    $o['yt_rt'] = (string) $j['refresh_token'];
    $o['yt_rt_t'] = time();   // when this token was made (a Google app in Testing mode lets it die after about 7 days)
    update_option('sb_share', $o, false);
    $back(true);
});


/* ------------------------------------------------------------ YouTube token watch: once a day, warns on Telegram before / when the token dies */
add_action('init', function () {
    if (!wp_next_scheduled('sb_yt_watch')) {
        wp_schedule_event(time() + 600, 'daily', 'sb_yt_watch');
    }
});
add_action('sb_yt_watch', function () {
    $c = sb_share_cfg();
    if (empty($c['yt_on']) || '' === $c['yt_rt'] || '' === $c['yt_cid']) {
        return;
    }
    $cfg = function_exists('sb_notify_cfg') ? sb_notify_cfg() : array('tg_chat' => '');
    if ('' === (string) $cfg['tg_chat'] || !function_exists('sb_tg_api')) {
        return;
    }
    if (empty($c['yt_rt_t'])) {   // token made before this watch existed: count from now
        $o = get_option('sb_share', array());
        $o = is_array($o) ? $o : array();
        $o['yt_rt_t'] = time();
        update_option('sb_share', $o, false);
        $c['yt_rt_t'] = $o['yt_rt_t'];
    }
    $link = admin_url('admin.php?page=sb-research');
    $age  = !empty($c['yt_rt_t']) ? (time() - (int) $c['yt_rt_t']) / DAY_IN_SECONDS : -1;
    $tok  = sb_share_yt_token($c);
    if (is_wp_error($tok)) {
        if (get_transient('sb_yt_warned_dead')) {
            return;   // one message per day at most
        }
        set_transient('sb_yt_warned_dead', 1, 20 * HOUR_IN_SECONDS);
        sb_tg_api('sendMessage', array('chat_id' => $cfg['tg_chat'], 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true',
            'text' => "🔑 <b>YouTube token has expired.</b>\nVideos will not go to YouTube until it is renewed.\nOpen: " . esc_url_raw($link) . "\nPress <b>ইউটিউব সংযোগ করুন</b>, then Allow (one click)."));
        return;
    }
    if ($age >= 5.5 && !get_transient('sb_yt_warned_soon')) {
        set_transient('sb_yt_warned_soon', 1, 20 * HOUR_IN_SECONDS);
        sb_tg_api('sendMessage', array('chat_id' => $cfg['tg_chat'], 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true',
            'text' => "🔑 <b>YouTube token will expire in about a day or two</b> (the Google app is in Testing mode).\nRenew it now: " . esc_url_raw($link) . "\nPress <b>ইউটিউব সংযোগ করুন</b>, then Allow (one click)."));
    }
});
