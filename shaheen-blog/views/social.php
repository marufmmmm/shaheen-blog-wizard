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
            <h2>সর্বশেষ পোস্ট নিজে নিজে আসা</h2>
            <label class="sba-inline-check"><input type="checkbox" name="fb_embed" value="1" <?php checked($s['fb_embed']); ?>> ফেসবুক পেজের সর্বশেষ পোস্ট (টাইমলাইন) দেখাও <small>— ফেসবুক নিজেই নতুন পোস্ট এনে দেয়</small></label>
            <label>ফেসবুক প্লাগইন কেমন দেখাবে
                <select name="fb_mode"><option value="card" <?php selected($s['fb_mode'], 'card'); ?>>ছোট পেজ কার্ড (কভার ছবি + লাইক/ফলো বাটন)</option>
                <option value="timeline" <?php selected($s['fb_mode'], 'timeline'); ?>>সর্বশেষ পোস্টসহ বড় (ফেসবুক পোস্ট না দিলে ফাঁকা দেখায়)</option></select></label>
            <label>ইউটিউবের সর্বশেষ কয়টি ভিডিও ঘুরবে <small>(০ = বন্ধ; প্রতি ১ ঘণ্টায় নতুন ভিডিও খোঁজা হয়)</small>
                <input type="number" min="0" max="5" name="yt_count" value="<?php echo esc_attr($s['yt_count']); ?>" style="max-width:120px"></label>
            <label>ইনস্টাগ্রাম বা অন্য পোস্ট/ভিডিও/রিলের লিংক <small>(প্রতি লাইনে একটি, সর্বোচ্চ ১২টি। ইনস্টাগ্রামের সর্বশেষ পোস্ট নিজে নিজে আনার ফ্রি পথ নেই, তাই নতুন পোস্ট হলে এখানে লিংক যোগ করুন)</small>
                <textarea name="items" rows="5" placeholder="https://www.instagram.com/p/…"><?php echo esc_textarea($s['items']); ?></textarea></label>
        </div>

        <div class="sba-card">
            <h2>📋 এমবেড কোড বসান <small>(ফেসবুক Page Plugin, ইউটিউব, ইনস্টাগ্রাম)</small></h2>
            <p class="sba-muted">ফেসবুক/ইউটিউব/ইনস্টাগ্রাম থেকে কপি করা এমবেড কোড এখানে পেস্ট করুন (বা শুধু লিংক)। প্রতিটি কোডের মাঝে একটি ফাঁকা লাইন রাখুন। ফেসবুক Page Plugin-এ নিজের লাইক/ফলো বাটন থাকে, তাই আলাদা "লাইক দিন" বাটন আর দেখাবে না। নিরাপত্তার জন্য কোড থেকে শুধু ঠিকানা নিয়ে সাইট নিজের ফ্রেম বানায়।</p>
            <textarea name="codes" rows="7" style="font-family:monospace;font-size:12px" placeholder="&lt;div class=&quot;fb-page&quot; data-href=&quot;https://www.facebook.com/…&quot;&gt;&lt;/div&gt;"><?php echo esc_textarea($s['codes']); ?></textarea>
            <label class="sba-inline-check"><input type="checkbox" name="hide_btns" value="1" <?php checked($s['hide_btns']); ?>> নিচের লাইক/ফলো/সাবস্ক্রাইব বাটনগুলো সব লুকিয়ে রাখো</label>
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
