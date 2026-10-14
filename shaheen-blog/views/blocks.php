<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap sba">
    <h1>📚 ব্লক লাইব্রেরি</h1>
    <p class="sba-muted">এখানে নাম দিয়ে ব্লক বানিয়ে রাখুন। প্রতিটি পোস্ট তৈরির সময় (ওয়িজার্ডের ধাপ ৩-এ) যে ব্লক চান টিক দিয়ে বেছে নেবেন, তাই ভিন্ন পোস্টে ভিন্ন ব্লক দেওয়া যাবে। "সব পোস্টে অটো" টিক দিলে ব্লকটি প্রতিটি পোস্টে নিজে নিজেই আসবে। ক্রেডিট লাগে না।</p>
    <div class="sba-card sba-form">
        <div id="gblk-list"></div>
        <p><button type="button" class="button" id="gblk-add">+ ব্লক যোগ করুন</button>
           <button type="button" class="button button-primary" id="gblk-save">💾 সেভ করুন</button>
           <span id="msg" class="sba-msg"></span></p>
    </div>
</div>
