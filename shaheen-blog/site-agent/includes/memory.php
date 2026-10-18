<?php
/**
 * Per-chat memory cache file.
 *
 * Economy mode sends only the latest messages, so the agent could forget what it already
 * found. Each chat therefore keeps a small JSON "memory file" (in the private uploads folder):
 * the task, pages and widgets involved, snippets it found, changes made, the current plan,
 * its own notes, and cached page outlines. A compact summary is added to every request, and
 * the agent pulls exact parts back out with page_outline / find_in_html / read_html.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function csa_mem_dir() {
	$d = csa_private_dir() . '/memory';
	if ( ! is_dir( $d ) ) {
		wp_mkdir_p( $d );
	}
	return $d;
}

function csa_mem_file( $chat_id ) {
	return csa_mem_dir() . '/chat-' . (int) $chat_id . '.json';
}

function csa_mem_default() {
	return array( 'task' => '', 'pages' => array(), 'found' => array(), 'done' => array(), 'plan' => '', 'steps' => array(), 'notes' => '', 'outlines' => array(), 'kind' => '', 'section' => '', 'site_snap' => '', 'mention' => array(), 'learned' => array() );
}

function csa_mem_load( $chat_id ) {
	if ( ! $chat_id || ! file_exists( csa_mem_file( $chat_id ) ) ) {
		return csa_mem_default();
	}
	$m = json_decode( (string) file_get_contents( csa_mem_file( $chat_id ) ), true );
	return is_array( $m ) ? array_merge( csa_mem_default(), $m ) : csa_mem_default();
}

function csa_mem_save( $chat_id, $mem ) {
	if ( $chat_id ) {
		file_put_contents( csa_mem_file( $chat_id ), wp_json_encode( $mem, CSA_JSON ) );
	}
}

function csa_mem_delete( $chat_id ) {
	$f = csa_mem_file( $chat_id );
	if ( file_exists( $f ) ) {
		@unlink( $f );
	}
}

function csa_mem_page( &$mem, $pid ) {
	$pid = (int) $pid;
	$p   = get_post( $pid );
	if ( ! $p ) {
		return;
	}
	if ( ! isset( $mem['pages'][ $pid ] ) ) {
		$mem['pages'][ $pid ] = array( 'title' => $p->post_title, 'type' => $p->post_type, 'elementor' => csa_is_elementor( $pid ), 'widgets' => array() );
	}
}

function csa_mem_push( &$list, $item, $max ) {
	$list[] = $item;
	if ( count( $list ) > $max ) {
		$list = array_slice( $list, -$max );
	}
}

/** Record what a tool call taught us. */
function csa_mem_track( &$mem, $name, $in, $out ) {
	if ( isset( $out['error'] ) ) {
		return;
	}
	$pid = (int) ( $in['id'] ?? 0 );
	switch ( $name ) {
		case 'get_page':
		case 'read_html':
		case 'page_outline':
		case 'get_seo_data':
		case 'get_image_slots':
			if ( $pid ) {
				csa_mem_page( $mem, $pid );
			}
			break;
		case 'get_elementor_html':
			csa_mem_page( $mem, $pid );
			foreach ( (array) ( $out['html_widgets'] ?? array() ) as $w ) {
				$mem['pages'][ $pid ]['widgets'][ (string) $w['widget_id'] ] = (int) ( $w['length'] ?? 0 );
			}
			break;
		case 'find_in_html':
			csa_mem_page( $mem, $pid );
			if ( ! empty( $out['snippets'] ) ) {
				csa_mem_push( $mem['found'], array( 'page' => $pid, 'widget' => (string) ( $in['widget_id'] ?? '' ), 'query' => mb_substr( (string) $in['query'], 0, 80 ), 'at' => (int) $out['snippets'][0]['at'], 'snippet' => mb_substr( $out['snippets'][0]['snippet'], 0, 500 ) ), 6 );
			}
			break;
		case 'propose_plan':
			$mem['plan']  = mb_substr( (string) ( $in['summary'] ?? '' ), 0, 300 );
			$mem['steps'] = array();
			foreach ( array_slice( (array) ( $in['steps'] ?? array() ), 0, 20 ) as $st ) {
				$mem['steps'][] = array( 't' => mb_substr( wp_strip_all_tags( (string) $st ), 0, 300 ), 's' => 'todo' );
			}
			break;
		case 'create_page':
		case 'update_page':
		case 'edit_content':
		case 'edit_elementor_html':
		case 'restore_elementor_backup':
		case 'update_seo_meta':
		case 'set_featured_image':
		case 'update_image_alt':
			$what = $name . ( $pid ? ' #' . $pid : '' ) . ( ! empty( $in['widget_id'] ) ? ' widget ' . $in['widget_id'] : '' );
			if ( isset( $in['new_text'] ) ) {
				$short = function ( $t ) {
					$p = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $t ) ) );
					return mb_substr( '' !== $p ? $p : trim( $t ), 0, 100 );
				};
				$what .= '' === trim( $in['new_text'] ) ? ': removed "' . $short( $in['old_text'] ?? '' ) . '"' : ': "' . $short( $in['new_text'] ) . '"';
			}
			csa_mem_push( $mem['done'], current_time( 'H:i' ) . ' ' . $what, 12 );
			if ( 'create_page' === $name && ! empty( $out['id'] ) ) {
				csa_mem_page( $mem, $out['id'] );
			}
			break;
	}
}

/** Compact summary added to each request. */
function csa_mem_text( $mem ) {
	$l = array();
	if ( $mem['task'] ) {
		$l[] = 'Task: ' . $mem['task'];
	}
	$secs = array( 'seo' => 'the SEO screen', 'blog' => 'Blog Studio', 'images' => 'the Images screen' );
	if ( ! empty( $mem['section'] ) && isset( $secs[ $mem['section'] ] ) ) {
		$l[] = 'The user is chatting from ' . $secs[ $mem['section'] ] . '; focus on that kind of work.';
	}
	if ( $mem['pages'] ) {
		$pp = array();
		foreach ( $mem['pages'] as $id => $p ) {
			$w = array();
			foreach ( (array) $p['widgets'] as $wid => $len ) {
				$w[] = $wid . ( $len ? ' (' . $len . ' chars)' : '' );
			}
			$pp[] = '#' . $id . ' "' . $p['title'] . '"' . ( $p['elementor'] ? ' Elementor' : '' ) . ( $w ? ', HTML widgets: ' . implode( ', ', $w ) : '' ) . ( isset( $mem['outlines'][ $id . ':' ] ) || preg_grep( '/^' . $id . ':/', array_keys( $mem['outlines'] ) ) ? ', outline cached' : '' );
		}
		$l[] = 'Pages: ' . implode( '; ', $pp );
	}
	if ( $mem['plan'] ) {
		$l[] = 'Latest plan: ' . $mem['plan'];
	}
	if ( $mem['steps'] ) {
		$names = array( 'todo' => 'to do', 'done' => 'DONE', 'later' => 'LATER - not now' );
		$sl    = array();
		foreach ( $mem['steps'] as $i => $st ) {
			$sl[] = ( $i + 1 ) . '. [' . ( $names[ $st['s'] ] ?? $st['s'] ) . '] ' . mb_substr( $st['t'], 0, 160 );
		}
		$l[] = "Plan checklist:\n" . implode( "\n", $sl );
	}
	foreach ( $mem['found'] as $f ) {
		$l[] = 'Found "' . $f['query'] . '" in #' . $f['page'] . ( $f['widget'] ? ' widget ' . $f['widget'] : '' ) . ' at ' . $f['at'] . ': ' . $f['snippet'];
	}
	if ( $mem['done'] ) {
		$l[] = 'Done so far: ' . implode( ' | ', $mem['done'] );
	}
	if ( '' !== trim( $mem['notes'] ) ) {
		$l[] = 'Your notes: ' . $mem['notes'];
	}
	if ( ! empty( $mem['learned'] ) ) {
		$l[] = 'Auto memory saved/updated in this chat (newest wins): ' . implode( ' | ', $mem['learned'] );
	}
	$pm = function_exists( 'csa_am_pages_text' ) ? csa_am_pages_text( array_merge( (array) $mem['mention'], array_keys( (array) $mem['pages'] ) ) ) : '';
	if ( '' !== $pm ) {
		$l[] = $pm;
	}
	if ( ! $l ) {
		return '';
	}
	$t = "[CHAT MEMORY — from this chat's cache file. Older messages may be left out to save cost; rely on this and extract exact parts with page_outline / find_in_html / read_html instead of re-reading whole pages.]\n" . implode( "\n", $l );
	return mb_substr( $t, 0, 7000 );
}

/**
 * Which kind of work a chat is, so "Auto" picks the right model:
 * chat (page design), seo, blog or vision. A section chat uses its section.
 */
function csa_chat_kind( $section, $text ) {
	$map = array( 'seo' => 'seo', 'blog' => 'blog', 'images' => 'vision' );
	if ( isset( $map[ $section ] ) ) {
		return $map[ $section ];
	}
	$t   = mb_strtolower( (string) $text );
	$has = function ( $words ) use ( $t ) {
		foreach ( $words as $w ) {
			if ( false !== mb_strpos( $t, $w ) ) {
				return true;
			}
		}
		return false;
	};
	$design = $has( array( 'mobile', 'মোবাইল', 'design', 'ডিজাইন', 'css', 'layout', 'লেআউট', 'section', 'সেকশন', 'hero', 'হিরো', 'font', 'ফন্ট', 'button', 'বাটন', 'color', 'কালার', 'রং' ) );
	if ( ! $design && $has( array( 'blog', 'ব্লগ', 'post লিখ', 'পোস্ট', 'article', 'আর্টিকেল', 'প্রবন্ধ' ) ) ) {
		return 'blog';
	}
	if ( ! $design && $has( array( 'seo', 'এসইও', 'meta', 'মেটা', 'keyword', 'কীওয়ার্ড', 'কিওয়ার্ড', 'title tag', 'schema', 'স্কিমা', 'google', 'গুগল', 'সার্চ', 'ranking', 'র‍্যাংক' ) ) ) {
		return 'seo';
	}
	if ( ! $design && $has( array( 'alt text', 'alt ', 'অল্ট', 'ছবির বর্ণনা' ) ) ) {
		return 'vision';
	}
	return 'chat';
}

/** Plan steps that are not done yet: index => step. */
function csa_mem_remaining( $mem ) {
	$r = array();
	foreach ( (array) $mem['steps'] as $i => $st ) {
		if ( 'done' !== $st['s'] ) {
			$r[ $i ] = $st;
		}
	}
	return $r;
}

/** Items for the "left for later" card. */
function csa_rest_item( $mem ) {
	$rest = csa_mem_remaining( $mem );
	if ( ! $rest ) {
		return null;
	}
	$steps = array();
	foreach ( $rest as $i => $st ) {
		$steps[] = array( 'n' => $i + 1, 't' => $st['t'], 'later' => 'later' === $st['s'] );
	}
	$first = array_key_first( $rest );
	return array( 'r' => 'rest', 'steps' => $steps, 'next' => $first + 1, 'next_t' => $rest[ $first ]['t'] );
}

/** First thing the user typed in this chat (skips [[system]] turns). */
function csa_first_human_text( $messages ) {
	foreach ( $messages as $m ) {
		if ( 'user' !== $m['role'] ) {
			continue;
		}
		$t = is_string( $m['content'] ) ? $m['content'] : implode( ' ', wp_list_pluck( array_filter( (array) $m['content'], function ( $b ) { return 'text' === ( $b['type'] ?? '' ); } ), 'text' ) );
		if ( '' !== trim( $t ) && 0 !== strpos( $t, '[[' ) ) {
			return $t;
		}
	}
	return '';
}

/** Add the memory summary as a text block at the end of the last user message (after any tool results). */
function csa_mem_inject( &$prepared, $mem ) {
	$t = csa_mem_text( $mem );
	if ( '' === $t || ! $prepared ) {
		return;
	}
	$i = count( $prepared ) - 1;
	if ( 'user' !== $prepared[ $i ]['role'] ) {
		return;
	}
	if ( is_string( $prepared[ $i ]['content'] ) ) {
		$prepared[ $i ]['content'] = array( array( 'type' => 'text', 'text' => $prepared[ $i ]['content'] ) );
	}
	$prepared[ $i ]['content'][] = array( 'type' => 'text', 'text' => $t, '_csa_mem' => 1 );
}

/* ------------------------------------------------------------------ */
/* Page outline (cached per chat)                                      */
/* ------------------------------------------------------------------ */

/** A map of a page's structure: tag.class#id with character offsets usable by read_html. */
function csa_page_outline( $html ) {
	$c = csa_compact_html( $html );
	if ( ! preg_match_all( '#<(section|div|header|footer|nav|main|article|aside|h[1-4]|img|button|form|table|ul|ol|style|script|iframe|video)\b([^>]*)>#i', $c, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		return array();
	}
	$lines = array();
	$prev  = '';
	$rep   = 0;
	$pos_b = 0;
	$pos_c = 0;
	foreach ( $m as $x ) {
		$tag   = strtolower( $x[1][0] );
		$attrs = $x[2][0];
		$cls   = preg_match( '/\bclass\s*=\s*["\']([^"\']*)["\']/i', $attrs, $cm ) ? trim( preg_replace( '/\s+/', '.', trim( $cm[1] ) ) ) : '';
		$id    = preg_match( '/\bid\s*=\s*["\']([^"\']*)["\']/i', $attrs, $im ) ? $im[1] : '';
		$is_h  = (bool) preg_match( '/^h[1-4]$/', $tag );
		if ( '' === $cls && '' === $id && ! $is_h && ! in_array( $tag, array( 'style', 'script', 'img', 'iframe', 'video', 'form', 'table' ), true ) ) {
			continue;
		}
		$byte  = $x[0][1];
		$pos_c += mb_strlen( substr( $c, $pos_b, $byte - $pos_b ) );
		$pos_b = $byte;
		$sig   = $tag . ( $cls ? '.' . $cls : '' ) . ( $id ? '#' . $id : '' );
		if ( $sig === $prev ) {
			$rep++;
			continue;
		}
		if ( $rep && $lines ) {
			$lines[ count( $lines ) - 1 ] .= '  (+' . $rep . ' more alike)';
		}
		$rep  = 0;
		$prev = $sig;
		$text = '';
		if ( $is_h && preg_match( '#^[^<]*(?:<(?!/' . $tag . ')[^<]*)*#', substr( $c, $byte + strlen( $x[0][0] ), 400 ), $tm ) ) {
			$text = ' "' . mb_substr( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $tm[0] ) ) ), 0, 60 ) . '"';
		} elseif ( 'img' === $tag && preg_match( '/\balt\s*=\s*["\']([^"\']*)["\']/i', $attrs, $am ) ) {
			$text = ' alt="' . mb_substr( $am[1], 0, 40 ) . '"';
		}
		$lines[] = '@' . $pos_c . ' <' . mb_substr( $sig, 0, 90 ) . '>' . $text;
		if ( count( $lines ) >= 120 ) {
			$lines[] = '… (more; use find_in_html for later parts)';
			break;
		}
	}
	if ( $rep && $lines ) {
		$lines[ count( $lines ) - 1 ] .= '  (+' . $rep . ' more alike)';
	}
	return $lines;
}

/* ------------------------------------------------------------------ */
/* AJAX: view / edit / clear a chat's memory                          */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_csa_chat_memory', 'csa_ajax_chat_memory' );
function csa_ajax_chat_memory() {
	csa_guard( 'chat' );
	$row = csa_chat_get( (int) ( $_POST['id'] ?? 0 ) );
	if ( ! $row ) {
		wp_send_json_error( csa_t( 'Chat not found.' ) );
	}
	$id  = (int) $row['id'];
	$mem = csa_mem_load( $id );
	$do  = sanitize_key( $_POST['do'] ?? 'get' );
	if ( 'save' === $do ) {
		$mem['notes'] = mb_substr( sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ), 0, 2000 );
		$mem['task']  = mb_substr( sanitize_textarea_field( wp_unslash( $_POST['task'] ?? $mem['task'] ) ), 0, 300 );
		csa_mem_save( $id, $mem );
	} elseif ( 'clear' === $do ) {
		$mem = array_merge( csa_mem_default(), array( 'task' => $mem['task'] ) );
		csa_mem_save( $id, $mem );
	}
	wp_send_json_success(
		array(
			'task'    => $mem['task'],
			'notes'   => $mem['notes'],
			'summary' => csa_mem_text( array_merge( $mem, array( 'notes' => '' ) ) ),
			'size'    => file_exists( csa_mem_file( $id ) ) ? size_format( filesize( csa_mem_file( $id ) ) ) : '0 B',
		)
	);
}
