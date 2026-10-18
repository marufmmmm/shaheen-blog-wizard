<?php
/**
 * SEO suite: page facts, AI audits, dashboard data, technical checks, broken links,
 * PageSpeed, schema output, keyword ideas, local checklist, weekly audit + email.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ================================================================== */
/* Page facts                                                          */
/* ================================================================== */

function csa_xval( $x, $expr ) {
	$n = $x->query( $expr );
	return ( $n && $n->length ) ? trim( $n->item( 0 )->nodeValue ) : '';
}

/** Rendered HTML of a page's content (no theme). */
function csa_render_content( $id ) {
	$c = '';
	if ( csa_is_elementor( $id ) && class_exists( '\Elementor\Plugin' ) ) {
		$c = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $id, true );
	}
	if ( ! $c ) {
		$p = get_post( $id );
		$c = $p ? apply_filters( 'the_content', $p->post_content ) : '';
	}
	return (string) $c;
}

function csa_seo_collect( $id ) {
	$p   = get_post( $id );
	$url = get_permalink( $id );
	$out = array( 'id' => $id, 'page_title' => $p->post_title, 'url' => $url, 'slug' => $p->post_name, 'status' => $p->post_status, 'type' => $p->post_type );

	$html = '';
	if ( 'publish' === $p->post_status ) {
		$t0 = microtime( true );
		csa_keepalive_start();
		$r = csa_http( 'GET', add_query_arg( 'csa_nocache', time(), $url ), array( 'timeout' => 15, 'redirection' => 3 ) );
		if ( ! is_wp_error( $r ) && 200 === $r['code'] && '' !== $r['body'] ) {
			$html               = $r['body'];
			$out['source']      = 'live page';
			$out['response_ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			$out['html_kb']     = (int) round( strlen( $html ) / 1024 );
		}
	}
	if ( ! $html ) {
		$html          = '<html><head><title></title></head><body><main>' . csa_render_content( $id ) . '</main></body></html>';
		$out['source'] = 'page content only (live page could not be fetched; title tag and meta are unknown)';
	}

	libxml_use_internal_errors( true );
	$d = new DOMDocument();
	$d->loadHTML( '<?xml encoding="UTF-8">' . $html );
	libxml_clear_errors();
	$x = new DOMXPath( $d );

	$out['title_tag']        = csa_xval( $x, '//title' );
	$out['meta_description'] = csa_xval( $x, '//meta[@name="description"]/@content' );
	$out['meta_robots']      = csa_xval( $x, '//meta[@name="robots"]/@content' );
	$out['canonical']        = csa_xval( $x, '//link[@rel="canonical"]/@href' );
	$out['og_title']         = csa_xval( $x, '//meta[@property="og:title"]/@content' );
	$out['og_image']         = csa_xval( $x, '//meta[@property="og:image"]/@content' );
	$out['viewport']         = csa_xval( $x, '//meta[@name="viewport"]/@content' );
	$out['html_lang']        = csa_xval( $x, '//html/@lang' );
	$out['schema_blocks']    = $x->query( '//script[@type="application/ld+json"]' )->length;
	$out['title_length']     = mb_strlen( $out['title_tag'] );
	$out['meta_desc_length'] = mb_strlen( $out['meta_description'] );

	$h1 = array();
	foreach ( $x->query( '//h1' ) as $n ) {
		$h1[] = trim( preg_replace( '/\s+/u', ' ', $n->textContent ) );
	}
	$out['h1']       = array_slice( $h1, 0, 5 );
	$out['h1_count'] = count( $h1 );

	$main = null;
	foreach ( array( "//*[contains(concat(' ',normalize-space(@class),' '),' elementor-$id ')]", '//main', '//article', '//body' ) as $e ) {
		$n = $x->query( $e );
		if ( $n && $n->length ) {
			$main = $n->item( 0 );
			break;
		}
	}
	if ( $main ) {
		$kill = array();
		foreach ( $x->query( './/script|.//style|.//noscript', $main ) as $s ) {
			$kill[] = $s;
		}
		foreach ( $kill as $s ) {
			$s->parentNode->removeChild( $s );
		}
		$h2 = array();
		foreach ( $x->query( './/h2', $main ) as $n ) {
			$h2[] = trim( preg_replace( '/\s+/u', ' ', $n->textContent ) );
		}
		$out['h2']       = array_slice( $h2, 0, 15 );
		$out['h3_count'] = $x->query( './/h3', $main )->length;

		$text               = trim( preg_replace( '/\s+/u', ' ', $main->textContent ) );
		$out['word_count']  = count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) );
		$out['text_sample'] = mb_substr( $text, 0, csa_eco_val( 700, 1500 ) );

		$imgs = $x->query( './/img', $main );
		$miss = array();
		foreach ( $imgs as $img ) {
			if ( '' === trim( $img->getAttribute( 'alt' ) ) ) {
				$src    = $img->getAttribute( 'src' ) ? $img->getAttribute( 'src' ) : $img->getAttribute( 'data-src' );
				$miss[] = basename( (string) strtok( $src, '?' ) );
			}
		}
		$out['images']             = $imgs->length;
		$out['images_missing_alt'] = count( $miss );
		$out['missing_alt_files']  = array_slice( $miss, 0, 10 );

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$int  = 0;
		$ext  = 0;
		foreach ( $x->query( './/a[@href]', $main ) as $a ) {
			$h = trim( $a->getAttribute( 'href' ) );
			if ( preg_match( '#^https?://#i', $h ) ) {
				( wp_parse_url( $h, PHP_URL_HOST ) === $host ) ? $int++ : $ext++;
			} elseif ( '' !== $h && '#' !== $h[0] && ! preg_match( '#^(tel|mailto|javascript|whatsapp):#i', $h ) ) {
				$int++;
			}
		}
		$out['internal_links'] = $int;
		$out['external_links'] = $ext;
		$out['iframes']        = $x->query( './/iframe', $main )->length;
	}

	$keys = csa_seo_keys();
	if ( $keys ) {
		$out['seo_plugin']           = csa_seo_plugin();
		$out['plugin_title']         = get_post_meta( $id, $keys['title'], true );
		$out['plugin_description']   = get_post_meta( $id, $keys['description'], true );
		$out['plugin_focus_keyword'] = get_post_meta( $id, $keys['keyword'], true );
	} else {
		$out['seo_plugin'] = 'none';
	}
	return $out;
}

/* ================================================================== */
/* AI audit                                                            */
/* ================================================================== */

/** Run an audit; returns saved array or WP_Error. */
function csa_seo_audit( $id, $image_path = null, $fast = false ) {
	$p = csa_valid_post( $id );
	if ( ! $p ) {
		return new WP_Error( 'nf', csa_t( 'Page not found.' ) );
	}
	$facts = csa_seo_collect( $p->ID );
	$psi   = get_post_meta( $p->ID, '_csa_psi', true );
	if ( is_array( $psi ) ) {
		$facts['pagespeed'] = $psi;
	}
	$lang = csa_opt( 'seo_lang' );

	$system = 'You are an expert SEO auditor. ' . csa_site_context()
		. "\n\nYou get facts extracted from ONE page" . ( $image_path ? ' and a screenshot of it' : '' ) . '. Judge: title tag, meta description, headings (exactly one H1), content depth and relevance to the likely search intent, keyword use, image alt text, internal links, canonical, Open Graph, schema, mobile viewport, speed signals'
		. ( $image_path ? ', and from the screenshot: layout, readability, calls to action, mobile problems' : '' ) . '. Be specific to this page; no generic advice.'
		. "\nReturn ONLY valid JSON, no markdown:"
		. "\n{\"score\": 0-100, \"summary\": \"2 sentences\", \"issues\": [{\"severity\": \"high|medium|low\", \"category\": \"title|meta|headings|content|images|links|technical|schema|speed|design\", \"issue\": \"...\", \"fix\": \"...\"}], \"suggested_title\": \"max 60 chars\", \"suggested_meta_description\": \"120-155 chars\", \"suggested_focus_keyword\": \"...\"}"
		. "\nAt most " . csa_eco_val( 6, 8 ) . " issues, most important first; keep each issue and fix to one short sentence. Write summary, issues and fixes in $lang. Write the suggested title, description and keyword in the main language of the page content.";

	$content = array();
	if ( $image_path ) {
		$blk = csa_image_block( $image_path );
		if ( $blk ) {
			$content[] = $blk;
		}
	}
	$content[] = array( 'type' => 'text', 'text' => "Page facts:\n" . wp_json_encode( $facts, CSA_JSON ) );

	$data = csa_claude(
		array( array( 'role' => 'user', 'content' => $content ) ),
		$system,
		array( 'max_tokens' => csa_eco_val( 1500, 3000 ), 'task' => $fast ? 'bulk' : 'seo', 'needs_vision' => (bool) $image_path, 'feature' => 'SEO audit' )
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$report = csa_json( csa_text_of( $data ) );
	if ( ! is_array( $report ) || ! isset( $report['score'] ) ) {
		return new WP_Error( 'parse', csa_t( 'Could not read the SEO report. Please try again.' ) );
	}
	$report['score'] = max( 0, min( 100, (int) $report['score'] ) );
	$prev            = get_post_meta( $p->ID, '_csa_seo', true );
	$saved           = array(
		'time'       => current_time( 'mysql' ),
		'score'      => $report['score'],
		'prev_score' => is_array( $prev ) ? (int) $prev['score'] : null,
		'report'     => $report,
		'facts'      => $facts,
	);
	update_post_meta( $p->ID, '_csa_seo', wp_slash( $saved ) );
	csa_log( csa_t( 'SEO audit of' ) . ' #' . $p->ID . ' ' . $p->post_title . ': ' . $report['score'] );
	return $saved;
}

add_action( 'wp_ajax_csa_seo_analyze', 'csa_ajax_seo_analyze' );
function csa_ajax_seo_analyze() {
	csa_guard( 'seo' );
	$img  = null;
	$data = (string) wp_unslash( $_POST['image'] ?? '' );
	if ( preg_match( '#^data:image/(jpeg|png|webp);base64,#', $data ) ) {
		$bin = base64_decode( substr( $data, strpos( $data, ',' ) + 1 ), true );
		if ( $bin && @getimagesizefromstring( $bin ) ) {
			$img = csa_private_dir() . '/seo-' . md5( $bin ) . '.jpg';
			file_put_contents( $img, $bin );
		}
	}
	$r = csa_seo_audit( (int) ( $_POST['id'] ?? 0 ), $img, ! empty( $_POST['bulk'] ) );
	if ( $img ) {
		@unlink( $img );
	}
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( $r->get_error_message() );
	}
	csa_seo_snapshot();
	$r['alerts'] = csa_take_alerts();
	wp_send_json_success( $r );
}

add_action( 'wp_ajax_csa_seo_get', 'csa_ajax_seo_get' );
function csa_ajax_seo_get() {
	csa_guard( 'seo' );
	$id    = (int) ( $_POST['id'] ?? 0 );
	$saved = get_post_meta( $id, '_csa_seo', true );
	if ( ! is_array( $saved ) ) {
		wp_send_json_error( csa_t( 'Not analysed yet.' ) );
	}
	$saved['psi'] = get_post_meta( $id, '_csa_psi', true );
	wp_send_json_success( $saved );
}

add_action( 'wp_ajax_csa_seo_apply', 'csa_ajax_seo_apply' );
function csa_ajax_seo_apply() {
	csa_guard( 'seo' );
	$p     = csa_valid_post( $_POST['id'] ?? 0 );
	$field = sanitize_key( $_POST['field'] ?? '' );
	if ( ! $p || ! in_array( $field, array( 'title', 'description', 'keyword' ), true ) ) {
		wp_send_json_error( csa_t( 'Page not found.' ) );
	}
	$r = csa_seo_set( $p->ID, array( $field => wp_unslash( $_POST['value'] ?? '' ) ), csa_t( 'Applied SEO suggestion on' ) );
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( $r->get_error_message() );
	}
	wp_send_json_success();
}

/* ================================================================== */
/* Overview / dashboard data                                           */
/* ================================================================== */

function csa_seo_snapshot() {
	$o     = csa_seo_overview();
	$trend = get_option( 'csa_seo_trend', array() );
	$trend = is_array( $trend ) ? $trend : array();
	if ( $o['audited'] ) {
		$trend[ current_time( 'Y-m-d' ) ] = $o['avg'];
	}
	ksort( $trend );
	update_option( 'csa_seo_trend', array_slice( $trend, -30, null, true ), false );
}

function csa_seo_overview() {
	$ids = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 500, 'fields' => 'ids' ) );
	$o   = array(
		'total'    => count( $ids ),
		'audited'  => 0,
		'avg'      => 0,
		'good'     => 0,
		'ok'       => 0,
		'bad'      => 0,
		'issues'   => array(),
		'worst'    => array(),
		'dup_t'    => array(),
		'dup_d'    => array(),
		'thin'     => array(),
		'noindex'  => array(),
		'slow'     => array(),
		'drops'    => array(),
	);
	$sum    = 0;
	$titles = array();
	$descs  = array();
	$count  = array(
		'missing_meta' => 0,
		'bad_title'    => 0,
		'h1'           => 0,
		'thin'         => 0,
		'alt'          => 0,
		'schema'       => 0,
		'links'        => 0,
		'slow'         => 0,
	);
	foreach ( $ids as $id ) {
		$s = get_post_meta( $id, '_csa_seo', true );
		if ( ! is_array( $s ) ) {
			continue;
		}
		$o['audited']++;
		$sc   = (int) $s['score'];
		$sum += $sc;
		$o[ $sc >= 80 ? 'good' : ( $sc >= 50 ? 'ok' : 'bad' ) ]++;
		$o['worst'][] = array( 'id' => $id, 'title' => get_the_title( $id ), 'score' => $sc );
		if ( null !== ( $s['prev_score'] ?? null ) && $sc < (int) $s['prev_score'] - 4 ) {
			$o['drops'][] = array( 'id' => $id, 'title' => get_the_title( $id ), 'from' => (int) $s['prev_score'], 'to' => $sc );
		}
		$f = (array) ( $s['facts'] ?? array() );
		if ( empty( $f['meta_description'] ) ) {
			$count['missing_meta']++;
		}
		$tl = (int) ( $f['title_length'] ?? 0 );
		if ( $tl < 25 || $tl > 65 ) {
			$count['bad_title']++;
		}
		if ( 1 !== (int) ( $f['h1_count'] ?? 1 ) ) {
			$count['h1']++;
		}
		if ( (int) ( $f['word_count'] ?? 999 ) < 300 ) {
			$count['thin']++;
			$o['thin'][] = array( 'id' => $id, 'title' => get_the_title( $id ), 'words' => (int) $f['word_count'] );
		}
		if ( (int) ( $f['images_missing_alt'] ?? 0 ) > 0 ) {
			$count['alt']++;
		}
		if ( ! (int) ( $f['schema_blocks'] ?? 0 ) ) {
			$count['schema']++;
		}
		if ( ! (int) ( $f['internal_links'] ?? 1 ) ) {
			$count['links']++;
		}
		if ( (int) ( $f['response_ms'] ?? 0 ) > 2500 ) {
			$count['slow']++;
			$o['slow'][] = array( 'id' => $id, 'title' => get_the_title( $id ), 'ms' => (int) $f['response_ms'] );
		}
		if ( false !== stripos( (string) ( $f['meta_robots'] ?? '' ), 'noindex' ) ) {
			$o['noindex'][] = array( 'id' => $id, 'title' => get_the_title( $id ) );
		}
		if ( ! empty( $f['title_tag'] ) ) {
			$titles[ mb_strtolower( $f['title_tag'] ) ][] = $id;
		}
		if ( ! empty( $f['meta_description'] ) ) {
			$descs[ mb_strtolower( $f['meta_description'] ) ][] = $id;
		}
	}
	if ( $o['audited'] ) {
		$o['avg'] = (int) round( $sum / $o['audited'] );
	}
	usort( $o['worst'], function ( $a, $b ) { return $a['score'] <=> $b['score']; } );
	$o['worst'] = array_slice( $o['worst'], 0, 8 );
	foreach ( $titles as $t => $list ) {
		if ( count( $list ) > 1 ) {
			$o['dup_t'][] = array( 'text' => $t, 'ids' => $list );
		}
	}
	foreach ( $descs as $t => $list ) {
		if ( count( $list ) > 1 ) {
			$o['dup_d'][] = array( 'text' => $t, 'ids' => $list );
		}
	}
	$labels = array(
		'missing_meta' => csa_t( 'Missing meta description' ),
		'bad_title'    => csa_t( 'Title too short or too long' ),
		'h1'           => csa_t( 'Not exactly one H1 heading' ),
		'thin'         => csa_t( 'Thin content (under 300 words)' ),
		'alt'          => csa_t( 'Images without alt text' ),
		'schema'       => csa_t( 'No schema markup' ),
		'links'        => csa_t( 'No internal links' ),
		'slow'         => csa_t( 'Slow response (over 2.5s)' ),
	);
	foreach ( $count as $k => $v ) {
		if ( $v ) {
			$o['issues'][] = array( 'label' => $labels[ $k ], 'count' => $v );
		}
	}
	usort( $o['issues'], function ( $a, $b ) { return $b['count'] <=> $a['count']; } );
	return $o;
}

/* ================================================================== */
/* Technical checks                                                    */
/* ================================================================== */

add_action( 'wp_ajax_csa_tech', 'csa_ajax_tech' );
function csa_ajax_tech() {
	csa_guard( 'seo' );
	$c    = array();
	$add  = function ( $label, $status, $detail ) use ( &$c ) {
		$c[] = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
	};
	$home = home_url( '/' );

	$add( csa_t( 'Search engines allowed' ), get_option( 'blog_public' ) ? 'ok' : 'bad', get_option( 'blog_public' ) ? csa_t( 'Your site is visible to search engines.' ) : csa_t( 'Settings > Reading: "Discourage search engines" is ON. Turn it off.' ) );
	$add( 'HTTPS', 0 === strpos( $home, 'https://' ) ? 'ok' : 'bad', $home );
	$ps = get_option( 'permalink_structure' );
	$add( csa_t( 'SEO-friendly URLs' ), $ps ? 'ok' : 'bad', $ps ? $ps : csa_t( 'Plain URLs (?p=123). Set Settings > Permalinks to "Post name".' ) );

	csa_keepalive_start();
	$sm = '';
	foreach ( array( 'sitemap_index.xml', 'wp-sitemap.xml', 'sitemap.xml' ) as $f ) {
		$r = csa_http( 'GET', $home . $f, array( 'timeout' => 15, 'redirection' => 2, 'max_bytes' => 65536 ) );
		$b = is_wp_error( $r ) ? '' : $r['body'];
		if ( ! is_wp_error( $r ) && 200 === $r['code'] && ( false !== stripos( $b, '<urlset' ) || false !== stripos( $b, '<sitemapindex' ) ) ) {
			$sm = $home . $f;
			break;
		}
	}
	$add( csa_t( 'XML sitemap' ), $sm ? 'ok' : 'bad', $sm ? $sm : csa_t( 'No sitemap found. Yoast / Rank Math / WordPress normally create one.' ) );

	$r   = csa_http( 'GET', $home . 'robots.txt', array( 'timeout' => 15 ) );
	$rb  = ( is_wp_error( $r ) || 200 !== $r['code'] ) ? '' : $r['body'];
	$blk = (bool) preg_match( '#^\s*Disallow:\s*/\s*$#mi', $rb );
	$add( 'robots.txt', $blk ? 'bad' : ( $rb ? 'ok' : 'warn' ), $blk ? csa_t( 'robots.txt blocks the whole site (Disallow: /).' ) : ( $rb ? ( false !== stripos( $rb, 'sitemap:' ) ? csa_t( 'Found, and it lists the sitemap.' ) : csa_t( 'Found, but it does not list your sitemap.' ) ) : csa_t( 'Not found.' ) ) );

	$ov = csa_seo_overview();
	$add( csa_t( 'Pages hidden from Google (noindex)' ), $ov['noindex'] ? 'warn' : 'ok', $ov['noindex'] ? implode( ', ', wp_list_pluck( $ov['noindex'], 'title' ) ) : csa_t( 'None found in audited pages.' ) );
	$add( csa_t( 'Duplicate titles' ), $ov['dup_t'] ? 'warn' : 'ok', $ov['dup_t'] ? count( $ov['dup_t'] ) . ' ' . csa_t( 'groups' ) : csa_t( 'None found in audited pages.' ) );
	$add( csa_t( 'Organization schema' ), csa_schema_opts()['on'] ? 'ok' : 'warn', csa_schema_opts()['on'] ? csa_t( 'Enabled in SEO > Schema.' ) : csa_t( 'Not enabled. Set it up in SEO > Schema & Local.' ) );
	$add( csa_t( 'SEO plugin' ), csa_seo_plugin() ? 'ok' : 'warn', csa_seo_plugin() ? csa_seo_plugin() : csa_t( 'None. Install Yoast SEO or Rank Math for titles, descriptions and sitemaps.' ) );
	$add( csa_t( 'Audit coverage' ), $ov['audited'] >= $ov['total'] ? 'ok' : 'warn', $ov['audited'] . ' / ' . $ov['total'] . ' ' . csa_t( 'published pages and posts audited' ) );

	wp_send_json_success( $c );
}

/* ================================================================== */
/* Broken links (client drives batches)                               */
/* ================================================================== */

add_action( 'wp_ajax_csa_links', 'csa_ajax_links' );
function csa_ajax_links() {
	csa_guard( 'seo' );
	$key = 'csa_links_' . get_current_user_id();
	$do  = sanitize_key( $_POST['do'] ?? '' );

	if ( 'collect' === $do ) {
		$offset = (int) ( $_POST['offset'] ?? 0 );
		$st     = 0 === $offset ? array( 'urls' => array(), 'broken' => array() ) : get_transient( $key );
		$st     = is_array( $st ) ? $st : array( 'urls' => array(), 'broken' => array() );
		$ids    = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 500, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
		$batch  = array_slice( $ids, $offset, 8 );
		$home   = home_url();
		csa_keepalive_start();
		foreach ( $batch as $id ) {
			csa_keepalive_tick();
			$html = csa_render_content( $id );
			if ( preg_match_all( '#href\s*=\s*["\']([^"\']+)["\']#i', $html, $m ) ) {
				foreach ( array_unique( $m[1] ) as $h ) {
					$h = html_entity_decode( trim( $h ) );
					if ( '' === $h || '#' === $h[0] || preg_match( '#^(tel|mailto|javascript|whatsapp|sms):#i', $h ) ) {
						continue;
					}
					if ( 0 === strpos( $h, '//' ) ) {
						$h = 'https:' . $h;
					} elseif ( '/' === $h[0] ) {
						$h = $home . $h;
					} elseif ( ! preg_match( '#^https?://#i', $h ) ) {
						continue;
					}
					$h                        = strtok( $h, '#' );
					$st['urls'][ $h ][]       = $id;
					$st['urls'][ $h ]         = array_values( array_unique( $st['urls'][ $h ] ) );
				}
			}
		}
		set_transient( $key, $st, HOUR_IN_SECONDS );
		$next = $offset + count( $batch );
		wp_send_json_success( array( 'next' => $next, 'total_pages' => count( $ids ), 'done' => $next >= count( $ids ), 'urls' => count( $st['urls'] ) ) );
	}

	if ( 'check' === $do ) {
		$st = get_transient( $key );
		if ( ! is_array( $st ) ) {
			wp_send_json_error( csa_t( 'Scan expired. Start again.' ) );
		}
		$offset = (int) ( $_POST['offset'] ?? 0 );
		$urls   = array_slice( array_keys( $st['urls'] ), $offset, 6 );
		$found  = array();
		csa_keepalive_start();
		foreach ( $urls as $u ) {
			$r    = csa_http( 'HEAD', $u, array( 'timeout' => 10, 'redirection' => 5 ) );
			$code = is_wp_error( $r ) ? 0 : $r['code'];
			if ( in_array( $code, array( 0, 403, 405, 501 ), true ) ) {
				$r    = csa_http( 'GET', $u, array( 'timeout' => 10, 'redirection' => 5, 'max_bytes' => 4096 ) );
				$code = is_wp_error( $r ) ? 0 : $r['code'];
			}
			if ( 0 === $code || $code >= 400 ) {
				$pages = array();
				foreach ( $st['urls'][ $u ] as $pid ) {
					$pages[] = array( 'id' => $pid, 'title' => get_the_title( $pid ), 'edit' => get_edit_post_link( $pid, 'raw' ) );
				}
				$row            = array( 'url' => $u, 'code' => $code ? $code : csa_t( 'no response' ), 'pages' => $pages );
				$found[]        = $row;
				$st['broken'][] = $row;
			}
		}
		set_transient( $key, $st, HOUR_IN_SECONDS );
		$next = $offset + count( $urls );
		if ( $next >= count( $st['urls'] ) ) {
			update_option( 'csa_links_last', array( 'time' => current_time( 'mysql' ), 'checked' => count( $st['urls'] ), 'broken' => $st['broken'] ), false );
			csa_log( csa_t( 'Broken link scan:' ) . ' ' . count( $st['broken'] ) . ' / ' . count( $st['urls'] ) );
		}
		wp_send_json_success( array( 'next' => $next, 'total' => count( $st['urls'] ), 'done' => $next >= count( $st['urls'] ), 'broken' => $found ) );
	}
	wp_send_json_error( 'Bad request' );
}

/* ================================================================== */
/* Google PageSpeed                                                    */
/* ================================================================== */

add_action( 'wp_ajax_csa_psi', 'csa_ajax_psi' );
function csa_ajax_psi() {
	csa_guard( 'seo' );
	$p = csa_valid_post( $_POST['id'] ?? 0 );
	if ( ! $p || 'publish' !== $p->post_status ) {
		wp_send_json_error( csa_t( 'Only published pages can be tested.' ) );
	}
	$strategy = 'desktop' === ( $_POST['strategy'] ?? '' ) ? 'desktop' : 'mobile';
	$args     = array( 'url' => get_permalink( $p->ID ), 'strategy' => $strategy, 'category' => 'performance' );
	if ( csa_opt( 'psi_key' ) ) {
		$args['key'] = csa_opt( 'psi_key' );
	}
	csa_keepalive_start();
	$r = csa_http( 'GET', add_query_arg( $args, 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' ), array( 'timeout' => 150 ) );
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( $r->get_error_message() );
	}
	$d = json_decode( $r['body'], true );
	if ( empty( $d['lighthouseResult'] ) ) {
		wp_send_json_error( csa_t( 'PageSpeed error:' ) . ' ' . ( $d['error']['message'] ?? 'HTTP ' . $r['code'] ) );
	}
	$lh  = $d['lighthouseResult'];
	$a   = $lh['audits'];
	$opp = array();
	foreach ( $a as $audit ) {
		if ( 'opportunity' === ( $audit['details']['type'] ?? '' ) && ( $audit['score'] ?? 1 ) < 0.9 ) {
			$opp[] = array( 'title' => $audit['title'], 'saving' => $audit['displayValue'] ?? '', 'ms' => (int) ( $audit['details']['overallSavingsMs'] ?? 0 ) );
		}
	}
	usort( $opp, function ( $x, $y ) { return $y['ms'] <=> $x['ms']; } );
	$res = array(
		'score' => (int) round( ( $lh['categories']['performance']['score'] ?? 0 ) * 100 ),
		'lcp'   => $a['largest-contentful-paint']['displayValue'] ?? '',
		'cls'   => $a['cumulative-layout-shift']['displayValue'] ?? '',
		'tbt'   => $a['total-blocking-time']['displayValue'] ?? '',
		'fcp'   => $a['first-contentful-paint']['displayValue'] ?? '',
		'opps'  => array_slice( $opp, 0, 4 ),
		'time'  => current_time( 'mysql' ),
	);
	$all              = get_post_meta( $p->ID, '_csa_psi', true );
	$all              = is_array( $all ) ? $all : array();
	$all[ $strategy ] = $res;
	update_post_meta( $p->ID, '_csa_psi', wp_slash( $all ) );
	wp_send_json_success( $res );
}

/* ================================================================== */
/* Schema output                                                       */
/* ================================================================== */

function csa_schema_opts() {
	$s = get_option( 'csa_schema', array() );
	return wp_parse_args(
		is_array( $s ) ? $s : array(),
		array( 'on' => 0, 'type' => 'EducationalOrganization', 'name' => get_bloginfo( 'name' ), 'alt_name' => '', 'logo' => '', 'phone' => '', 'email' => '', 'street' => '', 'city' => 'Dhaka', 'region' => 'Dhaka', 'postcode' => '', 'country' => 'BD', 'social' => '', 'branches' => '', 'founded' => '' )
	);
}

function csa_schema_json() {
	$s   = csa_schema_opts();
	$org = array(
		'@context' => 'https://schema.org',
		'@type'    => $s['type'],
		'name'     => $s['name'],
		'url'      => home_url( '/' ),
	);
	foreach ( array( 'alt_name' => 'alternateName', 'logo' => 'logo', 'phone' => 'telephone', 'email' => 'email', 'founded' => 'foundingDate' ) as $k => $v ) {
		if ( '' !== trim( $s[ $k ] ) ) {
			$org[ $v ] = trim( $s[ $k ] );
		}
	}
	if ( $s['street'] || $s['city'] ) {
		$org['address'] = array( '@type' => 'PostalAddress', 'streetAddress' => $s['street'], 'addressLocality' => $s['city'], 'addressRegion' => $s['region'], 'postalCode' => $s['postcode'], 'addressCountry' => $s['country'] );
	}
	$social = array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', $s['social'] ) ) ) );
	if ( $social ) {
		$org['sameAs'] = $social;
	}
	$dep = array();
	foreach ( preg_split( '/\r?\n/', $s['branches'] ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( '' === ( $parts[0] ?? '' ) ) {
			continue;
		}
		$b = array( '@type' => $s['type'], 'name' => $parts[0] );
		if ( ! empty( $parts[1] ) ) {
			$b['address'] = array( '@type' => 'PostalAddress', 'streetAddress' => $parts[1], 'addressCountry' => $s['country'] );
		}
		if ( ! empty( $parts[2] ) ) {
			$b['telephone'] = $parts[2];
		}
		$dep[] = $b;
	}
	if ( $dep ) {
		$org['department'] = $dep;
	}
	return $org;
}

add_action(
	'wp_head',
	function () {
		if ( csa_schema_opts()['on'] && ( is_front_page() || is_home() ) ) {
			echo "\n<script type=\"application/ld+json\">" . wp_json_encode( csa_schema_json(), CSA_JSON ) . "</script>\n";
		}
		if ( is_singular() ) {
			$faq = get_post_meta( get_the_ID(), '_csa_faq', true );
			if ( is_array( $faq ) && $faq ) {
				$q = array();
				foreach ( $faq as $f ) {
					$q[] = array( '@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $f['a'] ) );
				}
				echo "\n<script type=\"application/ld+json\">" . wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $q ), CSA_JSON ) . "</script>\n";
			}
		}
	},
	5
);

/* ================================================================== */
/* Keyword ideas + local checklist                                     */
/* ================================================================== */

add_action( 'wp_ajax_csa_keywords', 'csa_ajax_keywords' );
function csa_ajax_keywords() {
	csa_guard( 'seo' );
	$topic = sanitize_text_field( wp_unslash( $_POST['topic'] ?? '' ) );
	$lang  = sanitize_text_field( wp_unslash( $_POST['lang'] ?? 'Bangla and English' ) );
	if ( '' === $topic ) {
		wp_send_json_error( csa_t( 'Enter a topic.' ) );
	}
	$pages  = implode( "\n", array_map( function ( $p ) { return '- ' . $p->post_title; }, get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 60 ) ) ) );
	$system = 'You are an SEO keyword strategist for Bangladesh. ' . csa_site_context() . "\n\nExisting pages:\n" . $pages
		. "\n\nSuggest " . csa_eco_val( 10, 15 ) . " keywords people in Bangladesh would really search for, in $lang (include Banglish/romanised forms when common). Mix head terms and long-tail questions. Note which existing page fits or whether a new post is needed."
		. "\nReturn ONLY JSON array: [{\"keyword\": \"\", \"language\": \"Bangla|English|Banglish\", \"intent\": \"informational|navigational|commercial|local\", \"difficulty\": \"low|medium|high\", \"target\": \"existing page title or NEW POST\", \"title_idea\": \"\", \"why\": \"short reason\"}]. Difficulty is your estimate; there is no search volume data.";
	$data   = csa_claude( array( array( 'role' => 'user', 'content' => 'Topic: ' . $topic ) ), $system, array( 'max_tokens' => csa_eco_val( 1500, 3000 ), 'task' => 'bulk', 'feature' => 'Keywords' ) );
	if ( is_wp_error( $data ) ) {
		wp_send_json_error( $data->get_error_message() );
	}
	$list = csa_json( csa_text_of( $data ) );
	if ( ! is_array( $list ) ) {
		wp_send_json_error( csa_t( 'Could not read the result. Please try again.' ) );
	}
	wp_send_json_success( array( 'items' => $list, 'alerts' => csa_take_alerts() ) );
}

function csa_local_items() {
	return array(
		'gbp'      => csa_t( 'Google Business Profile claimed and verified for each branch' ),
		'nap'      => csa_t( 'Same name, address and phone on the website, Google and Facebook' ),
		'category' => csa_t( 'Correct primary category (e.g. School / Coaching center / Educational institution)' ),
		'photos'   => csa_t( 'At least 10 real photos per branch (building, classes, events)' ),
		'hours'    => csa_t( 'Opening hours and holiday hours up to date' ),
		'reviews'  => csa_t( 'Ask parents for Google reviews and reply to every review' ),
		'posts'    => csa_t( 'Post updates (admission, results, events) on Google Business monthly' ),
		'map'      => csa_t( 'Google Map embedded on the contact / branch pages' ),
		'branch'   => csa_t( 'A separate page for each branch with address, phone and map' ),
		'schema'   => csa_t( 'Organization schema enabled (SEO > Schema & Local)' ),
	);
}

add_action( 'wp_ajax_csa_local', 'csa_ajax_local' );
function csa_ajax_local() {
	csa_guard( 'seo' );
	$done = get_option( 'csa_local_done', array() );
	$done = is_array( $done ) ? $done : array();
	$k    = sanitize_key( $_POST['key'] ?? '' );
	if ( isset( csa_local_items()[ $k ] ) ) {
		$done[ $k ] = ! empty( $_POST['on'] ) ? 1 : 0;
		update_option( 'csa_local_done', $done, false );
	}
	wp_send_json_success();
}

/* ================================================================== */
/* Weekly: auto-audit + email summary                                  */
/* ================================================================== */

add_action( 'csa_weekly', 'csa_weekly_run' );
function csa_weekly_run() {
	$audited = array();
	if ( csa_opt( 'weekly_audit' ) && csa_has_any_key() ) {
		$ids  = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 500, 'fields' => 'ids' ) );
		$rows = array();
		foreach ( $ids as $id ) {
			$s     = get_post_meta( $id, '_csa_seo', true );
			$rows[] = array( 'id' => $id, 't' => is_array( $s ) ? $s['time'] : '0000' );
		}
		usort( $rows, function ( $a, $b ) { return strcmp( $a['t'], $b['t'] ); } );
		foreach ( array_slice( $rows, 0, max( 1, (int) csa_opt( 'weekly_pages' ) ) ) as $r ) {
			if ( csa_usage_percent() >= 90 ) {
				break;
			}
			$res = csa_seo_audit( $r['id'], null, true );
			if ( ! is_wp_error( $res ) ) {
				$audited[] = $r['id'];
			}
		}
		csa_seo_snapshot();
	}
	if ( csa_opt( 'weekly_email' ) ) {
		csa_weekly_email( $audited );
	}
}

function csa_weekly_email( $audited ) {
	$to = sanitize_email( csa_opt( 'email_to' ) );
	if ( ! $to ) {
		return;
	}
	$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - WEEK_IN_SECONDS );
	$log   = array_filter( (array) get_option( CSA_LOG, array() ), function ( $e ) use ( $since ) { return $e['t'] >= $since; } );
	$ov    = csa_seo_overview();
	$lvl   = csa_alert_level();
	$sched = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'future', 'numberposts' => 10, 'orderby' => 'date', 'order' => 'ASC' ) );

	$h  = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1d2327;max-width:640px">';
	$h .= '<h2 style="margin:0 0 8px">' . esc_html( csa_brand() . ' – ' . csa_t( 'Weekly summary' ) ) . '</h2>';
	$h .= '<p style="color:#646970">' . esc_html( get_bloginfo( 'name' ) . ' · ' . current_time( 'j M Y' ) ) . '</p>';
	$h .= '<h3>' . esc_html( csa_t( 'SEO' ) ) . '</h3><p>' . esc_html( sprintf( csa_t( 'Average score %1$d (%2$d of %3$d pages audited). %4$d pages need attention.' ), $ov['avg'], $ov['audited'], $ov['total'], $ov['bad'] ) ) . '</p>';
	if ( $ov['drops'] ) {
		$h .= '<p><b>' . esc_html( csa_t( 'Score drops:' ) ) . '</b><br>';
		foreach ( $ov['drops'] as $d ) {
			$h .= esc_html( $d['title'] . ': ' . $d['from'] . ' → ' . $d['to'] ) . '<br>';
		}
		$h .= '</p>';
	}
	if ( $audited ) {
		$h .= '<p>' . esc_html( sprintf( csa_t( '%d pages were re-audited automatically this week.' ), count( $audited ) ) ) . '</p>';
	}
	$h .= '<h3>' . esc_html( csa_t( 'Changes this week' ) ) . '</h3>';
	if ( $log ) {
		$h .= '<ul>';
		foreach ( array_slice( $log, 0, 25 ) as $e ) {
			$h .= '<li>' . esc_html( $e['t'] . ' – ' . $e['a'] ) . '</li>';
		}
		$h .= '</ul>';
	} else {
		$h .= '<p>' . esc_html( csa_t( 'No changes.' ) ) . '</p>';
	}
	if ( $sched ) {
		$h .= '<h3>' . esc_html( csa_t( 'Scheduled posts' ) ) . '</h3><ul>';
		foreach ( $sched as $p ) {
			$h .= '<li>' . esc_html( get_the_date( 'j M, g:i a', $p ) . ' – ' . $p->post_title ) . '</li>';
		}
		$h .= '</ul>';
	}
	$h .= '<h3>' . esc_html( csa_t( 'AI allowance' ) ) . '</h3><p>' . esc_html( $lvl ? csa_alert_text( $lvl ) : csa_t( 'Less than 15% of this month\'s AI allowance is used.' ) ) . '</p>';
	$h .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=csa-seo' ) ) . '">' . esc_html( csa_t( 'Open the SEO dashboard' ) ) . '</a></p></div>';

	wp_mail( $to, csa_brand() . ': ' . csa_t( 'Weekly summary' ) . ' – ' . get_bloginfo( 'name' ), $h, array( 'Content-Type: text/html; charset=UTF-8' ) );
}
