<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Default settings. */
function sb_defaults() {
    return array(
        'api_key'       => '',
        'model'         => 'gpt-4o-mini',
        'default_limit' => 20000,
        'cost_headlines' => 50,
        'cost_subtitle'  => 30,
        'cost_meta'      => 20,
        'cost_article'   => 180,
        'cost_summary'   => 80,
        'cost_research'  => 50,
        'cost_rewrite'   => 150,
        'cost_custom'    => 120,
        'cost_edit'      => 30,
        'cost_edit_many' => 45,
        'cost_phones'    => 100,
        'cost_faq'       => 80,
        'cost_related'   => 30,
        'style_note'     => 'প্রচলিত চলিত ভাষায় লিখবে। কঠিন, সাধু বা বইয়ের মতো ভারী শব্দ এড়িয়ে প্রতিদিনের সহজ শব্দ ব্যবহার করবে। যেখানে জনপ্রিয় ইংরেজি শব্দ (যেমন: ক্লাস, কোচিং, অনলাইন) বেশি চলে সেখানে সেটাই লিখবে।',
        'model_article'  => '',
        'top_offset'     => 0,
        'content_width'  => 0,
        'site_urls'      => "https://shaheen.edu.bd/",
        'about'          => '',
        'hero_title'    => '📚 শাহীন শিক্ষা পরিবার ব্লগ',
        'hero_subtitle' => 'শাহীন স্কুল, ক্যাডেট কোচিং ও শিক্ষাবিষয়ক তথ্য',
        'intro'         => 'শাহীন শিক্ষা পরিবারের ব্লগে স্বাগতম। এখানে স্কুলে ভর্তি, ক্যাডেট কলেজ ভর্তি প্রস্তুতি, শিক্ষাবিষয়ক পরামর্শ, পরীক্ষার প্রস্তুতি, একাডেমিক কার্যক্রম এবং শাহীন স্কুল ও ক্যাডেট কোচিং সম্পর্কিত গুরুত্বপূর্ণ তথ্য প্রকাশ করা হয়। শিক্ষার্থী ও অভিভাবকদের সঠিক সিদ্ধান্ত নিতে সহায়তা করাই আমাদের লক্ষ্য।',
        'show_intro'    => 1,
        'per_page'      => 12,
        'full_width'    => 1,
        'hide_theme_title' => 1,
    );
}

function sb_get($key) {
    $saved = get_option('sb_settings', array());
    $defaults = sb_defaults();
    if (is_array($saved) && array_key_exists($key, $saved) && $saved[$key] !== '') {
        return $saved[$key];
    }
    return isset($defaults[$key]) ? $defaults[$key] : '';
}

function sb_save_settings(array $new) {
    $saved = get_option('sb_settings', array());
    if (!is_array($saved)) {
        $saved = array();
    }
    update_option('sb_settings', array_merge($saved, $new), false);
}

/** Feature => credit cost. */
/** Model for a feature: article may use its own (better) model. */
function sb_model_for($feature) {
    $m = (in_array($feature, array('article', 'custom', 'rewrite', 'rewrite_post', 'edit', 'edit_new'), true)) ? trim((string) sb_get('model_article')) : '';
    return $m !== '' ? $m : sb_get('model');
}

function sb_feature_cost($feature) {
    $map = array(
        'headlines' => 'cost_headlines',
        'subtitle'  => 'cost_subtitle',
        'meta'      => 'cost_meta',
        'article'   => 'cost_article',
        'summary'   => 'cost_summary',
        'rewrite'   => 'cost_rewrite',
        'rewrite_post' => 'cost_rewrite',
        'edit'      => 'cost_edit',
        'edit_many' => 'cost_edit_many',
        'edit_new'  => 'cost_article',
        'custom'    => 'cost_custom',
        'phones'    => 'cost_phones',
        'faq'       => 'cost_faq',
        'related'   => 'cost_related',
    );
    return isset($map[$feature]) ? max(0, (int) sb_get($map[$feature])) : 0;
}

/* ---------------------------------------------------------------- views */
/** Numbers (2+ digits, Bengali or English) found in a text, as plain ASCII strings. */
function sb_numbers($text) {
    $text = strtr((string) $text, array('০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'));
    $text = preg_replace('/(?<=\d),(?=\d)/', '', $text);
    preg_match_all('/\d+(?:\.\d+)?/', $text, $m);
    return array_values(array_unique(array_filter($m[0], function ($n) { return strlen(str_replace('.', '', $n)) >= 2; })));
}

/** 300 starting numbers for the "read N times" counter (fixed, repeatable). */
function sb_view_pool() {
    static $pool = null;
    if ($pool === null) {
        mt_srand(8311);
        $pool = array();
        for ($i = 0; $i < 300; $i++) {
            $pool[] = 90 + $i * 7 + mt_rand(0, 6);   // 90 .. ~2200
        }
        // Deterministic shuffle so neighbouring posts differ.
        for ($i = count($pool) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $t = $pool[$i]; $pool[$i] = $pool[$j]; $pool[$j] = $t;
        }
        mt_srand();
    }
    return $pool;
}

/** Give a post its random starting count once; keeps real visits already counted. */
function sb_init_views($post_id) {
    if (get_post_meta($post_id, '_sb_views_seeded', true)) {
        return (int) get_post_meta($post_id, '_sb_views', true);
    }
    $pool = sb_view_pool();
    $start = $pool[wp_rand(0, count($pool) - 1)];
    $views = $start + max(0, (int) get_post_meta($post_id, '_sb_views', true));
    update_post_meta($post_id, '_sb_views', $views);
    update_post_meta($post_id, '_sb_views_seeded', 1);
    return $views;
}

/* ----------------------------------------------------------- press items */
function sb_is_press($post_id) {
    return (bool) get_post_meta($post_id, '_sb_press_url', true);
}

/**
 * Related items (posts, pages, press): manual picks first, then same category,
 * then latest blog posts, then pages — always at least $min when content exists.
 */
function sb_related_items($post_id, $min = 3, $max = 5) {
    $ids = array();
    $manual = get_post_meta($post_id, '_sb_related', true);
    if (is_array($manual)) {
        foreach ($manual as $m) {
            $m = (int) $m;
            if ($m && $m !== $post_id && get_post_status($m) === 'publish') {
                $ids[] = $m;
            }
        }
    }
    $add = function ($more) use (&$ids, $post_id) {
        foreach ($more as $m) {
            if ((int) $m !== $post_id && !in_array((int) $m, $ids, true)) {
                $ids[] = (int) $m;
            }
        }
    };
    $has_manual = !empty($ids);   // an AI/hand-picked list is shown as is, never padded with unrelated items
    if (!$has_manual) {
        // Free matching (no AI): posts & pages whose title/text share the post's title or keywords.
        $cache_key = 'sb_rel_' . $post_id . '_' . get_post_modified_time('U', true, $post_id);
        $auto = get_transient($cache_key);
        if (!is_array($auto)) {
            $kw = get_post_meta($post_id, '_sb_keywords', true);
            $auto = array();
            foreach (sb_related_candidates($post_id, is_array($kw) ? $kw : array(), get_the_title($post_id), 12) as $r) {
                if ($r['score'] > 0) {
                    $auto[] = $r['id'];
                }
            }
            $term = get_the_terms($post_id, 'sb_category');
            if (count($auto) < $min && !empty($term) && !is_wp_error($term)) {
                $same = get_posts(array('post_type' => 'sb_post', 'post_status' => 'publish', 'posts_per_page' => $max, 'fields' => 'ids',
                    'post__not_in' => array($post_id), 'no_found_rows' => true,
                    'meta_query' => array(array('key' => '_sb_press_url', 'compare' => 'NOT EXISTS')),
                    'tax_query' => array(array('taxonomy' => 'sb_category', 'field' => 'term_id', 'terms' => array((int) $term[0]->term_id)))));
                $auto = array_merge($auto, array_diff($same, $auto));
            }
            $auto = array_slice($auto, 0, $max);
            set_transient($cache_key, $auto, 6 * HOUR_IN_SECONDS);
        }
        $ids = $auto;
    }
    $why = get_post_meta($post_id, '_sb_related_why', true);
    $why = is_array($why) ? $why : array();
    $items = array();
    foreach (array_slice($ids, 0, max($min, $max)) as $rid) {
        $type = get_post_type($rid);
        $press = ($type === 'sb_post' && sb_is_press($rid));
        $items[] = array(
            'id'    => $rid,
            'title' => get_the_title($rid),
            'url'   => $press ? get_post_meta($rid, '_sb_press_url', true) : get_permalink($rid),
            'thumb' => get_the_post_thumbnail_url($rid, 'medium'),
            'tag'   => $press ? '📰 সংবাদ: ' . get_post_meta($rid, '_sb_press_paper', true) : ($type === 'page' ? '📄 পেজ' : ($type === 'sb_post' ? '📝 ব্লগ · ' . sb_post_category($rid)['bn'] : '📝 পোস্ট')),
            'press' => $press,
            'why'   => isset($why[$rid]) ? $why[$rid] : '',
        );
    }
    return $items;
}

function sb_feature_labels() {
    return array(
        'headlines' => '৫টি শিরোনাম',
        'subtitle'  => 'সাবটাইটেল',
        'meta'      => 'মেটা বর্ণনা (SEO)',
        'article'   => 'সম্পূর্ণ আর্টিকেল',
        'summary'   => 'সামারি',
        'rewrite'   => 'কপি করা লেখা নতুন করে লেখা',
        'rewrite_post' => 'রিরাইট (শিরোনাম + বর্ণনা + লেখা)',
        'edit'      => 'সংশোধন (১–৫টি পরিবর্তন)',
        'edit_many' => 'সংশোধন (৬–১০টি পরিবর্তন)',
        'edit_new'  => 'নির্দেশ দিয়ে নতুন করে লেখা',
        'custom'    => 'নিজের প্রম্পটে আর্টিকেল',
        'phones'    => 'নতুন মোবাইল মডেল খোঁজা',
        'faq'       => 'FAQ (প্রশ্ন-উত্তর)',
        'related'   => 'স্মার্ট সম্পর্কিত পোস্ট/পেজ',
    );
}

/** English digits to Bengali digits. */
function sb_bn_digits($value) {
    return strtr((string) $value, array(
        '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪',
        '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯',
    ));
}

/** Bengali date, e.g. "১৪ অক্টোবর ২০২৬". */
function sb_bn_date($timestamp, $with_year = true) {
    $months = array('', 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর');
    $d = (int) wp_date('j', $timestamp);
    $m = (int) wp_date('n', $timestamp);
    $y = wp_date('Y', $timestamp);
    $out = sb_bn_digits($d) . ' ' . $months[$m];
    if ($with_year) {
        $out .= ' ' . sb_bn_digits($y);
    }
    return $out;
}

/** The default 8 categories: slug => [Bengali, English]. */
function sb_default_categories() {
    return array(
        'cadet'      => array('ক্যাডেট', 'Cadet'),
        'student'    => array('শিক্ষার্থী', 'Student'),
        'parent'     => array('অভিভাবক', 'Parent'),
        'teacher'    => array('শিক্ষক', 'Teacher'),
        'education'  => array('শিক্ষা', 'Education'),
        'news'       => array('খবর', 'News'),
        'technology' => array('প্রযুক্তি', 'Technology'),
        'others'     => array('অন্যান্য', 'Others'),
    );
}

function sb_seed_categories() {
    foreach (sb_default_categories() as $slug => $names) {
        if (!term_exists($slug, 'sb_category')) {
            $t = wp_insert_term($names[0], 'sb_category', array('slug' => $slug));
            if (!is_wp_error($t)) {
                update_term_meta($t['term_id'], 'sb_name_en', $names[1]);
            }
        }
    }
}

/** Create the /blog page holding the shortcode (if no page has it yet). */
function sb_create_blog_page() {
    $existing = (int) get_option('sb_blog_page_id');
    if ($existing && get_post_status($existing)) {
        return $existing;
    }
    $page = get_page_by_path('blog');
    if ($page) {
        // Do not touch someone else's page; use a different slug.
        $slug = 'shaheen-blog';
    } else {
        $slug = 'blog';
    }
    $id = wp_insert_post(array(
        'post_type'    => 'page',
        'post_title'   => 'ব্লগ',
        'post_name'    => $slug,
        'post_content' => '[shaheen_blog]',
        'post_status'  => 'publish',
    ));
    if (!is_wp_error($id)) {
        update_option('sb_blog_page_id', (int) $id, false);
        return (int) $id;
    }
    return 0;
}

/** Category data for a post: array(slug, bn, en) */
function sb_post_category($post_id) {
    $terms = get_the_terms($post_id, 'sb_category');
    if (empty($terms) || is_wp_error($terms)) {
        return array('slug' => 'others', 'bn' => 'অন্যান্য', 'en' => 'Others');
    }
    $t = $terms[0];
    $en = get_term_meta($t->term_id, 'sb_name_en', true);
    return array('slug' => $t->slug, 'bn' => $t->name, 'en' => $en ? $en : '');
}

function sb_author_name($post_id) {
    $custom = get_post_meta($post_id, '_sb_author_name', true);
    if ($custom) {
        return $custom;
    }
    $p = get_post($post_id);
    return $p ? get_the_author_meta('display_name', $p->post_author) : '';
}

function sb_post_image($post_id, $size = 'large') {
    $url = get_the_post_thumbnail_url($post_id, $size);
    return $url ? $url : '';
}


/**
 * Candidate pool for "smart related": blog posts, site pages and posts, pre-filtered by
 * keyword overlap so the AI only reads a short list (keeps the cost tiny).
 */
function sb_related_candidates($post_id, $keywords, $title, $limit = 40) {
    $skip = array_filter(array((int) $post_id, (int) get_option('sb_blog_page_id'), (int) get_option('page_on_front'), (int) get_option('page_for_posts')));
    $ids = get_posts(array('post_type' => array('sb_post', 'page', 'post'), 'post_status' => 'publish', 'numberposts' => 250,
        'fields' => 'ids', 'post__not_in' => $skip, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true));
    $tokens = array();
    foreach (array_merge((array) $keywords, array($title)) as $t) {
        foreach (preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower((string) $t)) as $w) {
            if (mb_strlen($w) >= 3) {
                $tokens[$w] = true;
            }
        }
    }
    $rows = array();
    foreach ($ids as $rid) {
        if (get_post_type($rid) === 'sb_post' && sb_is_press($rid)) {
            continue;
        }
        $p = get_post($rid);
        $snip = mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(($p->post_excerpt ?: $p->post_content)))), 0, 110);
        $hay_t = mb_strtolower($p->post_title);
        $hay_b = mb_strtolower($snip);
        $score = 0;
        foreach (array_keys($tokens) as $w) {
            if (mb_strpos($hay_t, $w) !== false) {
                $score += 3;
            } elseif (mb_strpos($hay_b, $w) !== false) {
                $score += 1;
            }
        }
        $rows[] = array('id' => (int) $rid, 'type' => $p->post_type === 'page' ? 'পেজ' : 'পোস্ট', 'title' => $p->post_title, 'snip' => $snip, 'score' => $score);
    }
    usort($rows, function ($a, $b) { return $b['score'] <=> $a['score']; });
    return array_slice($rows, 0, $limit);
}
