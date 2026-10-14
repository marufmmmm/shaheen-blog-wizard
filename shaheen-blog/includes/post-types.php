<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'sb_register_types');
function sb_register_types() {
    register_taxonomy('sb_category', 'sb_post', array(
        'labels' => array(
            'name'          => 'ব্লগ ক্যাটাগরি',
            'singular_name' => 'ব্লগ ক্যাটাগরি',
        ),
        'public'            => true,
        'hierarchical'      => false,
        'show_ui'           => false,
        'show_in_rest'      => true,
        'show_admin_column' => false,
        'rewrite'           => array('slug' => 'blog-category'),
    ));

    register_post_type('sb_post', array(
        'labels' => array(
            'name'          => 'ব্লগ পোস্ট',
            'singular_name' => 'ব্লগ পোস্ট',
        ),
        'public'       => true,
        'show_ui'      => false,
        'show_in_rest' => true,
        'has_archive'  => false,
        'rewrite'      => array('slug' => 'blog', 'with_front' => false),
        'supports'     => array('title', 'editor', 'excerpt', 'thumbnail', 'author'),
        'taxonomies'   => array('sb_category'),
    ));
}
