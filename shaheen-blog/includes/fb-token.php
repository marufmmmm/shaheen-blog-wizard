<?php
/**
 * Facebook page-token tool (admin only): turns a short-lived Graph API Explorer user token into a long-lived one and
 * fetches the page tokens with it. Page tokens made from a long-lived user token do not expire.
 * The App ID is stored; the App Secret is write-only (never printed back); the user token is never stored.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_submenu_page(null, 'Facebook Token', '🔑 Facebook Token', 'manage_options', 'sb-fbtoken', 'sb_page_fbtoken');
}, 33);

function sb_fbtok_cfg() {
    $o = get_option('sb_fbtok', array());
    return array_merge(array('app_id' => '2850338315362367', 'app_secret' => ''), is_array($o) ? $o : array());
}

function sb_fbtok_get($c, $path, $args) {
    $r = wp_remote_get('https://graph.facebook.com/' . $c['api_ver'] . '/' . ltrim($path, '/') . '?' . http_build_query($args), array('timeout' => 40));
    if (is_wp_error($r)) {
        return $r;
    }
    $j = json_decode((string) wp_remote_retrieve_body($r), true);
    if (!is_array($j) || isset($j['error'])) {
        return new WP_Error('sb_fbt', is_array($j) && isset($j['error']['message']) ? (string) $j['error']['message'] : 'No answer (HTTP ' . (int) wp_remote_retrieve_response_code($r) . ')');
    }
    return $j;
}

/** Expiry of a token as text, using the saved app credentials. */
function sb_fbtok_expiry($c, $k, $token) {
    if ('' === $k['app_secret']) {
        return '';
    }
    $d = sb_fbtok_get($c, 'debug_token', array('input_token' => $token, 'access_token' => $k['app_id'] . '|' . $k['app_secret']));
    if (is_wp_error($d)) {
        return '';
    }
    $x = (array) ($d['data'] ?? array());
    if (empty($x['is_valid'])) {
        return 'invalid / expired';
    }
    $e = (int) ($x['expires_at'] ?? 0);
    return 0 === $e ? 'never expires' : 'expires ' . wp_date('d M Y H:i', $e);
}

add_action('wp_ajax_sb_fbtok_go', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No permission.');
    }
    $c = sb_share_cfg();
    $k = sb_fbtok_cfg();
    $id = preg_replace('/\D/', '', (string) ($_POST['app_id'] ?? ''));
    if ('' !== $id) {
        $k['app_id'] = $id;
    }
    $sec = trim((string) wp_unslash($_POST['app_secret'] ?? ''));
    if ('' !== $sec) {
        $k['app_secret'] = $sec;
    }
    update_option('sb_fbtok', $k, false);
    $mode = (string) ($_POST['mode'] ?? 'check');
    $rep  = array();
    if ('check' === $mode) {
        foreach (sb_fbpub_pages() as $pid => $p) {
            $r = sb_fbtok_get($c, $pid, array('fields' => 'id,name', 'access_token' => $p['token']));
            $ex = is_wp_error($r) ? '' : sb_fbtok_expiry($c, $k, $p['token']);
            $rep[] = (is_wp_error($r) ? '❌ ' : '✅ ') . $p['name'] . ' — ' . (is_wp_error($r) ? $r->get_error_message() : ('works' . ('' !== $ex ? ', ' . $ex : '')));
        }
        wp_send_json_success(array('report' => $rep ?: array('No pages connected.')));
    }
    $ut = trim((string) wp_unslash($_POST['user_token'] ?? ''));
    if ('' === $ut || '' === $k['app_secret'] || '' === $k['app_id']) {
        wp_send_json_error('App ID, App Secret and the user token are all needed (the App Secret is saved after the first time).');
    }
    $ll = sb_fbtok_get($c, 'oauth/access_token', array('grant_type' => 'fb_exchange_token', 'client_id' => $k['app_id'], 'client_secret' => $k['app_secret'], 'fb_exchange_token' => $ut));
    if (is_wp_error($ll) || empty($ll['access_token'])) {
        wp_send_json_error('Exchange failed: ' . (is_wp_error($ll) ? $ll->get_error_message() : 'no token returned'));
    }
    $acc = sb_fbtok_get($c, 'me/accounts', array('fields' => 'id,name,access_token', 'limit' => 100, 'access_token' => $ll['access_token']));
    if (is_wp_error($acc)) {
        wp_send_json_error($acc->get_error_message());
    }
    $have = get_option('sb_share_pages', array());
    $have = is_array($have) ? $have : array();
    $o2   = get_option('sb_share', array());
    $o2   = is_array($o2) ? $o2 : array();
    foreach ((array) ($acc['data'] ?? array()) as $a) {
        $pid = (string) $a['id'];
        if (empty($a['access_token'])) {
            continue;
        }
        $ex = sb_fbtok_expiry($c, $k, $a['access_token']);
        if ($pid === (string) $c['page_id']) {
            $o2['page_token'] = (string) $a['access_token'];
            $rep[] = '✅ ' . $a['name'] . ' (main page) — token replaced' . ('' !== $ex ? ', ' . $ex : '');
        } elseif (isset($have[$pid])) {
            $have[$pid]['token'] = (string) $a['access_token'];
            $rep[] = '✅ ' . $a['name'] . ' — token replaced' . ('' !== $ex ? ', ' . $ex : '');
        } elseif (!empty($_POST['add_all'])) {
            $have[$pid] = array('name' => (string) $a['name'], 'token' => (string) $a['access_token']);
            $rep[] = '➕ ' . $a['name'] . ' (' . $pid . ') — connected' . ('' !== $ex ? ', ' . $ex : '');
        } else {
            $rep[] = '➖ ' . $a['name'] . ' (' . $pid . ') — found, not connected (tick "connect all my pages" and run again)';
        }
    }
    update_option('sb_share', $o2, false);
    update_option('sb_share_pages', $have, false);
    wp_send_json_success(array('report' => $rep ?: array('No pages found for this user token.')));
});

function sb_page_fbtoken() {
    $k = sb_fbtok_cfg();
    ?>
    <div class="wrap">
        <h1>🔑 Facebook Token</h1>
        <p class="description">Auto-share, Facebook Post, Post Manager and Inbox all use the page token. A token from Graph API Explorer lasts only a few hours. Here it becomes a permanent one.</p>
        <div style="max-width:680px;background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:14px">
            <p><b>1.</b> In Graph API Explorer pick the app, click <i>Generate Access Token</i> (permissions: pages_show_list, pages_read_engagement, pages_manage_posts, pages_manage_engagement, pages_messaging) and paste it here.</p>
            <p>User token (short-lived)<br><input type="password" id="ft-u" style="width:100%" autocomplete="off"></p>
            <p>App ID<br><input type="text" id="ft-i" style="width:100%" value="<?php echo esc_attr($k['app_id']); ?>"></p>
            <p>App Secret <span class="description"><?php echo '' !== $k['app_secret'] ? '(saved — leave empty to keep)' : '(Meta app → Settings → Basic; stored on this site only, never shown again)'; ?></span><br><input type="password" id="ft-s" style="width:100%" autocomplete="off"></p>
            <p><label><input type="checkbox" id="ft-all"> Also connect every other page this account manages (they then show in Facebook Post / Manager / Inbox)</label></p>
            <p><button class="button button-primary" id="ft-go">♾ Make permanent page tokens</button> <button class="button" id="ft-ck">🔎 Check current tokens</button> <span id="ft-m" class="description"></span></p>
            <div id="ft-r"></div>
        </div>
    </div>
    <script>
    (function(){var $=function(s){return document.querySelector(s);},aj=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nn=<?php echo wp_json_encode(wp_create_nonce('sb_admin')); ?>;
        function run(mode){$('#ft-m').textContent='Working…';$('#ft-r').textContent='';
            fetch(aj,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'sb_fbtok_go',nonce:nn,mode:mode,user_token:$('#ft-u').value,app_id:$('#ft-i').value,app_secret:$('#ft-s').value,add_all:$('#ft-all').checked?'1':''})}).then(function(r){return r.json();}).then(function(j){
                if(!j.success){$('#ft-m').textContent='❌ '+j.data;return;}$('#ft-m').textContent='';$('#ft-u').value='';$('#ft-s').value='';
                j.data.report.forEach(function(t){var d=document.createElement('div');d.textContent=t;$('#ft-r').appendChild(d);});});}
        $('#ft-go').onclick=function(){run('go');};$('#ft-ck').onclick=function(){run('check');};
    })();
    </script>
    <?php
}
