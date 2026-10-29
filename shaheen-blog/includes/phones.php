<?php
/**
 * Mobile phone models: catalogue (with dimensions), shortcode, and an AI web-search
 * job that finds new models every N days (default 15).
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'sb_register_phone_type');
function sb_register_phone_type() {
    register_post_type('sb_phone', array(
        'label'               => 'মোবাইল মডেল',
        'public'              => false,
        'show_ui'             => false,
        'supports'            => array('title'),
        'exclude_from_search' => true,
        'rewrite'             => false,
    ));
}

function sb_phones_defaults() {
    return array(
        'auto'     => 1,
        'days'     => 15,
        'per_run'  => 20,
        'initial'  => 40,
        'review'   => 0,
        'brands'   => 'Samsung, Apple, Xiaomi, Redmi, POCO, Realme, Oppo, Vivo, Infinix, Tecno, itel, Symphony, Walton, Google Pixel, OnePlus, Motorola, Nokia, Huawei, Honor',
        'market'   => 'বাংলাদেশে পাওয়া যায় বা আন্তর্জাতিকভাবে জনপ্রিয়',
    );
}

function sb_phones_cfg($key = null) {
    $saved = get_option('sb_phones_cfg', array());
    $cfg = array_merge(sb_phones_defaults(), is_array($saved) ? $saved : array());
    return $key === null ? $cfg : (isset($cfg[$key]) ? $cfg[$key] : '');
}

/* ------------------------------------------------------------ data helpers */
function sb_phone_fields() {
    return array('brand', 'model', 'year', 'h', 'w', 't', 'weight', 'screen', 'ram', 'storage', 'battery', 'chipset', 'price', 'url');
}

function sb_phone_key($brand, $model) {
    return sanitize_title(strtolower(trim($brand) . ' ' . trim($model)));
}

function sb_phone_find($brand, $model) {
    $q = get_posts(array('post_type' => 'sb_phone', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids',
        'meta_key' => '_sb_ph_key', 'meta_value' => sb_phone_key($brand, $model)));
    return $q ? (int) $q[0] : 0;
}

function sb_phone_clean($in) {
    $num = function ($v, $min, $max, $dec = 1) {
        $v = strtr((string) $v, array('০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'));
        if (!preg_match('/\d+(?:[.,]\d+)?/', $v, $m)) {
            return '';
        }
        $n = (float) str_replace(',', '.', $m[0]);
        return ($n >= $min && $n <= $max) ? (string) round($n, $dec) : '';   // implausible values are dropped, never guessed
    };
    $year = (int) preg_replace('/\D/', '', isset($in['year']) ? (string) $in['year'] : '');
    $url = isset($in['url']) ? esc_url_raw(trim((string) $in['url'])) : '';
    return array(
        'brand'   => sanitize_text_field(isset($in['brand']) ? $in['brand'] : ''),
        'model'   => sanitize_text_field(isset($in['model']) ? $in['model'] : ''),
        'year'    => ($year >= 2000 && $year <= (int) wp_date('Y') + 1) ? (string) $year : '',
        'h'       => $num(isset($in['h']) ? $in['h'] : '', 60, 300),
        'w'       => $num(isset($in['w']) ? $in['w'] : '', 30, 200),
        't'       => $num(isset($in['t']) ? $in['t'] : '', 3, 30),
        'weight'  => $num(isset($in['weight']) ? $in['weight'] : '', 50, 800, 0),
        'screen'  => $num(isset($in['screen']) ? $in['screen'] : '', 1.5, 12, 2),
        'ram'     => sanitize_text_field(isset($in['ram']) ? $in['ram'] : ''),
        'storage' => sanitize_text_field(isset($in['storage']) ? $in['storage'] : ''),
        'battery' => $num(isset($in['battery']) ? $in['battery'] : '', 300, 20000, 0),
        'chipset' => sanitize_text_field(isset($in['chipset']) ? $in['chipset'] : ''),
        'price'   => sanitize_text_field(isset($in['price']) ? $in['price'] : ''),
        'url'     => $url,
    );
}

/** Insert or update. Returns post id or WP_Error. */
function sb_phone_store($d, $id = 0, $status = 'publish', $source = 'manual') {
    if ($d['brand'] === '' || $d['model'] === '') {
        return new WP_Error('sb_ph', 'ব্র্যান্ড ও মডেলের নাম দিন।');
    }
    if (!$id) {
        $id = sb_phone_find($d['brand'], $d['model']);
    }
    $args = array('post_type' => 'sb_phone', 'post_title' => $d['brand'] . ' ' . $d['model'], 'post_status' => $status);
    if ($id) {
        $args['ID'] = $id;
        $id = wp_update_post($args, true);
    } else {
        $id = wp_insert_post($args, true);
    }
    if (is_wp_error($id)) {
        return $id;
    }
    foreach (sb_phone_fields() as $f) {
        update_post_meta($id, '_sb_ph_' . $f, $d[$f]);
    }
    update_post_meta($id, '_sb_ph_key', sb_phone_key($d['brand'], $d['model']));
    if (!get_post_meta($id, '_sb_ph_source', true)) {
        update_post_meta($id, '_sb_ph_source', $source);
    }
    return $id;
}

function sb_phone_data($id) {
    $d = array('id' => (int) $id);
    foreach (sb_phone_fields() as $f) {
        $d[$f] = (string) get_post_meta($id, '_sb_ph_' . $f, true);
    }
    $d['source'] = (string) get_post_meta($id, '_sb_ph_source', true);
    $d['status'] = get_post_status($id);
    return $d;
}

function sb_phone_dim_text($d) {
    if ($d['h'] === '' || $d['w'] === '' || $d['t'] === '') {
        return '';
    }
    return sb_bn_digits($d['h'] . ' × ' . $d['w'] . ' × ' . $d['t']) . ' মিমি';
}

function sb_phone_all($status = 'publish') {
    $ids = get_posts(array('post_type' => 'sb_phone', 'post_status' => $status, 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC'));
    return array_map('sb_phone_data', $ids);
}

/* ------------------------------------------------------------- AI discovery */
function sb_phones_ai($count, $initial) {
    $key = sb_get('api_key');
    if ($key === '') {
        return new WP_Error('sb_nokey', 'OpenAI API কী দেওয়া নেই।');
    }
    $known = array();
    foreach (get_posts(array('post_type' => 'sb_phone', 'post_status' => 'any', 'numberposts' => 600, 'fields' => 'ids')) as $pid) {
        $known[] = get_the_title($pid);
    }
    $cfg = sb_phones_cfg();
    $today = wp_date('Y-m-d');
    $scope = $initial
        ? 'তালিকা এখন প্রায় ফাঁকা। ব্র্যান্ডগুলো থেকে সর্বশেষ ও জনপ্রিয় সাম্প্রতিক (গত ৩ বছরের) মডেল দাও।'
        : 'শুধু গত ৩০ দিনের মধ্যে ঘোষিত বা বাজারে আসা নতুন মডেল দাও (আজ ' . $today . ')।';
    $sys = 'তুমি একজন মোবাইল ফোন স্পেসিফিকেশন গবেষক। ওয়েব সার্চ করে প্রস্তুতকারকের অফিসিয়াল বা নির্ভরযোগ্য স্পেক সাইট থেকে তথ্য নাও। '
        . 'কঠোর নিয়ম: যে তথ্য সার্চে নিশ্চিত পাওনি সেটা অনুমান করবে না — ফাঁকা ("") রাখবে। শুধু JSON অ্যারে ফেরত দেবে, আর কিছু নয়। '
        . 'প্রতিটি আইটেমের কী: brand, model, year (ঘোষণার বছর, সংখ্যা), h, w, t (মিলিমিটারে উচ্চতা, প্রস্থ, পুরুত্ব — শুধু সংখ্যা), weight (গ্রাম), screen (ইঞ্চি), ram (যেমন "8GB"), storage (যেমন "256GB"), battery (mAh সংখ্যা), chipset, price (জানলে, মুদ্রাসহ), url (যে পাতা থেকে তথ্য নিয়েছ)।';
    $user = $scope . "\nব্র্যান্ড: " . $cfg['brands'] . "\nবাজার: " . $cfg['market'] . "\nসর্বোচ্চ " . (int) $count . "টি নতুন মডেল।"
        . "\nনিচের মডেলগুলো আগে থেকেই আছে, এগুলো আবার দেবে না:\n" . implode('; ', array_slice($known, 0, 400));

    if (function_exists('set_time_limit')) {
        @set_time_limit(240); // phpcs:ignore
    }
    $resp = wp_remote_post('https://api.openai.com/v1/responses', array(
        'timeout' => 200,
        'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array(
            'model' => sb_model_for('article'),
            'input' => array(array('role' => 'system', 'content' => $sys), array('role' => 'user', 'content' => $user)),
            'tools' => array(array('type' => 'web_search_preview')),
            'max_output_tokens' => 6000,
        )),
    ));
    if (is_wp_error($resp)) {
        return new WP_Error('sb_http', 'OpenAI-তে সংযোগ হয়নি: ' . $resp->get_error_message());
    }
    $code = wp_remote_retrieve_response_code($resp);
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code !== 200 || !is_array($data) || empty($data['output'])) {
        $msg = isset($data['error']['message']) ? $data['error']['message'] : 'HTTP ' . $code;
        return new WP_Error('sb_api', 'ওয়েব সার্চ করা যায়নি (' . $msg . ')। মডেল/অ্যাকাউন্টে web search চালু আছে কিনা দেখুন।');
    }
    $text = '';
    foreach ($data['output'] as $item) {
        if (isset($item['type']) && $item['type'] === 'message' && !empty($item['content'])) {
            foreach ($item['content'] as $c) {
                if (isset($c['text'])) {
                    $text .= $c['text'];
                }
            }
        }
    }
    $start = strpos($text, '[');
    $end = strrpos($text, ']');
    $list = ($start !== false && $end !== false && $end > $start) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
    if (!is_array($list)) {
        return new WP_Error('sb_json', 'AI-এর উত্তর পড়া যায়নি। আবার চেষ্টা করুন।');
    }
    return $list;
}

/**
 * Run one discovery pass. $by = user id for manual runs (charged), 0 for the scheduled job.
 */
function sb_phones_run($by = 0) {
    $cfg = sb_phones_cfg();
    $existing = (int) wp_count_posts('sb_phone')->publish + (int) wp_count_posts('sb_phone')->draft;
    $initial = $existing < 15;
    $count = $initial ? (int) $cfg['initial'] : (int) $cfg['per_run'];
    $list = sb_phones_ai(max(1, min(60, $count)), $initial);
    $log = array('t' => time(), 'by' => $by ? 'ম্যানুয়াল' : 'অটো', 'added' => 0, 'skipped' => 0, 'error' => '');
    if (is_wp_error($list)) {
        $log['error'] = $list->get_error_message();
        sb_phones_log($log);
        return $list;
    }
    $status = $cfg['review'] ? 'draft' : 'publish';
    foreach ($list as $row) {
        if (!is_array($row)) {
            continue;
        }
        $d = sb_phone_clean($row);
        if ($d['brand'] === '' || $d['model'] === '' || sb_phone_find($d['brand'], $d['model'])) {
            $log['skipped']++;
            continue;
        }
        $id = sb_phone_store($d, 0, $status, 'ai');
        if (!is_wp_error($id)) {
            $log['added']++;
        }
    }
    sb_phones_log($log);
    return $log;
}

function sb_phones_log($entry) {
    $l = get_option('sb_phones_log', array());
    if (!is_array($l)) {
        $l = array();
    }
    array_unshift($l, $entry);
    update_option('sb_phones_log', array_slice($l, 0, 12), false);
}

/* ------------------------------------------------------------ schedule (N days) */
add_action('init', 'sb_phones_schedule');
function sb_phones_schedule() {
    $next = wp_next_scheduled('sb_phones_cron');
    if (!sb_phones_cfg('auto')) {
        if ($next) {
            wp_clear_scheduled_hook('sb_phones_cron');
        }
        return;
    }
    if (!$next) {
        wp_schedule_single_event(time() + max(1, (int) sb_phones_cfg('days')) * DAY_IN_SECONDS, 'sb_phones_cron');
    }
}

add_action('sb_phones_cron', 'sb_phones_cron_run');
function sb_phones_cron_run() {
    if (sb_phones_cfg('auto')) {
        sb_phones_run(0);
    }
    wp_clear_scheduled_hook('sb_phones_cron');
    if (sb_phones_cfg('auto')) {
        wp_schedule_single_event(time() + max(1, (int) sb_phones_cfg('days')) * DAY_IN_SECONDS, 'sb_phones_cron');
    }
}

/* ------------------------------------------------------------------ admin ajax */
add_action('wp_ajax_sb_phone_save', function () {
    sb_ajax_guard('manage_options');
    $d = sb_phone_clean(array_intersect_key((array) wp_unslash($_POST), array_flip(sb_phone_fields()))); // phpcs:ignore
    $id = sb_phone_store($d, (int) sb_req('id', 0), 'publish', 'manual');
    if (is_wp_error($id)) {
        wp_send_json_error(array('message' => $id->get_error_message()));
    }
    wp_send_json_success(array('id' => $id));
});

add_action('wp_ajax_sb_phone_delete', function () {
    sb_ajax_guard('manage_options');
    $id = (int) sb_req('id', 0);
    if (get_post_type($id) !== 'sb_phone') {
        wp_send_json_error(array('message' => 'মডেলটি পাওয়া যায়নি।'));
    }
    wp_delete_post($id, true);
    wp_send_json_success();
});

add_action('wp_ajax_sb_phone_publish', function () {
    sb_ajax_guard('manage_options');
    $id = (int) sb_req('id', 0);
    if (get_post_type($id) !== 'sb_phone') {
        wp_send_json_error(array('message' => 'মডেলটি পাওয়া যায়নি।'));
    }
    wp_update_post(array('ID' => $id, 'post_status' => 'publish'));
    wp_send_json_success();
});

add_action('wp_ajax_sb_phones_cfg', function () {
    sb_ajax_guard('manage_options');
    $b = sanitize_textarea_field(sb_req('brands'));
    update_option('sb_phones_cfg', array(
        'auto'    => sb_req('auto') === '1' ? 1 : 0,
        'days'    => max(1, min(90, (int) sb_req('days', 15))),
        'per_run' => max(1, min(60, (int) sb_req('per_run', 20))),
        'initial' => max(5, min(60, (int) sb_req('initial', 40))),
        'review'  => sb_req('review') === '1' ? 1 : 0,
        'brands'  => $b !== '' ? $b : sb_phones_defaults()['brands'],
        'market'  => sanitize_text_field(sb_req('market', sb_phones_defaults()['market'])),
    ), false);
    wp_clear_scheduled_hook('sb_phones_cron');   // re-plan with the new interval
    sb_phones_schedule();
    wp_send_json_success();
});

add_action('wp_ajax_sb_phones_run', function () {
    sb_ajax_guard('manage_options');
    $uid = get_current_user_id();
    $cost = sb_feature_cost('phones');
    $st = SB_Credits::state($uid);
    if ($st['remaining'] < $cost) {
        wp_send_json_error(array('message' => 'ব্যালেন্স যথেষ্ট নয়। প্রয়োজন ' . sb_money($cost) . ', আছে ' . sb_money($st['remaining']) . '।', 'balance' => $st));
    }
    $r = sb_phones_run($uid);
    if (is_wp_error($r)) {
        wp_send_json_error(array('message' => $r->get_error_message(), 'balance' => $st));
    }
    $new = SB_Credits::charge($uid, 'phones', $cost, 0);
    wp_send_json_success(array('added' => $r['added'], 'skipped' => $r['skipped'], 'cost' => $cost,
        'balance' => is_wp_error($new) ? $st : $new));
});

add_action('wp_ajax_sb_phones_page', function () {
    sb_ajax_guard('manage_options');
    $id = (int) get_option('sb_phones_page_id');
    if (!$id || get_post_status($id) === false || get_post_status($id) === 'trash') {
        $id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'মোবাইল ফোন মডেল',
            'post_name' => 'mobile-phones', 'post_content' => '[shaheen_phones]'));
        if (is_wp_error($id)) {
            wp_send_json_error(array('message' => $id->get_error_message()));
        }
        update_option('sb_phones_page_id', $id, false);
    }
    wp_send_json_success(array('url' => get_permalink($id)));
});

/* ----------------------------------------------------------------- shortcode */
add_action('wp_enqueue_scripts', function () {
    if (is_singular()) {
        $p = get_post();
        if ($p && has_shortcode($p->post_content, 'shaheen_phones')) {
            sb_enqueue_front(false);
            wp_enqueue_style('sb-phones', SB_URL . 'assets/css/phones.css', array('sb-blog'), SB_VERSION . '.' . filemtime(SB_PATH . 'assets/css/phones.css'));
            wp_enqueue_script('sb-phones', SB_URL . 'assets/js/phones.js', array(), SB_VERSION . '.' . filemtime(SB_PATH . 'assets/js/phones.js'), true);
        }
    }
});

add_shortcode('shaheen_phones', function () {
    $phones = sb_phone_all('publish');
    $brands = array();
    foreach ($phones as $p) {
        $brands[$p['brand']] = isset($brands[$p['brand']]) ? $brands[$p['brand']] + 1 : 1;
    }
    ksort($brands);
    ob_start(); ?>
<div class="shb shb-ph" data-shb-ph>
    <div class="shb-ph-head">
        <h2>📱 মোবাইল ফোন মডেল</h2>
        <p>মডেলের নাম, ব্র্যান্ড ও সাইজ (উচ্চতা × প্রস্থ × পুরুত্ব) দেখুন। নতুন মডেল নিয়মিত যোগ হয়।</p>
    </div>
    <div class="shb-ph-tools">
        <input type="search" class="shb-ph-q" placeholder="মডেল বা ব্র্যান্ড খুঁজুন… (যেমন: Galaxy, Redmi)" aria-label="খুঁজুন">
        <select class="shb-ph-sort" aria-label="সাজান">
            <option value="new">নতুন আগে</option>
            <option value="h">ছোট ফোন আগে (উচ্চতা)</option>
            <option value="wt">হালকা আগে (ওজন)</option>
            <option value="az">নামের ক্রম</option>
        </select>
    </div>
    <div class="shb-ph-brands">
        <button type="button" class="on" data-brand="">সকল (<?php echo esc_html(sb_bn_digits(count($phones))); ?>)</button>
        <?php foreach ($brands as $b => $n) : ?>
            <button type="button" data-brand="<?php echo esc_attr($b); ?>"><?php echo esc_html($b); ?> (<?php echo esc_html(sb_bn_digits($n)); ?>)</button>
        <?php endforeach; ?>
    </div>
    <?php if (!$phones) : ?><p class="shb-ph-empty">এখনো কোনো মডেল যোগ হয়নি।</p><?php endif; ?>
    <div class="shb-ph-grid">
        <?php foreach ($phones as $i => $p) :
            $dim = sb_phone_dim_text($p);
            ?>
            <article class="shb-ph-card" data-brand="<?php echo esc_attr($p['brand']); ?>" data-name="<?php echo esc_attr(strtolower($p['brand'] . ' ' . $p['model'])); ?>"
                     data-i="<?php echo (int) $i; ?>" data-h="<?php echo esc_attr($p['h']); ?>" data-wt="<?php echo esc_attr($p['weight']); ?>">
                <div class="shb-ph-brand"><?php echo esc_html($p['brand']); ?><?php echo $p['year'] ? ' · ' . esc_html(sb_bn_digits($p['year'])) : ''; ?></div>
                <h3><?php echo esc_html($p['model']); ?></h3>
                <dl>
                    <dt>মাপ (উ×প্র×পু)</dt><dd><?php echo $dim ? esc_html($dim) : '—'; ?></dd>
                    <?php if ($p['weight'] !== '') : ?><dt>ওজন</dt><dd><?php echo esc_html(sb_bn_digits($p['weight'])); ?> গ্রাম</dd><?php endif; ?>
                    <?php if ($p['screen'] !== '') : ?><dt>স্ক্রিন</dt><dd><?php echo esc_html(sb_bn_digits($p['screen'])); ?> ইঞ্চি</dd><?php endif; ?>
                    <?php if ($p['ram'] !== '' || $p['storage'] !== '') : ?><dt>RAM / স্টোরেজ</dt><dd><?php echo esc_html(trim($p['ram'] . ' / ' . $p['storage'], ' /')); ?></dd><?php endif; ?>
                    <?php if ($p['battery'] !== '') : ?><dt>ব্যাটারি</dt><dd><?php echo esc_html(sb_bn_digits($p['battery'])); ?> mAh</dd><?php endif; ?>
                    <?php if ($p['chipset'] !== '') : ?><dt>চিপসেট</dt><dd><?php echo esc_html($p['chipset']); ?></dd><?php endif; ?>
                </dl>
                <?php if ($p['url'] !== '') : ?><a class="shb-ph-src" href="<?php echo esc_url($p['url']); ?>" target="_blank" rel="noopener nofollow">সূত্র ↗</a><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <p class="shb-ph-none" hidden>কোনো মডেল পাওয়া যায়নি।</p>
    <div class="shb-ph-more"><button type="button" hidden>আরও দেখুন</button></div>
    <p class="shb-ph-note">স্পেসিফিকেশন বিভিন্ন সূত্র থেকে সংগৃহীত; কেনার আগে প্রস্তুতকারকের তথ্য মিলিয়ে নিন।</p>
</div>
    <?php
    return ob_get_clean();
});
