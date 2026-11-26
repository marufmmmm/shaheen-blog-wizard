<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * "ভাষা যাচাই": finds bookish / translated-sounding Bangla in an article (free), then rewrites only the wording
 * into everyday spoken Bangla (AI step, billed at real cost x 1.4). Facts, numbers, headings, pictures stay as they are.
 */
const SB_LANGFIX_MULT = 1.4;

/** [bookish word => everyday way to say it]. Common English tech words (স্ক্রিন, মোবাইল, ফোন, কম্পিউটার) are fine and kept. */
function sb_lang_bookish() {
    $m = array(
        'পর্দা' => 'স্ক্রিন', 'পর্দায়' => 'স্ক্রিনে', 'পর্দার' => 'স্ক্রিনের',
        'যন্ত্র' => 'ফোন/কম্পিউটার/ডিভাইস', 'যন্ত্রে' => 'ফোনে/কম্পিউটারে', 'যন্ত্রের' => 'ডিভাইসের',
        'তৈরি উত্তর' => 'রেডিমেড উত্তর (নিজে না ভেবে পাওয়া উত্তর)', 'সংশ্লিষ্ট' => 'সংক্রান্ত / ওই', 'কনটেন্ট' => 'পড়ার জিনিস / পাঠ',
        'সামগ্রী' => 'জিনিস / উপকরণ', 'পাঠসামগ্রী' => 'পড়ার জিনিস', 'না-বোঝা' => 'বুঝতে না পারা', 'চলমান দৃশ্য' => 'ভিডিও',
        'অবলোকন' => 'দেখা', 'প্রদর্শন' => 'দেখানো', 'ব্যবহারকারী' => 'যে ব্যবহার করে', 'অনুশীলনী' => 'অনুশীলন', 'সম্পাদন' => 'করা',
        'নিশ্চিতকরণ' => 'নিশ্চিত করা', 'পর্যবেক্ষণ' => 'নজর রাখা / দেখা', 'পরিবীক্ষণ' => 'নজর রাখা', 'দিকনির্দেশনা' => 'পথ দেখানো',
        'বাস্তবায়ন' => 'চালু করা', 'উপস্থাপন' => 'তুলে ধরা', 'অনুধাবন' => 'বোঝা', 'প্রতিফলন' => 'ছাপ',
    );
    foreach (preg_split('/\R/u', (string) sb_get('lang_extra'), -1, PREG_SPLIT_NO_EMPTY) as $l) {
        $p = array_map('trim', explode('=>', $l));
        if (mb_strlen($p[0]) >= 2 && !empty($p[1])) {
            $m[$p[0]] = $p[1];
        }
    }
    return $m;
}

/** Longer words first, so "পাঠসামগ্রী" is not counted again as "সামগ্রী". */
function sb_lang_scan($html) {
    $text = ' ' . wp_strip_all_tags((string) $html) . ' ';
    $list = sb_lang_bookish();
    uksort($list, function ($a, $b) {
        return mb_strlen($b) - mb_strlen($a);
    });
    $out = array();
    foreach ($list as $w => $to) {
        $n = mb_substr_count($text, $w);
        if ($n > 0) {
            $out[] = array('w' => $w, 'to' => $to, 'n' => $n);
            $text = str_replace($w, ' ', $text);
        }
    }
    return $out;
}

add_action('wp_ajax_sb_langfix_scan', function () {
    sb_ajax_guard();
    $c = isset($_POST['content']) ? wp_unslash($_POST['content']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
    wp_send_json_success(array('found' => sb_lang_scan($c)));
});

/**
 * The whole language fix on one HTML text. Returns array(text, before, after, cost, balance) or WP_Error.
 * Nothing is charged unless the result passes the guards (same headings, pictures, links and numbers).
 */
function sb_langfix_core($uid, $cur, $title, $post_id) {
    $cost  = sb_feature_cost('langfix');
    $state = SB_Credits::state($uid);
    if ($cost && $state['remaining'] < $cost) {
        return new WP_Error('sb_limit', 'ব্যবহারের সীমা শেষ।');
    }
    $found = sb_lang_scan($cur);
    $pairs = array();
    foreach ($found as $f) {
        $pairs[] = '“' . $f['w'] . '” → ' . $f['to'];
    }
    $ins = "লেখাটির ভাষা পরীক্ষা করে এমন করো যেন একজন মানুষ মুখে যেমন বলে তেমন শোনায়, বইয়ের বা অনুবাদের মতো নয়।\n"
        . "- কঠিন, বইয়ের বা অনুবাদ-করা শব্দ বদলে রোজকার কথার শব্দ দাও। প্রচলিত ইংরেজি শব্দ (স্ক্রিন, মোবাইল, ফোন, কম্পিউটার, অনলাইন, ক্লাস, গ্রুপ) যেমন আছে রাখো; সব শব্দের বাংলা অর্থ লিখতে হবে না।\n"
        . "- যেমন: “পর্দা” → স্ক্রিন, “যন্ত্র” → ফোন/কম্পিউটার/ডিভাইস, “চলমান দৃশ্য” → ভিডিও, “তৈরি উত্তর” → সোজা কথায় (নিজে না ভেবে পাওয়া উত্তর), “সংশ্লিষ্ট” → ওই/সংক্রান্ত।\n"
        . ($pairs ? "- এই লেখায় পাওয়া শব্দগুলো অবশ্যই ঠিক করবে: " . implode('; ', $pairs) . "।\n" : '')
        . "- যে বাক্য বইয়ের মতো লাগে, সেটি ছোট ও সোজা করে নতুন করে লেখো। লম্বা বাক্য ভাঙো।\n"
        . "- শুধু শব্দ ও বাক্যের গড়ন বদলাবে। তথ্য, সংখ্যা, নাম, তারিখ, শিরোনাম (h2/h3), অনুচ্ছেদের ক্রম, ছবি ও ক্যাপশন, লিংক একদম একই রাখো। নতুন তথ্য যোগ করবে না, কিছু বাদ দেবে না।\n"
        . "- শাহীনের নাম লিখতে হলে শুধু “শাহীন শিক্ষা পরিবার” বা “শাহীন স্কুল ও ক্যাডেট কোচিং” লিখবে।";
    $ctx = array('title' => $title, 'topic' => '', 'excerpt' => '', 'content' => $cur, 'keywords' => array(), 'post_id' => (int) $post_id,
        'instruction' => $ins, 'tier' => 1, 'model' => '');
    $res = SB_AI::generate('edit', $ctx);
    if (is_wp_error($res)) {
        return $res;
    }
    $new = isset($res['text']) ? (string) $res['text'] : '';
    foreach (array('/<h2\b/i', '/<h3\b/i', '/<img\b/i', '/<figure\b/i', '/<a\s/i') as $re) {
        if (preg_match_all($re, $cur) !== preg_match_all($re, $new)) {
            return new WP_Error('sb_struct', 'AI লেখার কাঠামো (শিরোনাম/ছবি/লিংক) বদলে ফেলেছিল, তাই কিছু বদলানো হয়নি এবং বিল যোগ হয়নি।');
        }
    }
    $miss = array_diff(sb_numbers(wp_strip_all_tags($cur)), sb_numbers(wp_strip_all_tags($new)));
    if ($miss) {
        return new WP_Error('sb_nums', 'AI কিছু সংখ্যা বদলে ফেলেছিল (' . implode(', ', array_map('sb_bn_digits', $miss)) . '), তাই বদলানো হয়নি এবং বিল যোগ হয়নি।');
    }
    $notes = array();
    $GLOBALS['sb_mult_force'] = SB_LANGFIX_MULT;
    $out = sb_ai_finish($uid, 'langfix', $ctx, $cost, false, $res, $notes);
    unset($GLOBALS['sb_mult_force']);
    if (empty($out['ok'])) {
        return new WP_Error('sb_fin', $out['message']);
    }
    $d = $out['data'];
    return array('text' => $d['result']['text'], 'before' => $found, 'after' => sb_lang_scan($d['result']['text']), 'cost' => $d['cost'], 'balance' => $d['balance']);
}

/** Automatic step after an article is written: only when bookish words are really there, and only if the fix is safe. */
function sb_langfix_auto($uid, $html, $title, $post_id) {
    if (sb_get('lang_auto') === '0' || mb_strlen(wp_strip_all_tags((string) $html)) < 300 || !sb_lang_scan($html)) {
        return null;
    }
    $r = sb_langfix_core($uid, $html, $title, $post_id);
    return is_wp_error($r) ? null : $r;
}

add_action('wp_ajax_sb_langfix_run', function () {
    sb_ajax_guard();
    $cur = isset($_POST['content']) ? trim((string) wp_unslash($_POST['content'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
    if (mb_strlen(wp_strip_all_tags($cur)) < 120) {
        wp_send_json_error(array('message' => 'আগে আর্টিকেল লিখুন।'));
    }
    $r = sb_langfix_core(get_current_user_id(), $cur, sanitize_text_field(isset($_POST['title']) ? wp_unslash($_POST['title']) : ''), (int) sb_req('post_id', 0)); // phpcs:ignore WordPress.Security.NonceVerification
    if (is_wp_error($r)) {
        wp_send_json_error(array('message' => $r->get_error_message(), 'balance' => SB_Credits::state(get_current_user_id())));
    }
    wp_send_json_success($r);
});
