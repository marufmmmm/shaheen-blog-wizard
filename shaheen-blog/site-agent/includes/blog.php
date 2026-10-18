<?php
/**
 * Blog Studio: keyword queue -> AI plan -> approve -> section-by-section writing -> draft with SEO,
 * FAQ schema and image slots -> schedule. Plus topic ideas and a content calendar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function csa_job_get( $id ) {
	global $wpdb;
	$j = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . csa_table( 'jobs' ) . ' WHERE id = %d', $id ), ARRAY_A );
	if ( ! $j ) {
		return null;
	}
	foreach ( array( 'settings', 'plan', 'content' ) as $k ) {
		$j[ $k ] = json_decode( (string) $j[ $k ], true );
		if ( ! is_array( $j[ $k ] ) ) {
			$j[ $k ] = array();
		}
	}
	return $j;
}

function csa_job_update( $id, $fields ) {
	global $wpdb;
	foreach ( array( 'settings', 'plan', 'content' ) as $k ) {
		if ( isset( $fields[ $k ] ) && is_array( $fields[ $k ] ) ) {
			$fields[ $k ] = wp_json_encode( $fields[ $k ], CSA_JSON );
		}
	}
	$fields['updated'] = current_time( 'mysql' );
	return $wpdb->update( csa_table( 'jobs' ), $fields, array( 'id' => (int) $id ) );
}

function csa_job_public( $j ) {
	return array(
		'id'          => (int) $j['id'],
		'keyword'     => $j['keyword'],
		'description' => $j['description'],
		'settings'    => $j['settings'],
		'plan'        => $j['plan'],
		'status'      => $j['status'],
		'parts_done'  => count( (array) ( $j['content']['parts'] ?? array() ) ),
		'post_id'     => (int) $j['post_id'],
		'edit'        => $j['post_id'] ? get_edit_post_link( $j['post_id'], 'raw' ) : '',
		'preview'     => $j['post_id'] ? get_preview_post_link( $j['post_id'] ) : '',
		'post_status' => $j['post_id'] ? get_post_status( $j['post_id'] ) : '',
		'post_date'   => $j['post_id'] ? get_the_date( 'Y-m-d H:i', $j['post_id'] ) : '',
		'updated'     => $j['updated'],
	);
}

function csa_blog_settings( $in ) {
	$in = is_array( $in ) ? $in : array();
	return array(
		'type'     => ( ( $in['type'] ?? '' ) === 'page' ) ? 'page' : 'post',
		'lang'     => in_array( $in['lang'] ?? '', array( 'Bangla', 'English', 'Bangla + English' ), true ) ? $in['lang'] : 'Bangla',
		'length'   => in_array( $in['length'] ?? '', array( 'short', 'medium', 'long' ), true ) ? $in['length'] : 'medium',
		'tone'     => sanitize_text_field( $in['tone'] ?? 'friendly and informative' ),
		'category' => (int) ( $in['category'] ?? 0 ),
		'audience' => sanitize_text_field( $in['audience'] ?? 'parents and students' ),
	);
}

function csa_blog_words( $s ) {
	return array( 'short' => 600, 'medium' => 1200, 'long' => 2000 )[ $s['length'] ] ?? 1200;
}

function csa_blog_site_pages() {
	$rows = array();
	foreach ( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 60, 'orderby' => 'modified' ) ) as $p ) {
		$rows[] = '- ' . $p->post_title . ' | ' . get_permalink( $p );
	}
	return implode( "\n", $rows );
}

function csa_blog_writer_system( $s ) {
	return 'You are an expert SEO blog writer. ' . csa_site_context()
		. "\n\nWrite in " . $s['lang'] . '. Tone: ' . $s['tone'] . '. Audience: ' . $s['audience'] . '. Write naturally for people first, with keywords used naturally (no stuffing). Accurate and useful; do not invent statistics, prices, dates or phone numbers—use only facts from the site instructions/notes, or write general guidance.';
}

/* ================================================================== */
/* AJAX router                                                         */
/* ================================================================== */

add_action( 'wp_ajax_csa_blog', 'csa_ajax_blog' );
function csa_ajax_blog() {
	csa_guard( 'blog' );
	global $wpdb;
	$do = sanitize_key( $_POST['do'] ?? '' );
	$id = (int) ( $_POST['id'] ?? 0 );
	$t  = csa_table( 'jobs' );

	switch ( $do ) {

		case 'list':
			$rows = $wpdb->get_results( "SELECT * FROM $t ORDER BY FIELD(status,'writing','approved','planned','new','built'), updated DESC LIMIT 200", ARRAY_A );
			$out  = array();
			foreach ( (array) $rows as $r ) {
				$out[] = csa_job_public( csa_job_get( (int) $r['id'] ) );
			}
			wp_send_json_success( $out );

		case 'add':
			$settings = csa_blog_settings( json_decode( wp_unslash( $_POST['settings'] ?? '{}' ), true ) );
			$lines    = preg_split( '/\r?\n/', (string) wp_unslash( $_POST['items'] ?? '' ) );
			$added    = 0;
			foreach ( $lines as $line ) {
				$parts = array_map( 'trim', explode( '|', $line, 2 ) );
				if ( '' === $parts[0] ) {
					continue;
				}
				$wpdb->insert(
					$t,
					array(
						'user_id'     => get_current_user_id(),
						'keyword'     => mb_substr( sanitize_text_field( $parts[0] ), 0, 250 ),
						'description' => sanitize_textarea_field( $parts[1] ?? '' ),
						'settings'    => wp_json_encode( $settings, CSA_JSON ),
						'plan'        => '{}',
						'content'     => '{}',
						'status'      => 'new',
						'created'     => current_time( 'mysql' ),
						'updated'     => current_time( 'mysql' ),
					)
				);
				$added++;
			}
			wp_send_json_success( array( 'added' => $added ) );

		case 'delete':
			$wpdb->delete( $t, array( 'id' => $id ) );
			wp_send_json_success();

		case 'plan':
			$j = csa_job_get( $id );
			if ( ! $j ) {
				wp_send_json_error( csa_t( 'Item not found.' ) );
			}
			$s      = $j['settings'];
			$words  = csa_blog_words( $s );
			$system = csa_blog_writer_system( $s )
				. "\n\nExisting pages (for internal links):\n" . csa_blog_site_pages()
				. "\n\nCreate a content PLAN (not the article) for a " . $s['type'] . " of about $words words. Return ONLY JSON:"
				. "\n{\"titles\": [3 SEO title options, max 60 chars], \"title\": \"best of them\", \"slug\": \"english-lowercase-hyphen-slug\", \"primary_keyword\": \"\", \"secondary_keywords\": [\"\"], \"search_intent\": \"\", \"outline\": [{\"h2\": \"\", \"points\": [\"what this section covers\"]}], \"image_slots\": [{\"slot\": \"featured|img-1|img-2\", \"after_section\": 0, \"description\": \"what photo to upload\", \"size\": \"1200x630\", \"alt\": \"SEO alt text\", \"filename\": \"seo-file-name\", \"caption\": \"\"}], \"internal_links\": [{\"anchor\": \"\", \"url\": \"one of the existing page URLs\"}], \"key_takeaways\": [\"3-5 short takeaway bullets\"], \"faq\": [\"question\"], \"meta_title\": \"max 60 chars\", \"meta_description\": \"120-155 chars\", \"tags\": [\"\"]}"
				. "\nRules: 4-7 outline sections; 2-4 image slots including one \"featured\" (after_section -1); after_section = index of the section the image follows (0 = after the introduction); 3-5 FAQ questions people actually ask; only use internal link URLs from the list.";
			$data   = csa_claude(
				array( array( 'role' => 'user', 'content' => 'Keyword: ' . $j['keyword'] . "\nDescription / goal: " . ( $j['description'] ? $j['description'] : '(none)' ) ) ),
				$system,
				array( 'max_tokens' => csa_eco_val( 2000, 3000 ), 'task' => 'blog', 'feature' => 'Blog plan' )
			);
			if ( is_wp_error( $data ) ) {
				wp_send_json_error( $data->get_error_message() );
			}
			$plan = csa_json( csa_text_of( $data ) );
			if ( ! is_array( $plan ) || empty( $plan['outline'] ) ) {
				wp_send_json_error( csa_t( 'Could not read the plan. Please try again.' ) );
			}
			csa_job_update( $id, array( 'plan' => $plan, 'status' => 'planned', 'content' => array() ) );
			csa_log( csa_t( 'Blog plan created:' ) . ' ' . $j['keyword'] );
			wp_send_json_success( array( 'job' => csa_job_public( csa_job_get( $id ) ), 'alerts' => csa_take_alerts() ) );

		case 'save_plan':
			$j = csa_job_get( $id );
			if ( ! $j || empty( $j['plan'] ) ) {
				wp_send_json_error( csa_t( 'Item not found.' ) );
			}
			$plan                     = $j['plan'];
			$plan['title']            = sanitize_text_field( wp_unslash( $_POST['title'] ?? $plan['title'] ) );
			$plan['meta_title']       = sanitize_text_field( wp_unslash( $_POST['meta_title'] ?? $plan['meta_title'] ) );
			$plan['meta_description'] = sanitize_text_field( wp_unslash( $_POST['meta_description'] ?? $plan['meta_description'] ) );
			$outline_txt              = (string) wp_unslash( $_POST['outline'] ?? '' );
			if ( '' !== trim( $outline_txt ) ) {
				$outline = array();
				foreach ( preg_split( '/\r?\n/', $outline_txt ) as $line ) {
					$line = trim( $line );
					if ( preg_match( '/^#{1,3}\s*(.+)$/u', $line, $m ) ) {
						$outline[] = array( 'h2' => sanitize_text_field( $m[1] ), 'points' => array() );
					} elseif ( preg_match( '/^[-*•]\s*(.+)$/u', $line, $m ) && $outline ) {
						$outline[ count( $outline ) - 1 ]['points'][] = sanitize_text_field( $m[1] );
					}
				}
				if ( $outline ) {
					$plan['outline'] = $outline;
				}
			}
			$settings = $j['settings'];
			if ( isset( $_POST['category'] ) ) {
				$settings['category'] = (int) $_POST['category'];
			}
			csa_job_update( $id, array( 'plan' => $plan, 'settings' => $settings ) );
			wp_send_json_success( csa_job_public( csa_job_get( $id ) ) );

		case 'approve':
			$j = csa_job_get( $id );
			if ( ! $j || empty( $j['plan'] ) ) {
				wp_send_json_error( csa_t( 'Create a plan first.' ) );
			}
			csa_job_update( $id, array( 'status' => 'approved', 'content' => array( 'parts' => array() ) ) );
			csa_log( csa_t( 'Blog plan approved:' ) . ' ' . ( $j['plan']['title'] ?? $j['keyword'] ) );
			wp_send_json_success( array( 'steps' => count( $j['plan']['outline'] ) + 2 ) );

		case 'write':
			csa_blog_write_part( $id, (int) ( $_POST['part'] ?? 0 ) );
			break;

		case 'finish':
			csa_blog_finish( $id );
			break;

		case 'schedule':
			$j = csa_job_get( $id );
			if ( ! $j || ! $j['post_id'] ) {
				wp_send_json_error( csa_t( 'Build the post first.' ) );
			}
			$when = sanitize_text_field( wp_unslash( $_POST['when'] ?? '' ) );
			$ts   = strtotime( $when );
			if ( ! $ts || $ts <= current_time( 'timestamp' ) ) {
				wp_send_json_error( csa_t( 'Choose a future date and time.' ) );
			}
			$date = gmdate( 'Y-m-d H:i:s', $ts );
			$undo = csa_snapshot_post( $j['post_id'] );
			wp_update_post( array( 'ID' => $j['post_id'], 'post_status' => 'future', 'post_date' => $date, 'post_date_gmt' => get_gmt_from_date( $date ), 'edit_date' => true ) );
			csa_log( csa_t( 'Scheduled' ) . ' "' . get_the_title( $j['post_id'] ) . '" ' . $date, $undo );
			wp_send_json_success( csa_job_public( csa_job_get( $id ) ) );

		case 'next_slot':
			wp_send_json_success( array( 'when' => csa_next_slot() ) );

		case 'ideas':
			$titles = implode( "\n", array_map( function ( $p ) { return '- ' . $p->post_title; }, get_posts( array( 'post_type' => 'post', 'post_status' => array( 'publish', 'future', 'draft' ), 'numberposts' => 60 ) ) ) );
			$system = 'You are a content strategist for a website in Bangladesh. ' . csa_site_context()
				. "\n\nExisting posts (avoid duplicates):\n" . $titles
				. "\n\nSuggest " . csa_eco_val( 8, 10 ) . " blog post ideas that would bring search traffic and help the site's goals now (consider the current month, the Bangladesh academic calendar: admissions, exams, results, holidays, and gaps in existing content)."
				. "\nReturn ONLY JSON array: [{\"keyword\": \"main keyword\", \"description\": \"what the post should cover (1-2 sentences)\", \"language\": \"Bangla|English\", \"why\": \"short reason\"}]";
			$data   = csa_claude( array( array( 'role' => 'user', 'content' => 'Suggest ideas. Focus: ' . sanitize_text_field( wp_unslash( $_POST['focus'] ?? 'anything useful' ) ) ) ), $system, array( 'max_tokens' => csa_eco_val( 1500, 2500 ), 'task' => 'bulk', 'feature' => 'Blog ideas' ) );
			if ( is_wp_error( $data ) ) {
				wp_send_json_error( $data->get_error_message() );
			}
			$list = csa_json( csa_text_of( $data ) );
			if ( ! is_array( $list ) ) {
				wp_send_json_error( csa_t( 'Could not read the result. Please try again.' ) );
			}
			wp_send_json_success( array( 'items' => $list, 'alerts' => csa_take_alerts() ) );
	}
	wp_send_json_error( 'Bad request' );
}

/** Next free publishing slot (setting: weekday + time), skipping days that already have a scheduled post. */
function csa_next_slot() {
	$day  = csa_opt( 'slot_day' );
	$time = preg_match( '/^\d{1,2}:\d{2}$/', csa_opt( 'slot_time' ) ) ? csa_opt( 'slot_time' ) : '10:00';
	$now  = current_time( 'timestamp' );
	$busy = array();
	foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'future', 'numberposts' => 100 ) ) as $p ) {
		$busy[ get_the_date( 'Y-m-d', $p ) ] = 1;
	}
	for ( $i = 0; $i < 400; $i++ ) {
		$ts = strtotime( gmdate( 'Y-m-d', $now + $i * DAY_IN_SECONDS ) . ' ' . $time );
		if ( $ts <= $now ) {
			continue;
		}
		if ( 'daily' !== $day && strtolower( gmdate( 'l', $ts ) ) !== $day ) {
			continue;
		}
		if ( isset( $busy[ gmdate( 'Y-m-d', $ts ) ] ) ) {
			continue;
		}
		return gmdate( 'Y-m-d H:i', $ts );
	}
	return gmdate( 'Y-m-d H:i', $now + DAY_IN_SECONDS );
}

/* ================================================================== */
/* Writing (one part per request to avoid server timeouts)            */
/* ================================================================== */

function csa_blog_write_part( $id, $part ) {
	$j = csa_job_get( $id );
	if ( ! $j || ! in_array( $j['status'], array( 'approved', 'writing' ), true ) ) {
		wp_send_json_error( csa_t( 'Approve the plan first.' ) );
	}
	$plan    = $j['plan'];
	$s       = $j['settings'];
	$outline = array_values( (array) $plan['outline'] );
	$n       = count( $outline );
	$words   = csa_blog_words( $s );
	$per     = (int) round( $words * 0.85 / max( 1, $n ) );
	$links   = wp_json_encode( $plan['internal_links'] ?? array(), CSA_JSON );
	$heads   = implode( ' | ', wp_list_pluck( $outline, 'h2' ) );
	$base    = 'Article title: ' . $plan['title'] . "\nPrimary keyword: " . ( $plan['primary_keyword'] ?? $j['keyword'] ) . "\nSecondary keywords: " . implode( ', ', (array) ( $plan['secondary_keywords'] ?? array() ) ) . "\nAll sections: " . $heads . "\nInternal links you may use (anchor + URL, max one per section, only where natural): " . $links;

	$faq_mode = false;
	if ( 0 === $part ) {
		$task = "Write the INTRODUCTION only (about 90-130 words): hook the reader, state what they will learn, include the primary keyword in the first 2 sentences. No heading.";
	} elseif ( $part <= $n ) {
		$sec  = $outline[ $part - 1 ];
		$task = 'Write SECTION ' . $part . ' only. Start with <h2>' . esc_html( $sec['h2'] ) . '</h2>. Cover: ' . implode( '; ', (array) $sec['points'] ) . ". About $per words. Use <h3> sub-headings, short paragraphs, lists or a simple table where helpful.";
	} else {
		$faq_mode = true;
		$task     = 'Write the CONCLUSION (a short <h2> heading in the article language + 60-100 words with a clear call to action for this website), then an FAQ section wrapped in <div class="csa-faq">: first an <h2> heading, then for each question <details><summary>the question</summary><p>an answer of 40-80 words</p></details>. Questions: ' . implode( ' | ', (array) ( $plan['faq'] ?? array() ) )
			. "\nAfter the HTML, on a new line write ===FAQ_JSON=== and then a JSON array [{\"q\": \"\", \"a\": \"plain text answer\"}].";
	}

	$system = csa_blog_writer_system( $s ) . "\n\nOutput ONLY clean HTML using h2, h3, p, ul, ol, li, strong, em, a, table, thead, tbody, tr, th, td. No h1, no markdown, no <html>/<body>, no images, no inline styles."
		. "\nThe site's post format styles these extra components; use them where they genuinely help, at most two per section:"
		. "\n- <div class=\"csa-tip\">a practical tip</div>"
		. "\n- <div class=\"csa-note\">important information to verify or remember</div>"
		. "\n- <div class=\"csa-warning\">a common mistake or caution</div>"
		. "\nUse a <table> for comparisons, schedules, fee structures or subject lists.";
	$data   = csa_claude( array( array( 'role' => 'user', 'content' => $base . "\n\n" . $task ) ), $system, array( 'max_tokens' => csa_eco_val( 2500, 3500 ), 'task' => 'blog', 'feature' => 'Blog writing' ) );
	if ( is_wp_error( $data ) ) {
		wp_send_json_error( $data->get_error_message() );
	}
	$text = csa_text_of( $data );
	$text = trim( preg_replace( '/^```(?:html)?|```$/m', '', $text ) );
	$faq  = null;
	if ( $faq_mode && false !== strpos( $text, '===FAQ_JSON===' ) ) {
		list( $text, $json ) = array_map( 'trim', explode( '===FAQ_JSON===', $text, 2 ) );
		$faq                 = csa_json( $json );
	}
	$content                   = $j['content'];
	$content['parts'][ $part ] = wp_kses_post( $text );
	if ( is_array( $faq ) ) {
		$content['faq'] = $faq;
	}
	csa_job_update( $id, array( 'content' => $content, 'status' => 'writing' ) );
	wp_send_json_success( array( 'part' => $part, 'total' => $n + 2, 'alerts' => csa_take_alerts() ) );
}

function csa_blog_finish( $id ) {
	$j = csa_job_get( $id );
	if ( ! $j || 'writing' !== $j['status'] ) {
		wp_send_json_error( csa_t( 'The article is not written yet.' ) );
	}
	$plan    = $j['plan'];
	$s       = $j['settings'];
	$parts   = (array) ( $j['content']['parts'] ?? array() );
	$n       = count( (array) $plan['outline'] );
	$missing = array();
	for ( $i = 0; $i <= $n + 1; $i++ ) {
		if ( empty( $parts[ $i ] ) ) {
			$missing[] = $i;
		}
	}
	if ( $missing ) {
		wp_send_json_error( csa_t( 'Some parts are missing. Press Write again to continue.' ) );
	}

	$slots_after = array();
	foreach ( (array) ( $plan['image_slots'] ?? array() ) as $sl ) {
		$after = (int) ( $sl['after_section'] ?? 0 );
		if ( 'featured' !== ( $sl['slot'] ?? '' ) && $after >= 0 ) {
			$slots_after[ $after ][] = sanitize_key( $sl['slot'] );
		}
	}
	$html = '';
	$tk   = array_filter( array_map( 'sanitize_text_field', (array) ( $plan['key_takeaways'] ?? array() ) ) );
	for ( $i = 0; $i <= $n + 1; $i++ ) {
		$html .= $parts[ $i ] . "\n\n";
		if ( 0 === $i && $tk ) {
			$html .= '<div class="csa-takeaways"><p class="csa-takeaways-title">' . esc_html( csa_format_labels()['takeaways'] ) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $tk ) ) . "</li></ul></div>\n\n";
		}
		foreach ( $slots_after[ $i ] ?? array() as $slot ) {
			$html .= '<!-- csa-image-slot:' . $slot . " -->\n\n";
		}
	}

	$post = array(
		'post_type'    => $s['type'],
		'post_title'   => sanitize_text_field( $plan['title'] ),
		'post_name'    => sanitize_title( $plan['slug'] ?? $plan['title'] ),
		'post_content' => $html,
		'post_excerpt' => sanitize_text_field( $plan['meta_description'] ?? '' ),
		'post_status'  => 'draft',
		'post_author'  => csa_author_id(),
	);
	if ( 'post' === $s['type'] ) {
		if ( $s['category'] ) {
			$post['post_category'] = array( (int) $s['category'] );
		}
		$post['tags_input'] = array_map( 'sanitize_text_field', (array) ( $plan['tags'] ?? array() ) );
	}
	$pid = $j['post_id'] && get_post( $j['post_id'] ) ? wp_update_post( array_merge( $post, array( 'ID' => $j['post_id'] ) ), true ) : wp_insert_post( $post, true );
	if ( is_wp_error( $pid ) ) {
		wp_send_json_error( $pid->get_error_message() );
	}
	$keys = csa_seo_keys();
	if ( $keys ) {
		update_post_meta( $pid, $keys['title'], sanitize_text_field( $plan['meta_title'] ?? '' ) );
		update_post_meta( $pid, $keys['description'], sanitize_text_field( $plan['meta_description'] ?? '' ) );
		update_post_meta( $pid, $keys['keyword'], sanitize_text_field( $plan['primary_keyword'] ?? $j['keyword'] ) );
	}
	$faq = array();
	foreach ( (array) ( $j['content']['faq'] ?? array() ) as $f ) {
		if ( ! empty( $f['q'] ) && ! empty( $f['a'] ) ) {
			$faq[] = array( 'q' => sanitize_text_field( $f['q'] ), 'a' => sanitize_textarea_field( $f['a'] ) );
		}
	}
	update_post_meta( $pid, '_csa_faq', wp_slash( $faq ) );
	update_post_meta( $pid, '_csa_format', 1 );
	csa_slots_save( $pid, (array) ( $plan['image_slots'] ?? array() ) );
	csa_job_update( $id, array( 'status' => 'built', 'post_id' => $pid ) );
	csa_log( csa_t( 'Blog draft created:' ) . ' #' . $pid . ' ' . get_the_title( $pid ), array( 'type' => 'created', 'post' => $pid ) );
	wp_send_json_success( csa_job_public( csa_job_get( $id ) ) );
}
