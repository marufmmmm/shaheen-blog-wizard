<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * "মানবিক করুন": finds the places in an article that read stiff, machine-made or hard to follow, and rewrites only those.
 *
 * Step 1 (free, no AI): every paragraph is checked for stock AI phrases, heavy office language, over-long sentences,
 *        the same sentence opening again and again, a flat rhythm, too many passive endings, a word repeated too often.
 * Step 2 (AI, only the flagged paragraphs, the owner approves each one): plain everyday Bangla, same facts.
 *        A rewrite that drops or changes a number is refused.
 * The owner can add his own phrases in Settings; those are checked too and are also told to the writer so they stop appearing.
 */

/** [phrase => reason-group]. Group: ai = stock AI wording, off = heavy / office wording. */
function sb_human_phrases() {
    $ai = array('আজকের ডিজিটাল যুগে', 'বর্তমান ডিজিটাল যুগে', 'বর্তমান যুগে', 'বর্তমান প্রতিযোগিতামূলক', 'উল্লেখ্য যে', 'উল্লেখযোগ্য যে', 'মনে রাখা জরুরি যে',
        'মনে রাখা প্রয়োজন যে', 'বলার অপেক্ষা রাখে না', 'নিঃসন্দেহে', 'অনস্বীকার্য', 'সংক্ষেপে বলা যায়', 'সংক্ষেপে বলতে গেলে', 'সামগ্রিকভাবে বলা যায়', 'উপসংহারে বলা যায়',
        'এক কথায় বলতে গেলে', 'পরিশেষে বলা যায়', 'গুরুত্বপূর্ণ ভূমিকা পালন করে', 'অপরিহার্য ভূমিকা পালন', 'অন্যতম গুরুত্বপূর্ণ', 'সর্বোপরি', 'নতুন দিগন্ত', 'দিগন্ত উন্মোচন',
        'সম্ভাবনার দ্বার', 'অপার সম্ভাবনা', 'সফলতার চাবিকাঠি', 'সুদূরপ্রসারী', 'বহুমাত্রিক', 'ঘুরে দাঁড়ানোর গল্প', 'এক নতুন যুগের সূচনা', 'একটি অবিচ্ছেদ্য অংশ', 'অনুপ্রেরণার উৎস',
        'সময়ের দাবি', 'যুগোপযোগী', 'স্বপ্ন বুনতে', 'কোনো সন্দেহ নেই যে', 'আমরা আশা করি এই লেখা', 'এই প্রবন্ধে আমরা', 'চলুন জেনে নিই');
    $off = array('প্রদান করা হবে', 'প্রদান করে থাকে', 'প্রদান করা হয়', 'প্রদান করেন', 'সম্পাদন করা', 'গ্রহণ করা হয়েছে', 'উক্ত', 'নিম্নরূপ', 'অত্র', 'অবগতির জন্য',
        'করা হয়ে থাকে', 'হয়ে থাকে', 'করে থাকেন', 'সংশ্লিষ্ট কর্তৃপক্ষ', 'যথাযথ কর্তৃপক্ষ', 'বিবেচনাধীন', 'প্রযোজ্য ক্ষেত্রে', 'সুষ্ঠুভাবে সম্পন্ন', 'সম্যক ধারণা', 'অতঃপর', 'নিমিত্ত', 'কর্তৃক');
    $out = array();
    foreach ($ai as $p) {
        $out[$p] = 'ai';
    }
    foreach ($off as $p) {
        $out[$p] = 'off';
    }
    foreach (preg_split('/\R/u', (string) sb_get('human_extra'), -1, PREG_SPLIT_NO_EMPTY) as $l) {
        $p = trim(explode('=>', $l)[0]);
        if (mb_strlen($p) >= 3) {
            $out[$p] = 'own';
        }
    }
    return $out;
}

function sb_human_sentences($t) {
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    $s = preg_split('/(?<=[।?!])\s+|(?<=\.)\s+(?=[^\d])/u', $t, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($s) ? $s : array($t);
}

function sb_human_words($t) {
    return preg_split('/\s+/u', trim($t), -1, PREG_SPLIT_NO_EMPTY);
}

/** One paragraph (plain text) -> list of reasons it reads stiff (empty = fine). */
function sb_human_check($text) {
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    $why  = array();
    $wn   = count(sb_human_words($text));
    if ($wn < 8) {
        return $why;
    }
    $found = array();
    foreach (sb_human_phrases() as $p => $g) {
        if (mb_strpos($text, $p) !== false) {
            $found[$g][] = $p;
        }
    }
    if (!empty($found['ai'])) {
        $why[] = 'যন্ত্রের মতো বাঁধা বুলি: “' . implode('”, “', array_slice($found['ai'], 0, 3)) . '”';
    }
    if (!empty($found['off'])) {
        $why[] = 'ভারী দাপ্তরিক শব্দ: “' . implode('”, “', array_slice($found['off'], 0, 3)) . '”';
    }
    if (!empty($found['own'])) {
        $why[] = 'আপনার তালিকার বাক্যাংশ: “' . implode('”, “', array_slice($found['own'], 0, 3)) . '”';
    }
    $sent = sb_human_sentences($text);
    $lens = array();
    $long = 0;
    foreach ($sent as $s) {
        $n = count(sb_human_words($s));
        $lens[] = $n;
        if ($n > 30 || mb_substr_count($s, ',') + mb_substr_count($s, '،') >= 6) {
            $long++;
        }
    }
    if ($long) {
        $why[] = 'খুব লম্বা বা কমা-ভরা বাক্য (' . $long . 'টি): পাঠকের দম ফুরিয়ে যায়';
    }
    if ($wn > 110) {
        $why[] = 'অনুচ্ছেদ খুব বড় (' . $wn . ' শব্দ): ভেঙে ছোট করা ভালো';
    }
    $starts = array();
    foreach ($sent as $s) {
        $w = sb_human_words($s);
        $starts[] = isset($w[0]) ? $w[0] : '';
    }
    $cnt = array_count_values(array_filter($starts));
    if ($cnt && max($cnt) >= 3) {
        $why[] = 'একই শব্দ দিয়ে বারবার বাক্য শুরু: “' . array_search(max($cnt), $cnt, true) . '”';
    }
    if (count($lens) >= 4) {
        $mean = array_sum($lens) / count($lens);
        $var  = 0;
        foreach ($lens as $l) {
            $var += ($l - $mean) * ($l - $mean);
        }
        if ($mean >= 9 && sqrt($var / count($lens)) < 2.2) {
            $why[] = 'সব বাক্য প্রায় একই মাপের: পড়তে একঘেয়ে, যন্ত্রের ছন্দ';
        }
    }
    $pass = preg_match_all('/(করা|দেওয়া|নেওয়া|প্রদান করা|গ্রহণ করা|সম্পন্ন করা)\s+(হয়|হয়েছে|হবে|হয়েছিল|হচ্ছে|হয়ে থাকে)/u', $text);
    if ($pass >= 3) {
        $why[] = '“করা হয়েছে/হবে” ধরনের নিষ্ক্রিয় বাক্য বেশি (' . $pass . 'টি): কে কী করছে সরাসরি লিখলে সহজ হয়';
    }
    $conn = 0;
    foreach ($starts as $w) {
        if (in_array($w, array('এছাড়াও', 'এছাড়া', 'তাছাড়া', 'অতএব', 'সুতরাং', 'এছাড়াও,', 'অধিকন্তু', 'তদুপরি'), true)) {
            $conn++;
        }
    }
    if ($conn >= 2) {
        $why[] = '“এছাড়াও/সুতরাং” ধরনের জোড়ের শব্দ বাক্যের শুরুতে বারবার';
    }
    $stop = array('এবং', 'করতে', 'জন্য', 'থেকে', 'হবে', 'যাবে', 'করে', 'হয়', 'তবে', 'এখানে', 'সেখানে', 'পারে', 'পারেন', 'রয়েছে', 'আছে', 'যেখানে', 'কিন্তু', 'সাথে');
    $freq = array();
    foreach (sb_human_words(preg_replace('/[।,;:!?"“”‘’()\-–—]/u', ' ', $text)) as $w) {
        if (mb_strlen($w) >= 5 && !in_array($w, $stop, true)) {
            $freq[$w] = isset($freq[$w]) ? $freq[$w] + 1 : 1;
        }
    }
    if ($freq && max($freq) >= 4) {
        $why[] = 'একই শব্দ বারবার: “' . array_search(max($freq), $freq, true) . '” (' . max($freq) . ' বার)';
    }
    $rel = 0;
    foreach ($sent as $s) {
        if (preg_match_all('/(?:^|\s)(যা|যার|যেখানে|যাতে|যিনি)(?=\s)/u', $s) >= 3) {
            $rel++;
        }
    }
    if ($rel) {
        $why[] = '“যা/যার/যেখানে” দিয়ে বাক্য জোড়া লাগানো: ভেঙে সোজা করে বলা যায়';
    }
    return $why;
}

/** Free scan. Returns [{i, why[], text}], flagged paragraphs only, plus an overall mark 0-100 (higher = more natural). */
function sb_human_scan($paras) {
    $out = array();
    $n   = 0;
    foreach (array_slice((array) $paras, 0, 300) as $i => $t) {
        $t = trim(wp_strip_all_tags((string) $t));
        if (mb_strlen($t) < 40) {
            continue;
        }
        $n++;
        $why = sb_human_check($t);
        if ($why) {
            $out[] = array('i' => (int) $i, 'why' => $why);
        }
    }
    $score = $n ? (int) round(100 * (1 - count($out) / $n)) : 100;
    return array('flags' => $out, 'checked' => $n, 'score' => $score);
}

add_action('wp_ajax_sb_humanize_scan', function () {
    sb_ajax_guard();
    $p = isset($_POST['paras']) ? (array) wp_unslash($_POST['paras']) : array();
    wp_send_json_success(sb_human_scan($p));
});

/** Paid step: rewrite the flagged paragraphs the owner ticked. */
add_action('wp_ajax_sb_humanize_run', function () {
    sb_ajax_guard();
    $items = array();
    $raw   = isset($_POST['items']) ? (array) wp_unslash($_POST['items']) : array();
    foreach (array_slice($raw, 0, 12) as $r) {
        $t = isset($r['t']) ? trim(wp_strip_all_tags((string) $r['t'])) : '';
        if ($t !== '' && mb_strlen($t) <= 1800) {
            $items[] = array('i' => (int) $r['i'], 't' => $t, 'why' => sanitize_text_field(isset($r['why']) ? $r['why'] : ''));
        }
    }
    if (!$items) {
        wp_send_json_error(array('message' => 'কোনো অনুচ্ছেদ বাছা হয়নি।'));
    }
    $uid   = get_current_user_id();
    $cost  = sb_feature_cost('humanize');
    $state = SB_Credits::state($uid);
    if ($cost && $state['remaining'] < $cost) {
        wp_send_json_error(array('message' => 'ব্যবহারের সীমা শেষ।', 'balance' => $state));
    }
    $ctx = array('title' => sanitize_text_field(sb_req('title')), 'topic' => '', 'excerpt' => '', 'content' => '', 'keywords' => array(), 'post_id' => (int) sb_req('post_id', 0),
        'items' => $items, 'tier' => 1, 'model' => '');
    $notes = array();
    $out   = sb_ai_execute($uid, 'humanize', $ctx, $cost, false, $notes);
    if (empty($out['ok'])) {
        wp_send_json_error(array('message' => $out['message'], 'balance' => $state));
    }
    // guard: facts must survive
    $res  = array();
    $orig = array();
    foreach ($items as $it) {
        $orig[$it['i']] = $it['t'];
    }
    foreach ((array) $out['data']['result']['items'] as $r) {
        $i = (int) $r['i'];
        if (!isset($orig[$i])) {
            continue;
        }
        $new = trim($r['t']);
        $lo  = mb_strlen($orig[$i]);
        $ln  = mb_strlen($new);
        $miss = array_diff(sb_numbers($orig[$i]), sb_numbers($new));
        if ($new === '' || $new === $orig[$i]) {
            continue;
        }
        if ($miss) {
            $res[] = array('i' => $i, 'bad' => 'সংখ্যা মেলেনি (' . implode(', ', array_map('sb_bn_digits', $miss)) . '), তাই নেওয়া হয়নি');
        } elseif ($ln < $lo * 0.45 || $ln > $lo * 1.5) {
            $res[] = array('i' => $i, 'bad' => 'দৈর্ঘ্য অনেক বদলে গেছে, তাই নেওয়া হয়নি');
        } else {
            $res[] = array('i' => $i, 'old' => $orig[$i], 'new' => $new);
        }
    }
    $out['data']['result'] = array('items' => $res);
    wp_send_json_success($out['data']);
});
