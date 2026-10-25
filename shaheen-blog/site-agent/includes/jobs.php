<?php
/**
 * Jobs board: scan a page (no AI) -> numbered jobs -> the owner runs "1,2,5".
 * Design fixes are applied from the audit's ready CSS (no AI). SEO text and schema use ONE small
 * AI call each. Anything else is handed to the chat agent with the page already chosen.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------ */
/* SEO plugin's own score (Yoast / Rank Math / AIOSEO)                  */
/* ------------------------------------------------------------------ */

function csa_plugin_score( $id ) {
	global $wpdb;
	$plugin = csa_seo_plugin();
	$out    = array( 'plugin' => $plugin ? $plugin : 'none', 'seo' => null, 'read' => null, 'note' => '' );
	if ( 'Yoast SEO' === $plugin ) {
		$s = get_post_meta( $id, '_yoast_wpseo_linkdex', true );
		$r = get_post_meta( $id, '_yoast_wpseo_content_score', true );
		$out['seo']  = '' !== $s ? (int) $s : null;
		$out['read'] = '' !== $r ? (int) $r : null;
	} elseif ( 'Rank Math' === $plugin ) {
		$s = get_post_meta( $id, 'rank_math_seo_score', true );
		$out['seo'] = '' !== $s ? (int) $s : null;
	} else {
		$t = $wpdb->prefix . 'aioseo_posts';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			$s = $wpdb->get_var( $wpdb->prepare( "SELECT seo_score FROM {$t} WHERE post_id = %d", $id ) );
			$out['plugin'] = 'All in One SEO';
			$out['seo']    = null !== $s && '' !== $s ? (int) $s : null;
		}
	}
	if ( null === $out['seo'] ) {
		$out['note'] = 'none' === $out['plugin'] ? 'No Yoast / Rank Math / AIOSEO found.' : 'The plugin has not scored this page yet. Open it in the WordPress editor once and press Update: the plugin calculates its score only there.';
	}
	return $out;
}

/* ------------------------------------------------------------------ */
/* Build the job list                                                   */
/* ------------------------------------------------------------------ */

function csa_jobs_get( $id ) {
	$j = get_post_meta( $id, '_csa_jobs', true );
	return is_array( $j ) ? $j : array();
}

function csa_jobs_kw( $id ) {
	return trim( (string) csa_si_seo( $id )['sk'] );
}

function csa_jobs_build( $id ) {
	$sc = csa_seo_score( $id );
	if ( is_wp_error( $sc ) ) {
		return $sc;
	}
	$kw   = csa_jobs_kw( $id );
	$jobs = array();
	$add  = function ( $kind, $area, $title, $detail, $pts, $extra = array() ) use ( &$jobs ) {
		foreach ( $jobs as &$e ) {
			if ( $e['kind'] === $kind && $e['title'] === $title ) {
				$e['pts'] += $pts;
				return;
			}
		}
		unset( $e );
		$jobs[] = array_merge( array( 'key' => substr( md5( $kind . $title ), 0, 10 ), 'kind' => $kind, 'area' => $area, 'title' => $title, 'detail' => $detail, 'pts' => $pts, 'status' => 'todo', 'result' => '', 'done_title' => '', 'undo' => array() ), $extra );
	};
	$meta = 0;
	$cont = 0;
	$why  = array();
	$cwhy = array();
	$f0   = array();
	foreach ( $sc['items'] as $i ) {
		if ( $i['ok'] ) {
			continue;
		}
		$gap = $i['max'] - $i['points'];
		$c   = $i['check'];
		$f0[ $c ] = $gap;
		if ( preg_match( '/^(Title 30|Meta description 1|Keyword in title|Keyword in meta)/', $c ) ) {
			$meta += $gap;
			$why[] = $c;
		} elseif ( preg_match( '/^(At least 300|At least 3 internal)/', $c ) ) {
			$cont += $gap;
			$cwhy[] = $c;
		} elseif ( 0 === strpos( $c, 'At least 2 H2' ) ) {
			$add( 'h2', 'text', 'Turn existing section labels into H2 headings', 'No AI: the heading widgets that are H3-H6 (never the H1) become H2, so the page has at least 2 sub-headings. Only the heading level changes, not the look or the text.', $gap );
		} elseif ( 0 === strpos( $c, 'Schema' ) ) {
			$add( 'schema', 'seo', 'Add schema (JSON-LD)', 'The AI writes it from the page text and the site memory.', $gap );
		} elseif ( 0 === strpos( $c, 'Every image has alt' ) ) {
			$mf   = (array) ( $sc['facts']['missing_alt_files'] ?? array() );
			$real = array_filter( $mf, function ( $x ) { return 'data:placeholder' !== $x; } );
			if ( $mf && ! $real ) {
				if ( csa_jobs_image_candidates( $id ) ) {
					$add( 'image_place', 'image', 'Replace ' . count( $mf ) . ' placeholder image(s) with photos from your Media Library', 'The AI picks the best unused photos for this page. They replace the placeholders, with alt text.', $gap, array( 'needs_kw' => 1 ) );
				} else {
					$add( 'owner', 'image', 'YOU: ' . count( $mf ) . ' image(s) are inline placeholders (SVG/data), not real photos', 'Upload real photos in Site Agent > Images, then run this scan again: the agent will place them. Placeholders never get alt text.', $gap );
				}
			} elseif ( (int) ( $sc['facts']['images_missing_alt'] ?? 0 ) > 0 ) {
				$add( 'alt', 'image', 'Write alt text for ' . (int) $sc['facts']['images_missing_alt'] . ' image(s) without it', 'Uses the focus keyword only where the image really fits.', $gap );
			}
		} elseif ( 0 === strpos( $c, 'Exactly one H1' ) ) {
			$add( 'h1_fix', 'text', 'Make the page have exactly one H1', 'No AI: extra H1 headings in the content become H2 (the page title counts as the H1). Only the heading level changes. (' . $i['fix'] . ')', $gap );
		} elseif ( 0 === strpos( $c, 'Keyword in H1' ) && '' !== $kw ) {
			$add( 'h1_kw', 'text', 'Put the keyword "' . $kw . '" into the main heading (H1)', 'The AI rewrites the H1 with the same meaning.', $gap );
		} elseif ( 0 === strpos( $c, 'Keyword in the first part' ) && '' !== $kw ) {
			$add( 'intro_kw', 'text', 'Put the keyword "' . $kw . '" into the opening paragraph', 'The AI rewrites the first paragraph with the same facts.', $gap );
		} elseif ( 0 === strpos( $c, 'At least one image' ) || 0 === strpos( $c, 'Social image' ) ) {
			if ( csa_jobs_image_candidates( $id ) ) {
				$add( 'image_place', 'image', 'Place photos from your Media Library on this page (and set the featured image)', 'The AI picks the best unused photos. Alt text is written for each. This also fixes the social (og:image) image.', $gap, array( 'needs_kw' => 1 ) );
			} else {
				$add( 'owner', 'image', 'YOU: add a photo and set it as the featured image', 'Upload the photo in Site Agent > Images (it is renamed, compressed and given alt text), then run this scan again: the agent will place it and set the featured image.', $gap );
			}
		} elseif ( preg_match( '/^(Language|Canonical|Viewport)/', $c ) ) {
			if ( 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			$add( 'owner', 'seo', 'YOU: ' . $c . ' is missing', $i['fix'], $gap );
		} elseif ( 0 === strpos( $c, 'At least 1 external' ) ) {
			$add( 'owner', 'text', 'YOU (optional): add one link to an official or outside source', 'For example the education board or an official notice. Only if you have one.', $gap );
		}
	}
	if ( '' === $kw ) {
		$add( 'keyword', 'seo', 'Choose the focus keyword', 'The AI suggests 3 keywords for this page; you choose one. The text jobs wait for it.', 6 );
	}
	if ( $meta || '' === $kw ) {
		$add( 'seo_meta', 'seo', 'Write the SEO title and meta description', $why ? implode( '; ', $why ) : 'Title and description with the focus keyword.', $meta, array( 'needs_kw' => 1 ) );
	}
	if ( $cont ) {
		$add( 'content', 'text', 'Add useful text with the keyword and internal links', implode( '; ', $cwhy ) . '. The AI adds about 2 short sections (from facts on the site only) and a "Related" line with links.', $cont, array( 'needs_kw' => 1 ) );
	}
	// Design findings are only listed: the design agent will take them later.
	$dz = csa_dsg_audit( $id );
	if ( $dz ) {
		$lines = array();
		foreach ( array_slice( $dz, 0, 6 ) as $d ) {
			$lines[] = ucfirst( $d['severity'] ) . ' — ' . $d['what'] . ': ' . $d['issue'];
		}
		$add( 'design', 'design', 'DESIGN: ' . count( $dz ) . ' layout finding(s), left for the design agent', implode( ' | ', $lines ), 0 );
	}
	if ( function_exists( 'csa_slots_missing' ) && csa_slots_missing( $id ) ) {
		$add( 'owner', 'image', 'YOU: upload the photos this page needs', 'Open Site Agent > Images and upload them. They are renamed, compressed and given alt text automatically.', 0 );
	}
	// Keep finished jobs: they are the history ("what was changed").
	$have = array();
	foreach ( $jobs as $j ) {
		$have[ $j['key'] ] = true;
	}
	foreach ( csa_jobs_get( $id ) as $o ) {
		if ( 'done' === $o['status'] && empty( $have[ $o['key'] ] ) && 'design' !== $o['kind'] ) {
			$jobs[] = $o;
		}
	}
	$rank = array( 'keyword' => 1, 'seo_meta' => 2, 'h1_fix' => 2.5, 'h1_kw' => 3, 'intro_kw' => 4, 'h2' => 5, 'content' => 6, 'image_place' => 6.5, 'alt' => 7, 'schema' => 8, 'owner' => 9, 'design' => 10 );
	foreach ( $jobs as $i => &$j0 ) {
		$j0['_o'] = $i;
	}
	unset( $j0 );
	usort( $jobs, function ( $a, $b ) use ( $rank ) {
		return array( $rank[ $a['kind'] ], $a['_o'] ) <=> array( $rank[ $b['kind'] ], $b['_o'] );
	} );
	$old = array();
	foreach ( csa_jobs_get( $id ) as $o ) {
		$old[ $o['key'] ] = $o;
	}
	foreach ( $jobs as $i => &$j ) {
		$o = $old[ $j['key'] ] ?? null;
		if ( $o && 'skip' === $o['status'] ) {
			$j['status'] = 'skip';
		}
		if ( $o && 'done' === $o['status'] && 'owner' === $j['kind'] ) {
			$j = $o;
		}
		$j['n'] = $i + 1;
		unset( $j['_o'] );
	}
	unset( $j );
	update_post_meta( $id, '_csa_jobs', wp_slash( $jobs ) );
	update_post_meta( $id, '_csa_jobs_time', time() );
	if ( '' === (string) get_post_meta( $id, '_csa_score_first', true ) ) {
		update_post_meta( $id, '_csa_score_first', $sc['score'] );
	}
	return array( 'jobs' => $jobs, 'score' => $sc, 'first' => (int) get_post_meta( $id, '_csa_score_first', true ) );
}

/* ------------------------------------------------------------------ */
/* One small AI call that returns JSON                                  */
/* ------------------------------------------------------------------ */

function csa_jobs_ai_json( $system, $user, $feature, $max = 900, $extra = array() ) {
	$data = csa_claude( array( array( 'role' => 'user', 'content' => $user ) ), $system, array_merge( array( 'max_tokens' => $max, 'task' => 'seo', 'feature' => $feature ), $extra ) );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$t = csa_text_of( $data );
	if ( preg_match( '/\{.*\}/s', $t, $m ) ) {
		$j = json_decode( $m[0], true );
		if ( is_array( $j ) ) {
			return $j;
		}
	}
	return new WP_Error( 'json', 'The AI did not return usable JSON. Press Run again.' );
}

function csa_jobs_page_brief( $id ) {
	$f   = csa_seo_collect( $id );
	$cur = csa_si_seo( $id );
	return "Page: \"" . $f['page_title'] . "\" (" . $f['url'] . ")\nH1: " . implode( ' | ', (array) ( $f['h1'] ?? array() ) ) . "\nH2: " . implode( ' | ', array_slice( (array) ( $f['h2'] ?? array() ), 0, 8 ) )
		. "\nCurrent SEO title: " . $cur['st'] . "\nCurrent meta description: " . $cur['sd'] . "\nFocus keyword: " . $cur['sk'] . "\nText start: " . mb_substr( (string) ( $f['text_sample'] ?? '' ), 0, 900 );
}

const CSA_JOBS_RULES = ' Write in the same language as the page text. Use ONLY facts that appear in the page text or in the site memory: never invent fees, dates, numbers, names or promises. Use the focus keyword naturally (never stuffed, never twice in one sentence). WRITING STYLE (humanise): write like a warm, experienced school staff member talking to a parent: short plain sentences, concrete details, active voice, varied sentence length. Never use AI-sounding filler such as "in today\'s fast-paced world", "unlock", "delve", "tapestry", "realm", "embark on a journey", "game-changer", "cutting-edge", "holistic", "seamless", "it is important to note", "in conclusion", "look no further". No hype, no stacked adjectives, no empty praise, no repeated sentence openings, no lists of three buzzwords. COHERENCE: every sentence must belong to THIS page\'s topic and match the tone, tense, names and facts already on the page; never add a side topic, never contradict the page, never repeat what the page already says. If you have nothing true and useful to add, write less.';

/** Visible text of the first real paragraph of the page. */
function csa_jobs_filler( $text ) {
	return (bool) preg_match( '/fast-paced|unlock|delve|tapestry|\brealm\b|embark|game-changer|cutting-edge|holistic|seamless|important to note|in conclusion|look no further|state-of-the-art/i', $text );
}

function csa_jobs_first_para( $id ) {
	$strings = array();
	if ( csa_is_elementor( $id ) ) {
		list( , $data ) = csa_el_load( $id );
		$walk = function ( $els ) use ( &$walk, &$strings ) {
			foreach ( (array) $els as $el ) {
				if ( 'widget' === ( $el['elType'] ?? '' ) && in_array( $el['widgetType'] ?? '', array( 'text-editor', 'html' ), true ) ) {
					$strings[] = (string) ( $el['settings']['editor'] ?? $el['settings']['html'] ?? '' );
				}
				if ( ! empty( $el['elements'] ) ) {
					$walk( $el['elements'] );
				}
			}
		};
		$walk( $data );
	} else {
		$strings[] = (string) get_post_field( 'post_content', $id );
	}
	foreach ( $strings as $h ) {
		if ( preg_match_all( '#<p[^>]*>(.*?)</p>#is', $h, $m ) ) {
			foreach ( $m[1] as $inner ) {
				$t = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES, 'UTF-8' ) ) );
				if ( mb_strlen( $t ) >= 40 ) {
					return $t;
				}
			}
		}
	}
	return '';
}

/** Append HTML to the end of the page text. Returns array( ok, undo descriptor ) or WP_Error. */
function csa_jobs_append( $id, $html ) {
	$p = get_post( $id );
	if ( csa_is_elementor( $id ) ) {
		list( $raw, $data ) = csa_el_load( $id );
		$tid  = '';
		$tkey = '';
		$find = function ( array $els ) use ( &$find, &$tid, &$tkey ) {
			foreach ( $els as $el ) {
				if ( 'widget' === ( $el['elType'] ?? '' ) && 'text-editor' === ( $el['widgetType'] ?? '' ) && isset( $el['settings']['editor'] ) ) {
					$tid  = (string) $el['id'];
					$tkey = 'editor';
				} elseif ( 'widget' === ( $el['elType'] ?? '' ) && 'html' === ( $el['widgetType'] ?? '' ) && isset( $el['settings']['html'] ) && 'editor' !== $tkey ) {
					$tid  = (string) $el['id'];
					$tkey = 'html';
				}
				if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
					$find( $el['elements'] );
				}
			}
		};
		$find( (array) $data );
		if ( '' === $tid ) {
			return new WP_Error( 'append', 'This page has no text-editor or HTML widget to add text to. Convert the page to clean HTML first, or add a text widget at the end of the page.' );
		}
		$put = function ( array &$els ) use ( &$put, $tid, $tkey, $html ) {
			foreach ( $els as &$el ) {
				if ( (string) ( $el['id'] ?? '' ) === $tid ) {
					$el['settings'][ $tkey ] .= "\n" . $html;
				}
				if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
					$put( $el['elements'] );
				}
			}
			unset( $el );
		};
		$put( $data );
		$bid = csa_el_save( $id, $raw, $data );
		return array( 'type' => 'elementor', 'post' => (int) $id, 'backup' => $bid );
	}
	$undo = csa_snapshot_post( $id );
	$r    = wp_update_post( array( 'ID' => $id, 'post_content' => $p->post_content . "\n" . $html ), true );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	csa_clear_cache( $id );
	return $undo;
}


/** Unused photos in the Media Library that could go on a page (newest first). */
function csa_jobs_image_candidates( $id ) {
	static $cache = null;
	if ( null === $cache ) {
		$cache = array();
		$q     = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'numberposts' => 60, 'orderby' => 'date', 'order' => 'DESC' ) );
		foreach ( $q as $a ) {
			if ( ! wp_get_attachment_image_url( $a->ID, 'full' ) || 'image/svg+xml' === $a->post_mime_type ) {
				continue;
			}
			if ( csa_media_usage( $a->ID, 1 ) ) {
				continue;
			}
			$cache[] = $a->ID;
			if ( count( $cache ) >= 25 ) {
				break;
			}
		}
	}
	return $cache;
}

/* ------------------------------------------------------------------ */
/* Run one job                                                          */
/* ------------------------------------------------------------------ */

/** @return array( status, message, done_title, extra ) */
function csa_jobs_do( $id, $j ) {
	$ctx = array( 'state' => 'approved', 'items' => array(), 'pages' => array(), 'mem' => csa_mem_default() );
	$kw  = csa_jobs_kw( $id );
	if ( ! empty( $j['needs_kw'] ) && '' === $kw ) {
		return array( 'fail', 'Choose the focus keyword first (the first job).', '', array() );
	}
	$ttl = get_the_title( $id );
	switch ( $j['kind'] ) {
		case 'keyword':
			$sys = 'You are an SEO strategist for a school website in Bangladesh. ' . csa_site_context() . "\n\nSuggest exactly 3 focus keywords for ONE page. Take them from the KEYWORD STRATEGY above: pick the brand that matches this page (school or cadet), choose words that fit this page's topic, and fill [AREA] with this page's branch if it is a branch page (never mix brands, never invent an area). If nothing in the strategy fits, use what parents really type into Google (1-4 words). Return ONLY JSON: {\"options\":[{\"keyword\":\"...\",\"why\":\"one short reason\"},{...},{...}]}";
			$r   = csa_jobs_ai_json( $sys, csa_jobs_page_brief( $id ), 'SEO agent: keywords' );
			if ( is_wp_error( $r ) || empty( $r['options'] ) ) {
				return array( 'fail', is_wp_error( $r ) ? $r->get_error_message() : 'No keywords returned.', '', array() );
			}
			$opts = array();
			foreach ( array_slice( (array) $r['options'], 0, 3 ) as $o ) {
				$opts[] = array( 'keyword' => sanitize_text_field( $o['keyword'] ?? '' ), 'why' => sanitize_text_field( $o['why'] ?? '' ) );
			}
			return array( 'choose', 'Choose one keyword below (or type your own).', '', array( 'options' => $opts ) );

		case 'seo_meta':
			$sys = 'You are an SEO writer. ' . csa_site_context() . "\n\nReturn ONLY JSON: {\"title\":\"<=60 characters, the focus keyword near the start\",\"description\":\"125-155 characters, includes the keyword, ends with a reason to click\"}." . CSA_JOBS_RULES;
			$r   = csa_jobs_ai_json( $sys, csa_jobs_page_brief( $id ), 'SEO agent: title and meta' );
			if ( is_wp_error( $r ) ) {
				return array( 'fail', $r->get_error_message(), '', array() );
			}
			$old = csa_si_seo( $id );
			$t   = trim( (string) ( $r['title'] ?? '' ) );
			$d   = trim( (string) ( $r['description'] ?? '' ) );
			if ( mb_strlen( $t ) > 60 ) {
				$t = trim( mb_substr( $t, 0, 59 ) );
			}
			if ( mb_strlen( $d ) > 158 ) {
				$d = trim( mb_substr( $d, 0, 155 ) ) . '…';
			}
			if ( mb_strlen( $t ) < 20 || mb_strlen( $d ) < 80 ) {
				return array( 'fail', 'The AI did not return a usable title and description. Nothing was changed. Press Run again.', '', array() );
			}
			$o = csa_run_tool( 'update_seo_meta', array( 'id' => $id, 'title' => $t, 'description' => $d ), $ctx );
			if ( isset( $o['error'] ) ) {
				return array( 'fail', $o['error'], '', array() );
			}
			return array( 'done', 'Title: ' . $t . ' | Description: ' . $d . ( ! empty( $o['warnings'] ) ? ' | Check: ' . implode( '; ', $o['warnings'] ) : '' ), 'SEO title ' . mb_strlen( $old['st'] ) . '→' . mb_strlen( $t ) . ' characters, meta description ' . mb_strlen( $old['sd'] ) . '→' . mb_strlen( $d ), array() );

		case 'h1_kw':
			$f  = csa_seo_collect( $id );
			$h1 = trim( (string) ( ( (array) ( $f['h1'] ?? array() ) )[0] ?? '' ) );
			if ( '' === $h1 ) {
				return array( 'fail', 'No H1 found to rewrite.', '', array() );
			}
			$sys = 'You rewrite one page heading (H1) for SEO. ' . csa_site_context() . "\n\nKeep the meaning and keep it short (max 70 characters). Include the focus keyword naturally. Return ONLY JSON: {\"h1\":\"...\"}." . CSA_JOBS_RULES;
			$r   = csa_jobs_ai_json( $sys, "Focus keyword: {$kw}\nCurrent H1: {$h1}\n" . csa_jobs_page_brief( $id ), 'SEO agent: H1' );
			$new = is_wp_error( $r ) ? '' : trim( (string) ( $r['h1'] ?? '' ) );
			if ( '' === $new || false === mb_stripos( $new, $kw ) ) {
				return array( 'fail', is_wp_error( $r ) ? $r->get_error_message() : 'The AI heading did not contain the keyword. Press Run again.', '', array() );
			}
			if ( mb_strtolower( trim( wp_strip_all_tags( $h1 ) ) ) === mb_strtolower( trim( wp_strip_all_tags( get_the_title( $id ) ) ) ) ) {
				$res = csa_run_tool( 'set_page_title', array( 'id' => $id, 'title' => $new ), $ctx ); // The H1 is the page title: it is not in the page content.
			} else {
				$res = csa_run_tool( 'replace_text', array( 'id' => $id, 'old_text' => $h1, 'new_text' => $new ), $ctx );
			}
			if ( isset( $res['error'] ) ) {
				return array( 'fail', $res['error'], '', array() );
			}
			return array( 'done', 'H1: "' . $h1 . '" → "' . $new . '"', 'H1 now: ' . $new, array() );

		case 'intro_kw':
			$para = csa_jobs_first_para( $id );
			if ( '' === $para ) {
				return array( 'fail', 'No opening paragraph (a normal text paragraph of 40+ characters) was found. Use the chat agent or edit it yourself.', '', array() );
			}
			$sys = 'You improve one opening paragraph for SEO. ' . csa_site_context() . "\n\nKeep every fact, number and name exactly as in the old paragraph, and keep about the same length (at most 20% longer). Include the focus keyword once, naturally, near the start. Plain text only, no HTML. Return ONLY JSON: {\"paragraph\":\"...\"}." . CSA_JOBS_RULES;
			$r   = csa_jobs_ai_json( $sys, "Focus keyword: {$kw}\nOld paragraph: {$para}", 'SEO agent: intro', 700 );
			$new = is_wp_error( $r ) ? '' : trim( (string) ( $r['paragraph'] ?? '' ) );
			if ( '' === $new || false === mb_stripos( $new, $kw ) || mb_strlen( $new ) > mb_strlen( $para ) * 1.4 || csa_jobs_filler( $new ) ) {
				return array( 'fail', is_wp_error( $r ) ? $r->get_error_message() : 'The AI paragraph was not usable (keyword missing or too long). Press Run again.', '', array() );
			}
			$res = csa_run_tool( 'replace_text', array( 'id' => $id, 'old_text' => $para, 'new_text' => $new ), $ctx );
			if ( isset( $res['error'] ) ) {
				return array( 'fail', $res['error'], '', array() );
			}
			return array( 'done', 'Old: ' . mb_substr( $para, 0, 120 ) . '… | New: ' . mb_substr( $new, 0, 120 ) . '…', 'Opening paragraph now has the keyword', array() );

		case 'content':
			$f     = csa_seo_collect( $id );
			$words = (int) ( $f['word_count'] ?? 0 );
			$need  = max( 120, 320 - $words );
			$cand  = array();
			foreach ( get_posts( array( 'post_type' => csa_seo_types(), 'post_status' => 'publish', 'numberposts' => 40, 'post__not_in' => array( $id ), 'orderby' => 'modified' ) ) as $c ) {
				$cand[ $c->ID ] = get_the_title( $c );
			}
			$list = '';
			foreach ( $cand as $cid => $ct ) {
				$list .= $cid . ': ' . $ct . "\n";
			}
			$sys = 'You add useful text to a school web page. ' . csa_site_context() . "\n\nWrite about {$need} words as 2 short sections for parents. Each section has an h2 heading (use the focus keyword in one heading) and 1-2 short paragraphs. Also choose 3 related pages from the list for a 'Related' line. Return ONLY JSON: {\"sections\":[{\"h2\":\"...\",\"paragraphs\":[\"...\",\"...\"]}],\"links\":[{\"id\":123,\"anchor\":\"2-4 words\"}]}. Plain text in the values, no HTML." . CSA_JOBS_RULES;
			$r   = csa_jobs_ai_json( $sys, "Focus keyword: {$kw}\nPage words now: {$words}\n" . csa_jobs_page_brief( $id ) . "\n\nRelated page candidates (id: title):\n" . $list, 'SEO agent: content', 1800 );
			if ( is_wp_error( $r ) || empty( $r['sections'] ) ) {
				return array( 'fail', is_wp_error( $r ) ? $r->get_error_message() : 'No text returned. Press Run again.', '', array() );
			}
			if ( csa_jobs_filler( wp_json_encode( $r['sections'], JSON_UNESCAPED_UNICODE ) ) ) {
				return array( 'fail', 'The AI text sounded generic (stock phrases). Nothing was changed. Press Run again.', '', array() );
			}
			$html = '';
			$wc   = 0;
			foreach ( array_slice( (array) $r['sections'], 0, 3 ) as $sec ) {
				$h2 = sanitize_text_field( $sec['h2'] ?? '' );
				if ( '' === $h2 ) {
					continue;
				}
				$html .= '<h2>' . esc_html( $h2 ) . "</h2>\n";
				foreach ( array_slice( (array) ( $sec['paragraphs'] ?? array() ), 0, 3 ) as $pp ) {
					$pp    = sanitize_text_field( $pp );
					$html .= '<p>' . esc_html( $pp ) . "</p>\n";
					$wc   += count( preg_split( '/\s+/u', $pp, -1, PREG_SPLIT_NO_EMPTY ) );
				}
			}
			$ln = array();
			foreach ( array_slice( (array) ( $r['links'] ?? array() ), 0, 3 ) as $l ) {
				$lid = (int) ( $l['id'] ?? 0 );
				if ( isset( $cand[ $lid ] ) && trim( (string) ( $l['anchor'] ?? '' ) ) !== '' ) {
					$ln[] = '<a href="' . esc_url( get_permalink( $lid ) ) . '">' . esc_html( sanitize_text_field( $l['anchor'] ) ) . '</a>';
				}
			}
			if ( $ln ) {
				$html .= '<p>' . esc_html( csa_t( 'Related' ) ) . ': ' . implode( ' · ', $ln ) . "</p>\n";
			}
			if ( '' === $html ) {
				return array( 'fail', 'The AI returned nothing usable. Press Run again.', '', array() );
			}
			$u = csa_jobs_append( $id, $html );
			if ( is_wp_error( $u ) ) {
				return array( 'fail', $u->get_error_message(), '', array() );
			}
			csa_log( csa_t( 'Added text to' ) . ' #' . $id . ': ' . $ttl, $u );
			return array( 'done', 'Added about ' . $wc . ' words in ' . count( (array) $r['sections'] ) . ' section(s) and ' . count( $ln ) . ' internal link(s) at the end of the page text.', 'Added ~' . $wc . ' words, ' . count( $ln ) . ' internal links', array() );

		case 'h2':
		case 'h1_fix':
			$r = csa_th_levels( $id, 'h2' === $j['kind'] ? 'two_h2' : 'one_h1' );
			if ( isset( $r['error'] ) ) {
				return array( 'fail', $r['error'], '', array() );
			}
			$lst = implode( ' | ', array_map( function ( $t ) { return mb_substr( $t, 0, 40 ); }, $r['changed'] ) );
			return array( 'done', 'Now H2: ' . $lst, count( $r['changed'] ) . ' heading(s) changed to H2: ' . mb_substr( $r['changed'][0], 0, 30 ), array() );

		case 'schema':
			$sys = 'You write schema.org JSON-LD for a school website. ' . csa_site_context() . "\n\nReturn ONLY one valid JSON object with @context and @type (EducationalOrganization, Course or FAQPage, whichever fits this page; FAQPage only if the page text really has questions and answers). Use only facts from the page text and site memory; leave out anything unknown.";
			$r   = csa_jobs_ai_json( $sys, csa_jobs_page_brief( $id ), 'SEO agent: schema' );
			if ( is_wp_error( $r ) ) {
				return array( 'fail', $r->get_error_message(), '', array() );
			}
			$o = csa_run_tool( 'set_page_schema', array( 'id' => $id, 'jsonld' => wp_json_encode( $r, CSA_JSON ) ), $ctx );
			return isset( $o['error'] ) ? array( 'fail', $o['error'], '', array() ) : array( 'done', 'Schema type ' . ( $r['@type'] ?? '' ) . ' added.', 'Schema added: ' . ( $r['@type'] ?? '' ), array() );

		case 'image_place':
			$cand = csa_jobs_image_candidates( $id );
			if ( ! $cand ) {
				return array( 'fail', 'No unused photos in the Media Library. Upload photos in Site Agent > Images first.', '', array() );
			}
			$list = '';
			foreach ( $cand as $aid ) {
				$list .= $aid . ': ' . get_the_title( $aid ) . ' | ' . basename( (string) get_attached_file( $aid ) ) . ' | alt: ' . get_post_meta( $aid, '_wp_attachment_image_alt', true ) . "\n";
			}
			$sys = 'You choose photos for a school web page. ' . csa_site_context() . "\n\nFrom the list pick the 1-2 photos that fit THIS page best (by title, file name and alt). Never pick a photo that clearly belongs to another topic. If none fits, return an empty list. Return ONLY JSON: {\"pick\":[123,456]}";
			$r   = csa_jobs_ai_json( $sys, csa_jobs_page_brief( $id ) . "\n\nUnused photos (id: title | file | alt):\n" . $list, 'SEO agent: photos', 300 );
			if ( is_wp_error( $r ) ) {
				return array( 'fail', $r->get_error_message(), '', array() );
			}
			$pick = array();
			foreach ( array_slice( (array) ( $r['pick'] ?? array() ), 0, 2 ) as $pid ) {
				if ( in_array( (int) $pid, $cand, true ) ) {
					$pick[] = (int) $pid;
				}
			}
			if ( ! $pick ) {
				return array( 'fail', 'No photo in the Media Library fits this page. Upload a fitting photo, then run again.', '', array() );
			}
			$imgs = array();
			foreach ( $pick as $aid ) {
				$alt = trim( (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ) );
				if ( '' === $alt ) {
					$a2 = csa_media_make_alt( $aid );
					$alt = is_wp_error( $a2 ) ? get_the_title( $aid ) : $a2;
				}
				$imgs[] = '<figure class="wp-block-image"><img src="' . esc_url( wp_get_attachment_image_url( $aid, 'large' ) ) . '" alt="' . esc_attr( $alt ) . '"></figure>';
			}
			$p      = get_post( $id );
			$placed = 0;
			if ( csa_is_elementor( $id ) ) {
				$u = csa_jobs_append( $id, implode( "\n", $imgs ) );
				if ( is_wp_error( $u ) ) {
					return array( 'fail', $u->get_error_message(), '', array() );
				}
				$placed = count( $imgs );
				csa_log( csa_t( 'Placed photos on' ) . ' #' . $id . ': ' . $ttl, $u );
			} else {
				$c = $p->post_content;
				$n = 0;
				// Placeholders (inline data images) are replaced first, one photo each.
				$c = preg_replace_callback( '#<img[^>]+src=["\']data:[^>]*>#i', function ( $m ) use ( &$n, $imgs ) {
					if ( $n >= count( $imgs ) ) {
						return $m[0];
					}
					preg_match( '#src=["\']([^"\']+)["\']#', $imgs[ $n ], $sm );
					preg_match( '#alt=["\']([^"\']*)["\']#', $imgs[ $n ], $am );
					$n++;
					return '<img src="' . $sm[1] . '" alt="' . $am[1] . '">';
				}, $c );
				$placed = $n;
				for ( $k = $n; $k < count( $imgs ); $k++ ) {
					if ( 0 === $k && preg_match( '#</p>#i', $c ) ) {
						$c = preg_replace( '#</p>#i', "</p>\n" . $imgs[ $k ], $c, 1 );
					} elseif ( preg_match( '#<h2#i', $c ) && preg_match_all( '#<h2#i', $c ) >= 2 ) {
						$pos = strripos( $c, '<h2' );
						$c   = substr( $c, 0, $pos ) . $imgs[ $k ] . "\n" . substr( $c, $pos );
					} else {
						$c .= "\n" . $imgs[ $k ];
					}
					$placed++;
				}
				$u = csa_snapshot_post( $id );
				wp_update_post( array( 'ID' => $id, 'post_content' => $c ) );
				csa_clear_cache( $id );
				csa_log( csa_t( 'Placed photos on' ) . ' #' . $id . ': ' . $ttl, $u );
			}
			$feat = '';
			if ( ! has_post_thumbnail( $id ) ) {
				$old = get_post_meta( $id, '_thumbnail_id', true );
				set_post_thumbnail( $id, $pick[0] );
				csa_log( csa_t( 'Set featured image on' ) . ' #' . $id . ': ' . $ttl, array( 'type' => 'seo', 'post' => (int) $id, 'old' => array( '_thumbnail_id' => $old ) ) );
				$feat = ' + featured image';
			}
			return array( 'done', 'Placed ' . $placed . ' photo(s): ' . implode( ', ', array_map( function ( $a ) { return basename( (string) get_attached_file( $a ) ); }, $pick ) ) . $feat, 'Photos placed: ' . $placed . $feat, array() );

		case 'alt':
			global $wpdb;
			$f    = csa_seo_collect( $id );
			$made = array();
			foreach ( array_slice( (array) ( $f['missing_alt_files'] ?? array() ), 0, 6 ) as $file ) {
				$att = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value LIKE %s LIMIT 1", '%' . $wpdb->esc_like( preg_replace( '/-\d+x\d+(\.[a-z]+)$/i', '$1', $file ) ) ) );
				if ( ! $att ) {
					continue;
				}
				$a = csa_media_make_alt( $att );
				if ( ! is_wp_error( $a ) ) {
					$made[] = $file . ': ' . $a;
				}
			}
			$left = array_filter( (array) ( csa_seo_collect( $id )['missing_alt_files'] ?? array() ), function ( $x ) { return 'data:placeholder' !== $x; } );
			if ( ! $made && ! $left ) {
				return array( 'done', 'No image without alt text is left.', 'Alt text: nothing left to fix', array() );
			}
			return $made ? array( 'done', implode( ' | ', $made ), 'Alt text written for ' . count( $made ) . ' image(s)', array() ) : array( 'fail', 'No image from the media library could be matched. Add alt text in the Images screen, or use the chat agent.', '', array() );
	}
	return array( 'owner', '', '', array() );
}

/* ------------------------------------------------------------------ */
/* Ajax                                                                 */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_csa_jobs_scan', 'csa_ajax_jobs_scan' );
function csa_ajax_jobs_scan() {
	csa_guard( 'chat' );
	$id = (int) ( $_POST['id'] ?? 0 );
	if ( ! csa_valid_post( $id ) ) {
		wp_send_json_error( csa_t( 'Page not found.' ) );
	}
	$r = csa_jobs_build( $id );
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( $r->get_error_message() );
	}
	wp_send_json_success( array( 'title' => get_the_title( $id ), 'jobs' => $r['jobs'], 'own' => $r['score']['score'], 'first' => $r['first'], 'source' => $r['score']['source'], 'plugin' => csa_plugin_score( $id ), 'chat' => add_query_arg( array( 'page' => 'csa-chat', 'job' => $id ), admin_url( 'admin.php' ) ) ) );
}

function csa_jobs_log_ids() {
	$ids = array();
	foreach ( (array) get_option( CSA_LOG, array() ) as $e ) {
		$ids[] = $e['id'] ?? '';
	}
	return $ids;
}

function csa_jobs_save_one( $id, $n, $fn ) {
	$jobs = csa_jobs_get( $id );
	foreach ( $jobs as $i => $j ) {
		if ( (int) $j['n'] === $n ) {
			$jobs[ $i ] = $fn( $j );
			update_post_meta( $id, '_csa_jobs', wp_slash( $jobs ) );
			return $jobs[ $i ];
		}
	}
	return null;
}

/** Run one job of a page and save the result. Returns array( job, own ) or WP_Error. Used by the screen and by the background autopilot. */
function csa_jobs_run_one( $id, $n ) {
	$p = csa_valid_post( $id );
	if ( ! $p ) {
		return new WP_Error( 'run', csa_t( 'Page not found.' ) );
	}
	$g = csa_live_guard( $p );
	if ( $g ) {
		return new WP_Error( 'run', $g['error'] ?? 'Live editing is off.' );
	}
	foreach ( csa_jobs_get( $id ) as $j ) {
		if ( (int) $j['n'] !== $n ) {
			continue;
		}
		if ( 'done' === $j['status'] ) {
			return new WP_Error( 'run', 'This job is already done.' );
		}
		$before = csa_jobs_log_ids();
		list( $st, $msg, $title, $extra ) = csa_jobs_do( $id, $j );
		if ( 'fail' === $st && false !== strpos( (string) $msg, 'Press Run again' ) ) {
			list( $st, $msg, $title, $extra ) = csa_jobs_do( $id, $j ); // The AI answer was unusable: ask once more before giving up.
		}
		$new = array_values( array_diff( csa_jobs_log_ids(), $before ) );
		$job = csa_jobs_save_one( $id, $n, function ( $j ) use ( $st, $msg, $title, $extra, $new ) {
			$j['status'] = in_array( $st, array( 'done', 'fail', 'choose' ), true ) ? $st : 'todo';
			$j['result'] = $msg;
			if ( 'done' === $st ) {
				$j['done_title'] = $title;
				$j['undo']      = $new;
			}
			$j['options'] = 'choose' === $st ? ( $extra['options'] ?? array() ) : array();
			return $j;
		} );
		$sc = csa_seo_score( $id );
		return array( 'job' => $job, 'own' => is_wp_error( $sc ) ? null : $sc['score'] );
	}
	return new WP_Error( 'run', 'No such job. Scan the page again.' );
}

add_action( 'wp_ajax_csa_jobs_run', 'csa_ajax_jobs_run' );
function csa_ajax_jobs_run() {
	csa_guard( 'chat' );
	$r = csa_jobs_run_one( (int) ( $_POST['id'] ?? 0 ), (int) ( $_POST['n'] ?? 0 ) );
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( $r->get_error_message() );
	}
	$r['alerts'] = csa_take_alerts();
	wp_send_json_success( $r );
}

/** Save the approved keyword and rebuild the list. Returns array( jobs, own ) or WP_Error. */
function csa_jobs_apply_kw( $id, $n, $kw ) {
	$p = csa_valid_post( $id );
	if ( ! $p || '' === $kw ) {
		return new WP_Error( 'kw', csa_t( 'Choose or type a keyword.' ) );
	}
	$before = csa_jobs_log_ids();
	$old    = csa_jobs_kw( $id );
	$r      = csa_seo_set( $id, array( 'keyword' => $kw ), csa_t( 'Focus keyword set on' ) );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$new = array_values( array_diff( csa_jobs_log_ids(), $before ) );
	csa_jobs_save_one( $id, $n, function ( $j ) use ( $kw, $new, $old ) {
		$j['status']     = 'done';
		$j['options']    = array();
		$j['result']     = 'Focus keyword: ' . $kw;
		$j['done_title'] = 'Focus keyword: ' . ( '' !== $old ? $old . ' → ' : '' ) . $kw;
		$j['undo']       = $new;
		return $j;
	} );
	$b = csa_jobs_build( $id ); // Keyword changed: rebuild the list, keeping what is already done.
	return array( 'jobs' => is_wp_error( $b ) ? csa_jobs_get( $id ) : $b['jobs'], 'own' => is_wp_error( $b ) ? null : $b['score']['score'] );
}

add_action( 'wp_ajax_csa_jobs_setkw', 'csa_ajax_jobs_setkw' );
function csa_ajax_jobs_setkw() {
	csa_guard( 'chat' );
	$r = csa_jobs_apply_kw( (int) ( $_POST['id'] ?? 0 ), (int) ( $_POST['n'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) ) );
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( $r->get_error_message() );
	}
	wp_send_json_success( $r );
}

add_action( 'wp_ajax_csa_jobs_undo', 'csa_ajax_jobs_undo' );
function csa_ajax_jobs_undo() {
	csa_guard( 'chat' );
	$id = (int) ( $_POST['id'] ?? 0 );
	$n  = (int) ( $_POST['n'] ?? 0 );
	$err = '';
	$job = csa_jobs_save_one( $id, $n, function ( $j ) use ( &$err ) {
		foreach ( array_reverse( (array) ( $j['undo'] ?? array() ) ) as $lid ) {
			$r = csa_undo( $lid );
			if ( is_wp_error( $r ) ) {
				$err = $r->get_error_message();
			}
		}
		if ( ! $err ) {
			$j['status']     = 'todo';
			$j['done_title'] = '';
			$j['result']     = 'Undone.';
			$j['undo']       = array();
		}
		return $j;
	} );
	if ( $err ) {
		wp_send_json_error( $err );
	}
	$sc = csa_seo_score( $id );
	wp_send_json_success( array( 'job' => $job, 'own' => is_wp_error( $sc ) ? null : $sc['score'] ) );
}

add_action( 'wp_ajax_csa_jobs_manual', 'csa_ajax_jobs_manual' );
function csa_ajax_jobs_manual() {
	csa_guard( 'chat' );
	$id = (int) ( $_POST['id'] ?? 0 );
	$n  = (int) ( $_POST['n'] ?? 0 );
	$st = 'skip' === ( $_POST['status'] ?? '' ) ? 'skip' : ( 'todo' === ( $_POST['status'] ?? '' ) ? 'todo' : 'done' );
	$job = csa_jobs_save_one( $id, $n, function ( $j ) use ( $st ) {
		$j['status']     = $st;
		$j['done_title'] = 'done' === $st ? 'You did: ' . preg_replace( '/^YOU( \(optional\))?: /', '', $j['title'] ) : '';
		return $j;
	} );
	wp_send_json_success( array( 'job' => $job ) );
}


/** Pages for the autopilot: published pages/posts, optionally one type. */
add_action( 'wp_ajax_csa_jobs_targets', 'csa_ajax_jobs_targets' );
function csa_ajax_jobs_targets() {
	csa_guard( 'chat' );
	$type  = sanitize_key( $_POST['type'] ?? '' );
	$types = in_array( $type, csa_seo_types(), true ) ? array( $type ) : csa_seo_types();
	$ids   = get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'numberposts' => 60, 'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids' ) );
	$out   = array();
	foreach ( $ids as $id ) {
		$out[] = array( 'id' => $id, 'title' => get_the_title( $id ), 'type' => get_post_type( $id ) );
	}
	wp_send_json_success( $out );
}

add_action( 'wp_ajax_csa_jobs_overview', 'csa_ajax_jobs_overview' );
function csa_ajax_jobs_overview() {
	csa_guard( 'chat' );
	$ids = get_posts( array( 'post_type' => csa_seo_types(), 'post_status' => csa_seo_statuses(), 'numberposts' => 200, 'meta_key' => '_csa_jobs_time', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'fields' => 'ids' ) );
	$out = array();
	foreach ( $ids as $id ) {
		$c = array( 'todo' => 0, 'done' => 0, 'agent' => 0, 'fail' => 0 );
		foreach ( csa_jobs_get( $id ) as $j ) {
			$k = isset( $c[ $j['status'] ] ) ? $j['status'] : 'todo';
			$c[ $k ]++;
		}
		$out[] = array( 'id' => $id, 'title' => get_the_title( $id ), 'type' => get_post_type( $id ), 'c' => $c, 'when' => human_time_diff( (int) get_post_meta( $id, '_csa_jobs_time', true ) ) );
	}
	wp_send_json_success( $out );
}

add_action( 'wp_ajax_csa_jobs_load', 'csa_ajax_jobs_load' );
function csa_ajax_jobs_load() {
	csa_guard( 'chat' );
	$id = (int) ( $_POST['id'] ?? 0 );
	if ( ! csa_valid_post( $id ) ) {
		wp_send_json_error( csa_t( 'Page not found.' ) );
	}
	$sc = csa_seo_score( $id );
	wp_send_json_success( array( 'title' => get_the_title( $id ), 'jobs' => csa_jobs_get( $id ), 'own' => is_wp_error( $sc ) ? 0 : $sc['score'], 'first' => (int) get_post_meta( $id, '_csa_score_first', true ), 'source' => is_wp_error( $sc ) ? '' : $sc['source'], 'plugin' => csa_plugin_score( $id ) ) );
}

add_action( 'wp_ajax_csa_jobs_site', 'csa_ajax_jobs_site' );
function csa_ajax_jobs_site() {
	csa_guard( 'chat' );
	$ids = get_posts( array( 'post_type' => csa_seo_types(), 'post_status' => csa_seo_statuses(), 'numberposts' => 150, 'fields' => 'ids' ) );
	$out = array();
	foreach ( $ids as $id ) {
		$f = csa_dsg_audit( $id );
		if ( ! $f ) {
			continue;
		}
		$hi = 0;
		foreach ( $f as $x ) {
			if ( 'high' === $x['severity'] ) {
				$hi++;
			}
		}
		$out[] = array( 'id' => $id, 'title' => get_the_title( $id ), 'high' => $hi, 'total' => count( $f ), 'first' => $f[0]['issue'] );
	}
	usort( $out, function ( $a, $b ) { return array( $b['high'], $b['total'] ) <=> array( $a['high'], $a['total'] ); } );
	wp_send_json_success( array_slice( $out, 0, 40 ) );
}

/* ------------------------------------------------------------------ */
/* Screen                                                               */
/* ------------------------------------------------------------------ */

function csa_screen_jobs() {
	csa_header( csa_t( 'SEO Agent' ) );
	?>
	<div class="csa-card"><h2><?php echo esc_html( csa_t( 'Saved jobs: which page needs what' ) ); ?></h2><p class="csa-muted"><?php echo esc_html( csa_t( 'Every scanned page keeps its job list here, with what is done and what is left.' ) ); ?></p><div id="csa-jb-over"></div></div>
	<div class="csa-card" id="csa-ap">
		<h2>🚀 <?php echo esc_html( csa_t( 'Autopilot: do everything with one click' ) ); ?></h2>
		<p class="csa-muted"><?php echo esc_html( csa_t( 'For each page: scan, choose the focus keyword, then run every SEO job in order (title and meta, headings, keyword text, photos from your Media Library, alt text, schema). It runs on the server, so you can close this page and come back later (it needs normal site visits or a server cron to keep WP-Cron moving). At the end you get one report with what changed on each page, the score before and after, and what only you can do. Every change can be undone on the page\'s own list.' ) ); ?></p>
		<p>
			<label><?php echo esc_html( csa_t( 'Which pages' ) ); ?>:
			<select id="csa-ap-scope"><option value="one"><?php echo esc_html( csa_t( 'Only the page chosen below' ) ); ?></option><option value="page"><?php echo esc_html( csa_t( 'All pages (newest 60)' ) ); ?></option><option value="post"><?php echo esc_html( csa_t( 'All posts (newest 60)' ) ); ?></option><option value="sb_post"><?php echo esc_html( csa_t( 'All blog posts (Blog Write .ai)' ) ); ?></option></select></label>
			<label><input type="checkbox" id="csa-ap-kw" checked> <?php echo esc_html( csa_t( 'Choose the keyword for me (the AI\'s first suggestion; I will review it in the report)' ) ); ?></label>
			<label><input type="checkbox" id="csa-ap-skip" checked> <?php echo esc_html( csa_t( 'Skip pages that already score 90 or more' ) ); ?></label>
		</p>
		<p><button type="button" class="button button-primary button-hero" id="csa-ap-go">🚀 <?php echo esc_html( csa_t( 'Start autopilot' ) ); ?></button> <button type="button" class="button" id="csa-ap-stop" hidden>⏹ <?php echo esc_html( csa_t( 'Stop' ) ); ?></button> <span id="csa-ap-prog" class="csa-muted"></span></p>
		<div id="csa-ap-report"></div>
	</div>
	<div class="csa-card" id="csa-jobs-app" data-chat="<?php echo esc_attr( admin_url( 'admin.php?page=csa-chat' ) ); ?>">
		<p class="csa-muted"><?php echo esc_html( csa_t( '1) Pick a page or post and press Scan (free, no AI). 2) You get numbered jobs in the right order; the first is the focus keyword (the AI suggests, you approve). 3) Press Run all in order, which is your approval for the SEO changes, or run single jobs. Each finished job shows what changed in its title, and has an Undo button. Design problems are only listed, for the design agent.' ) ); ?></p>
		<div class="csa-row">
			<input type="search" class="regular-text" id="csa-jb-q" placeholder="<?php echo esc_attr( csa_t( 'Search pages and posts…' ) ); ?>">
			<select id="csa-jb-page" style="min-width:320px"></select>
			<button type="button" class="button button-primary" id="csa-jb-scan">🔍 <?php echo esc_html( csa_t( 'Scan' ) ); ?></button>
		</div>
		<div id="csa-jb-head"></div>
		<div id="csa-jb-list"></div>
		<div id="csa-jb-run" hidden>
			<p><label><input type="checkbox" id="csa-jb-all"> <?php echo esc_html( csa_t( 'Select all that I can run' ) ); ?></label>
			<input type="text" id="csa-jb-nums" placeholder="<?php echo esc_attr( csa_t( 'or type numbers: 1,2,5 or 1-4' ) ); ?>" style="width:220px">
			<button type="button" class="button button-primary" id="csa-jb-go">▶ <?php echo esc_html( csa_t( 'Run' ) ); ?></button>
			<button type="button" class="button" id="csa-jb-next">⏭ <?php echo esc_html( csa_t( 'Next job' ) ); ?></button>
			<button type="button" class="button" id="csa-jb-order">⏩ <?php echo esc_html( csa_t( 'Run all in order' ) ); ?></button>
			<button type="button" class="button" id="csa-jb-html" title="<?php echo esc_attr( csa_t( 'Keeps all text, headings, lists, images and links. Removes the Elementor layout. Can be undone in Activity.' ) ); ?>">🧱 <?php echo esc_html( csa_t( 'Convert this page to clean HTML' ) ); ?></button>
			<span id="csa-jb-prog" class="csa-muted"></span></p>
		</div>
	</div>
	<div class="csa-card">
		<h2><?php echo esc_html( csa_t( 'Anything else for this page' ) ); ?></h2>
		<p class="csa-muted"><?php echo esc_html( csa_t( 'Change some information, rewrite a part, or reduce the gaps to a standard mobile view: write it here and the chat agent starts with this page already chosen.' ) ); ?></p>
		<textarea id="csa-jb-ask" rows="2" class="widefat" placeholder="<?php echo esc_attr( csa_t( 'For example: change the admission phone number to … / reduce the gaps on mobile to a standard size' ) ); ?>"></textarea>
		<p><button type="button" class="button" id="csa-jb-chat">💬 <?php echo esc_html( csa_t( 'Send to the chat agent' ) ); ?></button></p>
	</div>
	<div class="csa-card">
		<h2><?php echo esc_html( csa_t( 'Find gaps in the whole site' ) ); ?></h2>
		<p class="csa-muted"><?php echo esc_html( csa_t( 'Checks every page for mobile and desktop layout problems (huge padding, fixed widths, tiny or huge fonts, no phone rules). Free, no AI. It reads the page settings, it does not look at a screenshot.' ) ); ?></p>
		<p><button type="button" class="button" id="csa-jb-site">🔎 <?php echo esc_html( csa_t( 'Check all pages' ) ); ?></button></p>
		<div id="csa-jb-sitelist"></div>
	</div>
	<?php
	csa_footer();
}


/* ------------------------------------------------------------------ */
/* The saved checklist is the agent's master plan                       */
/* ------------------------------------------------------------------ */

/** Compact checklist text for the chat agent's memory. */
function csa_jobs_text( $id ) {
	$jobs = csa_jobs_get( $id );
	if ( ! $jobs ) {
		return '';
	}
	$l = array();
	foreach ( array_slice( $jobs, 0, 14 ) as $j ) {
		$st  = 'done' === $j['status'] ? 'DONE' : ( 'skip' === $j['status'] ? 'SKIPPED' : 'OPEN' );
		$l[] = $j['n'] . '. [' . $st . '] ' . $j['title'] . ( 'OPEN' === $st && ! empty( $j['detail'] ) ? ' — ' . mb_substr( $j['detail'], 0, 120 ) : '' );
	}
	return 'Saved job checklist for #' . $id . ' "' . get_the_title( $id ) . '" (the owner\'s master plan, in the right order; do OPEN jobs in this order, never reorder, say which job number you are doing, call mark_job when one is finished):' . "\n" . implode( "\n", $l );
}

function csa_jobs_tool() {
	return array(
		array(
			'name'         => 'mark_job',
			'description'  => 'Mark a job of the saved checklist of a page as done or skipped after you finished it (use the job number n from the checklist). Does not change the page.',
			'input_schema' => csa_schema( array( 'id' => array( 'type' => 'integer' ), 'n' => array( 'type' => 'integer' ), 'status' => array( 'type' => 'string', 'enum' => array( 'done', 'skip', 'todo' ) ), 'note' => array( 'type' => 'string' ) ), array( 'id', 'n', 'status' ) ),
		),
	);
}

function csa_jobs_mark( $in ) {
	$id   = (int) ( $in['id'] ?? 0 );
	$n    = (int) ( $in['n'] ?? 0 );
	$st   = in_array( $in['status'] ?? '', array( 'done', 'skip', 'todo' ), true ) ? $in['status'] : 'done';
	$jobs = csa_jobs_get( $id );
	foreach ( $jobs as $i => $j ) {
		if ( (int) $j['n'] === $n ) {
			$jobs[ $i ]['status'] = $st;
			if ( ! empty( $in['note'] ) ) {
				$jobs[ $i ]['result'] = sanitize_text_field( $in['note'] );
			}
			update_post_meta( $id, '_csa_jobs', wp_slash( $jobs ) );
			return array( 'ok' => true );
		}
	}
	return array( 'error' => 'No such job on this page.' );
}
