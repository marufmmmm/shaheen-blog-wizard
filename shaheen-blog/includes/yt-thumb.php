<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thumbnails for new videos (made right after the YouTube upload, no one has to press anything):
 *  - the real frame of the video (Facebook's own preview picture) is the base, so the thumbnail matches the video;
 *  - the AI image model brightens it and writes one short Bangla hook on it;
 *  - the institution's logo (from the Uniform/Logo library) is placed once in a corner, unaltered;
 *  - the result is cut to 1280x720 and set on the video.  Billed like the other share AI work (real cost x 1.5).
 */
function sb_thumb_frame($c, $e, $dir) {
    $xp  = !empty($e['pgid']) ? get_option('sb_share_pages', array()) : array();
    $tok = !empty($xp[$e['pgid']]['token']) ? $xp[$e['pgid']]['token'] : '';
    $v   = sb_share_graph($c, $e['vid'], array('fields' => 'thumbnails{uri,is_preferred,width,height},picture'), $tok);
    $uri = '';
    if (!is_wp_error($v)) {
        $best = 0;
        foreach ((array) ($v['thumbnails']['data'] ?? array()) as $t) {
            $score = (!empty($t['is_preferred']) ? 1000000 : 0) + (int) ($t['width'] ?? 0);
            if (!empty($t['uri']) && $score > $best) {
                $best = $score;
                $uri  = $t['uri'];
            }
        }
        if ('' === $uri) {
            $uri = (string) ($v['picture'] ?? '');
        }
    }
    if ('' === $uri) {
        return new WP_Error('sb_th', 'ভিডিওর প্রিভিউ ছবি পাওয়া যায়নি।');
    }
    $path = $dir . 'sbthumb-frame-' . preg_replace('/\W/', '', (string) $e['vid']) . '.jpg';
    $r    = wp_remote_get($uri, array('timeout' => 60, 'stream' => true, 'filename' => $path));
    if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r) || !is_file($path) || filesize($path) < 2000) {
        @unlink($path);
        return new WP_Error('sb_th', 'ভিডিওর প্রিভিউ ছবি নামানো যায়নি।');
    }
    return $path;
}

/** a short hook from a title when the AI gave none: the part after "|" if it is short (it is usually the catchy one), else the first words, never cut inside a word */
function sb_thumb_hook_from($title) {
    $parts = array_values(array_filter(array_map('trim', preg_split('/\s*[|:–]\s*/u', $title))));
    $pick  = $parts ? end($parts) : $title;
    if (mb_strlen($pick) > 34 && count($parts) > 1) {
        $pick = $parts[0];
    }
    $out = '';
    foreach (preg_split('/\s+/u', $pick) as $w) {
        if ('' !== $out && mb_strlen($out . ' ' . $w) > 34) {
            break;
        }
        $out .= ('' === $out ? '' : ' ') . $w;
    }
    return $out;
}

/** returns array(jpeg_path, real_usd, model, hook) or WP_Error. Nothing is set on YouTube here. */
function sb_thumb_build($c, $e, $dr) {
    if (!function_exists('sb_img_call') || !function_exists('sb_img_models') || !sb_img_models()) {
        return new WP_Error('sb_th', 'ছবির মডেল চালু নেই।');
    }
    $dir   = get_temp_dir();
    $frame = sb_thumb_frame($c, $e, $dir);
    if (is_wp_error($frame)) {
        return $frame;
    }
    $hook = trim((string) ($dr['hook'] ?? ''));
    if ('' === $hook) {
        $hook = sb_thumb_hook_from((string) ($dr['yt_title'] ?? ''));
    }
    $refs = array(array('path' => $frame, 'cat' => 'frame', 'name' => 'video frame'));
    $logo = '';
    $all  = function_exists('sb_logos') ? sb_logos() : array();
    foreach (array('family', 'school') as $k) {
        if (isset($all[$k])) {
            $refs[] = array('att' => $all[$k]['att'], 'cat' => 'logo', 'name' => $all[$k]['label']);
            $logo   = $all[$k]['label'];
            break;
        }
    }
    $prompt = 'Create a YouTube thumbnail (landscape). Use reference image 1 — a real frame from the video — as the base: keep the real people, place and clothes exactly as they are, but make it brighter, sharper and richer in colour with a clean, uncluttered, eye-catching composition. '
        . 'Add ONE big bold headline in Bangla, exactly this text and nothing else: "' . $hook . '". Use a thick, clean Bengali typeface in white with a dark outline or on a solid colour band, very easy to read, with correct Bengali conjunct letters and no spelling changes. '
        . 'Keep the headline and the main subject well inside the central area (the top and bottom tenth of the picture may be cropped away). '
        . ('' !== $logo ? 'Reference image 2 is the official logo (' . $logo . '): copy it faithfully, unaltered, small, once, in the top-left corner. ' : '')
        . 'No other text, no watermark, no extra logos, no frames or borders.';
    $model = array_keys(sb_img_models())[0];
    $res   = sb_img_call($model, $prompt, '1536x1024', 'medium', $refs);
    @unlink($frame);
    if (is_wp_error($res)) {
        return $res;
    }
    list($bytes, $usage) = $res;
    $raw = $dir . 'sbthumb-raw-' . preg_replace('/\W/', '', (string) $e['vid']) . '.webp';
    file_put_contents($raw, $bytes);   // phpcs:ignore
    $ed = wp_get_image_editor($raw);
    if (is_wp_error($ed)) {
        @unlink($raw);
        return $ed;
    }
    $ed->resize(1280, 720, true);
    $ed->set_quality(88);
    $out = $dir . 'sbthumb-' . preg_replace('/\W/', '', (string) $e['vid']) . '.jpg';
    $sv  = $ed->save($out, 'image/jpeg');
    @unlink($raw);
    if (is_wp_error($sv) || !is_file($out) || filesize($out) > 1900000) {
        @unlink($out);
        return new WP_Error('sb_th', 'থাম্বনেইল ফাইল তৈরি করা যায়নি।');
    }
    return array($out, sb_img_cost($model, $usage), $model, $hook);
}

function sb_thumb_set($c, $yt_id, $file) {
    $tok = get_transient('sb_ytm_tok');
    if (!$tok) {
        $tok = sb_share_yt_token($c);
        if (is_wp_error($tok)) {
            return $tok;
        }
        set_transient('sb_ytm_tok', $tok, 50 * MINUTE_IN_SECONDS);
    }
    $u = sb_share_stream('POST', 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set?uploadType=media&videoId=' . rawurlencode($yt_id),
        array('Authorization: Bearer ' . $tok, 'Content-Type: image/jpeg'), $file);
    $j = json_decode($u['b'], true);
    if ($u['c'] >= 400 || empty($j['items'])) {
        return new WP_Error('sb_th', 'ইউটিউবে থাম্বনেইল বসেনি: ' . ($j['error']['message'] ?? ($u['e'] ?: 'HTTP ' . $u['c'])));
    }
    return true;
}

/** the whole step used by the share job: build, set, bill. Returns the text kept in the step log. */
function sb_thumb_step($c, $e, $dr, $yt_id) {
    @set_time_limit(300);
    $b = sb_thumb_build($c, $e, $dr);
    if (is_wp_error($b)) {
        return 'Failed: ' . $b->get_error_message();
    }
    list($file, $real, $model, $hook) = $b;
    $r = sb_thumb_set($c, $yt_id, $file);
    @unlink($file);
    if (is_wp_error($r)) {
        return 'Failed: ' . $r->get_error_message();
    }
    if ($real > 0) {
        sb_share_bill($real, $model, 'share_vid');
    }
    return 'ok:' . $hook;
}

/** admin: make a thumbnail for a video that is already queued (preview first, then set) */
add_action('wp_ajax_sb_thumb_run', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('অনুমতি নেই।');
    }
    $id = preg_replace('/[^\d_]/', '', (string) ($_POST['id'] ?? ''));
    $q  = sb_share_queue();
    if (!isset($q[$id]) || 'video' !== ($q[$id]['kind'] ?? '')) {
        wp_send_json_error('এই ভিডিও লাইনে নেই।');
    }
    $e   = $q[$id];
    $yt  = (string) ($e['steps']['yt'] ?? '');
    $yid = 0 === strpos($yt, 'ok:') ? substr($yt, 3) : '';
    $c   = sb_share_cfg();
    $b   = sb_thumb_build($c, $e, (array) ($e['draft'] ?? array()));
    if (is_wp_error($b)) {
        wp_send_json_error($b->get_error_message());
    }
    list($file, $real, $model, $hook) = $b;
    $prev = '';
    $up   = wp_upload_bits('sb-thumb-preview-' . preg_replace('/\W/', '', $id) . '.jpg', null, (string) file_get_contents($file));   // phpcs:ignore
    if (empty($up['error'])) {
        $prev = $up['url'];
    }
    $msg = 'preview';
    if (!empty($_POST['set']) && '' !== $yid) {
        $r = sb_thumb_set($c, $yid, $file);
        $msg = is_wp_error($r) ? $r->get_error_message() : 'set';
        if (!is_wp_error($r)) {
            sb_share_set($id, array('steps' => array_merge((array) $e['steps'], array('thumb' => 'ok:' . $hook))));
        }
    }
    if ($real > 0) {
        sb_share_bill($real, $model, 'share_vid');
    }
    @unlink($file);
    wp_send_json_success(array('url' => $prev, 'hook' => $hook, 'result' => $msg, 'yt' => $yid, 'real' => $real));
});
