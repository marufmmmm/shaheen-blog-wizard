<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings PIN. The PIN itself is not stored in the plugin; only a salted hash is.
 * Hint shown on the lock screen: 85.
 */
class SB_Lock {
    const HASH = '70987b803482b237707de9f51f71d770703fcb7840ec7608b7cbad74c4c6b456';
    const HINT = '85';
    const META = '_sb_unlock_until';
    const MINUTES = 30;

    public static function hash($pin) {
        return hash('sha256', 'shaheen-blog|' . $pin);
    }

    public static function is_unlocked($user_id = 0) {
        $user_id = $user_id ? $user_id : get_current_user_id();
        return (int) get_user_meta($user_id, self::META, true) > time();
    }

    public static function try_unlock($pin) {
        $uid = get_current_user_id();
        $fails_key = 'sb_pin_fail_' . $uid;
        if ((int) get_transient($fails_key) >= 5) {
            return new WP_Error('sb_locked', 'অনেকবার ভুল PIN। ১৫ মিনিট পর আবার চেষ্টা করুন।');
        }
        if (hash_equals(self::HASH, self::hash(trim((string) $pin)))) {
            delete_transient($fails_key);
            update_user_meta($uid, self::META, time() + self::MINUTES * 60);
            return true;
        }
        set_transient($fails_key, (int) get_transient($fails_key) + 1, 15 * MINUTE_IN_SECONDS);
        return new WP_Error('sb_pin', 'PIN সঠিক নয়। হিন্ট: ' . self::HINT);
    }

    public static function lock() {
        delete_user_meta(get_current_user_id(), self::META);
    }
}

add_action('wp_ajax_sb_unlock', function () {
    sb_ajax_guard('manage_options');
    $r = SB_Lock::try_unlock(sb_req('pin'));
    if (is_wp_error($r)) {
        wp_send_json_error(array('message' => $r->get_error_message()));
    }
    wp_send_json_success();
});
add_action('wp_ajax_sb_lock', function () {
    sb_ajax_guard('manage_options');
    SB_Lock::lock();
    wp_send_json_success();
});
