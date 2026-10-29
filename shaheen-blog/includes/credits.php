<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-user credit balance. Resets every month on the user's subscription date.
 *
 * Payment is NOT built in. When the subscription code is ready, call:
 *
 *     shaheen_blog_set_subscription( $user_id, '2026-10-20', 20000 );
 *
 * That starts a new cycle on that date (used credits = 0) with that credit limit.
 */
class SB_Credits {

    const META_START  = '_sb_cycle_start';   // Y-m-d, start of current cycle
    const META_ANCHOR = '_sb_anchor_day';    // day of month the subscription renews on
    const META_USED   = '_sb_credit_used';
    const META_LIMIT  = '_sb_credit_limit';
    const META_LOG    = '_sb_credit_log';

    public static function today() {
        return wp_date('Y-m-d');
    }

    public static function limit($user_id) {
        $v = get_user_meta($user_id, self::META_LIMIT, true);
        $limit = ($v !== '' && $v !== false) ? (int) $v : sb_limit_credits_from_usd(sb_get('limit_usd'));
        return max(0, (int) apply_filters('shaheen_blog_credit_limit', $limit, $user_id));
    }

    /** Add one month to a Y-m-d date, keeping the anchor day (clamped to month length). */
    public static function add_month($ymd, $anchor_day) {
        list($y, $m) = array_map('intval', explode('-', $ymd));
        $m++;
        if ($m > 12) {
            $m = 1;
            $y++;
        }
        $days_in_month = (int) gmdate('t', gmmktime(0, 0, 0, $m, 1, $y));
        $d = min(max(1, (int) $anchor_day), $days_in_month);
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /** Make sure a cycle exists and roll it forward if its month has passed. */
    protected static function sync($user_id) {
        $start = get_user_meta($user_id, self::META_START, true);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $start)) {
            $start = self::today();
            update_user_meta($user_id, self::META_START, $start);
            update_user_meta($user_id, self::META_ANCHOR, (int) substr($start, 8, 2));
            update_user_meta($user_id, self::META_USED, 0);
        }
        $anchor = (int) get_user_meta($user_id, self::META_ANCHOR, true);
        if ($anchor < 1) {
            $anchor = (int) substr($start, 8, 2);
            update_user_meta($user_id, self::META_ANCHOR, $anchor);
        }

        $today = self::today();
        $next  = self::add_month($start, $anchor);
        $rolled = false;
        $guard = 0;
        while ($today >= $next && $guard++ < 240) {
            $start  = $next;
            $next   = self::add_month($start, $anchor);
            $rolled = true;
        }
        if ($rolled) {
            update_user_meta($user_id, self::META_START, $start);
            update_user_meta($user_id, self::META_USED, 0);
        }
        return array($start, $next);
    }

    public static function state($user_id) {
        list($start, $next) = self::sync($user_id);
        $used  = max(0, (int) get_user_meta($user_id, self::META_USED, true));
        $limit = self::limit($user_id);
        $unl = ($limit <= 0);   // no limit: the client pays for what is used
        $remaining = $unl ? 1000000000 : max(0, $limit - $used);
        return array(
            'limit'       => $limit,
            'used'        => $used,
            'remaining'   => $remaining,
            'unit'        => sb_unit_billed(),
            'unlimited'   => $unl,
            'limit_usd'   => $unl ? 'Unlimited' : sb_fmt_usd(sb_usd($limit)),
            'used_usd'    => sb_fmt_usd(sb_usd($used)),
            'remaining_usd' => $unl ? sb_fmt_usd(sb_usd($used)) : sb_fmt_usd(sb_usd($remaining)),
            'percent'     => $unl ? 0 : min(100, round($used * 100 / $limit, 1)),
            'cycle_start' => $start,
            'next_reset'  => $next,
            'next_reset_bn' => sb_bn_date(strtotime($next . ' 12:00:00 ' . wp_timezone_string())),
        );
    }

    /** Charge credits. Returns the new state, or WP_Error when not enough credits. */
    public static function charge($user_id, $feature, $cost, $post_id = 0) {
        $cost = max(0, (int) $cost);
        $st = self::state($user_id);
        if ($st['remaining'] < $cost) {
            return new WP_Error('sb_no_credits', 'ব্যালেন্স যথেষ্ট নয়। প্রয়োজন ' . sb_money($cost) . ', আছে ' . sb_money($st['remaining']) . '।');
        }
        update_user_meta($user_id, self::META_USED, $st['used'] + $cost);
        $real = isset($GLOBALS['sb_real_charge']) ? (float) $GLOBALS['sb_real_charge'] : null;
        unset($GLOBALS['sb_real_charge']);
        if ($real === null) {
            $mv = sb_meter_take();
            $real = $mv > 0 ? $mv : null;
        }
        sb_ledger_add($user_id, $feature, $cost, $post_id, $real);

        $log = get_user_meta($user_id, self::META_LOG, true);
        if (!is_array($log)) {
            $log = array();
        }
        array_unshift($log, array(
            't' => time(), 'feature' => $feature, 'cost' => $cost, 'post' => (int) $post_id,
        ));
        update_user_meta($user_id, self::META_LOG, array_slice($log, 0, 60));

        return self::state($user_id);
    }

    public static function log($user_id, $limit = 10) {
        $log = get_user_meta($user_id, self::META_LOG, true);
        return is_array($log) ? array_slice($log, 0, $limit) : array();
    }

    /** Start a new subscription cycle. $start_date is Y-m-d (default today). */
    public static function set_subscription($user_id, $start_date = null, $limit = null) {
        $start_date = $start_date ? $start_date : self::today();
        $dt = DateTime::createFromFormat('Y-m-d', $start_date);
        if (!$dt || $dt->format('Y-m-d') !== $start_date) {
            return new WP_Error('sb_bad_date', 'তারিখ YYYY-MM-DD ফরম্যাটে দিন।');
        }
        update_user_meta($user_id, self::META_START, $start_date);
        update_user_meta($user_id, self::META_ANCHOR, (int) $dt->format('j'));
        update_user_meta($user_id, self::META_USED, 0);
        if ($limit !== null && $limit !== '') {
            update_user_meta($user_id, self::META_LIMIT, max(0, (int) $limit));
        }
        do_action('shaheen_blog_subscription_set', $user_id, $start_date, $limit);
        return self::state($user_id);
    }
}

/** Public hook for the subscription/payment code. */
function shaheen_blog_set_subscription($user_id, $start_date = null, $limit = null) {
    return SB_Credits::set_subscription((int) $user_id, $start_date, $limit);
}
