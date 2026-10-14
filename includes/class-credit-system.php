<?php
/**
 * Credit system management
 */

class Shaheen_Blog_Wizard_Credit_System {

    /**
     * Get user's available credits for current month
     */
    public static function get_available_credits($user_id) {
        global $wpdb;

        $current_month = date('Y-m');

        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}shaheen_credits
                 WHERE user_id = %d AND month_year = %s",
                $user_id,
                $current_month
            )
        );

        if (!$record) {
            // Create new monthly record
            self::initialize_monthly_credits($user_id);
            $record = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}shaheen_credits
                     WHERE user_id = %d AND month_year = %s",
                    $user_id,
                    $current_month
                )
            );
        }

        return $record->total_credits - $record->used_credits;
    }

    /**
     * Initialize monthly credits for user
     */
    public static function initialize_monthly_credits($user_id) {
        global $wpdb;

        $current_month = date('Y-m');
        $monthly_limit = intval(Shaheen_Blog_Wizard_Database::get_setting('monthly_credit_limit', 20000));

        $wpdb->insert(
            "{$wpdb->prefix}shaheen_credits",
            array(
                'user_id' => $user_id,
                'total_credits' => $monthly_limit,
                'used_credits' => 0,
                'month_year' => $current_month,
                'last_reset' => current_time('mysql'),
            )
        );
    }

    /**
     * Deduct credits for AI feature usage
     */
    public static function deduct_credits($user_id, $post_id, $feature_name, $credits_to_deduct) {
        global $wpdb;

        $available = self::get_available_credits($user_id);

        if ($available < $credits_to_deduct) {
            return array(
                'success' => false,
                'message' => 'Insufficient credits. Available: ' . $available . ', Required: ' . $credits_to_deduct,
            );
        }

        $current_month = date('Y-m');

        // Update credit record
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}shaheen_credits
                 SET used_credits = used_credits + %d
                 WHERE user_id = %d AND month_year = %s",
                $credits_to_deduct,
                $user_id,
                $current_month
            )
        );

        if (!$updated) {
            return array(
                'success' => false,
                'message' => 'Failed to deduct credits',
            );
        }

        // Record transaction
        $wpdb->insert(
            "{$wpdb->prefix}shaheen_credit_transactions",
            array(
                'user_id' => $user_id,
                'post_id' => $post_id,
                'action' => $feature_name,
                'credits_used' => $credits_to_deduct,
                'description' => 'AI ' . ucfirst($feature_name) . ' generation',
            )
        );

        return array(
            'success' => true,
            'remaining_credits' => self::get_available_credits($user_id),
        );
    }

    /**
     * Get credit costs
     */
    public static function get_feature_cost($feature_name) {
        $feature_map = array(
            'headlines' => 'headline_cost',
            'subtitle' => 'subtitle_cost',
            'meta_description' => 'meta_description_cost',
            'full_article' => 'full_article_cost',
            'summary' => 'summary_cost',
        );

        if (!isset($feature_map[$feature_name])) {
            return 0;
        }

        return intval(Shaheen_Blog_Wizard_Database::get_setting($feature_map[$feature_name], 0));
    }

    /**
     * Get total cost for multiple features
     */
    public static function calculate_total_cost($features) {
        $total = 0;

        foreach ($features as $feature) {
            $total += self::get_feature_cost($feature);
        }

        return $total;
    }

    /**
     * Get user's credit usage history
     */
    public static function get_user_history($user_id, $limit = 50) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}shaheen_credit_transactions
                 WHERE user_id = %d
                 ORDER BY created_at DESC
                 LIMIT %d",
                $user_id,
                $limit
            )
        );
    }

    /**
     * Get monthly statistics
     */
    public static function get_monthly_stats($user_id, $year_month = null) {
        global $wpdb;

        if (!$year_month) {
            $year_month = date('Y-m');
        }

        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}shaheen_credits
                 WHERE user_id = %d AND month_year = %s",
                $user_id,
                $year_month
            )
        );

        if (!$record) {
            return array(
                'total_credits' => 0,
                'used_credits' => 0,
                'remaining_credits' => 0,
                'percentage_used' => 0,
            );
        }

        $remaining = $record->total_credits - $record->used_credits;
        $percentage = $record->total_credits > 0 ? round(($record->used_credits / $record->total_credits) * 100, 2) : 0;

        return array(
            'total_credits' => $record->total_credits,
            'used_credits' => $record->used_credits,
            'remaining_credits' => $remaining,
            'percentage_used' => $percentage,
        );
    }
}
