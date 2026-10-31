<?php
/**
 * Branch page: the whole "শাখা সমূহ" page (hero, head office, quick buttons, nearby search, 150+ branches)
 * managed from Page Builder. Data lives in the database, any allowed editor can change it.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CSA_BR_OPT  = 'csa_br';
const CSA_BR_LIST = 'csa_br_list';

function csa_br_defaults() {
	return array(
		'hero'         => array( 'h1a' => 'আমাদের', 'h1b' => 'শাখা সমূহ', 'sub' => 'বাংলাদেশজুড়ে শাহীন শিক্ষা পরিবারের শাখা খুঁজুন', 'stats' => array( array( 'n' => '১৫০+', 'l' => 'মোট শাখা' ), array( 'n' => '৩৫', 'l' => 'জেলা' ), array( 'n' => '৮', 'l' => 'বিভাগ' ), array( 'n' => '৩৪', 'l' => 'বছর' ) ) ),
		'hq'           => array( 'name' => 'শাহীন স্কুল এন্ড ক্যাডেট কোচিং — প্রধান কার্যালয়', 'addr' => 'রেজিস্ট্রিপাড়া, টাঙ্গাইল সদর, টাঙ্গাইল', 'phones' => array(), 'wa' => '', 'map' => '' ),
		'actions'      => array(
			array( 'ic' => '💻', 't' => 'অনলাইন ক্যাডেট ভর্তি', 'u' => '' ),
			array( 'ic' => '🎓', 't' => 'অনলাইন প্রি-ক্যাডেট ভর্তি', 'u' => '' ),
			array( 'ic' => '📚', 't' => 'ক্যাডেট গাইড কিনুন', 'u' => '' ),
			array( 'ic' => '📝', 't' => 'মডেল টেস্ট কিনুন', 'u' => '' ),
		),
		'typo'         => array(),
		'cta'          => array( 't' => 'নতুন শাখা খুলতে চান?', 'p' => 'শাহীন শিক্ষা পরিবারের সাথে যুক্ত হয়ে শিক্ষার আলো ছড়িয়ে দিন', 'bt' => '📄 শাখা আবেদন করুন →', 'bu' => '' ),
		'types'        => array(
			'main'    => array( 'label' => 'সাধারণ শাখা', 'icon' => '🏫', 'kicker' => 'শাহীন স্কুল এন্ড ক্যাডেট কোচিং :', 'tag' => '' ),
			'islamic' => array( 'label' => 'ইসলামিক স্কুল', 'icon' => '☽', 'kicker' => 'শাহীন ইসলামিক স্কুল :', 'tag' => 'Islamic' ),
			'english' => array( 'label' => 'English Version', 'icon' => '🔤', 'kicker' => 'Shaheen School English Version :', 'tag' => 'English' ),
		),
		'div_order'    => array( 'ঢাকা', 'চট্টগ্রাম', 'রাজশাহী', 'সিলেট', 'খুলনা', 'বরিশাল', 'রংপুর', 'ময়মনসিংহ', 'অন্যান্য' ),
		'dist_order'   => array( 'ঢাকা', 'টাঙ্গাইল' ),
		'default_view' => 'div:ঢাকা',
		'nearby'       => 'both', // district | gps | both
		'roles'        => array( 'editor' ),
		'page'         => 0,
		'replace'      => 0,
		'lm'           => array(),
		'next'         => 1,
	);
}

function csa_br_opts() {
	$o = get_option( CSA_BR_OPT, array() );
	$d = csa_br_defaults();
	$o = is_array( $o ) ? $o : array();
	foreach ( $d as $k => $v ) {
		if ( ! isset( $o[ $k ] ) ) {
			$o[ $k ] = $v;
		} elseif ( is_array( $v ) && in_array( $k, array( 'hero', 'hq', 'cta' ), true ) ) {
			$o[ $k ] = array_merge( $v, (array) $o[ $k ] );
		}
	}
	return $o;
}

function csa_br_opt( $k ) {
	return csa_br_opts()[ $k ] ?? null;
}

function csa_br_set( $o ) {
	update_option( CSA_BR_OPT, $o, false );
	csa_br_purge();
}

/** Text parts of the page the owner can resize / re-font: key => [label, selectors]. */
function csa_br_typo_parts() {
	return array(
		'_all'     => array( 'পুরো পেজের মূল ফন্ট', '.sbr,.sbr button,.sbr select,.sbr input' ),
		'_num'     => array( 'সংখ্যা ও ফোন নম্বরের ফন্ট', '.sbr .hsn,.sbr .bc-ph-num,.sbr .hq-ph' ),
		'hero_h1'  => array( 'ওপরের বড় শিরোনাম (আমাদের শাখা সমূহ)', '.sbr .hero h1' ),
		'hero_p'   => array( 'ওপরের ছোট লেখা', '.sbr .hero p' ),
		'hero_n'   => array( 'ওপরের সংখ্যা (শাখা, জেলা...)', '.sbr .hsn' ),
		'hero_l'   => array( 'ওপরের সংখ্যার নিচের লেখা', '.sbr .hsl' ),
		'hq_name'  => array( 'প্রধান কার্যালয়ের নাম', '.sbr .hq-info h2' ),
		'hq_addr'  => array( 'প্রধান কার্যালয়ের ঠিকানা', '.sbr .hq-info .addr' ),
		'hq_ph'    => array( 'প্রধান কার্যালয়ের ফোন', '.sbr .hq-ph' ),
		'hq_btn'   => array( 'কার্যালয়ের বোতাম (কল, WhatsApp, Map)', '.sbr .hq-call,.sbr .hq-wa,.sbr .hq-map' ),
		'act'      => array( 'দ্রুত বোতামের লেখা (ভর্তি, গাইড...)', '.sbr .abtxt' ),
		'filter'   => array( 'বিভাগ বাছার বক্স ও লেখা', '.sbr .filter-in label,.sbr .div-sel' ),
		'div_h'    => array( 'বিভাগের শিরোনাম', '.sbr .div-head h2' ),
		'dist'     => array( 'জেলার নাম', '.sbr .dist-label' ),
		'card_t'   => array( 'শাখার কার্ডের নাম (সবুজ অংশ)', '.sbr .bc-hd h3' ),
		'card_k'   => array( 'কার্ডের "শাহীন স্কুল এন্ড ক্যাডেট কোচিং :" লেখা', '.sbr .bc-kicker' ),
		'card_a'   => array( 'কার্ডের ঠিকানা', '.sbr .bc-addr' ),
		'card_p'   => array( 'কার্ডের ফোন নম্বর', '.sbr .bc-ph-num,.sbr .bc-ph' ),
		'card_m'   => array( 'কার্ডের Map / কল বোতাম', '.sbr .bc-map,.sbr .bc-call' ),
		'nb_n'     => array( 'কাছের শাখার নাম', '.sbr .nbc-name' ),
		'nb_a'     => array( 'কাছের শাখার ঠিকানা', '.sbr .nbc-addr' ),
		'cta_t'    => array( 'নিচের হলুদ বক্সের শিরোনাম', '.sbr .cta h3' ),
		'cta_p'    => array( 'নিচের হলুদ বক্সের ছোট লেখা', '.sbr .cta p' ),
		'cta_b'    => array( 'নিচের হলুদ বক্সের বোতাম', '.sbr .cta a' ),
	);
}

/** Google Fonts link + CSS for the owner's size / font choices ('' when nothing is set). */
function csa_br_typo_head() {
	$ty = csa_br_opt( 'typo' );
	if ( ! is_array( $ty ) || ! $ty || ! function_exists( 'csa_font_list' ) ) {
		return '';
	}
	$parts = csa_br_typo_parts();
	$fams  = array();
	$css   = '';
	$mob   = '';
	foreach ( $ty as $k => $r ) {
		if ( ! isset( $parts[ $k ] ) || ! is_array( $r ) ) {
			continue;
		}
		$sel = $parts[ $k ][1];
		$d   = '';
		$f   = ! empty( $r['f'] ) ? csa_font( $r['f'] ) : null;
		if ( $f ) {
			$fams[ $f[0] ] = 'family=' . str_replace( ' ', '+', $f[0] ) . ':wght@' . $f[1];
			$d            .= 'font-family:' . csa_font_stack( $f ) . ' !important;';
		}
		$w = isset( $r['b'] ) ? (int) $r['b'] : 0;
		if ( $w >= 300 && $w <= 900 ) {
			$d .= 'font-weight:' . $w . ' !important;';
		}
		$s = isset( $r['s'] ) ? (float) $r['s'] : 0;
		$m = isset( $r['m'] ) ? (float) $r['m'] : 0;
		if ( $s >= 8 && $s <= 90 ) {
			$d .= 'font-size:' . $s . 'px !important;';
			if ( $m < 8 ) {
				$m = round( $s * 0.85, 1 );   // no phone size given: a little smaller than the computer size
			}
		}
		if ( $d ) {
			$css .= $sel . '{' . $d . '}';
		}
		if ( $m >= 8 && $m <= 90 ) {
			$mob .= $sel . '{font-size:' . $m . 'px !important}';
		}
	}
	if ( '' === $css . $mob ) {
		return '';
	}
	$link = $fams ? '<link rel="stylesheet" href="' . esc_url( 'https://fonts.googleapis.com/css2?' . implode( '&', $fams ) . '&display=swap' ) . '">' : '';
	return $link . '<style id="sbr-typo">' . $css . ( $mob ? '@media(max-width:600px){' . $mob . '}' : '' ) . '</style>';
}

/** The page is usually cached by the host (LiteSpeed etc.): clear it after every change, so a new link or text shows at once. */
function csa_br_purge() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$lp   = function_exists( 'csa_br_pageid' ) ? csa_br_pageid() : 0;
	if ( $lp && function_exists( 'csa_clear_cache' ) ) {
		csa_clear_cache( $lp );
	}
	do_action( 'litespeed_purge_all' );
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
}

function csa_br_list() {
	$l = get_option( CSA_BR_LIST, array() );
	return is_array( $l ) ? array_values( $l ) : array();
}

function csa_br_put( $l ) {
	update_option( CSA_BR_LIST, array_values( $l ), false );
	csa_br_purge();
}

/** May this user change the branch page? Administrators always; others by role (Settings). */
function csa_br_can() {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	$u = wp_get_current_user();
	return $u && $u->exists() && array_intersect( (array) $u->roles, (array) csa_br_opt( 'roles' ) );
}

function csa_br_en( $s ) { // Bangla digits -> ASCII
	return strtr( (string) $s, array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' ) );
}

function csa_br_bn( $s ) {
	return strtr( (string) $s, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
}

/** Latitude / longitude out of a long Google Maps link (short maps.app.goo.gl links carry none). */
function csa_br_coords( $url ) {
	$url = urldecode( (string) $url );
	if ( preg_match( '/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/', $url, $m ) || preg_match( '/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/', $url, $m ) || preg_match( '/[?&](?:q|ll|query)=(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/', $url, $m ) ) {
		return array( (float) $m[1], (float) $m[2] );
	}
	return null;
}

function csa_br_phones( $v ) {
	$items = is_array( $v ) ? $v : preg_split( '/[\r\n,;،、]+/u', (string) $v );
	$out   = array();
	foreach ( $items as $p ) {
		$p = trim( sanitize_text_field( $p ) );
		if ( '' !== $p ) {
			$out[] = $p;
		}
	}
	return array_values( array_unique( $out ) );
}

function csa_br_clean( $in, $keep_id = 0 ) {
	$o     = csa_br_opts();
	$t     = sanitize_key( $in['type'] ?? 'main' );
	$map   = esc_url_raw( trim( (string) ( $in['map'] ?? '' ) ) );
	$lat   = isset( $in['lat'] ) && '' !== trim( (string) $in['lat'] ) ? (float) csa_br_en( $in['lat'] ) : '';
	$lng   = isset( $in['lng'] ) && '' !== trim( (string) $in['lng'] ) ? (float) csa_br_en( $in['lng'] ) : '';
	if ( ( '' === $lat || '' === $lng ) && $map ) {
		$c = csa_br_coords( $map );
		if ( $c ) {
			list( $lat, $lng ) = $c;
		}
	}
	return array(
		'id'             => $keep_id ? $keep_id : (int) ( $in['id'] ?? 0 ),
		'name'           => sanitize_text_field( $in['name'] ?? '' ),
		'div'            => sanitize_text_field( $in['div'] ?? '' ),
		'district'       => sanitize_text_field( $in['district'] ?? '' ),
		'type'           => isset( $o['types'][ $t ] ) ? $t : 'main',
		'addr'           => sanitize_text_field( $in['addr'] ?? '' ),
		'phones'         => csa_br_phones( $in['phones'] ?? array() ),
		'map'            => $map,
		'lat'            => $lat,
		'lng'            => $lng,
		'pageLink'       => esc_url_raw( trim( (string) ( $in['pageLink'] ?? '' ) ) ),
		'pageLinkActive' => ! empty( $in['pageLinkActive'] ),
	);
}

/** Merge a block of branches (seed / old page / backup) into the stored list; same name + district is updated, not duplicated. */
function csa_br_merge( $rows, &$added = 0, &$updated = 0 ) {
	$list = csa_br_list();
	$o    = csa_br_opts();
	$idx  = array();
	foreach ( $list as $i => $b ) {
		$idx[ mb_strtolower( $b['name'] . '|' . $b['district'] . '|' . $b['type'] ) ] = $i;
	}
	foreach ( (array) $rows as $r ) {
		if ( ! is_array( $r ) || '' === trim( (string) ( $r['name'] ?? '' ) ) ) {
			continue;
		}
		$c   = csa_br_clean( $r );
		$key = mb_strtolower( $c['name'] . '|' . $c['district'] . '|' . $c['type'] );
		if ( isset( $idx[ $key ] ) ) {
			$c['id']          = $list[ $idx[ $key ] ]['id'];
			$list[ $idx[ $key ] ] = $c;
			$updated++;
		} else {
			$c['id']  = (int) $o['next']++;
			$list[]   = $c;
			$idx[ $key ] = count( $list ) - 1;
			$added++;
		}
	}
	csa_br_put( $list );
	csa_br_set( $o );
}

/* ---------- Page detection and takeover ---------- */
function csa_br_pageid() {
	$p = (int) csa_br_opt( 'page' );
	if ( $p && 'publish' === get_post_status( $p ) ) {
		return $p;
	}
	foreach ( array( 'branch', 'branches', 'sakha', 'shakha' ) as $path ) {
		$pg = get_page_by_path( $path );
		if ( $pg && 'publish' === $pg->post_status ) {
			return (int) $pg->ID;
		}
	}
	return 0;
}

add_filter( 'template_include', function ( $tpl ) {
	if ( ! csa_br_opt( 'replace' ) || ! is_page() || ! empty( $_GET['snr'] ) || wp_is_block_theme() ) {
		return $tpl;
	}
	$lp = csa_br_pageid();
	if ( ! $lp || (int) get_queried_object_id() !== $lp ) {
		return $tpl;
	}
	return CSA_DIR . 'includes/branch-template.php';
}, 99 );

add_filter( 'the_content', function ( $c ) {
	if ( ! csa_br_opt( 'replace' ) || ! is_page() || ! in_the_loop() || ! is_main_query() || ! empty( $GLOBALS['csa_br_done'] ) ) {
		return $c;
	}
	$lp = csa_br_pageid();
	if ( ! $lp || (int) get_the_ID() !== $lp ) {
		return $c;
	}
	if ( ! empty( $_GET['snr'] ) && function_exists( 'csa_sn_read_ok' ) && csa_sn_read_ok( sanitize_text_field( wp_unslash( $_GET['snr'] ) ) ) ) {
		return $c; // the plugin is reading the page's own content
	}
	return csa_br_page();
}, 30 );

add_shortcode( 'shaheen_branches', function () {
	$GLOBALS['csa_br_done'] = true;
	return csa_br_page();
} );

/* ---------- Public page ---------- */
function csa_br_collator() {
	static $c = null;
	if ( null === $c ) {
		$c = class_exists( 'Collator' ) ? new Collator( 'bn' ) : false;
	}
	return $c;
}

function csa_br_cmp( $a, $b ) {
	$c = csa_br_collator();
	return $c ? $c->compare( $a, $b ) : strcmp( $a, $b );
}

function csa_br_card( $b, $types ) {
	$t   = isset( $types[ $b['type'] ] ) ? $b['type'] : 'main';
	$m   = $types[ $t ];
	$cls = 'main' === $t ? '' : ' ' . $t;
	$h   = '<div class="bc' . $cls . '" data-id="' . (int) $b['id'] . '" data-div="' . esc_attr( $b['div'] ) . '" data-type="' . esc_attr( $t ) . '" data-q="' . esc_attr( mb_strtolower( $b['name'] . ' ' . $b['addr'] . ' ' . $b['district'] . ' ' . $b['div'] ) ) . '">';
	$h  .= '<div class="bc-hd' . $cls . '"><h3>' . esc_html( $m['icon'] . ' ' . wp_strip_all_tags( $b['name'] ) ) . ( $m['tag'] ? '<span class="bc-tag">' . esc_html( $m['tag'] ) . '</span>' : '' ) . '</h3></div><div class="bc-bd">';
	$h  .= '<div class="bc-addr"><span class="bc-kicker">' . esc_html( $m['kicker'] ) . '</span>' . esc_html( $b['addr'] ) . '</div><div class="bc-ph">';
	foreach ( (array) $b['phones'] as $p ) {
		$h .= '<a class="bc-ph-row" href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', csa_br_en( $p ) ) ) . '"><span class="bc-ic">📞</span><span class="bc-ph-num">' . esc_html( $p ) . '</span></a>';
	}
	$h .= '</div><div class="bc-ft">';
	$h .= $b['map'] ? '<a class="bc-map" href="' . esc_url( $b['map'] ) . '" target="_blank" rel="noopener">🗺️ Map দেখুন</a>' : '<span class="bc-map bc-nomap">🗺️ Map নেই</span>';
	if ( $b['pageLink'] && $b['pageLinkActive'] ) {
		$h .= '<a class="bc-pagelink" href="' . esc_url( $b['pageLink'] ) . '">📄 শাখা দেখুন →</a>';
	}
	return $h . '</div></div></div>';
}

function csa_br_page() {
	$o     = csa_br_opts();
	$list  = csa_br_list();
	$types = $o['types'];
	$hero  = $o['hero'];
	$hq    = $o['hq'];
	$grp   = array();
	foreach ( $list as $b ) {
		$dv = $b['div'] ?: 'অন্যান্য';
		$ds = $b['district'] ?: $dv;
		$t  = isset( $types[ $b['type'] ] ) ? $b['type'] : 'main';
		$grp[ $dv ][ $ds ][ $t ][] = $b;
	}
	uksort( $grp, function ( $a, $b ) use ( $o ) {
		$x = array_search( $a, $o['div_order'], true );
		$y = array_search( $b, $o['div_order'], true );
		return ( false === $x ? 99 : $x ) <=> ( false === $y ? 99 : $y );
	} );
	$sections = '';
	$order    = array_keys( $types );
	foreach ( $grp as $dv => $dists ) {
		uksort( $dists, function ( $a, $b ) use ( $o ) {
			$x = array_search( $a, $o['dist_order'], true );
			$y = array_search( $b, $o['dist_order'], true );
			$x = false === $x ? 99 : $x;
			$y = false === $y ? 99 : $y;
			return $x === $y ? csa_br_cmp( $a, $b ) : $x <=> $y;
		} );
		$tot = 0;
		foreach ( $dists as $ts ) {
			foreach ( $ts as $bs ) {
				$tot += count( $bs );
			}
		}
		$sections .= '<div class="div-sec" data-div="' . esc_attr( $dv ) . '"><div class="div-head"><h2>📍 ' . esc_html( $dv ) . ' বিভাগ</h2><span class="div-cnt" data-n="' . $tot . '">' . esc_html( csa_br_bn( $tot ) ) . 'টি শাখা</span></div>';
		foreach ( $dists as $ds => $ts ) {
			$n = 0;
			foreach ( $ts as $bs ) {
				$n += count( $bs );
			}
			$sections .= '<div class="dist-grp"><div class="dist-label">📍 ' . esc_html( $ds ) . ' <span class="dn">(' . esc_html( csa_br_bn( $n ) ) . ')</span></div>';
			foreach ( $order as $tk ) {
				if ( empty( $ts[ $tk ] ) ) {
					continue;
				}
				if ( count( $ts ) > 1 ) {
					$sections .= '<div class="type-lbl ' . esc_attr( $tk ) . '" data-type="' . esc_attr( $tk ) . '">' . esc_html( $types[ $tk ]['icon'] . ' ' . $types[ $tk ]['label'] ) . '</div>';
				}
				$sections .= '<div class="bgrid">';
				foreach ( $ts[ $tk ] as $b ) {
					$sections .= csa_br_card( $b, $types );
				}
				$sections .= '</div>';
			}
			$sections .= '</div>';
		}
		$sections .= '</div>';
	}
	$seen = array();
	$dd   = array();
	foreach ( $list as $b ) {
		$seen[ $b['div'] ?: 'অন্যান্য' ] = 1;
		if ( $b['district'] ) {
			$dd[ $b['district'] ] = 1;
		}
	}
	$opt = '<option value="all">🗺️ সকল বিভাগ</option>';
	foreach ( $o['div_order'] as $d ) {
		if ( isset( $seen[ $d ] ) ) {
			$opt .= '<option value="div:' . esc_attr( $d ) . '">📍 ' . esc_html( $d ) . ' বিভাগ</option>';
		}
	}
	foreach ( $types as $k => $m ) {
		if ( 'main' !== $k && in_array( $k, array_column( $list, 'type' ), true ) ) {
			$opt .= '<option value="type:' . esc_attr( $k ) . '">' . esc_html( $m['icon'] . ' ' . $m['label'] ) . '</option>';
		}
	}
	$stats = '';
	foreach ( (array) $hero['stats'] as $s ) {
		$stats .= '<div class="hst"><div class="hsn">' . esc_html( $s['n'] ?? '' ) . '</div><div class="hsl">' . esc_html( $s['l'] ?? '' ) . '</div></div>';
	}
	$ph = '';
	foreach ( (array) $hq['phones'] as $p ) {
		$ph .= '<a class="hq-ph" href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', csa_br_en( $p ) ) ) . '"><span class="bc-ic">📞</span> <span class="hq-num">' . esc_html( $p ) . '</span></a>';
	}
	$p1  = preg_replace( '/[^\d+]/', '', csa_br_en( ( (array) $hq['phones'] )[0] ?? '' ) );
	$act = '';
	foreach ( (array) $o['actions'] as $a ) {
		if ( '' !== trim( (string) ( $a['t'] ?? '' ) ) && ! empty( $a['u'] ) ) {
			$act .= '<a class="abtn" href="' . esc_url( $a['u'] ) . '"><div class="abic">' . esc_html( $a['ic'] ?? '' ) . '</div><span class="abtxt">' . esc_html( $a['t'] ) . '</span></a>';
		}
	}
	$nm = (string) $o['nearby'];
	$dl = '';
	foreach ( array_keys( $dd ) as $d ) {
		$dl .= '<option value="' . esc_attr( $d ) . '">';
	}
	$data = array();
	foreach ( $list as $b ) {
		$data[] = array( 'i' => $b['id'], 'n' => wp_strip_all_tags( $b['name'] ), 'd' => $b['div'], 's' => $b['district'], 'a' => $b['addr'], 'p' => $b['phones'] ? $b['phones'][0] : '', 'm' => $b['map'], 'la' => $b['lat'], 'lo' => $b['lng'] );
	}
	$cfg = array( 'b' => $data, 'lm' => $o['lm'], 'mode' => $nm, 'def' => $o['default_view'] );
	$GLOBALS['csa_br_done'] = true;
	$fonts = '<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Roboto+Mono:wght@500&display=swap" rel="stylesheet"><link rel="stylesheet" href="' . esc_url( CSA_URL . 'assets/branches.css?ver=' . CSA_VER ) . '">';
	$h  = $fonts . csa_br_typo_head() . '<div class="sbr" id="sbr"><div class="hero"><div class="sp" aria-hidden="true"><span style="left:5%;animation-duration:13s">✦</span><span style="left:20%;animation-duration:10s;animation-delay:2s">★</span><span style="left:40%;animation-duration:15s;animation-delay:1s">✦</span><span style="left:60%;animation-duration:11s;animation-delay:3s">★</span><span style="left:80%;animation-duration:14s;animation-delay:.5s">✦</span><span style="left:95%;animation-duration:9s;animation-delay:2s">★</span></div>';
	$h .= '<div class="hero-in"><h1>' . esc_html( $hero['h1a'] ) . ' <em>' . esc_html( $hero['h1b'] ) . '</em></h1><p>' . esc_html( $hero['sub'] ) . '</p><div class="hero-stats">' . $stats . '</div></div></div>';
	$h .= '<div class="hq"><div class="hq-in"><div class="hq-icon">🏢</div><div class="hq-info"><h2>' . esc_html( $hq['name'] ) . '</h2><div class="addr">📍 <strong>' . esc_html( $hq['addr'] ) . '</strong></div><div class="hq-phones">' . $ph . '</div></div><div class="hq-btns">'
		. ( $p1 ? '<a class="hq-call" href="tel:' . esc_attr( $p1 ) . '">📞 কল করুন</a>' : '' )
		. ( $hq['wa'] ? '<a class="hq-wa" href="https://wa.me/' . esc_attr( preg_replace( '/\D/', '', csa_br_en( $hq['wa'] ) ) ) . '" target="_blank" rel="noopener">💬 WhatsApp</a>' : '' )
		. ( $hq['map'] ? '<a class="hq-map" href="' . esc_url( $hq['map'] ) . '" target="_blank" rel="noopener">🗺️ Google Map</a>' : '' ) . '</div></div></div>';
	if ( $act ) {
		$h .= '<div class="actions"><div class="act-in">' . $act . '</div></div>';
	}
	if ( 'none' !== $nm ) {
		$h .= '<div class="loc"><div class="loc-in"><div class="loc-title">📍 আপনার কাছের শাখাসমূহ</div><div class="loc-prompt" id="sbrLoc"><p>আপনার এলাকার নাম লিখুন' . ( 'district' === $nm ? '' : ' অথবা অবস্থান ব্যবহার করুন' ) . '</p><div class="loc-form">';
		if ( 'gps' !== $nm ) {
			$h .= '<input class="loc-in-f" id="sbrArea" list="sbrDl" placeholder="জেলা বা এলাকা, যেমন: গাজীপুর" autocomplete="off"><button class="lbtn-y" id="sbrFind" type="button">🔍 খুঁজুন</button>';
		}
		if ( 'district' !== $nm ) {
			$h .= '<button class="lbtn-g" id="sbrGps" type="button">📡 আমার অবস্থান (GPS)</button>';
		}
		$h .= '</div><datalist id="sbrDl">' . $dl . '</datalist></div><div class="nearby" id="sbrNear"><div class="nb-head"><h3 id="sbrNbT">📍 কাছের শাখাসমূহ</h3><button class="nb-reset" id="sbrReset" type="button">🔄 পরিবর্তন</button></div><div class="nb-grid" id="sbrNbG"></div></div></div></div>';
	}
	$h .= '<div class="filter"><div class="filter-in"><label for="sbrSel">বিভাগ:</label><select class="div-sel" id="sbrSel">' . $opt . '</select><input class="sbr-q" id="sbrQ" type="search" placeholder="🔍 শাখার নাম বা ঠিকানা খুঁজুন" autocomplete="off"></div></div>';
	$h .= '<div class="bsec" id="sbrList">' . $sections . '<div class="empty-note" id="sbrEmpty" hidden>এই নির্বাচনে কোনো শাখা পাওয়া যায়নি। অন্য বিভাগ বেছে নিন বা খোঁজার শব্দ বদলান।</div></div>';
	if ( ! empty( $o['cta']['t'] ) ) {
		$h .= '<div class="cta"><h3>' . esc_html( $o['cta']['t'] ) . '</h3><p>' . esc_html( $o['cta']['p'] ) . '</p>' . ( $o['cta']['bu'] ? '<a href="' . esc_url( $o['cta']['bu'] ) . '">' . esc_html( '' !== trim( (string) $o['cta']['bt'] ) ? $o['cta']['bt'] : '📄 শাখা আবেদন করুন →' ) . '</a>' : '' ) . '</div>';
	}
	$h .= '</div><script>window.SBR=' . wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . ';</script><script src="' . esc_url( CSA_URL . 'assets/branches.js?ver=' . CSA_VER ) . '" defer></script>';
	return $h;
}

/* ---------- Reading the old hand-made page (BRANCHES / HQ / HERO inside its script) ---------- */
/** Read a JS array/object literal: strict JSON first, then a tolerant pass (bare keys, single quotes, trailing commas). */
function csa_br_js_data( $t ) {
	$j = json_decode( $t, true );
	if ( is_array( $j ) ) {
		return $j;
	}
	$t = preg_replace( '/\/\/[^\r\n"\']*$/m', '', $t );
	$t = preg_replace_callback( "/'((?:[^'\\\\]|\\\\.)*)'/su", function ( $m ) {
		return '"' . str_replace( array( '\\\'', '"' ), array( "'", '\\"' ), $m[1] ) . '"';
	}, $t );
	$t = preg_replace( '/([\{,]\s*)([A-Za-z_$][\w$]*)\s*:/u', '$1"$2":', $t );
	$t = preg_replace( '/,\s*([\]\}])/', '$1', $t );
	$j = json_decode( $t, true );
	return is_array( $j ) ? $j : null;
}

function csa_br_read_page( $pid ) {
	$html = '';
	if ( function_exists( 'csa_sn_read_token' ) ) {
		$r = wp_remote_get( add_query_arg( 'snr', csa_sn_read_token(), get_permalink( $pid ) ), array( 'timeout' => 30, 'sslverify' => false, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
			$html = (string) wp_remote_retrieve_body( $r );
		}
	}
	if ( false === strpos( $html, 'BRANCHES' ) ) { // fall back to the stored page text / Elementor widgets
		$p    = get_post( $pid );
		$html = (string) $p->post_content;
		$ed   = (string) get_post_meta( $pid, '_elementor_data', true );
		if ( $ed ) {
			$j = json_decode( $ed, true );
			if ( is_array( $j ) ) {
				array_walk_recursive( $j, function ( $v ) use ( &$html ) {
					if ( is_string( $v ) && false !== strpos( $v, 'BRANCHES' ) ) {
						$html .= "\n" . $v;
					}
				} );
			}
		}
	}
	$out = array();
	foreach ( array( 'BRANCHES' => 'branches', 'HQ' => 'hq', 'HERO' => 'hero' ) as $var => $key ) {
		if ( preg_match( '/(?:var|let|const)\s+' . $var . '\s*=\s*(\[.*?\]|\{.*?\})\s*;\s*(?:[\r\n]|\/\/|$)/s', $html, $m ) ) {
			$j = csa_br_js_data( html_entity_decode( $m[1], ENT_QUOTES ) );
			if ( is_array( $j ) ) {
				$out[ $key ] = $j;
			}
		}
	}
	return $out;
}

function csa_br_apply_pack( $pack, &$msg ) {
	$added = 0;
	$upd   = 0;
	if ( ! empty( $pack['branches'] ) ) {
		csa_br_merge( $pack['branches'], $added, $upd );
	}
	$o = csa_br_opts();
	if ( ! empty( $pack['hq'] ) && is_array( $pack['hq'] ) ) {
		$h          = $pack['hq'];
		$o['hq']    = array( 'name' => sanitize_text_field( $h['name'] ?? '' ), 'addr' => sanitize_text_field( $h['addr'] ?? '' ), 'phones' => csa_br_phones( $h['phones'] ?? array() ), 'wa' => sanitize_text_field( $h['wa'] ?? '' ), 'map' => esc_url_raw( $h['map'] ?? '' ) );
	}
	if ( ! empty( $pack['hero'] ) && is_array( $pack['hero'] ) ) {
		$h = $pack['hero'];
		$st = array();
		foreach ( (array) ( $h['stats'] ?? array() ) as $s ) {
			$st[] = array( 'n' => sanitize_text_field( $s['n'] ?? '' ), 'l' => sanitize_text_field( $s['l'] ?? '' ) );
		}
		$o['hero'] = array( 'h1a' => sanitize_text_field( $h['h1a'] ?? '' ), 'h1b' => sanitize_text_field( $h['h1b'] ?? '' ), 'sub' => sanitize_text_field( $h['sub'] ?? '' ), 'stats' => $st ? $st : $o['hero']['stats'] );
	}
	foreach ( array( 'div_order', 'dist_order' ) as $k ) {
		if ( ! empty( $pack[ $k ] ) && is_array( $pack[ $k ] ) ) {
			$o[ $k ] = array_values( array_map( 'sanitize_text_field', $pack[ $k ] ) );
		}
	}
	if ( ! empty( $pack['lm'] ) && is_array( $pack['lm'] ) && ! $o['lm'] ) {
		$o['lm'] = array_map( 'sanitize_text_field', $pack['lm'] );
	}
	csa_br_set( $o );
	$msg = $added . 'টি নতুন শাখা যোগ হয়েছে, ' . $upd . 'টি হালনাগাদ হয়েছে। মোট ' . count( csa_br_list() ) . 'টি।';
}

function csa_br_seed() {
	$f = CSA_DIR . 'data/branches-seed.json';
	$j = is_readable( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : array();
	return is_array( $j ) ? $j : array();
}

/* ---------- Admin ---------- */
add_action( 'admin_menu', function () {
	add_submenu_page( 'sef-builder', 'শাখা পেজ', '🏫 শাখা পেজ', 'edit_posts', 'sef-branches', 'csa_br_screen' );
}, 20 );

function csa_br_screen() {
	if ( ! csa_br_can() ) {
		wp_die( 'এই পেজ বদলানোর অনুমতি আপনার নেই।' );
	}
	$o = csa_br_opts();
	wp_enqueue_style( 'csa-br-admin', CSA_URL . 'assets/branches-admin.css', array(), CSA_VER );
	wp_enqueue_script( 'csa-br-admin', CSA_URL . 'assets/branches-admin.js', array(), CSA_VER, true );
	$lp = csa_br_pageid();
	wp_localize_script( 'csa-br-admin', 'BR', array(
		'ajax'     => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'csa_br' ),
		'list'     => csa_br_list(),
		'o'        => array_diff_key( $o, array( 'next' => 1 ) ),
		'isAdmin'  => current_user_can( 'manage_options' ),
		'roles'    => array_map( function ( $k, $r ) { return array( 'k' => $k, 'l' => translate_user_role( $r['name'] ) ); }, array_keys( wp_roles()->roles ), wp_roles()->roles ),
		'page'     => $lp ? array( 'id' => $lp, 'title' => get_the_title( $lp ), 'url' => get_permalink( $lp ) ) : null,
		'export'   => wp_nonce_url( admin_url( 'admin-post.php?action=csa_br_export' ), 'csa_br_export' ),
		'parts'    => array_map( function ( $k, $r ) { return array( 'k' => $k, 'l' => $r[0] ); }, array_keys( csa_br_typo_parts() ), csa_br_typo_parts() ),
		'fonts'    => function_exists( 'csa_font_list' ) ? array_map( function ( $k, $r ) { return array( 'k' => $k, 'l' => $r[0] . ' — ' . $r[3] ); }, array_keys( csa_font_list() ), csa_font_list() ) : array(),
		'seedN'    => count( (array) ( csa_br_seed()['branches'] ?? array() ) ),
	) );
	echo '<div class="wrap" id="csa-br"><h1>🏫 শাখা পেজ</h1><div id="br-app"><p>লোড হচ্ছে…</p></div></div>';
}

add_action( 'admin_post_csa_br_export', function () {
	if ( ! csa_br_can() || ! check_admin_referer( 'csa_br_export' ) ) {
		wp_die( 'No.' );
	}
	$o = csa_br_opts();
	unset( $o['next'] );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="shaheen-branches-' . wp_date( 'Y-m-d' ) . '.json"' );
	echo wp_json_encode( array( 'branches' => csa_br_list(), 'hq' => $o['hq'], 'hero' => $o['hero'], 'actions' => $o['actions'], 'cta' => $o['cta'], 'div_order' => $o['div_order'], 'dist_order' => $o['dist_order'], 'lm' => $o['lm'] ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	exit;
} );

add_action( 'wp_ajax_csa_br_op', function () {
	check_ajax_referer( 'csa_br', 'nonce' );
	if ( ! csa_br_can() ) {
		wp_send_json_error( 'অনুমতি নেই।' );
	}
	$op   = sanitize_key( $_POST['op'] ?? '' );
	$data = json_decode( wp_unslash( $_POST['data'] ?? 'null' ), true );
	$list = csa_br_list();
	$o    = csa_br_opts();
	switch ( $op ) {
		case 'upsert':
			if ( ! is_array( $data ) || '' === trim( (string) ( $data['name'] ?? '' ) ) ) {
				wp_send_json_error( 'শাখার নাম লিখুন।' );
			}
			$id = (int) ( $data['id'] ?? 0 );
			$c  = csa_br_clean( $data, $id );
			if ( $id ) {
				foreach ( $list as $i => $b ) {
					if ( (int) $b['id'] === $id ) {
						$list[ $i ] = $c;
						$id         = 0;
						break;
					}
				}
			}
			if ( $id || ! $c['id'] ) {
				$c['id'] = (int) $o['next']++;
				$list[]  = $c;
				csa_br_set( $o );
			}
			csa_br_put( $list );
			wp_send_json_success( array( 'b' => $c, 'list' => $list ) );
			break;
		case 'delete':
			$id   = (int) $data;
			$list = array_values( array_filter( $list, function ( $b ) use ( $id ) { return (int) $b['id'] !== $id; } ) );
			csa_br_put( $list );
			wp_send_json_success( array( 'list' => $list ) );
			break;
		case 'move': // data: {id, dir}
			$id  = (int) ( $data['id'] ?? 0 );
			$dir = (int) ( $data['dir'] ?? 0 );
			foreach ( $list as $i => $b ) {
				if ( (int) $b['id'] !== $id ) {
					continue;
				}
				for ( $j = $i + $dir; $j >= 0 && $j < count( $list ); $j += $dir ) { // swap with the next branch of the same group
					if ( $list[ $j ]['district'] === $b['district'] && $list[ $j ]['type'] === $b['type'] && $list[ $j ]['div'] === $b['div'] ) {
						$t = $list[ $j ];
						$list[ $j ] = $list[ $i ];
						$list[ $i ] = $t;
						break;
					}
				}
				break;
			}
			csa_br_put( $list );
			wp_send_json_success( array( 'list' => $list ) );
			break;
		case 'sec': // data: {key, val}
			$k = sanitize_key( $data['key'] ?? '' );
			$v = $data['val'] ?? null;
			if ( 'hero' === $k && is_array( $v ) ) {
				$st = array();
				foreach ( (array) ( $v['stats'] ?? array() ) as $s ) {
					$st[] = array( 'n' => sanitize_text_field( $s['n'] ?? '' ), 'l' => sanitize_text_field( $s['l'] ?? '' ) );
				}
				$o['hero'] = array( 'h1a' => sanitize_text_field( $v['h1a'] ?? '' ), 'h1b' => sanitize_text_field( $v['h1b'] ?? '' ), 'sub' => sanitize_text_field( $v['sub'] ?? '' ), 'stats' => $st );
			} elseif ( 'hq' === $k && is_array( $v ) ) {
				$o['hq'] = array( 'name' => sanitize_text_field( $v['name'] ?? '' ), 'addr' => sanitize_text_field( $v['addr'] ?? '' ), 'phones' => csa_br_phones( $v['phones'] ?? array() ), 'wa' => sanitize_text_field( $v['wa'] ?? '' ), 'map' => esc_url_raw( $v['map'] ?? '' ) );
			} elseif ( 'actions' === $k && is_array( $v ) ) {
				$a = array();
				foreach ( $v as $x ) {
					$a[] = array( 'ic' => sanitize_text_field( $x['ic'] ?? '' ), 't' => sanitize_text_field( $x['t'] ?? '' ), 'u' => esc_url_raw( $x['u'] ?? '' ) );
				}
				$o['actions'] = $a;
			} elseif ( 'typo' === $k && is_array( $v ) ) {
				$ty = array();
				foreach ( csa_br_typo_parts() as $pk => $pv ) {
					$r = isset( $v[ $pk ] ) && is_array( $v[ $pk ] ) ? $v[ $pk ] : array();
					$e = array(
						's' => ( '' !== trim( (string) ( $r['s'] ?? '' ) ) && (float) $r['s'] >= 8 && (float) $r['s'] <= 90 ) ? round( (float) $r['s'], 1 ) : '',
						'm' => ( '' !== trim( (string) ( $r['m'] ?? '' ) ) && (float) $r['m'] >= 8 && (float) $r['m'] <= 90 ) ? round( (float) $r['m'], 1 ) : '',
						'f' => ( ! empty( $r['f'] ) && csa_font( sanitize_key( $r['f'] ) ) ) ? sanitize_key( $r['f'] ) : '',
						'b' => in_array( (string) ( $r['b'] ?? '' ), array( '400', '500', '600', '700', '800' ), true ) ? (string) $r['b'] : '',
					);
					if ( '' !== $e['s'] . $e['m'] . $e['f'] . $e['b'] ) {
						$ty[ $pk ] = $e;
					}
				}
				$o['typo'] = $ty;
			} elseif ( 'cta' === $k && is_array( $v ) ) {
				$o['cta'] = array( 't' => sanitize_text_field( $v['t'] ?? '' ), 'p' => sanitize_text_field( $v['p'] ?? '' ), 'bt' => sanitize_text_field( $v['bt'] ?? '' ), 'bu' => esc_url_raw( $v['bu'] ?? '' ) );
			} elseif ( 'settings' === $k && is_array( $v ) ) {
				$o['nearby']       = in_array( $v['nearby'] ?? '', array( 'district', 'gps', 'both', 'none' ), true ) ? $v['nearby'] : 'both';
				$o['default_view'] = sanitize_text_field( $v['default_view'] ?? 'all' );
				$o['div_order']    = array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/[\r\n,]+/u', (string) ( $v['div_order'] ?? '' ) ) ) ) );
				$o['dist_order']   = array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/[\r\n,]+/u', (string) ( $v['dist_order'] ?? '' ) ) ) ) );
				$lm                = array();
				foreach ( preg_split( '/\r?\n/', (string) ( $v['lm'] ?? '' ) ) as $ln ) {
					$pp = array_map( 'trim', explode( '=', $ln, 2 ) );
					if ( 2 === count( $pp ) && '' !== $pp[0] && '' !== $pp[1] ) {
						$lm[ sanitize_text_field( $pp[0] ) ] = sanitize_text_field( $pp[1] );
					}
				}
				$o['lm'] = $lm;
				if ( current_user_can( 'manage_options' ) ) {
					$o['roles'] = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $v['roles'] ?? array() ) ), array_keys( wp_roles()->roles ) ) );
				}
				$ty = array();
				foreach ( (array) ( $v['types'] ?? array() ) as $tk => $t ) {
					$tk = sanitize_key( $tk );
					if ( isset( $o['types'][ $tk ] ) ) {
						$ty[ $tk ] = array( 'label' => sanitize_text_field( $t['label'] ?? '' ), 'icon' => sanitize_text_field( $t['icon'] ?? '' ), 'kicker' => sanitize_text_field( $t['kicker'] ?? '' ), 'tag' => sanitize_text_field( $t['tag'] ?? '' ) );
					}
				}
				$o['types'] = array_merge( $o['types'], $ty );
			} else {
				wp_send_json_error( 'অজানা অংশ।' );
			}
			csa_br_set( $o );
			wp_send_json_success( array( 'o' => array_diff_key( $o, array( 'next' => 1 ) ) ) );
			break;
		case 'connect': // data: {on:0/1}
			$o['replace'] = empty( $data['on'] ) ? 0 : 1;
			if ( ! empty( $data['page'] ) ) {
				$o['page'] = absint( $data['page'] );
			}
			csa_br_set( $o );
			$lp = csa_br_pageid();
			wp_send_json_success( array( 'o' => array_diff_key( $o, array( 'next' => 1 ) ), 'page' => $lp ? array( 'id' => $lp, 'title' => get_the_title( $lp ), 'url' => get_permalink( $lp ) ) : null ) );
			break;
		case 'import_seed':
		case 'import_page':
		case 'import_json':
			$pack = array();
			if ( 'import_seed' === $op ) {
				$pack = csa_br_seed();
			} elseif ( 'import_page' === $op ) {
				$lp = csa_br_pageid();
				if ( ! $lp ) {
					wp_send_json_error( 'শাখা পেজ পাওয়া যায়নি।' );
				}
				$pack = csa_br_read_page( $lp );
				if ( empty( $pack['branches'] ) ) {
					wp_send_json_error( 'পেজ থেকে শাখার তালিকা পড়া যায়নি। (পেজ যদি আগেই plugin-এর হাতে থাকে তাহলে "মূল পেজ" পড়া যায় না — ওই ক্ষেত্রে অন্য উপায় ব্যবহার করুন।)' );
				}
			} else {
				$pack = is_array( $data ) ? $data : array();
				if ( isset( $pack[0] ) ) { // a bare list of branches
					$pack = array( 'branches' => $pack );
				}
			}
			$msg = '';
			csa_br_apply_pack( $pack, $msg );
			$o = csa_br_opts();
			wp_send_json_success( array( 'msg' => $msg, 'list' => csa_br_list(), 'o' => array_diff_key( $o, array( 'next' => 1 ) ) ) );
			break;
		case 'coords': // fill missing coordinates from the map links
			$n = 0;
			foreach ( $list as $i => $b ) {
				if ( ( '' === $b['lat'] || '' === $b['lng'] ) && $b['map'] ) {
					$c = csa_br_coords( $b['map'] );
					if ( $c ) {
						$list[ $i ]['lat'] = $c[0];
						$list[ $i ]['lng'] = $c[1];
						$n++;
					}
				}
			}
			csa_br_put( $list );
			wp_send_json_success( array( 'msg' => $n . 'টি শাখার স্থানাঙ্ক Map লিংক থেকে বসানো হয়েছে।', 'list' => $list ) );
			break;
		case 'wipe':
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'শুধু অ্যাডমিন।' );
			}
			csa_br_put( array() );
			wp_send_json_success( array( 'list' => array() ) );
			break;
	}
	wp_send_json_error( 'অজানা কাজ।' );
} );

/** Branches for the notice form (id, name, district), so a notice can be tied to the branches it is about. */
function csa_br_for_notice() {
	$o = array();
	foreach ( csa_br_list() as $b ) {
		$o[] = array( 'i' => (int) $b['id'], 'n' => wp_strip_all_tags( $b['name'] ), 's' => $b['district'] );
	}
	return $o;
}

function csa_br_name( $id ) {
	foreach ( csa_br_list() as $b ) {
		if ( (int) $b['id'] === (int) $id ) {
			return wp_strip_all_tags( $b['name'] );
		}
	}
	return '';
}

/* ---------- Theme gaps: remove side/top/bottom padding of the theme's wrappers around the branch page ---------- */
add_action( 'wp_footer', function () {
	if ( ! is_page() || ! csa_br_opt( 'replace' ) || (int) get_queried_object_id() !== csa_br_pageid() ) {
		return;
	}
	echo '<script>(function(){function f(){var t=document.getElementById("sbr");if(!t){return;}var m=window.innerWidth<=800,e=t.parentNode,n=0;while(e&&e!==document.body&&n<8){if(e.id!=="page"){var s=e.style;if(m){s.paddingLeft="0";s.paddingRight="0";s.marginLeft="0";s.marginRight="0";s.maxWidth="none";s.width="100%";}s.paddingTop="0";s.paddingBottom="0";}e=e.parentNode;n++;}var h=document.querySelector(".entry-header,.page-header");if(h&&!h.textContent.trim()){h.style.display="none";}}f();window.addEventListener("resize",f);})();</script>';
}, 99 );

/* ---------- Page Builder overview card ---------- */
add_action( 'csa_sn_overview_cards', function () {
	$lp = csa_br_pageid();
	$n  = count( csa_br_list() );
	$on = (bool) csa_br_opt( 'replace' ) && $lp;
	echo '<div class="sn-set"><h2>🏫 শাখা পেজ</h2><p>' . ( $lp ? 'পেজ: <a href="' . esc_url( get_permalink( $lp ) ) . '" target="_blank"><b>' . esc_html( get_the_title( $lp ) ) . '</b></a> — অবস্থা: ' . ( $on ? '<b style="color:#1a6b3e">plugin চালাচ্ছে ✓</b>' : '<b style="color:#b06">এখনো আপনার পুরোনো পেজ</b>' ) : '"branch" পেজ পাওয়া যায়নি।' ) . ' · শাখা: <b>' . (int) $n . '</b>টি</p><p><a class="button button-primary button-large" href="' . esc_url( admin_url( 'admin.php?page=sef-branches' ) ) . '">🏫 শাখা পেজ ম্যানেজ করুন</a></p></div>';
} );
