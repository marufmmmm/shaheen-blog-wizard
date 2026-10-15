<?php
if (!defined('ABSPATH')) {
    exit;
}
$paged  = max(1, (int) (isset($_GET['paged']) ? $_GET['paged'] : 1)); // phpcs:ignore WordPress.Security.NonceVerification
$status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$cat    = isset($_GET['cat']) ? (int) $_GET['cat'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
$args = array(
    'post_type' => 'sb_post',
    'post_status' => in_array($status, array('publish', 'draft', 'pending'), true) ? $status : array('publish', 'draft', 'pending', 'future'),
    'posts_per_page' => 20,
    'paged' => $paged,
    's' => $search,
);
if ($cat) {
    $args['tax_query'] = array(array('taxonomy' => 'sb_category', 'field' => 'term_id', 'terms' => $cat));
}
$q = new WP_Query($args);
$cats = get_terms(array('taxonomy' => 'sb_category', 'hide_empty' => false));
$base = admin_url('admin.php?page=sb-posts');
?>
<div class="wrap sba">
    <h1>সব পোস্ট <a class="page-title-action" href="<?php echo esc_url(admin_url('admin.php?page=sb-new')); ?>">নতুন পোস্ট</a></h1>

    <form method="get" class="sba-filter">
        <input type="hidden" name="page" value="sb-posts">
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="খুঁজুন…">
        <select name="status">
            <option value="all">সব অবস্থা</option>
            <option value="publish" <?php selected($status, 'publish'); ?>>প্রকাশিত</option>
            <option value="draft" <?php selected($status, 'draft'); ?>>খসড়া</option>
        </select>
        <select name="cat">
            <option value="0">সব ক্যাটাগরি</option>
            <?php if (!is_wp_error($cats)) : foreach ($cats as $t) : ?>
                <option value="<?php echo (int) $t->term_id; ?>" <?php selected($cat, $t->term_id); ?>><?php echo esc_html($t->name); ?></option>
            <?php endforeach; endif; ?>
        </select>
        <button class="button">ফিল্টার</button>
    </form>

    <p class="sba-rp">
        <button type="button" class="button" id="rp-run">🤖 নির্বাচিত পোস্টে স্মার্ট সম্পর্কিত বানান (প্রতিটি <?php echo esc_html(sb_bn_digits(sb_feature_cost('related'))); ?> ক্রেডিট)</button>
        <label class="sba-inline-check"><input type="checkbox" id="rp-over"> যেগুলোতে আগে থেকে তালিকা আছে সেগুলোও বদলে দিন</label>
        <span id="rp-msg" class="sba-msg"></span><br>
        <small class="sba-muted">আগের পোস্টগুলোর জন্য: টিক দিয়ে বাটন চাপুন। AI সাইটের পোস্ট ও পেজ থেকে মিলগুলো বেছে পাশে বসাবে। কিছু না মিললে ক্রেডিট কাটে না।</small>
    </p>
    <table class="widefat striped sba-table">
        <thead><tr><td class="check-column"><input type="checkbox" id="rp-all"></td><th>শিরোনাম</th><th>ক্যাটাগরি</th><th>অবস্থা</th><th>তারিখ</th><th>দেখা</th><th>স্মার্ট সম্পর্কিত</th><th></th></tr></thead>
        <tbody>
        <?php if (!$q->have_posts()) : ?>
            <tr><td colspan="8">কোনো পোস্ট পাওয়া যায়নি।</td></tr>
        <?php else : while ($q->have_posts()) : $q->the_post(); $id = get_the_ID(); ?>
            <tr data-id="<?php echo (int) $id; ?>">
                <th class="check-column"><?php if (!sb_is_press($id)) : ?><input type="checkbox" class="rp-cb" value="<?php echo (int) $id; ?>"><?php endif; ?></th>
                <?php $edit_url = sb_is_press($id) ? admin_url('admin.php?page=sb-press&edit=' . $id) : admin_url('admin.php?page=sb-new&post=' . $id); ?>
                <td><strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html(get_the_title()); ?></a></strong>
                    <?php if (get_post_meta($id, '_sb_demo', true)) : ?><span class="sba-tag">ডেমো</span><?php endif; ?>
                    <?php if (sb_is_press($id)) : ?><span class="sba-tag">📰 সংবাদ</span><?php endif; ?></td>
                <td><?php echo esc_html(sb_post_category($id)); ?></td>
                <td><span class="sba-tag sba-tag--<?php echo esc_attr(get_post_status()); ?>"><?php echo get_post_status() === 'publish' ? 'প্রকাশিত' : 'খসড়া'; ?></span></td>
                <td><?php echo esc_html(sb_bn_date(get_post_time('U'))); ?></td>
                <td><?php echo esc_html(sb_bn_digits((int) get_post_meta($id, '_sb_views', true))); ?></td>
                <td class="rp-state"><?php echo sb_is_press($id) ? '—' : (array_filter((array) get_post_meta($id, '_sb_related', true)) ? '✅ আছে' : 'নেই (ক্যাটাগরি অনুযায়ী দেখাচ্ছে)'); ?></td>
                <td class="sba-actions">
                    <a href="<?php echo esc_url($edit_url); ?>">এডিট</a>
                    <?php if (get_post_status() === 'publish' && !sb_is_press($id)) : ?><a href="<?php echo esc_url(get_permalink()); ?>" target="_blank">দেখুন ↗</a><?php endif; ?>
                    <?php if (current_user_can('delete_post', $id)) : ?><a href="#" class="sba-trash" data-id="<?php echo (int) $id; ?>">ট্র্যাশ</a><?php endif; ?>
                </td>
            </tr>
        <?php endwhile; wp_reset_postdata(); endif; ?>
        </tbody>
    </table>
    <?php
    $links = paginate_links(array(
        'base' => add_query_arg('paged', '%#%', $base), 'format' => '', 'current' => $paged,
        'total' => max(1, $q->max_num_pages), 'type' => 'plain',
    ));
    if ($links) {
        echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($links) . '</div></div>';
    }
    ?>
</div>
