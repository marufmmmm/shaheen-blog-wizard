<?php
/**
 * Deterministic SEO score (0-100). No AI: the same page always gets the same number,
 * so the agent can fix, measure again and show a real before/after.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function csa_score_tool() {
	return array(
		array(
			'name'         => 'seo_score',
			'description'  => 'Exact SEO score (0-100) of ONE page from a fixed 20-point checklist (title, meta description, focus keyword, one H1, H2s, word count, image alt text, internal/external links, schema, language, canonical, social image) plus text-consistency warnings (phone numbers / e-mails that differ from the official facts). Free, no AI. Call it BEFORE you plan and AGAIN after your changes; the target is 90+. Every failed item says which tool fixes it.',
			'input_schema' => csa_schema( array( 'id' => array( 'type' => 'integer' ) ), array( 'id' ) ),
		),
	);
}

/** @return array{score:int,items:array,source:string}|WP_Error */
function csa_seo_score( $id ) {
	$p = csa_valid_post( $id );
	if ( ! $p ) {
		return new WP_Error( 'csa', 'Page not found.' );
	}
	$f   = csa_seo_collect( $p->ID );
	$kw  = trim( (string) ( $f['plugin_focus_keyword'] ?? '' ) );
	$ttl = trim( (string) ( $f['title_tag'] ?? '' ) );
	if ( '' === $ttl ) {
		$ttl = trim( (string) ( $f['plugin_title'] ?? '' ) );
	}
	$dsc  = trim( (string) ( $f['meta_description'] ?? '' ) );
	if ( '' === $dsc ) {
		$dsc = trim( (string) ( $f['plugin_description'] ?? '' ) );
	}
	$has  = function ( $hay, $needle ) {
		return '' !== $needle && false !== mb_stripos( (string) $hay, $needle );
	};
	$h1   = (array) ( $f['h1'] ?? array() );
	$live = 0 === strpos( (string) ( $f['source'] ?? '' ), 'live' );
	$tl   = mb_strlen( $ttl );
	$dl   = mb_strlen( $dsc );
	$img  = (int) ( $f['images'] ?? 0 );
	$miss = (int) ( $f['images_missing_alt'] ?? 0 );
	$items = array();
	$add   = function ( $label, $max, $ok, $fix, $part = null ) use ( &$items ) {
		$pts     = $ok ? $max : ( null !== $part ? (int) round( $max * $part ) : 0 );
		$items[] = array( 'check' => $label, 'points' => $pts, 'max' => $max, 'ok' => $ok, 'fix' => $ok ? '' : $fix );
	};
	$add( 'Title 30-60 characters', 10, $tl >= 30 && $tl <= 60, 'update_seo_meta title (now ' . $tl . ' chars)', $tl > 0 ? 0.4 : 0 );
	$add( 'Focus keyword set', 6, '' !== $kw, 'choose ONE focus keyword and save it with update_seo_meta focus_keyword' );
	$add( 'Keyword in title', 6, $has( $ttl, $kw ), 'put the focus keyword near the start of the title (update_seo_meta)' );
	$add( 'Meta description 120-155 characters', 10, $dl >= 120 && $dl <= 155, 'update_seo_meta description (now ' . $dl . ' chars)', $dl > 0 ? 0.4 : 0 );
	$add( 'Keyword in meta description', 4, $has( $dsc, $kw ), 'include the focus keyword naturally in the description' );
	$add( 'Exactly one H1', 8, 1 === (int) ( $f['h1_count'] ?? 0 ), 'the page has ' . (int) ( $f['h1_count'] ?? 0 ) . ' H1; make it exactly one (edit_elementor_html / edit_content)' );
	$add( 'Keyword in H1', 4, $has( implode( ' ', $h1 ), $kw ), 'use the focus keyword in the H1' );
	$add( 'At least 2 H2 headings', 6, count( (array) ( $f['h2'] ?? array() ) ) >= 2, 'add H2 sub-headings that split the text into sections' );
	$wc = (int) ( $f['word_count'] ?? 0 );
	$add( 'At least 300 words', 8, $wc >= 300, 'the page has ' . $wc . ' words; add useful sections (only real facts from the site)', min( 1, $wc / 300 ) * 0.7 );
	$add( 'Keyword in the first part of the text', 4, $has( $f['text_sample'] ?? '', $kw ), 'mention the focus keyword in the opening paragraph' );
	$add( 'At least one image', 3, $img >= 1, 'add a photo (suggest_image_slots / save_chat_image)' );
	$add( 'Every image has alt text', 8, $img >= 1 && 0 === $miss, $miss . ' image(s) lack alt: ' . implode( ', ', (array) ( $f['missing_alt_files'] ?? array() ) ) . ' (update_image_alt / save_chat_image)', $img > 0 ? 1 - $miss / $img : 0 );
	$add( 'At least 3 internal links', 6, (int) ( $f['internal_links'] ?? 0 ) >= 3, 'add internal links to related pages (' . (int) ( $f['internal_links'] ?? 0 ) . ' now)', min( 1, (int) ( $f['internal_links'] ?? 0 ) / 3 ) * 0.7 );
	$add( 'At least 1 external link', 3, (int) ( $f['external_links'] ?? 0 ) >= 1, 'add one useful link to an official/outside source' );
	$add( 'Schema (JSON-LD) present', 5, (int) ( $f['schema_blocks'] ?? 0 ) >= 1, 'set_page_schema (Organization / Course / FAQPage as fits)' );
	$add( 'Language attribute (html lang)', 2, '' !== (string) ( $f['html_lang'] ?? '' ), 'set by the theme / SEO plugin, not in page HTML: tell the owner' );
	$add( 'Canonical link', 2, '' !== (string) ( $f['canonical'] ?? '' ), 'set by the SEO plugin: tell the owner if missing' );
	$add( 'Social image (og:image)', 3, '' !== (string) ( $f['og_image'] ?? '' ), 'set a featured image (set_featured_image)' );
	$add( 'Viewport meta (mobile)', 2, '' !== (string) ( $f['viewport'] ?? '' ), 'set by the theme: tell the owner if missing' );

	$score = 0;
	$max   = 0;
	foreach ( $items as $i ) {
		$score += $i['points'];
		$max   += $i['max'];
	}
	$score = (int) round( $score / max( 1, $max ) * 100 );

	// Text consistency: contact facts on this page against the owner's official facts.
	$warn = array();
	if ( function_exists( 'csa_si_text' ) && function_exists( 'csa_si_official' ) ) {
		$pf  = csa_si_facts( csa_si_text( $p ) );
		$off = csa_si_official();
		if ( $off['ph'] ) {
			foreach ( $pf['ph'] as $n ) {
				if ( ! isset( $off['ph'][ $n ] ) ) {
					$warn[] = 'Phone ' . $n . ' on this page is not one of the official numbers in Site Memory: check it.';
				}
			}
		}
		if ( $off['em'] ) {
			foreach ( $pf['em'] as $e ) {
				if ( ! isset( $off['em'][ $e ] ) ) {
					$warn[] = 'E-mail ' . $e . ' is not an official e-mail in Site Memory: check it.';
				}
			}
		}
	}
	return array(
		'score'    => $score,
		'target'   => 90,
		'reached'  => $score >= 90,
		'items'    => $items,
		'warnings' => $warn,
		'source'   => $live ? 'live page' : 'saved content only (page not published or not reachable: title tag, meta, schema and language cannot be seen, so the score may be lower than the real one)',
		'note'     => 'This is the plugin\'s own fixed checklist, not the Yoast, Rank Math or Google score. Items marked "tell the owner" cannot be fixed by you.',
	);
}
