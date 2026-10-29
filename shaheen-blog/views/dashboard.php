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
    <h1>Blog Write .ai — Dashboard</h1>

    <div class="sba-grid">
        <div class="sba-card sba-card--credit">
            <h2>💵 AI Balance (USD)</h2>
            <p class="sba-big"><span data-sb="remaining"><?php echo esc_html($st['remaining_usd']); ?></span>
                <small>/ <?php echo esc_html($st['limit_usd']); ?></small></p>
            <div class="sba-bar"><div class="sba-bar-fill" style="width:<?php echo esc_attr($st['percent']); ?>%"></div></div>
            <p class="sba-muted">Used: <?php echo esc_html($st['used_usd']); ?> · Next reset: <strong><?php echo esc_html($st['next_reset_bn']); ?></strong></p>
        </div>
        <div class="sba-card">
            <h2>📰 Posts</h2>
            <p class="sba-big"><?php echo esc_html(sb_bn_digits((int) $count->publish)); ?> <small>published</small></p>
            <p class="sba-muted">Drafts: <?php echo esc_html(sb_bn_digits((int) $count->draft)); ?> · Categories: <?php echo esc_html(sb_bn_digits((int) $cats)); ?></p>
        </div>
        <div class="sba-card">
            <h2>⚡ Quick Start</h2>
            <p><a class="button button-primary button-hero" href="<?php echo esc_url(admin_url('admin.php?page=sb-new')); ?>">✍️ New Post</a></p>
            <p>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=sb-posts')); ?>">All Posts</a>
                <?php if ($blog_page && get_post_status($blog_page) === 'publish') : ?>
                    <a class="button" target="_blank" href="<?php echo esc_url(get_permalink($blog_page)); ?>">View blog page ↗</a>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="sba-grid sba-grid--2">
        <div class="sba-card">
            <h2>Recent Posts</h2>
            <?php if (!$recent) : ?>
                <p class="sba-muted">No posts yet.</p>
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
            <h2>Recent AI Use</h2>
            <?php if (!$log) : ?>
                <p class="sba-muted">এখনো কোনো AI ব্যবহার হয়নি।</p>
            <?php else : ?>
                <ul class="sba-list">
                    <?php foreach ($log as $row) : ?>
                        <li>
                            <?php echo esc_html(isset($labels[$row['feature']]) ? $labels[$row['feature']] : $row['feature']); ?>
                            <span class="sba-muted"><?php echo esc_html(sb_bn_date($row['t'], false)); ?></span>
                            <strong class="sba-minus">−<?php echo esc_html(sb_money($row['cost'])); ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="sba-card sba-how">
        <h2>How it works</h2>
        <ol>
            <li><strong>Type</strong> your title, keywords and prompt (attach a PDF or images if you have them). The prompt can be Bangla or Bijoy (ANSI).</li>
            <li><strong>Press Write.</strong> AI fills the article, subtitle, slug, meta, excerpt, summary and FAQ. Your title is never changed.</li>
            <li><strong>Change anything by chatting.</strong> Say what to change in the chat panel; AI rewrites only that part and everything else stays exactly the same. AI can look at your PDF/images again.</li>
            <li><strong>Replace hard words</strong> yourself. After saving, tick the words to remember; AI will never use them again.</li>
            <li><strong>Place buttons anywhere.</strong> Download buttons, PDFs and embeds can go at the editor cursor.</li>
        </ol>
    </div>

    <?php if (!sb_get('api_key')) : ?>
        <div class="notice notice-warning inline"><p>To use AI, add your OpenAI API key in <a href="<?php echo esc_url(admin_url('admin.php?page=sb-settings')); ?>">Settings</a>.</p></div>
    <?php endif; ?>
</div>
