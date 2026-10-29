<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owner's memory:
 *  - rules / facts the owner corrected or taught (kept in option sb_memory), added to every AI writing prompt;
 *  - website memory: pages of linked sites crawled and kept (option sb_site_mem), the relevant ones go to the prompt.
 */

function sb_memory_cfg() {
    $c = get_option('sb_mem_cfg', array());
    return array_merge(array('auto' => 1, 'max_pages' => 30), is_array($c) ? $c : array());
}

function sb_memory_all() {
    $m = get_option('sb_memory', array());
    return is_array($m) ? $m : array();
}

function sb_memory_clean($list) {
    $out = array();
    $seen = array();
    foreach (array_slice(is_array($list) ? $list : array(), 0, 80) as $r) {
        if (!is_array($r) || empty($r['text'])) {
            continue;
        }
        $text = mb_substr(trim(sanitize_textarea_field($r['text'])), 0, 400);
        $key = mb_strtolower($text);
        if ($text === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = 1;
        $out[] = array(
            'id'   => (isset($r['id']) && preg_match('/^[a-z0-9]{6,20}$/', $r['id'])) ? $r['id'] : strtolower(wp_generate_password(8, false, false)),
            'type' => (isset($r['type']) && $r['type'] === 'fact') ? 'fact' : 'rule',
            'text' => $text,
            'on'   => !empty($r['on']) ? 1 : 0,
            'src'  => (isset($r['src']) && $r['src'] === 'auto') ? 'auto' : 'manual',
            't'    => isset($r['t']) ? (int) $r['t'] : time(),
        );
    }
    return $out;
}

/* ---------- Word replacements: hard word -> simple word, remembered and enforced ---------- */
function sb_words_all() {
    $w = get_option('sb_words', array());
    return is_array($w) ? $w : array();
}

function sb_words_clean($list) {
    $out = array();
    $seen = array();
    foreach (array_slice(is_array($list) ? $list : array(), 0, 300) as $r) {
        $from = isset($r['from']) ? trim(sanitize_text_field($r['from'])) : '';
        $to = isset($r['to']) ? trim(sanitize_text_field($r['to'])) : '';
        if (mb_strlen($from) < 2 || $to === '' || $from === $to || isset($seen[mb_strtolower($from)])) {
            continue;
        }
        $seen[mb_strtolower($from)] = 1;
        $out[] = array('from' => mb_substr($from, 0, 60), 'to' => mb_substr($to, 0, 60));
    }
    return $out;
}

/** Replaces the banned words in plain text; whole words only (a letter or mark before/after means a different word). */
function sb_words_apply_text($s) {
    foreach (sb_words_all() as $w) {
        $to = $w['to'];
        $s = preg_replace_callback('/(?<![\p{L}\p{M}\p{N}])' . preg_quote($w['from'], '/') . '(?![\p{L}\p{M}\p{N}])/u', function () use ($to) { return $to; }, $s);
    }
    return $s;
}

/** Same for HTML: only the text between tags, never tag attributes (links, images). */
function sb_words_apply_html($h) {
    if (!sb_words_all() || !is_string($h) || $h === '') {
        return $h;
    }
    return preg_replace_callback('/>([^<]+)</u', function ($m) {
        return '>' . sb_words_apply_text($m[1]) . '<';
    }, $h);
}

function sb_words_apply_result($feature, $r) {
    if (!sb_words_all() || !is_array($r)) {
        return $r;
    }
    if (isset($r['text']) && is_string($r['text'])) {
        $r['text'] = in_array($feature, array('subtitle', 'meta', 'summary', 'caption'), true) ? sb_words_apply_text($r['text']) : sb_words_apply_html($r['text']);
    }
    foreach (array('meta', 'excerpt', 'summary', 'subtitle', 'headline') as $k) {
        if (isset($r[$k]) && is_string($r[$k])) {
            $r[$k] = sb_words_apply_text($r[$k]);
        }
    }
    if (!empty($r['faq']) && is_array($r['faq'])) {
        foreach ($r['faq'] as $i => $f) {
            $r['faq'][$i] = array('q' => sb_words_apply_text($f['q']), 'a' => sb_words_apply_text($f['a']));
        }
    }
    if (!empty($r['chat']) && is_array($r['chat'])) {
        foreach ($r['chat']['changes'] as $k => $v) {
            $r['chat']['changes'][$k] = $k === 'faq' ? array_map(function ($f) { return array('q' => sb_words_apply_text($f['q']), 'a' => sb_words_apply_text($f['a'])); }, $v) : sb_words_apply_text($v);
        }
        if (!empty($r['chat']['article'])) {
            $r['chat']['article'] = sb_words_apply_html($r['chat']['article']);
        }
    }
    return $r;
}

add_action('wp_ajax_sb_words_save', function () {
    sb_ajax_guard('edit_posts');
    $j = json_decode((string) sb_req('items', '[]'), true);
    update_option('sb_words', sb_words_clean($j), false);
    wp_send_json_success(array('items' => sb_words_all()));
});

add_action('wp_ajax_sb_words_add', function () {
    sb_ajax_guard('edit_posts');
    $j = json_decode((string) sb_req('items', '[]'), true);
    $all = array_merge(sb_words_all(), is_array($j) ? $j : array());
    // a newer pair for the same word wins
    $map = array();
    foreach ($all as $r) {
        if (isset($r['from'])) {
            $map[mb_strtolower($r['from'])] = $r;
        }
    }
    update_option('sb_words', sb_words_clean(array_values($map)), false);
    wp_send_json_success(array('items' => sb_words_all()));
});

add_action('wp_ajax_sb_mem_rule', function () {
    sb_ajax_guard('edit_posts');
    $t = sanitize_text_field(sb_req('text'));
    if (mb_strlen($t) < 6) {
        wp_send_json_error(array('message' => 'Rule is empty.'));
    }
    sb_memory_add('rule', $t, 'manual');
    wp_send_json_success(array('ok' => 1));
});

/** Text block added to AI writing prompts. */
function sb_memory_context($skip_auto = false) {
    $rules = array();
    $facts = array();
    foreach (sb_memory_all() as $m) {
        if (empty($m['on'])) {
            continue;
        }
        if ($skip_auto && isset($m['src']) && $m['src'] === 'auto') {
            continue; // the agent's own saved facts reach it through its auto memory
        }
        if ($m['type'] === 'fact') {
            $facts[] = '• ' . $m['text'];
        } else {
            $rules[] = '• ' . $m['text'];
        }
    }
    $words = sb_words_all();
    $agent_notes = '';
    if (!$skip_auto && function_exists('csa_memory')) {
        $cm = csa_memory();
        $agent_notes = trim(mb_substr(trim((string) $cm['instructions']) . "\n" . trim((string) $cm['notes']), 0, 1500));
    }
    if (!$rules && !$facts && !$words && $agent_notes === '') {
        return '';
    }
    $o = "--- মালিকের মেমোরি (আগের লেখায় মালিক যা ঠিক করে দিয়েছেন; সবসময় মানবে) ---\n";
    if ($rules) {
        $o .= "লেখার নিয়ম ও পছন্দ:\n" . implode("\n", array_slice($rules, 0, 30)) . "\n";
    }
    if ($facts) {
        $o .= "সঠিক তথ্য (এগুলো নির্ভুল ধরবে; উৎসের সাথে না মিললে এই তথ্যই ঠিক):\n" . implode("\n", array_slice($facts, 0, 40)) . "\n";
    }
    if ($words) {
        $pairs = array();
        foreach (array_slice($words, 0, 60) as $w) {
            $pairs[] = '• "' . $w['from'] . '" কখনো লিখবে না (এর যেকোনো রূপও নয়), তার বদলে "' . $w['to'] . '" লিখবে';
        }
        $o .= "মালিকের বাদ দেওয়া কঠিন শব্দ (কখনো ব্যবহার করবে না):\n" . implode("\n", $pairs) . "\n";
    }
    if ($agent_notes !== '') {
        $o .= "সাইটের সাধারণ নির্দেশনা ও নোট (Site Agent মেমোরি থেকে):\n" . $agent_notes . "\n";
    }
    return $o;
}

/** Adds (or updates, when the label before ':' already exists) one remembered fact/rule. */
function sb_memory_add($type, $text, $src = 'manual') {
    $list = sb_memory_all();
    $text = mb_substr(trim(sanitize_textarea_field($text)), 0, 400);
    if ($text === '') {
        return false;
    }
    $label = ($type === 'fact' && ($p = mb_strpos($text, ':')) !== false) ? mb_substr($text, 0, $p + 1) : '';
    foreach ($list as $i => $m) {
        if (mb_strtolower($m['text']) === mb_strtolower($text)) {
            return false;
        }
        if ($label !== '' && $m['type'] === 'fact' && mb_strpos($m['text'], $label) === 0) {
            $list[$i]['text'] = $text;
            $list[$i]['on'] = 1;
            $list[$i]['t'] = time();
            update_option('sb_memory', sb_memory_clean($list), false);
            return true;
        }
    }
    array_unshift($list, array('type' => $type, 'text' => $text, 'on' => 1, 'src' => $src, 't' => time()));
    update_option('sb_memory', sb_memory_clean($list), false);
    return true;
}

add_action('wp_ajax_sb_mem_save', function () {
    sb_ajax_guard('edit_posts');
    $j = json_decode((string) sb_req('items', '[]'), true);
    update_option('sb_memory', sb_memory_clean(is_array($j) ? $j : array()), false);
    $c = sb_memory_cfg();
    $c['auto'] = sb_req('auto') === '1' ? 1 : 0;
    update_option('sb_mem_cfg', $c, false);
    wp_send_json_success(array('items' => sb_memory_all()));
});

/** Owner corrected a fact / filled a missing one in the writing screen. */
add_action('wp_ajax_sb_mem_fact', function () {
    sb_ajax_guard('edit_posts');
    $label = sanitize_text_field(sb_req('label'));
    $value = sanitize_text_field(sb_req('value'));
    if ($label === '' || $value === '') {
        wp_send_json_error(array('message' => 'খালি তথ্য মনে রাখা যায় না।'));
    }
    sb_memory_add('fact', $label . ': ' . $value, 'manual');
    wp_send_json_success(array('ok' => 1));
});

/** After saving: what did the owner change in the AI draft? Keep the lasting lessons. */
add_action('wp_ajax_sb_mem_learn', function () {
    sb_ajax_guard('edit_posts');
    $cfg = sb_memory_cfg();
    if (empty($cfg['auto'])) {
        wp_send_json_error(array('message' => 'স্বয়ংক্রিয় শেখা বন্ধ আছে।'));
    }
    $draft = trim(wp_strip_all_tags(wp_kses_post(sb_req('draft'))));
    $final = trim(wp_strip_all_tags(wp_kses_post(sb_req('final'))));
    $ins = trim(sanitize_textarea_field(sb_req('ins')));
    if ($ins === '' && (mb_strlen($draft) < 200 || mb_strlen($final) < 200)) {
        wp_send_json_success(array('added' => 0, 'skipped' => 1));
    }
    $pct = 0;
    if ($draft !== '' && $final !== '') {
        similar_text(mb_substr($draft, 0, 3000), mb_substr($final, 0, 3000), $pct);
    }
    if ($ins === '' && $pct > 97) {
        wp_send_json_success(array('added' => 0, 'skipped' => 1));   // nothing meaningful changed
    }
    $uid = get_current_user_id();
    $cost = sb_feature_cost('learn');
    $state = SB_Credits::state($uid);
    if ($state['remaining'] < $cost) {
        wp_send_json_error(array('message' => 'শেখার জন্য ব্যালেন্স যথেষ্ট নয়।', 'balance' => $state));
    }
    $r = SB_AI::generate('learn', array('draft' => $draft, 'final' => $final, 'ins' => $ins, 'title' => sanitize_text_field(sb_req('title')), 'keywords' => array()));
    if (is_wp_error($r)) {
        wp_send_json_error(array('message' => $r->get_error_message(), 'balance' => $state));
    }
    $added = 0;
    foreach ($r['rules'] as $t) {
        $added += sb_memory_add('rule', $t, 'auto') ? 1 : 0;
    }
    foreach ($r['facts'] as $t) {
        $added += sb_memory_add('fact', $t, 'auto') ? 1 : 0;
    }
    $new = $state;
    if ($added) {
        $c = SB_Credits::charge($uid, 'learn', $cost, (int) sb_req('post_id', 0));
        $new = is_wp_error($c) ? $state : $c;
    }
    wp_send_json_success(array('added' => $added, 'cost' => $added ? $cost : 0, 'balance' => $new));
});

/* ------------------------------------------------------------ website memory */
function sb_site_mem() {
    $m = get_option('sb_site_mem', array());
    return array_merge(array('sites' => array(), 'pages' => array(), 'updated' => 0), is_array($m) ? $m : array());
}

function sb_crawl_clean_text($html, &$title) {
    $title = '';
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
        $title = trim(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    }
    $html = preg_replace('#<(script|style|noscript|svg|nav|header|footer|form)\b[^>]*>.*?</\1>#is', ' ', $html);
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8')));
}

/** One crawl step: fetch up to 4 pages from the queue; returns progress. */
add_action('wp_ajax_sb_site_crawl', function () {
    sb_ajax_guard('manage_options');
    if (function_exists('set_time_limit')) {
        @set_time_limit(90); // phpcs:ignore
    }
    $mem = sb_site_mem();
    $max = max(5, min(80, (int) sb_memory_cfg()['max_pages']));
    if (sb_req('start') === '1') {
        $urls = array();
        foreach (preg_split('/\R+/', (string) sb_req('urls')) as $u) {
            $u = esc_url_raw(trim($u), array('http', 'https'));
            if ($u) {
                $urls[] = $u;
            }
        }
        $urls = array_slice(array_values(array_unique($urls)), 0, 6);
        if (!$urls) {
            wp_send_json_error(array('message' => 'ঠিকমতো ওয়েবসাইট লিংক দিন (https://… দিয়ে শুরু)।'));
        }
        $mem['sites'] = $urls;
        $mem['pages'] = array();
        update_option('sb_site_mem', $mem, false);
        $hosts = array();
        foreach ($urls as $u) {
            $hosts[$u] = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url($u, PHP_URL_HOST)));
        }
        set_transient('sb_crawl_' . get_current_user_id(), array('queue' => $urls, 'seen' => array_fill_keys($urls, 1), 'hosts' => array_values(array_unique($hosts)), 'per' => array(), 'done' => 0), 3 * HOUR_IN_SECONDS);
    }
    $st = get_transient('sb_crawl_' . get_current_user_id());
    if (!is_array($st)) {
        wp_send_json_error(array('message' => 'ক্রল শুরু করা হয়নি।'));
    }
    $notes = array();
    for ($i = 0; $i < 4 && $st['queue'] && count($mem['pages']) < $max * count($mem['sites']); $i++) {
        $u = array_shift($st['queue']);
        $host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url($u, PHP_URL_HOST)));
        if (isset($st['per'][$host]) && $st['per'][$host] >= $max) {
            continue;
        }
        $r = wp_safe_remote_get($u, array('timeout' => 15, 'redirection' => 3, 'user-agent' => 'Mozilla/5.0 (ShaheenBlogMemory)'));
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
            $notes[] = 'পড়া যায়নি: ' . $u;
            continue;
        }
        $ct = (string) wp_remote_retrieve_header($r, 'content-type');
        if ($ct !== '' && stripos($ct, 'html') === false) {
            continue;
        }
        $html = wp_remote_retrieve_body($r);
        $title = '';
        $text = sb_crawl_clean_text($html, $title);
        if (mb_strlen($text) >= 80) {
            $mem['pages'][$u] = array('title' => mb_substr($title, 0, 150), 'text' => mb_substr($text, 0, 6000), 't' => time());
            $st['per'][$host] = isset($st['per'][$host]) ? $st['per'][$host] + 1 : 1;
        }
        if (preg_match_all('#<a\s[^>]*href=["\']([^"\'\#]+)["\']#i', $html, $mm)) {
            foreach ($mm[1] as $h) {
                $abs = esc_url_raw(WP_Http::make_absolute_url(html_entity_decode($h), $u), array('http', 'https'));
                if (!$abs || isset($st['seen'][$abs])) {
                    continue;
                }
                $hh = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url($abs, PHP_URL_HOST)));
                if (!in_array($hh, $st['hosts'], true) || preg_match('/\.(pdf|jpe?g|png|gif|webp|zip|docx?|xlsx?|mp4|mp3|css|js)(\?|$)/i', $abs)) {
                    continue;
                }
                $st['seen'][$abs] = 1;
                if (count($st['queue']) < 400) {
                    $st['queue'][] = preg_replace('/#.*$/', '', $abs);
                }
            }
        }
    }
    $mem['updated'] = time();
    update_option('sb_site_mem', $mem, false);
    $st['done'] = count($mem['pages']);
    $finished = !$st['queue'] || count($mem['pages']) >= $max * count($mem['sites']);
    if ($finished) {
        delete_transient('sb_crawl_' . get_current_user_id());
    } else {
        set_transient('sb_crawl_' . get_current_user_id(), $st, 3 * HOUR_IN_SECONDS);
    }
    wp_send_json_success(array('pages' => count($mem['pages']), 'queue' => count($st['queue']), 'finished' => $finished, 'notes' => $notes));
});

add_action('wp_ajax_sb_site_clear', function () {
    sb_ajax_guard('manage_options');
    delete_option('sb_site_mem');
    wp_send_json_success(array('ok' => 1));
});

add_action('wp_ajax_sb_site_cfg', function () {
    sb_ajax_guard('manage_options');
    $c = sb_memory_cfg();
    $c['max_pages'] = max(5, min(80, (int) sb_req('max_pages', 30)));
    update_option('sb_mem_cfg', $c, false);
    wp_send_json_success(array('ok' => 1));
});

/** Remembered pages that best match the keywords. */
function sb_site_mem_context(array $keywords, $title = '') {
    $mem = sb_site_mem();
    if (!$mem['pages']) {
        return '';
    }
    $terms = array_filter(array_merge($keywords, preg_split('/\s+/u', (string) $title)), function ($t) { return mb_strlen($t) >= 3; });
    $scored = array();
    foreach ($mem['pages'] as $u => $p) {
        $s = 0;
        foreach ($terms as $t) {
            $s += 5 * substr_count(mb_strtolower($p['title']), mb_strtolower($t)) + min(8, substr_count(mb_strtolower($p['text']), mb_strtolower($t)));
        }
        if ($s > 0) {
            $scored[$u] = $s;
        }
    }
    arsort($scored);
    $out = array();
    foreach (array_slice(array_keys($scored), 0, 4) as $u) {
        $out[] = '[' . $u . '] ' . $mem['pages'][$u]['title'] . ': ' . mb_substr($mem['pages'][$u]['text'], 0, 3500);
    }
    return implode("\n\n", $out);
}
