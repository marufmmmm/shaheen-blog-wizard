<?php
if (!defined('ABSPATH')) {
    exit;
}
$s = function ($k) { return sb_get($k); };
$has_key = (bool) sb_get('api_key');
$users = get_users(array('capability' => 'edit_posts', 'number' => 200));
if (!SB_Lock::is_unlocked()) : ?>
<div class="wrap sba">
    <h1>সেটিংস</h1>
    <div class="sba-card sba-lock">
        <h2>🔒 সেটিংস লক করা</h2>
        <p class="sba-muted">চালিয়ে যেতে PIN দিন। হিন্ট: <strong><?php echo esc_html(SB_Lock::HINT); ?></strong></p>
        <p><input type="password" id="pin" inputmode="numeric" maxlength="12" autocomplete="off" placeholder="PIN" style="max-width:200px">
           <button type="button" class="button button-primary" id="pin-go">আনলক</button></p>
        <p id="pin-msg" class="sba-msg"></p>
    </div>
</div>
<?php return; endif; ?>
<div class="wrap sba">
    <h1>সেটিংস <button type="button" class="button" id="pin-lock" style="margin-left:10px">🔒 লক করুন</button></h1>
    <?php if (!empty($_GET['saved'])) : // phpcs:ignore WordPress.Security.NonceVerification ?>
        <div class="notice notice-success is-dismissible"><p>সেটিংস সেভ হয়েছে।</p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sba-form">
        <input type="hidden" name="action" value="sb_save_settings">
        <?php wp_nonce_field('sb_save_settings'); ?>

        <div class="sba-card">
            <h2>🤖 AI (OpenAI)</h2>
            <label>OpenAI API Key
                <input type="password" name="api_key" value="" autocomplete="new-password" placeholder="<?php echo $has_key ? '••••••••••• (সংরক্ষিত আছে, বদলাতে নতুন কী লিখুন)' : 'sk-…'; ?>">
            </label>
            <?php if ($has_key) : ?><label class="sba-inline-check"><input type="checkbox" name="remove_key" value="1"> সংরক্ষিত কী মুছে ফেলুন</label><?php endif; ?>
            <label>মডেল
                <input type="text" name="model" value="<?php echo esc_attr($s('model')); ?>">
                <small>প্রস্তাবিত: gpt-4o-mini (সস্তা ও দ্রুত)</small>
            </label>
        </div>

        <div class="sba-card">
            <h2>💳 ক্রেডিট</h2>
            <label>মাসিক ক্রেডিট (ডিফল্ট)
                <input type="number" min="0" name="default_limit" value="<?php echo esc_attr($s('default_limit')); ?>">
                <small>২০,০০০ ক্রেডিট ≈ ৪ ডলার API খরচ।</small>
            </label>
            <div class="sba-row">
                <?php foreach (sb_feature_labels() as $f => $label) : ?>
                    <label><?php echo esc_html($label); ?><input type="number" min="0" name="cost_<?php echo esc_attr($f); ?>" value="<?php echo esc_attr($s('cost_' . $f)); ?>"></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="sba-card">
            <h2>🎨 ব্লগ পেজ</h2>
            <label>হিরো শিরোনাম<input type="text" name="hero_title" value="<?php echo esc_attr($s('hero_title')); ?>"></label>
            <label>হিরো সাবটাইটেল<input type="text" name="hero_subtitle" value="<?php echo esc_attr($s('hero_subtitle')); ?>"></label>
            <label>পরিচিতি লেখা<textarea name="intro" rows="4"><?php echo esc_textarea($s('intro')); ?></textarea></label>
            <label class="sba-inline-check"><input type="checkbox" name="show_intro" value="1" <?php checked($s('show_intro')); ?>> পরিচিতি লেখা দেখান</label>
            <label>প্রতিবারে কতটি পোস্ট দেখাবে<input type="number" min="3" max="50" name="per_page" value="<?php echo esc_attr($s('per_page')); ?>"></label>
            <label class="sba-inline-check"><input type="checkbox" name="full_width" value="1" <?php checked($s('full_width')); ?>> পুরো-প্রস্থ (full width) ডিজাইন</label>
            <label class="sba-inline-check"><input type="checkbox" name="hide_theme_title" value="1" <?php checked($s('hide_theme_title')); ?>> থিমের নিজস্ব পেজ শিরোনাম লুকান</label>
        </div>

        <p><button class="button button-primary button-hero">💾 সেটিংস সেভ করুন</button></p>
    </form>

    <div class="sba-card">
        <h2>🔁 সাবস্ক্রিপশন (ম্যানুয়াল)</h2>
        <p class="sba-muted">পেমেন্ট কোড যুক্ত হওয়ার আগে এখান থেকে ইউজারের সাবস্ক্রিপশন তারিখ ও ক্রেডিট সেট করুন। প্রতি মাসে এই তারিখে ক্রেডিট রিসেট হবে।</p>
        <div class="sba-row">
            <label>ইউজার
                <select id="sub-user">
                    <?php foreach ($users as $u) : ?><option value="<?php echo (int) $u->ID; ?>"><?php echo esc_html($u->display_name . ' (' . $u->user_login . ')'); ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>শুরুর তারিখ<input type="date" id="sub-date" value="<?php echo esc_attr(wp_date('Y-m-d')); ?>"></label>
            <label>ক্রেডিট লিমিট<input type="number" min="0" id="sub-limit" value="<?php echo esc_attr($s('default_limit')); ?>"></label>
        </div>
        <p><button type="button" class="button" id="sub-save">সেট করুন</button> <span id="sub-msg" class="sba-msg"></span></p>
    </div>

    <div class="sba-card">
        <h2>🧹 ডেমো পোস্ট</h2>
        <p class="sba-muted">প্লাগইনের সঙ্গে আসা ৬টি ডেমো পোস্ট এক ক্লিকে মুছে ফেলুন (নিজের পোস্টে হাত পড়বে না)।</p>
        <p><button type="button" class="button" id="demo-del">ডেমো পোস্ট মুছুন</button> <span id="demo-msg" class="sba-msg"></span></p>
    </div>
</div>
