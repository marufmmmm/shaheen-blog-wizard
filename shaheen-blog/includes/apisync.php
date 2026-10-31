<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bill from the provider's own numbers.
 *
 * Every 2 hours (and on demand) the plugin asks the OpenAI Costs API for the real dollars of the site's Project,
 * day by day (UTC days, grouped by line item). The monthly bill then follows the provider's statement:
 *   text and search  = real cost x 2        images = real cost / 0.70
 * The per-use ledger still tells WHAT was used; one "হিসাব সমন্বয়" line carries the difference, so the voucher always totals the provider's figure.

 * Starting point: the first successful sync fixes a baseline. The bill of that month = the amount owed before this version (a fixed carry-in,
 * default $18.00) + the provider's cost since the baseline. Nobody can refresh or trigger it from the screen: the OpenAI part only moves at the
 * automatic 2-hour sync, and stays unchanged between syncs. Other providers (Claude, Kimi...) are still counted live from the ledger.
 * Real cost and profit are stored only in the database, never printed on a client screen or voucher.
 * Needs an Admin key (the normal key cannot read costs). Put the site on its own Project and enter its Project ID.
 */

add_filter('cron_schedules', function ($s) {
    $s['sb_2h'] = array('interval' => 2 * HOUR_IN_SECONDS, 'display' => 'Every 2 hours');
    return $s;
});

add_action('init', function () {
    $on = trim((string) sb_get('admin_key')) !== '';
    $next = wp_next_scheduled('sb_api_sync_tick');
    if ($on && !$next) {
        wp_schedule_event(time() + 120, 'sb_2h', 'sb_api_sync_tick');
    } elseif (!$on && $next) {
        wp_clear_scheduled_hook('sb_api_sync_tick');
    }
}, 20);

add_action('sb_api_sync_tick', 'sb_api_sync');

/** What the client is billed for one month from the provider's cost lines. [text real, image real, bill] */
function sb_api_bill_from($text, $image) {
    $bill = round($text * SB_BILL_MULT, 4) + ceil(($image / (1 - SB_IMG_MARGIN)) * 10000 - 1e-9) / 10000;
    return round($bill, 4);
}

function sb_api_base() {
    $b = get_option('sb_api_base', array());
    return is_array($b) && !empty($b['t0']) ? $b : null;
}

/** Provider cost lines for a month that belong to this bill (text, image), or null when the sync is not active for it. */
function sb_api_month($ym) {
    $base = sb_api_base();
    $days = get_option('sb_api_days', array());
    if (!$base || !is_array($days) || $ym < $base['ym']) {
        return null;
    }
    $t = 0.0;
    $i = 0.0;
    $n = 0;
    foreach ($days as $d => $v) {
        if (substr($d, 0, 7) !== $ym || ($ym === $base['ym'] && $d < $base['day'])) {
            continue;
        }
        $t += (float) $v[0];
        $i += (float) $v[1];
        $n++;
    }
    if ($ym === $base['ym']) {   // what was already used on the baseline day is inside the carry-in amount
        $t = max(0.0, $t - (float) $base['text0']);
        $i = max(0.0, $i - (float) $base['image0']);
    }
    return $n || $ym === $base['ym'] ? array('text' => $t, 'image' => $i) : null;
}

/** The amount owed before this version (month of the baseline), fixed. */
function sb_api_carry() {
    $c = sb_get('api_carry');
    return $c === '' || $c === null ? 18.0 : max(0.0, (float) $c);
}

/** Pull the costs of last month + this month. Returns array(ok, message). */
function sb_api_sync() {
    $key = trim((string) sb_get('admin_key'));
    if ($key === '') {
        return array(false, 'Admin key দেওয়া নেই।');
    }
    $first = (new DateTimeImmutable('first day of last month 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
    $proj  = trim((string) sb_get('api_project'));
    $daysnew = array();
    $page   = '';
    $n      = 0;
    do {
        $q = 'start_time=' . $first . '&end_time=' . (time() + DAY_IN_SECONDS) . '&bucket_width=1d&limit=62&group_by%5B%5D=line_item' . ($proj !== '' ? '&project_ids%5B%5D=' . rawurlencode($proj) : '') . ($page !== '' ? '&page=' . rawurlencode($page) : '');
        $r = wp_remote_get('https://api.openai.com/v1/organization/costs?' . $q, array('timeout' => 40, 'headers' => array('Authorization' => 'Bearer ' . $key)));
        if (is_wp_error($r)) {
            return sb_api_fail('OpenAI-তে পৌঁছানো যায়নি: ' . $r->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($r);
        $d    = json_decode((string) wp_remote_retrieve_body($r), true);
        if ($code !== 200 || !is_array($d)) {
            $m = is_array($d) && !empty($d['error']['message']) ? $d['error']['message'] : ('HTTP ' . $code);
            return sb_api_fail(in_array($code, array(401, 403), true) ? 'Admin key কাজ করেনি (এটি সাধারণ কী নয়, Admin key লাগে): ' . $m : $m);
        }
        foreach (isset($d['data']) && is_array($d['data']) ? $d['data'] : array() as $b) {
            $day = gmdate('Y-m-d', (int) (isset($b['start_time']) ? $b['start_time'] : 0));
            if (!isset($daysnew[$day])) {
                $daysnew[$day] = array(0.0, 0.0);
            }
            foreach (isset($b['results']) && is_array($b['results']) ? $b['results'] : array() as $x) {
                $v  = isset($x['amount']['value']) ? (float) $x['amount']['value'] : 0.0;
                $li = isset($x['line_item']) ? (string) $x['line_item'] : '';
                $daysnew[$day][preg_match('/image|dall-?e/i', $li) ? 1 : 0] += $v;
            }
        }
        $page = !empty($d['has_more']) && !empty($d['next_page']) ? (string) $d['next_page'] : '';
    } while ($page !== '' && ++$n < 6);
    $days = get_option('sb_api_days', array());
    $days = is_array($days) ? $days : array();
    foreach ($daysnew as $d => $v) {
        $days[$d] = array(round($v[0], 6), round($v[1], 6));
    }
    krsort($days);
    update_option('sb_api_days', array_slice($days, 0, 75, true), false);
    if (!sb_api_base()) {   // first good sync: this moment is where the provider-based bill starts
        $today = gmdate('Y-m-d');
        update_option('sb_api_base', array('t0' => time(), 'ym' => wp_date('Y-m'), 'day' => $today,
            'text0' => isset($daysnew[$today]) ? $daysnew[$today][0] : 0, 'image0' => isset($daysnew[$today]) ? $daysnew[$today][1] : 0), false);
    }
    update_option('sb_api_state', array('ok' => 1, 'at' => time(), 'msg' => ''), false);
    return array(true, 'সিঙ্ক হয়েছে');
}

function sb_api_fail($msg) {
    $old = get_option('sb_api_state', array());
    update_option('sb_api_state', array('ok' => 0, 'at' => isset($old['at']) ? (int) $old['at'] : 0, 'msg' => mb_substr($msg, 0, 300), 'try' => time()), false);
    return array(false, $msg);
}

/** Sum of billed dollars of ledger rows that came from other providers (Claude, Kimi): the OpenAI cost page does not contain them. */
function sb_ledger_other_bill($ym, $since = 0) {
    global $wpdb;
    list($a, $b) = sb_month_range($ym);
    $a = max($a, (int) $since);
    $t = sb_ledger_table();
    return (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(bill_usd) FROM {$t} WHERE t BETWEEN %d AND %d AND model <> '' AND model NOT LIKE %s AND model <> 'openai'", $a, $b, 'gpt%'));
}
