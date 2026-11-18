<?php
/**
 * One "Facebook" button in the menu (like SEO): a single page with tabs for everything Facebook.
 * The old separate pages stay reachable by their own address (hidden from the menu) so no link or bookmark breaks.
 */
if (!defined('ABSPATH')) {
    exit;
}

function sb_fbhub_tabs() {
    return array(
        'post'    => array('✍️ পোস্ট লিখুন', 'sb_page_fbpost'),
        'manager' => array('🗂 পোস্ট ম্যানেজার', 'sb_page_fbmanager'),
        'inbox'   => array('💬 ইনবক্স', 'sb_page_fbinbox'),
        'memory'  => array('🧠 উত্তরের মেমোরি', 'sb_page_fbmemory'),
        'share'   => array('🔁 অটো-শেয়ার ও সোশ্যাল', 'sb_page_social'),
        'token'   => array('🔑 টোকেন', 'sb_page_fbtoken'),
    );
}

add_action('admin_menu', function () {
    add_menu_page('Facebook', 'Facebook', 'manage_options', 'sb-facebook', 'sb_page_facebook', 'dashicons-facebook-alt', 27);
}, 31);

function sb_fbhub_tab() {
    $t = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification
    return isset(sb_fbhub_tabs()[$t]) ? $t : 'post';
}

function sb_page_facebook() {
    $tabs = sb_fbhub_tabs();
    $cur  = sb_fbhub_tab();
    ?>
    <div class="wrap sb-fbhub">
        <h1>📘 Facebook</h1>
        <h2 class="nav-tab-wrapper" style="margin-bottom:14px">
            <?php foreach ($tabs as $k => $t) : ?>
                <a class="nav-tab<?php echo $k === $cur ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=sb-facebook&tab=' . $k)); ?>"><?php echo esc_html($t[0]); ?></a>
            <?php endforeach; ?>
        </h2>
        <style>.sb-fbhub .wrap{margin:0;padding:0}.sb-fbhub .wrap>h1:first-child{display:none}</style>
        <?php
        $fn = $tabs[$cur][1];
        if (function_exists($fn)) {
            $fn();
        }
        ?>
    </div>
    <?php
}
