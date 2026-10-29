<?php
if (!defined('ABSPATH')) {
    exit;
}
$cfg = sb_phones_cfg();
$eid = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
$e = array_fill_keys(sb_phone_fields(), '');
$e['id'] = 0;
if ($eid && get_post_type($eid) === 'sb_phone') {
    $e = sb_phone_data($eid);
}
$all = sb_phone_all(array('publish', 'draft'));
$next = wp_next_scheduled('sb_phones_cron');
$logs = get_option('sb_phones_log', array());
$label = array('brand' => 'ব্র্যান্ড *', 'model' => 'মডেল *', 'year' => 'বছর', 'h' => 'উচ্চতা (মিমি)', 'w' => 'প্রস্থ (মিমি)', 't' => 'পুরুত্ব (মিমি)',
    'weight' => 'ওজন (গ্রাম)', 'screen' => 'স্ক্রিন (ইঞ্চি)', 'ram' => 'RAM', 'storage' => 'স্টোরেজ', 'battery' => 'ব্যাটারি (mAh)', 'chipset' => 'চিপসেট', 'price' => 'দাম', 'url' => 'সূত্র লিংক');
?>
<div class="wrap sba">
    <h1>📱 মোবাইল মডেল</h1>
    <p class="sba-muted">ব্লগের যেকোনো পেজে <code>[shaheen_phones]</code> বসালে সব মডেল সার্চ, ব্র্যান্ড-ফিল্টার ও মাপ (ডাইমেনশন) সহ দেখাবে। AI প্রতি <?php echo esc_html(sb_bn_digits((int) $cfg['days'])); ?> দিনে ওয়েব সার্চ করে নতুন মডেল নিজেই যোগ করে।</p>
    <p><span id="ph-msg" class="sba-msg"></span></p>

    <div class="sba-grid sba-grid--2">
        <div class="sba-card sba-form">
            <h2>🤖 অটো আপডেট (AI ওয়েব সার্চ)</h2>
            <label class="sba-inline-check"><input type="checkbox" id="c-auto" <?php checked($cfg['auto']); ?>> প্রতি <input type="number" id="c-days" min="1" max="90" value="<?php echo (int) $cfg['days']; ?>" style="width:70px"> দিনে নিজে নতুন মডেল খুঁজবে</label>
            <div class="sba-row">
                <label>প্রতিবার সর্বোচ্চ নতুন মডেল<input type="number" id="c-per" min="1" max="60" value="<?php echo (int) $cfg['per_run']; ?>"></label>
                <label>প্রথমবার (তালিকা ফাঁকা থাকলে)<input type="number" id="c-init" min="5" max="60" value="<?php echo (int) $cfg['initial']; ?>"></label>
            </div>
            <label>কোন ব্র্যান্ড খুঁজবে <small>(কমা দিয়ে)</small><textarea id="c-brands" rows="3"><?php echo esc_textarea($cfg['brands']); ?></textarea></label>
            <label>বাজার/ফোকাস<input type="text" id="c-market" value="<?php echo esc_attr($cfg['market']); ?>"></label>
            <label class="sba-inline-check"><input type="checkbox" id="c-review" <?php checked($cfg['review']); ?>> AI-এর যোগ করা মডেল আগে ড্রাফটে রাখো, আমি দেখে প্রকাশ করব</label>
            <p><button type="button" class="button button-primary" id="ph-cfg-save">💾 সেটিংস সেভ</button>
               <button type="button" class="button" id="ph-run">🔍 এখনই নতুন মডেল খুঁজুন (<?php echo esc_html(sb_money(sb_feature_cost('phones'))); ?>)</button>
               <button type="button" class="button" id="ph-page">📄 মোবাইল পেজ তৈরি/দেখুন</button></p>
            <p class="sba-muted">পরবর্তী অটো-রান: <?php echo $next ? esc_html(wp_date('j M Y, g:i a', $next)) : 'বন্ধ'; ?> (সাইটে ভিজিটর এলে চলে — ওয়ার্ডপ্রেসের নিয়ম)। অটো-রানে ইউজারের বিল যোগ হয় না, শুধু আপনার OpenAI API ব্যালেন্স খরচ হয়। AI নিশ্চিত না হলে মাপ ফাঁকা রাখে, অনুমান করে না — তবু ভুল দেখলে এডিট করুন।</p>
            <?php if (is_array($logs) && $logs) : ?>
                <details><summary>সাম্প্রতিক রান</summary><ul>
                <?php foreach ($logs as $l) : ?>
                    <li><?php echo esc_html(wp_date('j M, g:i a', $l['t']) . ' · ' . $l['by'] . ' · '); ?>
                        <?php echo $l['error'] ? esc_html('ব্যর্থ: ' . $l['error']) : esc_html('নতুন ' . $l['added'] . ', বাদ ' . $l['skipped']); ?></li>
                <?php endforeach; ?></ul></details>
            <?php endif; ?>
        </div>

        <div class="sba-card sba-form">
            <h2><?php echo $e['id'] ? 'মডেল এডিট' : 'নিজে মডেল যোগ করুন'; ?></h2>
            <input type="hidden" id="ph-id" value="<?php echo (int) $e['id']; ?>">
            <div class="sba-row"><?php foreach (array('brand', 'model') as $f) : ?>
                <label><?php echo esc_html($label[$f]); ?><input type="text" id="ph-<?php echo esc_attr($f); ?>" value="<?php echo esc_attr($e[$f]); ?>"></label>
            <?php endforeach; ?></div>
            <div class="sba-row"><?php foreach (array('h', 'w', 't') as $f) : ?>
                <label><?php echo esc_html($label[$f]); ?><input type="text" id="ph-<?php echo esc_attr($f); ?>" value="<?php echo esc_attr($e[$f]); ?>"></label>
            <?php endforeach; ?></div>
            <div class="sba-row"><?php foreach (array('weight', 'screen', 'year') as $f) : ?>
                <label><?php echo esc_html($label[$f]); ?><input type="text" id="ph-<?php echo esc_attr($f); ?>" value="<?php echo esc_attr($e[$f]); ?>"></label>
            <?php endforeach; ?></div>
            <div class="sba-row"><?php foreach (array('ram', 'storage', 'battery') as $f) : ?>
                <label><?php echo esc_html($label[$f]); ?><input type="text" id="ph-<?php echo esc_attr($f); ?>" value="<?php echo esc_attr($e[$f]); ?>"></label>
            <?php endforeach; ?></div>
            <div class="sba-row"><?php foreach (array('chipset', 'price') as $f) : ?>
                <label><?php echo esc_html($label[$f]); ?><input type="text" id="ph-<?php echo esc_attr($f); ?>" value="<?php echo esc_attr($e[$f]); ?>"></label>
            <?php endforeach; ?></div>
            <label><?php echo esc_html($label['url']); ?><input type="text" id="ph-url" value="<?php echo esc_attr($e['url']); ?>" placeholder="https://…"></label>
            <p><button type="button" class="button button-primary" id="ph-save">💾 সেভ করুন</button>
               <?php if ($e['id']) : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=sb-phones')); ?>">নতুন যোগ</a><?php endif; ?></p>
        </div>
    </div>

    <div class="sba-card">
        <h2>সব মডেল (<?php echo esc_html(sb_bn_digits(count($all))); ?>)</h2>
        <?php if (!$all) : ?><p class="sba-muted">এখনো কোনো মডেল নেই। "এখনই নতুন মডেল খুঁজুন" চাপলে AI প্রথম তালিকা বানিয়ে দেবে।</p><?php else : ?>
        <table class="widefat striped"><thead><tr><th>মডেল</th><th>মাপ (উ×প্র×পু)</th><th>ওজন</th><th>উৎস</th><th></th></tr></thead><tbody>
        <?php foreach ($all as $p) : ?>
            <tr>
                <td><strong><?php echo esc_html($p['brand'] . ' ' . $p['model']); ?></strong><?php echo $p['status'] === 'draft' ? ' <em>(ড্রাফট)</em>' : ''; ?></td>
                <td><?php echo esc_html(sb_phone_dim_text($p) ?: '—'); ?></td>
                <td><?php echo $p['weight'] !== '' ? esc_html(sb_bn_digits($p['weight'])) . ' গ্রা' : '—'; ?></td>
                <td><?php echo $p['source'] === 'ai' ? '🤖 AI' : '✍️ নিজে'; ?></td>
                <td>
                    <?php if ($p['status'] === 'draft') : ?><button type="button" class="button button-small" data-pub="<?php echo (int) $p['id']; ?>">প্রকাশ</button><?php endif; ?>
                    <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=sb-phones&edit=' . $p['id'])); ?>">এডিট</a>
                    <button type="button" class="button-link-delete" data-del="<?php echo (int) $p['id']; ?>">মুছুন</button>
                </td>
            </tr>
        <?php endforeach; ?></tbody></table>
        <?php endif; ?>
    </div>
</div>
<script>var SBPH = {url: <?php echo wp_json_encode(admin_url('admin.php?page=sb-phones')); ?>};</script>
