<?php
/**
 * Whole-site memory: a free (no AI) index of every public page/post with its text and key facts,
 * plus a consistency check that shows where the site contradicts itself
 * (phone numbers, e-mails, founding year, counts, duplicate SEO titles/descriptions).
 *
 * Stored as a private JSON file, so it never slows the front end. Rebuilt by the "Scan whole site" button,
 * refreshed per page on save, and given to the agent as a compact site map + the search_site tool.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CSA_SI_TEXT_MAX = 12000;

function csa_si_file() {
	return csa_private_dir() . '/site-index.json';
}

function csa_si_load() {
	$f = csa_si_file();
	if ( ! file_exists( $f ) ) {
		return array( 'built' => 0, 'pages' => array() );
	}
	$d = json_decode( (string) file_get_contents( $f ), true );
	return is_array( $d ) && isset( $d['pages'] ) ? $d : array( 'built' => 0, 'pages' => array() );
}

function csa_si_save( $d ) {
	file_put_contents( csa_si_file(), wp_json_encode( $d, CSA_JSON ), LOCK_EX );
}

function csa_si_types() {
	$t = get_post_types( array( 'public' => true ), 'names' );
	unset( $t['attachment'] );
	return array_values( $t );
}

function csa_si_ids() {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => csa_si_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 600,
				'orderby'        => 'type title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		)
	);
}

/** Bangla digits to English, so "১২" and "12" are the same number. */
function csa_si_digits( $s ) {
	return strtr( (string) $s, array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' ) );
}

/** Collect the visible text of an Elementor document. */
function csa_si_elementor_text( $id ) {
	$raw = get_post_meta( $id, '_elementor_data', true );
	$els = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
	if ( ! is_array( $els ) ) {
		return '';
	}
	$out  = array();
	$skip = '/(color|_id|_url|url|class|size|width|height|align|image|icon|font|margin|padding|border|shadow|animation|effect|position|style|type|html_tag|layout|link|id$)/i';
	$walk = function ( $node ) use ( &$walk, &$out, $skip ) {
		foreach ( (array) $node as $k => $v ) {
			if ( is_array( $v ) ) {
				$walk( $v );
			} elseif ( is_string( $v ) && '' !== trim( $v ) && ! is_numeric( $v ) ) {
				if ( is_string( $k ) && preg_match( $skip, $k ) && ! preg_match( '/(html|editor|content|description|title|text|heading|label|caption)/i', $k ) ) {
					continue;
				}
				if ( is_string( $k ) && ! preg_match( '/(html|editor|content|description|title|text|heading|label|caption|name|sub|number|prefix|suffix|tab|item|answer|question)/i', $k ) ) {
					continue;
				}
				$out[] = $v;
			}
		}
	};
	$walk( $els );
	return implode( "\n", $out );
}

/** Plain text of any page/post: Elementor widgets or normal content. Hrefs of tel:/mailto: links are kept. */
function csa_si_text( $post ) {
	$html = '';
	if ( function_exists( 'csa_is_elementor' ) && csa_is_elementor( $post->ID ) ) {
		$html = csa_si_elementor_text( $post->ID );
	}
	if ( '' === trim( $html ) ) {
		$html = (string) $post->post_content;
	}
	$extra = '';
	if ( preg_match_all( '/(?:tel|mailto):([^"\'\s>]+)/i', $html, $m ) ) {
		$extra = ' ' . implode( ' ', array_map( 'rawurldecode', $m[1] ) );
	}
	$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html );
	$html = strip_shortcodes( $html );
	$t    = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
	$t    = trim( preg_replace( '/\s+/u', ' ', $t . $extra ) );
	return $t;
}

/** Key facts found in a page's text. */
function csa_si_facts( $text ) {
	$n     = csa_si_digits( $text );
	$facts = array( 'ph' => array(), 'em' => array(), 'est' => array(), 'cnt' => array() );
	if ( preg_match_all( '/(?<!\d)(?:\+?88)?[\s-]?0?1[3-9](?:[\s-]?\d){8}(?!\d)/', $n, $m ) ) {
		foreach ( $m[0] as $p ) {
			$d = preg_replace( '/\D+/', '', $p );
			if ( 0 === strpos( $d, '88' ) && strlen( $d ) > 11 ) {
				$d = substr( $d, 2 );
			}
			if ( 10 === strlen( $d ) ) {
				$d = '0' . $d;
			}
			if ( 11 === strlen( $d ) && 0 === strpos( $d, '01' ) ) {
				$facts['ph'][ $d ] = 1;
			}
		}
	}
	if ( preg_match_all( '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $text, $m ) ) {
		foreach ( $m[0] as $e ) {
			$e = strtolower( $e );
			if ( ! preg_match( '/\.(png|jpe?g|gif|webp|svg)$/', $e ) ) {
				$facts['em'][ $e ] = 1;
			}
		}
	}
	if ( preg_match_all( '/(established|estd\.?|since|founded|স্থাপিত|প্রতিষ্ঠিত|প্রতিষ্ঠা)\D{0,14}((?:19|20)\d{2})/iu', $n, $m ) ) {
		foreach ( $m[2] as $y ) {
			$facts['est'][ $y ] = 1;
		}
	}
	$units = array(
		'শাখা' => 'branches', 'branch' => 'branches', 'branches' => 'branches', 'campus' => 'campuses', 'campuses' => 'campuses', 'ক্যাম্পাস' => 'campuses',
		'শিক্ষার্থী' => 'students', 'students' => 'students', 'ছাত্র' => 'students', 'শিক্ষক' => 'teachers', 'teachers' => 'teachers', 'faculty' => 'teachers',
	);
	if ( preg_match_all( '/(\d[\d,]*)\s?\+?\s?(শাখা|branches|branch|campuses|campus|ক্যাম্পাস|শিক্ষার্থী|students|ছাত্র|শিক্ষক|teachers|faculty)/iu', $n, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $x ) {
			$v = (int) str_replace( ',', '', $x[1] );
			$l = $units[ mb_strtolower( $x[2] ) ] ?? '';
			if ( $l && $v > 0 && $v < 1000000 ) {
				$facts['cnt'][ $l . '|' . $v ] = 1;
			}
		}
	}
	foreach ( $facts as $k => $v ) {
		$facts[ $k ] = array_keys( $v );
	}
	return $facts;
}

function csa_si_seo( $id ) {
	if ( function_exists( 'csa_seo_plugin' ) && 'All in One SEO' === csa_seo_plugin() ) {
		$a = csa_aio_get( $id );
		return array( 'st' => trim( $a['title'] ), 'sd' => trim( $a['description'] ), 'sk' => trim( $a['keyword'] ) );
	}
	$k = function_exists( 'csa_seo_keys' ) ? csa_seo_keys() : array();
	return array(
		'st' => $k ? trim( (string) get_post_meta( $id, $k['title'], true ) ) : '',
		'sd' => $k ? trim( (string) get_post_meta( $id, $k['description'], true ) ) : '',
		'sk' => $k ? trim( (string) get_post_meta( $id, $k['keyword'], true ) ) : '',
	);
}

/** One page's index entry. */
function csa_si_entry( $post ) {
	$text  = csa_si_text( $post );
	$facts = csa_si_facts( $text . ' ' . $post->post_title );
	return array_merge(
		array(
			't'     => get_the_title( $post ),
			'u'     => get_permalink( $post ),
			'type'  => $post->post_type,
			'mod'   => $post->post_modified,
			'words' => max( 0, preg_match_all( '/\S+/u', $text ) ),
			'text'  => mb_substr( $text, 0, CSA_SI_TEXT_MAX ),
			'f'     => $facts,
		),
		csa_si_seo( $post->ID )
	);
}

function csa_si_refresh_one( $id ) {
	$d = csa_si_load();
	if ( ! $d['built'] ) {
		return; // No scan yet: nothing to keep fresh.
	}
	$post = get_post( $id );
	if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, csa_si_types(), true ) ) {
		unset( $d['pages'][ (int) $id ] );
	} else {
		$d['pages'][ (int) $id ] = csa_si_entry( $post );
	}
	csa_si_save( $d );
}

add_action(
	'save_post',
	function ( $id, $post ) {
		if ( wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		csa_si_refresh_one( $id );
	},
	99,
	2
);

add_action(
	'deleted_post',
	function ( $id ) {
		$d = csa_si_load();
		if ( isset( $d['pages'][ (int) $id ] ) ) {
			unset( $d['pages'][ (int) $id ] );
			csa_si_save( $d );
		}
	}
);

/* ------------------------------------------------------------------ */
/* Scan (in small batches, so hosting time limits never interrupt it)  */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_csa_si_scan', 'csa_ajax_si_scan' );
function csa_ajax_si_scan() {
	csa_guard( 'seo' );
	$do = sanitize_key( $_POST['do'] ?? '' );
	if ( 'start' === $do ) {
		$ids = csa_si_ids();
		update_option( 'csa_si_queue', $ids, false );
		csa_si_save( array( 'built' => 0, 'pages' => array(), 'partial' => array() ) );
		wp_send_json_success( array( 'total' => count( $ids ), 'left' => count( $ids ) ) );
	}
	$queue = (array) get_option( 'csa_si_queue', array() );
	$total = (int) ( $_POST['total'] ?? count( $queue ) );
	$batch = array_splice( $queue, 0, 10 );
	$d     = file_exists( csa_si_file() ) ? json_decode( (string) file_get_contents( csa_si_file() ), true ) : array();
	$d     = is_array( $d ) ? $d : array();
	$d     = array_merge( array( 'built' => 0, 'pages' => array(), 'partial' => array() ), $d );
	foreach ( $batch as $id ) {
		$post = get_post( $id );
		if ( $post ) {
			$d['pages'][ (int) $id ] = csa_si_entry( $post );
		}
	}
	if ( ! $queue ) {
		$d['built'] = time();
		unset( $d['partial'] );
		delete_option( 'csa_si_queue' );
		csa_log( sprintf( 'Site scan finished: %d pages indexed', count( $d['pages'] ) ) );
	} else {
		update_option( 'csa_si_queue', $queue, false );
	}
	csa_si_save( $d );
	wp_send_json_success( array( 'total' => $total, 'left' => count( $queue ), 'done' => ! $queue ) );
}

/* ------------------------------------------------------------------ */
/* Agent: site map + search                                            */
/* ------------------------------------------------------------------ */

/** Compact list of every page for the system prompt. */
function csa_si_map_text() {
	$d = csa_si_load();
	if ( ! $d['built'] || ! $d['pages'] ) {
		return '';
	}
	$lines = array();
	foreach ( $d['pages'] as $id => $p ) {
		$path    = wp_parse_url( $p['u'], PHP_URL_PATH );
		$lines[] = '#' . $id . ' ' . mb_substr( $p['t'], 0, 60 ) . ' [' . $p['type'] . ' ' . $path . ']';
	}
	$t = implode( "\n", $lines );
	if ( mb_strlen( $t ) > 5000 ) {
		$t = mb_substr( $t, 0, 5000 ) . "\n…(more pages: use search_site)";
	}
	return "\n\n## Site map (every public page, from the last full scan " . wp_date( 'j M Y', $d['built'] ) . '; ' . count( $d['pages'] ) . " pages). You know the whole site from this list: use search_site to find where a fact or phrase is, and get_page for details.\n" . $t;
}

function csa_si_tool() {
	return array(
		array(
			'name'         => 'search_site',
			'description'  => 'Search the text of ALL pages and posts (whole-site index, free and fast) for where a phone number, fee, name, date or phrase appears. Query = words as they really appear on the site, in Bangla or English (never Urdu/Hindi). Returns page ids, titles and snippets.',
			'input_schema' => csa_schema( array( 'query' => array( 'type' => 'string' ) ), array( 'query' ) ),
		),
		array(
			'name'         => 'check_consistency',
			'description'  => 'List where the site contradicts itself: near-identical phone numbers or e-mails (likely typos), different founding years / branch or student counts on different pages, duplicate SEO titles or descriptions. Free. Use it when asked about mistakes, mismatches or data problems.',
			'input_schema' => csa_schema( array() ),
		),
	);
}

function csa_si_run_tool( $name, $in ) {
	$d = csa_si_load();
	if ( ! $d['built'] ) {
		return array( 'error' => 'The whole-site scan has not been run yet. Tell the user to open Site Agent > Site Memory and press "Scan whole site" (free, no AI).' );
	}
	if ( 'check_consistency' === $name ) {
		$f = csa_si_findings();
		return array( 'pages_scanned' => count( $d['pages'] ), 'findings' => array_slice( $f, 0, 40 ), 'note' => 'Each finding lists the pages (id) involved. Verify with get_page / search_site, ask the user which value is right, and only then plan a fix.' );
	}
	$q = trim( (string) ( $in['query'] ?? '' ) );
	if ( mb_strlen( $q ) < 2 ) {
		return array( 'error' => 'Query too short.' );
	}
	$qd  = csa_si_digits( $q );
	$out = array();
	foreach ( $d['pages'] as $id => $p ) {
		$hay = $p['t'] . ' ' . $p['text'];
		$pos = mb_stripos( $hay, $q );
		if ( false === $pos ) {
			$pos = mb_stripos( csa_si_digits( $hay ), $qd );
		}
		if ( false === $pos ) {
			continue;
		}
		$out[] = array( 'id' => (int) $id, 'title' => $p['t'], 'url' => $p['u'], 'snippet' => '…' . mb_substr( $hay, max( 0, $pos - 70 ), 190 ) . '…' );
		if ( count( $out ) >= 10 ) {
			break;
		}
	}
	return array( 'matches' => $out, 'count' => count( $out ), 'hint' => $out ? '' : 'No match. Try different Bangla or English words, a phone-number fragment, or read the page itself.' );
}

/* ------------------------------------------------------------------ */
/* Consistency findings                                                */
/* ------------------------------------------------------------------ */

function csa_si_hamming( $a, $b ) {
	if ( strlen( $a ) !== strlen( $b ) ) {
		return 99;
	}
	$n = 0;
	for ( $i = 0, $l = strlen( $a ); $i < $l; $i++ ) {
		if ( $a[ $i ] !== $b[ $i ] ) {
			$n++;
		}
	}
	return $n;
}

/** The owner's own verified numbers / e-mails from Memory (facts that are switched on). */
function csa_si_official() {
	$ph = array();
	$em = array();
	if ( function_exists( 'sb_memory_all' ) ) {
		foreach ( sb_memory_all() as $m ) {
			if ( empty( $m['on'] ) || 'fact' !== $m['type'] || 'auto' === ( $m['src'] ?? '' ) ) {
				continue;
			}
			$f = csa_si_facts( $m['text'] );
			foreach ( $f['ph'] as $x ) {
				$ph[ $x ] = $m['text'];
			}
			foreach ( $f['em'] as $x ) {
				$em[ $x ] = $m['text'];
			}
		}
	}
	return array( 'ph' => $ph, 'em' => $em );
}

/**
 * @return array[] each: severity (high|med|low), kind, title, detail, values[value => [page ids]]
 */
function csa_si_findings() {
	$d = csa_si_load();
	$by = array( 'ph' => array(), 'em' => array(), 'est' => array() );
	$cnt = array();
	foreach ( $d['pages'] as $id => $p ) {
		foreach ( array( 'ph', 'em', 'est' ) as $k ) {
			foreach ( (array) ( $p['f'][ $k ] ?? array() ) as $v ) {
				$by[ $k ][ $v ][] = (int) $id;
			}
		}
		foreach ( (array) ( $p['f']['cnt'] ?? array() ) as $c ) {
			list( $label, $v )     = explode( '|', $c );
			$cnt[ $label ][ $v ][] = (int) $id;
		}
	}
	$out      = array();
	$official = csa_si_official();

	// Phone numbers one digit apart (typos), and numbers that look like a mistyped official number.
	$phones = array_keys( $by['ph'] );
	$seen   = array();
	foreach ( $phones as $i => $a ) {
		foreach ( array_slice( $phones, $i + 1 ) as $b ) {
			if ( csa_si_hamming( $a, $b ) <= 1 ) {
				$out[] = array( 'severity' => 'high', 'kind' => 'phone', 'title' => 'Two phone numbers differ by one digit', 'detail' => 'One of them is probably a typo.', 'values' => array( $a => $by['ph'][ $a ], $b => $by['ph'][ $b ] ) );
				$seen[ $a ] = $seen[ $b ] = 1;
			}
		}
	}
	if ( $official['ph'] ) {
		foreach ( $by['ph'] as $num => $pids ) {
			if ( isset( $official['ph'][ $num ] ) || isset( $seen[ $num ] ) ) {
				continue;
			}
			foreach ( array_keys( $official['ph'] ) as $o ) {
				if ( csa_si_hamming( $num, $o ) <= 2 ) {
					$out[] = array( 'severity' => 'high', 'kind' => 'phone', 'title' => 'A phone number is very close to your official number', 'detail' => 'Official (from Memory): ' . $o . '. Found: ' . $num . '.', 'values' => array( $num => $pids ) );
					break;
				}
			}
		}
	}
	// E-mails that look alike.
	$mails = array_keys( $by['em'] );
	foreach ( $mails as $i => $a ) {
		foreach ( array_slice( $mails, $i + 1 ) as $b ) {
			if ( abs( strlen( $a ) - strlen( $b ) ) <= 2 && levenshtein( $a, $b ) <= 2 ) {
				$out[] = array( 'severity' => 'med', 'kind' => 'email', 'title' => 'Two e-mail addresses look almost the same', 'detail' => 'Check that both are real.', 'values' => array( $a => $by['em'][ $a ], $b => $by['em'][ $b ] ) );
			}
		}
	}
	// Founding year.
	if ( count( $by['est'] ) > 1 ) {
		$out[] = array( 'severity' => 'high', 'kind' => 'year', 'title' => 'Different founding / establishment years', 'detail' => 'Different years appear near words like "established" or "since". Check whether each page means the founding year.', 'values' => $by['est'] );
	}
	// Counts of branches, students, teachers…
	$names = array( 'branches' => 'branches', 'campuses' => 'campuses', 'students' => 'students', 'teachers' => 'teachers' );
	foreach ( $cnt as $label => $vals ) {
		if ( count( $vals ) > 1 ) {
			$out[] = array( 'severity' => 'med', 'kind' => 'count', 'title' => 'Different numbers of ' . ( $names[ $label ] ?? $label ), 'detail' => 'May be an old figure on some pages.', 'values' => $vals );
		}
	}
	// Duplicate SEO titles / descriptions, missing descriptions.
	$dup = array( 'st' => array(), 'sd' => array() );
	$no  = array();
	foreach ( $d['pages'] as $id => $p ) {
		foreach ( array( 'st', 'sd' ) as $k ) {
			$v = mb_strtolower( trim( (string) ( $p[ $k ] ?? '' ) ) );
			if ( '' !== $v ) {
				$dup[ $k ][ $v ][] = (int) $id;
			}
		}
		if ( '' === trim( (string) ( $p['sd'] ?? '' ) ) ) {
			$no[] = (int) $id;
		}
	}
	foreach ( array( 'st' => 'SEO title', 'sd' => 'SEO description' ) as $k => $label ) {
		foreach ( $dup[ $k ] as $v => $pids ) {
			if ( count( $pids ) > 1 ) {
				$out[] = array( 'severity' => 'med', 'kind' => 'dup', 'title' => 'Same ' . $label . ' on ' . count( $pids ) . ' pages', 'detail' => mb_substr( $v, 0, 120 ), 'values' => array( mb_substr( $v, 0, 60 ) => $pids ) );
			}
		}
	}
	if ( $no ) {
		$out[] = array( 'severity' => 'low', 'kind' => 'meta', 'title' => count( $no ) . ' pages have no SEO description', 'detail' => 'Search engines will pick random text.', 'values' => array( '—' => $no ) );
	}
	$rank = array( 'high' => 0, 'med' => 1, 'low' => 2 );
	usort( $out, function ( $a, $b ) use ( $rank ) { return $rank[ $a['severity'] ] <=> $rank[ $b['severity'] ]; } );
	return $out;
}

/** Contact details actually used on the site, for the overview table. */
function csa_si_contacts() {
	$d  = csa_si_load();
	$ph = array();
	$em = array();
	foreach ( $d['pages'] as $id => $p ) {
		foreach ( (array) ( $p['f']['ph'] ?? array() ) as $v ) {
			$ph[ $v ][] = (int) $id;
		}
		foreach ( (array) ( $p['f']['em'] ?? array() ) as $v ) {
			$em[ $v ][] = (int) $id;
		}
	}
	return array( 'ph' => $ph, 'em' => $em );
}

/* ------------------------------------------------------------------ */
/* Screens                                                             */
/* ------------------------------------------------------------------ */

function csa_si_page_chips( $ids ) {
	$h = '<ul class="csa-pages-list">';
	foreach ( array_slice( (array) $ids, 0, 12 ) as $id ) {
		$h .= '<li><a href="' . esc_url( get_edit_post_link( $id ) ) . '">#' . (int) $id . ' ' . esc_html( mb_substr( get_the_title( $id ), 0, 28 ) ) . '</a></li>';
	}
	if ( count( (array) $ids ) > 12 ) {
		$h .= '<li>+' . ( count( $ids ) - 12 ) . '</li>';
	}
	return $h . '</ul>';
}

/** Card on the Site Memory page: scan the whole site. */
function csa_si_card() {
	$d     = csa_si_load();
	$stale = 0;
	foreach ( $d['pages'] as $id => $p ) {
		$post = get_post( $id );
		if ( $post && strcmp( $post->post_modified, $p['mod'] ) > 0 ) {
			$stale++;
		}
	}
	?>
	<div class="csa-card" id="csa-si-card">
		<h2>🗺 <?php echo esc_html( csa_t( 'Whole-site memory' ) ); ?></h2>
		<p class="csa-muted"><?php echo esc_html( csa_t( 'A free scan (no AI cost) reads every published page and post: text, phone numbers, e-mails, SEO title and description. The agent then knows the whole site, can search it, and the Data check shows where pages contradict each other.' ) ); ?></p>
		<p>
			<?php if ( $d['built'] ) : ?>
				<strong><?php echo (int) count( $d['pages'] ); ?></strong> <?php echo esc_html( csa_t( 'pages remembered' ) ); ?> · <?php echo esc_html( csa_t( 'last scan' ) . ' ' . wp_date( 'j M Y, g:i a', $d['built'] ) ); ?>
				<?php if ( $stale ) : ?> · <span class="csa-val-bad"><?php echo esc_html( sprintf( csa_t( '%d changed since the scan' ), $stale ) ); ?></span><?php endif; ?>
			<?php else : ?>
				<span class="csa-val-bad"><?php echo esc_html( csa_t( 'Not scanned yet.' ) ); ?></span>
			<?php endif; ?>
		</p>
		<p><button type="button" class="button button-primary" id="csa-si-go"><?php echo esc_html( $d['built'] ? csa_t( 'Scan again' ) : csa_t( 'Scan whole site' ) ); ?></button>
		<?php if ( $d['built'] ) : ?> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=csa-seo&tab=consistency' ) ); ?>"><?php echo esc_html( csa_t( 'Open Data check' ) ); ?></a><?php endif; ?>
		<span id="csa-si-msg" class="csa-muted"></span></p>
		<div class="csa-scan-bar" id="csa-si-bar" hidden><i></i></div>
		<p class="description"><?php echo esc_html( csa_t( 'Your rules and verified facts live in Blog Write .ai → Memory. The agent reads them too, and facts it learns appear there as well.' ) ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=sb-memory' ) ); ?>"><?php echo esc_html( csa_t( 'Open Memory' ) ); ?></a></p>
	</div>
	<?php csa_si_script(); ?>
	<?php
}

function csa_si_script() {
	?>
	<script>
	window.addEventListener('load',function(){
		var go=document.getElementById('csa-si-go'); if(!go||typeof CSA==='undefined')return;
		var msg=document.getElementById('csa-si-msg'),bar=document.getElementById('csa-si-bar');
		function call(d){var b=new URLSearchParams(Object.assign({action:'csa_si_scan',nonce:CSA.nonce},d));
			return fetch(CSA.ajax,{method:'POST',body:b,credentials:'same-origin'}).then(function(r){return r.json();});}
		go.addEventListener('click',async function(){
			go.disabled=true; bar.hidden=false; msg.textContent='…';
			try{
				var r=await call({'do':'start'}); if(!r.success)throw new Error(r.data||'error');
				var total=r.data.total,left=total;
				while(left>0){
					r=await call({'do':'batch',total:total}); if(!r.success)throw new Error(r.data||'error');
					left=r.data.left; bar.firstChild.style.width=Math.round((total-left)/Math.max(1,total)*100)+'%';
					msg.textContent=(total-left)+' / '+total;
				}
				msg.textContent='✓ '+total; setTimeout(function(){location.reload();},700);
			}catch(e){msg.textContent=String(e.message||e);go.disabled=false;}
		});
	});
	</script>
	<?php
}

/** SEO > Data check */
function csa_seo_tab_consistency() {
	$d = csa_si_load();
	if ( ! $d['built'] ) {
		?>
		<div class="csa-card"><h2><?php echo esc_html( csa_t( 'Data check' ) ); ?></h2>
		<p><?php echo esc_html( csa_t( 'First scan the whole site (free, no AI). It takes about a minute.' ) ); ?></p>
		<p><button type="button" class="button button-primary" id="csa-si-go"><?php echo esc_html( csa_t( 'Scan whole site' ) ); ?></button> <span id="csa-si-msg" class="csa-muted"></span></p>
		<div class="csa-scan-bar" id="csa-si-bar" hidden><i></i></div></div>
		<?php
		csa_si_script();
		return;
	}
	$f   = csa_si_findings();
	$con = csa_si_contacts();
	$sev = array( 'high' => array( '🔴', csa_t( 'Likely mistake' ) ), 'med' => array( '🟠', csa_t( 'Please check' ) ), 'low' => array( '🟡', csa_t( 'Minor' ) ) );
	?>
	<div class="csa-card">
		<h2><?php echo esc_html( csa_t( 'Where the site contradicts itself' ) ); ?></h2>
		<p class="csa-muted"><?php echo esc_html( sprintf( csa_t( 'Checked %1$d pages (scan: %2$s). This check is free and does not use AI. It finds likely mistakes; you decide which value is right.' ), count( $d['pages'] ), wp_date( 'j M Y, g:i a', $d['built'] ) ) ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=csa-memory' ) ); ?>"><?php echo esc_html( csa_t( 'Scan again' ) ); ?></a></p>
		<?php if ( ! $f ) : ?>
			<p class="csa-val-ok">✓ <?php echo esc_html( csa_t( 'No contradictions found.' ) ); ?></p>
		<?php else : ?>
		<table class="widefat striped csa-fact-table"><thead><tr><th style="width:120px"><?php echo esc_html( csa_t( 'Level' ) ); ?></th><th><?php echo esc_html( csa_t( 'Problem' ) ); ?></th><th><?php echo esc_html( csa_t( 'Found on' ) ); ?></th><th style="width:120px"></th></tr></thead><tbody>
		<?php foreach ( $f as $x ) : ?>
			<tr>
				<td><?php echo esc_html( $sev[ $x['severity'] ][0] . ' ' . $sev[ $x['severity'] ][1] ); ?></td>
				<td><strong><?php echo esc_html( $x['title'] ); ?></strong><br><span class="csa-muted"><?php echo esc_html( $x['detail'] ); ?></span></td>
				<td>
				<?php foreach ( $x['values'] as $val => $ids ) : ?>
					<div><code><?php echo esc_html( $val ); ?></code><?php echo csa_si_page_chips( $ids ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<?php endforeach; ?>
				</td>
				<td><?php
				$all = array();
				foreach ( $x['values'] as $val => $ids ) {
					$all[] = $val . ' → pages ' . implode( ', ', array_map( function ( $i ) { return '#' . $i . ' ' . get_the_title( $i ); }, array_slice( $ids, 0, 6 ) ) );
				}
				echo csa_agent_link( 'Data problem: ' . $x['title'] . '. ' . $x['detail'] . ' Found: ' . implode( ' | ', $all ) . '. Check these pages with get_page / search_site, tell me which value is correct (ask me if you are unsure), then make a plan to fix them.', csa_t( 'Fix with agent' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				?></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php endif; ?>
	</div>
	<div class="csa-grid2">
		<div class="csa-card"><h2><?php echo esc_html( csa_t( 'Phone numbers on the site' ) ); ?></h2>
			<table class="widefat striped csa-fact-table"><tbody>
			<?php foreach ( $con['ph'] as $n => $ids ) : ?>
				<tr><td style="width:130px"><code><?php echo esc_html( $n ); ?></code></td><td><?php echo csa_si_page_chips( $ids ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			<?php endforeach; ?>
			<?php if ( ! $con['ph'] ) : ?><tr><td class="csa-muted"><?php echo esc_html( csa_t( 'None found.' ) ); ?></td></tr><?php endif; ?>
			</tbody></table>
		</div>
		<div class="csa-card"><h2><?php echo esc_html( csa_t( 'E-mail addresses on the site' ) ); ?></h2>
			<table class="widefat striped csa-fact-table"><tbody>
			<?php foreach ( $con['em'] as $n => $ids ) : ?>
				<tr><td style="width:200px"><code><?php echo esc_html( $n ); ?></code></td><td><?php echo csa_si_page_chips( $ids ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			<?php endforeach; ?>
			<?php if ( ! $con['em'] ) : ?><tr><td class="csa-muted"><?php echo esc_html( csa_t( 'None found.' ) ); ?></td></tr><?php endif; ?>
			</tbody></table>
		</div>
	</div>
	<?php
}
