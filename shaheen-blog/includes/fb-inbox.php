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
    add_submenu_page(null, 'Facebook Inbox', '💬 Facebook Inbox', 'manage_options', 'sb-fbinbox', 'sb_page_fbinbox');
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
    $out = array('posts' => array(), 'convs' => array(), 'err' => array(), 'pafter' => '', 'cafter' => '');
    $pa  = preg_replace('/[^\w=\-]/', '', (string) ($_POST['pafter'] ?? ''));
    $ca  = preg_replace('/[^\w=\-]/', '', (string) ($_POST['cafter'] ?? ''));
    $part = (string) ($_POST['part'] ?? 'both');
    $pargs = array(
        'fields' => 'id,message,created_time,permalink_url,comments.summary(true).limit(15).order(reverse_chronological){id,message,from,created_time,is_hidden,can_hide,can_comment,comment_count,like_count,user_likes}',
        'limit'  => 8,
    );
    if ('' !== $pa) {
        $pargs['after'] = $pa;
    }
    $p = 'convs' === $part ? new WP_Error('skip', '') : sb_share_graph($c, $pid . '/published_posts', $pargs, $tok);
    if (is_wp_error($p)) {
        if ('skip' !== $p->get_error_code()) {
            $out['err']['comments'] = $p->get_error_message();
        }
    } else {
        $out['pafter'] = (string) ($p['paging']['cursors']['after'] ?? '');
        if (empty($p['paging']['next'])) {
            $out['pafter'] = '';
        }
        foreach ((array) ($p['data'] ?? array()) as $x) {
            $cm = array();
            foreach ((array) ($x['comments']['data'] ?? array()) as $k) {
                $cm[] = array('id' => $k['id'], 'msg' => (string) ($k['message'] ?? ''), 'from' => (string) ($k['from']['name'] ?? 'Someone'), 'time' => (string) ($k['created_time'] ?? ''), 'hidden' => !empty($k['is_hidden']), 'likes' => (int) ($k['like_count'] ?? 0), 'liked' => !empty($k['user_likes']), 'replies' => (int) ($k['comment_count'] ?? 0));
            }
            $out['posts'][] = array('id' => $x['id'], 'msg' => wp_trim_words((string) ($x['message'] ?? '(no text)'), 18, '…'), 'time' => (string) ($x['created_time'] ?? ''),
                'url' => (string) ($x['permalink_url'] ?? ''), 'total' => (int) ($x['comments']['summary']['total_count'] ?? 0), 'comments' => $cm);
        }
    }
    $margs = array(
        'fields' => 'id,updated_time,unread_count,participants,messages.limit(6){message,from,created_time,attachments}',
        'limit'  => 15,
    );
    if ('' !== $ca) {
        $margs['after'] = $ca;
    }
    $m = 'posts' === $part ? new WP_Error('skip', '') : sb_share_graph($c, $pid . '/conversations', $margs, $tok);
    if (is_wp_error($m)) {
        if ('skip' !== $m->get_error_code()) {
            $out['err']['messages'] = $m->get_error_message();
        }
    } else {
        $out['cafter'] = empty($m['paging']['next']) ? '' : (string) ($m['paging']['cursors']['after'] ?? '');
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
                $ms[] = array('msg' => '' !== (string) ($mm['message'] ?? '') ? (string) $mm['message'] : '📎 (attachment)', 'me' => (string) ($mm['from']['id'] ?? '') === $pid, 'time' => (string) ($mm['created_time'] ?? ''));
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
    } elseif ('like' === $what) {
        $r = sb_fbpub_call($c, $id . '/likes', array(), $tok);
    } elseif ('delete' === $what) {
        $r = sb_fbpub_delete($c, $id, $tok);
    } elseif ('private' === $what && '' !== $text) {   // one private Messenger reply to a commenter (within 7 days of the comment)
        $r = sb_fbpub_call($c, $id . '/private_replies', array('message' => $text), $tok);
    } elseif ('seen' === $what) {
        $r = sb_fbpub_call($c, $pid . '/messages', array('recipient' => wp_json_encode(array('id' => $id)), 'sender_action' => 'mark_seen'), $tok);
    } elseif ('msg' === $what && '' !== $text) {
        $base = array('recipient' => wp_json_encode(array('id' => $id)), 'message' => wp_json_encode(array('text' => $text)));
        $r = sb_fbpub_call($c, $pid . '/messages', array_merge($base, array('messaging_type' => 'RESPONSE')), $tok);
        if (is_wp_error($r)) {   // outside the 24-hour window: try the 7-day human-agent tag (needs that permission)
            $r2 = sb_fbpub_call($c, $pid . '/messages', array_merge($base, array('messaging_type' => 'MESSAGE_TAG', 'tag' => 'HUMAN_AGENT')), $tok);
            if (!is_wp_error($r2)) {
                $r = $r2;
            }
        }
    } else {
        wp_send_json_error('Nothing to do.');
    }
    is_wp_error($r) ? wp_send_json_error($r->get_error_message()) : wp_send_json_success('ok');
});

/** Saved quick replies (shared by all admins). */
add_action('wp_ajax_sb_fbin_quick', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No permission.');
    }
    if (isset($_POST['set'])) {
        $l = array_slice(array_values(array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash((string) $_POST['set'])))))), 0, 20);
        update_option('sb_fbin_quick', $l, false);
    }
    wp_send_json_success(array_values((array) get_option('sb_fbin_quick', array())));
});

function sb_page_fbinbox() {
    $pages = function_exists('sb_fbpub_pages') ? sb_fbpub_pages() : array();
    ?>
    <div class="wrap">
        <h1>💬 Facebook Inbox</h1>
        <p class="description">Recent comments on your posts and Messenger conversations. A message can be answered within 24 hours of the person's last message (Facebook's rule); older ones are tried with Facebook's 7-day human-agent tag. "Private reply" sends one Messenger message to a commenter.</p>
        <p><select id="fi-pg"><?php foreach ($pages as $id => $p) : ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($p['name']); ?></option><?php endforeach; ?></select>
            <button class="button button-primary" id="fi-go">🔄 Load</button>
            <label><input type="checkbox" id="fi-un"> Unread messages only</label>
            <label><input type="checkbox" id="fi-nc"> Comments needing a reply only</label>
            <button class="button" id="fi-q">⚡ Quick replies</button> <span id="fi-m" class="description"></span></p>
        <div id="fi-qe" hidden style="max-width:640px;margin-bottom:10px"><textarea id="fi-qt" rows="6" style="width:100%" placeholder="One quick reply per line"></textarea><button class="button" id="fi-qs">Save quick replies</button> <span id="fi-qm" class="description"></span></div>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
            <div style="flex:1 1 420px;min-width:300px"><h2>Comments</h2><div id="fi-c"></div><button class="button" id="fi-cm" hidden>⬇ Older posts</button></div>
            <div style="flex:1 1 420px;min-width:300px"><h2>Messages</h2><div id="fi-s"></div><button class="button" id="fi-sm" hidden>⬇ Older conversations</button></div>
        </div>
    </div>
    <script>
    (function(){var $=function(s){return document.querySelector(s);},aj=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nn=<?php echo wp_json_encode(wp_create_nonce('sb_admin')); ?>,pg='',pa='',ca='',quick=[];
        function el(t,css,txt){var e=document.createElement(t);if(css){e.style.cssText=css;}if(txt!==undefined){e.textContent=txt;}return e;}
        function post(x){return fetch(aj,{method:'POST',credentials:'same-origin',body:new URLSearchParams(Object.assign({nonce:nn},x))}).then(function(r){return r.json();});}
        function sm(b,txt,css){b.className='button button-small';b.style.marginRight='4px';return b;}
        function qsel(i){if(!quick.length){return null;}var s=el('select','max-width:140px');var o=el('option','','⚡');o.value='';s.appendChild(o);quick.forEach(function(q){var op=el('option','',q.slice(0,40));op.value=q;s.appendChild(op);});s.onchange=function(){if(s.value){i.value=s.value;s.value='';}};return s;}
        function box(parent,ph,btn,fn){var w=el('div','display:flex;gap:6px;margin-top:4px'),i=el('input','flex:1');i.placeholder=ph;var b=el('button','',btn);b.className='button';
            b.onclick=function(){if(!i.value.trim()){return;}b.disabled=true;fn(i.value,function(ok,msg){b.disabled=false;if(ok){i.value='';b.textContent='✅ sent';}else{b.textContent='❌ '+msg;}});};
            var q=qsel(i);if(q){w.appendChild(q);}w.appendChild(i);w.appendChild(b);parent.appendChild(w);}
        function act(x,b,done){b.textContent='…';post(Object.assign({action:'sb_fbin_act',page:pg},x)).then(function(r){if(r.success){b.textContent='✅';if(done){done();}}else{b.textContent='❌ '+r.data;}});}
        function aiDraft(r,c,p,ab){var old=r.querySelector('.ai-d');if(old){old.remove();}var w=el('div','margin-top:6px;padding:8px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px');w.className='ai-d';r.appendChild(w);w.textContent='🤖 লিখছে…';ab.disabled=true;
            post({action:'sb_fbai_draft',page:pg,id:c.id,post:p.id,msg:c.msg}).then(function(j){ab.disabled=false;w.textContent='';
                if(!j.success){w.appendChild(el('div','color:#b32d2e','❌ '+j.data));return;}
                var d=j.data;
                if(d.skip){w.appendChild(el('div','','👍 '+d.why+' — উত্তর লাগবে না, লাইকই যথেষ্ট।'));return;}
                w.appendChild(el('div','color:#6b7280;font-size:12px',d.info));
                var ta=el('textarea','width:100%;margin-top:4px');ta.rows=3;ta.value=d.reply||'';w.appendChild(ta);
                if(!d.reply){w.appendChild(el('div','color:#b45309','ℹ '+(d.why||'এই তথ্য পাইনি')+' — চাইলে নিজে লিখে পাঠান (এটাও শেখা হবে)।'));}
                var row=el('div','margin-top:4px'),ok=sm(el('button','','✔ পাঠান')),bad=sm(el('button','','✖ ঠিক নয়')),rg=sm(el('button','','↻ আবার লিখুন'));ok.className='button button-primary button-small';
                ok.onclick=function(){var t=ta.value.trim();if(!t){return;}ok.disabled=true;ok.textContent='…';post({action:'sb_fbin_act',page:pg,what:'reply',id:c.id,text:t}).then(function(x){if(x.success){ok.textContent='✅ পাঠানো হয়েছে';bad.remove();post({action:'sb_fbai_feedback',verdict:(t===(d.reply||''))?'ok':'edit',q:c.msg,a:t});}else{ok.disabled=false;ok.textContent='❌ '+x.data;}});};
                bad.onclick=function(){bad.style.display='none';var i=el('input','width:60%');i.placeholder='কেন ঠিক নয়? (ঐচ্ছিক)';var g=sm(el('button','','জমা দিন'));g.onclick=function(){post({action:'sb_fbai_feedback',verdict:'bad',q:c.msg,a:ta.value,note:i.value}).then(function(){w.textContent='✖ বাতিল — এভাবে আর লিখবে না।';});};row.appendChild(i);row.appendChild(g);};
                rg.onclick=function(){aiDraft(r,c,p,ab);};
                row.appendChild(ok);row.appendChild(bad);row.appendChild(rg);w.appendChild(row);});}
        function addPosts(d){var nc=$('#fi-nc').checked;
            if(d.err.comments){$('#fi-c').appendChild(el('div','color:#b32d2e','⚠ '+d.err.comments));}
            d.posts.forEach(function(p){var card=el('div','border:1px solid #d1d5db;border-radius:8px;padding:8px;margin-bottom:10px;background:#fff');var n=0;
                var h=el('div','font-weight:600',p.msg+'  ('+p.total+' comments)');if(p.url){var a=el('a','margin-left:6px;font-weight:400','View');a.href=p.url;a.target='_blank';a.rel='noopener';h.appendChild(a);}card.appendChild(h);
                p.comments.forEach(function(c){if(nc&&c.replies>0){return;}n++;var r=el('div','border-top:1px solid #eee;margin-top:6px;padding-top:6px'+(c.hidden?';opacity:.5':''));
                    r.appendChild(el('div','',c.from+': '+c.msg));r.appendChild(el('div','color:#6b7280',new Date(c.time).toLocaleString()+(c.likes?'  ·  👍 '+c.likes:'')+(c.replies?'  ·  ↩ '+c.replies+' replies':'')));
                    var ac=el('div','margin-top:3px');
                    var hb=sm(el('button','',c.hidden?'Unhide':'Hide'));hb.onclick=function(){act({what:c.hidden?'unhide':'hide',id:c.id},hb);};ac.appendChild(hb);
                    var lb=sm(el('button','',c.liked?'👍 Liked':'👍 Like'));lb.onclick=function(){act({what:'like',id:c.id},lb);};ac.appendChild(lb);
                    var db=sm(el('button','','🗑 Delete'));db.onclick=function(){if(db.dataset.s!=='1'){db.dataset.s='1';db.textContent='⚠ Confirm';return;}act({what:'delete',id:c.id},db,function(){r.remove();});};ac.appendChild(db);var ab=sm(el('button','','🤖 AI উত্তর'));ab.onclick=function(){aiDraft(r,c,p,ab);};ac.appendChild(ab);r.appendChild(ac);
                    box(r,'Reply publicly…','Reply',function(t,cb){post({action:'sb_fbin_act',page:pg,what:'reply',id:c.id,text:t}).then(function(x){cb(x.success,x.data);});});
                    box(r,'Private reply on Messenger…','Send privately',function(t,cb){post({action:'sb_fbin_act',page:pg,what:'private',id:c.id,text:t}).then(function(x){cb(x.success,x.data);});});
                    card.appendChild(r);});
                if(!nc||n){$('#fi-c').appendChild(card);}});}
        function addConvs(d){var un=$('#fi-un').checked;
            if(d.err.messages){$('#fi-s').appendChild(el('div','color:#b32d2e','⚠ '+d.err.messages));}
            d.convs.forEach(function(v){if(un&&!v.unread){return;}var card=el('div','border:1px solid #d1d5db;border-radius:8px;padding:8px;margin-bottom:10px;background:'+(v.unread?'#fffbeb':'#fff'));
                var h=el('div','font-weight:600',v.who+(v.unread?'  · '+v.unread+' unread':''));card.appendChild(h);
                if(v.unread){var sb=sm(el('button','','Mark read'));sb.style.marginLeft='6px';sb.onclick=function(){act({what:'seen',id:v.psid},sb,function(){card.style.background='#fff';});};h.appendChild(sb);}
                v.msgs.forEach(function(m){card.appendChild(el('div','margin-top:3px;white-space:pre-wrap;'+(m.me?'text-align:right;color:#1d4ed8':''),m.msg));});
                box(card,'Reply…','Send',function(t,cb){post({action:'sb_fbin_act',page:pg,what:'msg',id:v.psid,text:t}).then(function(x){cb(x.success,x.data);});});
                $('#fi-s').appendChild(card);});}
        function fetchQ(){return post({action:'sb_fbin_quick'}).then(function(j){quick=j.success?j.data:[];$('#fi-qt').value=quick.join('\n');});}
        function load(part){pg=$('#fi-pg').value;var first=part==='both';if(first){pa='';ca='';$('#fi-c').textContent='';$('#fi-s').textContent='';}$('#fi-m').textContent='Loading…';
            post({action:'sb_fbin_load',page:pg,part:part,pafter:part==='convs'?'':pa,cafter:part==='posts'?'':ca}).then(function(j){if(!j.success){$('#fi-m').textContent='❌ '+j.data;return;}var d=j.data;$('#fi-m').textContent='';
                if(part!=='convs'){addPosts(d);pa=d.pafter;$('#fi-cm').hidden=!pa;if(first&&!d.posts.length&&!d.err.comments){$('#fi-c').textContent='No posts.';}}
                if(part!=='posts'){addConvs(d);ca=d.cafter;$('#fi-sm').hidden=!ca;if(first&&!d.convs.length&&!d.err.messages){$('#fi-s').textContent='No conversations.';}}});}
        $('#fi-go').onclick=function(){fetchQ().then(function(){load('both');});};$('#fi-cm').onclick=function(){load('posts');};$('#fi-sm').onclick=function(){load('convs');};
        $('#fi-q').onclick=function(){$('#fi-qe').hidden=!$('#fi-qe').hidden;};
        $('#fi-qs').onclick=function(){post({action:'sb_fbin_quick',set:$('#fi-qt').value}).then(function(j){if(j.success){quick=j.data;$('#fi-qm').textContent='✅ saved ('+quick.length+')';}else{$('#fi-qm').textContent='❌ '+j.data;}});};
    })();
    </script>
    <?php
}
