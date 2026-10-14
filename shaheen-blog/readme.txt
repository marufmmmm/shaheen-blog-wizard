=== Shaheen Blog ===
Version: 2.0.0
Blog page (your green/gold design), AI post wizard (OpenAI) and admin panel in one plugin.

Install: Plugins > Add New > Upload Plugin > shaheen-blog.zip > Activate.
Then: Shaheen Blog > Settings > paste OpenAI API key.
The blog page is created automatically at /blog/ (shortcode [shaheen_blog]).
Subscription hook: shaheen_blog_set_subscription( $user_id, 'YYYY-MM-DD', 20000 );
