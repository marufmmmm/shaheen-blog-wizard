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
    if (is_array($pat)) {
        return sb_tf_fact_str($s, $pat);
    }
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
                if (count($samples) < 3 && !is_array($pat)) {
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
        unset($v);
        if (is_array($pat)) {
            $c += sb_tf_fact_pairs($node, $pat, $samples);
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
            if (!is_array($pat) && preg_match($pat, (string) $p->$f, $mm, PREG_OFFSET_CAPTURE)) {
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



/* ------------------------------------------------------------------ facts: numbers with units (years, campuses, students) */

function sb_tf_units() {
    return array('years' => 'বছর|years?|yrs', 'campus' => 'ক্যাম্পাস|শাখা|campuses|campus|branches|branch', 'students' => 'শিক্ষার্থী|ছাত্র-ছাত্রী|ছাত্র|students?');
}
function sb_tf_min() {
    return array('years' => 20, 'campus' => 20, 'students' => 100);
}
function sb_tf_cat_of($word) {
    foreach (sb_tf_units() as $cat => $re) {
        if (preg_match('~^(?:' . $re . ')~iu', $word)) {
            return $cat;
        }
    }
    return '';
}
function sb_tf_max() {
    return array('years' => 99, 'campus' => 999, 'students' => 200000);
}
function sb_tf_en($s) {
    return (int) strtr((string) $s, array('০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'));
}
/** write digits in the script of $like (Bengali or English) */
function sb_tf_script($num, $like) {
    $num = (string) $num;
    return preg_match('/[০-৯]/u', (string) $like) ? strtr($num, array('0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯')) : strtr($num, array('০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'));
}
/** typed new value "১৭৭+" -> [digits, suffix] */
function sb_tf_split_new($new) {
    return preg_match('/^\s*([0-9০-৯]+)\s*(\+|টি)?\s*$/u', (string) $new, $m) ? array(sb_tf_en($m[1]), $m[2] ?? '') : array(null, '');
}
/** note one occurrence in the collector ($d['col']) */
function sb_tf_fact_note($d, $cat, $val, $text) {
    $min = sb_tf_min();
    $max = sb_tf_max();
    if (!isset($d['col']) || $val < $min[$cat] || $val > $max[$cat]) {
        return;
    }
    $col = &$d['col'];
    $col[$cat][$val]['n'] = ($col[$cat][$val]['n'] ?? 0) + 1;
    $col[$cat][$val]['pages'][$d['ctx']] = 1;
    if (empty($col[$cat][$val]['s']) || count($col[$cat][$val]['s']) < 2) {
        $col[$cat][$val]['s'][] = mb_substr($text, 0, 80);
    }
}

/** inline "১৫০+ ক্যাম্পাস" inside one string (tags between number and unit allowed) */
function sb_tf_fact_str($s, $d) {
    $all = implode('|', sb_tf_units());
    $cnt = 0;
    $o   = preg_replace_callback('~(?<![0-9০-৯])([0-9০-৯]{1,5})(\+|টি)?((?:\s|<[^>]*>)*)(' . $all . ')~iu', function ($m) use ($d, &$cnt) {
        $cat = sb_tf_cat_of($m[4]);
        $val = sb_tf_en($m[1]);
        if ('' === $cat) {
            return $m[0];
        }
        if (array_key_exists('new', $d) && null !== $d['new']) {
            list($nv, $sf) = sb_tf_split_new($d['new']);
            if ($cat === $d['cat'] && $val === (int) $d['old'] && null !== $nv) {
                $cnt++;
                return sb_tf_script($nv, $m[1]) . $sf . $m[3] . $m[4];
            }
            return $m[0];
        }
        sb_tf_fact_note($d, $cat, $val, trim(wp_strip_all_tags($m[0])));
        return $m[0];
    }, $s);
    return array(null === $o ? $s : $o, $cnt);
}

/** a bare number ("৩৫") next to a text that starts with a unit ("বছরের অভিজ্ঞতা") inside one settings array */
function sb_tf_fact_pairs(&$node, $d, &$samples) {
    $all = implode('|', sb_tf_units());
    $c   = 0;
    foreach ($node as $k => $v) {
        if (!is_string($v) || !preg_match('/^\s*([0-9০-৯]{1,5})\s*(\+|টি)?\s*$/u', $v, $nm)) {
            continue;
        }
        $cat = '';
        $txt = '';
        foreach ($node as $k2 => $v2) {
            if ($k2 !== $k && is_string($v2)) {
                $plain = trim(wp_strip_all_tags($v2));
                if (preg_match('~^(?:(?:মোট|আমাদের|সর্বমোট|total|our|all)\s+){0,2}(' . $all . ')~iu', $plain, $um)) {
                    $cat = sb_tf_cat_of($um[1]);
                    $txt = $plain;
                    break;
                }
            }
        }
        if ('' === $cat) {
            continue;
        }
        $val = sb_tf_en($nm[1]);
        if (array_key_exists('new', $d) && null !== $d['new']) {
            list($nv, $sf) = sb_tf_split_new($d['new']);
            if ($cat === $d['cat'] && $val === (int) $d['old'] && null !== $nv) {
                $node[$k] = sb_tf_script($nv, $nm[1]) . $sf;
                $c++;
                if (count($samples) < 3) {
                    $samples[] = trim($v) . ' ' . mb_substr($txt, 0, 30) . ' → ' . $node[$k] . ' ' . mb_substr($txt, 0, 30);
                }
            }
        } else {
            sb_tf_fact_note($d, $cat, $val, trim($v) . ' ' . mb_substr($txt, 0, 40));
        }
    }
    return $c;
}

/** every number+unit fact on the site, grouped: cat => value => {n, pages, samples} */
function sb_tf_gather() {
    $col = array();
    $d   = array('col' => &$col, 'ctx' => '');
    foreach (sb_tf_ids() as $id) {
        $d['ctx'] = get_the_title($id) . ' (#' . $id . ')';
        sb_tf_item($id, $d, '');
    }
    foreach (sb_tf_opts() as $on) {
        $d['ctx'] = 'সেটিং: ' . $on;
        sb_tf_opt($on, $d, '');
    }
    $out = array();
    foreach ($col as $cat => $vals) {
        $rows = array();
        foreach ($vals as $v => $x) {
            $rows[] = array('v' => (int) $v, 'n' => $x['n'], 'pages' => count($x['pages']), 'p' => array_slice(array_keys($x['pages']), 0, 4), 's' => (array) ($x['s'] ?? array()));
        }
        usort($rows, function ($a, $b) {
            return $b['pages'] - $a['pages'];
        });
        $out[$cat] = $rows;
    }
    return $out;
}

add_action('wp_ajax_sb_tf_facts', function () {
    sb_ajax_guard('manage_options');
    $f = sb_tf_gather();
    $chars = strlen(wp_json_encode($f, JSON_UNESCAPED_UNICODE));
    $tok   = (int) ($chars / 2) + 700;
    $est   = round(($tok * 0.000002 + 600 * 0.00001) * SB_BILL_MULT, 3);   // rough upper estimate, shown to the client before the AI step
    $hint  = array();
    $hint['site'] = get_bloginfo('name');
    if (function_exists('csa_br_list')) {
        $hint['branch_pages_in_branch_plugin'] = count(csa_br_list());
    }
    $fp = (int) get_option('page_on_front');
    if ($fp) {
        $hint['home_meta_description'] = (string) get_post_meta($fp, '_yoast_wpseo_metadesc', true);
    }
    wp_send_json_success(array('facts' => $f, 'est' => $est, 'hint' => $hint));
});

add_action('wp_ajax_sb_tf_fact_scan', function () {
    sb_ajax_guard('manage_options');
    $d    = array('cat' => sanitize_key((string) ($_POST['cat'] ?? '')), 'old' => (int) ($_POST['old'] ?? 0), 'new' => (string) wp_unslash($_POST['new'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
    if (null === sb_tf_split_new($d['new'])[0]) {
        wp_send_json_error(array('message' => 'নতুন মান শুধু সংখ্যা হবে (চাইলে শেষে + বা টি)।'));
    }
    $rows  = array();
    $total = 0;
    foreach (sb_tf_ids() as $id) {
        list($c, $s) = sb_tf_item($id, $d, '');
        if ($c) {
            $total += $c;
            $rows[] = array('id' => $id, 'title' => get_the_title($id), 'type' => get_post_type($id), 'n' => $c, 's' => $s);
        }
    }
    foreach (sb_tf_opts() as $on) {
        list($c, $s) = sb_tf_opt($on, $d, '');
        if ($c) {
            $total += $c;
            $rows[] = array('id' => 'opt:' . $on, 'title' => 'সেটিং: ' . $on, 'type' => 'option', 'n' => $c, 's' => $s);
        }
    }
    wp_send_json_success(array('rows' => $rows, 'total' => $total));
});

add_action('wp_ajax_sb_tf_fact_apply', function () {
    sb_ajax_guard('manage_options');
    $_POST['mode'] = 'fact';
    sb_tf_apply_core(array('cat' => sanitize_key((string) ($_POST['cat'] ?? '')), 'old' => (int) ($_POST['old'] ?? 0), 'new' => (string) wp_unslash($_POST['new'] ?? '')), ''); // phpcs:ignore WordPress.Security.NonceVerification
});

add_action('wp_ajax_sb_tf_ai', function () {
    sb_ajax_guard('manage_options');
    $key = function_exists('sb_get') ? sb_get('api_key') : '';
    if ('' === (string) $key) {
        wp_send_json_error(array('message' => 'AI চাবি নেই (Settings-এ দিন)।'));
    }
    $facts = json_decode(wp_unslash((string) ($_POST['facts'] ?? '')), true); // phpcs:ignore WordPress.Security.NonceVerification
    $hint  = json_decode(wp_unslash((string) ($_POST['hint'] ?? '')), true);  // phpcs:ignore WordPress.Security.NonceVerification
    if (!is_array($facts) || !$facts) {
        wp_send_json_error(array('message' => 'কোনো তথ্য নেই।'));
    }
    $sys = 'You check the facts written on a school/coaching website (Bangladesh). For each category you get the numbers found on the site, how many pages show each number, and sample phrases. '
        . 'Reply with JSON only: {"items":[{"cat":"years|campus|students","suggest":"the number the site should use everywhere (digits, same script as the majority; add + only if the majority uses an approximate figure)","reason":"one short Bangla sentence","confidence":"high|medium|low","needs_input":true|false}]}. '
        . 'Rules: pick a value only if the evidence supports it: a value shown on the home page or confirmed by the hints (branch count from the branch plugin, home page description) beats older repeated values. Do not invent numbers. '
        . 'If one value is already used everywhere, suggest the same value with needs_input=false. If the evidence is not enough, set needs_input=true and suggest the most likely one. Different units of the same word (course length "৩ বছর") are already filtered out.';
    $user = 'Hints: ' . wp_json_encode($hint, JSON_UNESCAPED_UNICODE) . "\nFound:\n" . wp_json_encode($facts, JSON_UNESCAPED_UNICODE);
    $model = function_exists('sb_model_for') ? sb_model_for('headlines') : 'gpt-6.1-terra';
    $body  = array('model' => $model, 'input' => array(array('role' => 'system', 'content' => $sys), array('role' => 'user', 'content' => mb_substr($user, 0, 14000))), 'max_output_tokens' => 900);
    if (preg_match('/^(gpt-[5-9]|o\d)/i', $model)) {
        $body['reasoning'] = array('effort' => 'low');
    }
    if (function_exists('sb_meter_take')) {
        sb_meter_take();
    }
    $r = wp_remote_post('https://api.openai.com/v1/responses', array('timeout' => 70, 'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'), 'body' => wp_json_encode($body)));
    if (is_wp_error($r) || 200 !== (int) wp_remote_retrieve_response_code($r)) {
        if (function_exists('sb_meter_take')) {
            sb_meter_take();
        }
        wp_send_json_error(array('message' => 'AI উত্তর দেয়নি, একটু পরে আবার চেষ্টা করুন।'));
    }
    $dd  = json_decode(wp_remote_retrieve_body($r), true);
    $txt = '';
    foreach ((array) ($dd['output'] ?? array()) as $it) {
        foreach ((array) ($it['content'] ?? array()) as $c) {
            $txt .= $c['text'] ?? '';
        }
    }
    $real = function_exists('sb_meter_take') ? sb_meter_take() : 0.0;
    if ($real > 0 && function_exists('sb_ledger_add')) {
        $GLOBALS['sb_ledger_model'] = $model;
        sb_ledger_add(0, 'textfix_ai', max(1, (int) ceil($real * SB_BILL_MULT / sb_unit_billed())), 0, $real);
    }
    $j = json_decode(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($txt)), true);
    if (!is_array($j) || empty($j['items'])) {
        wp_send_json_error(array('message' => 'AI-র উত্তর পড়া যায়নি।'));
    }
    wp_send_json_success(array('items' => $j['items']));
});

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
    if ('' === $pat) {
        wp_send_json_error(array('message' => 'খোঁজার লেখা ঠিক নয়।'));
    }
    sb_tf_apply_core($pat, $rep);
});

/** write the change everywhere ($pat = regex string or fact array); sends the JSON answer */
function sb_tf_apply_core($pat, $rep) {
    $only = array_filter(array_map('sanitize_text_field', explode(',', (string) ($_POST['ids'] ?? '')))); // phpcs:ignore WordPress.Security.NonceVerification
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
    array_unshift($log, array('rid' => sanitize_key((string) ($_POST['rid'] ?? '')), 't' => time(), 'find' => is_array($pat) ? wp_json_encode($pat) : (string) wp_unslash($_POST['find'] ?? ''), 'rep' => $rep, 'items' => $bk)); // phpcs:ignore WordPress.Security.NonceVerification
    update_option('sb_tf_backup', array_slice($log, 0, 40), false);
    sb_tf_flush();
    wp_send_json_success(array('items' => $done, 'changes' => $total));
}


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
    <h2 class="nav-tab-wrapper" style="margin:14px 0"><a href="#" class="nav-tab nav-tab-active" id="tab-ai">🤖 তথ্য মিলিয়ে দেখুন (AI পরামর্শ)</a><a href="#" class="nav-tab" id="tab-man">✏️ হাতে বদল</a></h2>
    <div id="pane-ai">
        <p class="sba-muted" style="max-width:900px">সাইটে যত সংখ্যা-তথ্য আছে (ক্যাম্পাস, বছর, শিক্ষার্থী) ধরে ধরে মিলিয়ে দেখায়। একই তথ্য দুই রকম থাকলে ⚠ দেয়। <b>দেখা ফ্রি।</b> AI শুধু "কোনটা ঠিক হতে পারে" পরামর্শ দেয়, নিজে কিছু বদলায় না। বদল হয় আপনি ✅ চাপলে, প্রতিটি আলাদা, ফেরানো যায়।</p>
        <p><button type="button" class="button button-primary" id="ai-scan">🔍 সাইটের তথ্য দেখুন (ফ্রি)</button> <button type="button" class="button" id="ai-ask" disabled>🤖 AI-র পরামর্শ নিন</button> <span id="ai-msg" class="sba-msg"></span></p>
        <div id="ai-out"></div>
    </div>
    <div id="pane-man" style="display:none">
    <div id="tf-list"></div>
    <p><button type="button" class="button" id="tf-add">➕ আরেকটি বদল</button> <span id="tf-msg" class="sba-msg"></span></p>
    </div>
</div>
<style>
#sb-tf .tfr{background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:10px 12px;margin:10px 0;max-width:980px}
#sb-tf .tfr input[type=text]{width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:6px}
#sb-tf .tfg{display:grid;grid-template-columns:1fr 1fr;gap:10px}
#sb-tf .tfo{margin-top:8px;font-size:13px}#sb-tf .tfo p{margin:6px 0;padding:6px;background:#f8fafc;border-radius:6px}
#sb-tf .tfd{opacity:.6}
#sb-tf .fc{background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:12px;margin:10px 0;max-width:980px}
#sb-tf .fc.bad{border-color:#f59e0b}
#sb-tf .fv{border-top:1px solid #eef2f7;padding:8px 0}
#sb-tf .fai{background:#eff6ff;border-radius:6px;padding:6px 8px;margin:6px 0;font-size:13px}
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

    /* ---- tabs + facts ---- */
    function tab(w) { $('#pane-ai').style.display = w === 'ai' ? '' : 'none'; $('#pane-man').style.display = w === 'man' ? '' : 'none'; $('#tab-ai').classList.toggle('nav-tab-active', w === 'ai'); $('#tab-man').classList.toggle('nav-tab-active', w === 'man'); }
    $('#tab-ai').onclick = function (e) { e.preventDefault(); tab('ai'); }; $('#tab-man').onclick = function (e) { e.preventDefault(); tab('man'); };
    var CAT = { campus: 'ক্যাম্পাস / শাখার সংখ্যা', years: 'অভিজ্ঞতার বছর', students: 'শিক্ষার্থীর সংখ্যা' };
    var FACTS = null, HINT = null, SUG = {}, EST = 0;
    function bn(n, like) { return /[০-৯]/.test(like || '') ? String(n).replace(/\d/g, function (d) { return '০১২৩৪৫৬৭৮৯'[d]; }) : String(n); }
    function topVal(rows) { return rows.length ? rows[0].v : ''; }
    function drawFacts() {
        var out = $('#ai-out'); out.innerHTML = '';
        Object.keys(CAT).forEach(function (cat) {
            var rows = FACTS[cat] || []; if (!rows.length) { return; }
            var multi = rows.length > 1, sg = SUG[cat];
            var card = document.createElement('div'); card.className = 'fc' + (multi ? ' bad' : ''); card.dataset.cat = cat;
            var h = '<b>' + CAT[cat] + '</b> ' + (multi ? '<span style="color:#b45309">⚠ ' + rows.length + ' রকম মান পাওয়া গেছে</span>' : '<span style="color:#15803d">✓ সব জায়গায় একই</span>');
            if (sg) { h += '<div class="fai">🤖 <b>পরামর্শ: ' + esc(sg.suggest) + '</b> — ' + esc(sg.reason || '') + ' <i>(' + esc(sg.confidence || '') + ')</i>' + (sg.needs_input ? ' · <b>⚠ প্রমাণ কম, আপনি নিশ্চিত করুন</b>' : '') + '</div>'; }
            rows.forEach(function (r, ri) {
                if (ri === 4) { h += '<details><summary class="sba-muted">আরও ' + (rows.length - 4) + 'টি কম-ব্যবহৃত মান দেখুন</summary>'; }
                var like = (r.s && r.s[0]) || '';
                var pre = sg ? sg.suggest : (rows[0].v !== r.v ? bn(rows[0].v, like) : '');
                var isSg = sg && parseInt(String(sg.suggest).replace(/[০-৯]/g, function (d) { return '০১২৩৪৫৬৭৮৯'.indexOf(d); }), 10) === r.v;
                h += '<div class="fv" data-old="' + r.v + '"><b>' + esc(bn(r.v, like)) + '</b> — ' + r.pages + 'টি পেজ/জায়গায় (' + r.n + 'বার) <small class="sba-muted">' + esc((r.s || []).join(' | ')) + '<br>' + esc((r.p || []).join(', ')) + '</small><br>' +
                    (isSg ? '<span style="color:#15803d">✓ এটাই ঠিক মান (পরামর্শ অনুযায়ী)</span></div>' : 'বদলে হবে: <input type="text" class="nv" style="width:90px" value="' + esc(pre) + '" placeholder="নতুন মান"> <button type="button" class="button v-scan">🔎 কোথায় বদলাবে দেখুন</button> <button type="button" class="button button-primary v-apply" disabled>✅ প্রয়োগ</button> <button type="button" class="button v-undo">↩ ফেরান</button> <span class="sba-msg vm"></span><div class="tfo"></div></div>');
            });
            if (rows.length > 4) { h += '</details>'; }
            card.innerHTML = h; out.appendChild(card);
        });
        if (!out.children.length) { out.innerHTML = '<p>এই ধরনের কোনো সংখ্যা-তথ্য পাওয়া যায়নি।</p>'; }
    }
    $('#ai-scan').onclick = function () {
        $('#ai-msg').textContent = 'সাইট পড়া হচ্ছে… (আধ মিনিট লাগতে পারে)'; $('#ai-scan').disabled = true;
        post('sb_tf_facts').then(function (j) { $('#ai-scan').disabled = false; if (!j.success) { $('#ai-msg').textContent = 'ত্রুটি'; return; } FACTS = j.data.facts; HINT = j.data.hint; EST = j.data.est; SUG = {}; $('#ai-ask').disabled = false; $('#ai-ask').textContent = '🤖 AI-র পরামর্শ নিন (আনুমানিক খরচ ~$' + EST + ')'; $('#ai-msg').textContent = 'হয়ে গেছে। নিচে দেখুন।'; drawFacts(); });
    };
    $('#ai-ask').onclick = function () {
        if (!FACTS) { return; }
        $('#ai-msg').textContent = 'AI ভাবছে…'; $('#ai-ask').disabled = true;
        post('sb_tf_ai', { facts: JSON.stringify(FACTS), hint: JSON.stringify(HINT) }).then(function (j) { $('#ai-ask').disabled = false; if (!j.success) { $('#ai-msg').textContent = (j.data && j.data.message) || 'ত্রুটি'; return; } SUG = {}; j.data.items.forEach(function (x) { SUG[x.cat] = x; }); $('#ai-msg').textContent = 'AI-র পরামর্শ এসেছে (নিজে কিছু বদলায়নি)।'; drawFacts(); });
    };
    $('#ai-out').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) { return; }
        var v = b.closest('.fv'), card = b.closest('.fc'), m = $('.vm', v), out = $('.tfo', v), cat = card.dataset.cat, old = v.dataset.old, nv = $('.nv', v).value.trim(), rid = 'f' + cat + old;
        if (b.classList.contains('v-scan')) {
            if (!nv) { m.textContent = 'নতুন মান লিখুন।'; return; }
            m.textContent = 'খোঁজা হচ্ছে…'; $('.v-apply', v).disabled = true;
            post('sb_tf_fact_scan', { cat: cat, old: old, new: nv }).then(function (j) {
                if (!j.success) { m.textContent = (j.data && j.data.message) || 'ত্রুটি'; return; }
                var x = j.data; m.textContent = x.rows.length + 'টি জায়গায় (মোট ' + x.total + 'টি)।';
                out.innerHTML = x.rows.map(function (r) { return '<p><label><input type="checkbox" class="tf-id" value="' + esc(r.id) + '" checked> <b>' + esc(r.title || '#' + r.id) + '</b> <span class="sba-muted">(' + esc(r.type) + ', ' + r.n + ' জায়গা)</span></label><br><small>' + r.s.map(esc).join('<br>') + '</small></p>'; }).join('');
                $('.v-apply', v).disabled = !x.rows.length;
            });
        } else if (b.classList.contains('v-apply')) {
            var ids = [].map.call(v.querySelectorAll('.tf-id:checked'), function (x) { return x.value; }).join(','); if (!ids) { return; }
            m.textContent = 'বদলানো হচ্ছে…'; b.disabled = true;
            post('sb_tf_fact_apply', { cat: cat, old: old, new: nv, ids: ids, rid: rid }).then(function (j) { m.textContent = j.success ? '✅ শেষ: ' + j.data.items + 'টি জায়গা, ' + j.data.changes + 'টি বদল। (সাইট আবার স্ক্যান করলে নতুন অবস্থা দেখবেন)' : 'ত্রুটি'; if (j.success) { out.innerHTML = ''; } });
        } else if (b.classList.contains('v-undo')) {
            post('sb_tf_undo', { rid: rid }).then(function (j) { m.textContent = j.success ? '↩ ফেরানো হয়েছে (' + j.data.items + 'টি)।' : ((j.data && j.data.message) || 'ত্রুটি'); });
        }
    });
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
