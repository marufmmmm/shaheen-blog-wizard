<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import: copies the site's existing WordPress posts (or pages) into the blog so they can be edited,
 * rewritten and shown in the blog design. The original is never changed unless the owner ticks that box.
 */

/** original post id => blog post id, for everything already imported. */
function sb_imported_map() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sb_imported_from'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $map = array();
    foreach ((array) $rows as $r) {
        if (get_post_type((int) $r->post_id) === 'sb_post' && get_post_status((int) $r->post_id) !== 'trash') {
            $map[(int) $r->meta_value] = (int) $r->post_id;
        }
    }
    return $map;
}

function sb_import_clean_content($html) {
    $html = preg_replace('/<!--\s*\/?wp:[^>]*-->\s*/', '', (string) $html);
    return trim($html);
}

/** Finds (or creates) the blog category that matches an old WordPress category. */
function sb_import_term($old) {
    if (in_array($old->slug, array('uncategorized'), true) || $old->name === 'Uncategorized') {
        return 0;
    }
    $hit = get_term_by('name', $old->name, 'sb_category');
    if (!$hit) {
        $hit = get_term_by('slug', $old->slug, 'sb_category');
    }
    if ($hit) {
        return (int) $hit->term_id;
    }
    $r = wp_insert_term($old->name, 'sb_category');
    return is_wp_error($r) ? 0 : (int) $r['term_id'];
}

/** @return array(ok, id, message) */
function sb_import_one($orig_id, $status, $hide_orig) {
    $p = get_post($orig_id);
    if (!$p || !in_array($p->post_type, array('post', 'page'), true)) {
        return array('ok' => false, 'message' => 'পোস্ট পাওয়া যায়নি');
    }
    if (!current_user_can('edit_post', $orig_id)) {
        return array('ok' => false, 'message' => 'এই পোস্ট এডিটের অনুমতি নেই');
    }
    $map = sb_imported_map();
    if (isset($map[$orig_id])) {
        return array('ok' => true, 'id' => $map[$orig_id], 'message' => 'আগেই ইম্পোর্ট করা আছে', 'already' => true);
    }
    $content = sb_import_clean_content($p->post_content);
    if (trim(wp_strip_all_tags($content)) === '') {
        $why = get_post_meta($orig_id, '_elementor_data', true) ? 'এটি Elementor/পেজ-বিল্ডারে বানানো, লেখা কপি করা যায়নি' : 'লেখা ফাঁকা';
        return array('ok' => false, 'message' => $why);
    }
    $new = wp_insert_post(array(
        'post_type'    => 'sb_post',
        'post_status'  => $status,
        'post_title'   => $p->post_title,
        'post_content' => $content,
        'post_excerpt' => $p->post_excerpt,
        'post_name'    => $p->post_name,
        'post_author'  => $p->post_author,
        'post_date'    => $p->post_date,
        'post_date_gmt' => $p->post_date_gmt,
    ), true);
    if (is_wp_error($new) || !$new) {
        return array('ok' => false, 'message' => is_wp_error($new) ? $new->get_error_message() : 'তৈরি করা যায়নি');
    }
    update_post_meta($new, '_sb_imported_from', $orig_id);
    $thumb = get_post_thumbnail_id($orig_id);
    if ($thumb) {
        set_post_thumbnail($new, $thumb);
    }
    // categories: all old ones, the first becomes the main category
    $ids = array();
    $olds = get_the_terms($orig_id, 'category');
    if (is_array($olds)) {
        foreach ($olds as $o) {
            $t = sb_import_term($o);
            if ($t && !in_array($t, $ids, true)) {
                $ids[] = $t;
            }
        }
    }
    sb_set_post_categories($new, $ids ? $ids[0] : 0, array_slice($ids, 1));
    // keywords from tags
    $tags = wp_get_post_tags($orig_id, array('fields' => 'names'));
    if (!empty($tags) && !is_wp_error($tags)) {
        update_post_meta($new, '_sb_keywords', array_slice(array_map('sanitize_text_field', $tags), 0, 10));
    }
    // meta description from the common SEO plugins
    foreach (array('_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description') as $k) {
        $d = get_post_meta($orig_id, $k, true);
        if (is_string($d) && trim($d) !== '') {
            update_post_meta($new, '_sb_meta_desc', sanitize_textarea_field($d));
            break;
        }
    }
    sb_init_views($new);
    $hid = false;
    if ($hide_orig && $p->post_status === 'publish') {
        wp_update_post(array('ID' => $orig_id, 'post_status' => 'draft'));
        update_post_meta($orig_id, '_sb_import_hidden', 1);
        $hid = true;
    }
    return array('ok' => true, 'id' => (int) $new, 'message' => $hid ? 'ইম্পোর্ট হয়েছে (মূল পোস্ট খসড়ায় নেওয়া হয়েছে)' : 'ইম্পোর্ট হয়েছে');
}

add_action('wp_ajax_sb_import_run', 'sb_ajax_import_run');
function sb_ajax_import_run() {
    sb_ajax_guard();
    @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    $ids = array_slice(array_map('intval', sb_req_list('ids', 40)), 0, 40);
    $status = sb_req('status') === 'publish' && current_user_can('publish_posts') ? 'publish' : 'draft';
    $hide = sb_req('hide') === '1';
    $out = array();
    foreach ($ids as $id) {
        $r = sb_import_one($id, $status, $hide);
        $r['orig'] = $id;
        $r['title'] = get_the_title($id);
        if (!empty($r['id'])) {
            $r['edit'] = admin_url('admin.php?page=sb-new&post=' . (int) $r['id']);
        }
        $out[] = $r;
    }
    wp_send_json_success(array('results' => $out));
}

function sb_page_import() {
    include SB_PATH . 'views/import.php';
}
