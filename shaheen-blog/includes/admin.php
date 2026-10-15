<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'sb_admin_menu');
function sb_admin_menu() {
    add_menu_page('শাহীন ব্লগ', 'শাহীন ব্লগ', 'edit_posts', 'sb-dashboard', 'sb_page_dashboard', 'dashicons-welcome-write-blog', 26);
    add_submenu_page('sb-dashboard', 'ড্যাশবোর্ড', 'ড্যাশবোর্ড', 'edit_posts', 'sb-dashboard', 'sb_page_dashboard');
    add_submenu_page('sb-dashboard', 'নতুন পোস্ট', 'নতুন পোস্ট', 'edit_posts', 'sb-new', 'sb_page_new');
    add_submenu_page('sb-dashboard', 'রিরাইট করে পোস্ট', '🔁 রিরাইট করে পোস্ট', 'edit_posts', 'sb-rewrite', 'sb_page_rewrite');
    add_submenu_page('sb-dashboard', 'সোশ্যাল মিডিয়া', '🌐 সোশ্যাল মিডিয়া', 'manage_options', 'sb-social', 'sb_page_social');
    add_submenu_page('sb-dashboard', 'সংবাদপত্রে শাহীন', '📰 সংবাদপত্রে শাহীন', 'edit_posts', 'sb-press', 'sb_page_press');
    add_submenu_page('sb-dashboard', 'বিজ্ঞাপন/লিংক ব্লক', '📚 ব্লক লাইব্রেরি', 'manage_options', 'sb-blocks', 'sb_page_blocks');
    add_submenu_page('sb-dashboard', 'মোবাইল মডেল', '📱 মোবাইল মডেল', 'manage_options', 'sb-phones', 'sb_page_phones');
    add_submenu_page('sb-dashboard', 'মেমোরি', '🧠 মেমোরি', 'edit_posts', 'sb-memory', 'sb_page_memory');
    add_submenu_page('sb-dashboard', 'সব পোস্ট', 'সব পোস্ট', 'edit_posts', 'sb-posts', 'sb_page_posts');
    add_submenu_page('sb-dashboard', 'ক্যাটাগরি', 'ক্যাটাগরি', 'manage_categories', 'sb-categories', 'sb_page_categories');
    add_submenu_page('sb-dashboard', 'সেটিংস', 'সেটিংস', 'manage_options', 'sb-settings', 'sb_page_settings');
}

function sb_is_our_screen() {
    return isset($_GET['page']) && strpos(sanitize_key(wp_unslash($_GET['page'])), 'sb-') === 0; // phpcs:ignore WordPress.Security.NonceVerification
}

add_action('admin_enqueue_scripts', 'sb_admin_assets');
function sb_admin_assets() {
    if (!sb_is_our_screen()) {
        return;
    }
    $page = sanitize_key(wp_unslash($_GET['page'])); // phpcs:ignore WordPress.Security.NonceVerification

    wp_enqueue_style(
        'sb-fonts',
        'https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;600;700&display=swap',
        array(),
        null
    );
    wp_enqueue_style('sb-admin', SB_URL . 'assets/css/admin.css', array('sb-fonts'), SB_VERSION . '.' . filemtime(SB_PATH . 'assets/css/admin.css'));
    wp_enqueue_script('sb-admin', SB_URL . 'assets/js/admin.js', array(), SB_VERSION . '.' . filemtime(SB_PATH . 'assets/js/admin.js'), true);

    if ($page === 'sb-phones') {
        wp_enqueue_script('sb-phones-admin', SB_URL . 'assets/js/phones-admin.js', array('sb-admin'), SB_VERSION . '.' . filemtime(SB_PATH . 'assets/js/phones-admin.js'), true);
    }
    if ($page === 'sb-memory') {
        wp_enqueue_script('sb-memory-admin', SB_URL . 'assets/js/memory-admin.js', array('sb-admin'), SB_VERSION . '.' . filemtime(SB_PATH . 'assets/js/memory-admin.js'), true);
    }
    if ($page === 'sb-social') {
        wp_enqueue_script('sb-social-admin', SB_URL . 'assets/js/social-admin.js', array('sb-admin'), SB_VERSION . '.' . filemtime(SB_PATH . 'assets/js/social-admin.js'), true);
    }
    if (in_array($page, array('sb-new', 'sb-press', 'sb-rewrite'), true)) {
        wp_enqueue_media();
    }

    $terms = get_terms(array('taxonomy' => 'sb_category', 'hide_empty' => false, 'orderby' => 'term_id'));
    $cats = array();
    if (!is_wp_error($terms)) {
        foreach ($terms as $t) {
            $cats[] = array('id' => (int) $t->term_id, 'name' => $t->name, 'en' => get_term_meta($t->term_id, 'sb_name_en', true));
        }
    }
    $costs = array();
    foreach (array_keys(sb_feature_labels()) as $f) {
        $costs[$f] = sb_feature_cost($f);
    }
    $costs['custom2'] = sb_custom_cost(2);
    $costs['custom3'] = sb_custom_cost(3);
    $costs['research'] = max(0, (int) sb_get('cost_research'));
    wp_localize_script('sb-admin', 'SB', array(
        'ajax'    => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('sb_admin'),
        'balance' => SB_Credits::state(get_current_user_id()),
        'costs'   => $costs,
        'modelPrices' => sb_model_prices(),
        'labels'  => sb_feature_labels(),
        'cats'    => $cats,
        'page'    => $page,
        'gblocks' => sb_block_library(),
        'ratios'  => sb_ratio_options(),
        'memAuto' => (int) sb_memory_cfg()['auto'],
        'postsUrl' => admin_url('admin.php?page=sb-posts'),
    ));
}

/** "Tell the AI how you want the post" box (used in the wizard and the rewrite page). */
function sb_edit_box($p) {
    ?>
    <div class="sba-ai sba-editbox" id="<?php echo esc_attr($p); ?>-box">
        <div class="sba-ai-head"><h3>✏️ পোস্ট কেমন চান — নির্দেশ দিন</h3></div>
        <p class="sba-muted">প্রতিটি ঘরে একটি পরিবর্তন লিখুন, আরও বদলাতে চাইলে <strong>＋</strong> চাপুন (সর্বোচ্চ ১০টি)। AI সবগুলো একসাথে করবে; বাকি লেখা যেমন আছে তেমন থাকবে।</p>
        <div class="sba-insrows" id="<?php echo esc_attr($p); ?>-rows"></div>
        <button type="button" class="button" id="<?php echo esc_attr($p); ?>-addrow">＋ আরেকটি পরিবর্তন যোগ করুন</button>
        <div class="sba-edit-modes">
            <label class="sba-inline-check"><input type="radio" name="<?php echo esc_attr($p); ?>-mode" value="edit" checked> শুধু সংশোধন <em>(১–৫টি পরিবর্তন <?php echo esc_html(sb_bn_digits(sb_feature_cost('edit'))); ?> ক্রেডিট, ৬–১০টি <?php echo esc_html(sb_bn_digits(sb_feature_cost('edit_many'))); ?>)</em> — বাকি লেখা যেমন আছে তেমন থাকবে, শুধু আপনার নির্দেশের অংশ বদলাবে</label>
            <label class="sba-inline-check"><input type="radio" name="<?php echo esc_attr($p); ?>-mode" value="edit_new"> নতুন করে লেখা <em>(<?php echo esc_html(sb_bn_digits(sb_feature_cost('edit_new'))); ?> ক্রেডিট)</em> — নির্দেশ মেনে পুরো লেখা নতুন করে তৈরি হবে</label>
        </div>
        <p><button type="button" class="button button-primary" id="<?php echo esc_attr($p); ?>-go">🤖 নির্দেশ অনুযায়ী করুন</button>
           <button type="button" class="button" id="<?php echo esc_attr($p); ?>-undo" hidden>↩️ আগের লেখা ফিরিয়ে আনুন</button></p>
    </div>
    <?php
}

/** Shared header: credit pill. */
function sb_credit_pill() {
    $st = SB_Credits::state(get_current_user_id());
    ?>
    <div class="sba-credit" id="sb-credit">
        <div class="sba-credit-top">
            <span>💳 ক্রেডিট</span>
            <strong><span data-sb="remaining"><?php echo esc_html(sb_bn_digits(number_format_i18n($st['remaining']))); ?></span> / <?php echo esc_html(sb_bn_digits(number_format_i18n($st['limit']))); ?></strong>
        </div>
        <div class="sba-bar"><div class="sba-bar-fill" data-sb="bar" style="width:<?php echo esc_attr($st['percent']); ?>%"></div></div>
        <small>রিসেট: <span data-sb="reset"><?php echo esc_html($st['next_reset_bn']); ?></span></small>
    </div>
    <?php
}

function sb_page_dashboard() { include SB_PATH . 'views/dashboard.php'; }
function sb_page_new()       { include SB_PATH . 'views/wizard.php'; }
function sb_page_rewrite()   { include SB_PATH . 'views/rewrite.php'; }
function sb_page_memory()    { include SB_PATH . 'views/memory.php'; }
function sb_page_social()    { include SB_PATH . 'views/social.php'; }
function sb_page_press()     { include SB_PATH . 'views/press.php'; }
function sb_page_phones()    { include SB_PATH . 'views/phones.php'; }
function sb_page_blocks()    { include SB_PATH . 'views/blocks.php'; }
function sb_page_posts()     { include SB_PATH . 'views/posts.php'; }
function sb_page_categories() { include SB_PATH . 'views/categories.php'; }
function sb_page_settings()  { include SB_PATH . 'views/settings.php'; }

/* ------------------------------------------------------ settings save */
add_action('admin_post_sb_save_settings', 'sb_handle_save_settings');
function sb_handle_save_settings() {
    if (!current_user_can('manage_options')) {
        wp_die('অনুমতি নেই।', 403);
    }
    check_admin_referer('sb_save_settings');
    if (!SB_Lock::is_unlocked()) {
        wp_die('সেটিংস লক করা আছে। PIN দিয়ে আনলক করুন।', 403);
    }

    $in = function ($k, $d = '') {
        return isset($_POST[$k]) ? wp_unslash($_POST[$k]) : $d; // phpcs:ignore WordPress.Security.NonceVerification
    };

    $new = array(
        'model'         => sb_pick_model($in, 'model') ?: 'gpt-4o-mini',
        'default_limit' => max(0, (int) $in('default_limit', 20000)),
        'hero_title'    => sanitize_text_field($in('hero_title')),
        'hero_subtitle' => sanitize_text_field($in('hero_subtitle')),
        'intro'         => sanitize_textarea_field($in('intro')),
        'show_intro'    => $in('show_intro') ? 1 : 0,
        'per_page'      => max(3, min(50, (int) $in('per_page', 12))),
        'full_width'    => $in('full_width') ? 1 : 0,
        'hide_theme_title' => $in('hide_theme_title') ? 1 : 0,
        'content_width' => max(0, min(1600, (int) $in('content_width', 0))),
        'top_offset'    => max(-300, min(300, (int) $in('top_offset', 0))),
        'model_article' => sb_pick_model($in, 'model_article'),
        'style_note'    => sanitize_textarea_field($in('style_note')),
        'cost_research' => max(0, (int) $in('cost_research', 50)),
        'site_urls'     => sanitize_textarea_field($in('site_urls')),
        'about'         => sanitize_textarea_field($in('about')),
    );
    $mp = array();
    $post_mp = isset($_POST['mp']) && is_array($_POST['mp']) ? wp_unslash($_POST['mp']) : array(); // phpcs:ignore WordPress.Security.NonceVerification
    foreach (sb_model_catalog() as $mid => $mm) {
        $row = isset($post_mp[$mid]) && is_array($post_mp[$mid]) ? array_values($post_mp[$mid]) : array();
        $mp[$mid] = array();
        for ($i = 0; $i < 3; $i++) {
            $v = isset($row[$i]) ? (int) $row[$i] : 0;
            $mp[$mid][$i] = $v > 0 ? $v : $mm[3][$i];
        }
    }
    $new['model_prices'] = $mp;
    foreach (array('headlines', 'subtitle', 'meta', 'article', 'summary', 'rewrite', 'custom', 'custom2', 'custom3', 'learn', 'edit', 'edit_many', 'phones', 'faq', 'related', 'chat') as $f) {
        $new['cost_' . $f] = max(0, (int) $in('cost_' . $f, 0));
    }
    // API key: keep the saved one unless a new one is typed or removal is ticked.
    $key = trim(sanitize_text_field($in('api_key')));
    if ($in('remove_key')) {
        $new['api_key'] = '';
    } elseif ($key !== '') {
        $new['api_key'] = $key;
    }
    sb_save_settings($new);
    wp_safe_redirect(admin_url('admin.php?page=sb-settings&saved=1'));
    exit;
}
