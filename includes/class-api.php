<?php
/**
 * REST API endpoints
 */

class Shaheen_Blog_Wizard_API {

    private static $instance = null;

    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        // Create post endpoint
        register_rest_route('shaheen-blog-wizard/v1', '/posts/create', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_post'),
            'permission_callback' => array($this, 'check_user_permissions'),
        ));

        // Update post endpoint
        register_rest_route('shaheen-blog-wizard/v1', '/posts/(?P<id>\d+)/update', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_post'),
            'permission_callback' => array($this, 'check_user_permissions'),
        ));

        // Generate AI content endpoint
        register_rest_route('shaheen-blog-wizard/v1', '/generate/(?P<feature>\w+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_ai_content'),
            'permission_callback' => array($this, 'check_user_permissions'),
        ));

        // Get available credits endpoint
        register_rest_route('shaheen-blog-wizard/v1', '/credits/available', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_available_credits'),
            'permission_callback' => array($this, 'check_user_permissions'),
        ));

        // Get dashboard stats endpoint
        register_rest_route('shaheen-blog-wizard/v1', '/dashboard/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_dashboard_stats'),
            'permission_callback' => array($this, 'check_user_permissions'),
        ));

        // Get all posts endpoint
        register_rest_route('shaheen-blog-wizard/v1', '/posts', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_posts'),
            'permission_callback' => array($this, 'check_user_permissions'),
        ));
    }

    /**
     * Permission callback
     */
    public function check_user_permissions() {
        return current_user_can('edit_posts');
    }

    /**
     * Create post
     */
    public function create_post($request) {
        $params = $request->get_json_params();

        // Validate required fields
        if (empty($params['title'])) {
            return new WP_Error('invalid_title', 'Title is required', array('status' => 400));
        }

        $user_id = get_current_user_id();

        global $wpdb;

        // Insert post record
        $inserted = $wpdb->insert(
            "{$wpdb->prefix}shaheen_posts",
            array(
                'post_id' => 0, // Will be updated when WordPress post is created
                'title' => sanitize_text_field($params['title']),
                'excerpt' => isset($params['excerpt']) ? sanitize_textarea_field($params['excerpt']) : '',
                'image_url' => isset($params['image_url']) ? esc_url_raw($params['image_url']) : '',
                'category_id' => isset($params['category_id']) ? intval($params['category_id']) : 0,
                'author_id' => $user_id,
                'status' => 'draft',
            )
        );

        if (!$inserted) {
            return new WP_Error('insert_failed', 'Failed to create post', array('status' => 500));
        }

        $post_id = $wpdb->insert_id;

        // Store keywords if provided
        if (!empty($params['keywords']) && is_array($params['keywords'])) {
            $wpdb->insert(
                "{$wpdb->prefix}shaheen_post_meta",
                array(
                    'post_id' => $post_id,
                    'meta_key' => 'keywords',
                    'meta_value' => json_encode($params['keywords']),
                )
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'post_id' => $post_id,
            'message' => 'Post created successfully',
        ));
    }

    /**
     * Update post
     */
    public function update_post($request) {
        $post_id = intval($request['id']);
        $params = $request->get_json_params();

        global $wpdb;

        // Check if post exists
        $post = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}shaheen_posts WHERE id = %d",
                $post_id
            )
        );

        if (!$post) {
            return new WP_Error('post_not_found', 'Post not found', array('status' => 404));
        }

        // Check permissions
        if ($post->author_id != get_current_user_id() && !current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'You do not have permission to edit this post', array('status' => 403));
        }

        // Update post fields (these do NOT consume credits)
        $update_data = array();

        if (isset($params['title'])) {
            $update_data['title'] = sanitize_text_field($params['title']);
        }
        if (isset($params['excerpt'])) {
            $update_data['excerpt'] = sanitize_textarea_field($params['excerpt']);
        }
        if (isset($params['category_id'])) {
            $update_data['category_id'] = intval($params['category_id']);
        }
        if (isset($params['image_url'])) {
            $update_data['image_url'] = esc_url_raw($params['image_url']);
        }
        if (isset($params['publication_date'])) {
            $update_data['publication_date'] = sanitize_text_field($params['publication_date']);
        }

        if (!empty($update_data)) {
            $wpdb->update(
                "{$wpdb->prefix}shaheen_posts",
                $update_data,
                array('id' => $post_id)
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Post updated successfully (no credits consumed)',
        ));
    }

    /**
     * Generate AI content
     */
    public function generate_ai_content($request) {
        $feature = sanitize_text_field($request['feature']);
        $params = $request->get_json_params();

        $user_id = get_current_user_id();
        $post_id = isset($params['post_id']) ? intval($params['post_id']) : 0;

        // Get feature cost
        $cost = Shaheen_Blog_Wizard_Credit_System::get_feature_cost($feature);

        if ($cost == 0) {
            return new WP_Error('invalid_feature', 'Invalid feature name', array('status' => 400));
        }

        // Check available credits
        $available = Shaheen_Blog_Wizard_Credit_System::get_available_credits($user_id);

        if ($available < $cost) {
            return new WP_Error('insufficient_credits', 'Insufficient credits. Required: ' . $cost . ', Available: ' . $available, array('status' => 402));
        }

        try {
            $openai = new Shaheen_Blog_Wizard_OpenAI();
        } catch (Exception $e) {
            return new WP_Error('api_error', $e->getMessage(), array('status' => 500));
        }

        // Generate content based on feature
        $result = array();

        switch ($feature) {
            case 'headlines':
                $topic = isset($params['topic']) ? sanitize_text_field($params['topic']) : '';
                $keywords = isset($params['keywords']) ? array_map('sanitize_text_field', $params['keywords']) : array();
                $result = $openai->generate_headlines($topic, $keywords);
                break;

            case 'subtitle':
                $topic = isset($params['topic']) ? sanitize_text_field($params['topic']) : '';
                $headline = isset($params['headline']) ? sanitize_text_field($params['headline']) : '';
                $result = $openai->generate_subtitle($topic, $headline);
                break;

            case 'meta_description':
                $topic = isset($params['topic']) ? sanitize_text_field($params['topic']) : '';
                $headline = isset($params['headline']) ? sanitize_text_field($params['headline']) : '';
                $excerpt = isset($params['excerpt']) ? sanitize_textarea_field($params['excerpt']) : '';
                $result = $openai->generate_meta_description($topic, $headline, $excerpt);
                break;

            case 'full_article':
                $topic = isset($params['topic']) ? sanitize_text_field($params['topic']) : '';
                $headline = isset($params['headline']) ? sanitize_text_field($params['headline']) : '';
                $keywords = isset($params['keywords']) ? array_map('sanitize_text_field', $params['keywords']) : array();
                $result = $openai->generate_full_article($topic, $headline, $keywords);
                break;

            case 'summary':
                $content = isset($params['content']) ? sanitize_textarea_field($params['content']) : '';
                $result = $openai->generate_summary($content);
                break;

            default:
                return new WP_Error('invalid_feature', 'Invalid feature', array('status' => 400));
        }

        if (!$result['success']) {
            return new WP_Error('generation_failed', $result['message'], array('status' => 500));
        }

        // Deduct credits
        $deduct_result = Shaheen_Blog_Wizard_Credit_System::deduct_credits($user_id, $post_id, $feature, $cost);

        if (!$deduct_result['success']) {
            return new WP_Error('credit_deduction_failed', $deduct_result['message'], array('status' => 500));
        }

        // Store the generated content in AI features table
        if ($post_id > 0) {
            global $wpdb;

            $wpdb->insert(
                "{$wpdb->prefix}shaheen_ai_features",
                array(
                    'post_id' => $post_id,
                    'feature_name' => $feature,
                    'feature_data' => is_array($result) ? json_encode($result) : $result['content'],
                )
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'data' => $result,
            'credits_used' => $cost,
            'remaining_credits' => $deduct_result['remaining_credits'],
        ));
    }

    /**
     * Get available credits
     */
    public function get_available_credits($request) {
        $user_id = get_current_user_id();
        $available = Shaheen_Blog_Wizard_Credit_System::get_available_credits($user_id);
        $monthly_stats = Shaheen_Blog_Wizard_Credit_System::get_monthly_stats($user_id);

        return rest_ensure_response(array(
            'success' => true,
            'available_credits' => $available,
            'monthly_stats' => $monthly_stats,
        ));
    }

    /**
     * Get dashboard stats
     */
    public function get_dashboard_stats($request) {
        $user_id = get_current_user_id();

        global $wpdb;

        // Get post count
        $post_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}shaheen_posts WHERE author_id = %d",
                $user_id
            )
        );

        // Get credit stats
        $monthly_stats = Shaheen_Blog_Wizard_Credit_System::get_monthly_stats($user_id);

        // Get recent posts
        $recent_posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, title, status, created_at FROM {$wpdb->prefix}shaheen_posts
                 WHERE author_id = %d
                 ORDER BY created_at DESC
                 LIMIT 5",
                $user_id
            )
        );

        return rest_ensure_response(array(
            'success' => true,
            'stats' => array(
                'total_posts' => $post_count,
                'credits' => $monthly_stats,
            ),
            'recent_posts' => $recent_posts,
        ));
    }

    /**
     * Get all posts
     */
    public function get_posts($request) {
        $user_id = get_current_user_id();
        $page = intval($request->get_param('page') ?? 1);
        $per_page = intval($request->get_param('per_page') ?? 10);
        $status = sanitize_text_field($request->get_param('status') ?? '');
        $search = sanitize_text_field($request->get_param('search') ?? '');

        global $wpdb;

        $query = "SELECT * FROM {$wpdb->prefix}shaheen_posts WHERE author_id = %d";
        $params = array($user_id);

        if (!empty($status)) {
            $query .= " AND status = %s";
            $params[] = $status;
        }

        if (!empty($search)) {
            $query .= " AND (title LIKE %s OR excerpt LIKE %s)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $query .= " ORDER BY created_at DESC";

        // Get total count
        $total = $wpdb->get_var($wpdb->prepare(str_replace('SELECT *', 'SELECT COUNT(*)', $query), $params));

        // Get paginated results
        $offset = ($page - 1) * $per_page;
        $query .= " LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        $posts = $wpdb->get_results($wpdb->prepare($query, $params));

        return rest_ensure_response(array(
            'success' => true,
            'posts' => $posts,
            'total' => intval($total),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
        ));
    }
}
