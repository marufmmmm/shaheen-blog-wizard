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
            $j = sb_fbpub_call($c, $pid . '/feed', array_merge(array('message' => $o['text']), $sched), $tok);
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
        $u = wp_remote_post($s['upload_url'], array('timeout' => 120, 'headers' => array('Authorization' => 'OAuth ' . $tok, 'file_url' => $o['media'])));
        $uj = is_wp_error($u) ? null : json_decode((string) wp_remote_retrieve_body($u), true);
        if (is_wp_error($u) || empty($uj['success'])) {
            return new WP_Error('sb_fbp', 'Reel upload failed: ' . (is_wp_error($u) ? $u->get_error_message() : substr((string) wp_remote_retrieve_body($u), 0, 200)));
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
            $u = wp_remote_post($s['upload_url'], array('timeout' => 120, 'headers' => array('Authorization' => 'OAuth ' . $tok, 'file_url' => $o['media'])));
            $uj = is_wp_error($u) ? null : json_decode((string) wp_remote_retrieve_body($u), true);
            if (is_wp_error($u) || empty($uj['success'])) {
                return new WP_Error('sb_fbp', 'Story upload failed: ' . (is_wp_error($u) ? $u->get_error_message() : substr((string) wp_remote_retrieve_body($u), 0, 200)));
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

function sb_page_fbpost() {
    $pages = sb_fbpub_pages();
    $c     = sb_share_cfg();
    ?>
    <div class="wrap">
        <h1>📘 Facebook Post</h1>
        <p class="description">Write once, choose the type and the pages. Instagram collab invites are accepted by the other account in the Instagram app. Facebook has no collaborator API, so "more pages" means the same post on each page you manage.</p>
        <div style="max-width:760px;background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:14px">
            <p><b>Type</b> &nbsp;
                <label><input type="radio" name="fp-k" value="post" checked> Post</label> &nbsp;
                <label><input type="radio" name="fp-k" value="reel"> Reel (video)</label> &nbsp;
                <label><input type="radio" name="fp-k" value="story"> Story (photo/video)</label></p>
            <p><textarea id="fp-t" rows="5" style="width:100%" placeholder="Text / caption"></textarea></p>
            <p><input type="file" id="fp-f" accept="image/*,video/*"> <span id="fp-fs" class="description"></span></p>
            <p><b>Pages</b><br>
                <?php if (!$pages) : ?><span class="description">No pages connected yet.</span><?php endif; ?>
                <?php foreach ($pages as $id => $p) : ?>
                    <label style="display:block"><input type="checkbox" class="fp-pg" value="<?php echo esc_attr($id); ?>" <?php checked($id === (string) $c['page_id']); ?>> <?php echo esc_html($p['name']); ?> <span class="description">(<?php echo esc_html($id); ?>)</span></label>
                <?php endforeach; ?></p>
            <p><label><input type="checkbox" id="fp-ig" <?php disabled('' === $c['ig_id']); ?>> Also post to Instagram</label>
                &nbsp; Collab (up to 3 usernames): <input type="text" id="fp-co" placeholder="user1, user2" style="width:240px"></p>
            <p>When: <input type="datetime-local" id="fp-w"> <span class="description">(empty = now; scheduling applies to Facebook only)</span><br>
                <label><input type="checkbox" id="fp-d"> Test mode: save as private/unpublished, nothing goes public</label></p>
            <p><button type="button" class="button button-primary" id="fp-go">📤 Send</button> <span id="fp-m" class="description"></span></p>
            <div id="fp-log"></div>
        </div>
        <?php $h = (array) get_option('sb_fbpub_log', array()); if ($h) : ?>
            <h3>Recent</h3><ul>
            <?php foreach (array_slice($h, 0, 8) as $e) : ?>
                <li><?php echo esc_html(wp_date('d M H:i', (int) $e['t']) . ' · ' . $e['kind']); ?> — <?php foreach ($e['log'] as $l) { echo esc_html(($l['ok'] ? '✅ ' : '❌ ') . $l['to'] . '; '); } ?></li>
            <?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
    <script>
    (function(){var $=function(s){return document.querySelector(s);},aj=<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,nn=<?php echo wp_json_encode(wp_create_nonce('sb_admin')); ?>,media='',mtype='none';
        $('#fp-f').onchange=function(){var f=this.files[0];if(!f){return;}$('#fp-fs').textContent='Uploading…';var fd=new FormData();fd.append('action','sb_fbpub_upload');fd.append('nonce',nn);fd.append('f',f);
            fetch(aj,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(j){if(j.success){media=j.data.url;mtype=j.data.mtype;$('#fp-fs').textContent='✅ '+mtype+' ready';}else{media='';mtype='none';$('#fp-fs').textContent='❌ '+j.data;}});};
        $('#fp-go').onclick=function(){var pages=[].slice.call(document.querySelectorAll('.fp-pg:checked')).map(function(x){return x.value;});
            var d={kind:document.querySelector('input[name=fp-k]:checked').value,text:$('#fp-t').value,media:media,mtype:mtype,pages:pages,ig:$('#fp-ig').checked,collab:$('#fp-co').value,when:$('#fp-w').value.replace('T',' '),draft:$('#fp-d').checked};
            $('#fp-m').textContent='Sending… (videos can take a minute)';$('#fp-go').disabled=true;
            fetch(aj,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'sb_fbpub_send',nonce:nn,d:JSON.stringify(d)})}).then(function(r){return r.json();}).then(function(j){$('#fp-go').disabled=false;
                if(!j.success){$('#fp-m').textContent='❌ '+j.data;return;}$('#fp-m').textContent='Done.';
                $('#fp-log').innerHTML=j.data.log.map(function(l){var s=document.createElement('div');s.textContent=(l.ok?'✅ ':'❌ ')+l.to+' — '+l.msg;return s.outerHTML;}).join('');});};
    })();
    </script>
    <?php
}
