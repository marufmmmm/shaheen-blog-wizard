<?php
if (!defined('ABSPATH')) {
    exit;
}
$type   = isset($_GET['type']) && $_GET['type'] === 'page' ? 'page' : 'post'; // phpcs:ignore WordPress.Security.NonceVerification
$paged  = max(1, (int) (isset($_GET['paged']) ? $_GET['paged'] : 1)); // phpcs:ignore WordPress.Security.NonceVerification
$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$q = new WP_Query(array(
    'post_type' => $type, 'post_status' => array('publish', 'draft', 'pending', 'future', 'private'),
    'posts_per_page' => 40, 'paged' => $paged, 's' => $search, 'orderby' => 'date', 'order' => 'DESC',
));
$map  = sb_imported_map();
$base = admin_url('admin.php?page=sb-import&type=' . $type . ($search !== '' ? '&s=' . rawurlencode($search) : ''));
?>
<div class="wrap sba" id="sb-import">
    <h1>📥 পুরনো পোস্ট ইম্পোর্ট</h1>
    <div class="sba-note">সাইটে আগে থেকে থাকা পোস্ট এখানে ব্লগে কপি হয়ে আসবে। তারপর ওয়িজার্ডে খুলে এডিট করতে পারবেন, চ্যাট এজেন্টকে দিয়ে নতুন করে লেখাতে পারবেন। <strong>ইম্পোর্ট বিনামূল্যে, ক্রেডিট লাগে না।</strong> মূল পোস্ট ততক্ষণ বদলায় না, যতক্ষণ আপনি নিচের বক্সে টিক দেন।</div>

    <form method="get" class="sba-filter">
        <input type="hidden" name="page" value="sb-import">
        <select name="type">
            <option value="post" <?php selected($type, 'post'); ?>>পোস্ট</option>
            <option value="page" <?php selected($type, 'page'); ?>>পেজ</option>
        </select>
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="খুঁজুন…">
        <button class="button">দেখান</button>
    </form>

    <div class="sba-card">
        <label>ইম্পোর্ট হওয়া পোস্টের অবস্থা
            <select id="im-status"><option value="draft">খসড়া (প্রস্তাবিত: আগে এডিট করে নিন)</option><option value="publish">সঙ্গে সঙ্গে প্রকাশিত</option></select></label>
        <label class="sba-inline-check"><input type="checkbox" id="im-hide"> ইম্পোর্টের পর মূল পোস্ট খসড়ায় নিন <small class="sba-muted">(একই লেখা দুটো লিংকে থাকলে গুগলে ডুপ্লিকেট হয়। যা ইচ্ছা পরে ফিরিয়ে প্রকাশ করা যায়)</small></label><br>
        <button type="button" class="button button-primary" id="im-run">📥 টিক দেওয়াগুলো ইম্পোর্ট করুন</button>
        <span id="im-msg" class="sba-msg"></span>
        <ul id="im-out" class="sba-list"></ul>
    </div>

    <table class="widefat striped sba-table">
        <thead><tr><td class="check-column"><input type="checkbox" id="im-all"></td><th>শিরোনাম</th><th>ক্যাটাগরি</th><th>অবস্থা</th><th>তারিখ</th><th>ইম্পোর্ট</th></tr></thead>
        <tbody>
        <?php if (!$q->have_posts()) : ?>
            <tr><td colspan="6">কিছু পাওয়া যায়নি।</td></tr>
        <?php else : while ($q->have_posts()) : $q->the_post(); $id = get_the_ID();
            $done = isset($map[$id]) ? $map[$id] : 0;
            $cats = $type === 'post' ? wp_list_pluck((array) get_the_terms($id, 'category'), 'name') : array(); ?>
            <tr data-id="<?php echo (int) $id; ?>">
                <th class="check-column"><?php if (!$done) : ?><input type="checkbox" class="im-cb" value="<?php echo (int) $id; ?>"><?php endif; ?></th>
                <td><strong><?php echo esc_html(get_the_title()); ?></strong></td>
                <td><?php echo esc_html(implode(', ', array_filter(array_map('strval', $cats)))); ?></td>
                <td><?php echo esc_html(get_post_status() === 'publish' ? 'প্রকাশিত' : 'খসড়া/অন্যান্য'); ?></td>
                <td><?php echo esc_html(sb_bn_date(get_post_time('U'))); ?></td>
                <td class="im-state"><?php if ($done) : ?>✅ <a href="<?php echo esc_url(admin_url('admin.php?page=sb-new&post=' . $done)); ?>">এডিট করুন</a><?php else : ?>—<?php endif; ?></td>
            </tr>
        <?php endwhile; wp_reset_postdata(); endif; ?>
        </tbody>
    </table>
    <?php
    $links = paginate_links(array('base' => add_query_arg('paged', '%#%', $base), 'format' => '', 'current' => $paged, 'total' => max(1, $q->max_num_pages), 'type' => 'plain'));
    if ($links) {
        echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($links) . '</div></div>';
    }
    ?>
</div>
