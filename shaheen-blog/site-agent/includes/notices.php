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
		'slug'      => 'notice',
		'css'       => '',
		'model'     => '',
		'list_page' => 0,
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

function csa_sn_opts() {
	$o = get_option( CSA_SN_OPT, array() );
	return wp_parse_args( is_array( $o ) ? $o : array(), csa_sn_defaults() );
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

add_action( 'init', function () {
	register_post_type( 'sef_notice', array(
		'labels'       => array( 'name' => 'নোটিশ', 'singular_name' => 'নোটিশ', 'add_new' => 'নতুন নোটিশ', 'add_new_item' => 'নতুন নোটিশ', 'edit_item' => 'নোটিশ এডিট', 'all_items' => 'সব নোটিশ', 'menu_name' => 'নোটিশ' ),
		'public'       => true,
		'has_archive'  => false,
		'show_in_menu' => 'sef-builder',
		'supports'     => array( 'title', 'editor', 'thumbnail', 'revisions', 'author' ),
		'rewrite'      => array( 'slug' => sanitize_title( csa_sn_opt( 'slug' ) ?: 'notice' ), 'with_front' => false ),
		'show_in_rest' => false,
		'capability_type' => 'post',
	) );
} );

/* ------------------------------------------------------------------ */
/* Drawing                                                              */
/* ------------------------------------------------------------------ */

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
	$css  = ".sn,.sn *{box-sizing:border-box}.sn{--p:{$p};--k:{$k};--a:{$a};--s:{$s};--r:14px;max-width:{$w}px;margin:0 auto;padding:6px 8px;font-size:{$fs}px;line-height:1.7;color:#1d2b22}"
		. ".sn-bn{position:relative;overflow:hidden;border-radius:var(--r);color:#fff;text-align:center;padding:clamp(20px,4vw,34px) clamp(14px,4vw,34px);background:linear-gradient(120deg,var(--k),var(--p),var(--a),var(--p),var(--k));background-size:300% 300%;animation:snflow 10s ease infinite}"
		. ".sn-bn:before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 85% 20%,rgba(255,255,255,.18),transparent 45%);animation:snpulse 5s ease-in-out infinite}"
		. "@keyframes snflow{0%{background-position:0 50%}50%{background-position:100% 50%}100%{background-position:0 50%}}@keyframes snpulse{50%{opacity:.4}}@keyframes snin{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}"
		. ".sn-bt{position:relative;font-size:clamp(28px,7vw,52px);font-weight:700;line-height:1.2;margin:0 0 6px;text-shadow:0 2px 8px rgba(0,0,0,.25);animation:snin 1s ease both}"
		. ".sn-sub{position:relative;display:inline-block;background:#fff;color:var(--k);font-weight:700;border-radius:999px;padding:3px 16px;font-size:clamp(18px,4.4vw,28px);animation:snin 1s .2s ease both}.sn-yr{position:relative;display:inline-block;margin-left:8px;background:var(--a);border-radius:999px;padding:3px 14px;font-weight:700;font-size:clamp(16px,3.6vw,22px)}"
		. ".sn-st{display:inline-block;margin-top:10px;position:relative;border-radius:999px;padding:2px 14px;font-size:.8em;font-weight:700;background:rgba(255,255,255,.2)}.sn-st.x{background:#b3261e}"
		. ".sn-lay{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:14px;margin:12px 0;align-items:start}.sn-side{position:sticky;top:12px}"
		. ".sn-card{background:#fff;border:1px solid #dfe6e0;border-radius:var(--r);padding:14px 12px;margin:0 0 12px}.sn-card h2,.sn-h{margin:0 0 8px;font-size:1.4em;color:var(--k);line-height:1.3}"
		. ".sn-info{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin:0 0 12px}.sn-info>div{background:var(--s);border:1px solid #cfe1d2;border-radius:var(--r);padding:12px}.sn-info b{display:block;color:var(--p);font-size:.95em}.sn-info span{display:block;font-weight:700;font-size:1.1em;line-height:1.5}"
		. ".sn-ck{list-style:none;margin:0;padding:0}.sn-ck li{padding:10px 0 10px 36px;border-bottom:1px dashed #cfd8d1;position:relative}.sn-ck li:last-child{border-bottom:0}.sn-ck li:before{content:'✓';position:absolute;left:0;top:12px;width:25px;height:25px;border-radius:50%;background:var(--p);color:#fff;font-size:15px;display:grid;place-items:center;line-height:1}"
		. ".sn-note{background:#fff7e0;border-left:4px solid var(--a);padding:10px 14px;border-radius:8px;margin:0 0 12px}.sn-txt p{margin:0 0 .6em}"
		. ".sn-im{display:grid;gap:10px;margin:0 0 12px}.sn-im img{width:100%;height:auto;border-radius:var(--r);display:block}.sn-im.g2{grid-template-columns:1fr 1fr}.sn-cap{text-align:center;font-size:.85em;color:#556;margin:-6px 0 12px}"
		. ".sn-btns{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin:0 0 12px}.sn-btn{display:flex;align-items:center;justify-content:center;gap:6px;padding:14px 18px;border-radius:12px;font-weight:700;text-decoration:none!important;color:#fff!important;background:var(--p);text-align:center}.sn-btn.pdf{background:var(--a)}.sn-btn.fb{background:#1877f2}.sn-btn.oth{background:var(--k)}"
		. ".sn-ph{display:flex;flex-wrap:wrap;gap:10px}.sn-ph a{background:var(--p);color:#fff!important;border-radius:10px;padding:8px 16px;font-weight:700;text-decoration:none!important;font-size:1.05em}"
		. ".sn-emb{position:relative;aspect-ratio:16/9;border-radius:var(--r);overflow:hidden;background:#000;margin:0 0 12px}.sn-emb iframe{position:absolute;inset:0;width:100%;height:100%;border:0}"
		. ".sn-side h3{margin:0 0 6px;font-size:1.15em;color:var(--k);border-bottom:2px solid var(--p);padding-bottom:6px}.sn-side ul{margin:0;padding:0;list-style:none}.sn-side li{padding:10px 0;border-bottom:1px solid #edf1ee;line-height:1.5}.sn-side li small{display:block;color:var(--p);font-weight:700;font-size:.85em}.sn-side li time{color:#889;font-size:.8em}.sn-side a{color:inherit;text-decoration:none}"
		. ".sn-all{display:block;text-align:center;background:var(--p);color:#fff!important;border-radius:12px;padding:12px;font-weight:700;text-decoration:none!important;margin-top:10px}.sn-share{font-size:.8em;color:#778}.sn-share a{color:var(--p)}"
		/* cards used in lists and sliders */
		. ".sn-grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(230px,1fr))}.sn-nc{background:#fff;border:1px solid #dfe6e0;border-radius:var(--r);padding:12px;text-decoration:none!important;color:inherit!important;display:block;position:relative}.sn-nc .th{aspect-ratio:16/9;border-radius:10px;margin:-2px 0 8px;display:grid;place-items:center;text-align:center;color:#fff;font-weight:700;line-height:1.3;padding:6px;background:linear-gradient(120deg,var(--k),var(--p) 60%,var(--a));overflow:hidden}.sn-nc .th img{width:100%;height:100%;object-fit:cover;display:block}"
		. ".sn-nc small{color:var(--p);font-weight:700;display:block;font-size:.8em}.sn-nc b{font-weight:700;display:block;line-height:1.35;margin:3px 0 6px;font-size:1em;color:var(--k)}.sn-nc time{font-size:.75em;color:#889}.sn-nc .ex{position:absolute;top:18px;right:18px;background:#b3261e;color:#fff;border-radius:999px;padding:1px 10px;font-size:.7em;font-weight:700}"
		. ".sn-rn{position:relative}.sn-trk{--pv:" . (int) ( 2 === (int) $o['per_view'] ? 2 : 1 ) . ";display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(230px,1fr))}.sn-dots{display:none;text-align:center;margin-top:6px}.sn-dots i{display:inline-block;width:8px;height:8px;border-radius:50%;background:#c4d2c8;margin:0 3px}.sn-dots i.on{background:var(--p);width:20px;border-radius:6px}"
		. ".sn-fl{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}.sn-fl input,.sn-fl select{padding:9px 12px;border:1px solid #cfd8d1;border-radius:10px;font:inherit;font-size:.9em;background:#fff}.sn-fl button{padding:9px 18px;border:0;border-radius:10px;background:var(--p);color:#fff;font:inherit;font-size:.9em;font-weight:700;cursor:pointer}.sn-pg{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}.sn-pg a,.sn-pg span{padding:7px 14px;border-radius:8px;border:1px solid #cfd8d1;text-decoration:none;color:var(--k)}.sn-pg span{background:var(--p);color:#fff;border-color:var(--p)}"
		/* activities */
		. ".sn-act{text-align:center}.sn-act small{display:block;color:var(--p);font-weight:700;font-size:.85em;line-height:1.3}.sn-act h2{margin:0;font-size:1.5em;color:var(--k);line-height:1.25}.sn-act .lead{margin:0 0 6px;color:#556;font-size:.9em;line-height:1.4}"
		. ".sn-aw{position:relative}.sn-as{display:flex;gap:10px;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;padding:2px;scroll-behavior:smooth}.sn-as::-webkit-scrollbar{display:none}.sn-as .c{flex:0 0 min(84%,290px);scroll-snap-align:start;background:#fff;border:1px solid #dfe6e0;border-radius:14px;padding:12px 14px;text-align:left;line-height:1.45}"
		. ".sn-as .c i{display:grid;place-items:center;width:46px;height:46px;border-radius:12px;background:var(--s);font-style:normal;font-size:26px;margin-bottom:6px}.sn-as .c b{font-weight:700;display:block;font-size:1.1em;color:var(--k);line-height:1.3}.sn-as .c p{margin:2px 0 6px;font-size:.9em;color:#445}.sn-as .c a{color:var(--p);font-weight:700;text-decoration:none}"
		. ".sn-ar{display:none;position:absolute;top:50%;transform:translateY(-50%);width:44px;height:44px;border-radius:50%;border:0;background:var(--p);color:#fff;font-size:22px;cursor:pointer;box-shadow:0 3px 10px rgba(0,0,0,.25);z-index:2}.sn-ar.l{left:-4px}.sn-ar.r{right:-4px}"
		. "@media(min-width:860px){.sn-aw{padding:0 26px}.sn-ar{display:block}.sn-as .c{flex:0 0 calc((100% - 30px)/4)}}"
		. "@media(max-width:859px){.sn{font-size:{$fm}px}.sn-lay{grid-template-columns:1fr}.sn-side{display:none}.sn-btns{grid-template-columns:1fr}.sn-trk.sl{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none}.sn-trk.sl::-webkit-scrollbar{display:none}.sn-trk.sl .sn-nc{flex:0 0 calc((100% - (var(--pv) - 1)*12px)/var(--pv));scroll-snap-align:start}.sn-dots{display:block}}";
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
			foreach ( (array) ( $b['rows'] ?? array() ) as $r ) {
				if ( '' !== trim( (string) ( $r['v'] ?? '' ) ) ) {
					$o .= '<div><b>' . esc_html( $r['l'] ?? '' ) . '</b><span>' . esc_html( $r['v'] ) . '</span></div>';
				}
			}
			return $o ? '<div class="sn-info">' . $o . '</div>' : '';
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
	$bn     = '<div class="sn-bn">' . ( $branch ? '<div class="sn-bt">' . esc_html( $branch ) . '</div>' : '' ) . '<span class="sn-sub">' . esc_html( $topic ) . '</span>' . ( ! empty( $d['year'] ) ? '<span class="sn-yr">' . esc_html( $d['year'] ) . '</span>' : '' ) . ( get_post_meta( $id, '_sn_expiry', true ) ? '<div><span class="sn-st' . ( $exp ? ' x' : '' ) . '">' . ( $exp ? 'আবেদনের মেয়াদ শেষ' : 'চলমান' ) . '</span></div>' : '' ) . '</div>';
	$main   = '';
	foreach ( (array) ( $d['blocks'] ?? array() ) as $b ) {
		$main .= csa_sn_block( $b );
	}
	$prev = '';
	foreach ( get_posts( array( 'post_type' => 'sef_notice', 'post_status' => 'publish', 'numberposts' => 4, 'exclude' => array( $id ), 'meta_key' => '_sn_type', 'meta_value' => $d['type'] ?? '' ) ) as $p ) {
		$pb    = (string) get_post_meta( $p->ID, '_sn_branch', true );
		$prev .= '<li><a href="' . esc_url( get_permalink( $p ) ) . '"><small>' . esc_html( $tl . ( $pb ? ' · ' . $pb : '' ) ) . '</small>' . esc_html( get_the_title( $p ) ) . '<br><time>' . esc_html( csa_sn_date( get_post_time( 'U', true, $p ) ) ) . '</time></a></li>';
	}
	$lp   = (int) $o['list_page'];
	$all  = $lp && get_post_status( $lp ) ? get_permalink( $lp ) : get_post_type_archive_link( 'sef_notice' );
	$side = $prev ? '<aside class="sn-card sn-side"><h3>আগের নোটিশ</h3><ul>' . $prev . '</ul>' . ( $all ? '<a class="sn-all" href="' . esc_url( $all ) . '">সব নোটিশ দেখুন →</a>' : '' ) . '</aside>' : '';
	$url  = rawurlencode( get_permalink( $id ) );
	$share = '<p class="sn-share">শেয়ার: <a target="_blank" rel="noopener" href="https://wa.me/?text=' . $url . '">WhatsApp</a> · <a target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=' . $url . '">Facebook</a></p>';
	return csa_sn_css() . '<div class="sn">' . $bn . '<div class="sn-lay"><div>' . $main . $share . '</div>' . $side . '</div></div>';
}

add_filter( 'the_content', function ( $c ) {
	if ( ! is_singular( 'sef_notice' ) || ! in_the_loop() || ! is_main_query() ) {
		return $c;
	}
	$d = csa_sn_get( get_the_ID() );
	if ( ! empty( $d['detached'] ) ) {
		return $c;
	}
	return csa_sn_render( get_the_ID() );
}, 20 );

add_action( 'wp_head', function () {
	if ( ! is_singular( 'sef_notice' ) ) {
		return;
	}
	if ( csa_sn_opt( 'hide_title' ) ) {
		echo '<style>body.single-sef_notice .entry-header,body.single-sef_notice .entry-title,body.single-sef_notice .page-title,body.single-sef_notice .post-title,body.single-sef_notice .entry-meta,body.single-sef_notice .post-meta,body.single-sef_notice .post-thumbnail,body.single-sef_notice .elementor-widget-theme-post-title,body.single-sef_notice .wp-block-post-title,body.single-sef_notice .wp-block-post-author,body.single-sef_notice .wp-block-post-date,body.single-sef_notice .wp-block-post-terms,body.single-sef_notice .wp-block-post-featured-image,body.single-sef_notice .elementor-widget-theme-post-featured-image{display:none!important}body.single-sef_notice .entry-content,body.single-sef_notice .site-main,body.single-sef_notice article{margin-top:0!important;padding-top:0!important}</style>';
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
					$rows[] = array( 'l' => sanitize_text_field( $r['l'] ?? '' ), 'v' => sanitize_text_field( $r['v'] ?? '' ) );
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
		'slug'     => sanitize_title( $d['slug'] ?? '' ),
		'detached' => empty( $d['detached'] ) ? 0 : 1,
		'blocks'   => csa_sn_clean_blocks( $d['blocks'] ?? array() ),
	);
}

add_filter( 'wp_insert_post_data', function ( $data, $post ) {
	if ( 'sef_notice' !== $data['post_type'] || ! isset( $_POST['sn_data'] ) || empty( $_POST['sn_nonce'] ) || ! wp_verify_nonce( $_POST['sn_nonce'], 'sn_save' ) ) {
		return $data;
	}
	$d  = csa_sn_clean( json_decode( wp_unslash( $_POST['sn_data'] ), true ) );
	$ts = csa_sn_opt( 'types' )[ $d['type'] ] ?? array();
	if ( '' === trim( (string) $data['post_title'] ) || 'Auto Draft' === $data['post_title'] ) {
		$t = trim( ( $d['topic'] ?: ( $ts['topic'] ?? 'নোটিশ' ) ) . ( $d['branch'] ? ' — ' . $d['branch'] : '' ) . ( $d['year'] ? ' ' . $d['year'] : '' ) );
		$data['post_title'] = $t;
	}
	if ( $d['slug'] ) {
		$data['post_name'] = $d['slug'];
	}
	return $data;
}, 10, 2 );

add_action( 'save_post_sef_notice', function ( $id, $post ) {
	if ( ! isset( $_POST['sn_data'] ) || empty( $_POST['sn_nonce'] ) || ! wp_verify_nonce( $_POST['sn_nonce'], 'sn_save' ) || ! current_user_can( 'edit_post', $id ) || wp_is_post_revision( $id ) ) {
		return;
	}
	$d    = csa_sn_clean( json_decode( wp_unslash( $_POST['sn_data'] ), true ) );
	$old  = csa_sn_get( $id );
	update_post_meta( $id, '_sn', wp_slash( $d ) );
	update_post_meta( $id, '_sn_type', $d['type'] );
	update_post_meta( $id, '_sn_branch', $d['branch'] );
	update_post_meta( $id, '_sn_expiry', $d['expiry'] );
	if ( $d['summary'] ) {
		wp_update_post_excerpt_safe( $id, $d['summary'] );
	}
	// Switching to hand editing: keep what is shown now as the post's own content.
	static $busy = false;
	if ( $d['detached'] && empty( $old['detached'] ) && ! $busy ) {
		$busy = true;
		wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( csa_sn_render( $id ) ) ) );
		$busy = false;
	}
}, 10, 2 );

function wp_update_post_excerpt_safe( $id, $text ) {
	global $wpdb;
	$wpdb->update( $wpdb->posts, array( 'post_excerpt' => $text ), array( 'ID' => $id ) );
}

/* ------------------------------------------------------------------ */
/* Edit screen                                                          */
/* ------------------------------------------------------------------ */

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'sn_box', 'নোটিশের ঘর', function ( $post ) {
		wp_nonce_field( 'sn_save', 'sn_nonce' );
		echo '<input type="hidden" name="sn_data" id="sn_data" value="' . esc_attr( wp_json_encode( csa_sn_get( $post->ID ), CSA_JSON ) ) . '"><div id="sn-app"><p>লোড হচ্ছে…</p></div>';
	}, 'sef_notice', 'normal', 'high' );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	$scr = get_current_screen();
	if ( false !== strpos( $hook, 'sn-settings' ) || false !== strpos( $hook, 'sef-builder' ) ) {
		wp_enqueue_style( 'sn-admin', CSA_URL . 'assets/notice.css', array(), CSA_VER );
		return;
	}
	if ( ! $scr || 'sef_notice' !== $scr->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
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
	wp_localize_script( 'sn-admin', 'SN', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'csa' ), 'types' => $ty, 'branches' => $br, 'year' => csa_sn_bn( wp_date( 'Y' ) ) ) );
} );

/** Claude fills the form from pasted text (e.g. a Facebook post). */
add_action( 'wp_ajax_csa_sn_ai', function () {
	csa_guard( 'chat' );
	$txt = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
	if ( mb_strlen( $txt ) < 15 ) {
		wp_send_json_error( 'আগে নোটিশের লেখা পেস্ট করুন।' );
	}
	$ty = array();
	foreach ( csa_sn_opt( 'types' ) as $k => $t ) {
		$ty[] = $k . ' = ' . $t['label'];
	}
	$sys = 'You read a school notice (Bangla or English) and fill a form. Return ONLY JSON: {"type":"one of: ' . implode( ', ', $ty ) . '","branch":"branch name as written, e.g. শাহীন স্কুল, মাদারীপুর শাখা, or empty","topic":"short subject, e.g. সহকারী শিক্ষক নিয়োগ","year":"year in Bangla digits or empty","expiry":"application last date as YYYY-MM-DD or empty","summary":"1-2 plain sentences","cards":[{"l":"label such as আবেদনের শেষ সময়","v":"value exactly as written"}],"conditions":["each condition or rule as one short line"],"note":"one extra note or empty","phones":["numbers exactly as written"],"links":[{"kind":"apply|pdf|fb|oth","label":"button words","url":"only if the text contains it"}]}. Copy facts exactly; never invent dates, numbers, names or links; leave a field empty when the text does not say.';
	$m   = csa_sn_opt( 'model' );
	$r   = csa_jobs_ai_json( $sys, $txt, 'Notice: fill', 1800, $m && csa_model( $m ) ? array( 'model_key' => $m ) : array() );
	is_wp_error( $r ) ? wp_send_json_error( $r->get_error_message() ) : wp_send_json_success( csa_sn_any_clean( $r ) );
} );

/* ------------------------------------------------------------------ */
/* Shortcodes                                                           */
/* ------------------------------------------------------------------ */

function csa_sn_card( $p ) {
	$id  = $p->ID;
	$d   = csa_sn_get( $id );
	$ty  = csa_sn_opt( 'types' )[ $d['type'] ?? '' ]['label'] ?? '';
	$br  = (string) ( $d['branch'] ?? '' );
	$img = get_the_post_thumbnail_url( $id, 'medium_large' );
	$th  = $img ? '<img loading="lazy" src="' . esc_url( $img ) . '" alt="">' : esc_html( ( $br ? $br . ' — ' : '' ) . ( ( $d['topic'] ?? '' ) ?: $ty ) );
	return '<a class="sn-nc" href="' . esc_url( get_permalink( $id ) ) . '"><div class="th">' . $th . '</div><small>' . esc_html( $ty . ( $br ? ' · ' . $br : '' ) ) . '</small><b>' . esc_html( get_the_title( $id ) ) . '</b><time>' . esc_html( csa_sn_date( get_post_time( 'U', true, $p ) ) ) . '</time>' . ( csa_sn_expired( $id ) ? '<span class="ex">মেয়াদ শেষ</span>' : '' ) . '</a>';
}

function csa_sn_query( $a, $n, $paged = 1 ) {
	$mq = array();
	if ( ! empty( $a['type'] ) ) {
		$mq[] = array( 'key' => '_sn_type', 'value' => sanitize_key( $a['type'] ) );
	}
	if ( ! empty( $a['branch'] ) ) {
		$mq[] = array( 'key' => '_sn_branch', 'value' => sanitize_text_field( $a['branch'] ), 'compare' => 'LIKE' );
	}
	$q = array( 'post_type' => 'sef_notice', 'post_status' => 'publish', 'posts_per_page' => $n, 'paged' => $paged, 'orderby' => 'date', 'order' => 'DESC' );
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
	$a   = shortcode_atts( array( 'type' => '', 'per_page' => 12 ), $a );
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
	return csa_sn_css() . '<div class="sn">' . $f . ( $c ? '<div class="sn-grid">' . $c . '</div>' . $pgs : '<p>কোনো নোটিশ পাওয়া যায়নি।</p>' ) . '</div>';
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
	add_submenu_page( 'sef-builder', 'নোটিশ সেটিংস', 'নোটিশ সেটিংস', 'manage_options', 'sn-settings', 'csa_sn_settings_screen' );
}, 5 );

function csa_sn_overview() {
	$o = csa_sn_opts();
	?>
	<div class="wrap" id="sn-settings"><h1>Page Builder</h1>
	<div class="sn-set"><h2>📢 নোটিশ</h2>
	<p>নিয়োগ, নিয়োগ ফলাফল, বৃত্তি, বৃত্তি ফলাফল ও আপনার নিজের ধরনের নোটিশ। ফর্মে তথ্য লিখুন, পেজ নিজে তৈরি হবে।</p>
	<p><a class="button button-primary button-large" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=sef_notice' ) ); ?>">+ নতুন নোটিশ</a> <a class="button button-large" href="<?php echo esc_url( admin_url( 'edit.php?post_type=sef_notice' ) ); ?>">সব নোটিশ (<?php echo (int) wp_count_posts( 'sef_notice' )->publish; ?>)</a> <a class="button button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=sn-settings' ) ); ?>">সেটিংস</a></p>
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
		'slug'       => sanitize_title( $in['slug'] ?? 'notice' ) ?: 'notice',
		'css'        => wp_strip_all_tags( $in['css'] ?? '' ),
		'model'      => sanitize_key( $in['model'] ?? '' ),
		'list_page'  => (int) $o['list_page'],
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
		$n['types'][ $key ] = array( 'label' => $label, 'topic' => sanitize_text_field( $t['topic'] ?? '' ), 'cards' => sanitize_textarea_field( $t['cards'] ?? '' ), 'job' => empty( $t['job'] ) ? 0 : 1 );
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
		<p>মোবাইলে সর্বশেষ নোটিশ একসাথে <select name="sn[per_view]"><option value="1" <?php selected( $o['per_view'], 1 ); ?>>১টা</option><option value="2" <?php selected( $o['per_view'], 2 ); ?>>২টা</option></select> · কতগুলো দেখাবে <input type="number" min="1" max="30" name="sn[recent]" value="<?php echo (int) $o['recent']; ?>"></p>
		<p><label><input type="checkbox" name="sn[hide_title]" value="1" <?php checked( $o['hide_title'] ); ?>> থিমের নিজের শিরোনাম ও অতিরিক্ত ফাঁকা লুকান (নোটিশ পেজে)</label></p>
		<p>লিংকের শুরু (পরিবর্তন করলে পুরনো লিংক বদলে যাবে) <input type="text" name="sn[slug]" value="<?php echo esc_attr( $o['slug'] ); ?>"></p>
		<p>অতিরিক্ত CSS (ফাঁকা বা থিমের সাথে মিলানোর জন্য, ঐচ্ছিক)<br><textarea name="sn[css]" rows="3" style="width:100%;max-width:640px"><?php echo esc_textarea( $o['css'] ); ?></textarea></p>
		<p>Claude দিয়ে ঘর পূরণে কোন মডেল <select name="sn[model]"><option value="">স্বয়ংক্রিয় (SEO-র মডেল)</option><?php foreach ( csa_models() as $k => $m ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['model'], $k ); ?>><?php echo esc_html( $m['label'] ); ?></option><?php endforeach; ?></select></p>
		</div>
		<div class="sn-set"><h2>নোটিশের ধরন</h2><p class="description">প্রতিটি ধরনে নামের পাশে তথ্য-কার্ডের নাম লাইন ধরে লিখুন। নতুন ধরন: নিচের "ধরন যোগ করুন"।</p>
		<div id="sn-types"><?php foreach ( $o['types'] as $k => $t ) : ?><div class="sn-trow"><input type="hidden" name="sn[types][<?php echo esc_attr( $k ); ?>][key]" value="<?php echo esc_attr( $k ); ?>"><input type="text" name="sn[types][<?php echo esc_attr( $k ); ?>][label]" value="<?php echo esc_attr( $t['label'] ); ?>" placeholder="ধরনের নাম"> <input type="text" name="sn[types][<?php echo esc_attr( $k ); ?>][topic]" value="<?php echo esc_attr( $t['topic'] ); ?>" placeholder="ডিফল্ট বিষয়"> <textarea name="sn[types][<?php echo esc_attr( $k ); ?>][cards]" rows="3" placeholder="তথ্য-কার্ড (প্রতি লাইনে একটা)"><?php echo esc_textarea( $t['cards'] ); ?></textarea> <label><input type="checkbox" name="sn[types][<?php echo esc_attr( $k ); ?>][job]" value="1" <?php checked( ! empty( $t['job'] ) ); ?>> চাকরির বিজ্ঞপ্তি (Google-এ)</label> <button type="button" class="button sn-del">মুছুন</button></div><?php endforeach; ?></div>
		<p><button type="button" class="button" id="sn-addtype">+ ধরন যোগ করুন</button></p></div>
		<div class="sn-set"><h2>সকল কার্যক্রম একনজরে</h2>
		<div id="sn-acts"><?php foreach ( (array) $o['acts'] as $i => $x ) : ?><div class="sn-arow"><input type="text" class="sn-ic" name="sn[acts][<?php echo (int) $i; ?>][icon]" value="<?php echo esc_attr( $x['icon'] ); ?>" placeholder="আইকন"> <input type="text" name="sn[acts][<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr( $x['title'] ); ?>" placeholder="নাম"> <input type="text" name="sn[acts][<?php echo (int) $i; ?>][text]" value="<?php echo esc_attr( $x['text'] ); ?>" placeholder="ছোট বিবরণ" style="min-width:260px"> <input type="url" name="sn[acts][<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $x['url'] ); ?>" placeholder="লিংক"> <button type="button" class="button sn-del">মুছুন</button></div><?php endforeach; ?></div>
		<p><button type="button" class="button" id="sn-addact">+ বক্স যোগ করুন</button> <span class="description">ওঠানো-নামানো: মুছে আবার ক্রমে যোগ করুন।</span></p></div>
		<p><button class="button button-primary button-large">সংরক্ষণ করুন</button></p>
	</form>
	<div class="sn-set"><h2>কোড (যেকোনো পেজে বসান)</h2>
	<p><code>[shaheen_recent_notices]</code> সর্বশেষ নোটিশ (স্লাইডার) · <code>[shaheen_recent_notices type="niyog" branch="মাদারীপুর" limit="5"]</code> ছাঁকা · <code>[shaheen_activities]</code> কার্যক্রম · <code>[shaheen_home_notices]</code> দুটো একসাথে · <code>[shaheen_notice_list]</code> সব নোটিশের তালিকা (ফিল্টার সহ)। ধরনের কী (key) নিচের বক্সে দেখুন: <?php echo esc_html( implode( ', ', array_keys( $o['types'] ) ) ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'sn_settings' ); ?><input type="hidden" name="action" value="sn_listpage"><?php echo $o['list_page'] && get_post_status( (int) $o['list_page'] ) ? '<p>নোটিশ-তালিকা পেজ আছে: <a href="' . esc_url( get_permalink( (int) $o['list_page'] ) ) . '">দেখুন</a></p>' : '<button class="button">"নোটিশ" নামে তালিকা-পেজ বানান</button>'; ?></form></div>
	</div>
	<script>
	(function(){var c=0,T=document.getElementById('sn-types'),A=document.getElementById('sn-acts');
	document.addEventListener('click',function(e){if(e.target.classList.contains('sn-del'))e.target.parentNode.remove()});
	document.getElementById('sn-addtype').onclick=function(){var k='n'+Date.now();var d=document.createElement('div');d.className='sn-trow';d.innerHTML='<input type="hidden" name="sn[types]['+k+'][key]" value="'+k+'"><input type="text" name="sn[types]['+k+'][label]" placeholder="ধরনের নাম"> <input type="text" name="sn[types]['+k+'][topic]" placeholder="ডিফল্ট বিষয়"> <textarea name="sn[types]['+k+'][cards]" rows="3" placeholder="তথ্য-কার্ড (প্রতি লাইনে একটা)"></textarea> <label><input type="checkbox" name="sn[types]['+k+'][job]" value="1"> চাকরির বিজ্ঞপ্তি (Google-এ)</label> <button type="button" class="button sn-del">মুছুন</button>';T.appendChild(d)};
	document.getElementById('sn-addact').onclick=function(){var k=Date.now();var d=document.createElement('div');d.className='sn-arow';d.innerHTML='<input type="text" class="sn-ic" name="sn[acts]['+k+'][icon]" placeholder="আইকন"> <input type="text" name="sn[acts]['+k+'][title]" placeholder="নাম"> <input type="text" name="sn[acts]['+k+'][text]" placeholder="ছোট বিবরণ" style="min-width:260px"> <input type="url" name="sn[acts]['+k+'][url]" placeholder="লিংক"> <button type="button" class="button sn-del">মুছুন</button>';A.appendChild(d)};})();
	</script>
	<?php
}
