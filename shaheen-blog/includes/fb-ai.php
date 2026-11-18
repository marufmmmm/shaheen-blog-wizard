<?php
/**
 * Facebook AI comment replies (phase 1: draft mode).
 * The AI writes a draft for a comment; the owner presses OK / edits / Not OK; every verdict is kept in a reference library
 * that later drafts learn from. The AI answers only from: the post caption, what Gemini saw in the photo/video (seen ONCE, kept),
 * the branch directory, the owner's approved references and the head-office contacts. If none of these has the answer it writes nothing.
 * Models: 70% Terra, 30% Kimi (shuffled bag), through the Site Agent's single AI entry point (csa_claude), so usage lands in the USD ledger.
 */
if (!defined('ABSPATH')) {
    exit;
}

/** Head-office contacts used when no branch can be matched (editable via option sb_fbai_hq). */
function sb_fbai_hq() {
    $o = trim((string) get_option('sb_fbai_hq', ''));
    return '' !== $o ? $o : 'কর্পোরেট অফিস: হাউজ #৩৬, রোড #২৬, সেক্টর #০৭, উত্তরা, ঢাকা। ফোন: +৮৮০ ১৬২২ ২২২ ১১১, +৮৮০ ১৭৮৫ ০০ ৩৩ ৪৪। ইমেইল: corporate@shaheen.edu.bd। ওয়েবসাইট: shaheen.edu.bd';
}

/** Is this comment only thanks / prayer / praise / emoji (no reply needed)? Returns '' when it needs a look, else the reason. */
function sb_fbai_social_reason($t) {
    $t = trim(wp_strip_all_tags((string) $t));
    $plain = trim(preg_replace('/[\p{P}\p{S}\p{Z}\s]+/u', ' ', $t));
    if ('' === $plain) {
        return 'শুধু ইমোজি/স্টিকার';
    }
    $q = '/[?？]|কত|কিভাবে|কীভাবে|কোথায়|কখন|কবে|কেন|কোন|জানতে|জানান|জানাবেন|দাম|মূল্য|ফি(?![\p{L}\p{M}])|বেতন|খরচ|ভর্তি|ঠিকানা|ফোন|নাম্বার|নম্বর|যোগাযোগ|নিয়োগ|চাকরি|চাকুরি|সার্কুলার|বই|অর্ডার|কিনতে|শাখা|ক্লাস|ফলাফল|রেজাল্ট|(?<![\p{L}\p{M}])কি(?![\p{L}\p{M}])|(?<![\p{L}\p{M}])কী(?![\p{L}\p{M}])|\b(price|how|where|when|why|admission|fee|fees|address|phone|number|contact|job|apply|book|order|branch|class|result|cost|can|is|do|does)\b/iu';
    if (preg_match($q, $t)) {
        return '';
    }
    return mb_strlen($plain) < 90 ? 'ধন্যবাদ/দোয়া/প্রশংসা ধরনের কমেন্ট' : '';
}

function sb_fbai_tokens($s) {
    $s = mb_strtolower(wp_strip_all_tags((string) $s));
    $a = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique(array_filter((array) $a, function ($w) { return mb_strlen($w) >= 2; })));
}

/** The nearest saved references: [ok/edit examples, bad examples]. */
function sb_fbai_refs_for($q, $n = 3) {
    $qt = sb_fbai_tokens($q);
    $good = array();
    $bad = array();
    if (!$qt) {
        return array($good, $bad);
    }
    foreach ((array) get_option('sb_fbai_refs', array()) as $r) {
        $rt = sb_fbai_tokens($r['q'] ?? '');
        if (!$rt) {
            continue;
        }
        $inter = count(array_intersect($qt, $rt));
        $sim = $inter / max(1, count(array_unique(array_merge($qt, $rt))));
        if ($sim < 0.2) {
            continue;
        }
        $r['sim'] = $sim;
        if (($r['s'] ?? '') === 'bad') {
            $bad[] = $r;
        } else {
            $good[] = $r;
        }
    }
    $cmp = function ($a, $b) { return $b['sim'] <=> $a['sim']; };
    usort($good, $cmp);
    usort($bad, $cmp);
    return array(array_slice($good, 0, $n), array_slice($bad, 0, 2));
}

/** Branches whose name / district / division appears in the comment (max 3). */
function sb_fbai_branches_for($text) {
    if (!function_exists('csa_br_list')) {
        return array();
    }
    $t = mb_strtolower((string) $text);
    $out = array();
    foreach (csa_br_list() as $b) {
        $keys = array_filter(array(trim(wp_strip_all_tags((string) ($b['name'] ?? ''))), trim((string) ($b['district'] ?? ''))));
        foreach ($keys as $k) {
            $k = mb_strtolower(trim(preg_replace('/\s*(শাখা|ক্যাম্পাস|branch)\s*$/iu', '', $k)));
            if (mb_strlen($k) >= 3 && false !== mb_strpos($t, $k)) {
                $ph = $b['phones'] ?? array();
                $ph = is_array($ph) ? implode(', ', array_filter($ph)) : (string) $ph;
                $out[] = trim(wp_strip_all_tags((string) $b['name'])) . ' — ঠিকানা: ' . ($b['addr'] ?? '') . ($ph ? ' — ফোন: ' . $ph : '');
                break;
            }
        }
        if (count($out) >= 3) {
            break;
        }
    }
    return $out;
}

/** Post caption + what Gemini saw in its photo/video. Seen once, then kept for good. */
function sb_fbai_post_ctx($c, $tok, $post_id) {
    $key   = preg_replace('/[^\w_]/', '', (string) $post_id);
    $cache = get_option('sb_fbai_ctx', array());
    $cache = is_array($cache) ? $cache : array();
    if (isset($cache[$key]) && is_array($cache[$key])) {
        return $cache[$key];
    }
    $p = sb_share_graph($c, $key, array('fields' => 'message,full_picture,permalink_url,attachments{type,title,description,media_type,target{id}}'), $tok);
    $ctx = array('caption' => '', 'media' => '', 'kind' => 'text', 'url' => '', 't' => time());
    if (!is_wp_error($p)) {
        $ctx['caption'] = trim((string) ($p['message'] ?? ''));
        $ctx['url'] = esc_url_raw((string) ($p['permalink_url'] ?? ''));
        if ('' !== $ctx['url'] && 0 === strpos($ctx['url'], '/')) {
            $ctx['url'] = 'https://www.facebook.com' . $ctx['url'];
        }
        $at = (array) ($p['attachments']['data'][0] ?? array());
        if ('' === $ctx['caption']) {
            $ctx['caption'] = trim(trim((string) ($at['title'] ?? '')) . ' ' . trim((string) ($at['description'] ?? '')));
        }
        $isv = preg_match('/video/i', (string) ($at['type'] ?? '') . ' ' . (string) ($at['media_type'] ?? ''));
        $vid = preg_replace('/\D/', '', (string) ($at['target']['id'] ?? ''));
        $img = (string) ($p['full_picture'] ?? '');
        if ($isv && '' !== $vid) {
            $ctx['kind'] = 'video';
            $ctx['media'] = sb_fbai_see($c, $tok, $vid, '', true);
        } elseif ('' !== $img) {
            $ctx['kind'] = 'photo';
            $ctx['media'] = sb_fbai_see($c, $tok, '', $img, false);
        }
    }
    if (!isset($ctx['media'])) {
        $ctx['media'] = '';
    }
    // Keep only when Gemini succeeded or there was nothing to look at, so a failed look is tried again next time.
    if ('' !== $ctx['media'] || 'text' === $ctx['kind']) {
        $cache[$key] = $ctx;
        update_option('sb_fbai_ctx', array_slice($cache, -300, null, true), false);
    }
    return $ctx;
}

/** One look with Gemini at a video (by id) or a picture (by url). Returns a short Bangla/English summary or ''. */
function sb_fbai_see($c, $tok, $vid, $img_url, $video) {
    if (empty($c['gem_key'])) {
        return '';
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    @set_time_limit(240);
    $src = $img_url;
    if ($video) {
        $v = sb_share_graph($c, $vid, array('fields' => 'source,length'), $tok);
        if (is_wp_error($v) || empty($v['source']) || (float) ($v['length'] ?? 0) > 600) {
            return '';   // not readable, or longer than 10 minutes (too costly to watch)
        }
        $src = (string) $v['source'];
        $head = wp_remote_head($src, array('timeout' => 20));
        $len  = is_wp_error($head) ? 0 : (int) wp_remote_retrieve_header($head, 'content-length');
        if ($len > 90 * 1024 * 1024) {
            return '';
        }
    }
    $tmp = download_url($src, 200);
    if (is_wp_error($tmp)) {
        return '';
    }
    $sys = 'You look at a Facebook post media of a Bangladeshi school network (Shaheen Education Family) so that staff can answer viewers\' comments. Describe neutrally what it shows and every concrete detail that a viewer might ask about: dates, times, places, fees, offers, names, phone numbers, steps to apply, spoken or written words. Do not invent anything not visible or audible. Reply as JSON: {"summary":"..."} in the language of the media (Bangla if Bangla), at most 900 characters.';
    $r = sb_share_gemini($c, $sys, 'Describe this media.', $tmp, (bool) $video);
    @unlink($tmp);
    return is_array($r) ? trim(mb_substr((string) ($r['summary'] ?? ''), 0, 1200)) : '';
}

/** 7 of 10 replies on Terra, 3 on Kimi, in a shuffled bag (unpredictable order, exact ratio). */
function sb_fbai_pick_model() {
    $bag = get_option('sb_fbai_bag', array());
    if (!is_array($bag) || !$bag) {
        $bag = array_merge(array_fill(0, 7, 'gpt-terra'), array_fill(0, 3, 'kimi-k31'));
        for ($i = count($bag) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $t = $bag[$i];
            $bag[$i] = $bag[$j];
            $bag[$j] = $t;
        }
    }
    $pick = array_pop($bag);
    update_option('sb_fbai_bag', $bag, false);
    if ('kimi-k31' === $pick && (!function_exists('csa_provider_key') || '' === csa_provider_key('moonshot'))) {
        $pick = 'gpt-terra';   // no Kimi key: Terra instead
    }
    return $pick;
}

function sb_fbai_system() {
    return "তুমি শাহীন শিক্ষা পরিবারের (শাহীন স্কুল এন্ড ক্যাডেট কোচিং) ফেসবুক পেজের কমেন্টের উত্তর-লেখক। একজন মানুষ স্টাফের মতো ভদ্র, উষ্ণ ও সংক্ষিপ্ত (১–৩ বাক্য) উত্তর লেখো।\n"
        . "কঠোর নিয়ম:\n"
        . "১. উত্তর শুধু নিচে দেওয়া তথ্য থেকে: পোস্টের ক্যাপশন, ছবি/ভিডিওতে যা দেখা গেছে, শাখার ঠিকানা-ফোন, মালিকের অনুমোদিত আগের উত্তর (রেফারেন্স), প্রধান কার্যালয়ের যোগাযোগ। এর বাইরে কোনো তথ্য (ফি, বেতন, তারিখ, শর্ত, সংখ্যা) বানাবে না।\n"
        . "২. ভর্তি, ফি, বেতন বা তারিখের নিশ্চিত তথ্য না থাকলে: যে শাখার কথা বলা হয়েছে তার ঠিকানা ও ফোন নম্বর দাও। শাখা বোঝা না গেলে বিনয়ের সাথে এলাকা/শাখার নাম জানতে চাও এবং প্রধান কার্যালয়ের যোগাযোগ দাও। ভর্তি চাইলে সুন্দর করে আহ্বান জানাও।\n"
        . "৩. যে প্রশ্নের উত্তর দেওয়ার মতো কোনো তথ্য নেই এবং শাখা/যোগাযোগ দিয়েও উত্তর হয় না, সেখানে reply খালি রাখো এবং why-তে কারণ লেখো।\n"
        . "৪. কমেন্টের ভাষায় উত্তর দাও (বাংলা, ইংরেজি বা বাংলিশ)। প্রতিবার ভিন্ন শব্দে লেখো; নিচের 'সাম্প্রতিক উত্তর' গুলোর মতো শুরু বা বাক্য হুবহু আবার ব্যবহার করবে না।\n"
        . "৫. প্রতিশ্রুতি দেবে না, অভিযোগের প্রতি সহানুভূতি দেখাও কিন্তু সমাধান বানাবে না। অতিরিক্ত ইমোজি নয় (সর্বোচ্চ একটি)।\n"
        . "৬. 'না-ঠিক' দেখানো উদাহরণের ধরনে লিখবে না।\n"
        . "আউটপুট শুধু JSON: {\"reply\":\"...\",\"kind\":\"admission|fee|address|job|book|other\",\"why\":\"reply খালি হলে কারণ\"}";
}

add_action('wp_ajax_sb_fbai_draft', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No permission.');
    }
    if (!function_exists('csa_claude')) {
        wp_send_json_error('AI engine (Site Agent) is not loaded.');
    }
    $pid  = preg_replace('/\D/', '', (string) ($_POST['page'] ?? ''));
    $tok  = function_exists('sb_fbin_token') ? sb_fbin_token($pid) : '';
    $post = preg_replace('/[^\w_]/', '', (string) ($_POST['post'] ?? ''));
    $msg  = mb_substr(sanitize_textarea_field(wp_unslash((string) ($_POST['msg'] ?? ''))), 0, 1000);
    if ('' === $tok || '' === $post) {
        wp_send_json_error('Missing page or post.');
    }
    $why = sb_fbai_social_reason($msg);
    if ('' !== $why) {
        wp_send_json_success(array('skip' => true, 'why' => $why));
    }
    $c   = sb_share_cfg();
    $ctx = sb_fbai_post_ctx($c, $tok, $post);
    $br  = sb_fbai_branches_for($msg . ' ' . $ctx['caption']);
    list($good, $bad) = sb_fbai_refs_for($msg);
    $recent = array_slice((array) get_option('sb_fbai_recent', array()), 0, 6);

    $u  = "--- পোস্টের ক্যাপশন ---\n" . ('' !== $ctx['caption'] ? mb_substr($ctx['caption'], 0, 1800) : '(ক্যাপশন নেই)') . "\n";
    $u .= "\n--- পোস্টের " . ('video' === $ctx['kind'] ? 'ভিডিওতে' : ('photo' === $ctx['kind'] ? 'ছবিতে' : 'মিডিয়ায়')) . " যা দেখা/শোনা গেছে ---\n" . ('' !== $ctx['media'] ? $ctx['media'] : '(কিছু জানা নেই)') . "\n";
    $u .= "\n--- মিলে যাওয়া শাখা ---\n" . ($br ? implode("\n", $br) : '(কমেন্টে নির্দিষ্ট শাখা বোঝা যায়নি)') . "\n";
    $u .= "\n--- প্রধান কার্যালয় ---\n" . sb_fbai_hq() . "\n";
    if ($good) {
        $u .= "\n--- মালিকের অনুমোদিত আগের উত্তর (রেফারেন্স) ---\n";
        foreach ($good as $g) {
            $u .= 'প্রশ্ন: ' . mb_substr($g['q'], 0, 300) . "\nউত্তর: " . mb_substr($g['a'], 0, 500) . "\n";
        }
    }
    if ($bad) {
        $u .= "\n--- এভাবে লিখবে না (মালিক বাতিল করেছেন) ---\n";
        foreach ($bad as $g) {
            $u .= 'প্রশ্ন: ' . mb_substr($g['q'], 0, 200) . "\nবাতিল উত্তর: " . mb_substr($g['a'], 0, 300) . (!empty($g['note']) ? "\nকারণ: " . mb_substr($g['note'], 0, 200) : '') . "\n";
        }
    }
    if ($recent) {
        $u .= "\n--- সাম্প্রতিক উত্তর (এগুলোর মতো লিখবে না) ---\n- " . implode("\n- ", array_map(function ($x) { return mb_substr($x, 0, 160); }, $recent)) . "\n";
    }
    $u .= "\n=== কমেন্ট ===\n" . $msg . "\n\nশুধু JSON দাও।";

    $key = sb_fbai_pick_model();
    $res = csa_claude(array(array('role' => 'user', 'content' => $u)), sb_fbai_system(), array('task' => 'chat', 'model_key' => $key, 'max_tokens' => 700, 'feature' => 'fb_reply'));
    if (is_wp_error($res) && 'gpt-terra' !== $key) {
        $key = 'gpt-terra';   // Kimi failed: Terra
        $res = csa_claude(array(array('role' => 'user', 'content' => $u)), sb_fbai_system(), array('task' => 'chat', 'model_key' => $key, 'max_tokens' => 700, 'feature' => 'fb_reply'));
    }
    if (is_wp_error($res)) {
        wp_send_json_error($res->get_error_message());
    }
    $txt = '';
    foreach ((array) ($res['content'] ?? array()) as $b) {
        $txt .= (string) ($b['text'] ?? '');
    }
    $j = null;
    if (preg_match('/\{.*\}/su', $txt, $m)) {
        $j = json_decode($m[0], true);
    }
    $reply = is_array($j) ? trim((string) ($j['reply'] ?? '')) : '';
    $label = function_exists('csa_model') && csa_model((string) ($res['_model'] ?? $key)) ? csa_model((string) ($res['_model'] ?? $key))['label'] : $key;
    $info  = $label . ' · ' . ('' !== $ctx['caption'] ? 'ক্যাপশন ✔' : 'ক্যাপশন নেই') . ' · ' . ('' !== $ctx['media'] ? ('video' === $ctx['kind'] ? 'ভিডিও দেখা ✔ (সংরক্ষিত)' : 'ছবি দেখা ✔') : ('text' === $ctx['kind'] ? 'মিডিয়া নেই' : 'মিডিয়া দেখা যায়নি')) . ' · শাখা ' . count($br) . 'টি · রেফারেন্স ' . count($good) . 'টি';
    wp_send_json_success(array('skip' => false, 'reply' => $reply, 'kind' => is_array($j) ? (string) ($j['kind'] ?? 'other') : 'other',
        'why' => is_array($j) ? (string) ($j['why'] ?? '') : 'AI-র উত্তর বোঝা যায়নি', 'info' => $info, 'url' => (string) ($ctx['url'] ?? ''), 'model' => $label));
});

/** The owner's verdict: ok (sent as drafted), edit (sent after changing / written by hand), bad (not ok). Every entry is kept (with the post link) in the memory page and feeds later drafts. */
add_action('wp_ajax_sb_fbai_feedback', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No permission.');
    }
    $g = function ($k, $n) { return mb_substr(sanitize_textarea_field(wp_unslash((string) ($_POST[$k] ?? ''))), 0, $n); };
    $v = (string) ($_POST['verdict'] ?? '');
    $q = $g('q', 600);
    $a = $g('a', 900);
    if (!in_array($v, array('ok', 'edit', 'bad'), true) || '' === $q) {
        wp_send_json_error('Bad request.');
    }
    $refs = get_option('sb_fbai_refs', array());
    $refs = is_array($refs) ? $refs : array();
    $refs[] = array('id' => substr(md5(uniqid('', true)), 0, 10), 'q' => $q, 'a' => $a, 'draft' => $g('draft', 900), 's' => $v, 'note' => $g('note', 300), 't' => time(),
        'post' => preg_replace('/[^\w_]/', '', (string) ($_POST['post'] ?? '')), 'cid' => preg_replace('/[^\w_]/', '', (string) ($_POST['cid'] ?? '')),
        'url' => esc_url_raw((string) ($_POST['url'] ?? '')), 'model' => $g('model', 40), 'from' => $g('from', 60));
    update_option('sb_fbai_refs', array_slice($refs, -600), false);
    if ('bad' !== $v && '' !== $a) {
        $rc = (array) get_option('sb_fbai_recent', array());
        array_unshift($rc, $a);
        update_option('sb_fbai_recent', array_slice($rc, 0, 6), false);
    }
    wp_send_json_success(array('refs' => count($refs)));
});

/** Memory page actions: edit an answer / change its verdict / delete / add by hand. */
add_action('wp_ajax_sb_fbai_mem', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No permission.');
    }
    $refs = get_option('sb_fbai_refs', array());
    $refs = is_array($refs) ? $refs : array();
    $what = (string) ($_POST['what'] ?? '');
    $id   = preg_replace('/\W/', '', (string) ($_POST['id'] ?? ''));
    $a    = mb_substr(sanitize_textarea_field(wp_unslash((string) ($_POST['a'] ?? ''))), 0, 900);
    $q    = mb_substr(sanitize_textarea_field(wp_unslash((string) ($_POST['q'] ?? ''))), 0, 600);
    if ('add' === $what) {
        if ('' === $q || '' === $a) {
            wp_send_json_error('প্রশ্ন ও উত্তর দুটোই লিখুন।');
        }
        $refs[] = array('id' => substr(md5(uniqid('', true)), 0, 10), 'q' => $q, 'a' => $a, 's' => 'ok', 'note' => '', 't' => time(), 'post' => '', 'cid' => '', 'url' => '', 'model' => 'হাতে লেখা', 'from' => '');
    } else {
        $hit = false;
        foreach ($refs as $i => $r) {
            if (($r['id'] ?? '') !== $id) {
                continue;
            }
            $hit = true;
            if ('delete' === $what) {
                unset($refs[$i]);
            } elseif ('save' === $what) {
                $refs[$i]['a'] = $a;
                $st = (string) ($_POST['s'] ?? '');
                if (in_array($st, array('ok', 'edit', 'bad'), true)) {
                    $refs[$i]['s'] = $st;
                }
            }
            break;
        }
        if (!$hit) {
            wp_send_json_error('পাওয়া যায়নি।');
        }
    }
    update_option('sb_fbai_refs', array_values($refs), false);
    wp_send_json_success('ok');
});

/** Tab "উত্তরের মেমোরি": every answer given or taught, with links to the post and the comment. */
function sb_page_fbmemory() {
    $refs = array_reverse((array) get_option('sb_fbai_refs', array()));
    $ctx  = (array) get_option('sb_fbai_ctx', array());
    $cnt  = array('ok' => 0, 'edit' => 0, 'bad' => 0);
    foreach ($refs as $r) {
        $k = $r['s'] ?? 'ok';
        $cnt[$k] = ($cnt[$k] ?? 0) + 1;
    }
    $rows = array();
    foreach ($refs as $r) {
        $cl = '';
        if (!empty($r['url']) && !empty($r['cid'])) {
            $part = false !== strpos($r['cid'], '_') ? substr($r['cid'], strrpos($r['cid'], '_') + 1) : $r['cid'];
            $cl = $r['url'] . (false === strpos($r['url'], '?') ? '?' : '&') . 'comment_id=' . rawurlencode($part);
        }
        $rows[] = array('id' => $r['id'] ?? '', 'q' => $r['q'] ?? '', 'a' => $r['a'] ?? '', 'draft' => $r['draft'] ?? '', 's' => $r['s'] ?? 'ok', 'note' => $r['note'] ?? '', 't' => (int) ($r['t'] ?? 0),
            'url' => $r['url'] ?? '', 'cl' => $cl, 'model' => $r['model'] ?? '', 'from' => $r['from'] ?? '');
    }
    ?>
    <div class="wrap">
        <h1>🧠 উত্তরের মেমোরি</h1>
        <p class="description">AI যত উত্তর দিয়েছে বা আপনি শিখিয়েছেন, সব এখানে। প্রতিটির সাথে পোস্ট ও কমেন্টের লিংক আছে। উত্তর বদলে দিলে বা বাতিল করলে AI পরের বার সেটাই মানবে। এখান থেকেই নতুন প্রশ্ন-উত্তর শেখানো যায়।
        পোস্টের ছবি/ভিডিও AI একবার দেখেছে এমন পোস্ট: <b><?php echo (int) count($ctx); ?></b>টি (আবার দেখে না)।</p>
        <p><b>মোট <?php echo (int) count($rows); ?></b> · ✔ ঠিক <?php echo (int) $cnt['ok']; ?> · ✏ সম্পাদিত <?php echo (int) $cnt['edit']; ?> · ✖ বাতিল <?php echo (int) $cnt['bad']; ?></p>
        <p><select id="fm2-f"><option value="">সব</option><option value="ok">✔ ঠিক</option><option value="edit">✏ সম্পাদিত</option><option value="bad">✖ বাতিল</option></select>
            <input type="search" id="fm2-s" placeholder="খুঁজুন…" style="min-width:240px"> <span id="fm2-m" class="description"></span></p>
        <details style="margin-bottom:12px"><summary><b>➕ নতুন প্রশ্ন-উত্তর শেখান</b></summary>
            <div style="max-width:640px;margin-top:6px"><textarea id="fm2-nq" rows="2" style="width:100%" placeholder="প্রশ্ন (যেমন: ভর্তি কবে শুরু?)"></textarea><textarea id="fm2-na" rows="3" style="width:100%;margin-top:4px" placeholder="সঠিক উত্তর"></textarea><button class="button button-primary" id="fm2-add">যোগ করুন</button></div></details>
        <div id="fm2-l"></div>
    </div>
    <script>
    (function(){var $=function(s){return document.querySelector(s);},aj=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nn=<?php echo wp_json_encode(wp_create_nonce('sb_admin')); ?>,D=<?php echo wp_json_encode($rows); ?>;
        function el(t,css,txt){var e=document.createElement(t);if(css){e.style.cssText=css;}if(txt!==undefined){e.textContent=txt;}return e;}
        function post(x){return fetch(aj,{method:'POST',credentials:'same-origin',body:new URLSearchParams(Object.assign({nonce:nn},x))}).then(function(r){return r.json();});}
        var lab={ok:'✔ ঠিক',edit:'✏ সম্পাদিত',bad:'✖ বাতিল'},col={ok:'#16a34a',edit:'#2563eb',bad:'#dc2626'};
        function draw(){var f=$('#fm2-f').value,q=$('#fm2-s').value.trim().toLowerCase(),L=$('#fm2-l');L.textContent='';var n=0;
            D.forEach(function(r){if(r.dead){return;}if(f&&r.s!==f){return;}if(q&&(r.q+' '+r.a).toLowerCase().indexOf(q)<0){return;}n++;if(n>150){return;}
                var c=el('div','border:1px solid #d1d5db;border-left:4px solid '+(col[r.s]||'#999')+';border-radius:6px;padding:8px;margin-bottom:8px;background:#fff');
                var h=el('div','color:#6b7280;font-size:12px',(r.t?new Date(r.t*1000).toLocaleString():'')+'  ·  '+(lab[r.s]||r.s)+(r.model?'  ·  '+r.model:'')+(r.from?'  ·  '+r.from:'')+'  ');
                if(r.url){var a=el('a','','পোস্ট ↗');a.href=r.url;a.target='_blank';a.rel='noopener';h.appendChild(a);h.appendChild(document.createTextNode('  '));}
                if(r.cl){var b=el('a','','কমেন্ট ↗');b.href=r.cl;b.target='_blank';b.rel='noopener';h.appendChild(b);}
                c.appendChild(h);c.appendChild(el('div','margin-top:4px;white-space:pre-wrap','❓ '+r.q));
                if(r.draft&&r.draft!==r.a&&r.s==='edit'){c.appendChild(el('div','margin-top:3px;color:#9ca3af;white-space:pre-wrap;font-size:12px','AI-র খসড়া: '+r.draft));}
                if(r.note){c.appendChild(el('div','margin-top:3px;color:#b45309','কারণ: '+r.note));}
                var ta=el('textarea','width:100%;margin-top:4px');ta.rows=3;ta.value=r.a;c.appendChild(ta);
                var row=el('div','margin-top:4px');
                function mk(txt,st){var bt=el('button','',txt);bt.className='button button-small';bt.style.marginRight='4px';bt.onclick=function(){bt.textContent='…';post({action:'sb_fbai_mem',what:'save',id:r.id,a:ta.value,s:st||r.s}).then(function(x){if(x.success){r.a=ta.value;if(st){r.s=st;}draw();}else{bt.textContent='❌ '+x.data;}});};return bt;}
                row.appendChild(mk('💾 সংরক্ষণ'));if(r.s!=='ok'){row.appendChild(mk('✔ ঠিক হিসেবে রাখুন','ok'));}if(r.s!=='bad'){row.appendChild(mk('✖ বাতিল করুন','bad'));}
                var d=el('button','','🗑 মুছুন');d.className='button button-small';d.onclick=function(){if(d.dataset.s!=='1'){d.dataset.s='1';d.textContent='⚠ নিশ্চিত?';return;}post({action:'sb_fbai_mem',what:'delete',id:r.id}).then(function(x){if(x.success){r.dead=1;draw();}});};row.appendChild(d);
                c.appendChild(row);L.appendChild(c);});
            if(!n){L.textContent='কিছু নেই।';}$('#fm2-m').textContent=n>150?'প্রথম ১৫০টি দেখানো হচ্ছে — খুঁজে নিন।':'';}
        $('#fm2-f').onchange=draw;$('#fm2-s').oninput=draw;
        $('#fm2-add').onclick=function(){var q=$('#fm2-nq').value.trim(),a=$('#fm2-na').value.trim();post({action:'sb_fbai_mem',what:'add',q:q,a:a}).then(function(x){if(x.success){location.reload();}else{$('#fm2-m').textContent='❌ '+x.data;}});};
        draw();})();
    </script>
    <?php
}
