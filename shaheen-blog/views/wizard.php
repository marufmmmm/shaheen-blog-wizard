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
    'no_social'   => $post ? (int) get_post_meta($pid, '_sb_no_social', true) : 0,
    'no_global'   => $post ? (int) get_post_meta($pid, '_sb_no_global', true) : 0,
    'related'     => $post ? array_map(function ($rid) use ($pid) { $w = get_post_meta($pid, '_sb_related_why', true); return array('id' => (int) $rid, 'title' => get_the_title($rid), 'why' => (is_array($w) && isset($w[$rid])) ? $w[$rid] : ''); }, array_filter((array) get_post_meta($pid, '_sb_related', true))) : array(),
    'inc_summary' => $post ? ((($v = get_post_meta($pid, '_sb_inc_summary', true)) === '1') || ($v === '' && get_post_meta($pid, '_sb_summary', true) !== '')) : false,
    'inc_faq'     => $post ? ((($v = get_post_meta($pid, '_sb_inc_faq', true)) === '1') || ($v === '' && !empty(get_post_meta($pid, '_sb_faq', true)))) : false,
    'extras'      => $post ? sb_get_extras($pid) : sb_clean_extras(array()),
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
$costs['custom2'] = sb_custom_cost(2);
$costs['custom3'] = sb_custom_cost(3);
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
            <label>সারাংশ (কার্ডে দেখাবে) <small>— এখন ফাঁকা রাখতে পারেন। আর্টিকেল তৈরি/লেখার পর ধাপ ৪-এ "সারাংশ" বানালে এটা নিজে ভরে যাবে</small>
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
            <input type="hidden" id="f-topic" value="<?php echo esc_attr($d['topic']); ?>">
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

            <h3>সম্পর্কিত পোস্ট/পেজ <small>(পোস্টের পাশে দেখাবে)</small></h3>
            <p class="sba-muted">🤖 AI সাইটের সব পোস্ট ও পেজ থেকে এই লেখার সাথে সবচেয়ে মিলগুলো বেছে দেয় (যেমন ক্যাডেট পোস্টে ক্যাডেট কোচিং পেজ) এবং পাঠকের জন্য এক লাইনে কারণ লিখে দেয়। না মিললে জোর করে ভরে না। নিজেও খুঁজে যোগ করতে পারেন (বিনামূল্যে)। কিছু না বাছলে একই ক্যাটাগরির ব্লগ পোস্ট দেখাবে।
                <button type="button" class="button" data-ai="related">🤖 স্মার্ট সম্পর্কিত বানান (<?php echo esc_html(sb_bn_digits($costs['related'])); ?> ক্রেডিট)</button></p>
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
            <label class="sba-inline-check" style="margin-top:10px"><input type="checkbox" id="no-social"> এই পোস্টে সোশ্যাল মিডিয়া বক্স (ফেসবুক/ইউটিউব/ইনস্টাগ্রাম) দেখাবো না</label><br>
            <label class="sba-inline-check" style="margin-top:6px"><input type="checkbox" id="no-global"> লাইব্রেরির "সব পোস্টে অটো" ব্লকগুলো এই পোস্টে দেখাবো না</label>

            <h3>🔗 রেফারেন্স লিংক <small>(ঐচ্ছিক)</small></h3>
            <p class="sba-muted">"যে শব্দে লিংক বসবে" ঘরে আর্টিকেলের হুবহু শব্দ/বাক্যাংশ লিখলে লেখার ভেতরে সেই শব্দটি হাইপারলিংক হবে (প্রথমবার যেখানে পাবে সেখানে)। না লিখলে বা না পেলে শুধু নিচের "সূত্র" তালিকায় থাকবে। লিংক নতুন ট্যাবে খুলবে।</p>
            <div id="ex-refs"></div><button type="button" class="button" id="ex-refs-add">＋ রেফারেন্স যোগ করুন</button>

            <h3>⬇️ ডাউনলোড বাটন <small>(ঐচ্ছিক)</small></h3>
            <p class="sba-muted">বাটনের লেখা লিখুন, ফাইল মিডিয়া লাইব্রেরি থেকে বাছুন বা লিংক দিন, আর কোথায় বসবে ঠিক করুন।</p>
            <div id="ex-dls"></div><button type="button" class="button" id="ex-dls-add">＋ ডাউনলোড বাটন যোগ করুন</button>

            <h3>📄 PDF পড়ার ঘর <small>(ঐচ্ছিক)</small></h3>
            <p class="sba-muted">PDF পোস্টের ভেতরেই পড়া যাবে (ফোনেও)। পাশে "খুলুন / ডাউনলোড" লিংকও থাকবে।</p>
            <div id="ex-pdfs"></div><button type="button" class="button" id="ex-pdfs-add">＋ PDF যোগ করুন</button>

            <h3>▶️ ভিডিও / সোশ্যাল পোস্ট এমবেড <small>(ঐচ্ছিক)</small></h3>
            <p class="sba-muted">শুধু লিংক নয়, <strong>এমবেড কোডও</strong> পেস্ট করা যায়: ফেসবুকের Page Plugin / Like বাটন / পোস্ট কোড, ইউটিউবের iframe কোড, ইনস্টাগ্রামের এমবেড কোড। প্রতিটি আলাদা ঘরে দিন, আর প্রতিটির জন্য আলাদা জায়গা বাছুন। (নিরাপত্তার জন্য কোডের ভেতর থেকে শুধু ঠিকানাটুকু নিয়ে সাইট নিজের নিরাপদ ফ্রেম বানায়; অন্য স্ক্রিপ্ট চলে না।)</p>
            <div id="ex-embeds"></div><button type="button" class="button" id="ex-embeds-add">＋ ভিডিও/পোস্ট যোগ করুন</button>

            <h3>📣 এই পোস্টে সোশ্যাল বক্স (লাইক/ফলো) কোথায় দেখাবে</h3>
            <div class="sba-row"><select id="ex-social-pos">
                <option value="">সাধারণ সেটিং অনুযায়ী (সোশ্যাল মিডিয়া পেজে ঠিক করা)</option>
                <option value="top">লেখার একদম ওপরে</option><option value="p">N-তম অনুচ্ছেদের পর</option>
                <option value="middle">লেখার মাঝখানে</option><option value="end">লেখার শেষে</option><option value="off">এই পোস্টে দেখাবো না</option></select>
                <input type="number" id="ex-social-n" min="1" max="60" value="2" style="width:80px" title="কত নম্বর অনুচ্ছেদের পর"></div>
            <div id="ex-social-pick" style="margin-top:8px">
                <label class="sba-inline-check"><input type="radio" name="ex-soc-mode" value="all" checked> বক্সে সব চালু আইটেম দেখাও</label>
                <label class="sba-inline-check"><input type="radio" name="ex-soc-mode" value="pick"> শুধু এই আইটেমগুলো দেখাও:</label>
                <div id="ex-soc-list" style="margin:6px 0 0 24px">
                <?php foreach (sb_social_items() as $sit) : if (!$sit['on']) { continue; } ?>
                    <label class="sba-inline-check" style="display:block"><input type="checkbox" class="ex-soc-id" value="<?php echo esc_attr($sit['id']); ?>"> <?php echo esc_html($sit['name'] !== '' ? $sit['name'] : sb_social_item_types()[$sit['type']]); ?></label>
                <?php endforeach; ?>
                </div>
            </div>

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

            <div class="sba-ai sba-include">
                <div class="sba-ai-head"><h3>📌 পোস্টের সাথে কী কী থাকবে?</h3></div>
                <label class="sba-inline-check"><input type="checkbox" id="inc-summary" <?php checked($d['inc_summary']); ?>> সামারি <em>(আর্টিকেলের ওপরে "সংক্ষেপে" বক্স — AI হলে +<?php echo esc_html(sb_bn_digits($costs['summary'])); ?> ক্রেডিট)</em></label><br>
                <label class="sba-inline-check"><input type="checkbox" id="inc-faq" <?php checked($d['inc_faq']); ?>> FAQ <em>(আর্টিকেলের নিচে প্রশ্ন-উত্তর — AI হলে +<?php echo esc_html(sb_bn_digits($costs['faq'])); ?> ক্রেডিট)</em></label>
                <select id="f-faqn" title="FAQ কয়টি"><option value="3">৩</option><option value="5" selected>৫</option><option value="7">৭</option></select> টি<br>
                <label class="sba-inline-check"><input type="checkbox" id="inc-related"> স্মার্ট সম্পর্কিত পোস্ট/পেজ <em>(পাশে — AI সাইট থেকে মিলগুলো বেছে দেবে, +<?php echo esc_html(sb_bn_digits($costs['related'])); ?> ক্রেডিট)</em></label>
                <p class="sba-muted">পুরনো পোস্টে শুধু এগুলো যোগ করতে: টিক দিয়ে "শুধু টিক দেওয়াগুলো বানান" চাপুন — আর্টিকেল একদম বদলাবে না। নতুন পোস্টে টিক দিয়ে নিচের "সম্পূর্ণ আর্টিকেল" তৈরি করলে AI আর্টিকেল থেকে টিক দেওয়াগুলো নিজে বানিয়ে দেবে। হাতে লিখতে চাইলে নিচের সামারি ঘরে ও ধাপ ৩-এর FAQ অংশে লিখুন। <strong>টিক না দিলে সামারি/FAQ বানানো হবে না, পোস্টেও দেখাবে না।</strong>
                <button type="button" class="button button-primary" id="inc-only">🤖 শুধু টিক দেওয়াগুলো বানান (আর্টিকেল বদলাবে না)</button>
                <button type="button" class="button" data-ai="faq">🤖 শুধু FAQ তৈরি করুন (<?php echo esc_html(sb_bn_digits($costs['faq'])); ?>)</button></p>
            </div>

            <div class="sba-ai">
                <div class="sba-ai-head"><h3>✍️ লেখার প্রম্পট <small>(আপনার নির্দেশ ও তথ্যই AI মানবে)</small></h3></div>
                <p class="sba-muted">এখানে কী লিখতে হবে, কোন তথ্য থেকে লিখবে, সুর কেমন, কোন কোন অংশ থাকবে — সব বিস্তারিত লিখুন। নিচে ওয়েবসাইটের লিংক ও PDF/ছবি দিলে AI সেগুলো পড়ে তথ্য নেবে। আপনার দেওয়া তথ্যই সবার আগে; নিচের টিক দিলে AI ইন্টারনেটে সর্বশেষ খবরও যাচাই করবে, তবু বানিয়ে কিছু লিখবে না; কোনো তথ্য না থাকলে <code>[তথ্য দরকার: …]</code> লিখে রাখবে। শিরোনাম ও কীওয়ার্ডও (ধাপ ১-২) প্রম্পটের সাথে যাবে।</p>
                <textarea id="f-custom" rows="10" style="width:100%" placeholder="যেমন: ভর্তি বিজ্ঞপ্তির আর্টিকেল লিখুন। ক্লাস ৬ষ্ঠ–৯ম। আবেদন ১–১৫ নভেম্বর। ফি … । সুর আনুষ্ঠানিক, অভিভাবকদের উদ্দেশে। শেষে যোগাযোগের নম্বর দিন। (PDF-এর কোন অংশ কীভাবে ব্যবহার হবে তাও লিখুন)"></textarea>
                <label style="display:block;margin-top:8px">🌐 তথ্যের ওয়েবসাইট লিংক <small>(প্রতি লাইনে একটি, সর্বোচ্চ ৪টি; সাইট নিজে পড়ে লেখাটুকু AI-কে দেওয়া হবে)</small>
                    <textarea id="f-curls" rows="3" style="width:100%" placeholder="https://…"></textarea></label>
                <label style="display:block;margin-top:8px">📎 PDF / ছবি <small>(সর্বোচ্চ ৪টি ফাইল, প্রতিটি ১০ MB-এর মধ্যে; PDF, JPG, PNG, WEBP। স্ক্যান করা PDF বা ছবির লেখাও AI পড়ে)</small>
                    <input type="file" id="f-cfiles" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*"></label>
                <label class="sba-research" style="display:block;margin-top:8px"><input type="checkbox" id="f-research" checked> 🌐 সঠিক ও সর্বশেষ খবরের জন্য AI ইন্টারনেটেও যাচাই করুক <em>(+<?php echo esc_html(sb_bn_digits($costs['research'])); ?> ক্রেডিট, শুধু সফল হলে)</em></label>
                <div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <?php $cur_m = trim((string) sb_get('model_article')) ?: sb_get('model'); ?>
                    <select id="f-cmodel" title="কোন AI মডেলে লিখবে">
                        <?php foreach (sb_model_catalog() as $mid => $mm) : ?>
                            <option value="<?php echo esc_attr($mid); ?>" <?php selected($cur_m, $mid); ?>><?php echo esc_html($mid . ' — ' . $mm[2]); ?></option>
                        <?php endforeach; ?>
                        <?php if (!isset(sb_model_catalog()[$cur_m])) : ?><option value="<?php echo esc_attr($cur_m); ?>" selected><?php echo esc_html($cur_m); ?></option><?php endif; ?>
                    </select>
                    <select id="f-clen" title="আর্টিকেলের দৈর্ঘ্য">
                        <option value="1">৮০০–১০০০ শব্দ (<?php echo esc_html(sb_bn_digits($costs['custom'])); ?> ক্রেডিট)</option>
                        <option value="2">১০০০–১৫০০ শব্দ (<?php echo esc_html(sb_bn_digits($costs['custom2'])); ?> ক্রেডিট)</option>
                        <option value="3">১৫০০+ শব্দ (<?php echo esc_html(sb_bn_digits($costs['custom3'])); ?> ক্রেডিট)</option>
                    </select>
                    <button type="button" class="button button-primary" data-ai="custom">🤖 প্রম্পট থেকে লিখুন</button>
                </div>
                <div class="sba-ai-head" style="margin-top:14px"><h3>আর্টিকেল</h3></div>
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

            <?php sb_edit_box('ed'); ?>

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
