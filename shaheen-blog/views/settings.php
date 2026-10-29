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
    <nav class="nav-tab-wrapper"><a class="nav-tab nav-tab-active" href="<?php echo esc_url(admin_url('admin.php?page=sb-settings')); ?>">Blog Write .ai</a><?php if (function_exists('csa_install')) : ?><a class="nav-tab" href="<?php echo esc_url(admin_url('admin.php?page=csa-settings')); ?>">Site Agent &amp; SEO</a><?php endif; ?></nav>
    <?php if (!empty($_GET['saved'])) : // phpcs:ignore WordPress.Security.NonceVerification ?>
        <div class="notice notice-success is-dismissible"><p>সেটিংস সেভ হয়েছে।</p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sba-form">
        <input type="hidden" name="action" value="sb_save_settings">
        <?php wp_nonce_field('sb_save_settings'); ?>

        <div class="sba-card">
            <h2>🤖 AI (OpenAI)</h2>
            <label>AI writing language
                <select name="ai_lang" id="ai-lang">
                    <option value="bn" <?php selected($s('ai_lang'), 'bn'); ?>>Bangla (default)</option>
                    <option value="en" <?php selected($s('ai_lang'), 'en'); ?>>English</option>
                    <option value="other" <?php selected($s('ai_lang'), 'other'); ?>>Other language…</option>
                </select>
                <input type="text" name="ai_lang_other" value="<?php echo esc_attr($s('ai_lang_other')); ?>" placeholder="Other language name, e.g. Arabic, Hindi" style="margin-top:6px">
                <small>All articles, summaries, FAQ and chat answers are written in this language. Your typed instructions can be in any language.</small>
            </label>
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
        $t = $id . ' — আর্টিকেলে ~' . implode(' / ', array_map('sb_money', $m[1])) . ' খরচ — ' . $m[2];
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
                <small>প্রতিটি মডেলের চার্জ নিচের তালিকায় ঠিক করুন। লেখার সময় পোস্ট এডিটরেও মডেল বাছা যাবে।</small>
            </label>
            <label>➕ নিজের মডেল যোগ করুন <small>(OpenAI অ্যাকাউন্টে যে মডেলের API নাম আছে, প্রতি লাইনে একটি। চাইলে চার্জও দিন: <code>মডেল-নাম | ৮০০–১০০০ শব্দের চার্জ | ১০০০–১৫০০ | ১৫০০+</code>)</small>
                <textarea name="extra_models" rows="3" placeholder="model-id-here | 400 | 550 | 750"><?php echo esc_textarea($s('extra_models')); ?></textarea>
                <small>সেভ করলে এই মডেল পোস্ট এডিটরের মডেল-তালিকায় ও ওপরের সিলেক্টে আসবে। নামটি OpenAI-র API নামের সাথে হুবহু মিলতে হবে, নইলে লেখার সময় OpenAI ত্রুটি দেবে।</small>
            </label>
            <h3 style="margin-top:18px">💰 মডেল অনুযায়ী আর্টিকেলের চার্জ <small>(ভেতরের ক্রেডিট এককে; ইউজার এর ডলার-দাম দেখে)</small></h3>
            <p class="sba-muted">১ ক্রেডিট = ০.০০০২ ডলার আসল খরচ, মুনাফা যোগ হয়ে ইউজারকে দেখায় <?php echo esc_html(sb_fmt_usd(sb_unit_billed())); ?> (ইউজার শুধু ডলার দেখে, ক্রেডিট দেখে না)। "আনুমানিক খরচ" বাংলায় ১ শব্দ ≈ ৪ টোকেন ধরে হিসাব, ইনপুট ফাইলসহ। লাল ঘর মানে চার্জ খরচের চেয়ে কম, অর্থাৎ লোকসান। ইন্টারনেট যাচাইয়ের চার্জ আলাদা (নিচে "ইন্টারনেট রিসার্চের অতিরিক্ত ক্রেডিট")।</p>
            <div style="overflow-x:auto"><table class="widefat striped" id="sb-mp" style="max-width:900px">
                <thead><tr><th>মডেল</th><th>দাম (ইনপুট/আউটপুট $/১০ লাখ টোকেন)</th><th>৮০০–১০০০ শব্দ<br><small>আনুমানিক খরচ → চার্জ</small></th><th>১০০০–১৫০০ শব্দ<br><small>আনুমানিক খরচ → চার্জ</small></th><th>১৫০০+ শব্দ<br><small>আনুমানিক খরচ → চার্জ</small></th></tr></thead>
                <tbody>
                <?php $mprices = sb_model_prices(); foreach (sb_model_catalog() as $mid => $mm) : ?>
                    <tr><td><strong><?php echo esc_html($mid); ?></strong><br><small><?php echo esc_html($mm[2]); ?></small></td>
                        <td><?php echo esc_html($mm[0]); ?></td>
                        <?php for ($i = 0; $i < 3; $i++) : ?>
                        <td>~<?php echo esc_html(sb_bn_digits($mm[1][$i])); ?> → <input type="number" min="1" data-est="<?php echo (int) $mm[1][$i]; ?>" name="mp[<?php echo esc_attr($mid); ?>][]" value="<?php echo (int) $mprices[$mid][$i]; ?>" style="width:90px"></td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
        </div>

        <div class="sba-card">
            <h2>💵 USD বিলিং</h2>
            <label>মুনাফা (margin) %
                <input type="number" min="0" max="90" step="0.5" name="usd_margin" value="<?php echo esc_attr($s('usd_margin')); ?>">
                <small>৫০ দিলে $১ আসল খরচ = $২ দেখাবে (বিল = খরচ ÷ (১ − মুনাফা%))। এখন: $১ খরচ → <b><?php echo esc_html(sb_fmt_usd(sb_mult())); ?></b></small>
            </label>
            <label>মাসিক লিমিট (USD)
                <input type="number" min="0" step="0.5" name="limit_usd" value="<?php echo esc_attr($s('limit_usd')); ?>">
                <small><b>০ = সীমাহীন</b> (ক্লায়েন্ট যত ব্যবহার করবে তত বিল)। অংক দিলে এই ডলার শেষ হলে মাসের বাকি সময় AI বন্ধ।</small>
            </label>
            <label>VAT %
                <input type="number" min="0" max="50" step="0.5" name="vat_pct" value="<?php echo esc_attr($s('vat_pct')); ?>">
                <small>ভাউচারে মাসিক API স্টেটমেন্টের সাথে এই হারে VAT যোগ হয় (ডিফল্ট ৫%)।</small>
            </label>
            <label>মাসিক রিপোর্ট কোন ইমেইলে যাবে
                <input type="email" name="report_email" value="<?php echo esc_attr($s('report_email')); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                <small>খালি রাখলে সাইটের অ্যাডমিন ইমেইলে যাবে।</small>
            </label>
            <label>ক্লায়েন্টের ইমেইল (ঐচ্ছিক)
                <input type="email" name="client_email" value="<?php echo esc_attr($s('client_email')); ?>" placeholder="client@example.com">
                <small>দিলে মাস শেষে ক্লায়েন্টও শুধু বিল ও ভাউচার পাবে (আসল খরচ ও মুনাফা পাবে না)।</small>
            </label>
            <label class="sba-inline-check"><input type="checkbox" name="report_auto" value="1" <?php checked((int) $s('report_auto')); ?>> প্রতি মাস শেষে নিজে নিজে ইমেইল পাঠান ও ভাউচার বানান</label>
            <div class="sba-row">
                <label>ভাউচারে "কে বিল দিচ্ছে" (আপনার নাম/কোম্পানি)<input type="text" name="bill_from" value="<?php echo esc_attr($s('bill_from')); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></label>
                <label>ভাউচারে "কাকে" (গ্রাহকের নাম)<input type="text" name="bill_to" value="<?php echo esc_attr($s('bill_to')); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></label>
            </div>
            <div class="sba-row">
                <label>আপনার ঠিকানা/ফোন/ইমেইল (প্রতি লাইনে একটি)<textarea name="bill_from_addr" rows="4"><?php echo esc_textarea($s('bill_from_addr')); ?></textarea></label>
                <label>গ্রাহকের ঠিকানা (প্রতি লাইনে একটি)<textarea name="bill_to_addr" rows="4"><?php echo esc_textarea($s('bill_to_addr')); ?></textarea></label>
            </div>
            <p class="sba-muted">ভাউচার ও মাসের হিসাব দেখুন: <a href="<?php echo esc_url(admin_url('admin.php?page=sb-billing')); ?>">💵 USD Billing</a></p>
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
            <label>ইন্টারনেট রিসার্চের অতিরিক্ত চার্জ (ক্রেডিট এককে) <small>(শুধু রিসার্চ সফল হলে কাটে)</small>
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
        function sync() {
            document.querySelectorAll('.sb-model-sel').forEach(function (sel) {
                var o = document.querySelector('input[name="' + sel.dataset.for + '_other"]'); if (o) { o.style.display = sel.value === '__other' ? '' : 'none'; }
            });
            document.querySelectorAll('#sb-mp input[data-est]').forEach(function (i) {
                var low = (parseInt(i.value, 10) || 0) < parseInt(i.dataset.est, 10);
                i.style.background = low ? '#fde8e8' : ''; i.style.borderColor = low ? '#b32d2e' : '';
                i.title = low ? 'চার্জ আনুমানিক খরচের চেয়ে কম — লোকসান' : '';
            });
        }
        document.addEventListener('change', sync); document.addEventListener('input', sync); sync();
    })();
    </script>

    <div class="sba-card">
        <h2>🔄 ব্যালেন্স রিফ্রেশ</h2>
        <p class="sba-muted">এক ক্লিকে ব্যবহৃত ক্রেডিট ০ হয়ে যাবে, আজ থেকে নতুন এক মাসের চক্র শুরু হবে এবং নিচের লিমিট সবার ডিফল্ট হিসেবে সেভ হবে।</p>
        <div class="sba-row">
            <label>ব্যালেন্স (USD)<input type="number" min="0" step="0.5" id="cr-limit" value="<?php echo esc_attr($s('limit_usd')); ?>"></label>
            <label>কার জন্য
                <select id="cr-scope"><option value="me">শুধু আমার</option><option value="all">সব ইউজারের</option></select>
            </label>
        </div>
        <p><button type="button" class="button button-primary" id="cr-go">🔄 এখনই রিফ্রেশ করুন</button> <span id="cr-msg" class="sba-msg"></span></p>
    </div>

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
            <label>ব্যালেন্স (USD)<input type="number" min="0" step="0.5" id="sub-limit" value="<?php echo esc_attr($s('limit_usd')); ?>"></label>
        </div>
        <p><button type="button" class="button" id="sub-save">সেট করুন</button> <span id="sub-msg" class="sba-msg"></span></p>
    </div>

    <div class="sba-card">
        <h2>🧹 ডেমো পোস্ট</h2>
        <p class="sba-muted">প্লাগইনের সঙ্গে আসা ৬টি ডেমো পোস্ট এক ক্লিকে মুছে ফেলুন (নিজের পোস্টে হাত পড়বে না)।</p>
        <p><button type="button" class="button" id="demo-del">ডেমো পোস্ট মুছুন</button> <span id="demo-msg" class="sba-msg"></span></p>
    </div>
</div>
