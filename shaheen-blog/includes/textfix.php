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
                        $bf = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(mb_substr($node, $st, 70))));
                        $samples[] = $bf . ' → ' . trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(mb_substr($o, $st, 70 + mb_strlen($o) - mb_strlen($node)))));
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
                $st0 = max(0, (int) mb_strlen(substr((string) $p->$f, 0, $mm[0][1])) - 25);
                $samples[] = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(mb_substr((string) $p->$f, $st0, 70)))) . ' → ' . trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(mb_substr($o, $st0, 70 + mb_strlen($o) - mb_strlen((string) $p->$f)))));
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
                $samples[]      = 'SEO: ' . mb_substr($v, 0, 70) . ' → ' . mb_substr($o, 0, 70);
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
    array_unshift($log, array('rid' => sanitize_key((string) ($_POST['rid'] ?? '')), 't' => time(), 'find' => (string) wp_unslash($_POST['find'] ?? ''), 'rep' => $rep, 'items' => $bk)); // phpcs:ignore WordPress.Security.NonceVerification
    update_option('sb_tf_backup', array_slice($log, 0, 40), false);
    sb_tf_flush();
    wp_send_json_success(array('items' => $done, 'changes' => $total));
});

add_action('wp_ajax_sb_tf_undo', function () {
    sb_ajax_guard('manage_options');
    $log = get_option('sb_tf_backup', array());
    $rid = sanitize_key((string) ($_POST['rid'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
    $pos = null;
    foreach ((array) $log as $i => $r) {
        if (!empty($r['items']) && ('' === $rid || ($r['rid'] ?? '') === $rid)) {
            $pos = $i;
            break;
        }
    }
    if (null === $pos) {
        wp_send_json_error(array('message' => 'ফেরানোর কিছু নেই।'));
    }
    $run = $log[$pos];
    unset($log[$pos]);
    $log = array_values($log);
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

add_action('wp_ajax_sb_tf_rows', function () {
    sb_ajax_guard('manage_options');
    $rows = json_decode(wp_unslash((string) ($_POST['rows'] ?? '[]')), true); // phpcs:ignore WordPress.Security.NonceVerification
    $out  = array();
    foreach (array_slice((array) $rows, 0, 60) as $r) {
        $out[] = array('id' => sanitize_key((string) ($r['id'] ?? uniqid('r'))), 'f' => (string) ($r['f'] ?? ''), 'r' => (string) ($r['r'] ?? ''), 're' => !empty($r['re']) ? 1 : 0, 'done' => !empty($r['done']) ? 1 : 0);
    }
    update_option('sb_tf_rows', $out, false);
    wp_send_json_success(array('n' => count($out)));
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
    $rows = get_option('sb_tf_rows', array());
    $rows = is_array($rows) ? $rows : array();
    ?>
<div class="wrap sba" id="sb-tf">
    <h1>✏️ লেখা বদল (পুরো সাইটে)</h1>
    <p class="sba-muted">প্রতিটি বদল আলাদা লাইনে লিখুন: কী খুঁজবেন → কী বসাবেন। প্রতিটির নিজস্ব <b>দেখুন</b> (কোথায় কোথায় আছে ও আগে → পরে কেমন হবে), <b>বদলান</b> ও <b>ফেরান</b> বোতাম। একটি বদল অন্যটিকে ছোঁয় না। পেজ, পোস্ট, টেমপ্লেট, হেডার-ফুটার, SEO ও হোম সেটিং সব জায়গায় খোঁজে।</p>
    <div id="tf-list"></div>
    <p><button type="button" class="button" id="tf-add">➕ আরেকটি বদল</button> <span id="tf-msg" class="sba-msg"></span></p>
</div>
<style>
#sb-tf .tfr{background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:10px 12px;margin:10px 0;max-width:980px}
#sb-tf .tfr input[type=text]{width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:6px}
#sb-tf .tfg{display:grid;grid-template-columns:1fr 1fr;gap:10px}
#sb-tf .tfo{margin-top:8px;font-size:13px}#sb-tf .tfo p{margin:6px 0;padding:6px;background:#f8fafc;border-radius:6px}
#sb-tf .tfd{opacity:.6}
@media(max-width:700px){#sb-tf .tfg{grid-template-columns:1fr}}
</style>
<script>
(function () {
    var A = <?php echo wp_json_encode(array('ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('sb_admin'), 'rows' => $rows)); ?>;
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var list = $('#tf-list');
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function post(action, o) { var fd = new FormData(); fd.append('action', action); fd.append('nonce', A.nonce); Object.keys(o || {}).forEach(function (k) { fd.append(k, o[k]); }); return fetch(A.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
    function rid() { return 'r' + Math.random().toString(36).slice(2, 9); }
    function row(d) {
        d = d || { id: rid(), f: '', r: '', re: 0, done: 0 };
        var el = document.createElement('div'); el.className = 'tfr' + (d.done ? ' tfd' : ''); el.dataset.id = d.id;
        el.innerHTML = '<div class="tfg"><label>খুঁজবেন<input type="text" class="f" value="' + esc(d.f) + '" placeholder="যেমন: ১৫০+ ক্যাম্পাস"></label><label>বসাবেন<input type="text" class="r" value="' + esc(d.r) + '" placeholder="যেমন: ১৭৭টি ক্যাম্পাস"></label></div>' +
            '<p style="margin:8px 0 0"><label><input type="checkbox" class="re"' + (d.re ? ' checked' : '') + '> উন্নত (regex)</label> &nbsp; ' +
            '<button type="button" class="button b-scan">🔎 দেখুন</button> <button type="button" class="button button-primary b-apply" disabled>✅ বদলান</button> <button type="button" class="button b-undo">↩ ফেরান</button> <button type="button" class="button b-del">🗑 লাইন মুছুন</button> <span class="sba-msg m"></span></p><div class="tfo"></div>';
        list.appendChild(el); return el;
    }
    function data(el) { return { f: $('.f', el).value, r: $('.r', el).value, mode: $('.re', el).checked ? 'regex' : 'plain', rid: el.dataset.id }; }
    function save() { var rows = [].map.call(list.children, function (el) { return { id: el.dataset.id, f: $('.f', el).value, r: $('.r', el).value, re: $('.re', el).checked ? 1 : 0, done: el.classList.contains('tfd') ? 1 : 0 }; }); return post('sb_tf_rows', { rows: JSON.stringify(rows) }); }
    (A.rows.length ? A.rows : [null]).forEach(function (d) { row(d); });
    $('#tf-add').onclick = function () { var e = row(); $('.f', e).focus(); save(); };
    list.addEventListener('change', save);
    list.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) { return; }
        var el = b.closest('.tfr'), m = $('.m', el), out = $('.tfo', el), d = data(el);
        if (b.classList.contains('b-del')) { el.remove(); save(); return; }
        if (b.classList.contains('b-scan')) {
            if (!d.f) { m.textContent = 'খোঁজার লেখা দিন।'; return; }
            m.textContent = 'খোঁজা হচ্ছে…'; $('.b-apply', el).disabled = true; save();
            post('sb_tf_scan', { find: d.f, rep: d.r, mode: d.mode }).then(function (j) {
                if (!j.success) { m.textContent = (j.data && j.data.message) || 'ত্রুটি'; return; }
                var x = j.data; m.textContent = x.rows.length + 'টি জায়গায় (মোট ' + x.total + 'টি) পাওয়া গেছে।';
                out.innerHTML = x.rows.map(function (r) { return '<p><label><input type="checkbox" class="tf-id" value="' + esc(r.id) + '" checked> <b>' + esc(r.title || '#' + r.id) + '</b> <span class="sba-muted">(' + esc(r.type) + ', ' + r.n + ' জায়গা)</span></label><br><small>' + r.s.map(esc).join('<br>') + '</small></p>'; }).join('');
                $('.b-apply', el).disabled = !x.rows.length;
            });
        } else if (b.classList.contains('b-apply')) {
            var ids = [].map.call(el.querySelectorAll('.tf-id:checked'), function (x) { return x.value; }).join(',');
            if (!ids) { return; }
            m.textContent = 'বদলানো হচ্ছে…'; b.disabled = true;
            post('sb_tf_apply', { find: d.f, rep: d.r, mode: d.mode, ids: ids, rid: d.rid }).then(function (j) { m.textContent = j.success ? '✅ শেষ: ' + j.data.items + 'টি জায়গা, ' + j.data.changes + 'টি বদল।' : 'ত্রুটি'; if (j.success) { el.classList.add('tfd'); out.innerHTML = ''; save(); } });
        } else if (b.classList.contains('b-undo')) {
            post('sb_tf_undo', { rid: d.rid }).then(function (j) { m.textContent = j.success ? '↩ ফেরানো হয়েছে (' + j.data.items + 'টি)।' : ((j.data && j.data.message) || 'ত্রুটি'); if (j.success) { el.classList.remove('tfd'); save(); } });
        }
    });
})();
</script>
    <?php
}
