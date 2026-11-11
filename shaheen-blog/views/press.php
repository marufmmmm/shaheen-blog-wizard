<?php
if (!defined('ABSPATH')) {
    exit;
}
$eid = isset($_GET['edit']) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
$e = array('id' => 0, 'title' => '', 'paper' => '', 'url' => '', 'date' => wp_date('Y-m-d'), 'cat' => 0, 'excerpt' => '', 'image_id' => 0, 'image_url' => '');
if ($eid && get_post_type($eid) === 'sb_post' && sb_is_press($eid) && current_user_can('edit_post', $eid)) {
    $t = get_the_terms($eid, 'sb_category');
    $e = array('id' => $eid, 'title' => get_the_title($eid), 'paper' => get_post_meta($eid, '_sb_press_paper', true),
        'url' => get_post_meta($eid, '_sb_press_url', true), 'date' => get_the_date('Y-m-d', $eid),
        'cat' => (!empty($t) && !is_wp_error($t)) ? (int) $t[0]->term_id : 0,
        'excerpt' => get_post_field('post_excerpt', $eid), 'image_id' => (int) get_post_thumbnail_id($eid),
        'image_url' => (string) get_the_post_thumbnail_url($eid, 'medium'));
}
$cats = get_terms(array('taxonomy' => 'sb_category', 'hide_empty' => false, 'orderby' => 'term_id'));
$items = get_posts(array('post_type' => 'sb_post', 'post_status' => 'any', 'numberposts' => 100,
    'meta_key' => '_sb_press_url', 'meta_compare' => 'EXISTS'));
?>
<div class="wrap sba">
    <h1>📰 সংবাদপত্রে শাহীন</h1>
    <p class="sba-muted">বিভিন্ন পত্রিকায় প্রকাশিত আমাদের সংবাদের শিরোনাম ও লিংক এখানে দিন। এগুলো ব্লগ পেজে নির্বাচিত ক্যাটাগরিতে কার্ড হিসেবে দেখাবে; ক্লিক করলে পত্রিকার লিংক নতুন ট্যাবে খুলবে। (খরচ লাগে না)</p>


    <div class="sba-card" style="margin:0 0 16px">
        <h2>📥 ইভেন্ট পেজের সংবাদ এখানে আনুন</h2>
        <p class="sba-muted">ইভেন্ট পেজে (যেমন Spelling Star) যে সংবাদের লিংক আছে, সেগুলো থেকে শিরোনাম, পত্রিকার নাম, আসল প্রকাশের তারিখ, ছোট বর্ণনা ও সংবাদের ছবি এনে এখানে কার্ড বানাবে। যেগুলো এখানে আগেই আছে সেগুলো বাদ যাবে; যে লিংকে পড়ার মতো সংবাদ পাওয়া যায় না সেটিও বাদ যাবে। (খরচ লাগে না)</p>
        <p><select id="pi-cat"><option value="0">— ক্যাটাগরি বেছে নিন —</option>
            <?php if (!is_wp_error($cats)) : foreach ($cats as $t) : ?><option value="<?php echo (int) $t->term_id; ?>"><?php echo esc_html($t->name); ?></option><?php endforeach; endif; ?></select>
            <button type="button" class="button button-primary" id="pi-go">📥 সংবাদ আনুন</button> <span id="pi-msg" class="sba-msg"></span></p>
    </div>
    <script>
    (function(){var b=document.getElementById('pi-go'),m=document.getElementById('pi-msg');if(!b||typeof SB==='undefined'){return;}
    b.addEventListener('click',function(){b.disabled=true;var n=0,sk=0;m.textContent='আনা হচ্ছে…';
    (function step(){var fd=new FormData();fd.append('action','sb_press_import');fd.append('nonce',SB.nonce);fd.append('category',document.getElementById('pi-cat').value);
    fetch(SB.ajax||ajaxurl,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
    if(!j.success){m.textContent='ত্রুটি হয়েছে';b.disabled=false;return;}
    n+=j.data.done.length;sk+=j.data.skipped.length;m.textContent=n+'টি এসেছে'+(sk?', '+sk+'টি বাদ':'')+(j.data.left?' … চলছে':'');
    if(j.data.left>0&&(j.data.done.length||j.data.skipped.length)){step();}else{m.textContent=n?('শেষ: '+n+'টি নতুন সংবাদ এসেছে'+(sk?', '+sk+'টি বাদ গেছে':'')+'।'):('নতুন কিছু আনার নেই। ইভেন্ট পেজের সব সংবাদ আগেই এখানে আনা হয়েছে (নিচের তালিকা দেখুন)'+(sk?'; '+sk+'টি লিংক পড়া যায়নি তাই বাদ।':'।'));b.disabled=false;if(n){setTimeout(function(){location.reload();},1200);}}
    }).catch(function(){m.textContent='সংযোগে সমস্যা';b.disabled=false;});})();});})();
    </script>

    <div class="sba-grid sba-grid--2">
        <div class="sba-card sba-form">
            <h2><?php echo $e['id'] ? 'সংবাদ এডিট' : 'নতুন সংবাদ যোগ করুন'; ?></h2>
            <input type="hidden" id="pr-id" value="<?php echo (int) $e['id']; ?>">
            <label>সংবাদের শিরোনাম *<input type="text" id="pr-title" value="<?php echo esc_attr($e['title']); ?>"></label>
            <label>সংক্ষিপ্ত বর্ণনা <small>(কার্ডে শিরোনামের নিচে দেখাবে, ২-৩ লাইন)</small><textarea id="pr-excerpt" rows="3"><?php echo esc_textarea($e['excerpt']); ?></textarea></label>
            <label>পত্রিকার নাম *<input type="text" id="pr-paper" value="<?php echo esc_attr($e['paper']); ?>" placeholder="যেমন: প্রথম আলো"></label>
            <label>সংবাদের লিংক *<input type="text" id="pr-url" value="<?php echo esc_attr($e['url']); ?>" placeholder="https://…"></label>
            <div class="sba-row">
                <label>প্রকাশের তারিখ<input type="date" id="pr-date" value="<?php echo esc_attr($e['date']); ?>"></label>
                <label>ক্যাটাগরি
                    <select id="pr-cat">
                        <option value="0">— বেছে নিন —</option>
                        <?php if (!is_wp_error($cats)) : foreach ($cats as $t) : ?>
                            <option value="<?php echo (int) $t->term_id; ?>" <?php selected($e['cat'], $t->term_id); ?>><?php echo esc_html($t->name); ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                </label>
            </div>
            <div class="sba-image-box">
                <img id="pr-img" src="<?php echo esc_url($e['image_url']); ?>" alt="" <?php echo $e['image_url'] ? '' : 'hidden'; ?>>
                <input type="hidden" id="pr-image-id" value="<?php echo (int) $e['image_id']; ?>">
                <div class="sba-image-actions"><button type="button" class="button" id="pr-pick">ছবি / কাটিং বেছে নিন</button>
                    <button type="button" class="button-link-delete" id="pr-unpick" <?php echo $e['image_url'] ? '' : 'hidden'; ?>>ছবি সরান</button></div>
            </div>
            <p><button type="button" class="button button-primary" id="pr-save">💾 সেভ করুন</button>
               <?php if ($e['id']) : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=sb-press')); ?>">নতুন যোগ</a><?php endif; ?>
               <span id="msg" class="sba-msg"></span></p>
        </div>

        <div class="sba-card">
            <h2>যোগ করা সংবাদ (<?php echo esc_html(sb_bn_digits(count($items))); ?>)</h2>
            <?php if (!$items) : ?><p class="sba-muted">এখনো কোনো সংবাদ নেই।</p><?php else : ?>
            <ul class="sba-list">
                <?php foreach ($items as $p) : ?>
                    <li data-id="<?php echo (int) $p->ID; ?>">
                        <span><strong><?php echo esc_html(get_the_title($p)); ?></strong><br>
                            <small class="sba-muted"><?php echo esc_html(get_post_meta($p->ID, '_sb_press_paper', true) . ' · ' . sb_post_category($p->ID)['bn'] . ' · ' . sb_bn_date(get_post_time('U', false, $p))); ?></small></span>
                        <span class="sba-actions">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=sb-press&edit=' . $p->ID)); ?>">এডিট</a>
                            <a href="#" class="sba-trash" data-id="<?php echo (int) $p->ID; ?>">ট্র্যাশ</a>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
