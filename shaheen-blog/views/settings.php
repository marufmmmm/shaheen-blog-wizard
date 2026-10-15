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
<?php
$cat = sb_model_catalog();
$render = function ($name, $cur, $blank) use ($cat) {
    $known = isset($cat[$cur]);
    echo '<select name="' . esc_attr($name) . '_sel" class="sb-model-sel" data-for="' . esc_attr($name) . '">';
    if ($blank) { echo '<option value=""' . selected($cur, '', false) . '>— ওপরের মডেলই ব্যবহার হবে —</option>'; }
    foreach ($cat as $id => $m) {
        $t = $id . ' — আর্টিকেলে ~' . implode(' / ', array_map('sb_bn_digits', $m[1])) . ' ক্রেডিট খরচ — ' . $m[2];
        echo '<option value="' . esc_attr($id) . '" data-est="' . esc_attr(implode(',', $m[1])) . '"' . selected($cur, $id, false) . '>' . esc_html($t) . '</option>';
    }
    echo '<option value="__other"' . selected(!$known && $cur !== '', true, false) . '>অন্য মডেল (নিজে লিখব)</option></select> ';
    echo '<input type="text" name="' . esc_attr($name) . '_other" value="' . esc_attr(!$known ? $cur : '') . '" placeholder="মডেলের নাম" style="' . ($known || $cur === '' ? 'display:none;' : '') . 'margin-top:6px">';
};
?>
            <label>মডেল <small>(শিরোনাম, সাবটাইটেল, মেটা, সামারি, FAQ ইত্যাদির জন্য)</small>
                <?php $render('model', $s('model'), false); ?>
                <small>প্রস্তাবিত: gpt-4o-mini (সস্তা ও দ্রুত)। বাংলা মান ভালো চাইলে নিচে আর্টিকেলের মডেল আলাদা বাছুন।</small>
            </label>
            <label>লেখার ভাষা ও স্টাইল নির্দেশনা <small>(সব AI লেখায় মানা হবে)</small>
                <textarea name="style_note" rows="3"><?php echo esc_textarea($s('style_note')); ?></textarea></label>
            <label>আর্টিকেলের জন্য আলাদা মডেল <small>(প্রম্পট থেকে লেখা, সংশোধন, রিরাইট)</small>
                <?php $render('model_article', $s('model_article'), true); ?>
                <small id="sb-model-warn" style="display:block;font-weight:600"></small>
                <small>তালিকার ক্রেডিট সংখ্যা আনুমানিক খরচ: ৮০০–১০০০ / ১০০০–১৫০০ / ১৫০০+ শব্দের আর্টিকেলে (১ ক্রেডিট = ০.০০০২ ডলার; ইন্টারনেট যাচাইয়ের খরচ আলাদা)। আপনার চার্জের চেয়ে খরচ বেশি হলে নিচে লাল সতর্কতা দেখাবে। আসল খরচ OpenAI Usage পেজে মিলিয়ে নিন।</small>
            </label>
        </div>

        <div class="sba-card">
            <h2>💳 ক্রেডিট</h2>
            <label>মাসিক ক্রেডিট (ডিফল্ট)
                <input type="number" min="0" name="default_limit" value="<?php echo esc_attr($s('default_limit')); ?>">
                <small>২০,০০০ ক্রেডিট ≈ ৪ ডলার API খরচ।</small>
            </label>
            <div class="sba-row">
                <?php foreach (sb_feature_labels() as $f => $label) : if (in_array($f, array('rewrite_post', 'edit_new'), true)) { continue; } ?>
                    <label><?php echo esc_html($label); ?><input type="number" min="0" name="cost_<?php echo esc_attr($f); ?>" value="<?php echo esc_attr($s('cost_' . $f)); ?>"></label>
                <?php endforeach; ?>
                <label>নিজের প্রম্পটে আর্টিকেল: ১০০০–১৫০০ শব্দ<input type="number" min="0" name="cost_custom2" value="<?php echo esc_attr($s('cost_custom2')); ?>"></label>
                <label>নিজের প্রম্পটে আর্টিকেল: ১৫০০+ শব্দ<input type="number" min="0" name="cost_custom3" value="<?php echo esc_attr($s('cost_custom3')); ?>"></label>
            </div>
        </div>

        <div class="sba-card">
            <h2>📐 মাপ — ব্লগ পেজ, হোম পেজ ও সব পোস্টের জন্য একসাথে</h2>
            <p class="sba-muted">এখানে একবার বদলালে ব্লগ পেজ, <code>[shaheen_blog]</code> শর্টকোড বসানো যেকোনো পেজ (হোম পেজ সহ) এবং সব পোস্ট পেজের কনটেন্টের প্রস্থ একসাথে বদলে যাবে।</p>
            <div class="sba-row">
                <label>কনটেন্টের প্রস্থ (px) <small>(০ = থিমের হেডারের মাপে নিজে মিলবে। যেমন: ১১৪০, ১২০০)</small>
                    <input type="number" min="0" max="1600" name="content_width" value="<?php echo esc_attr($s('content_width')); ?>"></label>
                <label>হেডারের নিচের ফাঁকা সমন্বয় (px) <small>(০ = নিজে ঠিক হবে; আরও ফাঁকা চাইলে +, কমাতে −)</small>
                    <input type="number" min="-300" max="300" name="top_offset" value="<?php echo esc_attr($s('top_offset')); ?>"></label>
            </div>
        </div>

        <div class="sba-card">
            <h2>🏫 শাহীন সম্পর্কে AI-এর তথ্য ও ডিপ রিসার্চ</h2>
            <p class="sba-muted">আর্টিকেল লেখার সময় AI এই তথ্য ও ওয়েবসাইট থেকে শাহীন সম্পর্কে জেনে নেবে। কোনো তথ্য না পেলে অনুমান না করে লেখায় <code>[তথ্য দরকার: …]</code> বসিয়ে দেবে, যাতে আপনি পূরণ করতে পারেন।</p>
            <label>আমাদের ওয়েবসাইট লিংক <small>(প্রতি লাইনে একটি, সর্বোচ্চ ৪টি; ১২ ঘণ্টা পরপর নতুন করে পড়া হয়)</small>
                <textarea name="site_urls" rows="3"><?php echo esc_textarea($s('site_urls')); ?></textarea></label>
            <label>শাহীন সম্পর্কে আমাদের নিজস্ব তথ্য <small>(শাখা, ফি, যোগাযোগ, বৈশিষ্ট্য, ফলাফল ইত্যাদি, যা AI সবসময় জানবে)</small>
                <textarea name="about" rows="8"><?php echo esc_textarea($s('about')); ?></textarea></label>
            <label>ইন্টারনেট রিসার্চের অতিরিক্ত ক্রেডিট <small>(শুধু রিসার্চ সফল হলে কাটে)</small>
                <input type="number" min="0" name="cost_research" value="<?php echo esc_attr($s('cost_research')); ?>"></label>
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
    <script>
    (function () {
        var q = function (s) { return document.querySelector(s); };
        function sync() {
            document.querySelectorAll('.sb-model-sel').forEach(function (sel) {
                var o = q('input[name="' + sel.dataset.for + '_other"]'); if (o) { o.style.display = sel.value === '__other' ? '' : 'none'; }
            });
            var sel = q('select[name="model_article_sel"]'), base = q('select[name="model_sel"]'), w = q('#sb-model-warn');
            var opt = (sel.value ? sel : base).selectedOptions[0];
            if (!opt || !opt.dataset.est) { w.textContent = ''; return; }
            var est = opt.dataset.est.split(',').map(Number), prices = ['cost_custom', 'cost_custom2', 'cost_custom3'].map(function (n) { return parseInt((q('input[name="' + n + '"]') || {}).value, 10) || 0; });
            var bad = []; est.forEach(function (e, i) { if (e > prices[i]) { bad.push((i === 0 ? '৮০০–১০০০' : i === 1 ? '১০০০–১৫০০' : '১৫০০+') + ' শব্দ: খরচ ~' + e + ' > চার্জ ' + prices[i]); } });
            w.style.color = bad.length ? '#b32d2e' : '#1a6b3e';
            w.textContent = bad.length ? '⚠️ এই মডেলে লোকসান হবে — ' + bad.join('; ') + '। চার্জ বাড়ান বা সস্তা মডেল বাছুন।' : '✓ তিনটি দৈর্ঘ্যেই খরচ আপনার চার্জের ভেতরে।';
        }
        document.addEventListener('change', sync); document.addEventListener('input', function (e) { if (/^cost_custom/.test(e.target.name || '')) { sync(); } });
        sync();
    })();
    </script>

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
