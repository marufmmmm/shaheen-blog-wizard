<?php
/**
 * Bring the news links of an event page (e.g. Spelling Star "সংবাদে" section) into the blog's "📰 সংবাদপত্রে শাহীন" list.
 * Each link becomes one press card: title, paper, real publish date and a short description read from the article page,
 * and the article's own cover picture saved to the media library. Links already in the list are skipped.
 */
if (!defined('ABSPATH')) {
    exit;
}

/** news lines of all event pages: [url => [title, paper]] (videos are not news) */
function sb_press_import_candidates() {
    $out = array();
    if (!function_exists('csa_ev_list')) {
        return $out;
    }
    foreach ((array) csa_ev_list() as $e) {
        foreach ((array) ($e['vsec'] ?? array()) as $vs) {
            foreach (preg_split('/\r?\n/', (string) ($vs['lines'] ?? '')) as $ln) {
                $pt = array_map('trim', explode('|', $ln));
                $u  = $pt[1] ?? '';
                if ($pt[0] !== '' && preg_match('#^https?://#i', $u) && !csa_ev_yt($u) && !csa_ev_is_fb($u)) {
                    $out[$u] = array($pt[0], $pt[2] ?? '');
                }
            }
        }
    }
    return $out;
}

function sb_press_url_exists($url) {
    $q = get_posts(array('post_type' => 'sb_post', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids',
        'meta_key' => '_sb_press_url', 'meta_value' => $url));
    return !empty($q);
}

add_action('wp_ajax_sb_press_import', function () {
    sb_ajax_guard();
    $cat   = (int) sb_req('category', 0);
    $cands = sb_press_import_candidates();
    $todo  = array();
    foreach ($cands as $u => $v) {
        if (!sb_press_url_exists($u)) {
            $todo[$u] = $v;
        }
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $done = array();
    $skip = array();
    foreach (array_slice($todo, 0, 3, true) as $u => $v) {
        $row = function_exists('csa_ev_og_row') ? csa_ev_og_row($u) : null;
        if (!$row || !isset($row['code']) || (empty($row['ok']) && time() - (int) ($row['at'] ?? 0) > 3600)) {
            $row = csa_ev_og_fetch($u);
        }
        if (empty($row['ok'])) { // no readable article behind the link: not news, leave it out
            $skip[] = array('u' => $u, 'code' => (int) ($row['code'] ?? 0));
            update_option('sb_press_skip', array_slice(array_unique(array_merge((array) get_option('sb_press_skip', array()), array($u))), -200), false);
            continue;
        }
        $paper = trim(preg_replace('/\s*[·•].*$/u', '', $v[1]));
        $arr   = array('post_type' => 'sb_post', 'post_title' => $v[0], 'post_status' => 'publish', 'post_author' => get_current_user_id(),
            'post_excerpt' => mb_substr((string) $row['d'], 0, 240));
        if (!empty($row['pub'])) {
            $arr['post_date']     = $row['pub'] . ' 12:00:00';
            $arr['post_date_gmt'] = get_gmt_from_date($arr['post_date']);
        }
        $id = wp_insert_post(wp_slash($arr), true);
        if (is_wp_error($id)) {
            continue;
        }
        wp_update_post(array('ID' => $id, 'post_name' => 'press-' . $id));
        update_post_meta($id, '_sb_press_slugged', 1);
        update_post_meta($id, '_sb_press_url', esc_url_raw($u));
        update_post_meta($id, '_sb_press_paper', $paper !== '' ? $paper : wp_parse_url($u, PHP_URL_HOST));
        sb_seo_sync($id);
        sb_set_post_categories($id, $cat, array());
        $img = 0;
        if (!empty($row['img'])) {
            $r = media_sideload_image($row['img'], $id, $v[0], 'id');
            if (!is_wp_error($r)) {
                $img = (int) $r;
                set_post_thumbnail($id, $img);
            }
        }
        $done[] = array('id' => $id, 'title' => $v[0], 'img' => $img ? 1 : 0, 'pub' => $row['pub']);
    }
    $left = count($todo) - count($done) - count($skip);
    wp_send_json_success(array('done' => $done, 'skipped' => $skip, 'left' => max(0, $left), 'total' => count($cands)));
});
