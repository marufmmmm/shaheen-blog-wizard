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

/** same article written differently (www or not, http/https, trailing slash, tracking query) */
function sb_url_variants($url) {
    $p = wp_parse_url($url);
    if (empty($p['host'])) {
        return array($url);
    }
    $h    = preg_replace('/^www\./i', '', strtolower($p['host']));
    $path = rtrim((string) ($p['path'] ?? ''), '/');
    $q    = isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '';
    $out  = array();
    foreach (array('https', 'http') as $sc) {
        foreach (array($h, 'www.' . $h) as $hh) {
            foreach (array($path, $path . '/') as $pp) {
                $out[] = $sc . '://' . $hh . $pp . $q;
                if ($q !== '') {
                    $out[] = $sc . '://' . $hh . $pp;
                }
            }
        }
    }
    return array_values(array_unique(array_merge(array($url), $out)));
}

function sb_press_url_exists($url) {
    $q = get_posts(array('post_type' => 'sb_post', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids',
        'meta_query' => array(array('key' => '_sb_press_url', 'value' => sb_url_variants($url), 'compare' => 'IN'))));
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
        $id = sb_press_make($v[0], $u, $v[1], (string) $row['pub'], (string) $row['d'], (string) $row['img'], $cat);
        if (!$id) {
            continue;
        }
        $img = (int) get_post_thumbnail_id($id);
        $done[] = array('id' => $id, 'title' => $v[0], 'img' => $img ? 1 : 0, 'pub' => $row['pub']);
    }
    $left = count($todo) - count($done) - count($skip);
    wp_send_json_success(array('done' => $done, 'skipped' => $skip, 'left' => max(0, $left), 'total' => count($cands)));
});


/** make one press card (blog post of type sb_post with a press link). Returns post id or 0. */
function sb_press_make($title, $url, $paper, $pub, $excerpt, $img_url, $cat) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $paper = trim(preg_replace('/\s*[·•].*$/u', '', (string) $paper));
    $arr   = array('post_type' => 'sb_post', 'post_title' => $title, 'post_status' => 'publish', 'post_author' => get_current_user_id() ?: 1,
        'post_excerpt' => mb_substr(sanitize_textarea_field((string) $excerpt), 0, 240));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $pub) && $pub <= wp_date('Y-m-d')) {
        $arr['post_date']     = $pub . ' 12:00:00';
        $arr['post_date_gmt'] = get_gmt_from_date($arr['post_date']);
    }
    $id = wp_insert_post(wp_slash($arr), true);
    if (is_wp_error($id) || !$id) {
        return 0;
    }
    wp_update_post(array('ID' => $id, 'post_name' => 'press-' . $id));
    update_post_meta($id, '_sb_press_slugged', 1);
    update_post_meta($id, '_sb_press_url', esc_url_raw($url));
    update_post_meta($id, '_sb_press_paper', $paper !== '' ? $paper : (string) wp_parse_url($url, PHP_URL_HOST));
    sb_seo_sync($id);
    sb_set_post_categories($id, (int) $cat, array());
    if ($img_url !== '') {
        $r = media_sideload_image($img_url, $id, $title, 'id');
        if (!is_wp_error($r)) {
            set_post_thumbnail($id, (int) $r);
        }
    }
    return (int) $id;
}
