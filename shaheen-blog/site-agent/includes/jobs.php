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

function csa_jobs_build( $id ) {
	$sc   = csa_seo_score( $id );
	if ( is_wp_error( $sc ) ) {
		return $sc;
	}
	$jobs = array();
	$add  = function ( $kind, $area, $title, $detail, $pts, $extra = array() ) use ( &$jobs ) {
		$jobs[] = array_merge( array( 'key' => substr( md5( $kind . $title ), 0, 10 ), 'kind' => $kind, 'area' => $area, 'title' => $title, 'detail' => $detail, 'pts' => $pts, 'status' => 'todo', 'result' => '' ), $extra );
	};
	$meta_pts = 0;
	$meta_why = array();
	foreach ( $sc['items'] as $i ) {
		if ( $i['ok'] ) {
			continue;
		}
		$gap = $i['max'] - $i['points'];
		$c   = $i['check'];
		if ( preg_match( '/^(Title 30|Focus keyword|Keyword in title|Meta description|Keyword in meta)/', $c ) ) {
			$meta_pts += $gap;
			$meta_why[] = $c;
		} elseif ( 0 === strpos( $c, 'Schema' ) ) {
			$add( 'schema', 'seo', 'Add schema (JSON-LD)', $i['fix'], $gap );
		} elseif ( 0 === strpos( $c, 'Every image has alt' ) ) {
			if ( 0 === (int) ( $sc['facts']['images_missing_alt'] ?? 0 ) ) {
				continue;
			}
			$add( 'alt', 'image', 'Write alt text for images that have none', $i['fix'], $gap );
		} elseif ( preg_match( '/^(Language|Canonical|Viewport)/', $c ) ) {
			$add( 'owner', 'seo', $c . ' is missing', $i['fix'], $gap );
		} else {
			$add( 'agent', 'text', $c . ' — fix needed', $i['fix'], $gap );
		}
	}
	if ( $meta_pts ) {
		array_unshift( $jobs, array( 'key' => substr( md5( 'seo_meta' ), 0, 10 ), 'kind' => 'seo_meta', 'area' => 'seo', 'title' => 'Write SEO title, meta description and focus keyword', 'detail' => implode( '; ', $meta_why ), 'pts' => $meta_pts, 'status' => 'todo', 'result' => '' ) );
	}
	foreach ( csa_dsg_audit( $id ) as $f ) {
		$fix = (string) $f['fix'];
		$css = ( false !== strpos( $fix, '{' ) && 0 !== strpos( $fix, 'Append' ) && 0 !== strpos( $fix, 'Use ' ) ) ? $fix : '';
		$add( $css ? 'css' : 'agent', 'design', ucfirst( $f['severity'] ) . ': ' . $f['what'], $f['issue'], 0, $css ? array( 'css' => $css ) : array( 'hint' => $fix ) );
	}
	if ( function_exists( 'csa_slots_missing' ) && csa_slots_missing( $id ) ) {
		$add( 'owner', 'image', 'Upload the photos this page needs', 'Open Site Agent > Images and upload them (they are renamed, compressed and given alt text automatically).', 0 );
	}
	// Order: content first (the later steps depend on it), then SEO text, images, schema, design, owner tasks.
	$rank = array( 'agent' => 1, 'seo_meta' => 2, 'alt' => 3, 'schema' => 4, 'css' => 5, 'owner' => 6 );
	foreach ( $jobs as $i => &$j0 ) {
		$j0['_o'] = $i;
	}
	unset( $j0 );
	usort( $jobs, function ( $a, $b ) use ( $rank ) {
		$ra = $rank[ $a['kind'] ] + ( 'design' === $a['area'] && 'agent' === $a['kind'] ? 3 : 0 );
		$rb = $rank[ $b['kind'] ] + ( 'design' === $b['area'] && 'agent' === $b['kind'] ? 3 : 0 );
		return array( $ra, $b['pts'], $a['_o'] ) <=> array( $rb, $a['pts'], $b['_o'] );
	} );
	// Keep the status of jobs that are already known.
	$old = array();
	foreach ( csa_jobs_get( $id ) as $o ) {
		$old[ $o['key'] ] = $o;
	}
	foreach ( $jobs as $i => &$j ) {
		if ( isset( $old[ $j['key'] ] ) && in_array( $old[ $j['key'] ]['status'], array( 'done', 'skip' ), true ) && in_array( $j['kind'], array( 'css', 'schema' ), true ) ) {
			$j['status'] = $old[ $j['key'] ]['status'];
			$j['result'] = $old[ $j['key'] ]['result'];
		}
		$j['n'] = $i + 1;
		unset( $j['_o'] );
	}
	unset( $j );
	update_post_meta( $id, '_csa_jobs', wp_slash( $jobs ) );
	update_post_meta( $id, '_csa_jobs_time', time() );
	return array( 'jobs' => $jobs, 'score' => $sc );
}

/* ------------------------------------------------------------------ */
/* One small AI call that returns JSON                                  */
/* ------------------------------------------------------------------ */

function csa_jobs_ai_json( $system, $user, $feature ) {
	$data = csa_claude( array( array( 'role' => 'user', 'content' => $user ) ), $system, array( 'max_tokens' => 900, 'task' => 'seo', 'feature' => $feature ) );
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
		. "\nCurrent SEO title: " . $cur['st'] . "\nCurrent meta description: " . $cur['sd'] . "\nCurrent focus keyword: " . $cur['sk'] . "\nText start: " . mb_substr( (string) ( $f['text_sample'] ?? '' ), 0, 900 );
}

/* ------------------------------------------------------------------ */
/* Run one job                                                          */
/* ------------------------------------------------------------------ */

function csa_jobs_do( $id, $j ) {
	$ctx = array( 'state' => 'approved', 'items' => array(), 'pages' => array(), 'mem' => csa_mem_default() );
	switch ( $j['kind'] ) {
		case 'css':
			$r = csa_run_tool( 'set_page_css', array( 'id' => $id, 'name' => 'job' . $j['key'], 'css' => $j['css'], 'scope' => 'page' ), $ctx );
			return isset( $r['error'] ) ? array( 'fail', $r['error'] ) : array( 'done', 'CSS added for this page (undo in Activity).' );

		case 'seo_meta':
			$lang = csa_opt( 'seo_lang' );
			$sys  = 'You are an SEO writer. ' . csa_site_context() . "\n\nWrite for ONE page. Return ONLY JSON: {\"title\":\"<=60 characters, focus keyword near the start\",\"description\":\"125-155 characters, includes the keyword, ends with a reason to click\",\"focus_keyword\":\"1-3 words people really search\"}. Language: " . $lang . '. Use only facts that appear in the page text; never invent fees, dates or numbers.';
			$r    = csa_jobs_ai_json( $sys, csa_jobs_page_brief( $id ), 'Jobs: SEO text' );
			if ( is_wp_error( $r ) ) {
				return array( 'fail', $r->get_error_message() );
			}
			$t = trim( (string) ( $r['title'] ?? '' ) );
			$d = trim( (string) ( $r['description'] ?? '' ) );
			$k = trim( (string) ( $r['focus_keyword'] ?? '' ) );
			if ( mb_strlen( $t ) > 60 ) {
				$t = trim( mb_substr( $t, 0, 59 ) );
			}
			if ( mb_strlen( $d ) > 158 ) {
				$d = trim( mb_substr( $d, 0, 155 ) ) . '…';
			}
			$o = csa_run_tool( 'update_seo_meta', array( 'id' => $id, 'title' => $t, 'description' => $d, 'focus_keyword' => $k ), $ctx );
			if ( isset( $o['error'] ) ) {
				return array( 'fail', $o['error'] );
			}
			$w = (array) ( $o['warnings'] ?? array() );
			return array( $w ? 'done' : 'done', 'Title: ' . $t . ' | Description: ' . $d . ' | Keyword: ' . $k . ( $w ? ' | Check: ' . implode( '; ', $w ) : '' ) );

		case 'schema':
			$sys = 'You write schema.org JSON-LD for a school website. ' . csa_site_context() . "\n\nReturn ONLY one valid JSON object with @context and @type (EducationalOrganization, Course or FAQPage, whichever fits this page; FAQPage only if the page text really has questions and answers). Use only facts from the page text and site memory; leave out anything unknown.";
			$r   = csa_jobs_ai_json( $sys, csa_jobs_page_brief( $id ), 'Jobs: schema' );
			if ( is_wp_error( $r ) ) {
				return array( 'fail', $r->get_error_message() );
			}
			$o = csa_run_tool( 'set_page_schema', array( 'id' => $id, 'jsonld' => wp_json_encode( $r, CSA_JSON ) ), $ctx );
			return isset( $o['error'] ) ? array( 'fail', $o['error'] ) : array( 'done', 'Schema type ' . ( $r['@type'] ?? '' ) . ' added.' );

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
			return $made ? array( 'done', implode( ' | ', $made ) . ' (alt is stored on the image; if a widget has its own empty alt, use the chat agent)' ) : array( 'fail', 'No image from the media library could be matched. Use the chat agent for this job.' );
	}
	return array( 'agent', '' );
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
	wp_send_json_success( array( 'title' => get_the_title( $id ), 'jobs' => $r['jobs'], 'own' => $r['score']['score'], 'source' => $r['score']['source'], 'plugin' => csa_plugin_score( $id ), 'chat' => add_query_arg( array( 'page' => 'csa-chat', 'job' => $id ), admin_url( 'admin.php' ) ) ) );
}

add_action( 'wp_ajax_csa_jobs_run', 'csa_ajax_jobs_run' );
function csa_ajax_jobs_run() {
	csa_guard( 'chat' );
	$id = (int) ( $_POST['id'] ?? 0 );
	$n  = (int) ( $_POST['n'] ?? 0 );
	$p  = csa_valid_post( $id );
	if ( ! $p ) {
		wp_send_json_error( csa_t( 'Page not found.' ) );
	}
	$g = csa_live_guard( $p );
	if ( $g ) {
		wp_send_json_error( $g['error'] ?? 'Live editing is off.' );
	}
	$jobs = csa_jobs_get( $id );
	foreach ( $jobs as $i => $j ) {
		if ( (int) $j['n'] !== $n ) {
			continue;
		}
		list( $st, $msg ) = csa_jobs_do( $id, $j );
		if ( 'agent' === $st ) {
			$msg = 'Needs the chat agent (editing text or structure).';
		}
		$jobs[ $i ]['status'] = 'agent' === $st ? 'agent' : $st;
		$jobs[ $i ]['result'] = $msg;
		update_post_meta( $id, '_csa_jobs', wp_slash( $jobs ) );
		$sc = csa_seo_score( $id );
		wp_send_json_success( array( 'job' => $jobs[ $i ], 'own' => is_wp_error( $sc ) ? null : $sc['score'], 'alerts' => csa_take_alerts() ) );
	}
	wp_send_json_error( 'No such job. Scan the page again.' );
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
	wp_send_json_success( array( 'title' => get_the_title( $id ), 'jobs' => csa_jobs_get( $id ), 'own' => is_wp_error( $sc ) ? 0 : $sc['score'], 'source' => is_wp_error( $sc ) ? '' : $sc['source'], 'plugin' => csa_plugin_score( $id ) ) );
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
	csa_header( csa_t( 'Jobs' ) );
	?>
	<div class="csa-card"><h2><?php echo esc_html( csa_t( 'Saved jobs: which page needs what' ) ); ?></h2><p class="csa-muted"><?php echo esc_html( csa_t( 'Every scanned page keeps its job list here, with what is done and what is left.' ) ); ?></p><div id="csa-jb-over"></div></div>
	<div class="csa-card" id="csa-jobs-app" data-chat="<?php echo esc_attr( admin_url( 'admin.php?page=csa-chat' ) ); ?>">
		<p class="csa-muted"><?php echo esc_html( csa_t( '1) Pick a page or post and press Scan (free, no AI). 2) You get numbered jobs. 3) Tick the ones you want, or type their numbers (for example 1,2,5 or 1-4), and press Run.' ) ); ?></p>
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
