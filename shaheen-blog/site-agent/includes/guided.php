<?php
/**
 * Guided page job: pick a page or post, the plugin prepares a brief WITHOUT the AI (design audit,
 * SEO facts, text, image slots), the agent asks the owner a few questions, makes ONE plan with
 * everything, and then does all the work. When a limit stops a chat, the job continues in a fresh
 * chat by itself (hand-over). Also: save photos the owner attached in the chat to the Media Library.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CSA_ANSWERS = '[[ANSWERS]]';
const CSA_HANDOFF = '[[HANDOFF]]';

/** Guided jobs do all approved steps in one go; other chats follow the setting. */
function csa_job_mode( $mem ) {
	return ! empty( $mem['guided'] ) ? 'all' : csa_opt( 'job_mode' );
}

/* ------------------------------------------------------------------ */
/* Tools                                                               */
/* ------------------------------------------------------------------ */

function csa_gd_ask_tool() {
	return array(
		array(
			'name'         => 'ask_questions',
			'description'  => 'Ask the owner 3-6 short questions you really need answered before planning (shown as a form; they can pick options, type, or skip). Use once, at the start of a page job.',
			'input_schema' => csa_schema(
				array(
					'intro'     => array( 'type' => 'string', 'description' => 'One short sentence telling the owner why you ask.' ),
					'questions' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'q'       => array( 'type' => 'string' ),
								'options' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Up to 5 ready answers (optional).' ),
								'multi'   => array( 'type' => 'boolean', 'description' => 'More than one option may be chosen.' ),
							),
							'required'   => array( 'q' ),
						),
					),
				),
				array( 'questions' )
			),
		),
	);
}

function csa_gd_write_tool() {
	return array(
			'name'         => 'save_chat_image',
			'description'  => 'Save a photo the owner attached in this chat (see PHOTOS ATTACHED in the memory, use its number n) to the Media Library with an SEO file name and alt text. Returns the attachment id, URL and ready HTML to place it on the page with edit_elementor_html / edit_content, or use set_featured_image.',
			'input_schema' => csa_schema(
				array(
					'n'        => array( 'type' => 'integer', 'description' => 'Photo number from PHOTOS ATTACHED.' ),
					'filename' => array( 'type' => 'string', 'description' => 'SEO file name without extension, lowercase-with-hyphens, with the page keyword.' ),
					'alt'      => array( 'type' => 'string', 'description' => 'Alt text in the page language.' ),
					'caption'  => array( 'type' => 'string' ),
					'id'       => array( 'type' => 'integer', 'description' => 'Page/post the image belongs to.' ),
				),
				array( 'n', 'filename', 'alt' )
			),
	);
}

function csa_gd_save_chat_image( $in, &$ctx, $act ) {
	$n    = (int) ( $in['n'] ?? 0 );
	$list = (array) ( $ctx['mem']['attach'] ?? array() );
	$file = isset( $list[ $n ] ) ? basename( (string) $list[ $n ] ) : '';
	$src  = $file ? csa_private_dir() . '/' . $file : '';
	if ( ! $src || ! file_exists( $src ) ) {
		return array( 'error' => 'Photo number ' . $n . ' was not found. See PHOTOS ATTACHED in the memory.' );
	}
	$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
	$name = sanitize_title( $in['filename'] ?? 'photo' );
	$name = '' !== $name ? $name : 'photo';
	$tmp  = wp_tempnam( $name . '.' . $ext );
	if ( ! $tmp || ! copy( $src, $tmp ) ) {
		return array( 'error' => 'Could not copy the photo.' );
	}
	csa_media_includes();
	$pid = (int) ( $in['id'] ?? 0 );
	$alt = sanitize_text_field( $in['alt'] ?? '' );
	$att = media_handle_sideload( array( 'name' => $name . '.' . $ext, 'tmp_name' => $tmp ), $pid && csa_valid_post( $pid ) ? $pid : 0, null, array( 'post_title' => $alt, 'post_excerpt' => sanitize_text_field( $in['caption'] ?? '' ) ) );
	if ( is_wp_error( $att ) ) {
		@unlink( $tmp );
		return array( 'error' => $att->get_error_message() );
	}
	$path = get_attached_file( $att );
	if ( $path && ! is_wp_error( csa_optimize_image( $path ) ) ) {
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $path ) );
	}
	update_post_meta( $att, '_wp_attachment_image_alt', $alt );
	$act( csa_t( 'Saved photo to the Media Library:' ) . ' ' . $name . '.' . $ext, null, admin_url( 'post.php?post=' . $att . '&action=edit' ) );
	return array(
		'ok'            => true,
		'attachment_id' => $att,
		'url'           => wp_get_attachment_image_url( $att, 'large' ),
		'full_url'      => wp_get_attachment_url( $att ),
		'html'          => str_replace( "\n", ' ', csa_image_html( $att, sanitize_text_field( $in['caption'] ?? '' ) ) ),
	);
}

/* ------------------------------------------------------------------ */
/* Brief (no AI): everything we can know about the page for free       */
/* ------------------------------------------------------------------ */

function csa_gd_goal_labels() {
	return array(
		'design'   => 'Page design and layout',
		'mobile'   => 'Mobile view',
		'fonts'    => 'Fonts, sizes and padding',
		'humanise' => 'Make the text natural and pleasant (humanise)',
		'seo'      => 'SEO: title, description, headings, alt text, schema',
		'images'   => 'Photos: what is needed, alt text, placing them',
		'links'    => 'Internal links',
		'all'      => 'Everything that would improve this page',
	);
}

function csa_gd_brief( $pid, $goals, $note ) {
	$p = csa_valid_post( $pid );
	if ( ! $p ) {
		return '';
	}
	$labels = csa_gd_goal_labels();
	$gl     = array();
	foreach ( (array) $goals as $g ) {
		if ( isset( $labels[ $g ] ) ) {
			$gl[] = $labels[ $g ];
		}
	}
	$l   = array();
	$l[] = 'GUIDED PAGE JOB';
	$l[] = 'Page #' . $p->ID . ' "' . $p->post_title . '" (' . $p->post_type . ', ' . $p->post_status . ', ' . ( csa_is_elementor( $p->ID ) ? 'built with Elementor' : 'normal editor' ) . ') ' . get_permalink( $p ) . ( 'sb_post' === $p->post_type ? ' [made with Blog Write .ai]' : '' );
	$l[] = 'Owner wants: ' . ( $gl ? implode( '; ', $gl ) : $labels['all'] );
	if ( '' !== trim( (string) $note ) ) {
		$l[] = 'Owner note: ' . mb_substr( trim( $note ), 0, 600 );
	}
	$seo = csa_si_seo( $p->ID );
	$txt = csa_si_text( $p );
	$l[] = 'SEO now: title tag "' . $seo['st'] . '" | meta description "' . mb_substr( $seo['sd'], 0, 200 ) . '" | focus keyword "' . $seo['sk'] . '" | ' . count( preg_split( '/\s+/u', $txt, -1, PREG_SPLIT_NO_EMPTY ) ) . ' words';
	$au  = csa_dsg_audit( $p->ID );
	if ( $au ) {
		$rows = array();
		foreach ( array_slice( $au, 0, 12 ) as $f ) {
			$rows[] = '[' . $f['severity'] . '] ' . $f['what'] . ': ' . $f['issue'] . ' (element ' . $f['element'] . ')';
		}
		$l[] = "Design check (" . count( $au ) . " findings, worst first):\n" . implode( "\n", $rows );
	} else {
		$l[] = 'Design check: no automatic findings.';
	}
	$im = csa_slots_missing( $p->ID );
	$l[] = 'Photos: ' . $im['pending'] . ' suggested and not uploaded, ' . $im['skipped'] . ' skipped; ' . count( csa_slots_get( $p->ID ) ) . ' slots in all.';
	$l[] = 'Page text (start): ' . mb_substr( $txt, 0, 1800 );
	return mb_substr( implode( "\n", $l ), 0, 5200 );
}

add_action( 'wp_ajax_csa_job_pages', 'csa_ajax_job_pages' );
function csa_ajax_job_pages() {
	csa_guard( 'chat' );
	$q   = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
	$ids = get_posts(
		array(
			'post_type'      => csa_seo_types(),
			'post_status'    => csa_seo_statuses(),
			'posts_per_page' => 40,
			's'              => $q,
			'orderby'        => '' !== $q ? 'relevance' : 'modified',
			'order'          => 'DESC',
		)
	);
	$pre = (int) ( $_POST['pid'] ?? 0 );
	if ( $pre ) {
		$one = csa_valid_post( $pre );
		if ( $one ) {
			$ids = array_merge( array( $one ), array_filter( $ids, function ( $x ) use ( $pre ) { return $x->ID !== $pre; } ) );
		}
	}
	$out = array();
	foreach ( $ids as $p ) {
		$out[] = array( 'id' => $p->ID, 'title' => $p->post_title ? $p->post_title : '(no title)', 'type' => $p->post_type, 'status' => $p->post_status );
	}
	wp_send_json_success( $out );
}

/** Start a guided job from the "Page job" form: sets up the chat memory. Returns the message to send. */
function csa_gd_start( &$mem, $job ) {
	$pid   = (int) ( $job['id'] ?? 0 );
	$goals = array_map( 'sanitize_key', (array) ( $job['goals'] ?? array() ) );
	$note  = sanitize_textarea_field( $job['note'] ?? '' );
	$brief = csa_gd_brief( $pid, $goals, $note );
	if ( '' === $brief ) {
		return false;
	}
	$mem['guided']  = 1;
	$mem['brief']   = $brief;
	$mem['answers'] = '';
	csa_mem_page( $mem, $pid );
	return true;
}

/* ------------------------------------------------------------------ */
/* Prompt help                                                         */
/* ------------------------------------------------------------------ */

function csa_gd_prompt( $state, $mem ) {
	if ( empty( $mem['guided'] ) ) {
		return '';
	}
	$s = "\n\n## GUIDED PAGE JOB"
		. "\n- The owner started a guided job. The BRIEF in the chat memory already holds the page facts, the design check, the SEO facts and the text start: do not re-read what it says."
		. "\n- Order: (1) ask_questions ONCE; (2) after the answers, read at most 2 things you still need (the brief already has the rest; never search the page many times) (page_outline / get_elementor_html / find_in_html), then propose_plan with EVERY task of the job as numbered concrete steps (design and mobile CSS with set_page_css, text changes as old sentence → new sentence, SEO fields, schema, photos, links); (3) after approval do ALL steps in a row without asking again."
		. "\n- Questions: 3 to 6, short, in the owner's language, only what the brief does not answer: priority and style (colours, tone, formal or warm), what must stay unchanged, which facts or phone numbers to use, and PHOTOS: whether the owner has photos to upload (they attach them with the paperclip in the chat) and where each should go (they may describe it in plain words, for example 'after the principal's message'). Give ready options where possible."
		. "\n- SEO goal is 90+ on seo_score. If the goals include SEO (or 'everything'), the plan MUST start with a step that calls seo_score and END with a step that calls seo_score again; the text steps must keep one consistent tone and the owner's facts. Tell the owner the real before and after score."
		. "\n- Photos: save_chat_image works RIGHT NOW (it only puts the photo in the media library, it does not touch the page), so save every attached photo first, before the plan, one call per photo, then propose_plan for placing them. Do NOT use save_note for photos. Video links (for example Facebook reels) are placed as a responsive embed (iframe) or a clearly labelled link in the best section. Photos: attached photos are listed as PHOTOS ATTACHED. For each one call save_chat_image (SEO file name, alt text in the page language) and then place it where the owner said or in the best spot (next to the text it illustrates), using the returned HTML with edit_elementor_html / edit_content, or set_featured_image. If no photos are available, plan suggest_image_slots so the Images card appears, and say the page is marked 'image upload required' in the SEO section until they are uploaded.";
	if ( 'analyse' === $state && '' === trim( (string) $mem['answers'] ) ) {
		$s .= "\nNOW: call ask_questions (nothing else).";
	}
	return $s;
}

/* ------------------------------------------------------------------ */
/* Hand-over to a fresh chat when a limit stops the work               */
/* ------------------------------------------------------------------ */

/** Create a new chat that continues the approved job. Returns the new chat id (0 on failure). */
function csa_gd_handoff( $chat_id, $title, $mem, $reason ) {
	$left = csa_mem_remaining( $mem );
	if ( ! $left || (int) ( $mem['handoffs'] ?? 0 ) >= 6 ) {
		return 0;
	}
	$done = array();
	foreach ( (array) $mem['steps'] as $i => $st ) {
		if ( 'done' === $st['s'] ) {
			$done[] = ( $i + 1 ) . '. ' . $st['t'];
		}
	}
	$todo = array();
	foreach ( $left as $i => $st ) {
		$todo[] = ( $i + 1 ) . '. ' . $st['t'];
	}
	$text = CSA_APPROVED . "\n" . CSA_HANDOFF . " This is the same job continued in a fresh chat (" . $reason . "). The brief, plan checklist and notes are in the chat memory."
		. ( $done ? "\nAlready DONE (do not repeat):\n" . implode( "\n", $done ) : '' )
		. "\nStill TO DO, in this order (do them all now, tick each with step_done in the same turn as its last tool call; re-read only the small part you need with page_outline / find_in_html):\n" . implode( "\n", $todo );
	$GLOBALS['csa_section'] = $mem['section'] ?? '';
	$new = csa_chat_save( 0, array( array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => $text ) ) ) ), '↪ ' . ltrim( $title, '↪ ' ), 'approved', array_keys( (array) $mem['pages'] ) );
	if ( ! $new ) {
		return 0;
	}
	$m2             = $mem;
	$m2['handoffs'] = (int) ( $mem['handoffs'] ?? 0 ) + 1;
	$m2['fail_run'] = 0;
	$m2['dup_run']  = 0;
	$m2['fail_sigs']  = array();
	$m2['read_sigs']  = array();
	$m2['outlines'] = array();
	$m2['found']    = array();
	csa_mem_save( $new, $m2 );
	csa_log( csa_t( 'Job continued in a new chat:' ) . ' ' . $title );
	return $new;
}

/* ------------------------------------------------------------------ */
/* Recommended models per kind of work                                 */
/* ------------------------------------------------------------------ */

/** Best available model for each task, using only the providers that have a key. */
function csa_gd_recommended() {
	$has = function ( $k ) {
		$m = csa_model( $k );
		return $m && '' !== csa_provider_key( $m['provider'] );
	};
	$pick = function ( $list ) use ( $has ) {
		foreach ( $list as $k ) {
			if ( $has( $k ) ) {
				return $k;
			}
		}
		return '';
	};
	return array(
		'chat'   => array( 'key' => $pick( array( 'sonnet55', 'sonnet', 'kimi', 'haiku55', 'haiku' ) ), 'why' => csa_t( 'Page design, guided jobs, Elementor and CSS fixes: needs the most reliable tool use. Claude Sonnet 5.5 is best; Kimi K2.6 is the cheap alternative.' ) ),
		'blog'   => array( 'key' => $pick( array( 'sonnet55', 'sonnet', 'haiku55', 'haiku', 'kimi' ) ), 'why' => csa_t( 'Bangla writing and humanising text: Claude writes the most natural Bangla.' ) ),
		'seo'    => array( 'key' => $pick( array( 'haiku55', 'haiku', 'kimi', 'sonnet55' ) ), 'why' => csa_t( 'One SEO audit is a short, structured answer: cheap and fast is enough.' ) ),
		'bulk'   => array( 'key' => $pick( array( 'kimi', 'haiku55', 'haiku' ) ), 'why' => csa_t( 'Bulk audits, keywords, weekly audit: many pages, short answers. Put your Kimi balance to work here.' ) ),
		'vision' => array( 'key' => $pick( array( 'kimi', 'haiku55', 'sonnet55', 'haiku' ) ), 'why' => csa_t( 'Alt text and screenshots: any model that can see images; Kimi K2.6 is cheap.' ) ),
	);
}

add_action( 'wp_ajax_csa_apply_models', 'csa_ajax_apply_models' );
function csa_ajax_apply_models() {
	csa_guard( 'admin' );
	$o = get_option( CSA_OPT, array() );
	$o = is_array( $o ) ? $o : array();
	foreach ( csa_gd_recommended() as $task => $r ) {
		if ( $r['key'] ) {
			$o[ 'm_' . $task ] = $r['key'];
		}
	}
	update_option( CSA_OPT, $o );
	wp_send_json_success();
}

/** Limit reached while a plan is being built: continue in a new chat. Sends the reply and exits; false if not possible. */
function csa_gd_handoff_reply( $chat_id, $title, $state, $reason ) {
	if ( 'approved' !== $state || ! $chat_id ) {
		return false;
	}
	$new = csa_gd_handoff( $chat_id, $title, csa_mem_load( $chat_id ), $reason );
	if ( ! $new ) {
		return false;
	}
	csa_step_release();
	wp_send_json_success(
		array(
			'chat_id' => $new,
			'title'   => '↪ ' . ltrim( $title, '↪ ' ),
			'done'    => false,
			'handoff' => true,
			'state'   => 'approved',
			'items'   => array( array( 'r' => 'sys', 't' => '↪ ' . sprintf( csa_t( 'Limit reached (%s). Continuing the same job in a new chat…' ), $reason ) ) ),
			'alerts'  => array(),
		)
	);
	return true;
}

function csa_gd_clean_questions( $in ) {
	$out = array();
	foreach ( array_slice( (array) ( $in['questions'] ?? array() ), 0, 8 ) as $i => $q ) {
		if ( ! is_array( $q ) || '' === trim( (string) ( $q['q'] ?? '' ) ) ) {
			continue;
		}
		$out[] = array(
			'q'       => mb_substr( sanitize_text_field( $q['q'] ), 0, 240 ),
			'options' => array_values( array_filter( array_map( function ( $o ) { return mb_substr( sanitize_text_field( (string) $o ), 0, 120 ); }, array_slice( (array) ( $q['options'] ?? array() ), 0, 6 ) ) ) ),
			'multi'   => ! empty( $q['multi'] ),
		);
	}
	return $out;
}
