<?php
/**
 * Facebook inbox (admin only): recent posts with their comments, and Messenger conversations, for every connected page.
 * Reply to a comment, hide a comment, reply to a message. Uses the page tokens saved by the auto-share panel.
 * Needs: pages_read_engagement + pages_read_user_content (comments), pages_manage_engagement (reply / hide), pages_messaging (messages).
 * A missing permission shows Facebook's own message on that block; the rest keeps working.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_submenu_page('sb-dashboard', 'Facebook Inbox', '💬 Facebook Inbox', 'manage_options', 'sb-fbinbox', 'sb_page_fbinbox');
}, 31);

function sb_fbin_token($pid) {
    $pages = function_exists('sb_fbpub_pages') ? sb_fbpub_pages() : array();
    return isset($pages[$pid]) ? $pages[$pid]['token'] : '';
}

add_action('wp_ajax_sb_fbin_load', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No permission.');
    }
    $pid = preg_replace('/\D/', '', (string) ($_POST['page'] ?? ''));
    $tok = sb_fbin_token($pid);
    if ('' === $tok) {
        wp_send_json_error('Page not connected.');
    }
    $c   = sb_share_cfg();
    $out = array('posts' => array(), 'convs' => array(), 'err' => array());
    $p = sb_share_graph($c, $pid . '/published_posts', array(
        'fields' => 'id,message,created_time,permalink_url,comments.summary(true).limit(15).order(reverse_chronological){id,message,from,created_time,is_hidden,can_hide,can_comment}',
        'limit'  => 8,
    ), $tok);
    if (is_wp_error($p)) {
        $out['err']['comments'] = $p->get_error_message();
    } else {
        foreach ((array) ($p['data'] ?? array()) as $x) {
            $cm = array();
            foreach ((array) ($x['comments']['data'] ?? array()) as $k) {
                $cm[] = array('id' => $k['id'], 'msg' => (string) ($k['message'] ?? ''), 'from' => (string) ($k['from']['name'] ?? 'Someone'), 'time' => (string) ($k['created_time'] ?? ''), 'hidden' => !empty($k['is_hidden']));
            }
            $out['posts'][] = array('id' => $x['id'], 'msg' => wp_trim_words((string) ($x['message'] ?? '(no text)'), 18, '…'), 'time' => (string) ($x['created_time'] ?? ''),
                'url' => (string) ($x['permalink_url'] ?? ''), 'total' => (int) ($x['comments']['summary']['total_count'] ?? 0), 'comments' => $cm);
        }
    }
    $m = sb_share_graph($c, $pid . '/conversations', array(
        'fields' => 'id,updated_time,unread_count,participants,messages.limit(4){message,from,created_time}',
        'limit'  => 15,
    ), $tok);
    if (is_wp_error($m)) {
        $out['err']['messages'] = $m->get_error_message();
    } else {
        foreach ((array) ($m['data'] ?? array()) as $v) {
            $who = '';
            $psid = '';
            foreach ((array) ($v['participants']['data'] ?? array()) as $pt) {
                if ((string) $pt['id'] !== $pid) {
                    $who = (string) ($pt['name'] ?? 'Someone');
                    $psid = (string) $pt['id'];
                }
            }
            $ms = array();
            foreach (array_reverse((array) ($v['messages']['data'] ?? array())) as $mm) {
                $ms[] = array('msg' => (string) ($mm['message'] ?? '(attachment)'), 'me' => (string) ($mm['from']['id'] ?? '') === $pid, 'time' => (string) ($mm['created_time'] ?? ''));
            }
            $out['convs'][] = array('id' => $v['id'], 'who' => $who, 'psid' => $psid, 'unread' => (int) ($v['unread_count'] ?? 0), 'time' => (string) ($v['updated_time'] ?? ''), 'msgs' => $ms);
        }
    }
    wp_send_json_success($out);
});

add_action('wp_ajax_sb_fbin_act', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No permission.');
    }
    $pid  = preg_replace('/\D/', '', (string) ($_POST['page'] ?? ''));
    $tok  = sb_fbin_token($pid);
    $what = (string) ($_POST['what'] ?? '');
    $text = sanitize_textarea_field(wp_unslash((string) ($_POST['text'] ?? '')));
    $id   = preg_replace('/[^\w_]/', '', (string) ($_POST['id'] ?? ''));
    if ('' === $tok || '' === $id) {
        wp_send_json_error('Missing page or item.');
    }
    $c = sb_share_cfg();
    if ('reply' === $what && '' !== $text) {
        $r = sb_fbpub_call($c, $id . '/comments', array('message' => $text), $tok);
    } elseif ('hide' === $what || 'unhide' === $what) {
        $r = sb_fbpub_call($c, $id, array('is_hidden' => 'hide' === $what ? 'true' : 'false'), $tok);
    } elseif ('msg' === $what && '' !== $text) {
        $r = sb_fbpub_call($c, $pid . '/messages', array('recipient' => wp_json_encode(array('id' => $id)), 'messaging_type' => 'RESPONSE', 'message' => wp_json_encode(array('text' => $text))), $tok);
    } else {
        wp_send_json_error('Nothing to do.');
    }
    is_wp_error($r) ? wp_send_json_error($r->get_error_message()) : wp_send_json_success('ok');
});

function sb_page_fbinbox() {
    $pages = function_exists('sb_fbpub_pages') ? sb_fbpub_pages() : array();
    ?>
    <div class="wrap">
        <h1>💬 Facebook Inbox</h1>
        <p class="description">Recent comments on your posts and Messenger conversations. Messages can be answered only within 24 hours of the person's last message (Facebook's rule).</p>
        <p><select id="fi-pg"><?php foreach ($pages as $id => $p) : ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($p['name']); ?></option><?php endforeach; ?></select>
            <button class="button button-primary" id="fi-go">🔄 Load</button> <span id="fi-m" class="description"></span></p>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
            <div style="flex:1 1 420px;min-width:300px"><h2>Comments</h2><div id="fi-c"></div></div>
            <div style="flex:1 1 420px;min-width:300px"><h2>Messages</h2><div id="fi-s"></div></div>
        </div>
    </div>
    <script>
    (function(){var $=function(s){return document.querySelector(s);},aj=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nn=<?php echo wp_json_encode(wp_create_nonce('sb_admin')); ?>;
        function el(t,css,txt){var e=document.createElement(t);if(css){e.style.cssText=css;}if(txt!==undefined){e.textContent=txt;}return e;}
        function post(x){return fetch(aj,{method:'POST',credentials:'same-origin',body:new URLSearchParams(Object.assign({nonce:nn},x))}).then(function(r){return r.json();});}
        function box(parent,ph,btn,fn){var w=el('div','display:flex;gap:6px;margin-top:4px'),i=el('input','flex:1');i.placeholder=ph;var b=el('button','',btn);b.className='button';
            b.onclick=function(){if(!i.value.trim()){return;}b.disabled=true;fn(i.value,function(ok,msg){b.disabled=false;if(ok){i.value='';b.textContent='✅ sent';}else{b.textContent='❌ '+msg;}});};w.appendChild(i);w.appendChild(b);parent.appendChild(w);}
        function load(){var pg=$('#fi-pg').value;$('#fi-m').textContent='Loading…';$('#fi-c').textContent='';$('#fi-s').textContent='';
            post({action:'sb_fbin_load',page:pg}).then(function(j){if(!j.success){$('#fi-m').textContent='❌ '+j.data;return;}var d=j.data;$('#fi-m').textContent='';
                if(d.err.comments){$('#fi-c').appendChild(el('div','color:#b32d2e','⚠ '+d.err.comments));}
                d.posts.forEach(function(p){var card=el('div','border:1px solid #d1d5db;border-radius:8px;padding:8px;margin-bottom:10px;background:#fff');
                    var h=el('div','font-weight:600',p.msg+'  ('+p.total+' comments)');card.appendChild(h);
                    p.comments.forEach(function(c){var r=el('div','border-top:1px solid #eee;margin-top:6px;padding-top:6px'+(c.hidden?';opacity:.5':''));
                        r.appendChild(el('div','',c.from+': '+c.msg));
                        var act=el('div','margin-top:3px');var hb=el('button','',c.hidden?'Unhide':'Hide');hb.className='button button-small';
                        hb.onclick=function(){post({action:'sb_fbin_act',page:pg,what:c.hidden?'unhide':'hide',id:c.id}).then(function(x){hb.textContent=x.success?'✅':'❌ '+x.data;});};act.appendChild(hb);r.appendChild(act);
                        box(r,'Reply…','Reply',function(t,cb){post({action:'sb_fbin_act',page:pg,what:'reply',id:c.id,text:t}).then(function(x){cb(x.success,x.data);});});card.appendChild(r);});
                    $('#fi-c').appendChild(card);});
                if(d.err.messages){$('#fi-s').appendChild(el('div','color:#b32d2e','⚠ '+d.err.messages));}
                d.convs.forEach(function(v){var card=el('div','border:1px solid #d1d5db;border-radius:8px;padding:8px;margin-bottom:10px;background:#fff');
                    card.appendChild(el('div','font-weight:600',v.who+(v.unread?'  · '+v.unread+' unread':'')));
                    v.msgs.forEach(function(m){card.appendChild(el('div','margin-top:3px;'+(m.me?'text-align:right;color:#1d4ed8':''),m.msg));});
                    box(card,'Reply…','Send',function(t,cb){post({action:'sb_fbin_act',page:pg,what:'msg',id:v.psid,text:t}).then(function(x){cb(x.success,x.data);});});
                    $('#fi-s').appendChild(card);});
                if(!d.posts.length&&!d.err.comments){$('#fi-c').textContent='No posts.';}if(!d.convs.length&&!d.err.messages){$('#fi-s').textContent='No conversations.';}});}
        $('#fi-go').onclick=load;
    })();
    </script>
    <?php
}
