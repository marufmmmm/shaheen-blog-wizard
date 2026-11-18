<?php
/**
 * Facebook Post Manager (admin only): list a page's published and scheduled posts with reactions / comments / shares,
 * edit the text, delete, publish a scheduled post now, or move its time. Uses the page tokens of the auto-share panel.
 * Needs pages_manage_posts + pages_read_engagement.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_submenu_page(null, 'Facebook Post Manager', '🗂 Post Manager', 'manage_options', 'sb-fbmanager', 'sb_page_fbmanager');
}, 30);

add_action('wp_ajax_sb_fbm_list', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No permission.');
    }
    $pid   = preg_replace('/\D/', '', (string) ($_POST['page'] ?? ''));
    $tok   = function_exists('sb_fbin_token') ? sb_fbin_token($pid) : '';
    $sched = 'scheduled' === (string) ($_POST['mode'] ?? '');
    $after = preg_replace('/[^\w=\-]/', '', (string) ($_POST['after'] ?? ''));
    if ('' === $tok) {
        wp_send_json_error('Page not connected.');
    }
    $c    = sb_share_cfg();
    $args = array('limit' => 12);
    if ($sched) {
        $args['fields'] = 'id,message,scheduled_publish_time,full_picture,permalink_url,status_type';
    } else {
        $args['fields'] = 'id,message,created_time,permalink_url,full_picture,status_type,shares,reactions.summary(true).limit(0),comments.summary(true).limit(0)';
    }
    if ('' !== $after) {
        $args['after'] = $after;
    }
    $j = sb_share_graph($c, $pid . ($sched ? '/scheduled_posts' : '/published_posts'), $args, $tok);
    if (is_wp_error($j)) {
        wp_send_json_error($j->get_error_message());
    }
    $out = array();
    foreach ((array) ($j['data'] ?? array()) as $x) {
        $out[] = array(
            'id'    => (string) $x['id'],
            'msg'   => (string) ($x['message'] ?? ''),
            'time'  => $sched ? wp_date('d M Y H:i', (int) ($x['scheduled_publish_time'] ?? 0)) : wp_date('d M Y H:i', (int) strtotime((string) ($x['created_time'] ?? ''))),
            'ts'    => (int) ($x['scheduled_publish_time'] ?? 0),
            'url'   => (string) ($x['permalink_url'] ?? ''),
            'img'   => (string) ($x['full_picture'] ?? ''),
            'type'  => (string) ($x['status_type'] ?? ''),
            'react' => (int) ($x['reactions']['summary']['total_count'] ?? 0),
            'comm'  => (int) ($x['comments']['summary']['total_count'] ?? 0),
            'share' => (int) ($x['shares']['count'] ?? 0),
        );
    }
    wp_send_json_success(array('items' => $out, 'after' => (string) ($j['paging']['cursors']['after'] ?? ''), 'more' => !empty($j['paging']['next'])));
});

add_action('wp_ajax_sb_fbm_act', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No permission.');
    }
    $pid  = preg_replace('/\D/', '', (string) ($_POST['page'] ?? ''));
    $tok  = function_exists('sb_fbin_token') ? sb_fbin_token($pid) : '';
    $id   = preg_replace('/[^\d_]/', '', (string) ($_POST['id'] ?? ''));
    $what = (string) ($_POST['what'] ?? '');
    $text = sanitize_textarea_field(wp_unslash((string) ($_POST['text'] ?? '')));
    if ('' === $tok || '' === $id) {
        wp_send_json_error('Missing page or post.');
    }
    if (false !== strpos($id, '_') && 0 !== strpos($id, $pid . '_')) {
        wp_send_json_error('That post does not belong to this page.');
    }
    $c = sb_share_cfg();
    if ('edit' === $what && '' !== $text) {
        $r = sb_fbpub_call($c, $id, array('message' => $text), $tok);
    } elseif ('delete' === $what) {
        $r = sb_fbpub_delete($c, $id, $tok);
    } elseif ('now' === $what) {
        $r = sb_fbpub_call($c, $id, array('is_published' => 'true'), $tok);
    } elseif ('resched' === $what) {
        $ts = (int) strtotime((string) wp_unslash($_POST['when'] ?? '') . ' ' . wp_timezone_string());
        if ($ts < time() + 660) {
            wp_send_json_error('The new time must be at least 11 minutes from now.');
        }
        $r = sb_fbpub_call($c, $id, array('scheduled_publish_time' => $ts), $tok);
    } else {
        wp_send_json_error('Nothing to do.');
    }
    is_wp_error($r) ? wp_send_json_error($r->get_error_message()) : wp_send_json_success('ok');
});

function sb_page_fbmanager() {
    $pages = function_exists('sb_fbpub_pages') ? sb_fbpub_pages() : array();
    ?>
    <div class="wrap">
        <h1>🗂 Facebook Post Manager</h1>
        <p class="description">Every post on the page in one list. Edit the text, delete, or take a scheduled post live now. Deleting cannot be undone.</p>
        <p><select id="fm-pg"><?php foreach ($pages as $id => $p) : ?><option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($p['name']); ?></option><?php endforeach; ?></select>
            <label><input type="radio" name="fm-m" value="published" checked> Published</label>
            <label><input type="radio" name="fm-m" value="scheduled"> Scheduled</label>
            <button class="button button-primary" id="fm-go">🔄 Load</button> <span id="fm-m2" class="description"></span></p>
        <div id="fm-l" style="max-width:860px"></div>
        <p><button class="button" id="fm-more" hidden>⬇ Load older</button></p>
    </div>
    <script>
    (function(){var $=function(s){return document.querySelector(s);},aj=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nn=<?php echo wp_json_encode(wp_create_nonce('sb_admin')); ?>,after='',pg='',mode='published';
        function el(t,css,txt){var e=document.createElement(t);if(css){e.style.cssText=css;}if(txt!==undefined){e.textContent=txt;}return e;}
        function post(x){return fetch(aj,{method:'POST',credentials:'same-origin',body:new URLSearchParams(Object.assign({nonce:nn},x))}).then(function(r){return r.json();});}
        function btn(txt,fn){var b=el('button','',txt);b.className='button button-small';b.style.marginRight='4px';b.onclick=fn;return b;}
        function card(p){var c=el('div','display:flex;gap:10px;border:1px solid #d1d5db;border-radius:8px;padding:10px;margin-bottom:10px;background:#fff');
            if(p.img){var im=el('img','width:90px;height:90px;object-fit:cover;border-radius:6px;flex:none');im.src=p.img;c.appendChild(im);}
            var b=el('div','flex:1;min-width:0');var tx=el('div','white-space:pre-wrap;word-break:break-word',p.msg||'(no text)');
            if(p.msg.length>260){tx.textContent=p.msg.slice(0,260)+'…';}b.appendChild(tx);
            b.appendChild(el('div','color:#6b7280;margin:4px 0',p.time+(mode==='published'?'  ·  👍 '+p.react+'  💬 '+p.comm+'  🔁 '+p.share:'  ·  scheduled')+(p.type?'  ·  '+p.type:'')));
            var st=el('span','margin-left:6px');var act=el('div','');
            if(p.url){var a=el('a','margin-right:8px','View');a.href=p.url;a.target='_blank';a.rel='noopener';act.appendChild(a);}
            function run(what,extra,done){st.textContent='…';post(Object.assign({action:'sb_fbm_act',page:pg,id:p.id,what:what},extra||{})).then(function(j){if(j.success){st.textContent='✅';if(done){done();}}else{st.textContent='❌ '+j.data;}});}
            act.appendChild(btn('✏️ Edit',function(){var ed=el('div','margin-top:6px'),ta=el('textarea','width:100%');ta.rows=5;ta.value=p.msg;var sv=btn('Save',function(){run('edit',{text:ta.value},function(){p.msg=ta.value;tx.textContent=ta.value;ed.remove();});});var cx=btn('Cancel',function(){ed.remove();});ed.appendChild(ta);ed.appendChild(sv);ed.appendChild(cx);b.appendChild(ed);}));
            if(mode==='scheduled'){
                act.appendChild(btn('🚀 Publish now',function(){run('now',{},function(){c.remove();});}));
                act.appendChild(btn('🕒 New time',function(){var w=el('div','margin-top:6px'),i=el('input');i.type='datetime-local';var s=btn('Save time',function(){run('resched',{when:i.value.replace('T',' ')},function(){w.remove();});});w.appendChild(i);w.appendChild(s);b.appendChild(w);}));}
            var del=btn('🗑 Delete',function(){if(del.dataset.s!=='1'){del.dataset.s='1';del.textContent='⚠ Confirm delete';return;}run('delete',{},function(){c.remove();});});act.appendChild(del);act.appendChild(st);
            b.appendChild(act);c.appendChild(b);return c;}
        function load(more){pg=$('#fm-pg').value;mode=document.querySelector('input[name=fm-m]:checked').value;if(!more){after='';$('#fm-l').textContent='';}$('#fm-m2').textContent='Loading…';
            post({action:'sb_fbm_list',page:pg,mode:mode,after:more?after:''}).then(function(j){if(!j.success){$('#fm-m2').textContent='❌ '+j.data;return;}$('#fm-m2').textContent='';
                j.data.items.forEach(function(p){$('#fm-l').appendChild(card(p));});if(!j.data.items.length&&!more){$('#fm-l').textContent='Nothing here.';}
                after=j.data.after;$('#fm-more').hidden=!j.data.more;});}
        $('#fm-go').onclick=function(){load(false);};$('#fm-more').onclick=function(){load(true);};
    })();
    </script>
    <?php
}
