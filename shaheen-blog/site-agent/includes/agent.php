<?php
/**
 * The chat agent: plan-first tools, chat history, image attachments, saved prompts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CSA_APPROVED  = '[[PLAN APPROVED]] The user approved the plan. Build it now, following the approved steps exactly.';
const CSA_CANCELLED = '[[PLAN CANCELLED]] The user cancelled the plan. Do not build it.';

/* ================================================================== */
/* Tool definitions                                                    */
/* ================================================================== */

function csa_schema( $props, $required = array() ) {
	$s = array( 'type' => 'object', 'properties' => $props ? $props : new stdClass() );
	if ( $required ) {
		$s['required'] = $required;
	}
	return $s;
}

function csa_tools_read() {
	$id = array( 'id' => array( 'type' => 'integer' ) );
	return array(
		array( 'name' => 'get_site_info', 'description' => 'Site name, tagline, URL, theme, front page, SEO plugin.', 'input_schema' => csa_schema( array() ) ),
		array(
			'name'         => 'list_pages',
			'description'  => 'List pages or posts: ID, title, status, link, Elementor flag, last SEO score.',
			'input_schema' => csa_schema(
				array(
					'post_type' => array( 'type' => 'string', 'enum' => array( 'page', 'post' ) ),
					'search'    => array( 'type' => 'string' ),
				)
			),
		),
		array( 'name' => 'get_page', 'description' => 'Read one page/post. Non-Elementor: returns HTML content. Elementor: use get_elementor_html.', 'input_schema' => csa_schema( $id, array( 'id' ) ) ),
		array( 'name' => 'get_elementor_html', 'description' => 'An Elementor page\'s HTML widgets (widget_id + full HTML).', 'input_schema' => csa_schema( $id, array( 'id' ) ) ),
		array(
			'name'         => 'find_in_html',
			'description'  => 'Search a page\'s HTML (or one Elementor HTML widget) and get exact snippets around each match. Use this to find the precise text to edit instead of reading the whole page.',
			'input_schema' => csa_schema(
				array(
					'id'        => array( 'type' => 'integer' ),
					'widget_id' => array( 'type' => 'string', 'description' => 'Elementor HTML widget id; omit for normal pages' ),
					'query'     => array( 'type' => 'string', 'description' => 'text, class name or tag to find' ),
				),
				array( 'id', 'query' )
			),
		),
		array(
			'name'         => 'read_html',
			'description'  => 'Read part of a page\'s HTML (or one Elementor HTML widget) from an offset, when a previous read was cut off.',
			'input_schema' => csa_schema(
				array(
					'id'        => array( 'type' => 'integer' ),
					'widget_id' => array( 'type' => 'string' ),
					'offset'    => array( 'type' => 'integer' ),
					'length'    => array( 'type' => 'integer' ),
				),
				array( 'id' )
			),
		),
		array(
			'name'         => 'page_outline',
			'description'  => 'A cheap map of a page\'s structure (sections, headings, classes, ids) with character offsets for read_html. Cached per chat. Use it first on big pages, then read or search only the part you need.',
			'input_schema' => csa_schema(
				array(
					'id'        => array( 'type' => 'integer' ),
					'widget_id' => array( 'type' => 'string', 'description' => 'Elementor HTML widget id; omit for normal pages' ),
				),
				array( 'id' )
			),
		),
		array( 'name' => 'get_seo_data', 'description' => 'SEO facts for a page (title tag, meta description, headings, words, images without alt, links, schema, SEO plugin fields, PageSpeed) plus the last SEO audit.', 'input_schema' => csa_schema( $id, array( 'id' ) ) ),
		array( 'name' => 'get_image_slots', 'description' => 'Image slots suggested for a page and which ones the user has uploaded (with attachment id, URL, alt).', 'input_schema' => csa_schema( $id, array( 'id' ) ) ),
		array(
			'name'         => 'list_media',
			'description'  => 'Search the Media Library for images: id, title, URL, alt text.',
			'input_schema' => csa_schema( array( 'search' => array( 'type' => 'string' ) ) ),
		),
	);
}

/** Allowed in any state: low-risk, no page content changes. */
function csa_tools_anytime() {
	return array_merge( csa_am_tools(), csa_si_tool(), csa_dsg_read_tool(), csa_gd_ask_tool(), csa_tools_anytime_base() );
}

function csa_tools_anytime_base() {
	return array(
		array(
			'name'         => 'save_note',
			'description'  => 'Save a lasting note to Site Memory (when the user says "remember" or states a site-wide rule).',
			'input_schema' => csa_schema( array( 'note' => array( 'type' => 'string' ) ), array( 'note' ) ),
		),
		array(
			'name'         => 'chat_note',
			'description'  => 'Write a short note into THIS chat\'s memory file (key facts, decisions, exact class names, what is left to do). Older messages are dropped to save cost, but these notes are shown to you on every turn.',
			'input_schema' => csa_schema( array( 'note' => array( 'type' => 'string' ) ), array( 'note' ) ),
		),
		array(
			'name'         => 'suggest_image_slots',
			'description'  => 'Save image suggestions for a page so the user can upload photos for them in the Images section. Replaces earlier pending suggestions for that page.',
			'input_schema' => csa_schema(
				array(
					'id'    => array( 'type' => 'integer' ),
					'slots' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'slot'        => array( 'type' => 'string', 'description' => 'short id, e.g. hero, section-2, featured' ),
								'position'    => array( 'type' => 'string', 'description' => 'where it goes on the page' ),
								'description' => array( 'type' => 'string', 'description' => 'what photo to upload' ),
								'size'        => array( 'type' => 'string', 'description' => 'recommended size, e.g. 1200x630' ),
								'alt'         => array( 'type' => 'string', 'description' => 'SEO alt text with relevant keywords' ),
								'filename'    => array( 'type' => 'string', 'description' => 'SEO file name without extension, lowercase-with-hyphens' ),
								'caption'     => array( 'type' => 'string' ),
							),
							'required'   => array( 'slot', 'description', 'alt', 'filename' ),
						),
					),
				),
				array( 'id', 'slots' )
			),
		),
	);
}

function csa_tool_plan() {
	return array(
		'name'         => 'propose_plan',
		'description'  => 'Show the user your plan for changes and wait for approval. Required before ANY change. Build tools are only available after approval.',
		'input_schema' => csa_schema(
			array(
				'summary' => array( 'type' => 'string', 'description' => 'One or two sentences: what will be done and why.' ),
				'steps'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Numbered, concrete steps: which page, what changes.' ),
			),
			array( 'summary', 'steps' )
		),
	);
}

function csa_tool_step_done() {
	return array(
		'name'         => 'step_done',
		'description'  => 'Tick a step of the approved plan as finished (number from the plan checklist). Call it together with the tool that finishes the step, not as a separate turn.',
		'input_schema' => csa_schema( array( 'step' => array( 'type' => 'integer' ) ), array( 'step' ) ),
	);
}

function csa_tools_write() {
	return array(
		array(
			'name'         => 'create_page',
			'description'  => 'Create a new draft page or post.',
			'input_schema' => csa_schema(
				array(
					'title'     => array( 'type' => 'string' ),
					'content'   => array( 'type' => 'string', 'description' => 'HTML. Custom designs: wrap in <!-- wp:html --> ... <!-- /wp:html -->.' ),
					'post_type' => array( 'type' => 'string', 'enum' => array( 'page', 'post' ) ),
				),
				array( 'title', 'content' )
			),
		),
		array(
			'name'         => 'update_page',
			'description'  => 'Change title/status or replace whole content (non-Elementor). Prefer edit_content for small changes.',
			'input_schema' => csa_schema(
				array(
					'id'      => array( 'type' => 'integer' ),
					'title'   => array( 'type' => 'string' ),
					'content' => array( 'type' => 'string' ),
					'status'  => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'private', 'pending' ) ),
				),
				array( 'id' )
			),
		),
		array(
			'name'         => 'edit_content',
			'description'  => 'Targeted edit of a NON-Elementor page: replace old_text (exactly once) with new_text. Empty old_text appends.',
			'input_schema' => csa_schema(
				array(
					'id'       => array( 'type' => 'integer' ),
					'old_text' => array( 'type' => 'string' ),
					'new_text' => array( 'type' => 'string' ),
				),
				array( 'id', 'new_text' )
			),
		),
		array(
			'name'         => 'edit_elementor_html',
			'description'  => 'Targeted edit of one Elementor HTML widget: replace old_text (exactly once; spaces/line breaks may differ) with new_text. EMPTY old_text appends new_text at the end (best way to add a <style> block with @media rules). Backup saved automatically.',
			'input_schema' => csa_schema(
				array(
					'id'        => array( 'type' => 'integer' ),
					'widget_id' => array( 'type' => 'string' ),
					'old_text'  => array( 'type' => 'string' ),
					'new_text'  => array( 'type' => 'string' ),
				),
				array( 'id', 'widget_id', 'new_text' )
			),
		),
		array(
			'name'         => 'set_page_schema',
			'description'  => 'Attach schema.org JSON-LD (LocalBusiness, EducationalOrganization, FAQPage, Course, BreadcrumbList …) to ONE page. It is printed in the <head> of that page, so the page HTML / Elementor widgets are NOT touched: this is the ONLY correct way to add structured data. Never paste <script type="application/ld+json"> into page content. One block per @type: sending the same @type again replaces it. Undo is available in Activity.',
			'input_schema' => csa_schema(
				array(
					'id'     => array( 'type' => 'integer' ),
					'jsonld' => array( 'type' => 'string', 'description' => 'One valid JSON object as text, with @context and @type. Use only facts you read on the site or the user told you (real phone numbers, addresses, page URLs).' ),
				),
				array( 'id', 'jsonld' )
			),
		),
		csa_dsg_write_tool(),
		csa_gd_write_tool(),
		array( 'name' => 'restore_elementor_backup', 'description' => 'Undo the latest Elementor HTML edit on a page.', 'input_schema' => csa_schema( array( 'id' => array( 'type' => 'integer' ) ), array( 'id' ) ) ),
		array(
			'name'         => 'update_seo_meta',
			'description'  => 'Set SEO title, meta description and/or focus keyword (Yoast / Rank Math).',
			'input_schema' => csa_schema(
				array(
					'id'            => array( 'type' => 'integer' ),
					'title'         => array( 'type' => 'string' ),
					'description'   => array( 'type' => 'string' ),
					'focus_keyword' => array( 'type' => 'string' ),
				),
				array( 'id' )
			),
		),
		array(
			'name'         => 'set_featured_image',
			'description'  => 'Set a page/post featured image from a Media Library attachment.',
			'input_schema' => csa_schema( array( 'id' => array( 'type' => 'integer' ), 'attachment_id' => array( 'type' => 'integer' ) ), array( 'id', 'attachment_id' ) ),
		),
		array(
			'name'         => 'update_image_alt',
			'description'  => 'Set alt text of a Media Library image.',
			'input_schema' => csa_schema( array( 'attachment_id' => array( 'type' => 'integer' ), 'alt' => array( 'type' => 'string' ) ), array( 'attachment_id', 'alt' ) ),
		),
	);
}

function csa_write_tool_names() {
	return wp_list_pluck( csa_tools_write(), 'name' );
}

function csa_tool_label( $name, $in ) {
	$id  = isset( $in['id'] ) ? ' #' . (int) $in['id'] : '';
	$map = array(
		'get_site_info'            => csa_t( 'Read site info' ),
		'list_pages'               => csa_t( 'Listed pages' ),
		'get_page'                 => csa_t( 'Read page' ) . $id,
		'get_elementor_html'       => csa_t( 'Read page design' ) . $id,
		'get_seo_data'             => csa_t( 'Checked SEO of page' ) . $id,
		'find_in_html'             => csa_t( 'Searched page' ) . $id,
		'read_html'                => csa_t( 'Read part of page' ) . $id,
		'page_outline'             => csa_t( 'Mapped page structure' ) . $id,
		'chat_note'                => csa_t( 'Noted in chat memory' ),
		'search_site'              => '🔎 ' . csa_t( 'Searching the whole site' ) . ': ' . mb_substr( (string) ( $in['query'] ?? '' ), 0, 40 ),
		'design_audit'             => '🎨 ' . csa_t( 'Design check' ) . $id,
		'set_page_css'             => '🎨 ' . csa_t( 'Adding CSS' ) . $id,
		'set_page_schema'          => '🧩 ' . csa_t( 'Adding schema' ) . $id,
		'check_consistency'        => '🧮 ' . csa_t( 'Checking the site for contradictions' ),
		'remember'                 => '🧠 ' . csa_t( 'Remembered' ) . ': ' . mb_substr( (string) ( $in['key'] ?? '' ), 0, 40 ),
		'forget'                   => '🧠 ' . csa_t( 'Forgot' ) . ': ' . mb_substr( (string) ( $in['key'] ?? '' ), 0, 40 ),
		'step_done'                => sprintf( csa_t( 'Step %d done' ), (int) ( $in['step'] ?? 0 ) ),
		'get_image_slots'          => csa_t( 'Checked image slots of page' ) . $id,
		'list_media'               => csa_t( 'Searched Media Library' ),
		'save_note'                => csa_t( 'Saved a note to Site Memory' ),
		'suggest_image_slots'      => csa_t( 'Suggested images for page' ) . $id,
		'save_chat_image'          => '🖼 ' . csa_t( 'Saving your photo to the Media Library' ),
		'ask_questions'            => '❓ ' . csa_t( 'Preparing questions for you' ),
		'propose_plan'             => csa_t( 'Prepared a plan' ),
		'create_page'              => csa_t( 'Creating draft' ),
		'update_page'              => csa_t( 'Updating page' ) . $id,
		'edit_content'             => csa_t( 'Editing page' ) . $id,
		'edit_elementor_html'      => csa_t( 'Editing page design' ) . $id,
		'restore_elementor_backup' => csa_t( 'Restoring backup of page' ) . $id,
		'update_seo_meta'          => csa_t( 'Updating SEO fields of page' ) . $id,
		'set_featured_image'       => csa_t( 'Setting featured image of page' ) . $id,
		'update_image_alt'         => csa_t( 'Updating image alt text' ),
	);
	return $map[ $name ] ?? $name;
}

/* ================================================================== */
/* Elementor helpers                                                   */
/* ================================================================== */

function csa_el_load( $id ) {
	$raw  = get_post_meta( $id, '_elementor_data', true );
	$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
	return array( is_string( $raw ) ? $raw : wp_json_encode( $raw ), is_array( $data ) ? $data : null );
}

function csa_el_walk( array &$els, callable $fn ) {
	foreach ( $els as &$el ) {
		if ( 'widget' === ( $el['elType'] ?? '' ) && 'html' === ( $el['widgetType'] ?? '' ) ) {
			$fn( $el );
		}
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			csa_el_walk( $el['elements'], $fn );
		}
	}
	unset( $el );
}

/** Save Elementor data with a backup; returns backup id. */
function csa_el_save( $id, $raw_before, $data ) {
	$backups   = get_post_meta( $id, '_csa_backups', true );
	$backups   = is_array( $backups ) ? $backups : array();
	$bid       = substr( md5( uniqid( '', true ) ), 0, 10 );
	$backups[] = array( 'id' => $bid, 't' => current_time( 'mysql' ), 'd' => $raw_before );
	update_post_meta( $id, '_csa_backups', wp_slash( array_slice( $backups, -8 ) ) );
	update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	csa_clear_cache( $id );
	return $bid;
}

function csa_replace_once( $haystack, $old, $new ) {
	if ( '' === (string) $old ) {
		return $haystack . $new; // Empty old_text = append.
	}
	$n = substr_count( $haystack, $old );
	if ( 1 === $n ) {
		return str_replace( $old, $new, $haystack );
	}
	if ( $n > 1 ) {
		return new WP_Error( 'match', 'old_text was found ' . $n . ' times; it must match exactly once. Include more surrounding text.' );
	}
	if ( false !== strpos( $old, '[base64 file removed]' ) || false !== strpos( $old, 'omitted' ) ) {
		return new WP_Error( 'match', 'old_text contains a placeholder for hidden content ([base64 file removed] / omitted). Pick old_text that does not include hidden parts.' );
	}
	// Tolerant match: line endings (\r\n vs \n), tabs and repeated spaces may differ.
	$parts = preg_split( '/\s+/u', trim( $old ), -1, PREG_SPLIT_NO_EMPTY );
	if ( $parts ) {
		$re = '/' . implode( '\s*', array_map( function ( $p ) { return preg_quote( $p, '/' ); }, $parts ) ) . '/u';
		if ( preg_match_all( $re, $haystack, $m, PREG_OFFSET_CAPTURE ) ) {
			if ( 1 === count( $m[0] ) ) {
				$new_nl = false !== strpos( $haystack, "\r\n" ) ? preg_replace( '/(?<!\r)\n/', "\r\n", $new ) : $new;
				return substr( $haystack, 0, $m[0][0][1] ) . $new_nl . substr( $haystack, $m[0][0][1] + strlen( $m[0][0][0] ) );
			}
			return new WP_Error( 'match', 'old_text was found ' . count( $m[0] ) . ' times (ignoring spaces/line breaks); include more surrounding text.' );
		}
	}
	$hint = '';
	$lines = array_filter( array_map( 'trim', preg_split( '/\r?\n/', (string) $old ) ), function ( $l ) { return strlen( $l ) >= 12; } );
	usort( $lines, function ( $a, $b ) { return strlen( $b ) <=> strlen( $a ); } );
	foreach ( array_slice( $lines, 0, 4 ) as $l ) {
		$probe = substr( $l, 0, 22 );
		$pos   = strpos( $haystack, $probe );
		if ( false !== $pos ) {
			$hint = ' The start of one of your lines DOES exist, here is the real text around it: «' . substr( $haystack, max( 0, $pos - 60 ), 360 ) . '». Copy old_text exactly from this, or append with an EMPTY old_text.';
			break;
		}
	}
	return new WP_Error( 'match', 'old_text was not found.' . $hint . ' Do not retry the same old_text. Get the exact current text with find_in_html first. To add CSS (e.g. mobile @media rules), send an EMPTY old_text: new_text is then appended at the end of the widget as a new <style> block, no matching needed.' );
}

/* ================================================================== */
/* Token safety: compact large HTML before the AI reads it            */
/* ================================================================== */

const CSA_READ_CAP = 24000; // characters per read (~6-8k tokens); halved in economy mode

function csa_read_cap() {
	return csa_eco_val( 12000, CSA_READ_CAP );
}

/** Remove what the AI never needs to see: base64 files, big data scripts, big inline SVGs. */
function csa_compact_html( $html ) {
	$html = preg_replace( '#data:[a-z0-9.+/-]+;base64,[A-Za-z0-9+/=\s]{200,}#i', 'data:[base64 file removed]', (string) $html );
	$html = preg_replace_callback(
		'#(<script\b[^>]*>)(.*?)(</script>)#is',
		function ( $m ) {
			$len = strlen( $m[2] );
			if ( $len <= 1500 ) {
				return $m[0];
			}
			return $m[1] . substr( $m[2], 0, 300 ) . "\n/* [" . round( $len / 1024 ) . ' KB of script/data omitted to save cost; not editable from here] */' . $m[3];
		},
		$html
	);
	$html = preg_replace_callback(
		'#<svg\b.*?</svg>#is',
		function ( $m ) {
			return strlen( $m[0] ) > 3000 ? '<svg><!-- [large inline SVG omitted] --></svg>' : $m[0];
		},
		$html
	);
	$html = preg_replace_callback(
		'#(<style\b[^>]*>)(.*?)(</style>)#is',
		function ( $m ) {
			return strlen( $m[2] ) > 15000 ? $m[1] . substr( $m[2], 0, 15000 ) . "\n/* [rest of this style block omitted] */" . $m[3] : $m[0];
		},
		$html
	);
	return $html;
}

/** Raw HTML of a page (non-Elementor content) or one Elementor HTML widget. */
function csa_html_source( $id, $widget_id = '' ) {
	$p = csa_valid_post( $id );
	if ( ! $p ) {
		return new WP_Error( 'nf', 'Page not found.' );
	}
	if ( '' === (string) $widget_id ) {
		return $p->post_content;
	}
	list( , $data ) = csa_el_load( $p->ID );
	$found          = null;
	if ( $data ) {
		csa_el_walk(
			$data,
			function ( &$el ) use ( $widget_id, &$found ) {
				if ( (string) $el['id'] === (string) $widget_id ) {
					$found = $el['settings']['html'] ?? '';
				}
			}
		);
	}
	return null === $found ? new WP_Error( 'nf', 'Widget ' . $widget_id . ' not found.' ) : $found;
}

function csa_cap_note( $total ) {
	return 'Only the first ' . csa_read_cap() . ' of ' . $total . ' characters are shown. Use find_in_html to locate the exact part you need, or read_html with an offset. Do not read the whole page repeatedly.';
}

function csa_page_summary( $p ) {
	$seo = get_post_meta( $p->ID, '_csa_seo', true );
	return array(
		'id'        => $p->ID,
		'type'      => $p->post_type,
		'title'     => $p->post_title,
		'status'    => $p->post_status,
		'modified'  => $p->post_modified,
		'link'      => get_permalink( $p->ID ),
		'elementor' => csa_is_elementor( $p->ID ),
		'seo_score' => is_array( $seo ) ? ( $seo['score'] ?? null ) : null,
	);
}

function csa_live_guard( $p, $new_status = null ) {
	$live      = in_array( $p->post_status, array( 'publish', 'private' ), true );
	$goes_live = $new_status && in_array( $new_status, array( 'publish', 'private' ), true );
	if ( ( $live || $goes_live ) && ! csa_opt( 'allow_live' ) ) {
		return array( 'error' => 'Editing live pages is turned off in Settings. Work on a draft copy instead.' );
	}
	return null;
}

/* ================================================================== */
/* Tool runner                                                         */
/* ================================================================== */

/** $ctx: array with 'state', collects 'acts' (display items) and 'pages'. */
function csa_run_tool( $name, $in, &$ctx ) {
	if ( in_array( $name, csa_write_tool_names(), true ) && 'approved' !== $ctx['state'] ) {
		return array( 'error' => 'Not allowed yet: propose_plan first and wait for the user to approve.' );
	}
	if ( isset( $in['id'] ) ) {
		$ctx['pages'][] = (int) $in['id'];
	}
	$act = function ( $text, $undo = null, $link = '' ) use ( &$ctx ) {
		csa_log( $text, $undo );
		$ctx['items'][] = array( 'r' => 'act', 't' => $text, 'l' => $link );
	};

	switch ( $name ) {

		case 'get_site_info':
			return array(
				'name'       => get_bloginfo( 'name' ),
				'tagline'    => get_bloginfo( 'description' ),
				'url'        => home_url( '/' ),
				'theme'      => wp_get_theme()->get( 'Name' ),
				'front_page' => (int) get_option( 'page_on_front' ),
				'elementor'  => defined( 'ELEMENTOR_VERSION' ),
				'seo_plugin' => csa_seo_plugin(),
				'wp_version' => get_bloginfo( 'version' ),
			);

		case 'list_pages':
			$args = array(
				'post_type'   => ( ( $in['post_type'] ?? '' ) === 'post' ) ? 'post' : 'page',
				'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts' => csa_eco_val( 40, 150 ),
				'orderby'     => 'modified',
				'order'       => 'DESC',
			);
			if ( ! empty( $in['search'] ) ) {
				$args['s'] = sanitize_text_field( $in['search'] );
			}
			if ( ! csa_eco() ) {
				return array_map( 'csa_page_summary', get_posts( $args ) );
			}
			// Economy: one short line per page instead of a full record.
			$rows = array();
			foreach ( get_posts( $args ) as $lp ) {
				$seo    = get_post_meta( $lp->ID, '_csa_seo', true );
				$rows[] = $lp->ID . ' | ' . $lp->post_title . ' | ' . $lp->post_status . ( csa_is_elementor( $lp->ID ) ? ' | elementor' : '' ) . ( is_array( $seo ) ? ' | seo ' . $seo['score'] : '' );
			}
			return array( 'format' => 'id | title | status | flags', 'pages' => $rows, 'note' => count( $rows ) >= 40 ? 'Showing the 40 most recently edited. Use search to find others.' : '' );

		case 'get_page':
			$p = csa_valid_post( $in['id'] ?? 0 );
			if ( ! $p ) {
				return array( 'error' => 'Page not found.' );
			}
			$out = csa_page_summary( $p );
			if ( $out['elementor'] ) {
				$out['note'] = 'Elementor page: call get_elementor_html to see its HTML widgets.';
			} else {
				$c = csa_compact_html( $p->post_content );
				if ( mb_strlen( $c ) > csa_read_cap() ) {
					$out['note'] = csa_cap_note( mb_strlen( $c ) );
					$c           = mb_substr( $c, 0, csa_read_cap() );
				}
				$out['content'] = $c;
			}
			$out['featured_image'] = get_the_post_thumbnail_url( $p->ID, 'full' );
			return $out;

		case 'get_elementor_html':
			$p = csa_valid_post( $in['id'] ?? 0 );
			if ( ! $p ) {
				return array( 'error' => 'Page not found.' );
			}
			list( , $data ) = csa_el_load( $p->ID );
			if ( ! $data ) {
				return array( 'error' => 'No Elementor data on this page.' );
			}
			$w      = array();
			$budget = csa_read_cap();
			csa_el_walk(
				$data,
				function ( &$el ) use ( &$w, &$budget ) {
					$c     = csa_compact_html( $el['settings']['html'] ?? '' );
					$len   = mb_strlen( $c );
					$show  = max( 0, min( $len, $budget ) );
					$row   = array( 'widget_id' => $el['id'], 'length' => $len, 'html' => mb_substr( $c, 0, $show ) );
					if ( $show < $len ) {
						$row['truncated'] = true;
					}
					$budget -= $show;
					$w[]     = $row;
				}
			);
			$out = array( 'id' => $p->ID, 'link' => get_permalink( $p->ID ), 'html_widgets' => $w );
			if ( ! $w ) {
				$out['note'] = 'No HTML widgets: this page uses normal Elementor widgets; tell the user to edit it in Elementor.';
			} elseif ( $budget <= 0 ) {
				$out['note'] = 'Some widget HTML is cut off to save cost. Use find_in_html(id, widget_id, query) to get the exact snippet you need before editing, or read_html with an offset.';
			}
			return $out;

		case 'read_html':
			$src = csa_html_source( $in['id'] ?? 0, $in['widget_id'] ?? '' );
			if ( is_wp_error( $src ) ) {
				return array( 'error' => $src->get_error_message() );
			}
			$c   = csa_compact_html( $src );
			$off = max( 0, (int) ( $in['offset'] ?? 0 ) );
			$len = min( csa_read_cap(), max( 1000, (int) ( $in['length'] ?? csa_read_cap() ) ) );
			return array( 'offset' => $off, 'total' => mb_strlen( $c ), 'html' => mb_substr( $c, $off, $len ), 'more' => $off + $len < mb_strlen( $c ) );

		case 'find_in_html':
			$src = csa_html_source( $in['id'] ?? 0, $in['widget_id'] ?? '' );
			if ( is_wp_error( $src ) ) {
				return array( 'error' => $src->get_error_message() );
			}
			$q = (string) ( $in['query'] ?? '' );
			if ( '' === $q ) {
				return array( 'error' => 'Empty query.' );
			}
			$c    = csa_compact_html( $src );
			$hits = array();
			$pos  = 0;
			while ( count( $hits ) < 6 && false !== ( $pos = mb_stripos( $c, $q, $pos ) ) ) {
				$start  = max( 0, $pos - 400 );
				$hits[] = array( 'at' => $pos, 'snippet' => mb_substr( $c, $start, 800 + mb_strlen( $q ) ) );
				$pos   += mb_strlen( $q );
			}
			return array( 'matches' => count( $hits ), 'snippets' => $hits, 'note' => $hits ? 'Snippets are exact source text (except removed base64/scripts), usable as old_text for edits.' : 'Not found. Try a shorter or different query (a class name or a few words of visible text).' );

		case 'page_outline':
			$src = csa_html_source( $in['id'] ?? 0, $in['widget_id'] ?? '' );
			if ( is_wp_error( $src ) ) {
				return array( 'error' => $src->get_error_message() );
			}
			$key  = (int) $in['id'] . ':' . sanitize_key( $in['widget_id'] ?? '' );
			$hash = md5( $src );
			if ( isset( $ctx['mem'] ) && ( $ctx['mem']['outlines'][ $key ]['h'] ?? '' ) === $hash ) {
				$lines = $ctx['mem']['outlines'][ $key ]['l'];
			} else {
				$lines = csa_page_outline( $src );
				if ( isset( $ctx['mem'] ) ) {
					$ctx['mem']['outlines'][ $key ] = array( 'h' => $hash, 'l' => $lines );
					if ( count( $ctx['mem']['outlines'] ) > 8 ) {
						$ctx['mem']['outlines'] = array_slice( $ctx['mem']['outlines'], -8, null, true );
					}
				}
			}
			return array( 'total' => mb_strlen( csa_compact_html( $src ) ), 'outline' => implode( "\n", $lines ), 'note' => 'Offsets (@n) work with read_html(offset, length). Use find_in_html for exact text to edit.' );

		case 'remember':
		case 'forget':
			return csa_am_run_tool( $name, $in, $ctx );

		case 'search_site':
		case 'check_consistency':
			return csa_si_run_tool( $name, $in );

		case 'step_done':
			$k = (int) ( $in['step'] ?? 0 ) - 1;
			if ( ! isset( $ctx['mem']['steps'][ $k ] ) ) {
				return array( 'error' => 'No such step in the plan checklist.' );
			}
			if ( 'skipped' === $ctx['mem']['steps'][ $k ]['s'] ) {
				return array( 'error' => 'The user skipped this step. Do not do it.' );
			}
			if ( 'later' === $ctx['mem']['steps'][ $k ]['s'] ) {
				return array( 'error' => 'The user left this step for later. Do not do it now.' );
			}
			$ctx['mem']['steps'][ $k ]['s'] = 'done';
			$ctx['stepped']                 = true;
			return array( 'ok' => true );

		case 'chat_note':
			$note = sanitize_textarea_field( $in['note'] ?? '' );
			if ( '' === $note || ! isset( $ctx['mem'] ) ) {
				return array( 'error' => 'Empty note.' );
			}
			$n = trim( $ctx['mem']['notes'] . "\n- " . mb_substr( $note, 0, 400 ) );
			while ( mb_strlen( $n ) > 2000 && false !== strpos( $n, "\n" ) ) {
				$n = substr( $n, strpos( $n, "\n" ) + 1 ); // drop the oldest note
			}
			$ctx['mem']['notes'] = mb_substr( $n, -2000 );
			return array( 'ok' => true );

		case 'get_seo_data':
			$p = csa_valid_post( $in['id'] ?? 0 );
			if ( ! $p ) {
				return array( 'error' => 'Page not found.' );
			}
			$saved = get_post_meta( $p->ID, '_csa_seo', true );
			return array(
				'facts'      => csa_seo_collect( $p->ID ),
				'pagespeed'  => get_post_meta( $p->ID, '_csa_psi', true ),
				'last_audit' => is_array( $saved ) ? array( 'time' => $saved['time'], 'score' => $saved['score'], 'report' => $saved['report'] ) : null,
				'images_needed' => csa_slots_missing( $p->ID ),
			);

		case 'get_image_slots':
			return csa_slots_for_agent( (int) ( $in['id'] ?? 0 ) );

		case 'list_media':
			$q = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_mime_type' => 'image',
					'post_status'    => 'inherit',
					'numberposts'    => 20,
					's'              => sanitize_text_field( $in['search'] ?? '' ),
				)
			);
			return array_map(
				function ( $a ) {
					return array( 'id' => $a->ID, 'title' => $a->post_title, 'url' => wp_get_attachment_url( $a->ID ), 'alt' => get_post_meta( $a->ID, '_wp_attachment_image_alt', true ) );
				},
				$q
			);

		case 'save_note':
			$note = sanitize_textarea_field( $in['note'] ?? '' );
			if ( '' === $note ) {
				return array( 'error' => 'Empty note.' );
			}
			$m     = csa_memory();
			$old   = $m;
			$m['notes'] = trim( $m['notes'] . "\n- " . $note );
			if ( mb_strlen( $m['notes'] ) > 8000 ) {
				return array( 'error' => 'Site Memory notes are full. Ask the admin to tidy them in Site Memory.' );
			}
			update_option( CSA_MEM, $m, false );
			$act( csa_t( 'Saved to Site Memory:' ) . ' ' . mb_substr( $note, 0, 80 ), array( 'type' => 'memory', 'old' => $old ) );
			return array( 'ok' => true );

		case 'suggest_image_slots':
			$p = csa_valid_post( $in['id'] ?? 0 );
			if ( ! $p ) {
				return array( 'error' => 'Page not found.' );
			}
			$n = csa_slots_save( $p->ID, (array) ( $in['slots'] ?? array() ) );
			$ctx['items'][] = csa_slots_item( $p->ID );
			return array( 'ok' => true, 'slots' => $n, 'note' => 'An upload card is now shown in the chat: the user can upload each photo (the plugin sets alt text and the SEO file name) or skip it. Skipped photos are flagged "image upload required" on the SEO page. Tell the user in one sentence to use the card, then stop. When they press "Place images on page" you will get a task to put the uploaded images into the best spots.' );

		case 'create_page':
			$id = wp_insert_post(
				array(
					'post_type'    => ( ( $in['post_type'] ?? '' ) === 'post' ) ? 'post' : 'page',
					'post_title'   => sanitize_text_field( $in['title'] ?? 'Untitled' ),
					'post_content' => (string) ( $in['content'] ?? '' ),
					'post_status'  => 'draft',
					'post_author'  => csa_author_id(),
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				return array( 'error' => $id->get_error_message() );
			}
			$ctx['pages'][] = $id;
			if ( 'post' === get_post_type( $id ) ) {
				update_post_meta( $id, '_csa_format', 1 );
			}
			$act( csa_t( 'Created draft' ) . ' #' . $id . ': ' . get_the_title( $id ), array( 'type' => 'created', 'post' => $id ), get_preview_post_link( $id ) );
			return array( 'id' => $id, 'status' => 'draft', 'preview' => get_preview_post_link( $id ), 'edit' => admin_url( 'post.php?post=' . $id . '&action=edit' ) );

		case 'update_page':
		case 'edit_content':
			$p = csa_valid_post( $in['id'] ?? 0 );
			if ( ! $p ) {
				return array( 'error' => 'Page not found.' );
			}
			if ( ( 'edit_content' === $name || isset( $in['content'] ) ) && csa_is_elementor( $p->ID ) ) {
				return array( 'error' => 'Elementor page: use get_elementor_html and edit_elementor_html.' );
			}
			$g = csa_live_guard( $p, $in['status'] ?? null );
			if ( $g ) {
				return $g;
			}
			$data = array( 'ID' => $p->ID );
			if ( 'edit_content' === $name ) {
				$new = csa_replace_once( $p->post_content, $in['old_text'] ?? '', (string) ( $in['new_text'] ?? '' ) );
				if ( is_wp_error( $new ) ) {
					return array( 'error' => $new->get_error_message() );
				}
				$data['post_content'] = $new;
			} else {
				if ( isset( $in['title'] ) ) {
					$data['post_title'] = sanitize_text_field( $in['title'] );
				}
				if ( isset( $in['content'] ) ) {
					$data['post_content'] = (string) $in['content'];
				}
				if ( isset( $in['status'] ) ) {
					$data['post_status'] = sanitize_key( $in['status'] );
				}
			}
			$undo = csa_snapshot_post( $p->ID );
			$r    = wp_update_post( $data, true );
			if ( is_wp_error( $r ) ) {
				return array( 'error' => $r->get_error_message() );
			}
			csa_clear_cache( $p->ID );
			$act( csa_t( 'Updated' ) . ' #' . $p->ID . ': ' . get_the_title( $p->ID ), $undo, get_permalink( $p->ID ) );
			return array( 'ok' => true, 'link' => get_permalink( $p->ID ), 'preview' => get_preview_post_link( $p->ID ) );

		case 'edit_elementor_html':
			$p = csa_valid_post( $in['id'] ?? 0 );
			if ( ! $p ) {
				return array( 'error' => 'Page not found.' );
			}
			$g = csa_live_guard( $p );
			if ( $g ) {
				return $g;
			}
			list( $raw, $data ) = csa_el_load( $p->ID );
			if ( ! $data ) {
				return array( 'error' => 'No Elementor data on this page.' );
			}
			$wid   = (string) ( $in['widget_id'] ?? '' );
			$old   = (string) ( $in['old_text'] ?? '' );
			$new   = (string) ( $in['new_text'] ?? '' );
			$found = false;
			$err   = null;
			csa_el_walk(
				$data,
				function ( &$el ) use ( $wid, $old, $new, &$found, &$err ) {
					if ( (string) $el['id'] !== $wid ) {
						return;
					}
					$found = true;
					$res   = csa_replace_once( $el['settings']['html'] ?? '', $old, $new );
					if ( is_wp_error( $res ) ) {
						$err = $res->get_error_message();
					} else {
						$el['settings']['html'] = $res;
					}
				}
			);
			if ( ! $found ) {
				return array( 'error' => 'Widget ' . $wid . ' not found. Call get_elementor_html again.' );
			}
			if ( $err ) {
				return array( 'error' => $err );
			}
			$bid = csa_el_save( $p->ID, $raw, $data );
			$act( csa_t( 'Edited design of' ) . ' #' . $p->ID . ': ' . get_the_title( $p->ID ), array( 'type' => 'elementor', 'post' => $p->ID, 'backup' => $bid ), get_permalink( $p->ID ) );
			return array( 'ok' => true, 'link' => get_permalink( $p->ID ) );

		case 'restore_elementor_backup':
			$p = csa_valid_post( $in['id'] ?? 0 );
			if ( ! $p ) {
				return array( 'error' => 'Page not found.' );
			}
			$backups = get_post_meta( $p->ID, '_csa_backups', true );
			if ( ! is_array( $backups ) || ! $backups ) {
				return array( 'error' => 'No backups saved for this page.' );
			}
			$last = array_pop( $backups );
			update_post_meta( $p->ID, '_elementor_data', wp_slash( $last['d'] ) );
			update_post_meta( $p->ID, '_csa_backups', wp_slash( $backups ) );
			csa_clear_cache( $p->ID );
			$act( csa_t( 'Restored backup of' ) . ' #' . $p->ID . ' (' . $last['t'] . ')', null, get_permalink( $p->ID ) );
			return array( 'ok' => true, 'restored_from' => $last['t'] );

		case 'design_audit':
			$p = csa_valid_post( $in['id'] ?? 0 );
			if ( ! $p ) {
				return array( 'error' => 'Page not found.' );
			}
			return array( 'page' => $p->post_title, 'findings' => csa_dsg_audit( $p->ID ), 'note' => 'Show the user the findings by priority, then propose a plan with one fix per step (set_page_css for padding/font/mobile). Elementor element ids in "element" give the CSS selector .elementor-element-<id>.' );

		case 'set_page_css':
			return csa_dsg_set_css( $in, $act );

		case 'set_page_schema':
			$p = csa_valid_post( $in['id'] ?? 0 );
			if ( ! $p ) {
				return array( 'error' => 'Page not found.' );
			}
			$j = json_decode( (string) ( $in['jsonld'] ?? '' ), true );
			if ( ! is_array( $j ) || empty( $j['@type'] ) || empty( $j['@context'] ) ) {
				return array( 'error' => 'jsonld must be ONE valid JSON object (as text) containing @context and @type. Fix the JSON and send it again.' );
			}
			$json = wp_json_encode( $j, CSA_JSON );
			if ( strlen( $json ) > 20000 ) {
				return array( 'error' => 'This JSON-LD is too large (over 20 KB). Send a shorter one.' );
			}
			$old_all = get_post_meta( $p->ID, '_csa_jsonld', true );
			$all     = is_array( $old_all ) ? $old_all : array();
			$all[ is_array( $j['@type'] ) ? implode( '+', $j['@type'] ) : (string) $j['@type'] ] = $json;
			update_post_meta( $p->ID, '_csa_jsonld', wp_slash( $all ) );
			csa_clear_cache( $p->ID );
			$act( csa_t( 'Added schema' ) . ' ' . ( is_array( $j['@type'] ) ? implode( '+', $j['@type'] ) : $j['@type'] ) . ' → #' . $p->ID . ' ' . $p->post_title, array( 'type' => 'seo', 'post' => (int) $p->ID, 'old' => array( '_csa_jsonld' => $old_all ) ), get_permalink( $p ) );
			return array( 'ok' => true, 'note' => 'Printed in the page <head>. Check with the Rich Results Test if the user wants.' );

		case 'update_seo_meta':
			$p = csa_valid_post( $in['id'] ?? 0 );
			if ( ! $p ) {
				return array( 'error' => 'Page not found.' );
			}
			$f = array();
			foreach ( array( 'title' => 'title', 'description' => 'description', 'focus_keyword' => 'keyword' ) as $k => $v ) {
				if ( isset( $in[ $k ] ) ) {
					$f[ $v ] = $in[ $k ];
				}
			}
			$r = csa_seo_set( $p->ID, $f );
			if ( is_wp_error( $r ) ) {
				return array( 'error' => $r->get_error_message() );
			}
			$ctx['items'][] = array( 'r' => 'act', 't' => csa_t( 'Updated SEO fields of' ) . ' #' . $p->ID, 'l' => '' );
			return array( 'ok' => true );

		case 'set_featured_image':
			$p = csa_valid_post( $in['id'] ?? 0 );
			$a = (int) ( $in['attachment_id'] ?? 0 );
			if ( ! $p || ! wp_attachment_is_image( $a ) ) {
				return array( 'error' => 'Page or image not found.' );
			}
			set_post_thumbnail( $p->ID, $a );
			$act( csa_t( 'Set featured image of' ) . ' #' . $p->ID );
			return array( 'ok' => true );

		case 'save_chat_image':
			return csa_gd_save_chat_image( $in, $ctx, $act );

		case 'update_image_alt':
			$a = (int) ( $in['attachment_id'] ?? 0 );
			if ( ! wp_attachment_is_image( $a ) ) {
				return array( 'error' => 'Image not found.' );
			}
			$old = get_post_meta( $a, '_wp_attachment_image_alt', true );
			update_post_meta( $a, '_wp_attachment_image_alt', sanitize_text_field( $in['alt'] ?? '' ) );
			$act( csa_t( 'Updated alt text of image' ) . ' #' . $a, array( 'type' => 'alt', 'post' => $a, 'old' => $old ) );
			return array( 'ok' => true );
	}
	return array( 'error' => 'Unknown tool ' . $name );
}

/* ================================================================== */
/* System prompt                                                       */
/* ================================================================== */

function csa_agent_prompt( $state, $auto = null, $mem = array() ) {
	$s = 'You are ' . csa_brand() . ', the website design, content and SEO agent inside the WordPress admin. ' . csa_site_context( $auto )
		. "\n\n## How you work"
		. "\n- Reply in the language the user writes in (Bangla or English). Be clear and practical; no filler."
		. "\n- IN THIS MODE YOU CAN ONLY READ. Editing tools appear after the user approves a plan, so never say you have no edit/schema tool and never tell the user to paste code themselves. Read each page ONCE (the result stays in your notes; do not re-read the same page or SEO data), use at most 3-4 reads, then call propose_plan with every change (text, SEO title/description, schema, alt text, CSS) as concrete steps."
		. "\n- LANGUAGE / TONE REQUESTS (ভাষা, শ্রুতিমধুর, humanise, natural, rewrite): the user wants the visible TEXT changed, not SEO. First read the real text (get_elementor_html or page_outline/read_html), then propose_plan with one step per section listing the exact old sentence and your new sentence in the page's own language. Keep every fact, number, phone, name unchanged. Do not plan SEO/schema/image work unless asked."
		. "\n- PLAN FIRST, ALWAYS: you never change anything until the user approves. For any request that would change the site: (1) investigate with the read tools and any attached images, (2) call propose_plan with a short summary and concrete numbered steps (which page, exactly what changes), then STOP. Do not write the final content inside the plan."
		. "\n- Questions and analysis need no plan: just answer."
		. "\n- After approval you get build tools. Follow the approved steps exactly and work through ALL of them without stopping to ask; tick each finished step with step_done (in the same turn as the tool that finishes it). Steps marked LATER in the plan checklist were left for later by the user: never do them now. If something unexpected needs a different approach, stop and explain instead of improvising."
		. "\n- Plans: list every step the job needs, numbered, one concrete change per step, so the user can approve some now and leave others for later."
		. "\n- Analyse well: be specific (section names, CSS classes, exact text), explain the cause, and put the most important problems first. When the user attaches screenshots, study them carefully."
		. "\n- Save tokens: read only what you need, don't re-read unchanged pages, make small targeted edits, and never rewrite a whole large page in one call. Pages can be very large: after one overview read, use find_in_html to fetch only the exact part you will change. Base64 files and big data scripts are hidden from you on purpose; never try to rewrite them."
		. "\n\n## Technical rules"
		. "\n- Elementor pages keep custom code in HTML widgets: get_elementor_html, then edit_elementor_html. Pages built with normal Elementor widgets can't be edited by you: say so."
		. "\n- Mobile fixes: check fixed widths, big fixed font sizes, grids/flex rows that don't wrap, tables, large paddings, overflow, 100vw. Usually append one <style> block with @media (max-width:768px) and (max-width:480px) rules scoped to the page's own classes."
		. "\n- Blog posts you create get the site's post format automatically (table of contents, CTA box, share buttons). Inside post content you may use: <div class=\"csa-takeaways\"><p class=\"csa-takeaways-title\">…</p><ul>…</ul></div>, <div class=\"csa-tip|csa-note|csa-warning\">…</div>, tables, and an FAQ as <div class=\"csa-faq\"><h2>…</h2><details><summary>Q</summary><p>A</p></details></div>. Don't add inline styles to posts."
		. "\n- New designs: clean responsive HTML with inline <style> scoped under a unique wrapper class, wrapped in <!-- wp:html --> ... <!-- /wp:html -->. No external scripts."
		. "\n- SEO: use get_seo_data; fix the most important issues first (title, meta description, one H1, alt text, headings, internal links, schema)."
		. "\n- Images: when a page needs photos, call suggest_image_slots (what to shoot, recommended size, SEO alt text with the page's keywords, SEO file name). The user uploads them in the Images section. When asked to insert them, use get_image_slots and the uploaded URLs/alt in your edits (this needs a plan)."
		. "\n- When the user says to remember something about the site, call save_note."
		. "\n- Chat memory: each chat has a memory file shown to you as [CHAT MEMORY] at the end of the latest message (task, pages, widgets, found snippets, plan, changes, your notes). Older messages may be dropped, so trust it and don't re-read what it already tells you. Call chat_note to keep key facts (exact class names, decisions, what is left to do) — together with other tools, not as a separate step."
		. "\n- Auto memory (you keep it; the admin types nothing): whenever you learn a lasting fact — from the user or from a page — call remember in the same turn as your other tools. Site-wide facts (phone, address, brand colours, fonts, audience, programmes, admission dates, rules the user states) → scope site. Facts about one page (purpose, main sections, classes, known issues) → scope page with page_id. When a fact changes, save it again with the SAME key so the old value is replaced; use forget for facts that are no longer true. Use auto memory and PAGE MEMORY instead of re-reading pages. Never store passwords, keys or temporary task details."
		. "\n- Never repeat or summarize the CHAT MEMORY back to the user, and don't start replies with thanks. Say only what is new."
		. "\n- If an edit fails, don't retry the same old_text. For CSS changes append a new <style> block (empty old_text). If it fails twice, stop and explain."
		. "\n- Structured data (JSON-LD): NEVER paste <script type=\"application/ld+json\"> into page HTML or an Elementor widget. Use set_page_schema (printed in the <head>, page untouched). Use real facts only: search_site / the site map for phone numbers, addresses and the URLs of branch (campus) pages; reference each branch page by its URL."
		. "\n- ALWAYS do what the user's LATEST message asks, and nothing else first. The plan checklist, earlier plans and [CHAT MEMORY] are only a record: never resume or re-offer an older plan (for example an SEO step) when the latest message is about something else. If the latest message is a design check, your very next call is design_audit, not SEO tools. Ask about old unfinished steps only in one short line at the very end."
		. "\n- Design, mobile, fonts, padding, spacing: when asked for suggestions or fixes, run design_audit on the page first (free), tell the user the findings by priority in plain words, then plan ONE fix per step. Apply fixes with set_page_css (works on normal Elementor widgets too; use the .elementor-element-<id> selectors from the audit; phone = max-width:767px, tablet = 1024px). Use edit_elementor_html only for the markup inside HTML widgets. Keep every rule scoped to the page; site-wide CSS only if the user asked for a site-wide change. After a fix suggest the Preview button (phone). You can do all of this: say so instead of refusing."
		. "\n- search_site: search with words that really appear on the site, in Bangla or English (the site's own language). Never use Urdu, Hindi or Arabic script. No match? Try another Bangla or English word, or read the page."
		. "\n- Big pages: page_outline first (cheap, cached), then find_in_html or read_html with an offset for only the part you need."
		. "\n- After changes: give the page link and suggest checking it with the Preview button (phone view) and purging cache if a cache plugin is used.";
	if ( csa_eco() ) {
		$s .= "\n\n## Economy mode (the site owner wants low cost)"
			. "\n- Keep replies short: under about 120 words unless the user asks for detail. Plans: at most 5 steps."
			. "\n- Use as few steps as possible: call several read tools in the same turn when you can, and don't call tools you don't need."
			. "\n- Prefer find_in_html over reading whole pages. Never repeat a read you already have.";
	}
	if ( 'approved' === $state ) {
		$s .= "\n\nCURRENT STATE: the user has APPROVED your latest plan. Build it now.";
		if ( 'all' !== csa_job_mode( $mem ) ) {
			$s .= "\nONE JOB AT A TIME (overrides 'work through ALL of them'): do only the first open step of the checklist, finish it completely, and tick it with step_done in the same turn as its last tool call. Then stop. Never start the next step yourself: the system reports the finished step and asks the user whether to continue.";
		}
	}
	return $s . csa_gd_prompt( $state, $mem );
}

/* ================================================================== */
/* Chat storage                                                        */
/* ================================================================== */

function csa_chat_get( $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . csa_table( 'chats' ) . ' WHERE id = %d', $id ), ARRAY_A );
	if ( ! $row ) {
		return null;
	}
	if ( (int) $row['user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
		return null;
	}
	$row['messages'] = json_decode( $row['messages'], true );
	if ( ! is_array( $row['messages'] ) ) {
		$row['messages'] = array();
	}
	return $row;
}

function csa_chat_save( $id, $messages, $title, $state, $pages ) {
	global $wpdb;
	$now   = current_time( 'mysql' );
	$json  = wp_json_encode( $messages, CSA_JSON );
	$t     = csa_table( 'chats' );
	$pages = array_unique( array_filter( array_map( 'intval', $pages ) ) );
	if ( $id ) {
		$old   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT pages FROM $t WHERE id = %d", $id ) );
		$pages = array_unique( array_merge( array_filter( array_map( 'intval', explode( ',', $old ) ) ), $pages ) );
		$wpdb->update( $t, array( 'messages' => $json, 'updated' => $now, 'state' => $state, 'pages' => csa_pages_str( $pages ) ), array( 'id' => $id ) );
		return $id;
	}
	$row = array(
		'user_id'  => get_current_user_id(),
		'title'    => mb_substr( $title, 0, 190 ),
		'state'    => $state,
		'pages'    => csa_pages_str( $pages ),
		'messages' => $json,
		'created'  => $now,
		'updated'  => $now,
	);
	if ( csa_chats_has_section() ) {
		$row['section'] = sanitize_key( $GLOBALS['csa_section'] ?? '' );
	}
	$ok = $wpdb->insert( $t, $row );
	if ( ! $ok ) {
		return 0;
	}
	$new = (int) $wpdb->insert_id;
	csa_enforce_history_limit();
	return $new;
}

/** Has the chats table got the "section" column yet (added in v2.10)? */
function csa_chats_has_section() {
	static $has = null;
	if ( null === $has ) {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$wpdb->get_var( 'SELECT section FROM ' . csa_table( 'chats' ) . ' LIMIT 1' );
		$has = '' === $wpdb->last_error;
		$wpdb->suppress_errors( false );
	}
	return $has;
}

function csa_pages_str( $pages ) {
	$pages = array_slice( array_values( $pages ), -30 );
	return $pages ? ',' . implode( ',', $pages ) . ',' : '';
}

/** Keep at most N chats site-wide; oldest unpinned are deleted first. */
function csa_enforce_history_limit() {
	global $wpdb;
	$t     = csa_table( 'chats' );
	$limit = max( 10, (int) csa_opt( 'history_limit' ) );
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
	if ( $count <= $limit ) {
		return;
	}
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE pinned = 0 ORDER BY updated ASC LIMIT %d", $count - $limit ) );
	foreach ( $ids as $id ) {
		csa_chat_delete_row( (int) $id );
	}
}

function csa_chat_delete_row( $id ) {
	global $wpdb;
	csa_mem_delete( $id );
	$t   = csa_table( 'chats' );
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT messages FROM $t WHERE id = %d", $id ) );
	$dir = csa_private_dir();
	foreach ( (array) json_decode( (string) $raw, true ) as $m ) {
		foreach ( is_array( $m['content'] ?? null ) ? $m['content'] : array() as $b ) {
			if ( 'image' === ( $b['type'] ?? '' ) && 'csa' === ( $b['source']['type'] ?? '' ) ) {
				$f = $dir . '/' . basename( $b['source']['file'] );
				if ( file_exists( $f ) ) {
					@unlink( $f );
				}
			}
		}
	}
	$wpdb->delete( $t, array( 'id' => $id ) );
}

function csa_img_url( $file ) {
	return add_query_arg( array( 'action' => 'csa_img', 'file' => $file, 'nonce' => wp_create_nonce( 'csa' ) ), admin_url( 'admin-ajax.php' ) );
}

/** Stored API messages -> chat screen items. */
function csa_display_items( $messages, $state ) {
	$out  = array();
	$last = count( $messages ) - 1;
	foreach ( $messages as $i => $m ) {
		if ( 'user' === $m['role'] ) {
			if ( is_string( $m['content'] ) ) {
				$out[] = array( 'r' => 'user', 't' => $m['content'] );
				continue;
			}
			$text = '';
			$imgs = array();
			foreach ( $m['content'] as $b ) {
				$type = $b['type'] ?? '';
				if ( 'image' === $type && 'csa' === ( $b['source']['type'] ?? '' ) ) {
					$imgs[] = csa_img_url( $b['source']['file'] );
				} elseif ( 'text' === $type ) {
					if ( false !== strpos( $b['text'], '[[HANDOFF]]' ) ) {
						$out[] = array( 'r' => 'sys', 't' => '↪ ' . csa_t( 'Continued from the previous chat' ) );
					} elseif ( 0 === strpos( $b['text'], '[[PLAN APPROVED]]' ) ) {
						$lbl   = false !== strpos( $b['text'], 'remaining steps done' ) ? csa_t( 'Remaining steps approved' ) : ( false !== strpos( $b['text'], 'Left for LATER' ) ? csa_t( 'Plan approved (some steps left for later)' ) : csa_t( 'Plan approved' ) );
						$out[] = array( 'r' => 'sys', 't' => '✅ ' . $lbl );
					} elseif ( 0 === strpos( $b['text'], '[[PLAN CANCELLED]]' ) ) {
						$out[] = array( 'r' => 'sys', 't' => '✖ ' . csa_t( 'Plan cancelled' ) );
					} elseif ( 0 === strpos( $b['text'], '[[ANSWERS]]' ) ) {
						$text .= ( $text ? "\n" : '' ) . '✍️ ' . trim( substr( $b['text'], 11 ) );
					} elseif ( 0 === strpos( $b['text'], '[[CONTINUE]]' ) ) {
						$out[] = array( 'r' => 'sys', 't' => '▶ ' . csa_t( 'Continued' ) );
					} elseif ( 0 === strpos( $b['text'], '[[NEXT]]' ) ) {
						continue; // Automatic hand-over to the next plan step: not shown.
					} else {
						$text .= ( $text ? "\n" : '' ) . $b['text'];
					}
				}
			}
			if ( '' !== $text || $imgs ) {
				$out[] = array( 'r' => 'user', 't' => $text, 'imgs' => $imgs );
			}
			continue;
		}
		foreach ( (array) $m['content'] as $b ) {
			$type = $b['type'] ?? '';
			if ( 'text' === $type && '' !== trim( $b['text'] ) ) {
				$out[] = array( 'r' => 'bot', 't' => $b['text'] );
			} elseif ( 'tool_use' === $type ) {
				if ( 'ask_questions' === $b['name'] ) {
					$out[] = array( 'r' => 'ask', 'intro' => (string) ( $b['input']['intro'] ?? '' ), 'questions' => csa_gd_clean_questions( (array) $b['input'] ), 'pending' => ( 'asking' === $state && $i >= $last - 1 ) );
				} elseif ( 'propose_plan' === $b['name'] ) {
					$pending = ( 'planned' === $state && $i >= $last - 1 );
					$out[]   = array( 'r' => 'plan', 's' => $b['input']['summary'] ?? '', 'steps' => (array) ( $b['input']['steps'] ?? array() ), 'pending' => $pending );
				} else {
					$out[] = array( 'r' => 'tool', 't' => csa_tool_label( $b['name'], (array) $b['input'] ) );
				}
			}
		}
	}
	return $out;
}

/** Convert stored messages for the API: fix empty inputs, attach recent images, shrink old tool output. */
function csa_prepare( $messages ) {
	$n       = count( $messages );
	$dir     = csa_private_dir();
	$img_idx = array();
	foreach ( $messages as $i => $m ) {
		if ( 'user' === $m['role'] && is_array( $m['content'] ) ) {
			foreach ( $m['content'] as $b ) {
				if ( 'image' === ( $b['type'] ?? '' ) ) {
					$img_idx[] = $i;
					break;
				}
			}
		}
	}
	$keep_imgs = array_slice( $img_idx, -2 );

	foreach ( $messages as $i => &$m ) {
		if ( ! is_array( $m['content'] ) ) {
			continue;
		}
		$new = array();
		foreach ( $m['content'] as $b ) {
			$type = $b['type'] ?? '';
			if ( 'tool_use' === $type && empty( $b['input'] ) ) {
				$b['input'] = new stdClass();
			}
			if ( 'tool_result' === $type && $i < $n - 4 && is_string( $b['content'] ) && mb_strlen( $b['content'] ) > 4000 ) {
				$b['content'] = mb_substr( $b['content'], 0, 1200 ) . ' ...[older tool output shortened to save cost; use find_in_html if you need it again]';
			}
			if ( 'image' === $type && 'csa' === ( $b['source']['type'] ?? '' ) ) {
				$blk = in_array( $i, $keep_imgs, true ) ? csa_image_block( $dir . '/' . basename( $b['source']['file'] ) ) : null;
				$b   = $blk ? $blk : array( 'type' => 'text', 'text' => '[an image the user shared earlier]' );
			}
			$new[] = $b;
		}
		$m['content'] = $new;
	}
	unset( $m );
	return $messages;
}

/** Is this a user message that the person actually wrote (or an approve/continue signal)? */
function csa_is_human_turn( $m ) {
	if ( 'user' !== $m['role'] ) {
		return false;
	}
	if ( is_string( $m['content'] ) ) {
		return true;
	}
	foreach ( (array) $m['content'] as $b ) {
		if ( in_array( $b['type'] ?? '', array( 'text', 'image' ), true ) ) {
			return true;
		}
	}
	return false;
}

/** Agent steps taken since the person last spoke (or approved / pressed Continue). */
function csa_steps_since_human( $messages ) {
	$n = 0;
	for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
		if ( csa_is_human_turn( $messages[ $i ] ) ) {
			break;
		}
		if ( 'assistant' === $messages[ $i ]['role'] ) {
			$n++;
		}
	}
	return $n;
}

/** Economy: send only the last N conversation turns; older ones are dropped from the request (still saved). */
function csa_window( $messages, $turns ) {
	$starts = array();
	foreach ( $messages as $i => $m ) {
		if ( 'user' === $m['role'] && csa_is_human_turn( $m ) ) {
			$text = is_string( $m['content'] ) ? $m['content'] : implode( ' ', wp_list_pluck( array_filter( (array) $m['content'], function ( $b ) { return 'text' === ( $b['type'] ?? '' ); } ), 'text' ) );
			if ( 0 === strpos( $text, '[[' ) ) {
				continue; // Approve / cancel / continue signals belong to the current turn.
			}
			$starts[] = $i;
		}
	}
	if ( count( $starts ) <= $turns ) {
		return $messages;
	}
	$from = $starts[ count( $starts ) - $turns ];
	$kept = array_slice( $messages, $from );
	// The first kept message may carry results of tool calls that were dropped: turn them into a note.
	$first = $kept[0];
	if ( is_array( $first['content'] ) ) {
		$first['content'] = array_values( array_filter( $first['content'], function ( $b ) { return 'tool_result' !== ( $b['type'] ?? '' ); } ) );
	} else {
		$first['content'] = array( array( 'type' => 'text', 'text' => $first['content'] ) );
	}
	array_unshift( $first['content'], array( 'type' => 'text', 'text' => '[Earlier parts of this chat are not included, to save cost. Ask the user if you need something from them.]' ) );
	$kept[0] = $first;
	return $kept;
}

/** Add user content, merging into the previous user turn when needed. */
function csa_push_user( &$messages, $blocks ) {
	if ( ! $blocks ) {
		return;
	}
	$li = count( $messages ) - 1;
	if ( $li >= 0 && 'user' === $messages[ $li ]['role'] ) {
		$c = $messages[ $li ]['content'];
		if ( is_string( $c ) ) {
			$c = array( array( 'type' => 'text', 'text' => $c ) );
		}
		$messages[ $li ]['content'] = array_merge( $c, $blocks );
		return;
	}
	$messages[] = array( 'role' => 'user', 'content' => $blocks );
}

/* ================================================================== */
/* AJAX: agent step                                                    */
/* ================================================================== */

/** Release the per-chat lock; a message that failed before saving may be retried cleanly. */
function csa_step_release() {
	$l = $GLOBALS['csa_step_lock'] ?? array();
	if ( ! empty( $l['chat'] ) ) {
		delete_transient( 'csa_busy_' . $l['chat'] );
	}
	if ( ! empty( $l['rid'] ) && 'processing' === get_transient( $l['rid'] ) ) {
		delete_transient( $l['rid'] );
	}
	$GLOBALS['csa_step_lock'] = array();
}

/** Short typed answer that means "yes, go ahead". */
function csa_is_approval_text( $t ) {
	$t = trim( $t );
	if ( mb_strlen( $t ) > 40 ) {
		return false;
	}
	return (bool) preg_match( '/^(approve|approved|ok|okay|yes|y|yep|do it|go|go ahead|proceed|start|confirm|continue|haa|ha|hya|hyan|ji|thik ache|thik|korun|koro|kor|হ্যাঁ|হ্যা|হা|ঠিক আছে|করো|করুন|কর|ওকে|ঠিক)[\s.!।]*$/iu', $t );
}

/** Find a numbered plan written as plain text ("1. … 2. … Approve?"). */
function csa_text_plan( $text ) {
	if ( mb_strlen( $text ) < 40 ) {
		return null;
	}
	$steps = array();
	$intro = array();
	$seen  = false;
	foreach ( preg_split( '/\R/u', $text ) as $ln ) {
		if ( preg_match( '/^\s*(?:#+\s*)?(?:\*\*)?(?:step\s*)?(\d{1,2})\s*[.):\-]\s*(?:\*\*)?\s*(.+)$/iu', $ln, $m ) ) {
			$steps[] = trim( preg_replace( '/\*\*/', '', $m[2] ) );
			$seen    = true;
		} elseif ( ! $seen && '' !== trim( $ln ) ) {
			$intro[] = trim( $ln );
		}
	}
	if ( count( $steps ) < 2 || ! preg_match( '/approve|confirm|proceed|shall i|should i|ok\?|go ahead|অনুমোদন|করব|করবো|korbo|korbe|\?/iu', $text ) ) {
		return null;
	}
	$summary = $intro ? mb_substr( preg_replace( '/[*#]+/', '', $intro[0] ), 0, 200 ) : csa_t( 'Plan' );
	return array( 'summary' => $summary, 'steps' => array_map( 'csa_clean_step', array_slice( $steps, 0, 20 ) ), 'intro' => '' );
}

add_action( 'wp_ajax_csa_step', 'csa_ajax_step' );
function csa_ajax_step() {
	csa_guard( 'chat' );
	$chat_id = (int) ( $_POST['chat_id'] ?? 0 );
	$busy_msg = csa_t( 'Still working on your last request. Wait a minute, then press Retry.' );

	// Retry protection: the same message (rid) is never processed twice.
	$rid     = sanitize_key( $_POST['rid'] ?? '' );
	$rid_key = $rid ? 'csa_rid_' . md5( get_current_user_id() . '|' . $rid ) : '';
	$skip    = false;
	if ( $rid_key ) {
		$rv = get_transient( $rid_key );
		if ( 'processing' === $rv ) {
			wp_send_json_error( $busy_msg );
		}
		if ( $rv ) {
			$chat_id = (int) $rv; // Already saved by an earlier attempt: continue, don't add the message again.
			$skip    = true;
		} else {
			set_transient( $rid_key, 'processing', 10 * MINUTE_IN_SECONDS );
		}
	}
	// One step at a time per chat.
	if ( $chat_id ) {
		if ( get_transient( 'csa_busy_' . $chat_id ) ) {
			if ( $rid_key && ! $skip ) {
				delete_transient( $rid_key );
			}
			wp_send_json_error( $busy_msg );
		}
		set_transient( 'csa_busy_' . $chat_id, 1, 5 * MINUTE_IN_SECONDS );
	}
	$GLOBALS['csa_step_lock'] = array( 'chat' => $chat_id, 'rid' => $rid_key );
	register_shutdown_function( 'csa_step_release' ); // Safety net if PHP stops unexpectedly.

	$messages = array();
	$title    = '';
	$state    = 'analyse';
	if ( $chat_id ) {
		$row = csa_chat_get( $chat_id );
		if ( ! $row ) {
			csa_step_release(); wp_send_json_error( csa_t( 'Chat not found.' ) );
		}
		$messages = $row['messages'];
		$title    = $row['title'];
		$state    = $row['state'];
	}

	$text   = trim( wp_unslash( $_POST['message'] ?? '' ) );
	$images = json_decode( wp_unslash( $_POST['images'] ?? '[]' ), true );
	$blocks = array();
	foreach ( is_array( $images ) ? array_slice( $images, 0, 10 ) : array() as $f ) {
		if ( preg_match( '/^[a-f0-9]{32}\.(jpg|png|webp)$/', (string) $f ) && file_exists( csa_private_dir() . '/' . $f ) ) {
			$blocks[] = array( 'type' => 'image', 'source' => array( 'type' => 'csa', 'file' => $f ) );
		}
	}
	if ( '' !== $text ) {
		$blocks[] = array( 'type' => 'text', 'text' => $text );
	}
	if ( $skip ) {
		$blocks = array();
	}
	$typed_ok   = false;
	$was_asking = false;
	if ( 'planned' === $state && $blocks && ! $skip && '' !== $text && count( $blocks ) === 1 && csa_is_approval_text( $text ) && $chat_id && csa_mem_load( $chat_id )['steps'] ) {
		// Typed "approve" / "ok" / "yes" = the Approve button.
		$typed_ok = true;
		$state    = 'approved';
		$blocks   = array( array( 'type' => 'text', 'text' => CSA_APPROVED ) );
	}
	if ( $blocks ) {
		$was_asking = ( 'asking' === $state );
		if ( ! $typed_ok && ( 'planned' === $state || 'approved' === $state || 'asking' === $state ) ) {
			$state = 'analyse'; // New message while a plan waits = the user wants changes.
		}
		csa_push_user( $messages, $blocks );
		if ( ! $chat_id ) {
			$title = '' !== $text ? wp_trim_words( $text, 9, '…' ) : csa_t( 'Image analysis' );
		}
	}
	if ( ! $messages || 'user' !== end( $messages )['role'] ) {
		$items = array();
		if ( $skip && $messages ) {
			$items = csa_display_items( array( end( $messages ) ), $state );
		}
		csa_step_release(); wp_send_json_success( array( 'chat_id' => $chat_id, 'title' => $title, 'done' => true, 'items' => $items, 'state' => $state, 'alerts' => array() ) );
	}

	if ( csa_eco() ) {
		// Per-chat spending cap.
		$cap = (float) csa_opt( 'budget_usd' ) * (int) csa_opt( 'chat_cap_pct' ) / 100;
		if ( $chat_id && $cap > 0 && csa_chat_cost( $chat_id ) >= $cap ) {
			csa_gd_handoff_reply( $chat_id, $title, $state, csa_t( 'chat cost limit' ) );
			csa_step_release(); wp_send_json_error( sprintf( csa_t( 'This chat has used its limit (%d%% of the monthly budget). Start a new chat to continue; a fresh chat is cheaper because it does not carry the old conversation.' ), (int) csa_opt( 'chat_cap_pct' ) ) );
		}
		// Step cap: pause and ask before the agent keeps going.
		if ( ! empty( $_POST['cont'] ) && ! $blocks ) {
			csa_push_user( $messages, array( array( 'type' => 'text', 'text' => '[[CONTINUE]] The user asked you to continue. Finish with as few steps as possible.' ) ) );
		} else {
			$building = 'approved' === $state;
			$cap      = $building ? max( 5, (int) csa_opt( 'build_steps' ) ) : max( 2, (int) csa_opt( 'max_steps' ) );
			if ( csa_steps_since_human( $messages ) >= $cap ) {
				csa_chat_save( $chat_id, $messages, $title, $state, array() );
				if ( $building ) {
					csa_gd_handoff_reply( $chat_id, $title, $state, sprintf( csa_t( '%d steps in one chat' ), csa_steps_since_human( $messages ) ) );
				}
				$msg = $building ? sprintf( csa_t( 'Safety stop: the build has taken %d steps. Continue?' ), csa_steps_since_human( $messages ) ) : sprintf( csa_t( 'Paused after %d steps to save cost. Continue?' ), csa_steps_since_human( $messages ) );
				csa_step_release(); wp_send_json_success( array( 'chat_id' => $chat_id, 'title' => $title, 'done' => true, 'paused' => true, 'state' => $state, 'items' => array( array( 'r' => 'pause', 't' => $msg ) ) ) );
			}
		}
	}
	$GLOBALS['csa_current_chat'] = $chat_id;
	$GLOBALS['csa_section']      = sanitize_key( $_POST['section'] ?? '' );
	$mem = csa_mem_load( $chat_id );
	$job = json_decode( wp_unslash( $_POST['job'] ?? '' ), true );
	if ( is_array( $job ) && ! $chat_id && ! $skip ) {
		csa_gd_start( $mem, $job );   // Guided page job: brief is prepared here, without the AI.
	}
	if ( '' !== $text && 0 === strpos( $text, CSA_ANSWERS ) ) {
		$a = trim( substr( $text, strlen( CSA_ANSWERS ) ) );
		$mem['answers'] = '' !== $a ? $a : 'skipped';
	} elseif ( $was_asking && '' !== $text ) {
		$mem['answers'] = $text;   // The owner wrote a reply instead of using the form.
	}
	foreach ( $blocks as $b ) {
		if ( 'image' === ( $b['type'] ?? '' ) && ! empty( $b['source']['file'] ) ) {
			$an                   = (int) ( $mem['attach_n'] ?? 0 ) + 1;
			$mem['attach_n']      = $an;
			$mem['attach'][ $an ] = $b['source']['file'];
		}
	}
	if ( ! empty( $mem['attach'] ) && count( $mem['attach'] ) > 12 ) {
		$mem['attach'] = array_slice( $mem['attach'], -12, null, true );
	}
	if ( '' !== $text || empty( $mem['snap_set'] ) ) {
		$mem['snap_set']  = 1;
		$mem['read_sigs'] = array();
		// New message from the person: refresh the site-wide memory snapshot and look for page names.
		$mem['site_snap'] = csa_am_site_text();
		$mem['mention']   = csa_am_pages_in_text( $text );
	}
	if ( '' === (string) ( $mem['kind'] ?? '' ) ) {
		$mem['kind']    = csa_chat_kind( $GLOBALS['csa_section'], '' !== $text ? $text : csa_first_human_text( $messages ) );
		$mem['section'] = $GLOBALS['csa_section'];
	}
	if ( '' === $mem['task'] ) {
		$mem['task'] = mb_substr( trim( preg_replace( '/\s+/u', ' ', '' !== $text ? $text : csa_first_human_text( $messages ) ) ), 0, 300 );
	}

	$tools = 'approved' === $state
		? array_merge( csa_tools_read(), csa_tools_anytime(), csa_tools_write(), array( csa_tool_step_done() ) )
		: array_merge( csa_tools_read(), csa_tools_anytime(), array( csa_tool_plan() ) );

	if ( ! empty( $mem['guided'] ) && 'analyse' === $state && '' === trim( (string) $mem['answers'] ) ) {
		$tools = csa_gd_ask_tool();   // Guided job: the first thing the agent may do is ask its questions.
	} elseif ( 'approved' !== $state && 'planned' !== $state && ( csa_steps_since_human( $messages ) >= 4 || (int) ( $mem['dup_run'] ?? 0 ) >= 1 ) ) {
		// Too much reading without a plan: only the plan tool is left, so it must propose now.
		$tools = array( csa_tool_plan() );
		csa_push_user( $messages, array( array( 'type' => 'text', 'text' => '[[NEXT]] You have read enough. Call propose_plan now with concrete steps for everything you found.' ) ) );
	}
	$prepared = csa_prepare( csa_eco() ? csa_window( $messages, max( 1, (int) csa_opt( 'history_turns' ) ) ) : $messages );
	$system   = csa_agent_prompt( $state, (string) $mem['site_snap'], $mem );
	csa_mem_inject( $prepared, $mem );
	$limit    = max( 8000, (int) csa_opt( 'max_request_tokens' ) );
	if ( csa_eco() ) {
		$limit = min( $limit, 30000 );
	}
	// Too big? Shorten step by step instead of refusing.
	$shrink = function ( &$msgs, $max_len, $include_last ) {
		$last = count( $msgs ) - 1;
		foreach ( $msgs as $i => &$pm ) {
			if ( ( ! $include_last && $i >= $last ) || ! is_array( $pm['content'] ) ) {
				continue;
			}
			foreach ( $pm['content'] as &$pb ) {
				if ( 'tool_result' === ( $pb['type'] ?? '' ) && is_string( $pb['content'] ) && mb_strlen( $pb['content'] ) > $max_len ) {
					$pb['content'] = mb_substr( $pb['content'], 0, $max_len ) . ' ...[shortened to save cost; use find_in_html to get the exact part you need]';
				}
			}
			unset( $pb );
		}
		unset( $pm );
	};
	if ( csa_estimate_tokens( $prepared, $system, $tools ) > $limit ) {
		$shrink( $prepared, 600, false );          // 1. older tool results
	}
	if ( csa_estimate_tokens( $prepared, $system, $tools ) > $limit ) {
		$shrink( $prepared, 6000, true );          // 2. newest tool results too
	}
	if ( csa_estimate_tokens( $prepared, $system, $tools ) > $limit ) {
		$prepared = csa_window( $prepared, 1 );    // 3. only the current request
		$shrink( $prepared, 2500, true );
	}
	if ( csa_estimate_tokens( $prepared, $system, $tools ) > $limit * 2 ) {
		csa_chat_save( $chat_id, $messages, $title, $state, array() );
		csa_mem_save( $chat_id, $mem );
		csa_gd_handoff_reply( $chat_id, $title, $state, csa_t( 'request too large' ) );
		csa_step_release(); wp_send_json_error( sprintf( csa_t( 'This request is too large (about %1$s tokens; your limit is %2$s). Start a new chat, or ask about one section of the page at a time.' ), number_format_i18n( csa_estimate_tokens( $prepared, $system, $tools ) ), number_format_i18n( $limit ) ) );
	}

	$data = csa_claude(
		$prepared,
		$system,
		array(
			'tools'          => $tools,
			'max_tokens'     => csa_eco_val( 6000, 8000 ),
			'cache_messages' => true,
			'feature'        => 'Chat',
			'task'           => $mem['kind'],
			'model_key'      => sanitize_key( $_POST['model'] ?? '' ),
			'needs_vision'   => false !== strpos( wp_json_encode( end( $prepared ) ), '"type":"image"' ),
		)
	);
	if ( is_wp_error( $data ) ) {
		csa_step_release(); wp_send_json_error( $data->get_error_message() );
	}

	$messages[] = array( 'role' => 'assistant', 'content' => $data['content'] );
	$ctx        = array( 'state' => $state, 'items' => array(), 'pages' => array(), 'mem' => $mem );
	$results    = array();
	$planned    = false;
	$asked      = false;
	foreach ( $data['content'] as $b ) {
		if ( 'text' === $b['type'] && '' !== trim( $b['text'] ) ) {
			$ctx['items'][] = array( 'r' => 'bot', 't' => $b['text'] );
		} elseif ( 'tool_use' === $b['type'] ) {
			$in = is_array( $b['input'] ) ? $b['input'] : array();
			if ( 'ask_questions' === $b['name'] ) {
				$asked          = true;
				$qs             = csa_gd_clean_questions( $in );
				$ctx['items'][] = array( 'r' => 'ask', 'intro' => sanitize_text_field( $in['intro'] ?? '' ), 'questions' => $qs, 'pending' => true );
				$out            = array( 'ok' => true, 'note' => 'The questions are shown to the owner as a form. Stop now and wait for the answers.' );
			} elseif ( 'propose_plan' === $b['name'] ) {
				$planned        = true;
				$ctx['items'][] = array( 'r' => 'plan', 's' => $in['summary'] ?? '', 'steps' => array_map( 'csa_clean_step', (array) ( $in['steps'] ?? array() ) ), 'pending' => true );
				$out            = array( 'ok' => true, 'note' => 'The plan is shown to the user with Approve / Change / Cancel buttons. Stop now and wait.' );
			} else {
				$ctx['items'][] = array( 'r' => 'tool', 't' => csa_tool_label( $b['name'], $in ) );
				csa_keepalive_tick();
				$sig = md5( $b['name'] . wp_json_encode( $in ) );
				if ( in_array( $sig, (array) ( $ctx['mem']['fail_sigs'] ?? array() ), true ) ) {
					// Same call already failed: don't run it again.
					$out = array( 'error' => 'You already tried exactly this and it failed. Do not repeat it. Use a different approach (for CSS: empty old_text to append a <style> block), or stop and explain the problem to the user.' );
				} elseif ( in_array( $b['name'], wp_list_pluck( csa_tools_read(), 'name' ), true ) && in_array( $sig, (array) ( $ctx['mem']['read_sigs'] ?? array() ), true ) ) {
					$ctx['mem']['dup_run'] = (int) ( $ctx['mem']['dup_run'] ?? 0 ) + 1;
					$out = array( 'note' => 'You already read exactly this in this task; the result is earlier in the conversation and in your chat notes. Do NOT read it again. Call propose_plan now (or read something different).' );
				} else {
					$out = csa_run_tool( $b['name'], $in, $ctx );
					$ctx['mem']['dup_run'] = 0;
					if ( in_array( $b['name'], wp_list_pluck( csa_tools_read(), 'name' ), true ) && ! isset( $out['error'] ) ) {
						$ctx['mem']['read_sigs'][] = $sig;
					}
				}
				if ( in_array( $b['name'], csa_write_tool_names(), true ) ) {
					if ( isset( $out['error'] ) ) {
						$ctx['mem']['fail_run']  = (int) ( $ctx['mem']['fail_run'] ?? 0 ) + 1;
						$ctx['mem']['fail_sigs'] = array_slice( array_merge( (array) ( $ctx['mem']['fail_sigs'] ?? array() ), array( $sig ) ), -10 );
					} else {
						$ctx['mem']['fail_run'] = 0;
						if ( ! empty( $in['id'] ) ) {
							csa_slots_detect( (int) $in['id'], (string) ( $in['new_text'] ?? $in['html'] ?? $in['content'] ?? '' ) );
						}
					}
				}
			}
			csa_mem_track( $ctx['mem'], $b['name'], $in, is_array( $out ) ? $out : array() );
			csa_am_track( $b['name'], $in, is_array( $out ) ? $out : array(), $chat_id );
			$json = wp_json_encode( $out, CSA_JSON );
			if ( mb_strlen( $json ) > 40000 ) {
				$json = mb_substr( $json, 0, 40000 ) . ' ...[cut: too long]';
			}
			$results[] = array( 'type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => $json, 'is_error' => isset( $out['error'] ) );
		}
	}

	if ( ! empty( $ctx['learned'] ) ) {
		$ctx['mem']['learned'] = array_slice( array_merge( (array) ( $ctx['mem']['learned'] ?? array() ), $ctx['learned'] ), -10 );
	}
	// Loop guard: stop when edits keep failing or the agent repeats itself.
	$said = trim( implode( ' ', wp_list_pluck( array_filter( $data['content'], function ( $b ) { return 'text' === $b['type']; } ), 'text' ) ) );
	$loop = (int) ( $ctx['mem']['fail_run'] ?? 0 ) >= 3 || (int) ( $ctx['mem']['dup_run'] ?? 0 ) >= 3;
	if ( ! $loop && mb_strlen( $said ) > 40 ) {
		$key  = mb_substr( preg_replace( '/\s+/u', ' ', $said ), 0, 120 );
		$seen = 0;
		for ( $i = count( $messages ) - 2; $i >= 0 && ! csa_is_human_turn( $messages[ $i ] ); $i-- ) {
			if ( 'assistant' === $messages[ $i ]['role'] ) {
				$prev = trim( implode( ' ', wp_list_pluck( array_filter( (array) $messages[ $i ]['content'], function ( $b ) { return 'text' === ( $b['type'] ?? '' ); } ), 'text' ) ) );
				if ( mb_substr( preg_replace( '/\s+/u', ' ', $prev ), 0, 120 ) === $key ) {
					$seen++;
				}
			}
		}
		$loop = $seen >= 2;
	}

	$done = true;
	if ( $loop && $results ) {
		$messages[]             = array( 'role' => 'user', 'content' => $results );
		$ctx['mem']['fail_run'] = 0;
		$ctx['mem']['dup_run']  = 0;
		if ( 'approved' === $state || 'planned' !== $state ) {
			$state = 'analyse';
		}
		$ctx['items'][] = array( 'r' => 'alert', 't' => csa_t( 'The agent was stuck (the same edit kept failing or it repeated itself), so it was stopped to save money. Nothing more is charged. Tell it what to try differently, or start a new chat for this part.' ) );
		$results        = array();
		$rest = csa_rest_item( $ctx['mem'] );
		if ( $rest ) {
			$ctx['items'][] = $rest;
		}
	} elseif ( $results ) {
		$messages[] = array( 'role' => 'user', 'content' => $results );
		if ( $planned ) {
			$state = 'planned';
		} elseif ( $asked ) {
			$state = 'asking';
		} else {
			$done = false;
			if ( 'all' !== csa_job_mode( $ctx['mem'] ) && 'approved' === $state && ! empty( $ctx['stepped'] ) && csa_mem_remaining( $ctx['mem'] ) ) {
				// One job at a time: this step is finished. Report it and ask before the next one.
				$state = 'analyse';
				$done  = true;
				$last  = '';
				foreach ( (array) $ctx['mem']['steps'] as $i => $st ) {
					if ( 'done' === $st['s'] ) {
						$last = ( $i + 1 ) . '. ' . $st['t'];
					}
				}
				$ctx['items'][] = array( 'r' => 'sys', 't' => '✅ ' . csa_t( 'Done' ) . ': ' . mb_substr( $last, 0, 160 ) );
				$rest           = csa_rest_item( $ctx['mem'] );
				if ( $rest ) {
					$ctx['items'][] = $rest;
				}
			}
		}
	} else {
		$cut    = 'max_tokens' === ( $data['stop_reason'] ?? '' );
		$nudged = false;
		$tp     = ( 'analyse' === $state && ! $cut ) ? csa_text_plan( $said ) : null;
		if ( $tp ) {
			// The model wrote its plan as plain text: show the real plan card with Approve / Skip buttons.
			$ctx['items'] = array_values( array_filter( $ctx['items'], function ( $it ) { return 'bot' !== $it['r']; } ) );
			if ( '' !== $tp['intro'] ) {
				$ctx['items'][] = array( 'r' => 'bot', 't' => $tp['intro'] );
			}
			$ctx['items'][] = array( 'r' => 'plan', 's' => $tp['summary'], 'steps' => $tp['steps'], 'pending' => true );
			csa_mem_track( $ctx['mem'], 'propose_plan', array( 'summary' => $tp['summary'], 'steps' => $tp['steps'] ), array() );
			$state = 'planned';
		} elseif ( $cut ) {
			if ( 'approved' === $state && csa_auto_next_count( $messages ) < 3 ) {
				$ctx['items'][] = array( 'r' => 'sys', 't' => csa_t( 'The reply was cut off; continuing in a shorter way…' ) );
				csa_push_user( $messages, array( array( 'type' => 'text', 'text' => '[[NEXT]] Your last reply was cut off. Continue the current approved step with shorter output: use tools directly, do not write long text.' ) ) );
				$nudged = true;
				$done   = false;
			} else {
				$ctx['items'][] = array( 'r' => 'bot', 't' => csa_t( '(The reply was cut off. Type "continue" and I will go on in smaller pieces.)' ) );
			}
		}
		if ( 'all' === csa_job_mode( $ctx['mem'] ) && 'approved' === $state && csa_build_ticked( $messages ) && ! preg_match( '/[?？]\s*$/u', $said ) && csa_auto_next_count( $messages ) < ( ! empty( $ctx['mem']['guided'] ) ? 40 : 8 ) ) {
			// The model stopped but approved steps are still open: hand it the next one (one job at a time) instead of ending early.
			$todo = array();
			foreach ( (array) $ctx['mem']['steps'] as $i => $st ) {
				if ( 'todo' === $st['s'] ) {
					$todo[ $i ] = $st;
				}
			}
			if ( $todo ) {
				$n = array_key_first( $todo );
				csa_push_user( $messages, array( array( 'type' => 'text', 'text' => '[[NEXT]] Approved step ' . ( $n + 1 ) . ' is still open: ' . mb_substr( $todo[ $n ]['t'], 0, 200 ) . ' Do exactly this one step now, then tick it with step_done. Steps marked LATER stay untouched. If you cannot do it, say why in one sentence.' ) ) );
				$nudged = true;
				$done   = false;
			}
		}
		if ( 'approved' === $state && ! $nudged ) {
			$state = 'analyse'; // Build finished.
			if ( ! csa_build_ticked( $messages ) ) {
				// The model never ticked steps: assume the approved ones are done.
				foreach ( $ctx['mem']['steps'] as &$st ) {
					if ( 'todo' === $st['s'] ) {
						$st['s'] = 'done';
					}
				}
				unset( $st );
			}
			$rest = csa_rest_item( $ctx['mem'] );
			if ( $rest ) {
				$ctx['items'][] = $rest;
			}
		}
	}

	$saved = csa_chat_save( $chat_id, $messages, $title, $state, $ctx['pages'] );
	if ( $saved ) {
		csa_mem_save( $saved, $ctx['mem'] );
	}
	if ( ! $saved ) {
		csa_step_release(); wp_send_json_error( csa_t( 'Could not save the chat. Deactivate and activate the plugin once to create its database tables.' ) );
	}
	if ( ! $chat_id && $saved && ! empty( $GLOBALS['csa_last_cost'] ) ) {
		$cc           = get_option( 'csa_chatcost', array() );
		$cc           = is_array( $cc ) ? $cc : array();
		$cc[ $saved ] = ( $cc[ $saved ] ?? 0 ) + $GLOBALS['csa_last_cost'];
		update_option( 'csa_chatcost', array_slice( $cc, -300, null, true ), false );
	}
	if ( $rid_key ) {
		set_transient( $rid_key, $saved, 30 * MINUTE_IN_SECONDS );
	}
	foreach ( csa_take_alerts() as $a ) {
		$ctx['items'][] = array( 'r' => 'alert', 't' => $a );
	}
	$used = csa_model( $data['_model'] ?? '' );
	csa_step_release(); wp_send_json_success( array( 'chat_id' => $saved, 'title' => $title, 'done' => $done, 'items' => $ctx['items'], 'state' => $state, 'model' => $used ? $used['label'] : '', 'kind' => $ctx['mem']['kind'] ?? 'chat', 'progress' => ( ! $done && 'approved' === $state ) ? csa_progress_text( $ctx['mem'] ) : '' ) );
}

/** "Step 2: Do X" / "2. Do X" → "Do X" (the cards add their own numbers). */
function csa_clean_step( $t ) {
	return trim( preg_replace( '/^\s*(?:(?:step|ধাপ)\s*\d+|\d+)\s*[:.\-–)]\s*/iu', '', (string) $t ) );
}

/** How many automatic [[NEXT]] hand-overs happened since the person last wrote. */
function csa_auto_next_count( $messages ) {
	$n = 0;
	for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
		if ( ! csa_is_human_turn( $messages[ $i ] ) ) {
			continue;
		}
		$c    = $messages[ $i ]['content'];
		$text = is_string( $c ) ? $c : implode( ' ', wp_list_pluck( array_filter( (array) $c, function ( $b ) { return 'text' === ( $b['type'] ?? '' ); } ), 'text' ) );
		if ( 0 !== strpos( ltrim( $text ), '[[NEXT]]' ) ) {
			break;
		}
		$n++;
	}
	return $n;
}

/** "Step 3 of 7: …" for the progress line while an approved plan is being built. */
function csa_progress_text( $mem ) {
	$steps = (array) ( $mem['steps'] ?? array() );
	$total = 0;
	$done  = 0;
	$cur   = '';
	foreach ( $steps as $st ) {
		if ( 'later' === $st['s'] || 'skipped' === $st['s'] ) {
			continue;
		}
		$total++;
		if ( 'done' === $st['s'] ) {
			$done++;
		} elseif ( '' === $cur ) {
			$cur = $st['t'];
		}
	}
	if ( ! $total ) {
		return '';
	}
	return sprintf( csa_t( 'Job step %1$d of %2$d' ), min( $total, $done + 1 ), $total ) . ( '' !== $cur ? ': ' . mb_substr( $cur, 0, 70 ) : '' );
}

/** Approve or cancel a pending plan. */
add_action( 'wp_ajax_csa_plan', 'csa_ajax_plan' );
function csa_ajax_plan() {
	csa_guard( 'chat' );
	$row = csa_chat_get( (int) ( $_POST['chat_id'] ?? 0 ) );
	$d   = sanitize_key( $_POST['decision'] ?? '' );
	if ( ! $row ) {
		wp_send_json_error( csa_t( 'There is no plan waiting for approval.' ) );
	}
	$id  = (int) $row['id'];
	$mem = csa_mem_load( $id );

	if ( 'skip' === $d ) {
		// Skip the next open step: it will not be done and is not asked about again.
		$rest = csa_mem_remaining( $mem );
		if ( 'analyse' !== $row['state'] || ! $rest ) {
			wp_send_json_error( csa_t( 'Nothing is left to do from the last plan.' ) );
		}
		$first                    = array_key_first( $rest );
		$mem['steps'][ $first ]['s'] = 'skipped';
		csa_mem_save( $id, $mem );
		csa_log( csa_t( 'Step skipped in chat:' ) . ' ' . $mem['steps'][ $first ]['t'] );
		$items   = array();
		$items[] = array( 'r' => 'sys', 't' => '⏭ ' . csa_t( 'Skipped' ) . ': ' . mb_substr( $mem['steps'][ $first ]['t'], 0, 120 ) );
		$more    = csa_rest_item( $mem );
		$items[] = $more ? $more : array( 'r' => 'sys', 't' => '✔ ' . csa_t( 'No steps left in this plan.' ) );
		wp_send_json_success( array( 'state' => 'analyse', 'run' => false, 'items' => $items ) );
	}

	if ( 'images' === $d ) {
		// Place the uploaded photos in the best spots of the page (one synthetic approved step).
		$pid = (int) ( $_POST['post_id'] ?? 0 );
		$p   = csa_valid_post( $pid );
		if ( ! $p || 'planned' === $row['state'] ) {
			wp_send_json_error( csa_t( 'Page not found.' ) );
		}
		$lines = array();
		foreach ( csa_slots_get( $pid ) as $s ) {
			if ( 'uploaded' === $s['status'] && $s['attachment'] ) {
				$lines[] = '- slot "' . $s['slot'] . '" (wanted position: ' . $s['position'] . '; ' . $s['description'] . '), image ID ' . $s['attachment'] . ', alt "' . $s['alt'] . '", caption "' . $s['caption'] . '". Ready HTML: ' . ( 'featured' === $s['slot'] ? '(featured image is already set)' : str_replace( "\n", ' ', csa_image_html( $s['attachment'], $s['caption'] ) ) );
			}
		}
		if ( ! $lines ) {
			wp_send_json_error( csa_t( 'Upload at least one photo first.' ) );
		}
		$mem['steps'] = array( array( 't' => sprintf( 'Place the uploaded images on page #%d in the best positions', $pid ), 's' => 'todo' ) );
		$mem['plan']  = 'Place uploaded images';
		$text  = CSA_APPROVED . "\nTask: place these uploaded images on page ID " . $pid . ' ("' . $p->post_title . '") in the best possible positions (next to the text they illustrate; hero near the top; never between a heading and its first paragraph). Read the page first (get_elementor_html / read_html / page_outline). Use the ready HTML exactly (keep alt text and class). For an Elementor page put the image into a suitable widget or text-editor/HTML widget with edit_elementor_html; for a normal page use edit_content. Do not change any other text. Then call step_done and tell the user in 1-2 sentences where each image went.' . "\n" . implode( "\n", $lines );
		$state = 'approved';
		$log   = csa_t( 'Image placement approved in chat:' );
	} elseif ( 'rest' === $d || 'next' === $d ) {
		// Do the steps that were left for later (or not finished) now: all of them, or only the next one.
		$rest = csa_mem_remaining( $mem );
		if ( 'analyse' !== $row['state'] || ! $rest ) {
			wp_send_json_error( csa_t( 'Nothing is left to do from the last plan.' ) );
		}
		$lines = array();
		$later = array();
		$first = array_key_first( $rest );
		foreach ( $rest as $i => $st ) {
			if ( 'next' === $d && $i !== $first ) {
				$mem['steps'][ $i ]['s'] = 'later';
				$later[]                 = ( $i + 1 ) . '. ' . $st['t'];
				continue;
			}
			$mem['steps'][ $i ]['s'] = 'todo';
			$lines[]                 = ( $i + 1 ) . '. ' . $st['t'];
		}
		$text  = CSA_APPROVED . "\nThe user now wants the remaining steps done:\n" . implode( "\n", $lines );
		if ( $later ) {
			$text = CSA_APPROVED . "\nThe user now wants ONLY this next step done:\n" . implode( "\n", $lines ) . "\nLeft for LATER (do not do them now):\n" . implode( "\n", $later );
		}
		$state = 'approved';
		$log   = csa_t( 'Remaining steps approved in chat:' );
	} else {
		if ( 'planned' !== $row['state'] ) {
			wp_send_json_error( csa_t( 'There is no plan waiting for approval.' ) );
		}
		$approve = 'approve' === $d;
		$text    = $approve ? CSA_APPROVED : CSA_CANCELLED;
		$state   = $approve ? 'approved' : 'analyse';
		$log     = $approve ? csa_t( 'Plan approved in chat:' ) : csa_t( 'Plan cancelled in chat:' );
		if ( $approve && isset( $_POST['pick'] ) && $mem['steps'] ) {
			$pick = array_filter( array_map( 'intval', explode( ',', (string) $_POST['pick'] ) ) );
			if ( ! $pick ) {
				wp_send_json_error( csa_t( 'Tick at least one step to build.' ) );
			}
			$now = array();
			$lat = array();
			foreach ( $mem['steps'] as $i => $st ) {
				$on                      = in_array( $i + 1, $pick, true );
				$mem['steps'][ $i ]['s'] = $on ? 'todo' : 'later';
				if ( $on ) {
					$now[] = ( $i + 1 ) . '. ' . $st['t'];
				} else {
					$lat[] = ( $i + 1 ) . '. ' . $st['t'];
				}
			}
			if ( $lat ) {
				$text .= "\nDo ONLY these steps now:\n" . implode( "\n", $now ) . "\nLeft for LATER (do not do them now):\n" . implode( "\n", $lat );
			}
		} elseif ( ! $approve ) {
			$mem['steps'] = array();
		}
	}
	$messages = $row['messages'];
	csa_push_user( $messages, array( array( 'type' => 'text', 'text' => $text ) ) );
	csa_chat_save( $id, $messages, $row['title'], $state, array() );
	csa_mem_save( $id, $mem );
	csa_log( $log . ' ' . $row['title'] );
	wp_send_json_success( array( 'state' => $state, 'run' => 'approved' === $state ) );
}

/* ================================================================== */
/* AJAX: chat list, load, rename, pin, delete, images, prompts         */
/* ================================================================== */

add_action( 'wp_ajax_csa_chats', 'csa_ajax_chats' );
function csa_ajax_chats() {
	csa_guard( 'chat' );
	global $wpdb;
	$t     = csa_table( 'chats' );
	$where = current_user_can( 'manage_options' ) ? '1=1' : $wpdb->prepare( 'user_id = %d', get_current_user_id() );
	$q     = trim( sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) ) );
	if ( '' !== $q ) {
		$like   = '%' . $wpdb->esc_like( $q ) . '%';
		$where .= $wpdb->prepare( ' AND (title LIKE %s OR messages LIKE %s)', $like, $like );
	}
	$page = (int) ( $_POST['page_id'] ?? 0 );
	if ( $page ) {
		$where .= $wpdb->prepare( ' AND pages LIKE %s', '%,' . $page . ',%' );
	}
	$sec = sanitize_key( $_POST['section'] ?? '' );
	$col = csa_chats_has_section();
	if ( '' !== $sec && $col ) {
		$where .= $wpdb->prepare( ' AND section = %s', $sec );
	}
	$rows  = $wpdb->get_results( 'SELECT id, title, pinned, updated' . ( $col ? ', section' : '' ) . " FROM $t WHERE $where ORDER BY pinned DESC, updated DESC LIMIT 200", ARRAY_A );
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
	wp_send_json_success( array( 'chats' => $rows ? $rows : array(), 'total' => $total, 'limit' => (int) csa_opt( 'history_limit' ) ) );
}

add_action( 'wp_ajax_csa_chat_load', 'csa_ajax_chat_load' );
function csa_ajax_chat_load() {
	csa_guard( 'chat' );
	$row = csa_chat_get( (int) ( $_POST['id'] ?? 0 ) );
	if ( ! $row ) {
		wp_send_json_error( csa_t( 'Chat not found.' ) );
	}
	wp_send_json_success(
		array(
			'id'     => (int) $row['id'],
			'title'  => $row['title'],
			'pinned' => (int) $row['pinned'],
			'state'  => $row['state'],
			'kind'   => csa_mem_load( (int) $row['id'] )['kind'] ?? '',
			'items'  => csa_load_items( $row ),
		)
	);
}

function csa_load_items( $row ) {
	$items = csa_display_items( $row['messages'], $row['state'] );
	if ( 'planned' === $row['state'] && ! in_array( 'plan', wp_list_pluck( $items, 'r' ), true ) ) {
		$m = csa_mem_load( (int) $row['id'] );
		if ( ! empty( $m['steps'] ) ) {
			$items[] = array( 'r' => 'plan', 's' => $m['plan'], 'steps' => wp_list_pluck( $m['steps'], 't' ), 'pending' => true );
		}
	}
	if ( 'analyse' === $row['state'] ) {
		$rest = csa_rest_item( csa_mem_load( (int) $row['id'] ) );
		if ( $rest ) {
			$items[] = $rest;
		}
	}
	return $items;
}

/** Did the agent call step_done since the last plan approval? */
function csa_build_ticked( $messages ) {
	for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
		$m = $messages[ $i ];
		foreach ( is_array( $m['content'] ) ? $m['content'] : array() as $b ) {
			if ( 'tool_use' === ( $b['type'] ?? '' ) && 'step_done' === $b['name'] ) {
				return true;
			}
			if ( 'text' === ( $b['type'] ?? '' ) && 0 === strpos( $b['text'], '[[PLAN APPROVED]]' ) ) {
				return false;
			}
		}
	}
	return false;
}

add_action( 'wp_ajax_csa_chat_update', 'csa_ajax_chat_update' );
function csa_ajax_chat_update() {
	csa_guard( 'chat' );
	global $wpdb;
	$row = csa_chat_get( (int) ( $_POST['id'] ?? 0 ) );
	if ( ! $row ) {
		wp_send_json_error( csa_t( 'Chat not found.' ) );
	}
	$do = sanitize_key( $_POST['do'] ?? '' );
	if ( 'delete' === $do ) {
		csa_chat_delete_row( (int) $row['id'] );
	} elseif ( 'pin' === $do ) {
		$wpdb->update( csa_table( 'chats' ), array( 'pinned' => $row['pinned'] ? 0 : 1 ), array( 'id' => $row['id'] ) );
	} elseif ( 'rename' === $do ) {
		$wpdb->update( csa_table( 'chats' ), array( 'title' => mb_substr( sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ), 0, 190 ) ), array( 'id' => $row['id'] ) );
	}
	wp_send_json_success();
}

/** Receive a browser-resized image (data URL) for the chat. */
add_action( 'wp_ajax_csa_chat_upload', 'csa_ajax_chat_upload' );
function csa_ajax_chat_upload() {
	csa_guard( 'chat' );
	$data = (string) wp_unslash( $_POST['data'] ?? '' );
	if ( ! preg_match( '#^data:image/(jpeg|png|webp);base64,#', $data, $m ) ) {
		wp_send_json_error( csa_t( 'Unsupported image.' ) );
	}
	$bin = base64_decode( substr( $data, strpos( $data, ',' ) + 1 ), true );
	if ( ! $bin || strlen( $bin ) > 6 * MB_IN_BYTES || ! @getimagesizefromstring( $bin ) ) {
		wp_send_json_error( csa_t( 'Unsupported image.' ) );
	}
	$ext  = 'jpeg' === $m[1] ? 'jpg' : $m[1];
	$file = md5( $bin . microtime() ) . '.' . $ext;
	file_put_contents( csa_private_dir() . '/' . $file, $bin );
	wp_send_json_success( array( 'file' => $file, 'url' => csa_img_url( $file ) ) );
}

/** Serve a private chat image to logged-in users with chat access. */
add_action( 'wp_ajax_csa_img', 'csa_ajax_img' );
function csa_ajax_img() {
	if ( ! check_ajax_referer( 'csa', 'nonce', false ) || ! csa_can( 'chat' ) ) {
		status_header( 403 );
		exit;
	}
	$f = basename( (string) ( $_GET['file'] ?? '' ) );
	$p = csa_private_dir() . '/' . $f;
	if ( ! preg_match( '/^[a-f0-9]{32}\.(jpg|png|webp)$/', $f ) || ! file_exists( $p ) ) {
		status_header( 404 );
		exit;
	}
	header( 'Content-Type: ' . wp_check_filetype( $p )['type'] );
	header( 'Cache-Control: private, max-age=86400' );
	readfile( $p );
	exit;
}

/* Saved prompts (shared across the site) */

function csa_prompts() {
	$p = get_option( 'csa_prompts', array() );
	return is_array( $p ) ? $p : array();
}

add_action( 'wp_ajax_csa_prompt', 'csa_ajax_prompt' );
function csa_ajax_prompt() {
	csa_guard( 'chat' );
	$p = csa_prompts();
	if ( 'delete' === ( $_POST['do'] ?? '' ) ) {
		$id = sanitize_key( $_POST['id'] ?? '' );
		$p  = array_values( array_filter( $p, function ( $x ) use ( $id ) { return $x['id'] !== $id; } ) );
	} else {
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$text = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
		if ( '' === $name || '' === $text ) {
			wp_send_json_error( csa_t( 'Name and text are required.' ) );
		}
		$p[] = array( 'id' => substr( md5( uniqid( '', true ) ), 0, 8 ), 'name' => mb_substr( $name, 0, 40 ), 'text' => $text );
		$p   = array_slice( $p, -30 );
	}
	update_option( 'csa_prompts', $p, false );
	wp_send_json_success( $p );
}
