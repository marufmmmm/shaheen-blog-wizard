<?php
/**
 * replace_text: change a sentence by its VISIBLE text. Works in every Elementor widget
 * (HTML, text editor, heading, button, icon box, tabs …) and in normal page content,
 * ignoring tags, line breaks and entities between the words.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function csa_te_tool() {
	return array(
		array(
			'name'         => 'replace_text',
			'description'  => 'PREFERRED way to change wording. Replace one sentence/phrase by its VISIBLE text (as the reader sees it, no HTML tags). Works in ALL Elementor widgets (text editor, heading, button, HTML …) and in normal page content; tags, line breaks and entities between the words are ignored. old_text must identify ONE place: if it matches several, you get a list with widget ids and then add widget_id or more words. Keeps the tags around it. A backup is saved. Use edit_elementor_html / edit_content only for markup, CSS or structure.',
			'input_schema' => csa_schema(
				array(
					'id'        => array( 'type' => 'integer' ),
					'old_text'  => array( 'type' => 'string', 'description' => 'Visible text to replace, at least 3 words.' ),
					'new_text'  => array( 'type' => 'string', 'description' => 'Plain replacement text (no tags).' ),
					'widget_id' => array( 'type' => 'string', 'description' => 'Optional: only look in this Elementor element id.' ),
					'allow_common' => array( 'type' => 'boolean', 'description' => 'Only when the owner explicitly asked to change a SHARED block on every page. Normally leave out.' ),
				),
				array( 'id', 'old_text', 'new_text' )
			),
		),
	);
}


/** Pages (other than $id) that contain the same visible text: a sign of a shared / premade section. */
function csa_common_pages( $id, $plain ) {
	$plain = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $plain ), ENT_QUOTES, 'UTF-8' ) ) );
	if ( mb_strlen( $plain ) < 12 || ! function_exists( 'csa_si_load' ) ) {
		return array();
	}
	$out = array();
	foreach ( (array) csa_si_load()['pages'] as $pid => $p ) {
		if ( (int) $pid === (int) $id ) {
			continue;
		}
		$hay = preg_replace( '/\s+/u', ' ', (string) ( $p['text'] ?? '' ) );
		if ( false !== mb_stripos( $hay, $plain ) ) {
			$out[] = ( $p['t'] ?? '#' . $pid ) . ' (#' . $pid . ')';
		}
	}
	return $out;
}

function csa_common_error( $plain, $pages ) {
	return array( 'error' => 'COMMON SECTION: this exact text also appears on ' . count( $pages ) . ' other pages (for example ' . implode( ', ', array_slice( $pages, 0, 3 ) ) . '). It is a shared / premade block, not this page\'s own content, so it was NOT changed. Do not edit it and do not treat it as this page\'s H1. Find this page\'s OWN text instead: the page title (set_page_title), or the H1 from seo_score facts. Only if the owner clearly asked to change the shared block on every page, repeat the call with allow_common true.' );
}

/** Regex that matches the words of $plain with tags/whitespace/entities between them. */
function csa_te_regex( $plain ) {
	$plain = html_entity_decode( wp_strip_all_tags( (string) $plain ), ENT_QUOTES, 'UTF-8' );
	$words = preg_split( '/\s+/u', trim( $plain ), -1, PREG_SPLIT_NO_EMPTY );
	if ( count( $words ) < 2 && mb_strlen( $plain ) < 12 ) {
		return '';
	}
	$sep = '(?:\s|<[^>]*>|&nbsp;|&#160;|&#8203;)*';
	return '/' . implode( $sep, array_map( function ( $w ) { return preg_quote( $w, '/' ); }, $words ) ) . '/u';
}

/** Find every string field (path => value) that holds the text. */
function csa_te_scan( $node, $re, $path, &$hits, $skip_keys ) {
	if ( is_string( $node ) ) {
		if ( strlen( $node ) > 3 && preg_match_all( $re, $node, $m ) ) {
			$hits[] = array( 'path' => $path, 'count' => count( $m[0] ), 'snippet' => mb_substr( wp_strip_all_tags( $m[0][0] ), 0, 80 ) );
		}
		return;
	}
	if ( is_array( $node ) ) {
		foreach ( $node as $k => $v ) {
			if ( in_array( (string) $k, $skip_keys, true ) ) {
				continue;
			}
			csa_te_scan( $v, $re, array_merge( $path, array( $k ) ), $hits, $skip_keys );
		}
	}
}

/** Replacement that keeps the HTML balanced when the old text spanned tags. */
function csa_te_sub( $new ) {
	return function ( $m ) use ( $new ) {
		$old = $m[0];
		if ( false === strpos( $old, '<' ) ) {
			return $new;
		}
		$stack = array();
		$close = '';
		$open  = '';
		if ( preg_match_all( '#<(/?)([a-z0-9]+)\b[^>]*?(/?)>#i', $old, $t, PREG_SET_ORDER ) ) {
			foreach ( $t as $x ) {
				$tag = strtolower( $x[2] );
				if ( '/' === $x[3] || in_array( $tag, array( 'br', 'img', 'hr', 'input', 'wbr' ), true ) ) {
					continue;
				}
				if ( '' === $x[1] ) {
					$stack[] = array( $tag, $x[0] );
				} elseif ( $stack && end( $stack )[0] === $tag ) {
					array_pop( $stack );
				} else {
					$close .= '</' . $tag . '>';   // closes a tag that was opened before the match
				}
			}
		}
		foreach ( $stack as $o ) {
			$open .= $o[1];                    // opened inside the match, closed after it
		}
		return $new . $close . $open;
	};
}

function csa_te_run( $in ) {
	$p = csa_valid_post( $in['id'] ?? 0 );
	if ( ! $p ) {
		return array( 'error' => 'Page not found.' );
	}
	$g = csa_live_guard( $p );
	if ( $g ) {
		return $g;
	}
	$old = (string) ( $in['old_text'] ?? '' );
	$new = (string) ( $in['new_text'] ?? '' );
	$re  = csa_te_regex( $old );
	if ( '' === $re ) {
		return array( 'error' => 'old_text is too short. Give at least 3 words of the visible text.' );
	}
	if ( empty( $in['allow_common'] ) ) {
		$cp = csa_common_pages( $p->ID, $old );
		if ( count( $cp ) >= 2 ) {
			return csa_common_error( $old, $cp );
		}
	}
	$wid  = (string) ( $in['widget_id'] ?? '' );
	$skip = array( 'elType', 'widgetType', 'id', 'isInner', '_element_id', '_css_classes', 'custom_css', 'link', 'url', 'image', 'background_image' );

	if ( csa_is_elementor( $p->ID ) ) {
		list( $raw, $data ) = csa_el_load( $p->ID );
		if ( ! $data ) {
			return array( 'error' => 'No Elementor data on this page.' );
		}
		$found = array();
		$walk  = function ( array &$els ) use ( &$walk, $re, $wid, $skip, &$found ) {
			foreach ( $els as &$el ) {
				$id = (string) ( $el['id'] ?? '' );
				if ( 'widget' === ( $el['elType'] ?? '' ) && ( '' === $wid || $wid === $id ) ) {
					$h = array();
					csa_te_scan( $el['settings'] ?? array(), $re, array(), $h, $skip );
					foreach ( $h as $x ) {
						$found[] = array( 'widget' => $id, 'type' => (string) ( $el['widgetType'] ?? '' ), 'path' => $x['path'], 'count' => $x['count'], 'snippet' => $x['snippet'] );
					}
				}
				if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
					$walk( $el['elements'] );
				}
			}
			unset( $el );
		};
		$walk( $data );
		$total = array_sum( wp_list_pluck( $found, 'count' ) );
		if ( 1 !== $total ) {
			return csa_te_problem( $total, $found, $old );
		}
		$target = $found[0];
		$apply  = function ( array &$els ) use ( &$apply, $target, $re, $new ) {
			foreach ( $els as &$el ) {
				if ( (string) ( $el['id'] ?? '' ) === $target['widget'] ) {
					$ref = &$el['settings'];
					foreach ( $target['path'] as $k ) {
						$ref = &$ref[ $k ];
					}
					$ref = preg_replace_callback( $re, csa_te_sub( $new ), $ref, 1 );
					unset( $ref );
				}
				if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
					$apply( $el['elements'] );
				}
			}
			unset( $el );
		};
		$apply( $data );
		$bid = csa_el_save( $p->ID, $raw, $data );
		return array( 'ok' => true, 'widget' => $target['widget'], 'widget_type' => $target['type'], 'link' => get_permalink( $p->ID ), 'backup' => $bid, '_log' => array( csa_t( 'Edited text of' ) . ' #' . $p->ID . ': ' . get_the_title( $p->ID ), array( 'type' => 'elementor', 'post' => $p->ID, 'backup' => $bid ) ) );
	}

	// Normal page / post content.
	$h = array();
	csa_te_scan( $p->post_content, $re, array(), $h, array() );
	$total = array_sum( wp_list_pluck( $h, 'count' ) );
	if ( 1 !== $total ) {
		return csa_te_problem( $total, array(), $old );
	}
	$undo = csa_snapshot_post( $p->ID );
	$r    = wp_update_post( array( 'ID' => $p->ID, 'post_content' => preg_replace_callback( $re, csa_te_sub( $new ), $p->post_content, 1 ) ), true );
	if ( is_wp_error( $r ) ) {
		return array( 'error' => $r->get_error_message() );
	}
	return array( 'ok' => true, 'link' => get_permalink( $p->ID ), '_log' => array( csa_t( 'Edited text of' ) . ' #' . $p->ID . ': ' . get_the_title( $p->ID ), array( 'type' => 'post', 'post' => $p->ID, 'undo' => $undo ) ) );
}

function csa_te_problem( $total, $found, $old ) {
	if ( 0 === $total ) {
		return array( 'error' => 'That text was not found in any widget (searched the visible text of every widget). Do NOT retry the same words and do NOT re-read the whole page. Use find_in_html with 2-3 distinctive words from the sentence to see the real wording, then call replace_text again with the real words; or tell the owner the sentence is not on this page.' );
	}
	return array( 'error' => 'The text matches ' . $total . ' places. Add more words, or pass widget_id. Places: ' . wp_json_encode( array_slice( $found, 0, 8 ), CSA_JSON ) );
}
