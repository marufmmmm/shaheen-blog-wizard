<?php
/**
 * Spelling Star / event page SEO helpers (3.2.0): Bangla title, share image, FAQ + Video structured data, lang.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function csa_ev_faq_pairs( $e ) {
	$out = array();
	foreach ( preg_split( '/\r?\n/', (string) ( $e['faq'] ?? '' ) ) as $ln ) {
		$pt = array_map( 'trim', explode( '|', $ln, 2 ) );
		if ( 2 === count( $pt ) && '' !== $pt[0] && '' !== $pt[1] ) {
			$out[] = $pt;
		}
	}
	return $out;
}

/** The event record for the page being viewed (or null). */
function csa_ev_seo_cur() {
	static $c = array();
	if ( ! is_page() ) {
		return null;
	}
	$id = (int) get_queried_object_id();
	if ( ! array_key_exists( $id, $c ) ) {
		$ev     = csa_ev_page_event_id( $id );
		$c[ $id ] = ( $ev && ( $e = csa_ev_get( $ev ) ) ) ? csa_ev_full( $e ) : null;
	}
	return $c[ $id ];
}

function csa_ev_seo_img( $e ) {
	return ! empty( $e['ogi'] ) ? $e['ogi'] : ( ! empty( $e['cover'] ) ? $e['cover'] : ( ! empty( $e['logo'] ) ? $e['logo'] : '' ) );
}

function csa_ev_seo_title( $t ) {
	$e = csa_ev_seo_cur();
	return ( $e && ! empty( $e['seot'] ) ) ? $e['seot'] : $t;
}
add_filter( 'pre_get_document_title', 'csa_ev_seo_title', 30 );
add_filter( 'wpseo_title', 'csa_ev_seo_title', 30 );
add_filter( 'wpseo_opengraph_title', 'csa_ev_seo_title', 30 );
add_filter( 'wpseo_twitter_title', 'csa_ev_seo_title', 30 );

function csa_ev_seo_desc( $d ) {
	$e = csa_ev_seo_cur();
	return ( $e && ! empty( $e['seo'] ) ) ? $e['seo'] : $d;
}
add_filter( 'wpseo_opengraph_desc', 'csa_ev_seo_desc', 30 );
add_filter( 'wpseo_twitter_description', 'csa_ev_seo_desc', 30 );
add_filter( 'wpseo_metadesc', 'csa_ev_seo_desc', 30 );

add_filter( 'wpseo_opengraph_image', function ( $u ) {
	$e = csa_ev_seo_cur();
	return ( $e && csa_ev_seo_img( $e ) ) ? csa_ev_seo_img( $e ) : $u;
}, 30 );
add_filter( 'wpseo_twitter_image', function ( $u ) {
	$e = csa_ev_seo_cur();
	return ( $e && csa_ev_seo_img( $e ) ) ? csa_ev_seo_img( $e ) : $u;
}, 30 );
add_action( 'wpseo_add_opengraph_images', function ( $obj ) {
	$e = csa_ev_seo_cur();
	if ( $e && csa_ev_seo_img( $e ) && is_object( $obj ) && method_exists( $obj, 'add_image' ) ) {
		$obj->add_image( csa_ev_seo_img( $e ) );
	}
} );
foreach ( array( 'wpseo_locale', 'wpseo_og_locale' ) as $csa_loc_f ) {
	add_filter( $csa_loc_f, function ( $l ) {
		return csa_ev_seo_cur() ? 'bn_BD' : $l;
	}, 30 );
}
unset( $csa_loc_f );

/** Without Yoast, print the share tags ourselves. */
add_action( 'wp_head', function () {
	$e = csa_ev_seo_cur();
	if ( ! $e || defined( 'WPSEO_VERSION' ) ) {
		return;
	}
	$t = ! empty( $e['seot'] ) ? $e['seot'] : csa_ev_fullname( $e );
	echo '<meta property="og:type" content="website">' . "\n" . '<meta property="og:title" content="' . esc_attr( $t ) . '">' . "\n";
	if ( ! empty( $e['seo'] ) ) {
		echo '<meta property="og:description" content="' . esc_attr( $e['seo'] ) . '">' . "\n";
	}
	if ( csa_ev_seo_img( $e ) ) {
		echo '<meta property="og:image" content="' . esc_url( csa_ev_seo_img( $e ) ) . '">' . "\n" . '<meta name="twitter:card" content="summary_large_image">' . "\n";
	}
}, 8 );

add_filter( 'language_attributes', function ( $o ) {
	return csa_ev_seo_cur() ? preg_replace( '/lang="[^"]*"/', 'lang="bn-BD"', $o ) : $o;
}, 30 );

/** FAQPage + VideoObject (only episodes that have a YouTube link AND a date — Google requires an upload date). */
add_action( 'wp_head', function () {
	$e = csa_ev_seo_cur();
	if ( ! $e ) {
		return;
	}
	$faqs = csa_ev_faq_pairs( $e );
	if ( $faqs ) {
		$main = array();
		foreach ( $faqs as $f ) {
			$main[] = array( '@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $f[1] ) );
		}
		csa_ev_jsonld( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'inLanguage' => 'bn-BD', 'mainEntity' => $main ) );
	}
	$vids = array();
	foreach ( (array) $e['eps'] as $p ) {
		$yt = csa_ev_yt( $p['u'] ?? '' );
		if ( $yt && ! empty( $p['d'] ) && ! empty( $p['t'] ) ) {
			$vids[] = array(
				'@type'        => 'VideoObject',
				'name'         => $p['t'] . ( ! empty( $p['n'] ) ? ' — ' . $p['n'] : '' ),
				'description'  => csa_ev_fullname( $e ) . ( ! empty( $p['n'] ) ? ' · ' . $p['n'] : '' ),
				'thumbnailUrl' => array( 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg' ),
				'uploadDate'   => $p['d'],
				'embedUrl'     => 'https://www.youtube-nocookie.com/embed/' . $yt,
				'contentUrl'   => 'https://www.youtube.com/watch?v=' . $yt,
			);
		}
	}
	if ( $vids ) {
		csa_ev_jsonld( array( '@context' => 'https://schema.org', '@graph' => array_map( function ( $v ) {
			return $v;
		}, $vids ) ) );
	}
}, 9 );
