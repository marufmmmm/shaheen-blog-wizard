<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * USD billing on top of the credit counter.
 *
 * Inside, 1 credit = $0.0002 of real provider cost (that is how every price in the plugin was built).
 * What the person SEES is dollars: cost / (1 - margin). Margin 50% means $1 real cost is shown as $2.
 * Every use is written to a ledger table, so the monthly report and the voucher are exact.
 */

const SB_RAW_USD_PER_CREDIT = 0.0002;

function sb_margin() {
    $m = (float) sb_get('usd_margin');
    return max(0, min(90, $m));
}

/** Multiplier from real cost to shown price. */
function sb_mult() {
    return 1 / (1 - sb_margin() / 100);
}

function sb_unit_billed() {
    return SB_RAW_USD_PER_CREDIT * sb_mult();
}

/** Shown price in USD for an amount of credits. */
function sb_usd($credits) {
    return (float) $credits * sb_unit_billed();
}

function sb_fmt_usd($usd) {
    $usd = (float) $usd;
    if ($usd == 0) {
        return '$0.00';
    }
    if ($usd < 0.1) {
        return '$' . number_format($usd, 3, '.', ',');
    }
    return '$' . number_format($usd, 2, '.', ',');
}

function sb_money($credits) {
    return sb_fmt_usd(sb_usd($credits));
}

/** Monthly limit in credits, from the USD limit setting. */
function sb_limit_credits_from_usd($usd) {
    return (int) max(0, round((float) $usd / sb_unit_billed()));
}

/* ---------- Ledger ---------- */
function sb_ledger_table() {
    global $wpdb;
    return $wpdb->prefix . 'sb_usage';
}

add_action('init', function () {
    if (get_option('sb_usage_db') === '2') {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = sb_ledger_table();
    dbDelta("CREATE TABLE {$t} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        t int(11) unsigned NOT NULL,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        feature varchar(40) NOT NULL DEFAULT '',
        post_id bigint(20) unsigned NOT NULL DEFAULT 0,
        credits int(11) NOT NULL DEFAULT 0,
        raw_usd decimal(12,6) NOT NULL DEFAULT 0,
        bill_usd decimal(12,6) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY t (t)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('sb_usage_db', '2');
}, 5);

function sb_ledger_add($user_id, $feature, $credits, $post_id = 0) {
    global $wpdb;
    $credits = (int) $credits;
    if ($credits <= 0) {
        return;
    }
    $wpdb->insert(sb_ledger_table(), array(
        't'        => time(),
        'user_id'  => (int) $user_id,
        'feature'  => substr((string) $feature, 0, 40),
        'post_id'  => (int) $post_id,
        'credits'  => $credits,
        'raw_usd'  => $credits * SB_RAW_USD_PER_CREDIT,
        'bill_usd' => sb_usd($credits),
    ));
}

/** First/last second of a month "YYYY-MM" in the site's time zone. */
function sb_month_range($ym) {
    $s = new DateTimeImmutable($ym . '-01 00:00:00', wp_timezone());
    $e = $s->modify('+1 month');
    return array($s->getTimestamp(), $e->getTimestamp() - 1);
}

function sb_ledger_month($ym) {
    global $wpdb;
    list($a, $b) = sb_month_range($ym);
    $t   = sb_ledger_table();
    $out = array('ym' => $ym, 'count' => 0, 'credits' => 0, 'raw' => 0.0, 'bill' => 0.0, 'by_feature' => array(), 'by_user' => array());
    $rows = $wpdb->get_results($wpdb->prepare("SELECT feature, user_id, COUNT(*) n, SUM(credits) c, SUM(raw_usd) r, SUM(bill_usd) b FROM {$t} WHERE t BETWEEN %d AND %d GROUP BY feature, user_id", $a, $b), ARRAY_A);
    foreach ((array) $rows as $r) {
        $out['count']   += (int) $r['n'];
        $out['credits'] += (int) $r['c'];
        $out['raw']     += (float) $r['r'];
        $out['bill']    += (float) $r['b'];
        $f = $r['feature'];
        $u = (int) $r['user_id'];
        $out['by_feature'][$f] = isset($out['by_feature'][$f]) ? $out['by_feature'][$f] : array('n' => 0, 'bill' => 0.0);
        $out['by_feature'][$f]['n']    += (int) $r['n'];
        $out['by_feature'][$f]['bill'] += (float) $r['b'];
        $out['by_user'][$u] = isset($out['by_user'][$u]) ? $out['by_user'][$u] : array('n' => 0, 'bill' => 0.0);
        $out['by_user'][$u]['n']    += (int) $r['n'];
        $out['by_user'][$u]['bill'] += (float) $r['b'];
    }
    return $out;
}

/* ---------- Voucher ---------- */
function sb_vouchers() {
    $v = get_option('sb_vouchers', array());
    return is_array($v) ? $v : array();
}

function sb_voucher_by_month($ym) {
    foreach (sb_vouchers() as $id => $v) {
        if ($v['ym'] === $ym) {
            return $id;
        }
    }
    return '';
}

/** Create (or rebuild) the voucher for a month. Returns its id. */
function sb_voucher_make($ym) {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
        return '';
    }
    $m   = sb_ledger_month($ym);
    $all = sb_vouchers();
    $id  = sb_voucher_by_month($ym);
    if (!$id) {
        $id = 'SEF-' . str_replace('-', '', $ym) . '-' . sprintf('%03d', count($all) + 1);
    }
    $feat = array();
    $labels = sb_feature_labels();
    foreach ($m['by_feature'] as $f => $r) {
        $feat[] = array('name' => isset($labels[$f]) ? $labels[$f] : $f, 'n' => $r['n'], 'bill' => round($r['bill'], 4));
    }
    usort($feat, function ($a, $b) { return $b['bill'] <=> $a['bill']; });
    $users = array();
    foreach ($m['by_user'] as $uid => $r) {
        $u = get_userdata($uid);
        $users[] = array('name' => $u ? $u->display_name : ('#' . $uid), 'n' => $r['n'], 'bill' => round($r['bill'], 4));
    }
    $all[$id] = array(
        'id'      => $id,
        'ym'      => $ym,
        'made'    => time(),
        'count'   => $m['count'],
        'credits' => $m['credits'],
        'raw'     => round($m['raw'], 4),
        'bill'    => round($m['bill'], 2),
        'features' => $feat,
        'users'   => $users,
        'sent'    => isset($all[$id]['sent']) ? $all[$id]['sent'] : 0,
    );
    update_option('sb_vouchers', $all, false);
    return $id;
}

function sb_month_label($ym) {
    $s = new DateTimeImmutable($ym . '-01 12:00:00', wp_timezone());
    return wp_date('F Y', $s->getTimestamp());
}

/** One printable HTML page (billed amounts only: the real cost and the margin never appear on it). */
function sb_voucher_html($v) {
    $site = get_bloginfo('name');
    $to   = trim((string) sb_get('bill_to'));
    $to   = $to !== '' ? $to : $site;
    $rows = '';
    foreach ($v['features'] as $r) {
        $rows .= '<tr><td>' . esc_html($r['name']) . '</td><td class="n">' . (int) $r['n'] . '</td><td class="n">' . esc_html(sb_fmt_usd($r['bill'])) . '</td></tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="3" style="color:#777">This month there was no AI use.</td></tr>';
    }
    $urows = '';
    foreach ($v['users'] as $r) {
        $urows .= '<tr><td>' . esc_html($r['name']) . '</td><td class="n">' . (int) $r['n'] . '</td><td class="n">' . esc_html(sb_fmt_usd($r['bill'])) . '</td></tr>';
    }
    $from = sb_trim_from();
    ob_start();
    ?>
<!doctype html><html><head><meta charset="utf-8"><title>Voucher <?php echo esc_html($v['id']); ?></title>
<style>body{font-family:Arial,Helvetica,sans-serif;color:#1d2327;margin:0;background:#f3f4f6}.pg{max-width:760px;margin:24px auto;background:#fff;padding:40px;border-radius:6px}
h1{margin:0;font-size:26px;letter-spacing:1px}.top{display:flex;justify-content:space-between;border-bottom:3px solid #1a6b3e;padding-bottom:14px;margin-bottom:18px}
.meta{font-size:14px;line-height:1.7}.meta b{display:inline-block;min-width:96px}table{width:100%;border-collapse:collapse;margin:14px 0}th{background:#1a6b3e;color:#fff;text-align:left;padding:8px 10px;font-size:13px}
td{padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:14px}.n{text-align:right;white-space:nowrap}.tot td{font-weight:700;font-size:17px;border-top:2px solid #1a6b3e;border-bottom:0}
.note{font-size:12px;color:#666;margin-top:22px}.btn{margin:16px auto;text-align:center}@media print{body{background:#fff}.pg{margin:0;padding:0}.btn{display:none}}</style></head><body>
<div class="btn"><button onclick="window.print()" style="padding:8px 18px;font-size:14px">🖨 Print / Save as PDF</button></div>
<div class="pg"><div class="top"><div><h1>VOUCHER</h1><div style="color:#1a6b3e;font-weight:700"><?php echo esc_html($v['id']); ?></div></div>
<div class="meta" style="text-align:right"><b><?php echo esc_html($from); ?></b><br><?php echo esc_html(home_url()); ?></div></div>
<div class="meta"><div><b>Billed to</b> <?php echo esc_html($to); ?></div><div><b>Period</b> <?php echo esc_html(sb_month_label($v['ym'])); ?></div><div><b>Issued</b> <?php echo esc_html(wp_date('d M Y', $v['made'])); ?></div><div><b>Service</b> Blog Write .ai — AI usage</div><div><b>Total uses</b> <?php echo (int) $v['count']; ?></div></div>
<table><tr><th>AI feature</th><th class="n">Times used</th><th class="n">Amount (USD)</th></tr><?php echo $rows; // phpcs:ignore ?>
<tr class="tot"><td colspan="2">Total payable</td><td class="n"><?php echo esc_html(sb_fmt_usd($v['bill'])); ?></td></tr></table>
<?php if (count($v['users']) > 1) : ?><table><tr><th>User</th><th class="n">Times used</th><th class="n">Amount (USD)</th></tr><?php echo $urows; // phpcs:ignore ?></table><?php endif; ?>
<p class="note">All amounts are in US dollars. This voucher lists the AI services used through Blog Write .ai during the period shown.</p></div></body></html>
    <?php
    return ob_get_clean();
}

function sb_trim_from() {
    $f = trim((string) sb_get('bill_from'));
    return $f !== '' ? $f : get_bloginfo('name');
}

/** Email the month's summary and attach the voucher file. */
function sb_voucher_send($id) {
    $all = sb_vouchers();
    if (!isset($all[$id])) {
        return false;
    }
    $v  = $all[$id];
    $to = trim((string) sb_get('report_email'));
    $to = is_email($to) ? $to : get_option('admin_email');
    $dir = trailingslashit(wp_upload_dir()['basedir']) . 'sb-vouchers';
    wp_mkdir_p($dir);
    @file_put_contents($dir . '/index.html', '');
    $file = $dir . '/voucher-' . $id . '.html';
    file_put_contents($file, sb_voucher_html($v));
    $profit = $v['bill'] - $v['raw'];
    $link   = wp_nonce_url(admin_url('admin-post.php?action=sb_voucher_view&id=' . rawurlencode($id)), 'sb_voucher');
    $body   = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d2327"><h2 style="color:#1a6b3e;margin:0 0 8px">' . esc_html(get_bloginfo('name')) . ' — AI usage, ' . esc_html(sb_month_label($v['ym'])) . '</h2>'
        . '<table cellpadding="8" style="border-collapse:collapse;border:1px solid #ddd"><tr><td>AI uses</td><td align="right"><b>' . (int) $v['count'] . '</b></td></tr>'
        . '<tr><td>Real cost (provider)</td><td align="right"><b>$' . number_format($v['raw'], 2) . '</b></td></tr>'
        . '<tr><td>Billed amount (margin ' . esc_html(rtrim(rtrim(number_format(sb_margin(), 1), '0'), '.')) . '%)</td><td align="right"><b style="color:#1a6b3e;font-size:18px">' . esc_html(sb_fmt_usd($v['bill'])) . '</b></td></tr>'
        . '<tr><td>Profit</td><td align="right"><b>$' . number_format($profit, 2) . '</b></td></tr></table>'
        . '<p>Voucher <b>' . esc_html($id) . '</b> is attached (open it in a browser and print or save as PDF). You can also open it in your dashboard: <a href="' . esc_url($link) . '">view voucher</a>.</p></div>';
    $ok = wp_mail($to, '[' . get_bloginfo('name') . '] AI usage ' . sb_month_label($v['ym']) . ' — ' . sb_fmt_usd($v['bill']) . ' (voucher ' . $id . ')', $body, array('Content-Type: text/html; charset=UTF-8'), array($file));
    if ($ok) {
        $all[$id]['sent'] = time();
        update_option('sb_vouchers', $all, false);
    }
    return $ok;
}

/* ---------- Monthly automatic report ---------- */
add_action('init', function () {
    if (!wp_next_scheduled('sb_billing_tick')) {
        wp_schedule_event(time() + 600, 'daily', 'sb_billing_tick');
    }
});

add_action('sb_billing_tick', 'sb_billing_run');
function sb_billing_run() {
    if (!sb_get('report_auto')) {
        return;
    }
    $prev = (new DateTimeImmutable('first day of last month', wp_timezone()))->format('Y-m');
    $id   = sb_voucher_by_month($prev);
    if ($id && sb_vouchers()[$id]['sent']) {
        return; // already sent
    }
    $m = sb_ledger_month($prev);
    if ($m['count'] < 1) {
        return; // nothing was used that month
    }
    $id = sb_voucher_make($prev);
    if ($id) {
        sb_voucher_send($id);
    }
}

/* ---------- Admin: page, voucher view, actions ---------- */
add_action('admin_post_sb_voucher_view', function () {
    if (!current_user_can('manage_options')) {
        wp_die('No.');
    }
    check_admin_referer('sb_voucher');
    $all = sb_vouchers();
    $id  = sanitize_text_field(wp_unslash($_GET['id'] ?? ''));
    if (!isset($all[$id])) {
        wp_die('Voucher not found.');
    }
    header('Content-Type: text/html; charset=utf-8');
    echo sb_voucher_html($all[$id]); // phpcs:ignore
    exit;
});

add_action('admin_post_sb_voucher_do', function () {
    if (!current_user_can('manage_options')) {
        wp_die('No.');
    }
    check_admin_referer('sb_voucher_do');
    $ym  = sanitize_text_field(wp_unslash($_POST['ym'] ?? ''));
    $act = sanitize_key($_POST['do'] ?? 'make');
    $msg = '';
    $id  = sb_voucher_make($ym);
    if (!$id) {
        $msg = 'bad';
    } elseif ('send' === $act) {
        $msg = sb_voucher_send($id) ? 'sent' : 'mailfail';
    } else {
        $msg = 'made';
    }
    wp_safe_redirect(admin_url('admin.php?page=sb-billing&m=' . $msg));
    exit;
});

add_action('admin_menu', function () {
    add_submenu_page('sb-dashboard', 'USD Billing', '💵 USD Billing', 'manage_options', 'sb-billing', 'sb_page_billing');
}, 30);

function sb_page_billing() {
    if (!current_user_can('manage_options')) {
        wp_die('No.');
    }
    $cur  = wp_date('Y-m');
    $prev = (new DateTimeImmutable('first day of last month', wp_timezone()))->format('Y-m');
    $now  = sb_ledger_month($cur);
    $pm   = sb_ledger_month($prev);
    $msgs = array('made' => 'ভাউচার তৈরি হয়েছে ✓', 'sent' => 'ভাউচার তৈরি হয়েছে এবং ইমেইল পাঠানো হয়েছে ✓', 'mailfail' => 'ভাউচার তৈরি হয়েছে, কিন্তু ইমেইল যায়নি — হোস্টের মেইল ঠিক আছে কিনা দেখুন (সেটিংসে ইমেইল ঠিকানা মিলিয়ে নিন)।', 'bad' => 'মাস ঠিক নয়।');
    $m = sanitize_key($_GET['m'] ?? '');
    $profit = function ($x) { return $x['bill'] - $x['raw']; };
    echo '<div class="wrap sba"><h1>💵 USD Billing</h1>';
    if (isset($msgs[$m])) {
        echo '<div class="notice notice-' . ('mailfail' === $m || 'bad' === $m ? 'warning' : 'success') . '"><p>' . esc_html($msgs[$m]) . '</p></div>';
    }
    echo '<p class="sba-muted">মুনাফা (margin) এখন <b>' . esc_html(rtrim(rtrim(number_format(sb_margin(), 1), '0'), '.')) . '%</b> — মানে $১ আসল খরচ = <b>' . esc_html(sb_fmt_usd(sb_mult())) . '</b> দেখাবে। বদলাতে <a href="' . esc_url(admin_url('admin.php?page=sb-settings')) . '">সেটিংস</a>।</p>';
    echo '<div class="sba-grid sba-grid--2">';
    foreach (array(array('এই মাস (' . sb_month_label($cur) . ')', $now, $cur), array('গত মাস (' . sb_month_label($prev) . ')', $pm, $prev)) as $c) {
        echo '<div class="sba-card"><h2>' . esc_html($c[0]) . '</h2><p class="sba-big">' . esc_html(sb_fmt_usd($c[1]['bill'])) . ' <small>বিল</small></p><p class="sba-muted">ব্যবহার ' . (int) $c[1]['count'] . ' বার · আসল খরচ $' . esc_html(number_format($c[1]['raw'], 3)) . ' · মুনাফা $' . esc_html(number_format($profit($c[1]), 3)) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">' . wp_nonce_field('sb_voucher_do', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sb_voucher_do"><input type="hidden" name="ym" value="' . esc_attr($c[2]) . '"><button class="button" name="do" value="make">ভাউচার তৈরি</button> <button class="button button-primary" name="do" value="send">ভাউচার তৈরি + ইমেইল</button></form></div>';
    }
    echo '</div><div class="sba-card"><h2>ভাউচার তালিকা</h2>';
    $all = array_reverse(sb_vouchers(), true);
    if (!$all) {
        echo '<p class="sba-muted">এখনো কোনো ভাউচার নেই। মাস শেষে নিজে তৈরি হবে (সেটিংসে "অটো" চালু থাকলে), অথবা উপরের বোতামে এখনই বানান।</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>নম্বর</th><th>মাস</th><th>ব্যবহার</th><th>বিল</th><th>ইমেইল</th><th></th></tr></thead><tbody>';
        foreach ($all as $id => $v) {
            $link = wp_nonce_url(admin_url('admin-post.php?action=sb_voucher_view&id=' . rawurlencode($id)), 'sb_voucher');
            echo '<tr><td><b>' . esc_html($id) . '</b></td><td>' . esc_html(sb_month_label($v['ym'])) . '</td><td>' . (int) $v['count'] . '</td><td><b>' . esc_html(sb_fmt_usd($v['bill'])) . '</b></td><td>' . ($v['sent'] ? esc_html(wp_date('d M Y H:i', $v['sent'])) : '—') . '</td><td><a class="button" target="_blank" href="' . esc_url($link) . '">দেখুন / প্রিন্ট</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    $labels = sb_feature_labels();
    global $wpdb;
    $t    = sb_ledger_table();
    $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id DESC LIMIT 25", ARRAY_A);
    echo '<div class="sba-card"><h2>সর্বশেষ ব্যবহার</h2>';
    if (!$rows) {
        echo '<p class="sba-muted">এখনো কোনো AI ব্যবহার খাতায় ওঠেনি। (এই সংস্করণ থেকে খাতা শুরু হয়েছে।)</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>সময়</th><th>কাজ</th><th>ইউজার</th><th>বিল</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $u = get_userdata((int) $r['user_id']);
            echo '<tr><td>' . esc_html(wp_date('d M H:i', (int) $r['t'])) . '</td><td>' . esc_html(isset($labels[$r['feature']]) ? $labels[$r['feature']] : $r['feature']) . '</td><td>' . esc_html($u ? $u->display_name : '#' . $r['user_id']) . '</td><td>' . esc_html(sb_fmt_usd($r['bill_usd'])) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';
}
