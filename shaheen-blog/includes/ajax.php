<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Every AJAX call: nonce + capability. */
function sb_ajax_guard($cap = 'edit_posts') {
    check_ajax_referer('sb_admin', 'nonce');
    if (!current_user_can($cap)) {
        wp_send_json_error(array('message' => 'এই কাজের অনুমতি আপনার নেই।'), 403);
    }
}

function sb_req($key, $default = '') {
    return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default;
}

function sb_req_list($key, $max = 10) {
    $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : array();
    if (!is_array($raw)) {
        return array();
    }
    $out = array();
    foreach ($raw as $v) {
        $v = sanitize_text_field(is_scalar($v) ? $v : '');
        if ($v !== '') {
            $out[] = $v;
        }
    }
    return array_slice(array_values(array_unique($out)), 0, $max);
}

/** Text of the web pages the owner listed (max 4), for the prompt-article feature. */
function sb_fetch_pages($raw, &$notes) {
    $out = '';
    $n = 0;
    foreach (preg_split('/\R+/', trim((string) $raw)) as $u) {
        $u = esc_url_raw(trim($u), array('http', 'https'));
        if ($u === '' || $n >= 4) {
            continue;
        }
        $n++;
        $r = wp_safe_remote_get($u, array('timeout' => 12, 'redirection' => 3, 'user-agent' => 'Mozilla/5.0 (ShaheenBlog)'));
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
            $notes[] = 'ওয়েবসাইট পড়া যায়নি: ' . $u;
            continue;
        }
        $h = wp_remote_retrieve_body($r);
        $h = preg_replace('#<(script|style|noscript|svg|nav|footer|header)\b[^>]*>.*?</\1>#is', ' ', $h);
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($h), ENT_QUOTES, 'UTF-8')));
        if (mb_strlen($t) < 80) {
            $notes[] = 'পেজে পড়ার মতো লেখা পাওয়া যায়নি (জাভাস্ক্রিপ্টে লোড হওয়া পেজ হতে পারে): ' . $u;
            continue;
        }
        $out .= "\n[ওয়েবসাইট: {$u}]\n" . mb_substr($t, 0, 9000) . "\n";
        $notes[] = '✓ ওয়েবসাইট পড়া হয়েছে (' . sb_bn_digits(mb_strlen($t)) . ' অক্ষর): ' . $u;
    }
    return $out;
}

/** Uploaded PDFs / images as OpenAI message parts. */
function sb_upload_parts(&$notes) {
    $parts = array();
    if (empty($_FILES['files']) || !is_array($_FILES['files']['name'])) { // phpcs:ignore WordPress.Security.NonceVerification
        return $parts;
    }
    $f = $_FILES['files']; // phpcs:ignore WordPress.Security.NonceVerification
    $ok = array('pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
    foreach (array_slice(array_keys($f['name']), 0, 4) as $i) {
        $name = sanitize_file_name($f['name'][$i]);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ((int) $f['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'][$i])) {
            $notes[] = 'ফাইল আপলোড হয়নি (সম্ভবত বড়): ' . $name;
            continue;
        }
        if (!isset($ok[$ext]) || (int) $f['size'][$i] > 10 * 1024 * 1024) {
            $notes[] = 'ফাইল বাদ (শুধু PDF/JPG/PNG/WEBP, ১০ MB পর্যন্ত): ' . $name;
            continue;
        }
        $data = base64_encode((string) file_get_contents($f['tmp_name'][$i])); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $uri = 'data:' . $ok[$ext] . ';base64,' . $data;
        $parts[] = $ext === 'pdf'
            ? array('type' => 'file', 'file' => array('filename' => $name, 'file_data' => $uri))
            : array('type' => 'image_url', 'image_url' => array('url' => $uri));
    }
    return $parts;
}

/* ---------- AI generation (costs credits) ---------- */
add_action('wp_ajax_sb_ai', 'sb_ajax_ai');
function sb_ajax_ai() {
    sb_ajax_guard();
    $feature = sanitize_key(sb_req('feature'));
    $cost = sb_feature_cost($feature);
    if ($feature === 'edit' && (int) sb_req('ins_count', 1) > 5) {
        $cost = sb_feature_cost('edit_many');
    }
    $tier = max(1, min(3, (int) sb_req('tier', 1)));
    $cmodel = '';
    if ($feature === 'custom') {
        $cmodel = sanitize_text_field(sb_req('model'));
        if (!in_array($cmodel, sb_allowed_models(), true)) {
            $cmodel = trim((string) sb_get('model_article')) ?: sb_get('model');
        }
        $cost = sb_custom_cost($tier, $cmodel);
    }
    if (!$cost) {
        wp_send_json_error(array('message' => 'অজানা AI ফিচার।'));
    }
    $uid = get_current_user_id();
    $state = SB_Credits::state($uid);
    $research = (in_array($feature, array('article', 'custom'), true) && sb_req('research') === '1');
    $need = $cost + ($research ? max(0, (int) sb_get('cost_research')) : 0);
    if ($state['remaining'] < $need) {
        wp_send_json_error(array(
            'message' => 'ক্রেডিট যথেষ্ট নয়। প্রয়োজন ' . sb_bn_digits($need) . ', আছে ' . sb_bn_digits($state['remaining']) . '। রিসেট: ' . $state['next_reset_bn'],
            'balance' => $state,
        ));
    }

    $ctx = array(
        'title'    => sanitize_text_field(sb_req('title')),
        'topic'    => sanitize_text_field(sb_req('topic')),
        'excerpt'  => sanitize_textarea_field(sb_req('excerpt')),
        'content'  => wp_kses_post(sb_req('content')),
        'keywords' => sb_req_list('keywords', 10),
        'length'   => sanitize_key(sb_req('length', 'medium')),
        'research' => $research,
        'source'   => sanitize_textarea_field(sb_req('source')),
        'instruction' => sanitize_textarea_field(sb_req('instruction')),
        'faq_count' => (int) sb_req('faq_count', 5),
        'post_id'  => (int) sb_req('post_id', 0),
        'tier'     => $tier,
        'model'    => $cmodel,
    );
    if ($feature === 'custom') {
        sb_job_start($uid, $ctx, $cost, $research, $state);   // exits
    }
    $notes = array();
    $out = sb_ai_execute($uid, $feature, $ctx, $cost, $research, $notes);
    if (empty($out['ok'])) {
        wp_send_json_error(array('message' => $out['message'], 'balance' => $state));
    }
    wp_send_json_success($out['data']);
}

/** Runs one AI feature, post-processes and charges credits. Returns array(ok, message|data). */
function sb_ai_execute($uid, $feature, $ctx, $cost, $research, &$notes) {
    $result = SB_AI::generate($feature, $ctx);
    if (is_wp_error($result)) {
        return array('ok' => false, 'message' => $result->get_error_message());   // nothing charged
    }
    return sb_ai_finish($uid, $feature, $ctx, $cost, $research, $result, $notes);
}

/** Markdown leftovers and AI web-search citation clutter -> clean HTML. */
function sb_md_to_html($t) {
    $t = preg_replace('/\s*\(\s*\[[^\]]+\]\(https?:[^)]*utm_source=openai[^)]*\)\s*\)/u', '', $t);       // ([site](url?utm_source=openai))
    $t = preg_replace('/\[[^\]\s]+\.[^\]\s]+\]\(https?:[^)]*utm_source=openai[^)]*\)/u', '', $t);          // bare [domain.tld](url?utm...)
    $t = preg_replace('/[?&]utm_source=openai/', '', $t);
    $t = preg_replace('/\[([^\]]+)\]\((https?:[^)\s]+)\)/u', '<a href="$2" target="_blank" rel="noopener">$1</a>', $t);
    $t = preg_replace('/\*\*(.+?)\*\*/us', '<strong>$1</strong>', $t);
    if (!preg_match('/<(p|h[1-6]|ul|ol|table)\b/i', $t)) {
        $out = array();
        $list = array();
        $flush = function () use (&$list, &$out) {
            if ($list) {
                $out[] = '<ul><li>' . implode('</li><li>', $list) . '</li></ul>';
                $list = array();
            }
        };
        foreach (preg_split('/\R/u', $t) as $line) {
            $line = trim($line);
            if ($line === '') {
                $flush();
            } elseif (preg_match('/^(#{1,6})\s*(.+)$/u', $line, $m)) {
                $flush();
                $out[] = strlen($m[1]) <= 2 ? '<h2>' . $m[2] . '</h2>' : '<h3>' . $m[2] . '</h3>';
            } elseif (preg_match('/^[-*•]\s+(.+)$/u', $line, $m)) {
                $list[] = $m[1];
            } else {
                $flush();
                $out[] = '<p>' . $line . '</p>';
            }
        }
        $flush();
        $t = implode("\n", $out);
    }
    return $t;
}

/** Custom prompt-article: read the JSON the AI was asked for, clean the body, standardise placeholders. */
function sb_custom_result($r) {
    $raw = trim((string) $r['text']);
    $j = json_decode(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $raw), true);
    if (!is_array($j) && preg_match('/\{.*\}/us', $raw, $m)) {
        $j = json_decode($m[0], true);
    }
    if (is_array($j) && !empty($j['article'])) {
        $r['text'] = (string) $j['article'];
        $r['meta'] = isset($j['meta']) ? sanitize_text_field($j['meta']) : '';
        $r['excerpt'] = isset($j['excerpt']) ? sanitize_textarea_field($j['excerpt']) : '';
    }
    $t = sb_md_to_html($r['text']);
    $t = preg_replace('/\[[^\]\[]*(এখানে যুক্ত করুন|এখানে যোগ করুন)[^\]\[]*\]/u', '[তথ্য দরকার: সরকারি/মূল তথ্য]', $t);
    $r['text'] = wp_kses_post($t);
    return $r;
}

/** Post-processes a finished result and charges the credits. */
function sb_ai_finish($uid, $feature, $ctx, $cost, $research, $result, &$notes) {
    $post_id = (int) $ctx['post_id'];
    if ($feature === 'custom') {
        $result = sb_custom_result($result);
    }
    if ($feature === 'related' && empty($result['items'])) {
        return array('ok' => false, 'message' => 'সাইটে এই লেখার সাথে মেলে এমন পোস্ট/পেজ পাওয়া যায়নি, তাই ক্রেডিট কাটা হয়নি। নিজে খুঁজে যোগ করতে পারেন।');
    }
    if ($research && !empty($result['researched'])) {
        $cost += max(0, (int) sb_get('cost_research'));   // web search really ran
    }
    if (in_array($feature, array('article', 'custom', 'rewrite', 'edit', 'edit_new'), true)) {
        preg_match_all('/\[তথ্য দরকার:[^\]]*\]/u', $result['text'], $m);
        $result['missing'] = array_values(array_unique($m[0]));
    }
    if ($feature === 'rewrite_post') {
        $have = sb_numbers($result['headline'] . ' ' . $result['excerpt'] . ' ' . wp_strip_all_tags($result['text']));
        $result['facts_missing'] = array_values(array_map('sb_bn_digits', array_diff(sb_numbers($ctx['source'] . ' ' . $ctx['title']), $have)));
    }
    $new = SB_Credits::charge($uid, $feature, $cost, $post_id);
    if (is_wp_error($new)) {
        return array('ok' => false, 'message' => $new->get_error_message());
    }
    $result['notes'] = $notes;
    return array('ok' => true, 'data' => array('result' => $result, 'cost' => $cost, 'balance' => $new));
}

/* ---------- Long prompt-articles run as a background job (a web server cuts a request that stays open too long: HTTP 504) ---------- */
function sb_job_dir() {
    $u = wp_upload_dir();
    $d = trailingslashit($u['basedir']) . 'sb-tmp';
    if (!is_dir($d)) {
        wp_mkdir_p($d);
        @file_put_contents($d . '/index.php', "<?php // silence\n"); // phpcs:ignore
        @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n"); // phpcs:ignore
    }
    return $d;
}

/** PDFs the owner wants on the post (download button + reader): saved into the Media Library. */
function sb_keep_pdfs() {
    $out = array();
    if (empty($_FILES['keep_pdf']) || !is_array($_FILES['keep_pdf']['name']) || !current_user_can('upload_files')) { // phpcs:ignore WordPress.Security.NonceVerification
        return $out;
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $k = $_FILES['keep_pdf']; // phpcs:ignore WordPress.Security.NonceVerification
    foreach (array_slice(array_keys($k['name']), 0, 3) as $i) {
        if ((int) $k['error'][$i] !== UPLOAD_ERR_OK || strtolower(pathinfo($k['name'][$i], PATHINFO_EXTENSION)) !== 'pdf' || (int) $k['size'][$i] > 25 * 1024 * 1024) {
            continue;
        }
        $file = array('name' => sanitize_file_name($k['name'][$i]), 'type' => 'application/pdf', 'tmp_name' => $k['tmp_name'][$i], 'error' => 0, 'size' => $k['size'][$i]);
        $aid = media_handle_sideload($file, 0, preg_replace('/\.pdf$/i', '', $file['name']));
        if (!is_wp_error($aid)) {
            $out[] = array('name' => preg_replace('/\.pdf$/i', '', $file['name']), 'url' => wp_get_attachment_url($aid));
        }
    }
    return $out;
}

function sb_job_start($uid, $ctx, $cost, $research, $state) {
    if (mb_strlen(trim($ctx['instruction'])) < 30) {
        wp_send_json_error(array('message' => 'প্রম্পট বক্সে বিস্তারিত নির্দেশ লিখুন (কমপক্ষে ৩০ অক্ষর)।', 'balance' => $state));
    }
    $id = strtolower(wp_generate_password(16, false, false));
    $files = array();
    if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) { // phpcs:ignore WordPress.Security.NonceVerification
        $f = $_FILES['files']; // phpcs:ignore WordPress.Security.NonceVerification
        $ok = array('pdf', 'jpg', 'jpeg', 'png', 'webp');
        foreach (array_slice(array_keys($f['name']), 0, 14) as $i) {
            $name = sanitize_file_name($f['name'][$i]);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ((int) $f['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'][$i])) {
                $files[] = array('name' => $name, 'bad' => 'ফাইল আপলোড হয়নি (সম্ভবত বড়)');
                continue;
            }
            if (!in_array($ext, $ok, true) || (int) $f['size'][$i] > 10 * 1024 * 1024) {
                $files[] = array('name' => $name, 'bad' => 'ফাইল বাদ (শুধু PDF/JPG/PNG/WEBP, ১০ MB পর্যন্ত)');
                continue;
            }
            $path = sb_job_dir() . '/' . $id . '-' . $i . '.' . $ext;
            if (move_uploaded_file($f['tmp_name'][$i], $path)) {
                $files[] = array('name' => $name, 'path' => $path, 'ext' => $ext);
            }
        }
    }
    $job = array(
        'status' => 'queued', 'uid' => $uid, 'ctx' => $ctx, 'cost' => $cost, 'research' => $research ? 1 : 0,
        'urls' => (string) sb_req('urls'), 'files' => $files, 'created' => time(), 'beat' => time(),
    );
    set_transient('sb_job_' . $id, $job, 6 * HOUR_IN_SECONDS);
    wp_send_json_success(array('job' => $id, 'pdfs' => sb_keep_pdfs()));
}

/** Prepares the sources (web pages, files) and starts OpenAI's background generation. Short request. */
add_action('wp_ajax_sb_job_run', function () {
    sb_ajax_guard();
    $id = preg_replace('/[^a-z0-9]/', '', strtolower((string) sb_req('job')));
    $job = get_transient('sb_job_' . $id);
    if (!is_array($job) || (int) $job['uid'] !== get_current_user_id() || $job['status'] !== 'queued') {
        wp_send_json_error(array('message' => 'কাজটি পাওয়া যায়নি বা আগেই শুরু হয়েছে।'));
    }
    $job['status'] = 'starting';
    set_transient('sb_job_' . $id, $job, 6 * HOUR_IN_SECONDS);

    $notes = array();
    $ctx = $job['ctx'];
    $ctx['web'] = sb_fetch_pages($job['urls'], $notes);
    $ctx['parts'] = array();
    $okx = array('pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
    foreach ($job['files'] as $f) {
        if (!empty($f['bad'])) {
            $notes[] = $f['bad'] . ': ' . $f['name'];
            continue;
        }
        $uri = 'data:' . $okx[$f['ext']] . ';base64,' . base64_encode((string) file_get_contents($f['path'])); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $notes[] = '✓ ফাইল AI-কে পাঠানো হয়েছে: ' . $f['name'];
        $ctx['parts'][] = $f['ext'] === 'pdf'
            ? array('type' => 'file', 'file' => array('filename' => $f['name'], 'file_data' => $uri))
            : array('type' => 'image_url', 'image_url' => array('url' => $uri));
    }
    $ctx['bg'] = true;
    $r = SB_AI::generate('custom', $ctx);
    foreach ($job['files'] as $f) {
        if (!empty($f['path'])) {
            @unlink($f['path']); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
    }
    unset($ctx['parts']);
    if (is_wp_error($r)) {
        delete_transient('sb_job_' . $id);
        wp_send_json_error(array('message' => $r->get_error_message()));
    }
    $job['status'] = 'waiting';
    $job['bg_id'] = $r['bg_id'];
    $job['notes'] = $notes;
    $job['files'] = array();
    $job['beat'] = time();
    set_transient('sb_job_' . $id, $job, 6 * HOUR_IN_SECONDS);
    wp_send_json_success(array('state' => 'waiting'));
});

/** Polled by the browser: asks OpenAI how the background generation is doing; charges credits once when it is done. */
add_action('wp_ajax_sb_job_status', function () {
    sb_ajax_guard();
    $id = preg_replace('/[^a-z0-9]/', '', strtolower((string) sb_req('job')));
    $job = get_transient('sb_job_' . $id);
    $uid = get_current_user_id();
    if (!is_array($job) || (int) $job['uid'] !== $uid) {
        wp_send_json_error(array('message' => 'কাজটি পাওয়া যায়নি (সময় পেরিয়ে গেছে)।'));
    }
    if ($job['status'] !== 'waiting') {
        wp_send_json_success(array('state' => 'running'));   // starting / finalizing
    }
    $r = SB_AI::bg_fetch($job['bg_id']);
    if ($r['state'] === 'running') {
        if (time() - (int) $job['created'] > 20 * 60) {
            SB_AI::bg_cancel($job['bg_id']);
            delete_transient('sb_job_' . $id);
            wp_send_json_success(array('state' => 'error', 'message' => 'AI ২০ মিনিটেও শেষ করতে পারেনি, বন্ধ করা হয়েছে। ক্রেডিট কাটা হয়নি। কম ফাইল বা ছোট দৈর্ঘ্যে আবার চেষ্টা করুন।', 'balance' => SB_Credits::state($uid)));
        }
        wp_send_json_success(array('state' => 'running'));
    }
    $job['status'] = 'finalizing';
    set_transient('sb_job_' . $id, $job, 6 * HOUR_IN_SECONDS);
    delete_transient('sb_job_' . $id);
    if ($r['state'] === 'error') {
        wp_send_json_success(array('state' => 'error', 'message' => $r['message'], 'balance' => SB_Credits::state($uid)));
    }
    $notes = isset($job['notes']) ? $job['notes'] : array();
    $out = sb_ai_finish($uid, 'custom', $job['ctx'], (int) $job['cost'], (bool) $job['research'], $r['result'], $notes);
    if (empty($out['ok'])) {
        wp_send_json_success(array('state' => 'error', 'message' => $out['message'], 'balance' => SB_Credits::state($uid)));
    }
    wp_send_json_success(array('state' => 'done', 'data' => $out['data']));
});

/* ---------- Save post (free: editing never costs credits) ---------- */
add_action('wp_ajax_sb_save_post', 'sb_ajax_save_post');
function sb_ajax_save_post() {
    sb_ajax_guard();
    $id = (int) sb_req('id', 0);
    if ($id) {
        if (get_post_type($id) !== 'sb_post' || !current_user_can('edit_post', $id)) {
            wp_send_json_error(array('message' => 'এই পোস্ট এডিট করার অনুমতি নেই।'), 403);
        }
    }
    $title = sanitize_text_field(sb_req('title'));
    if ($title === '') {
        wp_send_json_error(array('message' => 'শিরোনাম লিখুন।'));
    }

    $status = sb_req('status', 'draft');
    if (!in_array($status, array('publish', 'draft'), true)) {
        $status = 'draft';
    }
    if ($status === 'publish' && !current_user_can('publish_posts')) {
        $status = 'pending';
    }

    $postarr = array(
        'post_type'    => 'sb_post',
        'post_title'   => $title,
        'post_excerpt' => sanitize_textarea_field(sb_req('excerpt')),
        'post_content' => wp_kses_post(sb_req('content')),
        'post_status'  => $status,
    );
    $date = sanitize_text_field(sb_req('date'));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $postarr['post_date']     = $date . ' ' . wp_date('H:i:s');
        $postarr['post_date_gmt'] = get_gmt_from_date($postarr['post_date']);
    }
    if ($id) {
        $postarr['ID'] = $id;
        $res = wp_update_post(wp_slash($postarr), true);
    } else {
        $postarr['post_author'] = get_current_user_id();
        $res = wp_insert_post(wp_slash($postarr), true);
    }
    if (is_wp_error($res)) {
        wp_send_json_error(array('message' => $res->get_error_message()));
    }
    $is_new = empty($postarr['ID']);
    $id = (int) $res;

    // Short, shareable link: custom slug if given, else post-{id} for new posts.
    $slug = sanitize_title(sb_req('slug'));
    if ($slug !== '') {
        wp_update_post(array('ID' => $id, 'post_name' => $slug));
    } elseif ($is_new) {
        wp_update_post(array('ID' => $id, 'post_name' => 'post-' . $id));
    }

    update_post_meta($id, '_sb_subtitle', sanitize_text_field(sb_req('subtitle')));
    update_post_meta($id, '_sb_meta_desc', sanitize_textarea_field(sb_req('meta_desc')));
    update_post_meta($id, '_sb_summary', sanitize_textarea_field(sb_req('summary')));
    update_post_meta($id, '_sb_inc_summary', sb_req('inc_summary') === '1' ? '1' : '0');
    update_post_meta($id, '_sb_inc_faq', sb_req('inc_faq') === '1' ? '1' : '0');
    update_post_meta($id, '_sb_author_name', sanitize_text_field(sb_req('author_name')));
    update_post_meta($id, '_sb_keywords', sb_req_list('keywords', 10));
    update_post_meta($id, '_sb_topic', sanitize_text_field(sb_req('topic')));

    $faq = array();
    $faq_raw = isset($_POST['faq']) ? wp_unslash($_POST['faq']) : array();
    if (is_array($faq_raw)) {
        foreach (array_slice($faq_raw, 0, 12) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $q = sanitize_text_field(isset($row['q']) ? $row['q'] : '');
            $a = sanitize_textarea_field(isset($row['a']) ? $row['a'] : '');
            if ($q !== '' && $a !== '') {
                $faq[] = array('q' => $q, 'a' => $a);
            }
        }
    }
    update_post_meta($id, '_sb_faq', $faq);

    $rel = array();
    $rel_raw = isset($_POST['related']) ? wp_unslash($_POST['related']) : array();
    if (is_array($rel_raw)) {
        foreach (array_slice($rel_raw, 0, 12) as $rid) {
            $rid = (int) $rid;
            if ($rid && $rid !== $id && in_array(get_post_type($rid), array('sb_post', 'post', 'page'), true)) {
                $rel[] = $rid;
            }
        }
    }
    update_post_meta($id, '_sb_related', array_values(array_unique($rel)));
    $why_in = json_decode((string) sb_req('related_why', '{}'), true);
    $why = array();
    if (is_array($why_in)) {
        foreach ($rel as $rid) {
            if (!empty($why_in[$rid])) {
                $why[$rid] = sanitize_text_field($why_in[$rid]);
            }
        }
    }
    update_post_meta($id, '_sb_related_why', $why);
    update_post_meta($id, '_sb_blocks', sb_clean_blocks(isset($_POST['blocks']) ? wp_unslash($_POST['blocks']) : array()));
    update_post_meta($id, '_sb_block_ids', array_values(array_filter(sb_req_list('block_ids', 20), function ($x) { return preg_match('/^[a-z0-9]{6,20}$/', $x); })));
    $ex_in = json_decode((string) sb_req('extras', '{}'), true);
    update_post_meta($id, '_sb_extras', sb_clean_extras(is_array($ex_in) ? $ex_in : array()));
    update_post_meta($id, '_sb_no_social', sb_req('no_social') === '1' ? 1 : 0);
    update_post_meta($id, '_sb_no_global', sb_req('no_global') === '1' ? 1 : 0);
    sb_init_views($id);

    $term = (int) sb_req('category', 0);
    if ($term && term_exists($term, 'sb_category')) {
        wp_set_object_terms($id, array($term), 'sb_category');
    } else {
        wp_set_object_terms($id, array(), 'sb_category');
    }

    $img = (int) sb_req('image_id', 0);
    if ($img && get_post_type($img) === 'attachment' && current_user_can('edit_post', $img)) {
        set_post_thumbnail($id, $img);
    } elseif (!$img) {
        delete_post_thumbnail($id);
    }

    wp_send_json_success(array(
        'id'     => $id,
        'status' => get_post_status($id),
        'url'    => get_permalink($id),
        'edit'   => admin_url('admin.php?page=sb-new&post=' . $id),
    ));
}

/* ---------- Move to trash ---------- */
add_action('wp_ajax_sb_trash_post', 'sb_ajax_trash_post');
function sb_ajax_trash_post() {
    sb_ajax_guard();
    $id = (int) sb_req('id', 0);
    if (!$id || get_post_type($id) !== 'sb_post' || !current_user_can('delete_post', $id)) {
        wp_send_json_error(array('message' => 'এই পোস্ট মুছার অনুমতি নেই।'), 403);
    }
    wp_trash_post($id);
    wp_send_json_success();
}

/* ---------- Add category ---------- */
add_action('wp_ajax_sb_add_category', 'sb_ajax_add_category');
function sb_ajax_add_category() {
    sb_ajax_guard('manage_categories');
    $bn = sanitize_text_field(sb_req('name_bn'));
    $en = sanitize_text_field(sb_req('name_en'));
    if ($bn === '') {
        wp_send_json_error(array('message' => 'বাংলা নাম লিখুন।'));
    }
    $slug = sanitize_title($en !== '' ? $en : $bn);
    $t = wp_insert_term($bn, 'sb_category', array('slug' => $slug));
    if (is_wp_error($t)) {
        wp_send_json_error(array('message' => $t->get_error_message()));
    }
    update_term_meta($t['term_id'], 'sb_name_en', $en);
    wp_send_json_success(array('id' => (int) $t['term_id'], 'name' => $bn, 'name_en' => $en));
}

add_action('wp_ajax_sb_delete_category', 'sb_ajax_delete_category');
function sb_ajax_delete_category() {
    sb_ajax_guard('manage_categories');
    $id = (int) sb_req('id', 0);
    $term = get_term($id, 'sb_category');
    if (!$term || is_wp_error($term)) {
        wp_send_json_error(array('message' => 'ক্যাটাগরি পাওয়া যায়নি।'));
    }
    wp_delete_term($id, 'sb_category');
    wp_send_json_success();
}

/* ---------- Admin-only tools ---------- */
add_action('wp_ajax_sb_delete_demo', 'sb_ajax_delete_demo');
function sb_ajax_delete_demo() {
    sb_ajax_guard('manage_options');
    if (!SB_Lock::is_unlocked()) {
        wp_send_json_error(array('message' => 'আগে সেটিংসে PIN দিয়ে আনলক করুন।'), 403);
    }
    $ids = get_posts(array(
        'post_type' => 'sb_post', 'post_status' => 'any', 'numberposts' => -1,
        'fields' => 'ids', 'meta_key' => '_sb_demo', 'meta_value' => '1',
    ));
    foreach ($ids as $id) {
        wp_delete_post($id, true);
    }
    wp_send_json_success(array('deleted' => count($ids)));
}

add_action('wp_ajax_sb_set_subscription', 'sb_ajax_set_subscription');
function sb_ajax_set_subscription() {
    sb_ajax_guard('manage_options');
    if (!SB_Lock::is_unlocked()) {
        wp_send_json_error(array('message' => 'আগে সেটিংসে PIN দিয়ে আনলক করুন।'), 403);
    }
    $uid = (int) sb_req('user_id', 0);
    if (!$uid || !get_userdata($uid)) {
        wp_send_json_error(array('message' => 'ইউজার পাওয়া যায়নি।'));
    }
    $date  = sanitize_text_field(sb_req('start_date'));
    $limit = sb_req('limit', '');
    $st = SB_Credits::set_subscription($uid, $date !== '' ? $date : null, $limit !== '' ? (int) $limit : null);
    if (is_wp_error($st)) {
        wp_send_json_error(array('message' => $st->get_error_message()));
    }
    wp_send_json_success(array('balance' => $st));
}

add_action('wp_ajax_sb_balance', 'sb_ajax_balance');
function sb_ajax_balance() {
    sb_ajax_guard();
    wp_send_json_success(array('balance' => SB_Credits::state(get_current_user_id())));
}

/* ---------- Search posts/pages to link as related ---------- */
add_action('wp_ajax_sb_search_content', 'sb_ajax_search_content');
function sb_ajax_search_content() {
    sb_ajax_guard();
    $q = sanitize_text_field(sb_req('q'));
    $exclude = (int) sb_req('exclude', 0);
    $posts = get_posts(array(
        'post_type' => array('sb_post', 'post', 'page'), 'post_status' => 'publish', 's' => $q,
        'posts_per_page' => 12, 'no_found_rows' => true, 'post__not_in' => $exclude ? array($exclude) : array(),
    ));
    $out = array();
    foreach ($posts as $p) {
        $type = $p->post_type === 'page' ? 'পেজ' : ($p->post_type === 'sb_post' ? (sb_is_press($p->ID) ? 'সংবাদ' : 'ব্লগ') : 'পোস্ট');
        $out[] = array('id' => $p->ID, 'title' => get_the_title($p), 'type' => $type);
    }
    wp_send_json_success(array('items' => $out));
}

/* ---------- Newspaper coverage (external link + category) ---------- */
add_action('wp_ajax_sb_save_press', 'sb_ajax_save_press');
function sb_ajax_save_press() {
    sb_ajax_guard();
    $id    = (int) sb_req('id', 0);
    $title = sanitize_text_field(sb_req('title'));
    $paper = sanitize_text_field(sb_req('paper'));
    $url   = esc_url_raw(trim(sb_req('url')), array('http', 'https'));
    $date  = sanitize_text_field(sb_req('date'));
    if ($title === '' || $url === '' || $paper === '') {
        wp_send_json_error(array('message' => 'শিরোনাম, পত্রিকার নাম ও লিংক—তিনটিই দিন।'));
    }
    if ($id && (get_post_type($id) !== 'sb_post' || !current_user_can('edit_post', $id))) {
        wp_send_json_error(array('message' => 'অনুমতি নেই।'), 403);
    }
    $arr = array('post_type' => 'sb_post', 'post_title' => $title, 'post_status' => 'publish',
        'post_excerpt' => sanitize_textarea_field(sb_req('excerpt')));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $arr['post_date'] = $date . ' ' . ($date >= wp_date('Y-m-d') ? wp_date('H:i:s') : '12:00:00');
        $arr['post_date_gmt'] = get_gmt_from_date($arr['post_date']);
    }
    if ($id) {
        $arr['ID'] = $id;
        $res = wp_update_post(wp_slash($arr), true);
    } else {
        $arr['post_author'] = get_current_user_id();
        $res = wp_insert_post(wp_slash($arr), true);
    }
    if (is_wp_error($res)) {
        wp_send_json_error(array('message' => $res->get_error_message()));
    }
    $id = (int) $res;
    if (!$id || !get_post_meta($id, '_sb_press_slugged', true)) {
        wp_update_post(array('ID' => $id, 'post_name' => 'press-' . $id));
        update_post_meta($id, '_sb_press_slugged', 1);
    }
    update_post_meta($id, '_sb_press_url', $url);
    update_post_meta($id, '_sb_press_paper', $paper);
    $term = (int) sb_req('category', 0);
    wp_set_object_terms($id, ($term && term_exists($term, 'sb_category')) ? array($term) : array(), 'sb_category');
    $img = (int) sb_req('image_id', 0);
    if ($img && get_post_type($img) === 'attachment' && current_user_can('edit_post', $img)) {
        set_post_thumbnail($id, $img);
    } elseif (!$img) {
        delete_post_thumbnail($id);
    }
    wp_send_json_success(array('id' => $id));
}


/* ---------- Add summary / FAQ / smart related to an already saved post (article is never touched) ---------- */
add_action('wp_ajax_sb_enrich_post', 'sb_ajax_enrich_post');
function sb_ajax_enrich_post() {
    sb_ajax_guard();
    $id = (int) sb_req('id', 0);
    if (get_post_type($id) !== 'sb_post' || !current_user_can('edit_post', $id) || sb_is_press($id)) {
        wp_send_json_error(array('message' => 'এই পোস্টে করা যাবে না।'));
    }
    $parts = array_values(array_intersect(array('summary', 'faq', 'related'), array_map('trim', explode(',', (string) sb_req('parts')))));
    $over = sb_req('overwrite') === '1';
    $uid = get_current_user_id();
    $kw = get_post_meta($id, '_sb_keywords', true);
    $ctx = array('title' => get_the_title($id), 'keywords' => is_array($kw) ? $kw : array(), 'post_id' => $id,
        'content' => get_post_field('post_content', $id), 'excerpt' => get_post_field('post_excerpt', $id), 'faq_count' => 5);
    $out = array('done' => array(), 'skipped' => array(), 'none' => array(), 'cost' => 0);
    foreach ($parts as $part) {
        $have = $part === 'summary' ? get_post_meta($id, '_sb_summary', true) !== ''
            : ($part === 'faq' ? !empty(get_post_meta($id, '_sb_faq', true)) : !empty(array_filter((array) get_post_meta($id, '_sb_related', true))));
        if ($have && !$over) {
            $out['skipped'][] = $part;
            continue;
        }
        if ($part !== 'related' && mb_strlen(wp_strip_all_tags($ctx['content'])) < 80) {
            $out['none'][] = $part;   // post has no real article to work from
            continue;
        }
        $cost = sb_feature_cost($part);
        $state = SB_Credits::state($uid);
        if ($state['remaining'] < $cost) {
            wp_send_json_error(array('message' => 'ক্রেডিট যথেষ্ট নয়। প্রয়োজন ' . sb_bn_digits($cost) . ', আছে ' . sb_bn_digits($state['remaining']) . '।', 'partial' => $out));
        }
        $res = SB_AI::generate($part, $ctx);
        if (is_wp_error($res)) {
            wp_send_json_error(array('message' => $res->get_error_message(), 'partial' => $out));
        }
        if ($part === 'summary') {
            update_post_meta($id, '_sb_summary', sanitize_textarea_field($res['text']));
            update_post_meta($id, '_sb_inc_summary', '1');
        } elseif ($part === 'faq') {
            update_post_meta($id, '_sb_faq', $res['faq']);
            update_post_meta($id, '_sb_inc_faq', '1');
        } else {
            if (empty($res['items'])) {
                $out['none'][] = $part;   // nothing relevant: not charged
                continue;
            }
            $ids = array();
            $why = array();
            foreach ($res['items'] as $it) {
                $ids[] = (int) $it['id'];
                $why[(int) $it['id']] = $it['why'];
            }
            update_post_meta($id, '_sb_related', $ids);
            update_post_meta($id, '_sb_related_why', $why);
        }
        SB_Credits::charge($uid, $part, $cost, $id);
        $out['done'][] = $part;
        $out['cost'] += $cost;
    }
    $out['balance'] = SB_Credits::state($uid);
    wp_send_json_success($out);
}
