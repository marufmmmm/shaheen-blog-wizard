<?php
if (!defined('ABSPATH')) {
    exit;
}
$cats = get_terms(array('taxonomy' => 'sb_category', 'hide_empty' => false, 'orderby' => 'term_id'));
$cost = sb_feature_cost('rewrite_post');
?>
<div class="wrap sba" id="rw-page">
    <h1>🔁 রিরাইট করে পোস্ট</h1>
    <?php sb_credit_pill(); ?>
    <div class="sba-note">অন্য উৎসের শিরোনাম ও লেখা পেস্ট করুন। AI <strong>তথ্য একদম ঠিক রেখে</strong> ভাষা সম্পূর্ণ বদলে সহজ প্রচলিত বাংলায় নতুন শিরোনাম, সংক্ষিপ্ত বর্ণনা ও লেখা বানাবে। তারপর ছবি দিয়ে এখান থেকেই পোস্ট করুন। শুধু "নতুন করে লিখুন" বাটনে <?php echo esc_html(sb_money($cost)); ?> লাগে, বাকি সব বিনামূল্যে।</div>

    <div class="sba-card sba-form">
        <h2>১. মূল লেখা পেস্ট করুন</h2>
        <label>মূল শিরোনাম<input type="text" id="rw-title" placeholder="যে লেখা থেকে নিচ্ছেন তার শিরোনাম"></label>
        <label>মূল লেখা *<textarea id="rw-source" rows="10" placeholder="এখানে পুরো লেখা পেস্ট করুন (কমপক্ষে ২০০ অক্ষর)"></textarea></label>
        <label>🌐 একই বিষয়ে অন্য সাইটের লিংক <small>(প্রতি লাইনে একটি, ৩-৪টি ভালো। AI সবগুলো ও মূল লেখা একসাথে বিশ্লেষণ করে আপনার ঢঙে একটিই সম্পূর্ণ নতুন লেখা লিখবে; তথ্য না মিললে জানাবে। শুধু লিংক দিলেও চলবে, তখন মূল লেখা ফাঁকা রাখতে পারেন)</small>
            <textarea id="rw-refs" rows="4" placeholder="https://site-1.com/news&#10;https://site-2.com/news&#10;https://site-3.com/news"></textarea></label>
        <label>🤖 মডেল <select id="rw-model"><option value="">সেটিংসের ডিফল্ট</option>
            <?php foreach (sb_model_catalog() as $mid => $mm) : ?><option value="<?php echo esc_attr($mid); ?>"><?php echo esc_html($mid); ?></option><?php endforeach; ?></select></label>
        <div class="sba-row">
            <label>সূত্র <small>(পত্রিকা/সাইটের নাম, ঐচ্ছিক)</small><input type="text" id="rw-src-name" placeholder="যেমন: প্রথম আলো"></label>
            <label>সূত্রের লিংক <small>(ঐচ্ছিক)</small><input type="text" id="rw-src-url" placeholder="https://…"></label>
        </div>
    </div>

    <div class="sba-card sba-form">
        <h2>২. ক্যাটাগরি, কীওয়ার্ড ও ছবি</h2>
        <div class="sba-row">
            <label>ক্যাটাগরি
                <select id="rw-cat"><option value="0">— বেছে নিন —</option>
                    <?php if (!is_wp_error($cats)) : foreach ($cats as $t) : ?><option value="<?php echo (int) $t->term_id; ?>"><?php echo esc_html($t->name); ?></option><?php endforeach; endif; ?>
                </select></label>
            <label>কীওয়ার্ড <small>(ঐচ্ছিক, কমা দিয়ে)</small><input type="text" id="rw-kw" placeholder="যেমন: ক্যাডেট কলেজ, ভর্তি"></label>
        </div>
        <div class="sba-image-box">
            <img id="rw-img" src="" alt="" hidden>
            <input type="hidden" id="rw-image-id" value="0">
            <div class="sba-image-actions">
                <button type="button" class="button" id="rw-pick">🖼 ফিচার্ড ছবি বেছে নিন</button>
                <button type="button" class="button-link-delete" id="rw-unpick" hidden>ছবি সরান</button>
                <small>লেখার ভেতরে আরও ছবি দিতে নিচের এডিটরে "Add Media" ব্যবহার করুন।</small>
            </div>
        </div>
        <p><button type="button" class="button button-primary button-hero" id="rw-go" data-cost="<?php echo (int) $cost; ?>">🤖 নতুন করে লিখুন <em>(<?php echo esc_html(sb_money($cost)); ?>)</em></button>
           <span id="msg" class="sba-msg"></span></p>
    </div>

    <div class="sba-card sba-form" id="rw-result" hidden>
        <h2>৩. নতুন লেখা (দেখে ঠিক করে নিন)</h2>
        <div id="rw-facts" class="sba-missing" hidden></div>
        <label>নতুন শিরোনাম<input type="text" id="rw-new-title"></label>
        <label>সংক্ষিপ্ত বর্ণনা (কার্ডে ও গুগলে দেখাবে)<textarea id="rw-new-excerpt" rows="2"></textarea></label>
        <?php wp_editor('', 'rw_content', array('textarea_name' => 'rw_content', 'media_buttons' => true, 'editor_height' => 380)); ?>
        <?php sb_edit_box('rwed'); ?>
        <fieldset class="sba-status" style="margin-top:14px">
            <label><input type="radio" name="rw-status" value="draft" checked> খসড়া হিসেবে সেভ</label>
            <label><input type="radio" name="rw-status" value="publish"> প্রকাশ করুন</label>
        </fieldset>
        <p><button type="button" class="button button-primary button-hero" id="rw-save">💾 সেভ / প্রকাশ করুন</button></p>
        <div id="rw-saved"></div>
    </div>
</div>
