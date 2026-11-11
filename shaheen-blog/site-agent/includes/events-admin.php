<?php
/** Page Builder > ইভেন্ট ও হোম : admin screen and its ajax. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_submenu_page( 'sef-builder', 'ইভেন্ট ও নতুন হোম', '🎪 ইভেন্ট ও হোম', 'edit_posts', 'sef-events', 'csa_ev_screen' );
}, 21 );

function csa_ev_admin_state() {
	$list = array();
	foreach ( csa_ev_list() as $e ) {
		$e           = csa_ev_full( $e );
		$st          = csa_ev_stage( $e );
		$e['_stage'] = $st[0];
		$e['_url']   = csa_ev_page_url( $e );
		$e['_live']  = csa_ev_page_live( $e );
		$e['_home']  = csa_ev_visible_home( $e );
		$list[]      = $e;
	}
	$o = csa_ev_opts();
	return array(
		'list'  => $list,
		'home'  => $o['home'],
		'hs'    => csa_ev_home_state(),
		'roles' => $o['roles'],
	);
}

function csa_ev_screen() {
	if ( ! csa_ev_can() ) {
		wp_die( 'এই পেজ বদলানোর অনুমতি আপনার নেই।' );
	}
	wp_enqueue_media();
	wp_enqueue_style( 'csa-ev-admin', CSA_URL . 'assets/events-admin.css', array(), CSA_VER );
	wp_enqueue_script( 'csa-ev-admin', CSA_URL . 'assets/events-admin.js', array(), CSA_VER, true );
	wp_localize_script( 'csa-ev-admin', 'EV', array(
		'ajax'    => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'csa_ev' ),
		's'       => csa_ev_admin_state(),
		'blank'   => csa_ev_blank(),
		'isAdmin' => current_user_can( 'manage_options' ),
		'allRoles' => array_map( function ( $k, $r ) { return array( 'k' => $k, 'l' => translate_user_role( $r['name'] ) ); }, array_keys( wp_roles()->roles ), wp_roles()->roles ),
		'fonts'   => function_exists( 'csa_font_list' ) ? array_map( function ( $k, $r ) { return array( 'k' => $k, 'l' => $r[0] . ' — ' . $r[3] ); }, array_keys( csa_font_list() ), csa_font_list() ) : array(),
		'sections' => array( 'hero' => 'ওপরের হিরো ও সংখ্যা', 'events' => 'ইভেন্ট সারি', 'notice' => 'নোটিশ বোর্ড', 'act' => 'আমাদের কার্যক্রম', 'branch' => 'কাছের শাখা খুঁজুন', 'ach' => 'গৌরবোজ্জ্বল অর্জন', 'cadet' => 'ক্যাডেট প্রস্তুতি (বই ও অনলাইন)', 'why' => 'কেন শাহীন (তিনটি কার্ড)', 'ideal' => 'শিক্ষার্থী-অভিভাবক-শিক্ষক', 'blog' => 'সর্বশেষ ব্লগ', 'cta' => 'নিচের ভর্তি ও যোগাযোগ ব্যান্ড' ),
	) );
	echo '<div class="wrap" id="csa-ev"><h1>🎪 ইভেন্ট ও নতুন হোম পেজ</h1><div id="ev-app"><p>লোড হচ্ছে…</p></div></div>';
	csa_ev_yt_card();
}

add_action( 'wp_ajax_csa_ev_op', function () {
	check_ajax_referer( 'csa_ev', 'nonce' );
	if ( ! csa_ev_can() ) {
		wp_send_json_error( 'অনুমতি নেই।' );
	}
	$op   = sanitize_key( $_POST['op'] ?? '' );
	$data = json_decode( wp_unslash( $_POST['data'] ?? 'null' ), true );
	$o    = csa_ev_opts();
	$list = csa_ev_list();
	$msg  = '';
	switch ( $op ) {
		case 'save': // one event
			if ( ! is_array( $data ) || '' === trim( (string) ( $data['title'] ?? '' ) ) ) {
				wp_send_json_error( 'ইভেন্টের নাম লিখুন।' );
			}
			$id  = (int) ( $data['id'] ?? 0 );
			$old = $id ? csa_ev_get( $id ) : null;
			if ( $old ) {
				$data['page'] = (int) $old['page'];
			}
			$c = csa_ev_clean( $data );
			if ( ! $old ) {
				$id = (int) $o['next']++;
				csa_ev_set( $o );
			}
			$c['id'] = $id;
			if ( '' === $c['slug'] ) {
				$c['slug'] = sanitize_title( $c['title'] ) ?: 'event-' . $id;
			}
			csa_ev_ensure_page( $c );
			if ( $c['page'] && ! $c['ext'] ) { // keep the page's address in step with the slug
				$pg = get_post( $c['page'] );
				if ( $pg && $pg->post_name !== $c['slug'] ) {
					wp_update_post( array( 'ID' => $c['page'], 'post_name' => wp_unique_post_slug( $c['slug'], $c['page'], $pg->post_status, 'page', 0 ) ) );
				}
			}
			$found = false;
			foreach ( $list as $i => $x ) {
				if ( (int) $x['id'] === $id ) {
					$list[ $i ] = $c;
					$found      = true;
				}
			}
			if ( ! $found ) {
				$list[] = $c;
			}
			csa_ev_put( $list );
			$msg = 'ইভেন্ট সংরক্ষিত ✓';
			break;
		case 'delete':
			$id   = (int) $data;
			$list = array_values( array_filter( $list, function ( $e ) use ( $id ) { return (int) $e['id'] !== $id; } ) );
			csa_ev_put( $list );
			$msg = 'ইভেন্ট মুছেছে (তার পেজটি ড্রাফট/ট্র্যাশে বা যেমন আছে তেমনই থাকবে)।';
			break;
		case 'page': // data: {id, to: publish|draft}
			$e = csa_ev_get( (int) ( $data['id'] ?? 0 ) );
			if ( ! $e || empty( $e['page'] ) ) {
				wp_send_json_error( 'এই ইভেন্টের নিজস্ব পেজ নেই।' );
			}
			$to = 'publish' === ( $data['to'] ?? '' ) ? 'publish' : 'draft';
			wp_update_post( array( 'ID' => (int) $e['page'], 'post_status' => $to ) );
			csa_ev_purge();
			$msg = 'publish' === $to ? 'পেজ প্রকাশিত হয়েছে ✓' : 'পেজ আবার ড্রাফট হয়েছে।';
			break;
		case 'seed':
			$n   = csa_ev_import_seed();
			$msg = $n ? $n . 'টি ইভেন্ট আনা হয়েছে (Spelling Star Season 1 ও ক্ষুদে স্টার)।' : 'দুটি ইভেন্টই আগে থেকে আছে।';
			break;
		case 'home': // save the home page texts
			if ( ! is_array( $data ) ) {
				wp_send_json_error( 'ডেটা আসেনি।' );
			}
			$o['home'] = csa_ev_clean_home( $data );
			csa_ev_set( $o );
			csa_ev_home_ensure();
			csa_ev_home_seo_sync();
			$msg = 'হোম পেজ সংরক্ষিত ✓';
			break;
		case 'home_reset':
			$o['home'] = csa_ev_home_defaults();
			csa_ev_set( $o );
			csa_ev_home_seo_sync();
			$msg = 'হোম পেজের লেখা আসল অবস্থায় ফিরেছে।';
			break;
		case 'home_make':
			csa_ev_home_ensure();
			$msg = 'নতুন হোম পেজের খসড়া তৈরি আছে ✓';
			break;
		case 'live':
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'শুধু অ্যাডমিন হোম পেজ বদলাতে পারেন।' );
			}
			$er = csa_ev_go_live();
			if ( $er ) {
				wp_send_json_error( $er );
			}
			$msg = 'নতুন হোম পেজ এখন সবার জন্য চালু ✓ (পুরোনোটি যেমন ছিল তেমনই আছে, ফেরার বোতাম আছে)।';
			break;
		case 'back':
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'শুধু অ্যাডমিন।' );
			}
			$er = csa_ev_go_back();
			if ( $er ) {
				wp_send_json_error( $er );
			}
			$msg = 'পুরোনো হোম পেজ ফিরে এসেছে ✓';
			break;
		case 'roles':
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'শুধু অ্যাডমিন।' );
			}
			$allowed    = array_keys( wp_roles()->roles );
			$o['roles'] = array_values( array_intersect( array_map( 'sanitize_key', (array) $data ), $allowed ) );
			csa_ev_set( $o );
			$msg = 'অনুমতি সংরক্ষিত ✓';
			break;
		default:
			wp_send_json_error( 'অজানা কাজ।' );
	}
	wp_send_json_success( array( 'msg' => $msg, 's' => csa_ev_admin_state() ) );
} );

add_action( 'csa_sn_overview_cards', function () {
	$n  = count( csa_ev_list() );
	$hs = csa_ev_home_state();
	echo '<div class="sn-set"><h2>🎪 ইভেন্ট ও নতুন হোম</h2><p>ইভেন্ট: <b>' . (int) $n . '</b>টি · নতুন হোম পেজ: ' . ( $hs['live'] ? '<b style="color:#1a6b3e">চালু ✓</b>' : '<b style="color:#b06">এখনো খসড়া (পুরোনো হোম চলছে)</b>' ) . '</p><p><a class="button button-primary button-large" href="' . esc_url( admin_url( 'admin.php?page=sef-events' ) ) . '">🎪 ইভেন্ট ও হোম ম্যানেজ করুন</a></p></div>';
} );
