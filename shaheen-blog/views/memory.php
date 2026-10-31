<?php
if (!defined('ABSPATH')) {
    exit;
}
$cfg   = sb_memory_cfg();
$items = sb_memory_all();
$site  = sb_site_mem();
$seed  = $site['sites'] ? implode("\n", $site['sites']) : trim((string) sb_get('site_urls'));
?>
<div class="wrap sba">
    <h1>🧠 মেমোরি</h1>
    <p class="sba-muted">আপনি লেখা ঠিক করে সেভ করলে যা শেখার তা এখানে জমা হয়, আর পরের প্রতিটি লেখায় AI এগুলো মেনে চলে। সবকিছু নিজে বদলানো, বন্ধ করা বা মুছে ফেলা যায়।</p>

    <div class="sba-card">
        <h2>📘 যা মনে রাখা আছে</h2>
        <label class="sba-inline-check"><input type="checkbox" id="mem-auto" <?php checked($cfg['auto']); ?>>
            লেখা সেভ করার সময় আপনার সংশোধন থেকে নিজে নিজে শেখো <em>(নতুন কিছু শিখলে শুধু তখনই খরচ হয়)</em></label>
        <div id="mem-list" style="margin-top:12px"></div>
        <p>
            <button type="button" class="button" id="mem-add-rule">＋ নিয়ম যোগ করুন</button>
            <button type="button" class="button" id="mem-add-fact">＋ তথ্য যোগ করুন</button>
            <button type="button" class="button button-primary" id="mem-save">💾 মেমোরি সেভ করুন</button>
            <span id="mem-msg" class="sba-muted"></span>
        </p>
        <p class="sba-muted"><strong>নিয়ম</strong> = লেখার ধরন (যেমন "ফোন নম্বর সবসময় শেষে দেবে")। <strong>তথ্য</strong> = সঠিক তথ্য (যেমন "প্রধান অফিসের ফোন: 01…")।</p>
    </div>

    <div class="sba-card">
        <h2>🔤 Word replacements</h2>
        <p class="sba-muted">Hard word → simple word. AI never uses the left word, and the site also replaces it automatically if AI slips. Whole words only: a changed form such as "প্রস্তুতির" needs its own row.</p>
        <div id="words-list"></div>
        <p><button type="button" class="button" id="words-add">＋ Add a word</button> <button type="button" class="button button-primary" id="words-save">💾 Save words</button> <span id="words-msg" class="sba-muted"></span></p>
    </div>

    <div class="sba-card">
        <h2>🌐 ওয়েবসাইট পড়ে মনে রাখা</h2>
        <p class="sba-muted">লিংক দিন (প্রতি লাইনে একটি, সর্বোচ্চ ৬টি)। প্লাগইন ওই সাইটের পেজ ও তার ভেতরের লিংক ঘুরে লেখাগুলো মনে রাখবে (একই সাইটের ভেতরেই থাকবে)। পরে যেকোনো লেখার সময় বিষয়ের সাথে মেলে এমন পেজ থেকে AI তথ্য নেবে। সাইট বদলালে আবার "পড়ো" চাপুন। এতে খরচ লাগে না।</p>
        <textarea id="site-urls" rows="4" style="width:100%" placeholder="https://shaheen.edu.bd/"><?php echo esc_textarea($seed); ?></textarea>
        <p>প্রতি সাইটে সর্বোচ্চ পেজ: <input type="number" id="site-max" min="5" max="80" value="<?php echo esc_attr($cfg['max_pages']); ?>" style="width:80px">
            <button type="button" class="button button-primary" id="site-go">🔄 পড়ো ও মনে রাখো</button>
            <button type="button" class="button" id="site-clear">🗑 ওয়েবসাইট মেমোরি মুছুন</button></p>
        <div id="site-status" class="sba-muted"><?php
            if ($site['pages']) {
                echo esc_html('মনে আছে: ' . sb_bn_digits(count($site['pages'])) . 'টি পেজ। সর্বশেষ আপডেট: ' . wp_date('j M Y, g:i a', $site['updated']));
            } else {
                echo 'এখনো কোনো ওয়েবসাইট মনে রাখা হয়নি।';
            }
        ?></div>
        <?php if ($site['pages']) : ?>
            <details style="margin-top:8px"><summary>মনে রাখা পেজের তালিকা</summary>
                <ol style="max-height:240px;overflow:auto">
                <?php foreach ($site['pages'] as $u => $pg) : ?>
                    <li><a href="<?php echo esc_url($u); ?>" target="_blank" rel="noopener"><?php echo esc_html($pg['title'] !== '' ? $pg['title'] : $u); ?></a></li>
                <?php endforeach; ?>
                </ol>
            </details>
        <?php endif; ?>
    </div>
</div>
<script>window.SBMEM = <?php echo wp_json_encode(array('items' => $items, 'words' => sb_words_all())); ?>;</script>
