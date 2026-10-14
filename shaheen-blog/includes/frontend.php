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
    $excerpt = get_the_excerpt($post_id);
    if ($excerpt === '') {
        $excerpt = wp_trim_words(wp_strip_all_tags(get_post_field('post_content', $post_id)), 28, '…');
    }
    $label = esc_html($cat['bn']);
    ob_start();
    ?>
    <a class="blog-card" href="<?php echo esc_url(get_permalink($post_id)); ?>" data-cat="<?php echo esc_attr($cat['slug']); ?>"<?php echo $hidden ? ' hidden' : ''; ?>>
        <div class="blog-image-container">
            <?php if ($img) : ?>
                <img class="blog-image" src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
            <?php else : ?>
                <div class="blog-noimg" aria-hidden="true">📚</div>
            <?php endif; ?>
            <span class="blog-category"><?php echo $label; ?></span>
        </div>
        <div class="blog-content">
            <h3 class="blog-title"><?php echo esc_html($title); ?></h3>
            <p class="blog-excerpt"><?php echo esc_html($excerpt); ?></p>
            <div class="blog-meta">
                <span class="blog-author">✍️ <?php echo esc_html(sb_author_name($post_id)); ?></span>
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
    ?>
    <div class="<?php echo esc_attr($classes); ?>" data-per-page="<?php echo (int) $per; ?>" data-hide-title="<?php echo sb_get('hide_theme_title') ? '1' : '0'; ?>">
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
    $views = (int) get_post_meta($id, '_sb_views', true);
    $url  = get_permalink($id);
    $blog = (int) get_option('sb_blog_page_id') ? get_permalink((int) get_option('sb_blog_page_id')) : home_url('/');

    if (!is_user_logged_in()) {
        update_post_meta($id, '_sb_views', $views + 1);
        $views++;
    }

    $classes = 'shb shb-single' . (sb_get('full_width') ? '' : ' shb--boxed');
    $title_enc = rawurlencode(get_the_title($id));
    $url_enc = rawurlencode($url);

    // Related: same category first, then latest.
    $related = array();
    $term = get_the_terms($id, 'sb_category');
    $base = array('post_type' => 'sb_post', 'post_status' => 'publish', 'posts_per_page' => 3,
        'post__not_in' => array($id), 'no_found_rows' => true, 'fields' => 'ids');
    if (!empty($term) && !is_wp_error($term)) {
        $related = get_posts($base + array('tax_query' => array(array(
            'taxonomy' => 'sb_category', 'field' => 'term_id', 'terms' => array((int) $term[0]->term_id)))));
    }
    if (count($related) < 3) {
        $more = get_posts(array_merge($base, array(
            'posts_per_page' => 3 - count($related),
            'post__not_in' => array_merge(array($id), $related),
        )));
        $related = array_merge($related, $more);
    }

    ob_start();
    ?>
    <article class="<?php echo esc_attr($classes); ?>">
        <header class="blog-header post-hero">
            <div class="header-content">
                <a class="post-back" href="<?php echo esc_url($blog); ?>">← ব্লগে ফিরুন</a><br>
                <span class="blog-category post-cat"><?php echo esc_html($cat['bn']); ?></span>
                <h1><?php echo esc_html(get_the_title($id)); ?></h1>
                <?php if ($sub) : ?><h2 class="blog-subtitle"><?php echo esc_html($sub); ?></h2><?php endif; ?>
                <div class="post-meta">
                    <span>✍️ <?php echo esc_html(sb_author_name($id)); ?></span>
                    <span>📅 <?php echo esc_html(sb_bn_date(get_post_time('U', false, $id))); ?></span>
                    <span>👁 <?php echo esc_html(sb_bn_digits($views)); ?> বার পড়া হয়েছে</span>
                </div>
            </div>
        </header>

        <div class="post-wrap">
            <div class="post-card">
                <?php if ($img) : ?>
                    <figure class="post-figure"><img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr(get_the_title($id)); ?>"></figure>
                <?php endif; ?>
                <div class="post-inner">
                    <?php if ($sum) : ?>
                        <div class="post-summary"><strong>সংক্ষেপে</strong><?php echo esc_html($sum); ?></div>
                    <?php endif; ?>

                    <div class="post-body"><?php echo wpautop($post->post_content); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>

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

        <?php if ($related) : ?>
        <section class="post-related">
            <h2>📖 আরও পড়ুন</h2>
            <div class="blog-grid">
                <?php foreach ($related as $rid) {
                    echo sb_card_html($rid); // phpcs:ignore WordPress.Security.EscapeOutput
                } ?>
            </div>
        </section>
        <?php endif; ?>
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
