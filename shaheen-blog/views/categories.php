<?php
if (!defined('ABSPATH')) {
    exit;
}
$cats = get_terms(array('taxonomy' => 'sb_category', 'hide_empty' => false, 'orderby' => 'term_id'));
?>
<div class="wrap sba">
    <h1>ক্যাটাগরি</h1>
    <p class="sba-muted">ব্লগ পেজে "সকল" বাটনটি সবসময় থাকবে। নিচের ক্যাটাগরিগুলো ফিল্টার বাটন হিসেবে দেখাবে।</p>
    <div class="sba-grid sba-grid--2">
        <div class="sba-card">
            <h2>বর্তমান ক্যাটাগরি</h2>
            <table class="widefat striped">
                <thead><tr><th>বাংলা</th><th>ইংরেজি</th><th>পোস্ট</th><th></th></tr></thead>
                <tbody id="cat-rows">
                <?php if (!is_wp_error($cats)) : foreach ($cats as $t) : ?>
                    <tr data-id="<?php echo (int) $t->term_id; ?>">
                        <td><?php echo esc_html($t->name); ?></td>
                        <td><?php echo esc_html(get_term_meta($t->term_id, 'sb_name_en', true)); ?></td>
                        <td><?php echo esc_html(sb_bn_digits((int) $t->count)); ?></td>
                        <td><a href="#" class="sba-cat-del" data-id="<?php echo (int) $t->term_id; ?>">মুছুন</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="sba-card">
            <h2>নতুন ক্যাটাগরি</h2>
            <label>বাংলা নাম *<input type="text" id="nc-bn"></label>
            <label>ইংরেজি নাম <small>(ফিল্টার বাটনে দেখাবে)</small><input type="text" id="nc-en"></label>
            <p><button type="button" class="button button-primary" id="nc-add-page">যোগ করুন</button> <span id="msg" class="sba-msg"></span></p>
        </div>
    </div>
</div>
