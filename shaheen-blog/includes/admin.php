<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'sb_admin_menu');
function sb_admin_menu() {
    add_menu_page('Blog Write .ai', 'Blog Write .ai', 'edit_posts', 'sb-dashboard', 'sb_page_dashboard', 'dashicons-welcome-write-blog', 26);
    add_submenu_page('sb-dashboard', 'Dashboard', 'Dashboard', 'edit_posts', 'sb-dashboard', 'sb_page_dashboard');
    add_submenu_page('sb-dashboard', 'New Post', 'New Post', 'edit_posts', 'sb-new', 'sb_page_new');
    add_submenu_page('sb-dashboard', 'Rewrite Post', '🔁 Rewrite Post', 'edit_posts', 'sb-rewrite', 'sb_page_rewrite');
    add_submenu_page('sb-dashboard', 'Social Media', '🌐 Social Media', 'manage_options', 'sb-social', 'sb_page_social');
    add_submenu_page('sb-dashboard', 'Press', '📰 Press', 'edit_posts', 'sb-press', 'sb_page_press');
    add_submenu_page('sb-dashboard', 'Block Library', '📚 Block Library', 'manage_options', 'sb-blocks', 'sb_page_blocks');
    add_submenu_page('sb-dashboard', 'Mobile Models', '📱 Mobile Models', 'manage_options', 'sb-phones', 'sb_page_phones');
    add_submenu_page('sb-dashboard', 'Memory', '🧠 Memory', 'edit_posts', 'sb-memory', 'sb_page_memory');
    add_submenu_page('sb-dashboard', 'All Posts', 'All Posts', 'edit_posts', 'sb-posts', 'sb_page_posts');
    add_submenu_page('sb-dashboard', 'Import', '📥 Import', 'edit_posts', 'sb-import', 'sb_page_import');
    add_submenu_page('sb-dashboard', 'Categories', 'Categories', 'manage_categories', 'sb-categories', 'sb_page_categories');
    add_submenu_page('sb-dashboard', 'Settings', 'Settings', 'manage_options', 'sb-settings', 'sb_page_settings');
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
        'h2p'     => SB_URL . 'assets/vendor/html2pdf.bundle.min.js',
        'balance' => SB_Credits::state(get_current_user_id()),
        'costs'   => $costs,
        'modelPrices' => sb_model_prices(),
        'labels'  => sb_feature_labels(),
        'cats'    => $cats,
        'page'    => $page,
        'gblocks' => sb_block_library(),
        'ratios'  => sb_ratio_options(),
        'memAuto' => (int) sb_memory_cfg()['auto'],
        'hasSrc'  => sb_src_has(get_current_user_id()) ? 1 : 0,
        'words'   => sb_words_all(),
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
            <label class="sba-inline-check"><input type="radio" name="<?php echo esc_attr($p); ?>-mode" value="edit" checked> শুধু সংশোধন <em>(১–৫টি পরিবর্তন <?php echo esc_html(sb_money(sb_feature_cost('edit'))); ?>, ৬–১০টি <?php echo esc_html(sb_bn_digits(sb_feature_cost('edit_many'))); ?>)</em> — বাকি লেখা যেমন আছে তেমন থাকবে, শুধু আপনার নির্দেশের অংশ বদলাবে</label>
            <label class="sba-inline-check"><input type="radio" name="<?php echo esc_attr($p); ?>-mode" value="edit_new"> নতুন করে লেখা <em>(<?php echo esc_html(sb_money(sb_feature_cost('edit_new'))); ?>)</em> — নির্দেশ মেনে পুরো লেখা নতুন করে তৈরি হবে</label>
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
            <span><?php echo $st['unlimited'] ? '💵 এই মাসের বিল' : '💵 ব্যালেন্স'; ?></span>
            <strong><span data-sb="remaining"><?php echo esc_html($st['remaining_usd']); ?></span><?php echo $st['unlimited'] ? '' : ' / ' . esc_html($st['limit_usd']); ?></strong>
        </div>
        <?php if (!$st['unlimited']) : ?><div class="sba-bar"><div class="sba-bar-fill" data-sb="bar" style="width:<?php echo esc_attr($st['percent']); ?>%"></div></div><?php endif; ?>
        <small><?php echo $st['unlimited'] ? 'মাস শেষে ভাউচার' : 'রিসেট: <span data-sb="reset">' . esc_html($st['next_reset_bn']) . '</span>'; ?></small>
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
        'model'         => sb_pick_model($in, 'model') ?: 'gpt-6.1-terra',
        'limit_usd'     => max(0, (float) $in('limit_usd', 32)),
        'report_email'  => is_email($in('report_email')) ? sanitize_email($in('report_email')) : '',
        'report_auto'   => $in('report_auto') ? 1 : 0,
        'flex_on'       => $in('flex_on') ? 1 : 0,
        'vat_pct'       => max(0, min(50, (float) $in('vat_pct', 5))),
        'client_email'  => is_email($in('client_email')) ? sanitize_email($in('client_email')) : '',
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
        'model_plan'    => sb_pick_model($in, 'model_plan'),
        'style_note'    => sanitize_textarea_field($in('style_note')),
        'site_urls'     => sanitize_textarea_field($in('site_urls')),
        'about'         => sanitize_textarea_field($in('about')),
        'ai_lang'       => in_array($in('ai_lang'), array('bn', 'en', 'other'), true) ? $in('ai_lang') : 'bn',
        'ai_lang_other' => sanitize_text_field($in('ai_lang_other')),
    );
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
