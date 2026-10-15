<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Link / ad blocks shown inside posts: text, button, text + button, or clickable text.
 * Global blocks (option sb_global_blocks) show in every post; each post can add its own.
 */
function sb_clean_blocks($raw, $max = 8) {
    $out = array();
    if (!is_array($raw)) {
        return $out;
    }
    foreach (array_slice($raw, 0, $max) as $b) {
        if (!is_array($b)) {
            continue;
        }
        $url = esc_url_raw(trim(isset($b['url']) ? $b['url'] : ''), array('http', 'https', 'tel', 'mailto'));
        $pos = isset($b['pos']) ? $b['pos'] : 'middle';
        $clean = array(
            'on'        => !empty($b['on']) ? 1 : 0,
            'show_text' => !empty($b['show_text']) ? 1 : 0,
            'show_btn'  => !empty($b['show_btn']) ? 1 : 0,
            'link_text' => !empty($b['link_text']) ? 1 : 0,
            'new_tab'   => !empty($b['new_tab']) ? 1 : 0,
            'label'     => !empty($b['label']) ? 1 : 0,
            'text'      => sanitize_text_field(isset($b['text']) ? $b['text'] : ''),
            'btn'       => sanitize_text_field(isset($b['btn']) ? $b['btn'] : ''),
            'url'       => $url,
            'pos'       => in_array($pos, array('start', 'middle', 'end'), true) ? $pos : 'middle',
            'name'      => sanitize_text_field(isset($b['name']) ? $b['name'] : ''),
            'auto'      => !empty($b['auto']) ? 1 : 0,
            'id'        => (isset($b['id']) && preg_match('/^[a-z0-9]{6,20}$/', $b['id'])) ? $b['id'] : strtolower(wp_generate_password(10, false, false)),
        );
        if (!$clean['show_text'] && !$clean['show_btn']) {
            continue;
        }
        $out[] = $clean;
    }
    return $out;
}

function sb_render_block($b) {
    if (empty($b['on'])) {
        return '';
    }
    $url = $b['url'];
    $target = !empty($b['new_tab']) ? ' target="_blank" rel="noopener nofollow sponsored"' : '';
    $html = '';
    if (!empty($b['show_text']) && $b['text'] !== '') {
        $t = esc_html($b['text']);
        if (!empty($b['link_text']) && $url) {
            $t = '<a class="sb-ad-link" href="' . esc_url($url) . '"' . $target . '>' . $t . '</a>';
        }
        $html .= '<p class="sb-ad-text">' . $t . '</p>';
    }
    if (!empty($b['show_btn']) && $b['btn'] !== '' && $url) {
        $html .= '<p class="sb-ad-actions"><a class="sb-ad-btn" href="' . esc_url($url) . '"' . $target . '>' . esc_html($b['btn']) . '</a></p>';
    }
    if ($html === '') {
        return '';
    }
    $label = !empty($b['label']) ? '<span class="sb-ad-tag">বিজ্ঞাপন</span>' : '';
    return '<aside class="sb-ad">' . $label . $html . '</aside>';
}

/** Put link blocks, the social box and the post's extras (PDF, downloads, embeds) into formatted HTML. */
function sb_inject_blocks($html, array $blocks, $post_id = 0) {
    $ex = $post_id ? sb_get_extras($post_id) : array('refs' => array(), 'social_pos' => '');
    $html = sb_link_refs($html, $ex['refs']);
    $map = array('start' => 'p:1', 'middle' => 'middle', 'end' => 'end');
    $items = array();

    $soc = sb_social_get();
    $sp = $ex['social_pos'] !== '' ? $ex['social_pos'] : (isset($map[$soc['pos']]) ? $map[$soc['pos']] : 'off');
    if ($sp !== 'off' && !($post_id && get_post_meta($post_id, '_sb_no_social', true))) {
        $items[] = array($sp, sb_social_html());
    }
    foreach ($blocks as $b) {
        $items[] = array(isset($map[$b['pos']]) ? $map[$b['pos']] : 'middle', sb_render_block($b));
    }
    if ($post_id) {
        $items = array_merge($items, sb_extras_items($post_id));
    }
    return sb_inject_at($html, $items);
}

/** The saved block library (each block has id, name, auto). Older saves get ids/auto filled in. */
function sb_block_library() {
    $g = get_option('sb_global_blocks', array());
    if (!is_array($g)) {
        return array();
    }
    foreach ($g as $i => $b) {
        if (empty($b['id'])) {
            $g[$i]['id'] = strtolower(wp_generate_password(10, false, false));
            $g[$i]['auto'] = 1;
            $g[$i]['name'] = isset($b['name']) && $b['name'] !== '' ? $b['name'] : 'ব্লক ' . ($i + 1);
        }
    }
    return $g;
}

/** Blocks for one post: library blocks ticked for it, auto ones, and the post's own blocks. */
function sb_post_blocks($post_id) {
    $picked = get_post_meta($post_id, '_sb_block_ids', true);
    $picked = is_array($picked) ? $picked : array();
    $skip_auto = (bool) get_post_meta($post_id, '_sb_no_global', true);
    $out = array();
    foreach (sb_block_library() as $b) {
        if (in_array($b['id'], $picked, true) || (!$skip_auto && !empty($b['auto']))) {
            $out[] = $b;
        }
    }
    $mine = get_post_meta($post_id, '_sb_blocks', true);
    return array_merge($out, is_array($mine) ? $mine : array());
}

/* ----- saving global blocks (admin page) ----- */
add_action('wp_ajax_sb_save_global_blocks', function () {
    sb_ajax_guard('manage_options');
    $raw = isset($_POST['blocks']) ? wp_unslash($_POST['blocks']) : array();
    update_option('sb_global_blocks', sb_clean_blocks($raw, 10), false);
    wp_send_json_success();
});
