<?php
/**
 * Database setup and management
 */

class Shaheen_Blog_Wizard_Database {

    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Posts table
        $posts_table = "
            CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shaheen_posts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                excerpt LONGTEXT,
                content LONGTEXT,
                image_url VARCHAR(500),
                category_id BIGINT UNSIGNED,
                author_id BIGINT UNSIGNED,
                publication_date DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                status VARCHAR(50) DEFAULT 'draft',
                UNIQUE KEY unique_post_id (post_id),
                KEY category_id_key (category_id),
                KEY author_id_key (author_id)
            ) $charset_collate;
        ";

        // Post Meta table (for keywords, generation history, AI feature settings)
        $post_meta_table = "
            CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shaheen_post_meta (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL,
                meta_key VARCHAR(255) NOT NULL,
                meta_value LONGTEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY post_id_key (post_id),
                KEY meta_key_key (meta_key)
            ) $charset_collate;
        ";

        // Credits table (for tracking user credit usage)
        $credits_table = "
            CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shaheen_credits (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                total_credits BIGINT DEFAULT 20000,
                used_credits BIGINT DEFAULT 0,
                month_year VARCHAR(20),
                last_reset DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_month (user_id, month_year),
                KEY user_id_key (user_id)
            ) $charset_collate;
        ";

        // Credit transactions table (audit trail)
        $transactions_table = "
            CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shaheen_credit_transactions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                post_id BIGINT UNSIGNED,
                action VARCHAR(100) NOT NULL,
                credits_used BIGINT NOT NULL,
                description TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY user_id_key (user_id),
                KEY post_id_key (post_id),
                KEY action_key (action)
            ) $charset_collate;
        ";

        // AI Features table (for tracking which features are used and their costs)
        $ai_features_table = "
            CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shaheen_ai_features (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL,
                feature_name VARCHAR(100) NOT NULL,
                feature_data LONGTEXT,
                version INT DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY post_id_key (post_id),
                KEY feature_name_key (feature_name)
            ) $charset_collate;
        ";

        // Settings table
        $settings_table = "
            CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shaheen_settings (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(255) NOT NULL UNIQUE,
                setting_value LONGTEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY setting_key_key (setting_key)
            ) $charset_collate;
        ";

        // Execute table creation
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($posts_table);
        dbDelta($post_meta_table);
        dbDelta($credits_table);
        dbDelta($transactions_table);
        dbDelta($ai_features_table);
        dbDelta($settings_table);

        // Initialize default settings if not exists
        self::initialize_default_settings();
    }

    private static function initialize_default_settings() {
        $default_settings = array(
            'openai_api_key' => '',
            'monthly_credit_limit' => 20000,
            'headline_cost' => 50,
            'subtitle_cost' => 30,
            'meta_description_cost' => 20,
            'full_article_cost' => 180,
            'summary_cost' => 80,
            'headline_count' => 5,
            'enable_plagiarism_check' => 1,
            'masonry_columns_desktop' => 3,
            'masonry_columns_tablet' => 2,
            'masonry_columns_mobile' => 1,
        );

        global $wpdb;

        foreach ($default_settings as $key => $value) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT setting_value FROM {$wpdb->prefix}shaheen_settings WHERE setting_key = %s",
                    $key
                )
            );

            if (!$exists) {
                $wpdb->insert(
                    "{$wpdb->prefix}shaheen_settings",
                    array(
                        'setting_key' => $key,
                        'setting_value' => is_array($value) ? json_encode($value) : $value,
                    )
                );
            }
        }
    }

    /**
     * Get a setting value
     */
    public static function get_setting($key, $default = '') {
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT setting_value FROM {$wpdb->prefix}shaheen_settings WHERE setting_key = %s",
                $key
            )
        );

        if ($value === null) {
            return $default;
        }

        // Try to decode JSON
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return $value;
    }

    /**
     * Update a setting value
     */
    public static function update_setting($key, $value) {
        global $wpdb;

        $value_to_store = is_array($value) ? json_encode($value) : $value;

        return $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}shaheen_settings (setting_key, setting_value)
                 VALUES (%s, %s)
                 ON DUPLICATE KEY UPDATE setting_value = %s",
                $key,
                $value_to_store,
                $value_to_store
            )
        );
    }
}
