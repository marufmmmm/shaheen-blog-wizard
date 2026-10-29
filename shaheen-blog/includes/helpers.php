<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Default settings. */
function sb_defaults() {
    return array(
        'api_key'       => '',
        'model'         => 'gpt-4o-mini',
        'default_limit' => 80000,
        'usd_margin'    => 50,
        'limit_usd'     => 32,
        'report_email'  => '',
        'report_auto'   => 1,
        'bill_to'       => '',
        'bill_from'     => '',
        'cost_headlines' => 50,
        'cost_subtitle'  => 30,
        'cost_meta'      => 20,
        'cost_article'   => 180,
        'cost_summary'   => 80,
        'cost_research'  => 50,
        'cost_rewrite'   => 150,
        'cost_learn'     => 20,
        'cost_custom'    => 120,
        'cost_custom2'   => 150,
        'cost_custom3'   => 180,
        'cost_edit'      => 30,
        'cost_edit_many' => 45,
        'cost_phones'    => 100,
        'cost_faq'       => 80,
        'cost_related'   => 30,
        'cost_chat'      => 30,
        'cost_brief'     => 30,
        'cost_caption'   => 30,
        'ai_lang'        => 'bn',
        'ai_lang_other'  => '',
        'style_note'     => 'প্রচলিত চলিত ভাষায় লিখবে। কঠিন, সাধু বা বইয়ের মতো ভারী শব্দ এড়িয়ে প্রতিদিনের সহজ শব্দ ব্যবহার করবে। যেখানে জনপ্রিয় ইংরেজি শব্দ (যেমন: ক্লাস, কোচিং, অনলাইন) বেশি চলে সেখানে সেটাই লিখবে।',
        'model_article'  => '',
        'extra_models'   => '',
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
/**
 * Models offered in Settings, with the estimated API cost of one prompt-article expressed in credits
 * (1 credit = 0.0002 USD) for the three length tiers. Estimates: Bengali ~4 tokens/word, ~8k input tokens,
 * thinking tokens included for reasoning models. Prices per 1M tokens (input/output) as of Oct 2026.
 */
/** Models the owner added in Settings (one per line: model-id | charge for 800-1000 | 1000-1500 | 1500+ words). */
function sb_extra_models() {
    $out = array();
    foreach (preg_split('/\R/u', (string) sb_get('extra_models')) as $line) {
        $p = array_map('trim', explode('|', $line));
        $id = $p[0];
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-]{1,59}$/', $id)) {
            continue;
        }
        $c = array();
        for ($i = 1; $i <= 3; $i++) {
            $c[] = isset($p[$i]) && (int) $p[$i] > 0 ? (int) $p[$i] : array(360, 450, 630)[$i - 1];
        }
        $out[$id] = array('—', array(0, 0, 0), 'আপনার যোগ করা মডেল', $c);
    }
    return $out;
}

function sb_model_catalog() {
    // id => [price text, [est credits t1,t2,t3], note, [default charge t1,t2,t3]]
    return array_merge(sb_extra_models(), array(
        'gpt-4o-mini'  => array('0.15 / 0.60', array(17, 21, 29), 'সবচেয়ে সস্তা; বাংলার মান মোটামুটি', array(120, 150, 180)),
        'gpt-5.4-nano' => array('0.20 / 1.25', array(55, 73, 103), 'সস্তা, নতুন প্রজন্ম', array(120, 150, 180)),
        'gpt-4.1-mini' => array('0.40 / 1.60', array(45, 56, 78), 'ভালো ও সস্তা', array(120, 150, 180)),
        'gpt-5-mini'   => array('0.25 / 2.00', array(80, 110, 155), 'ভালো বাংলা, মাঝারি খরচ (প্রস্তাবিত)', array(130, 180, 250)),
        'gpt-5.4-mini' => array('0.75 / 4.50', array(190, 255, 360), 'আরও ভালো, দাম বেশি', array(310, 410, 580)),
        'gpt-4.1'      => array('2.00 / 8.00', array(225, 280, 390), 'দামি', array(360, 450, 630)),
        'gpt-4o'       => array('2.50 / 10.00', array(280, 340, 460), 'দামি', array(450, 550, 740)),
        'gpt-6-astra'  => array('10.00 / 50.00', array(2283, 2983, 3983), 'OpenAI-র সবচেয়ে সক্ষম মডেল; খুব দামি', array(2300, 3000, 4000)),
        'gpt-5'        => array('1.25 / 10.00', array(410, 550, 750), 'সবচেয়ে দামি (চিন্তার টোকেনে)', array(660, 880, 1200)),
    ));
}

/** Charge (credits) per model for the three length tiers; owner-editable in Settings. */
function sb_model_prices() {
    $saved = sb_get('model_prices');
    $saved = is_array($saved) ? $saved : array();
    $out = array();
    foreach (sb_model_catalog() as $id => $m) {
        $row = isset($saved[$id]) && is_array($saved[$id]) ? array_values($saved[$id]) : array();
        for ($i = 0; $i < 3; $i++) {
            $row[$i] = isset($row[$i]) && (int) $row[$i] > 0 ? (int) $row[$i] : $m[3][$i];
        }
        $out[$id] = array_slice($row, 0, 3);
    }
    return $out;
}

/** Models an editor may pick for a prompt-article. */
function sb_allowed_models() {
    $ids = array_keys(sb_model_catalog());
    foreach (array('model', 'model_article') as $k) {
        $m = trim((string) sb_get($k));
        if ($m !== '') {
            $ids[] = $m;
        }
    }
    return array_values(array_unique($ids));
}

/** Model chosen in the Settings select (or typed under "other"). */
function sb_pick_model($in, $name) {
    $sel = sanitize_text_field($in($name . '_sel', ''));
    $v = $sel === '__other' ? sanitize_text_field($in($name . '_other', '')) : $sel;
    return preg_match('/^[A-Za-z0-9._:\-]{1,60}$/', $v) ? $v : '';
}

/** Model for a feature: article may use its own (better) model. */
function sb_model_for($feature) {
    $m = (in_array($feature, array('article', 'custom', 'rewrite', 'rewrite_post', 'edit', 'edit_new'), true)) ? trim((string) sb_get('model_article')) : '';
    return $m !== '' ? $m : sb_get('model');
}

/** Prompt-article price: by length tier (1 = 800-1000 words, 2 = 1000-1500, 3 = 1500+) and chosen model. */
function sb_custom_cost($tier, $model = '') {
    $tier = max(1, min(3, (int) $tier));
    $prices = sb_model_prices();
    if ($model !== '' && isset($prices[$model])) {
        return (int) $prices[$model][$tier - 1];
    }
    $k = array(1 => 'cost_custom', 2 => 'cost_custom2', 3 => 'cost_custom3');
    return max(0, (int) sb_get($k[$tier]));
}

/** Credits per 100 words of an article on a model (derived from its 800-1000 word price: ~900 words). */
function sb_per100($model = '') {
    return (int) max(1, ceil(sb_custom_cost(1, $model) / 9));
}

/** Article price by word count: per 100 words (rounded up), at least 300 words. */
function sb_words_cost($words, $model = '') {
    $words = max(300, min(5000, (int) $words));
    return (int) (ceil($words / 100) * sb_per100($model));
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
        'learn'     => 'cost_learn',
        'phones'    => 'cost_phones',
        'faq'       => 'cost_faq',
        'related'   => 'cost_related',
        'chat'      => 'cost_chat',
        'brief'     => 'cost_brief',
        'caption'   => 'cost_caption',
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
        'custom'    => 'নিজের প্রম্পটে আর্টিকেল (৮০০–১০০০ শব্দ)',
        'learn'     => 'মেমোরিতে শেখা (আপনার সংশোধন থেকে)',
        'phones'    => 'নতুন মোবাইল মডেল খোঁজা',
        'faq'       => 'FAQ (প্রশ্ন-উত্তর)',
        'related'   => 'স্মার্ট সম্পর্কিত পোস্ট/পেজ',
        'chat'      => 'চ্যাট সহকারী (প্রতি সফল পরিবর্তন)',
        'brief'     => 'লেখার আগে বোঝাপড়া ও গবেষণা',
        'caption'   => 'Facebook ক্যাপশন',
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
    $all = sb_post_categories($post_id);
    return $all[0];
}

/** All categories of a post, the main one first: list of array(id, slug, bn, en). A post without a category counts as "Others". */
function sb_post_categories($post_id) {
    $terms = get_the_terms($post_id, 'sb_category');
    if (empty($terms) || is_wp_error($terms)) {
        return array(array('id' => 0, 'slug' => 'others', 'bn' => 'অন্যান্য', 'en' => 'Others'));
    }
    $main = (int) get_post_meta($post_id, '_sb_cat_primary', true);
    $out = array();
    foreach ($terms as $t) {
        $en = get_term_meta($t->term_id, 'sb_name_en', true);
        $row = array('id' => (int) $t->term_id, 'slug' => $t->slug, 'bn' => $t->name, 'en' => $en ? $en : '');
        if ((int) $t->term_id === $main) {
            array_unshift($out, $row);
        } else {
            $out[] = $row;
        }
    }
    return $out;
}

/** Saves the main category plus any extra ones for a post. */
function sb_set_post_categories($post_id, $main, $extra) {
    $ids = array();
    foreach (array_merge(array((int) $main), array_map('intval', (array) $extra)) as $t) {
        if ($t && term_exists($t, 'sb_category') && !in_array($t, $ids, true)) {
            $ids[] = $t;
        }
    }
    wp_set_object_terms($post_id, $ids, 'sb_category');
    update_post_meta($post_id, '_sb_cat_primary', $ids ? $ids[0] : 0);
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


/** Post types: label, how the article is usually built, and the facts such a post normally needs (the brief step asks for missing ones). */
function sb_ptypes() {
    return array(
        'general'   => array('সাধারণ', '', ''),
        'admission' => array('ভর্তি বিজ্ঞপ্তি', 'কাঠামো: কারা আবেদন করতে পারবে, আবেদনের ধাপ, প্রয়োজনীয় কাগজপত্র, গুরুত্বপূর্ণ তারিখ, ফি ও খরচ, যোগাযোগ।', 'আবেদনের তারিখ, ক্লাস/বয়স/যোগ্যতা, ফি, প্রয়োজনীয় কাগজ, আসন সংখ্যা, শাখা ও ঠিকানা, যোগাযোগের ফোন'),
        'result'    => array('ফলাফল', 'কাঠামো: সংক্ষিপ্ত সুখবর, ফলাফলের মূল সংখ্যা, কৃতিত্বের কারণ (শিক্ষক, পদ্ধতি), শিক্ষার্থী ও অভিভাবকের অনুভূতি, পরের ধাপ।', 'পরীক্ষার নাম ও সাল, মোট পরীক্ষার্থী, পাস/সফল সংখ্যা, শতকরা হার, উল্লেখযোগ্য সাফল্য, ফলাফল দেখার লিংক'),
        'tips'      => array('পড়াশোনার টিপস', 'কাঠামো: সমস্যাটা পাঠকের চোখে, কেন হয়, ধাপে ধাপে সমাধান (উদাহরণসহ), সাধারণ ভুল, অভিভাবকের ভূমিকা, ছোট করে করণীয়।', 'কোন বয়স/শ্রেণির জন্য, সময়সূচি বা সংখ্যা (থাকলে), উৎস/গবেষণার নাম (থাকলে)'),
        'news'      => array('খবর / বিজ্ঞপ্তি', 'কাঠামো: কী হয়েছে (প্রথম অনুচ্ছেদেই), কখন-কোথায়-কারা, পটভূমি, এর ফলে পাঠকের কী করণীয়, যোগাযোগ।', 'ঘটনার তারিখ ও স্থান, জড়িত ব্যক্তি/প্রতিষ্ঠান, পাঠকের করণীয়, যোগাযোগ'),
        'contest'   => array('প্রতিযোগিতা', 'কাঠামো: প্রতিযোগিতা কী ও কেন, কারা অংশ নিতে পারবে, নিবন্ধনের ধাপ, নিয়ম, পুরস্কার, গুরুত্বপূর্ণ তারিখ, যোগাযোগ।', 'নিবন্ধনের শেষ তারিখ, অংশগ্রহণের যোগ্যতা, ফি (থাকলে), পুরস্কার, পরীক্ষার তারিখ ও স্থান, যোগাযোগ'),
    );
}

/** User presets (saved writing settings), per user. */
function sb_presets_get($uid = 0) {
    $uid = $uid ?: get_current_user_id();
    $p = get_user_meta($uid, 'sb_presets', true);
    return is_array($p) ? $p : array();
}


/** Reader and tone choices for the writing prompt. */
function sb_audiences() {
    return array('all' => array('সবাই (অভিভাবক ও শিক্ষার্থী)', ''), 'parents' => array('অভিভাবক', 'পাঠক মূলত অভিভাবক: সন্তানের ভবিষ্যৎ, নিরাপত্তা, খরচ ও ভরসার কথা বেশি ভাববেন, সরাসরি আপনি-সম্বোধনে লিখবে।'),
        'students' => array('শিক্ষার্থী', 'পাঠক মূলত শিক্ষার্থী: ছোট বাক্যে, উৎসাহ দিয়ে, বন্ধুসুলভ ভাষায়; কঠিন ধারণা উদাহরণ দিয়ে বোঝাবে।'));
}
function sb_tones() {
    return array('warm' => array('আন্তরিক ও মমতাময়', 'সুর: আন্তরিক, মমতাময়, আপন মানুষ পাশে বসে বোঝানোর মতো।'), 'formal' => array('আনুষ্ঠানিক', 'সুর: আনুষ্ঠানিক ও মার্জিত, বিজ্ঞপ্তির মতো পরিষ্কার; আবেগের বাড়াবাড়ি নয়।'),
        'motivating' => array('উৎসাহব্যঞ্জক', 'সুর: উৎসাহ ও আত্মবিশ্বাস জাগানো, ইতিবাচক, কিন্তু বাড়িয়ে বলা বা মিথ্যা আশা নয়।'), 'news' => array('সংবাদধর্মী', 'সুর: নিরপেক্ষ, তথ্যনির্ভর, সংবাদের মতো; প্রথম অনুচ্ছেদেই মূল খবর।'));
}


/** Hostnames (without www.) found in a pasted list of URLs. */
function sb_beat_hosts($txt) {
    $out = array();
    if (preg_match_all('#https?://[^\s"\'<>,]+#iu', (string) $txt, $m)) {
        foreach ($m[0] as $u) {
            $h = strtolower((string) wp_parse_url($u, PHP_URL_HOST));
            $h = preg_replace('/^www\./', '', $h);
            if ($h !== '') {
                $out[$h] = true;
            }
        }
    }
    return array_keys($out);
}

/**
 * Link hygiene for AI articles: never link to the sites the owner wants to beat;
 * keep at most 2 outside links in the body (the "তথ্যসূত্র" list at the bottom may keep its own).
 */
function sb_ref_clean($html, $beat_hosts) {
    $html = (string) $html;
    if (stripos($html, '<a ') === false) {
        return $html;
    }
    $own = preg_replace('/^www\./', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)));
    $split = preg_match('/<h[23][^>]*>[^<]*তথ্যসূত্র/u', $html, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : strlen($html);
    $parts = array(substr($html, 0, $split), substr($html, $split));
    $kept = 0;
    foreach ($parts as $i => $part) {
        $parts[$i] = preg_replace_callback('#<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)</a>#isu', function ($m) use ($beat_hosts, $own, &$kept, $i) {
            $h = preg_replace('/^www\./', '', strtolower((string) wp_parse_url($m[1], PHP_URL_HOST)));
            if ($h === '' || $h === $own) {
                return $m[0];   // internal link
            }
            if (in_array($h, $beat_hosts, true)) {
                return $m[2];   // competitor: plain text only
            }
            if ($i === 0 && ++$kept > 2) {
                return $m[2];
            }
            return $m[0];
        }, $part);
    }
    return $parts[0] . $parts[1];
}


/** Credits for a real API call: read (prompt) + write (completion incl. thinking), from the model's $/1M-token prices (1 credit = $0.0002). */
function sb_usage_credits($model, $usage) {
    $cat = sb_model_catalog();
    if (!isset($cat[$model]) || !is_array($usage) || empty($usage['prompt_tokens'])) {
        return array();
    }
    if (!preg_match('/([\d.]+)\s*\/\s*([\d.]+)/', (string) $cat[$model][0], $m)) {
        return array();
    }
    $read = ((int) $usage['prompt_tokens']) * (float) $m[1] / 1000000 / 0.0002;
    $write = ((int) (isset($usage['completion_tokens']) ? $usage['completion_tokens'] : 0)) * (float) $m[2] / 1000000 / 0.0002;
    return array('read' => (int) ceil($read), 'write' => (int) ceil($write));
}
