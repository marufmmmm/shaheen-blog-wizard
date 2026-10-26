<?php
/**
 * Notices: one post type for recruitment, results, scholarships and so on.
 * The owner fills a form (type, branch, facts, blocks); the page is drawn from a template when it is viewed,
 * so one design change updates every notice. Also: recent-notices slider, notice list, activities slider.
 */
defined( 'ABSPATH' ) || exit;

const CSA_SN_OPT = 'csa_sn';

function csa_sn_defaults() {
	return array(
		'fs'        => 20,
		'fs_m'      => 20,
		'width'     => 1240,
		'per_view'  => 1,
		'recent'    => 10,
		'hide_title' => 1,
		'brand'     => 'শাহীন স্কুল এন্ড ক্যাডেট কোচিং',
		'slug'      => 'notice-board',
		'css'       => '',
		'model'     => '',
		'list_page' => 0,
		'old'       => '',
		'gap'       => 12,
		'replace'   => 0,
		'board'     => array(),
		'hl'        => array(),
		'primary'   => '#2e7d32',
		'dark'      => '#0f3d1e',
		'accent'    => '#d9961a',
		'soft'      => '#e8f3ea',
		'types'     => array(
			'niyog'         => array( 'label' => 'নিয়োগ', 'topic' => 'সহকারী শিক্ষক নিয়োগ', 'cards' => "আবেদনের শেষ সময়\nলিখিত পরীক্ষার সময়\nপরীক্ষার ভেন্যু", 'job' => 1 ),
			'niyog_result'  => array( 'label' => 'নিয়োগ ফলাফল', 'topic' => 'নিয়োগ পরীক্ষার ফলাফল', 'cards' => "ফলাফল প্রকাশের তারিখ\nপরবর্তী ধাপ\nভাইভার সময়", 'job' => 0 ),
			'britti'        => array( 'label' => 'বৃত্তি', 'topic' => 'বৃত্তি পরীক্ষা', 'cards' => "আবেদনের শেষ সময়\nপরীক্ষার তারিখ\nপরীক্ষার ভেন্যু", 'job' => 0 ),
			'britti_result' => array( 'label' => 'বৃত্তি ফলাফল', 'topic' => 'বৃত্তি পরীক্ষার ফলাফল', 'cards' => "ফলাফল প্রকাশের তারিখ\nবৃত্তির পরিমাণ", 'job' => 0 ),
		),
		'acts'      => array(
			array( 'icon' => '🏠', 'title' => 'হোমপেজ', 'text' => 'শাহীন শিক্ষা পরিবারের মূল ওয়েবসাইট। সকল তথ্য এক জায়গায়।', 'url' => home_url( '/' ) ),
			array( 'icon' => '🏫', 'title' => 'বাংলা মাধ্যম স্কুল', 'text' => 'প্লে গ্রুপ থেকে ১০ম শ্রেণি পর্যন্ত আধুনিক শিক্ষা।', 'url' => '' ),
			array( 'icon' => '📘', 'title' => 'ইংলিশ ভার্সন স্কুল', 'text' => 'NCTB অনুমোদিত ইংরেজি মাধ্যম।', 'url' => '' ),
			array( 'icon' => '⭐', 'title' => 'ক্যাডেট কোচিং', 'text' => 'ক্যাডেট কলেজ ভর্তি প্রস্তুতি।', 'url' => '' ),
		),
	);
}

/** Colour group of a notice type on the board: job (blue), result (green), form (purple), admission (orange). */
function csa_sn_style_of( $k, $t ) {
	if ( ! empty( $t['style'] ) && in_array( $t['style'], array( 'job', 'result', 'form', 'admission' ), true ) ) {
		return $t['style'];
	}
	$x = $k . ' ' . ( $t['label'] ?? '' );
	if ( preg_match( '/result|ফলাফল/iu', $x ) ) {
		return 'result';
	}
	if ( preg_match( '/form|ফরম/iu', $x ) ) {
		return 'form';
	}
	return 'job';
}

/** Board sections a notice belongs to (one or more). Chosen in the notice screen; falls back to the notice type. */
function csa_sn_secs( $id ) {
	$m = get_post_meta( $id, '_sn_sec', true );
	$m = is_array( $m ) ? array_values( array_intersect( $m, array( 'job', 'result', 'form', 'admission' ) ) ) : array();
	if ( $m ) {
		return $m;
	}
	$k = (string) get_post_meta( $id, '_sn_type', true );
	$t = csa_sn_opt( 'types' )[ $k ] ?? array();
	return array( csa_sn_style_of( $k, $t ) );
}

function csa_sn_opts() {
	$o = get_option( CSA_SN_OPT, array() );
	$o = wp_parse_args( is_array( $o ) ? $o : array(), csa_sn_defaults() );
	$has = false;
	foreach ( $o['types'] as $k => $t ) {
		if ( 'form' === csa_sn_style_of( $k, $t ) ) {
			$has = true;
		}
	}
	if ( ! $has ) {
		$o['types']['form'] = array( 'label' => 'আবেদন ফরম', 'topic' => 'আবেদন ফরম', 'cards' => "আবেদনের শেষ সময়", 'job' => 0, 'style' => 'form' );
	}
	return $o;
}

function csa_sn_opt( $k ) {
	$o = csa_sn_opts();
	return $o[ $k ] ?? null;
}

function csa_sn_bn( $s ) {
	return strtr( (string) $s, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
}

function csa_sn_date( $ts_or_str ) {
	$ts = is_numeric( $ts_or_str ) ? (int) $ts_or_str : strtotime( (string) $ts_or_str );
	return $ts ? csa_sn_bn( wp_date( 'd-m-Y', $ts ) ) : '';
}

/** YouTube / Facebook / map addresses become safe embed addresses; anything else gives an empty string. */
function csa_sn_embed_url( $kind, $url ) {
	$url = trim( (string) $url );
	$h   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$h   = preg_replace( '/^(www\.|m\.)/', '', $h );
	if ( 'youtube' === $kind ) {
		$id = '';
		if ( preg_match( '#(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([\w-]{11})#', $url, $m ) ) {
			$id = $m[1];
		}
		return $id ? 'https://www.youtube-nocookie.com/embed/' . $id : '';
	}
	if ( 'map' === $kind ) {
		return ( 'google.com' === $h || 'maps.google.com' === $h ) && false !== strpos( $url, '/maps/embed' ) ? esc_url_raw( $url ) : '';
	}
	if ( ! in_array( $h, array( 'facebook.com', 'fb.watch', 'fb.com' ), true ) ) {
		return '';
	}
	$q = rawurlencode( $url );
	switch ( $kind ) {
		case 'fb_video':
			return 'https://www.facebook.com/plugins/video.php?href=' . $q . '&show_text=false&width=560';
		case 'fb_post':
			return 'https://www.facebook.com/plugins/post.php?href=' . $q . '&show_text=true&width=500';
		case 'fb_page':
			return 'https://www.facebook.com/plugins/page.php?href=' . $q . '&tabs=timeline&width=500&height=600&small_header=true&adapt_container_width=true&hide_cover=false&show_facepile=true';
	}
	return '';
}


/** Recursively clean a JSON answer from the AI. */
function csa_sn_any_clean( $v ) {
	if ( is_array( $v ) ) {
		$o = array();
		foreach ( $v as $k => $x ) {
			$o[ is_int( $k ) ? $k : sanitize_key( $k ) ] = csa_sn_any_clean( $x );
		}
		return $o;
	}
	return is_scalar( $v ) ? sanitize_textarea_field( (string) $v ) : '';
}

/* ------------------------------------------------------------------ */
/* Post type                                                            */
/* ------------------------------------------------------------------ */

/** Notices are ordinary WordPress posts (Posts library, categories, tags) that carry the notice form's data. */
function csa_sn_is( $id ) {
	return $id && (bool) get_post_meta( $id, '_sn_is', true );
}

/** True on the edit screen of a notice (existing notice, or a new one started from Page Builder). */
function csa_sn_editing( $post ) {
	return $post && 'post' === $post->post_type && ( csa_sn_is( $post->ID ) || ! empty( $_GET['sn'] ) );
}

function csa_sn_single() {
	return is_singular( 'post' ) && csa_sn_is( get_queried_object_id() );
}

// Old versions kept notices in their own post type: move them into Posts once.
add_action( 'init', function () {
	if ( get_option( 'csa_sn_mig' ) ) {
		return;
	}
	global $wpdb;
	$wpdb->query( "UPDATE {$wpdb->posts} SET post_type='post' WHERE post_type='sef_notice'" );
	$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) SELECT m.post_id, '_sn_is', '1' FROM {$wpdb->postmeta} m WHERE m.meta_key='_sn' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} x WHERE x.post_id=m.post_id AND x.meta_key='_sn_is')" );
	update_option( 'csa_sn_mig', 1, false );
	delete_option( 'csa_sn_rw' );
}, 1 );

// The notice form needs the classic editor.
add_filter( 'use_block_editor_for_post', function ( $use, $post ) {
	return csa_sn_editing( $post ) ? false : $use;
}, 20, 2 );

add_filter( 'body_class', function ( $c ) {
	if ( csa_sn_single() ) {
		$c[] = 'sn-notice';
	}
	return $c;
} );

// Posts > All Posts, only notices (Page Builder > সব নোটিশ).
add_action( 'pre_get_posts', function ( $q ) {
	if ( is_admin() && $q->is_main_query() && ! empty( $_GET['sn'] ) && 'edit.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
		$q->set( 'meta_key', '_sn_is' );
		$q->set( 'meta_value', '1' );
	}
} );

// Links used by earlier versions keep working.
add_action( 'template_redirect', function () {
	if ( is_404() && preg_match( '#^/?(notice-board|notice)/([^/]+)/?$#', (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), $m ) ) {
		$p = get_page_by_path( rawurldecode( $m[2] ), OBJECT, 'post' );
		if ( $p && csa_sn_is( $p->ID ) ) {
			wp_safe_redirect( csa_sn_url( $p ), 301 );
			exit;
		}
	}
} );

/* ------------------------------------------------------------------ */
/* Drawing                                                              */
/* ------------------------------------------------------------------ */

/** The page that shows the notice list: the one chosen in settings, else the site's own page at /notice/ (or /notices/). */
function csa_sn_listpage_id() {
	$lp = (int) csa_sn_opt( 'list_page' );
	if ( $lp && 'publish' === get_post_status( $lp ) ) {
		return $lp;
	}
	foreach ( array( 'notice', 'notices' ) as $path ) {
		$pg = get_page_by_path( $path );
		if ( $pg && 'publish' === $pg->post_status ) {
			return (int) $pg->ID;
		}
	}
	return 0;
}

function csa_sn_css() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;
	$o    = csa_sn_opts();
	$c    = function ( $k, $d ) use ( $o ) {
		return preg_match( '/^#[0-9a-f]{6}$/i', (string) $o[ $k ] ) ? $o[ $k ] : $d;
	};
	$p    = $c( 'primary', '#2e7d32' );
	$k    = $c( 'dark', '#0f3d1e' );
	$a    = $c( 'accent', '#d9961a' );
	$s    = $c( 'soft', '#e8f3ea' );
	$fs   = max( 14, min( 30, (int) $o['fs'] ) );
	$fm   = max( 14, min( 30, (int) $o['fs_m'] ) );
	$w    = max( 700, min( 1600, (int) $o['width'] ) );
	$gap  = max( 0, min( 80, (int) $o['gap'] ) );
	$css  = ".sn,.sn *{box-sizing:border-box}.sn{--p:{$p};--k:{$k};--a:{$a};--s:{$s};--r:14px;max-width:{$w}px;margin:0 auto;padding:{$gap}px 8px;font-size:{$fs}px;line-height:1.7;color:#1d2b22}"
		. ".sn-bn{position:relative;overflow:hidden;border-radius:var(--r);color:#fff;text-align:center;padding:clamp(20px,4vw,34px) clamp(14px,4vw,34px);background:linear-gradient(120deg,var(--k),var(--p),var(--a),var(--p),var(--k));background-size:300% 300%;animation:snflow 10s ease infinite}"
		. ".sn-bn:before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 85% 20%,rgba(255,255,255,.18),transparent 45%);animation:snpulse 5s ease-in-out infinite}"
		. "@keyframes snflow{0%{background-position:0 50%}50%{background-position:100% 50%}100%{background-position:0 50%}}@keyframes snpulse{50%{opacity:.4}}@keyframes snin{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}"
		. ".sn-brand{position:relative;font-size:.9em;font-weight:600;opacity:.92;margin:0 0 6px;letter-spacing:.2px}"
		. ".sn-bt{position:relative;font-size:clamp(28px,7vw,52px);font-weight:700;line-height:1.2;margin:0 0 6px;text-shadow:0 2px 8px rgba(0,0,0,.25);animation:snin 1s ease both}"
		. ".sn-sub{position:relative;display:inline-block;background:#fff;color:var(--k);font-weight:700;border-radius:999px;padding:3px 16px;font-size:clamp(18px,4.4vw,28px);animation:snin 1s .2s ease both}.sn-yr{position:relative;display:inline-block;margin-left:8px;background:var(--a);border-radius:999px;padding:3px 14px;font-weight:700;font-size:clamp(16px,3.6vw,22px)}"
		. ".sn-pub{position:relative;margin-top:8px;font-size:.82em;opacity:.9}.sn-vn{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:var(--s);border:1px solid #cfe1d2;border-radius:var(--r);padding:12px 14px;margin:0 0 12px}.sn-vn .ic{font-size:1.5em}.sn-vn .tx{flex:1;min-width:200px}.sn-vn b{display:block;color:var(--p);font-size:.95em}.sn-vn span{display:block;font-weight:600;text-align:left;line-height:1.5}.sn-vn a{background:var(--p);color:#fff!important;text-decoration:none;border-radius:999px;padding:6px 16px;font-weight:600;font-size:.9em;white-space:nowrap}.sn-st{display:inline-block;margin-top:10px;position:relative;border-radius:999px;padding:2px 14px;font-size:.8em;font-weight:700;background:rgba(255,255,255,.2)}.sn-st.x{background:#b3261e}"
		. ".sn-lay{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:14px;margin:12px 0;align-items:start}.sn-side{position:sticky;top:12px}.sn-lay.sn-1{grid-template-columns:minmax(0,1fr)}"
		. ".sn-card{background:#fff;border:1px solid #dfe6e0;border-radius:var(--r);padding:14px 12px;margin:0 0 12px}.sn-card h2,.sn-h{margin:0 0 8px;font-size:1.4em;color:var(--k);line-height:1.3}"
		. ".sn-info{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin:0 0 12px}.sn-info>div{background:var(--s);border:1px solid #cfe1d2;border-radius:var(--r);padding:12px}.sn-info b{display:block;color:var(--p);font-size:.95em}.sn-info span{display:block;font-weight:700;font-size:1.1em;line-height:1.5}"
		. ".sn-ck{list-style:none;margin:0;padding:0}.sn-ck li{padding:10px 0 10px 36px;border-bottom:1px dashed #cfd8d1;position:relative}.sn-ck li:last-child{border-bottom:0}.sn-ck li:before{content:'✓';position:absolute;left:0;top:12px;width:25px;height:25px;border-radius:50%;background:var(--p);color:#fff;font-size:15px;display:grid;place-items:center;line-height:1}"
		. ".sn-note{background:#fff7e0;border-left:4px solid var(--a);padding:10px 14px;border-radius:8px;margin:0 0 12px}.sn-txt p{margin:0 0 .6em}"
		. ".sn-im{display:grid;gap:10px;margin:0 0 12px}.sn-im img{width:100%;height:auto;border-radius:var(--r);display:block}.sn-im.g2{grid-template-columns:1fr 1fr}.sn-cap{text-align:center;font-size:.85em;color:#556;margin:-6px 0 12px}"
		. ".sn-btns{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin:0 0 12px}.sn-btn{display:flex;align-items:center;justify-content:center;gap:6px;padding:14px 18px;border-radius:12px;font-weight:700;text-decoration:none!important;color:#fff!important;background:var(--p);text-align:center}.sn-btn.pdf{background:var(--a)}.sn-btn.fb{background:#1877f2}.sn-btn.oth{background:var(--k)}"
		. ".sn-ph{display:flex;flex-wrap:wrap;gap:10px}.sn-ph a{background:var(--p);color:#fff!important;border-radius:10px;padding:8px 16px;font-weight:700;text-decoration:none!important;font-size:1.05em}"
		. ".sn-emb{position:relative;aspect-ratio:16/9;border-radius:var(--r);overflow:hidden;background:#000;margin:0 0 12px}.sn-emb iframe{position:absolute;inset:0;width:100%;height:100%;border:0}"
		. ".sn-side h3.sn-h2{margin-top:16px}.sn-side h3{margin:0 0 6px;font-size:1.15em;color:var(--k);border-bottom:2px solid var(--p);padding-bottom:6px}.sn-side ul{margin:0;padding:0;list-style:none}.sn-side li{padding:10px 0;border-bottom:1px solid #edf1ee;line-height:1.5}.sn-side li small{display:block;color:var(--p);font-weight:700;font-size:.85em}.sn-side li time{color:#889;font-size:.8em}.sn-side a{color:inherit;text-decoration:none}"
		. ".sn-all{display:block;text-align:center;background:var(--p);color:#fff!important;border-radius:12px;padding:12px;font-weight:700;text-decoration:none!important;margin-top:10px}.sn-share{font-size:.8em;color:#778}.sn-share a{color:var(--p)}"
		/* cards used in lists and sliders */
		. ".sn-grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(230px,1fr))}.sn-nc{background:#fff;border:1px solid #dfe6e0;border-radius:var(--r);padding:12px;text-decoration:none!important;color:inherit!important;display:block;position:relative}.sn-nc .th{aspect-ratio:16/9;border-radius:10px;margin:-2px 0 8px;display:grid;place-items:center;text-align:center;color:#fff;font-weight:700;line-height:1.3;padding:6px;background:linear-gradient(120deg,var(--k),var(--p) 60%,var(--a));overflow:hidden}.sn-nc .th img{width:100%;height:100%;object-fit:cover;display:block}"
		. ".sn-nc small{color:var(--p);font-weight:700;display:block;font-size:.8em}.sn-nc b{font-weight:700;display:block;line-height:1.35;margin:3px 0 6px;font-size:1em;color:var(--k)}.sn-nc time{font-size:.75em;color:#889}.sn-nc .ex{position:absolute;top:18px;right:18px;background:#b3261e;color:#fff;border-radius:999px;padding:1px 10px;font-size:.7em;font-weight:700}"
		. ".sn-rn{position:relative}.sn-trk{--pv:" . (int) ( 2 === (int) $o['per_view'] ? 2 : 1 ) . ";display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(230px,1fr))}.sn-dots{display:none;text-align:center;margin-top:6px}.sn-dots i{display:inline-block;width:8px;height:8px;border-radius:50%;background:#c4d2c8;margin:0 3px}.sn-dots i.on{background:var(--p);width:20px;border-radius:6px}"
		. ".sn-hl h3{margin:0 0 6px;font-size:1.2em;color:var(--k)}.sn-hl ul{margin:0;padding:0;list-style:none}.sn-hl li a{display:flex;align-items:baseline;gap:8px;padding:9px 4px;border-bottom:1px dashed #cfd8d1;text-decoration:none!important;color:#1d2b22!important;line-height:1.5}.sn-hl li a:hover .t{color:var(--p)}.sn-hl .t{flex:1;font-weight:600}.sn-hl time{font-size:.8em;color:#6a7d71;white-space:nowrap}.sn-hl .n{background:#e0457b;color:#fff;border-radius:999px;font-size:.7em;padding:1px 8px}.sn-tk{display:flex;align-items:center;gap:12px;background:var(--s);border-radius:12px;padding:8px 12px;overflow:hidden}.sn-tk .h{background:var(--k);color:#fff;padding:4px 12px;border-radius:999px;white-space:nowrap;font-size:.9em}.sn-tk .w{overflow:hidden;flex:1}.sn-tk ul{display:flex;gap:36px;width:max-content;animation:sntk linear infinite}.sn-tk .w:hover ul{animation-play-state:paused}.sn-tk li a{border:0;padding:0;white-space:nowrap}@keyframes sntk{to{transform:translateX(-50%)}}.sn-board{background:linear-gradient(135deg,var(--k),var(--p));color:#fff;border-radius:var(--r);padding:22px 20px;margin:0 0 12px}.sn-board h2{margin:0 0 6px;color:#fff;font-size:1.6em}.sn-board p{margin:0 0 14px;opacity:.92}.sn-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px}.sn-tiles div{background:#fff;color:var(--k);border-radius:12px;padding:10px 12px}.sn-tiles b{display:block;font-size:1.5em;line-height:1.2}.sn-tiles span{font-size:.85em;color:#52645a}.sn-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}.sn-tabs a{padding:8px 16px;border:1px solid #cfd8d1;border-radius:999px;text-decoration:none!important;color:var(--k)!important;background:#fff;font-weight:600}.sn-tabs a.on{background:var(--k);color:#fff!important;border-color:var(--k)}"
		. ".sn-fl{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}.sn-fl input,.sn-fl select{padding:9px 12px;border:1px solid #cfd8d1;border-radius:10px;font:inherit;font-size:.9em;background:#fff}.sn-fl button{padding:9px 18px;border:0;border-radius:10px;background:var(--p);color:#fff;font:inherit;font-size:.9em;font-weight:700;cursor:pointer}.sn-pg{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}.sn-pg a,.sn-pg span{padding:7px 14px;border-radius:8px;border:1px solid #cfd8d1;text-decoration:none;color:var(--k)}.sn-pg span{background:var(--p);color:#fff;border-color:var(--p)}"
		/* activities */
		. ".sn-act{text-align:center}.sn-act small{display:block;color:var(--p);font-weight:700;font-size:.85em;line-height:1.3}.sn-act h2{margin:0;font-size:1.5em;color:var(--k);line-height:1.25}.sn-act .lead{margin:0 0 6px;color:#556;font-size:.9em;line-height:1.4}"
		. ".sn-aw{position:relative}.sn-as{display:flex;gap:10px;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;padding:2px;scroll-behavior:smooth}.sn-as::-webkit-scrollbar{display:none}.sn-as .c{flex:0 0 min(84%,290px);scroll-snap-align:start;background:#fff;border:1px solid #dfe6e0;border-radius:14px;padding:12px 14px;text-align:left;line-height:1.45}"
		. ".sn-as .c i{display:grid;place-items:center;width:46px;height:46px;border-radius:12px;background:var(--s);font-style:normal;font-size:26px;margin-bottom:6px}.sn-as .c b{font-weight:700;display:block;font-size:1.1em;color:var(--k);line-height:1.3}.sn-as .c p{margin:2px 0 6px;font-size:.9em;color:#445}.sn-as .c a{color:var(--p);font-weight:700;text-decoration:none}"
		. ".sn-ar{display:none;position:absolute;top:50%;transform:translateY(-50%);width:44px;height:44px;border-radius:50%;border:0;background:var(--p);color:#fff;font-size:22px;cursor:pointer;box-shadow:0 3px 10px rgba(0,0,0,.25);z-index:2}.sn-ar.l{left:-4px}.sn-ar.r{right:-4px}"
		. "@media(min-width:860px){.sn-aw{padding:0 26px}.sn-ar{display:block}.sn-as .c{flex:0 0 calc((100% - 30px)/4)}}"
		. "@media(max-width:859px){.sn{font-size:{$fm}px}.sn-lay{grid-template-columns:1fr}.sn-side{position:static;margin-top:6px}.sn-btns{grid-template-columns:1fr}.sn-trk.sl{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none}.sn-trk.sl::-webkit-scrollbar{display:none}.sn-trk.sl .sn-nc{flex:0 0 calc((100% - (var(--pv) - 1)*12px)/var(--pv));scroll-snap-align:start}.sn-dots{display:block}}";
	if ( ! empty( $o['css'] ) ) {
		$css .= wp_strip_all_tags( $o['css'] );
	}
	return '<style>' . $css . '</style>';
}

function csa_sn_drive( $u ) {
	$u = trim( (string) $u );
	if ( preg_match( '#drive\.google\.com/file/d/([\w-]+)#', $u, $m ) || preg_match( '#drive\.google\.com/(?:open|uc)\?(?:.*&)?id=([\w-]+)#', $u, $m ) ) {
		return 'https://drive.google.com/uc?export=download&id=' . $m[1];
	}
	return $u;
}

function csa_sn_lines( $s ) {
	return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $s ) ), 'strlen' ) );
}

function csa_sn_get( $id ) {
	$d = get_post_meta( $id, '_sn', true );
	return is_array( $d ) ? $d : array();
}

function csa_sn_expired( $id ) {
	$e = (string) get_post_meta( $id, '_sn_expiry', true );
	return $e && strtotime( $e . ' 23:59:59' ) < current_time( 'timestamp' );
}

function csa_sn_block( $b ) {
	$t = $b['t'] ?? '';
	$h = ! empty( $b['h'] ) ? '<h2>' . esc_html( $b['h'] ) . '</h2>' : '';
	switch ( $t ) {
		case 'cards':
			$o = '';
			$vn = '';
			foreach ( (array) ( $b['rows'] ?? array() ) as $r ) {
				if ( '' === trim( (string) ( $r['v'] ?? '' ) ) ) {
					continue;
				}
				if ( preg_match( '/ভেন্যু|স্থান|ঠিকানা|কেন্দ্র|venue/iu', (string) ( $r['l'] ?? '' ) ) ) {
					$m   = ! empty( $r['m'] ) ? $r['m'] : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $r['v'] );
					$vn .= '<div class="sn-vn"><div class="ic">📍</div><div class="tx"><b>' . esc_html( $r['l'] ) . '</b><span>' . esc_html( $r['v'] ) . '</span></div><a href="' . esc_url( $m ) . '" target="_blank" rel="noopener">🗺 ম্যাপে দেখুন</a></div>';
				} else {
					$o .= '<div><b>' . esc_html( $r['l'] ?? '' ) . '</b><span>' . esc_html( $r['v'] ) . '</span></div>';
				}
			}
			return ( $o ? '<div class="sn-info">' . $o . '</div>' : '' ) . $vn;
		case 'list':
			$o = '';
			foreach ( csa_sn_lines( $b['lines'] ?? '' ) as $l ) {
				$o .= '<li>' . esc_html( $l ) . '</li>';
			}
			return $o ? '<div class="sn-card">' . $h . '<ul class="sn-ck">' . $o . '</ul></div>' : '';
		case 'text':
			$o = '';
			foreach ( csa_sn_lines( $b['body'] ?? '' ) as $l ) {
				$o .= '<p>' . esc_html( $l ) . '</p>';
			}
			return $o ? '<div class="sn-card sn-txt">' . $h . $o . '</div>' : '';
		case 'note':
			return '' !== trim( (string) ( $b['body'] ?? '' ) ) ? '<div class="sn-note">✨ ' . esc_html( $b['body'] ) . '</div>' : '';
		case 'image':
			$o = '';
			foreach ( (array) ( $b['ids'] ?? array() ) as $i ) {
				$img = wp_get_attachment_image( (int) $i, 'large', false, array( 'loading' => 'lazy' ) );
				if ( $img ) {
					$full = wp_get_attachment_image_url( (int) $i, 'full' );
					$o   .= '<a href="' . esc_url( $full ) . '" target="_blank" rel="noopener">' . $img . '</a>';
				}
			}
			if ( ! $o ) {
				return '';
			}
			return '<div class="sn-im' . ( count( (array) $b['ids'] ) > 1 ? ' g2' : '' ) . '">' . $o . '</div>' . ( ! empty( $b['cap'] ) ? '<p class="sn-cap">' . esc_html( $b['cap'] ) . '</p>' : '' );
		case 'buttons':
			$o = '';
			$ic = array( 'apply' => '✍️', 'pdf' => '📄', 'fb' => '👍', 'oth' => '🔗' );
			foreach ( (array) ( $b['items'] ?? array() ) as $it ) {
				$k = isset( $ic[ $it['kind'] ?? '' ] ) ? $it['kind'] : 'oth';
				$u = 'pdf' === $k ? csa_sn_drive( $it['url'] ?? '' ) : trim( (string) ( $it['url'] ?? '' ) );
				if ( '' === $u ) {
					continue;
				}
				$o .= '<a class="sn-btn ' . ( 'apply' === $k ? '' : $k ) . '" target="_blank" rel="noopener" href="' . esc_url( $u ) . '">' . $ic[ $k ] . ' ' . esc_html( ( $it['label'] ?? '' ) ?: 'লিংক' ) . '</a>';
			}
			return $o ? '<div class="sn-btns">' . $o . '</div>' : '';
		case 'phones':
			$o = '';
			foreach ( csa_sn_lines( $b['nums'] ?? '' ) as $n ) {
				$o .= '<a href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', strtr( $n, array_flip( array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) ) ) ) ) . '">' . esc_html( $n ) . '</a>';
			}
			return $o ? '<div class="sn-card"><b>📞 ' . esc_html( ( $b['h'] ?? '' ) ?: 'বিস্তারিত যোগাযোগ করুন:' ) . '</b><div class="sn-ph" style="margin-top:8px">' . $o . '</div></div>' : '';
		case 'video':
			$src = csa_sn_embed_url( 'youtube', $b['url'] ?? '' ) ?: csa_sn_embed_url( 'fb_video', $b['url'] ?? '' );
			return $src ? $h . '<div class="sn-emb"><iframe src="' . esc_url( $src ) . '" loading="lazy" allowfullscreen title="video"></iframe></div>' : '';
		case 'html':
			return ! empty( $b['code'] ) ? '<div class="sn-card">' . $b['code'] . '</div>' : '';
	}
	return '';
}

/** The whole page of one notice. */
function csa_sn_render( $id ) {
	$d      = csa_sn_get( $id );
	$o      = csa_sn_opts();
	$types  = $o['types'];
	$tl     = $types[ $d['type'] ?? '' ]['label'] ?? '';
	$topic  = ( $d['topic'] ?? '' ) ?: ( $types[ $d['type'] ?? '' ]['topic'] ?? get_the_title( $id ) );
	$branch = trim( (string) ( $d['branch'] ?? '' ) );
	$exp    = csa_sn_expired( $id );
	$brand  = trim( (string) $o['brand'] );
	$pub    = '<div class="sn-pub">প্রকাশ: ' . esc_html( csa_sn_date( (int) get_post_time( 'U', true, $id ) ) ) . '</div>';
	$bn     = '<div class="sn-bn">' . ( $brand ? '<div class="sn-brand">' . esc_html( $brand ) . '</div>' : '' ) . ( $branch ? '<div class="sn-bt">' . esc_html( $branch ) . '</div>' : '' ) . '<span class="sn-sub">' . esc_html( $topic ) . '</span>' . ( ! empty( $d['year'] ) ? '<span class="sn-yr">' . esc_html( $d['year'] ) . '</span>' : '' ) . ( get_post_meta( $id, '_sn_expiry', true ) ? '<div><span class="sn-st' . ( $exp ? ' x' : '' ) . '">' . ( $exp ? 'আবেদনের মেয়াদ শেষ' : 'চলমান' ) . '</span></div>' : '' ) . $pub . '</div>';
	$main   = '';
	foreach ( (array) ( $d['blocks'] ?? array() ) as $b ) {
		$main .= csa_sn_block( $b );
	}
	$prev = '';
	$seen = array( $id );
	$mk   = function ( $p ) use ( $types ) {
		$pt = (string) get_post_meta( $p->ID, '_sn_type', true );
		$pb = (string) get_post_meta( $p->ID, '_sn_branch', true );
		return '<li><a href="' . esc_url( csa_sn_url( $p ) ) . '"><small>' . esc_html( ( $types[ $pt ]['label'] ?? '' ) . ( $pb ? ' · ' . $pb : '' ) ) . '</small>' . esc_html( get_the_title( $p ) ) . '<br><time>' . esc_html( csa_sn_date( get_post_time( 'U', true, $p ) ) ) . '</time></a></li>';
	};
	// Newest first, every kind mixed; each item shows its own kind as a small tag.
	foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 6, 'orderby' => 'date', 'order' => 'DESC', 'post__not_in' => $seen, 'meta_query' => array( array( 'key' => '_sn_is', 'value' => '1' ) ) ) ) as $p ) {
		$prev .= $mk( $p );
	}
	// Older notices that live on the site's old pages: "title | link" per line in the settings.
	$old = '';
	foreach ( array_slice( preg_split( '/\r?\n/', (string) $o['old'] ), 0, 12 ) as $ln ) {
		$pp = array_map( 'trim', explode( '|', $ln, 2 ) );
		if ( 2 === count( $pp ) && '' !== $pp[0] && preg_match( '#^https?://#i', $pp[1] ) && trim( $pp[1], '/' ) !== trim( get_permalink( $id ), '/' ) ) {
			$old .= '<li><a href="' . esc_url( $pp[1] ) . '">' . esc_html( $pp[0] ) . '</a></li>';
		}
	}
	$lp   = csa_sn_listpage_id();
	$all  = $lp && get_post_status( $lp ) ? get_permalink( $lp ) : '';
	$side = ( $prev || $old ) ? '<aside class="sn-card sn-side"><h3>আগের নোটিশ</h3>' . ( $prev ? '<ul>' . $prev . '</ul>' : '' ) . ( $old ? '<h3 class="sn-h2">পুরোনো পেজের নোটিশ</h3><ul class="sn-olds">' . $old . '</ul>' : '' ) . ( $all ? '<a class="sn-all" href="' . esc_url( $all ) . '">সব নোটিশ দেখুন →</a>' : '' ) . '</aside>' : '';
	$url  = rawurlencode( get_permalink( $id ) );
	$share = '<p class="sn-share">শেয়ার: <a target="_blank" rel="noopener" href="https://wa.me/?text=' . $url . '">WhatsApp</a> · <a target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=' . $url . '">Facebook</a></p>';
	return csa_sn_css() . '<div class="sn">' . $bn . '<div class="sn-lay' . ( $side ? '' : ' sn-1' ) . '"><div>' . $main . $share . '</div>' . $side . '</div></div>';
}

add_filter( 'the_content', function ( $c ) {
	if ( ! csa_sn_single() || ! in_the_loop() || ! is_main_query() ) {
		return $c;
	}
	$d = csa_sn_get( get_the_ID() );
	if ( ! empty( $d['detached'] ) ) {
		return $c;
	}
	try {
		return csa_sn_render( get_the_ID() );
	} catch ( \Throwable $e ) {
		csa_sn_note_error( $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() );
		return $c . ( current_user_can( 'edit_posts' ) ? '<p style="color:#b00">নোটিশ দেখাতে সমস্যা হয়েছে: ' . esc_html( $e->getMessage() ) . '</p>' : '' );
	}
}, 20 );

/** Headline-style list of the latest notices; can be limited to WordPress categories and can include normal posts of those categories. */
function csa_sn_hl_opts() {
	return wp_parse_args( (array) csa_sn_opt( 'hl' ), array( 'pages' => array(), 'pos' => 'top', 'cats' => array(), 'limit' => 8, 'style' => 'list', 'posts' => 1, 'title' => 'সর্বশেষ নোটিশ' ) );
}

function csa_sn_headlines( $a ) {
	$a    = wp_parse_args( $a, csa_sn_hl_opts() );
	$cats = array_values( array_filter( array_map( 'absint', (array) $a['cats'] ) ) );
	$args = array( 'post_status' => 'publish', 'posts_per_page' => max( 1, min( 30, (int) $a['limit'] ) ), 'orderby' => 'date', 'order' => 'DESC', 'post_type' => 'post', 'ignore_sticky_posts' => true );
	if ( $cats ) {
		$args['category__in'] = $cats;
	}
	if ( ! $cats || ! $a['posts'] ) {
		$args['meta_key']   = '_sn_is';
		$args['meta_value'] = '1';
	}
	$li = '';
	foreach ( ( new WP_Query( $args ) )->posts as $p ) {
		$url = csa_sn_url( $p );
		$new = ( time() - get_post_time( 'U', true, $p ) ) < 7 * DAY_IN_SECONDS;
		$li .= '<li><a href="' . esc_url( $url ) . '"><span class="b">🔔</span><span class="t">' . esc_html( get_the_title( $p ) ) . '</span>' . ( $new ? '<span class="n">নতুন</span>' : '' ) . '<time>' . esc_html( csa_sn_date( get_post_time( 'U', true, $p ) ) ) . '</time></a></li>';
	}
	if ( '' === $li ) {
		return '';
	}
	if ( 'ticker' === $a['style'] ) {
		return csa_sn_css() . '<div class="sn sn-hl sn-tk"><b class="h">' . esc_html( $a['title'] ) . '</b><div class="w"><ul style="animation-duration:' . max( 12, 7 * substr_count( $li, '<li>' ) ) . 's">' . $li . $li . '</ul></div></div>';
	}
	return csa_sn_css() . '<div class="sn sn-hl"><h3>' . esc_html( $a['title'] ) . '</h3><ul>' . $li . '</ul></div>';
}

add_shortcode( 'shaheen_notice_headlines', function ( $a ) {
	$a = shortcode_atts( array( 'category' => '', 'limit' => '', 'style' => '', 'title' => '', 'posts' => '' ), $a );
	$o = array();
	if ( '' !== $a['category'] ) {
		$o['cats'] = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $a['category'] ) ) ) as $c ) {
			$t = is_numeric( $c ) ? get_category( (int) $c ) : get_category_by_slug( $c );
			if ( $t && ! is_wp_error( $t ) ) {
				$o['cats'][] = (int) $t->term_id;
			}
		}
	}
	foreach ( array( 'limit', 'style', 'title', 'posts' ) as $k ) {
		if ( '' !== $a[ $k ] ) {
			$o[ $k ] = $a[ $k ];
		}
	}
	return csa_sn_headlines( $o );
} );

// Pages chosen in settings get the headline block on top or at the bottom; their own content is not touched.
add_filter( 'the_content', function ( $c ) {
	$h = csa_sn_hl_opts();
	if ( ! $h['pages'] || ! is_page( array_map( 'intval', (array) $h['pages'] ) ) || ! in_the_loop() || ! is_main_query() || ! empty( $_GET['snr'] ) ) {
		return $c;
	}
	$b = csa_sn_headlines( array() );
	return 'bottom' === $h['pos'] ? $c . $b : $b . $c;
}, 31 );

// The site's own "notice" page (made with Elementor etc.) shows the list at its end, without editing it.
add_filter( 'the_content', function ( $c ) {
	$lp = csa_sn_opt( 'replace' ) ? csa_sn_listpage_id() : (int) csa_sn_opt( 'list_page' ); // the old page stays as it is unless chosen / the board mode is on
	if ( ! $lp || ! is_page( $lp ) || ! in_the_loop() || ! is_main_query() || ! empty( $GLOBALS['csa_sn_list_done'] ) ) {
		return $c;
	}
	if ( ! empty( $_GET['snr'] ) && csa_sn_read_ok( sanitize_text_field( wp_unslash( $_GET['snr'] ) ) ) ) {
		return $c; // The plugin is reading the page's own content.
	}
	return csa_sn_opt( 'replace' ) ? csa_sn_board() : $c . do_shortcode( '[shaheen_notice_list]' );
}, 30 );

/** Remember the last error of the notice tool, so it can be shown in Page Builder (a blank page leaves no other trace). */
function csa_sn_note_error( $msg ) {
	update_option( 'csa_sn_fatal', array( 't' => time(), 'm' => mb_substr( (string) $msg, 0, 600 ) ), false );
}

function csa_sn_watch() {
	static $on = false;
	if ( $on ) {
		return;
	}
	$on = true;
	register_shutdown_function( function () {
		$e = error_get_last();
		if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			csa_sn_note_error( $e['message'] . ' @' . basename( $e['file'] ) . ':' . $e['line'] );
		}
	} );
}
add_action( 'load-post.php', 'csa_sn_watch' );
add_action( 'load-post-new.php', 'csa_sn_watch' );
add_action( 'template_redirect', function () {
	if ( csa_sn_single() ) {
		csa_sn_watch();
	}
} );
add_action( 'admin_post_sn_clearerr', function () {
	if ( current_user_can( 'manage_options' ) && check_admin_referer( 'sn_clearerr' ) ) {
		delete_option( 'csa_sn_fatal' );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=sef-builder' ) );
	exit;
} );

add_action( 'wp_head', function () {
	if ( ! csa_sn_single() ) {
		return;
	}
	if ( csa_sn_opt( 'hide_title' ) ) {
		echo '<style>body.sn-notice .entry-header,body.sn-notice .entry-title,body.sn-notice .page-title,body.sn-notice .post-title,body.sn-notice .entry-meta,body.sn-notice .post-meta,body.sn-notice .ast-blog-single-element,body.sn-notice .ast-blog-meta-container,body.sn-notice .ast-single-post-order,body.sn-notice .elementor-widget-post-info,body.sn-notice .elementor-widget-author-box,body.sn-notice .post-navigation,body.sn-notice .comments-area,body.sn-notice .ast-single-related-posts-container,body.sn-notice .ast-author-box,body.sn-notice .ast-single-author-box,body.sn-notice .entry-footer,body.sn-notice .post-thumbnail,body.sn-notice .elementor-widget-theme-post-title,body.sn-notice .wp-block-post-title,body.sn-notice .wp-block-post-author,body.sn-notice .wp-block-post-date,body.sn-notice .wp-block-post-terms,body.sn-notice .wp-block-post-featured-image,body.sn-notice .elementor-widget-theme-post-featured-image{display:none!important}body.sn-notice .entry-content,body.sn-notice .site-main,body.sn-notice article{margin:0!important;padding:0!important;background:transparent!important;box-shadow:none!important;border:0!important}body.sn-notice #primary,body.sn-notice .content-area,body.sn-notice #main,body.sn-notice .ast-article-single,body.sn-notice .ast-article-post{margin:0!important;padding:0!important;background:transparent!important}body.sn-notice .site-content,body.sn-notice #content,body.sn-notice .site-content>.ast-container,body.sn-notice #content>.ast-container{padding-top:0!important;padding-bottom:0!important;margin-top:0!important;margin-bottom:0!important}body.sn-notice .ast-separate-container .ast-article-single:not(.ast-related-post){padding:0!important}body.sn-notice #secondary,body.sn-notice .widget-area{display:none!important}body.sn-notice .site-main>article:last-child,body.sn-notice .entry-content>*:last-child{margin-bottom:0!important}body.sn-notice .ast-container{padding-bottom:0!important}body.sn-notice .site-footer,body.sn-notice footer{margin-top:0!important}</style><script>document.addEventListener("DOMContentLoaded",function(){if(!document.body.classList.contains("sn-notice"))return;var n=document.querySelectorAll("a[href*=\\"/author/\\"]");for(var i=0;i<n.length;i++){var e=n[i].closest("[class*=meta],[class*=post-info],[class*=elementor-widget],header,div");if(e&&!e.querySelector(".sn")&&!e.closest(".sn")&&e.textContent.length<120)e.style.display="none"}});</script>';
	}
	// Google JobPosting for recruitment notices.
	$id = get_the_ID();
	$d  = csa_sn_get( $id );
	$ty = csa_sn_opt( 'types' )[ $d['type'] ?? '' ] ?? array();
	if ( ! empty( $ty['job'] ) ) {
		$j = array(
			'@context'           => 'https://schema.org',
			'@type'              => 'JobPosting',
			'title'              => ( $d['topic'] ?? '' ) ?: get_the_title( $id ),
			'description'        => ( $d['summary'] ?? '' ) ?: wp_strip_all_tags( get_the_title( $id ) ),
			'datePosted'         => get_post_time( 'c', true, $id ),
			'hiringOrganization' => array( '@type' => 'Organization', 'name' => 'Shaheen Education Family', 'sameAs' => home_url( '/' ) ),
			'jobLocation'        => array( '@type' => 'Place', 'address' => array( '@type' => 'PostalAddress', 'addressLocality' => $d['branch'] ?? '', 'addressCountry' => 'BD' ) ),
		);
		$e = get_post_meta( $id, '_sn_expiry', true );
		if ( $e ) {
			$j['validThrough'] = $e . 'T23:59:59+06:00';
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $j, CSA_JSON ) . '</script>';
	}
} );

/* ------------------------------------------------------------------ */
/* Saving                                                               */
/* ------------------------------------------------------------------ */

function csa_sn_clean_blocks( $in ) {
	$out = array();
	foreach ( (array) $in as $b ) {
		$t = sanitize_key( $b['t'] ?? '' );
		switch ( $t ) {
			case 'cards':
				$rows = array();
				foreach ( (array) ( $b['rows'] ?? array() ) as $r ) {
					$rows[] = array( 'l' => sanitize_text_field( $r['l'] ?? '' ), 'v' => sanitize_text_field( $r['v'] ?? '' ), 'm' => esc_url_raw( (string) ( $r['m'] ?? '' ) ) );
				}
				$out[] = array( 't' => $t, 'rows' => $rows );
				break;
			case 'list':
				$out[] = array( 't' => $t, 'h' => sanitize_text_field( $b['h'] ?? '' ), 'lines' => sanitize_textarea_field( $b['lines'] ?? '' ) );
				break;
			case 'text':
				$out[] = array( 't' => $t, 'h' => sanitize_text_field( $b['h'] ?? '' ), 'body' => sanitize_textarea_field( $b['body'] ?? '' ) );
				break;
			case 'note':
				$out[] = array( 't' => $t, 'body' => sanitize_textarea_field( $b['body'] ?? '' ) );
				break;
			case 'image':
				$out[] = array( 't' => $t, 'ids' => array_values( array_filter( array_map( 'intval', (array) ( $b['ids'] ?? array() ) ) ) ), 'cap' => sanitize_text_field( $b['cap'] ?? '' ) );
				break;
			case 'buttons':
				$it = array();
				foreach ( (array) ( $b['items'] ?? array() ) as $x ) {
					$it[] = array( 'label' => sanitize_text_field( $x['label'] ?? '' ), 'url' => esc_url_raw( $x['url'] ?? '' ), 'kind' => sanitize_key( $x['kind'] ?? 'oth' ) );
				}
				$out[] = array( 't' => $t, 'items' => $it );
				break;
			case 'phones':
				$out[] = array( 't' => $t, 'h' => sanitize_text_field( $b['h'] ?? '' ), 'nums' => sanitize_textarea_field( $b['nums'] ?? '' ) );
				break;
			case 'video':
				$out[] = array( 't' => $t, 'h' => sanitize_text_field( $b['h'] ?? '' ), 'url' => esc_url_raw( $b['url'] ?? '' ) );
				break;
			case 'html':
				$out[] = array( 't' => $t, 'code' => current_user_can( 'unfiltered_html' ) ? (string) ( $b['code'] ?? '' ) : wp_kses_post( $b['code'] ?? '' ) );
				break;
		}
	}
	return $out;
}

function csa_sn_clean( $d ) {
	$d = is_array( $d ) ? $d : array();
	return array(
		'type'     => sanitize_key( $d['type'] ?? '' ),
		'branch'   => sanitize_text_field( $d['branch'] ?? '' ),
		'topic'    => sanitize_text_field( $d['topic'] ?? '' ),
		'year'     => sanitize_text_field( $d['year'] ?? '' ),
		'summary'  => sanitize_textarea_field( $d['summary'] ?? '' ),
		'expiry'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d['expiry'] ?? '' ) ? $d['expiry'] : '',
		'cats'     => array_values( array_filter( array_map( 'absint', (array) ( $d['cats'] ?? array() ) ) ) ),
		'newcat'   => sanitize_text_field( $d['newcat'] ?? '' ),
		'slug'     => sanitize_title( $d['slug'] ?? '' ),
		'title'    => sanitize_text_field( $d['title'] ?? '' ),
		'seo_t'    => sanitize_text_field( $d['seo_t'] ?? '' ),
		'seo_d'    => sanitize_textarea_field( $d['seo_d'] ?? '' ),
		'seo_k'    => sanitize_text_field( $d['seo_k'] ?? '' ),
		'detached' => empty( $d['detached'] ) ? 0 : 1,
		'blocks'   => csa_sn_clean_blocks( $d['blocks'] ?? array() ),
	);
}

// A notice may be saved with an empty title box (the title is made from the form).
add_filter( 'wp_insert_post_empty_content', function ( $empty ) {
	if ( $empty && isset( $_POST['sn_data'] ) && ! empty( $_POST['sn_nonce'] ) && wp_verify_nonce( $_POST['sn_nonce'], 'sn_save' ) ) {
		return false;
	}
	return $empty;
} );

add_filter( 'wp_insert_post_data', function ( $data, $post ) {
	if ( 'post' !== $data['post_type'] || ! isset( $_POST['sn_data'] ) || empty( $_POST['sn_nonce'] ) || ! wp_verify_nonce( $_POST['sn_nonce'], 'sn_save' ) ) {
		return $data;
	}
	$d  = csa_sn_clean( json_decode( wp_unslash( $_POST['sn_data'] ), true ) );
	$ts = csa_sn_opt( 'types' )[ $d['type'] ] ?? array();
	if ( '' !== $d['title'] ) {
		$data['post_title'] = $d['title'];
	}
	if ( '' === trim( (string) $data['post_title'] ) || 'Auto Draft' === $data['post_title'] ) {
		$brand = trim( (string) csa_sn_opt( 'brand' ) );
		$who   = trim( $brand . ( $brand && $d['branch'] ? ', ' : '' ) . $d['branch'] );
		$t     = trim( ( $d['topic'] ?: ( $ts['topic'] ?? 'নোটিশ' ) ) . ( $d['year'] ? ' ' . $d['year'] : '' ) . ( $who ? ' — ' . $who : '' ) );
		$data['post_title'] = $t;
	}
	if ( $d['slug'] ) {
		$data['post_name'] = $d['slug'];
	}
	return $data;
}, 10, 2 );

add_action( 'save_post_post', function ( $id, $post ) {
	if ( ! isset( $_POST['sn_data'] ) || empty( $_POST['sn_nonce'] ) || ! wp_verify_nonce( $_POST['sn_nonce'], 'sn_save' ) || ! current_user_can( 'edit_post', $id ) || wp_is_post_revision( $id ) ) {
		return;
	}
	$d    = csa_sn_clean( json_decode( wp_unslash( $_POST['sn_data'] ), true ) );
	$old  = csa_sn_get( $id );
	$first = ! csa_sn_is( $id );
	update_post_meta( $id, '_sn_is', 1 );
	if ( ! empty( $_POST['sn_sec_nonce'] ) && wp_verify_nonce( $_POST['sn_sec_nonce'], 'sn_sec' ) ) {
		$sec = array_values( array_intersect( (array) ( $_POST['sn_sec'] ?? array() ), array( 'job', 'result', 'form', 'admission' ) ) );
		if ( $sec ) {
			update_post_meta( $id, '_sn_sec', $sec );
		} else {
			delete_post_meta( $id, '_sn_sec' );
		}
	}
	update_post_meta( $id, '_sn', wp_slash( $d ) );
	update_post_meta( $id, '_sn_type', $d['type'] );
	update_post_meta( $id, '_sn_branch', $d['branch'] );
	update_post_meta( $id, '_sn_expiry', $d['expiry'] );
	$d = csa_sn_autoseo( $id, $d );
	if ( $first ) { // a new notice starts in the "নোটিশ" category; change it in Posts at any time
		$cur = wp_get_post_categories( $id );
		if ( ! $cur || ( 1 === count( $cur ) && (int) $cur[0] === (int) get_option( 'default_category' ) ) ) {
			$tm = term_exists( 'নোটিশ', 'category' ) ?: wp_insert_term( 'নোটিশ', 'category' );
			if ( ! is_wp_error( $tm ) ) {
				wp_set_post_categories( $id, array( (int) ( is_array( $tm ) ? $tm['term_id'] : $tm ) ) );
			}
		}
	}
	if ( $d['summary'] ) {
		wp_update_post_excerpt_safe( $id, $d['summary'] );
	}
	update_post_meta( $id, '_sn', wp_slash( $d ) );

	// Switching to hand editing: keep what is shown now as the post's own content.
	static $busy = false;
	if ( $d['detached'] && empty( $old['detached'] ) && ! $busy ) {
		$busy = true;
		wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( csa_sn_render( $id ) ) ) );
		$busy = false;
	}
}, 10, 2 );

/** Fills the summary and the Google/SEO fields from the form when they are empty (no AI cost). */
/** Written after Yoast / Rank Math have saved their own (empty) boxes, so the notice's values win. */
add_action( 'wp_insert_post', function ( $id, $post ) {
	if ( ! csa_sn_is( $id ) || wp_is_post_revision( $id ) || ! function_exists( 'csa_seo_set' ) || ! function_exists( 'csa_seo_plugin' ) || ! csa_seo_plugin() ) {
		return;
	}
	$d = csa_sn_get( $id );
	if ( ! empty( $d['seo_t'] ) || ! empty( $d['seo_d'] ) || ! empty( $d['seo_k'] ) ) {
		csa_seo_set( $id, array( 'title' => $d['seo_t'] ?? '', 'description' => $d['seo_d'] ?? '', 'keyword' => $d['seo_k'] ?? '' ), 'Notice SEO' );
	}
}, 99, 2 );

// The notice form has its own Google/SEO boxes, so the SEO plugins' boxes are not shown on notices.
add_action( 'add_meta_boxes', function ( $pt, $post ) {
	if ( ! csa_sn_editing( $post ) ) {
		return;
	}
	foreach ( array( 'wpseo_meta', 'rank_math_metabox', 'aioseo-settings' ) as $m ) {
		remove_meta_box( $m, 'post', 'normal' );
		remove_meta_box( $m, 'post', 'advanced' );
	}
}, 999, 2 );

// No SEO plugin: print the description ourselves.
add_action( 'wp_head', function () {
	if ( ! csa_sn_single() || ( function_exists( 'csa_seo_plugin' ) && csa_seo_plugin() ) ) {
		return;
	}
	$d = csa_sn_get( get_the_ID() );
	if ( ! empty( $d['seo_d'] ) ) {
		echo '<meta name="description" content="' . esc_attr( $d['seo_d'] ) . '">' . "\n";
	}
}, 5 );

function csa_sn_autoseo( $id, $d ) {
	$ts    = csa_sn_opt( 'types' )[ $d['type'] ?? '' ] ?? array();
	$topic = $d['topic'] ?: ( $ts['topic'] ?? 'নোটিশ' );
	$brand = trim( (string) csa_sn_opt( 'brand' ) );
	$who   = trim( $brand . ( $brand && $d['branch'] ? ', ' : '' ) . $d['branch'] );
	$bits  = array();
	foreach ( (array) $d['blocks'] as $b ) {
		if ( 'cards' === ( $b['t'] ?? '' ) ) {
			foreach ( array_slice( (array) $b['rows'], 0, 3 ) as $r ) {
				if ( ! empty( $r['l'] ) && ! empty( $r['v'] ) ) {
					$bits[] = $r['l'] . ': ' . $r['v'];
				}
			}
			break;
		}
	}
	if ( '' === $d['summary'] ) {
		$d['summary'] = trim( $topic . ( $d['year'] ? ' ' . $d['year'] : '' ) . ( $who ? ' — ' . $who . '।' : '।' ) . ( $bits ? ' ' . implode( '; ', array_slice( $bits, 0, 2 ) ) . '।' : '' ) );
	}
	if ( '' === $d['seo_t'] ) {
		$d['seo_t'] = trim( $topic . ( $d['year'] ? ' ' . $d['year'] : '' ) . ( $who ? ' | ' . $who : '' ) );
	}
	if ( '' === $d['seo_d'] ) {
		$d['seo_d'] = mb_substr( $d['summary'], 0, 155 );
	}
	if ( '' === $d['seo_k'] ) {
		$d['seo_k'] = trim( $topic . ( $d['year'] ? ' ' . $d['year'] : '' ) );
	}
	return $d;
}

function wp_update_post_excerpt_safe( $id, $text ) {
	global $wpdb;
	$wpdb->update( $wpdb->posts, array( 'post_excerpt' => $text ), array( 'ID' => $id ) );
}

/* ------------------------------------------------------------------ */
/* Edit screen                                                          */
/* ------------------------------------------------------------------ */

add_action( 'add_meta_boxes', function ( $pt, $post ) {
	if ( ! csa_sn_editing( $post ) ) {
		return;
	}
	add_meta_box( 'sn_box', 'নোটিশের ঘর', function ( $post ) {
		wp_nonce_field( 'sn_save', 'sn_nonce' );
		echo '<input type="hidden" name="sn_data" id="sn_data" value="' . esc_attr( wp_json_encode( csa_sn_get( $post->ID ), CSA_JSON ) ) . '"><div id="sn-app"><p>লোড হচ্ছে…</p></div>';
	}, 'post', 'normal', 'high' );
	add_meta_box( 'sn_sec', 'নোটিশ বোর্ডে কোন বিভাগে দেখাবে', function ( $post ) {
		wp_nonce_field( 'sn_sec', 'sn_sec_nonce' );
		$o   = csa_sn_board_opts();
		$has = (array) get_post_meta( $post->ID, '_sn_sec', true );
		$cur = $has ? $has : ( csa_sn_is( $post->ID ) ? csa_sn_secs( $post->ID ) : array() );
		echo '<p class="description">একাধিক টিক দিতে পারেন — নোটিশটি প্রতিটি বিভাগের তালিকা ও গণনায় আসবে। কিছু না দিলে নোটিশের ধরন অনুযায়ী বসবে।</p>';
		foreach ( array( 'job' => $o['l_job'], 'result' => $o['l_result'], 'form' => $o['l_form'], 'admission' => 'ভর্তি' ) as $k => $l ) {
			echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="sn_sec[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $cur, true ), true, false ) . '> ' . esc_html( $l ) . '</label>';
		}
	}, 'post', 'side', 'high' );
}, 10, 2 );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	$scr = get_current_screen();
	if ( false !== strpos( $hook, 'sn-settings' ) || false !== strpos( $hook, 'sn-import' ) || false !== strpos( $hook, 'sn-board' ) || false !== strpos( $hook, 'sef-builder' ) ) {
		wp_enqueue_style( 'sn-admin', CSA_URL . 'assets/notice.css', array(), CSA_VER );
		return;
	}
	if ( ! $scr || 'post' !== $scr->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! csa_sn_editing( get_post() ) ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_style( 'sn-admin', CSA_URL . 'assets/notice.css', array(), CSA_VER );
	wp_enqueue_script( 'sn-admin', CSA_URL . 'assets/notice.js', array(), CSA_VER, true );
	$o  = csa_sn_opts();
	$ty = array();
	foreach ( $o['types'] as $k => $t ) {
		$ty[] = array( 'key' => $k, 'label' => $t['label'], 'topic' => $t['topic'], 'cards' => csa_sn_lines( $t['cards'] ) );
	}
	$br = array();
	foreach ( function_exists( 'csa_branches' ) ? csa_branches() : array() as $b ) {
		$br[] = $b['title'];
	}
	global $wpdb;
	foreach ( (array) $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_sn_branch' AND meta_value<>'' LIMIT 60" ) as $b ) {
		$br[] = $b;
	}
	$br = array_values( array_unique( $br ) );
	wp_localize_script( 'sn-admin', 'SN', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'csa' ), 'types' => $ty, 'cats' => array_map( function ( $c ) {
		return array( 'id' => (int) $c->term_id, 'name' => $c->name );
	}, get_categories( array( 'hide_empty' => false ) ) ), 'branches' => $br, 'settings' => admin_url( 'admin.php?page=sn-settings' ), 'year' => csa_sn_bn( wp_date( 'Y' ) ) ) );
} );

/** Claude fills the form from pasted text (e.g. a Facebook post). */
add_action( 'wp_ajax_csa_sn_ai', function () {
	csa_guard( 'chat' );
	$txt  = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
	$imgs = array();
	foreach ( array_slice( array_map( 'absint', (array) ( $_POST['imgs'] ?? array() ) ), 0, 3 ) as $aid ) {
		$f = $aid && current_user_can( 'edit_post', $aid ) ? get_attached_file( $aid ) : '';
		if ( ! $f || ! file_exists( $f ) ) {
			continue;
		}
		$ed = wp_get_image_editor( $f );
		if ( is_wp_error( $ed ) ) {
			continue;
		}
		$ed->resize( 1600, 1600, false );
		$tmp = wp_tempnam( 'snai' );
		$sv  = $ed->save( $tmp, 'image/jpeg' );
		if ( ! is_wp_error( $sv ) && ! empty( $sv['path'] ) ) {
			$imgs[] = array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode( (string) file_get_contents( $sv['path'] ) ) ) );
			wp_delete_file( $sv['path'] );
		}
		wp_delete_file( $tmp );
	}
	if ( ! $imgs && mb_strlen( $txt ) < 15 ) {
		wp_send_json_error( 'লেখা পেস্ট করুন অথবা পোস্টারের ছবি বাছুন।' );
	}
	$ty = array();
	foreach ( csa_sn_opt( 'types' ) as $k => $t ) {
		$ty[] = $k . ' = ' . $t['label'];
	}
	$sys = 'You read a school notice (Bangla or English) and fill a form. Return ONLY JSON: {"type":"one of: ' . implode( ', ', $ty ) . '","branch":"only the branch or place name, e.g. মাদারীপুর শাখা, WITHOUT the school group name (শাহীন স্কুল এন্ড ক্যাডেট কোচিং), or empty","topic":"short subject, e.g. সহকারী শিক্ষক নিয়োগ","year":"year in Bangla digits or empty","expiry":"application last date as YYYY-MM-DD or empty","summary":"1-2 plain sentences","cards":[{"l":"label such as আবেদনের শেষ সময়","v":"value exactly as written"}],"conditions":["each condition or rule as one short line"],"note":"one extra note or empty","phones":["numbers exactly as written"],"links":[{"kind":"apply|pdf|fb|oth","label":"button words","url":"only if the text contains it"}]}. Copy facts exactly; never invent dates, numbers, names or links; leave a field empty when the text does not say.';
	$m   = csa_sn_opt( 'model' );
	$x   = $m && csa_model( $m ) ? array( 'model_key' => $m ) : array();
	if ( $imgs ) {
		$x['needs_vision'] = true;
		$sys              .= ' The notice may be given as a poster image: read ALL the text in it carefully (Bangla and English), including small print and phone numbers.';
		$txt               = array_merge( $imgs, array( array( 'type' => 'text', 'text' => '' !== $txt ? $txt : 'Read the poster image above and fill the form.' ) ) );
	}
	$r   = csa_jobs_ai_json( $sys, $txt, 'Notice: fill', 2200, $x );
	is_wp_error( $r ) ? wp_send_json_error( $r->get_error_message() ) : wp_send_json_success( csa_sn_any_clean( $r ) );
} );

/** Claude writes the short summary and the Google/SEO fields from what is already in the form. */
add_action( 'wp_ajax_csa_sn_sum', function () {
	csa_guard( 'chat' );
	$d = csa_sn_clean( json_decode( wp_unslash( $_POST['data'] ?? '' ), true ) );
	$ts = csa_sn_opt( 'types' )[ $d['type'] ] ?? array();
	$facts = array( 'Type: ' . ( $ts['label'] ?? '' ), 'Organisation: ' . csa_sn_opt( 'brand' ), 'Branch: ' . $d['branch'], 'Topic: ' . $d['topic'], 'Year: ' . $d['year'], 'Application deadline: ' . $d['expiry'] );
	foreach ( $d['blocks'] as $b ) {
		foreach ( array( 'rows' => 'cards', 'lines' => 'list', 'body' => 'text' ) as $k => $_ ) {
			if ( isset( $b[ $k ] ) ) {
				$facts[] = wp_strip_all_tags( is_array( $b[ $k ] ) ? wp_json_encode( $b[ $k ], CSA_JSON ) : (string) $b[ $k ] );
			}
		}
	}
	$sys = 'You write for a school notice page (Bangla). Return ONLY JSON: {"title":"page title in Bangla, max 70 characters: topic + year + branch, no school name repeated","slug":"link in lowercase English letters and hyphens only, 3-6 words, branch place name transliterated, e.g. kumilla-assistant-teacher-2026","summary":"1-2 plain sentences for the notice list","seo_t":"Google title, max 60 characters, topic + year + organisation/branch","seo_d":"Google description, 120-155 characters, says what the notice is, who can apply and the key date","seo_k":"one focus keyword phrase people would search, e.g. মাদারীপুর সহকারী শিক্ষক নিয়োগ ২০২৬"}. Use ONLY the facts given; never invent dates, numbers or names. Plain, warm, no hype.';
	$m   = csa_sn_opt( 'model' );
	$r   = csa_jobs_ai_json( $sys, implode( "\n", $facts ), 'Notice: summary', 800, $m && csa_model( $m ) ? array( 'model_key' => $m ) : array() );
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( $r->get_error_message() );
	}
	wp_send_json_success( array( 'title' => sanitize_text_field( $r['title'] ?? '' ), 'slug' => sanitize_title( $r['slug'] ?? '' ), 'summary' => sanitize_textarea_field( $r['summary'] ?? '' ), 'seo_t' => sanitize_text_field( $r['seo_t'] ?? '' ), 'seo_d' => sanitize_textarea_field( $r['seo_d'] ?? '' ), 'seo_k' => sanitize_text_field( $r['seo_k'] ?? '' ) ) );
} );

/* ------------------------------------------------------------------ */
/* Shortcodes                                                           */
/* ------------------------------------------------------------------ */

/** Where a notice card goes: an imported old page keeps its own address. */
function csa_sn_url( $p ) {
	$l = (string) get_post_meta( is_object( $p ) ? $p->ID : $p, '_sn_link', true );
	return $l ? $l : get_permalink( $p );
}

add_action( 'template_redirect', function () {
	if ( csa_sn_single() ) {
		$l = (string) get_post_meta( get_the_ID(), '_sn_link', true );
		if ( $l && ! is_user_logged_in() ) {
			wp_redirect( $l, 301 );
			exit;
		}
	}
} );

function csa_sn_card( $p ) {
	$id  = $p->ID;
	$d   = csa_sn_get( $id );
	$ty  = csa_sn_opt( 'types' )[ $d['type'] ?? '' ]['label'] ?? '';
	$br  = (string) ( $d['branch'] ?? '' );
	$img = get_the_post_thumbnail_url( $id, 'medium_large' );
	$th  = $img ? '<img loading="lazy" src="' . esc_url( $img ) . '" alt="">' : esc_html( ( $br ? $br . ' — ' : '' ) . ( ( $d['topic'] ?? '' ) ?: $ty ) );
	return '<a class="sn-nc" href="' . esc_url( csa_sn_url( $p ) ) . '"><div class="th">' . $th . '</div><small>' . esc_html( $ty . ( $br ? ' · ' . $br : '' ) ) . '</small><b>' . esc_html( get_the_title( $id ) ) . '</b><time>' . esc_html( csa_sn_date( get_post_time( 'U', true, $p ) ) ) . '</time>' . ( csa_sn_expired( $id ) ? '<span class="ex">মেয়াদ শেষ</span>' : '' ) . '</a>';
}

function csa_sn_query( $a, $n, $paged = 1 ) {
	$mq = array( array( 'key' => '_sn_is', 'value' => '1' ) );
	if ( ! empty( $a['type'] ) ) {
		$mq[] = array( 'key' => '_sn_type', 'value' => sanitize_key( $a['type'] ) );
	}
	if ( ! empty( $a['branch'] ) ) {
		$mq[] = array( 'key' => '_sn_branch', 'value' => sanitize_text_field( $a['branch'] ), 'compare' => 'LIKE' );
	}
	$q = array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $n, 'paged' => $paged, 'orderby' => 'date', 'order' => 'DESC' );
	if ( $mq ) {
		$q['meta_query'] = $mq;
	}
	if ( ! empty( $a['q'] ) ) {
		$q['s'] = sanitize_text_field( $a['q'] );
	}
	return new WP_Query( $q );
}

function csa_sn_js() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;
	return '<script>(function(){function w(t){return t.children[0]?t.children[0].offsetWidth+12:0}document.querySelectorAll(".sn-rn").forEach(function(r){var t=r.querySelector(".sn-trk"),d=r.querySelector(".sn-dots"),pv=+getComputedStyle(t).getPropertyValue("--pv")||1,n=Math.ceil(t.children.length/pv),i=0;for(var k=0;k<n;k++)d.appendChild(document.createElement("i"));function m(x){[].forEach.call(d.children,function(c,j){c.className=j===x?"on":""})}m(0);t.addEventListener("scroll",function(){var x=Math.round(t.scrollLeft/(pv*w(t)||1));i=x;m(x)});function g(x){i=x;t.scrollTo({left:x*pv*w(t),behavior:"smooth"});m(x)}setInterval(function(){if(d.offsetParent!==null)if(n>1)g((i+1)%n)},4500)});document.querySelectorAll(".sn-aw").forEach(function(a){var s=a.querySelector(".sn-as");a.querySelector(".sn-ar.l").onclick=function(){s.scrollBy({left:-s.clientWidth*.8})};a.querySelector(".sn-ar.r").onclick=function(){s.scrollBy({left:s.clientWidth*.8})}})})();</script>';
}

add_shortcode( 'shaheen_recent_notices', function ( $a ) {
	$a = shortcode_atts( array( 'type' => '', 'branch' => '', 'limit' => 0, 'title' => 'সর্বশেষ নোটিশ' ), $a );
	$n = (int) $a['limit'] ?: (int) csa_sn_opt( 'recent' );
	$q = csa_sn_query( $a, max( 1, min( 30, $n ) ) );
	if ( ! $q->have_posts() ) {
		return '';
	}
	$c = '';
	foreach ( $q->posts as $p ) {
		$c .= csa_sn_card( $p );
	}
	return csa_sn_css() . '<div class="sn sn-rn">' . ( $a['title'] ? '<h2 class="sn-h">' . esc_html( $a['title'] ) . '</h2>' : '' ) . '<div class="sn-trk sl">' . $c . '</div><div class="sn-dots"></div></div>' . csa_sn_js();
} );

add_shortcode( 'shaheen_notice_list', function ( $a ) {
	$GLOBALS['csa_sn_list_done'] = true;
	$a   = shortcode_atts( array( 'type' => '', 'per_page' => 12, 'header' => 0 ), $a );
	$t   = sanitize_key( $_GET['sn_t'] ?? $a['type'] );
	$b   = sanitize_text_field( wp_unslash( $_GET['sn_b'] ?? '' ) );
	$s   = sanitize_text_field( wp_unslash( $_GET['sn_q'] ?? '' ) );
	$pg  = max( 1, (int) ( $_GET['sn_pg'] ?? 1 ) );
	$q   = csa_sn_query( array( 'type' => $t, 'branch' => $b, 'q' => $s ), max( 1, (int) $a['per_page'] ), $pg );
	$opt = '<option value="">সব ধরন</option>';
	foreach ( csa_sn_opt( 'types' ) as $k => $ty ) {
		$opt .= '<option value="' . esc_attr( $k ) . '"' . selected( $t, $k, false ) . '>' . esc_html( $ty['label'] ) . '</option>';
	}
	$f  = '<form class="sn-fl" method="get"><select name="sn_t">' . $opt . '</select><input type="text" name="sn_b" placeholder="শাখার নাম" value="' . esc_attr( $b ) . '"><input type="text" name="sn_q" placeholder="খুঁজুন" value="' . esc_attr( $s ) . '"><button>খুঁজুন</button></form>';
	$c  = '';
	foreach ( $q->posts as $p ) {
		$c .= csa_sn_card( $p );
	}
	$pgs = '';
	if ( $q->max_num_pages > 1 ) {
		for ( $i = 1; $i <= $q->max_num_pages; $i++ ) {
			$pgs .= $i === $pg ? '<span>' . csa_sn_bn( $i ) . '</span>' : '<a href="' . esc_url( add_query_arg( 'sn_pg', $i ) ) . '">' . csa_sn_bn( $i ) . '</a>';
		}
		$pgs = '<div class="sn-pg">' . $pgs . '</div>';
	}
	$hd = '';
	if ( $a['header'] ) {
		$tabs = '<a class="' . ( $t ? '' : 'on' ) . '" href="' . esc_url( remove_query_arg( array( 'sn_t', 'sn_pg' ) ) ) . '">সব নোটিশ</a>';
		$tiles = '';
		$tot  = (int) csa_sn_query( array(), 1 )->found_posts;
		$tiles .= '<div><b>' . csa_sn_bn( $tot ) . '</b><span>মোট নোটিশ</span></div>';
		foreach ( csa_sn_opt( 'types' ) as $k => $ty ) {
			$n = (int) csa_sn_query( array( 'type' => $k ), 1 )->found_posts;
			if ( $n ) {
				$tiles .= '<div><b>' . csa_sn_bn( $n ) . '</b><span>' . esc_html( $ty['label'] ) . '</span></div>';
				$tabs  .= '<a class="' . ( $t === $k ? 'on' : '' ) . '" href="' . esc_url( add_query_arg( 'sn_t', $k, remove_query_arg( 'sn_pg' ) ) ) . '">' . esc_html( $ty['label'] ) . '</a>';
			}
		}
		$hd = '<div class="sn-board"><h2>🔔 নোটিশ বোর্ড</h2><p>নিয়োগ বিজ্ঞপ্তি, পরীক্ষার ফলাফল ও যাবতীয় প্রাতিষ্ঠানিক ঘোষণা এখানে যথাসময়ে প্রকাশ করা হয়।</p><div class="sn-tiles">' . $tiles . '</div></div><div class="sn-tabs">' . $tabs . '</div>';
	}
	return csa_sn_css() . '<div class="sn">' . $hd . $f . ( $c ? '<div class="sn-grid">' . $c . '</div>' . $pgs : '<p>কোনো নোটিশ পাওয়া যায়নি।</p>' ) . '</div>';
} );

add_shortcode( 'shaheen_activities', function ( $a ) {
	$a = shortcode_atts( array( 'kicker' => 'শাহীন শিক্ষা পরিবার', 'title' => 'সকল কার্যক্রম একনজরে', 'lead' => 'আপনার প্রয়োজনীয় তথ্য ও সেবা খুঁজে নিন' ), $a );
	$c = '';
	foreach ( (array) csa_sn_opt( 'acts' ) as $x ) {
		if ( empty( $x['title'] ) ) {
			continue;
		}
		$c .= '<div class="c"><i>' . esc_html( $x['icon'] ?: '•' ) . '</i><b>' . esc_html( $x['title'] ) . '</b>' . ( ! empty( $x['text'] ) ? '<p>' . esc_html( $x['text'] ) . '</p>' : '' ) . ( ! empty( $x['url'] ) ? '<a href="' . esc_url( $x['url'] ) . '">দেখুন →</a>' : '' ) . '</div>';
	}
	return $c ? csa_sn_css() . '<div class="sn sn-act"><small>' . esc_html( $a['kicker'] ) . '</small><h2>' . esc_html( $a['title'] ) . '</h2><p class="lead">' . esc_html( $a['lead'] ) . '</p><div class="sn-aw"><button class="sn-ar l" aria-label="আগে">←</button><button class="sn-ar r" aria-label="পরে">→</button><div class="sn-as">' . $c . '</div></div></div>' . csa_sn_js() : '';
} );

add_shortcode( 'shaheen_home_notices', function ( $a ) {
	return do_shortcode( '[shaheen_recent_notices]' ) . do_shortcode( '[shaheen_activities]' );
} );

/* ------------------------------------------------------------------ */
/* Settings                                                             */
/* ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
	add_menu_page( 'Page Builder', 'Page Builder', 'edit_posts', 'sef-builder', 'csa_sn_overview', 'dashicons-layout', 29 );
	add_submenu_page( 'sef-builder', 'Page Builder', 'ওভারভিউ', 'edit_posts', 'sef-builder', 'csa_sn_overview' );
	add_submenu_page( 'sef-builder', 'নোটিশ বোর্ড', 'নোটিশ বোর্ড', 'manage_options', 'sn-board', 'csa_sn_board_screen' );
	add_submenu_page( 'sef-builder', 'সব নোটিশ', 'সব নোটিশ', 'edit_posts', 'edit.php?sn=1' );
	add_submenu_page( 'sef-builder', 'নতুন নোটিশ', 'নতুন নোটিশ', 'edit_posts', 'post-new.php?sn=1' );
	add_submenu_page( 'sef-builder', 'পুরোনো নোটিশ আনুন', 'পুরোনো নোটিশ আনুন', 'manage_options', 'sn-import', 'csa_sn_import_screen' );
	add_submenu_page( 'sef-builder', 'নোটিশ সেটিংস', 'নোটিশ সেটিংস', 'manage_options', 'sn-settings', 'csa_sn_settings_screen' );
}, 5 );

/* ------------------------------------------------------------------ */
/* Bring the site's old notice pages into the list                      */
/* ------------------------------------------------------------------ */

function csa_sn_read_token( $back = 0 ) {
	return wp_hash( 'snr' . ( floor( time() / 900 ) - $back ) );
}

function csa_sn_read_ok( $t ) {
	return hash_equals( csa_sn_read_token(), $t ) || hash_equals( csa_sn_read_token( 1 ), $t );
}

/** Reads the notice cards (title, date, address, kind) out of an existing page's own content. Returns array( items, how ). */
function csa_sn_read_page( $pid ) {
	$html = '';
	$how  = '';
	$r    = wp_remote_get( add_query_arg( 'snr', csa_sn_read_token(), get_permalink( $pid ) ), array( 'timeout' => 25, 'sslverify' => false, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
	if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
		$html = (string) wp_remote_retrieve_body( $r );
		$how  = 'পেজ যেভাবে দেখা যায় সেভাবে';
	}
	if ( strlen( $html ) < 500 ) { // Could not load the page: use what is stored in it.
		$p    = get_post( $pid );
		$html = (string) $p->post_content;
		$ed   = (string) get_post_meta( $pid, '_elementor_data', true );
		if ( $ed ) {
			$j = json_decode( $ed, true );
			array_walk_recursive( $j, function ( $v, $k ) use ( &$html ) {
				if ( is_string( $v ) && false !== strpos( $v, '<' ) ) {
					$html .= "\n" . $v;
				}
			} );
		}
		$how = 'পেজের সংরক্ষিত লেখা থেকে';
	}
	if ( ! class_exists( 'DOMDocument' ) || '' === trim( $html ) ) {
		return array( array(), $how );
	}
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	$self  = trim( get_permalink( $pid ), '/' );
	$base  = trim( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '/' );
	$bn    = array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' );
	$items = array();
	$seen  = array();
	foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
		$href = trim( (string) $a->getAttribute( 'href' ) );
		if ( '' === $href || '#' === $href[0] || 0 === stripos( $href, 'javascript' ) || 0 === stripos( $href, 'mailto' ) || 0 === stripos( $href, 'tel' ) ) {
			continue;
		}
		if ( 0 === strpos( $href, '/' ) ) {
			$href = home_url( $href );
		}
		$tk = trim( strtok( $href, '#' ), '/' );
		if ( $tk === $self || isset( $seen[ $tk ] ) || false !== strpos( $tk, '/notice-board/' ) || false !== strpos( $tk, '?sn_' ) ) {
			continue;
		}
		$card = null;
		$node = $a;
		for ( $i = 0; $i < 8 && $node; $i++, $node = $node->parentNode ) {
			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				break;
			}
			$n = preg_match_all( '/\d{1,2}[-\/.]\d{1,2}[-\/.]\d{4}/', strtr( $node->textContent, $bn ) );
			if ( 1 === $n ) {
				$card = $node; // keep climbing: the whole card is the largest part that still holds one date
				continue;
			}
			if ( $n > 1 || $card ) {
				break;
			}
		}
		if ( ! $card ) {
			continue;
		}
		$tx = strtr( $card->textContent, $bn );
		preg_match( '/(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})/', $tx, $m );
		$date = sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
		$strs = array();
		$walk = function ( $el ) use ( &$walk, &$strs ) {
			$has = false;
			foreach ( $el->childNodes as $c ) {
				if ( XML_ELEMENT_NODE === $c->nodeType ) {
					$has = true;
					$walk( $c );
				}
			}
			if ( ! $has ) {
				$t = trim( preg_replace( '/\s+/u', ' ', $el->textContent ) );
				if ( '' !== $t ) {
					$strs[] = $t;
				}
			}
		};
		$walk( $card );
		$title = '';
		$badge = '';
		foreach ( $strs as $t ) {
			if ( preg_match( '/\d{1,2}[-\/.]\d{1,2}[-\/.]\d{4}/', strtr( $t, $bn ) ) || preg_match( '/বিস্তারিত|ডাউনলোড|দেখুন|Read more|^নতুন$|^New$/iu', $t ) ) {
				continue;
			}
			if ( mb_strlen( $t ) > mb_strlen( $title ) ) {
				if ( $title && mb_strlen( $title ) <= 28 && ! $badge ) {
					$badge = $title;
				}
				$title = $t;
			} elseif ( ! $badge && mb_strlen( $t ) <= 28 ) {
				$badge = $t;
			}
		}
		if ( '' === $title ) {
			$title = trim( preg_replace( '/\s+/u', ' ', $a->textContent ) );
		}
		if ( mb_strlen( $title ) < 6 ) {
			continue;
		}
		$seen[ $tk ] = 1;
		$items[]     = array( 't' => $title, 'u' => $href, 'd' => $date, 'b' => $badge );
	}
	return array( $items, $how );
}

function csa_sn_lk( $u ) {
	return strtolower( trim( preg_replace( '#^https?://(www\.)?#i', '', strtok( trim( (string) $u ), '#' ) ), '/' ) );
}

function csa_sn_exists_link( $u ) {
	return (bool) get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_sn_lk', 'meta_value' => csa_sn_lk( $u ) ) );
}

function csa_sn_guess_type( $t ) {
	$types = array_keys( csa_sn_opt( 'types' ) );
	$has   = function ( $k ) use ( $types ) {
		return in_array( $k, $types, true );
	};
	if ( preg_match( '/ফলাফল|result/iu', $t ) ) {
		return preg_match( '/বৃত্তি/u', $t ) && $has( 'britti_result' ) ? 'britti_result' : ( $has( 'niyog_result' ) ? 'niyog_result' : reset( $types ) );
	}
	if ( preg_match( '/বৃত্তি|সিলেবাস/u', $t ) && $has( 'britti' ) ) {
		return 'britti';
	}
	return $has( 'niyog' ) ? 'niyog' : reset( $types );
}

function csa_sn_candidates() {
	$have = array();
	foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_sn_src' ) ) as $nid ) {
		$have[ (int) get_post_meta( $nid, '_sn_src', true ) ] = 1;
	}
	$skip = array_filter( array( csa_sn_listpage_id(), (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );
	$out  = array();
	foreach ( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 600, 'orderby' => 'date', 'order' => 'DESC' ) ) as $p ) {
		if ( isset( $have[ $p->ID ] ) || in_array( $p->ID, $skip, true ) || csa_sn_exists_link( get_permalink( $p ) ) ) {
			continue;
		}
		if ( preg_match( '/নিয়োগ|ফলাফল|বৃত্তি|বিজ্ঞপ্তি|নোটিশ|সিলেবাস|প্রবেশপত্র|আবেদন|রেজাল্ট|result|notice|circular|admit|vacanc|recruit|scholarship/iu', $p->post_title . ' ' . $p->post_name ) ) {
			$out[] = $p;
		}
	}
	return $out;
}

function csa_sn_import_one( $title, $url, $date, $type, $src = 0, $thumb = 0 ) {
	$d  = array( 'type' => $type, 'branch' => '', 'topic' => $title, 'year' => '', 'summary' => '', 'expiry' => '', 'slug' => '', 'blocks' => array() );
	if ( preg_match( '/([^\s,–\-—|]+)\s*শাখা/u', $title, $m ) ) {
		$d['branch'] = $m[1] . ' শাখা';
	}
	$arr = array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => sanitize_title( 'old-' . ( $src ?: wp_generate_password( 6, false ) ) ) );
	if ( $date ) {
		$ts = strtotime( $date );
		if ( $ts ) {
			$arr['post_date']     = wp_date( 'Y-m-d H:i:s', $ts );
			$arr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $ts );
		}
	}
	$id = wp_insert_post( $arr );
	if ( ! $id || is_wp_error( $id ) ) {
		return 0;
	}
	update_post_meta( $id, '_sn_is', 1 );
	update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', '1' ); // the card only points to the old page
	update_post_meta( $id, '_sn', wp_slash( $d ) );
	update_post_meta( $id, '_sn_type', $type );
	update_post_meta( $id, '_sn_branch', $d['branch'] );
	update_post_meta( $id, '_sn_link', esc_url_raw( $url ) );
	update_post_meta( $id, '_sn_lk', csa_sn_lk( $url ) );
	if ( $src ) {
		update_post_meta( $id, '_sn_src', $src );
	}
	if ( $thumb ) {
		set_post_thumbnail( $id, $thumb );
	}
	return $id;
}

add_action( 'admin_post_sn_import_all', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sn_import_all' ) ) {
		wp_die( 'No.' );
	}
	$lp = csa_sn_listpage_id();
	$n  = 0;
	if ( $lp ) {
		list( $rows ) = csa_sn_read_page( $lp );
		foreach ( $rows as $r ) {
			if ( ! csa_sn_exists_link( $r['u'] ) ) {
				$sid = (int) url_to_postid( $r['u'] );
				if ( csa_sn_import_one( $r['t'], $r['u'], $r['d'], csa_sn_guess_type( $r['b'] . ' ' . $r['t'] ), $sid, $sid ? (int) get_post_thumbnail_id( $sid ) : 0 ) ) {
					$n++;
				}
			}
		}
	}
	wp_safe_redirect( admin_url( 'admin.php?page=sn-board&imported=' . $n ) );
	exit;
} );

add_action( 'admin_post_sn_import', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sn_import' ) ) {
		wp_die( 'No.' );
	}
	$n   = 0;
	$ty  = (array) ( $_POST['ty'] ?? array() );
	foreach ( array_map( 'absint', (array) ( $_POST['pick'] ?? array() ) ) as $pid ) {
		$p = get_post( $pid );
		if ( $p && 'publish' === $p->post_status && ! get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_sn_src', 'meta_value' => $pid ) ) ) {
			$type = sanitize_key( $ty[ $pid ] ?? '' ) ?: csa_sn_guess_type( $p->post_title );
			if ( csa_sn_import_one( $p->post_title, get_permalink( $p ), $p->post_date, $type, $pid, (int) get_post_thumbnail_id( $pid ) ) ) {
				$n++;
			}
		}
	}
	foreach ( (array) wp_unslash( $_POST['rd'] ?? array() ) as $r ) {
		if ( empty( $r['on'] ) || empty( $r['u'] ) || csa_sn_exists_link( $r['u'] ) ) {
			continue;
		}
		$type = sanitize_key( $r['y'] ?? '' ) ?: csa_sn_guess_type( $r['t'] ?? '' );
		$sid  = (int) url_to_postid( $r['u'] );
		if ( csa_sn_import_one( sanitize_text_field( $r['t'] ?? '' ), $r['u'], sanitize_text_field( $r['d'] ?? '' ), $type, $sid, $sid ? (int) get_post_thumbnail_id( $sid ) : 0 ) ) {
			$n++;
		}
	}
	foreach ( preg_split( '/\r?\n/', wp_unslash( $_POST['extra'] ?? '' ) ) as $ln ) {
		$pp = array_map( 'trim', explode( '|', $ln ) );
		if ( count( $pp ) >= 2 && '' !== $pp[0] && preg_match( '#^https?://#i', $pp[1] ) && csa_sn_import_one( sanitize_text_field( $pp[0] ), $pp[1], $pp[2] ?? '', csa_sn_guess_type( $pp[0] ) ) ) {
			$n++;
		}
	}
	wp_safe_redirect( admin_url( 'admin.php?page=sn-import&done=' . $n ) );
	exit;
} );

function csa_sn_import_screen() {
	$rows = csa_sn_candidates();
	$ty   = csa_sn_opt( 'types' );
	?>
	<div class="wrap" id="sn-settings"><h1>পুরোনো নোটিশ আনুন</h1>
	<?php echo isset( $_GET['done'] ) ? '<div class="notice notice-success"><p>' . (int) $_GET['done'] . 'টা নোটিশ তালিকায় যোগ হয়েছে ✓ — এখন নোটিশ সেটিংসে "পুরোনো লেখা সরিয়ে শুধু নোটিশ বোর্ড" চালু করুন।</p></div>' : ''; ?>
	<?php
	$lpid = csa_sn_listpage_id();
	$rd   = array();
	$how  = '';
	if ( $lpid && isset( $_GET['read'] ) ) {
		list( $rd, $how ) = csa_sn_read_page( $lpid );
	}
	?>
	<div class="sn-set"><h2>১) আপনার নোটিশ পেজ পড়ে আনুন</h2>
	<?php if ( $lpid ) : ?>
		<p>নোটিশ পেজ: <a href="<?php echo esc_url( get_permalink( $lpid ) ); ?>" target="_blank"><?php echo esc_html( get_the_title( $lpid ) ); ?></a> <small>(<?php echo esc_html( get_permalink( $lpid ) ); ?>)</small> — এই পেজের কার্ডগুলো (নাম, তারিখ, লিংক) plugin নিজে পড়ে নেবে।</p>
		<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=sn-import&read=1' ) ); ?>">📖 পেজটা পড়ুন</a></p>
	<?php else : ?>
		<p>নোটিশ পেজ পাওয়া যায়নি। নোটিশ সেটিংসে "নোটিশ-তালিকা পেজ" বেছে নিন।</p>
	<?php endif; ?>
	</div>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'sn_import' ); ?><input type="hidden" name="action" value="sn_import">
	<?php if ( isset( $_GET['read'] ) ) : ?>
		<h2>পেজে পাওয়া নোটিশ (<?php echo esc_html( $how ); ?> পড়া হয়েছে): <?php echo count( $rd ); ?>টা</h2>
		<table class="widefat striped"><thead><tr><td style="width:30px"></td><th>নাম</th><th>তারিখ</th><th>ধরন</th><th>লিংক</th></tr></thead><tbody>
		<?php foreach ( $rd as $i => $r ) : $ex = csa_sn_exists_link( $r['u'] ); $g = csa_sn_guess_type( $r['b'] . ' ' . $r['t'] ); ?>
			<tr><td><input type="checkbox" name="rd[<?php echo (int) $i; ?>][on]" value="1" <?php echo $ex ? 'disabled' : 'checked'; ?>><input type="hidden" name="rd[<?php echo (int) $i; ?>][u]" value="<?php echo esc_attr( $r['u'] ); ?>"></td>
			<td><input type="text" class="large-text" name="rd[<?php echo (int) $i; ?>][t]" value="<?php echo esc_attr( $r['t'] ); ?>"></td>
			<td><input type="text" size="10" name="rd[<?php echo (int) $i; ?>][d]" value="<?php echo esc_attr( $r['d'] ); ?>"></td>
			<td><select name="rd[<?php echo (int) $i; ?>][y]"><?php foreach ( $ty as $k => $t ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $g, $k, false ) . '>' . esc_html( $t['label'] ) . '</option>'; } ?></select><?php echo $r['b'] ? '<br><small>পেজে: ' . esc_html( $r['b'] ) . '</small>' : ''; ?></td>
			<td><a href="<?php echo esc_url( $r['u'] ); ?>" target="_blank">খুলুন</a><?php echo $ex ? ' <small>(আগেই আনা)</small>' : ''; ?></td></tr>
		<?php endforeach; if ( ! $rd ) { echo '<tr><td colspan="5">কার্ড পাওয়া যায়নি। (নিচের "অন্য লিংক" বা ২নং তালিকা ব্যবহার করুন।)</td></tr>'; } ?>
		</tbody></table>
	<?php endif; ?>
	<h2>২) সাইটের অন্য পেজ থেকে</h2>
	<p>সাইটের যে পেজ/পোস্টের নামে নিয়োগ, ফলাফল, বৃত্তি, বিজ্ঞপ্তি ইত্যাদি আছে, সেগুলো নিচে এসেছে। টিক দেওয়াগুলো নোটিশ-তালিকায় যোগ হবে। কার্ডে চাপলে আগের পেজেই যাবে (পেজ বদলায় না), আর তারিখ আগের পেজের তারিখই থাকবে, তাই নতুনটা সবার আগে দেখাবে।</p>
	<table class="widefat striped"><thead><tr><td style="width:30px"><input type="checkbox" onclick="document.querySelectorAll('.sn-pk').forEach(function(c){c.checked=this.checked}.bind(this))" checked></td><th>পেজের নাম</th><th>তারিখ</th><th>ধরন</th></tr></thead><tbody>
	<?php foreach ( $rows as $p ) : $g = csa_sn_guess_type( $p->post_title ); ?>
		<tr><td><input class="sn-pk" type="checkbox" name="pick[]" value="<?php echo (int) $p->ID; ?>" checked></td><td><a href="<?php echo esc_url( get_permalink( $p ) ); ?>" target="_blank"><?php echo esc_html( $p->post_title ); ?></a> <small>/<?php echo esc_html( $p->post_name ); ?>/</small></td><td><?php echo esc_html( csa_sn_date( get_post_time( 'U', true, $p ) ) ); ?></td><td><select name="ty[<?php echo (int) $p->ID; ?>]"><?php foreach ( $ty as $k => $t ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $g, $k, false ) . '>' . esc_html( $t['label'] ) . '</option>'; } ?></select></td></tr>
	<?php endforeach; if ( ! $rows ) { echo '<tr><td colspan="4">নতুন কিছু পাওয়া যায়নি।</td></tr>'; } ?>
	</tbody></table>
	<h2>অন্য লিংক (Drive, বা তালিকায় না আসা পেজ)</h2>
	<p class="description">প্রতি লাইনে একটা: <code>নাম | লিংক | তারিখ (২০২৫-০৫-১০, ঐচ্ছিক)</code></p>
	<textarea name="extra" rows="5" class="large-text"></textarea>
	<p><button class="button button-primary button-large">তালিকায় যোগ করুন</button></p></form></div>
	<?php
}


/* ------------------------------------------------------------------ */
/* The notice board page (design of the site's old /notice/ page)       */
/* ------------------------------------------------------------------ */

function csa_sn_board_opts() {
	return wp_parse_args( (array) csa_sn_opt( 'board' ), array(
		'title'    => '🔔 নোটিশ বোর্ড',
		'lead'     => 'নিয়োগ বিজ্ঞপ্তি, পরীক্ষার ফলাফল ও যাবতীয় প্রাতিষ্ঠানিক ঘোষণা এখানে যথাসময়ে প্রকাশ করা হয়।',
		'l_total'  => 'মোট নোটিশ',
		'l_job'    => 'নিয়োগ বিজ্ঞপ্তি',
		'l_result' => 'পরীক্ষার ফলাফল',
		'l_form'   => 'আবেদন ফরম',
		'per'      => 12,
		'new_days' => 7,
	) );
}

function csa_sn_bn_date_long( $ts ) {
	$m = array( 1 => 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর' );
	return csa_sn_bn( wp_date( 'j', $ts ) ) . ' ' . $m[ (int) wp_date( 'n', $ts ) ] . ' ' . csa_sn_bn( wp_date( 'Y', $ts ) );
}

function csa_sn_board_css() {
	return '.snb{--ink:#1c2333;--bg:#f4f7fb;--blue:#1e5fbf;--blue-dark:#123f85;--blue-light:#e7f0ff;--orange:#f5821f;--orange-light:#fff1e2;--teal:#0fa98c;--teal-light:#e2f8f2;--purple:#7c4dff;--purple-light:#f0eaff;--yellow:#ffc94d;--pink:#ef5da8;--line:rgba(28,35,51,.10);--shadow:0 16px 34px -18px rgba(30,60,120,.28);--shadow-sm:0 6px 16px -10px rgba(30,60,120,.25);font-family:"Hind Siliguri",sans-serif;color:var(--ink);line-height:1.6}'
	. '.snb,.snb *{box-sizing:border-box}.snb a{text-decoration:none!important}.snb h1,.snb h2,.snb h3,.snb .num{font-family:"Baloo Da 2","Hind Siliguri",sans-serif}'
	. '.sn-boardpage{display:block;flex:1 1 100%;width:100%;max-width:1140px;margin:0 auto!important;padding:0!important;align-self:center;float:none!important}.snb{width:100%}.snb-wrap{max-width:1180px;margin:0 auto;padding:0 20px}'
	. '.snb-hero{background:linear-gradient(120deg,#0e3320 0%,#123a22 55%,#1a4a2c 100%);padding:40px 0 34px;position:relative;overflow:hidden}.snb-hero:before{content:"🔔";position:absolute;font-size:170px;opacity:.12;right:-20px;top:-40px;transform:rotate(-12deg)}'
	. '.snb-hi{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px}.snb-hero h1{color:#fff;font-size:30px;margin:0 0 6px;font-weight:700;line-height:1.3}.snb-hero p{color:#e2f0ff;margin:0;font-size:14.5px;max-width:50ch}.snb-badge{background:rgba(255,255,255,.18);color:#fff;padding:8px 18px;border-radius:20px;font-size:13px;font-weight:700;white-space:nowrap}'
	. '.snb-stats{margin-top:-26px;position:relative;z-index:3}.snb-sg{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}.snb-sc{background:#fff;border-radius:16px;padding:18px 20px;box-shadow:var(--shadow);display:flex;flex-direction:row;align-items:center;gap:14px;text-align:left}'
	. '.snb-ic{width:44px;height:44px;min-width:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px}.snb-sc .num{font-weight:800;font-size:22px;line-height:1;color:var(--ink)}.snb-sc .lbl{font-size:12.5px;color:#758099;margin-top:3px}'
	. '.snb-c1 .snb-ic{background:var(--blue-light)}.snb-c2 .snb-ic{background:var(--orange-light)}.snb-c3 .snb-ic{background:var(--teal-light)}.snb-c4 .snb-ic{background:var(--purple-light)}'
	. '.snb-filters{display:flex;gap:10px;flex-wrap:wrap;margin:40px 0 26px}.snb-chip{padding:9px 18px;border-radius:20px;font-size:13.5px;font-weight:700;border:1.5px solid var(--line);color:#516079;background:#fff;display:inline-flex;align-items:center;gap:7px;cursor:pointer;font-family:inherit}.snb-chip i{width:8px;height:8px;border-radius:50%;display:inline-block}.snb-chip.on{background:var(--ink);color:#fff;border-color:var(--ink)}.snb-chip.on i{background:var(--yellow)!important}.snb-chip:hover:not(.on){border-color:var(--blue);color:var(--blue)}'
	. '.snb-board{padding-bottom:60px}.snb-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}'
	. '.snb-card{background:#fff;border-radius:16px;padding:20px 22px;box-shadow:var(--shadow-sm);border-top:4px solid var(--blue);display:flex;flex-direction:column;gap:10px;text-align:left}.snb-card[hidden]{display:none}'
	. '.snb-job{border-color:var(--blue)}.snb-result{border-color:var(--teal)}.snb-admission{border-color:var(--orange)}.snb-form{border-color:var(--purple)}'
	. '.snb-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.snb-tag{font-size:11px;font-weight:700;padding:5px 11px;border-radius:14px;white-space:nowrap}'
	. '.snb-job .snb-tag{background:var(--blue-light);color:var(--blue-dark)}.snb-result .snb-tag{background:var(--teal-light);color:#0b7a65}.snb-admission .snb-tag{background:var(--orange-light);color:#b2590f}.snb-form .snb-tag{background:var(--purple-light);color:#5a34c9}'
	. '.snb-new{background:var(--pink);color:#fff;font-size:10.5px;font-weight:800;padding:3px 10px;border-radius:10px}'
	. '.snb-title{font-size:15.5px;font-weight:600;line-height:1.65;color:var(--ink)!important}.snb-title:hover{color:var(--blue)!important}'
	. '.snb-bot{display:flex;align-items:center;justify-content:space-between;margin-top:auto;padding-top:8px;border-top:1px dashed var(--line);gap:10px}.snb-date{font-family:"JetBrains Mono",monospace;font-size:12px;color:#123a22;font-weight:700}'
	. '.snb-btn{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:#fff!important;background:var(--ink);padding:8px 16px;border-radius:14px}.snb-job .snb-btn{background:var(--blue)}.snb-result .snb-btn{background:var(--teal)}.snb-admission .snb-btn{background:var(--orange)}.snb-form .snb-btn{background:var(--purple)}.snb-btn:hover{opacity:.88}'
	. '.snb-pager{display:flex;justify-content:center;gap:8px;margin-top:36px;flex-wrap:wrap}.snb-pager button{width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:12px;font-family:"Baloo Da 2",sans-serif;font-weight:700;font-size:14px;background:#fff;color:#758099;box-shadow:var(--shadow-sm);border:0;cursor:pointer}.snb-pager button.on{background:var(--blue);color:#fff}'
	. '@media(max-width:900px){.snb-sg{grid-template-columns:1fr 1fr}.snb-grid{grid-template-columns:1fr}}@media(max-width:560px){.snb-hero h1{font-size:24px}}';
}

/** The whole notice board page, built from the notices. */
function csa_sn_board() {
	$o     = csa_sn_board_opts();
	$types = csa_sn_opt( 'types' );
	$q     = new WP_Query( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 400, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => array( array( 'key' => '_sn_is', 'value' => '1' ) ), 'no_found_rows' => true ) );
	$cards = '';
	$cnt   = array( 'job' => 0, 'result' => 0, 'form' => 0, 'admission' => 0 );
	$last  = 0;
	$i     = 0;
	foreach ( $q->posts as $p ) {
		$k    = (string) get_post_meta( $p->ID, '_sn_type', true );
		$t    = $types[ $k ] ?? array( 'label' => '' );
		$secs = csa_sn_secs( $p->ID );
		$st   = $secs[0];
		foreach ( $secs as $sc ) {
			$cnt[ $sc ]++;
		}
		$ts = (int) get_post_time( 'U', true, $p );
		$last = max( $last, $ts );
		$new  = ( time() - $ts ) < (int) $o['new_days'] * DAY_IN_SECONDS;
		$cards .= '<div class="snb-card snb-' . $st . '" data-c="' . esc_attr( implode( ' ', $secs ) ) . '"><div class="snb-top"><span class="snb-tag">' . esc_html( $t['label'] ) . '</span>' . ( $new ? '<span class="snb-new">নতুন</span>' : '' ) . '</div><a class="snb-title" href="' . esc_url( csa_sn_url( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a><div class="snb-bot"><span class="snb-date">📅 ' . esc_html( csa_sn_date( $ts ) ) . '</span><a class="snb-btn" href="' . esc_url( csa_sn_url( $p ) ) . '">⬇ বিস্তারিত দেখুন</a></div></div>';
		$i++;
	}
	$chips = '<button type="button" class="snb-chip on" data-f=""><i style="background:#fff"></i> সব নোটিশ</button>';
	foreach ( array( 'job' => array( 'l_job', 'var(--blue)' ), 'result' => array( 'l_result', 'var(--teal)' ), 'form' => array( 'l_form', 'var(--purple)' ), 'admission' => array( '', '#ea8a1f' ) ) as $k => $v ) {
		if ( $cnt[ $k ] ) {
			$chips .= '<button type="button" class="snb-chip" data-f="' . $k . '"><i style="background:' . $v[1] . '"></i> ' . esc_html( $v[0] ? $o[ $v[0] ] : 'ভর্তি' ) . '</button>';
		}
	}
	$tile = function ( $cls, $ic, $n, $l ) {
		return '<div class="snb-sc snb-' . $cls . '"><div class="snb-ic">' . $ic . '</div><div><div class="num">' . esc_html( csa_sn_bn( str_pad( (string) $n, 2, '0', STR_PAD_LEFT ) ) ) . '</div><div class="lbl">' . esc_html( $l ) . '</div></div></div>';
	};
	$total = count( $q->posts );
	$js    = '(function(){var r=document.currentScript.parentNode,cs=[].slice.call(r.querySelectorAll(".snb-card")),per=+r.getAttribute("data-per")||12,f="",pg=1,pgr=r.querySelector(".snb-pager");function bn(n){return String(n).replace(/[0-9]/g,function(d){return "০১২৩৪৫৬৭৮৯".charAt(d)})}function draw(){var vis=cs.filter(function(c){return f===""||(" "+c.getAttribute("data-c")+" ").indexOf(" "+f+" ")>-1}),pages=Math.max(1,Math.ceil(vis.length/per));if(pg>pages)pg=1;cs.forEach(function(c){c.hidden=true});vis.forEach(function(c,i){var st=(pg-1)*per;c.hidden=(i<st)||(i>=st+per)});pgr.innerHTML="";if(pages>1){for(var i=1;i<=pages;i++){var b=document.createElement("button");b.type="button";b.textContent=bn(i);if(i===pg)b.className="on";b.onclick=(function(n){return function(){pg=n;draw();r.scrollIntoView({behavior:"smooth",block:"start"})}})(i);pgr.appendChild(b)}}}[].forEach.call(r.querySelectorAll(".snb-chip"),function(b){b.onclick=function(){f=b.getAttribute("data-f");pg=1;[].forEach.call(r.querySelectorAll(".snb-chip"),function(x){x.className="snb-chip"+(x===b?" on":"")});draw()}});draw()})();';
	return '<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Baloo+Da+2:wght@500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet"><style>' . csa_sn_board_css() . '</style>'
		. '<div class="snb" data-per="' . (int) $o['per'] . '"><div class="snb-hero"><div class="snb-wrap snb-hi"><div><h1>' . esc_html( $o['title'] ) . '</h1><p>' . esc_html( $o['lead'] ) . '</p></div>' . ( $last ? '<div class="snb-badge">সর্বশেষ হালনাগাদ · ' . esc_html( csa_sn_bn_date_long( $last ) ) . '</div>' : '' ) . '</div></div>'
		. '<div class="snb-wrap snb-stats"><div class="snb-sg">' . $tile( 'c1', '📋', $total, $o['l_total'] ) . $tile( 'c2', '👩‍🏫', $cnt['job'], $o['l_job'] ) . $tile( 'c3', '🏆', $cnt['result'], $o['l_result'] ) . $tile( 'c4', '📝', $cnt['form'], $o['l_form'] ) . '</div></div>'
		. '<div class="snb-board"><div class="snb-wrap"><div class="snb-filters">' . $chips . '</div><div class="snb-grid">' . ( $cards ?: '<p>কোনো নোটিশ নেই।</p>' ) . '</div><div class="snb-pager"></div></div></div><script>' . $js . '</script></div>';
}

/** Board mode: the plugin builds the whole page itself (the theme's header and footer stay), whatever the page was made with. */
add_filter( 'template_include', function ( $tpl ) {
	if ( ! csa_sn_opt( 'replace' ) || ! is_page() || ! empty( $_GET['snr'] ) || wp_is_block_theme() ) { // block themes show the board through the page content instead
		return $tpl;
	}
	$lp = csa_sn_listpage_id();
	if ( ! $lp || (int) get_queried_object_id() !== (int) $lp ) {
		return $tpl;
	}
	return CSA_DIR . 'includes/board-template.php';
}, 99 );

add_shortcode( 'shaheen_notice_board', function () {
	$GLOBALS['csa_sn_list_done'] = true;
	return csa_sn_board();
} );

/* Page Builder > নোটিশ বোর্ড: the page and all its notices in one place */
add_action( 'admin_post_sn_board_save', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sn_board' ) ) {
		wp_die( 'No.' );
	}
	$in = wp_unslash( $_POST['b'] ?? array() );
	$o  = csa_sn_opts();
	$o['board']     = array(
		'title'    => sanitize_text_field( $in['title'] ?? '' ),
		'lead'     => sanitize_textarea_field( $in['lead'] ?? '' ),
		'l_total'  => sanitize_text_field( $in['l_total'] ?? '' ),
		'l_job'    => sanitize_text_field( $in['l_job'] ?? '' ),
		'l_result' => sanitize_text_field( $in['l_result'] ?? '' ),
		'l_form'   => sanitize_text_field( $in['l_form'] ?? '' ),
		'per'      => max( 3, min( 60, (int) ( $in['per'] ?? 12 ) ) ),
		'new_days' => max( 0, min( 60, (int) ( $in['new_days'] ?? 7 ) ) ),
	);
	$o['list_page'] = absint( $_POST['page'] ?? 0 );
	$o['replace']   = empty( $_POST['on'] ) ? 0 : 1;
	update_option( CSA_SN_OPT, $o, false );
	wp_safe_redirect( admin_url( 'admin.php?page=sn-board&saved=1' ) );
	exit;
} );

add_action( 'wp_ajax_sn_settype', function () {
	check_ajax_referer( 'sn_board', 'n' );
	$id = absint( $_POST['id'] ?? 0 );
	$t  = sanitize_key( $_POST['t'] ?? '' );
	if ( ! $id || ! current_user_can( 'edit_post', $id ) || ! csa_sn_is( $id ) || ! isset( csa_sn_opt( 'types' )[ $t ] ) ) {
		wp_send_json_error( 'no' );
	}
	update_post_meta( $id, '_sn_type', $t );
	delete_post_meta( $id, '_sn_sec' );
	$d         = csa_sn_get( $id );
	$d['type'] = $t;
	update_post_meta( $id, '_sn', wp_slash( $d ) );
	wp_send_json_success();
} );

function csa_sn_board_screen() {
	$o   = csa_sn_opts();
	$b   = csa_sn_board_opts();
	$lp  = csa_sn_listpage_id();
	$pgn = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$q   = new WP_Query( array( 'post_type' => 'post', 'post_status' => array( 'publish', 'draft', 'pending', 'future' ), 'posts_per_page' => 30, 'paged' => $pgn, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => array( array( 'key' => '_sn_is', 'value' => '1' ) ) ) );
	?>
	<div class="wrap" id="sn-settings"><h1>নোটিশ বোর্ড</h1>
	<?php echo ! empty( $_GET['saved'] ) ? '<div class="notice notice-success"><p>সংরক্ষিত ✓ — LiteSpeed cache থাকলে Purge All করুন।</p></div>' : ''; ?>
	<?php echo isset( $_GET['imported'] ) ? '<div class="notice notice-success"><p>পেজ থেকে ' . (int) $_GET['imported'] . 'টা নোটিশ আনা হয়েছে ✓ (ধরন ভুল হলে নিচের তালিকায় বদলে নিন)।</p></div>' : ''; ?>
	<div class="sn-set"><h2>⚡ পুরোনো নোটিশ এক ক্লিকে আনুন</h2><p>আপনার নোটিশ পেজে হাতে বসানো কার্ডগুলো (নাম, তারিখ, লিংক) পড়ে সব একসাথে এই বোর্ডে আনবে। আগে আনা থাকলে আবার আনবে না।</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'sn_import_all' ); ?><input type="hidden" name="action" value="sn_import_all"><button class="button button-primary button-large">📥 পেজের সব পুরোনো নোটিশ আনুন</button> <a class="button button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=sn-import&read=1' ) ); ?>">দেখে বেছে আনতে চাইলে</a></form></div>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'sn_board' ); ?><input type="hidden" name="action" value="sn_board_save">
	<div class="sn-set"><h2>১) পেজ</h2>
	<p><b>কোন পেজটা নোটিশ বোর্ড?</b> <?php wp_dropdown_pages( array( 'name' => 'page', 'selected' => $lp, 'show_option_none' => '— বাছুন —', 'option_none_value' => '0' ) ); ?> <?php echo $lp ? '<a href="' . esc_url( get_permalink( $lp ) ) . '" target="_blank">পেজ দেখুন ↗</a>' : ''; ?></p>
	<p><label><input type="checkbox" name="on" value="1" <?php checked( $o['replace'] ); ?>> <b>এই পেজ plugin চালাক</b> — পেজের পুরোনো হাতের লেখা লুকিয়ে নোটিশ থেকে বোর্ড নিজে দেখাবে (টিক তুললে পুরোনোটা ফিরে আসে)</label></p>
	<?php
	if ( $lp && isset( $_GET['check'] ) ) {
		$r  = wp_remote_get( add_query_arg( 'snt', time(), get_permalink( $lp ) ), array( 'timeout' => 25, 'sslverify' => false, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		$ok = ! is_wp_error( $r ) && false !== strpos( (string) wp_remote_retrieve_body( $r ), 'class="snb"' );
		echo $ok ? '<div class="notice notice-success inline"><p>✅ পেজটা এখন plugin চালাচ্ছে।</p></div>' : '<div class="notice notice-error inline"><p>❌ পেজে এখনো plugin-এর বোর্ড আসেনি' . ( is_wp_error( $r ) ? ' (' . esc_html( $r->get_error_message() ) . ')' : '' ) . '। কারণ হতে পারে: "plugin চালাক" টিক দেওয়া হয়নি/সংরক্ষণ হয়নি, ভুল পেজ বাছা, অথবা cache (LiteSpeed → Purge All)।</p></div>';
	}
	?>
	<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sn-board&check=1' ) ); ?>">🔍 পেজ plugin চালাচ্ছে কি না যাচাই করুন</a></p>
	<p class="description">পেজের জায়গায় আলাদাভাবে বসাতে চাইলে শর্টকোড: <code>[shaheen_notice_board]</code></p></div>
	<div class="sn-set"><h2>২) পেজের লেখা</h2>
	<p>শিরোনাম <input type="text" class="large-text" name="b[title]" value="<?php echo esc_attr( $b['title'] ); ?>"></p>
	<p>ছোট বিবরণ <textarea class="large-text" rows="2" name="b[lead]"><?php echo esc_textarea( $b['lead'] ); ?></textarea></p>
	<p>সংখ্যা-কার্ডের নাম: মোট <input type="text" name="b[l_total]" value="<?php echo esc_attr( $b['l_total'] ); ?>"> নীল গ্রুপ <input type="text" name="b[l_job]" value="<?php echo esc_attr( $b['l_job'] ); ?>"> সবুজ গ্রুপ <input type="text" name="b[l_result]" value="<?php echo esc_attr( $b['l_result'] ); ?>"> বেগুনি গ্রুপ <input type="text" name="b[l_form]" value="<?php echo esc_attr( $b['l_form'] ); ?>"></p>
	<p>এক পেজে কতগুলো কার্ড <input type="number" min="3" max="60" name="b[per]" value="<?php echo (int) $b['per']; ?>" style="width:70px"> · "নতুন" ট্যাগ কত দিন <input type="number" min="0" max="60" name="b[new_days]" value="<?php echo (int) $b['new_days']; ?>" style="width:70px"> দিন। <span class="description">সংখ্যা, তারিখ, ট্যাব, কার্ড সব নোটিশ থেকে নিজে তৈরি হয়। ধরনের রং বদলাতে "নোটিশ সেটিংস → নোটিশের ধরন"।</span></p>
	<p><button class="button button-primary button-large">সংরক্ষণ করুন</button></p></div></form>
	<div class="sn-set"><h2>৩) এই পেজের সব নোটিশ (<?php echo (int) $q->found_posts; ?>)</h2>
	<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?sn=1' ) ); ?>">+ নতুন নোটিশ</a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sn-import&read=1' ) ); ?>">📖 পুরোনো পেজ থেকে নোটিশ আনুন</a> <a class="button" href="<?php echo esc_url( admin_url( 'edit.php?sn=1' ) ); ?>">Posts-এ দেখুন</a></p>
	<table class="widefat striped"><thead><tr><th>নাম</th><th>ধরন</th><th>ক্যাটাগরি</th><th>তারিখ</th><th>অবস্থা</th><th></th></tr></thead><tbody>
	<?php foreach ( $q->posts as $p ) : $k = (string) get_post_meta( $p->ID, '_sn_type', true ); ?>
		<tr><td><a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a><?php echo get_post_meta( $p->ID, '_sn_link', true ) ? ' <small>(পুরোনো পেজের লিংক)</small>' : ''; ?></td>
		<td><select class="sn-st" data-id="<?php echo (int) $p->ID; ?>"><?php foreach ( $o['types'] as $tk => $t ) { echo '<option value="' . esc_attr( $tk ) . '"' . selected( $k, $tk, false ) . '>' . esc_html( $t['label'] ) . '</option>'; } ?></select></td>
		<td><?php echo esc_html( implode( ', ', wp_get_post_categories( $p->ID, array( 'fields' => 'names' ) ) ) ); ?></td>
		<td><?php echo esc_html( csa_sn_date( get_post_time( 'U', true, $p ) ) ); ?></td>
		<td><?php echo 'publish' === $p->post_status ? '✅ প্রকাশিত' : '📝 ' . esc_html( $p->post_status ); ?></td>
		<td><a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>">সম্পাদনা</a> · <a href="<?php echo esc_url( csa_sn_url( $p ) ); ?>" target="_blank">দেখুন</a> · <a href="<?php echo esc_url( get_delete_post_link( $p->ID ) ); ?>" onclick="return confirm('ট্র্যাশে পাঠাবেন?')">ট্র্যাশ</a></td></tr>
	<?php endforeach; if ( ! $q->posts ) { echo '<tr><td colspan="6">এখনো নোটিশ নেই।</td></tr>'; } ?>
	</tbody></table>
	<?php echo $q->max_num_pages > 1 ? '<p>' . wp_kses_post( paginate_links( array( 'total' => $q->max_num_pages, 'current' => $pgn, 'base' => add_query_arg( 'paged', '%#%' ) ) ) ) . '</p>' : ''; ?>
	</div>
	<script>
	document.querySelectorAll('.sn-st').forEach(function(s){s.onchange=function(){var u=new URLSearchParams({action:'sn_settype',n:'<?php echo esc_js( wp_create_nonce( 'sn_board' ) ); ?>',id:s.dataset.id,t:s.value});s.disabled=true;fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:u}).then(function(r){return r.json()}).then(function(j){s.disabled=false;s.style.outline=j.success?'2px solid #2e7d32':'2px solid #c00'})}});
	</script>
	</div>
	<?php
}

add_action( 'admin_post_sn_connect', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sn_connect' ) ) {
		wp_die( 'No.' );
	}
	$o = csa_sn_opts();
	$lp = absint( $_POST['page'] ?? 0 ) ?: csa_sn_listpage_id();
	if ( $lp ) {
		$o['list_page'] = $lp;
		$o['replace']   = empty( $_POST['off'] ) ? 1 : 0;
		update_option( CSA_SN_OPT, $o, false );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=sef-builder&conn=' . ( $lp ? ( empty( $_POST['off'] ) ? 1 : 2 ) : 0 ) ) );
	exit;
} );

function csa_sn_overview() {
	$o = csa_sn_opts();
	?>
	<div class="wrap" id="sn-settings"><h1>Page Builder</h1>
	<?php $fe = get_option( 'csa_sn_fatal' ); if ( is_array( $fe ) ) : ?>
		<div class="notice notice-error"><p><b>নোটিশের শেষ ত্রুটি (<?php echo esc_html( wp_date( 'd-m-Y H:i', $fe['t'] ) ); ?>):</b> <code><?php echo esc_html( $fe['m'] ); ?></code> — এই লেখাটা কপি করে আমাকে পাঠান। <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sn_clearerr' ), 'sn_clearerr' ) ); ?>">মুছে দিন</a></p></div>
	<?php endif; ?>
	<?php
	$lp0 = csa_sn_listpage_id();
	$on  = (bool) csa_sn_opt( 'replace' ) && $lp0;
	if ( isset( $_GET['conn'] ) ) {
		echo '<div class="notice notice-' . ( '0' === $_GET['conn'] ? 'error' : 'success' ) . '"><p>' . ( '0' === $_GET['conn'] ? 'নোটিশ পেজ পাওয়া যায়নি। "নোটিশ বোর্ড" স্ক্রিনে পেজ বেছে নিন।' : ( '1' === $_GET['conn'] ? 'পেজটা এখন plugin-এর হাতে ✓ — LiteSpeed Purge All করুন।' : 'পেজ আগের অবস্থায় ফিরেছে।' ) ) . '</p></div>';
	}
	?>
	<div class="sn-set"><h2>🔔 নোটিশ ল্যান্ডিং পেজ</h2>
	<?php if ( $lp0 ) : ?>
		<p>পেজ: <a href="<?php echo esc_url( get_permalink( $lp0 ) ); ?>" target="_blank"><b><?php echo esc_html( get_the_title( $lp0 ) ); ?></b> <small><?php echo esc_html( get_permalink( $lp0 ) ); ?></small></a> — অবস্থা: <?php echo $on ? '<b style="color:#2e7d32">✅ plugin চালাচ্ছে। নতুন নোটিশ Publish করলেই এখানে সবার ওপরে আসবে।</b>' : '<b style="color:#b26a00">⚠ এখনো হাতে চলছে (নতুন নোটিশ নিজে আসবে না)</b>'; ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"><?php wp_nonce_field( 'sn_connect' ); ?><input type="hidden" name="action" value="sn_connect"><input type="hidden" name="page" value="<?php echo (int) $lp0; ?>">
		<?php echo $on ? '<input type="hidden" name="off" value="1"><button class="button">পুরোনো অবস্থায় ফেরান</button>' : '<button class="button button-primary button-large">এই পেজ plugin-এর হাতে দিন</button>'; ?></form>
		<a class="button button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=sn-board' ) ); ?>">পেজ ও সব নোটিশ ম্যানেজ করুন</a>
		<a class="button button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=sn-board' ) ); ?>">📥 পুরোনো নোটিশ আনুন</a>
	<?php else : ?>
		<p>"নোটিশ" নামের পেজ পাওয়া যায়নি। <a href="<?php echo esc_url( admin_url( 'admin.php?page=sn-board' ) ); ?>">নোটিশ বোর্ড স্ক্রিনে</a> পেজ বেছে নিন।</p>
	<?php endif; ?></div>
	<div class="sn-set"><h2>📢 নোটিশ</h2>
	<p>নিয়োগ, নিয়োগ ফলাফল, বৃত্তি, বৃত্তি ফলাফল ও আপনার নিজের ধরনের নোটিশ। ফর্মে তথ্য লিখুন, পেজ নিজে তৈরি হবে।</p>
	<p><a class="button button-primary button-large" href="<?php echo esc_url( admin_url( 'post-new.php?sn=1' ) ); ?>">+ নতুন নোটিশ</a> <a class="button button-large" href="<?php echo esc_url( admin_url( 'edit.php?sn=1' ) ); ?>">সব নোটিশ (<?php echo (int) csa_sn_query( array(), 1 )->found_posts; ?>)</a> <a class="button button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=sn-settings' ) ); ?>">সেটিংস</a></p>
	<p class="description">যেকোনো পেজে বসানোর কোড: <code>[shaheen_recent_notices]</code> · <code>[shaheen_activities]</code> · <code>[shaheen_home_notices]</code> · <code>[shaheen_notice_list]</code></p></div>
	<div class="sn-set"><h2>আরও পেজ ধরন</h2><p class="description">ভর্তি, ইভেন্ট, গ্যালারি ইত্যাদি পেজ বিল্ডার এখানে যোগ হবে।</p></div>
	</div>
	<?php
}

add_action( 'admin_post_sn_save', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sn_settings' ) ) {
		wp_die( 'No.' );
	}
	$in = wp_unslash( $_POST['sn'] ?? array() );
	$o  = csa_sn_opts();
	$n  = array(
		'fs'         => max( 14, min( 30, (int) ( $in['fs'] ?? 20 ) ) ),
		'fs_m'       => max( 14, min( 30, (int) ( $in['fs_m'] ?? 20 ) ) ),
		'width'      => max( 700, min( 1600, (int) ( $in['width'] ?? 1240 ) ) ),
		'per_view'   => 2 === (int) ( $in['per_view'] ?? 1 ) ? 2 : 1,
		'recent'     => max( 1, min( 30, (int) ( $in['recent'] ?? 10 ) ) ),
		'hide_title' => empty( $in['hide_title'] ) ? 0 : 1,
		'brand'      => sanitize_text_field( $in['brand'] ?? '' ),
		'slug'       => sanitize_title( $in['slug'] ?? 'notice-board' ) ?: 'notice-board',
		'css'        => wp_strip_all_tags( $in['css'] ?? '' ),
		'model'      => sanitize_key( $in['model'] ?? '' ),
		'gap'        => max( 0, min( 80, (int) ( $in['gap'] ?? 12 ) ) ),
		'replace'    => empty( $in['replace'] ) ? 0 : 1,
		'board'      => (array) csa_sn_opt( 'board' ),
		'hl'         => array(
			'pages' => array_values( array_filter( array_map( 'absint', (array) ( $in['hl']['pages'] ?? array() ) ) ) ),
			'pos'   => 'bottom' === ( $in['hl']['pos'] ?? '' ) ? 'bottom' : 'top',
			'cats'  => array_values( array_filter( array_map( 'absint', (array) ( $in['hl']['cats'] ?? array() ) ) ) ),
			'limit' => max( 1, min( 30, (int) ( $in['hl']['limit'] ?? 8 ) ) ),
			'style' => 'ticker' === ( $in['hl']['style'] ?? '' ) ? 'ticker' : 'list',
			'posts' => empty( $in['hl']['posts'] ) ? 0 : 1,
			'title' => sanitize_text_field( $in['hl']['title'] ?? 'সর্বশেষ নোটিশ' ) ?: 'সর্বশেষ নোটিশ',
		),
		'old'        => sanitize_textarea_field( $in['old'] ?? '' ),
		'list_page'  => isset( $in['list_page'] ) ? absint( $in['list_page'] ) : (int) $o['list_page'],
	);
	foreach ( array( 'primary', 'dark', 'accent', 'soft' ) as $k ) {
		$n[ $k ] = preg_match( '/^#[0-9a-f]{6}$/i', $in[ $k ] ?? '' ) ? $in[ $k ] : $o[ $k ];
	}
	$n['types'] = array();
	foreach ( (array) ( $in['types'] ?? array() ) as $t ) {
		$label = sanitize_text_field( $t['label'] ?? '' );
		if ( '' === $label ) {
			continue;
		}
		$key = sanitize_key( $t['key'] ?? '' ) ?: 't' . substr( md5( $label ), 0, 6 );
		$n['types'][ $key ] = array( 'label' => $label, 'topic' => sanitize_text_field( $t['topic'] ?? '' ), 'cards' => sanitize_textarea_field( $t['cards'] ?? '' ), 'job' => empty( $t['job'] ) ? 0 : 1, 'style' => in_array( $t['style'] ?? '', array( 'job', 'result', 'form', 'admission' ), true ) ? $t['style'] : csa_sn_style_of( $key, array( 'label' => $label ) ) );
	}
	$n['types'] = $n['types'] ?: csa_sn_defaults()['types'];
	$n['acts']  = array();
	foreach ( (array) ( $in['acts'] ?? array() ) as $x ) {
		if ( '' !== trim( $x['title'] ?? '' ) ) {
			$n['acts'][] = array( 'icon' => sanitize_text_field( $x['icon'] ?? '' ), 'title' => sanitize_text_field( $x['title'] ), 'text' => sanitize_text_field( $x['text'] ?? '' ), 'url' => esc_url_raw( $x['url'] ?? '' ) );
		}
	}
	update_option( CSA_SN_OPT, array_merge( $o, $n ), false );
	flush_rewrite_rules( false );
	wp_safe_redirect( admin_url( 'admin.php?page=sn-settings&saved=1' ) );
	exit;
} );

add_action( 'admin_post_sn_listpage', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sn_settings' ) ) {
		wp_die( 'No.' );
	}
	$o  = csa_sn_opts();
	$id = wp_insert_post( array( 'post_title' => 'নোটিশ', 'post_name' => 'notices', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[shaheen_notice_list]' ) );
	if ( $id && ! is_wp_error( $id ) ) {
		$o['list_page'] = $id;
		update_option( CSA_SN_OPT, $o, false );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=sn-settings&saved=2' ) );
	exit;
} );

function csa_sn_settings_screen() {
	$o = csa_sn_opts();
	$n = 'sn';
	?>
	<div class="wrap" id="sn-settings"><h1>নোটিশ সেটিংস</h1>
	<?php echo ! empty( $_GET['saved'] ) ? '<div class="notice notice-success"><p>সংরক্ষিত ✓</p></div>' : ''; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'sn_settings' ); ?><input type="hidden" name="action" value="sn_save">
		<div class="sn-set"><h2>দেখতে কেমন</h2>
		<p>লেখার মাপ (কম্পিউটার) <input type="number" min="14" max="30" name="sn[fs]" value="<?php echo (int) $o['fs']; ?>"> মোবাইল <input type="number" min="14" max="30" name="sn[fs_m]" value="<?php echo (int) $o['fs_m']; ?>"> পিক্সেল · পেজের প্রস্থ <input type="number" min="700" max="1600" step="10" name="sn[width]" value="<?php echo (int) $o['width']; ?>"></p>
		<p>রং: মূল <input type="color" name="sn[primary]" value="<?php echo esc_attr( $o['primary'] ); ?>"> গাঢ় <input type="color" name="sn[dark]" value="<?php echo esc_attr( $o['dark'] ); ?>"> কাঠালি <input type="color" name="sn[accent]" value="<?php echo esc_attr( $o['accent'] ); ?>"> হালকা <input type="color" name="sn[soft]" value="<?php echo esc_attr( $o['soft'] ); ?>"></p>
		<p>হেডারের নিচে ও ফুটারের ওপরে ফাঁক (পিক্সেল; ০ দিলে একদম লেগে থাকবে) <input type="number" min="0" max="80" name="sn[gap]" value="<?php echo (int) $o['gap']; ?>"></p>
		<p>মোবাইলে সর্বশেষ নোটিশ একসাথে <select name="sn[per_view]"><option value="1" <?php selected( $o['per_view'], 1 ); ?>>১টা</option><option value="2" <?php selected( $o['per_view'], 2 ); ?>>২টা</option></select> · কতগুলো দেখাবে <input type="number" min="1" max="30" name="sn[recent]" value="<?php echo (int) $o['recent']; ?>"></p>
		<p>সব নোটিশে শাখার নামের ওপরে যে নাম বসবে (সব শাখার জন্য একই; খালি রাখলে বসবে না) <input type="text" class="large-text" name="sn[brand]" value="<?php echo esc_attr( $o['brand'] ); ?>"></p>
		<p><label><input type="checkbox" name="sn[hide_title]" value="1" <?php checked( $o['hide_title'] ); ?>> থিমের নিজের শিরোনাম ও অতিরিক্ত ফাঁকা লুকান (নোটিশ পেজে)</label></p>
		<p>অতিরিক্ত CSS (ফাঁকা বা থিমের সাথে মিলানোর জন্য, ঐচ্ছিক)<br><textarea name="sn[css]" rows="3" style="width:100%;max-width:640px"><?php echo esc_textarea( $o['css'] ); ?></textarea></p>
		<p>Claude দিয়ে ঘর পূরণে কোন মডেল <select name="sn[model]"><option value="">স্বয়ংক্রিয় (SEO-র মডেল)</option><?php foreach ( csa_models() as $k => $m ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['model'], $k ); ?>><?php echo esc_html( $m['label'] ); ?></option><?php endforeach; ?></select></p>
		</div>
		<div class="sn-set"><h2>নোটিশের ধরন</h2><p class="description">প্রতিটি ধরনে নামের পাশে তথ্য-কার্ডের নাম লাইন ধরে লিখুন। নতুন ধরন: নিচের "ধরন যোগ করুন"।</p>
		<div id="sn-types"><?php foreach ( $o['types'] as $k => $t ) : ?><div class="sn-trow"><input type="hidden" name="sn[types][<?php echo esc_attr( $k ); ?>][key]" value="<?php echo esc_attr( $k ); ?>"><input type="text" name="sn[types][<?php echo esc_attr( $k ); ?>][label]" value="<?php echo esc_attr( $t['label'] ); ?>" placeholder="ধরনের নাম"> <input type="text" name="sn[types][<?php echo esc_attr( $k ); ?>][topic]" value="<?php echo esc_attr( $t['topic'] ); ?>" placeholder="ডিফল্ট বিষয়"> <textarea name="sn[types][<?php echo esc_attr( $k ); ?>][cards]" rows="3" placeholder="তথ্য-কার্ড (প্রতি লাইনে একটা)"><?php echo esc_textarea( $t['cards'] ); ?></textarea> <label><input type="checkbox" name="sn[types][<?php echo esc_attr( $k ); ?>][job]" value="1" <?php checked( ! empty( $t['job'] ) ); ?>> চাকরির বিজ্ঞপ্তি (Google-এ)</label> <select name="sn[types][<?php echo esc_attr( $k ); ?>][style]"><?php foreach ( array( 'job' => 'নীল (নিয়োগ)', 'result' => 'সবুজ (ফলাফল)', 'form' => 'বেগুনি (ফরম)', 'admission' => 'কমলা (ভর্তি)' ) as $sv => $sl ) { echo '<option value="' . esc_attr( $sv ) . '"' . selected( csa_sn_style_of( $k, $t ), $sv, false ) . '>' . esc_html( $sl ) . '</option>'; } ?></select> <button type="button" class="button sn-del">মুছুন</button></div><?php endforeach; ?></div>
		<p><button type="button" class="button" id="sn-addtype">+ ধরন যোগ করুন</button></p></div>
		<div class="sn-set"><h2>সকল কার্যক্রম একনজরে</h2>
		<div id="sn-acts"><?php foreach ( (array) $o['acts'] as $i => $x ) : ?><div class="sn-arow"><input type="text" class="sn-ic" name="sn[acts][<?php echo (int) $i; ?>][icon]" value="<?php echo esc_attr( $x['icon'] ); ?>" placeholder="আইকন"> <input type="text" name="sn[acts][<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr( $x['title'] ); ?>" placeholder="নাম"> <input type="text" name="sn[acts][<?php echo (int) $i; ?>][text]" value="<?php echo esc_attr( $x['text'] ); ?>" placeholder="ছোট বিবরণ" style="min-width:260px"> <input type="url" name="sn[acts][<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $x['url'] ); ?>" placeholder="লিংক"> <button type="button" class="button sn-del">মুছুন</button></div><?php endforeach; ?></div>
		<p><button type="button" class="button" id="sn-addact">+ বক্স যোগ করুন</button> <span class="description">ওঠানো-নামানো: মুছে আবার ক্রমে যোগ করুন।</span></p></div>
		<p><button class="button button-primary button-large">সংরক্ষণ করুন</button></p>
		<div class="sn-set"><h2>পুরোনো পেজের নোটিশ (ডানের "আগের নোটিশ" বক্সে দেখাবে)</h2><p class="description">প্রতি লাইনে একটা: <code>নাম | লিংক</code> — যেমন: <code>মাদারীপুর শিক্ষক নিয়োগ ২০২৬ | https://shaheen.edu.bd/madaripur-26/</code>। সর্বোচ্চ ১২টা।</p><textarea name="sn[old]" rows="6" class="large-text"><?php echo esc_textarea( $o['old'] ); ?></textarea></div>
		<?php $hl = csa_sn_hl_opts(); ?>
		<div class="sn-set"><h2>নোটিশ হেডলাইন (আগের পেজ/ল্যান্ডিং পেজে, পেজ না বদলে)</h2>
		<p class="description">যে পেজগুলো বাছবেন সেগুলোর নিজের লেখা যেমন আছে তেমনই থাকবে, শুধু ওপরে বা নিচে "সর্বশেষ নোটিশ" হেডলাইন যোগ হবে। নতুন নোটিশ প্রকাশ করলে এখানে নিজে আসবে।</p>
		<p><b>কোন কোন পেজে?</b> (একাধিক বাছা যায়: Ctrl চেপে ধরে ক্লিক)<br><select name="sn[hl][pages][]" multiple size="8" style="min-width:320px"><?php foreach ( get_pages( array( 'number' => 300 ) ) as $pg ) { echo '<option value="' . (int) $pg->ID . '"' . selected( in_array( $pg->ID, array_map( 'intval', (array) $hl['pages'] ), true ), true, false ) . '>' . esc_html( $pg->post_title ) . ' (/' . esc_html( $pg->post_name ) . '/)</option>'; } ?></select></p>
		<p><b>কোন ক্যাটাগরির নোটিশ?</b> (কিছু না বাছলে সব নোটিশ। একাধিক হলে যেকোনো একটাতে থাকলেই আসবে)<br><?php foreach ( get_categories( array( 'hide_empty' => false ) ) as $ct ) { echo '<label style="display:inline-block;margin:2px 14px 2px 0"><input type="checkbox" name="sn[hl][cats][]" value="' . (int) $ct->term_id . '"' . checked( in_array( $ct->term_id, array_map( 'intval', (array) $hl['cats'] ), true ), true, false ) . '> ' . esc_html( $ct->name ) . '</label>'; } ?></p>
		<p><label><input type="checkbox" name="sn[hl][posts]" value="1" <?php checked( $hl['posts'] ); ?>> ওই ক্যাটাগরির সাধারণ WordPress পোস্টও একসাথে দেখান (আগের নোটিশ-পোস্ট থাকলে; না টিক দিলে শুধু নোটিশ-বিল্ডারের নোটিশ)</label></p>
		<p>অবস্থান <select name="sn[hl][pos]"><option value="top" <?php selected( $hl['pos'], 'top' ); ?>>পেজের ওপরে</option><option value="bottom" <?php selected( $hl['pos'], 'bottom' ); ?>>পেজের নিচে</option></select> · ধরন <select name="sn[hl][style]"><option value="list" <?php selected( $hl['style'], 'list' ); ?>>হেডলাইন তালিকা</option><option value="ticker" <?php selected( $hl['style'], 'ticker' ); ?>>চলমান লাইন (টিকার)</option></select> · কতগুলো <input type="number" min="1" max="30" name="sn[hl][limit]" value="<?php echo (int) $hl['limit']; ?>" style="width:70px"> · শিরোনাম <input type="text" name="sn[hl][title]" value="<?php echo esc_attr( $hl['title'] ); ?>"></p>
		<p class="description">Elementor-এর ভেতরে নিজে বসাতে চাইলে Shortcode widget-এ লিখুন: <code>[shaheen_notice_headlines category="niyog,result" limit="8"]</code> (ক্যাটাগরির slug বা id; style="ticker" দিলে চলমান লাইন)।</p>
		<p><button class="button button-primary">সংরক্ষণ করুন</button></p></div>
		<div class="sn-set"><h2>নোটিশ-তালিকা পেজ</h2><p><b>নোটিশ-তালিকা কোন পেজে দেখাবে?</b> (না বাছলে আপনার আগের /notice/ পেজই নিজে ধরবে; Elementor পেজ এডিট করতে হবে না, পেজের শেষে নতুন নোটিশগুলো নিজে যোগ হবে। নিচের "সংরক্ষণ" চাপুন) <?php wp_dropdown_pages( array( 'name' => 'sn[list_page]', 'selected' => (int) $o['list_page'], 'show_option_none' => '— বাছুন —', 'option_none_value' => '0', 'form' => '' ) ); ?></p><p><label><input type="checkbox" name="sn[replace]" value="1" <?php checked( $o['replace'] ); ?>> ওই পেজের পুরোনো লেখা সরিয়ে শুধু নোটিশ বোর্ড (সব নোটিশ, নতুনটা আগে) দেখান — পুরোনো নোটিশগুলো আনার পরে এটা চালু করুন</label></p><p><button class="button button-primary">সংরক্ষণ করুন</button></p></div>
	</form>
	<div class="sn-set"><h2>কোড (যেকোনো পেজে বসান)</h2>
	<p><code>[shaheen_recent_notices]</code> সর্বশেষ নোটিশ (স্লাইডার) · <code>[shaheen_recent_notices type="niyog" branch="মাদারীপুর" limit="5"]</code> ছাঁকা · <code>[shaheen_activities]</code> কার্যক্রম · <code>[shaheen_home_notices]</code> দুটো একসাথে · <code>[shaheen_notice_list]</code> সব নোটিশের তালিকা (ফিল্টার সহ)। ধরনের কী (key) নিচের বক্সে দেখুন: <?php echo esc_html( implode( ', ', array_keys( $o['types'] ) ) ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'sn_settings' ); ?><input type="hidden" name="action" value="sn_listpage"><?php echo $o['list_page'] && get_post_status( (int) $o['list_page'] ) ? '<p>নোটিশ-তালিকা পেজ আছে: <a href="' . esc_url( get_permalink( (int) $o['list_page'] ) ) . '">দেখুন</a></p>' : '<button class="button">"নোটিশ" নামে তালিকা-পেজ বানান</button>'; ?></form></div>
	</div>
	<script>
	(function(){var c=0,T=document.getElementById('sn-types'),A=document.getElementById('sn-acts');
	document.addEventListener('click',function(e){if(e.target.classList.contains('sn-del'))e.target.parentNode.remove()});
	document.getElementById('sn-addtype').onclick=function(){var k='n'+Date.now();var d=document.createElement('div');d.className='sn-trow';d.innerHTML='<input type="hidden" name="sn[types]['+k+'][key]" value="'+k+'"><input type="text" name="sn[types]['+k+'][label]" placeholder="ধরনের নাম"> <input type="text" name="sn[types]['+k+'][topic]" placeholder="ডিফল্ট বিষয়"> <textarea name="sn[types]['+k+'][cards]" rows="3" placeholder="তথ্য-কার্ড (প্রতি লাইনে একটা)"></textarea> <label><input type="checkbox" name="sn[types]['+k+'][job]" value="1"> চাকরির বিজ্ঞপ্তি (Google-এ)</label> <select name="sn[types]['+k+'][style]"><option value="job">নীল (নিয়োগ)</option><option value="result">সবুজ (ফলাফল)</option><option value="form">বেগুনি (ফরম)</option><option value="admission">কমলা (ভর্তি)</option></select> <button type="button" class="button sn-del">মুছুন</button>';T.appendChild(d)};
	document.getElementById('sn-addact').onclick=function(){var k=Date.now();var d=document.createElement('div');d.className='sn-arow';d.innerHTML='<input type="text" class="sn-ic" name="sn[acts]['+k+'][icon]" placeholder="আইকন"> <input type="text" name="sn[acts]['+k+'][title]" placeholder="নাম"> <input type="text" name="sn[acts]['+k+'][text]" placeholder="ছোট বিবরণ" style="min-width:260px"> <input type="url" name="sn[acts]['+k+'][url]" placeholder="লিংক"> <button type="button" class="button sn-del">মুছুন</button>';A.appendChild(d)};})();
	</script>
	<?php
}
