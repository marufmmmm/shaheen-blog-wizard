<?php
/**
 * Runs only when the plugin is deleted from the Plugins screen.
 * Removes settings, caches, scheduled jobs and credit data. Posts, phone models and categories are kept.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
global $wpdb;
foreach (array('sb_settings', 'sb_social', 'sb_social_items', 'sb_global_blocks', 'sb_phones_cfg', 'sb_phones_log', 'sb_phones_page_id', 'sb_blog_page_id', 'sb_demo_done') as $opt) {
    delete_option($opt);
}
wp_clear_scheduled_hook('sb_phones_cron');
wp_clear_scheduled_hook('csa_weekly');
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_sb\_%' OR option_name LIKE '\_transient\_timeout\_sb\_%' OR option_name LIKE 'sb\_ytid\_%'");
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'sb\_credit%' OR meta_key LIKE '\_sb\_credit%'");

// USD billing: vouchers and the usage ledger.
delete_option('sb_vouchers');
delete_option('sb_usage_db');
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sb_usage");
wp_clear_scheduled_hook('sb_billing_tick');
