<?php
/**
 * Background autopilot: runs the SEO checklist on one or many pages on the server.
 * It keeps going when the browser is closed (WP-Cron), and runs faster while the screen is open.
 * One small step (one AI call) at a time; state lives in an option.
 */
defined( 'ABSPATH' ) || exit;

const CSA_AP_OPT = 'csa_ap_state';

function csa_ap_get() {
	$s = get_option( CSA_AP_OPT, array() );
	return is_array( $s ) ? $s : array();
}

function csa_ap_put( $s ) {
	$s['updated'] = time();
	update_option( CSA_AP_OPT, $s, false );
}

function csa_ap_running() {
	$s = csa_ap_get();
	return ! empty( $s['status'] ) && 'running' === $s['status'];
}

/** Start a run. $ids: page ids in order. */
function csa_ap_start( $ids, $auto_kw, $skip_high ) {
	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	csa_ap_put( array(
		'status'    => 'running',
		'user'      => get_current_user_id(),
		'queue'     => $ids,
		'i'         => 0,
		'stage'     => 'scan',
		'todo'      => array(),
		'row'       => array(),
		'rows'      => array(),
		'auto_kw'   => $auto_kw ? 1 : 0,
		'skip_high' => $skip_high ? 1 : 0,
		'started'   => time(),
		'msg'       => '',
	) );
	csa_ap_schedule( 3 );
}

function csa_ap_schedule( $in = 20 ) {
	if ( ! wp_next_scheduled( 'csa_ap_cron' ) ) {
		wp_schedule_single_event( time() + $in, 'csa_ap_cron' );
	}
}

function csa_ap_row_new( $id ) {
	return array( 'id' => $id, 'title' => get_the_title( $id ), 'done' => array(), 'failed' => array(), 'owner' => array(), 'first' => null, 'now' => null, 'note' => '' );
}

function csa_ap_next_page( &$s ) {
	$s['rows'][] = $s['row'];
	$s['row']    = array();
	$s['todo']   = array();
	$s['i']++;
	$s['stage'] = 'scan';
	if ( $s['i'] >= count( $s['queue'] ) ) {
		$s['status'] = 'done';
		$s['msg']    = '';
	}
}

/** Do ONE small step. Returns true while there is more to do. */
function csa_ap_step() {
	$s = csa_ap_get();
	if ( empty( $s['status'] ) || 'running' !== $s['status'] ) {
		return false;
	}
	if ( ! empty( $s['user'] ) ) {
		wp_set_current_user( (int) $s['user'] );
	}
	$id = (int) ( $s['queue'][ $s['i'] ] ?? 0 );
	if ( ! $id || ! get_post( $id ) ) {
		$s['row'] = $s['row'] ? $s['row'] : array( 'id' => $id, 'title' => '#' . $id, 'done' => array(), 'failed' => array(), 'owner' => array(), 'first' => null, 'now' => null, 'note' => 'page not found' );
		csa_ap_next_page( $s );
		csa_ap_put( $s );
		return 'running' === $s['status'];
	}
	if ( empty( $s['row'] ) ) {
		$s['row'] = csa_ap_row_new( $id );
	}
	$row =& $s['row'];
	$ttl = $row['title'];

	if ( 'scan' === $s['stage'] ) {
		$s['msg'] = ( $s['i'] + 1 ) . ' / ' . count( $s['queue'] ) . ': ' . $ttl . ' — scanning';
		$b = csa_jobs_build( $id );
		if ( is_wp_error( $b ) ) {
			$row['note'] = $b->get_error_message();
			csa_ap_next_page( $s );
			csa_ap_put( $s );
			return 'running' === $s['status'];
		}
		$row['first'] = $row['now'] = $b['score']['score'];
		if ( ! empty( $s['skip_high'] ) && $b['score']['score'] >= 90 ) {
			$row['note'] = 'already 90+, skipped';
			csa_ap_next_page( $s );
			csa_ap_put( $s );
			return 'running' === $s['status'];
		}
		$kj = null;
		foreach ( $b['jobs'] as $j ) {
			if ( 'keyword' === $j['kind'] && 'done' !== $j['status'] ) {
				$kj = $j;
			}
		}
		if ( $kj && ! empty( $s['auto_kw'] ) ) {
			$s['stage'] = 'kw';
			$s['kw_n']  = $kj['n'];
		} else {
			$s['stage'] = 'jobs';
			$s['todo']  = csa_ap_todo( $b['jobs'] );
		}
		csa_ap_put( $s );
		return true;
	}

	if ( 'kw' === $s['stage'] ) {
		$s['msg'] = ( $s['i'] + 1 ) . ' / ' . count( $s['queue'] ) . ': ' . $ttl . ' — choosing keyword';
		csa_ap_put( $s );
		$r = csa_jobs_run_one( $id, (int) $s['kw_n'] );
		if ( is_wp_error( $r ) || ( 'fail' === ( $r['job']['status'] ?? '' ) ) ) {
			$r = csa_jobs_run_one( $id, (int) $s['kw_n'] ); // one retry
		}
		$s = csa_ap_get();
		$row =& $s['row'];
		if ( ! is_wp_error( $r ) && 'choose' === ( $r['job']['status'] ?? '' ) && ! empty( $r['job']['options'][0]['keyword'] ) ) {
			$kw = $r['job']['options'][0]['keyword'];
			$k  = csa_jobs_apply_kw( $id, (int) $s['kw_n'], $kw );
			if ( ! is_wp_error( $k ) ) {
				$row['done'][] = '🔑 Focus keyword: ' . $kw;
				$s['todo']     = csa_ap_todo( $k['jobs'] );
			} else {
				$row['failed'][] = '🔑 ' . $k->get_error_message();
				$s['todo'] = csa_ap_todo( csa_jobs_get( $id ) );
			}
		} else {
			$row['failed'][] = '🔑 ' . ( is_wp_error( $r ) ? $r->get_error_message() : ( $r['job']['result'] ?? 'keyword failed' ) );
			$s['todo'] = csa_ap_todo( csa_jobs_get( $id ) );
		}
		// Without a keyword the text jobs cannot run: they will fail with a clear message.
		$s['stage'] = 'jobs';
		csa_ap_put( $s );
		return true;
	}

	// stage jobs
	if ( $s['todo'] ) {
		$n   = (int) array_shift( $s['todo'] );
		$job = null;
		foreach ( csa_jobs_get( $id ) as $j ) {
			if ( (int) $j['n'] === $n ) {
				$job = $j;
			}
		}
		$s['msg'] = ( $s['i'] + 1 ) . ' / ' . count( $s['queue'] ) . ': ' . $ttl . ' — ' . ( $job ? $job['title'] : '' );
		csa_ap_put( $s );
		$r  = csa_jobs_run_one( $id, $n );
		$s  = csa_ap_get();
		$row =& $s['row'];
		if ( is_wp_error( $r ) ) {
			$row['failed'][] = ( $job ? $job['title'] . ': ' : '' ) . $r->get_error_message();
		} else {
			if ( null !== $r['own'] ) {
				$row['now'] = $r['own'];
			}
			if ( 'done' === $r['job']['status'] ) {
				$row['done'][] = '✅ ' . $r['job']['done_title'];
			} elseif ( 'fail' === $r['job']['status'] ) {
				$row['failed'][] = '❌ ' . $r['job']['title'] . ' — ' . $r['job']['result'];
			}
		}
		csa_ap_put( $s );
		return true;
	}
	// finish this page
	foreach ( csa_jobs_get( $id ) as $j ) {
		if ( 'owner' === $j['kind'] && 'done' !== $j['status'] ) {
			$row['owner'][] = preg_replace( '/^YOU( \(optional\))?: /', '', $j['title'] );
		}
	}
	$sc = csa_seo_score( $id );
	if ( ! is_wp_error( $sc ) ) {
		$row['now'] = $sc['score'];
	}
	csa_ap_next_page( $s );
	csa_ap_put( $s );
	return 'running' === $s['status'];
}

function csa_ap_todo( $jobs ) {
	$out = array();
	foreach ( $jobs as $j ) {
		if ( in_array( $j['kind'], array( 'seo_meta', 'h1_fix', 'h1_kw', 'intro_kw', 'h2', 'content', 'image_place', 'schema', 'alt' ), true ) && ! in_array( $j['status'], array( 'done', 'skip' ), true ) ) {
			$out[] = (int) $j['n'];
		}
	}
	return $out;
}

/** Run steps for up to $secs seconds, one at a time (a lock keeps two runners apart). */
function csa_ap_work( $secs = 45 ) {
	if ( get_transient( 'csa_ap_lock' ) ) {
		return;
	}
	set_transient( 'csa_ap_lock', 1, 150 );
	@set_time_limit( 0 );
	$end = time() + $secs;
	try {
		while ( time() < $end && csa_ap_step() ) {
			set_transient( 'csa_ap_lock', 1, 150 );
		}
	} finally {
		delete_transient( 'csa_ap_lock' );
	}
	if ( csa_ap_running() ) {
		csa_ap_schedule( 5 );
	}
}

add_action( 'csa_ap_cron', function () {
	csa_ap_work( 50 );
	if ( function_exists( 'spawn_cron' ) && csa_ap_running() ) {
		spawn_cron();
	}
} );

/** Safety net: if a run looks stalled, wake it up when anyone opens the admin. */
add_action( 'admin_init', function () {
	$s = csa_ap_get();
	if ( ! empty( $s['status'] ) && 'running' === $s['status'] && time() - (int) ( $s['updated'] ?? 0 ) > 240 ) {
		delete_transient( 'csa_ap_lock' );
		csa_ap_schedule( 1 );
	}
} );

/* ---------------- Ajax ---------------- */

function csa_ap_public( $s ) {
	return array(
		'status'  => $s['status'] ?? 'idle',
		'msg'     => $s['msg'] ?? '',
		'i'       => (int) ( $s['i'] ?? 0 ),
		'total'   => count( (array) ( $s['queue'] ?? array() ) ),
		'rows'    => $s['rows'] ?? array(),
		'current' => $s['row'] ?? array(),
		'started' => (int) ( $s['started'] ?? 0 ),
	);
}

add_action( 'wp_ajax_csa_ap_start', function () {
	csa_guard( 'chat' );
	if ( csa_ap_running() && time() - (int) ( csa_ap_get()['updated'] ?? 0 ) < 600 ) {
		wp_send_json_error( csa_t( 'An autopilot run is already going. Stop it first.' ) );
	}
	$scope = sanitize_key( $_POST['scope'] ?? 'one' );
	if ( 'one' === $scope ) {
		$ids = array( (int) ( $_POST['id'] ?? 0 ) );
	} else {
		$types = in_array( $scope, csa_seo_types(), true ) ? array( $scope ) : csa_seo_types();
		$ids   = get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'numberposts' => 60, 'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids' ) );
	}
	$ids = array_filter( $ids, function ( $i ) { return csa_valid_post( $i ); } );
	if ( ! $ids ) {
		wp_send_json_error( csa_t( 'No pages found.' ) );
	}
	csa_ap_start( $ids, ! empty( $_POST['auto_kw'] ), ! empty( $_POST['skip_high'] ) );
	wp_send_json_success( csa_ap_public( csa_ap_get() ) );
} );

/** Polling: also does a little work while the screen is open, so it is faster than cron alone. */
add_action( 'wp_ajax_csa_ap_status', function () {
	csa_guard( 'chat' );
	if ( ! empty( $_POST['work'] ) && csa_ap_running() ) {
		csa_ap_work( 25 );
	}
	wp_send_json_success( csa_ap_public( csa_ap_get() ) );
} );

add_action( 'wp_ajax_csa_ap_stop', function () {
	csa_guard( 'chat' );
	$s = csa_ap_get();
	if ( ! empty( $s['status'] ) && 'running' === $s['status'] ) {
		$s['status'] = 'stopped';
		$s['msg']    = '';
		if ( ! empty( $s['row'] ) ) {
			$s['rows'][] = $s['row'];
			$s['row']    = array();
		}
		csa_ap_put( $s );
	}
	wp_send_json_success( csa_ap_public( csa_ap_get() ) );
} );
