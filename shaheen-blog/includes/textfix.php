<?php
/**
 * Site-wide text fix: find a word/phrase (or a regex) in every page, post, Elementor template and header/footer,
 * show where, then replace it everywhere. A backup of every touched item is kept so it can be undone.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_submenu_page('sb-dashboard', 'Text Fix', '✏️ লেখা বদল', 'manage_options', 'sb-textfix', 'sb_page_textfix');
}, 20);

/** ids of everything that can hold text */
function sb_tf_ids() {
    $types = array_unique(array_merge(array_values(get_post_types(array('public' => true))), array('elementor_library', 'elementor-hf', 'sb_post')));
    $types = array_diff($types, array('attachment'));
    return get_posts(array('post_type' => $types, 'post_status' => array('publish', 'draft', 'private', 'pending', 'future'), 'numberposts' => -1, 'fields' => 'ids', 'no_found_rows' => true));
}

/** regex for a pair: plain text (quoted) or a PCRE pattern body without delimiters */
function sb_tf_pattern($find, $mode) {
    $find = (string) $find;
    if ('' === $find) {
        return '';
    }
    $p = '~' . ('regex' === $mode ? str_replace('~', '\~', $find) : preg_quote($find, '~')) . '~u';
    return false === @preg_match($p, '') ? '' : $p;
}

/** replace inside one string; returns [newString, count] */
function sb_tf_str($s, $pat, $rep) {
    $n = 0;
    $o = preg_replace($pat, $rep, $s, -1, $n);
    return null === $o ? array($s, 0) : array($o, $n);
}

/** walk a decoded Elementor tree; modifies strings in place, returns the number of changes */
function sb_tf_walk(&$node, $pat, $rep, &$samples) {
    $c = 0;
    if (is_string($node)) {
        if ('' !== $node && !preg_match('~^(https?:)?//|^#[0-9a-f]{3,8}$~i', $node)) {
            list($o, $n) = sb_tf_str($node, $pat, $rep);
            if ($n) {
                if (count($samples) < 3) {
                    if (preg_match($pat, $node, $m, PREG_OFFSET_CAPTURE)) {
                        $st = max(0, (int) mb_strlen(substr($node, 0, $m[0][1])) - 25);
                        $samples[] = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(mb_substr($node, $st, 70))));
                    }
                }
                $node = $o;
                $c += $n;
            }
        }
        return $c;
    }
    if (is_array($node)) {
        foreach ($node as $k => &$v) {
            $c += sb_tf_walk($v, $pat, $rep, $samples);
        }
    }
    return $c;
}

/** one item: returns array(count, samples, changes[]) where changes = what would be written */
function sb_tf_item($id, $pat, $rep) {
    $p       = get_post($id);
    $count   = 0;
    $samples = array();
    $ch      = array();
    foreach (array('post_title', 'post_content', 'post_excerpt') as $f) {
        list($o, $n) = sb_tf_str((string) $p->$f, $pat, $rep);
        if ($n) {
            $count += $n;
            $ch['post'][$f] = array((string) $p->$f, $o);
            if (preg_match($pat, (string) $p->$f, $mm, PREG_OFFSET_CAPTURE)) {
                $samples[] = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(mb_substr((string) $p->$f, max(0, (int) mb_strlen(substr((string) $p->$f, 0, $mm[0][1])) - 25), 70))));
            }
        }
    }
    $raw = get_post_meta($id, '_elementor_data', true);
    if (is_string($raw) && '' !== $raw && '[]' !== $raw) {
        $d = json_decode($raw, true);
        if (is_array($d)) {
            $n = sb_tf_walk($d, $pat, $rep, $samples);
            if ($n) {
                $count += $n;
                $ch['el'] = array($raw, wp_json_encode($d));
            }
        }
    }
    $jl = get_post_meta($id, '_csa_jsonld', true);
    if (is_array($jl) && $jl) {
        $newjl = $jl;
        $nj    = 0;
        foreach ($jl as $i => $js) {
            $dj = is_string($js) ? json_decode($js, true) : null;
            if (is_array($dj)) {
                $n = sb_tf_walk($dj, $pat, $rep, $samples);
                if ($n) {
                    $nj += $n;
                    $newjl[$i] = wp_json_encode($dj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }
        if ($nj) {
            $count += $nj;
            $ch['jl'] = array($jl, $newjl);
        }
    }
    foreach (array('_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_opengraph-title', '_yoast_wpseo_opengraph-description', '_yoast_wpseo_twitter-title', '_yoast_wpseo_twitter-description') as $k) {
        $v = get_post_meta($id, $k, true);
        if (is_string($v) && '' !== $v) {
            list($o, $n) = sb_tf_str($v, $pat, $rep);
            if ($n) {
                $count += $n;
                $ch['meta'][$k] = array($v, $o);
                $samples[]      = 'SEO: ' . mb_substr($v, 0, 80);
            }
        }
    }
    return array($count, array_slice($samples, 0, 3), $ch);
}


/** plugin options that hold visible site text (home sections, schema …) */
function sb_tf_opts() {
    global $wpdb;
    $names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'csa\\_%'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return array_values(array_filter((array) $names, function ($n) {
        return !preg_match('~key|token|secret|log|backup|chat|mem|cache|_og$|hist|queue|seen|job|usage|ledger|cost|nonce|transient|undo|activity|plan~i', $n);
    }));
}

/** one option: returns array(count, samples, newValue|null) */
function sb_tf_opt($name, $pat, $rep) {
    $v = get_option($name, null);
    if (!is_array($v) && !is_string($v)) {
        return array(0, array(), null);
    }
    $n       = 0;
    $samples = array();
    if (is_string($v)) {
        list($o, $n) = sb_tf_str($v, $pat, $rep);
        $v = $o;
        if ($n) {
            $samples[] = mb_substr($o, 0, 60);
        }
    } else {
        $n = sb_tf_walk($v, $pat, $rep, $samples);
    }
    return array($n, array_slice($samples, 0, 3), $n ? $v : null);
}

add_action('wp_ajax_sb_tf_scan', function () {
    sb_ajax_guard('manage_options');
    $pat = sb_tf_pattern((string) wp_unslash($_POST['find'] ?? ''), sanitize_key((string) ($_POST['mode'] ?? 'plain'))); // phpcs:ignore WordPress.Security.NonceVerification
    $rep = (string) wp_unslash($_POST['rep'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification
    if ('' === $pat) {
        wp_send_json_error(array('message' => 'খোঁজার লেখা ঠিক নয়।'));
    }
    $rows  = array();
    $total = 0;
    foreach (sb_tf_ids() as $id) {
        list($c, $s) = sb_tf_item($id, $pat, $rep);
        if ($c) {
            $total += $c;
            $rows[] = array('id' => $id, 'title' => get_the_title($id), 'type' => get_post_type($id), 'n' => $c, 's' => $s, 'url' => get_permalink($id));
        }
    }
    foreach (sb_tf_opts() as $on) {
        list($c, $sm) = sb_tf_opt($on, $pat, $rep);
        if ($c) {
            $total += $c;
            $rows[] = array('id' => 'opt:' . $on, 'title' => 'সেটিং: ' . $on, 'type' => 'option', 'n' => $c, 's' => $sm, 'url' => '');
        }
    }
    wp_send_json_success(array('rows' => $rows, 'total' => $total));
});

add_action('wp_ajax_sb_tf_apply', function () {
    sb_ajax_guard('manage_options');
    $pat = sb_tf_pattern((string) wp_unslash($_POST['find'] ?? ''), sanitize_key((string) ($_POST['mode'] ?? 'plain'))); // phpcs:ignore WordPress.Security.NonceVerification
    $rep = (string) wp_unslash($_POST['rep'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification
    $only = array_filter(array_map('sanitize_text_field', explode(',', (string) ($_POST['ids'] ?? '')))); // phpcs:ignore WordPress.Security.NonceVerification
    if ('' === $pat) {
        wp_send_json_error(array('message' => 'খোঁজার লেখা ঠিক নয়।'));
    }
    $bk    = array();
    $done  = 0;
    $total = 0;
    foreach (sb_tf_ids() as $id) {
        if ($only && !in_array((string) $id, $only, true)) {
            continue;
        }
        list($c, , $ch) = sb_tf_item($id, $pat, $rep);
        if (!$c) {
            continue;
        }
        $b = array();
        if (!empty($ch['post'])) {
            $upd = array('ID' => $id);
            foreach ($ch['post'] as $f => $pair) {
                $upd[$f] = $pair[1];
                $b['post'][$f] = $pair[0];
            }
            wp_update_post(wp_slash($upd));
        }
        if (!empty($ch['el'])) {
            $b['el'] = $ch['el'][0];
            update_post_meta($id, '_elementor_data', wp_slash($ch['el'][1]));
        }
        if (!empty($ch['jl'])) {
            $b['jl'] = $ch['jl'][0];
            update_post_meta($id, '_csa_jsonld', $ch['jl'][1]);
        }
        foreach ((array) ($ch['meta'] ?? array()) as $k => $pair) {
            $b['meta'][$k] = $pair[0];
            update_post_meta($id, $k, $pair[1]);
        }
        $bk[$id] = $b;
        $done++;
        $total += $c;
        clean_post_cache($id);
    }
    foreach (sb_tf_opts() as $on) {
        if ($only && !in_array('opt:' . $on, $only, true)) {
            continue;
        }
        $old = get_option($on, null);
        list($c, , $nv) = sb_tf_opt($on, $pat, $rep);
        if ($c && null !== $nv) {
            $bk['opt:' . $on] = array('opt' => $old);
            update_option($on, $nv);
            $done++;
            $total += $c;
        }
    }
    $log = get_option('sb_tf_backup', array());
    $log = is_array($log) ? $log : array();
    array_unshift($log, array('t' => time(), 'find' => (string) wp_unslash($_POST['find'] ?? ''), 'rep' => $rep, 'items' => $bk)); // phpcs:ignore WordPress.Security.NonceVerification
    update_option('sb_tf_backup', array_slice($log, 0, 5), false);
    sb_tf_flush();
    wp_send_json_success(array('items' => $done, 'changes' => $total));
});

add_action('wp_ajax_sb_tf_undo', function () {
    sb_ajax_guard('manage_options');
    $log = get_option('sb_tf_backup', array());
    if (empty($log[0]['items'])) {
        wp_send_json_error(array('message' => 'ফেরানোর কিছু নেই।'));
    }
    $run = array_shift($log);
    foreach ($run['items'] as $id => $b) {
        if (isset($b['opt'])) {
            update_option(substr((string) $id, 4), $b['opt']);
            continue;
        }
        if (!empty($b['post'])) {
            wp_update_post(wp_slash(array_merge(array('ID' => $id), $b['post'])));
        }
        if (isset($b['el'])) {
            update_post_meta($id, '_elementor_data', wp_slash($b['el']));
        }
        if (isset($b['jl'])) {
            update_post_meta($id, '_csa_jsonld', $b['jl']);
        }
        foreach ((array) ($b['meta'] ?? array()) as $k => $v) {
            update_post_meta($id, $k, $v);
        }
        clean_post_cache($id);
    }
    update_option('sb_tf_backup', $log, false);
    sb_tf_flush();
    wp_send_json_success(array('items' => count($run['items'])));
});

function sb_tf_flush() {
    if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }
    do_action('litespeed_purge_all');
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
}

function sb_page_textfix() {
    $last = get_option('sb_tf_backup', array());
    ?>
<div class="wrap sba" id="sb-tf">
    <h1>✏️ লেখা বদল (পুরো সাইটে)</h1>
    <p class="sba-muted">একটি শব্দ বা বাক্য পুরো সাইটের সব পেজ, পোস্ট, টেমপ্লেট, হেডার-ফুটার ও SEO টাইটেল/বিবরণে খুঁজে একসাথে বদলায়। আগে "দেখুন" দিয়ে কোথায় কোথায় আছে দেখুন, তারপর "বদলান"। প্রতিবার ব্যাকআপ থাকে, "শেষ বদল ফেরান" দিয়ে ফেরানো যায়।</p>
    <div class="sba-card sba-form" style="max-width:860px">
        <label>যা খুঁজবেন <input type="text" id="tf-find" placeholder="যেমন: ৩৫ বছরের"></label>
        <label>যা বসাবেন <input type="text" id="tf-rep" placeholder="যেমন: ৩৬ বছরের"></label>
        <label><input type="checkbox" id="tf-re"> উন্নত (regex) — শুধু জানলে</label>
        <p><button type="button" class="button" id="tf-scan">🔎 দেখুন</button>
        <button type="button" class="button button-primary" id="tf-apply" disabled>✅ সব বদলান</button>
        <button type="button" class="button" id="tf-undo"<?php echo empty($last[0]['items']) ? ' disabled' : ''; ?>>↩ শেষ বদল ফেরান</button>
        <span id="tf-msg" class="sba-msg"></span></p>
        <div id="tf-out"></div>
    </div>
</div>
<script>
(function () {
    var A = <?php echo wp_json_encode(array('ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('sb_admin'))); ?>;
    var $ = function (s) { return document.querySelector(s); };
    function call(action, extra) {
        var fd = new FormData(); fd.append('action', action); fd.append('nonce', A.nonce);
        fd.append('find', $('#tf-find').value); fd.append('rep', $('#tf-rep').value); fd.append('mode', $('#tf-re').checked ? 'regex' : 'plain');
        Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
        return fetch(A.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    $('#tf-scan').onclick = function () {
        $('#tf-msg').textContent = 'খোঁজা হচ্ছে…'; $('#tf-apply').disabled = true;
        call('sb_tf_scan').then(function (j) {
            if (!j.success) { $('#tf-msg').textContent = (j.data && j.data.message) || 'ত্রুটি'; return; }
            var d = j.data; $('#tf-msg').textContent = d.rows.length + 'টি পেজ/পোস্টে মোট ' + d.total + ' জায়গায় পাওয়া গেছে।';
            $('#tf-out').innerHTML = d.rows.map(function (r) { return '<p><label><input type="checkbox" class="tf-id" value="' + r.id + '" checked> <b>' + esc(r.title || '#' + r.id) + '</b> <span class="sba-muted">(' + esc(r.type) + ' #' + r.id + ', ' + r.n + ' জায়গা)</span></label><br><small>' + r.s.map(esc).join(' … ') + '</small></p>'; }).join('');
            $('#tf-apply').disabled = !d.rows.length;
        });
    };
    $('#tf-apply').onclick = function () {
        var ids = [].slice.call(document.querySelectorAll('.tf-id:checked')).map(function (x) { return x.value; }).join(',');
        if (!ids) { return; }
        $('#tf-msg').textContent = 'বদলানো হচ্ছে…'; $('#tf-apply').disabled = true;
        call('sb_tf_apply', { ids: ids }).then(function (j) { $('#tf-msg').textContent = j.success ? 'শেষ: ' + j.data.items + 'টি পেজ/পোস্ট, ' + j.data.changes + ' জায়গা বদলেছে।' : 'ত্রুটি'; $('#tf-undo').disabled = false; });
    };
    $('#tf-undo').onclick = function () { call('sb_tf_undo').then(function (j) { $('#tf-msg').textContent = j.success ? 'ফেরানো হয়েছে (' + j.data.items + 'টি)।' : ((j.data && j.data.message) || 'ত্রুটি'); }); };
})();
</script>
    <?php
}
