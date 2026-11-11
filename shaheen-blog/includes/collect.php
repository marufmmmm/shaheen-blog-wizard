<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Collected" box for the desktop browser app (Shaheen Browser).
 * The app sends the page the admin is looking at (title, link, text, pictures, video links) with a key made here.
 * Collecting costs nothing; credits are only used when AI works on the collected items later.
 * The key is shown once; only its hash is stored.
 */

const SB_COLLECT_MAX = 300;

function sb_collect_items() {
    $l = get_option('sb_collected', array());
    return is_array($l) ? $l : array();
}

function sb_collect_key_ok($key) {
    $h = (string) get_option('sb_collect_key', '');
    return $h !== '' && '' !== (string) $key && hash_equals($h, hash('sha256', (string) $key));
}

function sb_collect_perm(WP_REST_Request $r) {
    $k = $r->get_header('x-sb-key');
    return sb_collect_key_ok($k) ? true : new WP_Error('sb_key', 'চাবি (key) ভুল বা দেওয়া নেই।', array('status' => 401));
}

add_action('rest_api_init', function () {
    register_rest_route('sb/v1', '/ping', array('methods' => 'GET', 'permission_callback' => 'sb_collect_perm', 'callback' => function () {
        return array('ok' => true, 'site' => get_bloginfo('name'), 'count' => count(sb_collect_items()));
    }));
    register_rest_route('sb/v1', '/collect', array('methods' => 'POST', 'permission_callback' => 'sb_collect_perm', 'callback' => function (WP_REST_Request $r) {
        $url = esc_url_raw((string) $r->get_param('url'));
        if (!preg_match('#^https?://#i', $url)) {
            return new WP_Error('sb_url', 'লিংক দরকার।', array('status' => 400));
        }
        $imgs = array();
        foreach ((array) $r->get_param('images') as $i) {
            $u = esc_url_raw((string) $i);
            if ($u && preg_match('#^https?://#i', $u)) {
                $imgs[] = $u;
            }
        }
        $vids = array();
        foreach ((array) $r->get_param('videos') as $i) {
            $u = esc_url_raw((string) $i);
            if ($u && preg_match('#^https?://#i', $u)) {
                $vids[] = $u;
            }
        }
        $item = array(
            'id'     => substr(md5($url . microtime(true)), 0, 10),
            't'      => time(),
            'url'    => $url,
            'host'   => (string) wp_parse_url($url, PHP_URL_HOST),
            'title'  => mb_substr(sanitize_text_field((string) $r->get_param('title')), 0, 300),
            'text'   => mb_substr(sanitize_textarea_field((string) $r->get_param('text')), 0, 8000),
            'images' => array_slice(array_values(array_unique($imgs)), 0, 8),
            'videos' => array_slice(array_values(array_unique($vids)), 0, 8),
        );
        $l = sb_collect_items();
        foreach ($l as $k => $x) {   // same link again: replace the old copy
            if ($x['url'] === $url) {
                unset($l[$k]);
            }
        }
        array_unshift($l, $item);
        update_option('sb_collected', array_slice(array_values($l), 0, SB_COLLECT_MAX), false);
        return array('ok' => true, 'id' => $item['id'], 'count' => min(SB_COLLECT_MAX, count($l)));
    }));
});

add_action('wp_ajax_sb_collect_key', function () {
    sb_ajax_guard('manage_options');
    $k = 'sbk_' . wp_generate_password(32, false, false);
    update_option('sb_collect_key', hash('sha256', $k), false);
    wp_send_json_success(array('key' => $k, 'site' => untrailingslashit(home_url())));
});

add_action('wp_ajax_sb_collect_list', function () {
    sb_ajax_guard('edit_posts');
    wp_send_json_success(array('items' => array_map(function ($x) {
        $x['date'] = wp_date('Y-m-d H:i', $x['t']);
        $x['text'] = mb_substr($x['text'], 0, 400);
        return $x;
    }, sb_collect_items())));
});

add_action('wp_ajax_sb_collect_del', function () {
    sb_ajax_guard('edit_posts');
    $id = sanitize_text_field((string) sb_req('id'));
    $l  = array_values(array_filter(sb_collect_items(), function ($x) use ($id) { return $id !== 'all' && $x['id'] !== $id; }));
    update_option('sb_collected', $l, false);
    wp_send_json_success(array('left' => count($l)));
});

function sb_collect_card() {
    ?>
    <div class="sba-card sba-form" id="cl-card" style="max-width:860px;margin-top:16px">
        <h2>🧺 সংগৃহীত (ডেস্কটপ ব্রাউজার থেকে)</h2>
        <p class="sba-muted">Shaheen Browser অ্যাপে কোনো পেজে "সংগ্রহ করুন" চাপলে এখানে আসে। সংগ্রহ করতে ক্রেডিট কাটে না।</p>
        <?php if (current_user_can('manage_options')) : ?>
            <p><button type="button" class="button" id="cl-key">🔑 অ্যাপের জন্য নতুন চাবি বানান</button> <span class="sba-muted">(আগের চাবি বাতিল হয়ে যাবে)</span></p>
            <p id="cl-keybox" style="display:none"><b>সাইট:</b> <code id="cl-site"></code><br><b>চাবি:</b> <code id="cl-keyv"></code><br><small>এটা একবারই দেখানো হয়। অ্যাপে বসিয়ে নিন।</small></p>
        <?php endif; ?>
        <p><button type="button" class="button button-primary" id="cl-load">তালিকা দেখুন</button> <button type="button" class="button" id="cl-clear">সব মুছুন</button></p>
        <div id="cl-list"></div>
        <p id="cl-msg" class="sba-msg"></p>
    </div>
<script>
(function(){
    var nonce = <?php echo wp_json_encode(wp_create_nonce('sb_admin')); ?>, ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var $ = function (s) { return document.querySelector(s); };
    function call(op, data) { return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(Object.assign({ action: op, nonce: nonce }, data || {})) }).then(function (r) { return r.json(); }); }
    function on(id, fn) { var e = $(id); if (e) { e.addEventListener('click', fn); } }
    on('#cl-key', function () { call('sb_collect_key').then(function (j) { if (!j.success) { return; } $('#cl-keybox').style.display = 'block'; $('#cl-site').textContent = j.data.site; $('#cl-keyv').textContent = j.data.key; }); });
    function draw(items) {
        var L = $('#cl-list'); L.innerHTML = '';
        $('#cl-msg').textContent = items.length ? items.length + 'টি সংগৃহীত' : 'এখনো কিছু সংগ্রহ করা হয়নি।';
        items.forEach(function (x) {
            var d = document.createElement('div'); d.style.cssText = 'padding:10px 0;border-bottom:1px solid #e5e7eb';
            var a = document.createElement('a'); a.href = x.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = x.title || x.url; a.style.fontWeight = '600';
            var m = document.createElement('small'); m.style.display = 'block'; m.textContent = x.host + ' · ' + x.date + ' · ছবি ' + x.images.length + ' · ভিডিও ' + x.videos.length;
            var p = document.createElement('div'); p.style.cssText = 'color:#444;margin-top:4px'; p.textContent = x.text;
            var b = document.createElement('button'); b.type = 'button'; b.className = 'button-link'; b.textContent = 'মুছুন'; b.style.color = '#b32d2e';
            b.addEventListener('click', function () { call('sb_collect_del', { id: x.id }).then(function () { d.remove(); }); });
            d.appendChild(a); d.appendChild(m); d.appendChild(p); d.appendChild(b); L.appendChild(d);
        });
    }
    function load() { call('sb_collect_list').then(function (j) { if (j.success) { draw(j.data.items); } }); }
    on('#cl-load', load);
    on('#cl-clear', function () { if (!window.confirm('সংগৃহীত সব মুছবেন?')) { return; } call('sb_collect_del', { id: 'all' }).then(load); });
})();
</script>
    <?php
}
