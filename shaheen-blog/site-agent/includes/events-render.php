<?php
/** Public HTML of the event pages and the new home page. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function csa_ev_img( $url, $alt = '', $cls = '' ) {
	return $url ? '<img class="' . esc_attr( $cls ) . '" src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async">' : '';
}

/** Icon or picture of a card: the picture wins, else the emoji / letters. */
function csa_ev_icon( $img, $ic, $alt = '' ) {
	if ( $img ) {
		return '<span class="ic im">' . csa_ev_img( $img, $alt ) . '</span>';
	}
	return $ic ? '<span class="ic">' . esc_html( $ic ) . '</span>' : '';
}

function csa_ev_vars( $e ) {
	$f   = ( $e['font'] && function_exists( 'csa_font' ) ) ? csa_font( $e['font'] ) : null;
	$stk = $f ? csa_font_stack( $f ) : "'Baloo Da 2','Hind Siliguri',sans-serif";
	return '--c1:' . $e['c1'] . ';--c2:' . $e['c2'] . ';--ac:' . $e['ac'] . ';--hf:' . $stk . ';';
}

function csa_ev_btn_html( $b, $cls = '' ) {
	$ext = preg_match( '#^https?://#', $b['u'] ) && 0 !== strpos( $b['u'], home_url() );
	return '<a class="b ' . esc_attr( $b['k'] ) . ' ' . $cls . '" href="' . esc_url( csa_ev_url( $b['u'] ) ) . '"' . ( $ext ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( $b['t'] ) . '</a>';
}

function csa_ev_sparkles( $n = 14 ) {
	$h = '<div class="sp" aria-hidden="true">';
	for ( $i = 0; $i < $n; $i++ ) {
		$h .= '<span style="left:' . ( ( $i * 37 + 7 ) % 100 ) . '%;animation-duration:' . ( 9 + ( $i * 5 ) % 9 ) . 's;animation-delay:-' . ( ( $i * 3 ) % 11 ) . 's;font-size:' . ( 12 + ( $i * 7 ) % 14 ) . 'px">' . ( 0 === $i % 3 ? '★' : '✦' ) . '</span>';
	}
	return $h . '</div>';
}

function csa_ev_head( $t, $p = '', $light = false ) {
	return '<div class="sh rv' . ( $light ? ' lt' : '' ) . '"><h2>' . esc_html( $t ) . '</h2>' . ( $p ? '<p>' . esc_html( $p ) . '</p>' : '' ) . '<i></i></div>';
}

/* ---------- Event page ---------- */
function csa_ev_event_html( $e ) {
	$stage = csa_ev_stage( $e );
	$ms    = csa_ev_miles( $e );
	$btns  = csa_ev_btns( $e );
	$nav   = array();
	$h     = csa_ev_assets_tag() . '<div class="sev" style="' . esc_attr( csa_ev_vars( $e ) ) . '">';

	/* hero */
	$cover = $e['cover'] ? ' style="background-image:linear-gradient(120deg,' . esc_attr( $e['c1'] ) . 'e6,' . esc_attr( $e['c2'] ) . 'cc),url(' . esc_url( $e['cover'] ) . ')"' : '';
	$h    .= '<section class="hero"' . $cover . '>' . csa_ev_sparkles() . '<div class="in">';
	$h    .= '<a class="back" href="' . esc_url( home_url( '/' ) ) . '">← শাহীন শিক্ষা পরিবার</a>';
	$h    .= '<div class="hl">';
	if ( $e['logo'] ) {
		$h .= '<div class="logo">' . csa_ev_img( $e['logo'], $e['title'] ) . '</div>';
	}
	$h .= '<div class="ht">';
	if ( $e['series'] ) {
		$h .= '<div class="kick">' . esc_html( $e['series'] ) . '</div>';
	}
	$h .= '<h1>' . esc_html( $e['title'] ) . ( $e['season'] ? ' <em>' . esc_html( $e['season'] ) . '</em>' : '' ) . '</h1>';
	if ( $e['tagline'] ) {
		$h .= '<p class="tag">' . esc_html( $e['tagline'] ) . '</p>';
	}
	if ( $stage[0] ) {
		$h .= '<span class="st ' . esc_attr( $stage[1] ) . '">' . esc_html( $stage[0] ) . '</span>';
	}
	$h .= '</div></div>';
	if ( $stage[2] ) {
		$h .= '<div class="cd" data-until="' . esc_attr( $stage[2] ) . '"><span>শুরু হতে বাকি</span><b data-k="d">০</b><small>দিন</small><b data-k="h">০</b><small>ঘণ্টা</small><b data-k="m">০</b><small>মিনিট</small></div>';
	}
	if ( $btns ) {
		$h .= '<div class="btns">';
		foreach ( $btns as $b ) {
			$h .= csa_ev_btn_html( $b );
		}
		$h .= '</div>';
	}
	$h .= '</div></section>';

	/* facts strip */
	if ( $e['facts'] ) {
		$h .= '<section class="facts"><div class="in">';
		foreach ( $e['facts'] as $f ) {
			$h .= '<div class="fc rv"><span class="ic">' . esc_html( $f['ic'] ) . '</span><div><small>' . esc_html( $f['l'] ) . '</small><b>' . esc_html( $f['v'] ) . '</b></div></div>';
		}
		$h .= '</div></section>';
	}

	/* sub navigation (built from what exists) */
	$sections = array();
	if ( $e['eps'] ) {
		$sections['sev-eps'] = '🎬 পর্বসমূহ';
	}
	foreach ( (array) $e['vsec'] as $vi => $vs ) {
		$sections[ 'sev-v' . (int) $vi ] = trim( ( $vs['ic'] ? $vs['ic'] . ' ' : '' ) . $vs['t'] );
	}
	if ( $ms ) {
		$sections['sev-miles'] = '🗓️ সময়সূচি';
	}
	if ( $e['tv']['on'] ) {
		$sections['sev-tv'] = '📺 সম্প্রচার';
	}
	if ( $e['blocks'] || ( $e['prizes']['on'] && $e['prizes']['items'] ) ) {
		$sections['sev-info'] = '📋 তথ্য ও পুরস্কার';
	}
	if ( $e['gal'] ) {
		$sections['sev-gal'] = '🖼️ গ্যালারি';
	}
	$notices = ( $e['nq'] && function_exists( 'csa_sn_query' ) ) ? csa_sn_query( array( 'q' => $e['nq'] ), 6 ) : null;
	if ( $notices && $notices->have_posts() ) {
		$sections['sev-notice'] = '🔔 নোটিশ';
	}
	if ( $e['org']['name'] || $e['partners'] ) {
		$sections['sev-by'] = '🤝 আয়োজক';
	}
	if ( count( $sections ) > 1 ) {
		$h .= '<nav class="snav"><div class="in">';
		foreach ( $sections as $id => $l ) {
			$h .= '<a href="#' . esc_attr( $id ) . '">' . esc_html( $l ) . '</a>';
		}
		$h .= '</div></nav>';
	}

	/* episodes */
	if ( $e['eps'] ) {
		$h .= '<section class="sec" id="sev-eps"><div class="in">' . csa_ev_head( 'পর্ব অনুযায়ী দেখুন', 'পর্বে ক্লিক করলেই এখানেই ভিডিও চলবে' ) . '<div class="eps">';
		foreach ( $e['eps'] as $p ) {
			$yt   = csa_ev_yt( $p['u'] );
			$img  = $p['img'] ? $p['img'] : ( $yt ? 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg' : '' );
			$fb   = csa_ev_is_fb( $p['u'] );
			$em   = csa_ev_embed( $p['u'], $yt, $fb, $p['t'] );
			$tx   = ( $p['n'] ? '<small>' . esc_html( $p['n'] ) . '</small>' : '' ) . '<b>' . esc_html( $p['t'] ) . '</b>' . ( $p['d'] ? '<time>' . esc_html( csa_ev_date( $p['d'] ) ) . '</time>' : '' );
			if ( $em ) {
				$h .= '<div class="ep rv emb-c"><div class="th">' . $em . csa_ev_bigbtn( $p['u'] ) . '</div><a class="tx" href="' . esc_url( $p['u'] ) . '" target="_blank" rel="noopener">' . $tx . '</a></div>';
				continue;
			}
			$h   .= '<a class="ep rv" href="' . esc_url( $p['u'] ) . '" target="_blank" rel="noopener"' . ( $yt ? ' data-yt="' . esc_attr( $yt ) . '"' : ( $fb ? ' data-fb="' . esc_url( $p['u'] ) . '"' : '' ) ) . '><span class="th' . ( $img ? '' : ' ph' ) . '">' . ( $img ? csa_ev_img( $img, $p['t'] ) : '' ) . '<i class="play">▶</i>' . ( $fb ? '<em>Facebook</em>' : ( $yt ? '<em>YouTube</em>' : '' ) ) . '</span><span class="tx">' . ( $p['n'] ? '<small>' . esc_html( $p['n'] ) . '</small>' : '' ) . '<b>' . esc_html( $p['t'] ) . '</b>' . ( $p['d'] ? '<time>' . esc_html( csa_ev_date( $p['d'] ) ) . '</time>' : '' ) . '</span></a>';
		}
		$h .= '</div></div></section>';
	}


	/* content sections (videos grouped by topic) */
	foreach ( (array) $e['vsec'] as $vi => $vs ) {
		$cards = '';
		foreach ( preg_split( '/\r?\n/', (string) $vs['lines'] ) as $ln ) {
			$ln = trim( $ln );
			if ( '' === $ln ) {
				continue;
			}
			$pt = array_map( 'trim', explode( '|', $ln ) );
			$tt = $pt[0] ?? '';
			$uu = $pt[1] ?? '';
			$mm = $pt[2] ?? '';
			if ( '' === $tt ) {
				continue;
			}
			$yt = csa_ev_yt( $uu );
			$fb = csa_ev_is_fb( $uu );
			$em = csa_ev_embed( $uu, $yt, $fb, $tt );
			if ( $em ) {
				$cards .= '<div class="vc rv emb-c"><div class="vm">' . $em . csa_ev_bigbtn( $uu ) . '</div><a class="vx" href="' . esc_url( $uu ) . '" target="_blank" rel="noopener"><b>' . esc_html( $tt ) . '</b>' . ( $mm ? '<small>' . esc_html( $mm ) . '</small>' : '' ) . '</a></div>';
				continue;
			}
			if ( ! $yt && ! $fb && preg_match( '#^https?://#i', $uu ) && function_exists( 'csa_ev_og_get' ) ) { // news link: picture card
				$og = csa_ev_og_get( $uu );
				if ( ! $og ) {
					csa_ev_og_queue( $uu );
				}
				$ds = ( $og && $og['d'] ) ? ( mb_strlen( $og['d'] ) > 130 ? mb_substr( $og['d'], 0, 128 ) . '…' : $og['d'] ) : '';
				$cards .= '<a class="vc nw rv' . ( $og && $og['img'] ? '' : ' ni' ) . '" href="' . esc_url( $uu ) . '" target="_blank" rel="noopener">'
					. ( $og && $og['img'] ? '<span class="nwi"><img loading="lazy" decoding="async" referrerpolicy="no-referrer" src="' . esc_url( $og['img'] ) . '" alt="" onerror="this.parentNode.className+=\' x\';this.remove()"></span>' : '<span class="vp">📰</span>' )
					. '<span class="vx"><b>' . esc_html( $tt ) . '</b>' . ( $ds ? '<em class="nwd">' . esc_html( $ds ) . '</em>' : '' ) . ( $mm ? '<small>' . esc_html( $mm ) . '</small>' : '' ) . '</span></a>';
				continue;
			}
			$cards .= '<a class="vc rv" href="' . esc_url( $uu ? $uu : '#' ) . '" target="_blank" rel="noopener"' . ( $yt ? ' data-yt="' . esc_attr( $yt ) . '"' : ( $fb ? ' data-fb="' . esc_url( $uu ) . '"' : '' ) ) . '><span class="vp">▶</span><span class="vx"><b>' . esc_html( $tt ) . '</b>' . ( $mm ? '<small>' . esc_html( $mm ) . '</small>' : '' ) . '</span></a>';
		}
		if ( $cards ) {
			$h .= '<section class="sec' . ( 0 === $vi % 2 ? ' alt' : '' ) . '" id="sev-v' . (int) $vi . '"><div class="in">' . csa_ev_head( trim( ( $vs['ic'] ? $vs['ic'] . ' ' : '' ) . $vs['t'] ), $vs['p'] ) . '<div class="vcs">' . $cards . '</div></div></section>';
		}
	}

	/* timeline */
	if ( $ms ) {
		$h .= '<section class="sec" id="sev-miles"><div class="in">' . csa_ev_head( 'সময়সূচি ও ধাপসমূহ', 'কোন ধাপ শেষ, কোনটি চলছে, কোনটি আসছে' ) . '<ol class="tl">';
		foreach ( $ms as $m ) {
			$lbl = array( 'live' => 'চলছে', 'done' => 'সম্পন্ন', 'soon' => 'আসছে' )[ $m['s'] ];
			$h  .= '<li class="rv ' . esc_attr( $m['s'] ) . '"><span class="dot">' . ( $m['ic'] ? esc_html( $m['ic'] ) : '●' ) . '</span><div class="card"><div class="tt"><b>' . esc_html( $m['t'] ) . '</b><span class="pill ' . esc_attr( $m['s'] ) . '">' . esc_html( $m['lbl'] ? $m['lbl'] : $lbl ) . '</span></div>' . ( $m['dt'] ? '<time>' . esc_html( $m['dt'] ) . '</time>' : '' ) . ( $m['note'] ? '<p>' . nl2br( esc_html( $m['note'] ) ) . '</p>' : '' ) . '</div></li>';
		}
		$h .= '</ol></div></section>';
	}

	/* TV */
	if ( $e['tv']['on'] ) {
		$h .= '<section class="sec alt" id="sev-tv"><div class="in"><div class="tv rv">' . ( $e['tv']['img'] ? '<div class="tvi">' . csa_ev_img( $e['tv']['img'], $e['tv']['t'] ) . '</div>' : '<div class="tvi ph">📺</div>' ) . '<div class="tvt">' . ( $e['tv']['ch'] ? '<span class="chip">' . esc_html( $e['tv']['ch'] ) . '</span>' : '' ) . '<h2>' . esc_html( $e['tv']['t'] ) . '</h2>' . ( $e['tv']['p'] ? '<p>' . nl2br( esc_html( $e['tv']['p'] ) ) . '</p>' : '' ) . '</div></div></div></section>';
	}

	/* info blocks + prizes */
	if ( $e['blocks'] || ( $e['prizes']['on'] && $e['prizes']['items'] ) ) {
		$h .= '<section class="sec alt" id="sev-info"><div class="in">';
		if ( $e['prizes']['on'] && $e['prizes']['items'] ) {
			$h .= csa_ev_head( $e['prizes']['t'] ? $e['prizes']['t'] : 'পুরস্কারসমূহ' ) . '<div class="prz">';
			foreach ( $e['prizes']['items'] as $i => $p ) {
				$h .= '<div class="pz rv p' . min( $i, 4 ) . '"><span class="rk">' . ( 0 === $i ? '🥇' : ( 1 === $i ? '🥈' : ( 2 === $i ? '🥉' : '🏅' ) ) ) . '</span><small>' . esc_html( $p['l'] ) . '</small><b>' . esc_html( $p['v'] ) . '</b></div>';
			}
			$h .= '</div>' . ( $e['prizes']['note'] ? '<p class="pn rv">🎁 ' . esc_html( $e['prizes']['note'] ) . '</p>' : '' );
		}
		if ( $e['blocks'] ) {
			$h .= '<div class="blk">';
			foreach ( $e['blocks'] as $b ) {
				$lines = array_filter( array_map( 'trim', explode( "\n", $b['d'] ) ) );
				$h    .= '<div class="bk rv">' . ( $b['img'] ? '<div class="bi">' . csa_ev_img( $b['img'], $b['t'] ) . '</div>' : '' ) . '<h3>' . ( $b['ic'] ? esc_html( $b['ic'] ) . ' ' : '' ) . esc_html( $b['t'] ) . '</h3>';
				if ( count( $lines ) > 1 ) {
					$h .= '<ul>';
					foreach ( $lines as $l ) {
						$h .= '<li>' . esc_html( $l ) . '</li>';
					}
					$h .= '</ul>';
				} elseif ( $lines ) {
					$h .= '<p>' . esc_html( reset( $lines ) ) . '</p>';
				}
				$h .= '</div>';
			}
			$h .= '</div>';
		}
		$h .= '</div></section>';
	}

	/* gallery */
	if ( $e['gal'] ) {
		$tags = array();
		foreach ( $e['gal'] as $g ) {
			if ( $g['tag'] ) {
				$tags[ $g['tag'] ] = 1;
			}
		}
		$h .= '<section class="sec" id="sev-gal"><div class="in">' . csa_ev_head( 'ছবি, পোস্ট ও ভিডিও' ) . '<div class="flt"><button class="on" data-f="*">সব</button><button data-f="photo">🖼️ ছবি</button><button data-f="video">🎬 ভিডিও</button><button data-f="post">📝 পোস্ট</button>';
		foreach ( array_keys( $tags ) as $t ) {
			$h .= '<button data-t="' . esc_attr( $t ) . '"># ' . esc_html( $t ) . '</button>';
		}
		$h .= '</div><div class="gal">';
		foreach ( $e['gal'] as $g ) {
			$yt  = csa_ev_yt( $g['u'] );
			$img = $g['img'] ? $g['img'] : ( $yt ? 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg' : '' );
			$lnk = $g['u'] ? $g['u'] : $g['img'];
			$h  .= '<a class="gi rv" data-k="' . esc_attr( $g['k'] ) . '" data-tag="' . esc_attr( $g['tag'] ) . '" href="' . esc_url( $lnk ) . '"' . ( 'photo' === $g['k'] ? ' data-full="' . esc_url( $g['img'] ? $g['img'] : $g['u'] ) . '"' : ' target="_blank" rel="noopener"' ) . ( $yt ? ' data-yt="' . esc_attr( $yt ) . '"' : '' ) . '>' . ( $img ? csa_ev_img( $img, $g['t'] ) : '<span class="ph">' . ( 'post' === $g['k'] ? '📝' : '🎬' ) . '</span>' ) . ( 'photo' !== $g['k'] ? '<i class="play">' . ( 'video' === $g['k'] ? '▶' : '↗' ) . '</i>' : '' ) . ( $g['t'] ? '<span class="cap">' . esc_html( $g['t'] ) . '</span>' : '' ) . '</a>';
		}
		$h .= '</div></div></section>';
	}

	/* notices */
	if ( $notices && $notices->have_posts() ) {
		$h .= '<section class="sec alt" id="sev-notice"><div class="in">' . csa_ev_head( 'এই আয়োজনের নোটিশ' ) . '<div class="nts">';
		foreach ( $notices->posts as $p ) {
			$h .= csa_ev_notice_row( $p );
		}
		$h .= '</div></div></section>';
	}

	/* organiser + partners */
	if ( $e['org']['name'] || $e['partners'] ) {
		$h .= '<section class="sec" id="sev-by"><div class="in">' . csa_ev_head( 'আয়োজক ও মিডিয়া পার্টনার' ) . '<div class="by">';
		if ( $e['org']['name'] ) {
			$h .= csa_ev_party( array( 'name' => $e['org']['name'], 'role' => 'আয়োজক', 'logo' => $e['org']['logo'], 'url' => $e['org']['url'] ) );
		}
		foreach ( $e['partners'] as $p ) {
			$h .= csa_ev_party( $p );
		}
		$h .= '</div></div></section>';
	}

	/* linked events */
	$rel = '';
	foreach ( (array) $e['links'] as $lid ) {
		$o = csa_ev_get( $lid );
		if ( $o && (int) $o['id'] !== (int) $e['id'] ) {
			$rel .= csa_ev_card( csa_ev_full( $o ), false );
		}
	}
	if ( $rel ) {
		$h .= '<section class="sec alt"><div class="in">' . csa_ev_head( 'আরও আয়োজন' ) . '<div class="evs">' . $rel . '</div></div></section>';
	}

	/* closing band */
	$h .= '<section class="end"><div class="in">' . ( $e['foot'] ? '<p class="ft">' . esc_html( $e['foot'] ) . '</p>' : '' );
	if ( $btns ) {
		$h .= '<div class="btns">';
		foreach ( $btns as $b ) {
			$h .= csa_ev_btn_html( $b );
		}
		$h .= '</div>';
	}
	$h .= '</div></section></div>';
	return $h;
}

function csa_ev_party( $p ) {
	$tag = ! empty( $p['url'] ) ? 'a' : 'div';
	$at  = ! empty( $p['url'] ) ? ' href="' . esc_url( csa_ev_url( $p['url'] ) ) . '" target="_blank" rel="noopener"' : '';
	return '<' . $tag . ' class="py rv"' . $at . '>' . ( $p['logo'] ? '<span class="pl">' . csa_ev_img( $p['logo'], $p['name'] ) . '</span>' : '<span class="pl ph">' . esc_html( mb_substr( $p['name'], 0, 1 ) ) . '</span>' ) . '<small>' . esc_html( $p['role'] ?? '' ) . '</small><b>' . esc_html( $p['name'] ) . '</b></' . $tag . '>';
}

function csa_ev_notice_row( $p ) {
	$u = function_exists( 'csa_sn_url' ) ? csa_sn_url( $p ) : get_permalink( $p );
	$d = function_exists( 'csa_sn_date' ) ? csa_sn_date( get_post_time( 'U', true, $p ) ) : csa_ev_date( get_the_date( 'Y-m-d', $p ) );
	return '<a class="nt rv" href="' . esc_url( $u ) . '"><span class="nd">🔔</span><span class="nx"><b>' . esc_html( get_the_title( $p ) ) . '</b><time>' . esc_html( $d ) . '</time></span><i>→</i></a>';
}

/* ---------- Event card (home strip, linked events) ---------- */
function csa_ev_visible_home( $e ) {
	if ( empty( $e['show_home'] ) ) {
		return false;
	}
	$t = csa_ev_today();
	if ( ( $e['home_from'] && $t < $e['home_from'] ) || ( $e['home_to'] && $t > $e['home_to'] ) ) {
		return false;
	}
	return csa_ev_page_live( $e ) || csa_ev_can();
}

/** "watch big" pill on an embedded video (opens the in-page big player) */
function csa_ev_bigbtn( $u ) {
	return '<button type="button" class="bigv" data-big="' . esc_url( $u ) . '" title="বড় পর্দায় দেখুন">⛶ বড় করে দেখুন</button>';
}

function csa_ev_embed( $u, $yt, $fb, $t ) {
	if ( $yt ) {
		return '<iframe class="emb" loading="lazy" title="' . esc_attr( $t ) . '" data-src="https://www.youtube-nocookie.com/embed/' . esc_attr( $yt ) . '?rel=0" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>';
	}
	if ( $fb ) {
		return '<iframe class="emb" loading="lazy" title="' . esc_attr( $t ) . '" data-src="https://www.facebook.com/plugins/video.php?show_text=false&amp;width=560&amp;href=' . rawurlencode( $u ) . '" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" allowfullscreen></iframe>';
	}
	return '';
}

function csa_ev_card( $e, $home = true ) {
	$st  = csa_ev_stage( $e );
	$u   = csa_ev_page_url( $e );
	$bt  = $home ? csa_ev_btns( $e, true ) : array();
	$txt = $e['card'] ? $e['card'] : $e['tagline'];
	$txt = trim( preg_replace( '/\s*সব পর্ব, ফলাফল ও তথ্য জানতে এখানে ক্লিক করুন[।.]?/u', '', (string) $txt ) );
	$h   = '<article class="ec rv" style="' . esc_attr( csa_ev_vars( $e ) ) . '"><div class="eh">' . csa_ev_sparkles( 6 ) . '<div class="eb">' . ( $e['logo'] ? '<span class="el">' . csa_ev_img( $e['logo'], $e['title'] ) . '</span>' : '' ) . '<div>' . ( $e['series'] ? '<small>' . esc_html( $e['series'] ) . '</small>' : '' ) . '<h3>' . esc_html( $e['title'] ) . ( $e['season'] ? ' <em>' . esc_html( $e['season'] ) . '</em>' : '' ) . '</h3></div></div>' . ( $st[0] ? ( $u ? '<a class="st lnk ' . esc_attr( $st[1] ) . '" href="' . esc_url( $u ) . '">' . esc_html( $st[0] ) . ' <i>→</i></a>' : '<span class="st ' . esc_attr( $st[1] ) . '">' . esc_html( $st[0] ) . '</span>' ) : '' ) . '</div><div class="ex">';
	if ( $txt ) {
		$h .= '<p>' . esc_html( $txt ) . '</p>';
	}
	if ( ! empty( $e['facts'] ) && $home ) {
		$h .= '<div class="ecf">';
		foreach ( array_slice( $e['facts'], 0, 4 ) as $f ) {
			$h .= '<span><i>' . esc_html( $f['ic'] ) . '</i><small>' . esc_html( $f['l'] ) . '</small><b>' . esc_html( $f['v'] ) . '</b></span>';
		}
		$h .= '</div>';
	}
	if ( $home && ! empty( $e['prizes']['on'] ) && ! empty( $e['prizes']['items'] ) ) {
		$h .= '<div class="ecp"><strong>🏆 পুরস্কার</strong>';
		foreach ( array_slice( $e['prizes']['items'], 0, 3 ) as $i => $p ) {
			$h .= '<span>' . ( 0 === $i ? '🥇' : ( 1 === $i ? '🥈' : '🥉' ) ) . ' ' . esc_html( $p['l'] ) . ' <b>' . esc_html( $p['v'] ) . '</b></span>';
		}
		$h .= '</div>';
	}
	if ( $home ) {
		$lg = array();
		if ( ! empty( $e['org']['logo'] ) ) {
			$lg[] = array( 'l' => 'আয়োজক', 'i' => $e['org']['logo'], 'n' => $e['org']['name'] ?? '' );
		}
		foreach ( (array) $e['partners'] as $pt ) {
			if ( ! empty( $pt['logo'] ) ) {
				$lg[] = array( 'l' => ! empty( $pt['role'] ) ? $pt['role'] : 'সহযোগী', 'i' => $pt['logo'], 'n' => $pt['name'] ?? '' );
			}
		}
		if ( $lg ) {
			$h .= '<div class="ecl"><small class="lt">আয়োজক ও সহযোগী</small><div class="lg">';
			foreach ( $lg as $g ) {
				$h .= '<span title="' . esc_attr( $g['n'] ) . '">' . csa_ev_img( $g['i'], $g['n'] ) . '<small>' . esc_html( $g['l'] ) . '</small></span>';
			}
			$h .= '</div>';
			$h .= '</div>';
		} else {
			$by = array();
			if ( ! empty( $e['org']['name'] ) ) {
				$by[] = 'আয়োজক: ' . $e['org']['name'];
			}
			foreach ( (array) $e['partners'] as $pt ) {
				if ( ! empty( $pt['name'] ) ) {
					$by[] = ( ! empty( $pt['role'] ) ? $pt['role'] : 'সহযোগী' ) . ': ' . $pt['name'];
				}
			}
			if ( $by ) {
				$h .= '<p class="ecy">' . esc_html( implode( ' • ', $by ) ) . '</p>';
			}
		}
	}
	$more = ( $u && ! empty( $e['card_more'] ) ) ? '<a class="more" href="' . esc_url( $u ) . '">তথ্য জানতে এখানে ক্লিক করুন <i>→</i></a>' : '';
	$prim = false;
	foreach ( array_slice( $bt, 0, 3 ) as $b ) {
		if ( ! empty( $b['k'] ) && 'p' === $b['k'] ) {
			$prim = true;
		}
	}
	$btn = '';
	foreach ( array_slice( $bt, 0, 3 ) as $b ) {
		$btn .= csa_ev_btn_html( $b );
	}
	$btn = $btn ? '<div class="btns">' . $btn . '</div>' : '';
	$h  .= '<div class="ecb">' . ( $prim ? $btn . $more : $more . $btn );
	return $h . '</div></div></article>';
}

function csa_ev_events_strip( $max, $ctx ) {
	$list = array();
	foreach ( csa_ev_list() as $e ) {
		$e = csa_ev_full( $e );
		if ( csa_ev_visible_home( $e ) ) {
			$list[] = $e;
		}
	}
	usort( $list, function ( $a, $b ) {
		return (int) $a['order'] <=> (int) $b['order'];
	} );
	$list = array_slice( $list, 0, max( 1, (int) $max ) );
	if ( ! $list ) {
		return '';
	}
	$h = '<div class="evs n' . min( count( $list ), 3 ) . '">';
	foreach ( $list as $e ) {
		$h .= csa_ev_card( $e );
	}
	return $h . '</div>';
}

/* ---------- Home page ---------- */
function csa_ev_link_attr( $u ) {
	$ext = preg_match( '#^https?://#', $u ) && 0 !== strpos( $u, home_url() );
	return ' href="' . esc_url( csa_ev_url( $u ) ) . '"' . ( $ext ? ' target="_blank" rel="noopener"' : '' );
}

function csa_ev_home_html() {
	$o   = csa_ev_opts();
	$H   = $o['home'];
	array_walk_recursive( $H, function ( &$v, $k ) {
		if ( is_string( $v ) && 'u' !== $k && 'order' !== $k ) {
			$v = csa_ev_tok( $v );
		}
	} );
	$out = '';
	foreach ( $H['order'] as $k ) {
		if ( in_array( $k, (array) $H['off'], true ) ) {
			continue;
		}
		$fn = 'csa_ev_hs_' . $k;
		if ( function_exists( $fn ) ) {
			$out .= call_user_func( $fn, $H[ $k ], $H );
		}
	}
	return csa_ev_assets_tag() . '<div class="shm">' . $out . '</div>';
}

function csa_ev_hs_hero( $x ) {
	$bg = $x['img'] ? ' style="background-image:linear-gradient(160deg,#0d2e1ce6,#0f3524d9 45%,#091a2ee6),url(' . esc_url( $x['img'] ) . ')"' : '';
	$h  = '<section class="hh"' . $bg . '>' . csa_ev_sparkles( 16 ) . '<div class="in">' . ( $x['kicker'] ? '<span class="kick rv">' . esc_html( $x['kicker'] ) . '</span>' : '' ) . '<h1 class="rv">' . esc_html( $x['h1a'] ) . ( $x['h1b'] ? '<br><em>' . esc_html( $x['h1b'] ) . '</em>' : '' ) . '</h1>' . ( $x['sub'] ? '<p class="rv">' . esc_html( csa_ev_tok( $x['sub'] ) ) . '</p>' : '' ) . '<div class="btns rv">';
	foreach ( array( 'b1' => 'p', 'b2' => 's' ) as $k => $c ) {
		if ( ! empty( $x[ $k ]['t'] ) && ! empty( $x[ $k ]['u'] ) ) {
			$h .= '<a class="b ' . $c . '"' . csa_ev_link_attr( $x[ $k ]['u'] ) . '>' . esc_html( $x[ $k ]['t'] ) . '</a>';
		}
	}
	$h .= '</div>';
	if ( $x['stats'] ) {
		$h .= '<div class="stats">';
		foreach ( $x['stats'] as $s ) {
			$h .= '<div class="stt rv"><b>' . esc_html( csa_ev_tok( $s['n'] ) ) . '</b><small>' . esc_html( $s['l'] ) . '</small></div>';
		}
		$h .= '</div>';
	}
	return $h . '</div></section>';
}

function csa_ev_hs_events( $x ) {
	$s = csa_ev_events_strip( $x['max'], array() );
	if ( '' === $s ) {
		return '';
	}
	return '<section class="sec evsec" id="events"><div class="in">' . csa_ev_head( $x['t'], $x['p'], true ) . $s . '</div></section>';
}

function csa_ev_hs_notice( $x ) {
	if ( ! function_exists( 'csa_sn_query' ) ) {
		return '';
	}
	$q = csa_sn_query( array(), (int) $x['n'] );
	if ( ! $q->have_posts() ) {
		return '';
	}
	$bu = $x['bu'] ? $x['bu'] : ( function_exists( 'csa_sn_listpage_id' ) && csa_sn_listpage_id() ? get_permalink( csa_sn_listpage_id() ) : '' );
	$h  = '<section class="sec alt" id="notice"><div class="in">' . csa_ev_head( $x['t'], $x['p'] ) . '<div class="nts">';
	foreach ( $q->posts as $p ) {
		$h .= csa_ev_notice_row( $p );
	}
	$h .= '</div>' . ( $bu && $x['bt'] ? '<p class="mr rv"><a class="b p"' . csa_ev_link_attr( $bu ) . '>' . esc_html( $x['bt'] ) . '</a></p>' : '' ) . '</div></section>';
	return $h;
}

function csa_ev_cards_grid( $cards, $cls = 'cg' ) {
	$h = '<div class="' . $cls . '">';
	foreach ( $cards as $c ) {
		$tag = ! empty( $c['u'] ) ? 'a' : 'div';
		$at  = ! empty( $c['u'] ) ? csa_ev_link_attr( $c['u'] ) : '';
		$h  .= '<' . $tag . ' class="cc rv"' . $at . '>' . ( ! empty( $c['tag'] ) ? '<span class="tg">' . esc_html( $c['tag'] ) . '</span>' : '' ) . csa_ev_icon( $c['img'] ?? '', $c['ic'] ?? '', $c['t'] ) . '<b>' . esc_html( $c['t'] ) . '</b>' . ( ! empty( $c['d'] ) ? '<span>' . esc_html( $c['d'] ) . '</span>' : '' ) . ( ! empty( $c['u'] ) ? '<i class="ar">→</i>' : '' ) . '</' . $tag . '>';
	}
	return $h . '</div>';
}

function csa_ev_hs_act( $x ) {
	if ( ! $x['cards'] ) {
		return '';
	}
	$h = '<section class="sec act" id="programs">' . csa_ev_sparkles( 12 ) . '<div class="in">' . csa_ev_head( $x['t'], $x['p'], true ) . '<div class="ag">';
	foreach ( $x['cards'] as $c ) {
		$tag = ! empty( $c['u'] ) ? 'a' : 'div';
		$at  = ! empty( $c['u'] ) ? csa_ev_link_attr( $c['u'] ) : '';
		$h  .= '<' . $tag . ' class="ga rv"' . $at . '>' . ( ! empty( $c['tag'] ) ? '<span class="tg">' . esc_html( $c['tag'] ) . '</span>' : '' ) . csa_ev_icon( $c['img'] ?? '', $c['ic'] ?? '', $c['t'] ) . '<b>' . esc_html( $c['t'] ) . '</b>' . ( ! empty( $c['d'] ) ? '<small>' . esc_html( $c['d'] ) . '</small>' : '' ) . '</' . $tag . '>';
	}
	$h .= '</div>';
	if ( ! empty( $x['bars'] ) ) {
		$h .= '<div class="bars">';
		foreach ( $x['bars'] as $q ) {
			$h .= '<a class="bar rv ' . esc_attr( $q['c'] ) . '"' . ( $q['u'] ? csa_ev_link_attr( $q['u'] ) : '' ) . '><span class="bi">' . esc_html( $q['ic'] ) . '</span><b>' . esc_html( $q['t'] ) . '</b><i>→</i></a>';
		}
		$h .= '</div>';
	}
	return $h . '</div></section>';
}

/** Gold laurel badge (years / national firsts) — same look as the old map graphic. */
function csa_ev_badge( $top, $big, $bot, $sub = '' ) {
	return '<div class="bdg rv"><div class="bdi"><small>' . esc_html( $top ) . '</small><b>' . esc_html( $big ) . '</b><em>' . esc_html( $bot ) . '</em></div>' . ( $sub ? '<span>' . esc_html( $sub ) . '</span>' : '' ) . '</div>';
}

function csa_ev_hs_map( $x ) {
	if ( empty( $x['map'] ) ) {
		return '';
	}
	$img = preg_match( '#^https?://#', $x['map'] ) ? $x['map'] : home_url( $x['map'] );
	$h   = '<section class="sec mps" id="map"><div class="in"><div class="mp">';
	$h  .= '<div class="mpl">';
	if ( $x['y1'] || $x['yn'] ) {
		$h .= csa_ev_badge( trim( $x['y1'] . ' – ' . $x['y2'], ' –' ), $x['yn'], $x['yl'] );
	}
	if ( $x['n4'] ) {
		$h .= csa_ev_badge( $x['n4l'], $x['n4'], '' );
	}
	if ( $x['n4'] && $x['n4s'] ) {
		$h .= '<p class="bdn rv">' . esc_html( $x['n4s'] ) . '</p>';
	}
	$h .= '</div><div class="mpr rv"><img src="' . esc_url( $img ) . '" alt="' . esc_attr( csa_ev_tok( $x['p'] ? $x['p'] : $x['t'] ) ) . '" loading="lazy"></div></div></div></section>';
	return $h;
}

function csa_ev_hs_branch( $x ) {
	$n = function_exists( 'csa_br_list' ) ? count( csa_br_list() ) : 0;
	$dv = array();
	if ( function_exists( 'csa_br_opt' ) ) {
		$dv = array_slice( (array) csa_br_opt( 'div_order' ), 0, 8 );
	}
	$h = csa_ev_hs_map( $x ) . '<section class="sec brs"><div class="in"><div class="bx rv"><div class="bt"><h2>' . esc_html( $x['t'] ) . '</h2>' . ( $x['p'] ? '<p>' . esc_html( csa_ev_tok( $x['p'] ) ) . '</p>' : '' ) . ( $n ? '<span class="chip">' . esc_html( csa_ev_bn( $n ) ) . 'টি শাখার তালিকা</span>' : '' ) . '</div>';
	if ( $dv ) {
		$h .= '<div class="dvs">';
		foreach ( $dv as $d ) {
			if ( 'অন্যান্য' !== $d ) {
				$h .= '<a' . csa_ev_link_attr( $x['bu'] ) . '>' . esc_html( $d ) . '</a>';
			}
		}
		$h .= '</div>';
	}
	return $h . ( $x['bu'] ? '<a class="b p"' . csa_ev_link_attr( $x['bu'] ) . '>' . esc_html( $x['bt'] ) . '</a>' : '' ) . '</div></div></section>';
}

function csa_ev_hs_ach( $x ) {
	$h = '<section class="sec alt ach" id="success"><div class="in">' . csa_ev_head( $x['t'], $x['p'] );
	if ( $x['nums'] ) {
		$h .= '<div class="nums">';
		foreach ( $x['nums'] as $n ) {
			$h .= '<div class="nm rv"><b>' . esc_html( $n['n'] ) . '</b><small>' . esc_html( $n['l'] ) . '</small></div>';
		}
		$h .= '</div>';
	}
	if ( $x['items'] ) {
		$logo = home_url( '/wp-content/uploads/2026/06/SEF-Logo.png' );
		$h   .= '<div class="rsl rv" data-rs><div class="rst">';
		foreach ( $x['items'] as $i ) {
			$big = ! empty( $i['big'] ) ? $i['big'] : '';
			$bg  = ! empty( $i['img'] ) ? ' has-img" style="--rb:url(' . esc_url( $i['img'] ) . ')' : '';
			$hl  = function ( $t ) {
				return preg_replace( '/([০-৯][০-৯,.]*)/u', '<mark>$1</mark>', esc_html( $t ) );
			};
			$h .= '<article class="rsc' . $bg . '"><i class="rd1"></i><i class="rd2"></i><div class="rbr"><img src="' . esc_url( $logo ) . '" alt="" loading="lazy"><b>শাহীন শিক্ষা পরিবার</b></div><span class="rtg">' . esc_html( $i['t'] ) . '</span>';
			if ( $big ) {
				$h .= '<p class="rbg">' . $hl( $big ) . '</p>';
			}
			if ( ! empty( $i['d'] ) ) {
				$h .= '<p class="rpp">' . $hl( $i['d'] ) . '</p>';
			}
			$h .= '</article>';
		}
		$h .= '</div><div class="rnv"><button type="button" class="rpv" aria-label="আগেরটি">←</button><span class="rdt"></span><button type="button" class="rnx" aria-label="পরেরটি">→</button></div></div>';
	}
	return $h . ( $x['bu'] && $x['bt'] ? '<p class="mr rv"><a class="b p"' . csa_ev_link_attr( $x['bu'] ) . '>' . esc_html( $x['bt'] ) . '</a></p>' : '' ) . '</div></section>';
}

function csa_ev_hs_cadet( $x ) {
	if ( ! $x['cards'] ) {
		return '';
	}
	$h = '<section class="sec cad" id="cadet"><div class="in">' . csa_ev_head( $x['t'], $x['p'], true );
	if ( ! empty( $x['nums'] ) ) {
		$h .= '<div class="nums cn">';
		foreach ( $x['nums'] as $n ) {
			$h .= '<div class="nm rv"><b>' . esc_html( $n['n'] ) . '</b><small>' . esc_html( $n['l'] ) . '</small></div>';
		}
		$h .= '</div>';
	}
	$h .= '<div class="cd2">';
	foreach ( $x['cards'] as $c ) {
		$h .= '<div class="cb rv">' . csa_ev_icon( $c['img'], $c['ic'], $c['t'] ) . '<h3>' . esc_html( $c['t'] ) . '</h3><p>' . esc_html( $c['d'] ) . '</p>' . ( $c['u'] ? '<a class="b p"' . csa_ev_link_attr( $c['u'] ) . '>' . esc_html( $c['bt'] ? $c['bt'] : 'বিস্তারিত →' ) . '</a>' : '' ) . '</div>';
	}
	$h .= '</div>';
	if ( ! empty( $x['bu'] ) && ! empty( $x['bt'] ) ) {
		$h .= '<p class="mr rv"><a class="b p"' . csa_ev_link_attr( $x['bu'] ) . '>' . esc_html( $x['bt'] ) . '</a></p>';
	}
	return $h . '</div></section>';
}

function csa_ev_page_img( $u ) {
	if ( ! $u ) {
		return '';
	}
	$k = 'csa_evpi_' . md5( $u );
	$c = get_transient( $k );
	if ( false !== $c ) {
		return $c;
	}
	$img = '';
	$id  = url_to_postid( $u );
	if ( $id ) {
		$img = (string) get_the_post_thumbnail_url( $id, 'medium_large' );
		if ( ! $img ) {
			$p = get_post( $id );
			if ( $p && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $p->post_content, $m ) ) {
				$img = $m[1];
			} elseif ( $p ) {
				$q = get_post_meta( $id, '_elementor_data', true );
				if ( is_string( $q ) && preg_match( '#"url":"(https?:[^"]+?\.(?:jpe?g|png|webp))"#i', $q, $m ) ) {
					$img = stripslashes( $m[1] );
				}
			}
		}
	}
	set_transient( $k, $img, 6 * HOUR_IN_SECONDS );
	return $img;
}

function csa_ev_hs_why( $x ) {
	if ( empty( $x['cards'] ) ) {
		return '';
	}
	$h = '<section class="sec alt wys"><div class="in">' . csa_ev_head( $x['t'], $x['p'] ) . '<div class="wyg">';
	foreach ( $x['cards'] as $c ) {
		$tag = ! empty( $c['u'] ) ? 'a' : 'div';
		$at  = ! empty( $c['u'] ) ? csa_ev_link_attr( $c['u'] ) : '';
		$img = ! empty( $c['img'] ) ? $c['img'] : csa_ev_page_img( $c['u'] ?? '' );
		$h  .= '<' . $tag . ' class="wc rv"' . $at . '><span class="wi' . ( $img ? '' : ' ph' ) . '">' . ( $img ? csa_ev_img( $img, $c['t'] ) : esc_html( $c['ic'] ?? '✨' ) ) . '</span><span class="wt"><b>' . esc_html( $c['t'] ) . '</b>' . ( ! empty( $c['u'] ) ? '<i>→</i>' : '' ) . '</span></' . $tag . '>';
	}
	return $h . '</div></div></section>';
}

function csa_ev_hs_ideal( $x ) {
	// A card with 'pid' takes its title, summary and link from that blog post, so editing the post in the blog updates the home page.
	foreach ( (array) $x['cards'] as $i => $c ) {
		$pid = (int) ( $c['pid'] ?? 0 );
		if ( $pid && get_post_type( $pid ) === 'sb_post' && get_post_status( $pid ) === 'publish' ) {
			$x['cards'][ $i ]['u'] = get_permalink( $pid );
			$x['cards'][ $i ]['t'] = get_the_title( $pid );
			$ex = trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $pid ) ) );
			if ( $ex !== '' ) {
				$x['cards'][ $i ]['d'] = mb_strlen( $ex ) > 90 ? mb_substr( $ex, 0, 88 ) . '…' : $ex;
			}
		}
	}
	return $x['cards'] ? '<section class="sec"><div class="in">' . csa_ev_head( $x['t'], $x['p'] ) . csa_ev_cards_grid( $x['cards'], 'cg c3' ) . '</div></section>' : '';
}

function csa_ev_hs_blog( $x ) {
	$q = new WP_Query( array( 'post_type' => post_type_exists( 'sb_post' ) ? 'sb_post' : 'post', 'post_status' => 'publish', 'posts_per_page' => (int) $x['n'], 'ignore_sticky_posts' => true ) );
	if ( ! $q->have_posts() && post_type_exists( 'sb_post' ) ) {
		$q = new WP_Query( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => (int) $x['n'], 'ignore_sticky_posts' => true ) );
	}
	if ( ! $q->have_posts() ) {
		return '';
	}
	$h = '<section class="sec alt" id="blog"><div class="in">' . csa_ev_head( $x['t'], $x['p'] ) . '<div class="bl">';
	foreach ( $q->posts as $p ) {
		$img = get_the_post_thumbnail_url( $p, 'medium_large' );
		$h  .= '<a class="bp rv" href="' . esc_url( get_permalink( $p ) ) . '"><span class="th' . ( $img ? '' : ' ph' ) . '">' . ( $img ? csa_ev_img( $img, get_the_title( $p ) ) : '📰' ) . '</span><span class="tx"><b>' . esc_html( get_the_title( $p ) ) . '</b><time>' . esc_html( csa_ev_date( get_the_date( 'Y-m-d', $p ) ) ) . '</time></span></a>';
	}
	$bu = $x['bu'] ? $x['bu'] : '/blog/'; // empty = the blog page, so the button is always there
	$bt = $x['bt'] ? $x['bt'] : 'সব লেখা পড়ুন →';
	$h .= '</div><p class="mr rv"><a class="b p"' . csa_ev_link_attr( $bu ) . '>' . esc_html( $bt ) . '</a></p></div></section>';
	return $h;
}

function csa_ev_hs_cta( $x ) {
	$h = '<section class="end"><div class="in"><h2 class="rv">' . esc_html( $x['t'] ) . '</h2>' . ( $x['p'] ? '<p class="rv">' . esc_html( $x['p'] ) . '</p>' : '' ) . '<div class="btns rv">';
	foreach ( array( 'b1' => 'p', 'b2' => 's' ) as $k => $c ) {
		if ( ! empty( $x[ $k ]['t'] ) && ! empty( $x[ $k ]['u'] ) ) {
			$h .= '<a class="b ' . $c . '"' . csa_ev_link_attr( $x[ $k ]['u'] ) . '>' . esc_html( $x[ $k ]['t'] ) . '</a>';
		}
	}
	$h .= '</div>';
	if ( $x['phone'] || $x['mail'] ) {
		$h .= '<p class="ct rv">' . ( $x['phone'] ? '📞 ' . esc_html( $x['phone'] ) . ' (হেল্পলাইন)' : '' ) . ( $x['phone'] && $x['mail'] ? ' &nbsp;·&nbsp; ' : '' ) . ( $x['mail'] ? '✉️ ' . esc_html( $x['mail'] ) : '' ) . '</p>';
	}
	return $h . '</div></section>';
}
