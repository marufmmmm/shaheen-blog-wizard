<?php
/**
 * Plugin Name: Shaheen Blog
 * Plugin URI:  https://shaheen.edu.bd
 * Description: শাহীন শিক্ষা পরিবারের ব্লগ: নিজস্ব ডিজাইনের ব্লগ পেজ, AI দিয়ে পোস্ট তৈরির উইজার্ড, ক্রেডিট সিস্টেম ও অ্যাডমিন প্যানেল।
 * Version:     2.0.0
 * Author:      Shaheen Education Family
 * Text Domain: shaheen-blog
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SB_VERSION', '2.5.0');
define('SB_FILE', __FILE__);
define('SB_PATH', plugin_dir_path(__FILE__));
define('SB_URL', plugin_dir_url(__FILE__));

require_once SB_PATH . 'includes/helpers.php';
require_once SB_PATH . 'includes/post-types.php';
require_once SB_PATH . 'includes/credits.php';
require_once SB_PATH . 'includes/know.php';
require_once SB_PATH . 'includes/ai.php';
require_once SB_PATH . 'includes/ajax.php';
require_once SB_PATH . 'includes/lock.php';
require_once SB_PATH . 'includes/blocks.php';
require_once SB_PATH . 'includes/social.php';
require_once SB_PATH . 'includes/frontend.php';
require_once SB_PATH . 'includes/demo.php';
if (is_admin()) {
    require_once SB_PATH . 'includes/admin.php';
}

register_activation_hook(__FILE__, 'sb_activate');
function sb_activate() {
    sb_register_types();
    sb_seed_categories();
    sb_create_blog_page();
    if (!get_option('sb_demo_done')) {
        sb_create_demo_posts();
        update_option('sb_demo_done', 1, false);
    }
    // Start the credit cycle for the installing user.
    SB_Credits::state(get_current_user_id());
    flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
