<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Automatic sharing: a new post on the Facebook page goes to Instagram; a video also goes to YouTube.
 *
 *  - Every 5 minutes a cron tick reads the page's newest posts (Graph API, page token). Posts made before sharing was switched on are never touched.
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
        'gem_key' => '', 'gem_model' => 'gemini-2.5-flash',
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

/* ------------------------------------------------------------ cron: look at the page every 5 minutes */
add_filter('cron_schedules', function ($s) {
    $s['sb_5min'] = array('interval' => 300, 'display' => 'Every 5 minutes');
    return $s;
});
add_action('init', function () {
    $c  = sb_share_cfg();
    $ev = wp_next_scheduled('sb_share_poll');
    if (!empty($c['on']) && !$ev) {
        wp_schedule_event(time() + 60, 'sb_5min', 'sb_share_poll');
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
    $j = sb_share_graph($c, $c['page_id'] . '/published_posts', array(
        'fields' => 'id,created_time,message,permalink_url,full_picture,status_type,attachments{media_type,target{id}}',
        'limit'  => 10,
    ));
    if (is_wp_error($j)) {
        return 'পেজ পড়া যায়নি: ' . $j->get_error_message();
    }
    $q = sb_share_queue();
    $n = 0;
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
        );
        $n++;
        if ($kind) {
            wp_schedule_single_event(time() + 5 * $n, 'sb_share_run', array($pid));
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
    return $n ? $n . 'টি নতুন পোস্ট লাইনে বসালাম।' : 'নতুন পোস্ট নেই।';
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
            $v = sb_share_graph($c, $e['vid'], array('fields' => 'source,length'));
            if (is_wp_error($v) || empty($v['source'])) {
                $S['dl'] = 'ভিডিও ফাইলের লিংক পাইনি: ' . (is_wp_error($v) ? $v->get_error_message() : 'source খালি');
                sb_share_finish($id, $S, $file);
                return;
            }
            $src = $v['source'];
        }
        $r = wp_remote_get($src, array('timeout' => 600, 'stream' => true, 'filename' => $file, 'redirection' => 5));
        if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r) || !is_file($file) || filesize($file) < 1000) {
            $S['dl'] = 'ডাউনলোড হয়নি' . (is_wp_error($r) ? ': ' . $r->get_error_message() : '');
            if ($video) {
                sb_share_finish($id, $S, $file);
                return;
            }
            @unlink($file);   // a photo still goes out, only without the AI looking at it
        } elseif (filesize($file) > (int) $c['max_mb'] * 1048576) {
            $S['dl'] = 'ফাইল ' . (int) $c['max_mb'] . ' MB-র বেশি, তাই বাদ।';
            sb_share_finish($id, $S, $file);
            return;
        }
    }

    // 2. AI title / description / caption
    $dr = $e['draft'] ?? null;
    if (empty($S['ai'])) {
        $dr = sb_share_ai($c, $video, $file, $e['msg'], $e['fb']);
        $S['ai'] = !empty($dr['ai']) ? 'ok' : 'সরল খসড়া (AI পায়নি)';
        sb_share_set($id, array('draft' => $dr, 'steps' => $S));
    }

    // 3. Instagram
    if (empty($S['ig'])) {
        if ('' === $c['ig_id'] || '' === $c['ig_token']) {
            $S['ig'] = 'ইনস্টাগ্রামের আইডি/টোকেন নেই।';
        } else {
            $r = $video ? sb_share_ig_reel($c, $file, $dr['ig']) : sb_share_ig_image($c, $e['img'], $dr['ig']);
            $S['ig'] = is_wp_error($r) ? 'ব্যর্থ: ' . $r->get_error_message() : 'ok:' . $r;
        }
        sb_share_set($id, array('steps' => $S));
    }

    // 4. YouTube (videos only), uploaded private, made public later
    if ($video && !empty($c['yt_on']) && empty($S['yt'])) {
        $r = sb_share_yt_upload($c, $file, $dr);
        if (is_wp_error($r)) {
            $S['yt'] = 'ব্যর্থ: ' . $r->get_error_message();
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
    $L[] = '📸 ইনস্টাগ্রাম: ' . (isset($S['ig']) ? (0 === strpos($S['ig'], 'ok:') ? 'গেছে ✅' : esc_html($S['ig'])) : '—');
    if ('video' === ($e['kind'] ?? '')) {
        $yt = $S['yt'] ?? (empty(sb_share_cfg()['yt_on']) ? 'এখনো সংযুক্ত নয়' : '—');
        $L[] = '▶️ ইউটিউব: ' . (0 === strpos($yt, 'ok:') ? 'আপলোড হয়েছে ✅ (প্রাইভেট), ' . (int) sb_share_cfg()['yt_delay'] . ' মিনিট পর পাবলিক হবে' : esc_html($yt));
    }
    if (!empty($S['dl'])) {
        $L[] = '⚠️ ' . esc_html($S['dl']);
    }
    $cfg = function_exists('sb_notify_cfg') ? sb_notify_cfg() : array('tg_chat' => '');
    if ('' !== (string) $cfg['tg_chat']) {
        $t = trim((string) ($e['draft']['yt_title'] ?? '')) ?: mb_substr(wp_strip_all_tags((string) ($e['msg'] ?? '')), 0, 70);
        sb_tg_api('sendMessage', array('chat_id' => $cfg['tg_chat'], 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true',
            'text' => "📣 <b>ফেসবুক পোস্ট অটো-শেয়ার হয়েছে</b>\n" . esc_html($t) . "\n\n" . implode("\n", $L) . "\n\n🗑️ সার্ভার থেকে ভিডিও/ছবি মুছে ফেলা হয়েছে।" . (!empty($e['fb']) ? "\n" . esc_url_raw($e['fb']) : '')));
    }
}

/* ------------------------------------------------------------ AI: watches the video / sees the picture */
function sb_share_footer() {
    return "\n\n🌐 https://shaheen.edu.bd/\n📞 +8801622222111 | +8801785003344\n👍 https://www.facebook.com/shaheen.edu.bd/";
}

function sb_share_bill($real, $model) {
    if ($real > 0 && function_exists('sb_ledger_add')) {
        $GLOBALS['sb_ledger_model'] = $model;
        sb_ledger_add(0, 'share_ai', max(1, (int) ceil($real * SB_SHARE_MULT / sb_unit_billed())), 0, $real);
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
    $model = sb_model_for('custom');
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
function sb_share_gemini($c, $sys, $usr, $file, $video) {
    $key  = $c['gem_key'];
    $base = 'https://generativelanguage.googleapis.com';
    $mime = $video ? 'video/mp4' : 'image/jpeg';
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
    $model = preg_replace('/[^\w.\-]/', '', $c['gem_model']);
    $r = wp_remote_post($base . '/v1beta/models/' . $model . ':generateContent?key=' . rawurlencode($key), array('timeout' => 180, 'headers' => array('Content-Type' => 'application/json'),
        'body' => wp_json_encode(array(
            'system_instruction' => array('parts' => array(array('text' => $sys))),
            'contents' => array(array('role' => 'user', 'parts' => array($part, array('text' => $usr)))),
            'generationConfig' => array('response_mime_type' => 'application/json', 'temperature' => 0.4),
        ))));
    if ('' !== $name) {
        sb_share_gemini_del($base, $name, $key);   // the copy at Google is deleted too
    }
    if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r)) {
        return null;
    }
    $j = json_decode((string) wp_remote_retrieve_body($r), true);
    $u = $j['usageMetadata'] ?? array();
    // Gemini 2.5 Flash list price (USD per 1M tokens): input 0.30, output (incl. thinking) 2.50. Change here if Google changes it.
    $real = ((int) ($u['promptTokenCount'] ?? 0) * 0.30 + ((int) ($u['candidatesTokenCount'] ?? 0) + (int) ($u['thoughtsTokenCount'] ?? 0)) * 2.50) / 1000000;
    sb_share_bill($real, $model);
    $txt = '';
    foreach ((array) ($j['candidates'][0]['content']['parts'] ?? array()) as $p) {
        $txt .= $p['text'] ?? '';
    }
    return json_decode(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($txt)), true);
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
            return new WP_Error('sb_ig', 'ইনস্টাগ্রাম প্রসেস করতে পারেনি: ' . (string) ($s['status'] ?? $code));
        }
        sleep(6);
    }
    $r2 = wp_remote_post($base . '/media_publish', array('timeout' => 60, 'body' => array('creation_id' => $creation, 'access_token' => $c['ig_token'])));
    if (is_wp_error($r2)) {
        return $r2;
    }
    $j2 = json_decode((string) wp_remote_retrieve_body($r2), true);
    return empty($j2['id']) ? new WP_Error('sb_ig', (string) ($j2['error']['message'] ?? 'প্রকাশ হয়নি')) : (string) $j2['id'];
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

/** Reel through the resumable upload route: the file goes straight to Instagram, no public copy is needed. */
function sb_share_ig_reel($c, $file, $caption) {
    $base = 'https://graph.facebook.com/' . $c['api_ver'] . '/' . rawurlencode($c['ig_id']);
    $r = wp_remote_post($base . '/media', array('timeout' => 60, 'body' => array('media_type' => 'REELS', 'upload_type' => 'resumable', 'caption' => $caption, 'share_to_feed' => 'true', 'access_token' => $c['ig_token'])));
    if (is_wp_error($r)) {
        return $r;
    }
    $j = json_decode((string) wp_remote_retrieve_body($r), true);
    if (empty($j['id'])) {
        return new WP_Error('sb_ig', (string) ($j['error']['message'] ?? 'রিলের কন্টেইনার হয়নি'));
    }
    $uri = !empty($j['uri']) ? (string) $j['uri'] : 'https://rupload.facebook.com/ig-api-upload/' . $c['api_ver'] . '/' . $j['id'];
    $up  = sb_share_stream('POST', $uri, array('Authorization: OAuth ' . $c['ig_token'], 'offset: 0', 'file_size: ' . filesize($file)), $file);
    $uj  = json_decode($up['b'], true);
    if (empty($uj['success'])) {
        return new WP_Error('sb_ig', 'ভিডিও আপলোড হয়নি: ' . (is_array($uj) && isset($uj['debug_info']['message']) ? $uj['debug_info']['message'] : ($up['e'] ?: 'HTTP ' . $up['c'])));
    }
    return sb_share_ig_publish($c, (string) $j['id']);
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
        return new WP_Error('sb_yt', 'আপলোড শুরু হয়নি: ' . ($e['error']['message'] ?? 'HTTP ' . $a['c']));
    }
    $u = sb_share_stream('PUT', $loc, array('Authorization: Bearer ' . $tok, 'Content-Type: video/*'), $file);
    $j = json_decode($u['b'], true);
    return empty($j['id']) ? new WP_Error('sb_yt', 'আপলোড হয়নি: ' . ($j['error']['message'] ?? ($u['e'] ?: 'HTTP ' . $u['c']))) : (string) $j['id'];
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
        $msg = 'টোকেন সমস্যা: ' . $tok->get_error_message();
    } else {
        $r = wp_remote_request('https://www.googleapis.com/youtube/v3/videos?part=status', array('method' => 'PUT', 'timeout' => 40,
            'headers' => array('Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('id' => $vid, 'status' => array('privacyStatus' => 'public', 'selfDeclaredMadeForKids' => false)))));
        $chk = wp_remote_get('https://www.googleapis.com/youtube/v3/videos?part=status&id=' . rawurlencode($vid), array('timeout' => 30, 'headers' => array('Authorization' => 'Bearer ' . $tok)));
        $st  = is_wp_error($chk) ? '' : (string) (json_decode((string) wp_remote_retrieve_body($chk), true)['items'][0]['status']['privacyStatus'] ?? '');
        $msg = 'public' === $st ? 'এখন পাবলিক ✅' : 'এখনো প্রাইভেট আছে (Google-এর API অডিট শেষ না হলে এমন হয়)। YouTube Studio থেকে "Public" করুন।';
    }
    sb_share_set($id, array('yt_public' => $msg));
    $cfg = function_exists('sb_notify_cfg') ? sb_notify_cfg() : array('tg_chat' => '');
    if ('' !== (string) $cfg['tg_chat']) {
        sb_tg_api('sendMessage', array('chat_id' => $cfg['tg_chat'], 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true',
            'text' => "▶️ <b>ইউটিউব ভিডিও</b>\n" . esc_html(mb_substr((string) ($e['draft']['yt_title'] ?? ''), 0, 90)) . "\n" . esc_html($msg) . "\nhttps://youtu.be/" . $vid));
    }
}

/* ------------------------------------------------------------ old Telegram-approval hooks: the flow is automatic now */
function sb_share_offer($m, $url, $title, $img = '') {
    return;
}
function sb_share_cb($m, $cb) {
    sb_desk_say($m, 'শেয়ার এখন অটো চলে — ফেসবুক পেজে পোস্ট করলেই ইনস্টাগ্রাম/ইউটিউবে যায়, অনুমোদন লাগে না।');
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
            foreach (array('ig_token', 'page_token', 'gem_key', 'yt_cid', 'yt_sec', 'yt_rt') as $k) {
                if (!empty($n[$k])) {
                    $c[$k] = trim((string) $n[$k]);
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
        'on' => (int) $c['on'], 'page_id' => $c['page_id'], 'ig_id' => $c['ig_id'], 'api_ver' => $c['api_ver'], 'yt_on' => (int) $c['yt_on'], 'yt_delay' => (int) $c['yt_delay'], 'max_mb' => (int) $c['max_mb'], 'gem_model' => $c['gem_model'],
        'set' => array('page_token' => '' !== $c['page_token'], 'ig_token' => '' !== $c['ig_token'], 'gem_key' => '' !== $c['gem_key'], 'yt' => '' !== $c['yt_cid'] && '' !== $c['yt_sec'] && '' !== $c['yt_rt']),
        'msg' => $msg,
        'queue' => array_map(function ($e) { return array('k' => $e['kind'], 's' => $e['status'], 'steps' => $e['steps'] ?? array(), 'yt_public' => $e['yt_public'] ?? ''); }, array_slice(sb_share_queue(), -8, null, true)),
    ));
});
