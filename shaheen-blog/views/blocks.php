<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap sba">
    <h1>📢 সব পোস্টের ব্লক</h1>
    <p class="sba-muted">এখানে যে ব্লক বানাবেন তা প্রতিটি পোস্টের ভেতরে (শুরু/মাঝখান/শেষে) দেখাবে। কোনো পোস্টে আলাদা ব্লক চাইলে ওয়িজার্ডের ধাপ ৩-এ দিন। ক্রেডিট লাগে না।</p>
    <div class="sba-card sba-form">
        <div id="gblk-list"></div>
        <p><button type="button" class="button" id="gblk-add">+ ব্লক যোগ করুন</button>
           <button type="button" class="button button-primary" id="gblk-save">💾 সেভ করুন</button>
           <span id="msg" class="sba-msg"></span></p>
    </div>
</div>
