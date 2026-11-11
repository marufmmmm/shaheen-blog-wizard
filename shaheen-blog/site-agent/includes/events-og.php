<?php
/**
 * Link previews for news links in event page sections (picture + short text).
 * Reads the public Open Graph tags (og:image / og:title / og:description) of each news link on the SERVER, caches them,
 * and the event page shows a Facebook-style card. Nothing is fetched while a visitor loads the page: missing previews are
 * filled in the background (WP-cron) a few seconds after a link is first used, or on demand from the admin screen.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CSA_EV_OG = 'csa_ev_og';

function csa_ev_og_all() {
	$o = get_option( CSA_EV_OG, array() );
	return is_array( $o ) ? $o : array();
}

/** cached preview (array img,t,d) or null */
function csa_ev_og_get( $url ) {
	$o = csa_ev_og_all();
	$k = md5( $url );
	return ( isset( $o[ $k ] ) && ! empty( $o[ $k ]['ok'] ) ) ? $o[ $k ] : null;
}

function csa_ev_og_meta( $html ) {
	$m = array();
	if ( preg_match_all( '/<meta\s[^>]*>/i', $html, $tags ) ) {
		foreach ( $tags[0] as $t ) {
			if ( preg_match( '/(?:property|name)\s*=\s*["\']([^"\']+)["\']/i', $t, $k ) && preg_match( '/content\s*=\s*(["\'])(.*?)\1/is', $t, $v ) ) {
				$key = strtolower( trim( $k[1] ) );
				if ( ! isset( $m[ $key ] ) ) {
					$m[ $key ] = trim( html_entity_decode( $v[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				}
			}
		}
	}
	return $m;
}

/** fetch + store one link's preview; returns the stored row */
function csa_ev_og_fetch( $url ) {
	$row = array( 'ok' => 0, 'img' => '', 't' => '', 'd' => '', 'at' => time() );
	$r   = wp_remote_get( $url, array(
		'timeout'             => 8,
		'redirection'         => 4,
		'limit_response_size' => 400000,
		'user-agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
		'headers'             => array( 'Accept-Language' => 'bn,en;q=0.8' ),
	) );
	if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
		$m   = csa_ev_og_meta( (string) wp_remote_retrieve_body( $r ) );
		$img = $m['og:image'] ?? ( $m['og:image:url'] ?? ( $m['twitter:image'] ?? '' ) );
		if ( $img && 0 === strpos( $img, '//' ) ) {
			$img = 'https:' . $img;
		} elseif ( $img && 0 === strpos( $img, '/' ) ) {
			$p   = wp_parse_url( $url );
			$img = ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' ) . $img;
		}
		$row['img'] = ( $img && preg_match( '#^https?://#i', $img ) ) ? esc_url_raw( $img ) : '';
		$row['t']   = sanitize_text_field( $m['og:title'] ?? ( $m['twitter:title'] ?? '' ) );
		$row['d']   = sanitize_text_field( $m['og:description'] ?? ( $m['description'] ?? ( $m['twitter:description'] ?? '' ) ) );
		$row['ok']  = ( $row['img'] || $row['d'] ) ? 1 : 0;
	}
	$o                   = csa_ev_og_all();
	$o[ md5( $url ) ]    = $row;
	if ( count( $o ) > 600 ) {
		$o = array_slice( $o, -600, null, true );
	}
	update_option( CSA_EV_OG, $o, false );
	return $row;
}

/** urls used as plain links (not YouTube/Facebook embeds) in any event's sections */
function csa_ev_og_urls() {
	$out = array();
	foreach ( (array) csa_ev_list() as $e ) {
		foreach ( (array) ( $e['vsec'] ?? array() ) as $vs ) {
			foreach ( preg_split( '/\r?\n/', (string) ( $vs['lines'] ?? '' ) ) as $ln ) {
				$pt = array_map( 'trim', explode( '|', $ln ) );
				$u  = $pt[1] ?? '';
				if ( preg_match( '#^https?://#i', $u ) && ! csa_ev_yt( $u ) && ! csa_ev_is_fb( $u ) ) {
					$out[ $u ] = 1;
				}
			}
		}
	}
	return array_keys( $out );
}

/** urls that still need a look (never tried, or a failed try older than a day) */
function csa_ev_og_missing( $urls ) {
	$o = csa_ev_og_all();
	$m = array();
	foreach ( $urls as $u ) {
		$k = md5( $u );
		if ( ! isset( $o[ $k ] ) || ( empty( $o[ $k ]['ok'] ) && time() - (int) $o[ $k ]['at'] > DAY_IN_SECONDS ) ) {
			$m[] = $u;
		}
	}
	return $m;
}

/** background fill: up to 6 links per run, runs again if more are left */
add_action( 'csa_ev_og_fill', function () {
	$miss = csa_ev_og_missing( csa_ev_og_urls() );
	foreach ( array_slice( $miss, 0, 6 ) as $u ) {
		csa_ev_og_fetch( $u );
	}
	if ( count( $miss ) > 6 && ! wp_next_scheduled( 'csa_ev_og_fill' ) ) {
		wp_schedule_single_event( time() + 20, 'csa_ev_og_fill' );
	}
	if ( $miss && function_exists( 'csa_ev_purge' ) ) {
		csa_ev_purge();
	}
} );

/** called while rendering: queue a fill if something is missing (cheap, no network) */
function csa_ev_og_queue( $u ) {
	if ( ! wp_next_scheduled( 'csa_ev_og_fill' ) && csa_ev_og_missing( array( $u ) ) ) {
		wp_schedule_single_event( time() + 10, 'csa_ev_og_fill' );
	}
}

/** admin: fetch now. data: {urls:[...]} (max 5 per call) or {all:1} for the next missing ones */
add_action( 'wp_ajax_csa_ev_og_op', function () {
	check_ajax_referer( 'csa_ev', 'nonce' );
	if ( ! csa_ev_can() ) {
		wp_send_json_error( 'অনুমতি নেই।' );
	}
	$d    = json_decode( wp_unslash( $_POST['data'] ?? 'null' ), true );
	$all  = csa_ev_og_urls();
	$urls = ( is_array( $d ) && ! empty( $d['urls'] ) ) ? array_values( array_intersect( array_map( 'esc_url_raw', (array) $d['urls'] ), $all ) ) : csa_ev_og_missing( $all );
	$res  = array();
	foreach ( array_slice( $urls, 0, 5 ) as $u ) {
		$r     = csa_ev_og_fetch( $u );
		$res[] = array( 'u' => $u, 'ok' => (int) $r['ok'], 'img' => $r['img'] ? 1 : 0 );
	}
	if ( $res && function_exists( 'csa_ev_purge' ) ) {
		csa_ev_purge();
	}
	wp_send_json_success( array( 'done' => $res, 'left' => count( csa_ev_og_missing( $all ) ) ) );
} );
