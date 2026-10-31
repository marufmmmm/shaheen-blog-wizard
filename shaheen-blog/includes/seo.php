<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * One SEO story for blog posts.
 *
 * Yoast SEO active  -> Yoast prints every tag. We only feed it: the description, focus keyword, SEO title are written into
 *                      Yoast's own fields on save, and filters give old posts (and empty fields) our values. We print no duplicate tags.
 * No SEO plugin     -> we print description, Open Graph, Twitter, article dates and Article JSON-LD ourselves.
 * Either way        -> FAQ JSON-LD, news-link posts kept out of search and sitemaps.
 */

function sb_seo_yoast() {
    return defined('WPSEO_VERSION');
}

/** Description for search results: 120-155 characters ideal; never empty, never longer than 160. */
function sb_meta_fit($s) {
    $s = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $s)));
    if (mb_strlen($s) > 160) {
        $cut = mb_substr($s, 0, 157);
        $sp  = mb_strrpos($cut, ' ');
        $s   = rtrim($sp !== false && $sp > 100 ? mb_substr($cut, 0, $sp) : $cut, " ,;:-–—") . '…';
    }
    return $s;
}

function sb_post_desc($id) {
    $d = trim((string) get_post_meta($id, '_sb_meta_desc', true));
    if ($d === '') {
        $d = trim((string) get_post_field('post_excerpt', $id));
    }
    if ($d === '') {
        $d = trim((string) get_post_meta($id, '_sb_summary', true));
    }
    if ($d === '') {
        $d = mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes((string) get_post_field('post_content', $id))))), 0, 400);
    }
    return sb_meta_fit($d);
}

function sb_post_focus($id) {
    $k = get_post_meta($id, '_sb_keywords', true);
    $k = is_array($k) ? array_values(array_filter($k)) : array();
    return $k ? (string) $k[0] : '';
}

/** English-letter slug from the keywords or the title; '' when there is nothing Latin to use (Bangla cannot be turned into a clean slug here). */
function sb_slug_guess($title, $keywords) {
    foreach (array_merge((array) $keywords, array($title)) as $c) {
        if (preg_match('/[A-Za-z]{3,}/', (string) $c)) {
            $s = sanitize_title(remove_accents((string) $c));
            if (strlen($s) >= 3 && preg_match('/^[a-z0-9-]+$/', $s)) {
                return substr($s, 0, 60);
            }
        }
    }
    return '';
}

function sb_is_noindex_post($id) {
    return function_exists('sb_is_press') && sb_is_press($id);
}

/** Called after every save of a blog post: copy our SEO fields into Yoast's. */
function sb_seo_sync($id) {
    $id = (int) $id;
    if (!$id || !sb_seo_yoast()) {
        return;
    }
    update_post_meta($id, '_yoast_wpseo_metadesc', sb_post_desc($id));
    $kw = sb_post_focus($id);
    if ($kw !== '') {
        update_post_meta($id, '_yoast_wpseo_focuskw', $kw);
    }
    $t = trim((string) get_post_meta($id, '_sb_seo_title', true));
    if ($t !== '') {
        update_post_meta($id, '_yoast_wpseo_title', $t);
    } elseif (get_post_meta($id, '_yoast_wpseo_title', true) !== '') {
        delete_post_meta($id, '_yoast_wpseo_title');   // Yoast's own template (title + site name) decides
    }
    if (sb_is_noindex_post($id)) {
        update_post_meta($id, '_yoast_wpseo_meta-robots-noindex', '1');
    }
}

/** Once, after Yoast is found: bring all existing blog posts in line. */
add_action('admin_init', function () {
    if (!sb_seo_yoast() || get_option('sb_seo_synced') === '1' || !current_user_can('edit_posts')) {
        return;
    }
    foreach (get_posts(array('post_type' => 'sb_post', 'post_status' => 'any', 'numberposts' => 600, 'fields' => 'ids')) as $pid) {
        sb_seo_sync($pid);
    }
    update_option('sb_seo_synced', '1', false);
});

/* ------------------------------------------------------------ Yoast filters */
add_filter('wpseo_metadesc', function ($d) {
    if (is_singular('sb_post') && trim((string) $d) === '') {
        return sb_post_desc(get_queried_object_id());
    }
    return $d;
});
add_filter('wpseo_title', function ($t) {
    if (is_singular('sb_post')) {
        $own = trim((string) get_post_meta(get_queried_object_id(), '_sb_seo_title', true));
        if ($own !== '' && !get_post_meta(get_queried_object_id(), '_yoast_wpseo_title', true)) {
            return $own;
        }
    }
    return $t;
});
add_filter('wpseo_schema_article_post_types', function ($t) {
    $t[] = 'sb_post';
    return array_values(array_unique($t));
});
add_filter('wpseo_opengraph_type', function ($t) {
    return is_singular('sb_post') ? 'article' : $t;
});
add_filter('wpseo_robots', function ($r) {
    return is_singular('sb_post') && sb_is_noindex_post(get_queried_object_id()) ? 'noindex, follow' : $r;
});
add_filter('wpseo_exclude_from_sitemap_by_post_ids', function ($ids) {
    $ids = (array) $ids;
    foreach (get_posts(array('post_type' => 'sb_post', 'post_status' => 'publish', 'numberposts' => 600, 'fields' => 'ids',
        'meta_query' => array(array('key' => '_sb_press_url', 'compare' => 'EXISTS')))) as $pid) {
        $ids[] = (int) $pid;
    }
    return $ids;
});

/* ------------------------------------------- no SEO plugin / core sitemap / robots */
add_filter('wp_robots', function ($r) {
    if (is_singular('sb_post') && sb_is_noindex_post(get_queried_object_id())) {
        $r['noindex'] = true;
        $r['follow']  = true;
        unset($r['index']);
    }
    return $r;
});
add_filter('wp_sitemaps_posts_query_args', function ($args, $type) {
    if ($type === 'sb_post') {
        $args['meta_query'] = array(array('key' => '_sb_press_url', 'compare' => 'NOT EXISTS'));
    }
    return $args;
}, 10, 2);

/* ------------------------------------------------------------------- head output */
function sb_faq_jsonld($id) {
    $faq = get_post_meta($id, '_sb_faq', true);
    $ent = array();
    foreach (is_array($faq) ? $faq : array() as $r) {
        if (!empty($r['q']) && !empty($r['a'])) {
            $ent[] = array('@type' => 'Question', 'name' => wp_strip_all_tags($r['q']),
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => wp_strip_all_tags($r['a'])));
        }
    }
    return $ent ? array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $ent) : null;
}

/** Replaces the old basic tag block (frontend.php). */
function sb_head_meta() {
    if (!is_singular('sb_post')) {
        return;
    }
    $id  = get_queried_object_id();
    $faq = !sb_is_noindex_post($id) && get_post_meta($id, '_sb_inc_faq', true) !== '0' ? sb_faq_jsonld($id) : null;
    if (!sb_seo_yoast()) {
        $desc = sb_post_desc($id);
        $img  = sb_post_image($id, 'large');
        $url  = get_permalink($id);
        $name = get_bloginfo('name');
        $own  = trim((string) get_post_meta($id, '_sb_seo_title', true));
        $ttl  = $own !== '' ? $own : get_the_title($id);
        echo "\n<meta name=\"description\" content=\"" . esc_attr($desc) . "\">\n";
        echo '<meta property="og:type" content="article">' . "\n";
        echo '<meta property="og:locale" content="bn_BD">' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr($name) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($ttl) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        echo '<meta property="article:published_time" content="' . esc_attr(get_post_time('c', true, $id)) . '">' . "\n";
        echo '<meta property="article:modified_time" content="' . esc_attr(get_post_modified_time('c', true, $id)) . '">' . "\n";
        echo '<meta name="twitter:card" content="' . ($img ? 'summary_large_image' : 'summary') . '">' . "\n";
        if ($img) {
            echo '<meta property="og:image" content="' . esc_url($img) . '">' . "\n";
        }
        $art = array('@context' => 'https://schema.org', '@type' => 'Article', 'headline' => mb_substr($ttl, 0, 110), 'description' => $desc,
            'mainEntityOfPage' => $url, 'inLanguage' => 'bn', 'datePublished' => get_post_time('c', true, $id), 'dateModified' => get_post_modified_time('c', true, $id),
            'author' => array('@type' => 'Person', 'name' => ($a = trim((string) get_post_meta($id, '_sb_author_name', true))) !== '' ? $a : $name),
            'publisher' => array('@type' => 'Organization', 'name' => $name));
        if ($img) {
            $art['image'] = array($img);
        }
        echo '<script type="application/ld+json">' . wp_json_encode($art, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
    }
    if ($faq) {
        echo '<script type="application/ld+json">' . wp_json_encode($faq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
    }
}
