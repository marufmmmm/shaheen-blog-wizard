# Shaheen Blog (WordPress plugin)

Plugin folder: `shaheen-blog/` — zip it (or use `shaheen-blog.zip`) and upload via Plugins → Add New → Upload.

- Blog page built from the original design (`index.html`), shortcode `[shaheen_blog]`, auto-created `/blog/` page
- Single post design + 6 demo posts (removable in Settings)
- 5-step AI post wizard (OpenAI; 2–3 keywords required): headlines 50, subtitle 30, meta 20, article 180, summary 80 credits
- Monthly credits (20,000), reset on subscription date; hook `shaheen_blog_set_subscription()` for payment code later
- Editing posts is free; only AI buttons cost credits
- Admin: dashboard, posts, categories, settings

## v2.1
- Deep research (OpenAI web search) + AI knows Shaheen via settings text and linked website URLs; missing facts are marked `[তথ্য দরকার: …]`
- Related posts/pages sidebar (min 3, manual picks), newspaper coverage items (external links), header-aligned width/gap, randomized read counter (300 start values)

## v2.7.0 — মোবাইল মডেল সেকশন
- শাহীন ব্লগ → 📱 মোবাইল মডেল: ফোনের তালিকা (ব্র্যান্ড, মডেল, মাপ উ×প্র×পু মিমি, ওজন, স্ক্রিন, RAM/স্টোরেজ, ব্যাটারি, চিপসেট)।
- পেজে `[shaheen_phones]` শর্টকোড (সার্চ, ব্র্যান্ড ফিল্টার, ছোট/হালকা ফোন সর্ট)।
- AI ওয়েব সার্চ প্রতি ১৫ দিনে (সেটিংসে বদলানো যায়) নতুন মডেল যোগ করে; ডুপ্লিকেট বাদ, অসম্ভব মাপ বাদ; চাইলে ড্রাফটে রেখে রিভিউ।

## v2.8.0 — সামারি / FAQ নির্বাচন
- ধাপ ৪-এ "সামারি" ও "FAQ" টিক বক্স: টিক দিয়ে আর্টিকেল তৈরি করলে AI আর্টিকেল থেকে বাকি অংশ বানায়; টিক না দিলে বানায়ও না, পোস্টে দেখায়ও না। হাতেও লেখা যায়।

## v2.9.0 — স্মার্ট সম্পর্কিত পোস্ট/পেজ
- AI সাইটের পোস্ট ও পেজ থেকে প্রাসঙ্গিকগুলো বেছে দেয়, সাথে পাঠকের জন্য এক লাইনের কারণ। (৩০ ক্রেডিট; কিছু না মিললে ক্রেডিট কাটে না)
- সাধারণ পেজ (ব্লগ, Sample Page ইত্যাদি) আর জোর করে দেখায় না।

## v2.11.0
- সম্পর্কিত পোস্ট: AI ছাড়া বিনামূল্যে কীওয়ার্ড-মিল; না মিললে লুকানো।
- সংশোধনের গতিশীল ঘর (＋), ক্রেডিট ১–৫টি = ৩০, ৬–১০টি = ৪৫।
- রেফারেন্স লিংক (লেখার ভেতরে হাইপারলিংক + সূত্র তালিকা), ডাউনলোড বাটন, PDF পড়ার ঘর (ফোনে-সহ), ভিডিও/সোশ্যাল পোস্ট এমবেড — প্রতিটির জায়গা বাছা যায়।
- প্রতি পোস্টে সোশ্যাল বক্সের জায়গা বাছা যায়।
