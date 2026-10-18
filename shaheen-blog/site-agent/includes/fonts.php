<?php
/**
 * Site-wide Bangla Unicode fonts.
 *
 * Loads the chosen Google Fonts once on the front end and applies them to body text and
 * headings (theme, Elementor and, if "force" is on, custom HTML widgets too). Icon fonts
 * are left alone. The agent is told which fonts the site uses.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Unicode Bangla fonts on Google Fonts: key => [family, weights, style note]. */
function csa_font_list() {
	return array(
		'noto-sans'  => array( 'Noto Sans Bengali', '400;500;600;700', 'sans-serif', 'Clean and modern; the most readable for body text' ),
		'anek'       => array( 'Anek Bangla', '400;500;600;700;800', 'sans-serif', 'Modern, many weights; good for headings and text' ),
		'baloo'      => array( 'Baloo Da 2', '400;500;600;700;800', 'sans-serif', 'Rounded and friendly; school and coaching headings' ),
		'hind'       => array( 'Hind Siliguri', '400;500;600;700', 'sans-serif', 'Simple and light; body text' ),
		'tiro'       => array( 'Tiro Bangla', '400', 'serif', 'Book-like; articles and formal text' ),
		'noto-serif' => array( 'Noto Serif Bengali', '400;500;600;700', 'serif', 'Formal serif; notices and official pages' ),
		'mina'       => array( 'Mina', '400;700', 'sans-serif', 'Geometric and bold; big headings and banners' ),
		'galada'     => array( 'Galada', '400', 'cursive', 'Handwritten look; decoration only' ),
		'atma'       => array( 'Atma', '400;500;600;700', 'cursive', 'Playful; children\'s sections and offers' ),
	);
}

function csa_font( $key ) {
	$l = csa_font_list();
	return $l[ $key ] ?? null;
}

function csa_font_stack( $f ) {
	return "'" . $f[0] . "', " . ( 'serif' === $f[2] ? "'Noto Serif Bengali', serif" : "'Noto Sans Bengali', sans-serif" );
}

/** Google Fonts URL for the chosen fonts ('' when none). */
function csa_fonts_url() {
	$fam = array();
	foreach ( array_unique( array_filter( array( csa_opt( 'font_head' ), csa_opt( 'font_body' ) ) ) ) as $k ) {
		$f = csa_font( $k );
		if ( $f ) {
			$fam[] = 'family=' . str_replace( ' ', '+', $f[0] ) . ':wght@' . $f[1];
		}
	}
	return $fam ? 'https://fonts.googleapis.com/css2?' . implode( '&', $fam ) . '&display=swap' : '';
}

/** Front-end CSS that applies the fonts. */
function csa_fonts_css() {
	$b = csa_font( (string) csa_opt( 'font_body' ) );
	$h = csa_font( (string) csa_opt( 'font_head' ) );
	if ( ! $b && ! $h ) {
		return '';
	}
	$imp   = csa_opt( 'font_force' ) ? ' !important' : '';
	$noicn = ':not(i):not([class*="icon"]):not([class*="fa-"]):not([class*="dashicons"]):not([class*="eicon"]):not([class*="material"])';
	$css   = '';
	if ( $b ) {
		$s    = csa_font_stack( $b );
		$css .= 'body,button,input,select,textarea{font-family:' . $s . $imp . '}';
		if ( $imp ) {
			$css .= 'body :is(p,li,td,th,dt,dd,blockquote,label,a,span,div,strong,em,b,small,figcaption,summary,button,input,textarea,select)' . $noicn . '{font-family:' . $s . $imp . '}';
		}
	}
	if ( $h ) {
		$s    = csa_font_stack( $h );
		$css .= 'h1,h2,h3,h4,h5,h6,.entry-title,.site-title,.elementor-heading-title{font-family:' . $s . $imp . '}';
		if ( $imp ) {
			$css .= 'body :is(h1,h2,h3,h4,h5,h6) :is(span,a,strong,b,em)' . $noicn . '{font-family:' . $s . $imp . '}';
		}
	}
	return $css;
}

/** Font size in px: empty (keep the theme) or 10–90. */
function csa_fs_clean( $v ) {
	$n = (int) $v;
	return ( $n >= 10 && $n <= 90 ) ? $n : '';
}

function csa_lh_clean( $v ) {
	$n = (float) str_replace( ',', '.', (string) $v );
	return ( $n >= 1 && $n <= 2.6 ) ? round( $n, 2 ) : '';
}

/** Front-end CSS for the font sizes set in Settings (desktop, and phone under 768px). */
function csa_sizes_css() {
	$g   = function ( $k ) { return csa_opt( $k ); };
	$rules = function ( $sfx ) use ( $g ) {
		$c = '';
		$b = $g( 'fs_body' . $sfx );
		if ( '' !== $b && null !== $b ) {
			$c .= 'body,body :is(p,li,td,th,dd,dt,label,blockquote,figcaption,.elementor-widget-text-editor,.elementor-widget-text-editor *){font-size:' . (int) $b . 'px!important}';
		}
		foreach ( array( 'h1', 'h2', 'h3' ) as $h ) {
			$v = $g( 'fs_' . $h . $sfx );
			if ( '' !== $v && null !== $v ) {
				$c .= $h . ',' . $h . '.elementor-heading-title,.elementor-widget-heading ' . $h . '.elementor-heading-title,.entry-content ' . $h . '{font-size:' . (int) $v . 'px!important;line-height:1.25}';
			}
		}
		return $c;
	};
	$css = $rules( '' );
	$lh  = $g( 'lh_body' );
	if ( '' !== $lh && null !== $lh ) {
		$css .= 'body :is(p,li,td,th,.elementor-widget-text-editor){line-height:' . (float) $lh . '!important}';
	}
	$m = $rules( '_m' );
	if ( '' !== $m ) {
		$css .= '@media (max-width:767px){' . $m . '}';
	}
	return $css;
}

add_action( 'wp_enqueue_scripts', 'csa_fonts_enqueue', 99 );
function csa_fonts_enqueue() {
	$url   = csa_fonts_url();
	$sizes = csa_sizes_css();
	if ( '' === $url && '' === $sizes ) {
		return;
	}
	if ( '' !== $url ) {
		wp_enqueue_style( 'csa-site-fonts', $url, array(), null );
	}
	wp_register_style( 'csa-site-fonts-apply', false, '' !== $url ? array( 'csa-site-fonts' ) : array(), CSA_VER );
	wp_enqueue_style( 'csa-site-fonts-apply' );
	wp_add_inline_style( 'csa-site-fonts-apply', csa_fonts_css() . $sizes );
}

add_filter( 'wp_resource_hints', 'csa_fonts_hints', 10, 2 );
function csa_fonts_hints( $urls, $type ) {
	if ( 'preconnect' === $type && '' !== csa_fonts_url() ) {
		$urls[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' );
	}
	return $urls;
}

/** One line for the agent's system prompt. */
function csa_fonts_context() {
	$b = csa_font( (string) csa_opt( 'font_body' ) );
	$h = csa_font( (string) csa_opt( 'font_head' ) );
	$sz = csa_sizes_css();
	if ( ! $b && ! $h && '' === $sz ) {
		return '';
	}
	return "\n\n## Site fonts (loaded site-wide by the plugin)\nHeadings: " . ( $h ? $h[0] : 'theme default' ) . '. Body text: ' . ( $b ? $b[0] : 'theme default' ) . ". Write all Bangla as Unicode. Don't add other font imports or font-family rules to pages; use font-family: inherit. Site-wide font sizes (body, H1-H3, desktop and phone) are set by the user in Settings > Site fonts; for ONE page use set_page_css.";
}

/** Settings card. */
function csa_fonts_card( $o, $n ) {
	$list = csa_font_list();
	$sel  = function ( $name, $cur ) use ( $list, $n ) {
		echo '<select name="' . esc_attr( $n . '[' . $name . ']' ) . '"><option value="">' . esc_html( csa_t( 'Keep the theme font' ) ) . '</option>';
		foreach ( $list as $k => $f ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $cur, $k, false ) . '>' . esc_html( $f[0] . ' — ' . csa_t( $f[3] ) ) . '</option>';
		}
		echo '</select>';
	};
	?>
	<div class="csa-card csa-fonts-card">
		<h2>🔤 <?php echo esc_html( csa_t( 'Site fonts (Bangla Unicode)' ) ); ?></h2>
		<p class="csa-muted"><?php echo esc_html( csa_t( 'All fonts here are Unicode, so Bangla shows correctly on every phone and Google can read it. Keep at most two fonts for speed.' ) ); ?></p>
		<table class="form-table">
			<tr><th><?php echo esc_html( csa_t( 'Headings' ) ); ?></th><td><?php $sel( 'font_head', $o['font_head'] ); ?></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'Body text' ) ); ?></th><td><?php $sel( 'font_body', $o['font_body'] ); ?></td></tr>
			<tr><th><?php echo esc_html( csa_t( 'Custom pages too' ) ); ?></th><td><label class="csa-check"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[font_force]" value="1" <?php checked( $o['font_force'], 1 ); ?>> <?php echo esc_html( csa_t( 'Also use these fonts inside custom HTML / Elementor pages that set their own font (icons are not affected).' ) ); ?></label></td></tr>
		</table>
		<h3><?php echo esc_html( csa_t( 'Font sizes' ) ); ?></h3>
		<p class="csa-muted"><?php echo esc_html( csa_t( 'Sizes in px for the whole site. Leave a box empty to keep the theme size. "Phone" applies on screens narrower than 768px.' ) ); ?></p>
		<table class="widefat striped csa-fs-table" style="max-width:520px"><thead><tr><th></th><th><?php echo esc_html( csa_t( 'Computer' ) ); ?></th><th><?php echo esc_html( csa_t( 'Phone' ) ); ?></th></tr></thead><tbody>
		<?php foreach ( array( 'body' => csa_t( 'Body text' ) . ' (16–18)', 'h1' => 'H1 ' . csa_t( 'main heading' ) . ' (32–44)', 'h2' => 'H2 (26–34)', 'h3' => 'H3 (20–26)' ) as $k => $lab ) : ?>
			<tr><td><?php echo esc_html( $lab ); ?></td>
			<td><input type="number" min="10" max="90" style="width:80px" name="<?php echo esc_attr( $n . '[fs_' . $k . ']' ); ?>" value="<?php echo esc_attr( $o[ 'fs_' . $k ] ?? '' ); ?>"> px</td>
			<td><input type="number" min="10" max="90" style="width:80px" name="<?php echo esc_attr( $n . '[fs_' . $k . '_m]' ); ?>" value="<?php echo esc_attr( $o[ 'fs_' . $k . '_m' ] ?? '' ); ?>"> px</td></tr>
		<?php endforeach; ?>
			<tr><td><?php echo esc_html( csa_t( 'Line height (body)' ) ); ?> (1.5–1.8)</td><td colspan="2"><input type="number" step="0.05" min="1" max="2.6" style="width:80px" name="<?php echo esc_attr( $n . '[lh_body]' ); ?>" value="<?php echo esc_attr( $o['lh_body'] ?? '' ); ?>"></td></tr>
		</tbody></table>
		<p class="description"><?php echo esc_html( csa_t( 'A single page can still be changed by the agent (Design check). Page rules win over these site-wide sizes only if the agent adds them with !important.' ) ); ?></p>
		<?php $prev = array_filter( array( csa_font( (string) $o['font_head'] ), csa_font( (string) $o['font_body'] ) ) ); ?>
		<?php if ( $prev && csa_fonts_url() ) : ?>
			<link rel="stylesheet" href="<?php echo esc_url( csa_fonts_url() ); ?>">
			<div class="csa-font-preview">
				<?php $hf = csa_font( (string) $o['font_head'] ); $bf = csa_font( (string) $o['font_body'] ); ?>
				<div style="font-family:<?php echo esc_attr( $hf ? csa_font_stack( $hf ) : 'inherit' ); ?>;font-size:24px;font-weight:700">শাহীন এডুকেশন ফ্যামিলি – ভর্তি চলছে</div>
				<div style="font-family:<?php echo esc_attr( $bf ? csa_font_stack( $bf ) : 'inherit' ); ?>;font-size:16px">অভিজ্ঞ শিক্ষকদের সাথে লাইভ ক্লাস, সাপ্তাহিক পরীক্ষা ও নিয়মিত অভিভাবক সভা। ০১২৩৪৫৬৭৮৯</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
