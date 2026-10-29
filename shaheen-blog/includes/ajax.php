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

/** Fresh security token for a page that has been open for a long time (logged-in users only). */
add_action('wp_ajax_sb_nonce', function () {
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'login'), 403);
    }
    wp_send_json_success(array('nonce' => wp_create_nonce('sb_admin')));
});

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
function sb_fetch_pages($raw, &$notes, $max = 4, $tag = 'ওয়েবসাইট') {
    $out = '';
    $n = 0;
    foreach (preg_split('/\R+/', trim((string) $raw)) as $u) {
        $u = esc_url_raw(trim($u), array('http', 'https'));
        if ($u === '' || $n >= $max) {
            continue;
        }
        $n++;
        $r = wp_safe_remote_get($u, array('timeout' => 12, 'redirection' => 3, 'user-agent' => 'Mozilla/5.0 (ShaheenBlog)'));
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
            $notes[] = $tag . ' পড়া যায়নি: ' . $u;
            continue;
        }
        $h = wp_remote_retrieve_body($r);
        $h = preg_replace('#<(script|style|noscript|svg|nav|footer|header)\b[^>]*>.*?</\1>#is', ' ', $h);
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($h), ENT_QUOTES, 'UTF-8')));
        if (mb_strlen($t) < 80) {
            $notes[] = 'পেজে পড়ার মতো লেখা পাওয়া যায়নি (জাভাস্ক্রিপ্টে লোড হওয়া পেজ হতে পারে): ' . $u;
            continue;
        }
        $out .= "\n[{$tag}: {$u}]\n" . mb_substr($t, 0, 9000) . "\n";
        $notes[] = '✓ ' . $tag . ' পড়া হয়েছে (' . sb_bn_digits(mb_strlen($t)) . ' অক্ষর): ' . $u;
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
    if ($feature === 'custom' || $feature === 'brief') {
        $cmodel = sanitize_text_field(sb_req('model'));
        if (!in_array($cmodel, sb_allowed_models(), true)) {
            $cmodel = trim((string) sb_get('model_article')) ?: sb_get('model');
        }
        $cost = ($feature === 'brief') ? max($cost, (int) round(sb_custom_cost(1, $cmodel) * 0.2)) : (((int) sb_req('words', 0) >= 300) ? sb_words_cost((int) sb_req('words'), $cmodel) : sb_custom_cost($tier, $cmodel));   // the brief costs about 1/5 of an article on the chosen model
    }
    if ($feature === 'rewrite_post') {
        $rm = sanitize_text_field(sb_req('model'));
        if ($rm !== '' && in_array($rm, sb_allowed_models(), true)) {
            $cmodel = $rm;
            $cost = max($cost, sb_custom_cost(1, $rm));   // a rewrite on a pricier model costs like a short article on it
        }
    }
    if (!$cost) {
        wp_send_json_error(array('message' => 'অজানা AI ফিচার।'));
    }
    $uid = get_current_user_id();
    $state = SB_Credits::state($uid);
    $research = (in_array($feature, array('article', 'custom', 'brief'), true) && sb_req('research') === '1');
    $need = $cost + ($research ? max(0, (int) sb_get('cost_research')) : 0);
    if ($state['remaining'] < $need) {
        wp_send_json_error(array(
            'message' => 'ব্যালেন্স যথেষ্ট নয়। প্রয়োজন ' . sb_money($need) . ', আছে ' . sb_money($state['remaining']) . '। রিসেট: ' . $state['next_reset_bn'],
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
        'ansi'     => sb_req('ansi') === '1',
        'style'    => in_array(sb_req('style'), array('para', 'points', 'both', 'ai'), true) ? sb_req('style') : 'para',
        'words'    => max(0, min(5000, (int) sb_req('words', 0))),
        'mode'     => in_array(sb_req('mode'), array('links', 'paste', 'both'), true) ? sb_req('mode') : 'both',
        'paste'    => mb_substr(sanitize_textarea_field(sb_req('paste')), 0, 40000),
        'ptype'    => isset(sb_ptypes()[sb_req('ptype')]) ? sb_req('ptype') : 'general',
        'aud'      => isset(sb_audiences()[sb_req('aud')]) ? sb_req('aud') : 'all',
        'tone'     => isset(sb_tones()[sb_req('tone')]) ? sb_req('tone') : 'warm',
        'brief'    => mb_substr(sanitize_textarea_field(sb_req('brief')), 0, 8000),
        'beat_urls' => mb_substr(sanitize_textarea_field(sb_req('comp') . ' ' . ($feature === 'rewrite_post' ? sb_req('urls') : '')), 0, 3000),
    );
    if ($feature === 'chat') {
        if (sb_req('use_src') === '1') {
            $ctx['parts'] = sb_src_parts($uid);
        }
        $ctx['state'] = sb_chat_state(sb_req('state'));
        $ctx['history'] = sb_chat_history(sb_req('history'));
    }
    if ($feature === 'custom' || $feature === 'brief' || $feature === 'rewrite_post') {
        sb_job_start($uid, $ctx, $cost, $research, $state, $feature);   // exits (long job: runs in the background, the browser polls)
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
/** True when a sentence of the article is the owner's own instruction/keyword list copied into the text. */
function sb_is_echo($sent, $prompt_sents, $keywords) {
    $sent = trim($sent);
    if (mb_strlen($sent) < 12) {
        return false;
    }
    if (preg_match('/নির্দেশ(না)?\s*অনুযায়ী|প্রম্পট|কীওয়ার্ড(গুলো|গুলি)?\s*(ব্যবহার|হিসেবে|হিসাবে)|শব্দসংখ্যা|শব্দের\s*(মধ্যে|সীমা|লক্ষ্য)|লিখে\s*দিন|লিখে\s*দিচ্ছি|আর্টিকেল(টি)?\s*(লেখা\s*হয়েছে|সাজানো\s*হয়েছে)|মেটা\s*(বর্ণনা|ডেসক্রিপশন)|URL\s*স্লাগ|\[?এই\s*লেখায়\s*আমরা/u', $sent)) {
        return true;
    }
    $hit = 0;
    foreach ($keywords as $k) {
        if ($k !== '' && mb_stripos($sent, $k) !== false) {
            $hit++;
        }
    }
    if ($hit >= 3 && substr_count($sent, ',') + substr_count($sent, '،') >= 2 && mb_strlen($sent) < 220) {
        return true;
    }
    foreach ($prompt_sents as $p) {
        similar_text($sent, $p, $pct);
        if ($pct >= 72) {
            return true;
        }
    }
    return false;
}

/** Removes sentences/list items that are copies of the owner's instructions or keyword lists. Returns [html, removed[]]. */
function sb_strip_echo($html, $instruction, $keywords) {
    $ps = array();
    foreach (preg_split('/(?<=[।\.\?!])\s+|\R+/u', wp_strip_all_tags((string) $instruction)) as $p) {
        $p = trim($p);
        // only the owner's command-like sentences count (a plain fact copied from the prompt is fine)
        if (mb_strlen($p) >= 20 && preg_match('/লিখ(ুন|বে|ে\s*দিন|ে\s*দিবে)|দিন|দেবে|দেবেন|থাকবে|বাদ|করুন|করবে|করবেন|সুর|কীওয়ার্ড|শব্দ/u', $p)) {
            $ps[] = $p;
        }
    }
    $removed = array();
    $html = preg_replace_callback('#<(p|li)\b([^>]*)>(.*?)</\1>#us', function ($m) use ($ps, $keywords, &$removed) {
        $inner = $m[3];
        $plain = trim(wp_strip_all_tags($inner));
        $sents = preg_split('/(?<=[।\.\?!])\s+/u', $plain);
        $keep = array();
        $drop = 0;
        foreach ($sents as $sn) {
            if (sb_is_echo($sn, $ps, $keywords)) {
                $drop++;
                $removed[] = mb_substr($sn, 0, 140);
            } else {
                $keep[] = $sn;
            }
        }
        if (!$drop) {
            return $m[0];
        }
        if (!$keep) {
            return '';
        }
        if (strpos($inner, '<') === false) {
            return '<' . $m[1] . $m[2] . '>' . implode(' ', $keep) . '</' . $m[1] . '>';
        }
        return $m[0];   // sentence has inner tags (links, bold): leave it, it is listed in the warning
    }, (string) $html);
    return array($html, array_values(array_unique($removed)));
}

function sb_custom_result($r, $ctx = array()) {
    $raw = trim((string) $r['text']);
    $j = json_decode(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $raw), true);
    if (!is_array($j) && preg_match('/\{.*\}/us', $raw, $m)) {
        $j = json_decode($m[0], true);
    }
    if (is_array($j) && empty($j['article'])) {
        // the model answered with a note or a question instead of the article: never show raw JSON in the editor
        $note = '';
        foreach (array('excerpt', 'subtitle', 'meta') as $nk) {
            if (!empty($j[$nk]) && is_string($j[$nk]) && mb_strlen($j[$nk]) > mb_strlen($note)) {
                $note = $j[$nk];
            }
        }
        $r['text'] = '';
        $r['no_article'] = mb_substr(sanitize_textarea_field($note), 0, 600);
        return $r;
    }
    if (is_array($j) && !empty($j['article'])) {
        $r['text'] = (string) $j['article'];
        // the article is really a plan / note to the owner, not a reader-facing article
        $plain = wp_strip_all_tags($r['text']);
        $hits = preg_match_all('/প্রস্তাবিত লেখার রূপরেখা|প্রস্তাবিত রূপরেখা|অনুমোদন(ের)? পর|অনুমোদন পেলে|পূর্ণ আর্টিকেল (নয়|লেখা যাবে)|পরিকল্পনা দেওয়া হলো|শব্দ-বরাদ্দ|চূড়ান্তভাবে নিশ্চিত|মালিকের দেওয়া/u', $plain);
        if ($hits >= 2) {
            $r['text'] = '';
            $r['no_article'] = 'AI পূর্ণ আর্টিকেলের বদলে পরিকল্পনা/রূপরেখা লিখেছে।';
            return $r;
        }
        $r['pdf_docs'] = isset($j['pdfs']) ? sb_pdf_docs($j['pdfs']) : array();
        $r['meta'] = isset($j['meta']) ? sanitize_text_field($j['meta']) : '';
        $r['excerpt'] = isset($j['excerpt']) ? sanitize_textarea_field($j['excerpt']) : '';
        if (mb_strlen($r['excerpt']) > 400 || preg_match('/[?？]|নির্দেশ|বোঝাপড়া|রূপরেখা|অনুমোদন/u', $r['excerpt'])) {
            $r['excerpt'] = '';   // a chat-style note, not an excerpt
        }
        if (mb_strlen($r['meta']) > 320) {
            $r['meta'] = mb_substr($r['meta'], 0, 160);
        }
        $facts = array();
        $plain = wp_strip_all_tags((string) $j['article']);
        foreach (!empty($j['facts']) && is_array($j['facts']) ? array_slice($j['facts'], 0, 20) : array() as $f) {
            $l = isset($f['label']) ? sanitize_text_field($f['label']) : '';
            $v = isset($f['value']) ? sanitize_text_field($f['value']) : '';
            if ($l !== '' && $v !== '' && mb_strpos($plain, $v) !== false) {
                $facts[] = array('label' => $l, 'value' => $v);
            }
        }
        $r['facts'] = $facts;
        $r['subtitle'] = isset($j['subtitle']) && mb_strpos($j['subtitle'], 'সর্বোচ্চ ১৪০') === false ? mb_substr(sanitize_text_field($j['subtitle']), 0, 200) : '';
        $r['slug'] = isset($j['slug']) ? trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $j['slug'])), '-') : '';
        $gaps = array();
        foreach (isset($j['gaps']) && is_array($j['gaps']) ? array_slice($j['gaps'], 0, 6) : array() as $g) {
            $g = is_string($g) ? sanitize_text_field($g) : '';
            if (mb_strlen($g) > 8 && mb_strpos($g, 'প্রতিযোগী সাইটগুলোর') === false) {
                $gaps[] = $g;
            }
        }
        $r['gaps'] = $gaps;
        $verify = array();
        foreach (isset($j['verify']) && is_array($j['verify']) ? array_slice($j['verify'], 0, 12) : array() as $v) {
            $it = is_array($v) && isset($v['item']) ? sanitize_text_field($v['item']) : (is_string($v) ? sanitize_text_field($v) : '');
            if (mb_strlen($it) > 5 && mb_strpos($it, 'কী তথ্য ও মান') === false) {
                $verify[] = array('item' => $it, 'source' => is_array($v) && isset($v['source']) ? esc_url_raw($v['source']) : '');
            }
        }
        $r['verify'] = $verify;
        $cov = array();
        foreach (isset($j['coverage']) && is_array($j['coverage']) ? array_slice($j['coverage'], 0, 14) : array() as $v) {
            $tp = is_array($v) && isset($v['topic']) ? sanitize_text_field($v['topic']) : '';
            if (mb_strlen($tp) > 3 && mb_strpos($tp, 'বিষয়') !== 0) {
                $cov[] = array('topic' => $tp, 'covered' => !empty($v['covered']) && $v['covered'] !== 'false');
            }
        }
        $r['coverage'] = $cov;
    }
    $deferral = array();
    $plain_all = wp_strip_all_tags((string) $r['text']);
    foreach (preg_split('/(?<=[।\.\?!])\s+/u', $plain_all) as $sent) {
        if (preg_match('/(সংযুক্ত|সংযোজিত|নিচের|নিচে দেওয়া)\s*(পিডিএফ|PDF|ফাইল)|(পিডিএফ|PDF)[\s\-‑]*(এ|-এ|তে)?\s*(বিস্তারিত|দেখুন|উল্লেখ)|স্পষ্টভাবে বলা আছে|নির্দেশনা দেখুন|লিখে দিতে পারেন|বিস্তারিত লিখে|ওয়েবসাইটে (দেখুন|বলা আছে)|সংশ্লিষ্ট (নির্দেশনা|বিজ্ঞপ্তি|পেজ).{0,12}দেখুন/u', $sent)) {
            $deferral[] = trim(mb_substr($sent, 0, 220));
        }
    }
    $r['deferral'] = array_slice(array_values(array_unique($deferral)), 0, 8);
    $t = sb_md_to_html($r['text']);
    $echo = sb_strip_echo($t, isset($ctx['instruction']) ? $ctx['instruction'] : '', isset($ctx['keywords']) && is_array($ctx['keywords']) ? $ctx['keywords'] : array());
    $t = $echo[0];
    $r['echoed'] = $echo[1];
    $t = preg_replace('/\[[^\]\[]*(এখানে যুক্ত করুন|এখানে যোগ করুন)[^\]\[]*\]/u', '[তথ্য দরকার: সরকারি/মূল তথ্য]', $t);
    $r['text'] = wp_kses_post($t);
    return $r;
}

/** Sanitised PDF documents the AI was asked to produce (title + html), at most 2. */
function sb_pdf_docs($arr) {
    $out = array();
    foreach (is_array($arr) ? array_slice($arr, 0, 2) : array() as $d) {
        if (!is_array($d) || empty($d['html']) || !is_string($d['html'])) {
            continue;
        }
        $t = isset($d['title']) ? sanitize_text_field($d['title']) : '';
        $out[] = array('title' => $t !== '' ? mb_substr($t, 0, 80) : 'PDF', 'html' => wp_kses_post($d['html']));
    }
    return $out;
}

/** Post-processes a finished result and charges the credits. */
function sb_ai_finish($uid, $feature, $ctx, $cost, $research, $result, &$notes) {
    $post_id = (int) $ctx['post_id'];
    if ($feature === 'custom') {
        $result = sb_custom_result($result, $ctx);
    }
    if ($feature === 'custom' && (isset($result['no_article']) || mb_strlen(wp_strip_all_tags((string) $result['text'])) < 200)) {
        $why = !empty($result['no_article']) ? ' AI বলছে: "' . $result['no_article'] . '"' : '';
        return array('ok' => false, 'message' => 'AI আর্টিকেল লেখেনি, তাই বিল যোগ হয়নি।' . $why . ' আবার "লিখুন" চাপুন; বারবার হলে প্রম্পট বক্সে "আগে অনুমোদন নাও/প্রশ্ন করো" ধরনের কথা থাকলে মুছে দিন।');
    }
    $result = sb_words_apply_result($feature, $result);
    $bh = sb_beat_hosts(isset($ctx['beat_urls']) ? $ctx['beat_urls'] : '');
    if (in_array($feature, array('custom', 'rewrite_post'), true) && !empty($result['text'])) {
        $result['text'] = sb_ref_clean($result['text'], $bh);
    }
    if ($feature === 'chat') {
        $result = sb_chat_result($result, $ctx);
        if (isset($result['chat']['article']) && is_string($result['chat']['article'])) {
            $result['chat']['article'] = sb_ref_clean($result['chat']['article'], $bh);
        }
        if (empty($result['chat']['applied'])) {
            $cost = 0;   // a question or nothing applied: free
        } else {
            // charged by the real work done: what the AI read + what it wrote (+ PDFs), priced at the model's rates
            $u = sb_usage_credits(isset($result['model']) ? $result['model'] : '', isset($result['usage']) ? $result['usage'] : array());
            if ($u) {
                $cost = max(5, $u['read'] + $u['write']);
                $result['chat']['cost_note'] = 'পড়া ' . sb_money($u['read']) . ' + লেখা/চিন্তা ' . sb_money($u['write']);
            }
        }
    }
    if ($feature === 'related' && empty($result['items'])) {
        return array('ok' => false, 'message' => 'সাইটে এই লেখার সাথে মেলে এমন পোস্ট/পেজ পাওয়া যায়নি, তাই বিল যোগ হয়নি। নিজে খুঁজে যোগ করতে পারেন।');
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
    if (in_array($feature, array('custom', 'rewrite_post'), true) && !empty($result['text'])) {
        // charged after writing: by the words actually produced, per 100 words; never more than the user has
        $wc = count(preg_split('/\s+/u', trim(wp_strip_all_tags((string) $result['text'])), -1, PREG_SPLIT_NO_EMPTY));
        $actual = max(1, (int) ceil($wc / 100)) * sb_per100(isset($ctx['model']) ? (string) $ctx['model'] : '');
        $rp = ($research && !empty($result['researched'])) ? max(0, (int) sb_get('cost_research')) : 0;
        $rem = SB_Credits::state($uid)['remaining'];
        $cost = max(0, min($actual + $rp, $rem));
        $result['words'] = $wc;
    }
    $new = $cost > 0 ? SB_Credits::charge($uid, $feature, $cost, $post_id) : SB_Credits::state($uid);
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

function sb_job_start($uid, $ctx, $cost, $research, $state, $feature = 'custom') {
    if ($feature === 'rewrite_post' && mb_strlen(trim($ctx['source'])) < 200 && trim((string) sb_req('urls')) === '') {
        wp_send_json_error(array('message' => 'কমপক্ষে ২০০ অক্ষরের মূল লেখা পেস্ট করুন, অথবা সাইটের লিংক দিন।'));
    }
    if ($feature !== 'rewrite_post' && mb_strlen(trim($ctx['instruction'])) < 30) {
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
        'status' => 'queued', 'feature' => $feature, 'uid' => $uid, 'ctx' => $ctx, 'cost' => $cost, 'research' => $research ? 1 : 0,
        'urls' => (string) sb_req('urls'), 'comp' => (string) sb_req('comp'), 'files' => $files, 'created' => time(), 'beat' => time(),
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
    ignore_user_abort(true);   // a gateway timeout in the browser must not kill the preparation
    if (function_exists('set_time_limit')) {
        @set_time_limit(300); // phpcs:ignore
    }
    $job['status'] = 'starting';
    $job['started'] = time();
    set_transient('sb_job_' . $id, $job, 6 * HOUR_IN_SECONDS);

    $notes = array();
    $ctx = $job['ctx'];
    $ctx['web'] = sb_fetch_pages($job['urls'], $notes);
    if (!empty($job['comp']) && (!isset($ctx['mode']) || $ctx['mode'] !== 'paste')) {
        $cn = array();
        $ctx['comp_web'] = sb_fetch_pages($job['comp'], $cn, 5, 'প্রতিযোগী সাইট');
        foreach ($cn as $x) {
            $notes[] = $x;
        }
    }
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
    sb_src_save($job['uid'], $job['files']);
    $notes = array_merge($notes, SB_Know::status(isset($ctx['keywords']) ? $ctx['keywords'] : array()));
    $ctx['bg'] = true;
    $r = SB_AI::generate(isset($job['feature']) ? $job['feature'] : 'custom', $ctx);
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
        if ($job['status'] === 'starting' && time() - (int) (isset($job['started']) ? $job['started'] : time()) > 6 * 60) {
            delete_transient('sb_job_' . $id);
            wp_send_json_success(array('state' => 'error', 'message' => 'সোর্স (সাইট/PDF) তৈরি করতে বেশি সময় লেগে গেছে, কাজ শুরু হয়নি; বিল যোগ হয়নি। ফাইল/লিংক কমিয়ে আবার চেষ্টা করুন।', 'balance' => SB_Credits::state($uid)));
        }
        wp_send_json_success(array('state' => 'running'));   // starting / finalizing
    }
    $r = SB_AI::bg_fetch($job['bg_id']);
    if ($r['state'] === 'running') {
        if (time() - (int) $job['created'] > 20 * 60) {
            SB_AI::bg_cancel($job['bg_id']);
            delete_transient('sb_job_' . $id);
            wp_send_json_success(array('state' => 'error', 'message' => 'AI ২০ মিনিটেও শেষ করতে পারেনি, বন্ধ করা হয়েছে। বিল যোগ হয়নি। কম ফাইল বা ছোট দৈর্ঘ্যে আবার চেষ্টা করুন।', 'balance' => SB_Credits::state($uid)));
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
    $jf = isset($job['feature']) ? $job['feature'] : 'custom';
    $res = $r['result'];
    if ($jf === 'rewrite_post') {
        $rt = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', (string) $res['text']));
        $rj = json_decode($rt, true);
        if (!is_array($rj) && preg_match('/\{.*\}/us', $rt, $rm)) {
            $rj = json_decode($rm[0], true);
        }
        if (!is_array($rj) || empty($rj['article'])) {
            wp_send_json_success(array('state' => 'error', 'message' => 'AI-এর উত্তর পড়া যায়নি। বিল যোগ হয়নি। আবার চেষ্টা করুন।', 'balance' => SB_Credits::state($uid)));
        }
        $cf = array();
        foreach (isset($rj['conflicts']) && is_array($rj['conflicts']) ? array_slice($rj['conflicts'], 0, 8) : array() as $cx) {
            $cx = is_string($cx) ? sanitize_text_field($cx) : '';
            if (mb_strlen($cx) > 8) {
                $cf[] = $cx;
            }
        }
        $res = array('headline' => sanitize_text_field(isset($rj['headline']) ? $rj['headline'] : ''), 'excerpt' => sanitize_textarea_field(isset($rj['excerpt']) ? $rj['excerpt'] : ''),
            'text' => wp_kses_post(sb_md_to_html((string) $rj['article'])), 'conflicts' => $cf);
    }
    if ($jf === 'brief') {
        $pb = SB_AI::parse_brief($res['text']);
        if (is_wp_error($pb)) {
            wp_send_json_success(array('state' => 'error', 'message' => $pb->get_error_message() . ' বিল যোগ হয়নি।', 'balance' => SB_Credits::state($uid)));
        }
        $pb['sources'] = isset($res['sources']) ? $res['sources'] : array();
        $pb['researched'] = !empty($res['researched']);
        $res = $pb;
    }
    $out = sb_ai_finish($uid, $jf, $job['ctx'], (int) $job['cost'], (bool) $job['research'], $res, $notes);
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
        // keep the last 3 earlier versions of the article so a bad edit can be rolled back
        $old_content = (string) get_post_field('post_content', $id);
        if (mb_strlen(wp_strip_all_tags($old_content)) >= 50 && trim($old_content) !== trim((string) $postarr['post_content'])) {
            $vers = get_post_meta($id, '_sb_versions', true);
            $vers = is_array($vers) ? $vers : array();
            array_unshift($vers, array('t' => time(), 'c' => $old_content));
            update_post_meta($id, '_sb_versions', array_slice($vers, 0, 3));
        }
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
    $spos = (string) sb_req('summary_pos');
    update_post_meta($id, '_sb_summary_pos', preg_match('/^(top|middle|end|p:([1-9]|[1-3][0-9]))$/', $spos) ? $spos : 'top');
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

    sb_set_post_categories($id, (int) sb_req('category', 0), sb_req_list('category_extra', 20));

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

add_action('wp_ajax_sb_credit_refresh', 'sb_ajax_credit_refresh');
/** One-click credit refresh: used = 0, new cycle from today, limit applied (and saved as the default for everyone). */
function sb_ajax_credit_refresh() {
    sb_ajax_guard('manage_options');
    if (!SB_Lock::is_unlocked()) {
        wp_send_json_error(array('message' => 'আগে সেটিংসে PIN দিয়ে আনলক করুন।'), 403);
    }
    $usd   = max(0, min(100000, (float) sb_req('limit_usd', 32)));
    $limit = sb_limit_credits_from_usd($usd);
    $scope = sb_req('scope') === 'all' ? 'all' : 'me';
    $s = get_option('sb_settings', array());
    $s = is_array($s) ? $s : array();
    $s['default_limit'] = $limit;
    $s['limit_usd']     = $usd;
    update_option('sb_settings', $s);
    $ids = array(get_current_user_id());
    if ($scope === 'all') {
        $ids = wp_list_pluck(get_users(array('capability' => 'edit_posts', 'number' => 500, 'fields' => array('ID'))), 'ID');
    }
    $n = 0;
    foreach ($ids as $id) {
        $r = SB_Credits::set_subscription((int) $id, SB_Credits::today(), $limit);
        if (!is_wp_error($r)) {
            $n++;
        }
    }
    wp_send_json_success(array('users' => $n, 'balance' => SB_Credits::state(get_current_user_id())));
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
    $limit = sb_req('limit_usd', '');
    $st = SB_Credits::set_subscription($uid, $date !== '' ? $date : null, $limit !== '' ? sb_limit_credits_from_usd((float) $limit) : null);
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
    sb_set_post_categories($id, (int) sb_req('category', 0), array());
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
            wp_send_json_error(array('message' => 'ব্যালেন্স যথেষ্ট নয়। প্রয়োজন ' . sb_money($cost) . ', আছে ' . sb_money($state['remaining']) . '।', 'partial' => $out));
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


/* ---------- Chat assistant: change one point of the post at a time ---------- */
function sb_chat_faq($raw) {
    $out = array();
    foreach (is_array($raw) ? array_slice($raw, 0, 10) : array() as $row) {
        $q = sanitize_text_field(isset($row['q']) ? $row['q'] : '');
        $a = sanitize_textarea_field(isset($row['a']) ? $row['a'] : '');
        if ($q !== '' && $a !== '') {
            $out[] = array('q' => $q, 'a' => $a);
        }
    }
    return $out;
}

function sb_chat_state($json) {
    $j = json_decode((string) $json, true);
    $j = is_array($j) ? $j : array();
    $o = array();
    foreach (array('title', 'subtitle', 'meta', 'slug') as $k) {
        $o[$k] = isset($j[$k]) ? sanitize_text_field($j[$k]) : '';
    }
    foreach (array('excerpt', 'summary') as $k) {
        $o[$k] = isset($j[$k]) ? sanitize_textarea_field($j[$k]) : '';
    }
    $o['faq'] = sb_chat_faq(isset($j['faq']) ? $j['faq'] : array());
    $o['spos'] = isset($j['spos']) && preg_match('/^(top|middle|end|p:\d{1,2})$/', (string) $j['spos']) ? $j['spos'] : 'top';
    $o['author'] = isset($j['author']) ? sanitize_text_field($j['author']) : '';
    $o['caption'] = isset($j['caption']) ? sanitize_textarea_field($j['caption']) : '';
    $o['incS'] = !empty($j['incS']);
    $o['incF'] = !empty($j['incF']);
    $o['category'] = isset($j['category']) ? sanitize_text_field($j['category']) : '';
    $o['cats'] = array();
    foreach (isset($j['cats']) && is_array($j['cats']) ? array_slice($j['cats'], 0, 60) : array() as $c) {
        $o['cats'][] = sanitize_text_field($c);
    }
    $o['kw'] = array();
    foreach (isset($j['kw']) && is_array($j['kw']) ? array_slice($j['kw'], 0, 10) : array() as $k) {
        $o['kw'][] = sanitize_text_field($k);
    }
    return $o;
}

function sb_chat_history($json) {
    $j = json_decode((string) $json, true);
    $o = array();
    foreach (is_array($j) ? array_slice($j, -6) : array() as $h) {
        $o[] = array('role' => (isset($h['role']) && $h['role'] === 'ai') ? 'ai' : 'me', 'text' => sanitize_textarea_field(isset($h['text']) ? $h['text'] : ''));
    }
    return $o;
}

/** Reads the chat JSON, applies find/replace edits to the article on the server, reports what really changed. */
/** Find $find inside $html ignoring whitespace differences, tags between the words and &nbsp;. Returns [byte offset, byte length] or null (also when it is ambiguous). */
function sb_find_loose($html, $find) {
    $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($find), ENT_QUOTES, 'UTF-8')));
    $plain = str_replace("\xC2\xA0", ' ', $plain);
    if (mb_strlen($plain) < 12) {
        return null;
    }
    $words = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);
    $gap = '(?:\s|&nbsp;|&#160;|<[^>]*>)*';
    $re = '/' . implode($gap, array_map(function ($w) { return preg_quote($w, '/'); }, $words)) . '/u';
    if (preg_match_all($re, $html, $mm, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }
    return array($mm[0][0][1], strlen($mm[0][0][0]));
}

function sb_chat_result($r, $ctx) {
    $raw = trim((string) $r['text']);
    $j = json_decode(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $raw), true);
    if (!is_array($j) && preg_match('/\{.*\}/us', $raw, $m)) {
        $j = json_decode($m[0], true);
    }
    $out = array('reply' => '', 'rule' => '', 'changes' => array(), 'article' => null, 'applied' => 0, 'failed' => array(), 'missing' => array());
    if (!is_array($j)) {
        $cut = isset($r['finish']) && $r['finish'] === 'length';
        // salvage the spoken part of a cut-off or plain-text answer
        $say = '';
        if (preg_match('/"reply"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/us', $raw, $rm)) {
            $say = (string) json_decode('"' . $rm[1] . '"');
        } elseif ($raw !== '' && $raw[0] !== '{') {
            $say = $raw;
        }
        $out['reply'] = ($say !== '' ? mb_substr(sanitize_textarea_field($say), 0, 500) . "\n" : '')
            . ($cut ? '⚠️ AI-র উত্তর সীমা ছাড়িয়ে কেটে গেছে (বেশি বড় লেখা বদলাতে চেয়েছিল), তাই কিছু বদলায়নি। বদলটা ছোট করে বা একটা অংশ ধরে লিখুন, যেমন "ফোন নম্বরের লাইনটা বদলাও"। (বিল যোগ হয়নি)'
                : '⚠️ AI-র উত্তর ঠিক ফরম্যাটে আসেনি, কিছু বদলায়নি; আবার চেষ্টা করুন। (বিল যোগ হয়নি)' . ($raw !== '' ? ' [উত্তরের শুরু: ' . mb_substr(sanitize_text_field($raw), 0, 120) . ']' : ''));
        $r['chat'] = $out;
        return $r;
    }
    $out['reply'] = isset($j['reply']) ? sanitize_textarea_field($j['reply']) : '';
    $out['rule'] = isset($j['rule']) && is_string($j['rule']) ? mb_substr(sanitize_text_field($j['rule']), 0, 300) : '';
    $cur = isset($ctx['content']) ? (string) $ctx['content'] : '';
    $ch = isset($j['changes']) && is_array($j['changes']) ? $j['changes'] : array();
    $st = isset($ctx['state']) ? $ctx['state'] : array();
    foreach (array('subtitle', 'meta', 'slug') as $k) {
        if (isset($ch[$k]) && is_string($ch[$k])) {
            $v = sanitize_text_field($ch[$k]);
            if ($k === 'slug') {
                $v = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($v)), '-');
            }
            if ($v !== '' && $v !== (isset($st[$k]) ? $st[$k] : '')) {
                $out['changes'][$k] = $v;
            }
        }
    }
    foreach (array('excerpt', 'summary') as $k) {
        if (isset($ch[$k]) && is_string($ch[$k])) {
            $v = sanitize_textarea_field($ch[$k]);
            if ($v !== '' && $v !== (isset($st[$k]) ? $st[$k] : '')) {
                $out['changes'][$k] = $v;
            }
        }
    }
    // the rest of the post (the chat can manage every part of the page)
    if (isset($ch['spos']) && is_string($ch['spos']) && preg_match('/^(top|middle|end|p:([1-9]|10))$/', $ch['spos']) && $ch['spos'] !== (isset($st['spos']) ? $st['spos'] : 'top')) {
        $out['changes']['spos'] = $ch['spos'];
    }
    foreach (array('author' => 'author', 'caption' => 'caption') as $k => $sk) {
        if (isset($ch[$k]) && is_string($ch[$k])) {
            $v = $k === 'caption' ? sanitize_textarea_field($ch[$k]) : sanitize_text_field($ch[$k]);
            if ($v !== '' && $v !== (isset($st[$sk]) ? $st[$sk] : '')) {
                $out['changes'][$k] = $v;
            }
        }
    }
    foreach (array('incS', 'incF') as $k) {
        if (isset($ch[$k]) && is_bool($ch[$k]) && $ch[$k] !== !empty($st[$k])) {
            $out['changes'][$k] = $ch[$k];
        }
    }
    if (isset($ch['category']) && is_string($ch['category']) && !empty($st['cats']) && in_array(sanitize_text_field($ch['category']), $st['cats'], true) && sanitize_text_field($ch['category']) !== (isset($st['category']) ? $st['category'] : '')) {
        $out['changes']['category'] = sanitize_text_field($ch['category']);
    }
    if (isset($ch['keywords']) && is_array($ch['keywords'])) {
        $kw = array();
        foreach (array_slice($ch['keywords'], 0, 10) as $k) {
            $k = is_string($k) ? sanitize_text_field($k) : '';
            if ($k !== '' && !in_array($k, $kw, true)) {
                $kw[] = $k;
            }
        }
        if ($kw && $kw !== (isset($st['kw']) ? $st['kw'] : array())) {
            $out['changes']['keywords'] = $kw;
        }
    }
    if (isset($ch['faq']) && is_array($ch['faq'])) {
        $f = sb_chat_faq($ch['faq']);
        if ($f && $f !== (isset($st['faq']) ? $st['faq'] : array())) {
            $out['changes']['faq'] = $f;
        }
    }
    $new = $cur;
    if (!empty($j['article']) && is_string($j['article'])) {
        $cand = wp_kses_post(sb_md_to_html(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($j['article']))));
        $cl = mb_strlen(wp_strip_all_tags($cand));
        $ol = mb_strlen(wp_strip_all_tags($cur));
        // a truncated or placeholder answer must never replace the owner's article
        $cand = sb_strip_echo($cand, isset($ctx['instruction']) ? $ctx['instruction'] : '', isset($ctx['keywords']) && is_array($ctx['keywords']) ? $ctx['keywords'] : array())[0];
        $cl = mb_strlen(wp_strip_all_tags($cand));
        if ($cl < 150 || ($ol > 600 && $cl < $ol * 0.4)) {
            $out['failed'][] = 'AI returned an incomplete article; the article was left unchanged';
        } else {
            $new = $cand;
        }
    } elseif (!empty($j['edits']) && is_array($j['edits'])) {
        foreach (array_slice($j['edits'], 0, 12) as $e) {
            $f = isset($e['find']) && is_string($e['find']) ? $e['find'] : '';
            $rep = isset($e['replace']) && is_string($e['replace']) ? wp_kses_post($e['replace']) : '';
            $pos = $f !== '' ? strpos($new, $f) : false;
            $len = strlen($f);
            if ($pos === false && $f !== '') {
                // The AI rarely copies HTML byte for byte (spaces, line breaks, &nbsp;, tags): match the visible text instead.
                $m = sb_find_loose($new, $f);
                if ($m) {
                    $pos = $m[0];
                    $len = $m[1];
                }
            }
            if ($pos === false) {
                $out['failed'][] = mb_substr(wp_strip_all_tags($f), 0, 80);
                continue;
            }
            $new = substr_replace($new, $rep, $pos, $len);
        }
    }
    if ($new !== $cur) {
        preg_match_all('/\[sb_slot [a-z0-9]+\]/', $cur, $sl);
        foreach ($sl[0] as $mk) {
            if (strpos($new, $mk) === false) {
                $new .= "\n<p>" . $mk . '</p>';   // AI dropped a placement marker: keep it (at the end)
            }
        }
        $out['article'] = $new;
        preg_match_all('/\[তথ্য দরকার:[^\]]*\]/u', $new, $mm);
        $out['missing'] = array_values(array_unique($mm[0]));
    }
    $out['pdf_docs'] = isset($j['pdfs']) ? sb_pdf_docs($j['pdfs']) : array();
    $out['applied'] = count($out['changes']) + ($out['article'] !== null ? 1 : 0) + ($out['pdf_docs'] ? 1 : 0);
    $out['promise'] = (!$out['applied'] && empty($out['failed']) && preg_match('/(করব|করছি|যুক্ত করে দেব|যোগ করব|দিচ্ছি|লিখব|বদলাব|সরিয়ে দেব)/u', $out['reply'])) ? 1 : 0;
    if (!$out['applied'] && $out['reply'] === '') {
        $out['reply'] = 'কিছু বদলানো যায়নি। কোন অংশ কীভাবে বদলাবেন আরও স্পষ্ট করে লিখুন।';
    }
    $r['chat'] = $out;
    return $r;
}


/* ---------- Source pack: the PDF pages / images of the last prompt, so the chat can look at them again ---------- */
function sb_src_dir($uid) {
    return sb_job_dir() . '/src-' . (int) $uid;
}

function sb_src_has($uid) {
    $d = sb_src_dir($uid);
    return is_dir($d) && count(glob($d . '/*.{jpg,jpeg,png,webp,pdf}', GLOB_BRACE)) > 0;
}

function sb_src_save($uid, $files) {
    $d = sb_src_dir($uid);
    foreach (glob(dirname($d) . '/src-*') ?: array() as $old) {          // old packs (14 days) are removed
        if (is_dir($old) && filemtime($old) < time() - 14 * DAY_IN_SECONDS) {
            array_map('unlink', glob($old . '/*') ?: array());
            @rmdir($old); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
    }
    $keep = array();
    foreach ($files as $f) {
        if (!empty($f['path']) && file_exists($f['path'])) {
            $keep[] = $f;
        }
    }
    if (!$keep) {
        return;
    }
    wp_mkdir_p($d);
    array_map('unlink', glob($d . '/*') ?: array());
    foreach (array_slice($keep, 0, 14) as $i => $f) {
        @copy($f['path'], $d . '/' . sprintf('%02d', $i) . '.' . $f['ext']); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }
    @touch($d); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

function sb_src_parts($uid) {
    $mime = array('pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
    $parts = array();
    $total = 0;
    foreach (glob(sb_src_dir($uid) . '/*') ?: array() as $p) {
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        if (!isset($mime[$ext]) || count($parts) >= 10 || $total > 9 * 1024 * 1024) {
            continue;
        }
        $total += filesize($p);
        $uri = 'data:' . $mime[$ext] . ';base64,' . base64_encode((string) file_get_contents($p)); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $parts[] = $ext === 'pdf'
            ? array('type' => 'file', 'file' => array('filename' => basename($p), 'file_data' => $uri))
            : array('type' => 'image_url', 'image_url' => array('url' => $uri));
    }
    return $parts;
}

/* ---------- Autosave: a private draft copy, never the live post ---------- */
add_action('wp_ajax_sb_autosave', function () {
    sb_ajax_guard('edit_posts');
    $pid = (int) sb_req('post_id', 0);
    $data = (string) sb_req('data');
    if (strlen($data) > 600000 || !is_array(json_decode($data, true))) {
        wp_send_json_error(array('message' => 'autosave too large'));
    }
    update_user_meta(get_current_user_id(), 'sb_auto_' . $pid, array('t' => time(), 'data' => $data));
    wp_send_json_success(array('t' => time()));
});

add_action('wp_ajax_sb_autosave_clear', function () {
    sb_ajax_guard('edit_posts');
    delete_user_meta(get_current_user_id(), 'sb_auto_' . (int) sb_req('post_id', 0));
    wp_send_json_success(array('ok' => 1));
});

function sb_autosave_get($pid) {
    $a = get_user_meta(get_current_user_id(), 'sb_auto_' . (int) $pid, true);
    return (is_array($a) && !empty($a['data'])) ? array('t' => (int) $a['t'], 'data' => json_decode($a['data'], true)) : null;
}


/* ---------- Writing presets (saved settings; free) ---------- */
add_action('wp_ajax_sb_preset_save', function () {
    sb_ajax_guard();
    $name = mb_substr(sanitize_text_field(sb_req('name')), 0, 40);
    if ($name === '') {
        wp_send_json_error(array('message' => 'প্রিসেটের একটা নাম দিন।'));
    }
    $raw = json_decode((string) sb_req('data'), true);
    $keys = array('ptype', 'aud', 'tone', 'style', 'words', 'mode', 'model', 'clen', 'research', 'inc_summary', 'inc_faq', 'faqn');
    $data = array();
    foreach ($keys as $k) {
        $data[$k] = is_array($raw) && isset($raw[$k]) && is_scalar($raw[$k]) ? sanitize_text_field((string) $raw[$k]) : '';
    }
    $all = sb_presets_get();
    if (!isset($all[$name]) && count($all) >= 20) {
        wp_send_json_error(array('message' => 'সর্বোচ্চ ২০টি প্রিসেট রাখা যায়; আগে একটি মুছুন।'));
    }
    $all[$name] = $data;
    update_user_meta(get_current_user_id(), 'sb_presets', $all);
    wp_send_json_success(array('presets' => $all));
});
add_action('wp_ajax_sb_preset_del', function () {
    sb_ajax_guard();
    $name = mb_substr(sanitize_text_field(sb_req('name')), 0, 40);
    $all = sb_presets_get();
    unset($all[$name]);
    update_user_meta(get_current_user_id(), 'sb_presets', $all);
    wp_send_json_success(array('presets' => $all));
});


add_action('wp_ajax_sb_pdf_upload', 'sb_ajax_pdf_upload');
/** A PDF made in the browser (from AI-written content) is kept in the Media Library. */
function sb_ajax_pdf_upload() {
    sb_ajax_guard('upload_files');
    if (empty($_FILES['pdf']) || (int) $_FILES['pdf']['error'] !== UPLOAD_ERR_OK || (int) $_FILES['pdf']['size'] > 25 * 1024 * 1024) { // phpcs:ignore WordPress.Security.NonceVerification
        wp_send_json_error(array('message' => 'PDF ফাইল আসেনি।'));
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $name = sanitize_text_field(sb_req('name'));
    $base = sanitize_file_name(($name !== '' ? $name : 'document') . '-' . wp_date('Ymd-His'));
    $head = (string) file_get_contents($_FILES['pdf']['tmp_name'], false, null, 0, 5); // phpcs:ignore
    if ($head !== '%PDF-') {
        wp_send_json_error(array('message' => 'ফাইলটি PDF নয়।'));
    }
    $file = array('name' => $base . '.pdf', 'type' => 'application/pdf', 'tmp_name' => $_FILES['pdf']['tmp_name'], 'error' => 0, 'size' => $_FILES['pdf']['size']); // phpcs:ignore
    $aid = media_handle_sideload($file, 0, $name !== '' ? $name : 'PDF');
    if (is_wp_error($aid)) {
        wp_send_json_error(array('message' => $aid->get_error_message()));
    }
    wp_send_json_success(array('name' => $name !== '' ? $name : 'PDF', 'url' => wp_get_attachment_url($aid)));
}
