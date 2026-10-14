<?php
/**
 * Helper functions
 */

/**
 * Get plugin setting
 */
function shaheen_blog_get_setting($key, $default = '') {
    return Shaheen_Blog_Wizard_Database::get_setting($key, $default);
}

/**
 * Update plugin setting
 */
function shaheen_blog_update_setting($key, $value) {
    return Shaheen_Blog_Wizard_Database::update_setting($key, $value);
}

/**
 * Get user's available credits
 */
function shaheen_blog_get_available_credits($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    return Shaheen_Blog_Wizard_Credit_System::get_available_credits($user_id);
}

/**
 * Get feature cost
 */
function shaheen_blog_get_feature_cost($feature_name) {
    return Shaheen_Blog_Wizard_Credit_System::get_feature_cost($feature_name);
}

/**
 * Get total cost for multiple features
 */
function shaheen_blog_calculate_total_cost($features) {
    return Shaheen_Blog_Wizard_Credit_System::calculate_total_cost($features);
}

/**
 * Format credits for display
 */
function shaheen_blog_format_credits($credits) {
    return number_format($credits, 0);
}

/**
 * Get feature display name
 */
function shaheen_blog_get_feature_label($feature_name) {
    $labels = array(
        'headlines' => 'Headlines (5 options)',
        'subtitle' => 'Subtitle',
        'meta_description' => 'Meta Description',
        'full_article' => 'Full Article',
        'summary' => 'Summary',
    );

    return isset($labels[$feature_name]) ? $labels[$feature_name] : ucfirst(str_replace('_', ' ', $feature_name));
}

/**
 * Safe JSON encode
 */
function shaheen_blog_json_encode($data) {
    return wp_json_encode($data);
}

/**
 * Check if user can create posts
 */
function shaheen_blog_user_can_create_posts($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    return user_can($user_id, 'edit_posts');
}
