<?php
if (!defined('ABSPATH')) {
    exit;
}

/* ------------------------------------------------------------------ assets */
add_action('wp_enqueue_scripts', 'sb_front_assets_maybe');
function sb_front_assets_maybe() {
    if (is_singular('sb_post')) {
        sb_enqueue_front(false);
        return;
    }
    if (is_singular()) {
        $p = get_post();
        if ($p && has_shortcode($p->post_content, 'shaheen_blog')) {
            sb_enqueue_front(true);
        }
    }
}

function sb_enqueue_front($with_js) {
    wp_enqueue_style(
        'sb-fonts',
        'https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@300;400;500;600;700&family=Noto+Serif+Bengali:wght@600;700&family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap',
        array(),
        null
    );
    wp_enqueue_style('sb-blog', SB_URL . 'assets/css/blog.css', array('sb-fonts'), SB_VERSION . '.' . filemtime(SB_PATH . 'assets/css/blog.css'));
    wp_enqueue_script('sb-blog', SB_URL . 'assets/js/blog.js', array(), SB_VERSION . '.' . filemtime(SB_PATH . 'assets/js/blog.js'), true);
    wp_localize_script('sb-blog', 'SBF', array('ajax' => admin_url('admin-ajax.php')));
}

/** Optional fixed content width (0 = match the theme header automatically). */
function sb_fixedw_attr() {
    $a = (int) sb_get('content_width') >= 600 ? ' data-fixedw="1"' : '';
    $page = (int) get_option('sb_blog_page_id');
    if (is_singular('sb_post') || ($page && is_page($page))) {
        $a .= ' data-gapfix="1"';
    }
    return $a . ' data-offset="' . (int) sb_get('top_offset') . '"';
}

function sb_width_style() {
    $w = (int) sb_get('content_width');
    return $w >= 600 ? '<style>.shb{--shb-w:' . $w . 'px}</style>' : '';
}

/* Press items have no page of their own: go to the newspaper link. */
add_action('template_redirect', function () {
    if (is_singular('sb_post') && sb_is_press(get_the_ID())) {
        wp_redirect(esc_url_raw(get_post_meta(get_the_ID(), '_sb_press_url', true)), 302); // phpcs:ignore WordPress.Security.SafeRedirect
        exit;
    }
});

/* Count a read (called by blog.js, once per visitor per 6 hours). */
add_action('wp_ajax_nopriv_sb_view', 'sb_ajax_view');
add_action('wp_ajax_sb_view', 'sb_ajax_view');
function sb_ajax_view() {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
    if (!$id || get_post_type($id) !== 'sb_post' || get_post_status($id) !== 'publish') {
        wp_send_json_error();
    }
    $views = sb_init_views($id);
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    $bot = $ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor/', $ua);
    if (!$bot && !current_user_can('edit_posts')) {
        $views++;
        update_post_meta($id, '_sb_views', $views);
    }
    wp_send_json_success(array('views' => $views, 'text' => sb_bn_digits($views)));
}

add_filter('body_class', 'sb_body_class');
function sb_body_class($classes) {
    $on = false;
    if (is_singular('sb_post')) {
        $on = true;
    } elseif (is_singular()) {
        $p = get_post();
        $on = $p && has_shortcode($p->post_content, 'shaheen_blog');
    }
    if ($on) {
        $classes[] = 'shb-page';
        if (sb_get('hide_theme_title')) {
            $classes[] = 'shb-hide-title';
        }
    }
    return $classes;
}

/* ---------------------------------------------------------------- listing */
function sb_card_html($post_id, $hidden = false) {
    $cat   = sb_post_category($post_id);
    $img   = sb_post_image($post_id, 'large');
    $title = get_the_title($post_id);
    $press = sb_is_press($post_id);
    $excerpt = get_the_excerpt($post_id);
    if ($excerpt === '' && !$press) {
        $excerpt = wp_trim_words(wp_strip_all_tags(get_post_field('post_content', $post_id)), 28, '…');
    }
    $label = esc_html($cat['bn']);
    $href  = $press ? get_post_meta($post_id, '_sb_press_url', true) : get_permalink($post_id);
    $paper = $press ? get_post_meta($post_id, '_sb_press_paper', true) : '';
    ob_start();
    ?>
    <a class="blog-card<?php echo $press ? ' is-press' : ''; ?>" href="<?php echo esc_url($href); ?>"<?php echo $press ? ' target="_blank" rel="noopener nofollow"' : ''; ?> data-cat="<?php echo esc_attr($cat['slug']); ?>"<?php echo $hidden ? ' hidden' : ''; ?>>
        <div class="blog-image-container">
            <?php if ($img) : ?>
                <img class="blog-image" src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
            <?php else : ?>
                <div class="blog-noimg" aria-hidden="true"><?php echo $press ? '📰' : '📚'; ?></div>
            <?php endif; ?>
            <span class="blog-category"><?php echo $label; ?></span>
        </div>
        <div class="blog-content">
            <h3 class="blog-title"><?php echo esc_html($title); ?></h3>
            <?php if ($press) : ?>
                <p class="blog-excerpt">📰 <?php echo esc_html($paper); ?> · সংবাদটি পড়তে ক্লিক করুন ↗</p>
            <?php else : ?>
                <p class="blog-excerpt"><?php echo esc_html($excerpt); ?></p>
            <?php endif; ?>
            <div class="blog-meta">
                <span class="blog-author"><?php echo $press ? '📰 ' . esc_html($paper) : '✍️ ' . esc_html(sb_author_name($post_id)); ?></span>
                <span class="blog-date"><?php echo esc_html(sb_bn_date(get_post_time('U', false, $post_id), false)); ?></span>
            </div>
        </div>
    </a>
    <?php
    return ob_get_clean();
}

add_shortcode('shaheen_blog', 'sb_shortcode');
function sb_shortcode($atts) {
    sb_enqueue_front(true);
    $atts = shortcode_atts(array(
        'per_page' => (int) sb_get('per_page'),
    ), $atts, 'shaheen_blog');
    $per = max(3, min(50, (int) $atts['per_page']));

    $q = new WP_Query(array(
        'post_type'      => 'sb_post',
        'post_status'    => 'publish',
        'posts_per_page' => 200,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ));
    $terms = get_terms(array('taxonomy' => 'sb_category', 'hide_empty' => false, 'orderby' => 'term_id'));
    if (is_wp_error($terms)) {
        $terms = array();
    }
    $classes = 'shb shb-blog' . (sb_get('full_width') ? '' : ' shb--boxed');

    ob_start();
    echo sb_width_style(); // phpcs:ignore WordPress.Security.EscapeOutput
    ?>
    <div class="<?php echo esc_attr($classes); ?>"<?php echo sb_fixedw_attr(); // phpcs:ignore WordPress.Security.EscapeOutput ?> data-per-page="<?php echo (int) $per; ?>" data-hide-title="<?php echo sb_get('hide_theme_title') ? '1' : '0'; ?>">
        <header class="blog-header">
            <div class="header-content">
                <h1><?php echo esc_html(sb_get('hero_title')); ?></h1>
                <h2 class="blog-subtitle"><?php echo esc_html(sb_get('hero_subtitle')); ?></h2>
            </div>
        </header>

        <?php if (sb_get('show_intro') && sb_get('intro')) : ?>
        <section class="intro-section">
            <div class="intro-container"><p><?php echo esc_html(sb_get('intro')); ?></p></div>
        </section>
        <?php endif; ?>

        <div class="blog-container">
            <section class="filter-section">
                <h2 class="section-title">📂 বিভাগ অনুসারে ব্রাউজ করুন</h2>
                <div class="category-filter" role="group" aria-label="বিভাগ">
                    <button type="button" class="category-btn active" data-cat="all" aria-pressed="true">সকল (All)</button>
                    <?php foreach ($terms as $t) :
                        $en = get_term_meta($t->term_id, 'sb_name_en', true); ?>
                        <button type="button" class="category-btn" data-cat="<?php echo esc_attr($t->slug); ?>" aria-pressed="false"><?php echo esc_html($t->name . ($en ? ' (' . $en . ')' : '')); ?></button>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="blog-grid">
                <?php
                $n = 0;
                foreach ($q->posts as $p) {
                    echo sb_card_html($p->ID, $n >= $per); // phpcs:ignore WordPress.Security.EscapeOutput
                    $n++;
                }
                ?>
                <div class="empty-state" <?php echo $q->post_count ? 'hidden' : ''; ?>>
                    <p style="font-size: 40px; margin-bottom: 20px;">📭</p>
                    <h3>কোনো পোস্ট পাওয়া যায়নি</h3>
                    <p>অন্য বিভাগ চেষ্টা করুন</p>
                </div>
            </div>

            <div class="load-more-container" hidden>
                <button type="button" class="load-more-btn"><span>আরও পোস্ট লোড করুন</span></button>
            </div>
        </div>
        <noscript><style>.shb .blog-card[hidden]{display:flex!important}</style></noscript>
    </div>
    <?php
    wp_reset_postdata();
    return ob_get_clean();
}

/* ------------------------------------------------------------ single post */
add_filter('the_content', 'sb_single_content', 20);
function sb_single_content($content) {
    if (!is_singular('sb_post') || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    $post = get_post();
    $id   = $post->ID;
    $cat  = sb_post_category($id);
    $sub  = get_post_meta($id, '_sb_subtitle', true);
    $sum  = get_post_meta($id, '_sb_summary', true);
    $faq  = get_post_meta($id, '_sb_faq', true);
    $img  = sb_post_image($id, 'full');
    $views = sb_init_views($id);
    $url  = get_permalink($id);
    $blog = (int) get_option('sb_blog_page_id') ? get_permalink((int) get_option('sb_blog_page_id')) : home_url('/');

    $classes = 'shb shb-single' . (sb_get('full_width') ? '' : ' shb--boxed');
    $title_enc = rawurlencode(get_the_title($id));
    $url_enc = rawurlencode($url);

    $related = sb_related_items($id, 3, 5);

    ob_start();
    echo sb_width_style(); // phpcs:ignore WordPress.Security.EscapeOutput
    ?>
    <article class="<?php echo esc_attr($classes); ?>"<?php echo sb_fixedw_attr(); // phpcs:ignore WordPress.Security.EscapeOutput ?> data-post="<?php echo (int) $id; ?>">
        <header class="blog-header post-hero">
            <div class="header-content">
                <a class="post-back" href="<?php echo esc_url($blog); ?>">← ব্লগে ফিরুন</a><br>
                <span class="blog-category post-cat"><?php echo esc_html($cat['bn']); ?></span>
                <h1><?php echo esc_html(get_the_title($id)); ?></h1>
                <?php if ($sub) : ?><h2 class="blog-subtitle"><?php echo esc_html($sub); ?></h2><?php endif; ?>
                <div class="post-meta">
                    <span>✍️ <?php echo esc_html(sb_author_name($id)); ?></span>
                    <span>📅 <?php echo esc_html(sb_bn_date(get_post_time('U', false, $id))); ?></span>
                    <span>👁 <span class="js-views"><?php echo esc_html(sb_bn_digits($views)); ?></span> বার পড়া হয়েছে</span>
                </div>
            </div>
        </header>

        <div class="post-layout">
        <div class="post-wrap">
            <div class="post-card">
                <?php if ($img) : ?>
                    <figure class="post-figure"><img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr(get_the_title($id)); ?>"></figure>
                <?php endif; ?>
                <div class="post-inner">
                    <?php if ($sum) : ?>
                        <div class="post-summary"><strong>সংক্ষেপে</strong><?php echo esc_html($sum); ?></div>
                    <?php endif; ?>

                    <div class="post-body"><?php echo sb_inject_blocks(wpautop($post->post_content), sb_post_blocks($id)); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>

                    <?php if (!empty($faq) && is_array($faq)) : ?>
                        <section class="post-faq">
                            <h2>❓ সচরাচর জিজ্ঞাসা</h2>
                            <?php foreach ($faq as $row) : ?>
                                <details>
                                    <summary><?php echo esc_html($row['q']); ?></summary>
                                    <div class="faq-a"><?php echo esc_html($row['a']); ?></div>
                                </details>
                            <?php endforeach; ?>
                        </section>
                    <?php endif; ?>

                    <section class="post-share">
                        <h2>📤 পোস্টটি শেয়ার করুন</h2>
                        <div class="share-row">
                            <a class="share-btn" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $url_enc; ?>">Facebook</a>
                            <a class="share-btn" target="_blank" rel="noopener" href="https://wa.me/?text=<?php echo $title_enc . '%20' . $url_enc; ?>">WhatsApp</a>
                            <a class="share-btn" target="_blank" rel="noopener" href="https://t.me/share/url?url=<?php echo $url_enc; ?>&amp;text=<?php echo $title_enc; ?>">Telegram</a>
                            <a class="share-btn" target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?url=<?php echo $url_enc; ?>&amp;text=<?php echo $title_enc; ?>">X</a>
                            <button type="button" class="share-btn js-copy-link" data-url="<?php echo esc_attr($url); ?>">🔗 লিংক কপি</button>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <aside class="post-aside" aria-label="সম্পর্কিত পোস্ট">
            <div class="aside-box">
                <h2>📖 সম্পর্কিত পোস্ট</h2>
                <ul class="rel-list">
                    <?php foreach ($related as $r) : ?>
                        <li>
                            <a class="rel-item" href="<?php echo esc_url($r['url']); ?>"<?php echo $r['press'] ? ' target="_blank" rel="noopener nofollow"' : ''; ?>>
                                <span class="rel-thumb"><?php if ($r['thumb']) : ?><img src="<?php echo esc_url($r['thumb']); ?>" alt="" loading="lazy"><?php else : ?><i><?php echo $r['press'] ? '📰' : '📚'; ?></i><?php endif; ?></span>
                                <span class="rel-text"><small><?php echo esc_html($r['tag']); ?></small><strong><?php echo esc_html($r['title']); ?></strong></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </aside>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

/* -------------------------------------------------------------- SEO tags */
add_action('wp_head', 'sb_head_meta', 5);
function sb_head_meta() {
    if (!is_singular('sb_post')) {
        return;
    }
    $id = get_the_ID();
    $desc = get_post_meta($id, '_sb_meta_desc', true);
    if (!$desc) {
        $desc = get_the_excerpt($id);
    }
    $img = sb_post_image($id, 'large');
    echo "\n<meta name=\"description\" content=\"" . esc_attr(wp_strip_all_tags($desc)) . "\">\n";
    echo '<meta property="og:type" content="article">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr(get_the_title($id)) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr(wp_strip_all_tags($desc)) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url(get_permalink($id)) . '">' . "\n";
    if ($img) {
        echo '<meta property="og:image" content="' . esc_url($img) . '">' . "\n";
    }
}
