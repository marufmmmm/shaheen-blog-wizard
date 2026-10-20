<?php
/**
 * Images: AI-suggested image slots per page (upload -> SEO rename, compress, alt, insert),
 * bulk AI alt text for the Media Library, and oversized image compression.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function csa_slots_get( $id ) {
	$s = get_post_meta( $id, '_csa_image_slots', true );
	return is_array( $s ) ? $s : array();
}

/** Save suggestions: keeps uploaded/inserted slots, replaces pending ones. Returns number of new slots. */
function csa_slots_save( $id, $slots ) {
	$keep = array_filter( csa_slots_get( $id ), function ( $s ) { return 'pending' !== ( $s['status'] ?? 'pending' ); } );
	$have = wp_list_pluck( $keep, 'slot' );
	$n    = 0;
	foreach ( $slots as $s ) {
		$slot = sanitize_key( $s['slot'] ?? '' );
		if ( '' === $slot || in_array( $slot, $have, true ) ) {
			continue;
		}
		$keep[] = array(
			'slot'        => $slot,
			'position'    => sanitize_text_field( $s['position'] ?? ( isset( $s['after_section'] ) ? ( (int) $s['after_section'] < 0 ? 'featured image' : 'after section ' . (int) $s['after_section'] ) : '' ) ),
			'description' => sanitize_text_field( $s['description'] ?? '' ),
			'size'        => sanitize_text_field( $s['size'] ?? '1200x800' ),
			'alt'         => sanitize_text_field( $s['alt'] ?? '' ),
			'filename'    => sanitize_title( $s['filename'] ?? $slot ),
			'caption'     => sanitize_text_field( $s['caption'] ?? '' ),
			'status'      => 'pending',
			'attachment'  => 0,
		);
		$have[] = $slot;
		$n++;
	}
	update_post_meta( $id, '_csa_image_slots', wp_slash( array_values( $keep ) ) );
	return $n;
}

/** Card data for the chat: slots of a page with their status. */
function csa_slots_item( $pid ) {
	$p = csa_valid_post( $pid );
	if ( ! $p ) {
		return null;
	}
	$out = array();
	foreach ( csa_slots_get( $pid ) as $s ) {
		$out[] = array(
			'slot'        => $s['slot'],
			'description' => $s['description'],
			'position'    => $s['position'],
			'size'        => $s['size'],
			'alt'         => $s['alt'],
			'filename'    => $s['filename'],
			'status'      => $s['status'],
			'thumb'       => $s['attachment'] ? wp_get_attachment_image_url( $s['attachment'], 'thumbnail' ) : '',
		);
	}
	return array( 'r' => 'slots', 'pid' => (int) $pid, 'title' => $p->post_title, 'slots' => $out );
}

/** Slots that still need a photo (not uploaded yet, or skipped by the user). */
function csa_slots_missing( $pid ) {
	$n = array( 'pending' => 0, 'skipped' => 0 );
	foreach ( csa_slots_get( $pid ) as $s ) {
		if ( 'pending' === $s['status'] ) {
			$n['pending']++;
		} elseif ( 'skipped' === $s['status'] ) {
			$n['skipped']++;
		}
	}
	$n['total'] = $n['pending'] + $n['skipped'];
	return $n;
}

/** After the agent edited a page: mark uploaded slots whose image now appears in the new content. */
function csa_slots_detect( $pid, $text ) {
	if ( ! $pid || '' === $text ) {
		return;
	}
	$slots = csa_slots_get( $pid );
	$ch    = false;
	foreach ( $slots as $k => $s ) {
		if ( 'uploaded' !== $s['status'] || ! $s['attachment'] ) {
			continue;
		}
		$file = wp_get_attachment_url( $s['attachment'] );
		$base = $file ? pathinfo( $file, PATHINFO_FILENAME ) : '';
		if ( false !== strpos( $text, 'wp-image-' . $s['attachment'] ) || ( $base && false !== strpos( $text, $base ) ) ) {
			$slots[ $k ]['status'] = 'inserted';
			$ch                    = true;
		}
	}
	if ( $ch ) {
		update_post_meta( $pid, '_csa_image_slots', wp_slash( $slots ) );
	}
}

function csa_slots_for_agent( $id ) {
	$p = csa_valid_post( $id );
	if ( ! $p ) {
		return array( 'error' => 'Page not found.' );
	}
	$out = array();
	foreach ( csa_slots_get( $id ) as $s ) {
		if ( $s['attachment'] ) {
			$s['url']        = wp_get_attachment_image_url( $s['attachment'], 'large' );
			$s['full_url']   = wp_get_attachment_url( $s['attachment'] );
		}
		$out[] = $s;
	}
	return array( 'page' => $p->post_title, 'elementor' => csa_is_elementor( $id ), 'slots' => $out );
}

function csa_media_includes() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
}

/** Image block HTML for an attachment. */
function csa_image_html( $att, $caption = '' ) {
	$src = wp_get_attachment_image_url( $att, 'large' );
	$alt = get_post_meta( $att, '_wp_attachment_image_alt', true );
	$h   = '<!-- wp:image {"id":' . (int) $att . ',"sizeSlug":"large"} -->' . "\n"
		. '<figure class="wp-block-image size-large"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . (int) $att . '"/>'
		. ( $caption ? '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>' : '' )
		. '</figure>' . "\n" . '<!-- /wp:image -->';
	return $h;
}

add_action( 'wp_ajax_csa_slots', 'csa_ajax_slots' );
function csa_ajax_slots() {
	csa_guard( 'images' );
	$do   = sanitize_key( $_POST['do'] ?? '' );
	$pid  = (int) ( $_POST['post_id'] ?? 0 );
	$p    = csa_valid_post( $pid );
	$slot = sanitize_key( $_POST['slot'] ?? '' );
	if ( ! $p ) {
		wp_send_json_error( csa_t( 'Page not found.' ) );
	}
	$slots = csa_slots_get( $pid );

	if ( 'upload' === $do ) {
		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( csa_t( 'No file.' ) );
		}
		$i = array_search( $slot, wp_list_pluck( $slots, 'slot' ), true );
		if ( false === $i ) {
			wp_send_json_error( csa_t( 'Slot not found.' ) );
		}
		$s   = $slots[ $i ];
		$ext = strtolower( pathinfo( $_FILES['file']['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
			wp_send_json_error( csa_t( 'Use a JPG, PNG or WebP image.' ) );
		}
		$_FILES['file']['name'] = ( $s['filename'] ? $s['filename'] : $slot ) . '.' . $ext;
		csa_media_includes();
		$att = media_handle_upload( 'file', $pid, array( 'post_title' => $s['alt'] ? $s['alt'] : $s['description'], 'post_excerpt' => $s['caption'] ) );
		if ( is_wp_error( $att ) ) {
			wp_send_json_error( $att->get_error_message() );
		}
		// Compress the stored image, then rebuild its thumbnails.
		$file = get_attached_file( $att );
		if ( $file && ! is_wp_error( csa_optimize_image( $file ) ) ) {
			wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $file ) );
		}
		update_post_meta( $att, '_wp_attachment_image_alt', $s['alt'] );
		$slots[ $i ]['attachment'] = $att;
		$slots[ $i ]['status']     = 'uploaded';
		if ( 'featured' === $slot ) {
			set_post_thumbnail( $pid, $att );
			$slots[ $i ]['status'] = 'inserted';
		}
		update_post_meta( $pid, '_csa_image_slots', wp_slash( $slots ) );
		csa_log( csa_t( 'Image uploaded for' ) . ' "' . $p->post_title . '" (' . $slot . ')' );
		wp_send_json_success( array( 'slot' => $slots[ $i ], 'thumb' => wp_get_attachment_image_url( $att, 'thumbnail' ), 'item' => csa_slots_item( $pid ) ) );
	}

	if ( 'skip' === $do || 'unskip' === $do || 'skipall' === $do ) {
		foreach ( $slots as $k => $s ) {
			if ( 'skipall' === $do && 'pending' === $s['status'] ) {
				$slots[ $k ]['status'] = 'skipped';
			} elseif ( $s['slot'] === $slot && 'skip' === $do && 'pending' === $s['status'] ) {
				$slots[ $k ]['status'] = 'skipped';
			} elseif ( $s['slot'] === $slot && 'unskip' === $do && 'skipped' === $s['status'] ) {
				$slots[ $k ]['status'] = 'pending';
			}
		}
		update_post_meta( $pid, '_csa_image_slots', wp_slash( $slots ) );
		wp_send_json_success( array( 'item' => csa_slots_item( $pid ), 'missing' => csa_slots_missing( $pid ) ) );
	}

	if ( 'remove' === $do ) {
		$slots = array_values( array_filter( $slots, function ( $s ) use ( $slot ) { return $s['slot'] !== $slot; } ) );
		update_post_meta( $pid, '_csa_image_slots', wp_slash( $slots ) );
		wp_send_json_success();
	}

	if ( 'insert' === $do ) {
		$content = $p->post_content;
		$done    = 0;
		$left    = 0;
		foreach ( $slots as $k => $s ) {
			if ( 'uploaded' !== $s['status'] ) {
				continue;
			}
			$marker = '<!-- csa-image-slot:' . $s['slot'] . ' -->';
			if ( false !== strpos( $content, $marker ) ) {
				$content                = str_replace( $marker, csa_image_html( $s['attachment'], $s['caption'] ), $content );
				$slots[ $k ]['status']  = 'inserted';
				$done++;
			} else {
				$left++;
			}
		}
		if ( $done ) {
			$undo = csa_snapshot_post( $pid );
			wp_update_post( array( 'ID' => $pid, 'post_content' => $content ) );
			update_post_meta( $pid, '_csa_image_slots', wp_slash( $slots ) );
			csa_clear_cache( $pid );
			csa_log( sprintf( csa_t( 'Inserted %1$d image(s) into "%2$s"' ), $done, $p->post_title ), $undo );
		}
		wp_send_json_success( array( 'inserted' => $done, 'left' => $left ) );
	}
	wp_send_json_error( 'Bad request' );
}

/* ================================================================== */
/* Media Library: missing alt text, AI alt, oversized images          */
/* ================================================================== */


/** Posts/pages that use an image (parent, featured image, or the file shown in content / Elementor data). */
function csa_media_usage( $id, $max = 3 ) {
	global $wpdb;
	$ids = array();
	$a   = get_post( $id );
	if ( $a && $a->post_parent ) {
		$ids[] = (int) $a->post_parent;
	}
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id' AND meta_value=%d LIMIT 3", $id ) ) as $x ) {
		$ids[] = (int) $x;
	}
	$f = get_attached_file( $id );
	if ( $f && count( array_unique( $ids ) ) < $max ) {
		$base = preg_replace( '/\.[a-z0-9]+$/i', '', basename( $f ) );
		if ( strlen( $base ) >= 4 ) {
			$like = '%' . $wpdb->esc_like( $base ) . '%';
			$q    = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('page','post','sb_post') AND post_status IN ('publish','draft','future','pending','private') AND post_content LIKE %s LIMIT 3", $like ) );
			$m    = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_elementor_data' AND meta_value LIKE %s LIMIT 3", $like ) );
			foreach ( array_merge( (array) $q, (array) $m ) as $x ) {
				$ids[] = (int) $x;
			}
		}
	}
	$out = array();
	foreach ( array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, $max ) as $pid ) {
		$p = get_post( $pid );
		if ( $p && 'attachment' !== $p->post_type ) {
			$out[] = $p;
		}
	}
	return $out;
}

/** Plain-text context for an image's alt text: where it is used, topic, keyword, nearby text. */
function csa_media_context( $id ) {
	$parts = array();
	$f     = get_attached_file( $id );
	$base  = $f ? preg_replace( '/\.[a-z0-9]+$/i', '', basename( $f ) ) : '';
	foreach ( csa_media_usage( $id ) as $p ) {
		$kw = function_exists( 'csa_si_seo' ) ? csa_si_seo( $p->ID )['sk'] : '';
		if ( '' === $kw ) {
			$kw = (string) get_post_meta( $p->ID, '_sb_keyword', true );
		}
		$line = 'Used on the ' . $p->post_type . ' "' . get_the_title( $p ) . '"' . ( '' !== $kw ? ' (focus keyword: ' . $kw . ')' : '' ) . '.';
		$txt  = wp_strip_all_tags( preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $p->post_content ) );
		$txt  = trim( preg_replace( '/\s+/u', ' ', $txt ) );
		if ( '' !== $txt ) {
			$line .= ' Page text starts: ' . mb_substr( $txt, 0, 300 ) . '.';
		}
		$parts[] = $line;
	}
	return implode( ' ', $parts );
}

add_action( 'wp_ajax_csa_media', 'csa_ajax_media' );
function csa_ajax_media() {
	csa_guard( 'images' );
	$do = sanitize_key( $_POST['do'] ?? '' );

	if ( 'missing' === $do ) {
		$q   = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => 40,
				'paged'          => max( 1, (int) ( $_POST['paged'] ?? 1 ) ),
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_wp_attachment_image_alt', 'value' => '' ),
				),
			)
		);
		$out = array();
		foreach ( $q->posts as $a ) {
			$used   = array_map( function ( $p ) { return get_the_title( $p ); }, csa_media_usage( $a->ID ) );
			$out[]  = array( 'id' => $a->ID, 'thumb' => wp_get_attachment_image_url( $a->ID, 'thumbnail' ), 'title' => $a->post_title, 'parent' => implode( ' · ', $used ) );
		}
		wp_send_json_success( array( 'items' => $out, 'total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages ) );
	}

	if ( 'alt' === $do ) {
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( ! wp_attachment_is_image( $id ) ) {
			wp_send_json_error( csa_t( 'Image not found.' ) );
		}
		$src = image_get_intermediate_size( $id, csa_eco_val( 'medium', 'medium_large' ) );
		if ( ! $src ) {
			$src = image_get_intermediate_size( $id, 'medium' );
		}
		$path = $src ? path_join( dirname( get_attached_file( $id ) ), $src['file'] ) : get_attached_file( $id );
		$blk  = csa_image_block( $path );
		if ( ! $blk ) {
			wp_send_json_error( csa_t( 'Could not read this image.' ) );
		}
		$a      = get_post( $id );
		$ctx    = csa_media_context( $id );
		$system = 'You write image alt text for SEO and accessibility. ' . csa_site_context()
			. "\n\nDescribe what the image shows in one concise sentence (max 120 characters). Make it fit the page topic given in the context: use the page's focus keyword or topic naturally only when the image really relates to it (never stuff keywords, never describe things not visible). If no context is given, use a relevant keyword for this website when it fits. Language: " . csa_opt( 'seo_lang' ) . '. Return ONLY the alt text, no quotes.';
		$data   = csa_claude( array( array( 'role' => 'user', 'content' => array( $blk, array( 'type' => 'text', 'text' => 'File: ' . $a->post_title . '. ' . $ctx ) ) ) ), $system, array( 'max_tokens' => 200, 'task' => 'vision', 'needs_vision' => true, 'feature' => 'Alt text' ) );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( $data->get_error_message() );
		}
		$alt = trim( csa_text_of( $data ), " \t\n\r\"'" );
		$old = get_post_meta( $id, '_wp_attachment_image_alt', true );
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		csa_log( csa_t( 'AI alt text for image' ) . ' #' . $id . ': ' . $alt, array( 'type' => 'alt', 'post' => $id, 'old' => $old ) );
		wp_send_json_success( array( 'alt' => $alt, 'alerts' => csa_take_alerts() ) );
	}

	if ( 'save_alt' === $do ) {
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( ! wp_attachment_is_image( $id ) ) {
			wp_send_json_error( csa_t( 'Image not found.' ) );
		}
		$old = get_post_meta( $id, '_wp_attachment_image_alt', true );
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( wp_unslash( $_POST['alt'] ?? '' ) ) );
		csa_log( csa_t( 'Alt text edited for image' ) . ' #' . $id, array( 'type' => 'alt', 'post' => $id, 'old' => $old ) );
		wp_send_json_success();
	}

	if ( 'big' === $do ) {
		$ids = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ), 'post_status' => 'inherit', 'numberposts' => 400, 'fields' => 'ids' ) );
		$out = array();
		foreach ( $ids as $id ) {
			$f = get_attached_file( $id );
			if ( $f && file_exists( $f ) && filesize( $f ) > 300 * KB_IN_BYTES ) {
				$m     = wp_get_attachment_metadata( $id );
				$out[] = array( 'id' => $id, 'thumb' => wp_get_attachment_image_url( $id, 'thumbnail' ), 'title' => get_the_title( $id ), 'kb' => (int) round( filesize( $f ) / KB_IN_BYTES ), 'w' => (int) ( $m['width'] ?? 0 ), 'h' => (int) ( $m['height'] ?? 0 ) );
			}
		}
		usort( $out, function ( $a, $b ) { return $b['kb'] <=> $a['kb']; } );
		wp_send_json_success( array_slice( $out, 0, 60 ) );
	}

	if ( 'compress' === $do ) {
		$id = (int) ( $_POST['id'] ?? 0 );
		$f  = get_attached_file( $id );
		if ( ! $f || ! file_exists( $f ) ) {
			wp_send_json_error( csa_t( 'Image not found.' ) );
		}
		$before = filesize( $f );
		$r      = csa_optimize_image( $f );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( $r->get_error_message() );
		}
		clearstatcache( true, $f );
		csa_media_includes();
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $f ) );
		$after = filesize( $f );
		csa_log( sprintf( csa_t( 'Compressed image #%1$d: %2$d KB → %3$d KB' ), $id, round( $before / KB_IN_BYTES ), round( $after / KB_IN_BYTES ) ) );
		wp_send_json_success( array( 'kb' => (int) round( $after / KB_IN_BYTES ) ) );
	}
	wp_send_json_error( 'Bad request' );
}
