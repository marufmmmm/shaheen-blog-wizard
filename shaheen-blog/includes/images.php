<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI Blog Image Studio (phase 1): uniform reference library, one featured image per click (WebP),
 * SEO metadata, billing at a 20% margin (code-only). Prices come from data/models.json.
 */
const SB_IMG_MARGIN = 0.20;   // selling price = direct cost / (1 - 0.20); never shown on any screen

function sb_img_models() {
    static $m = null;
    if (null === $m) {
        $d = is_readable(SB_PATH . 'data/models.json') ? json_decode((string) file_get_contents(SB_PATH . 'data/models.json'), true) : array();
        $m = (is_array($d) && !empty($d['image_models']) && is_array($d['image_models'])) ? $d['image_models'] : array();
    }
    return $m;
}

/** Real USD cost of one image answer from the provider's own usage numbers. null = no usage reported. */
function sb_img_cost($model, $usage) {
    $m = sb_img_models();
    if (!isset($m[$model]) || !is_array($usage)) {
        return null;
    }
    $in  = (int) (isset($usage['input_tokens']) ? $usage['input_tokens'] : 0);
    $out = (int) (isset($usage['output_tokens']) ? $usage['output_tokens'] : 0);
    $det = isset($usage['input_tokens_details']) && is_array($usage['input_tokens_details']) ? $usage['input_tokens_details'] : array();
    $img = (int) (isset($det['image_tokens']) ? $det['image_tokens'] : 0);
    $txt = isset($det['text_tokens']) ? (int) $det['text_tokens'] : max(0, $in - $img);
    if ($in + $out < 1) {
        return null;
    }
    return ($txt * (float) $m[$model]['txt_in'] + $img * (float) $m[$model]['img_in'] + $out * (float) $m[$model]['img_out']) / 1000000;
}

/** What the client pays: cost / 0.80, rounded UP to $0.0001. */
function sb_img_price($real) {
    return ceil(($real / (1 - SB_IMG_MARGIN)) * 10000 - 1e-9) / 10000;
}

/* ---------- Uniform reference library ---------- */
function sb_uniform_cats() {
    return array('general' => 'সাধারণ / বাংলা মাধ্যম', 'english' => 'ইংলিশ ভার্সন', 'madrasha' => 'মাদ্রাসা / ইসলামিক', 'other' => 'অন্যান্য');
}

function sb_uniforms($only_active = false) {
    $u = get_option('sb_uniforms', array());
    $u = is_array($u) ? $u : array();
    if ($only_active) {
        $u = array_values(array_filter($u, function ($r) { return !empty($r['active']) && get_attached_file((int) $r['att']); }));
    }
    return $u;
}

/** Which uniforms to send: explicit id, 'none', or automatic from the article. Returns array(refs[], note). */
function sb_uniform_pick($explicit, $text) {
    $all = sb_uniforms(true);
    if ($explicit === 'none' || !$all) {
        return array(array(), $all ? '' : 'ইউনিফর্ম লাইব্রেরিতে কোনো ছবি নেই, তাই রেফারেন্স ছাড়াই বানানো হবে।');
    }
    if ($explicit !== '' && $explicit !== 'auto') {
        foreach ($all as $r) {
            if ((string) $r['id'] === (string) $explicit) {
                return array(array($r), '');
            }
        }
    }
    $cat = '';
    if (preg_match('/ইংলিশ\s*ভার্সন|ইংরেজি\s*ভার্সন|english\s*version/iu', $text)) {
        $cat = 'english';
    } elseif (preg_match('/মাদ্রাসা|মাদরাসা|মাদ্রাসাহ|ইসলামিক\s*স্কুল|madrasa|madrasha/iu', $text)) {
        $cat = 'madrasha';
    } elseif (preg_match('/স্কুল|শিক্ষার্থী|ছাত্র|ছাত্রী|ক্যাডেট|school|student/iu', $text)) {
        $cat = 'general';
    }
    $pool = array();
    if ($cat !== '') {
        $pool = array_values(array_filter($all, function ($r) use ($cat) { return $r['cat'] === $cat; }));
    }
    if (!$pool) {
        $pool = array_values(array_filter($all, function ($r) { return !empty($r['default']); }));
        if (!$pool) {
            return array(array(), 'এই লেখার জন্য কোন ইউনিফর্ম, তা নিশ্চিত নই। উপরে ইউনিফর্ম বেছে দিন, নইলে রেফারেন্স ছাড়াই বানানো হবে।');
        }
    }
    usort($pool, function ($a, $b) { return (int) !empty($b['default']) <=> (int) !empty($a['default']); });
    return array(array_slice($pool, 0, 3), '');
}

/* ---------- OpenAI calls ---------- */
function sb_img_multipart($fields, $files) {
    $b    = 'sbimg' . wp_generate_password(16, false);
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
    }
    foreach ($files as $f) {
        $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"image[]\"; filename=\"{$f['name']}\"\r\nContent-Type: {$f['mime']}\r\n\r\n" . $f['data'] . "\r\n";
    }
    $body .= "--{$b}--\r\n";
    return array('multipart/form-data; boundary=' . $b, $body);
}

/** One image. Returns array(bytes, usage, model) or WP_Error. */
function sb_img_call($model, $prompt, $size, $quality, $refs) {
    $key = trim((string) sb_get('api_key'));
    if ($key === '') {
        return new WP_Error('sb_nokey', 'OpenAI API কী দেওয়া নেই (Settings-এ দিন)।');
    }
    if (!isset(sb_img_models()[$model])) {
        return new WP_Error('sb_model', 'এই ছবির মডেল চালু নেই।');
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(190); // phpcs:ignore
    }
    $common = array('model' => $model, 'prompt' => $prompt, 'size' => $size, 'quality' => $quality, 'output_format' => 'webp', 'output_compression' => 85, 'n' => 1);
    if ($refs) {
        $files = array();
        foreach ($refs as $r) {
            $path = get_attached_file((int) $r['att']);
            if (!$path || !is_readable($path)) {
                continue;
            }
            $ft = wp_check_filetype($path);
            $files[] = array('name' => basename($path), 'mime' => $ft['type'] ? $ft['type'] : 'image/jpeg', 'data' => (string) file_get_contents($path)); // phpcs:ignore
        }
        list($ct, $body) = sb_img_multipart(array_map('strval', $common), array_slice($files, 0, 16));
        $url = 'https://api.openai.com/v1/images/edits';
    } else {
        $ct   = 'application/json';
        $body = wp_json_encode($common);
        $url  = 'https://api.openai.com/v1/images/generations';
    }
    $r = wp_remote_post($url, array('timeout' => 180, 'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => $ct), 'body' => $body));
    if (is_wp_error($r)) {
        return new WP_Error('sb_net', 'ছবির সার্ভারে পৌঁছানো যায়নি: ' . $r->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($r);
    $d    = json_decode((string) wp_remote_retrieve_body($r), true);
    if ($code !== 200 || !is_array($d) || empty($d['data'][0]['b64_json'])) {
        $msg = is_array($d) && !empty($d['error']['message']) ? $d['error']['message'] : ('HTTP ' . $code);
        return new WP_Error('sb_img_fail', 'ছবি তৈরি হয়নি, তাই কোনো বিল যোগ হয়নি। কারণ: ' . $msg);
    }
    $bytes = base64_decode($d['data'][0]['b64_json'], true);
    if ($bytes === false || !preg_match('/^(RIFF.{4}WEBP|\x89PNG|\xFF\xD8\xFF)/s', substr($bytes, 0, 12))) {
        return new WP_Error('sb_img_bad', 'ছবির ফাইল ঠিকমতো আসেনি, তাই কোনো বিল যোগ হয়নি।');
    }
    return array($bytes, isset($d['usage']) ? $d['usage'] : null, $model);
}

/** Save the bytes in the media library with its SEO fields. Returns attachment id or WP_Error. */
function sb_img_save($bytes, $meta, $post_id) {
    $slug = sanitize_title(isset($meta['slug']) ? $meta['slug'] : '');
    $slug = preg_match('/^[a-z0-9-]+$/', $slug) ? $slug : '';   // Bangla titles give unreadable file names, so use "image"
    $name = 'sb-' . ($slug !== '' ? mb_substr($slug, 0, 40) : 'image') . '-' . wp_date('YmdHis') . '.webp';
    $up   = wp_upload_bits($name, null, $bytes);
    if (!empty($up['error'])) {
        return new WP_Error('sb_save', 'ছবি সেভ করা যায়নি: ' . $up['error']);
    }
    $id = wp_insert_attachment(array(
        'post_mime_type' => 'image/webp',
        'post_title'     => isset($meta['title']) && $meta['title'] !== '' ? $meta['title'] : preg_replace('/\.webp$/', '', $name),
        'post_excerpt'   => isset($meta['caption']) ? $meta['caption'] : '',
        'post_content'   => isset($meta['details']) ? $meta['details'] : '',
        'post_status'    => 'inherit',
    ), $up['file'], (int) $post_id);
    if (is_wp_error($id) || !$id) {
        return new WP_Error('sb_save', 'ছবি মিডিয়া লাইব্রেরিতে যোগ করা যায়নি।');
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $md = wp_generate_attachment_metadata($id, $up['file']);
    if ($md) {
        wp_update_attachment_metadata($id, $md);
    }
    update_post_meta($id, '_wp_attachment_image_alt', isset($meta['alt']) ? $meta['alt'] : '');
    update_post_meta($id, '_sb_ai_image', 1);
    return $id;
}

/** Book the image: real cost is recorded, the client is billed cost / 0.80 (rounded up). */
function sb_img_charge($uid, $post_id, $real) {
    $bill = sb_img_price($real);
    $GLOBALS['sb_real_charge']   = $real;
    $GLOBALS['sb_bill_override'] = $bill;
    $r = SB_Credits::charge($uid, 'image', max(1, (int) ceil($bill / sb_unit_billed())), $post_id);
    unset($GLOBALS['sb_real_charge'], $GLOBALS['sb_bill_override']);
    return is_wp_error($r) ? $r : $bill;
}

/* ---------- AJAX ---------- */
function sb_img_context() {
    $title = sanitize_text_field(sb_req('title'));
    $text  = mb_substr(wp_strip_all_tags((string) sb_req('content')), 0, 3500);
    $cat   = sanitize_text_field(sb_req('category'));
    return array($title, $text, $cat);
}

add_action('wp_ajax_sb_img_prompt', function () {
    sb_ajax_guard();
    $key = trim((string) sb_get('api_key'));
    if ($key === '') {
        wp_send_json_error(array('message' => 'OpenAI API কী দেওয়া নেই।'));
    }
    list($title, $text, $cat) = sb_img_context();
    if ($title === '' && mb_strlen($text) < 40) {
        wp_send_json_error(array('message' => 'আগে শিরোনাম বা আর্টিকেল লিখুন।'));
    }
    list($refs, $note) = sb_uniform_pick(sanitize_text_field(sb_req('uniform', 'auto')), $title . ' ' . $cat . ' ' . $text);
    $udesc = '';
    foreach ($refs as $r) {
        $udesc .= ($r['name'] !== '' ? $r['name'] . ': ' : '') . $r['desc'] . ' ';
    }
    $size = sanitize_text_field(sb_req('size', '1536x1024'));
    $sys  = "You write image-generation prompts for blog images of Shaheen Education Family (a school network in Bangladesh). Reply with JSON only: {\"prompt\":\"...\",\"alt\":\"...\",\"title\":\"...\",\"caption\":\"...\",\"details\":\"...\"}.\n"
        . "prompt: English, 90-160 words, specific to THIS article. Cover: main visual message, who the audience is, setting and background, composition and camera angle, natural lighting and realism, aspect ratio, and things to avoid.\n"
        . "People must look like natural, realistic Bangladeshi people: South Asian features, authentic light-brown to deep-brown skin tones with natural texture, black or dark-brown hair, age-appropriate children, realistic Bangladeshi adults. No skin lightening, no beauty filter, no European or East Asian look. Use authentic Bangladeshi classrooms, homes, schools, libraries.\n"
        . ($refs ? "If students are shown, they wear the school uniform exactly as in the attached reference image(s): same colours, shirt/tunic, trousers/skirt, scarf/hijab/cap. Never invent a logo, badge, school name or a different uniform. " . ($udesc ? "Uniform notes: {$udesc}" : '') . "\n" : "If students are shown, use plain, simple school-style clothes with no logos or text.\n")
        . "No text, letters, watermarks or logos inside the image. Avoid stereotypes.\n"
        . "alt: Bangla, one natural sentence describing what is actually shown, no keyword stuffing. title: Bangla, short. caption: Bangla, one short line. details: Bangla, 1-2 sentences on what the image shows and why it fits the article.";
    $usr  = "Article title: {$title}\nCategory: {$cat}\nImage size: {$size}\nArticle text:\n{$text}";
    $r    = wp_remote_post('https://api.openai.com/v1/chat/completions', array('timeout' => 90, 'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
        'body' => wp_json_encode(array('model' => sb_model_for('meta'), 'response_format' => array('type' => 'json_object'), 'messages' => array(array('role' => 'system', 'content' => $sys), array('role' => 'user', 'content' => $usr))))));
    $d = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
    $j = is_array($d) && !empty($d['choices'][0]['message']['content']) ? json_decode((string) $d['choices'][0]['message']['content'], true) : null;
    if (!is_array($j) || empty($j['prompt'])) {
        sb_meter_take();
        wp_send_json_error(array('message' => 'প্রম্পট তৈরি হয়নি, তাই কোনো বিল যোগ হয়নি। আবার চেষ্টা করুন।'));
    }
    $real = sb_meter_take();
    $uid  = get_current_user_id();
    $GLOBALS['sb_real_charge'] = $real;
    SB_Credits::charge($uid, 'imgprompt', max(1, (int) ceil($real / SB_RAW_USD_PER_CREDIT)), (int) sb_req('post_id', 0));
    unset($GLOBALS['sb_real_charge']);
    wp_send_json_success(array(
        'prompt' => sanitize_textarea_field($j['prompt']), 'alt' => sanitize_text_field(isset($j['alt']) ? $j['alt'] : ''),
        'title' => sanitize_text_field(isset($j['title']) ? $j['title'] : ''), 'caption' => sanitize_text_field(isset($j['caption']) ? $j['caption'] : ''),
        'details' => sanitize_textarea_field(isset($j['details']) ? $j['details'] : ''), 'note' => $note, 'uniforms' => count($refs),
    ));
});

add_action('wp_ajax_sb_img_make', function () {
    sb_ajax_guard();
    $uid    = get_current_user_id();
    $job    = preg_replace('/[^a-z0-9]/i', '', (string) sb_req('job'));
    $prompt = trim(sanitize_textarea_field(sb_req('prompt')));
    if (mb_strlen($prompt) < 20) {
        wp_send_json_error(array('message' => 'আগে প্রম্পট লিখুন বা "প্রম্পট তৈরি করুন" চাপুন।'));
    }
    // The same click (same job id) never creates or bills a second image.
    if ($job !== '' && ($done = get_transient('sb_imgjob_' . $job))) {
        wp_send_json_success($done);
    }
    $lock = 'sb_imglock_' . $uid;
    if (get_transient($lock)) {
        wp_send_json_error(array('message' => 'আগের ছবিটা এখনো তৈরি হচ্ছে। একটু অপেক্ষা করুন।'));
    }
    set_transient($lock, 1, 200);
    $sizes   = array('1536x1024', '1024x1024', '1024x1536');
    $size    = in_array(sb_req('size'), $sizes, true) ? sb_req('size') : '1536x1024';
    $quality = in_array(sb_req('quality'), array('low', 'medium', 'high'), true) ? sb_req('quality') : 'medium';
    list($title, $text, $cat) = sb_img_context();
    list($refs, $note) = sb_uniform_pick(sanitize_text_field(sb_req('uniform', 'auto')), $title . ' ' . $cat . ' ' . $text . ' ' . $prompt);
    $model = sanitize_text_field(sb_req('model', 'auto'));
    if ($model === 'auto' || !isset(sb_img_models()[$model])) {
        $model = $refs ? 'gpt-image-2.5-sunburst' : 'gpt-image-2.5-flare';   // references need the most precise model
    }
    $res = sb_img_call($model, $prompt, $size, $quality, $refs);
    sb_meter_take();
    if (is_wp_error($res)) {
        delete_transient($lock);
        wp_send_json_error(array('message' => $res->get_error_message()));
    }
    list($bytes, $usage) = $res;
    $real = sb_img_cost($model, $usage);
    $est  = false;
    if ($real === null) {   // provider gave no usage: bill a conservative estimate and say so
        $real = ($quality === 'high' ? 0.17 : ($quality === 'low' ? 0.02 : 0.07));
        $est  = true;
    }
    $meta = array('alt' => sanitize_text_field(sb_req('alt')), 'title' => sanitize_text_field(sb_req('imgtitle')), 'caption' => sanitize_text_field(sb_req('caption')),
        'details' => sanitize_textarea_field(sb_req('details')), 'slug' => $title);
    $id = sb_img_save($bytes, $meta, (int) sb_req('post_id', 0));
    if (is_wp_error($id)) {
        delete_transient($lock);
        wp_send_json_error(array('message' => $id->get_error_message()));
    }
    $bill = sb_img_charge($uid, (int) sb_req('post_id', 0), $real);
    delete_transient($lock);
    $out = array('id' => $id, 'url' => wp_get_attachment_image_url($id, 'medium'), 'bill' => sb_fmt_usd(is_wp_error($bill) ? 0 : $bill),
        'model' => sb_img_models()[$model]['label'], 'uniforms' => count($refs), 'note' => $note, 'estimated' => $est, 'balance' => SB_Credits::state($uid));
    if ($job !== '') {
        set_transient('sb_imgjob_' . $job, $out, 900);
    }
    wp_send_json_success($out);
});

/** AI writes alt / title / caption / details by LOOKING at the chosen image (and the article). */
add_action('wp_ajax_sb_img_describe', function () {
    sb_ajax_guard();
    $key = trim((string) sb_get('api_key'));
    $id  = (int) sb_req('id');
    if ($key === '') {
        wp_send_json_error(array('message' => 'OpenAI API কী দেওয়া নেই।'));
    }
    if (!$id || get_post_type($id) !== 'attachment' || !current_user_can('edit_post', $id)) {
        wp_send_json_error(array('message' => 'আগে একটি ফিচার্ড ছবি বেছে নিন বা বানান।'));
    }
    $path = get_attached_file($id);
    if (!$path || !is_readable($path)) {
        wp_send_json_error(array('message' => 'ছবির ফাইল পাওয়া যায়নি।'));
    }
    $data = null;
    $mime = 'image/jpeg';
    $ed   = wp_get_image_editor($path);
    if (!is_wp_error($ed)) {   // a small copy is enough to see the picture, and cheaper
        $ed->resize(768, 768, false);
        $ed->set_quality(75);
        $tmp = wp_tempnam('sbimg') . '.jpg';
        $sv  = $ed->save($tmp, 'image/jpeg');
        if (!is_wp_error($sv) && is_readable($tmp)) {
            $data = file_get_contents($tmp); // phpcs:ignore
            @unlink($tmp); // phpcs:ignore
        }
    }
    if ($data === null) {
        $ft   = wp_check_filetype($path);
        $mime = $ft['type'] ? $ft['type'] : 'image/jpeg';
        $data = filesize($path) < 3000000 ? file_get_contents($path) : null; // phpcs:ignore
    }
    if (!$data) {
        wp_send_json_error(array('message' => 'ছবিটা পড়া যায়নি।'));
    }
    list($title, $text, $cat) = sb_img_context();
    $ip  = mb_substr(trim(sanitize_textarea_field(sb_req('prompt'))), 0, 1500);
    $sys = "You write SEO metadata in Bangla for a blog image of a school network in Bangladesh. Use three sources together: the picture itself (describe only what is really visible), the prompt the image was made from (what it was meant to show), and the article (so the text fits it). Reply with JSON only: {\"alt\":\"...\",\"title\":\"...\",\"caption\":\"...\",\"details\":\"...\"}. "
        . "alt: one natural sentence, what is shown, no keyword stuffing. title: short. caption: one short line. details: 1-2 sentences on what the image shows and why it fits the article. Do not invent names, places or school names that are not visible.";
    $usr = array(array('type' => 'text', 'text' => "Article title: {$title}\nCategory: {$cat}\nImage prompt (what the image was meant to show): " . ($ip !== '' ? $ip : '(none)') . "\nArticle text:\n{$text}"),
        array('type' => 'image_url', 'image_url' => array('url' => 'data:' . $mime . ';base64,' . base64_encode($data))));
    $r = wp_remote_post('https://api.openai.com/v1/chat/completions', array('timeout' => 90, 'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
        'body' => wp_json_encode(array('model' => sb_model_for('meta'), 'response_format' => array('type' => 'json_object'), 'messages' => array(array('role' => 'system', 'content' => $sys), array('role' => 'user', 'content' => $usr))))));
    $d = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
    $j = is_array($d) && !empty($d['choices'][0]['message']['content']) ? json_decode((string) $d['choices'][0]['message']['content'], true) : null;
    if (!is_array($j) || empty($j['alt'])) {
        sb_meter_take();
        wp_send_json_error(array('message' => 'AI লিখতে পারেনি, তাই কোনো বিল যোগ হয়নি। আবার চেষ্টা করুন।'));
    }
    $real = sb_meter_take();
    $GLOBALS['sb_real_charge'] = $real;
    SB_Credits::charge(get_current_user_id(), 'imgmeta', max(1, (int) ceil($real / SB_RAW_USD_PER_CREDIT)), 0);
    unset($GLOBALS['sb_real_charge']);
    wp_send_json_success(array('alt' => sanitize_text_field($j['alt']), 'title' => sanitize_text_field(isset($j['title']) ? $j['title'] : ''),
        'caption' => sanitize_text_field(isset($j['caption']) ? $j['caption'] : ''), 'details' => sanitize_textarea_field(isset($j['details']) ? $j['details'] : '')));
});

/** SEO fields of the chosen featured image (works for images picked by hand too). */
add_action('wp_ajax_sb_img_meta', function () {
    sb_ajax_guard();
    $id = (int) sb_req('id');
    if (!$id || get_post_type($id) !== 'attachment' || !current_user_can('edit_post', $id)) {
        wp_send_json_error(array('message' => 'ছবি পাওয়া যায়নি।'));
    }
    wp_update_post(array('ID' => $id, 'post_title' => sanitize_text_field(sb_req('imgtitle')), 'post_excerpt' => sanitize_text_field(sb_req('caption')), 'post_content' => sanitize_textarea_field(sb_req('details'))));
    update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field(sb_req('alt')));
    wp_send_json_success(array('ok' => 1));
});

/* ---------- Uniform library page ---------- */
add_action('admin_menu', function () {
    add_submenu_page('sb-dashboard', 'Uniform Library', '🎽 Uniform Library', 'manage_options', 'sb-uniforms', 'sb_page_uniforms');
}, 31);

add_action('admin_post_sb_uniform_save', function () {
    if (!current_user_can('manage_options')) {
        wp_die('No.');
    }
    check_admin_referer('sb_uniform_save');
    $cats = sb_uniform_cats();
    $rows = isset($_POST['u']) && is_array($_POST['u']) ? wp_unslash($_POST['u']) : array(); // phpcs:ignore WordPress.Security.NonceVerification
    $def  = isset($_POST['u_default']) ? (int) $_POST['u_default'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
    $out  = array();
    foreach ($rows as $r) {
        $att = (int) (isset($r['att']) ? $r['att'] : 0);
        if (!$att || !empty($r['remove'])) {
            continue;
        }
        $out[] = array('id' => $att, 'att' => $att, 'cat' => isset($cats[$r['cat']]) ? $r['cat'] : 'other', 'name' => sanitize_text_field(isset($r['name']) ? $r['name'] : ''),
            'desc' => sanitize_textarea_field(isset($r['desc']) ? $r['desc'] : ''), 'active' => !empty($r['active']) ? 1 : 0, 'default' => ($def === $att) ? 1 : 0);
    }
    update_option('sb_uniforms', $out, false);
    wp_safe_redirect(admin_url('admin.php?page=sb-uniforms&saved=1'));
    exit;
});

function sb_page_uniforms() {
    if (!current_user_can('manage_options')) {
        wp_die('No.');
    }
    $cats = sb_uniform_cats();
    $rows = sb_uniforms();
    echo '<div class="wrap sba"><h1>🎽 Uniform Library</h1>';
    if (isset($_GET['saved'])) { // phpcs:ignore WordPress.Security.NonceVerification
        echo '<div class="notice notice-success"><p>সেভ হয়েছে ✓</p></div>';
    }
    echo '<p class="sba-muted">এখানে স্কুলের ইউনিফর্মের নমুনা ছবি রাখুন (৩–৫টি দিয়ে শুরু করুন)। AI ছবি বানানোর সময় লেখা অনুযায়ী সঠিক ইউনিফর্মের ছবি মডেলকে দেখানো হয়। কোনো লোগো বা স্কুলের নাম AI নিজে বানাবে না।</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sb_uniform_save', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sb_uniform_save">';
    echo '<div class="sba-card"><table class="widefat striped" id="sbu-table"><thead><tr><th>ছবি</th><th>ধরন</th><th>নাম</th><th>বর্ণনা (ঐচ্ছিক)</th><th>চালু</th><th>ডিফল্ট</th><th>মুছুন</th></tr></thead><tbody>';
    foreach ($rows as $i => $r) {
        sb_uniform_row($i, $r, $cats);
    }
    echo '</tbody></table><p><button type="button" class="button" id="sbu-add">➕ ছবি যোগ করুন</button> <button class="button button-primary">💾 সেভ করুন</button></p></div></form>';
    ?>
    <script>
    (function () {
        var cats = <?php echo wp_json_encode($cats); ?>, n = <?php echo (int) count($rows) + 100; ?>;
        document.getElementById('sbu-add').addEventListener('click', function () {
            if (!window.wp || !wp.media) { return; }
            var f = wp.media({ title: 'ইউনিফর্মের ছবি', library: { type: 'image' }, multiple: true });
            f.on('select', function () {
                f.state().get('selection').each(function (m) {
                    var a = m.toJSON(), i = n++, tb = document.querySelector('#sbu-table tbody'), tr = document.createElement('tr'), o = '';
                    Object.keys(cats).forEach(function (k) { o += '<option value="' + k + '">' + cats[k] + '</option>'; });
                    var u = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
                    tr.innerHTML = '<td><img src="' + u + '" style="width:60px;height:60px;object-fit:cover"><input type="hidden" name="u[' + i + '][att]" value="' + a.id + '"></td>'
                        + '<td><select name="u[' + i + '][cat]">' + o + '</select></td><td><input type="text" name="u[' + i + '][name]"></td><td><textarea name="u[' + i + '][desc]" rows="2"></textarea></td>'
                        + '<td><input type="checkbox" name="u[' + i + '][active]" value="1" checked></td><td><input type="radio" name="u_default" value="' + a.id + '"></td><td><input type="checkbox" name="u[' + i + '][remove]" value="1"></td>';
                    tb.appendChild(tr);
                });
            });
            f.open();
        });
    })();
    </script>
    <?php
    echo '</div>';
}

function sb_uniform_row($i, $r, $cats) {
    $o = '';
    foreach ($cats as $k => $l) {
        $o .= '<option value="' . esc_attr($k) . '"' . selected($r['cat'], $k, false) . '>' . esc_html($l) . '</option>';
    }
    echo '<tr><td>' . wp_get_attachment_image((int) $r['att'], array(60, 60)) . '<input type="hidden" name="u[' . (int) $i . '][att]" value="' . (int) $r['att'] . '"></td>'
        . '<td><select name="u[' . (int) $i . '][cat]">' . $o . '</select></td>'
        . '<td><input type="text" name="u[' . (int) $i . '][name]" value="' . esc_attr($r['name']) . '"></td>'
        . '<td><textarea name="u[' . (int) $i . '][desc]" rows="2">' . esc_textarea($r['desc']) . '</textarea></td>'
        . '<td><input type="checkbox" name="u[' . (int) $i . '][active]" value="1"' . checked(!empty($r['active']), true, false) . '></td>'
        . '<td><input type="radio" name="u_default" value="' . (int) $r['att'] . '"' . checked(!empty($r['default']), true, false) . '></td>'
        . '<td><input type="checkbox" name="u[' . (int) $i . '][remove]" value="1"></td></tr>';
}
