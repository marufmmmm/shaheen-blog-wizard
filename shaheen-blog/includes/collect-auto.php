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
    return array_merge(array('on' => 1, 'cat' => 0, 'event' => 'spelling-star', 'kw' => 'Spelling Star|স্পেলিং স্টার', 'extra' => ''), $c);
}

function sb_ca_ai($item, $cfg) {
    $key = function_exists('sb_get') ? sb_get('api_key') : '';
    if ('' === (string) $key) {
        return new WP_Error('sb_ca', 'AI চাবি নেই');
    }
    $sys = 'You are the editor-in-chief gatekeeper of the news section of the website of "Shaheen Education Family" (Shaheen Cadet Coaching, Bangladesh) and of its TV competition "Spelling Star" (with Channel i). '
        . 'A page was collected by staff. Decide whether it may be published on our site. Reply with JSON only: '
        . '{"kind":"news|video|other","relevant":true|false,"topic":"event|other","negative":true|false,"reason":"short Bangla reason","title":"clean headline, Bangla or as in the page, max 100 chars","paper":"name of the newspaper/site in Bangla","summary":"1-2 neutral Bangla sentences in your own words","date":"YYYY-MM-DD or empty"}. '
        . 'Rules: kind=news only for a real news article/report. relevant=true only for education news, or anything about Shaheen Education Family / Spelling Star / Channel i / Shaheen Cadet Coaching. '
        . 'topic=event when it is about the competition "Spelling Star" ('. $cfg['kw'] . '), else other. '
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
function sb_ca_process($item) {
    $cfg = sb_ca_cfg();
    if (function_exists('sb_press_url_exists') && sb_press_url_exists($item['url'])) {
        return array('dup', 'এই লিংক আগেই আছে।');
    }
    $j = sb_ca_ai($item, $cfg);
    if (is_wp_error($j)) {
        return array('error', $j->get_error_message() . ' — যাচাই ছাড়া কিছু বসানো হয়নি।');
    }
    $why = sanitize_text_field((string) ($j['reason'] ?? ''));
    if (!empty($j['negative'])) {
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
    $id    = sb_press_make($title, $item['url'], $paper, $date, $sum, $img, (int) $cfg['cat']);
    if (!$id) {
        return array('error', 'ব্লগে কার্ড বানানো যায়নি।');
    }
    $where = 'ব্লগ';
    if (($j['topic'] ?? '') === 'event' && $cfg['event'] !== '' && sb_ca_event_line($cfg['event'], $title, $item['url'], $paper . ($date ? ' · ' . sb_ca_bn_date($date) : ''))) {
        $where = 'ব্লগ + ইভেন্ট পেজ';
    }
    return array('filed', $where);
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
    list($st, $note) = sb_ca_process($item);
    sb_ca_mark($item['id'], $st, $note);
});

/** admin: save settings / file the items that are still waiting (max 3 per call) */
add_action('wp_ajax_sb_ca_save', function () {
    sb_ajax_guard('manage_options');
    $c = array('on' => sb_req('on') ? 1 : 0, 'cat' => (int) sb_req('cat', 0), 'event' => sanitize_title((string) sb_req('event')),
        'kw' => sanitize_text_field((string) sb_req('kw')), 'extra' => sanitize_text_field((string) sb_req('extra')));
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
        <p style="margin:8px 0"><label>ব্লগের ক্যাটাগরি <select id="ca-cat"><option value="0">—</option>
            <?php if (!is_wp_error($cats)) : foreach ($cats as $t) : ?><option value="<?php echo (int) $t->term_id; ?>" <?php selected((int) $c['cat'], $t->term_id); ?>><?php echo esc_html($t->name); ?></option><?php endforeach; endif; ?></select></label>
        &nbsp; <label>ইভেন্ট পেজ <select id="ca-ev"><option value="">(ইভেন্ট পেজে বসাবে না)</option>
            <?php foreach ($evs as $e) : ?><option value="<?php echo esc_attr($e['slug']); ?>" <?php selected($c['event'], $e['slug']); ?>><?php echo esc_html($e['title']); ?></option><?php endforeach; ?></select></label></p>
        <p style="margin:8px 0"><label>ইভেন্টের শব্দ (| দিয়ে আলাদা) <input type="text" id="ca-kw" value="<?php echo esc_attr($c['kw']); ?>" style="width:260px"></label></p>
        <p style="margin:8px 0"><label>আরও নির্দেশ (ঐচ্ছিক) <input type="text" id="ca-extra" value="<?php echo esc_attr($c['extra']); ?>" style="width:100%" placeholder="যেমন: রাজনীতির খবর বাদ দাও"></label></p>
        <p><button type="button" class="button" id="ca-save">💾 সেভ</button> <button type="button" class="button button-primary" id="ca-run">▶ আগে সংগ্রহ করা বাকিগুলো এখন বসান</button> <span id="ca-msg" class="sba-msg"></span></p>
    </div>
    <?php
}
