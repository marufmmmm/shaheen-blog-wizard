<?php
/**
 * Facebook / Instagram publisher (admin only): write once, choose Post / Reel / Story, choose the pages, post now or schedule.
 * Uses the page tokens already saved by the auto-share panel (main page + "more pages"). Media is uploaded to the media library
 * first and Facebook fetches it from its public URL.
 * Instagram collab: up to 3 usernames are sent with the Instagram media; they accept in the Instagram app.
 * Facebook itself offers no collaborator-post API, so "collaborators" on Facebook = the same post on several of your own pages.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_submenu_page('sb-dashboard', 'Facebook Post', '📘 Facebook Post', 'manage_options', 'sb-fbpost', 'sb_page_fbpost');
}, 30);

/** All pages that can be posted to: id => [name, token]. */
function sb_fbpub_pages() {
    $c   = sb_share_cfg();
    $out = array();
    if ('' !== $c['page_id'] && '' !== $c['page_token']) {
        $out[(string) $c['page_id']] = array('name' => 'Main page', 'token' => $c['page_token']);
    }
    foreach ((array) get_option('sb_share_pages', array()) as $id => $p) {
        if (!empty($p['token'])) {
            $out[(string) $id] = array('name' => (string) ($p['name'] ?: $id), 'token' => (string) $p['token']);
        }
    }
    return $out;
}

function sb_fbpub_call($c, $path, $args, $token, $headers = array()) {
    $args['access_token'] = $token;
    $r = wp_remote_post('https://graph.facebook.com/' . $c['api_ver'] . '/' . ltrim($path, '/'), array('timeout' => 90, 'body' => $args, 'headers' => $headers));
    if (is_wp_error($r)) {
        return $r;
    }
    $j = json_decode((string) wp_remote_retrieve_body($r), true);
    if (!is_array($j) || isset($j['error'])) {
        return new WP_Error('sb_fbp', is_array($j) && isset($j['error']['message']) ? (string) $j['error']['message'] : 'No answer (HTTP ' . (int) wp_remote_retrieve_response_code($r) . ')');
    }
    return $j;
}

/** Send the video bytes straight to Facebook's upload URL (the site's own file when possible); falls back to letting Facebook fetch the URL. */
function sb_fbpub_upload_media($upload_url, $tok, $media_url) {
    $up   = wp_upload_dir();
    $path = '';
    if (0 === strpos($media_url, $up['baseurl'])) {
        $path = $up['basedir'] . substr($media_url, strlen($up['baseurl']));
    }
    if ('' !== $path && is_file($path) && filesize($path) < 150 * MB_IN_BYTES) {
        $bytes = file_get_contents($path);
        $r = wp_remote_post($upload_url, array('timeout' => 180, 'body' => $bytes, 'headers' => array(
            'Authorization' => 'OAuth ' . $tok, 'offset' => '0', 'file_size' => (string) strlen($bytes), 'Content-Type' => 'application/octet-stream',
        )));
    } else {
        $r = wp_remote_post($upload_url, array('timeout' => 120, 'headers' => array('Authorization' => 'OAuth ' . $tok, 'file_url' => $media_url)));
    }
    $j = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
    if (is_wp_error($r) || empty($j['success'])) {
        return new WP_Error('sb_fbp', 'Upload failed: ' . (is_wp_error($r) ? $r->get_error_message() : substr((string) wp_remote_retrieve_body($r), 0, 200)));
    }
    return true;
}

/** Publish one item on one Facebook page. $o: kind (post|reel|story), text, media (url), mtype (photo|video|none), when (unix or 0), draft (bool). */
function sb_fbpub_page($c, $pid, $tok, $o) {
    $when  = (int) $o['when'];
    $sched = $when > time() + 600 ? array('published' => 'false', 'scheduled_publish_time' => $when) : array();
    if (!empty($o['draft'])) {
        $sched = array('published' => 'false');   // unpublished (private) item: used for testing
    }
    $kind = $o['kind'];
    if ('post' === $kind) {
        if ('photo' === $o['mtype']) {
            $j = sb_fbpub_call($c, $pid . '/photos', array_merge(array('url' => $o['media'], 'caption' => $o['text']), $sched), $tok);
        } elseif ('video' === $o['mtype']) {
            $j = sb_fbpub_call($c, $pid . '/videos', array_merge(array('file_url' => $o['media'], 'description' => $o['text']), $sched), $tok);
        } else {
            $ph = array_values(array_filter((array) ($o['photos'] ?? array())));
            if (count($ph) > 1) {   // album-style post: each photo is uploaded unpublished, then attached to one feed post
                $att = array();
                foreach ($ph as $i => $u) {
                    $r = sb_fbpub_call($c, $pid . '/photos', array('url' => $u, 'published' => 'false'), $tok);
                    if (is_wp_error($r) || empty($r['id'])) {
                        return is_wp_error($r) ? $r : new WP_Error('sb_fbp', 'Photo ' . ($i + 1) . ' could not be uploaded.');
                    }
                    $att['attached_media[' . $i . ']'] = wp_json_encode(array('media_fbid' => $r['id']));
                }
                $j = sb_fbpub_call($c, $pid . '/feed', array_merge(array('message' => $o['text']), $att, $sched), $tok);
            } else {
                $args = array('message' => $o['text']);
                if (!empty($o['link'])) {
                    $args['link'] = $o['link'];
                }
                $j = sb_fbpub_call($c, $pid . '/feed', array_merge($args, $sched), $tok);
            }
        }
        return is_wp_error($j) ? $j : (string) ($j['post_id'] ?? $j['id'] ?? 'ok');
    }
    if ('reel' === $kind) {
        if ('video' !== $o['mtype']) {
            return new WP_Error('sb_fbp', 'A Reel needs a video.');
        }
        $s = sb_fbpub_call($c, $pid . '/video_reels', array('upload_phase' => 'start'), $tok);
        if (is_wp_error($s) || empty($s['video_id']) || empty($s['upload_url'])) {
            return is_wp_error($s) ? $s : new WP_Error('sb_fbp', 'Reel start failed');
        }
        $u = sb_fbpub_upload_media($s['upload_url'], $tok, $o['media']);
        if (is_wp_error($u)) {
            return $u;
        }
        $fin = array('upload_phase' => 'finish', 'video_id' => $s['video_id'], 'description' => $o['text']);
        if (!empty($o['draft'])) {
            $fin['video_state'] = 'DRAFT';
        } elseif ($when > time() + 600) {
            $fin['video_state'] = 'SCHEDULED';
            $fin['scheduled_publish_time'] = $when;
        } else {
            $fin['video_state'] = 'PUBLISHED';
        }
        $f = sb_fbpub_call($c, $pid . '/video_reels', $fin, $tok);
        return is_wp_error($f) ? $f : 'reel ' . $s['video_id'];
    }
    if ('story' === $kind) {
        if ('photo' === $o['mtype']) {
            $ph = sb_fbpub_call($c, $pid . '/photos', array('url' => $o['media'], 'published' => 'false'), $tok);
            if (is_wp_error($ph) || empty($ph['id'])) {
                return is_wp_error($ph) ? $ph : new WP_Error('sb_fbp', 'Photo step failed');
            }
            $st = sb_fbpub_call($c, $pid . '/photo_stories', array('photo_id' => $ph['id']), $tok);
            return is_wp_error($st) ? $st : 'story ' . ($st['post_id'] ?? 'ok');
        }
        if ('video' === $o['mtype']) {
            $s = sb_fbpub_call($c, $pid . '/video_stories', array('upload_phase' => 'start'), $tok);
            if (is_wp_error($s) || empty($s['video_id']) || empty($s['upload_url'])) {
                return is_wp_error($s) ? $s : new WP_Error('sb_fbp', 'Story start failed');
            }
            $u = sb_fbpub_upload_media($s['upload_url'], $tok, $o['media']);
            if (is_wp_error($u)) {
                return $u;
            }
            $f = sb_fbpub_call($c, $pid . '/video_stories', array('upload_phase' => 'finish', 'video_id' => $s['video_id']), $tok);
            return is_wp_error($f) ? $f : 'story ' . $s['video_id'];
        }
        return new WP_Error('sb_fbp', 'A Story needs a photo or a video.');
    }
    return new WP_Error('sb_fbp', 'Unknown type');
}

/** Instagram: post, reel or story, with optional collaborators (usernames). */
function sb_fbpub_ig($c, $o, $collab) {
    if ('' === $c['ig_id'] || '' === $c['ig_token']) {
        return new WP_Error('sb_fbp', 'Instagram is not connected.');
    }
    if ('none' === $o['mtype']) {
        return new WP_Error('sb_fbp', 'Instagram needs a photo or a video.');
    }
    $a = array('caption' => $o['text']);
    $video = 'video' === $o['mtype'];
    if ('story' === $o['kind']) {
        $a['media_type'] = 'STORIES';
        $a[$video ? 'video_url' : 'image_url'] = $o['media'];
    } elseif ($video) {
        $a['media_type'] = 'REELS';
        $a['video_url']  = $o['media'];
    } else {
        $a['image_url'] = $o['media'];
    }
    if ($collab && 'story' !== $o['kind']) {
        $a['collaborators'] = wp_json_encode(array_values($collab));
    }
    $m = sb_fbpub_call($c, $c['ig_id'] . '/media', $a, $c['ig_token']);
    if (is_wp_error($m) || empty($m['id'])) {
        return is_wp_error($m) ? $m : new WP_Error('sb_fbp', 'Instagram container failed');
    }
    for ($i = 0; $i < 20; $i++) {   // wait until Instagram has processed the file
        $s = sb_share_graph($c, $m['id'], array('fields' => 'status_code,status'), $c['ig_token']);
        $code = is_wp_error($s) ? '' : (string) ($s['status_code'] ?? '');
        if ('FINISHED' === $code) {
            break;
        }
        if ('ERROR' === $code) {
            return new WP_Error('sb_fbp', 'Instagram could not process the file: ' . (string) ($s['status'] ?? ''));
        }
        sleep(4);
    }
    $p = sb_fbpub_call($c, $c['ig_id'] . '/media_publish', array('creation_id' => $m['id']), $c['ig_token']);
    return is_wp_error($p) ? $p : 'ig ' . ($p['id'] ?? 'ok');
}

add_action('wp_ajax_sb_fbpub_upload', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options') || empty($_FILES['f'])) {
        wp_send_json_error('No file.');
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $id = media_handle_upload('f', 0);
    if (is_wp_error($id)) {
        wp_send_json_error($id->get_error_message());
    }
    $mime = (string) get_post_mime_type($id);
    wp_send_json_success(array('url' => wp_get_attachment_url($id), 'mtype' => 0 === strpos($mime, 'video') ? 'video' : (0 === strpos($mime, 'image') ? 'photo' : 'none')));
});

add_action('wp_ajax_sb_fbpub_send', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No permission.');
    }
    @set_time_limit(300);
    $c    = sb_share_cfg();
    $d    = json_decode(wp_unslash((string) ($_POST['d'] ?? '')), true);
    $d    = is_array($d) ? $d : array();
    $o    = array(
        'kind'  => in_array($d['kind'] ?? '', array('post', 'reel', 'story'), true) ? $d['kind'] : 'post',
        'text'  => sanitize_textarea_field((string) ($d['text'] ?? '')),
        'media' => esc_url_raw((string) ($d['media'] ?? '')),
        'mtype' => in_array($d['mtype'] ?? '', array('photo', 'video'), true) ? $d['mtype'] : 'none',
        'when'  => !empty($d['when']) ? (int) strtotime((string) $d['when'] . ' ' . wp_timezone_string()) : 0,
        'draft' => !empty($d['draft']),
    );
    if ('' === $o['media']) {
        $o['mtype'] = 'none';
    }
    $o['link']   = esc_url_raw((string) ($d['link'] ?? ''));
    $o['photos'] = array();
    if ('post' === $o['kind'] && 'photo' === $o['mtype']) {
        foreach (array_slice((array) ($d['photos'] ?? array()), 0, 10) as $u) {
            $u = esc_url_raw((string) $u);
            if ('' !== $u) {
                $o['photos'][] = $u;
            }
        }
        if (count($o['photos']) > 1) {
            $o['media'] = $o['photos'][0];
        } else {
            $o['photos'] = array();
        }
    }
    $all  = sb_fbpub_pages();
    $pick = array_map('strval', (array) ($d['pages'] ?? array()));
    $log  = array();
    foreach ($pick as $pid) {
        if (!isset($all[$pid])) {
            continue;
        }
        $r = sb_fbpub_page($c, $pid, $all[$pid]['token'], $o);
        $log[] = array('to' => 'FB · ' . $all[$pid]['name'], 'ok' => !is_wp_error($r), 'msg' => is_wp_error($r) ? $r->get_error_message() : $r);
    }
    if (!empty($d['ig']) && empty($o['draft']) && !($o['when'] > time() + 600)) {
        $collab = array_slice(array_filter(array_map(function ($u) { return ltrim(trim($u), '@'); }, preg_split('/[\s,]+/', (string) ($d['collab'] ?? '')))), 0, 3);
        $r = sb_fbpub_ig($c, $o, $collab);
        $log[] = array('to' => 'Instagram' . ($collab ? ' + collab @' . implode(', @', $collab) : ''), 'ok' => !is_wp_error($r), 'msg' => is_wp_error($r) ? $r->get_error_message() : $r);
    } elseif (!empty($d['ig'])) {
        $log[] = array('to' => 'Instagram', 'ok' => false, 'msg' => 'Skipped: Instagram posts now only (no schedule or draft here).');
    }
    $hist = get_option('sb_fbpub_log', array());
    array_unshift($hist, array('t' => time(), 'kind' => $o['kind'], 'log' => $log));
    update_option('sb_fbpub_log', array_slice((array) $hist, 0, 30), false);
    wp_send_json_success(array('log' => $log));
});


/** DELETE helper for the Facebook Graph API (used by the post manager and the inbox). */
function sb_fbpub_delete($c, $path, $token) {
    $r = wp_remote_request('https://graph.facebook.com/' . $c['api_ver'] . '/' . ltrim($path, '/') . '?' . http_build_query(array('access_token' => $token)), array('method' => 'DELETE', 'timeout' => 40));
    if (is_wp_error($r)) {
        return $r;
    }
    $j = json_decode((string) wp_remote_retrieve_body($r), true);
    if (!is_array($j) || isset($j['error'])) {
        return new WP_Error('sb_fbp', is_array($j) && isset($j['error']['message']) ? (string) $j['error']['message'] : 'No answer (HTTP ' . (int) wp_remote_retrieve_response_code($r) . ')');
    }
    return $j;
}

/** Saved Facebook groups (name | url per line). Facebook's API cannot post into groups, so these are opened for a manual share. */
add_action('wp_ajax_sb_fbpub_groups', function () {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No permission.');
    }
    if (isset($_POST['set'])) {
        $out = array();
        foreach (explode("\n", (string) wp_unslash($_POST['set'])) as $ln) {
            $pt = array_map('trim', explode('|', $ln, 2));
            $u  = esc_url_raw(count($pt) > 1 ? $pt[1] : $pt[0]);
            if ('' !== $u && preg_match('#^https://(www\.|m\.)?facebook\.com/groups/#', $u)) {
                $out[] = array('n' => sanitize_text_field(count($pt) > 1 ? $pt[0] : $u), 'u' => $u);
            }
        }
        update_option('sb_fbpub_groups', array_slice($out, 0, 60), false);
    }
    wp_send_json_success(array_values((array) get_option('sb_fbpub_groups', array())));
});

function sb_page_fbpost() {
    $pages = sb_fbpub_pages();
    $c     = sb_share_cfg();
    $blog  = array();
    foreach (get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 15)) as $bp) {
        $blog[] = array('t' => html_entity_decode(get_the_title($bp), ENT_QUOTES, 'UTF-8'), 'u' => get_permalink($bp), 'img' => (string) get_the_post_thumbnail_url($bp, 'large'));
    }
    ?>
    <div class="wrap">
        <h1>📘 Facebook Post</h1>
        <p class="description">Write once, choose the type and the pages. Instagram collab invites are accepted by the other account in the Instagram app. Facebook has no collaborator API, so "more pages" means the same post on each page you manage. Posts already published are managed in <b>🗂 Post Manager</b>.</p>
        <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start">
        <div style="flex:1 1 460px;max-width:760px;background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:14px">
            <p><b>Type</b> &nbsp;
                <label><input type="radio" name="fp-k" value="post" checked> Post</label> &nbsp;
                <label><input type="radio" name="fp-k" value="reel"> Reel (video)</label> &nbsp;
                <label><input type="radio" name="fp-k" value="story"> Story (photo/video)</label></p>
            <p><select id="fp-b" style="max-width:100%"><option value="">📰 Fill from a blog post…</option>
                <?php foreach ($blog as $i => $b) : ?><option value="<?php echo (int) $i; ?>"><?php echo esc_html(wp_trim_words($b['t'], 12, '…')); ?></option><?php endforeach; ?></select></p>
            <p><textarea id="fp-t" rows="6" style="width:100%" placeholder="Text / caption"></textarea><span id="fp-n" class="description"></span></p>
            <p id="fp-lw">🔗 Link (shows a preview card, text posts only): <input type="url" id="fp-l" style="width:100%" placeholder="https://shaheen.edu.bd/…"></p>
            <p><input type="file" id="fp-f" accept="image/*,video/*" multiple> <span id="fp-fs" class="description">Several photos = one album post (up to 10). A video must be sent alone.</span></p>
            <div id="fp-th" style="display:flex;gap:6px;flex-wrap:wrap"></div>
            <p><b>Pages</b><br>
                <?php if (!$pages) : ?><span class="description">No pages connected yet.</span><?php endif; ?>
                <?php foreach ($pages as $id => $p) : ?>
                    <label style="display:block"><input type="checkbox" class="fp-pg" value="<?php echo esc_attr($id); ?>" <?php checked($id === (string) $c['page_id']); ?>> <?php echo esc_html($p['name']); ?> <span class="description">(<?php echo esc_html($id); ?>)</span></label>
                <?php endforeach; ?></p>
            <details style="margin-bottom:8px"><summary><b>👥 Groups</b> <span class="description">(Facebook does not let any tool post into groups; saved groups open for a manual share after you send)</span></summary>
                <textarea id="fp-gt" rows="5" style="width:100%" placeholder="One per line:  Group name | https://www.facebook.com/groups/…"></textarea>
                <button type="button" class="button" id="fp-gs">Save groups</button> <span id="fp-gm" class="description"></span></details>
            <div style="margin-bottom:8px;padding:8px;background:#f0f6ff;border-radius:6px"><b>🤝 Facebook collab</b> <span class="description">(Facebook has no API for collab; it must be added while the post is created in Business Suite)</span><br>
                Collaborator page / profile (name or link): <input type="text" id="fp-cf" style="width:100%;max-width:420px" placeholder="https://www.facebook.com/…">
                <button type="button" class="button" id="fp-cb">📋 Copy text &amp; open Business Suite</button> <span id="fp-cm" class="description"></span></div>
            <p><label><input type="checkbox" id="fp-ig" <?php disabled('' === $c['ig_id']); ?>> Also post to Instagram</label>
                &nbsp; Collab (up to 3 usernames): <input type="text" id="fp-co" placeholder="user1, user2" style="width:240px"></p>
            <p>When: <input type="datetime-local" id="fp-w"> <span class="description">(empty = now; scheduling applies to Facebook only)</span><br>
                <label><input type="checkbox" id="fp-d"> Test mode: save as private/unpublished, nothing goes public</label></p>
            <p><button type="button" class="button button-primary" id="fp-go">📤 Send</button> <button type="button" class="button" id="fp-clr">🧹 Clear</button> <span id="fp-m" class="description"></span></p>
            <div id="fp-log"></div>
        </div>
        <div style="flex:0 1 340px;min-width:260px">
            <h3 style="margin-top:0">Preview</h3>
            <div style="background:#fff;border:1px solid #d1d5db;border-radius:8px;overflow:hidden">
                <div style="padding:10px;font-weight:600">Shaheen Education Family <span class="description" style="font-weight:400">· now</span></div>
                <div id="fp-pt" style="padding:0 10px 10px;white-space:pre-wrap;word-break:break-word"></div>
                <div id="fp-pm"></div>
            </div>
            <p class="description">The draft is kept in this browser until you press Send or Clear.</p>
        </div>
        </div>
        <?php $h = (array) get_option('sb_fbpub_log', array()); if ($h) : ?>
            <h3>Recent</h3><ul>
            <?php foreach (array_slice($h, 0, 8) as $e) : ?>
                <li><?php echo esc_html(wp_date('d M H:i', (int) $e['t']) . ' · ' . $e['kind']); ?> — <?php foreach ($e['log'] as $l) { echo esc_html(($l['ok'] ? '✅ ' : '❌ ') . $l['to'] . '; '); } ?></li>
            <?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
    <script>
    (function(){var $=function(s){return document.querySelector(s);},aj=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nn=<?php echo wp_json_encode(wp_create_nonce('sb_admin')); ?>,blog=<?php echo wp_json_encode($blog); ?>,files=[];
        function ls(k,v){try{if(v===undefined){return localStorage.getItem(k)||'';}if(v===null){localStorage.removeItem(k);}else{localStorage.setItem(k,v);}}catch(e){}return '';}
        function kind(){return document.querySelector('input[name=fp-k]:checked').value;}
        function refresh(){var t=$('#fp-t').value;$('#fp-n').textContent=' '+t.length+' characters';$('#fp-pt').textContent=t||'Your text appears here…';
            $('#fp-lw').style.display=kind()==='post'?'':'none';var pm=$('#fp-pm');pm.textContent='';
            files.forEach(function(f,i){var b=document.createElement(f.mtype==='video'?'video':'img');b.src=f.url;if(f.mtype==='video'){b.controls=true;}b.style.cssText='width:100%;display:block;border-top:1px solid #eee';pm.appendChild(b);});
            var th=$('#fp-th');th.textContent='';files.forEach(function(f,i){var w=document.createElement('span');w.style.cssText='background:#eef2ff;border-radius:4px;padding:2px 6px';w.textContent=(f.mtype==='video'?'🎬 ':'🖼 ')+(i+1)+' ';var x=document.createElement('a');x.href='#';x.textContent='✕';x.onclick=function(e){e.preventDefault();files.splice(i,1);refresh();};w.appendChild(x);th.appendChild(w);});
            ls('sb_fp_draft',JSON.stringify({t:t,l:$('#fp-l').value}));}
        var dr=ls('sb_fp_draft');if(dr){try{var o=JSON.parse(dr);$('#fp-t').value=o.t||'';$('#fp-l').value=o.l||'';}catch(e){}}
        $('#fp-t').oninput=refresh;$('#fp-l').oninput=refresh;[].forEach.call(document.querySelectorAll('input[name=fp-k]'),function(r){r.onchange=refresh;});
        $('#fp-b').onchange=function(){var b=blog[this.value];if(!b){return;}$('#fp-t').value=b.t+'\n\n'+b.u;$('#fp-l').value=b.u;refresh();};
        $('#fp-clr').onclick=function(){$('#fp-t').value='';$('#fp-l').value='';files=[];$('#fp-f').value='';ls('sb_fp_draft',null);refresh();};
        function up(f){var fd=new FormData();fd.append('action','sb_fbpub_upload');fd.append('nonce',nn);fd.append('f',f);
            return fetch(aj,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();});}
        $('#fp-f').onchange=function(){var fl=[].slice.call(this.files);if(!fl.length){return;}var self=this,n=0;$('#fp-fs').textContent='Uploading…';
            (function next(){if(!fl.length){$('#fp-fs').textContent='✅ '+files.length+' file(s) ready';self.value='';refresh();return;}
                up(fl.shift()).then(function(j){if(j.success){if(j.data.mtype==='video'){files=[j.data];}else if(!files.length||files[0].mtype!=='video'){files.push(j.data);}files=files.slice(0,10);}else{$('#fp-fs').textContent='❌ '+j.data;}next();}).catch(function(){$('#fp-fs').textContent='❌ upload failed';next();});})();};
        $('#fp-go').onclick=function(){var pages=[].slice.call(document.querySelectorAll('.fp-pg:checked')).map(function(x){return x.value;});
            var first=files[0]||{url:'',mtype:'none'};var photos=files.filter(function(f){return f.mtype==='photo';}).map(function(f){return f.url;});
            var d={kind:kind(),text:$('#fp-t').value,link:$('#fp-l').value,media:first.url,mtype:first.mtype,photos:photos,pages:pages,ig:$('#fp-ig').checked,collab:$('#fp-co').value,when:$('#fp-w').value.replace('T',' '),draft:$('#fp-d').checked};
            $('#fp-m').textContent='Sending… (videos can take a minute)';$('#fp-go').disabled=true;
            fetch(aj,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'sb_fbpub_send',nonce:nn,d:JSON.stringify(d)})}).then(function(r){return r.json();}).then(function(j){$('#fp-go').disabled=false;
                if(!j.success){$('#fp-m').textContent='❌ '+j.data;return;}$('#fp-m').textContent='Done.';
                var ok=j.data.log.length>0&&j.data.log.every(function(l){return l.ok;});if(ok){ls('sb_fp_draft',null);}
                var firstOk=j.data.log.filter(function(l){return l.ok&&/^\d+_\d+$/.test(l.msg);})[0];
                $('#fp-log').innerHTML=j.data.log.map(function(l){var s=document.createElement('div');s.textContent=(l.ok?'✅ ':'❌ ')+l.to+' — '+l.msg;return s.outerHTML;}).join('')+(firstOk&&!d.draft?window.sbFpGroupPanel('https://www.facebook.com/'+firstOk.msg,d.text):'');});};
        $('#fp-cf').value=ls('sb_fp_collab');$('#fp-cf').oninput=function(){ls('sb_fp_collab',this.value);};
        $('#fp-cb').onclick=function(){var t=$('#fp-t').value;try{navigator.clipboard.writeText(t);}catch(e){}
            $('#fp-cm').textContent='✅ Text copied. In Business Suite: paste the text, add media, open Collaborator and search: '+($('#fp-cf').value||'(enter the page name)');
            window.open('https://business.facebook.com/latest/composer?asset_id='+<?php echo wp_json_encode((string) $c['page_id']); ?>,'_blank');};
        var groups=[];
        function gpost(set){var b={action:'sb_fbpub_groups',nonce:nn};if(set!==undefined){b.set=set;}return fetch(aj,{method:'POST',credentials:'same-origin',body:new URLSearchParams(b)}).then(function(r){return r.json();});}
        gpost().then(function(j){if(j.success){groups=j.data;$('#fp-gt').value=groups.map(function(g){return g.n+' | '+g.u;}).join('\n');}});
        $('#fp-gs').onclick=function(){gpost($('#fp-gt').value).then(function(j){if(j.success){groups=j.data;$('#fp-gm').textContent='✅ saved '+groups.length;}else{$('#fp-gm').textContent='❌ '+j.data;}});};
        function groupPanel(link,text){if(!groups.length||!link){return '';}var w=document.createElement('div');w.style.cssText='margin-top:10px;padding:8px;border:1px dashed #9ca3af;border-radius:6px';
            var h=document.createElement('div');h.textContent='👥 Share to groups (manual): copy, open the group, paste.';w.appendChild(h);
            var cp=document.createElement('button');cp.type='button';cp.className='button button-small';cp.textContent='Copy post link';cp.onclick=function(){try{navigator.clipboard.writeText(link);cp.textContent='✅ copied';}catch(e){cp.textContent=link;}};w.appendChild(cp);
            var ct=document.createElement('button');ct.type='button';ct.className='button button-small';ct.style.marginLeft='6px';ct.textContent='Copy text';ct.onclick=function(){try{navigator.clipboard.writeText(text);ct.textContent='✅ copied';}catch(e){}};w.appendChild(ct);
            groups.forEach(function(g){var d=document.createElement('div');var a=document.createElement('a');a.href=g.u;a.target='_blank';a.rel='noopener';a.textContent='Open '+g.n;d.appendChild(a);w.appendChild(d);});return w.outerHTML;}
        window.sbFpGroupPanel=groupPanel;
        refresh();
    })();
    </script>
    <?php
}
