<?php
/**
 * News watch helpers (3.3.0):
 *  - Google News RSS as a second source (links are encoded; they are opened to the real newspaper address)
 *  - negative-word pre-block (before any AI cost)
 *  - notifications: Telegram channel (bot) + e-mail whenever something is added to the website (and when something was held back)
 */
if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------- settings */
function sb_notify_cfg() {
    $o = get_option('sb_notify', array());
    $o = is_array($o) ? $o : array();
    return array_merge(array('on' => 1, 'tg_token' => '', 'tg_chat' => '', 'mail' => 'mosiur@shaheen.edu.bd', 'blocked' => 1), $o);
}

/* ---------------------------------------------------------------- Telegram + mail */
function sb_tg_send($html) {
    $o = sb_notify_cfg();
    if ('' === $o['tg_token'] || '' === $o['tg_chat']) {
        return new WP_Error('sb_tg', 'টেলিগ্রামের টোকেন বা চ্যানেল দেওয়া নেই।');
    }
    $r = wp_remote_post('https://api.telegram.org/bot' . $o['tg_token'] . '/sendMessage', array('timeout' => 15, 'body' => array(
        'chat_id' => $o['tg_chat'], 'text' => mb_substr($html, 0, 3900), 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true')));
    if (is_wp_error($r)) {
        return $r;
    }
    $j = json_decode(wp_remote_retrieve_body($r), true);
    if (empty($j['ok'])) {
        return new WP_Error('sb_tg', 'টেলিগ্রাম: ' . (is_array($j) && !empty($j['description']) ? $j['description'] : 'HTTP ' . (int) wp_remote_retrieve_response_code($r)));
    }
    return true;
}

function sb_notify_mail($subject, $plain) {
    $o  = sb_notify_cfg();
    $to = array_filter(array_map('trim', explode(',', (string) $o['mail'])), 'is_email');
    return $to ? wp_mail($to, $subject, $plain) : false;
}

/** queue one line; everything queued during a request goes out as ONE message at the end of the request */
function sb_notify_queue($kind, $title, $url, $note = '') {
    $o = sb_notify_cfg();
    if (empty($o['on']) || ('blocked' === $kind && empty($o['blocked']))) {
        return;
    }
    static $reg = false;
    $GLOBALS['sb_notify_q'][] = array($kind, $title, $url, $note);
    if (!$reg) {
        $reg = true;
        register_shutdown_function('sb_notify_flush');
    }
}

function sb_notify_flush() {
    $q = isset($GLOBALS['sb_notify_q']) ? (array) $GLOBALS['sb_notify_q'] : array();
    $GLOBALS['sb_notify_q'] = array();
    if (!$q) {
        return;
    }
    $by = array();
    foreach ($q as $x) {
        $by[$x[0]][] = $x;
    }
    $head = array('filed' => '✅ ওয়েবসাইটে নতুন খবর যোগ হয়েছে', 'blocked' => '⛔ আটকানো হয়েছে (সাইটে বসেনি — দেখে নিন)', 'video' => '🎬 নতুন ভিডিও/পর্ব যোগ হয়েছে');
    $h = '';
    $p = '';
    foreach (array('filed', 'video', 'blocked') as $k) {
        if (empty($by[$k])) {
            continue;
        }
        $vstart = strlen($p);
        $h .= '<b>' . esc_html($head[$k]) . '</b>' . "\n";
        $p .= $head[$k] . "\n";
        foreach ($by[$k] as $x) {
            $h .= '• <a href="' . esc_url($x[2]) . '">' . esc_html($x[1]) . '</a>' . ($x[3] ? "\n   " . esc_html($x[3]) : '') . "\n";
            $p .= '• ' . $x[1] . "\n  " . $x[2] . ($x[3] ? "\n  " . $x[3] : '') . "\n";
        }
        $h .= "\n";
        $p .= "\n";
        if ('video' === $k) {   // the YouTube watcher already sends its own mail for these
            $p = substr($p, 0, $vstart);
        }
    }
    $h .= esc_html(home_url('/'));
    $o  = sb_notify_cfg();
    if ('' !== $o['tg_token'] && '' !== $o['tg_chat']) {
        $r = sb_tg_send($h);
        if (is_wp_error($r)) {
            update_option('sb_notify_err', wp_date('d M H:i') . ' — ' . $r->get_error_message(), false);
        }
    }
    if (isset($by['filed']) || isset($by['blocked'])) {
        sb_notify_mail('শাহীন শিক্ষা পরিবার ওয়েবসাইট: ' . (isset($by['filed']) ? 'নতুন খবর যোগ হয়েছে' : 'খবর আটকানো হয়েছে'), $p . home_url('/'));
    }
}

/** called by the YouTube episode watcher when it added something */
function sb_notify_yt($added) {
    foreach ((array) $added as $a) {
        sb_notify_queue('video', $a['title'], $a['url'], $a['where']);
    }
}

/* ---------------------------------------------------------------- negative words (before AI) */
function sb_neg_words() {
    return array('অভিযোগ', 'দুর্নীতি', 'কেলেঙ্কারি', 'কেলেংকারি', 'গ্রেপ্তার', 'গ্রেফতার', 'মামলা', 'প্রতারণা', 'প্রতারক', 'জালিয়াতি', 'ফাঁস', 'আত্মহত্যা', 'নিহত', 'মৃত্যু', 'ধর্ষণ', 'হত্যা', 'খুন',
        'বিক্ষোভ', 'অনিয়ম', 'নির্যাতন', 'হামলা', 'সংঘর্ষ', 'দুর্ঘটনা', 'বহিষ্কার', 'ক্ষোভ', 'বিতর্ক', 'সমালোচনা', 'অব্যবস্থাপনা', 'হয়রানি', 'ভুয়া', 'গুজব',
        'scam', 'fraud', 'arrest', 'allegation', 'corruption', 'leak', 'controvers', 'protest');
}

function sb_neg_hit($text) {
    foreach (sb_neg_words() as $w) {
        if (false !== mb_stripos((string) $text, $w)) {
            return $w;
        }
    }
    return '';
}

/* ---------------------------------------------------------------- Google News RSS */
function sb_ca_gnews($q) {
    $r = wp_remote_get('https://news.google.com/rss/search?q=' . rawurlencode($q . ' when:30d') . '&hl=bn&gl=BD&ceid=BD:bn', array('timeout' => 15, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36'));
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
        $src = trim((string) $it->source);
        $t   = trim((string) $it->title);
        if ('' !== $src && substr($t, -strlen(' - ' . $src)) === ' - ' . $src) {
            $t = substr($t, 0, -strlen(' - ' . $src));
        }
        $out[] = array($t, esc_url_raw((string) $it->link), $src, (string) $it->pubDate);   // the RSS description lists OTHER related headlines: never use it for matching
    }
    return $out;
}

function sb_gn_is_google($u) {
    return (bool) preg_match('#^https?://news\.google\.com/(rss/)?(articles|read)/#i', (string) $u);
}

/** encoded Google News link -> the newspaper's real address, or '' */
function sb_gn_resolve($link) {
    $k = 'sb_gn_' . md5($link);
    $c = get_transient($k);
    if (is_string($c) && '' !== $c) {
        return $c;
    }
    if (!preg_match('#/(?:articles|read)/([\w-]+)#', $link, $m)) {
        return '';
    }
    $GLOBALS['sb_gn_dbg'] = array();
    $id  = $m[1];
    $s   = strtr($id, '-_', '+/');
    $b   = base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    $url = '';
    if (false !== $b && preg_match('#https?://[\x21-\x7e]+#', $b, $mm) && !preg_match('#^AU_yqL#', $b)) {
        $url = $mm[0];
    }
    if ('' === $url) {
        $hdr = array('Accept-Language' => 'en-US,en;q=0.9', 'Cookie' => 'CONSENT=YES+cb; SOCS=CAI');
        $ua  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
        $sg  = '';
        $ts  = '';
        foreach (array('https://news.google.com/articles/' . $id . '?hl=en-US&gl=US&ceid=US:en', 'https://news.google.com/rss/articles/' . $id . '?hl=en-US&gl=US&ceid=US:en') as $pg) {
            $r = wp_remote_get($pg, array('timeout' => 15, 'user-agent' => $ua, 'headers' => $hdr, 'redirection' => 5));
            if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r)) {
                $GLOBALS['sb_gn_dbg'][] = 'page:' . (is_wp_error($r) ? $r->get_error_message() : (int) wp_remote_retrieve_response_code($r));
                continue;
            }
            $h = (string) wp_remote_retrieve_body($r);
            if (preg_match('/data-n-a-sg="([^"]+)"/', $h, $a) && preg_match('/data-n-a-ts="([^"]+)"/', $h, $b2)) {
                $sg = $a[1];
                $ts = $b2[1];
                break;
            }
        }
        if ('' === $sg) {
            $GLOBALS['sb_gn_dbg'][] = 'no-sg';
        }
        if ('' !== $sg) {
            $payload = '["garturlreq",[["X","X",["X","X"],null,null,1,1,"US:en",null,1,null,null,null,null,null,0,1],"X","X",1,[1,1,1],1,1,null,0,0,null,0],' . wp_json_encode($id) . ',' . (int) $ts . ',' . wp_json_encode($sg) . ']';
            $freq    = wp_json_encode(array(array(array('Fbv4je', $payload, null, 'generic'))));
            $ep   = 'https://news.google.com/_/DotsSplashUi/data/batchexecute?rpcids=Fbv4je&source-path=' . rawurlencode('/articles/' . $id) . '&hl=en-US&rt=c';
            $post = function ($body, $cookies) use ($ep, $ua, $hdr) {
                return wp_remote_post($ep, array('timeout' => 15, 'user-agent' => $ua, 'cookies' => $cookies, 'headers' => array_merge($hdr, array('Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8')), 'body' => $body));
            };
            $r1  = $post('f.req=' . rawurlencode($freq), array());   // 1st call: Google answers with a one-time XSRF token
            $tok = '';
            if (!is_wp_error($r1) && preg_match('#xsrf\\\\",\\\\"([^"\\\\]+)#', (string) wp_remote_retrieve_body($r1), $mt)) {
                $tok = $mt[1];
            }
            $GLOBALS['sb_gn_dbg'][] = '' === $tok ? 'no-xsrf(' . (is_wp_error($r1) ? $r1->get_error_message() : (int) wp_remote_retrieve_response_code($r1)) . ')' : 'xsrf-ok';
            if ('' !== $tok) {
                $r2 = $post('f.req=' . rawurlencode($freq) . '&at=' . rawurlencode($tok), wp_remote_retrieve_cookies($r1));
                if (!is_wp_error($r2) && preg_match('#garturlres\\\\",\\\\"(https?://(?:[^"\\\\]|\\\\u[0-9a-fA-F]{4})+)#', (string) wp_remote_retrieve_body($r2), $mu)) {
                    $d   = json_decode('"' . $mu[1] . '"');
                    $url = is_string($d) ? $d : $mu[1];
                } else {
                    $GLOBALS['sb_gn_dbg'][] = 'r2:' . (is_wp_error($r2) ? $r2->get_error_message() : (int) wp_remote_retrieve_response_code($r2) . ':' . substr(preg_replace('/\s+/', ' ', (string) wp_remote_retrieve_body($r2)), 0, 80));
                }
            }
        }
    }
    $url = $url ? esc_url_raw($url) : '';
    if ('' !== $url && !sb_gn_is_google($url)) {
        set_transient($k, $url, 30 * DAY_IN_SECONDS);
        return $url;
    }
    return '';
}

/* ---------------------------------------------------------------- settings panel + ajax */
add_action('wp_ajax_sb_notify_op', function () {
    sb_ajax_guard('manage_options');
    $op = sanitize_key((string) sb_req('op'));
    $o  = sb_notify_cfg();
    if ('save' === $op) {
        $o['on']      = '1' === (string) sb_req('on') ? 1 : 0;
        $o['blocked'] = '1' === (string) sb_req('blocked') ? 1 : 0;
        $o['mail']    = sanitize_text_field((string) sb_req('mail'));
        $o['tg_chat'] = preg_replace('/[^\w@\-]/', '', (string) sb_req('tg_chat'));
        $tk           = trim((string) sb_req('tg_token'));
        if ('' !== $tk) {
            $o['tg_token'] = '-' === $tk ? '' : preg_replace('/[^\w:\-]/', '', $tk);
        }
        update_option('sb_notify', $o, false);
        wp_send_json_success(array('msg' => 'সংরক্ষিত ✓', 'has' => '' !== $o['tg_token'] ? 1 : 0));
    }
    if ('gn' === $op) {   // diagnostics for the Google News source (what the SERVER sees)
        $out = array();
        $r   = wp_remote_get('https://news.google.com/rss/search?q=' . rawurlencode('শাহীন শিক্ষা পরিবার when:30d') . '&hl=bn&gl=BD&ceid=BD:bn', array('timeout' => 15, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36'));
        $out['rss'] = is_wp_error($r) ? $r->get_error_message() : (int) wp_remote_retrieve_response_code($r);
        $it = sb_ca_gnews('শাহীন শিক্ষা পরিবার');
        $out['items'] = is_array($it) ? count($it) : null;
        $out['resolved'] = array();
        foreach (array_slice((array) $it, 0, 3) as $x) {
            $u = sb_gn_resolve($x[1]);
            $out['resolved'][] = array(mb_substr($x[0], 0, 40), $u ? $u : 'FAIL', (array) ($GLOBALS['sb_gn_dbg'] ?? array()));
        }
        wp_send_json_success(array('msg' => wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }
    if ('test' === $op) {
        $t = sb_tg_send('<b>✅ টেস্ট বার্তা</b>' . "\n" . 'শাহীন শিক্ষা পরিবার ওয়েবসাইট থেকে নোটিফিকেশন ঠিকমতো যুক্ত হয়েছে।');
        $m = sb_notify_mail('শাহীন শিক্ষা পরিবার ওয়েবসাইট: টেস্ট বার্তা', 'নোটিফিকেশন ঠিকমতো যুক্ত হয়েছে।');
        wp_send_json_success(array('msg' => 'টেলিগ্রাম: ' . (true === $t ? '✅ গেছে' : '❌ ' . $t->get_error_message()) . ' · মেইল: ' . ($m ? '✅ পাঠানো হয়েছে' : '❌ যায়নি (ঠিকানা/মেইল সেটিং দেখুন)')));
    }
    wp_send_json_error(array('message' => 'অজানা কাজ।'));
});

function sb_notify_panel() {
    $o = sb_notify_cfg();
    ?>
    <div id="sb-nf" style="border:1px solid #d1d5db;border-radius:8px;padding:12px;margin:12px 0;background:#fffbeb">
        <b>🔔 নোটিফিকেশন (টেলিগ্রাম চ্যানেল + মেইল)</b>
        <p class="sba-muted" style="margin:4px 0 8px">সাইটে নতুন খবর বা ভিডিও যোগ হলেই টেলিগ্রাম চ্যানেলে বার্তা যাবে ও মেইল যাবে। যে খবর নেতিবাচক মনে হওয়ায় <b>আটকানো</b> হলো, সেটাও আলাদা করে জানানো হবে (সাইটে বসে না), যাতে আপনারা দেখে নিতে পারেন।</p>
        <label><input type="checkbox" id="nf-on" <?php checked($o['on'], 1); ?>> চালু</label> &nbsp; <label><input type="checkbox" id="nf-bl" <?php checked($o['blocked'], 1); ?>> আটকানো খবরও জানাও</label>
        <p style="margin:8px 0"><label>মেইল (কমা দিয়ে একাধিক)<br><input type="text" id="nf-mail" value="<?php echo esc_attr($o['mail']); ?>" style="width:100%"></label></p>
        <p style="margin:8px 0"><label>টেলিগ্রাম চ্যানেল (@নাম অথবা -100… নম্বর)<br><input type="text" id="nf-chat" value="<?php echo esc_attr($o['tg_chat']); ?>" style="width:100%" placeholder="@shaheen_news_alert"></label></p>
        <p style="margin:8px 0"><label>বটের টোকেন (@BotFather থেকে; মুছতে শুধু <code>-</code> লিখুন)<br><input type="password" id="nf-tok" autocomplete="off" style="width:100%"></label> <span id="nf-has" class="description"><?php echo '' !== $o['tg_token'] ? '✓ টোকেন সংরক্ষিত আছে' : 'টোকেন দেওয়া নেই'; ?></span></p>
        <p><button type="button" class="button" id="nf-save">💾 সেভ</button> <button type="button" class="button" id="nf-test">📨 টেস্ট বার্তা পাঠান</button> <span id="nf-msg" class="sba-msg"></span></p>
        <?php $er = get_option('sb_notify_err', ''); if ($er) : ?><p class="description" style="color:#b32d2e">শেষ ত্রুটি: <?php echo esc_html($er); ?></p><?php endif; ?>
    </div>
    <script>
    (function(){var $=function(s){return document.querySelector(s);},aj=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nn=<?php echo wp_json_encode(wp_create_nonce('sb_admin')); ?>;
        function call(op,x){$('#nf-msg').textContent='…';return fetch(aj,{method:'POST',credentials:'same-origin',body:new URLSearchParams(Object.assign({action:'sb_notify_op',nonce:nn,op:op},x||{}))}).then(function(r){return r.json();}).then(function(j){$('#nf-msg').textContent=j.success?j.data.msg:((j.data&&j.data.message)||'ত্রুটি');if(j.success&&'has' in j.data){$('#nf-has').textContent=j.data.has?'✓ টোকেন সংরক্ষিত আছে':'টোকেন দেওয়া নেই';$('#nf-tok').value='';}});}
        $('#nf-save').onclick=function(){call('save',{on:$('#nf-on').checked?'1':'0',blocked:$('#nf-bl').checked?'1':'0',mail:$('#nf-mail').value,tg_chat:$('#nf-chat').value,tg_token:$('#nf-tok').value});};
        $('#nf-test').onclick=function(){call('test');};
    })();
    </script>
    <?php
}
