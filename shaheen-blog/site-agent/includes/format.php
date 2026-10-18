<?php
/**
 * Post & page format: a consistent, branded design for Blog Studio content.
 * Adds reading time, table of contents, styled headings, key-takeaways box, callouts,
 * tables, FAQ accordion, call-to-action box, share buttons and related posts.
 * Everything is rendered at view time, so changing a setting updates every post.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CSA_FMT = 'csa_format';

function csa_format_defaults() {
	return array(
		'primary'      => '#0b5d3b',
		'accent'       => '#c9a227',
		'font'         => 'Hind Siliguri',
		'labels'       => 'bn',
		'all_posts'    => 0,
		'reading'      => 1,
		'toc'          => 1,
		'cta'          => 1,
		'share'        => 1,
		'related'      => 1,
		'cta_title'    => 'ভর্তি ও বিস্তারিত তথ্যের জন্য যোগাযোগ করুন',
		'cta_text'     => 'আমাদের অভিজ্ঞ শিক্ষকমণ্ডলী আপনার সন্তানের সঠিক প্রস্তুতিতে পাশে আছে।',
		'cta_btn'      => 'যোগাযোগ করুন',
		'cta_url'      => home_url( '/contact/' ),
		'cta_phone'    => '',
		'cta_whatsapp' => '',
	);
}

function csa_format() {
	$f = get_option( CSA_FMT, array() );
	return wp_parse_args( is_array( $f ) ? $f : array(), csa_format_defaults() );
}

function csa_format_labels() {
	$bn = 'bn' === csa_format()['labels'];
	return array(
		'toc'        => $bn ? 'এই লেখায় যা আছে' : 'In this article',
		'min'        => $bn ? 'মিনিট পড়া' : 'min read',
		'updated'    => $bn ? 'হালনাগাদ' : 'Updated',
		'takeaways'  => $bn ? 'এক নজরে মূল কথা' : 'Key takeaways',
		'share'      => $bn ? 'শেয়ার করুন' : 'Share',
		'copy'       => $bn ? 'লিংক কপি' : 'Copy link',
		'copied'     => $bn ? 'কপি হয়েছে' : 'Copied',
		'related'    => $bn ? 'আরও পড়ুন' : 'Read more',
		'call'       => $bn ? 'কল করুন' : 'Call',
		'whatsapp'   => 'WhatsApp',
	);
}

function csa_bn_digits( $s ) {
	return 'bn' === csa_format()['labels'] ? strtr( (string) $s, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) ) : (string) $s;
}

/** Should this post get the format? */
function csa_format_applies( $post ) {
	if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || csa_is_elementor( $post->ID ) ) {
		return false;
	}
	if ( get_post_meta( $post->ID, '_csa_format', true ) ) {
		return true;
	}
	return 'post' === $post->post_type && csa_format()['all_posts'];
}

/** Wrap content with the format. Also used by the admin preview. */
function csa_format_render( $content, $post = null ) {
	$f = csa_format();
	$l = csa_format_labels();

	// Heading ids + table of contents.
	$toc  = array();
	$used = array();
	$content = preg_replace_callback(
		'#<h2([^>]*)>(.*?)</h2>#is',
		function ( $m ) use ( &$toc, &$used ) {
			$text = trim( wp_strip_all_tags( $m[2] ) );
			if ( preg_match( '/\sid=["\']([^"\']+)["\']/', $m[1], $idm ) ) {
				$id = $idm[1];
				$attrs = $m[1];
			} else {
				$id = sanitize_title( $text );
				if ( '' === $id || is_numeric( $id ) ) {
					$id = 'section';
				}
				$base = $id;
				$n    = 2;
				while ( isset( $used[ $id ] ) ) {
					$id = $base . '-' . $n++;
				}
				$attrs = $m[1] . ' id="' . esc_attr( $id ) . '"';
			}
			$used[ $id ] = 1;
			if ( false === strpos( $attrs, 'csa-no-toc' ) ) {
				$toc[] = array( 'id' => $id, 'text' => $text );
			}
			return '<h2' . $attrs . '>' . $m[2] . '</h2>';
		},
		$content
	);

	$top = '';
	if ( $f['reading'] ) {
		$words = count( preg_split( '/\s+/u', trim( wp_strip_all_tags( $content ) ), -1, PREG_SPLIT_NO_EMPTY ) );
		$mins  = max( 1, (int) ceil( $words / 200 ) );
		$top  .= '<div class="csa-meta-bar"><span>⏱ ' . esc_html( csa_bn_digits( $mins ) . ' ' . $l['min'] ) . '</span>';
		if ( $post ) {
			$top .= '<span>🗓 ' . esc_html( $l['updated'] . ': ' . csa_bn_digits( get_the_modified_date( 'j M Y', $post ) ) ) . '</span>';
			$cats = 'post' === $post->post_type ? get_the_category( $post->ID ) : array();
			if ( $cats ) {
				$top .= '<a class="csa-chip" href="' . esc_url( get_category_link( $cats[0] ) ) . '">' . esc_html( $cats[0]->name ) . '</a>';
			}
		}
		$top .= '</div>';
	}

	if ( $f['toc'] && count( $toc ) >= 3 ) {
		$items = '';
		foreach ( $toc as $t ) {
			$items .= '<li><a href="#' . esc_attr( $t['id'] ) . '">' . esc_html( $t['text'] ) . '</a></li>';
		}
		$box = '<nav class="csa-toc" aria-label="' . esc_attr( $l['toc'] ) . '"><details open><summary>' . esc_html( $l['toc'] ) . '</summary><ol>' . $items . '</ol></details></nav>';
		$pos = stripos( $content, '<h2' );
		$content = false === $pos ? $box . $content : substr( $content, 0, $pos ) . $box . substr( $content, $pos );
	}

	$bottom = '';
	if ( $f['cta'] && ( $f['cta_title'] || $f['cta_text'] ) ) {
		$btns = '';
		if ( $f['cta_url'] && $f['cta_btn'] ) {
			$btns .= '<a class="csa-btn csa-btn-main" href="' . esc_url( $f['cta_url'] ) . '">' . esc_html( $f['cta_btn'] ) . '</a>';
		}
		if ( $f['cta_phone'] ) {
			$btns .= '<a class="csa-btn" href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', $f['cta_phone'] ) ) . '">📞 ' . esc_html( $l['call'] . ' ' . $f['cta_phone'] ) . '</a>';
		}
		if ( $f['cta_whatsapp'] ) {
			$wa    = preg_replace( '/\D/', '', $f['cta_whatsapp'] );
			$wa    = ( 0 === strpos( $wa, '01' ) ) ? '88' . $wa : $wa;
			$btns .= '<a class="csa-btn csa-btn-wa" href="https://wa.me/' . esc_attr( $wa ) . '" target="_blank" rel="noopener">💬 ' . esc_html( $l['whatsapp'] ) . '</a>';
		}
		$bottom .= '<aside class="csa-cta"><p class="csa-cta-title">' . esc_html( $f['cta_title'] ) . '</p>' . ( $f['cta_text'] ? '<p class="csa-cta-text">' . esc_html( $f['cta_text'] ) . '</p>' : '' ) . ( $btns ? '<div class="csa-cta-btns">' . $btns . '</div>' : '' ) . '</aside>';
	}

	if ( $f['share'] ) {
		$url     = $post ? get_permalink( $post ) : home_url( '/' );
		$title   = $post ? get_the_title( $post ) : '';
		$bottom .= '<div class="csa-share"><span>' . esc_html( $l['share'] ) . ':</span>'
			. '<a href="https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url ) . '" target="_blank" rel="noopener">Facebook</a>'
			. '<a href="https://wa.me/?text=' . rawurlencode( $title . ' ' . $url ) . '" target="_blank" rel="noopener">WhatsApp</a>'
			. '<a href="#" class="csa-copy" data-url="' . esc_url( $url ) . '" data-done="' . esc_attr( $l['copied'] ) . '">' . esc_html( $l['copy'] ) . '</a></div>';
	}

	if ( $f['related'] && $post && 'post' === $post->post_type ) {
		$cats = wp_get_post_categories( $post->ID );
		$rel  = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 3, 'post__not_in' => array( $post->ID ), 'category__in' => $cats ? $cats : array(), 'orderby' => 'date' ) );
		if ( $rel ) {
			$bottom .= '<section class="csa-related"><p class="csa-related-title">' . esc_html( $l['related'] ) . '</p><div class="csa-related-grid">';
			foreach ( $rel as $r ) {
				$img     = get_the_post_thumbnail_url( $r, 'medium_large' );
				$bottom .= '<a class="csa-rel" href="' . esc_url( get_permalink( $r ) ) . '">' . ( $img ? '<img src="' . esc_url( $img ) . '" alt="" loading="lazy">' : '<span class="csa-rel-ph"></span>' ) . '<span class="csa-rel-t">' . esc_html( $r->post_title ) . '</span></a>';
			}
			$bottom .= '</div></section>';
		}
	}

	$type = $post ? $post->post_type : 'post';
	return '<div class="csa-article csa-type-' . esc_attr( $type ) . '">' . $top . $content . $bottom . '</div>';
}

add_filter(
	'the_content',
	function ( $content ) {
		if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! csa_format_applies( $post ) || false !== strpos( $content, 'csa-article' ) ) {
			return $content;
		}
		$GLOBALS['csa_format_used'] = true;
		return csa_format_render( $content, $post );
	},
	20
);

/** Styles: brand colours and font as CSS variables. */
function csa_format_css_vars() {
	$f    = csa_format();
	$font = 'theme' === $f['font'] ? 'inherit' : "'" . $f['font'] . "', sans-serif";
	return '.csa-article{--csa-p:' . sanitize_hex_color( $f['primary'] ) . ';--csa-a:' . sanitize_hex_color( $f['accent'] ) . ';--csa-font:' . $font . ';}';
}

function csa_format_enqueue() {
	$f = csa_format();
	if ( in_array( $f['font'], array( 'Hind Siliguri', 'Noto Sans Bengali' ), true ) ) {
		wp_enqueue_style( 'csa-post-font', 'https://fonts.googleapis.com/css2?family=' . rawurlencode( $f['font'] ) . ':wght@400;500;600;700&display=swap', array(), null );
	}
	wp_enqueue_style( 'csa-post', CSA_URL . 'assets/post.css', array(), CSA_VER );
	wp_add_inline_style( 'csa-post', csa_format_css_vars() );
}

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( is_singular() && csa_format_applies( get_post() ) ) {
			csa_format_enqueue();
		}
	}
);

add_action(
	'wp_footer',
	function () {
		if ( empty( $GLOBALS['csa_format_used'] ) ) {
			return;
		}
		?>
		<script>
		document.addEventListener('click',function(e){var a=e.target.closest('.csa-copy');if(!a)return;e.preventDefault();
		(navigator.clipboard?navigator.clipboard.writeText(a.dataset.url):Promise.reject()).then(function(){a.textContent=a.dataset.done;});});
		</script>
		<?php
	}
);

/* ------------------------------------------------------------------ */
/* Settings                                                            */
/* ------------------------------------------------------------------ */

add_action(
	'admin_init',
	function () {
		register_setting(
			'csa_format_group',
			CSA_FMT,
			array(
				'sanitize_callback' => function ( $in ) {
					$d   = csa_format_defaults();
					$out = array();
					foreach ( array( 'primary', 'accent' ) as $k ) {
						$out[ $k ] = sanitize_hex_color( $in[ $k ] ?? '' ) ? sanitize_hex_color( $in[ $k ] ) : $d[ $k ];
					}
					$out['font']   = in_array( $in['font'] ?? '', array( 'Hind Siliguri', 'Noto Sans Bengali', 'theme' ), true ) ? $in['font'] : 'Hind Siliguri';
					$out['labels'] = ( ( $in['labels'] ?? '' ) === 'en' ) ? 'en' : 'bn';
					foreach ( array( 'all_posts', 'reading', 'toc', 'cta', 'share', 'related' ) as $k ) {
						$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
					}
					foreach ( array( 'cta_title', 'cta_btn', 'cta_phone', 'cta_whatsapp' ) as $k ) {
						$out[ $k ] = sanitize_text_field( $in[ $k ] ?? '' );
					}
					$out['cta_text'] = sanitize_textarea_field( $in['cta_text'] ?? '' );
					$out['cta_url']  = esc_url_raw( $in['cta_url'] ?? '' );
					return $out;
				},
			)
		);
	}
);

/** Sample article for the admin preview. */
function csa_format_sample() {
	$bn = 'bn' === csa_format()['labels'];
	if ( $bn ) {
		return '<p>ক্যাডেট কলেজে ভর্তি প্রতিটি অভিভাবকের স্বপ্ন। সঠিক পরিকল্পনা আর নিয়মিত অনুশীলনে এই স্বপ্ন পূরণ করা সম্ভব। এই লেখায় ধাপে ধাপে প্রস্তুতির পথ দেখানো হলো।</p>'
			. '<div class="csa-takeaways"><p class="csa-takeaways-title">এক নজরে মূল কথা</p><ul><li>পঞ্চম শ্রেণি থেকেই প্রস্তুতি শুরু করা ভালো</li><li>গণিত ও ইংরেজিতে বিশেষ জোর দিন</li><li>নিয়মিত মডেল টেস্ট দিন</li></ul></div>'
			. '<h2>ভর্তির যোগ্যতা</h2><p>প্রার্থীকে নির্দিষ্ট বয়সসীমার মধ্যে থাকতে হবে এবং শারীরিকভাবে সুস্থ হতে হবে।</p><div class="csa-note">বিজ্ঞপ্তিতে দেওয়া সর্বশেষ তথ্য অবশ্যই যাচাই করে নিন।</div>'
			. '<h2>পরীক্ষার বিষয় ও নম্বর</h2><table><thead><tr><th>বিষয়</th><th>নম্বর</th></tr></thead><tbody><tr><td>গণিত</td><td>১০০</td></tr><tr><td>ইংরেজি</td><td>১০০</td></tr><tr><td>বাংলা</td><td>১০০</td></tr></tbody></table>'
			. '<h2>প্রস্তুতির কৌশল</h2><h3>প্রতিদিনের রুটিন</h3><p>প্রতিদিন নির্দিষ্ট সময়ে পড়ার অভ্যাস গড়ে তুলুন।</p><div class="csa-tip">প্রতি সপ্তাহে একটি পূর্ণাঙ্গ মডেল টেস্ট দিন এবং ভুলগুলো আলাদা খাতায় লিখে রাখুন।</div><div class="csa-warning">পরীক্ষার আগের রাতে বেশি রাত জেগে পড়বেন না।</div>'
			. '<div class="csa-faq"><h2>সাধারণ প্রশ্ন</h2><details><summary>কোন শ্রেণি থেকে প্রস্তুতি শুরু করা উচিত?</summary><p>পঞ্চম শ্রেণি থেকে নিয়মিত প্রস্তুতি শুরু করলে সবচেয়ে ভালো ফল পাওয়া যায়।</p></details><details><summary>কোচিং কি জরুরি?</summary><p>জরুরি নয়, তবে অভিজ্ঞ শিক্ষকের দিকনির্দেশনা প্রস্তুতিকে সহজ করে।</p></details></div>';
	}
	return '<p>Getting into a cadet college is a dream for many families. With the right plan and steady practice it is achievable. This guide walks you through it step by step.</p>'
		. '<div class="csa-takeaways"><p class="csa-takeaways-title">Key takeaways</p><ul><li>Start preparing from class five</li><li>Focus on maths and English</li><li>Take regular model tests</li></ul></div>'
		. '<h2>Eligibility</h2><p>Candidates must be within the age limit and in good health.</p><div class="csa-note">Always check the latest official circular.</div>'
		. '<h2>Subjects and marks</h2><table><thead><tr><th>Subject</th><th>Marks</th></tr></thead><tbody><tr><td>Maths</td><td>100</td></tr><tr><td>English</td><td>100</td></tr></tbody></table>'
		. '<h2>Preparation strategy</h2><h3>Daily routine</h3><p>Study at the same time every day.</p><div class="csa-tip">Take one full model test every week and note your mistakes.</div><div class="csa-warning">Don\'t stay up late the night before the exam.</div>'
		. '<div class="csa-faq"><h2>FAQ</h2><details><summary>When should preparation start?</summary><p>Starting in class five gives the best results.</p></details></div>';
}

/** Blog Studio > Format tab. */
function csa_format_screen() {
	$f = csa_format();
	$n = CSA_FMT;
	?>
	<div class="csa-format">
		<form method="post" action="options.php" class="csa-card csa-format-form">
			<?php settings_fields( 'csa_format_group' ); ?>
			<h2><?php echo esc_html( csa_t( 'Post & page format' ) ); ?></h2>
			<p class="csa-muted"><?php echo esc_html( csa_t( 'One design for every Blog Studio post and page. Changes apply to all of them instantly.' ) ); ?></p>
			<div class="csa-form-grid">
				<label><?php echo esc_html( csa_t( 'Main colour' ) ); ?><input type="color" name="<?php echo esc_attr( $n ); ?>[primary]" value="<?php echo esc_attr( $f['primary'] ); ?>" data-var="--csa-p"></label>
				<label><?php echo esc_html( csa_t( 'Accent colour' ) ); ?><input type="color" name="<?php echo esc_attr( $n ); ?>[accent]" value="<?php echo esc_attr( $f['accent'] ); ?>" data-var="--csa-a"></label>
				<label><?php echo esc_html( csa_t( 'Font' ) ); ?><select name="<?php echo esc_attr( $n ); ?>[font]">
					<option <?php selected( $f['font'], 'Hind Siliguri' ); ?>>Hind Siliguri</option>
					<option <?php selected( $f['font'], 'Noto Sans Bengali' ); ?>>Noto Sans Bengali</option>
					<option value="theme" <?php selected( $f['font'], 'theme' ); ?>><?php echo esc_html( csa_t( 'Theme font' ) ); ?></option>
				</select></label>
				<label><?php echo esc_html( csa_t( 'Labels language' ) ); ?><select name="<?php echo esc_attr( $n ); ?>[labels]"><option value="bn" <?php selected( $f['labels'], 'bn' ); ?>>বাংলা</option><option value="en" <?php selected( $f['labels'], 'en' ); ?>>English</option></select></label>
				<?php
				$checks = array(
					'reading'   => csa_t( 'Reading time and date' ),
					'toc'       => csa_t( 'Table of contents (3+ sections)' ),
					'cta'       => csa_t( 'Call-to-action box at the end' ),
					'share'     => csa_t( 'Share buttons' ),
					'related'   => csa_t( 'Related posts' ),
					'all_posts' => csa_t( 'Also apply to all my other posts (not only Blog Studio posts)' ),
				);
				foreach ( $checks as $k => $label ) {
					echo '<label class="csa-check"><input type="checkbox" name="' . esc_attr( $n . '[' . $k . ']' ) . '" value="1" ' . checked( $f[ $k ], 1, false ) . '> ' . esc_html( $label ) . '</label>';
				}
				?>
				<h3 class="csa-full"><?php echo esc_html( csa_t( 'Call-to-action box' ) ); ?></h3>
				<label class="csa-full"><?php echo esc_html( csa_t( 'Heading' ) ); ?><input type="text" name="<?php echo esc_attr( $n ); ?>[cta_title]" value="<?php echo esc_attr( $f['cta_title'] ); ?>"></label>
				<label class="csa-full"><?php echo esc_html( csa_t( 'Text' ) ); ?><textarea name="<?php echo esc_attr( $n ); ?>[cta_text]" rows="2"><?php echo esc_textarea( $f['cta_text'] ); ?></textarea></label>
				<label><?php echo esc_html( csa_t( 'Button text' ) ); ?><input type="text" name="<?php echo esc_attr( $n ); ?>[cta_btn]" value="<?php echo esc_attr( $f['cta_btn'] ); ?>"></label>
				<label><?php echo esc_html( csa_t( 'Button link' ) ); ?><input type="url" name="<?php echo esc_attr( $n ); ?>[cta_url]" value="<?php echo esc_attr( $f['cta_url'] ); ?>"></label>
				<label><?php echo esc_html( csa_t( 'Phone' ) ); ?><input type="text" name="<?php echo esc_attr( $n ); ?>[cta_phone]" value="<?php echo esc_attr( $f['cta_phone'] ); ?>" placeholder="01XXXXXXXXX"></label>
				<label><?php echo esc_html( csa_t( 'WhatsApp number' ) ); ?><input type="text" name="<?php echo esc_attr( $n ); ?>[cta_whatsapp]" value="<?php echo esc_attr( $f['cta_whatsapp'] ); ?>" placeholder="01XXXXXXXXX"></label>
				<div class="csa-full"><?php submit_button( csa_t( 'Save format' ), 'primary', 'submit', false ); ?></div>
			</div>
		</form>
		<div class="csa-card csa-format-preview">
			<h2><?php echo esc_html( csa_t( 'Preview' ) ); ?> <span class="csa-muted"><?php echo esc_html( csa_t( '(sample post; colours update live)' ) ); ?></span></h2>
			<div class="csa-preview-frame" id="csa-fmt-preview">
				<?php echo csa_format_render( csa_format_sample(), null ); // phpcs:ignore -- built from escaped parts. ?>
			</div>
		</div>
	</div>
	<?php
}
