<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * YouTube SEO backfill.
 *
 *  - Every video of the channel is improved, most-viewed first, SB_YTS_PER (40) videos per day, until the whole list is done.
 *  - Per video: a short keyword-rich summary on top of the description, tags, a better title ONLY if the title is weak and the
 *    video is not a popular one, and a place in the existing playlists (more than one is fine). No playlist is created here.
 *  - New videos are put at the front of the queue by the daily run, so every new video gets the same treatment.
 *  - Every January the facts block (campuses, districts ...) is refreshed in ALL videos (sb_ytm_start_sync with no 100 limit).
 *  Price: one bill per working day = real AI cost x 1.5 (never below 0.20), shown only as the total on client screens.
 */
const SB_YTS_PER = 40;

function sb_yts_cfg() {
    $o = get_option('sb_yts', array());
    return array_merge(array('on' => 0, 'per' => SB_YTS_PER, 'queue' => array(), 'done' => array(), 'built' => 0, 'pop' => 0, 'day' => '', 'today' => 0, 'year' => 0, 'real' => 0.0, 'ndone' => 0, 'log' => '', 'fails' => 0), is_array($o) ? $o : array());
}

function sb_yts_save($p) {
    update_option('sb_yts', array_merge(sb_yts_cfg(), $p), false);
}

function sb_yts_schedule($in = 20) {
    if (!wp_next_scheduled('sb_yts_tick')) {
        wp_schedule_single_event(time() + $in, 'sb_yts_tick');
    }
}

/** the queue: first time = all videos, most views first; later = new videos go to the front */
function sb_yts_build() {
    $c   = sb_yts_cfg();
    $ids = sb_ytm_ids(3000);
    if (is_wp_error($ids)) {
        return $ids;
    }
    $known = array_flip(array_merge(array_keys($c['done']), $c['queue']));
    $new   = array_values(array_filter($ids, function ($x) use ($known) {
        return !isset($known[$x]);
    }));
    if (!$new) {
        sb_yts_save(array('built' => time()));
        return true;
    }
    $views = array();
    foreach (array_chunk($new, 50) as $ch) {
        $r = sb_ytm_call('GET', 'videos', array('part' => 'statistics', 'id' => implode(',', $ch)));
        if (is_wp_error($r)) {
            return $r;
        }
        foreach ((array) ($r['items'] ?? array()) as $v) {
            $views[$v['id']] = (int) ($v['statistics']['viewCount'] ?? 0);
        }
    }
    if (empty($c['built'])) {   // first build: most viewed first, and "popular" = the top fifth by views
        usort($new, function ($a, $b) use ($views) {
            return ($views[$b] ?? 0) <=> ($views[$a] ?? 0);
        });
        $vals = array_values($views);
        rsort($vals);
        $pop = $vals ? (int) $vals[(int) floor(count($vals) * 0.2)] : 0;
        sb_yts_save(array('queue' => $new, 'built' => time(), 'pop' => max(1, $pop)));
    } else {
        sb_yts_save(array('queue' => array_merge($new, $c['queue']), 'built' => time()));   // new uploads: newest first, before the old ones
    }
    return true;
}

/** playlist id => name + videos already in it (cached for 3 hours; the retired branch playlist is never a target) */
function sb_yts_playlists($force = false) {
    $m = get_transient('sb_yts_pl');
    if (is_array($m) && !$force) {
        return $m;
    }
    $m  = array();
    $pt = '';
    do {
        $q = array('part' => 'snippet', 'mine' => 'true', 'maxResults' => 50);
        if ('' !== $pt) {
            $q['pageToken'] = $pt;
        }
        $r = sb_ytm_call('GET', 'playlists', $q);
        if (is_wp_error($r)) {
            return $r;
        }
        foreach ((array) ($r['items'] ?? array()) as $p) {
            $nm = trim((string) $p['snippet']['title']);
            if (!preg_match('/উত্তরা|uttara/iu', $nm)) {
                $m[$p['id']] = array('name' => $nm, 'v' => array());
            }
        }
        $pt = (string) ($r['nextPageToken'] ?? '');
    } while ('' !== $pt);
    foreach (array_keys($m) as $pid) {
        $pt = '';
        do {
            $q = array('part' => 'contentDetails', 'playlistId' => $pid, 'maxResults' => 50);
            if ('' !== $pt) {
                $q['pageToken'] = $pt;
            }
            $r = sb_ytm_call('GET', 'playlistItems', $q);
            if (is_wp_error($r)) {
                return $r;
            }
            foreach ((array) ($r['items'] ?? array()) as $it) {
                $m[$pid]['v'][] = (string) $it['contentDetails']['videoId'];
            }
            $pt = (string) ($r['nextPageToken'] ?? '');
        } while ('' !== $pt);
    }
    set_transient('sb_yts_pl', $m, 3 * HOUR_IN_SECONDS);
    return $m;
}

function sb_yts_block() {
    $b = trim((string) sb_ytm_cfg()['block']);
    if ('' !== $b) {
        return $b;
    }
    $f = sb_ytm_facts();
    return is_wp_error($f) ? '' : sb_ytm_block($f);
}

function sb_yts_sys() {
    return 'তুমি শাহীন শিক্ষা পরিবারের ইউটিউব চ্যানেলের SEO সম্পাদক। প্রতিটি ভিডিওর শুধু দেওয়া নাম ও বিবরণ থেকে কাজ করবে; নিজে কোনো তথ্য, সংখ্যা, নাম বা ঘটনা বানাবে না। '
        . 'প্রতিটি ভিডিওর জন্য: (১) "title": শুধু যদি নামটি দুর্বল হয় (যেমন VID_2023, Untitled, মানে বোঝা যায় না, খুব ছোট, কোনো বিষয় নেই) তাহলে ৭০ অক্ষরের মধ্যে পরিষ্কার সহজ চলতি বাংলা নাম; নামটি ভালো হলে খালি "" দেবে, সন্দেহ হলেও ""। '
        . '(২) "sum": ২-৩ বাক্যের স্বাভাবিক সারাংশ (প্রয়োজনে প্রধান ইংরেজি শব্দসহ) যাতে ভিডিওর আসল বিষয়ের কীওয়ার্ড থাকে; বিবরণে যা নেই তা যোগ করবে না। "শাহীন" একা লিখবে না, লিখবে "শাহীন শিক্ষা পরিবার" বা "শাহীন স্কুল ও ক্যাডেট কোচিং"। '
        . '(৩) "tags": ৮-১২টি ট্যাগ, বাংলা ও ইংরেজি মিলিয়ে, # ছাড়া। '
        . '(৪) "pl": নিচের প্লেলিস্ট তালিকা থেকে হুবহু নাম, যতগুলোতে ভিডিওটি সত্যিই মানায় (০-৩টি); না মানালে খালি তালিকা। তালিকার বাইরের নাম কখনো দেবে না। '
        . 'শুধু এই JSON: {"v":[{"id":"...","title":"","sum":"...","tags":["..."],"pl":["..."]}]}';
}

/** one step = one small batch; returns more | day | empty | quota | err */
function sb_yts_step() {
    $c = sb_yts_cfg();
    if (empty($c['on'])) {
        return 'off';
    }
    if ((int) $c['today'] >= (int) $c['per']) {
        return 'day';
    }
    if (!$c['queue']) {
        return 'empty';
    }
    if ('' === (string) sb_get('api_key')) {
        sb_yts_save(array('log' => 'AI key missing'));
        return 'err';
    }
    $batch = array_slice($c['queue'], 0, min(4, (int) $c['per'] - (int) $c['today']));
    $r     = sb_ytm_call('GET', 'videos', array('part' => 'snippet,statistics', 'id' => implode(',', $batch)));
    if (is_wp_error($r)) {
        return 'sb_quota' === $r->get_error_code() ? 'quota' : sb_yts_fail($r->get_error_message());
    }
    $vid = array();
    foreach ((array) ($r['items'] ?? array()) as $v) {
        $vid[$v['id']] = $v;
    }
    $pls = sb_yts_playlists();
    if (is_wp_error($pls)) {
        return 'sb_quota' === $pls->get_error_code() ? 'quota' : sb_yts_fail($pls->get_error_message());
    }
    $rows = array();
    foreach ($batch as $id) {
        if (isset($vid[$id])) {
            $s      = $vid[$id]['snippet'];
            $rows[] = array('id' => $id, 'title' => (string) $s['title'], 'desc' => mb_substr(trim(preg_replace('/\s+/u', ' ', sb_ytm_apply_block((string) ($s['description'] ?? ''), ''))), 0, 500), 'tags' => array_slice((array) ($s['tags'] ?? array()), 0, 12));
        }
    }
    $out = array();
    if ($rows) {
        list($ai, $real) = sb_ytm_ai(sb_yts_sys() . ' প্লেলিস্ট তালিকা: ' . implode(' | ', array_map(function ($p) {
            return $p['name'];
        }, $pls)) . '.', wp_json_encode($rows, JSON_UNESCAPED_UNICODE));
        if (empty($ai['v']) || !is_array($ai['v'])) {
            return sb_yts_fail('AI gave no answer');
        }
        sb_yts_save(array('real' => (float) $c['real'] + (float) $real));
        foreach ($ai['v'] as $o) {
            if (!empty($o['id'])) {
                $out[(string) $o['id']] = $o;
            }
        }
    }
    $block = sb_yts_block();
    $c     = sb_yts_cfg();
    foreach ($batch as $id) {
        if (isset($vid[$id], $out[$id])) {
            $e = sb_yts_apply_one($vid[$id], $out[$id], $pls, $block, (int) $c['pop']);
            if ('quota' === $e) {
                return 'quota';   // not marked done; it is tried again later
            }
            if ('fail' === $e) {
                $c['fails']++;
            }
        }
        array_shift($c['queue']);
        $c['done'][$id] = time();
        $c['today']++;
        $c['ndone']++;
        sb_yts_save(array('queue' => $c['queue'], 'done' => $c['done'], 'today' => $c['today'], 'ndone' => $c['ndone'], 'fails' => $c['fails']));
    }
    return 'more';
}

function sb_yts_fail($m) {
    $c = sb_yts_cfg();
    sb_yts_save(array('fails' => (int) $c['fails'] + 1, 'log' => mb_substr($m, 0, 200)));
    if ((int) $c['fails'] + 1 >= 6) {
        sb_yts_save(array('on' => 0));
        sb_ytm_notify("▶️ <b>YouTube SEO stopped</b>\n" . esc_html($m));
    }
    return 'err';
}

/** write one video: title (weak + not popular only), summary, tags, playlists. returns ok | fail | quota */
function sb_yts_apply_one($v, $o, &$pls, $block, $pop) {
    $s     = $v['snippet'];
    $views = (int) ($v['statistics']['viewCount'] ?? 0);
    $title = (string) $s['title'];
    $nt    = trim(sanitize_text_field((string) ($o['title'] ?? '')));
    if ('' !== $nt && $views < $pop && mb_strlen($nt) >= 12 && mb_strlen($nt) <= 100 && $nt !== $title) {
        $title = $nt;
    }
    $old = str_replace("\r", '', (string) ($s['description'] ?? ''));
    $sum = trim(wp_strip_all_tags((string) ($o['sum'] ?? '')));
    $sum = mb_substr($sum, 0, 600);
    if ('' !== $sum && false === mb_strpos($old, mb_substr($sum, 0, 30))) {
        $old = $sum . "\n\n" . $old;
    }
    $desc = '' !== $block ? sb_ytm_apply_block($old, $block) : $old;
    if (strlen($desc) > 4800) {
        $desc = (string) $s['description'];
    }
    $tags = array();
    $len  = 0;
    foreach (array_merge((array) ($s['tags'] ?? array()), (array) ($o['tags'] ?? array())) as $t) {
        $t = trim(str_replace(array('#', '<', '>', '"', ','), '', mb_substr((string) $t, 0, 30)));
        $k = mb_strtolower($t);
        if ('' === $t || isset($tags[$k]) || $len + strlen($t) + 3 > 480) {
            continue;
        }
        $tags[$k] = $t;
        $len     += strlen($t) + 3;
    }
    $snip = array('title' => $title, 'description' => $desc, 'categoryId' => $s['categoryId'] ?? '27');
    if ($tags) {
        $snip['tags'] = array_values($tags);
    }
    foreach (array('defaultLanguage', 'defaultAudioLanguage') as $k) {
        if (!empty($s[$k])) {
            $snip[$k] = $s[$k];
        }
    }
    $r = sb_ytm_call('PUT', 'videos', array('part' => 'snippet'), array('id' => $v['id'], 'snippet' => $snip), 50);
    if (is_wp_error($r)) {
        return 'sb_quota' === $r->get_error_code() ? 'quota' : 'fail';
    }
    $want = array_map('mb_strtolower', array_map('trim', (array) ($o['pl'] ?? array())));
    $n    = 0;
    foreach ($pls as $pid => $p) {
        if ($n >= 3 || !in_array(mb_strtolower($p['name']), $want, true) || in_array($v['id'], $p['v'], true)) {
            continue;
        }
        $a = sb_ytm_call('POST', 'playlistItems', array('part' => 'snippet'), array('snippet' => array('playlistId' => $pid, 'resourceId' => array('kind' => 'youtube#video', 'videoId' => $v['id']))), 50);
        if (is_wp_error($a)) {
            return 'sb_quota' === $a->get_error_code() ? 'quota' : 'fail';
        }
        $pls[$pid]['v'][] = $v['id'];
        $n++;
    }
    set_transient('sb_yts_pl', $pls, 3 * HOUR_IN_SECONDS);
    return 'ok';
}

/** the end of a working day: one bill, one Telegram line */
function sb_yts_finish_day($why) {
    $c = sb_yts_cfg();
    if ((int) $c['today'] < 1) {
        return;
    }
    sb_ytm_bill(true, (float) $c['real'], 'ইউটিউব SEO: ' . (int) $c['today'] . 'টি ভিডিও');
    $left = count($c['queue']);
    sb_ytm_notify("▶️ <b>YouTube SEO</b>\nVideos improved today: " . (int) $c['today'] . "\nLeft in the list: " . $left . ('empty' === $why || !$left ? "\n✅ All videos are done. New videos will be handled automatically." : ''));
    sb_yts_save(array('real' => 0.0, 'today' => 0, 'day' => wp_date('Ymd'), 'fails' => 0));   // billed: the counter restarts, the next working day waits for tomorrow
    update_option('sb_yts_billed_day', wp_date('Ymd'), false);
}

add_action('sb_yts_tick', function () {
    $c = sb_yts_cfg();
    if (empty($c['on'])) {
        return;
    }
    if (get_transient('sb_yts_lock')) {
        sb_yts_schedule(60);
        return;
    }
    if (wp_date('Ymd') === get_option('sb_yts_billed_day', '')) {
        return;   // today's 40 are done
    }
    set_transient('sb_yts_lock', 1, 5 * MINUTE_IN_SECONDS);
    @set_time_limit(240);
    $t0 = microtime(true);
    do {
        $r = sb_yts_step();
    } while ('more' === $r && microtime(true) - $t0 < 35);
    delete_transient('sb_yts_lock');
    if ('more' === $r) {
        sb_yts_schedule(20);
    } elseif ('day' === $r || 'empty' === $r) {
        sb_yts_finish_day($r);
    } elseif ('quota' === $r) {
        sb_yts_schedule(3 * HOUR_IN_SECONDS);
    } elseif ('err' === $r) {
        sb_yts_schedule(30 * MINUTE_IN_SECONDS);
    }
});

/** daily: queue refresh (new uploads), start the day, and the January refresh of ALL videos */
add_action('init', function () {
    if (!wp_next_scheduled('sb_yts_daily')) {
        wp_schedule_event(time() + 900, 'daily', 'sb_yts_daily');
    }
});
add_action('sb_yts_daily', function () {
    $c = sb_yts_cfg();
    if (empty($c['on'])) {
        return;
    }
    $b = sb_yts_build();
    if (is_wp_error($b)) {
        sb_yts_save(array('log' => $b->get_error_message()));
    }
    if (1 === (int) wp_date('n') && (int) $c['year'] !== (int) wp_date('Y')) {   // every January: the facts block in every video
        $j = sb_ytm_job();
        if (empty($j) || 'run' !== ($j['state'] ?? '')) {
            $r = sb_ytm_start_sync(3000);
            if (!is_wp_error($r)) {
                sb_yts_save(array('year' => (int) wp_date('Y')));
                sb_ytm_notify("▶️ <b>Yearly YouTube update started</b>\nCampus numbers and other facts are being refreshed in all videos from the website.");
            }
        }
    }
    sb_yts_save(array('day' => wp_date('Ymd')));
    sb_yts_schedule(60);
});

/* ------------------------------------------------------------ admin */
add_action('wp_ajax_sb_yts', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('অনুমতি নেই।');
    }
    $do = sanitize_key((string) ($_POST['do'] ?? ''));
    if ('save' === $do) {
        $on = empty($_POST['on']) ? 0 : 1;
        sb_yts_save(array('on' => $on, 'per' => max(5, min(60, (int) ($_POST['per'] ?? SB_YTS_PER)))));
        if ($on) {
            $b = sb_yts_build();
            if (is_wp_error($b)) {
                wp_send_json_error($b->get_error_message());
            }
            delete_option('sb_yts_billed_day');
            sb_yts_schedule(5);
        }
    } elseif ('run' === $do) {
        sb_yts_save(array('on' => 1));
        delete_option('sb_yts_billed_day');
        sb_yts_schedule(5);
    }
    $c = sb_yts_cfg();
    wp_send_json_success(array('on' => (int) $c['on'], 'per' => (int) $c['per'], 'left' => count($c['queue']), 'done' => (int) $c['ndone'], 'today' => (int) $c['today'], 'built' => (int) $c['built'], 'log' => (string) $c['log'], 'fails' => (int) $c['fails']));
});

function sb_yts_panel() {
    $nonce = wp_create_nonce('sb_admin');
    ?>
    <div id="sb-yts" style="border:1px solid #d1d5db;border-radius:8px;padding:12px;margin:12px 0;background:#f0fdf4">
        <b>🔎 ইউটিউব SEO: সব পুরনো ভিডিও ঠিক করা</b>
        <p class="sba-muted" style="margin:4px 0 8px">সবচেয়ে বেশি ভিউয়ের ভিডিও আগে। প্রতিদিন নির্দিষ্ট সংখ্যক ভিডিওর সারাংশ, ট্যাগ ও প্লেলিস্ট ঠিক হয়; দুর্বল নাম হলে (জনপ্রিয় ভিডিও বাদে) নামও বদলায়। সব শেষ হলে নতুন ভিডিও নিজে নিজে একই নিয়মে যাবে। প্রতি জানুয়ারিতে সব ভিডিওর তথ্য (ক্যাম্পাস ইত্যাদি) ওয়েবসাইট থেকে নতুন হবে।</p>
        <p><label><input type="checkbox" id="ys-on"> <b>চালু</b></label> &nbsp; প্রতিদিন <input type="number" id="ys-per" min="5" max="60" value="40" style="width:70px"> টি ভিডিও &nbsp; <button class="button button-primary" id="ys-save">সেভ ও শুরু</button> <button class="button" id="ys-run">আজকের কাজ এখনই শুরু</button></p>
        <p id="ys-st" class="description"></p>
        <script>
        window.addEventListener('load',function(){
            var $=function(s){return document.querySelector(s);};
            function call(x){return fetch(window.ajaxurl,{method:'POST',credentials:'same-origin',body:new URLSearchParams(Object.assign({action:'sb_yts',nonce:'<?php echo esc_js($nonce); ?>'},x))}).then(function(r){return r.json();});}
            function show(d){$('#ys-on').checked=!!d.on;$('#ys-per').value=d.per;$('#ys-st').textContent='এ পর্যন্ত ঠিক হয়েছে: '+d.done+' · বাকি: '+d.left+' · আজ: '+d.today+'/'+d.per+(d.log?' · ⚠️ '+d.log:'');}
            function go(x){$('#ys-st').textContent='…';call(x).then(function(r){if(!r.success){$('#ys-st').textContent='❌ '+r.data;return;}show(r.data);});}
            $('#ys-save').onclick=function(){go({do:'save',on:$('#ys-on').checked?1:'',per:$('#ys-per').value});};
            $('#ys-run').onclick=function(){go({do:'run'});};
            go({do:'state'});
        });
        </script>
    </div>
    <?php
}
