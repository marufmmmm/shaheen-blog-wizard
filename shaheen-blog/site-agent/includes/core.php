<?php
/**
 * Core helpers: settings, permissions, Claude API, token allowance, activity log with undo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CSA_OPT        = 'csa_settings';
const CSA_MEM        = 'csa_memory';
const CSA_LOG        = 'csa_activity';
const CSA_USAGE      = 'csa_usage';
const CSA_ALERTS     = 'csa_alerts';
const CSA_API        = 'https://api.anthropic.com/v1/messages';
const CSA_JSON       = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
const CSA_THRESHOLDS = array( 15, 40, 70, 90 ); // Only 4 reminders a month; at 100% requests stop with a message.

/* ------------------------------------------------------------------ */
/* Settings                                                            */
/* ------------------------------------------------------------------ */

/** Built-in design standard that the AI follows for layout, gap, font and mobile work. Editable in Settings. */
function csa_design_std_default() {
	return <<<'STD'
DESIGN STANDARD (applies to every page; follow it whenever you design, fix or review layout, gaps, fonts or mobile view)

Spacing scale: use only 8, 16, 24, 32, 40, 48, 64, 80 px. Never odd values like 53px.

MOBILE (max-width: 767px)
- Side padding of every section and container: 16px (max 20px).
- Section top/bottom padding: 32-40px. Hero: 40-48px. Small strips: 24-32px.
- Gap between two sections comes ONLY from section padding, no extra margins. The visible space between the content of two neighbouring sections is at most 72px (for example 36 + 36). With different background colours 40 + 40 is allowed.
- Heading to paragraph 12px. Paragraph to paragraph 12-16px. Paragraph to button 20-24px.
- Cards/boxes: 16px gap, one per row (small icon cards may be two per row).
- Body text 16px (never below 15px), line-height 1.7 for Bangla and 1.6 for English.
- H1 28-32px (never above 34px). H2 22-26px. H3 18-20px.
- Buttons: font 16px, height 44-48px, full width. Every tap target at least 44px.
- Images: width 100%, height auto, hero image at most 280px high. No fixed width above 400px. No horizontal scrolling.

TABLET (max-width: 1024px): side padding 24px, section padding 48-56px, cards two per row, H1 36px.

DESKTOP: content width max 1140-1200px centred. Section padding 64-80px (hero 80-100px). Column gap 24-32px. H1 40-48px, H2 30-36px, body 16-18px.

CONSISTENCY: one font family per page (Bangla: Hind Siliguri or Noto Sans Bengali). Same button style in every section. One job per section: a heading, one short text, then one action (button or image).

HOW TO WORK: for any layout, gap, font or mobile request first run design_audit, compare the page with this standard and list the problems by priority with the exact px values you will use. Fix with set_page_css scoped to the page: @media (max-width:767px) for phone, @media (max-width:1024px) for tablet. Do not change the desktop look unless asked. After a fix tell the owner which section ids and which px values changed. Never invent contact numbers, fees or dates.
STD;
}

function csa_defaults() {
	return array(
		'api_key'          => '',
		'key_moonshot'     => '',
		'key_deepseek'     => '',
		'key_gemini'       => '',
		'key_openrouter'   => '',
		'key_openai'       => '',
		'm_chat'           => 'gpt-terra',
		'm_blog'           => 'gpt-terra',
		'm_seo'            => 'gpt-terra',
		'm_bulk'           => 'gpt-terra',
		'm_vision'         => 'gpt-terra',
		'custom_models'    => '',
		'brand'            => 'Blog Write .ai',
		'ui_lang'          => 'bn',
		'budget_usd'       => 20,
		'allowance_tokens' => 50000000,
		'allow_live'       => 0,
		'seo_lang'         => 'Bangla',
		'roles_chat'       => array( 'administrator' ),
		'roles_seo'        => array( 'administrator', 'editor' ),
		'roles_blog'       => array( 'administrator', 'editor' ),
		'roles_images'     => array( 'administrator', 'editor' ),
		'weekly_email'     => 1,
		'design_std'       => '',
		'kw_strategy'      => '',
		'kw_branch_words'  => '',
		'email_to'         => get_option( 'admin_email' ),
		'weekly_audit'     => 0,
		'weekly_pages'     => 10,
		'bot_author'       => 1,
		'psi_key'          => '',
		'history_limit'    => 200,
		'max_request_tokens' => 60000,
		'job_mode'         => 'one',
		'economy'          => 0,
		'max_steps'        => 25,
		'build_steps'      => 60,
		'history_turns'    => 6,
		'font_head'        => '',
		'font_body'        => '',
		'font_force'       => 1,
		'fs_body'          => '',
		'fs_body_m'        => '',
		'fs_h1'            => '',
		'fs_h1_m'          => '',
		'fs_h2'            => '',
		'fs_h2_m'          => '',
		'fs_h3'            => '',
		'fs_h3_m'          => '',
		'lh_body'          => '',
		'chat_cap_pct'     => 10,
		'day_cap_pct'      => 20,
		'slot_day'         => 'sunday',
		'slot_time'        => '10:00',
	);
}

function csa_opts() {
	static $cache = null;
	if ( null === $cache ) {
		$o     = get_option( CSA_OPT, array() );
		$cache = wp_parse_args( is_array( $o ) ? $o : array(), csa_defaults() );
	}
	return $cache;
}

function csa_opt( $key ) {
	$o = csa_opts();
	return $o[ $key ] ?? null;
}

/** Economy mode: conservative limits that keep every request small. */
function csa_eco() {
	return (bool) csa_opt( 'economy' );
}

/** Pick the economy or normal value. */
function csa_eco_val( $eco, $normal ) {
	return csa_eco() ? $eco : $normal;
}

function csa_day_cost() {
	$d = get_option( 'csa_usage_day', array() );
	return (float) ( ( is_array( $d ) ? $d : array() )[ current_time( 'Y-m-d' ) ] ?? 0 );
}

function csa_chat_cost( $chat_id ) {
	$c = get_option( 'csa_chatcost', array() );
	return (float) ( ( is_array( $c ) ? $c : array() )[ (int) $chat_id ] ?? 0 );
}

function csa_brand() {
	return 'Site Agent';
}

function csa_memory() {
	$m = get_option( CSA_MEM, array() );
	return wp_parse_args( is_array( $m ) ? $m : array(), array( 'instructions' => '', 'notes' => '' ) );
}

/* ------------------------------------------------------------------ */
/* Permissions                                                         */
/* ------------------------------------------------------------------ */

/** Sections: chat, seo, blog, images. Admin-only: memory, activity, settings. */
function csa_can( $section ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	$roles = csa_opt( 'roles_' . $section );
	if ( ! is_array( $roles ) ) {
		return false;
	}
	return (bool) array_intersect( (array) wp_get_current_user()->roles, $roles );
}

function csa_can_any() {
	foreach ( array( 'chat', 'seo', 'blog', 'images' ) as $s ) {
		if ( csa_can( $s ) ) {
			return true;
		}
	}
	return false;
}

function csa_guard( $section ) {
	if ( ! check_ajax_referer( 'csa', 'nonce', false ) ) {
		wp_send_json_error( csa_t( 'Your session expired. Reload the page.' ) );
	}
	$ok = in_array( $section, array( 'admin' ), true ) ? current_user_can( 'manage_options' ) : csa_can( $section );
	if ( ! $ok ) {
		wp_send_json_error( csa_t( 'You do not have permission for this.' ), 403 );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 );
	}
}

/* ------------------------------------------------------------------ */
/* Token allowance (price-weighted) and alerts                        */
/* ------------------------------------------------------------------ */

function csa_usage_month() {
	$u = get_option( CSA_USAGE, array() );
	$m = current_time( 'Y-m' );
	return ( is_array( $u ) && isset( $u[ $m ] ) ) ? $u[ $m ] : array( 'cost' => 0, 'in' => 0, 'out' => 0, 'calls' => 0 );
}

/** The monthly limit is the single USD limit from Blog Write .ai settings (0 = unlimited, no pause, no reminders). */
function csa_budget_base() {
	return function_exists( 'sb_get' ) ? max( 0.0, (float) sb_get( 'limit_usd' ) ) : 0.0;
}

function csa_usage_percent() {
	$base = csa_budget_base();
	if ( $base <= 0 || ! function_exists( 'sb_ledger_month' ) ) {
		return 0.0;
	}
	return round( (float) sb_ledger_month( current_time( 'Y-m' ) )['bill'] / $base * 100, 1 );
}

/**
 * Token wallet rule: the monthly allowance (e.g. 50,000,000 tokens) is worth exactly the monthly budget (e.g. $20).
 * One allowance token = budget / allowance dollars. A model token costs price_per_million / 1,000,000 dollars,
 * so it uses price_per_million * allowance / (budget * 1,000,000) allowance tokens.
 * Returns allowance tokens charged per 1 real token: read, write, cached read.
 */
function csa_wallet_rate( $m ) {
	$f = (float) csa_opt( 'allowance_tokens' ) / ( max( 0.01, (float) csa_opt( 'budget_usd' ) ) * 1000000 );
	return array( 'read' => $m['in'] * $f, 'write' => $m['out'] * $f, 'cached' => $m['cache'] * $f );
}

/** Allowance tokens used this month (price-weighted: the full allowance always equals the USD budget). */
function csa_tokens_used() {
	return (int) round( csa_usage_percent() / 100 * (int) csa_opt( 'allowance_tokens' ) );
}

function csa_alert_text( $t ) {
	if ( $t >= 100 ) {
		return csa_t( "This month's AI allowance is used up. It resets on the 1st of next month, or an admin can raise it in Settings." );
	}
	if ( $t >= 90 ) {
		return sprintf( csa_t( '%d%% of this month\'s AI allowance is used. Only about 10%% is left.' ), $t );
	}
	return sprintf( csa_t( '%d%% of this month\'s AI allowance is used.' ), $t );
}

function csa_record_usage( $entry, $usage, $feature = '' ) {
	if ( ! is_array( $entry ) ) {
		// Accept a model key or a provider model id too.
		$found = csa_model( (string) $entry );
		if ( ! $found ) {
			foreach ( csa_models() as $k => $mm ) {
				if ( $mm['id'] === $entry ) {
					$found = csa_model( $k );
				}
			}
		}
		$entry = $found ? $found : csa_model( 'gpt-terra' );
	}
	$in   = (int) ( $usage['input_tokens'] ?? 0 );
	$cw   = (int) ( $usage['cache_creation_input_tokens'] ?? 0 );
	$cr   = (int) ( $usage['cache_read_input_tokens'] ?? 0 );
	$out  = (int) ( $usage['output_tokens'] ?? 0 );
	$wmul = 'anthropic' === ( $entry['provider'] ?? '' ) ? 1.25 : 1.0;
	$cost = ( $in * $entry['in'] + $cw * $entry['in'] * $wmul + $cr * $entry['cache'] + $out * $entry['out'] ) / 1000000;

	$all = get_option( CSA_USAGE, array() );
	$all = is_array( $all ) ? $all : array();
	$m   = current_time( 'Y-m' );
	if ( ! isset( $all[ $m ] ) ) {
		$all[ $m ] = array( 'cost' => 0, 'in' => 0, 'out' => 0, 'calls' => 0 );
	}
	$all[ $m ]['cost']  += $cost;
	$all[ $m ]['in']    += $in + $cw + $cr;
	$all[ $m ]['out']   += $out;
	$all[ $m ]['calls'] += 1;
	krsort( $all );
	update_option( CSA_USAGE, array_slice( $all, 0, 12, true ), false );

	// Per-day and per-chat spend (for the economy caps).
	$day                              = get_option( 'csa_usage_day', array() );
	$day                              = is_array( $day ) ? $day : array();
	$day[ current_time( 'Y-m-d' ) ] = ( $day[ current_time( 'Y-m-d' ) ] ?? 0 ) + $cost;
	krsort( $day );
	update_option( 'csa_usage_day', array_slice( $day, 0, 40, true ), false );
	$GLOBALS['csa_last_cost'] = $cost;
	// Same USD ledger as the blog writer, so the monthly bill covers SEO, chat and every other Site Agent use.
	if ( function_exists( 'sb_ledger_add' ) && $cost > 0 ) {
		$GLOBALS['sb_ledger_model'] = (string) ( $entry['provider'] ?? '' );   // 'openai' rows are covered by the provider cost page; Claude / Kimi rows are not
		sb_ledger_add( get_current_user_id(), 'agent_' . ( $feature ? $feature : 'chat' ), max( 1, (int) ceil( $cost / SB_RAW_USD_PER_CREDIT ) ), 0, $cost );
		unset( $GLOBALS['sb_ledger_model'] );
	}
	if ( ! empty( $GLOBALS['csa_current_chat'] ) ) {
		$cc                                  = get_option( 'csa_chatcost', array() );
		$cc                                  = is_array( $cc ) ? $cc : array();
		$cid                                 = (int) $GLOBALS['csa_current_chat'];
		$cc[ $cid ]                          = ( $cc[ $cid ] ?? 0 ) + $cost;
		update_option( 'csa_chatcost', array_slice( $cc, -300, null, true ), false );
	}

	// Per-request log (last 100) so every cost is visible on the Activity page.
	$log = get_option( 'csa_reqlog', array() );
	$log = is_array( $log ) ? $log : array();
	array_unshift( $log, array( 't' => current_time( 'mysql' ), 'f' => $feature, 'm' => $entry['label'] ?? '', 'in' => $in + $cw + $cr, 'cached' => $cr, 'out' => $out, 'cost' => round( $cost, 5 ) ) );
	update_option( 'csa_reqlog', array_slice( $log, 0, 100 ), false );

	// Threshold reminders: 15, 40, 70, 90 % (each once a month).
	$pct = csa_usage_percent();
	$al  = get_option( CSA_ALERTS, array() );
	if ( ! is_array( $al ) || ( $al['month'] ?? '' ) !== $m ) {
		$al = array( 'month' => $m, 'hit' => array() );
	}
	foreach ( CSA_THRESHOLDS as $t ) {
		if ( $pct >= $t && ! in_array( $t, $al['hit'], true ) ) {
			$al['hit'][]              = $t;
			$GLOBALS['csa_alerts'][] = csa_alert_text( $t );
		}
	}
	update_option( CSA_ALERTS, $al, false );
}

/** Alerts raised during this request (shown in chat / tool responses). */
function csa_take_alerts() {
	$a                     = $GLOBALS['csa_alerts'] ?? array();
	$GLOBALS['csa_alerts'] = array();
	return $a;
}

/** Highest threshold reached this month (0 if none). */
function csa_alert_level() {
	$al = get_option( CSA_ALERTS, array() );
	if ( ! is_array( $al ) || ( $al['month'] ?? '' ) !== current_time( 'Y-m' ) || empty( $al['hit'] ) ) {
		return 0;
	}
	return max( $al['hit'] );
}

/* ------------------------------------------------------------------ */
/* Keep-alive: stop nginx/Cloudflare from cutting long AJAX requests  */
/* ------------------------------------------------------------------ */

/**
 * Servers like nginx return "504 Gateway Time-out" when PHP sends nothing for ~60 s.
 * During long waits (AI replies, PageSpeed, link checks) we send a space every few
 * seconds. Leading spaces are valid before JSON, so the browser still parses the reply.
 */
function csa_keepalive_start() {
	if ( ! empty( $GLOBALS['csa_ka'] ) || ! wp_doing_ajax() || headers_sent() ) {
		return;
	}
	$GLOBALS['csa_ka'] = microtime( true );
	@ini_set( 'zlib.output_compression', '0' );
	while ( ob_get_level() > 0 ) {
		@ob_end_flush();
	}
	header( 'Content-Type: application/json; charset=UTF-8' );
	header( 'X-Accel-Buffering: no' );
	header( 'Cache-Control: no-cache, no-store' );
	echo ' ';
	@flush();
}

function csa_keepalive_tick() {
	if ( empty( $GLOBALS['csa_ka'] ) || microtime( true ) - $GLOBALS['csa_ka'] < 4 ) {
		return;
	}
	$GLOBALS['csa_ka'] = microtime( true );
	echo ' ';
	@flush();
}

/**
 * HTTP request that keeps the browser connection alive while it waits.
 * $args: timeout, headers (assoc), body, redirection, max_bytes.
 * Returns array( 'code' => int, 'body' => string ) or WP_Error.
 */
function csa_http( $method, $url, $args = array() ) {
	$args = wp_parse_args( $args, array( 'timeout' => 30, 'headers' => array(), 'body' => null, 'redirection' => 5, 'max_bytes' => 0 ) );

	// Let WordPress / other plugins short-circuit requests exactly like wp_remote_request().
	$pre = apply_filters( 'pre_http_request', false, array_merge( $args, array( 'method' => $method ) ), $url );
	if ( false !== $pre ) {
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}
		return array( 'code' => (int) wp_remote_retrieve_response_code( $pre ), 'body' => (string) wp_remote_retrieve_body( $pre ) );
	}

	if ( ! function_exists( 'curl_init' ) ) {
		$r = wp_remote_request( $url, array( 'method' => $method, 'timeout' => $args['timeout'], 'headers' => $args['headers'], 'body' => $args['body'], 'redirection' => $args['redirection'], 'limit_response_size' => $args['max_bytes'] ? $args['max_bytes'] : null ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array( 'code' => (int) wp_remote_retrieve_response_code( $r ), 'body' => (string) wp_remote_retrieve_body( $r ) );
	}

	$headers = array();
	foreach ( $args['headers'] as $k => $v ) {
		$headers[] = $k . ': ' . $v;
	}
	$buf = '';
	$ch  = curl_init( $url );
	$opt = array(
		CURLOPT_CUSTOMREQUEST    => $method,
		CURLOPT_HTTPHEADER       => $headers,
		CURLOPT_TIMEOUT          => (int) $args['timeout'],
		CURLOPT_CONNECTTIMEOUT   => 15,
		CURLOPT_FOLLOWLOCATION   => $args['redirection'] > 0,
		CURLOPT_MAXREDIRS        => (int) $args['redirection'],
		CURLOPT_USERAGENT        => 'Mozilla/5.0 (compatible; ClaudeSiteAgent/' . CSA_VER . '; ' . home_url( '/' ) . ')',
		CURLOPT_SSL_VERIFYPEER   => true,
		CURLOPT_NOPROGRESS       => false,
		CURLOPT_PROGRESSFUNCTION => function () {
			csa_keepalive_tick();
			return 0;
		},
		CURLOPT_WRITEFUNCTION    => function ( $c, $chunk ) use ( &$buf, $args ) {
			$buf .= $chunk;
			if ( $args['max_bytes'] && strlen( $buf ) > $args['max_bytes'] ) {
				return 0; // Enough: stop downloading.
			}
			return strlen( $chunk );
		},
	);
	$ca = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
	if ( file_exists( $ca ) ) {
		$opt[ CURLOPT_CAINFO ] = $ca;
	}
	if ( 'HEAD' === $method ) {
		$opt[ CURLOPT_NOBODY ] = true;
	}
	if ( null !== $args['body'] ) {
		$opt[ CURLOPT_POSTFIELDS ] = $args['body'];
	}
	curl_setopt_array( $ch, $opt );
	curl_exec( $ch );
	$err  = curl_errno( $ch );
	$msg  = curl_error( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );
	if ( $err && ! ( 23 === $err && $args['max_bytes'] && $code ) ) {
		return new WP_Error( 'http', $msg ? $msg : 'cURL error ' . $err );
	}
	return array( 'code' => $code, 'body' => $buf );
}

/** Rough token estimate of a request (images counted separately, not as base64 text). */
function csa_estimate_tokens( $messages, $system = '', $tools = array() ) {
	$imgs  = 0;
	$strip = $messages;
	foreach ( $strip as &$m ) {
		if ( is_array( $m['content'] ?? null ) ) {
			foreach ( $m['content'] as &$b ) {
				if ( 'image' === ( $b['type'] ?? '' ) ) {
					$imgs++;
					$b = array( 'type' => 'image' );
				}
			}
			unset( $b );
		}
	}
	unset( $m );
	$text  = wp_json_encode( $strip, CSA_JSON ) . $system . wp_json_encode( $tools, CSA_JSON );
	$multi = preg_match_all( '/[^\x00-\x7F]/u', $text ); // Bangla etc.: about one token per character.
	$ascii = strlen( $text ) - (int) preg_match_all( '/[^\x00-\x7F]/u', $text, $m ) * 3;
	return (int) ( max( 0, $ascii ) / 3.5 + $multi ) + $imgs * 1600;
}

function csa_text_of( $data ) {
	$t = '';
	foreach ( (array) ( $data['content'] ?? array() ) as $b ) {
		if ( 'text' === ( $b['type'] ?? '' ) ) {
			$t .= $b['text'];
		}
	}
	return trim( $t );
}

/** Pull a JSON object/array out of model text. */
function csa_json( $text ) {
	$text = trim( preg_replace( '/^```(?:json)?|```$/m', '', trim( $text ) ) );
	$d    = json_decode( $text, true );
	if ( is_array( $d ) ) {
		return $d;
	}
	foreach ( array( array( '{', '}' ), array( '[', ']' ) ) as $p ) {
		$s = strpos( $text, $p[0] );
		$e = strrpos( $text, $p[1] );
		if ( false !== $s && false !== $e && $e > $s ) {
			$d = json_decode( substr( $text, $s, $e - $s + 1 ), true );
			if ( is_array( $d ) ) {
				return $d;
			}
		}
	}
	return null;
}

/** Shared site context for every prompt. */
function csa_site_context( $auto = null ) {
	$mem = csa_memory();
	$s   = 'Website: "' . get_bloginfo( 'name' ) . '" (' . home_url( '/' ) . '). Theme: ' . wp_get_theme()->get( 'Name' ) . '. SEO plugin: ' . ( csa_seo_plugin() ? csa_seo_plugin() : 'none' ) . '. Today: ' . current_time( 'l, j F Y' ) . '.';
	if ( '' !== trim( $mem['instructions'] ) ) {
		$s .= "\n\n## Site instructions from the admin (always follow)\n" . $mem['instructions'];
	}
	if ( '' !== trim( $mem['notes'] ) ) {
		$s .= "\n\n## Saved notes about this site\n" . $mem['notes'];
	}
	if ( function_exists( 'csa_fonts_context' ) ) {
		$s .= csa_fonts_context();
	}
	if ( function_exists( 'sb_memory_context' ) ) {
		$sbm = sb_memory_context( true ); // Owner's own rules, verified facts and banned words from Blog Write .ai > Memory.
		if ( '' !== $sbm ) {
			$s .= "\n\n## Owner's memory (shared with the blog writer; always follow, these facts are correct)\n" . $sbm;
		}
	}
	if ( function_exists( 'csa_kw_context' ) ) {
		$s .= csa_kw_context();
	}
	if ( function_exists( 'csa_si_map_text' ) ) {
		$s .= csa_si_map_text();
	}
	if ( function_exists( 'csa_am_site_text' ) ) {
		$s .= null === $auto ? csa_am_site_text() : $auto; // A chat passes its snapshot so the prompt cache stays valid.
	}
	return $s;
}

/* ------------------------------------------------------------------ */
/* Activity log + one-click undo                                       */
/* ------------------------------------------------------------------ */

function csa_log( $text, $undo = null ) {
	$l = get_option( CSA_LOG, array() );
	$l = is_array( $l ) ? $l : array();
	$e = array(
		'id'   => substr( md5( uniqid( '', true ) ), 0, 10 ),
		't'    => current_time( 'mysql' ),
		'u'    => wp_get_current_user()->user_login ? wp_get_current_user()->user_login : 'system',
		'a'    => $text,
		'undo' => $undo,
		'done' => 0,
	);
	array_unshift( $l, $e );
	update_option( CSA_LOG, array_slice( $l, 0, 300 ), false );
	return $e['id'];
}

/** Save a post's title/content/status before a change; returns an undo descriptor. */
function csa_snapshot_post( $id ) {
	$p = get_post( $id );
	if ( ! $p ) {
		return null;
	}
	$key          = substr( md5( uniqid( '', true ) ), 0, 10 );
	$all          = get_post_meta( $id, '_csa_undo', true );
	$all          = is_array( $all ) ? $all : array();
	$all[ $key ]  = array( 'post_title' => $p->post_title, 'post_content' => $p->post_content, 'post_status' => $p->post_status, 'post_date' => $p->post_date );
	$all          = array_slice( $all, -8, null, true );
	update_post_meta( $id, '_csa_undo', wp_slash( $all ) );
	return array( 'type' => 'post', 'post' => (int) $id, 'key' => $key );
}

function csa_undo( $log_id ) {
	$l = get_option( CSA_LOG, array() );
	foreach ( (array) $l as $i => $e ) {
		if ( ( $e['id'] ?? '' ) !== $log_id ) {
			continue;
		}
		if ( empty( $e['undo'] ) || ! empty( $e['done'] ) ) {
			return new WP_Error( 'undo', csa_t( 'This change cannot be undone (or was already undone).' ) );
		}
		$u  = $e['undo'];
		$id = (int) ( $u['post'] ?? 0 );
		switch ( $u['type'] ) {
			case 'post':
				$all = get_post_meta( $id, '_csa_undo', true );
				if ( ! is_array( $all ) || ! isset( $all[ $u['key'] ] ) ) {
					return new WP_Error( 'undo', csa_t( 'The saved copy for this change is no longer available.' ) );
				}
				$d = $all[ $u['key'] ];
				$r = wp_update_post( array( 'ID' => $id, 'post_title' => $d['post_title'], 'post_content' => $d['post_content'], 'post_status' => $d['post_status'], 'post_date' => $d['post_date'], 'post_date_gmt' => get_gmt_from_date( $d['post_date'] ) ), true );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'elementor':
				$backups = get_post_meta( $id, '_csa_backups', true );
				$found   = null;
				foreach ( (array) $backups as $k => $b ) {
					if ( $b['id'] === $u['backup'] ) {
						$found = $k;
					}
				}
				if ( null === $found ) {
					return new WP_Error( 'undo', csa_t( 'The saved copy for this change is no longer available.' ) );
				}
				update_post_meta( $id, '_elementor_data', wp_slash( $backups[ $found ]['d'] ) );
				update_post_meta( $id, '_csa_backups', wp_slash( array_slice( $backups, 0, $found ) ) );
				break;
			case 'unel':
				$r = csa_th_undo( $id, $u['key'] );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'seo':
				foreach ( (array) $u['old'] as $meta => $v ) {
					update_post_meta( $id, $meta, $v );
				}
				break;
			case 'aio':
				global $wpdb;
				if ( csa_aio_table() && $u['old'] ) {
					$wpdb->update( csa_aio_table(), (array) $u['old'], array( 'post_id' => $id ) );
				}
				break;
			case 'created':
				wp_trash_post( $id );
				break;
			case 'alt':
				update_post_meta( $id, '_wp_attachment_image_alt', $u['old'] );
				break;
			case 'memory':
				update_option( CSA_MEM, $u['old'], false );
				break;
			case 'option':
				if ( 0 === strpos( (string) $u['option'], 'csa_' ) ) {
					update_option( $u['option'], $u['old'], false );
				}
				break;
			default:
				return new WP_Error( 'undo', 'Unknown undo type.' );
		}
		csa_clear_cache( $id );
		$l[ $i ]['done'] = 1;
		update_option( CSA_LOG, $l, false );
		csa_log( csa_t( 'Undone:' ) . ' ' . $e['a'] );
		return true;
	}
	return new WP_Error( 'undo', csa_t( 'Log entry not found.' ) );
}

/* ------------------------------------------------------------------ */
/* WordPress helpers                                                   */
/* ------------------------------------------------------------------ */

function csa_is_elementor( $id ) {
	return 'builder' === get_post_meta( $id, '_elementor_edit_mode', true );
}

/** Content types the SEO tools work on: pages, posts and the posts made with Blog Write .ai. */
function csa_seo_types() {
	$t = array( 'page', 'post' );
	if ( post_type_exists( 'sb_post' ) ) {
		$t[] = 'sb_post';
	}
	return $t;
}

/** Drafts, scheduled and private items are listed too, so new work shows up before it is published. */
function csa_seo_statuses() {
	return array( 'publish', 'draft', 'pending', 'future', 'private' );
}

function csa_valid_post( $id ) {
	$p = get_post( (int) $id );
	return ( $p && in_array( $p->post_type, csa_seo_types(), true ) ) ? $p : null;
}

function csa_clear_cache( $id ) {
	if ( ! $id ) {
		return;
	}
	delete_post_meta( $id, '_elementor_css' );
	delete_post_meta( $id, '_elementor_element_cache' );
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
	clean_post_cache( $id );
	do_action( 'litespeed_purge_post', $id );
	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $id );
	}
}

function csa_seo_plugin() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'Yoast SEO';
	}
	if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
		return 'Rank Math';
	}
	if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
		return 'All in One SEO';
	}
	return '';
}

/** All in One SEO keeps its fields in its own table. */
function csa_aio_table() {
	global $wpdb;
	$t = $wpdb->prefix . 'aioseo_posts';
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ? $t : '';
}

function csa_aio_get( $id ) {
	global $wpdb;
	$t = csa_aio_table();
	if ( ! $t ) {
		return array( 'title' => '', 'description' => '', 'keyword' => '' );
	}
	$r  = $wpdb->get_row( $wpdb->prepare( "SELECT title, description, keyphrases FROM {$t} WHERE post_id = %d", $id ), ARRAY_A );
	$kp = $r && ! empty( $r['keyphrases'] ) ? json_decode( $r['keyphrases'], true ) : array();
	return array( 'title' => (string) ( $r['title'] ?? '' ), 'description' => (string) ( $r['description'] ?? '' ), 'keyword' => (string) ( $kp['focus']['keyphrase'] ?? '' ) );
}

function csa_aio_set( $id, $fields ) {
	global $wpdb;
	$t = csa_aio_table();
	if ( ! $t ) {
		return new WP_Error( 'noseo', 'The All in One SEO table was not found.' );
	}
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE post_id = %d", $id ), ARRAY_A );
	$set = array();
	if ( isset( $fields['title'] ) ) {
		$set['title'] = sanitize_text_field( $fields['title'] );
	}
	if ( isset( $fields['description'] ) ) {
		$set['description'] = sanitize_text_field( $fields['description'] );
	}
	if ( isset( $fields['keyword'] ) ) {
		$kp                           = $row && ! empty( $row['keyphrases'] ) ? json_decode( $row['keyphrases'], true ) : array();
		$kp                           = is_array( $kp ) ? $kp : array();
		$kp['focus']                  = array_merge( (array) ( $kp['focus'] ?? array( 'score' => 0, 'analysis' => new stdClass() ) ), array( 'keyphrase' => sanitize_text_field( $fields['keyword'] ) ) );
		$kp['additional']             = $kp['additional'] ?? array();
		$set['keyphrases']            = wp_json_encode( $kp );
	}
	if ( ! $set ) {
		return true;
	}
	$old = $row ? array_intersect_key( $row, $set ) : array();
	if ( $row ) {
		$ok = $wpdb->update( $t, $set, array( 'post_id' => $id ) );
	} else {
		$ok = $wpdb->insert( $t, array_merge( array( 'post_id' => $id, 'created' => current_time( 'mysql' ), 'updated' => current_time( 'mysql' ) ), $set ) );
	}
	if ( false === $ok ) {
		return new WP_Error( 'aio', 'Could not save to the All in One SEO table.' );
	}
	csa_clear_cache( $id );
	csa_log( csa_t( 'Updated SEO fields on' ) . ' #' . $id . ' ' . get_the_title( $id ) . ' (All in One SEO)', array( 'type' => 'aio', 'post' => (int) $id, 'old' => $old ) );
	return true;
}

function csa_seo_keys() {
	switch ( csa_seo_plugin() ) {
		case 'Yoast SEO':
			return array( 'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'keyword' => '_yoast_wpseo_focuskw' );
		case 'Rank Math':
			return array( 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'keyword' => 'rank_math_focus_keyword' );
	}
	return array();
}

/** Set SEO fields (title/description/keyword) with undo; returns true|WP_Error. */
function csa_seo_set( $id, $fields, $label = '' ) {
	if ( 'All in One SEO' === csa_seo_plugin() ) {
		return csa_aio_set( $id, $fields );
	}
	$keys = csa_seo_keys();
	if ( ! $keys ) {
		return new WP_Error( 'noseo', csa_t( 'No supported SEO plugin (Yoast SEO, Rank Math or All in One SEO) is active.' ) );
	}
	$old = array();
	foreach ( $fields as $f => $v ) {
		if ( isset( $keys[ $f ] ) ) {
			$old[ $keys[ $f ] ] = get_post_meta( $id, $keys[ $f ], true );
			update_post_meta( $id, $keys[ $f ], sanitize_text_field( $v ) );
		}
	}
	csa_clear_cache( $id );
	csa_log( ( $label ? $label : csa_t( 'Updated SEO fields on' ) ) . ' #' . $id . ' ' . get_the_title( $id ) . ' (' . implode( ', ', array_keys( $fields ) ) . ')', array( 'type' => 'seo', 'post' => (int) $id, 'old' => $old ) );
	return true;
}

/** A dedicated author user for generated drafts, named after the brand. */
function csa_author_id() {
	if ( ! csa_opt( 'bot_author' ) ) {
		return get_current_user_id();
	}
	$id = (int) get_option( 'csa_bot_user' );
	if ( $id && get_userdata( $id ) ) {
		if ( get_userdata( $id )->display_name !== csa_brand() ) {
			wp_update_user( array( 'ID' => $id, 'display_name' => csa_brand(), 'nickname' => csa_brand() ) );
		}
		return $id;
	}
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	$id   = wp_insert_user(
		array(
			'user_login'   => 'claude-api-agent',
			'user_pass'    => wp_generate_password( 32, true, true ),
			'user_email'   => 'agent-noreply@' . ( $host ? $host : 'example.com' ),
			'display_name' => csa_brand(),
			'nickname'     => csa_brand(),
			'role'         => 'author',
		)
	);
	if ( is_wp_error( $id ) ) {
		return get_current_user_id();
	}
	update_option( 'csa_bot_user', $id, false );
	return $id;
}

/** Private uploads folder for chat images. */
function csa_private_dir() {
	$u   = wp_upload_dir();
	$dir = trailingslashit( $u['basedir'] ) . 'csa-private';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php // Silence.\n" );
	}
	return $dir;
}

/** Resize + recompress an image file in place (max width, JPEG/WebP quality). */
function csa_optimize_image( $path, $max = 1920, $quality = 82 ) {
	$ed = wp_get_image_editor( $path );
	if ( is_wp_error( $ed ) ) {
		return $ed;
	}
	$size = $ed->get_size();
	if ( $size['width'] > $max ) {
		$ed->resize( $max, null, false );
	}
	$ed->set_quality( $quality );
	$r = $ed->save( $path );
	return is_wp_error( $r ) ? $r : true;
}

/** Base64 image block for the API from a local file (downsized copy). */
function csa_image_block( $path ) {
	if ( ! $path || ! file_exists( $path ) ) {
		return null;
	}
	$mime = wp_check_filetype( $path )['type'];
	if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
		return null;
	}
	$data = @file_get_contents( $path );
	if ( false === $data ) {
		return null;
	}
	return array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => $mime, 'data' => base64_encode( $data ) ) );
}


/** The standard the AI uses: the admin's own text, or the built-in default. */
function csa_design_std() {
	$t = trim( (string) csa_opt( 'design_std' ) );
	return '' !== $t ? $t : csa_design_std_default();
}
