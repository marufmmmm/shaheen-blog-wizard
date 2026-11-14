<?php
/**
 * Auto-filing of collected pages (Shaheen Browser).
 * Every collected page is first judged by AI (billed real x 1.7, feature "research_collect"):
 *   - negative / critical / controversial / doubtful about Shaheen, Spelling Star or anything sensitive  -> BLOCKED (never published)
 *   - not education news worth showing, or a video page                                                  -> skipped
 *   - otherwise it is filed: a blog news card (সংবাদপত্রে শাহীন) and, when it is about the event
 *     (default Spelling Star), also a line in that event page's news section.
 * Without an AI key nothing is filed (no vetting = no publishing).
 */
if (!defined('ABSPATH')) {
    exit;
}

function sb_ca_cfg() {
    $c = get_option('sb_collect_auto', array());
    $c = is_array($c) ? $c : array();
    return array_merge(array('on' => 1, 'cat' => 0, 'event' => 'spelling-star', 'kw' => 'Spelling Star|স্পেলিং স্টার', 'extra' => '', 'disc' => 1,
        'q' => "স্পেলিং স্টার শাহীন শিক্ষা পরিবার\nSpelling Star Shaheen Education Family Channel i\nশাহীন শিক্ষা পরিবার", 'dlast' => 0, 'dlog' => array()), $c);
}

/** topics: what to look for and where it goes. [ {id,name,kw,q,event,cat} ] */
function sb_ca_topics() {
    $c = sb_ca_cfg();
    if (!empty($c['topics']) && is_array($c['topics'])) {
        return $c['topics'];
    }
    return array(
        array('id' => 't1', 'name' => 'Spelling Star', 'kw' => $c['kw'], 'q' => $c['q'], 'event' => $c['event'], 'cat' => (int) $c['cat']),
        array('id' => 't2', 'name' => 'শাহীন কোচিং ও ক্যাডেট কোচিং', 'kw' => 'Shaheen Cadet|Shaheen Coaching|শাহীন ক্যাডেট|শাহীন কোচিং|শাহীন শিক্ষা|Shaheen Education',
            'q' => "শাহীন ক্যাডেট কোচিং\nShaheen Cadet Coaching\nশাহীন কোচিং সংবাদ", 'event' => '', 'cat' => (int) $c['cat']),
    );
}

function sb_ca_ai($item, $cfg, $manual = true) {
    $key = function_exists('sb_get') ? sb_get('api_key') : '';
    if ('' === (string) $key) {
        return new WP_Error('sb_ca', 'AI চাবি নেই');
    }
    $tl = array();
    foreach (sb_ca_topics() as $t) {
        $tl[] = $t['id'] . ' = ' . $t['name'] . ' (keywords: ' . $t['kw'] . ')';
    }
    $sys = 'You are the editor-in-chief gatekeeper of the news section of the website of "Shaheen Education Family" (Shaheen Cadet Coaching, Bangladesh) and of its TV competition "Spelling Star" (with Channel i). '
        . 'A page was collected. Decide whether it may be published on our site. Reply with JSON only: '
        . '{"kind":"news|video|other","relevant":true|false,"topic":"topic id or none","negative":true|false,"reason":"short Bangla reason","title":"clean headline, Bangla or as in the page, max 100 chars","paper":"name of the newspaper/site in Bangla","summary":"1-2 neutral Bangla sentences in your own words","date":"YYYY-MM-DD or empty"}. '
        . 'Topics the owner follows: ' . implode('; ', $tl) . '. topic = the id of the best matching topic, or none. '
        . 'Rules: kind=news only for a real news article/report. relevant=true only if the page is really about one of these topics' . ($manual ? ', or is clearly education news a school/coaching site would share' : '') . '; a page that merely mentions a keyword in passing is NOT relevant. '
        . 'negative=true if the page says or implies ANYTHING bad, critical, controversial, scandalous, a complaint, fraud, accident, case, protest, political fight, unfair result, or any doubtful/sensational claim about Shaheen, Spelling Star, Channel i or their people, or is negative news in general (exam leak, crime, corruption, tragedy). When in doubt, negative=true. '
        . ($cfg['extra'] !== '' ? 'Extra instruction from the owner: ' . $cfg['extra'] . ' ' : '')
        . 'Never invent facts; date only if clearly written in the page.';
    $user = 'Link: ' . $item['url'] . "\nTitle: " . $item['title'] . "\nText:\n" . mb_substr((string) $item['text'], 0, 3500);
    $model = function_exists('sb_model_for') ? sb_model_for('headlines') : 'gpt-6.1-terra';
    $body  = array('model' => $model, 'input' => array(array('role' => 'system', 'content' => $sys), array('role' => 'user', 'content' => $user)), 'max_output_tokens' => 700);
    if (preg_match('/^(gpt-[5-9]|o\d)/i', $model)) {
        $body['reasoning'] = array('effort' => 'low');
    }
    if (function_exists('sb_meter_take')) {
        sb_meter_take();
    }
    $r = wp_remote_post('https://api.openai.com/v1/responses', array('timeout' => 70, 'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'), 'body' => wp_json_encode($body)));
    if (is_wp_error($r)) {
        return $r;
    }
    $d = json_decode(wp_remote_retrieve_body($r), true);
    if (200 !== (int) wp_remote_retrieve_response_code($r) || !is_array($d)) {
        if (function_exists('sb_meter_take')) {
            sb_meter_take();
        }
        return new WP_Error('sb_ca', 'AI উত্তর দেয়নি');
    }
    $txt = '';
    foreach ((array) ($d['output'] ?? array()) as $it) {
        foreach ((array) ($it['content'] ?? array()) as $c) {
            $txt .= $c['text'] ?? '';
        }
    }
    $real = function_exists('sb_meter_take') ? sb_meter_take() : 0.0;
    if ($real > 0 && function_exists('sb_ledger_add')) {
        $GLOBALS['sb_ledger_model'] = $model;
        sb_ledger_add(0, 'research_collect', max(1, (int) ceil($real * SB_RESEARCH_MULT / sb_unit_billed())), 0, $real);
    }
    $j = json_decode(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($txt)), true);
    if (!is_array($j) || !isset($j['negative'])) {
        return new WP_Error('sb_ca', 'AI-র উত্তর পড়া যায়নি');
    }
    return $j;
}

/** put a line into the event page's news section (creates the section when missing) */
function sb_ca_event_line($slug, $title, $url, $meta) {
    if (!function_exists('csa_ev_list')) {
        return false;
    }
    $l = csa_ev_list();
    foreach ($l as $i => $e) {
        if ($e['slug'] !== $slug) {
            continue;
        }
        $line = str_replace(array('|', "\n"), ' ', $title) . ' | ' . $url . ' | ' . str_replace(array('|', "\n"), ' ', $meta);
        $si   = -1;
        foreach ((array) $e['vsec'] as $k => $vs) {
            if (false !== mb_strpos((string) $vs['t'], 'সংবাদে')) {
                $si = $k;
            }
        }
        if ($si < 0) {
            $e['vsec'][] = array('ic' => '📰', 't' => 'সংবাদে ' . $e['title'], 'p' => 'সংবাদমাধ্যমে প্রকাশিত খবর', 'lines' => $line);
        } else {
            if (false !== strpos((string) $e['vsec'][$si]['lines'], '| ' . $url . ' |')) {
                return true;
            }
            $e['vsec'][$si]['lines'] = $line . "\n" . $e['vsec'][$si]['lines'];
        }
        $l[$i] = $e;
        csa_ev_put($l);
        if (function_exists('csa_ev_purge')) {
            csa_ev_purge();
        }
        return true;
    }
    return false;
}

function sb_ca_bn_date($ymd) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $ymd, $m)) {
        return '';
    }
    $M = array('জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর');
    return sb_bn_digits((int) $m[3]) . ' ' . $M[(int) $m[2] - 1] . ' ' . sb_bn_digits($m[1]);
}

/** judge + file one collected item; returns array(status, note). status: filed|blocked|skipped|dup|error */
function sb_ca_process($item, $manual = true) {
    $cfg = sb_ca_cfg();
    if (function_exists('sb_press_url_exists') && sb_press_url_exists($item['url'])) {
        return array('dup', 'এই লিংক আগেই আছে।');
    }
    $nw = function_exists('sb_neg_hit') ? sb_neg_hit($item['title'] . ' ' . mb_substr((string) $item['text'], 0, 400)) : '';
    if ('' !== $nw) {   // negative word: stop before any AI cost
        if (function_exists('sb_notify_queue')) {
            sb_notify_queue('blocked', $item['title'] ?: $item['url'], $item['url'], 'নেতিবাচক শব্দ: ' . $nw);
        }
        return array('blocked', 'নেতিবাচক শব্দ পাওয়া গেছে: ' . $nw);
    }
    $j = sb_ca_ai($item, $cfg, $manual);
    if (is_wp_error($j)) {
        return array('error', $j->get_error_message() . ' — যাচাই ছাড়া কিছু বসানো হয়নি।');
    }
    $why = sanitize_text_field((string) ($j['reason'] ?? ''));
    if (!empty($j['negative'])) {
        if (function_exists('sb_notify_queue')) {
            sb_notify_queue('blocked', $item['title'] ?: $item['url'], $item['url'], $why !== '' ? $why : 'নেতিবাচক বা সন্দেহজনক');
        }
        return array('blocked', $why !== '' ? $why : 'নেতিবাচক বা সন্দেহজনক');
    }
    if (($j['kind'] ?? '') !== 'news' || empty($j['relevant'])) {
        return array('skipped', $why !== '' ? $why : 'সংবাদ নয় বা বিষয়ের সাথে মেলে না');
    }
    $title = sanitize_text_field((string) ($j['title'] ?? '')) ?: $item['title'];
    $paper = sanitize_text_field((string) ($j['paper'] ?? '')) ?: $item['host'];
    $date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($j['date'] ?? '')) ? $j['date'] : '';
    $sum   = sanitize_textarea_field((string) ($j['summary'] ?? ''));
    $img   = (string) ($item['images'][0] ?? '');
    $tp    = null;
    foreach (sb_ca_topics() as $t) {
        if ((string) ($j['topic'] ?? '') === $t['id']) {
            $tp = $t;
        }
    }
    $cat   = $tp ? (int) $tp['cat'] : (int) $cfg['cat'];
    $id    = sb_press_make($title, $item['url'], $paper, $date, $sum, $img, $cat);
    if (!$id) {
        return array('error', 'ব্লগে কার্ড বানানো যায়নি।');
    }
    $where = 'ব্লগ';
    if ($tp && $tp['event'] !== '' && sb_ca_event_line($tp['event'], $title, $item['url'], $paper . ($date ? ' · ' . sb_ca_bn_date($date) : ''))) {
        $where = 'ব্লগ + ইভেন্ট পেজ';
    }
    if (function_exists('sb_notify_queue')) {
        sb_notify_queue('filed', $title, $item['url'], $paper . ' · ' . $where);
    }
    return array('filed', $where . ($tp ? ' · বিষয়: ' . $tp['name'] : ''));
}

function sb_ca_mark($id, $st, $note) {
    $l = sb_collect_items();
    foreach ($l as $k => $x) {
        if ($x['id'] === $id) {
            $l[$k]['st']   = $st;
            $l[$k]['note'] = $note;
        }
    }
    update_option('sb_collected', $l, false);
}

/** called right after an item is collected */
add_action('sb_collected_new', function ($item) {
    $cfg = sb_ca_cfg();
    if (empty($cfg['on'])) {
        return;
    }
    list($st, $note) = sb_ca_process($item, false);
    sb_ca_mark($item['id'], $st, $note);
});

/** admin: save settings / file the items that are still waiting (max 3 per call) */
add_action('wp_ajax_sb_ca_save', function () {
    sb_ajax_guard('manage_options');
    $c = array('on' => sb_req('on') ? 1 : 0, 'extra' => sanitize_text_field((string) sb_req('extra')), 'disc' => sb_req('disc') ? 1 : 0);
    $tj = json_decode(wp_unslash((string) ($_POST['topics'] ?? '')), true);   // phpcs:ignore WordPress.Security.NonceVerification
    if (is_array($tj)) {
        $c['topics'] = array();
        foreach (array_slice($tj, 0, 12) as $i => $t) {
            $name = sanitize_text_field((string) ($t['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $c['topics'][] = array('id' => 't' . ($i + 1), 'name' => $name, 'kw' => sanitize_text_field((string) ($t['kw'] ?? '')), 'q' => sanitize_textarea_field((string) ($t['q'] ?? '')),
                'event' => sanitize_title((string) ($t['event'] ?? '')), 'cat' => (int) ($t['cat'] ?? 0));
        }
    }
    $c = array_merge(sb_ca_cfg(), $c);
    update_option('sb_collect_auto', $c, false);
    wp_send_json_success(array('ok' => 1));
});
add_action('wp_ajax_sb_ca_run', function () {
    sb_ajax_guard('edit_posts');
    $done = 0;
    foreach (sb_collect_items() as $x) {
        if ($done >= 3) {
            break;
        }
        if (!empty($x['st']) && $x['st'] !== 'error') {
            continue;
        }
        list($st, $note) = sb_ca_process($x);
        sb_ca_mark($x['id'], $st, $note);
        $done++;
    }
    $left = 0;
    foreach (sb_collect_items() as $x) {
        if (empty($x['st']) || $x['st'] === 'error') {
            $left++;
        }
    }
    wp_send_json_success(array('done' => $done, 'left' => $left));
});

function sb_ca_panel() {
    $c    = sb_ca_cfg();
    $cats = get_terms(array('taxonomy' => 'sb_category', 'hide_empty' => false, 'orderby' => 'term_id'));
    $evs  = function_exists('csa_ev_list') ? csa_ev_list() : array();
    ?>
    <div style="border:1px solid #d1d5db;border-radius:8px;padding:12px;margin:12px 0;background:#f8fafc">
        <b>🤖 নিজে নিজে বসানো (AI যাচাই করে)</b>
        <p class="sba-muted" style="margin:4px 0 8px">সংগ্রহ করা প্রতিটি পেজ আগে AI দেখে। নেতিবাচক, সমালোচনা, বিতর্ক, অভিযোগ বা সন্দেহজনক কিছু থাকলে <b>আটকে দেয়</b>, কিছুই বসায় না। ভালো শিক্ষা-সংবাদ হলে ব্লগের "সংবাদপত্রে শাহীন"-এ কার্ড বানায়; Spelling Star-এর খবর হলে ইভেন্ট পেজের সংবাদ অংশেও বসায়। প্রতিটি পেজের জন্য AI-এর খরচ (প্রকৃত খরচ × ১.৭) কাটে। AI চাবি না থাকলে কিছুই বসে না।</p>
        <label><input type="checkbox" id="ca-on" <?php checked($c['on'], 1); ?>> চালু</label>
        <p style="margin:8px 0"><b>🎯 কী কী খুঁজবে (বিষয়)</b> — প্রতিটি বিষয়ের নিজের শব্দ, সার্চ আর গন্তব্য। ইভেন্ট পেজ "না" দিলে শুধু ব্লগে যাবে।</p>
        <div id="ca-topics">
        <?php foreach (sb_ca_topics() as $t) : ?>
            <div class="ca-t" style="border:1px solid #cbd5e1;border-radius:6px;padding:8px;margin:6px 0;background:#fff">
                <input type="text" class="t-name" value="<?php echo esc_attr($t['name']); ?>" placeholder="বিষয়ের নাম (যেমন: শাহীন কোচিং)" style="width:60%">
                <button type="button" class="button t-del" style="float:right">✕ মুছুন</button>
                <p style="margin:6px 0"><label>শব্দ (| দিয়ে আলাদা; এর কোনোটি থাকলে তবেই দেখবে) <input type="text" class="t-kw" value="<?php echo esc_attr($t['kw']); ?>" style="width:100%"></label></p>
                <p style="margin:6px 0"><label>সার্চ (প্রতি লাইনে একটি) <textarea class="t-q" rows="3" style="width:100%"><?php echo esc_textarea($t['q']); ?></textarea></label></p>
                <p style="margin:6px 0"><label>ইভেন্ট পেজ <select class="t-ev"><option value="">না — শুধু ব্লগ</option>
                    <?php foreach ($evs as $e) : ?><option value="<?php echo esc_attr($e['slug']); ?>" <?php selected($t['event'], $e['slug']); ?>><?php echo esc_html($e['title']); ?></option><?php endforeach; ?></select></label>
                &nbsp; <label>ব্লগ ক্যাটাগরি <select class="t-cat"><option value="0">—</option>
                    <?php if (!is_wp_error($cats)) : foreach ($cats as $ct) : ?><option value="<?php echo (int) $ct->term_id; ?>" <?php selected((int) $t['cat'], $ct->term_id); ?>><?php echo esc_html($ct->name); ?></option><?php endforeach; endif; ?></select></label></p>
            </div>
        <?php endforeach; ?>
        </div>
        <p><button type="button" class="button" id="ca-addt">➕ নতুন বিষয়</button></p>
        <p style="margin:8px 0"><label>আরও নির্দেশ (ঐচ্ছিক) <input type="text" id="ca-extra" value="<?php echo esc_attr($c['extra']); ?>" style="width:100%" placeholder="যেমন: রাজনীতির খবর বাদ দাও"></label></p>
        <p style="margin:8px 0"><label><input type="checkbox" id="ca-disc" <?php checked($c['disc'], 1); ?>> 🔎 নতুন সংবাদ <b>নিজে নিজে খুঁজুন</b> (প্রতি ৬ ঘণ্টায় একবার; খোঁজার খরচ প্রতি বার $০.০১, পাওয়া প্রতিটি পেজ AI যাচাইয়ের খরচ আলাদা)</label></p>
        <?php /* history backfill UI hidden: Bing web RSS returns nothing now (checked live) */ ?>
        <?php if (!empty($c['dlog'])) : ?><p class="sba-muted" style="margin:4px 0">শেষ খোঁজা: <?php echo esc_html(wp_date('d M, H:i', (int) $c['dlast'])); ?> — <?php echo esc_html((string) $c['dlog'][0]); ?></p><?php endif; ?>
        <p><button type="button" class="button" id="ca-save">💾 সেভ</button> <button type="button" class="button" id="ca-find">🔎 এখনই খুঁজুন</button> <button type="button" class="button button-primary" id="ca-run">▶ আগে সংগ্রহ করা বাকিগুলো এখন বসান</button> <span id="ca-msg" class="sba-msg"></span></p>
    </div>
    <?php
    if (function_exists('sb_notify_panel')) {
        sb_notify_panel();
    }
}


/* ------------------------------------------------------------------ automatic discovery (no browser needed) */

/** news search results from Bing News RSS (free, no key). Returns [ [title, url, desc, date], ... ] */
function sb_ca_search($q, $web = false) {
    $r = wp_remote_get('https://www.bing.com/' . ($web ? 'search' : 'news/search') . '?q=' . rawurlencode($q) . '&format=rss&setlang=bn&cc=BD' . ($web ? '&count=30' : ''), array('timeout' => 15, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36'));
    if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r)) {
        return null;
    }
    $prev = libxml_use_internal_errors(true);
    $x    = simplexml_load_string((string) wp_remote_retrieve_body($r));
    libxml_use_internal_errors($prev);
    if (!$x || !isset($x->channel->item)) {
        return array();
    }
    $out = array();
    foreach ($x->channel->item as $it) {
        $u = (string) $it->link;
        $p = wp_parse_url($u);
        if (!empty($p['query'])) {
            parse_str($p['query'], $qq);
            if (!empty($qq['url'])) {
                $u = (string) $qq['url'];
            }
        }
        if (!preg_match('#^https?://#i', $u)) {
            continue;
        }
        $out[] = array(trim((string) $it->title), esc_url_raw($u), trim(wp_strip_all_tags((string) $it->description)), (string) $it->pubDate);
    }
    return $out;
}

/** open a news page on the server: title, readable text, cover picture. null when it cannot be read. */
function sb_ca_read($url) {
    $r = wp_remote_get($url, array('timeout' => 12, 'redirection' => 4, 'limit_response_size' => 600000,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36', 'headers' => array('Accept-Language' => 'bn,en;q=0.8')));
    if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r)) {
        return null;
    }
    $h = (string) wp_remote_retrieve_body($r);
    $m = function_exists('csa_ev_og_meta') ? csa_ev_og_meta($h) : array();
    $t = $m['og:title'] ?? '';
    if ($t === '' && preg_match('#<title[^>]*>(.*?)</title>#is', $h, $tm)) {
        $t = html_entity_decode(trim($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $h2 = preg_replace('#<(script|style|noscript|nav|footer|header)\b.*?</\1>#is', ' ', $h);
    $tx = '';
    if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $h2, $ps)) {
        foreach ($ps[1] as $p) {
            $p = trim(html_entity_decode(wp_strip_all_tags($p), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (mb_strlen($p) > 30) {
                $tx .= $p . "\n";
            }
            if (mb_strlen($tx) > 4000) {
                break;
            }
        }
    }
    if ($tx === '') {
        $tx = $m['og:description'] ?? ($m['description'] ?? '');
    }
    if ($t === '' && $tx === '') {
        return null;
    }
    $img = $m['og:image'] ?? ($m['twitter:image'] ?? '');
    return array('url' => esc_url_raw($url), 'host' => (string) wp_parse_url($url, PHP_URL_HOST), 'title' => sanitize_text_field($t), 'text' => sanitize_textarea_field($tx),
        'images' => (preg_match('#^https?://#i', $img) ? array(esc_url_raw($img)) : array()), 'videos' => array());
}

/** read, judge and file candidate pages. $seen is updated by reference. Returns counters. */
function sb_ca_file($cand, $cap, &$seen) {
    $n = array('filed' => 0, 'blocked' => 0, 'skipped' => 0, 'error' => 0, 'dup' => 0, 'unread' => 0);
    foreach ($cand as $u => $r) {
        if ($cap-- <= 0) {
            break;
        }
        $orig = $u;
        if (function_exists('sb_gn_is_google') && sb_gn_is_google($u)) {   // Google News link: open it to the newspaper's real address first
            $real = sb_gn_resolve($u);
            if ('' === $real) {
                $fl = get_option('sb_gn_fail', array());
                $fl = is_array($fl) ? $fl : array();
                $fl[md5($u)] = (int) ($fl[md5($u)] ?? 0) + 1;
                if ($fl[md5($u)] >= 3) {
                    $seen[md5($u)] = time();
                }
                update_option('sb_gn_fail', array_slice($fl, -500, null, true), false);
                $n['unread']++;
                continue;
            }
            if (isset($seen[md5($real)]) || sb_press_url_exists($real)) {
                $seen[md5($u)] = time();
                $n['dup']++;
                continue;
            }
            $u = $real;
        }
        $item = sb_ca_read($u);
        if (!$item) {
            $n['unread']++;
            $seen[md5($u)] = time();
            $seen[md5($orig)] = time();
            continue;
        }
        $item['id'] = substr(md5($u . microtime(true)), 0, 10);
        $item['t']  = time();
        list($st, $note) = sb_ca_process($item, false);
        if ('error' === $st) {   // try again next round
            $n['error']++;
            continue;
        }
        $seen[md5($u)] = time();
        $seen[md5($orig)] = time();
        $n[$st] = ($n[$st] ?? 0) + 1;
        $item['st']   = $st;
        $item['note'] = $note;
        $item['src']  = 'auto';
        $l = sb_collect_items();
        array_unshift($l, $item);
        update_option('sb_collected', array_slice($l, 0, SB_COLLECT_MAX), false);
    }
    update_option('sb_ca_seen', array_slice($seen, -3000, null, true), false);
    return $n;
}

/** one round of discovery. $force skips the on/off switch. Returns a short Bangla note. */
function sb_ca_discover($force = false) {
    $cfg = sb_ca_cfg();
    if (!$force && (empty($cfg['on']) || empty($cfg['disc']))) {
        return '';
    }
    $seen  = get_option('sb_ca_seen', array());
    $seen  = is_array($seen) ? $seen : array();
    $cand  = array();
    $looks = 0;
    foreach (sb_ca_topics() as $t) {
        $words = array_filter(array_map('trim', explode('|', (string) $t['kw'])));
        foreach (array_slice(array_filter(array_map('trim', explode("\n", (string) $t['q']))), 0, 6) as $q) {
            $res = sb_ca_search($q);
            $gn  = function_exists('sb_ca_gnews') ? sb_ca_gnews($q) : null;
            if (null === $res && null === $gn) {
                continue;
            }
            $res = array_merge((array) $res, (array) $gn);
            $looks++;
            foreach ($res as $r) {
                $hay = $r[0] . ' ' . $r[2];
                $ok  = !$words;
                foreach ($words as $w) {
                    if (false !== mb_stripos($hay, $w)) {
                        $ok = true;
                    }
                }
                if ($ok && !isset($seen[md5($r[1])]) && !sb_press_url_exists($r[1])) {
                    $nw = function_exists('sb_neg_hit') ? sb_neg_hit($r[0] . ' ' . $r[2]) : '';
                    if ('' !== $nw) {   // negative word in headline/summary: never opened, never published; the team is told
                        $seen[md5($r[1])] = time();
                        if (function_exists('sb_notify_queue')) {
                            sb_notify_queue('blocked', $r[0], $r[1], 'নেতিবাচক শব্দ: ' . $nw);
                        }
                        continue;
                    }
                    $cand[$r[1]] = $r;
                }
            }
        }
    }
    if ($looks && function_exists('sb_ledger_add') && defined('SB_YT_CHECK_FEE')) {   // fixed fee per look, no AI
        $GLOBALS['sb_bill_override'] = SB_YT_CHECK_FEE;
        sb_ledger_add(0, 'news_watch', max(1, (int) ceil(SB_YT_CHECK_FEE / sb_unit_billed())), 0, 0.0);
    }
    $n = sb_ca_file($cand, 8, $seen);
    $msg = $looks ? ('পেলাম ' . count($cand) . 'টি নতুন · বসানো ' . $n['filed'] . ', ⛔ আটকানো ' . $n['blocked'] . ', বাদ ' . ($n['skipped'] + $n['dup'] . '') . ($n['unread'] ? ', পড়া যায়নি ' . $n['unread'] : '') . ($n['error'] ? ', ⚠ যাচাই হয়নি ' . $n['error'] : '')) : 'সার্চ সাড়া দেয়নি';
    $c = sb_ca_cfg();
    $c['dlast'] = time();
    $c['dlog']  = array_slice(array_merge(array($msg), (array) $c['dlog']), 0, 5);
    update_option('sb_collect_auto', $c, false);
    return $msg;
}


/** history backfill: topic x year web searches, a few per call, progress is remembered. */
function sb_ca_backfill($reset = false) {
    $st = get_option('sb_ca_bf', array());
    $st = is_array($st) ? $st : array();
    $from = max(2000, (int) ($st['from'] ?? 2010));
    $to   = (int) wp_date('Y');
    if ($reset || empty($st['y'])) {
        $st = array('from' => $from, 'y' => $from, 'ti' => 0, 'qi' => 0, 'done' => 0, 'found' => 0, 'filed' => 0, 'blocked' => 0);
    }
    $topics = sb_ca_topics();
    $seen   = get_option('sb_ca_seen', array());
    $seen   = is_array($seen) ? $seen : array();
    $cand   = array();
    $steps  = 0;
    $looks  = 0;
    while ($steps < 4 && $st['y'] <= $to) {
        $t = $topics[$st['ti']] ?? null;
        if ($t) {
            $qs = array_values(array_filter(array_map('trim', explode("\n", (string) $t['q']))));
            $q  = $qs[$st['qi']] ?? null;
            if ($q) {
                $words = array_filter(array_map('trim', explode('|', (string) $t['kw'])));
                $res   = sb_ca_search($q . ' ' . $st['y'], true);
                $looks++;
                foreach ((array) $res as $r) {
                    $hay = $r[0] . ' ' . $r[2];
                    $ok  = !$words;
                    foreach ($words as $w) {
                        if (false !== mb_stripos($hay, $w)) {
                            $ok = true;
                        }
                    }
                    if ($ok && !isset($seen[md5($r[1])]) && !sb_press_url_exists($r[1])) {
                        $cand[$r[1]] = $r;
                    }
                }
                $steps++;
            }
        }
        $st['qi']++;      // next query -> next topic -> next year
        if (!$t || $st['qi'] >= max(1, count(array_filter(array_map('trim', explode("\n", (string) ($t['q'] ?? ''))))))) {
            $st['qi'] = 0;
            $st['ti']++;
            if ($st['ti'] >= count($topics)) {
                $st['ti'] = 0;
                $st['y']++;
            }
        }
    }
    if ($looks && function_exists('sb_ledger_add') && defined('SB_YT_CHECK_FEE')) {
        $GLOBALS['sb_bill_override'] = SB_YT_CHECK_FEE;
        sb_ledger_add(0, 'news_watch', max(1, (int) ceil(SB_YT_CHECK_FEE / sb_unit_billed())), 0, 0.0);
    }
    $n = sb_ca_file(array_slice($cand, 0, 6, true), 6, $seen);
    $st['found']   += count($cand);
    $st['filed']   += $n['filed'];
    $st['blocked'] += $n['blocked'];
    $fin = $st['y'] > $to;
    $st['finished'] = $fin;
    update_option('sb_ca_bf', $st, false);
    return array('year' => min($st['y'], $to), 'to' => $to, 'from' => $st['from'], 'finished' => $fin, 'found' => $st['found'], 'filed' => $st['filed'], 'blocked' => $st['blocked']);
}
add_action('wp_ajax_sb_ca_bf', function () {
    sb_ajax_guard('manage_options');
    $from = (int) sb_req('from', 0);
    if ($from) {
        $st = get_option('sb_ca_bf', array());
        $st = is_array($st) ? $st : array();
        $st['from'] = max(2000, min(2026, $from));
        update_option('sb_ca_bf', $st, false);
    }
    wp_send_json_success(sb_ca_backfill((bool) sb_req('reset')));
});

add_filter('cron_schedules', function ($s) {
    $s['sb_6h'] = array('interval' => 6 * HOUR_IN_SECONDS, 'display' => 'Every 6 hours');
    return $s;
});
add_action('init', function () {
    $cfg = sb_ca_cfg();
    $on  = !empty($cfg['on']) && !empty($cfg['disc']);
    $nx  = wp_next_scheduled('sb_ca_discover_tick');
    if ($on && !$nx) {
        wp_schedule_event(time() + 600, 'sb_6h', 'sb_ca_discover_tick');
    } elseif (!$on && $nx) {
        wp_clear_scheduled_hook('sb_ca_discover_tick');
    }
});
add_action('sb_ca_discover_tick', function () {
    sb_ca_discover(false);
});
add_action('wp_ajax_sb_ca_find', function () {
    sb_ajax_guard('edit_posts');
    wp_send_json_success(array('msg' => sb_ca_discover(true)));
});
