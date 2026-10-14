<?php
if (!defined('ABSPATH')) {
    exit;
}
$uid   = get_current_user_id();
$st    = SB_Credits::state($uid);
$count = wp_count_posts('sb_post');
$cats  = wp_count_terms(array('taxonomy' => 'sb_category', 'hide_empty' => false));
$recent = get_posts(array('post_type' => 'sb_post', 'post_status' => array('publish', 'draft', 'pending', 'future'), 'numberposts' => 6));
$log = SB_Credits::log($uid, 8);
$labels = sb_feature_labels();
$blog_page = (int) get_option('sb_blog_page_id');
?>
<div class="wrap sba">
    <h1>শাহীন ব্লগ</h1>

    <div class="sba-grid">
        <div class="sba-card sba-card--credit">
            <h2>💳 ক্রেডিট ব্যালেন্স</h2>
            <p class="sba-big"><span data-sb="remaining"><?php echo esc_html(sb_bn_digits(number_format_i18n($st['remaining']))); ?></span>
                <small>/ <?php echo esc_html(sb_bn_digits(number_format_i18n($st['limit']))); ?></small></p>
            <div class="sba-bar"><div class="sba-bar-fill" style="width:<?php echo esc_attr($st['percent']); ?>%"></div></div>
            <p class="sba-muted">ব্যবহৃত: <?php echo esc_html(sb_bn_digits(number_format_i18n($st['used']))); ?> · পরবর্তী রিসেট: <strong><?php echo esc_html($st['next_reset_bn']); ?></strong></p>
        </div>
        <div class="sba-card">
            <h2>📰 পোস্ট</h2>
            <p class="sba-big"><?php echo esc_html(sb_bn_digits((int) $count->publish)); ?> <small>প্রকাশিত</small></p>
            <p class="sba-muted">খসড়া: <?php echo esc_html(sb_bn_digits((int) $count->draft)); ?> · ক্যাটাগরি: <?php echo esc_html(sb_bn_digits((int) $cats)); ?></p>
        </div>
        <div class="sba-card">
            <h2>⚡ দ্রুত কাজ</h2>
            <p><a class="button button-primary button-hero" href="<?php echo esc_url(admin_url('admin.php?page=sb-new')); ?>">✍️ নতুন পোস্ট তৈরি করুন</a></p>
            <p>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=sb-posts')); ?>">সব পোস্ট</a>
                <?php if ($blog_page && get_post_status($blog_page) === 'publish') : ?>
                    <a class="button" target="_blank" href="<?php echo esc_url(get_permalink($blog_page)); ?>">ব্লগ পেজ দেখুন ↗</a>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="sba-grid sba-grid--2">
        <div class="sba-card">
            <h2>সাম্প্রতিক পোস্ট</h2>
            <?php if (!$recent) : ?>
                <p class="sba-muted">এখনো কোনো পোস্ট নেই।</p>
            <?php else : ?>
                <ul class="sba-list">
                    <?php foreach ($recent as $p) : ?>
                        <li>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=sb-new&post=' . $p->ID)); ?>"><?php echo esc_html(get_the_title($p)); ?></a>
                            <span class="sba-tag sba-tag--<?php echo esc_attr($p->post_status); ?>"><?php echo $p->post_status === 'publish' ? 'প্রকাশিত' : 'খসড়া'; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="sba-card">
            <h2>সাম্প্রতিক ক্রেডিট খরচ</h2>
            <?php if (!$log) : ?>
                <p class="sba-muted">এখনো কোনো AI ব্যবহার হয়নি।</p>
            <?php else : ?>
                <ul class="sba-list">
                    <?php foreach ($log as $row) : ?>
                        <li>
                            <?php echo esc_html(isset($labels[$row['feature']]) ? $labels[$row['feature']] : $row['feature']); ?>
                            <span class="sba-muted"><?php echo esc_html(sb_bn_date($row['t'], false)); ?></span>
                            <strong class="sba-minus">−<?php echo esc_html(sb_bn_digits($row['cost'])); ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!sb_get('api_key')) : ?>
        <div class="notice notice-warning inline"><p>AI ব্যবহার করতে <a href="<?php echo esc_url(admin_url('admin.php?page=sb-settings')); ?>">সেটিংসে</a> OpenAI API কী যোগ করুন।</p></div>
    <?php endif; ?>
</div>
