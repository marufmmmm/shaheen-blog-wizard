<?php
if (!defined('ABSPATH')) {
    exit;
}
$pid  = isset($_GET['post']) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
$post = null;
if ($pid) {
    $post = get_post($pid);
    if (!$post || $post->post_type !== 'sb_post' || !current_user_can('edit_post', $pid)) {
        echo '<div class="wrap"><div class="notice notice-error"><p>পোস্ট পাওয়া যায়নি বা এডিটের অনুমতি নেই।</p></div></div>';
        return;
    }
}
$cat_terms = get_the_terms($pid, 'sb_category');
$d = array(
    'id'          => $post ? $post->ID : 0,
    'title'       => $post ? $post->post_title : '',
    'slug'        => $post ? $post->post_name : '',
    'excerpt'     => $post ? $post->post_excerpt : '',
    'subtitle'    => $post ? get_post_meta($pid, '_sb_subtitle', true) : '',
    'meta_desc'   => $post ? get_post_meta($pid, '_sb_meta_desc', true) : '',
    'summary'     => $post ? get_post_meta($pid, '_sb_summary', true) : '',
    'author_name' => $post ? get_post_meta($pid, '_sb_author_name', true) : '',
    'keywords'    => $post ? (array) get_post_meta($pid, '_sb_keywords', true) : array(),
    'topic'       => $post ? get_post_meta($pid, '_sb_topic', true) : '',
    'blocks'      => $post ? (array) get_post_meta($pid, '_sb_blocks', true) : array(),
    'block_ids'   => $post ? (array) get_post_meta($pid, '_sb_block_ids', true) : array(),
    'no_global'   => $post ? (int) get_post_meta($pid, '_sb_no_global', true) : 0,
    'related'     => $post ? array_map(function ($rid) { return array('id' => (int) $rid, 'title' => get_the_title($rid)); }, array_filter((array) get_post_meta($pid, '_sb_related', true))) : array(),
    'faq'         => $post ? (array) get_post_meta($pid, '_sb_faq', true) : array(),
    'category'    => (!empty($cat_terms) && !is_wp_error($cat_terms)) ? (int) $cat_terms[0]->term_id : 0,
    'image_id'    => $post ? (int) get_post_thumbnail_id($pid) : 0,
    'image_url'   => $post ? (string) get_the_post_thumbnail_url($pid, 'medium') : '',
    'date'        => $post ? get_the_date('Y-m-d', $pid) : wp_date('Y-m-d'),
    'status'      => $post ? $post->post_status : 'draft',
    'url'         => $post ? get_permalink($pid) : '',
);
$cats = get_terms(array('taxonomy' => 'sb_category', 'hide_empty' => false, 'orderby' => 'term_id'));
$costs = array();
foreach (array_keys(sb_feature_labels()) as $f) {
    $costs[$f] = sb_feature_cost($f);
}
$costs['research'] = max(0, (int) sb_get('cost_research'));
?>
<div class="wrap sba">
    <h1><?php echo $post ? 'পোস্ট এডিট করুন' : 'নতুন পোস্ট তৈরি করুন'; ?></h1>
    <?php sb_credit_pill(); ?>

    <div class="sba-note">✏️ পোস্টের লেখা, ছবি, ক্যাটাগরি, তারিখ এডিট করতে <strong>কোনো ক্রেডিট লাগে না</strong>। ক্রেডিট কাটে শুধু AI বাটনে ক্লিক করলে।</div>

    <script>window.SB_POST = <?php echo wp_json_encode($d); ?>;</script>

    <ol class="sba-steps" id="sb-steps">
        <li><button type="button" data-go="1"><span>১</span> মূল তথ্য</button></li>
        <li><button type="button" data-go="2"><span>২</span> কীওয়ার্ড</button></li>
        <li><button type="button" data-go="3"><span>৩</span> ক্যাটাগরি</button></li>
        <li><button type="button" data-go="4"><span>৪</span> AI কনটেন্ট</button></li>
        <li><button type="button" data-go="5"><span>৫</span> রিভিউ ও প্রকাশ</button></li>
    </ol>

    <form id="sb-form" class="sba-form" autocomplete="off" onsubmit="return false;">

        <!-- Step 1 -->
        <section class="sba-step" data-step="1">
            <h2>ধাপ ১: মূল তথ্য</h2>
            <label>শিরোনাম *
                <input type="text" id="f-title" value="<?php echo esc_attr($d['title']); ?>" placeholder="পোস্টের শিরোনাম">
            </label>
            <label>সারাংশ (কার্ডে দেখাবে)
                <textarea id="f-excerpt" rows="3" placeholder="২-৩ লাইনের সংক্ষিপ্ত বিবরণ"><?php echo esc_textarea($d['excerpt']); ?></textarea>
            </label>
            <div class="sba-row">
                <label>লেখকের নাম <small>(ফাঁকা থাকলে আপনার নাম)</small>
                    <input type="text" id="f-author" value="<?php echo esc_attr($d['author_name']); ?>" placeholder="<?php echo esc_attr(wp_get_current_user()->display_name); ?>">
                </label>
                <label>তারিখ
                    <input type="date" id="f-date" value="<?php echo esc_attr($d['date']); ?>">
                </label>
                <label>লিংকের নাম <small>(ইংরেজি, ঐচ্ছিক)</small>
                    <input type="text" id="f-slug" value="<?php echo esc_attr($d['slug']); ?>" placeholder="যেমন: cadet-admission-tips">
                </label>
            </div>
            <div class="sba-image">
                <span class="sba-label">ফিচার্ড ছবি</span>
                <div class="sba-image-box">
                    <img id="f-image-preview" src="<?php echo esc_url($d['image_url']); ?>" alt="" <?php echo $d['image_url'] ? '' : 'hidden'; ?>>
                    <div class="sba-image-actions">
                        <button type="button" class="button" id="f-image-pick">ছবি বেছে নিন</button>
                        <button type="button" class="button-link-delete" id="f-image-remove" <?php echo $d['image_url'] ? '' : 'hidden'; ?>>ছবি সরান</button>
                        <small>প্রস্তাবিত মাপ: ১২০০×৬৫০ বা তার বেশি।</small>
                    </div>
                </div>
            </div>
        </section>

        <!-- Step 2 -->
        <section class="sba-step" data-step="2" hidden>
            <h2>ধাপ ২: কীওয়ার্ড</h2>
            <p class="sba-muted">মূল কীওয়ার্ডগুলো কমা দিয়ে লিখুন। AI শিরোনাম, সাবটাইটেল, আর্টিকেল ও মেটা বর্ণনায় এগুলো ব্যবহার করবে।</p>
            <label>কীওয়ার্ড * <small>(কমা দিয়ে আলাদা করুন, কমপক্ষে ২টি, বেশিও দিতে পারেন)</small>
                <input type="text" id="f-kw" value="<?php echo esc_attr(implode(', ', array_filter($d['keywords']))); ?>" placeholder="যেমন: ক্যাডেট কলেজ, ভর্তি প্রস্তুতি, মডেল টেস্ট">
            </label>
            <div id="kw-chips" class="sba-chips"></div>
            <label>বিষয়/প্রসঙ্গ <small>(ঐচ্ছিক, AI-কে দিকনির্দেশনা)</small>
                <input type="text" id="f-topic" value="<?php echo esc_attr($d['topic']); ?>" placeholder="যেমন: ষষ্ঠ শ্রেণির অভিভাবকদের জন্য">
            </label>
        </section>

        <!-- Step 3 -->
        <section class="sba-step" data-step="3" hidden>
            <h2>ধাপ ৩: ক্যাটাগরি</h2>
            <label>ক্যাটাগরি
                <select id="f-category">
                    <option value="0">— বেছে নিন —</option>
                    <?php foreach ($cats as $t) :
                        $en = get_term_meta($t->term_id, 'sb_name_en', true); ?>
                        <option value="<?php echo (int) $t->term_id; ?>" <?php selected($d['category'], $t->term_id); ?>><?php echo esc_html($t->name . ($en ? " ($en)" : '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if (current_user_can('manage_categories')) : ?>
            <details class="sba-inline">
                <summary>+ নতুন ক্যাটাগরি যোগ করুন</summary>
                <div class="sba-row">
                    <label>বাংলা নাম<input type="text" id="nc-bn"></label>
                    <label>ইংরেজি নাম<input type="text" id="nc-en"></label>
                    <div><button type="button" class="button" id="nc-add">যোগ করুন</button></div>
                </div>
            </details>
            <?php endif; ?>

            <h3>সম্পর্কিত পোস্ট/পেজ <small>(ঐচ্ছিক — ফাঁকা রাখলে কমপক্ষে ৩টি নিজে নিজে দেখাবে)</small></h3>
            <input type="search" id="rel-q" placeholder="পোস্ট বা পেজের নাম লিখে খুঁজুন…" style="max-width:420px">
            <ul id="rel-results" class="sba-relres"></ul>
            <div id="rel-chosen" class="sba-chips"></div>

            <h3>📢 পোস্টের মাঝে বাটন / টেক্সট / লিংক ব্লক <small>(ঐচ্ছিক, বিজ্ঞাপন বা লিংকের জন্য)</small></h3>
            <p class="sba-muted">চেকবক্স দিয়ে বেছে নিন: শুধু টেক্সট, শুধু বাটন, টেক্সট + বাটন, অথবা টেক্সটে ক্লিক করলে লিংকে যাবে।</p>
            <div class="sba-blkpick"><strong>📚 লাইব্রেরি থেকে বাছুন:</strong> <span id="blk-pick"></span>
                <small class="sba-muted"> (<a href="<?php echo esc_url(admin_url('admin.php?page=sb-blocks')); ?>" target="_blank">ব্লক লাইব্রেরি</a>-তে আগে ব্লক বানিয়ে রাখুন)</small></div>
            <p class="sba-muted">অথবা শুধু এই পোস্টের জন্য নতুন ব্লক:</p>
            <div id="blk-list"></div>
            <button type="button" class="button" id="blk-add">+ নতুন ব্লক যোগ করুন</button>
            <label class="sba-inline-check" style="margin-top:10px"><input type="checkbox" id="no-global"> লাইব্রেরির "সব পোস্টে অটো" ব্লকগুলো এই পোস্টে দেখাবো না</label>

            <h3>সচরাচর জিজ্ঞাসা (FAQ) <small>ঐচ্ছিক</small></h3>
            <div id="faq-list"></div>
            <button type="button" class="button" id="faq-add">+ প্রশ্ন যোগ করুন</button>
        </section>

        <!-- Step 4 -->
        <section class="sba-step" data-step="4" hidden>
            <h2>ধাপ ৪: AI কনটেন্ট <small>(সব ঐচ্ছিক)</small></h2>
            <p class="sba-muted">প্রতিটি অংশ হাতেও লেখা যায় (বিনামূল্যে)। AI বাটনে ক্লিক করলেই শুধু ক্রেডিট কাটবে।</p>

            <div class="sba-ai">
                <div class="sba-ai-head"><h3>৫টি শিরোনাম</h3>
                    <button type="button" class="button button-primary" data-ai="headlines">🤖 তৈরি করুন <em>(<?php echo esc_html(sb_bn_digits($costs['headlines'])); ?> ক্রেডিট)</em></button></div>
                <p class="sba-muted">ধাপ ২-এর কীওয়ার্ড ব্যবহার করে। পছন্দেরটিতে ক্লিক করলে শিরোনাম হিসেবে বসবে।</p>
                <ul id="headline-results" class="sba-headlines"></ul>
            </div>

            <div class="sba-ai">
                <div class="sba-ai-head"><h3>সাবটাইটেল</h3>
                    <button type="button" class="button" data-ai="subtitle">🤖 তৈরি করুন <em>(<?php echo esc_html(sb_bn_digits($costs['subtitle'])); ?>)</em></button></div>
                <input type="text" id="f-subtitle" value="<?php echo esc_attr($d['subtitle']); ?>">
            </div>

            <div class="sba-ai">
                <div class="sba-ai-head"><h3>মেটা বর্ণনা (SEO)</h3>
                    <button type="button" class="button" data-ai="meta">🤖 তৈরি করুন <em>(<?php echo esc_html(sb_bn_digits($costs['meta'])); ?>)</em></button></div>
                <textarea id="f-meta" rows="2"><?php echo esc_textarea($d['meta_desc']); ?></textarea>
                <small class="sba-muted">গুগলে দেখানোর জন্য ১২০-১৬০ অক্ষর ভালো। <span id="meta-count"></span></small>
            </div>

            <div class="sba-ai">
                <div class="sba-ai-head"><h3>📋 কপি করা লেখা নতুন করে লেখা</h3>
                    <button type="button" class="button" data-ai="rewrite">🤖 নতুন করে লিখুন <em>(<?php echo esc_html(sb_bn_digits($costs['rewrite'])); ?>)</em></button></div>
                <textarea id="f-source" rows="6" placeholder="অন্য উৎস থেকে কপি করা লেখা এখানে পেস্ট করুন। AI এটি বুঝে নিজের ভাষায়, প্রচলিত বাংলায় নতুন করে লিখে নিচের আর্টিকেল বক্সে বসাবে।"></textarea>
                <small class="sba-muted">অন্যের লেখার তথ্য নতুন ভাষায় লেখা হয়, তবে অন্যের লেখা হুবহু বা সামান্য বদলে ছাপলে কপিরাইট ও গুগলের স্প্যাম নীতির ঝুঁকি থাকে। প্রকাশের আগে তথ্য যাচাই করুন, দরকারে সূত্র উল্লেখ করুন।</small>
            </div>

            <div class="sba-ai">
                <div class="sba-ai-head"><h3>সম্পূর্ণ আর্টিকেল</h3>
                    <span>
                        <select id="f-length" title="দৈর্ঘ্য">
                            <option value="short">ছোট (৫০০-৮০০ শব্দ)</option>
                            <option value="medium" selected>মাঝারি (৮০০-১২০০)</option>
                            <option value="long">বড় (১৫০০+)</option>
                        </select>
                        <label class="sba-research"><input type="checkbox" id="f-research" checked> 🌐 ইন্টারনেট থেকে সর্বশেষ তথ্য <em>(+<?php echo esc_html(sb_bn_digits($costs['research'])); ?>)</em></label>
                        <button type="button" class="button" data-ai="article">🤖 তৈরি করুন <em>(<?php echo esc_html(sb_bn_digits($costs['article'])); ?>)</em></button>
                    </span></div>
                <?php
                wp_editor($post ? $post->post_content : '', 'sb_content', array(
                    'textarea_name' => 'sb_content',
                    'media_buttons' => true,
                    'editor_height' => 360,
                    'teeny'         => false,
                ));
                ?>
                <div id="article-sources" class="sba-sources" hidden></div>
                <div id="article-missing" class="sba-missing" hidden></div>
            </div>

            <div class="sba-ai">
                <div class="sba-ai-head"><h3>সামারি <small class="sba-muted">(মূল পোস্ট থেকে তৈরি হয়)</small></h3>
                    <button type="button" class="button" data-ai="summary">🤖 তৈরি করুন <em>(<?php echo esc_html(sb_bn_digits($costs['summary'])); ?>)</em></button></div>
                <textarea id="f-summary" rows="4"><?php echo esc_textarea($d['summary']); ?></textarea>
                <small class="sba-muted">আর্টিকেলের ওপরে "সংক্ষেপে" বক্সে দেখাবে। আর্টিকেল থেকে তৈরি হয়।</small>
            </div>
        </section>

        <!-- Step 5 -->
        <section class="sba-step" data-step="5" hidden>
            <h2>ধাপ ৫: রিভিউ ও প্রকাশ</h2>
            <dl class="sba-review" id="review"></dl>
            <fieldset class="sba-status">
                <label><input type="radio" name="f-status" value="draft" <?php checked($d['status'] !== 'publish'); ?>> খসড়া হিসেবে সেভ</label>
                <label><input type="radio" name="f-status" value="publish" <?php checked($d['status'], 'publish'); ?>> প্রকাশ করুন</label>
            </fieldset>
            <p><button type="button" class="button button-primary button-hero" id="f-save">💾 সেভ করুন</button></p>
            <div id="save-result"></div>
        </section>

        <div class="sba-nav">
            <button type="button" class="button" id="btn-prev">← পিছনে</button>
            <span id="msg" class="sba-msg" role="status"></span>
            <button type="button" class="button button-primary" id="btn-next">পরবর্তী →</button>
        </div>
    </form>
</div>
