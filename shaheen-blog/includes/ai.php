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

    protected static function system_prompt($mem = true) {
        $memory = ($mem && function_exists('sb_memory_context')) ? sb_memory_context() : '';
        $style = trim((string) sb_get('style_note'));
        return 'তুমি শাহীন শিক্ষা পরিবারের (বাংলাদেশের একটি শিক্ষা প্রতিষ্ঠান-নেটওয়ার্ক, স্কুল ও ক্যাডেট কোচিং) ব্লগের জন্য একজন অভিজ্ঞ বাংলা কনটেন্ট লেখক। '
            . 'শিক্ষার্থী, অভিভাবক ও শিক্ষকরা পাঠক। কোনো মিথ্যা তথ্য, ভুয়া পরিসংখ্যান বা ভুয়া উদ্ধৃতি বানাবে না। নিশ্চিত না হলে সাধারণ ও নিরাপদ কথা লিখবে। '
            . 'ভাষা: শুদ্ধ বানানে, ' . ($style !== '' ? $style : 'প্রচলিত সহজ বাংলায়') . ' '
            . 'লেখার মান: মানুষের মতো স্বাভাবিক ছন্দে লিখবে। বাক্যের দৈর্ঘ্যে বৈচিত্র্য রাখবে (ছোট ও মাঝারি মিলিয়ে), একই শুরু বা একই কাঠামো বারবার ব্যবহার করবে না, '
            . '"সংক্ষেপে বলতে গেলে", "উপসংহারে বলা যায়", "এক কথায়" জাতীয় গতানুগতিক ফাঁকা বাক্য এড়াবে। বাংলাদেশের বাস্তব প্রেক্ষাপট, নির্দিষ্ট উদাহরণ ও ব্যবহারিক পরামর্শ দেবে।'
            . ($mem ? "\n\nসব লেখায় মানার স্থায়ী নিয়ম: (০) প্রতিটি লেখা শাহীন শিক্ষা পরিবার (Shaheen Education Family) পেজ/ওয়েবসাইটেই প্রকাশ হবে, তাই লেখা শাহীনের নিজের ব্লগ হিসেবে লিখবে, বাইরের কেউ শাহীন সম্পর্কে বলছে এমনভাবে নয়। ভাষা হবে সহজ, চলিত ও প্রচলিত বাংলা, যা অভিভাবক ও ছাত্র-ছাত্রীরা সহজে বোঝে। কঠিন, সাধু বা বইয়ের শব্দ ও অপ্রচলিত শব্দ লিখবে না; কঠিন শব্দ লাগলে তার সহজ প্রচলিত শব্দ ব্যবহার করবে। (১) শাহীন শিক্ষা পরিবারের কোচিং, ফি, শাখা, ফলাফল, শিক্ষক বা সুবিধা সম্পর্কে শুধু দেওয়া তথ্য/উৎস থেকে লিখবে; 'সাধারণত', 'প্রায়ই', 'থাকলে', 'সম্ভবত' দিয়ে অনুমানে কিছু লিখবে না, যেকোনো কোচিংয়ের সাধারণ কথা শাহীনের নামে চালাবে না। (২) 'PDF/ওয়েবসাইটে দেখুন' বলে তথ্য এড়াবে না, জানা তথ্য নিজে সম্পূর্ণ লিখবে; না জানলে [তথ্য দরকার: …]। (৩) লিংক, ফোন, তারিখ, ফি বানাবে না। (৪) মালিকের নির্দেশ-বাক্য লেখায় কপি করবে না; ফাঁকা ভরাট বা গতানুগতিক সাধারণ কথা এড়াবে, প্রতি অনুচ্ছেদে নির্দিষ্ট তথ্য রাখবে।" : '')
            . ($memory !== '' ? "\n\n" . $memory : '');
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
                $tier = isset($c['tier']) ? (int) $c['tier'] : 1;
                $range = array(1 => '৮০০ থেকে ১০০০', 2 => '১০০০ থেকে ১৫০০', 3 => '১৫০০ বা তার বেশি');
                $user = "তুমি একটি ওয়েবসাইটের ব্লগ পোস্টের লেখা তৈরি করছো। পোস্ট মালিকের প্রম্পট (নির্দেশ ও তথ্য) অনুযায়ী বাংলা আর্টিকেল লিখবে।\n"
                    . "তথ্যের অগ্রাধিকার: (১) সংযুক্ত PDF/ছবি ও 'ওয়েবসাইট থেকে নেওয়া লেখা' ও প্রম্পটে দেওয়া তথ্য, আগে এগুলো সম্পূর্ণ পড়ে নেবে; (২) তারপর অন্য কিছু। এগুলোতে যে তথ্য আছে (তারিখ, ফি, যোগ্যতা, বয়স, সিলেবাস, নিয়ম) তা হুবহু ব্যবহার করবে এবং কোথাও [তথ্য দরকার] লিখবে না।\n"
                    . "কাজের পদ্ধতি (এই ক্রমে করবে):\n"
                    . "ক) সংযুক্ত প্রতিটি PDF-এর প্রতিটি পাতা ও ছবি শুরু থেকে শেষ পর্যন্ত পড়বে (টেবিল, বাক্স, লাল বা মোটা লেখা, পাদটীকা সহ)। প্রতিটি তারিখ, সময়, টাকার অঙ্ক, নম্বর, বয়স, উচ্চতা, শর্ত, তালিকা ও যোগাযোগ নম্বর আলাদা করে খুঁজে নেবে।\n"
                    . "খ) তারপর লিখবে, কোনো তথ্য বাদ না দিয়ে। কাঠামো (বিষয় অনুযায়ী দরকারি অংশ রাখবে): শুরুতে ২-৩ লাইনের ভূমিকা ও মূল তারিখ; গুরুত্বপূর্ণ তারিখের টেবিল; কারা আবেদন করতে পারবে (যোগ্যতা, বয়স, শর্ত); কারা অযোগ্য; পরীক্ষা পদ্ধতি ও নম্বর বণ্টনের টেবিল; আবেদনের ধাপ (ক্রমিক তালিকা); ফি ও পরিশোধের মাধ্যম; কোন কাগজ লাগবে ও কীভাবে জমা দেবে; পরীক্ষার দিনের নিয়ম; সাবধানতা (যেসব ভুলে আবেদন বাতিল হয়); শাহীন শিক্ষা পরিবারের অংশ; শেষ কথা।\n"
                    . "গ) ভাষা হবে সরল, চলিত, প্রচলিত; কঠিন শব্দ নয়। ছোট বাক্য, ছোট অনুচ্ছেদ। অভিভাবক ও শিক্ষার্থী যেন লেখাটা পড়েই সব বুঝে কাজ করতে পারে।\n"
                    . "ঘ) শাহীনের অংশে শুধু দেওয়া তথ্য ও 'আমাদের তথ্য' থেকে লিখবে (কোচিংয়ের ধরন, ফলাফল, হেল্পলাইন)। অনুমানে কোনো সুবিধা, ক্লাসের ধরন বা ফিজিক্যাল/মেডিকেল প্রস্তুতির কথা লিখবে না।\n"
                    . "ঙ) লেখা শেষ করার আগে প্রতিটি সংখ্যা ও তারিখ উৎসের সাথে আবার মিলিয়ে নেবে; উৎসে যা আছে তার উল্টো বা বাড়তি কিছু (যেমন 'সর্বোচ্চ'-কে 'সর্বনিম্ন') লিখবে না। শব্দসংখ্যার লক্ষ্য মানবে, তবে তার জন্য কোনো তথ্য বাদ দেবে না।\n"
                    . "কঠোর নিয়ম:\n- আর্টিকেল ঠিক " . (isset($range[$tier]) ? $range[$tier] : $range[1]) . " শব্দের হবে।\n"
                    . "- সব নাম, তারিখ, সংখ্যা, অঙ্ক, শর্ত উৎসের সাথে ঠিক রাখবে; কিছু বানাবে না।\n"
                    . "- কোনো জরুরি তথ্য কোথাও না পেলে শুধুমাত্র ঠিক এই ছাঁচে লিখবে: [তথ্য দরকার: কী তথ্য লাগবে]। অন্য কোনো ধরনের প্লেসহোল্ডার (যেমন 'এখানে যুক্ত করুন') লিখবে না।\n"
                    . "- 'article' অংশে শুধু পাঠকের পড়ার মতো মূল লেখা থাকবে। এগুলো কখনোই article-এ দেবে না: SEO টাইটেল, কীওয়ার্ড তালিকা, মেটা বর্ণনা, URL স্লাগ, ছবির alt text, ফাইলের নাম, WordPress বা প্রকাশনার পরামর্শ, লেখকের নোট, 'চূড়ান্ত নোট/সতর্কতা', সারসংক্ষেপ ও FAQ (এগুলো ওয়েবসাইট আলাদাভাবে বানায়)।\n"
                    . "- সবচেয়ে গুরুত্বপূর্ণ: যে তথ্য সংযুক্ত PDF/ছবি/ওয়েবসাইট/প্রম্পটে আছে, তা পাঠককে নিজে পুরোটা লিখে জানাবে। কখনো এভাবে এড়িয়ে যাবে না: 'সংযুক্ত PDF-এ বিস্তারিত', 'ওয়েবসাইটে বলা আছে', 'সংশ্লিষ্ট নির্দেশনা দেখুন', 'বিস্তারিত জানতে PDF দেখুন'। যেমন বয়সসীমা হলে সর্বনিম্ন ও সর্বোচ্চ বয়স এবং কোন তারিখ ধরে গণনা তা সংখ্যাসহ লিখবে; যোগ্যতা হলে নির্দিষ্ট শ্রেণি, শর্ত, GPA/ফলাফল সহ লিখবে। কোনো তথ্য ফাইলে স্পষ্ট পড়তে না পারলে অনুমান না করে ঠিক সেখানেই [তথ্য দরকার: …] লিখবে।\n"
                    . "- তুমি পাঠকের জন্য লিখছো, মালিকের সাথে কথা বলছো না। মালিকের প্রম্পটের নির্দেশ-বাক্য ('লিখে দিতে পারেন', 'গুছিয়ে দিবে', 'ডাউনলোড বাটন থাকবে' ইত্যাদি) আর্টিকেলে কপি করবে না। 'এই তথ্যগুলো লিখে দিতে পারেন' ধরনের কথা আর্টিকেলে আসবে না।\n"
                    . "- সূত্র হিসেবে ব্র্যাকেটে লিংক বা ([site](url)) ধরনের উদ্ধৃতি লিখবে না। দরকার হলে বাক্যের ভেতরে স্বাভাবিকভাবে <a href=\"...\">লিংক</a> দেবে, শুধু উৎসে থাকা আসল ঠিকানা দিয়ে।\n"
                    . "- article অংশ শুধু HTML: <h2>, <h3>, <p>, <ul>, <li>, <strong>, <a>, দরকারে <table>। Markdown (**, ##, - ) ব্যবহার করবে না। <h1>, <html>, <body> বা কোড-ব্লক দেবে না।\n"
                    . "- আউটপুট শুধু একটি JSON অবজেক্ট: {\"article\":\"HTML লেখা\",\"meta\":\"১৫০-১৬০ অক্ষরের SEO মেটা বর্ণনা, মূল কীওয়ার্ডসহ\",\"excerpt\":\"২-৩ লাইনের সংক্ষিপ্ত বিবরণ\",\"facts\":[{\"label\":\"তথ্যের নাম (যেমন: আবেদনের শেষ তারিখ)\",\"value\":\"আর্টিকেলে যেভাবে লিখেছো ঠিক সেই মান\"}]}। facts-এ আর্টিকেলের গুরুত্বপূর্ণ তথ্য (তারিখ, ফি, বয়স, যোগ্যতা, ফোন, ঠিকানা, লিংক) সর্বোচ্চ ১৫টি দাও; value আর্টিকেলের লেখার সাথে হুবহু মিলবে। JSON-এর বাইরে কিছু লিখবে না।\n\n"
                    . ($title ? "শিরোনাম: {$title}\n" : '') . ($kw ? "কীওয়ার্ড (স্বাভাবিকভাবে ব্যবহার করবে): {$kw}\n" : '')
                    . "\n--- পোস্ট মালিকের প্রম্পট ---\n" . mb_substr($ins, 0, 20000)
                    . (!empty($c['web']) ? "\n\n--- ওয়েবসাইট থেকে নেওয়া লেখা ---" . $c['web'] : '');
                $know = SB_Know::context($keywords);
                if ($know !== '') {
                    $user .= "\n\n--- আমাদের (শাহীন শিক্ষা পরিবারের) তথ্য: শাহীন সম্পর্কিত ফলাফল, শাখা, কোচিং, ফি, যোগাযোগ শুধু এখান থেকে ও প্রম্পট থেকে নেবে ---\n" . $know;
                }
                if (!empty($c['research'])) {
                    $user .= "\n\nইন্টারনেটে খুঁজে বিষয়টির সর্বশেষ ও সঠিক খবর/তথ্য (সরকারি ও অফিসিয়াল সাইট অগ্রাধিকার) যাচাই করে লেখায় ব্যবহার করবে। তবে প্রম্পট, ওয়েবসাইট, PDF বা ছবির তথ্য সবার আগে; ইন্টারনেটের তথ্য এগুলোর সাথে না মিললে সেটা না লিখে [তথ্য দরকার: …] লিখবে। যাচাই করা যায়নি এমন তারিখ বা সংখ্যা দেবে না।";
                }
                if (!empty($c['parts'])) {
                    $user .= "\n\n(এই বার্তার সাথে সংযুক্ত PDF/ছবিগুলোও তথ্যের উৎস।)";
                    $content = array_merge(array(array('type' => 'text', 'text' => $user)), $c['parts']);
                }
                $max = 6000;
                break;

            case 'learn':
                $user = "নিচে একটি AI-লেখা খসড়া এবং মালিকের সংশোধন করে প্রকাশযোগ্য করা চূড়ান্ত লেখা দেওয়া আছে। দুটি মিলিয়ে দেখো মালিক কী কী বদলেছেন।\n"
                    . "ভবিষ্যতের সব লেখায় কাজে লাগবে এমন স্থায়ী শিক্ষা বের করো:\n"
                    . "- rules: লেখার ধরন, ভাষা, শব্দচয়ন, কাঠামো, যা এড়াতে বা সবসময় করতে হবে (প্রতিটি একটি বাক্যে, নির্দেশের ভঙ্গিতে)।\n"
                    . "- facts: শাহীন শিক্ষা পরিবারের স্থায়ী সঠিক তথ্য (ফোন, ঠিকানা, শাখা, ফি, ফলাফল, লিংক) যা মালিক ঠিক করেছেন বা যোগ করেছেন, 'নাম: মান' ছাঁচে।\n"
                    . "শুধু এই এক লেখার নির্দিষ্ট ঘটনা/তারিখ বা শুধু বানান-টাইপো ঠিক করা শেখাবে না। কিছু শেখার না থাকলে খালি তালিকা দাও। সর্বোচ্চ ৫টি rule ও ৬টি fact।\n"
                    . "- মালিকের নির্দেশ/অভিযোগও দেওয়া থাকতে পারে (প্রম্পট বা সংশোধনের নির্দেশ)। তাতে ভবিষ্যতের সব লেখায় খাটে এমন পছন্দ, অভিযোগ বা সমস্যা (যেমন 'অনুমানে লিখবে না', 'ফোন শেষে দেবে') থাকলে তা rule করো; শুধু এই একটি পোস্টের বিষয়-নির্দিষ্ট নির্দেশ rule করবে না। মালিকের নিজের লেখা সঠিক তথ্য (ফোন, ফি, লিংক) fact করো।\n"
                    . "শুধু JSON: {\"rules\":[\"...\"],\"facts\":[\"নাম: মান\"]}\n\n"
                    . (!empty($c['ins']) ? "--- মালিকের নির্দেশ/প্রম্পট ---\n" . mb_substr($c['ins'], 0, 3000) . "\n\n" : '')
                    . "--- AI-র খসড়া ---\n" . mb_substr($c['draft'], 0, 6000)
                    . "\n\n--- মালিকের চূড়ান্ত লেখা ---\n" . mb_substr($c['final'], 0, 6000);
                $json = true;
                $max = 700;
                $sys_nomem = true;
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

        $model = (!empty($c['model']) && in_array($c['model'], sb_allowed_models(), true)) ? $c['model'] : sb_model_for($feature);
        $body = array(
            'model'    => $model,
            'messages' => array(
                array('role' => 'system', 'content' => self::system_prompt(empty($sys_nomem))),
                array('role' => 'user', 'content' => isset($content) ? $content : $user),
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

        if ($feature === 'custom' && !empty($c['bg'])) {
            return self::bg_start($model, $user, !empty($c['parts']) ? $c['parts'] : array(), $max, !empty($c['research']));
        }
        if (in_array($feature, array('article', 'custom'), true) && !empty($c['research'])) {
            $r = self::research_article($user, $max, ($feature === 'custom' && !empty($c['parts'])) ? $c['parts'] : array(), $model);
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
        if ($feature === 'learn') {
            $j = json_decode($text, true);
            $o = array('rules' => array(), 'facts' => array());
            foreach (array('rules', 'facts') as $k) {
                foreach (is_array($j) && !empty($j[$k]) && is_array($j[$k]) ? array_slice($j[$k], 0, 6) : array() as $t) {
                    $t = is_string($t) ? sanitize_text_field($t) : '';
                    if (mb_strlen($t) >= 8) {
                        $o[$k][] = $t;
                    }
                }
            }
            return $o;
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
    /** Responses-API input for a user text plus optional PDF/image parts. */
    protected static function bg_input($user, $parts) {
        $in = array(array('type' => 'input_text', 'text' => $user));
        foreach ($parts as $p) {
            $in[] = $p['type'] === 'file'
                ? array('type' => 'input_file', 'filename' => $p['file']['filename'], 'file_data' => $p['file']['file_data'])
                : array('type' => 'input_image', 'image_url' => $p['image_url']['url']);
        }
        return $in;
    }

    /** Starts a long generation in OpenAI's background mode: returns at once with an id (no long-open request, so no 504). */
    public static function bg_start($model, $user, $parts, $max, $research) {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120); // phpcs:ignore
        }
        $body = array(
            'model' => $model,
            'input' => array(
                array('role' => 'system', 'content' => self::system_prompt()),
                array('role' => 'user', 'content' => $parts ? self::bg_input($user, $parts) : $user),
            ),
            'background' => true,
            'max_output_tokens' => $max * 2 + 2000,
        );
        if (preg_match('/^(gpt-5|o\d)/i', $model)) {
            $body['reasoning'] = array('effort' => 'low');
        }
        if ($research) {
            $body['tools'] = array(array('type' => 'web_search_preview'));
        }
        $resp = wp_remote_post('https://api.openai.com/v1/responses', array(
            'timeout' => 100,
            'headers' => array('Authorization' => 'Bearer ' . sb_get('api_key'), 'Content-Type' => 'application/json'),
            'body'    => wp_json_encode($body),
        ));
        if (is_wp_error($resp)) {
            return new WP_Error('sb_http', 'OpenAI-তে সংযোগ হয়নি: ' . $resp->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200 || empty($data['id'])) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
            if ($code === 401) {
                $msg = 'API কী ভুল বা বাতিল।';
            } elseif ($code === 429) {
                $msg = 'OpenAI ব্যালেন্স শেষ বা অনুরোধ বেশি হয়েছে। (' . $msg . ')';
            }
            return new WP_Error('sb_api', $msg);
        }
        return array('bg_id' => $data['id']);
    }

    /** Checks a background generation: array(state => running|done|error, result|message). */
    public static function bg_fetch($id) {
        $resp = wp_remote_get('https://api.openai.com/v1/responses/' . rawurlencode($id), array(
            'timeout' => 30,
            'headers' => array('Authorization' => 'Bearer ' . sb_get('api_key')),
        ));
        if (is_wp_error($resp)) {
            return array('state' => 'running');   // transient network hiccup: ask again
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (wp_remote_retrieve_response_code($resp) !== 200 || !is_array($data)) {
            return array('state' => 'error', 'message' => isset($data['error']['message']) ? $data['error']['message'] : 'OpenAI থেকে উত্তর পাওয়া যায়নি।');
        }
        $st = isset($data['status']) ? $data['status'] : '';
        if ($st === 'queued' || $st === 'in_progress') {
            return array('state' => 'running');
        }
        if ($st !== 'completed') {
            $why = isset($data['error']['message']) ? $data['error']['message'] : (isset($data['incomplete_details']['reason']) ? $data['incomplete_details']['reason'] : $st);
            return array('state' => 'error', 'message' => 'AI লেখা শেষ করতে পারেনি (' . $why . ')। ক্রেডিট কাটা হয়নি।');
        }
        $text = '';
        $sources = array();
        $searched = false;
        foreach (isset($data['output']) && is_array($data['output']) ? $data['output'] : array() as $item) {
            if (isset($item['type']) && $item['type'] === 'web_search_call') {
                $searched = true;
            }
            if (isset($item['type']) && $item['type'] === 'message' && !empty($item['content'])) {
                foreach ($item['content'] as $c) {
                    if (isset($c['text'])) {
                        $text .= $c['text'];
                    }
                    foreach (!empty($c['annotations']) ? $c['annotations'] : array() as $a) {
                        if (isset($a['url']) && !isset($sources[$a['url']])) {
                            $sources[$a['url']] = isset($a['title']) ? sanitize_text_field($a['title']) : $a['url'];
                        }
                    }
                }
            }
        }
        $text = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($text)));
        if ($text === '') {
            return array('state' => 'error', 'message' => 'AI কোনো লেখা দেয়নি। ক্রেডিট কাটা হয়নি।');
        }
        $list = array();
        foreach ($sources as $u => $t) {
            $list[] = array('url' => esc_url_raw($u), 'title' => $t);
        }
        return array('state' => 'done', 'result' => array('text' => $text, 'sources' => array_slice($list, 0, 8), 'researched' => $searched));
    }

    public static function bg_cancel($id) {
        wp_remote_post('https://api.openai.com/v1/responses/' . rawurlencode($id) . '/cancel', array('timeout' => 15, 'headers' => array('Authorization' => 'Bearer ' . sb_get('api_key'))));
    }

    protected static function research_article($user, $max, $parts = array(), $model = '') {
        if ($parts) {
            $in = array(array('type' => 'input_text', 'text' => $user));
            foreach ($parts as $p) {
                $in[] = $p['type'] === 'file'
                    ? array('type' => 'input_file', 'filename' => $p['file']['filename'], 'file_data' => $p['file']['file_data'])
                    : array('type' => 'input_image', 'image_url' => $p['image_url']['url']);
            }
            $user = $in;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(240); // phpcs:ignore
        }
        $resp = wp_remote_post('https://api.openai.com/v1/responses', array(
            'timeout' => 200,
            'headers' => array('Authorization' => 'Bearer ' . sb_get('api_key'), 'Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'model' => $model !== '' ? $model : sb_model_for('article'),
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
