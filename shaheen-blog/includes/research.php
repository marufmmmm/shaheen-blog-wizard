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
