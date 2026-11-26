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
    $uri = '';
    $v   = null;
    if (!empty($e['link'])) {   // link sent by the owner: the preview picture of the Facebook page is the frame
        $uri = (string) ($e['img'] ?? '');
        $e['vid'] = substr(md5((string) $e['link']), 0, 12);
    } else {
        $v = sb_share_graph($c, $e['vid'], array('fields' => 'thumbnails{uri,is_preferred,width,height},picture'), $tok);
    }
    if (null !== $v && !is_wp_error($v)) {
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
    $path = $dir . 'sbthumb-frame-' . preg_replace('/\W/', '', (string) ($e['vid'] ?: md5((string) ($e['link'] ?? '')))) . '.jpg';
    $r    = wp_remote_get($uri, array('timeout' => 60, 'stream' => true, 'filename' => $path));
    if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r) || !is_file($path) || filesize($path) < 2000) {
        @unlink($path);
        return new WP_Error('sb_th', 'ভিডিওর প্রিভিউ ছবি নামানো যায়নি।');
    }
    return $path;
}

/** Event logos: when the video talks about an event (Spelling Star, Khude Star …) that event's logo is used. Lines: "name ; words separated by | ; media id". */
function sb_ev_logos() {
    $o = get_option('sb_ev_logos', null);
    if (!is_array($o)) {
        $o = array(
            array('name' => 'স্পেলিং স্টার', 'words' => 'স্পেলিং স্টার|স্পেলিং স্টার|spelling star|spellingstar|SPL', 'att' => 29447),
            array('name' => 'ক্ষুদে স্টার', 'words' => 'ক্ষুদে স্টার|খুদে স্টার|khude star|khudestar|kshude', 'att' => 31558),
        );
    }
    return array_values(array_filter($o, function ($l) {
        return !empty($l['att']) && get_attached_file((int) $l['att']);
    }));
}

function sb_ev_logos_text() {
    $o = get_option('sb_ev_logos', null);
    $o = is_array($o) ? $o : sb_ev_logos();
    return implode("\n", array_map(function ($l) {
        return $l['name'] . ' ; ' . $l['words'] . ' ; ' . (int) $l['att'];
    }, $o));
}

function sb_ev_logos_save($text) {
    $out = array();
    foreach (preg_split('/\R/u', (string) $text) as $ln) {
        $p = array_map('trim', explode(';', $ln));
        if (count($p) >= 3 && '' !== $p[0] && '' !== $p[1] && (int) $p[2] > 0) {
            $out[] = array('name' => sanitize_text_field($p[0]), 'words' => sanitize_text_field($p[1]), 'att' => (int) $p[2]);
        }
    }
    update_option('sb_ev_logos', $out, false);
}

function sb_thumb_event($texts) {
    $hay = mb_strtolower(implode(' ', $texts));
    foreach (sb_ev_logos() as $l) {
        foreach (array_filter(array_map('trim', explode('|', $l['words']))) as $w) {
            if (false !== mb_strpos($hay, mb_strtolower($w))) {
                return $l;
            }
        }
    }
    return null;
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
    $all  = function_exists('sb_logos') ? sb_logos() : array();
    $hay  = array((string) ($e['msg'] ?? ''), (string) ($dr['yt_title'] ?? ''), (string) ($dr['yt_desc'] ?? ''), $hook);
    $ev   = sb_thumb_event($hay);
    $txt_all = mb_strtolower(implode(' ', $hay));
    $order   = array('family', 'school');
    if (preg_match('/ইসলামিক|ইসলামী|ইসলাম শিক্ষা|মাদ্রাসা|মাদরাসা|কুরআন|কোরআন|হিফজ|নাযেরা|দ্বীনি|islamic|madrasa|quran|hifz/u', $txt_all) && isset($all['islamic'])) {
        $order = array('islamic', 'family', 'school');
    }
    $logo_files = array();   // logos are laid on top by the site itself (exact, whole, never cropped)
    if ($ev && ($f = get_attached_file((int) $ev['att']))) {
        $logo_files['ev'] = $f;
    }
    foreach ($order as $k) {
        if (isset($all[$k]) && ($f = get_attached_file((int) $all[$k]['att']))) {
            $logo_files['inst'] = $f;
            break;
        }
    }
    // the thumbnail has the video's own shape: wide video -> 16:9, square -> 1:1, tall -> 9:16
    $tall = false;
    $gi = @getimagesize($frame);
    $ratio = ($gi && $gi[1] > 0) ? $gi[0] / $gi[1] : 1.7778;
    if ($ratio >= 1.3) {
        $gen = '1536x1024'; $W = 1280; $H = 720; $shape = 'wide 16:9 landscape';
    } elseif ($ratio <= 0.77) {
        $gen = '1536x1024'; $W = 1280; $H = 720; $shape = 'wide 16:9 landscape'; $tall = true;   // YouTube shows every thumbnail as 16:9, so a tall video gets a 16:9 one built around its tall picture
    } else {
        $gen = '1024x1024'; $W = 1080; $H = 1080; $shape = 'square 1:1';
    }
    $idea   = trim((string) ($dr['thumb_idea'] ?? ''));
    $prompt = 'Create a scroll-stopping YouTube thumbnail, ' . $shape . ' shape. Use reference image 1 — a real frame from the video — as the base: keep the real people, place and clothes exactly as they are, but make it brighter, sharper and richer in colour, with strong contrast, a clean uncluttered composition and a clear focal point' . ('' !== $idea ? ' (direction from the video analysis: ' . $idea . ')' : '') . '. '
        . 'Add ONE huge bold headline in Bangla, exactly this text and nothing else: "' . $hook . '". Use a thick, clean Bengali typeface, white or bright yellow on a solid dark or red band (or with a heavy dark outline), so it stays readable even when the thumbnail is tiny; correct Bengali conjunct letters, no spelling changes. '
        . ('wide 16:9 landscape' === $shape ? 'The picture will be cropped to 16:9 (the top and bottom 15% are cut off), so keep the headline and the main subject fully inside the central 70% of the height. ' : '')
        . ($tall ? 'The reference frame is a tall (portrait) video picture: put it, sharp and large, in the centre of the 16:9 canvas, and fill the sides with the same picture enlarged, blurred and darkened as a background; place the headline over the lower part of the centre picture or on a band across the bottom. ' : '')
        . 'Keep the headline at least 6% away from every edge. Keep the top-left and top-right corners (each about 28% of the width and 24% of the height) free of faces and text: logos will be placed there later. '
        . 'Make it look professional and eye-catching: vivid saturated colours, glowing edge light around the main subject, crisp edges, a balanced layout that makes a viewer want to click. Do NOT draw any logo, watermark, frame or border, and no other text.';
    $model = array_keys(sb_img_models())[0];
    $res   = sb_img_call($model, $prompt, $gen, 'medium', $refs);
    @unlink($frame);
    if (is_wp_error($res)) {
        return $res;
    }
    list($bytes, $usage) = $res;
    $key = preg_replace('/\W/', '', (string) ($e['vid'] ?: md5((string) ($e['link'] ?? ''))));
    $raw = $dir . 'sbthumb-raw-' . $key . '.webp';
    file_put_contents($raw, $bytes);   // phpcs:ignore
    $ed = wp_get_image_editor($raw);
    if (is_wp_error($ed)) {
        @unlink($raw);
        return $ed;
    }
    $ed->resize($W, $H, true);
    $ed->set_quality(90);
    $mid = $dir . 'sbthumb-mid-' . $key . '.png';
    $sv  = $ed->save($mid, 'image/png');
    @unlink($raw);
    if (is_wp_error($sv) || !is_file($mid)) {
        @unlink($mid);
        return new WP_Error('sb_th', 'থাম্বনেইল ফাইল তৈরি করা যায়নি।');
    }
    $out = $dir . 'sbthumb-' . $key . '.jpg';
    $ok  = sb_thumb_compose($mid, $out, $W, $H, $logo_files);
    @unlink($mid);
    if (!$ok || !is_file($out) || filesize($out) > 1900000) {
        @unlink($out);
        return new WP_Error('sb_th', 'থাম্বনেইল ফাইল তৈরি করা যায়নি।');
    }
    return array($out, sb_img_cost($model, $usage), $model, $hook);
}

/** lays the logos on the finished picture with GD: event logo top-left (large), institution logo top-right on a white rounded badge. Both always whole, inside the margins. */
function sb_thumb_compose($src, $dst, $W, $H, $logos) {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return false;
    }
    $base = @imagecreatefromstring((string) file_get_contents($src));   // phpcs:ignore
    if (!$base) {
        return false;
    }
    imagealphablending($base, true);
    $load = function ($path) {
        $b = is_file($path) ? @file_get_contents($path) : false;   // phpcs:ignore
        return $b ? @imagecreatefromstring($b) : false;
    };
    $fit = function ($im, $maxw, $maxh) {
        $w = imagesx($im);
        $h = imagesy($im);
        $k = min($maxw / $w, $maxh / $h);
        $nw = max(1, (int) round($w * $k));
        $nh = max(1, (int) round($h * $k));
        $t = imagecreatetruecolor($nw, $nh);
        imagealphablending($t, false);
        imagesavealpha($t, true);
        imagefill($t, 0, 0, imagecolorallocatealpha($t, 0, 0, 0, 127));
        imagecopyresampled($t, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $t;
    };
    $m = (int) round(min($W, $H) * 0.035);   // margin from the edges
    if (!empty($logos['ev']) && ($im = $load($logos['ev']))) {
        $t = $fit($im, (int) round($W * 0.27), (int) round($H * 0.24));
        imagealphablending($base, true);
        imagecopy($base, $t, $m, $m, 0, 0, imagesx($t), imagesy($t));
        imagedestroy($t);
        imagedestroy($im);
    }
    if (!empty($logos['inst']) && ($im = $load($logos['inst']))) {
        $pad = (int) round(min($W, $H) * 0.012);
        $t   = $fit($im, (int) round($W * 0.22), (int) round($H * 0.14));
        $bw  = imagesx($t) + 2 * $pad;
        $bh  = imagesy($t) + 2 * $pad;
        $x   = $W - $m - $bw;
        $y   = $m;
        $white = imagecolorallocate($base, 255, 255, 255);
        $r = (int) round(min($bw, $bh) * 0.22);
        imagefilledrectangle($base, $x + $r, $y, $x + $bw - $r, $y + $bh, $white);
        imagefilledrectangle($base, $x, $y + $r, $x + $bw, $y + $bh - $r, $white);
        foreach (array(array($x + $r, $y + $r), array($x + $bw - $r, $y + $r), array($x + $r, $y + $bh - $r), array($x + $bw - $r, $y + $bh - $r)) as $cc) {
            imagefilledellipse($base, $cc[0], $cc[1], 2 * $r, 2 * $r, $white);
        }
        imagecopy($base, $t, $x + $pad, $y + $pad, 0, 0, imagesx($t), imagesy($t));
        imagedestroy($t);
        imagedestroy($im);
    }
    $ok = false;
    foreach (array(90, 84, 78, 70) as $q) {
        imagejpeg($base, $dst, $q);
        if (is_file($dst) && filesize($dst) <= 1900000) {
            $ok = true;
            break;
        }
    }
    imagedestroy($base);
    return $ok;
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
    wp_send_json_success(array('url' => $prev, 'hook' => $hook, 'result' => $msg, 'yt' => $yid));
});
