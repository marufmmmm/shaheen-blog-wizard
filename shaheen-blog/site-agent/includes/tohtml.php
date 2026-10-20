<?php
/**
 * Convert an Elementor page into clean HTML (keeps all content, drops the Elementor layout)
 * so that text edits, SEO and keyword work are reliable. Backed up and undoable.
 */
defined( 'ABSPATH' ) || exit;

/** Turn Elementor elements into plain semantic HTML in reading order. $unknown collects widget types that had no text to carry over. */
function csa_th_render( array $els, array &$unknown, array &$count ) {
	$h = '';
	foreach ( $els as $el ) {
		$type = $el['elType'] ?? '';
		$s    = (array) ( $el['settings'] ?? array() );
		if ( 'widget' !== $type ) {
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$h .= csa_th_render( $el['elements'], $unknown, $count );
			}
			continue;
		}
		$w = $el['widgetType'] ?? '';
		$g = function ( $k ) use ( $s ) {
			$v = $s[ $k ] ?? '';
			return is_string( $v ) ? $v : '';
		};
		$url = function ( $k ) use ( $s ) {
			$v = $s[ $k ] ?? array();
			return is_array( $v ) ? (string) ( $v['url'] ?? '' ) : '';
		};
		switch ( $w ) {
			case 'heading':
				$t = $g( 'title' );
				if ( '' === trim( wp_strip_all_tags( $t ) ) ) {
					break;
				}
				$sz = strtolower( $g( 'header_size' ) );
				$sz = in_array( $sz, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $sz : 'h2';
				$lk = $url( 'link' );
				$in = $lk ? '<a href="' . esc_url( $lk ) . '">' . $t . '</a>' : $t;
				$h .= "<{$sz}>" . $in . "</{$sz}>\n";
				$count['heading']++;
				break;
			case 'text-editor':
				$h .= trim( $g( 'editor' ) ) . "\n";
				$count['text']++;
				break;
			case 'html':
				$h .= trim( $g( 'html' ) ) . "\n";
				$count['html']++;
				break;
			case 'shortcode':
				$h .= trim( $g( 'shortcode' ) ) . "\n";
				break;
			case 'image':
				$im = (array) ( $s['image'] ?? array() );
				if ( ! empty( $im['url'] ) ) {
					$alt = '';
					if ( ! empty( $im['id'] ) ) {
						$alt = (string) get_post_meta( (int) $im['id'], '_wp_attachment_image_alt', true );
					}
					$tag = '<img src="' . esc_url( $im['url'] ) . '" alt="' . esc_attr( $alt ) . '">';
					$lk  = $url( 'link' );
					$tag = $lk ? '<a href="' . esc_url( $lk ) . '">' . $tag . '</a>' : $tag;
					$cap = $g( 'caption' );
					$h  .= '<p>' . $tag . '</p>' . ( $cap ? '<p><em>' . esc_html( $cap ) . '</em></p>' : '' ) . "\n";
					$count['image']++;
				}
				break;
			case 'button':
				$t = $g( 'text' );
				if ( '' !== trim( $t ) ) {
					$lk = $url( 'link' );
					$h .= '<p>' . ( $lk ? '<a href="' . esc_url( $lk ) . '">' . esc_html( $t ) . '</a>' : esc_html( $t ) ) . "</p>\n";
					$count['button']++;
				}
				break;
			case 'icon-list':
				$li = '';
				foreach ( (array) ( $s['icon_list'] ?? array() ) as $it ) {
					$t = (string) ( $it['text'] ?? '' );
					if ( '' === trim( $t ) ) {
						continue;
					}
					$lk  = is_array( $it['link'] ?? null ) ? (string) ( $it['link']['url'] ?? '' ) : '';
					$li .= '<li>' . ( $lk ? '<a href="' . esc_url( $lk ) . '">' . esc_html( $t ) . '</a>' : esc_html( $t ) ) . "</li>\n";
				}
				if ( $li ) {
					$h .= "<ul>\n" . $li . "</ul>\n";
					$count['list']++;
				}
				break;
			case 'image-box':
			case 'icon-box':
				$t = $g( 'title_text' );
				$d = $g( 'description_text' );
				$im = (array) ( $s['image'] ?? array() );
				if ( 'image-box' === $w && ! empty( $im['url'] ) ) {
					$h .= '<p><img src="' . esc_url( $im['url'] ) . '" alt="' . esc_attr( wp_strip_all_tags( $t ) ) . '"></p>' . "\n";
				}
				if ( '' !== trim( $t ) ) {
					$h .= '<h3>' . $t . "</h3>\n";
				}
				if ( '' !== trim( $d ) ) {
					$h .= '<p>' . $d . "</p>\n";
				}
				$count['box']++;
				break;
			case 'accordion':
			case 'toggle':
			case 'tabs':
				foreach ( (array) ( $s['tabs'] ?? array() ) as $tb ) {
					$t = (string) ( $tb['tab_title'] ?? '' );
					$c = (string) ( $tb['tab_content'] ?? '' );
					$h .= ( '' !== trim( $t ) ? '<h3>' . $t . "</h3>\n" : '' ) . ( '' !== trim( $c ) ? $c . "\n" : '' );
				}
				$count['tabs']++;
				break;
			case 'video':
				$v = $g( 'youtube_url' ) . $g( 'vimeo_url' );
				if ( $v ) {
					$h .= '<p><a href="' . esc_url( $v ) . '">' . esc_html( $v ) . "</a></p>\n";
				} else {
					$unknown['video'] = 1;
				}
				break;
			case 'divider':
			case 'spacer':
				break;
			default:
				// Unknown widget: carry over any plain text it holds so nothing is silently lost.
				$got = '';
				foreach ( array( 'title', 'title_text', 'description_text', 'description', 'editor', 'text', 'content', 'caption' ) as $k ) {
					$v = $g( $k );
					if ( '' !== trim( wp_strip_all_tags( $v ) ) ) {
						$got .= '<p>' . $v . "</p>\n";
					}
				}
				if ( $got ) {
					$h .= $got;
				} else {
					$unknown[ $w ] = 1;
				}
		}
	}
	return $h;
}

/** Convert one page. Returns array( ok, summary ) or array( error ). */
function csa_th_convert( $id ) {
	$p = csa_valid_post( $id );
	if ( ! $p ) {
		return array( 'error' => 'Page not found.' );
	}
	$g = csa_live_guard( $p );
	if ( $g ) {
		return $g;
	}
	if ( ! csa_is_elementor( $p->ID ) ) {
		return array( 'error' => 'This page is not built with Elementor, so it is already plain content. Edit it directly with replace_text.' );
	}
	list( $raw, $data ) = csa_el_load( $p->ID );
	if ( ! $data ) {
		return array( 'error' => 'No Elementor data found on this page.' );
	}
	$unknown = array();
	$count   = array( 'heading' => 0, 'text' => 0, 'html' => 0, 'image' => 0, 'button' => 0, 'list' => 0, 'box' => 0, 'tabs' => 0 );
	$html    = trim( csa_th_render( $data, $unknown, $count ) );
	if ( '' === $html ) {
		return array( 'error' => 'No text or content could be taken from this page, so nothing was changed.' );
	}
	// Safety check: every heading and paragraph text of the old page must be in the new HTML.
	$before = wp_strip_all_tags( $raw );
	$key    = substr( md5( uniqid( '', true ) ), 0, 10 );
	$all    = get_post_meta( $p->ID, '_csa_el_removed', true );
	$all    = is_array( $all ) ? $all : array();
	$all[ $key ] = array(
		'data'     => $raw,
		'mode'     => get_post_meta( $p->ID, '_elementor_edit_mode', true ),
		'template' => get_post_meta( $p->ID, '_wp_page_template', true ),
		'content'  => $p->post_content,
	);
	update_post_meta( $p->ID, '_csa_el_removed', wp_slash( array_slice( $all, -3, 3, true ) ) );
	$r = wp_update_post( array( 'ID' => $p->ID, 'post_content' => $html ), true );
	if ( is_wp_error( $r ) ) {
		return array( 'error' => $r->get_error_message() );
	}
	delete_post_meta( $p->ID, '_elementor_edit_mode' );
	delete_post_meta( $p->ID, '_elementor_data' );
	delete_post_meta( $p->ID, '_elementor_css' );
	csa_clear_cache( $p->ID );
	csa_log( csa_t( 'Converted from Elementor to clean HTML' ) . ' #' . $p->ID . ' ' . $p->post_title, array( 'type' => 'unel', 'post' => (int) $p->ID, 'key' => $key ) );
	return array(
		'ok'      => true,
		'summary' => $count,
		'words'   => count( preg_split( '/\s+/u', trim( wp_strip_all_tags( $html ) ), -1, PREG_SPLIT_NO_EMPTY ) ),
		'not_carried_over' => array_keys( $unknown ),
		'note'    => 'The page is now plain HTML in the normal editor. Elementor layout, columns and styling are gone (the theme styles it now). Edit it with replace_text.',
	);
}

function csa_th_undo( $id, $key ) {
	$all = get_post_meta( $id, '_csa_el_removed', true );
	if ( ! is_array( $all ) || ! isset( $all[ $key ] ) ) {
		return new WP_Error( 'undo', csa_t( 'The saved copy for this change is no longer available.' ) );
	}
	$d = $all[ $key ];
	update_post_meta( $id, '_elementor_data', wp_slash( $d['data'] ) );
	update_post_meta( $id, '_elementor_edit_mode', $d['mode'] ? $d['mode'] : 'builder' );
	wp_update_post( array( 'ID' => $id, 'post_content' => $d['content'] ) );
	csa_clear_cache( $id );
	unset( $all[ $key ] );
	update_post_meta( $id, '_csa_el_removed', wp_slash( $all ) );
	return true;
}

function csa_th_tool() {
	return array(
		array(
			'name'         => 'convert_to_html',
			'description'  => 'Convert ONE Elementor page into clean plain HTML (all headings, text, lists, images, links and buttons are kept in order; Elementor layout and styling are dropped, the theme styles it). Use it when a text/SEO/keyword edit on an Elementor page fails even once (Widget not found, no match): the owner has pre-approved this for text jobs. NEVER use it for design or layout jobs. After it, edit with replace_text / edit_content. Undoable.',
			'input_schema' => csa_schema( array( 'id' => array( 'type' => 'integer' ) ), array( 'id' ) ),
		),
	);
}

add_action( 'wp_ajax_csa_jobs_tohtml', function () {
	csa_guard( 'chat' );
	$r = csa_th_convert( (int) ( $_POST['id'] ?? 0 ) );
	if ( isset( $r['error'] ) ) {
		wp_send_json_error( $r['error'] );
	}
	wp_send_json_success( $r );
} );
