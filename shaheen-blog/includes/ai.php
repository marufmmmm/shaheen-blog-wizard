<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI content generation (Bengali). Five features: headlines, subtitle,
 * meta, article, summary. Keywords drive every prompt.
 */
class SB_AI {

    const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    protected static function system_prompt() {
        $style = trim((string) sb_get('style_note'));
        return 'তুমি শাহীন শিক্ষা পরিবারের (বাংলাদেশের একটি শিক্ষা প্রতিষ্ঠান-নেটওয়ার্ক, স্কুল ও ক্যাডেট কোচিং) ব্লগের জন্য একজন অভিজ্ঞ বাংলা কনটেন্ট লেখক। '
            . 'শিক্ষার্থী, অভিভাবক ও শিক্ষকরা পাঠক। কোনো মিথ্যা তথ্য, ভুয়া পরিসংখ্যান বা ভুয়া উদ্ধৃতি বানাবে না। নিশ্চিত না হলে সাধারণ ও নিরাপদ কথা লিখবে। '
            . 'ভাষা: শুদ্ধ বানানে, ' . ($style !== '' ? $style : 'প্রচলিত সহজ বাংলায়') . ' '
            . 'লেখার মান: মানুষের মতো স্বাভাবিক ছন্দে লিখবে। বাক্যের দৈর্ঘ্যে বৈচিত্র্য রাখবে (ছোট ও মাঝারি মিলিয়ে), একই শুরু বা একই কাঠামো বারবার ব্যবহার করবে না, '
            . '"সংক্ষেপে বলতে গেলে", "উপসংহারে বলা যায়", "এক কথায়" জাতীয় গতানুগতিক ফাঁকা বাক্য এড়াবে। বাংলাদেশের বাস্তব প্রেক্ষাপট, নির্দিষ্ট উদাহরণ ও ব্যবহারিক পরামর্শ দেবে।';
    }

    protected static function kw_text($keywords) {
        return implode(', ', array_map('strval', $keywords));
    }

    /**
     * @param string $feature headlines|subtitle|meta|article|summary
     * @param array  $c       title, topic, keywords[], excerpt, content, length
     * @return array|WP_Error
     */
    public static function generate($feature, array $c) {
        $key = sb_get('api_key');
        if (empty($key)) {
            return new WP_Error('sb_no_key', 'OpenAI API কী দেওয়া হয়নি। ব্লগ সেটিংসে কী যোগ করুন।');
        }
        $title    = isset($c['title']) ? $c['title'] : '';
        $topic    = isset($c['topic']) ? $c['topic'] : '';
        $keywords = isset($c['keywords']) && is_array($c['keywords']) ? array_values(array_filter($c['keywords'])) : array();
        $kw       = self::kw_text($keywords);
        $json     = false;
        $max      = 400;

        switch ($feature) {
            case 'headlines':
                if (count($keywords) < 2) {
                    return new WP_Error('sb_kw', 'শিরোনামের জন্য কমপক্ষে ২টি কীওয়ার্ড দিন।');
                }
                $user = "একটি ব্লগ পোস্টের জন্য ঠিক ৫টি আলাদা বাংলা শিরোনাম লেখো।\n"
                    . "কীওয়ার্ড (বাধ্যতামূলক): {$kw}\n"
                    . ($topic ? "বিষয়/প্রসঙ্গ: {$topic}\n" : '')
                    . ($title ? "খসড়া শিরোনাম: {$title}\n" : '')
                    . "নিয়ম:\n- প্রতিটি শিরোনামে উপরের কীওয়ার্ডগুলোর অন্তত ২টি স্বাভাবিকভাবে থাকবে।\n"
                    . "- ৮ থেকে ১৪ শব্দ, আকর্ষণীয় কিন্তু ক্লিকবেইট নয়।\n- পাঁচটি শিরোনাম একটি থেকে অন্যটি স্পষ্ট আলাদা হবে।\n"
                    . "শুধু JSON দাও: {\"headlines\":[\"...\",\"...\",\"...\",\"...\",\"...\"]}";
                $json = true;
                $max  = 500;
                break;

            case 'subtitle':
                $user = "নিচের পোস্টের জন্য একটি সাবটাইটেল লেখো (সর্বোচ্চ ১৪০ অক্ষর)।\nশিরোনাম: {$title}\n"
                    . ($kw ? "কীওয়ার্ড: {$kw}\n" : '') . ($topic ? "প্রসঙ্গ: {$topic}\n" : '')
                    . 'শুধু সাবটাইটেলটি দাও, কোনো ব্যাখ্যা বা উদ্ধৃতিচিহ্ন ছাড়া।';
                $max = 150;
                break;

            case 'meta':
                $user = "নিচের পোস্টের জন্য SEO মেটা বর্ণনা লেখো (১২০ থেকে ১৬০ অক্ষর), যাতে মূল কীওয়ার্ড থাকবে।\nশিরোনাম: {$title}\n"
                    . ($kw ? "কীওয়ার্ড: {$kw}\n" : '') . (!empty($c['excerpt']) ? "সারাংশ: {$c['excerpt']}\n" : '')
                    . 'শুধু বর্ণনাটি দাও।';
                $max = 220;
                break;

            case 'article':
                $len = isset($c['length']) ? $c['length'] : 'medium';
                $words = array('short' => '৫০০ থেকে ৮০০', 'medium' => '৮০০ থেকে ১২০০', 'long' => '১৫০০ বা তার বেশি');
                $w = isset($words[$len]) ? $words[$len] : $words['medium'];
                $user = "একটি সম্পূর্ণ বাংলা ব্লগ আর্টিকেল লেখো।\nশিরোনাম: {$title}\n"
                    . ($kw ? "কীওয়ার্ড (স্বাভাবিকভাবে ব্যবহার করবে): {$kw}\n" : '') . ($topic ? "প্রসঙ্গ: {$topic}\n" : '')
                    . "দৈর্ঘ্য: প্রায় {$w} শব্দ।\nনিয়ম:\n- শুরুতে একটি আকর্ষণীয় ভূমিকা (গল্প বা প্রশ্ন দিয়ে)।\n"
                    . "- প্রায় প্রতি ১৫০ শব্দে একটি উপশিরোনাম (<h2>)।\n- ছোট অনুচ্ছেদ, সহজ ভাষা, সক্রিয় বাক্য।\n"
                    . "- বাস্তবসম্মত উদাহরণ দাও, তবে ভুয়া সংখ্যা বা উদ্ধৃতি বানাবে না।\n- শেষে একটি উপসংহার ও পাঠকের জন্য করণীয় (call to action)।\n"
                    . "- শাহীন শিক্ষা পরিবার সম্পর্কিত তথ্য (ফি, তারিখ, ঠিকানা, ফোন, শাখা, ফলাফল, নিয়ম ইত্যাদি) শুধু নিচের 'আমাদের তথ্য' থেকে নেবে। সেখানে না থাকলে অনুমান বা বানিয়ে লিখবে না; ওই জায়গায় ঠিক এভাবে লিখবে: [তথ্য দরকার: কী তথ্য লাগবে]।\n"
                    . "আউটপুট শুধু HTML: <h2>, <h3>, <p>, <ul>, <li>, <strong> ব্যবহার করো। <h1>, <html>, <body> বা কোড-ব্লক দেবে না।";
                $know = SB_Know::context($keywords);
                if ($know !== '') {
                    $user .= "\n\n--- আমাদের তথ্য ---\n" . $know;
                }
                if (!empty($c['research'])) {
                    $user .= "\n\nইন্টারনেটে খুঁজে বিষয়টির সর্বশেষ ও নির্ভরযোগ্য তথ্য (সরকারি/অফিসিয়াল সাইট অগ্রাধিকার) ব্যবহার করো। তারিখ ও সংখ্যা যাচাই করা তথ্য থেকেই দেবে।";
                }
                $max = 3800;
                break;

            case 'custom':
                $ins = isset($c['instruction']) ? trim($c['instruction']) : '';
                if (mb_strlen($ins) < 30) {
                    return new WP_Error('sb_short', 'প্রম্পট বক্সে কী লিখতে হবে তা বিস্তারিত লিখুন (কমপক্ষে ৩০ অক্ষর)।');
                }
                $user = "তুমি পোস্ট মালিকের দেওয়া প্রম্পট (নির্দেশ ও তথ্য) অনুযায়ী একটি বাংলা ব্লগ আর্টিকেল লিখবে।\n"
                    . "কঠোর নিয়ম:\n- প্রম্পটে যা চাওয়া হয়েছে (বিষয়, কাঠামো, সুর, দৈর্ঘ্য, কোন কোন অংশ থাকবে) ঠিক তা-ই মানবে।\n"
                    . "- প্রম্পটে বা নিচের তথ্যে যা আছে, সব নাম, তারিখ, সংখ্যা, অঙ্ক, শর্ত হুবহু ঠিক রাখবে; কিছু বাদ দেবে না, বদলাবে না।\n"
                    . "- প্রম্পটে নেই এমন তথ্য, সংখ্যা বা উদ্ধৃতি বানাবে না; কোনো জরুরি তথ্য না থাকলে ওই জায়গায় ঠিক এভাবে লিখবে: [তথ্য দরকার: কী তথ্য লাগবে]।\n"
                    . "- ইন্টারনেট বা নিজের অনুমান থেকে নতুন তথ্য যোগ করবে না।\n"
                    . "আউটপুট শুধু HTML: <h2>, <h3>, <p>, <ul>, <li>, <strong>, দরকারে <table>। <h1>, <html>, <body> বা কোড-ব্লক দেবে না।\n\n"
                    . ($title ? "শিরোনাম: {$title}\n" : '') . ($kw ? "কীওয়ার্ড (স্বাভাবিকভাবে ব্যবহার করবে): {$kw}\n" : '')
                    . "\n--- পোস্ট মালিকের প্রম্পট ---\n" . mb_substr($ins, 0, 20000);
                $max = 4200;
                break;

            case 'summary':
                $body = isset($c['content']) ? wp_strip_all_tags($c['content']) : '';
                if (mb_strlen($body) < 80) {
                    return new WP_Error('sb_short', 'সামারি বানাতে আগে আর্টিকেলের লেখা দিন বা তৈরি করুন।');
                }
                $user = "নিচের মূল পোস্টটি (আর্টিকেল) পড়ে তার ভিত্তিতে একটি সংক্ষিপ্ত সামারি লেখো (প্রায় ১০০ থেকে ১৫০ শব্দ)। শুধু আর্টিকেলে যা আছে তা-ই সংক্ষেপে বলবে, নতুন তথ্য যোগ করবে না। শুধু সামারি দাও।\n\nশিরোনাম: {$title}\n\n" . mb_substr($body, 0, 6000);
                $max = 500;
                break;

            case 'related':
                $cands = sb_related_candidates(isset($c['post_id']) ? (int) $c['post_id'] : 0, $keywords, $title);
                if (!$cands) {
                    return new WP_Error('sb_none', 'সাইটে এখনো বেছে নেওয়ার মতো অন্য পোস্ট/পেজ নেই।');
                }
                $lines = array();
                foreach ($cands as $r) {
                    $lines[] = $r['id'] . ' | ' . $r['type'] . ' | ' . $r['title'] . ' | ' . $r['snip'];
                }
                $body = isset($c['content']) ? mb_substr(wp_strip_all_tags($c['content']), 0, 1500) : '';
                $user = "নিচের পোস্টের পাশে 'সম্পর্কিত' তালিকা দেখানো হবে। প্রার্থী তালিকা (আইডি | ধরন | শিরোনাম | সংক্ষিপ্ত অংশ) থেকে সবচেয়ে প্রাসঙ্গিক ৩ থেকে ৫টি বেছে নাও, সবচেয়ে প্রাসঙ্গিকটি আগে।\n"
                    . "নিয়ম: শুধু সত্যিই সম্পর্কিত হলে নেবে — মিল না থাকলে কম নাও বা খালি তালিকা দাও, জোর করে ভরবে না। পেজ ও পোস্ট দুটোই নেওয়া যায় (যেমন ক্যাডেট বিষয়ক পোস্টে ক্যাডেট কোচিংয়ের পেজ)। হোম/ব্লগ/নেভিগেশনের মতো সাধারণ পেজ নেবে না। তালিকার বাইরের আইডি দেবে না।\n"
                    . "প্রতিটির জন্য why: পাঠক কেন এটা পড়বে/দেখবে — সহজ বাংলায় ৮ শব্দের মধ্যে।\n"
                    . "শুধু JSON দাও: {\"items\":[{\"id\":123,\"why\":\"...\"}]}\n\n"
                    . "পোস্টের শিরোনাম: {$title}\n" . ($kw ? "কীওয়ার্ড: {$kw}\n" : '') . ($body ? "লেখার শুরু: {$body}\n" : '')
                    . "\nপ্রার্থী তালিকা:\n" . implode("\n", $lines);
                $json = true;
                $max = 500;
                break;

            case 'faq':
                $body = isset($c['content']) ? wp_strip_all_tags($c['content']) : '';
                if (mb_strlen($body) < 80) {
                    return new WP_Error('sb_short', 'FAQ বানাতে আগে আর্টিকেলের লেখা দিন বা তৈরি করুন।');
                }
                $n = isset($c['faq_count']) ? max(3, min(8, (int) $c['faq_count'])) : 5;
                $user = "নিচের আর্টিকেল পড়ে পাঠক যে {$n}টি প্রশ্ন সবচেয়ে বেশি করতে পারে তা প্রশ্ন-উত্তর (FAQ) আকারে লেখো।\n"
                    . "নিয়ম: উত্তর শুধু আর্টিকেলে থাকা তথ্য থেকে দেবে, নতুন তথ্য বা সংখ্যা যোগ করবে না। প্রশ্ন ছোট ও সহজ, উত্তর ১-৩ বাক্যে।\n"
                    . "শুধু JSON দাও: {\"faq\":[{\"q\":\"...\",\"a\":\"...\"}]}\n\nশিরোনাম: {$title}\n\n" . mb_substr($body, 0, 6000);
                $json = true;
                $max = 900;
                break;

            case 'rewrite':
                $src = isset($c['source']) ? trim($c['source']) : '';
                if (mb_strlen($src) < 200) {
                    return new WP_Error('sb_short', 'নতুন করে লেখার জন্য কমপক্ষে ২০০ অক্ষরের লেখা পেস্ট করুন।');
                }
                $user = "নিচের লেখাটি অন্য উৎস থেকে কপি করা। এটি পড়ে বিষয়টি বুঝে সম্পূর্ণ নিজের ভাষায় নতুন করে লেখো।\nনিয়ম:\n"
                    . "- তথ্য, সংখ্যা ও অর্থ ঠিক রাখো, কিন্তু বাক্যগঠন, শব্দচয়ন ও অনুচ্ছেদ সাজানো নতুন করো। মূল লেখার কোনো বাক্য হুবহু বা সামান্য বদলে রাখবে না।\n"
                    . "- মূল লেখায় নেই এমন কোনো তথ্য, সংখ্যা বা উদ্ধৃতি যোগ করবে না।\n"
                    . "- সহজ, প্রচলিত ভাষা; ছোট অনুচ্ছেদ; দরকারে উপশিরোনাম (<h2>)।\n"
                    . ($title ? "- পোস্টের শিরোনাম: {$title}\n" : '') . ($kw ? "- কীওয়ার্ড (স্বাভাবিকভাবে ব্যবহার করবে): {$kw}\n" : '')
                    . "- মূল লেখায় কোনো সূত্র বা প্রতিষ্ঠানের নাম থাকলে লেখার শেষে \"সূত্র\" হিসেবে উল্লেখ করবে।\n"
                    . "আউটপুট শুধু HTML: <h2>, <h3>, <p>, <ul>, <li>, <strong>। <h1>, <html>, <body> বা কোড-ব্লক দেবে না।\n\n--- মূল লেখা ---\n" . mb_substr($src, 0, 12000);
                $max = 3800;
                break;

            case 'edit':
            case 'edit_new':
                $ins = isset($c['instruction']) ? trim($c['instruction']) : '';
                $cur = isset($c['content']) ? trim($c['content']) : '';
                if (mb_strlen($ins) < 4) {
                    return new WP_Error('sb_ins', 'কী বদলাতে চান তা লিখুন।');
                }
                if (mb_strlen($cur) > 16000) {
                    return new WP_Error('sb_long', 'লেখাটি এই সুবিধার জন্য বেশি বড়। অংশে ভাগ করে করুন।');
                }
                if ($feature === 'edit') {
                    if (mb_strlen(wp_strip_all_tags($cur)) < 30) {
                        return new WP_Error('sb_short', 'সংশোধনের জন্য আগে এডিটরে লেখা থাকতে হবে। নতুন লিখতে "নতুন করে লিখুন" বেছে নিন।');
                    }
                    $user = "নিচে একটি ব্লগ পোস্টের বর্তমান লেখা (HTML) আছে। পোস্ট মালিকের নির্দেশ অনুযায়ী এটি সংশোধন করো।\n"
                        . "নিয়ম:\n- শুধু নির্দেশে যা বলা হয়েছে সেটুকু বদলাও; বাকি লেখা, তথ্য, ক্রম ও কাঠামো হুবহু যেমন আছে তেমন রাখো।\n"
                        . "- ছবি (<img>), লিংক (<a>) ও তাদের HTML ট্যাগ কোনোভাবে বদলাবে না বা বাদ দেবে না।\n"
                        . "- নির্দেশে নেই এমন নতুন তথ্য, সংখ্যা বা উদ্ধৃতি বানাবে না; তথ্য লাগলে ওই জায়গায় [তথ্য দরকার: কী তথ্য লাগবে] লিখবে।\n"
                        . "- ভাষা প্রচলিত সহজ বাংলা।\n"
                        . "আউটপুট: সংশোধিত পুরো লেখা শুধু HTML হিসেবে, কোনো ব্যাখ্যা বা কোড-ব্লক ছাড়া।\n\n"
                        . "--- নির্দেশ ---\n{$ins}\n\n--- বর্তমান লেখা ---\n{$cur}";
                } else {
                    $user = "পোস্ট মালিকের নির্দেশ অনুযায়ী পোস্টটি নতুন করে লেখো।\n--- নির্দেশ ---\n{$ins}\n"
                        . ($title ? "শিরোনাম: {$title}\n" : '') . ($kw ? "কীওয়ার্ড (স্বাভাবিকভাবে ব্যবহার করবে): {$kw}\n" : '')
                        . ($cur !== '' ? "\nনিচের বর্তমান লেখাটি শুধু তথ্যের উৎস হিসেবে দেখো, এর কাঠামো মানতে হবে না:\n{$cur}\n" : '')
                        . "\nনিয়ম: নির্দেশ ও বর্তমান লেখায় নেই এমন তথ্য বানাবে না; দরকারে [তথ্য দরকার: …] লিখবে। শাহীন সম্পর্কিত তথ্য শুধু নিচের 'আমাদের তথ্য' থেকে নেবে।\n"
                        . "আউটপুট শুধু HTML: <h2>, <h3>, <p>, <ul>, <li>, <strong>। <h1>, কোড-ব্লক দেবে না।";
                    $know = SB_Know::context($keywords);
                    if ($know !== '') {
                        $user .= "\n\n--- আমাদের তথ্য ---\n" . $know;
                    }
                }
                $max = 3800;
                break;

            case 'rewrite_post':
                $src = isset($c['source']) ? trim($c['source']) : '';
                if (mb_strlen($src) < 200) {
                    return new WP_Error('sb_short', 'কমপক্ষে ২০০ অক্ষরের লেখা পেস্ট করুন।');
                }
                $user = "নিচে অন্য উৎসের একটি সংবাদ/লেখার শিরোনাম ও মূল লেখা দেওয়া আছে। এটি পড়ে বিষয়টি বুঝে শিরোনাম, সংক্ষিপ্ত বর্ণনা ও পুরো লেখা নিজের ভাষায় নতুন করে লেখো।\n"
                    . "কঠোর নিয়ম:\n"
                    . "- সব তথ্য একদম ঠিক রাখবে: নাম, পদবি, প্রতিষ্ঠানের নাম, সংখ্যা, তারিখ, সময়, স্থান, অর্থের অঙ্ক, বক্তার কথার মর্ম। কিছু বাদ দেবে না, নতুন কিছু যোগ করবে না, অনুমান করবে না।\n"
                    . "- ভাষা সম্পূর্ণ বদলাবে: বাক্যগঠন, শব্দচয়ন, অনুচ্ছেদের সাজানো নতুন হবে; মূল লেখার কোনো বাক্য হুবহু বা সামান্য বদলে রাখবে না। সহজ, প্রচলিত ভাষা।\n"
                    . "- সরাসরি উদ্ধৃতি (বক্তার মুখের কথা) থাকলে তার অর্থ ঠিক রেখে নিজের ভাষায় পরোক্ষ উক্তিতে লিখবে।\n"
                    . "- নতুন শিরোনাম আকর্ষণীয় কিন্তু ক্লিকবেইট নয়" . ($kw ? ", এবং এই কীওয়ার্ডগুলো স্বাভাবিকভাবে থাকবে: {$kw}" : '') . "।\n"
                    . "- সংক্ষিপ্ত বর্ণনা (excerpt) ২ থেকে ৩ লাইনের, ১৬০ অক্ষরের মধ্যে।\n"
                    . "- article অংশ HTML: <h2>, <p>, <ul>, <li>, <strong> ব্যবহার করো। <h1>, কোড-ব্লক দেবে না।\n"
                    . "শুধু JSON দাও: {\"headline\":\"...\",\"excerpt\":\"...\",\"article\":\"...\"}\n\n"
                    . "--- মূল শিরোনাম ---\n{$title}\n\n--- মূল লেখা ---\n" . mb_substr($src, 0, 12000);
                $json = true;
                $max = 4200;
                break;

            default:
                return new WP_Error('sb_feature', 'অজানা ফিচার।');
        }

        $model = sb_model_for($feature);
        $body = array(
            'model'    => $model,
            'messages' => array(
                array('role' => 'system', 'content' => self::system_prompt()),
                array('role' => 'user', 'content' => $user),
            ),
        );
        if (preg_match('/^(gpt-5|o\d)/i', $model)) {
            // Reasoning-style models: no temperature, and thinking tokens count against the limit.
            $body['max_completion_tokens'] = $max * 3;
        } else {
            $body['temperature'] = 0.7;
            $body['max_tokens'] = $max;
        }
        if ($json) {
            $body['response_format'] = array('type' => 'json_object');
        }

        if ($feature === 'article' && !empty($c['research'])) {
            $r = self::research_article($user, $max);
            if (!is_wp_error($r)) {
                return $r;
            }
            // Web search not available for this key/model: fall back to a normal article (no extra charge).
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(180); // phpcs:ignore
        }
        $resp = wp_remote_post(self::ENDPOINT, array(
            'timeout' => 150,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode($body),
        ));
        if (is_wp_error($resp)) {
            return new WP_Error('sb_http', 'OpenAI-তে সংযোগ হয়নি: ' . $resp->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
            if ($code === 401) {
                $msg = 'API কী ভুল বা বাতিল।';
            } elseif ($code === 429) {
                $msg = 'OpenAI ব্যালেন্স শেষ বা অনুরোধ বেশি হয়েছে। (' . $msg . ')';
            }
            return new WP_Error('sb_api', $msg);
        }
        $text = isset($data['choices'][0]['message']['content']) ? trim($data['choices'][0]['message']['content']) : '';
        if ($text === '') {
            return new WP_Error('sb_empty', 'AI কোনো লেখা দেয়নি। আবার চেষ্টা করুন।');
        }

        if ($feature === 'headlines') {
            return self::parse_headlines($text, $keywords);
        }
        if ($feature === 'related') {
            $j = json_decode($text, true);
            $ok = array();
            foreach (sb_related_candidates(isset($c['post_id']) ? (int) $c['post_id'] : 0, $keywords, $title) as $r) {
                $ok[$r['id']] = $r;
            }
            $out = array();
            if (is_array($j) && !empty($j['items']) && is_array($j['items'])) {
                foreach ($j['items'] as $row) {
                    $rid = isset($row['id']) ? (int) $row['id'] : 0;
                    if ($rid && isset($ok[$rid]) && count($out) < 5) {
                        $out[$rid] = array('id' => $rid, 'title' => $ok[$rid]['title'], 'type' => $ok[$rid]['type'],
                            'why' => sanitize_text_field(isset($row['why']) ? $row['why'] : ''));
                    }
                }
            }
            return array('items' => array_values($out));
        }
        if ($feature === 'faq') {
            $j = json_decode($text, true);
            $out = array();
            if (is_array($j) && !empty($j['faq']) && is_array($j['faq'])) {
                foreach (array_slice($j['faq'], 0, 8) as $row) {
                    $q = sanitize_text_field(isset($row['q']) ? $row['q'] : '');
                    $a = sanitize_textarea_field(isset($row['a']) ? $row['a'] : '');
                    if ($q !== '' && $a !== '') {
                        $out[] = array('q' => $q, 'a' => $a);
                    }
                }
            }
            if (!$out) {
                return new WP_Error('sb_json', 'AI-এর উত্তর পড়া যায়নি। আবার চেষ্টা করুন।');
            }
            return array('faq' => $out);
        }
        if ($feature === 'rewrite_post') {
            $j = json_decode($text, true);
            if (!is_array($j) || empty($j['article'])) {
                return new WP_Error('sb_json', 'AI-এর উত্তর পড়া যায়নি। আবার চেষ্টা করুন।');
            }
            return array(
                'headline' => sanitize_text_field(isset($j['headline']) ? $j['headline'] : ''),
                'excerpt'  => sanitize_textarea_field(isset($j['excerpt']) ? $j['excerpt'] : ''),
                'text'     => wp_kses_post($j['article']),
            );
        }
        if (in_array($feature, array('article', 'custom', 'rewrite', 'edit', 'edit_new'), true)) {
            $text = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text);
            return array('text' => wp_kses_post($text));
        }
        return array('text' => trim(wp_strip_all_tags($text), " \t\n\r\0\x0B\"'“”"));
    }

    /** Article using OpenAI web search (Responses API). */
    protected static function research_article($user, $max) {
        if (function_exists('set_time_limit')) {
            @set_time_limit(240); // phpcs:ignore
        }
        $resp = wp_remote_post('https://api.openai.com/v1/responses', array(
            'timeout' => 200,
            'headers' => array('Authorization' => 'Bearer ' . sb_get('api_key'), 'Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'model' => sb_model_for('article'),
                'input' => array(
                    array('role' => 'system', 'content' => self::system_prompt()),
                    array('role' => 'user', 'content' => $user),
                ),
                'tools' => array(array('type' => 'web_search_preview')),
                'max_output_tokens' => $max + 1500,
            )),
        ));
        if (is_wp_error($resp)) {
            return $resp;
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (wp_remote_retrieve_response_code($resp) !== 200 || !is_array($data) || empty($data['output'])) {
            return new WP_Error('sb_noresearch', 'ওয়েব সার্চ পাওয়া যায়নি।');
        }
        $text = '';
        $sources = array();
        foreach ($data['output'] as $item) {
            if (isset($item['type']) && $item['type'] === 'message' && !empty($item['content'])) {
                foreach ($item['content'] as $c) {
                    if (isset($c['text'])) {
                        $text .= $c['text'];
                    }
                    if (!empty($c['annotations'])) {
                        foreach ($c['annotations'] as $a) {
                            if (isset($a['url']) && !isset($sources[$a['url']])) {
                                $sources[$a['url']] = isset($a['title']) ? sanitize_text_field($a['title']) : $a['url'];
                            }
                        }
                    }
                }
            }
        }
        $text = trim($text);
        if ($text === '') {
            return new WP_Error('sb_empty', 'AI কোনো লেখা দেয়নি।');
        }
        $text = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text);
        $list = array();
        foreach ($sources as $u => $t) {
            $list[] = array('url' => esc_url_raw($u), 'title' => $t);
        }
        return array('text' => wp_kses_post($text), 'sources' => array_slice($list, 0, 8), 'researched' => true);
    }

    protected static function parse_headlines($text, $keywords) {
        $list = array();
        $j = json_decode($text, true);
        if (is_array($j) && isset($j['headlines']) && is_array($j['headlines'])) {
            $list = $j['headlines'];
        } else {
            foreach (preg_split('/\R/u', $text) as $line) {
                $line = trim(preg_replace('/^\s*[\d০-৯]+[\.\)\-]\s*/u', '', $line), " \t\"'“”-*");
                if ($line !== '') {
                    $list[] = $line;
                }
            }
        }
        $out = array();
        foreach (array_slice($list, 0, 5) as $h) {
            $h = sanitize_text_field((string) $h);
            if ($h === '') {
                continue;
            }
            $used = 0;
            foreach ($keywords as $k) {
                if ($k !== '' && mb_stripos($h, $k) !== false) {
                    $used++;
                }
            }
            $out[] = array('text' => $h, 'keywords_used' => $used);
        }
        if (!$out) {
            return new WP_Error('sb_empty', 'শিরোনাম পাওয়া যায়নি। আবার চেষ্টা করুন।');
        }
        return array('headlines' => $out);
    }
}
