<?php
/**
 * Plugin Name: Shaheen Blog Wizard
 * Description: AI-powered blog post creation wizard with masonry display and admin dashboard
 * Version: 1.0.0
 * Author: Shaheen Education Family
 * Author URI: https://shaheen.edu.bd
 * Text Domain: shaheen-blog-wizard
 * Domain Path: /languages
 * License: GPL-2.0+
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SHAHEEN_BLOG_WIZARD_VERSION', '1.0.0');
define('SHAHEEN_BLOG_WIZARD_PATH', plugin_dir_path(__FILE__));
define('SHAHEEN_BLOG_WIZARD_URL', plugin_dir_url(__FILE__));
define('SHAHEEN_BLOG_WIZARD_BASENAME', plugin_basename(__FILE__));

// Include core files
require_once SHAHEEN_BLOG_WIZARD_PATH . 'includes/class-plugin-loader.php';
require_once SHAHEEN_BLOG_WIZARD_PATH . 'includes/class-database.php';
require_once SHAHEEN_BLOG_WIZARD_PATH . 'includes/class-admin.php';
require_once SHAHEEN_BLOG_WIZARD_PATH . 'includes/class-api.php';
require_once SHAHEEN_BLOG_WIZARD_PATH . 'includes/class-openai.php';
require_once SHAHEEN_BLOG_WIZARD_PATH . 'includes/class-credit-system.php';
require_once SHAHEEN_BLOG_WIZARD_PATH . 'includes/functions.php';

// Initialize plugin
class Shaheen_Blog_Wizard {

    private static $instance = null;

    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->setup_hooks();
    }

    private function setup_hooks() {
        // Activation
        register_activation_hook(__FILE__, array($this, 'activate'));

        // Deactivation
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Initialize when WordPress is loaded
        add_action('wp_loaded', array($this, 'init'));
    }

    public function activate() {
        // Create database tables
        Shaheen_Blog_Wizard_Database::create_tables();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    public function init() {
        // Load text domain for translations
        load_plugin_textdomain(
            'shaheen-blog-wizard',
            false,
            dirname(SHAHEEN_BLOG_WIZARD_BASENAME) . '/languages'
        );

        // Initialize admin
        if (is_admin()) {
            Shaheen_Blog_Wizard_Admin::get_instance();
        }

        // Initialize API
        Shaheen_Blog_Wizard_API::get_instance();
    }
}

// Start the plugin
Shaheen_Blog_Wizard::get_instance();
