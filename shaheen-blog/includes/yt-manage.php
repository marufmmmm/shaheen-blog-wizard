<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * YouTube manager.
 *
 *  1. Information sync: the site's own facts (campuses, districts, years, phone, links) are read from the home page. When they change
 *     (checked daily) or once a month, the facts block in the description of the newest 100 videos and the channel description are rewritten.
 *  2. Playlists: the newest 100 videos are sorted into categories (keyword rules, or AI) -> a plan is shown -> after approval the playlists are made.
 *
 * Price (ledger feature "yt_manage"): no AI = 0.20 USD for the whole job; with AI = real AI cost x 1.5, never below 0.20.
 * YouTube's daily quota (10,000 units; a write costs 50) is respected: work stops at a safe limit and carries on later.
 */
const SB_YTM_MIN     = 0.20;
const SB_YTM_AI_MULT = 1.5;
const SB_YTM_QUOTA   = 8500;
const SB_YTM_START   = '━━ শাহীন শিক্ষা পরিবার ━━';
const SB_YTM_END     = '━━━━━━━━━━━━━━━━━━';

function sb_ytm_cfg() {
    $o = get_option('sb_ytm', array());
    return array_merge(array('auto' => 0, 'ai' => 0, 'hash' => '', 'last' => 0, 'block' => '', 'note' => '', 'campus' => '', 'kw' => 'শাহীন স্কুল ও ক্যাডেট কোচিং, ক্যাডেট কলেজ ভর্তি প্রস্তুতি, শাহীন শিক্ষা পরিবার, টাঙ্গাইল', 'thumb' => 1), is_array($o) ? $o : array());
}

function sb_ytm_save($patch) {
    update_option('sb_ytm', array_merge(sb_ytm_cfg(), $patch), false);
}

function sb_ytm_job() {
    $j = get_option('sb_ytm_job', array());
    return is_array($j) ? $j : array();
}

function sb_ytm_job_save($j) {
    update_option('sb_ytm_job', $j, false);
}

function sb_ytm_notify($html) {
    if (function_exists('sb_tg_send')) {
        sb_tg_send($html);
    }
}

/* ------------------------------------------------------------ YouTube API */
function sb_ytm_call($method, $path, $query = array(), $body = null, $cost = 1) {
    $c = sb_share_cfg();
    if ('' === $c['yt_cid'] || '' === $c['yt_sec'] || '' === $c['yt_rt']) {
        return new WP_Error('sb_ytm', 'ইউটিউব সংযোগ দেওয়া নেই।');
    }
    $qk = 'sb_ytm_q_' . wp_date('Ymd', null, new DateTimeZone('America/Los_Angeles'));
    $qn = (int) get_transient($qk);
    if ($qn + $cost > SB_YTM_QUOTA) {
        return new WP_Error('sb_quota', 'আজকের ইউটিউব সীমা প্রায় শেষ।');
    }
    $tok = get_transient('sb_ytm_tok');
    if (!$tok) {
        $tok = sb_share_yt_token($c);
        if (is_wp_error($tok)) {
            return $tok;
        }
        set_transient('sb_ytm_tok', $tok, 50 * MINUTE_IN_SECONDS);
    }
    set_transient($qk, $qn + $cost, DAY_IN_SECONDS);
    $args = array('method' => $method, 'timeout' => 40, 'headers' => array('Authorization' => 'Bearer ' . $tok));
    if (null !== $body) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    $r = wp_remote_request('https://www.googleapis.com/youtube/v3/' . $path . '?' . http_build_query($query), $args);
    if (is_wp_error($r)) {
        return $r;
    }
    $j    = json_decode((string) wp_remote_retrieve_body($r), true);
    $code = (int) wp_remote_retrieve_response_code($r);
    if ($code >= 400) {
        $m = (string) ($j['error']['message'] ?? ('HTTP ' . $code));
        $q = false !== stripos($m, 'quota') || 403 === $code && false !== stripos(wp_json_encode($j), 'quotaExceeded');
        return new WP_Error($q ? 'sb_quota' : 'sb_ytm', $m);
    }
    return is_array($j) ? $j : array();
}

/** ids of the newest videos (the channel's uploads list is newest first) */
function sb_ytm_ids($max = 100) {
    $ch = sb_ytm_call('GET', 'channels', array('part' => 'contentDetails', 'mine' => 'true'));
    if (is_wp_error($ch)) {
        return $ch;
    }
    $up = (string) ($ch['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '');
    if ('' === $up) {
        return new WP_Error('sb_ytm', 'চ্যানেলের ভিডিও তালিকা পাওয়া যায়নি।');
    }
    $ids = array();
    $pt  = '';
    while (count($ids) < $max) {
        $q = array('part' => 'contentDetails', 'playlistId' => $up, 'maxResults' => 50);
        if ('' !== $pt) {
            $q['pageToken'] = $pt;
        }
        $r = sb_ytm_call('GET', 'playlistItems', $q);
        if (is_wp_error($r)) {
            return $r;
        }
        foreach ((array) ($r['items'] ?? array()) as $it) {
            $ids[] = (string) $it['contentDetails']['videoId'];
        }
        $pt = (string) ($r['nextPageToken'] ?? '');
        if ('' === $pt) {
            break;
        }
    }
    return array_slice($ids, 0, $max);
}

function sb_ytm_snips($ids) {
    $out = array();
    foreach (array_chunk($ids, 50) as $ch) {
        $r = sb_ytm_call('GET', 'videos', array('part' => 'snippet,status', 'id' => implode(',', $ch)));
        if (is_wp_error($r)) {
            return $r;
        }
        foreach ((array) ($r['items'] ?? array()) as $v) {
            $out[$v['id']] = $v;
        }
    }
    return $out;
}

/* ------------------------------------------------------------ the site's facts */
function sb_ytm_home() {
    $r = wp_remote_get(home_url('/'), array('timeout' => 30));
    if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r)) {
        return new WP_Error('sb_ytm', 'হোম পেজ পড়া যায়নি।');
    }
    return (string) wp_remote_retrieve_body($r);
}

function sb_ytm_bn($s) {
    return strtr((string) $s, array('0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯'));
}

/** numbers written on the home page; every one must be found, or nothing is changed */
function sb_ytm_facts() {
    $html = sb_ytm_home();
    if (is_wp_error($html)) {
        return $html;
    }
    $meta = preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)#i', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
    $txt  = sb_crawl_clean_text($html, $t);
    $hay  = $meta . ' ' . $txt;
    $f    = array();
    foreach (array(
        'campus'  => '/([০-৯0-9]+)\s*টি\s*ক্যাম্পাস/u',
        'district' => '/([০-৯0-9]+)\s*টি\s*জেলা/u',
        'division' => '/([০-৯0-9]+)\s*টি\s*বিভাগীয়\s*শহর/u',
        'years'   => '/([০-৯0-9]+)\s*বছরের\s*অভিজ্ঞতা/u',
    ) as $k => $re) {
        if (!preg_match($re, $hay, $mm)) {
            return new WP_Error('sb_ytm', 'হোম পেজে "' . $k . '" এর সংখ্যা পাওয়া যায়নি, তাই কিছু বদলানো হয়নি।');
        }
        $f[$k] = sb_ytm_bn($mm[1]);
    }
    $ov = (int) sb_ytm_bn2en((string) sb_ytm_cfg()['campus']);   // a number the owner wants shown before the site itself says it; the site wins once it reaches it
    if ($ov > (int) sb_ytm_bn2en($f['campus'])) {
        $f['campus'] = sb_ytm_bn((string) $ov);
    }
    $f['phone'] = preg_match('#href=["\']tel:(\+?\d+)#i', $html, $mm) ? $mm[1] : '+8801622222111';
    $f['text']  = mb_substr($txt, 0, 6000);
    return $f;
}

function sb_ytm_stats_line($f) {
    return '🏫 দেশের ' . $f['division'] . 'টি বিভাগীয় শহরসহ ' . $f['district'] . 'টি জেলায় ' . $f['campus'] . 'টি ক্যাম্পাস | ' . $f['years'] . ' বছরের অভিজ্ঞতা';
}

function sb_ytm_block($f, $stats = '') {
    $kw = trim((string) sb_ytm_cfg()['kw']);
    return SB_YTM_START . "\n" . ('' !== $stats ? $stats : sb_ytm_stats_line($f)) . ('' !== $kw ? "\n🔎 " . $kw : '') . "\n🌐 " . home_url('/') . "\n📞 " . $f['phone'] . "\n👍 https://www.facebook.com/shaheen.edu.bd/\n" . SB_YTM_END;
}

function sb_ytm_channel_text($f, $stats) {
    return "শাহীন শিক্ষা পরিবার — বাংলাদেশের সবচেয়ে বড় শিক্ষা পরিবার। " . $f['years'] . " বছরের অভিজ্ঞতা নিয়ে " . $f['division'] . "টি বিভাগীয় শহরসহ " . $f['district'] . "টি জেলার " . $f['campus'] . "টি ক্যাম্পাসে শাহীন স্কুল ও ক্যাডেট কোচিংয়ের কার্যক্রম চলছে.\n\n"
        . ('' !== $stats ? $stats . "\n\n" : '')
        . "আমাদের ভিডিওতে পাবেন: ক্যাডেট কলেজ ভর্তি প্রস্তুতি, ক্লাস ও পরীক্ষার কৌশল, শিক্ষার্থীদের অনুষ্ঠান ও সাফল্যের গল্প।\n\n"
        . "We are the biggest Education Family in Bangladesh. Educational Excellence and Efficiency is our specialty.\n\n"
        . 'ওয়েবসাইট: ' . home_url('/') . "\nফেসবুক: https://www.facebook.com/shaheen.edu.bd\nযোগাযোগ: " . $f['phone'] . "\nইমেইল: corporate@shaheen.edu.bd";
}

/** put the facts block into one description: old block / old footer lines removed, hashtags kept last and written without spaces */
function sb_ytm_apply_block($desc, $block) {
    $desc = str_replace("\r", '', (string) $desc);
    $desc = preg_replace('/' . preg_quote(SB_YTM_START, '/') . '.*?' . preg_quote(SB_YTM_END, '/') . '/su', '', $desc);
    $lines = explode("\n", $desc);
    $tags  = array();
    $body  = array();
    foreach ($lines as $l) {
        $t = trim($l);
        if (preg_match('/^#/u', $t)) {
            foreach (array_filter(array_map('trim', explode('#', $t))) as $x) {
                $tags[] = '#' . preg_replace('/\s+/u', '', $x);
            }
            continue;
        }
        if (preg_match('#^(🌐\s*https?://(www\.)?shaheen\.edu\.bd/?|📞\s*\+?880[\d\s|+\-]*|👍\s*https?://(www\.)?facebook\.com/shaheen\.edu\.bd/?)\s*$#u', $t)) {
            continue;
        }
        $body[] = rtrim($l);
    }
    $text = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $body)));
    $out  = ('' !== $text ? $text . "\n\n" : '') . $block;
    if ($tags) {
        $out .= "\n\n" . implode(' ', array_unique($tags));
    }
    return $out;
}

/* ------------------------------------------------------------ AI (own metering, own price) */
function sb_ytm_ai($sys, $usr, $timeout = 70) {
    $key = function_exists('sb_get') ? sb_get('api_key') : '';
    if ('' === (string) $key) {
        return array(null, 0.0);
    }
    sb_meter_take();
    $r    = wp_remote_post(SB_AI::ENDPOINT, array('timeout' => (int) $timeout, 'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
        'body' => wp_json_encode(array('model' => sb_model_for('bulk'), 'response_format' => array('type' => 'json_object'), 'messages' => array(array('role' => 'system', 'content' => $sys), array('role' => 'user', 'content' => $usr))))));
    $real = sb_meter_take();
    if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r)) {
        return array(null, (float) $real);
    }
    $j = json_decode((string) wp_remote_retrieve_body($r), true);
    return array(json_decode((string) ($j['choices'][0]['message']['content'] ?? ''), true), (float) $real);
}

/** one bill per job: no AI = 0.20; with AI = real x 1.5, at least 0.20 */
function sb_ytm_bill($ai, $real, $note) {
    $bill = $ai ? max(SB_YTM_MIN, round($real * SB_YTM_AI_MULT, 4)) : SB_YTM_MIN;
    $GLOBALS['sb_bill_override'] = $bill;
    $GLOBALS['sb_mult_force']    = 1;
    $GLOBALS['sb_ledger_note']   = $note;
    $GLOBALS['sb_ledger_model']  = $ai ? sb_model_for('bulk') : '';
    sb_ledger_add(0, 'yt_manage', max(1, (int) ceil($bill / sb_unit_billed())), 0, $real > 0 ? $real : null);
    unset($GLOBALS['sb_bill_override'], $GLOBALS['sb_mult_force'], $GLOBALS['sb_ledger_model']);
    return $bill;
}

/* ------------------------------------------------------------ job runner */
add_action('sb_ytm_tick', 'sb_ytm_tick');

function sb_ytm_schedule($in = 20) {
    if (!wp_next_scheduled('sb_ytm_tick')) {
        wp_schedule_single_event(time() + $in, 'sb_ytm_tick');
    }
}

function sb_ytm_start_sync() {
    $j = sb_ytm_job();
    if (!empty($j) && 'run' === ($j['state'] ?? '')) {
        return new WP_Error('sb_ytm', 'আরেকটি কাজ চলছে।');
    }
    $cfg   = sb_ytm_cfg();
    $f     = sb_ytm_facts();
    if (is_wp_error($f)) {
        return $f;
    }
    $ai    = !empty($cfg['ai']) && '' !== (string) sb_get('api_key');
    $real  = 0.0;
    $stats = '';
    if ($ai) {
        list($r, $real) = sb_ytm_ai(
            'তুমি শাহীন শিক্ষা পরিবারের ইউটিউব ভিডিওর বিবরণের জন্য ছোট তথ্য-লাইন লেখো। শুধু নিচের হোম পেজের লেখায় থাকা সংখ্যা ও তথ্য ব্যবহার করবে, নিজে কিছু বানাবে না। সহজ চলতি বাংলা। শুধু "শাহীন" একা লিখবে না; লিখবে "শাহীন শিক্ষা পরিবার" বা "শাহীন স্কুল ও ক্যাডেট কোচিং"। শুধু এই JSON দেবে: {"lines":["২-৩টি লাইন, প্রতিটি একটি ইমোজি দিয়ে শুরু, প্রতিটি ৯০ অক্ষরের মধ্যে; প্রথমটিতে ক্যাম্পাস/জেলা/অভিজ্ঞতা, বাকিগুলোতে স্কুলের বিশেষ সাফল্য"]}',
            "হোম পেজের লেখা:\n" . $f['text']
        );
        if (!empty($r['lines']) && is_array($r['lines'])) {
            $stats = trim(implode("\n", array_slice(array_map(function ($x) {
                return trim(sanitize_text_field((string) $x));
            }, $r['lines']), 0, 3)));
        } else {
            $ai = false;   // the AI gave nothing: the plain block is used, and only the minimum is charged
        }
    }
    $ids = sb_ytm_ids(100);
    if (is_wp_error($ids)) {
        return $ids;
    }
    $block = sb_ytm_block($f, $stats);
    sb_ytm_job_save(array('type' => 'sync', 'state' => 'run', 'ids' => $ids, 'i' => 0, 'ok' => 0, 'same' => 0, 'fail' => 0, 'err' => '', 'block' => $block,
        'chan' => sb_ytm_channel_text($f, $stats), 'chan_done' => 0, 'ai' => $ai ? 1 : 0, 'real' => $real, 't' => time(), 'hash' => md5(sb_ytm_stats_line($f) . $f['phone'])));
    sb_ytm_schedule(5);
    return true;
}

function sb_ytm_tick() {
    if (get_transient('sb_ytm_lock')) {
        sb_ytm_schedule(60);
        return;
    }
    set_transient('sb_ytm_lock', 1, 5 * MINUTE_IN_SECONDS);
    @set_time_limit(240);
    $j = sb_ytm_job();
    if (empty($j) || 'run' !== ($j['state'] ?? '')) {
        delete_transient('sb_ytm_lock');
        return;
    }
    $pause = false;
    $t0    = microtime(true);
    do {   // as many steps as fit in about 40 seconds (the host's cron may only come round now and then)
        if ('sync' === $j['type']) {
            $j = sb_ytm_step_sync($j, $pause);
        } elseif ('apply' === $j['type']) {
            $j = sb_ytm_step_apply($j, $pause);
        }
        sb_ytm_job_save($j);
    } while ('run' === $j['state'] && !$pause && microtime(true) - $t0 < 40);
    sb_ytm_job_save($j);
    delete_transient('sb_ytm_lock');
    if ('done' === $j['state'] && 'apply' === $j['type'] && get_option('sb_ytm_chain_sync')) {   // playlists finished: now the descriptions
        delete_option('sb_ytm_chain_sync');
        $r = sb_ytm_start_sync();
        if (is_wp_error($r)) {
            sb_ytm_notify("▶️ <b>বিবরণ হালনাগাদ শুরু হয়নি</b>\n" . esc_html($r->get_error_message()));
        }
    }
    if ('run' === $j['state']) {
        sb_ytm_schedule($pause ? 3 * HOUR_IN_SECONDS : 30);
    }
}

function sb_ytm_step_sync($j, &$pause) {
    $batch = array_slice($j['ids'], (int) $j['i'], 12);
    if ($batch) {
        $sn = sb_ytm_snips($batch);
        if (is_wp_error($sn)) {
            return sb_ytm_fail($j, $sn, $pause);
        }
        foreach ($batch as $id) {
            if (empty($sn[$id])) {
                $j['fail']++;
                $j['i']++;
                continue;
            }
            $s   = $sn[$id]['snippet'];
            $new = sb_ytm_apply_block((string) ($s['description'] ?? ''), $j['block']);
            if ($new === str_replace("\r", '', (string) ($s['description'] ?? ''))) {
                $j['same']++;
                $j['i']++;
                continue;
            }
            $snip = array('title' => $s['title'], 'description' => $new, 'categoryId' => $s['categoryId'] ?? '27');
            foreach (array('tags', 'defaultLanguage', 'defaultAudioLanguage') as $k) {
                if (!empty($s[$k])) {
                    $snip[$k] = $s[$k];
                }
            }
            $r = sb_ytm_call('PUT', 'videos', array('part' => 'snippet'), array('id' => $id, 'snippet' => $snip), 50);
            if (is_wp_error($r)) {
                if ('sb_quota' === $r->get_error_code()) {
                    return sb_ytm_fail($j, $r, $pause);
                }
                $j['fail']++;
                $j['err'] = $r->get_error_message();
            } else {
                $j['ok']++;
            }
            $j['i']++;
        }
        return $j;
    }
    if (empty($j['chan_done'])) {
        $ch = sb_ytm_call('GET', 'channels', array('part' => 'brandingSettings', 'mine' => 'true'));
        if (is_wp_error($ch)) {
            return sb_ytm_fail($j, $ch, $pause);
        }
        $it = $ch['items'][0] ?? null;
        if ($it) {
            $it['brandingSettings']['channel']['description'] = $j['chan'];
            $r = sb_ytm_call('PUT', 'channels', array('part' => 'brandingSettings'), array('id' => $it['id'], 'brandingSettings' => $it['brandingSettings']), 50);
            if (is_wp_error($r)) {
                if ('sb_quota' === $r->get_error_code()) {
                    return sb_ytm_fail($j, $r, $pause);
                }
                $j['err'] = 'চ্যানেলের বিবরণ: ' . $r->get_error_message();
            }
        }
        $j['chan_done'] = 1;
        return $j;
    }
    $bill = sb_ytm_bill(!empty($j['ai']), (float) $j['real'], 'বিবরণ হালনাগাদ: ' . $j['ok'] . 'টি ভিডিও');
    sb_ytm_save(array('hash' => $j['hash'], 'last' => time(), 'block' => $j['block'], 'note' => ''));
    $j['state'] = 'done';
    $j['bill']  = $bill;
    sb_ytm_notify("▶️ <b>ইউটিউব তথ্য হালনাগাদ হয়েছে</b>\nবদলেছে: " . (int) $j['ok'] . 'টি ভিডিও · আগেই ঠিক ছিল: ' . (int) $j['same'] . ' · ব্যর্থ: ' . (int) $j['fail'] . "\nচ্যানেলের বিবরণও নতুন করা হয়েছে।" . ('' !== $j['err'] ? "\n⚠️ " . esc_html($j['err']) : ''));
    return $j;
}

function sb_ytm_fail($j, $e, &$pause) {
    if ('sb_quota' === $e->get_error_code()) {
        $pause     = true;
        $j['err']  = 'ইউটিউবের দৈনিক সীমা ছুঁয়েছে; কিছুক্ষণ পর বাকিটা নিজে চলবে।';
        return $j;
    }
    $j['tok_fail'] = (int) ($j['tok_fail'] ?? 0) + 1;
    if (false !== stripos($e->get_error_message(), 'token') && $j['tok_fail'] <= 4) {   // Google sometimes answers "expired or revoked" once and then works again: wait and retry
        $pause    = true;
        $j['err'] = 'ইউটিউব টোকেন একবার সাড়া দেয়নি; আবার চেষ্টা হবে (' . $j['tok_fail'] . ')।';
        delete_transient('sb_ytm_tok');
        return $j;
    }
    $j['state'] = 'error';
    $j['err']   = $e->get_error_message();
    sb_ytm_notify("▶️ <b>ইউটিউব ম্যানেজার থেমে গেছে</b>\n" . esc_html($j['err']));
    return $j;
}

/* ------------------------------------------------------------ playlists */
function sb_ytm_bn2en($s) {
    return strtr((string) $s, array('০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'));
}

function sb_ytm_is_en($title) {
    $lat = preg_match_all('/[A-Za-z]/', $title);
    $bn  = preg_match_all('/[\x{0980}-\x{09FF}]/u', $title);
    return $lat > $bn || preg_match('/spelling|grammar|word formation|english|স্পেলিং|গ্রামার|ইংরেজি/iu', $title);
}

/** other categories (checked on title + start of the description, first match wins) */
function sb_ytm_rules() {
    return array(
        'ক্যাডেট কলেজ ভর্তি প্রস্তুতি'    => '/ক্যাডেট|cadet|ভর্তি/iu',
        'ক্ষুদে স্টার ও শিশুদের অনুষ্ঠান'  => '/ক্ষুদে|খুদে|স্টার|star|শিশু/iu',
        'ইসলামিক শিক্ষা ও নৈতিকতা'        => '/ইসলামিক|ইসলামি|নৈতিক|ধর্মীয়|কুরআন|হিফজ/iu',
        'অনুষ্ঠান ও আয়োজন'              => '/অনুষ্ঠান|আয়োজন|প্রতিযোগিতা|উৎসব|দিবস/iu',
        'সাফল্য ও ফলাফল'                => '/ফলাফল|সাফল্য|রেজাল্ট|result|চান্স|প্রথম স্থান/iu',
        'স্কুল পরিচিতি ও ক্যাম্পাস'       => '/স্কুল|ক্যাম্পাস|শাখা|পরিচিতি|campus/iu',
    );
}

/** category by keywords (no AI): appreciation, class lectures (Bangla / English), then the rest */
function sb_ytm_rule_cat($title, $desc) {
    if (preg_match('/সম্মাননা|প্রশংসা|অভিনন্দন|কৃতজ্ঞতা|সংবর্ধনা|পুরস্কার|appreciation|testimonial|অভিভাবকের (মতামত|মন্তব্য)|মতামত/iu', $title . ' ' . mb_substr($desc, 0, 120))) {
        return 'প্রশংসা ও সম্মাননা';
    }
    if (preg_match('/ক্লাস|লেকচার|lecture|class|lesson|পাঠ|অধ্যায়|পর্ব|part\s*\d|spelling|word formation|grammar|স্পেলিং|গ্রামার|কিভাবে পড়বে|কীভাবে পড়বে/iu', $title)) {
        return sb_ytm_is_en($title) ? 'ক্লাস লেকচার (ইংরেজি)' : 'ক্লাস লেকচার (বাংলা)';
    }
    foreach (sb_ytm_rules() as $name => $re) {
        if (preg_match($re, $title . ' ' . $desc)) {
            return $name;
        }
    }
    return '';
}

/** watch order: lectures go as a series (part / class number first, the rest oldest first); everything else newest first */
function sb_ytm_order($name, $ids, $vids) {
    if (!preg_match('/লেকচার/u', $name)) {
        usort($ids, function ($a, $b) use ($vids) {
            return strcmp((string) $vids[$b]['p'], (string) $vids[$a]['p']);
        });
        return $ids;
    }
    $num = array();
    $rest = array();
    foreach ($ids as $id) {
        if (preg_match('/(?:পর্ব|part|ক্লাস|class|lesson|পাঠ|অধ্যায়|ep|episode)\s*[-:#.]?\s*([০-৯0-9]+)/iu', $vids[$id]['t'], $m)) {
            $num[$id] = (int) sb_ytm_bn2en($m[1]);
        } else {
            $rest[] = $id;
        }
    }
    asort($num);
    usort($rest, function ($a, $b) use ($vids) {
        return strcmp((string) $vids[$a]['p'], (string) $vids[$b]['p']);
    });
    return array_merge(array_keys($num), $rest);
}

/** $only = a list of video ids (new videos); null = every video of the channel (up to 500) is looked at first */
function sb_ytm_make_plan($ai, $only = null, $known = array()) {
    $ids = null === $only ? sb_ytm_ids(500) : $only;
    if (is_wp_error($ids)) {
        return $ids;
    }
    $sn = sb_ytm_snips($ids);
    if (is_wp_error($sn)) {
        return $sn;
    }
    $vids = array();
    foreach ($ids as $id) {
        if (!empty($sn[$id])) {
            $s = $sn[$id]['snippet'];
            $vids[$id] = array('t' => (string) $s['title'], 'p' => (string) ($s['publishedAt'] ?? ''), 'd' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $s['description'])), 0, 90));
        }
    }
    $plan = array('t' => time(), 'ai' => 0, 'real' => 0.0, 'lists' => array(), 'total' => count($vids));
    if ($ai && $vids && '' !== (string) sb_get('api_key')) {
        $rows = array();
        foreach ($vids as $id => $v) {
            $rows[] = $id . ' | ' . substr($v['p'], 0, 10) . ' | ' . $v['t'] . ' | ' . $v['d'];
        }
        list($r, $real) = sb_ytm_ai(
            'তুমি একটি স্কুল-কোচিং প্রতিষ্ঠানের ইউটিউব চ্যানেলের ভিডিও সাজাচ্ছ। প্রথমে সব ভিডিওর নাম ও বিবরণ দেখো, তারপর সেগুলোকে প্লেলিস্টে ভাগ করো যাতে দর্শক সহজে বোঝে কোনটা কী। অবশ্যই আলাদা প্লেলিস্ট থাকবে: (১) প্রশংসা ও সম্মাননা (অভিভাবক/অতিথির মতামত, সম্মাননা, অভিনন্দন), (২) ক্লাস লেকচার (বাংলা), (৩) ক্লাস লেকচার (ইংরেজি); এরপর বাকি ভিডিওর বিষয় দেখে আরও প্লেলিস্ট (মোট ৪ থেকে ৯টি)। যে ক্যাটাগরিতে ভিডিও নেই তার প্লেলিস্ট বানাবে না। প্রতিটি ভিডিও সবচেয়ে মানানসই একটি প্লেলিস্টে যাবে। "ids" তালিকা সেই ক্রমে দেবে যে ক্রমে দেখা উচিত: লেকচার/সিরিজে আগের বা সহজ পাঠ আগে (পর্ব/পার্ট নম্বর বা বিষয়ের ধারা দেখে), অন্য প্লেলিস্টে নতুন ভিডিও আগে। নাম সহজ চলতি বাংলায়, ৬০ অক্ষরের মধ্যে; বর্ণনা এক বাক্যে। নামে "শাহীন" একা লিখবে না। ভিডিওর আইডি বানাবে না, শুধু দেওয়া আইডি ব্যবহার করবে।'
            . ($known ? ' আগে থেকে এই প্লেলিস্টগুলো আছে, মানানসই হলে হুবহু এই নামই ব্যবহার করবে, নতুন বিষয় হলে তবেই নতুন নাম দেবে: ' . implode(' | ', $known) . '.' : '')
            . ' শুধু এই JSON: {"lists":[{"name":"...","desc":"...","ids":["..."]}]}',
            implode("\n", $rows)
        );
        $plan['real'] = $real;
        if (!empty($r['lists']) && is_array($r['lists'])) {
            $plan['ai'] = 1;
            foreach ($r['lists'] as $l) {
                $ok = array_values(array_unique(array_filter((array) ($l['ids'] ?? array()), function ($x) use ($vids) {
                    return isset($vids[$x]);
                })));
                if ($ok && !empty($l['name'])) {
                    $plan['lists'][] = array('name' => mb_substr(sanitize_text_field((string) $l['name']), 0, 100), 'desc' => mb_substr(sanitize_text_field((string) ($l['desc'] ?? '')), 0, 300), 'ids' => $ok);
                }
            }
        }
    }
    if (!$plan['lists']) {   // keyword rules: no AI, so the minimum price only
        $by = array();
        foreach ($vids as $id => $v) {
            $cat = sb_ytm_rule_cat($v['t'], $v['d']);
            if ('' !== $cat) {
                $by[$cat][] = $id;
            }
        }
        foreach ($by as $name => $list) {
            $plan['lists'][] = array('name' => $name, 'desc' => '', 'ids' => sb_ytm_order($name, $list, $vids));
        }
    }
    $plan['titles'] = array_map(function ($v) {
        return $v['t'];
    }, $vids);
    return $plan;
}

function sb_ytm_step_apply($j, &$pause) {
    $L = &$j['lists'];
    if (!isset($L[(int) $j['li']])) {
        $bill = sb_ytm_bill(!empty($j['ai']), (float) $j['real'], 'প্লেলিস্ট: ' . count($L) . 'টি, ' . (int) $j['added'] . 'টি ভিডিও বসানো');
        $j['state'] = 'done';
        $j['bill']  = $bill;
        $pl = get_option('sb_ytm_placed', array());
        update_option('sb_ytm_placed', array_merge(is_array($pl) ? $pl : array(), (array) ($j['placed'] ?? array())), false);
        sb_ytm_notify((!empty($j['auto']) ? "▶️ <b>নতুন ভিডিও প্লেলিস্টে বসেছে</b>" : "▶️ <b>ইউটিউব প্লেলিস্ট তৈরি হয়েছে</b>") . "\nপ্লেলিস্ট: " . count($L) . ' · ভিডিও বসেছে: ' . (int) $j['added'] );
        return $j;
    }
    $cur = &$L[(int) $j['li']];
    if (empty($cur['pid'])) {
        $ex = sb_ytm_call('GET', 'playlists', array('part' => 'snippet', 'mine' => 'true', 'maxResults' => 50));
        if (is_wp_error($ex)) {
            return sb_ytm_fail($j, $ex, $pause);
        }
        foreach ((array) ($ex['items'] ?? array()) as $p) {
            if (0 === strcasecmp(trim((string) $p['snippet']['title']), trim($cur['name']))) {
                $cur['pid'] = $p['id'];
            }
        }
        if (empty($cur['pid'])) {
            $r = sb_ytm_call('POST', 'playlists', array('part' => 'snippet,status'), array('snippet' => array('title' => $cur['name'], 'description' => $cur['desc'], 'defaultLanguage' => 'bn'), 'status' => array('privacyStatus' => 'public')), 50);
            if (is_wp_error($r)) {
                return sb_ytm_fail($j, $r, $pause);
            }
            $cur['pid'] = $r['id'];
        }
        $have = array();
        $pt   = '';
        do {
            $q = array('part' => 'contentDetails', 'playlistId' => $cur['pid'], 'maxResults' => 50);
            if ('' !== $pt) {
                $q['pageToken'] = $pt;
            }
            $pi = sb_ytm_call('GET', 'playlistItems', $q);
            if (is_wp_error($pi)) {
                break;
            }
            foreach ((array) ($pi['items'] ?? array()) as $it) {
                $have[] = (string) $it['contentDetails']['videoId'];
            }
            $pt = (string) ($pi['nextPageToken'] ?? '');
        } while ('' !== $pt);
        foreach ($have as $h) {
            $j['placed'][$h] = $cur['name'];
        }
        $cur['ids'] = array_values(array_diff($cur['ids'], $have));
        return $j;
    }
    $n = 0;
    while ($cur['ids'] && $n < 12) {
        $vid = array_shift($cur['ids']);
        $r   = sb_ytm_call('POST', 'playlistItems', array('part' => 'snippet'), array('snippet' => array('playlistId' => $cur['pid'], 'resourceId' => array('kind' => 'youtube#video', 'videoId' => $vid))), 50);
        if (is_wp_error($r)) {
            array_unshift($cur['ids'], $vid);
            if ('sb_quota' === $r->get_error_code()) {
                return sb_ytm_fail($j, $r, $pause);
            }
            $j['err'] = $r->get_error_message();
            array_shift($cur['ids']);   // a video that cannot be added is skipped
            continue;
        }
        $j['added']++;
        $j['placed'][$vid] = $cur['name'];
        $n++;
    }
    if (!$cur['ids']) {
        $j['li']++;
    }
    return $j;
}

/** new videos after the first playlist set-up are put into the right playlist (a new playlist if it is a new kind) */
function sb_ytm_place_new() {
    $placed = get_option('sb_ytm_placed', array());
    if (empty($placed) || !is_array($placed)) {
        return;   // the first playlist set-up is done by hand (with approval); only after that new videos follow
    }
    $j = sb_ytm_job();
    if (!empty($j) && 'run' === ($j['state'] ?? '')) {
        return;
    }
    $ids = sb_ytm_ids(30);
    if (is_wp_error($ids)) {
        return;
    }
    $new = array_values(array_diff($ids, array_keys($placed)));
    if (!$new) {
        return;
    }
    $cfg  = sb_ytm_cfg();
    $plan = sb_ytm_make_plan(!empty($cfg['ai']), $new, array_values(array_unique($placed)));
    if (is_wp_error($plan) || empty($plan['lists'])) {
        return;
    }
    $L = array();
    foreach ($plan['lists'] as $l) {
        $L[] = array('name' => $l['name'], 'desc' => $l['desc'], 'ids' => $l['ids']);
    }
    sb_ytm_job_save(array('type' => 'apply', 'state' => 'run', 'auto' => 1, 'lists' => $L, 'li' => 0, 'added' => 0, 'placed' => array(), 'err' => '', 'ai' => (int) $plan['ai'], 'real' => (float) $plan['real'], 't' => time()));
    sb_ytm_schedule(5);
}

/* ------------------------------------------------------------ daily check */
add_action('init', function () {
    if (!wp_next_scheduled('sb_ytm_daily')) {
        wp_schedule_event(time() + 600, 'daily', 'sb_ytm_daily');
    }
});
add_action('sb_ytm_daily', function () {
    $cfg = sb_ytm_cfg();
    if (empty($cfg['auto'])) {
        return;
    }
    $j = sb_ytm_job();
    if (!empty($j) && 'run' === ($j['state'] ?? '')) {
        return;
    }
    sb_ytm_place_new();
    $f = sb_ytm_facts();
    if (is_wp_error($f)) {
        return;
    }
    $hash = md5(sb_ytm_stats_line($f) . $f['phone']);
    if ('' === $cfg['hash']) {   // first time: only remember what the site says now (nothing is charged or changed)
        sb_ytm_save(array('hash' => $hash, 'last' => time()));
        return;
    }
    if ($hash !== $cfg['hash'] || time() - (int) $cfg['last'] > 30 * DAY_IN_SECONDS) {
        $r = sb_ytm_start_sync();
        if (is_wp_error($r)) {
            sb_ytm_notify("▶️ <b>ইউটিউব তথ্য হালনাগাদ শুরু হয়নি</b>\n" . esc_html($r->get_error_message()));
        }
    }
});

/* ------------------------------------------------------------ admin ajax + panel */
function sb_ytm_state() {
    $cfg = sb_ytm_cfg();
    $j   = sb_ytm_job();
    $pl  = get_option('sb_ytm_plan', array());
    $out = array(
        'evlogos' => function_exists('sb_ev_logos_text') ? sb_ev_logos_text() : '', 'auto' => (int) $cfg['auto'], 'ai' => (int) $cfg['ai'], 'thumb' => (int) $cfg['thumb'], 'campus' => $cfg['campus'], 'kw' => $cfg['kw'], 'last' => $cfg['last'] ? wp_date('j M Y, g:i A', (int) $cfg['last']) : '', 'block' => $cfg['block'],
        'job' => $j ? array('type' => $j['type'], 'state' => $j['state'], 'i' => (int) ($j['i'] ?? $j['li'] ?? 0), 'n' => count($j['ids'] ?? $j['lists'] ?? array()), 'ok' => (int) ($j['ok'] ?? $j['added'] ?? 0), 'err' => (string) ($j['err'] ?? ''), 'bill' => $j['bill'] ?? null) : null,
        'plan' => null,
        'quota' => (int) get_transient('sb_ytm_q_' . wp_date('Ymd', null, new DateTimeZone('America/Los_Angeles'))),
        'busy' => (time() - (int) get_option('sb_ytm_plan_busy', 0)) < 600 ? 1 : 0,
        'perr' => (string) get_option('sb_ytm_plan_err', ''),
        'plog' => (string) (get_option('sb_ytm_plan_st', array())['log'] ?? ''),
    );
    if (!empty($pl['lists'])) {
        $out['plan'] = array('ai' => (int) $pl['ai'], 'lists' => array_map(function ($l) use ($pl) {
            return array('name' => $l['name'], 'desc' => $l['desc'], 'ids' => $l['ids'], 'titles' => array_map(function ($i) use ($pl) {
                return $pl['titles'][$i] ?? $i;
            }, array_slice($l['ids'], 0, 6)));
        }, $pl['lists']));
    }
    return $out;
}

/** the plan is made in the background; with AI the videos go in batches of 50 (one short AI call each), so nothing runs into a time limit */
function sb_ytm_plan_next($sec = 3) {
    wp_schedule_single_event(time() + $sec, 'sb_ytm_plan_run', array(1));
    spawn_cron();
}

add_action('sb_ytm_plan_run', function ($ai) {
    @set_time_limit(300);
    if (!$ai) {
        $p = sb_ytm_make_plan(false);
        if (is_wp_error($p)) {
            update_option('sb_ytm_plan_err', $p->get_error_message(), false);
        } else {
            update_option('sb_ytm_plan', $p, false);
        }
        delete_option('sb_ytm_plan_busy');
        return;
    }
    $st = get_option('sb_ytm_plan_st', array());
    if (empty($st)) {
        $ids = sb_ytm_ids(500);
        $sn  = is_wp_error($ids) ? $ids : sb_ytm_snips($ids);
        if (is_wp_error($sn)) {
            update_option('sb_ytm_plan_err', $sn->get_error_message(), false);
            delete_option('sb_ytm_plan_busy');
            return;
        }
        $vids = array();
        foreach ($ids as $id) {
            if (!empty($sn[$id])) {
                $s = $sn[$id]['snippet'];
                $vids[$id] = array('t' => (string) $s['title'], 'p' => (string) ($s['publishedAt'] ?? ''), 'd' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $s['description'])), 0, 90));
            }
        }
        $st = array('vids' => $vids, 'order' => array_keys($vids), 'i' => 0, 'lists' => array(), 'desc' => array(), 'real' => 0.0, 'ok' => 0, 'tries' => 0, 'log' => 'ভিডিও তালিকা পড়া হয়েছে: ' . count($vids));
        update_option('sb_ytm_plan_st', $st, false);
        update_option('sb_ytm_plan_busy', time(), false);
        sb_ytm_plan_next();
        return;
    }
    $vids  = $st['vids'];
    $batch = array_slice($st['order'], (int) $st['i'], 25);
    // if this batch has already died twice (time limit, error), it goes by the keyword rules instead of the AI
    $st['tries'] = (int) $st['tries'] + 1;
    update_option('sb_ytm_plan_st', $st, false);
    update_option('sb_ytm_plan_busy', time(), false);
    $finished = false;
    register_shutdown_function(function () use (&$finished) {
        if (!$finished) {
            sb_ytm_plan_next(20);   // the batch was cut off: try again
        }
    });
    $rows  = array();
    foreach ($batch as $id) {
        $rows[] = $id . ' | ' . $vids[$id]['t'] . ' | ' . $vids[$id]['d'];
    }
    $r = null;
    $real = 0.0;
    if ((int) $st['tries'] <= 2) {
        list($r, $real) = sb_ytm_ai(
        'তুমি একটি স্কুল-কোচিং প্রতিষ্ঠানের ইউটিউব ভিডিও প্লেলিস্টে সাজাচ্ছ। নিচের ভিডিওগুলো প্রতিটি সবচেয়ে মানানসই একটি প্লেলিস্টে দাও। এই প্লেলিস্টগুলো অবশ্যই ব্যবহার করবে যেখানে মানায়: "প্রশংসা ও সম্মাননা" (অভিভাবক/অতিথির মতামত, সম্মাননা, অভিনন্দন), "ক্লাস লেকচার (বাংলা)", "ক্লাস লেকচার (ইংরেজি)"; এছাড়া বিষয় দেখে অন্য প্লেলিস্ট (সব মিলিয়ে ৪ থেকে ৯টির বেশি হবে না)। নাম সহজ চলতি বাংলায়, ৬০ অক্ষরের মধ্যে, নামে "শাহীন" একা লিখবে না; বর্ণনা এক বাক্যে।'
        . ($st['lists'] ? ' এই প্লেলিস্টগুলো আগেই হয়েছে; মানালে হুবহু এই নামই ব্যবহার করবে, একেবারে নতুন বিষয় হলে তবেই নতুন নাম দেবে: ' . implode(' | ', array_keys($st['lists'])) . '.' : '')
        . ' ভিডিওর আইডি বানাবে না। শুধু এই JSON: {"lists":[{"name":"...","desc":"...","ids":["..."]}]}',
        implode("\n", $rows),
        45
        );
    }
    $st['real'] += $real;
    $done = array();
    if (!empty($r['lists']) && is_array($r['lists'])) {
        $st['ok']++;
        foreach ($r['lists'] as $l) {
            $nm = trim(mb_substr(sanitize_text_field((string) ($l['name'] ?? '')), 0, 100));
            if ('' === $nm) {
                continue;
            }
            foreach ((array) ($l['ids'] ?? array()) as $x) {
                if (in_array($x, $batch, true) && !isset($done[$x])) {
                    $st['lists'][$nm][] = $x;
                    $done[$x] = 1;
                    if (empty($st['desc'][$nm]) && !empty($l['desc'])) {
                        $st['desc'][$nm] = mb_substr(sanitize_text_field((string) $l['desc']), 0, 300);
                    }
                }
            }
        }
    }
    foreach ($batch as $x) {   // anything the AI left out goes by the keyword rules
        if (!isset($done[$x])) {
            $c = sb_ytm_rule_cat($vids[$x]['t'], $vids[$x]['d']);
            if ('' !== $c) {
                $st['lists'][$c][] = $x;
            }
        }
    }
    $st['i'] = (int) $st['i'] + 25;
    $st['tries'] = 0;
    $st['log'] = 'দেখা হয়েছে ' . min($st['i'], count($st['order'])) . '/' . count($st['order']);
    $finished = true;
    if ($st['i'] < count($st['order'])) {
        update_option('sb_ytm_plan_st', $st, false);
        update_option('sb_ytm_plan_busy', time(), false);
        sb_ytm_plan_next();
        return;
    }
    $plan = array('t' => time(), 'ai' => $st['ok'] ? 1 : 0, 'real' => $st['real'], 'lists' => array(), 'total' => count($vids), 'titles' => array_map(function ($v) {
        return $v['t'];
    }, $vids));
    foreach ($st['lists'] as $nm => $ids) {
        $plan['lists'][] = array('name' => $nm, 'desc' => $st['desc'][$nm] ?? '', 'ids' => sb_ytm_order($nm, array_values(array_unique($ids)), $vids));
    }
    update_option('sb_ytm_plan', $plan, false);
    delete_option('sb_ytm_plan_st');
    delete_option('sb_ytm_plan_busy');
});

add_action('wp_ajax_sb_ytm', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('অনুমতি নেই।');
    }
    $do  = sanitize_key((string) ($_POST['do'] ?? ''));
    $msg = '';
    if ('save' === $do) {
        sb_ytm_save(array('auto' => empty($_POST['auto']) ? 0 : 1, 'ai' => empty($_POST['ai']) ? 0 : 1, 'thumb' => empty($_POST['thumb']) ? 0 : 1, 'campus' => preg_replace('/[^0-9০-৯]/u', '', (string) ($_POST['campus'] ?? '')), 'kw' => mb_substr(sanitize_text_field((string) wp_unslash($_POST['kw'] ?? '')), 0, 200)));
        $msg = 'সেভ হয়েছে।';
    } elseif ('preview' === $do) {
        $f = sb_ytm_facts();
        if (is_wp_error($f)) {
            wp_send_json_error($f->get_error_message());
        }
        wp_send_json_success(array('block' => sb_ytm_block($f)) + sb_ytm_state());
    } elseif ('sync' === $do) {
        $r = sb_ytm_start_sync();
        if (is_wp_error($r)) {
            wp_send_json_error($r->get_error_message());
        }
        $msg = 'কাজ শুরু হয়েছে; কয়েক মিনিটে হয়ে যাবে, শেষে টেলিগ্রামে জানাব।';
    } elseif ('plan' === $do) {
        delete_option('sb_ytm_plan');
        delete_option('sb_ytm_plan_st');
        delete_option('sb_ytm_plan_err');
        update_option('sb_ytm_plan_busy', time(), false);
        wp_schedule_single_event(time() + 2, 'sb_ytm_plan_run', array(!empty($_POST['ai']) ? 1 : 0));
        spawn_cron();
        $msg = 'সব ভিডিও দেখে পরিকল্পনা বানানো শুরু হয়েছে (১-২ মিনিট লাগতে পারে)।';
    } elseif ('plan_step' === $do) {   // the open admin page drives the batches one by one (a background cron alone was not reliable on this host)
        if ((time() - (int) get_option('sb_ytm_plan_busy', 0)) < 600) {
            do_action('sb_ytm_plan_run', 1);
        }
    } elseif ('evlogos' === $do) {
        sb_ev_logos_save((string) wp_unslash($_POST['lines'] ?? ''));
        $msg = 'ইভেন্ট লোগো সেভ হয়েছে।';
    } elseif ('tokentest' === $do) {   // is the saved YouTube refresh token alive? (shows Google's own answer and when the token was saved)
        $c = sb_share_cfg();
        $t = sb_share_yt_token($c);
        wp_send_json_success(array('tok' => is_wp_error($t) ? $t->get_error_message() : 'ok', 'saved' => !empty($c['yt_rt_t']) ? wp_date('j M g:i:s A', (int) $c['yt_rt_t']) : '', 'now' => wp_date('j M g:i:s A'), 'len' => strlen((string) $c['yt_rt'])));
    } elseif ('resume' === $do) {
        $j = sb_ytm_job();
        if (!empty($j) && 'error' === ($j['state'] ?? '')) {
            $j['state'] = 'run';
            $j['err']   = '';
            $j['tok_fail'] = 0;
            sb_ytm_job_save($j);
            sb_ytm_schedule(5);
        }
        $msg = 'আবার চালু করা হয়েছে।';
    } elseif ('tick' === $do) {   // the open admin page can also push a running job forward
        $j = sb_ytm_job();
        if (!empty($j) && 'run' === ($j['state'] ?? '')) {
            sb_ytm_tick();
        }
    } elseif ('apply' === $do) {
        $pl  = get_option('sb_ytm_plan', array());
        $sel = json_decode((string) wp_unslash($_POST['lists'] ?? '[]'), true);
        $L   = array();
        foreach ((array) $sel as $s) {
            $i = (int) ($s['i'] ?? -1);
            if (isset($pl['lists'][$i]) && !empty($s['on'])) {
                $nm = trim(sanitize_text_field((string) ($s['name'] ?? '')));
                $L[] = array('name' => '' !== $nm ? mb_substr($nm, 0, 100) : $pl['lists'][$i]['name'], 'desc' => $pl['lists'][$i]['desc'], 'ids' => $pl['lists'][$i]['ids']);
            }
        }
        if (!$L) {
            wp_send_json_error('কোনো প্লেলিস্ট বাছা হয়নি।');
        }
        $j = sb_ytm_job();
        if (!empty($j) && 'run' === ($j['state'] ?? '')) {
            wp_send_json_error('আরেকটি কাজ চলছে।');
        }
        sb_ytm_job_save(array('type' => 'apply', 'state' => 'run', 'lists' => $L, 'li' => 0, 'added' => 0, 'placed' => array(), 'err' => '', 'ai' => (int) $pl['ai'], 'real' => (float) $pl['real'], 't' => time()));
        delete_option('sb_ytm_plan');
        if (!empty($_POST['chain'])) {
            update_option('sb_ytm_chain_sync', 1, false);
        }
        sb_ytm_schedule(5);
        $msg = 'প্লেলিস্ট বানানো শুরু হয়েছে; শেষে টেলিগ্রামে জানাব।';
    } elseif ('stop' === $do) {
        $j = sb_ytm_job();
        if ($j) {
            $j['state'] = 'stopped';
            sb_ytm_job_save($j);
        }
        $msg = 'থামানো হয়েছে।';
    }
    wp_send_json_success(array('msg' => $msg) + sb_ytm_state());
});

function sb_ytm_panel() {
    ?>
    <div id="sb-ytm" style="border:1px solid #d1d5db;border-radius:8px;padding:12px;margin:12px 0;background:#fff7ed">
        <b>▶️ ইউটিউব ম্যানেজার: তথ্য মিলানো ও প্লেলিস্ট</b>
        <p class="sba-muted" style="margin:4px 0 8px">সাইটের তথ্য (ক্যাম্পাস, জেলা, অভিজ্ঞতা, ফোন) বদলালে শেষ ১০০ ভিডিওর বিবরণ ও চ্যানেলের বিবরণ নিজে হালনাগাদ হয়; না বদলালেও মাসে একবার। প্রতিবার কাজের খরচ আপনার ব্যবহারের হিসাবে যোগ হয়।</p>
        <p><label><input type="checkbox" id="ym-auto"> <b>নিজে নিজে চালু</b></label> &nbsp; <label><input type="checkbox" id="ym-ai"> AI দিয়ে বিশেষ সাফল্যের লাইনও যোগ হোক</label> <button class="button" id="ym-save">সেভ</button></p>
        <p><label><input type="checkbox" id="ym-th"> নতুন ভিডিওর থাম্বনেইল AI দিয়ে (হুক লেখা + লোগো) নিজে বানাও</label></p>
        <p><b>ইভেন্ট লোগো</b> <span class="description">(ভিডিওর লেখায় এই শব্দ থাকলে সেই ইভেন্টের লোগো থাম্বনেইলে বসে। প্রতি লাইনে: নাম ; শব্দ (| দিয়ে আলাদা) ; মিডিয়া লাইব্রেরির ছবির আইডি)</span><br><textarea id="ym-ev" rows="3" style="width:100%"></textarea> <button class="button" id="ym-evsave">লোগো তালিকা সেভ</button></p>
        <p><label>ক্যাম্পাস সংখ্যা (সাইটে এখনো না বদলালে এটাই বসবে; সাইট এতে পৌঁছালে সাইটের সংখ্যা চলবে): <input type="text" id="ym-campus" size="5"></label> &nbsp; <label>SEO কীওয়ার্ড লাইন: <input type="text" id="ym-kw" style="width:100%"></label></p>
        <p><button class="button" id="ym-preview">যে ব্লক বসবে দেখুন</button> <button class="button button-primary" id="ym-sync">এখনই ১০০ ভিডিওর বিবরণ হালনাগাদ</button> <button class="button" id="ym-stop">থামান</button></p>
        <pre id="ym-block" style="white-space:pre-wrap;background:#fff;border:1px solid #e5e7eb;padding:8px;display:none"></pre>
        <p><b>প্লেলিস্ট:</b> <button class="button" id="ym-plan0">নিয়ম দিয়ে ভাগ (০.২০)</button> <button class="button" id="ym-plan1">AI দিয়ে ভাগ</button></p>
        <div id="ym-plan"></div>
        <p id="ym-st" class="description"></p>
        <script>
        (function(){
            var $=function(s){return document.querySelector(s);},aj=(window.SB&&SB.ajax)||window.ajaxurl,nn=window.SB&&SB.nonce;
            if(!nn){var m=document.documentElement.innerHTML.match(/"nonce":"([a-z0-9]+)"/);nn=m&&m[1];}
            function call(x){return fetch(aj,{method:'POST',credentials:'same-origin',body:new URLSearchParams(Object.assign({action:'sb_ytm',nonce:nn},x))}).then(function(r){return r.json();});}
            function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
            var plan=null;
            function show(d){
                $('#ym-auto').checked=!!d.auto;$('#ym-ai').checked=!!d.ai;$('#ym-th').checked=!!d.thumb;if(document.activeElement!==$('#ym-ev'))$('#ym-ev').value=d.evlogos||'';if(document.activeElement!==$('#ym-campus'))$('#ym-campus').value=d.campus||'';if(document.activeElement!==$('#ym-kw'))$('#ym-kw').value=d.kw||'';
                var j=d.job,t=(d.msg?d.msg+' · ':'')+(d.last?'শেষ হালনাগাদ: '+d.last+' · ':'')+'আজ ইউটিউব সীমা ব্যবহার: '+d.quota+'/১০০০০';
                if(j){t+=' · কাজ: '+(j.type==='sync'?'বিবরণ':'প্লেলিস্ট')+' '+j.state+' ('+j.i+'/'+j.n+')'+(j.err?' — '+j.err:'');}
                if(d.perr)t+=' · ❌ '+d.perr;if(d.busy)t+=' · পরিকল্পনা বানানো চলছে… '+(d.plog||'');
                $('#ym-st').textContent=t;if(d.busy)setTimeout(function(){call({do:'plan_step'}).then(function(r){if(r.success)show(r.data);});},1500);
                if(d.block){$('#ym-block').style.display='block';$('#ym-block').textContent=d.block;}
                plan=d.plan;var h='';
                if(plan){h='<p><b>পরিকল্পনা ('+(plan.ai?'AI':'নিয়ম')+'):</b> বাদ দিতে টিক তুলুন, নাম বদলাতে পারেন।</p>';
                    plan.lists.forEach(function(l,i){h+='<div style="margin:6px 0;padding:6px;border:1px solid #e5e7eb;background:#fff"><label><input type="checkbox" class="ym-on" data-i="'+i+'" checked> <input type="text" class="ym-nm" data-i="'+i+'" value="'+esc(l.name)+'" style="width:60%"> <b>'+l.ids.length+'টি ভিডিও</b></label><div class="description">'+l.titles.map(esc).join(' · ')+(l.ids.length>6?' …':'')+'</div></div>';});
                    h+='<p><button class="button button-primary" id="ym-apply">প্লেলিস্ট বানাও</button></p>';}
                $('#ym-plan').innerHTML=h;
                if($('#ym-apply'))$('#ym-apply').onclick=function(){var s=[];document.querySelectorAll('.ym-on').forEach(function(c){var i=c.dataset.i;s.push({i:+i,on:c.checked,name:document.querySelector('.ym-nm[data-i="'+i+'"]').value});});go({do:'apply',lists:JSON.stringify(s)});};
            }
            function go(x){$('#ym-st').textContent='…';call(x).then(function(r){if(!r.success){$('#ym-st').textContent='❌ '+r.data;return;}show(r.data);});}
            $('#ym-save').onclick=function(){go({do:'save',auto:$('#ym-auto').checked?1:'',ai:$('#ym-ai').checked?1:'',thumb:$('#ym-th').checked?1:'',campus:$('#ym-campus').value,kw:$('#ym-kw').value});};
            $('#ym-evsave').onclick=function(){go({do:'evlogos',lines:$('#ym-ev').value});};
            $('#ym-preview').onclick=function(){go({do:'preview'});};
            $('#ym-sync').onclick=function(){go({do:'sync'});};
            $('#ym-stop').onclick=function(){go({do:'stop'});};
            $('#ym-plan0').onclick=function(){go({do:'plan'});};
            $('#ym-plan1').onclick=function(){go({do:'plan',ai:1});};
            go({do:'state'});
        })();
        </script>
    </div>
    <?php
}
