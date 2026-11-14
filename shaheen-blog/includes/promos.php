<?php
/**
 * Promo cards: a designed HTML/CSS card (heading, short lead, up to 6 link tiles and an optional Facebook like button)
 * shown inside blog posts. Cards live in the library (option sb_promos). A card with "auto" shows in every post;
 * any other card is picked per post (post meta _sb_promo_ids). A post can switch all cards off (_sb_no_promo).
 */
if (!defined('ABSPATH')) {
    exit;
}

function sb_promos() {
    $p = get_option('sb_promos', array());
    return is_array($p) ? $p : array();
}

function sb_promo_clean($raw) {
    $out = array();
    if (!is_array($raw)) {
        return $out;
    }
    foreach (array_slice($raw, 0, 12) as $r) {
        if (!is_array($r)) {
            continue;
        }
        $items = array();
        foreach (array_slice(isset($r['items']) && is_array($r['items']) ? $r['items'] : array(), 0, 6) as $it) {
            $url = esc_url_raw(trim(isset($it['url']) ? (string) $it['url'] : ''), array('http', 'https'));
            $t   = sanitize_text_field(isset($it['t']) ? $it['t'] : '');
            if ($url && $t !== '') {
                $items[] = array('ic' => mb_substr(sanitize_text_field(isset($it['ic']) ? $it['ic'] : ''), 0, 4), 't' => mb_substr($t, 0, 60),
                    'd' => mb_substr(sanitize_text_field(isset($it['d']) ? $it['d'] : ''), 0, 110), 'url' => $url);
            }
        }
        if (!$items) {
            continue;
        }
        $out[] = array(
            'id'    => (!empty($r['id']) && preg_match('/^[a-z0-9]{3,20}$/', $r['id'])) ? $r['id'] : strtolower(wp_generate_password(8, false, false)),
            'name'  => sanitize_text_field(isset($r['name']) ? $r['name'] : ''),
            'title' => sanitize_text_field(isset($r['title']) ? $r['title'] : ''),
            'lead'  => mb_substr(sanitize_text_field(isset($r['lead']) ? $r['lead'] : ''), 0, 200),
            'items' => $items,
            'like'  => esc_url_raw(trim(isset($r['like']) ? (string) $r['like'] : ''), array('http', 'https')),
            'like_label' => sanitize_text_field(!empty($r['like_label']) ? $r['like_label'] : '👍 ফেসবুকে লাইক দিন'),
            'pos'   => sb_clean_pos(isset($r['pos']) ? $r['pos'] : 'middle'),
            'auto'  => !empty($r['auto']) ? 1 : 0,
            'match' => mb_substr(sanitize_text_field(isset($r['match']) ? $r['match'] : ''), 0, 120),
        );
    }
    return $out;
}

function sb_promo_html($p) {
    $h = '<aside class="sb-promo" aria-label="' . esc_attr($p['title'] !== '' ? $p['title'] : 'শাহীন শিক্ষা পরিবার') . '">';
    if ($p['title'] !== '') {
        $h .= '<div class="sb-promo-head">' . esc_html($p['title']) . '</div>';
    }
    if ($p['lead'] !== '') {
        $h .= '<p class="sb-promo-lead">' . esc_html($p['lead']) . '</p>';
    }
    $h .= '<div class="sb-promo-grid">';
    foreach ($p['items'] as $it) {
        $h .= '<a class="sb-promo-tile" href="' . esc_url($it['url']) . '" target="_blank" rel="noopener">'
            . ($it['ic'] !== '' ? '<span class="sb-promo-ic" aria-hidden="true">' . esc_html($it['ic']) . '</span>' : '')
            . '<span class="sb-promo-tx"><strong>' . esc_html($it['t']) . '</strong>' . ($it['d'] !== '' ? '<small>' . esc_html($it['d']) . '</small>' : '') . '</span>'
            . '<span class="sb-promo-go" aria-hidden="true">→</span></a>';
    }
    $h .= '</div>';
    if ($p['like'] !== '') {
        $h .= '<p class="sb-promo-foot"><a class="sb-promo-like" href="' . esc_url($p['like']) . '" target="_blank" rel="noopener">' . esc_html($p['like_label']) . '</a></p>';
    }
    return $h . '</aside>';
}

/** A card with "match" words (comma separated) shows by itself in posts about that topic: words in the title, English title, keywords, or 3+ times in the text. */
function sb_promo_matches($p, $post_id) {
    $m = isset($p['match']) ? trim((string) $p['match']) : '';
    if ($m === '' || !$post_id) {
        return false;
    }
    $kw = get_post_meta($post_id, '_sb_keywords', true);
    $head = mb_strtolower(get_the_title($post_id) . ' ' . get_post_meta($post_id, '_sb_en_title', true) . ' ' . (is_array($kw) ? implode(' ', $kw) : (string) $kw));
    $body = mb_strtolower(wp_strip_all_tags((string) get_post_field('post_content', $post_id)));
    foreach (array_filter(array_map('trim', preg_split('/[,،\n]+/u', mb_strtolower($m)))) as $w) {
        if (mb_strpos($head, $w) !== false || mb_substr_count($body, $w) >= 3) {
            return true;
        }
    }
    return false;
}

/** [[pos, html], ...] for one post */
function sb_promo_items($post_id) {
    if ($post_id && get_post_meta($post_id, '_sb_no_promo', true)) {
        return array();
    }
    $picked = $post_id ? get_post_meta($post_id, '_sb_promo_ids', true) : array();
    $picked = is_array($picked) ? $picked : array();
    $out = array();
    foreach (sb_promos() as $p) {
        if (!empty($p['auto']) || in_array($p['id'], $picked, true) || sb_promo_matches($p, $post_id)) {
            $out[] = array($p['pos'], sb_promo_html($p));
        }
    }
    return $out;
}
