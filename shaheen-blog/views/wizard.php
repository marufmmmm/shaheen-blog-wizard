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
    'presets'     => (object) sb_presets_get(),
    'versions'    => $post ? array_values(array_map(function ($v) { return array('label' => wp_date('d M, h:i A', (int) $v['t']), 'len' => mb_strlen(wp_strip_all_tags($v['c'])), 'c' => $v['c']); }, (array) (get_post_meta($pid, '_sb_versions', true) ?: array()))) : array(),
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

    <?php $d['autosave'] = sb_autosave_get($post ? $post->ID : 0); ?>
    <script>window.SB_POST = <?php echo wp_json_encode($d); ?>;</script>
    <div class="sba-topsave" id="topsave"><span id="autosave-note" class="sba-muted">Autosave is on</span> <button type="button" class="button button-primary" id="f-save-top">💾 Save</button></div>
    <div class="notice notice-info inline" id="restore-box" hidden><p><span id="restore-text"></span> <button type="button" class="button" id="restore-yes">Restore it</button> <button type="button" class="button-link" id="restore-no">Ignore</button></p></div>

    <ol class="sba-steps" id="sb-steps">
        <li><button type="button" data-go="1"><span>১</span> লিখুন</button></li>
        <li><button type="button" data-go="2"><span>২</span> ঠিক করুন</button></li>
        <li><button type="button" data-go="3"><span>৩</span> শেষ করুন</button></li>
    </ol>

    <form id="sb-form" class="sba-form" autocomplete="off" onsubmit="return false;">

        <!-- Page 1: write -->
        <section class="sba-step" data-step="1">
            <h2>পেজ ১: লিখুন</h2>
            <label><strong>১. শিরোনাম *</strong> <small>(আপনি নিজে লিখবেন, AI বদলাবে না)</small>
                <input type="text" id="f-title" value="<?php echo esc_attr($d['title']); ?>" placeholder="পোস্টের শিরোনাম">
            </label>

            <div class="sba-ai">
                <div class="sba-ai-head"><h3>৫টি শিরোনাম</h3>
                    <button type="button" class="button button-primary" data-ai="headlines">🤖 তৈরি করুন <em>(<?php echo esc_html(sb_bn_digits($costs['headlines'])); ?> ক্রেডিট)</em></button></div>
                <p class="sba-muted">ওপরের কীওয়ার্ড ব্যবহার করে। পছন্দেরটিতে ক্লিক করলে শিরোনাম হিসেবে বসবে।</p>
                <ul id="headline-results" class="sba-headlines"></ul>
            </div>

            <h3 style="margin-top:14px">২. কীওয়ার্ড</h3>
            <p class="sba-muted">মূল কীওয়ার্ডগুলো কমা দিয়ে লিখুন। AI শিরোনাম, সাবটাইটেল, আর্টিকেল ও মেটা বর্ণনায় এগুলো ব্যবহার করবে।</p>
            <label>কীওয়ার্ড * <small>(কমা দিয়ে আলাদা করুন, কমপক্ষে ২টি, বেশিও দিতে পারেন)</small>
                <input type="text" id="f-kw" value="<?php echo esc_attr(implode(', ', array_filter($d['keywords']))); ?>" placeholder="যেমন: ক্যাডেট কলেজ, ভর্তি প্রস্তুতি, মডেল টেস্ট">
            </label>
            <div id="kw-chips" class="sba-chips"></div>
            <input type="hidden" id="f-topic" value="<?php echo esc_attr($d['topic']); ?>">

            <div class="sba-ai sba-write">
                <div class="sba-ai-head"><h3>✍️ কী লিখবেন <small>(১ম পেজের সব কাজ এখানে)</small></h3></div>

                <label><strong>৩. বিবরণ</strong> <small>(বিষয়টি নিয়ে আপনার জানা আসল তথ্য ও নির্দেশ: কী লিখবে, কাদের জন্য, কোন তথ্য থাকবে। এটাই লেখার মূল প্রম্পট)</small>
                <div id="ansi-note" class="sba-muted" hidden>🔤 This text looks like old Bijoy (ANSI). AI will decode it to Unicode Bangla first.</div>
                <textarea id="f-custom" rows="8" style="width:100%" placeholder="যেমন: ক্যাডেট কলেজ ভর্তি নিয়ে লিখুন। ষষ্ঠ শ্রেণি, ছেলেদের জন্য। আবেদন ১–১৫ নভেম্বর। ফি … । অভিভাবকদের উদ্দেশে, আন্তরিক সুরে।"></textarea></label>

                <label style="display:block;margin-top:10px">📂 পোস্টের ধরন <small>(ধরন অনুযায়ী AI জানবে কোন তথ্য লাগে ও লেখা কীভাবে সাজাবে)</small>
                    <select id="f-ptype">
                    <?php foreach (sb_ptypes() as $ptk => $ptv) : ?>
                        <option value="<?php echo esc_attr($ptk); ?>"><?php echo esc_html($ptv[0]); ?></option>
                    <?php endforeach; ?>
                    </select></label>

                <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
                    <label>👥 পাঠক <select id="f-aud"><?php foreach (sb_audiences() as $ak => $av) : ?><option value="<?php echo esc_attr($ak); ?>"><?php echo esc_html($av[0]); ?></option><?php endforeach; ?></select></label>
                    <label>🎙️ সুর <select id="f-tone"><?php foreach (sb_tones() as $tk => $tv) : ?><option value="<?php echo esc_attr($tk); ?>"><?php echo esc_html($tv[0]); ?></option><?php endforeach; ?></select></label>
                </div>
                <label class="sba-comp" style="display:block;margin-top:10px"><strong>৪. Websites to beat</strong> <small class="sba-muted">(প্রতি লাইনে একটি লিংক, সর্বোচ্চ ৫টি)</small>
                    <textarea id="f-comp" rows="3" style="width:100%" placeholder="https://site-1.com/their-article&#10;https://site-2.com/their-article"></textarea>
                    <small class="sba-muted">AI এই পেজগুলো পড়ে দেখবে ওরা কী বলেছে ও কী বলেনি, তারপর আপনার কীওয়ার্ড ও বিবরণ মিশিয়ে নিজের ভাষায় নতুন লেখা লিখবে (হুবহু কপি নয়)।</small></label>

                <fieldset class="sba-mode" style="margin-top:10px;border:1px solid #dcdcde;padding:8px 12px;border-radius:6px">
                    <legend><strong>৫. লেখা কোন উৎস থেকে হবে?</strong></legend>
                    <label class="sba-inline-check" style="display:block"><input type="radio" name="f-mode" value="links"> (ক) শুধু ওপরের লিংকগুলো থেকে পড়ে, কীওয়ার্ড ও বিবরণ মিশিয়ে</label>
                    <label class="sba-inline-check" style="display:block"><input type="radio" name="f-mode" value="paste"> (খ) নিচের বক্সে পেস্ট করা লেখা থেকে, কীওয়ার্ড ও বিবরণ মিশিয়ে</label>
                    <label class="sba-inline-check" style="display:block"><input type="radio" name="f-mode" value="both" checked> (গ) লিংক ও পেস্ট করা লেখা দুটোই</label>
                </fieldset>
                <div id="paste-wrap" style="margin-top:8px">
                    <label>📋 পেস্ট করা লেখা <small>(অন্য কোথাও থেকে কপি করা লেখা বা নিজের নোট; AI নিজের ভাষায় নতুন করে লিখবে, হুবহু কপি নয়)</small>
                    <textarea id="f-paste" rows="6" style="width:100%" placeholder="এখানে লেখা পেস্ট করুন (ঐচ্ছিক)"></textarea></label>
                </div>

                <details class="sba-more" style="margin-top:10px"><summary>➕ আরও উৎস: তথ্যের সাইট, PDF ও ছবি (ঐচ্ছিক)</summary>
                    <label style="display:block;margin-top:8px">🌐 তথ্যের ওয়েবসাইট লিংক <small>(প্রতি লাইনে একটি, সর্বোচ্চ ৪টি; সাইট নিজে পড়ে লেখাটুকু AI-কে দেওয়া হবে)</small>
                    <textarea id="f-curls" rows="3" style="width:100%" placeholder="https://…"></textarea></label>
                    <label style="display:block;margin-top:8px">📎 PDF / ছবি <small>(সর্বোচ্চ ৪টি ফাইল, প্রতিটি ১০ MB-এর মধ্যে; PDF, JPG, PNG, WEBP। স্ক্যান করা PDF বা ছবির লেখাও AI পড়ে)</small>
                    <input type="file" id="f-cfiles" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*"></label>
                    <label class="sba-inline-check" style="display:block;margin-top:6px"><input type="checkbox" id="f-cimg" checked> 🖼️ PDF-এর পাতাগুলো ছবি করে AI-কে দাও <em>(সরকারি PDF প্রায়ই পুরনো বিজয় ফন্টে থাকে, লেখা হিজিবিজি হয়ে যায়, ছবি করে পড়ালে ঠিক পড়ে; বেশি পাতা হলে প্রথম ১২ পাতা)</em></label>
                    <label class="sba-inline-check" style="display:block;margin-top:4px"><input type="checkbox" id="f-ckeep" checked> 📥 PDF সাইটের মিডিয়ায় রেখে এই পোস্টে <strong>ডাউনলোড বাটন</strong> ও <strong>পেজে পড়ার ঘর</strong> যোগ করো <em>(পেজ ৩-এর নিচে দেখা যাবে, সরাতেও পারবেন)</em></label>
                </details>
                <label class="sba-research" style="display:block;margin-top:8px"><input type="checkbox" id="f-research" checked> 🌐 সঠিক ও সর্বশেষ খবরের জন্য AI ইন্টারনেটেও যাচাই করুক <em>(+<?php echo esc_html(sb_bn_digits($costs['research'])); ?> ক্রেডিট, শুধু সফল হলে)</em></label>

                <div class="sba-style" style="margin-top:12px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                    <label>🤖 মডেল<br><?php $cur_m = trim((string) sb_get('model_article')) ?: sb_get('model'); ?>
                    <select id="f-cmodel" title="কোন AI মডেলে লিখবে">
                        <?php foreach (sb_model_catalog() as $mid => $mm) : ?>
                            <option value="<?php echo esc_attr($mid); ?>" <?php selected($cur_m, $mid); ?>><?php echo esc_html($mid . ' — ' . $mm[2]); ?></option>
                        <?php endforeach; ?>
                        <?php if (!isset(sb_model_catalog()[$cur_m])) : ?><option value="<?php echo esc_attr($cur_m); ?>" selected><?php echo esc_html($cur_m); ?></option><?php endif; ?>
                    </select></label>
                    <label>📏 দৈর্ঘ্য<br><select id="f-clen" title="আর্টিকেলের দৈর্ঘ্য">
                        <option value="1">৮০০–১০০০ শব্দ (<?php echo esc_html(sb_bn_digits($costs['custom'])); ?> ক্রেডিট)</option>
                        <option value="2">১০০০–১৫০০ শব্দ (<?php echo esc_html(sb_bn_digits($costs['custom2'])); ?> ক্রেডিট)</option>
                        <option value="3">১৫০০+ শব্দ (<?php echo esc_html(sb_bn_digits($costs['custom3'])); ?> ক্রেডিট)</option>
                    </select></label>
                    <label>📝 লেখার ধরন<br>
                        <select id="f-style">
                            <option value="para">প্যারা ভিত্তিক (গল্পের মতো, পয়েন্ট কম)</option>
                            <option value="points">পয়েন্ট ভিত্তিক</option>
                            <option value="both">প্যারা + পয়েন্ট দুটোই</option>
                            <option value="ai">AI-র পরামর্শ অনুযায়ী</option>
                        </select></label>
                    <label>🔢 শব্দসংখ্যা (প্রায়)<br><input type="number" id="f-words" min="300" max="5000" step="50" placeholder="যেমন ১২০০" style="width:110px"></label>
                </div>

                <div class="sba-presets" style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <label>💾 প্রিসেট <select id="preset-sel"><option value="">— সেভ করা সেটিং বসান —</option></select></label>
                    <button type="button" class="button" id="preset-save">এই সেটিং সেভ করুন</button>
                    <button type="button" class="button-link-delete" id="preset-del" hidden>মুছুন</button>
                    <small class="sba-muted">ধরন, লেখার ধরন, শব্দসংখ্যা, মডেল, সামারি/FAQ ইত্যাদি মনে রাখে। বিনামূল্যে।</small>
                </div>

                <p class="sba-muted" style="margin:10px 0 0">💡 <strong>আগে বুঝে নিন:</strong> AI পুরো লেখা না লিখে আপনার তথ্য, সাইট ও ইন্টারনেট ঘেঁটে বলবে সে কী বুঝেছে, কী জানতে চায় ও কী যোগ করলে লেখা তথ্যবহুল হবে। আপনি ঠিক করে দিলে তবেই লেখা শুরু হবে।</p>
                <div style="margin-top:8px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <button type="button" class="button button-primary" id="brief-go">🧭 আগে বুঝে নিন (<?php echo esc_html(sb_bn_digits($costs['brief'])); ?> ক্রেডিট)</button>
                    <button type="button" class="button" data-ai="custom">🤖 সরাসরি লিখুন</button>
                </div>
                <div id="brief-box" class="sba-brief" hidden></div>
            </div>
        </section>

        <!-- Page 2: fix -->
        <section class="sba-step" data-step="2" hidden>
            <h2>পেজ ২: ঠিক করুন</h2>
            <p class="sba-muted">লেখা এখানে আসবে। সরাসরি এডিট করুন (বিনামূল্যে), অথবা নিচের বক্সে AI-কে বলুন কী বদলাতে হবে।</p>
            <div class="sba-ai sba-article">
<div class="sba-ai-head"><h3>আর্টিকেল <small class="sba-muted">(WordPress এডিটর, সরাসরি এডিট করা যায়, বিনামূল্যে)</small></h3></div>
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
                <div id="article-facts" class="sba-facts" hidden></div>
            
                <div class="sba-tools" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <button type="button" class="button" id="hard-check">🔍 কঠিন শব্দ চেক (বিনামূল্যে)</button>
                    <label id="ver-wrap" hidden>🕘 আগের ভার্সন <select id="ver-sel"><option value="">— ফিরে যান —</option></select></label>
                </div>
                <div id="hard-box" class="sba-factpanel" hidden></div>
                <div id="fact-panel" class="sba-factpanel" hidden></div>
                <div id="cover-panel" class="sba-coverpanel" hidden></div>
            </div>

            <div class="sba-ai sba-chat" id="chat-box">
                <div class="sba-ai-head"><h3>💬 Tell AI what to change</h3>
                    <button type="button" class="button" id="chat-undo" hidden>↩ Undo last draft change</button></div>
                <p class="sba-muted">One box for everything. Type like a chat, in Bangla or English. AI works on a <strong>working draft</strong> shown here; the editor stays untouched until you press <strong>Send final to editor</strong>. AI changes only what you ask, and the rest of the draft stays the same. Examples: <em>"make paragraph 3 shorter"</em>, <em>"add the fee after the first paragraph"</em>, <em>"add an FAQ about the exam date"</em>. Each change that is applied costs <strong><?php echo esc_html(sb_bn_digits($costs['chat'])); ?> credits</strong>; a question or no change is free. Your title is never changed.</p>
                <label class="sba-inline-check" id="chat-src-wrap" hidden><input type="checkbox" id="chat-src" checked> Let AI look at the attached PDF / images again (reads the source pages)</label>
                <div class="sba-chat-draft" id="chat-draft" hidden>
                    <div class="sba-chat-draft-head"><strong>📝 Working draft</strong> <span id="chat-draft-state" class="sba-muted"></span></div>
                    <div class="sba-chat-draft-body" id="chat-draft-body"></div>
                    <div class="sba-chat-draft-act">
                        <button type="button" class="button button-primary" id="chat-apply" disabled>✅ Send final to editor</button>
                        <button type="button" class="button" id="chat-reset">↺ Reset draft from editor</button>
                        <button type="button" class="button" id="chat-undo-editor" hidden>↩ Undo last send to editor</button>
                    </div>
                </div>
                <label class="sba-inline-check"><input type="checkbox" id="chat-direct"> Also send every change straight to the editor (otherwise changes stay in the draft until you press "Send final to editor")</label>
                <div class="sba-chat-log" id="chat-log" aria-live="polite"></div>
                <div class="sba-chat-in">
                    <textarea id="chat-text" rows="3" placeholder="What do you want to change? (Ctrl+Enter to send)"></textarea>
                    <button type="button" class="button button-primary" id="chat-send">Send <small>· <?php echo esc_html(sb_bn_digits($costs['chat'])); ?> credits</small></button>
                </div>
                <div class="sba-chat-rule" id="chat-rule" hidden><span id="chat-rule-text"></span> <button type="button" class="button" id="chat-rule-yes">Remember for all posts</button> <button type="button" class="button-link" id="chat-rule-no">No</button></div>
            </div>

            <textarea id="f-source" hidden></textarea>

        </section>

        <!-- Page 3: finish -->
        <section class="sba-step" data-step="3" hidden>
            <h2>পেজ ৩: শেষ করুন <small>(সব ঐচ্ছিক, আর্টিকেল তৈরির পরে)</small></h2>

            <div class="sba-ai sba-include">
                <div class="sba-ai-head"><h3>📌 পোস্টের সাথে কী কী থাকবে?</h3></div>
                <label class="sba-inline-check"><input type="checkbox" id="inc-summary" <?php checked($d['inc_summary']); ?>> সামারি <em>(আর্টিকেলের ওপরে "সংক্ষেপে" বক্স — AI হলে +<?php echo esc_html(sb_bn_digits($costs['summary'])); ?> ক্রেডিট)</em></label><br>
                <label class="sba-inline-check"><input type="checkbox" id="inc-faq" <?php checked($d['inc_faq']); ?>> FAQ <em>(আর্টিকেলের নিচে প্রশ্ন-উত্তর — AI হলে +<?php echo esc_html(sb_bn_digits($costs['faq'])); ?> ক্রেডিট)</em></label>
                <select id="f-faqn" title="FAQ কয়টি"><option value="3">৩</option><option value="5" selected>৫</option><option value="7">৭</option></select> টি<br>
                <label class="sba-inline-check"><input type="checkbox" id="inc-related"> স্মার্ট সম্পর্কিত পোস্ট/পেজ <em>(পাশে — AI সাইট থেকে মিলগুলো বেছে দেবে, +<?php echo esc_html(sb_bn_digits($costs['related'])); ?> ক্রেডিট)</em></label>
                <p class="sba-muted">পুরনো পোস্টে শুধু এগুলো যোগ করতে: টিক দিয়ে "শুধু টিক দেওয়াগুলো বানান" চাপুন — আর্টিকেল একদম বদলাবে না। নতুন পোস্টে টিক দিয়ে নিচের "সম্পূর্ণ আর্টিকেল" তৈরি করলে AI আর্টিকেল থেকে টিক দেওয়াগুলো নিজে বানিয়ে দেবে। হাতে লিখতে চাইলে নিচের সামারি ঘরে ও পেজ ৩-এর FAQ অংশে লিখুন। <strong>টিক না দিলে সামারি/FAQ বানানো হবে না, পোস্টেও দেখাবে না।</strong>
                <button type="button" class="button button-primary" id="inc-only">🤖 শুধু টিক দেওয়াগুলো বানান (আর্টিকেল বদলাবে না)</button>
                <button type="button" class="button" data-ai="faq">🤖 শুধু FAQ তৈরি করুন (<?php echo esc_html(sb_bn_digits($costs['faq'])); ?>)</button></p>
            </div>

            <div class="sba-ai">
                <div class="sba-ai-head"><h3>সামারি <small class="sba-muted">(মূল পোস্ট থেকে তৈরি হয়)</small></h3>
                    <button type="button" class="button" data-ai="summary">🤖 তৈরি করুন <em>(<?php echo esc_html(sb_bn_digits($costs['summary'])); ?>)</em></button></div>
                <textarea id="f-summary" rows="4"><?php echo esc_textarea($d['summary']); ?></textarea>
                <small class="sba-muted">আর্টিকেলের ওপরে "সংক্ষেপে" বক্সে দেখাবে। আর্টিকেল থেকে তৈরি হয়।</small>
            </div>

            <div class="sba-ai">
                <div class="sba-ai-head"><h3>FAQ (প্রশ্ন-উত্তর) <small class="sba-muted">(আর্টিকেল লেখার পর বাটনে তৈরি হয়)</small></h3>
                    <button type="button" class="button" data-ai="faq">🤖 তৈরি করুন <em>(<?php echo esc_html(sb_bn_digits($costs['faq'])); ?>)</em></button></div>
                <div id="faq-list"></div>
                <button type="button" class="button" id="faq-add">+ প্রশ্ন যোগ করুন</button>
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

            <label>সারাংশ (কার্ডে দেখাবে) <small>— এখন ফাঁকা রাখতে পারেন। আর্টিকেল তৈরি/লেখার পর পেজ ৩-এ "সারাংশ" বানালে এটা নিজে ভরে যাবে</small>
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
            <div class="sba-ai">
                <div class="sba-ai-head"><h3>📣 Facebook ক্যাপশন <small class="sba-muted">(আর্টিকেল থেকে; পেজে পোস্টের সময় কপি করুন)</small></h3>
                    <button type="button" class="button" data-ai="caption">🤖 তৈরি করুন <em>(<?php echo esc_html(sb_bn_digits($costs['caption'])); ?>)</em></button></div>
                <textarea id="f-caption" rows="5" placeholder="ক্যাপশন এখানে আসবে (সেভ হয় না, কপি করে নিন)"></textarea>
                <button type="button" class="button button-small" id="caption-copy">📋 কপি</button>
            </div>

            <h3 style="margin-top:18px">🗂️ ক্যাটাগরি ও অন্যান্য সেটিং</h3>
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


            <h3 style="margin-top:18px">✅ রিভিউ ও প্রকাশ</h3>
            <dl class="sba-review" id="review"></dl>
            <fieldset class="sba-status">
                <label><input type="radio" name="f-status" value="draft" <?php checked($d['status'] !== 'publish'); ?>> খসড়া হিসেবে সেভ</label>
                <label><input type="radio" name="f-status" value="publish" <?php checked($d['status'], 'publish'); ?>> প্রকাশ করুন</label>
            </fieldset>
            <p><button type="button" class="button button-primary button-hero" id="f-save">💾 সেভ করুন</button></p>
            <div id="save-result"></div>
            <div class="sba-card" id="word-swaps" hidden></div>
        </section>

        <div class="sba-nav">
            <button type="button" class="button" id="btn-prev">← পিছনে</button>
            <span id="msg" class="sba-msg" role="status"></span>
            <button type="button" class="button button-primary" id="btn-next">পরবর্তী →</button>
        </div>
    </form>
</div>
