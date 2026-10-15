<?php
if (!defined('ABSPATH')) {
    exit;
}
$s = sb_social_get();
?>
<div class="wrap sba">
    <h1>🌐 সোশ্যাল মিডিয়া</h1>
    <?php if (!empty($_GET['saved'])) : // phpcs:ignore WordPress.Security.NonceVerification ?>
        <div class="notice notice-success is-dismissible"><p>সেভ হয়েছে।</p></div>
    <?php endif; ?>
    <p class="sba-muted">ফেসবুক, ইউটিউব ও ইনস্টাগ্রাম পেজের লিংক দিন। পোস্টের মাঝখানে একটি বক্স আসবে: নিচে লাইক/ফলো/সাবস্ক্রাইব বাটন, ওপরে একটি একটি করে বদলে যাওয়া সর্বশেষ পোস্ট বা ভিডিও (নিজে নিজে ঘোরে, প্রতিবার একটি)। কোনো ক্রেডিট লাগে না।</p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sba-form">
        <input type="hidden" name="action" value="sb_save_social">
        <?php wp_nonce_field('sb_save_social'); ?>

        <div class="sba-card">
            <h2>পেজের লিংক ও বাটনের লেখা</h2>
            <div class="sba-row">
                <label>ফেসবুক পেজের লিংক<input type="text" name="fb" value="<?php echo esc_attr($s['fb']); ?>" placeholder="https://www.facebook.com/…"></label>
                <label>বাটনের লেখা<input type="text" name="fb_label" value="<?php echo esc_attr($s['fb_label']); ?>"></label>
            </div>
            <div class="sba-row">
                <label>ইউটিউব চ্যানেলের লিংক <small>(@নাম বা /channel/… যেকোনোটি)</small><input type="text" name="yt" value="<?php echo esc_attr($s['yt']); ?>" placeholder="https://www.youtube.com/@…"></label>
                <label>বাটনের লেখা<input type="text" name="yt_label" value="<?php echo esc_attr($s['yt_label']); ?>"></label>
            </div>
            <div class="sba-row">
                <label>ইনস্টাগ্রাম পেজের লিংক<input type="text" name="ig" value="<?php echo esc_attr($s['ig']); ?>" placeholder="https://www.instagram.com/…"></label>
                <label>বাটনের লেখা<input type="text" name="ig_label" value="<?php echo esc_attr($s['ig_label']); ?>"></label>
            </div>
            <label>বক্সের শিরোনাম<input type="text" name="title" value="<?php echo esc_attr($s['title']); ?>"></label>
        </div>

        <div class="sba-card">
            <h2>📦 বক্সের ভেতরে কী কী দেখাবে <small>(নিজের মতো যোগ/বাদ করুন)</small></h2>
            <p class="sba-muted">প্রতিটি আইটেম আলাদা: শুধু ফেসবুক লাইক বাটন, সর্বশেষ পোস্টসহ বড় বক্স, ইউটিউবের সর্বশেষ ভিডিও, নির্দিষ্ট পোস্ট, বা এমবেড কোড। <strong>👁 দেখুন</strong> চাপলে সেভ করার আগেই এখানে দেখা যাবে। ফেসবুক প্লাগইন বক্সের চওড়ার সাথে নিজে মিলে যায়, দুই পাশে ফাঁকা থাকে না।</p>
            <div id="soc-items"></div>
            <p><button type="button" class="button" id="soc-add">＋ নতুন আইটেম যোগ করুন</button></p>
            <input type="hidden" name="items_json" id="soc-json">
            <div class="sba-row">
                <label>একাধিক আইটেম থাকলে কীভাবে দেখাবে
                    <select name="layout">
                        <option value="rotate" <?php selected($s['layout'], 'rotate'); ?>>একটি একটি করে ঘুরবে (জায়গা কম লাগে)</option>
                        <option value="stack" <?php selected($s['layout'], 'stack'); ?>>সবগুলো একসাথে, ওপর-নিচে</option>
                    </select></label>
                <label class="sba-inline-check"><input type="checkbox" name="hide_btns" value="1" <?php checked($s['hide_btns']); ?>> নিচের লাইক/ফলো/সাবস্ক্রাইব বাটনগুলো সব লুকিয়ে রাখো</label>
            </div>
        </div>

        <div class="sba-card">
            <h2>কোথায় ও কত সময় পরপর বদলাবে</h2>
            <div class="sba-row">
                <label>পোস্টের ভেতরে কোথায় দেখাবে
                    <select name="pos">
                        <option value="middle" <?php selected($s['pos'], 'middle'); ?>>লেখার মাঝখানে</option>
                        <option value="start" <?php selected($s['pos'], 'start'); ?>>প্রথম অনুচ্ছেদের পর</option>
                        <option value="end" <?php selected($s['pos'], 'end'); ?>>লেখার শেষে</option>
                        <option value="off" <?php selected($s['pos'], 'off'); ?>>পোস্টে দেখাবো না</option>
                    </select></label>
                <label>কত সেকেন্ড পরপর পরেরটি আসবে<input type="number" min="4" max="30" name="interval" value="<?php echo esc_attr($s['interval']); ?>"></label>
            </div>
            <p class="sba-muted">যেকোনো পেজে (যেমন হোম পেজ) বসাতে শর্টকোড: <code>[shaheen_social]</code>। কোনো পোস্টে না চাইলে ওয়িজার্ডের ধাপ ৩-এ টিক দিন।</p>
        </div>

        <p><button class="button button-primary button-hero">💾 সেভ করুন</button></p>
    </form>
</div>
<script>window.SBSOC = <?php echo wp_json_encode(array('items' => sb_social_items(), 'types' => sb_social_item_types(), 'h' => array('fb_like' => 40, 'fb_card' => 135, 'fb_timeline' => 500, 'fb_post' => 560, 'ig' => 620))); ?>;</script>
