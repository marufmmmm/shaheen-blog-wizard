<?php
/**
 * Design help for the agent: a free design/mobile audit of a page (Elementor settings, HTML widgets, content)
 * and a safe CSS tool (per page or site-wide) that works for every page, including normal Elementor widgets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CSA_CSS_SITE = 'csa_css_site';

function csa_dsg_px( $v ) {
	return ( is_array( $v ) && ( ! isset( $v['unit'] ) || 'px' === $v['unit'] ) ) ? $v : null;
}

function csa_dsg_label( $el ) {
	$s = (array) ( $el['settings'] ?? array() );
	foreach ( array( 'title', 'editor', 'text', 'heading', 'description' ) as $k ) {
		if ( ! empty( $s[ $k ] ) && is_string( $s[ $k ] ) ) {
			return mb_substr( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $s[ $k ] ) ) ), 0, 40 );
		}
	}
	return (string) ( $el['widgetType'] ?? $el['elType'] ?? '' );
}

/**
 * Design findings for one page. Free (no AI).
 * @return array[] severity, el (Elementor element id or ''), what, issue, fix
 */
function csa_dsg_audit( $id ) {
	$out   = array();
	$add   = function ( $sev, $el, $what, $issue, $fix ) use ( &$out ) {
		$out[] = array( 'severity' => $sev, 'element' => $el, 'what' => $what, 'issue' => $issue, 'fix' => $fix );
	};
	$fonts = array();
	$mq    = '@media (max-width:767px)';
	if ( csa_is_elementor( $id ) ) {
		$raw = get_post_meta( $id, '_elementor_data', true );
		$els = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		$walk = function ( $list ) use ( &$walk, &$fonts, $add, $mq ) {
			foreach ( (array) $list as $el ) {
				if ( ! is_array( $el ) ) {
					continue;
				}
				$s    = (array) ( $el['settings'] ?? array() );
				$eid  = (string) ( $el['id'] ?? '' );
				$what = ( $el['widgetType'] ?? $el['elType'] ?? 'element' ) . ' "' . csa_dsg_label( $el ) . '"';
				$sel  = '.elementor-element-' . $eid;
				foreach ( array( 'padding', '_padding' ) as $pk ) {
					$p = csa_dsg_px( $s[ $pk ] ?? null );
					if ( $p && empty( $s[ $pk . '_mobile' ] ) ) {
						$v = max( (float) ( $p['top'] ?? 0 ), (float) ( $p['bottom'] ?? 0 ) );
						$h = max( (float) ( $p['left'] ?? 0 ), (float) ( $p['right'] ?? 0 ) );
						if ( $h >= 40 ) {
							$add( 'high', $eid, $what, 'Side padding ' . (int) $h . 'px on every screen: on a phone this squeezes the content.', $mq . '{' . $sel . '{padding-left:16px!important;padding-right:16px!important}}' );
						}
						if ( $v >= 56 ) {
							$add( 'med', $eid, $what, 'Top/bottom padding ' . (int) $v . 'px with no mobile value: more than the 32-40px standard for a phone.', $mq . '{' . $sel . '{padding-top:36px!important;padding-bottom:36px!important}}' );
						}
					}
				}
				$fs = csa_dsg_px( $s['typography_font_size'] ?? null );
				if ( $fs && ! empty( $fs['size'] ) ) {
					$size = (float) $fs['size'];
					if ( $size >= 34 && empty( $s['typography_font_size_mobile'] ) ) {
						$add( 'high', $eid, $what, 'Font size ' . (int) $size . 'px is not reduced on mobile: long headings wrap badly or overflow.', $mq . '{' . $sel . ' .elementor-heading-title,' . $sel . '{font-size:' . max( 22, (int) round( $size * 0.62 ) ) . 'px!important;line-height:1.25!important}}' );
					}
					if ( $size <= 13 ) {
						$add( 'med', $eid, $what, 'Font size ' . (int) $size . 'px is below the 15px minimum.', $sel . '{font-size:16px!important}' );
					}
				}
				if ( ! empty( $s['typography_font_family'] ) && is_string( $s['typography_font_family'] ) ) {
					$fonts[ $s['typography_font_family'] ][] = $eid;
				}
				$w = csa_dsg_px( $s['width'] ?? null );
				if ( $w && ! empty( $w['size'] ) && (float) $w['size'] > 480 && empty( $s['width_mobile'] ) ) {
					$add( 'med', $eid, $what, 'Fixed width ' . (int) $w['size'] . 'px with no mobile width: may overflow a phone screen.', $mq . '{' . $sel . '{width:100%!important;max-width:100%!important}}' );
				}
				if ( ! empty( $s['html'] ) && is_string( $s['html'] ) ) {
					$h = $s['html'];
					if ( false === stripos( $h, '@media' ) ) {
						$add( 'high', $eid, $what, 'This HTML widget has no @media rules: its layout is the same on phone and desktop.', 'Append a scoped <style> with @media (max-width:767px) rules (use edit_elementor_html with an empty old_text).' );
					}
					if ( preg_match( '/(?<![-\w])width\s*:\s*(\d{3,4})px/i', $h, $m ) && (int) $m[1] >= 420 ) {
						$add( 'med', $eid, $what, 'Fixed width ' . (int) $m[1] . 'px inside the HTML.', 'Use max-width:100% (or a % width) for it.' );
					}
				}
				if ( ! empty( $el['elements'] ) ) {
					$walk( $el['elements'] );
				}
			}
		};
		if ( is_array( $els ) ) {
			$walk( $els );
		}
	} else {
		$p = get_post( $id );
		$c = $p ? (string) $p->post_content : '';
		if ( preg_match_all( '/(?<![-\w])width\s*:\s*(\d{3,4})px/i', $c, $m ) ) {
			foreach ( array_unique( $m[1] ) as $w ) {
				if ( (int) $w >= 420 ) {
					$add( 'med', '', 'content', 'Fixed width ' . (int) $w . 'px in the page content.', 'Change to max-width:100%.' );
				}
			}
		}
		if ( preg_match_all( '/font-size\s*:\s*(\d{2,3})px/i', $c, $m ) ) {
			foreach ( array_unique( $m[1] ) as $f ) {
				if ( (int) $f >= 36 ) {
					$add( 'med', '', 'content', 'Inline font size ' . (int) $f . 'px with no mobile rule.', 'Add a mobile rule that lowers it.' );
				}
			}
		}
	}
	// Rendered content: images and tables.
	$html = function_exists( 'csa_render_content' ) ? csa_render_content( $id ) : '';
	if ( '' !== $html ) {
		$noalt = preg_match_all( '/<img\b(?![^>]*\balt=)[^>]*>/i', $html );
		$nodim = preg_match_all( '/<img\b(?![^>]*\bwidth=)[^>]*>/i', $html );
		if ( $noalt ) {
			$add( 'med', '', 'images', $noalt . ' image(s) have no alt text.', 'Use update_image_alt / the Images section.' );
		}
		if ( $nodim > 3 ) {
			$add( 'low', '', 'images', $nodim . ' images have no width/height: the page jumps while loading.', 'Set sizes in the image widgets.' );
		}
		if ( preg_match( '/<table\b/i', $html ) && ! preg_match( '/overflow-x\s*:\s*auto/i', $html ) ) {
			$add( 'med', '', 'table', 'A table without a scrolling wrapper: it can push the page sideways on a phone.', 'Wrap it in a div with overflow-x:auto.' );
		}
	}
	if ( count( $fonts ) > 2 ) {
		$add( 'med', '', 'fonts', count( $fonts ) . ' different fonts on one page (' . implode( ', ', array_slice( array_keys( $fonts ), 0, 5 ) ) . '): looks untidy and loads slower.', 'Keep one heading font and one body font (Settings → Fonts can force them site-wide).' );
	}
	$rank = array( 'high' => 0, 'med' => 1, 'low' => 2 );
	usort( $out, function ( $a, $b ) use ( $rank ) { return $rank[ $a['severity'] ] <=> $rank[ $b['severity'] ]; } );
	return array_slice( $out, 0, 40 );
}

function csa_dsg_read_tool() {
	return array(
		array(
			'name'         => 'design_audit',
			'description'  => 'Free design and mobile check of ONE page: padding that is too big on phones, headings not reduced on mobile, tiny fonts, too many fonts, fixed widths, HTML widgets without @media, images without alt, tables that overflow. Elementor findings include the element id, so the fix can be a CSS rule for .elementor-element-<id> via set_page_css. Run it before suggesting design changes.',
			'input_schema' => csa_schema( array( 'id' => array( 'type' => 'integer' ) ), array( 'id' ) ),
		),
	);
}

function csa_dsg_write_tool() {
	return array(
		'name'         => 'set_page_css',
		'description'  => 'Add, replace or remove a named CSS block for ONE page or the WHOLE site. It is printed in the <head>, so it works for every page including normal Elementor widgets, and no widget is edited. Use it for padding, margins, font sizes, colours, spacing and mobile fixes (@media (max-width:767px){…} phone, 1024px tablet). Same name = replaces that block; empty css = removes it. Scope the selectors (e.g. .elementor-element-<id> from design_audit) and use !important only to beat Elementor\'s own CSS. Site-wide CSS affects EVERY page: use it only when the user asked for a site-wide change. Undo is in Activity.',
		'input_schema' => csa_schema(
			array(
				'scope' => array( 'type' => 'string', 'enum' => array( 'page', 'site' ) ),
				'id'    => array( 'type' => 'integer', 'description' => 'page id (scope page)' ),
				'name'  => array( 'type' => 'string', 'description' => 'short label, e.g. mobile-padding' ),
				'css'   => array( 'type' => 'string' ),
			),
			array( 'scope', 'name', 'css' )
		),
	);
}

/** Validate and store a CSS block. Returns array(ok) or array(error). */
function csa_dsg_set_css( $in, &$act ) {
	$scope = 'site' === ( $in['scope'] ?? '' ) ? 'site' : 'page';
	$name  = sanitize_key( $in['name'] ?? '' );
	$css   = trim( (string) ( $in['css'] ?? '' ) );
	if ( '' === $name ) {
		return array( 'error' => 'A short name is required.' );
	}
	if ( preg_match( '/@import|expression\s*\(|javascript:|behavior\s*:|-moz-binding|<\/?\s*(style|script)/i', $css ) ) {
		return array( 'error' => 'This CSS contains something that is not allowed (@import, javascript:, scripts, expression). Send plain CSS rules only.' );
	}
	$css = str_replace( array( '<', "\0" ), '', $css );
	if ( strlen( $css ) > 8000 ) {
		return array( 'error' => 'CSS block too large (over 8000 characters). Split it into several named blocks.' );
	}
	if ( substr_count( $css, '{' ) !== substr_count( $css, '}' ) ) {
		return array( 'error' => 'The CSS braces { } do not match. Fix and send again.' );
	}
	if ( 'site' === $scope ) {
		$old = get_option( CSA_CSS_SITE, array() );
		$all = is_array( $old ) ? $old : array();
	} else {
		$p = csa_valid_post( $in['id'] ?? 0 );
		if ( ! $p ) {
			return array( 'error' => 'Page not found (scope page needs a valid id).' );
		}
		$old = get_post_meta( $p->ID, '_csa_css', true );
		$all = is_array( $old ) ? $old : array();
	}
	if ( '' === $css ) {
		unset( $all[ $name ] );
	} else {
		if ( ! isset( $all[ $name ] ) && count( $all ) >= 12 ) {
			return array( 'error' => 'Too many CSS blocks (12). Replace or remove an existing one.' );
		}
		$all[ $name ] = $css;
	}
	if ( 'site' === $scope ) {
		update_option( CSA_CSS_SITE, $all, false );
		$act( ( '' === $css ? csa_t( 'Removed site CSS' ) : csa_t( 'Site-wide CSS' ) ) . ': ' . $name, array( 'type' => 'option', 'option' => CSA_CSS_SITE, 'old' => $old ) );
	} else {
		update_post_meta( $p->ID, '_csa_css', wp_slash( $all ) );
		csa_clear_cache( $p->ID );
		$act( ( '' === $css ? csa_t( 'Removed CSS' ) : csa_t( 'Added CSS' ) ) . ' ' . $name . ' → #' . $p->ID . ' ' . $p->post_title, array( 'type' => 'seo', 'post' => (int) $p->ID, 'old' => array( '_csa_css' => $old ) ), get_permalink( $p ) );
	}
	if ( 'site' === $scope ) {
		do_action( 'litespeed_purge_all' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}
	return array( 'ok' => true, 'note' => 'Printed in the <head>. Preview on phone to confirm. Purge the cache plugin if the change does not show.' );
}

add_action(
	'wp_head',
	function () {
		$css  = '';
		$site = get_option( CSA_CSS_SITE, array() );
		foreach ( is_array( $site ) ? $site : array() as $n => $c ) {
			$css .= '/* ' . esc_html( $n ) . " */\n" . $c . "\n";
		}
		if ( is_singular() ) {
			$pg = get_post_meta( get_the_ID(), '_csa_css', true );
			foreach ( is_array( $pg ) ? $pg : array() as $n => $c ) {
				$css .= '/* ' . esc_html( $n ) . " */\n" . $c . "\n";
			}
		}
		if ( '' !== $css ) {
			echo "\n<style id=\"csa-custom-css\">\n" . str_replace( '</', '<\\/', $css ) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	},
	99
);
