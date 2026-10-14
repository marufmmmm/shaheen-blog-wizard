<?php
/**
 * Admin panel management
 */

class Shaheen_Blog_Wizard_Admin {

    private static $instance = null;

    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'Shaheen Blog Wizard',
            'Blog Wizard',
            'edit_posts',
            'shaheen-blog-wizard',
            array($this, 'render_admin_page'),
            'dashicons-edit',
            30
        );

        add_submenu_page(
            'shaheen-blog-wizard',
            'Dashboard',
            'Dashboard',
            'edit_posts',
            'shaheen-blog-wizard',
            array($this, 'render_admin_page')
        );

        add_submenu_page(
            'shaheen-blog-wizard',
            'Create Post',
            'Create Post',
            'edit_posts',
            'shaheen-blog-wizard-create',
            array($this, 'render_create_post_page')
        );

        add_submenu_page(
            'shaheen-blog-wizard',
            'All Posts',
            'All Posts',
            'edit_posts',
            'shaheen-blog-wizard-posts',
            array($this, 'render_all_posts_page')
        );

        add_submenu_page(
            'shaheen-blog-wizard',
            'Settings',
            'Settings',
            'manage_options',
            'shaheen-blog-wizard-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only enqueue on our plugin pages
        if (strpos($hook, 'shaheen-blog-wizard') === false) {
            return;
        }

        // Enqueue jQuery
        wp_enqueue_script('jquery');

        // Enqueue our admin script
        wp_enqueue_script(
            'shaheen-blog-wizard-admin',
            SHAHEEN_BLOG_WIZARD_URL . 'assets/js/admin.js',
            array('jquery', 'wp-api'),
            SHAHEEN_BLOG_WIZARD_VERSION,
            true
        );

        // Localize script with necessary data
        wp_localize_script('shaheen-blog-wizard-admin', 'shaheenBlogWizard', array(
            'apiUrl' => rest_url('shaheen-blog-wizard/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'userId' => get_current_user_id(),
        ));

        // Enqueue admin styles
        wp_enqueue_style(
            'shaheen-blog-wizard-admin',
            SHAHEEN_BLOG_WIZARD_URL . 'assets/css/admin.css',
            array(),
            SHAHEEN_BLOG_WIZARD_VERSION
        );
    }

    /**
     * Render main admin page (Dashboard)
     */
    public function render_admin_page() {
        ?>
        <div class="wrap shaheen-blog-wizard-page">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <div id="wizard-app">
                <!-- Dashboard section will be rendered by JavaScript -->
                <div class="dashboard-section">
                    <h2>Dashboard</h2>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <h3>Total Posts</h3>
                            <p class="stat-value" id="total-posts">0</p>
                        </div>
                        <div class="stat-card">
                            <h3>Monthly Credits</h3>
                            <div class="credit-bar">
                                <div class="credit-used" id="credit-bar"></div>
                            </div>
                            <p id="credit-text">Loading...</p>
                        </div>
                        <div class="stat-card">
                            <h3>Recent Posts</h3>
                            <ul id="recent-posts" class="recent-list">
                                <li>Loading...</li>
                            </ul>
                        </div>
                    </div>
                    <div class="action-buttons">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=shaheen-blog-wizard-create')); ?>" class="button button-primary">Create New Post</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=shaheen-blog-wizard-posts')); ?>" class="button">View All Posts</a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render create post page
     */
    public function render_create_post_page() {
        include SHAHEEN_BLOG_WIZARD_PATH . 'templates/create-post-wizard.php';
    }

    /**
     * Render all posts page
     */
    public function render_all_posts_page() {
        include SHAHEEN_BLOG_WIZARD_PATH . 'templates/all-posts.php';
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        include SHAHEEN_BLOG_WIZARD_PATH . 'templates/settings.php';
    }
}
