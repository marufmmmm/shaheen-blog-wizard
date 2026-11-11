<?php
/**
 * Auto-add new episodes from YouTube to an event page (Spelling Star).
 *
 * Reads the free public RSS feed of one or more YouTube channels (no API key).
 * Schedule (Dhaka time): a window opens every Friday and Sunday at 20:00 and lasts 48 hours. Inside the window the feed is checked
 * every 3 hours. As soon as a matching new video is found it is added to the event's episodes and checking stops until the next window.
 * If nothing was found, the next window starts the check again, so the episode is picked up as soon as it appears.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CSA_EV_YT = 'csa_ev_yt';

function csa_ev_yt_cfg() {
	$c = get_option( CSA_EV_YT, array() );
	$c = is_array( $c ) ? $c : array();
	return array_merge( array(
		'on'       => 0,
		'event'    => 'spelling-star',
		'channels' => array(),
		'match'    => 'Spelling Star|স্পেলিং স্টার',
		'exclude'  => 'promo|প্রোমো|teaser|টিজার|shorts|trailer',
		'done'     => 0,
		'last'     => 0,
		'log'      => array(),
		'mail'     => 'mosiur@shaheen.edu.bd',
		'adds'     => array(),
	), $c );
}

function csa_ev_yt_save( $c ) {
	update_option( CSA_EV_YT, $c, false );
}

function csa_ev_yt_log( &$c, $msg ) {
	array_unshift( $c['log'], array( 't' => time(), 'm' => $msg ) );
	$c['log'] = array_slice( $c['log'], 0, 25 );
}

/** Start (timestamp) of the check window we are in right now, or 0. Window: Friday/Sunday 20:00 Dhaka, 48 hours. */
function csa_ev_yt_window( $now ) {
	$tz   = new DateTimeZone( 'Asia/Dhaka' );
	$best = 0;
	for ( $i = 0; $i <= 3; $i++ ) {
		$d = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->modify( '-' . $i . ' day' )->setTime( 20, 0, 0 );
		if ( in_array( (int) $d->format( 'w' ), array( 5, 0 ), true ) && $d->getTimestamp() <= $now ) {
			$best = max( $best, $d->getTimestamp() );
		}
	}
	return ( $best && $now < $best + 48 * HOUR_IN_SECONDS ) ? $best : 0;
}

function csa_ev_yt_feed( $channel_id ) {
	$r = wp_remote_get( 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode( $channel_id ), array( 'timeout' => 25 ) );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
		return new WP_Error( 'csa_yt', 'YouTube ফিড পাওয়া যায়নি (HTTP ' . (int) wp_remote_retrieve_response_code( $r ) . ')।' );
	}
	return csa_ev_yt_parse( wp_remote_retrieve_body( $r ) );
}

/** Parse a YouTube Atom feed: array(name, entries => [id, title, published]). */
function csa_ev_yt_parse( $xml ) {
	$prev = libxml_use_internal_errors( true );
	$x    = simplexml_load_string( (string) $xml );
	libxml_use_internal_errors( $prev );
	if ( ! $x ) {
		return new WP_Error( 'csa_yt', 'ফিড পড়া যায়নি।' );
	}
	$out = array( 'name' => (string) $x->title, 'entries' => array() );
	foreach ( $x->entry as $e ) {
		$yt  = $e->children( 'http://www.youtube.com/xml/schemas/2015' );
		$id  = (string) $yt->videoId;
		$pub = strtotime( (string) $e->published );
		if ( '' === $id || ! $pub ) {
			continue;
		}
		$out['entries'][] = array( 'id' => $id, 'title' => trim( (string) $e->title ), 'published' => $pub );
	}
	return $out;
}

/** Channel link, @handle, video link or UC… id -> array(id, name) or WP_Error. */
function csa_ev_yt_resolve( $in ) {
	$in = trim( (string) $in );
	$id = '';
	if ( preg_match( '/^UC[\w-]{22}$/', $in ) ) {
		$id = $in;
	} elseif ( preg_match( '#youtube\.com/channel/(UC[\w-]{22})#', $in, $m ) ) {
		$id = $m[1];
	} else {
		$url = $in;
		if ( preg_match( '/^@[\w.\-]+$/', $in ) ) {
			$url = 'https://www.youtube.com/' . $in;
		} elseif ( ! preg_match( '#^https?://#i', $in ) ) {
			return new WP_Error( 'csa_yt', 'চ্যানেলের লিংক বা @নাম দিন।' );
		}
		if ( ! preg_match( '#^https?://([\w-]+\.)?(youtube\.com|youtu\.be)/#i', $url ) ) {
			return new WP_Error( 'csa_yt', 'এটা YouTube-এর লিংক নয়।' );
		}
		$r = wp_remote_get( $url, array( 'timeout' => 25, 'headers' => array( 'Accept-Language' => 'en' ) ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$b = (string) wp_remote_retrieve_body( $r );
		if ( preg_match( '#channel_id=(UC[\w-]{22})#', $b, $m ) || preg_match( '#"channelId":"(UC[\w-]{22})"#', $b, $m ) || preg_match( '#"externalId":"(UC[\w-]{22})"#', $b, $m ) ) {
			$id = $m[1];
		}
	}
	if ( '' === $id ) {
		return new WP_Error( 'csa_yt', 'এই লিংক থেকে চ্যানেল চেনা যায়নি। চ্যানেলের হোম পেজের লিংক (youtube.com/@নাম) দিন।' );
	}
	$f = csa_ev_yt_feed( $id );
	if ( is_wp_error( $f ) ) {
		return $f;
	}
	return array( 'id' => $id, 'name' => sanitize_text_field( $f['name'] ) );
}

function csa_ev_yt_has( $e, $vid ) {
	foreach ( (array) $e['eps'] as $p ) {
		if ( false !== strpos( (string) $p['u'], $vid ) ) {
			return true;
		}
	}
	return false;
}

/** Episode number from a title ("পর্ব ৪", "Episode 4", "Ep-04"), or 0. */
function csa_ev_yt_num( $s ) {
	$s = strtr( (string) $s, array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' ) );
	return preg_match( '/(?:পর্ব|episode|ep)\.?[\s\-–:#.]*0*(\d{1,3})/iu', $s, $m ) ? (int) $m[1] : 0;
}

/** AI picks the right section for a video that is not an episode. Returns array(index, meta) or WP_Error. Billed: real cost x 1.7. */
function csa_ev_yt_ai_section( $ev, $title ) {
	$key = function_exists( 'sb_get' ) ? sb_get( 'api_key' ) : '';
	if ( '' === (string) $key ) {
		return new WP_Error( 'csa_yt', 'AI চাবি নেই।' );
	}
	$secs = array();
	foreach ( (array) $ev['vsec'] as $i => $v ) {
		$secs[] = $i . ': ' . trim( $v['ic'] . ' ' . $v['t'] ) . ( $v['p'] ? ' — ' . $v['p'] : '' );
	}
	if ( ! $secs ) {
		return new WP_Error( 'csa_yt', 'কোনো ভিডিও সেকশন নেই।' );
	}
	$model = function_exists( 'sb_model_for' ) ? sb_model_for( 'headlines' ) : 'gpt-6.1-terra';
	$body  = array(
		'model' => $model,
		'input' => array(
			array( 'role' => 'system', 'content' => 'You file a new video into one section of a website page. Reply with JSON only: {"section": <number from the list, or -1 if none fits>, "meta": "<2-5 word Bangla label>"}.' ),
			array( 'role' => 'user', 'content' => "Sections:\n" . implode( "\n", $secs ) . "\n\nVideo title: " . $title ),
		),
		'max_output_tokens' => 400,
	);
	if ( preg_match( '/^(gpt-[5-9]|o\d)/i', $model ) ) {
		$body['reasoning'] = array( 'effort' => 'low' );
	}
	if ( function_exists( 'sb_meter_take' ) ) {
		sb_meter_take();
	}
	$r = wp_remote_post( 'https://api.openai.com/v1/responses', array( 'timeout' => 60, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$d = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) || ! is_array( $d ) ) {
		if ( function_exists( 'sb_meter_take' ) ) {
			sb_meter_take();
		}
		return new WP_Error( 'csa_yt', 'AI উত্তর দেয়নি।' );
	}
	$txt = '';
	foreach ( (array) ( $d['output'] ?? array() ) as $it ) {
		foreach ( (array) ( $it['content'] ?? array() ) as $c ) {
			$txt .= $c['text'] ?? '';
		}
	}
	$real = function_exists( 'sb_meter_take' ) ? sb_meter_take() : 0.0;
	if ( $real > 0 && function_exists( 'sb_ledger_add' ) ) {
		$GLOBALS['sb_ledger_model'] = $model;
		sb_ledger_add( 0, 'research_yt', max( 1, (int) ceil( $real * SB_RESEARCH_MULT / sb_unit_billed() ) ), 0, $real );   // AI used: real cost x 1.7
		$GLOBALS['csa_yt_ai_billed'] = true;
	}
	$j = json_decode( preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', trim( $txt ) ), true );
	if ( ! is_array( $j ) || ! isset( $j['section'] ) || (int) $j['section'] < 0 || ! isset( $ev['vsec'][ (int) $j['section'] ] ) ) {
		return new WP_Error( 'csa_yt', 'কোন সেকশনে যাবে বোঝা যায়নি।' );
	}
	return array( (int) $j['section'], sanitize_text_field( (string) ( $j['meta'] ?? '' ) ) );
}

/** Look at the feeds; add new matching videos to the event (episodes to the episode list, others to the right section by AI). Returns array(count, message, added[], fetched). */
function csa_ev_yt_check( &$c, $since ) {
	if ( empty( $c['channels'] ) ) {
		return array( 0, 'কোনো চ্যানেল দেওয়া নেই।', array(), false );
	}
	$list = csa_ev_list();
	$idx  = -1;
	foreach ( $list as $i => $e ) {
		if ( $e['slug'] === $c['event'] ) {
			$idx = $i;
		}
	}
	if ( $idx < 0 ) {
		return array( 0, 'ইভেন্ট পাওয়া যায়নি: ' . $c['event'], array(), false );
	}
	$ev      = $list[ $idx ];
	$found   = array();
	$errs    = array();
	$fetched = false;
	foreach ( $c['channels'] as $ch ) {
		$f = csa_ev_yt_feed( $ch['id'] );
		if ( is_wp_error( $f ) ) {
			$errs[] = $ch['name'] . ': ' . $f->get_error_message();
			continue;
		}
		$fetched = true;
		foreach ( $f['entries'] as $v ) {
			if ( $v['published'] < $since || csa_ev_yt_has( $ev, $v['id'] ) || csa_ev_yt_has_line( $ev, $v['id'] ) ) {
				continue;
			}
			if ( '' === trim( $c['match'] ) || preg_match( '/(' . str_replace( '/', '\/', $c['match'] ) . ')/iu', $v['title'] ) ) {
				$found[ $v['id'] ] = $v;
			}
		}
	}
	if ( ! $found ) {
		return array( 0, 'নতুন পর্ব/ভিডিও পাওয়া যায়নি।' . ( $errs ? ' (' . implode( '; ', $errs ) . ')' : '' ), array(), $fetched );
	}
	usort( $found, function ( $a, $b ) { return $a['published'] <=> $b['published']; } );
	$added = array();
	$notes = array();
	foreach ( $found as $v ) {
		$num = csa_ev_yt_num( $v['title'] );
		$no  = '' !== trim( $c['exclude'] ) && preg_match( '/(' . str_replace( '/', '\/', $c['exclude'] ) . ')/iu', $v['title'] );
		$url = 'https://www.youtube.com/watch?v=' . $v['id'];
		if ( $num > 0 && ! $no ) {   // an episode
			$row = array( 'n' => 'পর্ব ' . csa_ev_bn( sprintf( '%02d', $num ) ), 't' => $v['title'], 'u' => $url, 'd' => gmdate( 'Y-m-d', $v['published'] + 6 * HOUR_IN_SECONDS ), 'img' => '' );
			$rep = -1;
			foreach ( $ev['eps'] as $i => $p ) {
				if ( csa_ev_yt_num( $p['n'] ) === $num ) {   // e.g. the promo of this episode: the full episode takes its place
					$rep = $i;
				}
			}
			if ( $rep >= 0 ) {
				$ev['eps'][ $rep ] = $row;
			} else {
				$ev['eps'][] = $row;
			}
			$added[] = array( 'type' => 'পর্ব', 'where' => 'পর্ব অনুযায়ী দেখুন — ' . $row['n'], 'title' => $v['title'], 'url' => $url );
			continue;
		}
		$ai = csa_ev_yt_ai_section( $ev, $v['title'] );   // not an episode: AI chooses the section
		if ( is_wp_error( $ai ) ) {
			$notes[] = 'বসানো হয়নি (' . $ai->get_error_message() . '): ' . $v['title'];
			continue;
		}
		$meta = $ai[1] ? $ai[1] : csa_ev_date( gmdate( 'Y-m-d', $v['published'] + 6 * HOUR_IN_SECONDS ) );
		$ev['vsec'][ $ai[0] ]['lines'] = rtrim( (string) $ev['vsec'][ $ai[0] ]['lines'] ) . "\n" . str_replace( '|', '-', $v['title'] ) . ' | ' . $url . ' | ' . $meta;
		$added[] = array( 'type' => 'ভিডিও', 'where' => trim( $ev['vsec'][ $ai[0] ]['ic'] . ' ' . $ev['vsec'][ $ai[0] ]['t'] ), 'title' => $v['title'], 'url' => $url );
	}
	if ( $added ) {
		$list[ $idx ] = $ev;
		csa_ev_put( $list );
	}
	$msg = $added ? '✅ যোগ হয়েছে: ' . implode( ' | ', array_map( function ( $a ) { return $a['where'] . ' ← ' . $a['title']; }, $added ) ) : 'কিছু বসানো যায়নি।';
	return array( count( $added ), $msg . ( $notes ? ' · ' . implode( ' · ', $notes ) : '' ), $added, $fetched );
}

function csa_ev_yt_has_line( $e, $vid ) {
	foreach ( (array) $e['vsec'] as $v ) {
		if ( false !== strpos( (string) $v['lines'], $vid ) ) {
			return true;
		}
	}
	return false;
}

/** Mail the people who watch this: what was added now and in the last 24 hours. */
function csa_ev_yt_mail( &$c, $added, $event ) {
	foreach ( $added as $a ) {
		$c['adds'][] = array( 't' => time(), 'type' => $a['type'], 'where' => $a['where'], 'title' => $a['title'], 'url' => $a['url'] );
	}
	$c['adds'] = array_values( array_filter( $c['adds'], function ( $x ) { return $x['t'] > time() - 7 * DAY_IN_SECONDS; } ) );
	$to = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) $c['mail'] ) ) );
	if ( ! $to ) {
		return;
	}
	$lines = array( 'এই মুহূর্তে বসানো হয়েছে:' );
	foreach ( $added as $a ) {
		$lines[] = '• [' . $a['type'] . '] ' . $a['title'] . "\n  কোথায়: " . $a['where'] . "\n  লিংক: " . $a['url'];
	}
	$lines[] = '';
	$lines[] = 'গত ২৪ ঘণ্টায় মোট যা বসেছে:';
	$n = 0;
	foreach ( $c['adds'] as $x ) {
		if ( $x['t'] >= time() - DAY_IN_SECONDS ) {
			$lines[] = '• ' . wp_date( 'j M, H:i', $x['t'] ) . ' — [' . $x['type'] . '] ' . $x['title'] . ' → ' . $x['where'] . "\n  " . $x['url'];
			$n++;
		}
	}
	$lines[] = '';
	$lines[] = 'পেজ: ' . home_url( '/' . $event . '/' );
	wp_mail( $to, '[' . get_bloginfo( 'name' ) . '] ' . count( $added ) . 'টি নতুন ভিডিও/লিংক বসানো হয়েছে (গত ২৪ ঘণ্টায় ' . $n . 'টি)', implode( "\n", $lines ) );
}

/** Hourly: decide whether this is a time to look. */
function csa_ev_yt_tick( $force = false ) {
	$c = csa_ev_yt_cfg();
	if ( ! $force && ! $c['on'] ) {
		return;
	}
	$now = time();
	$w   = csa_ev_yt_window( $now );
	if ( $force ) {
		$since = $now - 7 * DAY_IN_SECONDS;
	} else {
		if ( ! $w || (int) $c['done'] === $w || $now - (int) $c['last'] < 3 * HOUR_IN_SECONDS - 600 ) {
			return;
		}
		$since = $w - 4 * HOUR_IN_SECONDS;
	}
	$c['last'] = $now;
	$GLOBALS['csa_yt_ai_billed'] = false;
	list( $n, $msg, $added, $fetched ) = csa_ev_yt_check( $c, $since );
	if ( $fetched && empty( $GLOBALS['csa_yt_ai_billed'] ) && function_exists( 'sb_ledger_add' ) && defined( 'SB_YT_CHECK_FEE' ) ) {
		// no AI used in this look: a fixed fee per search
		$GLOBALS['sb_bill_override'] = SB_YT_CHECK_FEE;
		$GLOBALS['sb_ledger_model']  = '';
		sb_ledger_add( 0, 'yt_watch', max( 1, (int) ceil( SB_YT_CHECK_FEE / sb_unit_billed() ) ), 0, 0.0 );
		unset( $GLOBALS['sb_bill_override'] );
	}
	if ( $n > 0 ) {
		csa_ev_yt_mail( $c, $added, $c['event'] );
		if ( $w && ! $force ) {
			$c['done'] = $w;   // found: stop until the next window
		}
	}
	csa_ev_yt_log( $c, ( $force ? '[এখনই দেখা] ' : '' ) . $msg );
	csa_ev_yt_save( $c );
}

add_filter( 'cron_schedules', function ( $s ) {
	if ( ! isset( $s['hourly'] ) ) {
		$s['hourly'] = array( 'interval' => HOUR_IN_SECONDS, 'display' => 'Hourly' );
	}
	return $s;
} );

add_action( 'init', function () {
	$c    = csa_ev_yt_cfg();
	$next = wp_next_scheduled( 'csa_ev_yt_tick' );
	if ( $c['on'] && ! $next ) {
		wp_schedule_event( time() + 300, 'hourly', 'csa_ev_yt_tick' );
	} elseif ( ! $c['on'] && $next ) {
		wp_clear_scheduled_hook( 'csa_ev_yt_tick' );
	}
}, 30 );
add_action( 'csa_ev_yt_tick', 'csa_ev_yt_tick' );

/* ---------------- admin ---------------- */
add_action( 'wp_ajax_csa_ev_yt_op', function () {
	check_ajax_referer( 'csa_ev', 'nonce' );
	if ( ! csa_ev_can() ) {
		wp_send_json_error( 'অনুমতি নেই।' );
	}
	$op = sanitize_key( (string) ( $_POST['op'] ?? '' ) ); // phpcs:ignore
	$c  = csa_ev_yt_cfg();
	if ( 'save' === $op ) {
		$c['on']      = ! empty( $_POST['on'] ) && '0' !== $_POST['on'] ? 1 : 0; // phpcs:ignore
		$c['event']   = sanitize_title( wp_unslash( $_POST['event'] ?? 'spelling-star' ) ) ?: 'spelling-star'; // phpcs:ignore
		$c['match']   = sanitize_text_field( wp_unslash( $_POST['match'] ?? '' ) ); // phpcs:ignore
		$c['exclude'] = sanitize_text_field( wp_unslash( $_POST['exclude'] ?? '' ) ); // phpcs:ignore
		$c['mail']    = sanitize_text_field( wp_unslash( $_POST['mail'] ?? '' ) ); // phpcs:ignore
		$new          = array();
		$errs         = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) wp_unslash( $_POST['channels'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) as $line ) { // phpcs:ignore
			$line = trim( $line );
			$have = null;
			foreach ( $c['channels'] as $ch ) {
				if ( ( $ch['src'] ?? '' ) === $line ) {
					$have = $ch;
				}
			}
			if ( $have ) {
				$new[] = $have;
				continue;
			}
			$r = csa_ev_yt_resolve( $line );
			if ( is_wp_error( $r ) ) {
				$errs[] = $line . ' → ' . $r->get_error_message();
			} else {
				$r['src'] = $line;
				$new[]    = $r;
			}
		}
		$c['channels'] = $new;
		csa_ev_yt_save( $c );
		if ( $errs ) {
			wp_send_json_error( 'সংরক্ষণ হয়েছে, কিন্তু: ' . implode( ' · ', $errs ) );
		}
		wp_send_json_success( array( 'msg' => 'সংরক্ষিত ✓' . ( $c['on'] ? ' (অটো চালু)' : ' (অটো বন্ধ)' ), 'cfg' => csa_ev_yt_view() ) );
	}
	if ( 'now' === $op ) {
		csa_ev_yt_tick( true );
		wp_send_json_success( array( 'msg' => 'দেখা হয়েছে।', 'cfg' => csa_ev_yt_view() ) );
	}
	wp_send_json_error( 'অজানা কাজ।' );
} );

function csa_ev_yt_view() {
	$c = csa_ev_yt_cfg();
	$w = csa_ev_yt_window( time() );
	$log = array();
	foreach ( $c['log'] as $l ) {
		$log[] = wp_date( 'j M, H:i', $l['t'] ) . ' — ' . $l['m'];
	}
	return array(
		'on' => (int) $c['on'], 'mail' => $c['mail'], 'event' => $c['event'], 'match' => $c['match'], 'exclude' => $c['exclude'],
		'channels' => implode( "\n", array_map( function ( $ch ) { return $ch['src'] ?? $ch['id']; }, $c['channels'] ) ),
		'names' => array_map( function ( $ch ) { return $ch['name']; }, $c['channels'] ),
		'state' => ! $c['on'] ? 'অটো বন্ধ' : ( $w ? ( (int) $c['done'] === $w ? 'এই সপ্তাহের পর্ব পাওয়া গেছে, পরের শুক্র/রবি রাত ৮টায় আবার শুরু' : 'পর্বের জন্য দেখা চলছে (প্রতি ৩ ঘণ্টা পর পর)' ) : 'অপেক্ষা: পরের শুক্র/রবি রাত ৮টা' ),
		'log' => $log,
	);
}

function csa_ev_yt_card() {
	?>
	<div class="wrap" id="csa-evyt" style="max-width:900px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;margin-top:20px">
		<h2 style="margin-top:0">▶ YouTube থেকে নতুন পর্ব নিজে নিজে বসানো</h2>
		<p>প্রতি শুক্র ও রবিবার রাত ৮টা থেকে পরের ২ দিন, প্রতি ৩ ঘণ্টা পর পর নিচের চ্যানেলে নতুন পর্ব দেখা হয়। পর্ব পেলে পর্ব-তালিকায় বসে যায় ও দেখা বন্ধ হয়; না পেলে পরের শুক্র/রবি আবার শুরু হয়। পর্ব ছাড়া অন্য ভিডিও পেলে AI ঠিক সেকশন বেছে সেখানে বসায়। প্রতিবার বসানোর পর ইমেইল যায়।</p>
		<p><label><input type="checkbox" id="yt-on"> অটো চালু</label> &nbsp; <b id="yt-state"></b></p>
		<p><label>ইভেন্ট (slug)<br><input type="text" id="yt-event" style="width:260px"></label></p>
		<p><label>YouTube চ্যানেল (প্রতি লাইনে একটি: চ্যানেলের লিংক বা @নাম)<br><textarea id="yt-ch" rows="3" style="width:100%" placeholder="https://www.youtube.com/@channeli"></textarea></label></p>
		<p><label>শিরোনামে থাকতে হবে (| দিয়ে আলাদা)<br><input type="text" id="yt-match" style="width:100%"></label></p>
		<p><label>শিরোনামে থাকলে বাদ (প্রোমো ইত্যাদি)<br><input type="text" id="yt-ex" style="width:100%"></label></p>
		<p><label>নতুন কিছু বসলে ইমেইল যাবে (কমা দিয়ে একাধিক)<br><input type="text" id="yt-mail" style="width:100%"></label></p>
		<p><button type="button" class="button button-primary" id="yt-save">সংরক্ষণ</button> <button type="button" class="button" id="yt-now">এখনই দেখুন (গত ৭ দিন)</button> <span id="yt-msg"></span></p>
		<p id="yt-names" class="description"></p>
		<details><summary>সর্বশেষ দেখার হিসাব</summary><ul id="yt-log"></ul></details>
	</div>
	<script>
	(function(){
		var nonce=<?php echo wp_json_encode( wp_create_nonce( 'csa_ev' ) ); ?>,ajax=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,V=<?php echo wp_json_encode( csa_ev_yt_view() ); ?>;
		var $=function(s){return document.querySelector(s);};
		function fill(v){$('#yt-on').checked=!!v.on;$('#yt-event').value=v.event;$('#yt-ch').value=v.channels;$('#yt-match').value=v.match;$('#yt-ex').value=v.exclude;$('#yt-mail').value=v.mail;$('#yt-state').textContent=v.state;
			$('#yt-names').textContent=v.names.length?'চেনা চ্যানেল: '+v.names.join(', '):'';var u=$('#yt-log');u.innerHTML='';v.log.forEach(function(t){var l=document.createElement('li');l.textContent=t;u.appendChild(l);});}
		function msg(t,bad){var m=$('#yt-msg');m.textContent=t;m.style.color=bad?'#b32d2e':'#1a6b3e';}
		function call(op,extra){msg('…');return fetch(ajax,{method:'POST',credentials:'same-origin',body:new URLSearchParams(Object.assign({action:'csa_ev_yt_op',nonce:nonce,op:op},extra||{}))}).then(function(r){return r.json();}).then(function(j){if(j.success){msg(j.data.msg);fill(j.data.cfg);}else{msg(j.data,true);}});}
		$('#yt-save').onclick=function(){call('save',{on:$('#yt-on').checked?'1':'0',event:$('#yt-event').value,channels:$('#yt-ch').value,match:$('#yt-match').value,exclude:$('#yt-ex').value,mail:$('#yt-mail').value});};
		$('#yt-now').onclick=function(){call('now');};
		fill(V);
	})();
	</script>
	<?php
}
