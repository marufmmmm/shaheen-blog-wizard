<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Every AJAX call: nonce + capability. */
function sb_ajax_guard($cap = 'edit_posts') {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can($cap)) {
        wp_send_json_error(array('message' => 'এই কাজের অনুমতি আপনার নেই।'), 403);
    }
}

function sb_req($key, $default = '') {
    return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default;
}

function sb_req_list($key, $max = 10) {
    $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : array();
    if (!is_array($raw)) {
        return array();
    }
    $out = array();
    foreach ($raw as $v) {
        $v = sanitize_text_field(is_scalar($v) ? $v : '');
        if ($v !== '') {
            $out[] = $v;
        }
    }
    return array_slice(array_values(array_unique($out)), 0, $max);
}

/* ---------- AI generation (costs credits) ---------- */
add_action('wp_ajax_sb_ai', 'sb_ajax_ai');
function sb_ajax_ai() {
    sb_ajax_guard();
    $feature = sanitize_key(sb_req('feature'));
    $cost = sb_feature_cost($feature);
    if (!$cost) {
        wp_send_json_error(array('message' => 'অজানা AI ফিচার।'));
    }
    $uid = get_current_user_id();
    $state = SB_Credits::state($uid);
    if ($state['remaining'] < $cost) {
        wp_send_json_error(array(
            'message' => 'ক্রেডিট যথেষ্ট নয়। প্রয়োজন ' . sb_bn_digits($cost) . ', আছে ' . sb_bn_digits($state['remaining']) . '। রিসেট: ' . $state['next_reset_bn'],
            'balance' => $state,
        ));
    }

    $ctx = array(
        'title'    => sanitize_text_field(sb_req('title')),
        'topic'    => sanitize_text_field(sb_req('topic')),
        'excerpt'  => sanitize_textarea_field(sb_req('excerpt')),
        'content'  => wp_kses_post(sb_req('content')),
        'keywords' => sb_req_list('keywords', 10),
        'length'   => sanitize_key(sb_req('length', 'medium')),
    );
    $result = SB_AI::generate($feature, $ctx);
    if (is_wp_error($result)) {
        // Nothing was charged: the call failed.
        wp_send_json_error(array('message' => $result->get_error_message(), 'balance' => $state));
    }
    $post_id = (int) sb_req('post_id', 0);
    $new = SB_Credits::charge($uid, $feature, $cost, $post_id);
    if (is_wp_error($new)) {
        wp_send_json_error(array('message' => $new->get_error_message(), 'balance' => $state));
    }
    wp_send_json_success(array('result' => $result, 'cost' => $cost, 'balance' => $new));
}

/* ---------- Save post (free: editing never costs credits) ---------- */
add_action('wp_ajax_sb_save_post', 'sb_ajax_save_post');
function sb_ajax_save_post() {
    sb_ajax_guard();
    $id = (int) sb_req('id', 0);
    if ($id) {
        if (get_post_type($id) !== 'sb_post' || !current_user_can('edit_post', $id)) {
            wp_send_json_error(array('message' => 'এই পোস্ট এডিট করার অনুমতি নেই।'), 403);
        }
    }
    $title = sanitize_text_field(sb_req('title'));
    if ($title === '') {
        wp_send_json_error(array('message' => 'শিরোনাম লিখুন।'));
    }

    $status = sb_req('status', 'draft');
    if (!in_array($status, array('publish', 'draft'), true)) {
        $status = 'draft';
    }
    if ($status === 'publish' && !current_user_can('publish_posts')) {
        $status = 'pending';
    }

    $postarr = array(
        'post_type'    => 'sb_post',
        'post_title'   => $title,
        'post_excerpt' => sanitize_textarea_field(sb_req('excerpt')),
        'post_content' => wp_kses_post(sb_req('content')),
        'post_status'  => $status,
    );
    $date = sanitize_text_field(sb_req('date'));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $postarr['post_date']     = $date . ' ' . wp_date('H:i:s');
        $postarr['post_date_gmt'] = get_gmt_from_date($postarr['post_date']);
    }
    if ($id) {
        $postarr['ID'] = $id;
        $res = wp_update_post(wp_slash($postarr), true);
    } else {
        $postarr['post_author'] = get_current_user_id();
        $res = wp_insert_post(wp_slash($postarr), true);
    }
    if (is_wp_error($res)) {
        wp_send_json_error(array('message' => $res->get_error_message()));
    }
    $is_new = empty($postarr['ID']);
    $id = (int) $res;

    // Short, shareable link: custom slug if given, else post-{id} for new posts.
    $slug = sanitize_title(sb_req('slug'));
    if ($slug !== '') {
        wp_update_post(array('ID' => $id, 'post_name' => $slug));
    } elseif ($is_new) {
        wp_update_post(array('ID' => $id, 'post_name' => 'post-' . $id));
    }

    update_post_meta($id, '_sb_subtitle', sanitize_text_field(sb_req('subtitle')));
    update_post_meta($id, '_sb_meta_desc', sanitize_textarea_field(sb_req('meta_desc')));
    update_post_meta($id, '_sb_summary', sanitize_textarea_field(sb_req('summary')));
    update_post_meta($id, '_sb_author_name', sanitize_text_field(sb_req('author_name')));
    update_post_meta($id, '_sb_keywords', sb_req_list('keywords', 10));
    update_post_meta($id, '_sb_topic', sanitize_text_field(sb_req('topic')));

    $faq = array();
    $faq_raw = isset($_POST['faq']) ? wp_unslash($_POST['faq']) : array();
    if (is_array($faq_raw)) {
        foreach (array_slice($faq_raw, 0, 12) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $q = sanitize_text_field(isset($row['q']) ? $row['q'] : '');
            $a = sanitize_textarea_field(isset($row['a']) ? $row['a'] : '');
            if ($q !== '' && $a !== '') {
                $faq[] = array('q' => $q, 'a' => $a);
            }
        }
    }
    update_post_meta($id, '_sb_faq', $faq);

    $term = (int) sb_req('category', 0);
    if ($term && term_exists($term, 'sb_category')) {
        wp_set_object_terms($id, array($term), 'sb_category');
    } else {
        wp_set_object_terms($id, array(), 'sb_category');
    }

    $img = (int) sb_req('image_id', 0);
    if ($img && get_post_type($img) === 'attachment' && current_user_can('edit_post', $img)) {
        set_post_thumbnail($id, $img);
    } elseif (!$img) {
        delete_post_thumbnail($id);
    }

    wp_send_json_success(array(
        'id'     => $id,
        'status' => get_post_status($id),
        'url'    => get_permalink($id),
        'edit'   => admin_url('admin.php?page=sb-new&post=' . $id),
    ));
}

/* ---------- Move to trash ---------- */
add_action('wp_ajax_sb_trash_post', 'sb_ajax_trash_post');
function sb_ajax_trash_post() {
    sb_ajax_guard();
    $id = (int) sb_req('id', 0);
    if (!$id || get_post_type($id) !== 'sb_post' || !current_user_can('delete_post', $id)) {
        wp_send_json_error(array('message' => 'এই পোস্ট মুছার অনুমতি নেই।'), 403);
    }
    wp_trash_post($id);
    wp_send_json_success();
}

/* ---------- Add category ---------- */
add_action('wp_ajax_sb_add_category', 'sb_ajax_add_category');
function sb_ajax_add_category() {
    sb_ajax_guard('manage_categories');
    $bn = sanitize_text_field(sb_req('name_bn'));
    $en = sanitize_text_field(sb_req('name_en'));
    if ($bn === '') {
        wp_send_json_error(array('message' => 'বাংলা নাম লিখুন।'));
    }
    $slug = sanitize_title($en !== '' ? $en : $bn);
    $t = wp_insert_term($bn, 'sb_category', array('slug' => $slug));
    if (is_wp_error($t)) {
        wp_send_json_error(array('message' => $t->get_error_message()));
    }
    update_term_meta($t['term_id'], 'sb_name_en', $en);
    wp_send_json_success(array('id' => (int) $t['term_id'], 'name' => $bn, 'name_en' => $en));
}

add_action('wp_ajax_sb_delete_category', 'sb_ajax_delete_category');
function sb_ajax_delete_category() {
    sb_ajax_guard('manage_categories');
    $id = (int) sb_req('id', 0);
    $term = get_term($id, 'sb_category');
    if (!$term || is_wp_error($term)) {
        wp_send_json_error(array('message' => 'ক্যাটাগরি পাওয়া যায়নি।'));
    }
    wp_delete_term($id, 'sb_category');
    wp_send_json_success();
}

/* ---------- Admin-only tools ---------- */
add_action('wp_ajax_sb_delete_demo', 'sb_ajax_delete_demo');
function sb_ajax_delete_demo() {
    sb_ajax_guard('manage_options');
    if (!SB_Lock::is_unlocked()) {
        wp_send_json_error(array('message' => 'আগে সেটিংসে PIN দিয়ে আনলক করুন।'), 403);
    }
    $ids = get_posts(array(
        'post_type' => 'sb_post', 'post_status' => 'any', 'numberposts' => -1,
        'fields' => 'ids', 'meta_key' => '_sb_demo', 'meta_value' => '1',
    ));
    foreach ($ids as $id) {
        wp_delete_post($id, true);
    }
    wp_send_json_success(array('deleted' => count($ids)));
}

add_action('wp_ajax_sb_set_subscription', 'sb_ajax_set_subscription');
function sb_ajax_set_subscription() {
    sb_ajax_guard('manage_options');
    if (!SB_Lock::is_unlocked()) {
        wp_send_json_error(array('message' => 'আগে সেটিংসে PIN দিয়ে আনলক করুন।'), 403);
    }
    $uid = (int) sb_req('user_id', 0);
    if (!$uid || !get_userdata($uid)) {
        wp_send_json_error(array('message' => 'ইউজার পাওয়া যায়নি।'));
    }
    $date  = sanitize_text_field(sb_req('start_date'));
    $limit = sb_req('limit', '');
    $st = SB_Credits::set_subscription($uid, $date !== '' ? $date : null, $limit !== '' ? (int) $limit : null);
    if (is_wp_error($st)) {
        wp_send_json_error(array('message' => $st->get_error_message()));
    }
    wp_send_json_success(array('balance' => $st));
}

add_action('wp_ajax_sb_balance', 'sb_ajax_balance');
function sb_ajax_balance() {
    sb_ajax_guard();
    wp_send_json_success(array('balance' => SB_Credits::state(get_current_user_id())));
}
