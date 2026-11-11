<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Research page (step 1): web search with source links, paid from credits.
 * Billing: real provider cost x 1.7 (SB_RESEARCH_MULT), profit included, never shown on a client screen.
 * Web search is an OpenAI feature, so only OpenAI (gpt) models can be picked here.
 */

function sb_research_models() {
    $out = array();
    foreach (sb_allowed_models() as $id) {
        if (0 === strpos($id, 'gpt')) {
            $out[] = $id;
        }
    }
    return $out;
}

/** Credits a research costs: what the person is billed (real x 1.7) in the site's credit unit. */
function sb_research_credits($real) {
    return max(1, (int) ceil(((float) $real * SB_RESEARCH_MULT) / sb_unit_billed()));
}

function sb_research_run($uid, $q, $model) {
    $key = sb_get('api_key');
    if ($key === '') {
        return new WP_Error('sb_nokey', 'OpenAI API কী দেওয়া নেই।');
    }
    $st = SB_Credits::state($uid);
    if (empty($st['unlimited']) && $st['remaining'] < 30) {
        return new WP_Error('sb_no_credits', 'ব্যালেন্স যথেষ্ট নয় (সার্চের জন্য অন্তত ' . sb_money(30) . ' দরকার)।');
    }
    $today = wp_date('Y-m-d');
    $sys   = 'তুমি একজন গবেষণা সহকারী। ওয়েব সার্চ করে নির্ভরযোগ্য সূত্র থেকে তথ্য নাও। কঠোর নিয়ম: যা সূত্রে পাওনি তা অনুমান করবে না, স্পষ্ট বলবে "পাওয়া যায়নি"। '
        . 'উত্তর বাংলায় দেবে (নাম, সংখ্যা ও সরাসরি উদ্ধৃতি যেমন আছে তেমন রাখবে)। প্রথমে ৩–৫ লাইনের সারকথা, তারপর পয়েন্ট আকারে প্রয়োজনীয় তথ্য, শেষে কোন তথ্য কোন সূত্র থেকে। আজ ' . $today . '।';
    $body = array(
        'model' => $model,
        'input' => array(array('role' => 'system', 'content' => $sys), array('role' => 'user', 'content' => $q)),
        'tools' => array(array('type' => 'web_search_preview')),
        'max_output_tokens' => 3500,
    );
    if (preg_match('/^(gpt-[5-9]|o\d)/i', $model)) {
        $body['reasoning'] = array('effort' => 'low');
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(150); // phpcs:ignore
    }
    sb_meter_take();   // start from zero
    $resp = wp_remote_post('https://api.openai.com/v1/responses', array(
        'timeout' => 140,
        'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
        'body'    => wp_json_encode($body),
    ));
    if (is_wp_error($resp)) {
        sb_meter_take();
        return new WP_Error('sb_http', 'OpenAI-তে সংযোগ হয়নি: ' . $resp->get_error_message() . ' (বিল যোগ হয়নি)');
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    $d    = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code !== 200 || !is_array($d) || empty($d['output'])) {
        sb_meter_take();
        $m = is_array($d) && !empty($d['error']['message']) ? $d['error']['message'] : 'HTTP ' . $code;
        return new WP_Error('sb_api', 'সার্চ করা যায়নি (' . $m . ')। বিল যোগ হয়নি।');
    }
    $text    = '';
    $sources = array();
    foreach ($d['output'] as $item) {
        if (isset($item['type']) && 'message' === $item['type'] && !empty($item['content'])) {
            foreach ($item['content'] as $c) {
                if (isset($c['text'])) {
                    $text .= $c['text'];
                }
                foreach (!empty($c['annotations']) ? $c['annotations'] : array() as $a) {
                    if (isset($a['url']) && !isset($sources[$a['url']])) {
                        $sources[$a['url']] = isset($a['title']) ? sanitize_text_field($a['title']) : $a['url'];
                    }
                }
            }
        }
    }
    $text = trim($text);
    if ($text === '') {
        sb_meter_take();
        return new WP_Error('sb_empty', 'AI কোনো উত্তর দেয়নি। বিল যোগ হয়নি।');
    }
    $real = sb_meter_take();   // what OpenAI really charged for this search (tokens + search fee)
    $cost = $real > 0 ? sb_research_credits($real) : 40;
    $GLOBALS['sb_real_charge'] = $real > 0 ? $real : null;
    $new = SB_Credits::charge($uid, 'research', $cost, 0);
    if (is_wp_error($new)) {
        return $new;
    }
    $list = array();
    foreach ($sources as $u => $t) {
        $list[] = array('url' => esc_url_raw($u), 'title' => $t);
    }
    return array('text' => $text, 'sources' => array_slice($list, 0, 12), 'cost' => sb_money($cost), 'balance' => sb_money($new['remaining']), 'unlimited' => !empty($new['unlimited']));
}

add_action('wp_ajax_sb_research', function () {
    sb_ajax_guard('edit_posts');
    $q = trim((string) sb_req('q'));
    if (mb_strlen($q) < 8) {
        wp_send_json_error(array('message' => 'কী খুঁজবেন বিস্তারিত লিখুন (কমপক্ষে ৮ অক্ষর)।'));
    }
    $q     = mb_substr($q, 0, 1500);
    $model = sanitize_text_field((string) sb_req('model'));
    $ok    = sb_research_models();
    if (!in_array($model, $ok, true)) {
        $model = $ok ? $ok[0] : '';
    }
    if ($model === '') {
        wp_send_json_error(array('message' => 'কোনো OpenAI মডেল পাওয়া যায়নি।'));
    }
    $r = sb_research_run(get_current_user_id(), $q, $model);
    if (is_wp_error($r)) {
        wp_send_json_error(array('message' => $r->get_error_message()));
    }
    wp_send_json_success($r);
});

function sb_page_research() {
    $models = sb_research_models();
    $def    = sb_model_for('article');
    $st     = SB_Credits::state(get_current_user_id());
    ?>
<div class="wrap sba" id="sb-rs">
    <h1>🔎 Research</h1>
    <p class="sba-muted">ওয়েব সার্চ করে তথ্য ও সূত্রের লিংকসহ উত্তর। প্রতিটি সার্চে আসল খরচ অনুযায়ী ক্রেডিট কাটে।</p>
    <div class="sba-card sba-form" style="max-width:860px">
        <label>কী জানতে চান?
            <textarea id="rs-q" rows="4" placeholder="যেমন: ২০২৬ সালে বাংলাদেশে ক্যাডেট কলেজ ভর্তি পরীক্ষার তারিখ ও নিয়ম কী?"></textarea>
        </label>
        <div class="sba-row">
            <label>মডেল
                <select id="rs-model">
                    <?php foreach ($models as $m) : ?>
                        <option value="<?php echo esc_attr($m); ?>" <?php selected($m, $def); ?>><?php echo esc_html($m); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div style="align-self:end"><button type="button" class="button button-primary button-hero" id="rs-go">🔎 সার্চ করুন</button></div>
        </div>
        <p class="sba-muted" id="rs-bal">ব্যালেন্স: <b><?php echo $st['unlimited'] ? 'আনলিমিটেড' : esc_html(sb_money($st['remaining'])); ?></b> · ওয়েব সার্চ শুধু OpenAI মডেলে চলে।</p>
        <p id="rs-msg" class="sba-msg"></p>
    </div>
    <div class="sba-card" id="rs-out" style="max-width:860px;display:none;margin-top:16px">
        <h2>উত্তর</h2>
        <div id="rs-text" style="white-space:pre-wrap;line-height:1.7"></div>
        <h3>সূত্র</h3>
        <ol id="rs-src"></ol>
        <p class="sba-muted" id="rs-cost"></p>
        <p><button type="button" class="button" id="rs-copy">📋 উত্তর কপি করুন</button></p>
    </div>
    <?php sb_fb_card(); ?>
</div>
<script>
(function(){
    var nonce = <?php echo wp_json_encode(wp_create_nonce('sb_admin')); ?>, ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var $ = function (s) { return document.querySelector(s); }, busy = false;
    function msg(t, bad) { var m = $('#rs-msg'); m.textContent = t || ''; m.style.color = bad ? '#b32d2e' : '#1a6b3e'; }
    $('#rs-go').addEventListener('click', function () {
        if (busy) { return; }
        var q = $('#rs-q').value.trim();
        if (q.length < 8) { msg('কী খুঁজবেন বিস্তারিত লিখুন।', true); return; }
        busy = true; $('#rs-go').disabled = true; msg('খুঁজছি… (১ মিনিট পর্যন্ত লাগতে পারে)');
        var b = new URLSearchParams({ action: 'sb_research', nonce: nonce, q: q, model: $('#rs-model').value });
        fetch(ajax, { method: 'POST', credentials: 'same-origin', body: b }).then(function (r) { return r.json(); }).then(function (j) {
            busy = false; $('#rs-go').disabled = false;
            if (!j.success) { msg((j.data && j.data.message) || 'ত্রুটি', true); return; }
            msg('');
            var d = j.data;
            $('#rs-out').style.display = 'block';
            $('#rs-text').textContent = d.text;
            var ol = $('#rs-src'); ol.innerHTML = '';
            d.sources.forEach(function (s) {
                var li = document.createElement('li'), a = document.createElement('a');
                a.href = s.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = s.title || s.url; li.appendChild(a); ol.appendChild(li);
            });
            $('#rs-cost').textContent = 'এই সার্চে কেটেছে ' + d.cost + (d.unlimited ? '' : ' · ব্যালেন্স এখন ' + d.balance);
        }).catch(function () { busy = false; $('#rs-go').disabled = false; msg('সংযোগ হয়নি। আবার চেষ্টা করুন। (বিল যোগ হয়নি)', true); });
    });
    $('#rs-copy').addEventListener('click', function () {
        var t = $('#rs-text').textContent; try { navigator.clipboard.writeText(t); msg('কপি হয়েছে'); } catch (e) { msg('কপি করা যায়নি', true); }
    });
})();
</script>
    <?php
}


/* ---------------------------------------------------------------- Facebook Page (official Graph API, no credits: it is the page's own data) */
function sb_fb_cfg() {
    $c = get_option('sb_fb', array());
    return is_array($c) ? $c : array();
}

function sb_fb_get($path, $args, $token) {
    $args['access_token'] = $token;
    $r = wp_remote_get('https://graph.facebook.com/' . ltrim($path, '/') . '?' . http_build_query($args), array('timeout' => 30));
    if (is_wp_error($r)) {
        return new WP_Error('sb_fb', 'Facebook-এ সংযোগ হয়নি: ' . $r->get_error_message());
    }
    $d = json_decode(wp_remote_retrieve_body($r), true);
    if (!is_array($d) || !empty($d['error'])) {
        return new WP_Error('sb_fb', 'Facebook: ' . (isset($d['error']['message']) ? $d['error']['message'] : 'HTTP ' . wp_remote_retrieve_response_code($r)));
    }
    return $d;
}

/** One-time connect: short user token + app id/secret -> long-lived user token -> never-expiring Page token. Secret and user token are not kept. */
add_action('wp_ajax_sb_fb_connect', function () {
    sb_ajax_guard('manage_options');
    $app = sanitize_text_field((string) sb_req('app_id'));
    $sec = trim((string) sb_req('app_secret'));
    $tok = trim((string) sb_req('user_token'));
    $pid = preg_replace('/\D/', '', (string) sb_req('page_id'));
    if ($app === '' || $sec === '' || $tok === '') {
        wp_send_json_error(array('message' => 'App ID, App Secret ও টোকেন তিনটিই দিন।'));
    }
    $ex = sb_fb_get('oauth/access_token', array('grant_type' => 'fb_exchange_token', 'client_id' => $app, 'client_secret' => $sec, 'fb_exchange_token' => $tok), $tok);
    if (is_wp_error($ex) || empty($ex['access_token'])) {
        wp_send_json_error(array('message' => is_wp_error($ex) ? $ex->get_error_message() : 'টোকেন বদলানো যায়নি।'));
    }
    $acc = sb_fb_get('me/accounts', array('fields' => 'id,name,access_token', 'limit' => 100), $ex['access_token']);
    if (is_wp_error($acc)) {
        wp_send_json_error(array('message' => $acc->get_error_message()));
    }
    $pick = null;
    foreach (isset($acc['data']) ? $acc['data'] : array() as $pg) {
        if ($pid === '' || (string) $pg['id'] === $pid) {
            $pick = $pg;
            break;
        }
    }
    if (!$pick || empty($pick['access_token'])) {
        $names = array();
        foreach (isset($acc['data']) ? $acc['data'] : array() as $pg) {
            $names[] = $pg['name'] . ' (' . $pg['id'] . ')';
        }
        wp_send_json_error(array('message' => 'এই অ্যাকাউন্টে পেজ পাওয়া যায়নি। পাওয়া গেছে: ' . ($names ? implode(', ', $names) : 'কিছু নেই') . '। টোকেন নেওয়ার সময় পেজটি বেছে নিয়েছেন কিনা দেখুন।'));
    }
    update_option('sb_fb', array('page_id' => $pick['id'], 'page_name' => sanitize_text_field($pick['name']), 'token' => $pick['access_token'], 't' => time()), false);
    wp_send_json_success(array('message' => '✅ সংযুক্ত হয়েছে: ' . $pick['name']));
});

add_action('wp_ajax_sb_fb_disconnect', function () {
    sb_ajax_guard('manage_options');
    delete_option('sb_fb');
    delete_transient('sb_fb_feed');
    wp_send_json_success(array('message' => 'সংযোগ মুছে ফেলা হয়েছে।'));
});

/** Posts and videos of the connected Page (10-minute cache). */
add_action('wp_ajax_sb_fb_fetch', function () {
    sb_ajax_guard('edit_posts');
    $c = sb_fb_cfg();
    if (empty($c['token'])) {
        wp_send_json_error(array('message' => 'Facebook পেজ এখনো সংযুক্ত নয়।'));
    }
    $force = '1' === (string) sb_req('force');
    $out   = $force ? false : get_transient('sb_fb_feed');
    if (!is_array($out)) {
        $posts  = sb_fb_get($c['page_id'] . '/posts', array('fields' => 'id,message,created_time,permalink_url,full_picture', 'limit' => 40), $c['token']);
        $videos = sb_fb_get($c['page_id'] . '/videos', array('fields' => 'id,title,description,created_time,permalink_url,picture,length', 'limit' => 40), $c['token']);
        if (is_wp_error($posts) && is_wp_error($videos)) {
            wp_send_json_error(array('message' => $posts->get_error_message() . ' (টোকেন মেয়াদ শেষ বা অনুমতি কম হলে আবার সংযুক্ত করুন)'));
        }
        $out = array('posts' => array(), 'videos' => array());
        foreach (is_wp_error($posts) || empty($posts['data']) ? array() : $posts['data'] as $p) {
            $out['posts'][] = array('id' => $p['id'], 'text' => isset($p['message']) ? wp_strip_all_tags($p['message']) : '', 'date' => isset($p['created_time']) ? substr($p['created_time'], 0, 10) : '', 'url' => isset($p['permalink_url']) ? esc_url_raw($p['permalink_url']) : '', 'img' => isset($p['full_picture']) ? esc_url_raw($p['full_picture']) : '');
        }
        foreach (is_wp_error($videos) || empty($videos['data']) ? array() : $videos['data'] as $v) {
            $u = isset($v['permalink_url']) ? $v['permalink_url'] : '';
            $out['videos'][] = array('id' => $v['id'], 'text' => trim((isset($v['title']) ? $v['title'] : '') . ' ' . (isset($v['description']) ? $v['description'] : '')), 'date' => isset($v['created_time']) ? substr($v['created_time'], 0, 10) : '', 'url' => $u ? esc_url_raw(0 === strpos($u, 'http') ? $u : 'https://www.facebook.com' . $u) : '', 'img' => isset($v['picture']) ? esc_url_raw($v['picture']) : '', 'len' => isset($v['length']) ? (int) $v['length'] : 0);
        }
        set_transient('sb_fb_feed', $out, 600);
    }
    wp_send_json_success($out);
});

function sb_fb_card() {
    $c  = sb_fb_cfg();
    $on = !empty($c['token']);
    ?>
    <div class="sba-card sba-form" id="fb-card" style="max-width:860px;margin-top:16px">
        <h2>📘 Facebook পেজ</h2>
        <?php if ($on) : ?>
            <p>সংযুক্ত: <b><?php echo esc_html($c['page_name']); ?></b> · এই তথ্য আনতে ক্রেডিট কাটে না (আপনার নিজের পেজের তথ্য)।</p>
            <p>
                <button type="button" class="button button-primary" id="fb-load">পেজের পোস্ট ও ভিডিও আনুন</button>
                <button type="button" class="button" id="fb-reload">🔄 নতুন করে আনুন</button>
                <?php if (current_user_can('manage_options')) : ?><button type="button" class="button" id="fb-off">সংযোগ মুছুন</button><?php endif; ?>
            </p>
            <div id="fb-list"></div>
        <?php elseif (current_user_can('manage_options')) : ?>
            <p class="sba-muted">একবার সংযুক্ত করলে পেজের পোস্ট ও ভিডিও এখানে আসবে। টোকেন কীভাবে নেবেন তা নিচে লেখা।</p>
            <label>App ID<input type="text" id="fb-app" autocomplete="off"></label>
            <label>App Secret<input type="password" id="fb-sec" autocomplete="off"></label>
            <label>টোকেন (Graph API Explorer থেকে)<input type="password" id="fb-tok" autocomplete="off"></label>
            <label>পেজ আইডি <small>(ফাঁকা রাখলে প্রথম পেজ)</small><input type="text" id="fb-pid" autocomplete="off"></label>
            <p><button type="button" class="button button-primary" id="fb-go">সংযুক্ত করুন</button></p>
            <details><summary>টোকেন কীভাবে নেবেন</summary>
                <ol>
                    <li><b>developers.facebook.com</b> → My Apps → Create App (টাইপ: Business বা Other)।</li>
                    <li>Tools → <b>Graph API Explorer</b>। উপরে আপনার App বেছে নিন।</li>
                    <li>User or Page → <b>Get User Access Token</b>। অনুমতির তালিকায় <code>pages_show_list</code> ও <code>pages_read_engagement</code> টিক দিন। Generate চাপুন, Facebook-এ আপনার পেজটি বেছে নিন (আপনাকে পেজের অ্যাডমিন হতে হবে)।</li>
                    <li>যে টোকেন আসবে সেটা উপরের "টোকেন" ঘরে বসান। App ID ও App Secret পাবেন App Settings → Basic থেকে।</li>
                    <li>সংযুক্ত করুন চাপুন। প্লাগইন নিজে মেয়াদহীন পেজ-টোকেন বানিয়ে রাখবে। App Secret ও আপনার টোকেন সংরক্ষণ হবে না।</li>
                </ol>
            </details>
        <?php else : ?>
            <p class="sba-muted">Facebook পেজ এখনো সংযুক্ত হয়নি। অ্যাডমিন সংযুক্ত করবেন।</p>
        <?php endif; ?>
        <p id="fb-msg" class="sba-msg"></p>
    </div>
<script>
(function(){
    var nonce = <?php echo wp_json_encode(wp_create_nonce('sb_admin')); ?>, ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var $ = function (s) { return document.querySelector(s); };
    function msg(t, bad) { var m = $('#fb-msg'); m.textContent = t || ''; m.style.color = bad ? '#b32d2e' : '#1a6b3e'; }
    function call(op, data) { return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(Object.assign({ action: op, nonce: nonce }, data || {})) }).then(function (r) { return r.json(); }); }
    function on(id, fn) { var e = $(id); if (e) { e.addEventListener('click', fn); } }
    on('#fb-go', function () {
        msg('সংযুক্ত করছি…');
        call('sb_fb_connect', { app_id: $('#fb-app').value, app_secret: $('#fb-sec').value, user_token: $('#fb-tok').value, page_id: $('#fb-pid').value }).then(function (j) {
            if (!j.success) { msg(j.data.message, true); return; } msg(j.data.message); setTimeout(function () { location.reload(); }, 900);
        });
    });
    on('#fb-off', function () { call('sb_fb_disconnect').then(function () { location.reload(); }); });
    function row(x, kind) {
        var d = document.createElement('div'); d.style.cssText = 'display:flex;gap:10px;padding:8px 0;border-bottom:1px solid #e5e7eb';
        if (x.img) { var i = document.createElement('img'); i.src = x.img; i.alt = ''; i.style.cssText = 'width:84px;height:60px;object-fit:cover;border-radius:6px;flex:none'; d.appendChild(i); }
        var t = document.createElement('div'); t.style.minWidth = '0';
        var a = document.createElement('a'); a.href = x.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = (kind === 'v' ? '🎬 ' : '📝 ') + (x.text ? x.text.slice(0, 140) : '(লেখা নেই)');
        var s = document.createElement('small'); s.style.display = 'block'; s.textContent = x.date + (x.len ? ' · ' + Math.round(x.len / 60) + ' মিনিট' : '');
        t.appendChild(a); t.appendChild(s); d.appendChild(t); return d;
    }
    function load(force) {
        msg('আনছি…');
        call('sb_fb_fetch', { force: force ? '1' : '0' }).then(function (j) {
            if (!j.success) { msg(j.data.message, true); return; }
            msg('');
            var L = $('#fb-list'); L.innerHTML = '';
            [['ভিডিও', j.data.videos, 'v'], ['পোস্ট', j.data.posts, 'p']].forEach(function (g) {
                var h = document.createElement('h3'); h.textContent = g[0] + ' (' + g[1].length + ')'; L.appendChild(h);
                g[1].forEach(function (x) { L.appendChild(row(x, g[2])); });
            });
        });
    }
    on('#fb-load', function () { load(false); });
    on('#fb-reload', function () { load(true); });
})();
</script>
    <?php
}
