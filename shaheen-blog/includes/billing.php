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

/** Shown price = real provider cost x this number. Fixed here in the code, never shown in any screen. */
const SB_BILL_MULT = 2;

function sb_mult() {
    return SB_BILL_MULT;
}

/** Credits <-> shown USD, for the price boxes in Settings (the credit unit never appears on screen). */
function sb_c2u($credits) {
    return round((float) $credits * sb_unit_billed(), 4);
}
function sb_u2c($usd) {
    return (int) max(0, round((float) $usd / sb_unit_billed()));
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

function sb_vat_pct() {
    return max(0, min(50, (float) sb_get('vat_pct')));
}

/** Monthly limit in credits (0 = no limit: the client simply pays for what is used). */
function sb_limit_credits_from_usd($usd) {
    return (int) max(0, round((float) $usd / sb_unit_billed()));
}

/* ---------- Real cost metering: every answer from OpenAI carries its token counts; price them with the model's rates ---------- */
function sb_meter_model($name) {
    $best = '';
    foreach (array_keys(sb_model_catalog()) as $id) {
        if (strpos($name, $id) === 0 && strlen($id) > strlen($best)) {
            $best = $id;
        }
    }
    return $best;
}

/** Real USD cost of one answer: input and output tokens at the model's $/1M-token rates (+ $0.01 per web search). */
function sb_real_usd($model, $usage, $searches = 0, $flex = false) {
    $id  = sb_meter_model((string) $model);
    $cat = sb_model_catalog();
    if ($id === '' || !is_array($usage) || !preg_match('/([\d.]+)\s*\/\s*([\d.]+)/', (string) $cat[$id][0], $m)) {
        return 0.0;
    }
    $in  = (int) (isset($usage['prompt_tokens']) ? $usage['prompt_tokens'] : (isset($usage['input_tokens']) ? $usage['input_tokens'] : 0));
    $out = (int) (isset($usage['completion_tokens']) ? $usage['completion_tokens'] : (isset($usage['output_tokens']) ? $usage['output_tokens'] : 0));
    if ($in + $out < 1) {
        return 0.0;
    }
    $tok = $in * (float) $m[1] / 1000000 + $out * (float) $m[2] / 1000000;
    // Client is billed at the Standard price even when the call ran on Flex (Flex discount is kept by the owner).
    $half = $flex && !sb_get('flex_bill_standard');
    return ($half ? $tok * 0.5 : $tok) + $searches * 0.01;   // Flex = half the token price; the search fee is the same
}

add_filter('http_response', function ($r, $args, $url) {
    if (is_wp_error($r) || false === strpos((string) $url, 'api.openai.com')) {
        return $r;
    }
    $d = json_decode((string) wp_remote_retrieve_body($r), true);
    if (!is_array($d) || empty($d['usage']) || !is_array($d['usage']) || (isset($d['status']) && 'completed' !== $d['status'])) {
        return $r;
    }
    $searches = 0;
    foreach (isset($d['output']) && is_array($d['output']) ? $d['output'] : array() as $it) {
        if (isset($it['type']) && 'web_search_call' === $it['type']) {
            $searches++;
        }
    }
    $usd = sb_real_usd(isset($d['model']) ? $d['model'] : '', $d['usage'], $searches, isset($d['service_tier']) && 'flex' === $d['service_tier']);
    if ($usd > 0) {
        $GLOBALS['sb_meter'] = (isset($GLOBALS['sb_meter']) ? (float) $GLOBALS['sb_meter'] : 0.0) + $usd;
    }
    return $r;
}, 10, 3);

/** Real cost gathered since the last call (this request), then reset. */
function sb_meter_take() {
    $v = isset($GLOBALS['sb_meter']) ? (float) $GLOBALS['sb_meter'] : 0.0;
    unset($GLOBALS['sb_meter']);
    return $v;
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

function sb_ledger_add($user_id, $feature, $credits, $post_id = 0, $real_usd = null) {
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
        'raw_usd'  => $real_usd !== null ? (float) $real_usd : $credits * SB_RAW_USD_PER_CREDIT,
        'bill_usd' => ($real_usd !== null ? (float) $real_usd : $credits * SB_RAW_USD_PER_CREDIT) * SB_BILL_MULT,
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
        $id = 'BF-' . str_replace('-', '', $ym) . '-' . sprintf('%03d', count($all) + 1);
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
        'bill'    => round($m['bill'], 2),
        'vat_pct' => sb_vat_pct(),
        'vat'     => round($m['bill'] * sb_vat_pct() / 100, 2),
        'total'   => round($m['bill'], 2) + round($m['bill'] * sb_vat_pct() / 100, 2),
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

/** One printable HTML page in the same layout as the OpenRouter receipt. Billed amounts only: the real cost and the margin never appear. */
function sb_addr_lines($t) {
    $o = '';
    foreach (preg_split('/\R/u', (string) $t) as $l) {
        if (trim($l) !== '') {
            $o .= '<div>' . esc_html(trim($l)) . '</div>';
        }
    }
    return $o;
}

function sb_voucher_html($v) {
    $from = sb_trim_from();
    $to   = 'Shaheen Education Family';
    $fa   = '';
    $ta   = '';
    $sub  = (float) $v['bill'];
    $vat  = isset($v['vat']) ? (float) $v['vat'] : 0;
    $tot  = isset($v['total']) ? (float) $v['total'] : $sub;
    $vp   = isset($v['vat_pct']) ? (float) $v['vat_pct'] : 0;
    $mon  = sb_month_label($v['ym']);
    $det  = '';
    foreach ($v['features'] as $r) {
        $det .= '<tr><td>' . esc_html($r['name']) . '</td><td class="r">' . (int) $r['n'] . '</td><td class="r">' . esc_html(sb_fmt_usd($r['bill'])) . '</td></tr>';
    }
    ob_start();
    ?>
<!doctype html><html><head><meta charset="utf-8"><title>Voucher <?php echo esc_html($v['id']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f4f6;color:#111;font:13px/1.5 Inter,"Segoe UI",Arial,sans-serif}
.pg{max-width:820px;margin:24px auto;background:#fff;padding:42px 42px 36px;min-height:1000px;position:relative}
.logo{position:absolute;right:42px;top:42px;width:56px;height:56px;border-radius:6px;background:#1a6b3e;color:#fff;font-weight:800;font-size:20px;display:flex;align-items:center;justify-content:center}
h1{font-size:26px;margin:0 0 14px;font-weight:700}h2{font-size:19px;margin:34px 0 8px;font-weight:700}
.meta div{display:flex;gap:10px;line-height:1.45}.meta b{display:inline-block;min-width:118px;font-weight:600}.meta span{font-weight:500}
.cols{display:flex;gap:60px;margin-top:26px}.cols>div{flex:1;min-width:0}.cols b{display:block;margin-bottom:4px}
.cols .l{line-height:1.5}
table{width:100%;border-collapse:collapse}.items th{font-weight:400;font-size:11px;color:#444;text-align:left;padding:0 0 8px;border-bottom:1px solid #111}
.items th.r,.det th.r{text-align:right}.items td{padding:9px 0;font-size:13px}.r{text-align:right}
.sum{width:55%;margin:18px 0 0 auto}.sum td{padding:3px 0;border-bottom:1px solid #e5e7eb;font-size:13px}.sum tr:last-child td{border-bottom:0}.sum .t td{font-weight:700}
.det{margin-top:30px}.det th{font-weight:400;font-size:11px;color:#444;text-align:left;padding:0 0 6px;border-bottom:1px solid #111}.det td{padding:6px 0;border-bottom:1px solid #f0f0f0;font-size:12.5px}
.foot{position:absolute;left:42px;right:42px;bottom:28px;border-top:1px solid #e5e7eb;padding-top:12px;font-size:11px;color:#555;display:flex;justify-content:space-between}
.btn{text-align:center;margin:16px}@media print{body{background:#fff}.pg{margin:0;padding:0;min-height:0}.logo{right:0;top:0}.foot{left:0;right:0}.btn{display:none}}
</style></head><body>
<div class="btn"><button onclick="window.print()" style="padding:8px 18px;font-size:14px">🖨 Print / Save as PDF</button></div>
<div class="pg"><div class="logo">BW</div>
<h1>Voucher</h1>
<div class="meta"><div><b>Voucher number</b><span><?php echo esc_html($v['id']); ?></span></div><div><b>Statement</b><span>Monthly API statement — <?php echo esc_html($mon); ?></span></div><div><b>Voucher date</b><span><?php echo esc_html(wp_date('F j, Y', $v['made'])); ?></span></div></div>
<div class="cols"><div class="l"><b><?php echo esc_html($from); ?></b><?php echo $fa !== '' ? sb_addr_lines($fa) : '<div>' . esc_html(home_url()) . '</div>'; // phpcs:ignore ?></div>
<div class="l"><b>Bill to</b><div><?php echo esc_html($to); ?></div><?php echo sb_addr_lines($ta); // phpcs:ignore ?></div></div>
<h2>Amount due: <?php echo esc_html(sb_fmt_usd($tot)); ?></h2>
<div style="margin-bottom:22px;color:#333">Monthly API statement for <?php echo esc_html($mon); ?></div>
<table class="items"><tr><th>Description</th><th class="r" style="width:60px">Qty</th><th class="r" style="width:110px">Unit price</th><th class="r" style="width:110px">Amount</th></tr>
<tr><td>Monthly API Statement — <?php echo esc_html($mon); ?></td><td class="r">1</td><td class="r"><?php echo esc_html(sb_fmt_usd($sub)); ?></td><td class="r"><?php echo esc_html(sb_fmt_usd($sub)); ?></td></tr></table>
<table class="sum"><tr><td>Subtotal</td><td class="r"><?php echo esc_html(sb_fmt_usd($sub)); ?></td></tr>
<tr><td>VAT (<?php echo esc_html(rtrim(rtrim(number_format($vp, 2), '0'), '.')); ?>%)</td><td class="r"><?php echo esc_html(sb_fmt_usd($vat)); ?></td></tr>
<tr class="t"><td>Total</td><td class="r"><?php echo esc_html(sb_fmt_usd($tot)); ?></td></tr></table>
<?php if ($det !== '') : ?><table class="det"><tr><th>Usage summary (<?php echo (int) $v['count']; ?> uses, before VAT)</th><th class="r" style="width:60px">Times</th><th class="r" style="width:110px">Amount</th></tr><?php echo $det; // phpcs:ignore ?></table><?php endif; ?>
<div class="foot"><span><?php echo esc_html(home_url()); ?></span><span>Page 1 of 1</span></div></div></body></html>
    <?php
    return ob_get_clean();
}

function sb_trim_from() {
    return 'Blog Write .ai';   // the voucher is always issued by Blog Write .ai to Shaheen Education Family
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
    $link   = wp_nonce_url(admin_url('admin-post.php?action=sb_voucher_view&id=' . rawurlencode($id)), 'sb_voucher');
    $tot    = isset($v['total']) ? $v['total'] : $v['bill'];
    $hd     = array('Content-Type: text/html; charset=UTF-8');
    $subj   = '[' . get_bloginfo('name') . '] Monthly API statement ' . sb_month_label($v['ym']) . ' — ' . sb_fmt_usd($tot) . ' (voucher ' . $id . ')';
    $vat    = 'VAT ' . rtrim(rtrim(number_format(isset($v['vat_pct']) ? $v['vat_pct'] : 0, 2), '0'), '.') . '%';
    $ctable = '<table cellpadding="8" style="border-collapse:collapse;border:1px solid #ddd"><tr><td>Subtotal</td><td align="right">' . esc_html(sb_fmt_usd($v['bill'])) . '</td></tr><tr><td>' . esc_html($vat) . '</td><td align="right">' . esc_html(sb_fmt_usd(isset($v['vat']) ? $v['vat'] : 0)) . '</td></tr><tr><td><b>Total</b></td><td align="right"><b style="color:#1a6b3e;font-size:18px">' . esc_html(sb_fmt_usd($tot)) . '</b></td></tr></table>';
    // to the report address
    $body = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d2327"><h2 style="color:#1a6b3e;margin:0 0 8px">' . esc_html(get_bloginfo('name')) . ' — API statement, ' . esc_html(sb_month_label($v['ym'])) . '</h2>'
        . '<p>AI uses: <b>' . (int) $v['count'] . '</b></p>' . $ctable
        . '<p>Voucher <b>' . esc_html($id) . '</b> is attached (open it in a browser and print or save as PDF). Dashboard: <a href="' . esc_url($link) . '">view voucher</a>.</p></div>';
    $ok = wp_mail($to, $subj, $body, $hd, array($file));
    // to the client (optional): the bill only
    $cl = trim((string) sb_get('client_email'));
    if (is_email($cl)) {
        $cb = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d2327"><h2 style="color:#1a6b3e;margin:0 0 8px">Monthly API statement — ' . esc_html(sb_month_label($v['ym'])) . '</h2>' . $ctable . '<p>Your voucher <b>' . esc_html($id) . '</b> is attached. You can also see it any time in your dashboard under Blog Write .ai → USD Billing.</p></div>';
        wp_mail($cl, $subj, $cb, $hd, array($file));
    }
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
    echo '<div class="wrap sba"><h1>💵 USD Billing</h1>';
    if (isset($msgs[$m])) {
        echo '<div class="notice notice-' . ('mailfail' === $m || 'bad' === $m ? 'warning' : 'success') . '"><p>' . esc_html($msgs[$m]) . '</p></div>';
    }
    echo '<p class="sba-muted">এখানে আপনার মাসের AI ব্যবহারের বিল ও ভাউচার দেখা যায়। কোনো সীমা নেই — যত ব্যবহার তত বিল।</p>';
    $vp = sb_vat_pct();
    echo '<div class="sba-grid sba-grid--2">';
    foreach (array(array('এই মাস (' . sb_month_label($cur) . ')', $now, $cur), array('গত মাস (' . sb_month_label($prev) . ')', $pm, $prev)) as $c) {
        $vat = round($c[1]['bill'] * $vp / 100, 2);
        echo '<div class="sba-card"><h2>' . esc_html($c[0]) . '</h2><p class="sba-big">' . esc_html(sb_fmt_usd(round($c[1]['bill'], 2) + $vat)) . ' <small>মোট বিল</small></p><p class="sba-muted">ব্যবহার ' . (int) $c[1]['count'] . ' বার · API স্টেটমেন্ট ' . esc_html(sb_fmt_usd($c[1]['bill'])) . ' + VAT ' . esc_html(rtrim(rtrim(number_format($vp, 2), '0'), '.')) . '% ' . esc_html(sb_fmt_usd($vat)) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">' . wp_nonce_field('sb_voucher_do', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sb_voucher_do"><input type="hidden" name="ym" value="' . esc_attr($c[2]) . '"><button class="button" name="do" value="make">ভাউচার তৈরি</button> <button class="button button-primary" name="do" value="send">ভাউচার তৈরি + ইমেইল</button></form></div>';
    }
    echo '</div><div class="sba-card"><h2>ভাউচার তালিকা</h2>';
    $all = array_reverse(sb_vouchers(), true);
    if (!$all) {
        echo '<p class="sba-muted">এখনো কোনো ভাউচার নেই। মাস শেষে নিজে তৈরি হবে (সেটিংসে "অটো" চালু থাকলে), অথবা উপরের বোতামে এখনই বানান।</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>নম্বর</th><th>মাস</th><th>ব্যবহার</th><th>মোট (VAT সহ)</th><th>ইমেইল</th><th></th></tr></thead><tbody>';
        foreach ($all as $id => $v) {
            $link = wp_nonce_url(admin_url('admin-post.php?action=sb_voucher_view&id=' . rawurlencode($id)), 'sb_voucher');
            echo '<tr><td><b>' . esc_html($id) . '</b></td><td>' . esc_html(sb_month_label($v['ym'])) . '</td><td>' . (int) $v['count'] . '</td><td><b>' . esc_html(sb_fmt_usd(isset($v['total']) ? $v['total'] : $v['bill'])) . '</b></td><td>' . ($v['sent'] ? esc_html(wp_date('d M Y H:i', $v['sent'])) : '—') . '</td><td><a class="button" target="_blank" href="' . esc_url($link) . '">দেখুন / প্রিন্ট</a></td></tr>';
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

/** One-time: voucher numbers start with BF- (they used to start with SEF-). */
add_action('init', function () {
    if (get_option('sb_bf_ref') === '1') {
        return;
    }
    $all = get_option('sb_vouchers', array());
    if (is_array($all) && $all) {
        $new = array();
        foreach ($all as $id => $v) {
            $nid = preg_replace('/^SEF-/', 'BF-', (string) $id);
            if (is_array($v)) {
                $v['id'] = $nid;
            }
            $new[$nid] = $v;
        }
        update_option('sb_vouchers', $new, false);
    }
    update_option('sb_bf_ref', '1', false);
}, 8);


/**
 * One-time: usage that happened before the USD ledger existed (it is only in each user's cycle counter) is added to the
 * ledger as one "আগের ব্যবহার" row per user, so this month's bill is not $0. Stays in the month it is created in.
 */
add_action('init', function () {
    if (get_option('sb_ledger_backfill') === '1' || get_option('sb_usage_db') !== '2') {
        return;
    }
    global $wpdb;
    $t = sb_ledger_table();
    foreach (get_users(array('fields' => 'ID', 'meta_key' => SB_Credits::META_USED)) as $uid) {
        $used  = max(0, (int) get_user_meta($uid, SB_Credits::META_USED, true));
        $start = (string) get_user_meta($uid, SB_Credits::META_START, true);
        $from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? strtotime($start . ' 00:00:00 ' . wp_timezone_string()) : 0;
        $have  = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(credits),0) FROM {$t} WHERE user_id = %d AND t >= %d", $uid, $from));
        if ($used > $have) {
            sb_ledger_add($uid, 'legacy', $used - $have);
        }
    }
    update_option('sb_ledger_backfill', '1', false);
}, 9);
