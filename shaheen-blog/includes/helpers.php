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
function sb_feature_cost($feature) {
    $map = array(
        'headlines' => 'cost_headlines',
        'subtitle'  => 'cost_subtitle',
        'meta'      => 'cost_meta',
        'article'   => 'cost_article',
        'summary'   => 'cost_summary',
    );
    return isset($map[$feature]) ? max(0, (int) sb_get($map[$feature])) : 0;
}

function sb_feature_labels() {
    return array(
        'headlines' => '৫টি শিরোনাম',
        'subtitle'  => 'সাবটাইটেল',
        'meta'      => 'মেটা বর্ণনা (SEO)',
        'article'   => 'সম্পূর্ণ আর্টিকেল',
        'summary'   => 'সামারি',
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
