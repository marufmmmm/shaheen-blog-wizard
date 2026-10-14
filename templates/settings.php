<?php
/**
 * Settings Page Template
 */

// Check user capabilities
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to access this page.');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_wpnonce'])) {
    if (wp_verify_nonce($_POST['_wpnonce'], 'shaheen_settings_nonce')) {
        // Update OpenAI API Key
        if (isset($_POST['openai_api_key'])) {
            Shaheen_Blog_Wizard_Database::update_setting('openai_api_key', sanitize_text_field($_POST['openai_api_key']));
        }

        // Update credit limits
        if (isset($_POST['monthly_credit_limit'])) {
            Shaheen_Blog_Wizard_Database::update_setting('monthly_credit_limit', intval($_POST['monthly_credit_limit']));
        }

        // Update feature costs
        if (isset($_POST['headline_cost'])) {
            Shaheen_Blog_Wizard_Database::update_setting('headline_cost', intval($_POST['headline_cost']));
        }
        if (isset($_POST['subtitle_cost'])) {
            Shaheen_Blog_Wizard_Database::update_setting('subtitle_cost', intval($_POST['subtitle_cost']));
        }
        if (isset($_POST['meta_description_cost'])) {
            Shaheen_Blog_Wizard_Database::update_setting('meta_description_cost', intval($_POST['meta_description_cost']));
        }
        if (isset($_POST['full_article_cost'])) {
            Shaheen_Blog_Wizard_Database::update_setting('full_article_cost', intval($_POST['full_article_cost']));
        }
        if (isset($_POST['summary_cost'])) {
            Shaheen_Blog_Wizard_Database::update_setting('summary_cost', intval($_POST['summary_cost']));
        }

        echo '<div class="notice notice-success"><p>Settings updated successfully!</p></div>';
    }
}

// Get current settings
$openai_key = Shaheen_Blog_Wizard_Database::get_setting('openai_api_key', '');
$monthly_limit = Shaheen_Blog_Wizard_Database::get_setting('monthly_credit_limit', 20000);
$headline_cost = Shaheen_Blog_Wizard_Database::get_setting('headline_cost', 50);
$subtitle_cost = Shaheen_Blog_Wizard_Database::get_setting('subtitle_cost', 30);
$meta_cost = Shaheen_Blog_Wizard_Database::get_setting('meta_description_cost', 20);
$article_cost = Shaheen_Blog_Wizard_Database::get_setting('full_article_cost', 180);
$summary_cost = Shaheen_Blog_Wizard_Database::get_setting('summary_cost', 80);
?>

<div class="wrap shaheen-blog-wizard-page">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <form method="POST" action="" class="settings-form">
        <?php wp_nonce_field('shaheen_settings_nonce'); ?>

        <table class="form-table">
            <tbody>
                <!-- OpenAI API Key -->
                <tr>
                    <th scope="row">
                        <label for="openai_api_key">OpenAI API Key</label>
                    </th>
                    <td>
                        <input type="password" name="openai_api_key" id="openai_api_key" value="<?php echo esc_attr($openai_key); ?>" class="regular-text">
                        <p class="description">Your OpenAI API key for accessing GPT models. Get it from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Dashboard</a></p>
                    </td>
                </tr>

                <!-- Monthly Credit Limit -->
                <tr>
                    <th scope="row">
                        <label for="monthly_credit_limit">Monthly Credit Limit</label>
                    </th>
                    <td>
                        <input type="number" name="monthly_credit_limit" id="monthly_credit_limit" value="<?php echo esc_attr($monthly_limit); ?>" min="1000" step="1000" class="small-text">
                        <p class="description">Maximum credits available per user per month</p>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2>AI Feature Costs (in credits)</h2>

        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="headline_cost">Headlines (5 options)</label>
                    </th>
                    <td>
                        <input type="number" name="headline_cost" id="headline_cost" value="<?php echo esc_attr($headline_cost); ?>" min="1" class="small-text"> credits
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="subtitle_cost">Subtitle</label>
                    </th>
                    <td>
                        <input type="number" name="subtitle_cost" id="subtitle_cost" value="<?php echo esc_attr($subtitle_cost); ?>" min="1" class="small-text"> credits
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="meta_description_cost">Meta Description</label>
                    </th>
                    <td>
                        <input type="number" name="meta_description_cost" id="meta_description_cost" value="<?php echo esc_attr($meta_cost); ?>" min="1" class="small-text"> credits
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="full_article_cost">Full Article</label>
                    </th>
                    <td>
                        <input type="number" name="full_article_cost" id="full_article_cost" value="<?php echo esc_attr($article_cost); ?>" min="1" class="small-text"> credits
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="summary_cost">Summary</label>
                    </th>
                    <td>
                        <input type="number" name="summary_cost" id="summary_cost" value="<?php echo esc_attr($summary_cost); ?>" min="1" class="small-text"> credits
                    </td>
                </tr>
            </tbody>
        </table>

        <p>
            <?php submit_button('Save Settings', 'primary', 'submit', true); ?>
        </p>
    </form>

    <h2>Information</h2>
    <div class="info-box">
        <h3>Total Monthly Cost Example</h3>
        <p>If a user generates all 5 features for one post:</p>
        <ul>
            <li>Headlines: <?php echo esc_html($headline_cost); ?> credits</li>
            <li>Subtitle: <?php echo esc_html($subtitle_cost); ?> credits</li>
            <li>Meta Description: <?php echo esc_html($meta_cost); ?> credits</li>
            <li>Full Article: <?php echo esc_html($article_cost); ?> credits</li>
            <li>Summary: <?php echo esc_html($summary_cost); ?> credits</li>
            <li><strong>Total: <?php echo esc_html($headline_cost + $subtitle_cost + $meta_cost + $article_cost + $summary_cost); ?> credits</strong></li>
        </ul>
    </div>
</div>

<style>
.settings-form {
    max-width: 800px;
}

.form-table {
    width: 100%;
    margin-bottom: 20px;
}

.form-table th {
    text-align: left;
    padding: 10px;
    border-bottom: 1px solid #ddd;
}

.form-table td {
    padding: 10px;
    border-bottom: 1px solid #ddd;
}

.regular-text {
    width: 300px;
}

.small-text {
    width: 80px;
}

.description {
    display: block;
    color: #666;
    margin-top: 5px;
    font-size: 13px;
}

.info-box {
    background: #f9f9f9;
    border-left: 4px solid #2271b1;
    padding: 15px;
    margin-top: 20px;
}

.info-box h3 {
    margin-top: 0;
}

.info-box ul {
    margin: 10px 0;
    padding-left: 20px;
}

.info-box li {
    margin: 5px 0;
}
</style>
