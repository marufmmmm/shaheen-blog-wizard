<?php
/**
 * Auto memory: facts the agent keeps by itself, without the admin typing anything.
 *
 * Two kinds:
 *  - Universal (site-wide): contact details, brand, audience, programmes, rules, technical facts.
 *    Given to every chat, SEO audit and blog post, whatever model is used.
 *  - Specific (one page): what the page is for, its structure, known issues, recent changes.
 *    Given only when a chat works on that page.
 *
 * Each fact has a heading and a key. Saving the same key again REPLACES the old value, so an
 * outdated fact is dropped automatically when the newest one arrives. The plugin also records
 * page structure and recent changes itself (no AI cost).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CSA_AUTOMEM      = 'csa_auto_mem';
const CSA_AM_SITE_MAX  = 80;
const CSA_AM_PAGE_MAX  = 25;
const CSA_AM_SITE_CHAR = 3500;
const CSA_AM_PAGE_CHAR = 1500;

function csa_am_headings( $scope ) {
	return 'site' === $scope
		? array( 'Contact & identity', 'Brand & design', 'Audience & tone', 'Programmes & admissions', 'Rules', 'Technical', 'Other' )
		: array( 'About this page', 'Structure', 'Known issues', 'Changes made', 'Other' );
}

function csa_am_get() {
	$m = get_option( CSA_AUTOMEM, array() );
	$m = is_array( $m ) ? $m : array();
	return array_merge( array( 'site' => array(), 'pages' => array() ), $m );
}

function csa_am_put( $m ) {
	update_option( CSA_AUTOMEM, $m, false );
}

/** Normalised key, so "Phone number" and "phone  number" are the same fact. */
function csa_am_key( $k ) {
	$k = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $k ) ) ) );
	return mb_substr( $k, 0, 60 );
}

/** Never keep secrets in memory. */
function csa_am_is_secret( $text ) {
	return (bool) preg_match( '/\b(sk-[a-z0-9_-]{10,}|api[_ -]?key|password|passwd|পাসওয়ার্ড|otp|pin\s*[:=]?\s*\d{4,})/iu', (string) $text );
}

/**
 * Add or update one fact. Same (scope, page, key) replaces the old value.
 * A key that is almost the same as an existing one (e.g. "phone" / "phone number") updates it too.
 */
function csa_am_set( $scope, $page_id, $heading, $key, $value, $chat_id = 0 ) {
	$value = trim( mb_substr( wp_strip_all_tags( (string) $value ), 0, 400 ) );
	$key   = csa_am_key( $key );
	if ( '' === $key || '' === $value ) {
		return new WP_Error( 'empty', 'Key and fact are required.' );
	}
	if ( csa_am_is_secret( $key . ' ' . $value ) ) {
		return new WP_Error( 'secret', 'Passwords, API keys and codes are never stored in memory.' );
	}
	$scope = 'page' === $scope ? 'page' : 'site';
	$hs    = csa_am_headings( $scope );
	$h     = in_array( $heading, $hs, true ) ? $heading : end( $hs );
	$m     = csa_am_get();
	if ( 'page' === $scope ) {
		$page_id = (int) $page_id;
		if ( ! $page_id || ! get_post( $page_id ) ) {
			return new WP_Error( 'page', 'page_id is required for page memory.' );
		}
		$list = $m['pages'][ $page_id ] ?? array();
	} else {
		$list = $m['site'];
	}
	// Close match to an existing key in the same heading = the same fact.
	if ( ! isset( $list[ $key ] ) ) {
		foreach ( $list as $ek => $ef ) {
			similar_text( $ek, $key, $pct );
			if ( ( $ef['h'] ?? '' ) === $h && ( $pct >= 85 || 0 === strpos( $ek, $key . ' ' ) || 0 === strpos( $key, $ek . ' ' ) ) ) {
				$key = $ek;
				break;
			}
		}
	}
	$old          = $list[ $key ]['v'] ?? null;
	$list[ $key ] = array(
		'h' => $h,
		'v' => $value,
		't' => current_time( 'mysql' ),
		'c' => (int) $chat_id,
		'n' => (int) ( $list[ $key ]['n'] ?? 0 ) + ( null === $old ? 0 : 1 ),
	);
	if ( 'site' === $scope && csa_am_mirror_set( $key, $value ) ) {
		$list[ $key ]['m'] = 1; // Also visible / editable in Blog Write .ai > Memory.
	}
	// Keep the store small: drop the least recently updated facts.
	$max = 'page' === $scope ? CSA_AM_PAGE_MAX : CSA_AM_SITE_MAX;
	if ( count( $list ) > $max ) {
		uasort( $list, function ( $a, $b ) { return strcmp( $b['t'], $a['t'] ); } );
		$list = array_slice( $list, 0, $max, true );
	}
	if ( 'page' === $scope ) {
		$m['pages'][ $page_id ] = $list;
	} else {
		$m['site'] = $list;
	}
	csa_am_put( $m );
	return array( 'key' => $key, 'replaced' => $old );
}

function csa_am_forget( $scope, $page_id, $key ) {
	$m   = csa_am_get();
	$key = csa_am_key( $key );
	if ( 'page' === $scope ) {
		unset( $m['pages'][ (int) $page_id ][ $key ] );
		if ( empty( $m['pages'][ (int) $page_id ] ) ) {
			unset( $m['pages'][ (int) $page_id ] );
		}
	} else {
		unset( $m['site'][ $key ] );
		csa_am_mirror_forget( $key );
	}
	csa_am_put( $m );
}

/* ---- One memory, two doors: site-wide facts the agent learns also live in Blog Write .ai > Memory ---- */

function csa_am_label( $key ) {
	return mb_strtoupper( mb_substr( $key, 0, 1 ) ) . mb_substr( $key, 1 );
}

function csa_am_mirror_set( $key, $value ) {
	if ( ! function_exists( 'sb_memory_add' ) ) {
		return false;
	}
	sb_memory_add( 'fact', csa_am_label( $key ) . ': ' . $value, 'auto' );
	return true;
}

function csa_am_mirror_forget( $key ) {
	if ( ! function_exists( 'sb_memory_all' ) ) {
		return;
	}
	$label = mb_strtolower( csa_am_label( $key ) ) . ':';
	$keep  = array();
	foreach ( sb_memory_all() as $m ) {
		if ( 'fact' === $m['type'] && 'auto' === ( $m['src'] ?? '' ) && 0 === mb_strpos( mb_strtolower( $m['text'] ), $label ) ) {
			continue;
		}
		$keep[] = $m;
	}
	update_option( 'sb_memory', sb_memory_clean( $keep ), false );
}

/** Mirrored facts the owner switched off or deleted in Memory must not reach the agent any more. */
function csa_am_site_visible( $list ) {
	if ( ! function_exists( 'sb_memory_all' ) ) {
		return $list;
	}
	$state = array();
	foreach ( sb_memory_all() as $m ) {
		if ( 'fact' === $m['type'] && false !== ( $p = mb_strpos( $m['text'], ':' ) ) ) {
			$state[ mb_strtolower( mb_substr( $m['text'], 0, $p ) ) ] = ! empty( $m['on'] );
		}
	}
	foreach ( $list as $k => $f ) {
		if ( ! empty( $f['m'] ) && empty( $state[ mb_strtolower( $k ) ] ) ) {
			unset( $list[ $k ] );
		}
	}
	return $list;
}

/** Facts as text, grouped by heading. */
function csa_am_render( $list, $scope, $max_chars ) {
	if ( ! $list ) {
		return '';
	}
	$by = array();
	foreach ( $list as $k => $f ) {
		$by[ $f['h'] ][] = '- ' . $k . ': ' . $f['v'];
	}
	$out = array();
	foreach ( csa_am_headings( $scope ) as $h ) {
		if ( ! empty( $by[ $h ] ) ) {
			$out[] = '### ' . $h . "\n" . implode( "\n", $by[ $h ] );
		}
	}
	return mb_substr( implode( "\n", $out ), 0, $max_chars );
}

/** Universal memory for prompts. */
function csa_am_site_text() {
	$t = csa_am_render( csa_am_site_visible( csa_am_get()['site'] ), 'site', CSA_AM_SITE_CHAR );
	return '' === $t ? '' : "\n\n## Auto memory: site-wide facts (kept up to date automatically; newest value wins)\n" . $t;
}

/** Specific memory for the given pages. */
function csa_am_pages_text( $page_ids ) {
	$m   = csa_am_get();
	$out = array();
	foreach ( array_slice( array_unique( array_map( 'intval', $page_ids ) ), 0, 3 ) as $pid ) {
		if ( empty( $m['pages'][ $pid ] ) ) {
			continue;
		}
		$out[] = 'Page #' . $pid . ' "' . get_the_title( $pid ) . "\" (from earlier chats):\n" . csa_am_render( $m['pages'][ $pid ], 'page', CSA_AM_PAGE_CHAR );
	}
	return $out ? "[PAGE MEMORY]\n" . implode( "\n", $out ) : '';
}

/** Page ids mentioned in a message: "#123" or a page title. */
function csa_am_pages_in_text( $text ) {
	$ids = array();
	if ( preg_match_all( '/#(\d{1,7})\b/', (string) $text, $m ) ) {
		$ids = array_map( 'intval', $m[1] );
	}
	$mem = csa_am_get();
	foreach ( array_keys( $mem['pages'] ) as $pid ) {
		$t = get_the_title( $pid );
		if ( $t && mb_strlen( $t ) >= 4 && false !== mb_stripos( (string) $text, $t ) ) {
			$ids[] = (int) $pid;
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Free, automatic page facts from tool results (no AI call).
 */
function csa_am_track( $name, $in, $out, $chat_id ) {
	if ( ! is_array( $out ) || isset( $out['error'] ) ) {
		return;
	}
	$pid = (int) ( $in['id'] ?? 0 );
	if ( 'get_elementor_html' === $name && $pid && ! empty( $out['html_widgets'] ) ) {
		$w = array();
		foreach ( $out['html_widgets'] as $x ) {
			$w[] = $x['widget_id'] . ' (' . number_format_i18n( (int) $x['length'] ) . ' chars)';
		}
		csa_am_set( 'page', $pid, 'Structure', 'builder', 'Elementor page with custom HTML widgets: ' . implode( ', ', $w ), $chat_id );
	}
	if ( 'page_outline' === $name && $pid && ! empty( $out['outline'] ) ) {
		$secs = array();
		foreach ( explode( "\n", $out['outline'] ) as $line ) {
			if ( preg_match( '/<(section|header|footer|h1|h2)[.#]?([^>]*)>(.*)$/', $line, $mm ) ) {
				$secs[] = trim( $mm[1] . ( $mm[2] ? '.' . $mm[2] : '' ) . ' ' . trim( $mm[3] ) );
			}
		}
		if ( $secs ) {
			csa_am_set( 'page', $pid, 'Structure', 'main sections', mb_substr( implode( ' | ', array_slice( $secs, 0, 12 ) ), 0, 400 ), $chat_id );
		}
	}
	if ( in_array( $name, array( 'edit_elementor_html', 'edit_content', 'update_page', 'update_seo_meta', 'restore_elementor_backup' ), true ) && $pid ) {
		$m    = csa_am_get();
		$prev = $m['pages'][ $pid ]['recent changes']['v'] ?? '';
		$what = $name;
		if ( isset( $in['new_text'] ) ) {
			$p    = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $in['new_text'] ) ) );
			$what .= ': ' . mb_substr( '' !== $p ? $p : (string) $in['new_text'], 0, 70 );
		}
		$lines = array_filter( explode( ' || ', $prev ) );
		array_unshift( $lines, current_time( 'j M' ) . ' ' . $what );
		csa_am_set( 'page', $pid, 'Changes made', 'recent changes', implode( ' || ', array_slice( $lines, 0, 5 ) ), $chat_id );
	}
}

/* ------------------------------------------------------------------ */
/* Agent tools                                                         */
/* ------------------------------------------------------------------ */

function csa_am_tools() {
	return array(
		array(
			'name'         => 'remember',
			'description'  => 'Keep a lasting fact in auto memory (used by every future chat, whatever model). scope "site" = true for the whole site (contact, brand, audience, programmes, rules); scope "page" = only about one page (needs page_id). Saving the SAME key again replaces the old value, so use the existing key when a fact changes. Call it together with other tools, never as a separate step. No temporary task details, no passwords or keys.',
			'input_schema' => csa_schema(
				array(
					'scope'   => array( 'type' => 'string', 'enum' => array( 'site', 'page' ) ),
					'page_id' => array( 'type' => 'integer' ),
					'heading' => array( 'type' => 'string', 'enum' => array_values( array_unique( array_merge( csa_am_headings( 'site' ), csa_am_headings( 'page' ) ) ) ) ),
					'key'     => array( 'type' => 'string', 'description' => 'short subject, e.g. "phone number", "brand colours", "admission deadline"' ),
					'fact'    => array( 'type' => 'string', 'description' => 'the current fact, one or two sentences' ),
				),
				array( 'scope', 'heading', 'key', 'fact' )
			),
		),
		array(
			'name'         => 'forget',
			'description'  => 'Remove a fact from auto memory when it is no longer true and there is no new value.',
			'input_schema' => csa_schema(
				array(
					'scope'   => array( 'type' => 'string', 'enum' => array( 'site', 'page' ) ),
					'page_id' => array( 'type' => 'integer' ),
					'key'     => array( 'type' => 'string' ),
				),
				array( 'scope', 'key' )
			),
		),
	);
}

function csa_am_run_tool( $name, $in, &$ctx ) {
	$chat = (int) ( $GLOBALS['csa_current_chat'] ?? 0 );
	if ( 'remember' === $name ) {
		$r = csa_am_set( $in['scope'] ?? 'site', $in['page_id'] ?? 0, $in['heading'] ?? '', $in['key'] ?? '', $in['fact'] ?? '', $chat );
		if ( is_wp_error( $r ) ) {
			return array( 'error' => $r->get_error_message() );
		}
		$ctx['learned'][] = ( 'page' === ( $in['scope'] ?? '' ) ? '#' . (int) $in['page_id'] . ' ' : '' ) . $r['key'] . ': ' . mb_substr( (string) $in['fact'], 0, 160 );
		return array( 'ok' => true, 'replaced_old_value' => $r['replaced'] );
	}
	csa_am_forget( $in['scope'] ?? 'site', $in['page_id'] ?? 0, $in['key'] ?? '' );
	$ctx['learned'][] = 'forgot ' . csa_am_key( $in['key'] ?? '' );
	return array( 'ok' => true );
}

/* ------------------------------------------------------------------ */
/* Admin: view / delete (read-only otherwise; the agent keeps it)      */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_csa_automem', 'csa_ajax_automem' );
function csa_ajax_automem() {
	csa_guard( 'admin' );
	$do = sanitize_key( $_POST['do'] ?? '' );
	if ( 'delete' === $do ) {
		csa_am_forget( 'page' === ( $_POST['scope'] ?? '' ) ? 'page' : 'site', (int) ( $_POST['page_id'] ?? 0 ), wp_unslash( $_POST['key'] ?? '' ) );
	} elseif ( 'clear' === $do ) {
		csa_am_put( array( 'site' => array(), 'pages' => array() ) );
	}
	wp_send_json_success( array( 'ok' => true ) );
}

function csa_am_card() {
	$m = csa_am_get();
	$row = function ( $scope, $pid, $k, $f ) {
		echo '<li><strong>' . esc_html( $k ) . ':</strong> ' . esc_html( $f['v'] ) . ' <span class="csa-muted">· ' . esc_html( mysql2date( 'j M, g:i a', $f['t'] ) ) . ( $f['n'] ? ' · ' . esc_html( sprintf( csa_t( 'updated %d×' ), $f['n'] ) ) : '' ) . '</span> <button type="button" class="button-link csa-am-del" data-scope="' . esc_attr( $scope ) . '" data-page="' . (int) $pid . '" data-key="' . esc_attr( $k ) . '" title="' . esc_attr( csa_t( 'Delete' ) ) . '">×</button></li>';
	};
	?>
	<div class="csa-card csa-automem">
		<h2>🤖 <?php echo esc_html( csa_t( 'Auto memory (kept by the agent)' ) ); ?></h2>
		<p class="csa-muted"><?php echo esc_html( csa_t( 'The agent saves lasting facts here by itself while it works. When a fact changes, the newest value replaces the old one. Every model uses this memory, so it re-reads less and costs less. You don\'t need to type anything; you can delete a wrong fact with ×.' ) ); ?></p>
		<h3>🌐 <?php echo esc_html( csa_t( 'Universal (whole site)' ) ); ?></h3>
		<?php if ( ! $m['site'] ) : ?>
			<p class="csa-muted"><?php echo esc_html( csa_t( 'Nothing yet.' ) ); ?></p>
		<?php else : ?>
			<?php
			$by = array();
			foreach ( $m['site'] as $k => $f ) {
				$by[ $f['h'] ][ $k ] = $f;
			}
			foreach ( csa_am_headings( 'site' ) as $h ) :
				if ( empty( $by[ $h ] ) ) {
					continue;
				}
				?>
				<h4><?php echo esc_html( csa_t( $h ) ); ?></h4><ul class="csa-am-list"><?php foreach ( $by[ $h ] as $k => $f ) { $row( 'site', 0, $k, $f ); } ?></ul>
			<?php endforeach; ?>
		<?php endif; ?>
		<h3>📄 <?php echo esc_html( csa_t( 'Specific (per page)' ) ); ?></h3>
		<?php if ( ! $m['pages'] ) : ?>
			<p class="csa-muted"><?php echo esc_html( csa_t( 'Nothing yet.' ) ); ?></p>
		<?php else : ?>
			<?php foreach ( $m['pages'] as $pid => $facts ) : ?>
				<details class="csa-am-page"><summary><strong><?php echo esc_html( '#' . $pid . ' ' . get_the_title( $pid ) ); ?></strong> <span class="csa-muted">(<?php echo count( $facts ); ?>)</span></summary>
					<ul class="csa-am-list"><?php foreach ( $facts as $k => $f ) { $row( 'page', $pid, $k, $f ); } ?></ul>
				</details>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
}
