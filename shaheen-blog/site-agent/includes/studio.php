<?php
/**
 * Page Studio: build or rebuild pages from ready templates and sections.
 * Pages are plain HTML made of sections. Text comes from the AI (small JSON call), facts (phone, address, branches)
 * are filled in when the page is drawn, so changing them once updates every Studio page.
 */
defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ */
/* Design tokens                                                        */
/* ------------------------------------------------------------------ */

function csa_ps_token_defaults() {
	return array(
		'primary'  => '#2e7d32', // Shaheen green
		'dark'     => '#0f3d1e',
		'accent'   => '#d9961a', // kathali (jackfruit gold)
		'soft'     => '#e8f3ea',
		'bg'       => '#ffffff',
		'text'     => '#1d2b22',
		'radius'   => 14,
		'gradient' => 1,
		'fs'       => 17,
		'fs_m'     => 16,
		'width'    => 1100,
		'gap'      => 40,
		'lh'       => 1.75,
		'phone'    => '+880 1622-222111',
		'email'    => 'corporate@shaheen.edu.bd',
		'address'  => 'রেজিস্ট্রিপাড়া, টাঙ্গাইল সদর',
	);
}

function csa_ps_presets() {
	return array(
		'shaheen' => array( 'label' => 'শাহীন সবুজ + কাঠালি', 'primary' => '#2e7d32', 'dark' => '#0f3d1e', 'accent' => '#d9961a', 'soft' => '#e8f3ea', 'gradient' => 1 ),
		'deep'    => array( 'label' => 'গাঢ় সবুজ + সোনালি', 'primary' => '#14532d', 'dark' => '#052e16', 'accent' => '#eab308', 'soft' => '#ecfdf3', 'gradient' => 1 ),
		'soft'    => array( 'label' => 'হালকা সবুজ + ক্রিম', 'primary' => '#3f8f4f', 'dark' => '#1f4d2b', 'accent' => '#c98a2b', 'soft' => '#f3f8ee', 'gradient' => 0 ),
		'cadet'   => array( 'label' => 'ক্যাডেট: নেভি + সবুজ + সোনালি', 'primary' => '#1e3a5f', 'dark' => '#0b1f38', 'accent' => '#d9961a', 'soft' => '#e8eef6', 'gradient' => 1 ),
	);
}

function csa_ps_tokens( $override = array() ) {
	$g = get_option( 'csa_ps_tokens', array() );
	return array_merge( csa_ps_token_defaults(), is_array( $g ) ? $g : array(), is_array( $override ) ? $override : array() );
}

function csa_ps_color( $c, $fallback ) {
	return preg_match( '/^#[0-9a-f]{6}$/i', (string) $c ) ? $c : $fallback;
}

function csa_ps_css( $t ) {
	$d = csa_ps_token_defaults();
	$p = csa_ps_color( $t['primary'], $d['primary'] );
	$k = csa_ps_color( $t['dark'], $d['dark'] );
	$a = csa_ps_color( $t['accent'], $d['accent'] );
	$s = csa_ps_color( $t['soft'], $d['soft'] );
	$b = csa_ps_color( $t['bg'], $d['bg'] );
	$x = csa_ps_color( $t['text'], $d['text'] );
	$r  = max( 0, min( 40, (int) $t['radius'] ) );
	$fs = max( 13, min( 24, (int) $t['fs'] ) );
	$fm = max( 12, min( 22, (int) $t['fs_m'] ) );
	$w  = max( 600, min( 1600, (int) $t['width'] ) );
	$gp = max( 12, min( 100, (int) $t['gap'] ) );
	$gm = min( $gp, 28 );
	$lh = max( 1.2, min( 2.4, (float) $t['lh'] ) );
	$grad = ! empty( $t['gradient'] ) ? "linear-gradient(135deg,{$k} 0%,{$p} 60%,{$a} 140%)" : $p;
	return "<style>.ps-page{--p:{$p};--k:{$k};--a:{$a};--s:{$s};--bg:{$b};--tx:{$x};--r:{$r}px;--g:{$grad};max-width:{$w}px;margin:0 auto;padding:0 16px;color:var(--tx);line-height:{$lh};font-size:{$fs}px;box-sizing:border-box}"
		. ".ps-page *{box-sizing:border-box}.ps-sec{margin:0 0 {$gp}px}.ps-sec h2{color:var(--k);font-size:clamp(24px,3.2vw,34px);line-height:1.3;margin:0 0 14px}.ps-sec h3{color:var(--k);margin:0 0 6px;font-size:20px}"
		. ".ps-hero{background:var(--g);color:#fff;border-radius:var(--r);padding:clamp(28px,6vw,64px) clamp(18px,4vw,48px);text-align:center}.ps-hero h2{color:#fff;font-size:clamp(26px,4.4vw,44px);margin:0 0 10px}.ps-hero p{margin:0 auto 18px;max-width:720px;opacity:.95}"
		. ".ps-btn{display:inline-block;background:var(--a);color:#fff!important;padding:12px 26px;border-radius:999px;font-weight:700;text-decoration:none}.ps-btn:hover{filter:brightness(1.08)}.ps-hero .ps-btn{background:#fff;color:var(--k)!important}"
		. ".ps-grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(230px,1fr))}.ps-card{background:var(--s);border:1px solid rgba(0,0,0,.06);border-radius:var(--r);padding:20px}.ps-card p{margin:0}.ps-card .n{display:inline-flex;width:34px;height:34px;border-radius:50%;background:var(--p);color:#fff;align-items:center;justify-content:center;font-weight:700;margin-bottom:8px}"
		. ".ps-table{width:100%;border-collapse:collapse;border-radius:var(--r);overflow:hidden}.ps-table th,.ps-table td{padding:12px 14px;text-align:left;border-bottom:1px solid rgba(0,0,0,.08);vertical-align:top}.ps-table th{background:var(--s);color:var(--k);width:34%}"
		. ".ps-list{list-style:none;margin:0;padding:0}.ps-list li{padding:12px 0;border-bottom:1px dashed rgba(0,0,0,.15)}.ps-list b{color:var(--p)}"
		. ".ps-faq details{background:var(--s);border-radius:var(--r);padding:12px 16px;margin-bottom:10px}.ps-faq summary{cursor:pointer;font-weight:700;color:var(--k)}.ps-faq p{margin:8px 0 0}"
		. ".ps-gal{display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(200px,1fr))}.ps-gal img{width:100%;height:170px;object-fit:cover;border-radius:var(--r);display:block}"
		. ".ps-cta{background:var(--g);color:#fff;border-radius:var(--r);padding:clamp(24px,4vw,44px);text-align:center}.ps-cta h2{color:#fff}.ps-cta .ps-btn{background:#fff;color:var(--k)!important}"
		. ".ps-contact{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}.ps-contact div{background:var(--s);border-radius:var(--r);padding:16px}.ps-contact b{display:block;color:var(--p);margin-bottom:2px}"
		. "" . '.ps-embed{position:relative;border-radius:var(--r);overflow:hidden;background:#000}.ps-embed.yt{aspect-ratio:16/9}.ps-embed.yt iframe{width:100%;height:100%;position:absolute;inset:0}.ps-embed.reel{max-width:380px;margin:0 auto}.ps-embed.fbbox{max-width:520px;margin:0 auto;background:var(--s)}.ps-embed.map{background:var(--s)}.ps-vid{width:100%;border-radius:var(--r);display:block;background:#000}.ps-cap{text-align:center;color:#556;font-size:.9em;margin:8px 0 0}.ps-btns{display:flex;flex-wrap:wrap;gap:10px}.ps-form{display:grid;gap:12px;max-width:640px}.ps-form label{display:grid;gap:4px;font-weight:600;color:var(--k)}.ps-form input,.ps-form textarea,.ps-form select{width:100%;padding:11px 12px;border:1px solid rgba(0,0,0,.2);border-radius:calc(var(--r) / 1.5);font:inherit;background:#fff}.ps-form .ps-btn{border:0;cursor:pointer;justify-self:start;font:inherit;font-weight:700}.ps-hp{position:absolute;left:-9999px;height:0;overflow:hidden}.ps-raw iframe,.ps-raw video,.ps-raw img{max-width:100%}' . "@media(max-width:600px){.ps-page{font-size:{$fm}px;padding:0 14px}.ps-sec{margin-bottom:{$gm}px}.ps-table th{width:40%}}</style>";
}

/* ------------------------------------------------------------------ */
/* Sections and templates                                               */
/* ------------------------------------------------------------------ */

function csa_ps_sections() {
	return array(
		'hero'     => array( 'label' => 'হিরো (বড় শিরোনাম)', 'ai' => '{"title":"short strong heading with the keyword","subtitle":"1-2 sentences","cta_text":"2-4 words","cta_url":"#contact or a real path like /admission/"}' ),
		'intro'    => array( 'label' => 'ভূমিকা', 'ai' => '{"heading":"...","paragraphs":["2-3 short paragraphs, 40-70 words each"]}' ),
		'features' => array( 'label' => 'বৈশিষ্ট্য কার্ড', 'ai' => '{"heading":"...","items":[{"title":"2-4 words","text":"1-2 sentences"}]} (3 to 6 items)' ),
		'steps'    => array( 'label' => 'ধাপে ধাপে (প্রক্রিয়া)', 'ai' => '{"heading":"...","items":[{"title":"...","text":"1 sentence"}]} (3 to 6 steps)' ),
		'details'  => array( 'label' => 'তথ্য টেবিল (তারিখ, যোগ্যতা…)', 'ai' => '{"heading":"...","rows":[{"label":"...","value":"..."}]} (4 to 8 rows; only facts from the source or site memory, otherwise leave the row out)' ),
		'notice'   => array( 'label' => 'নোটিশ তালিকা', 'ai' => '{"heading":"...","items":[{"date":"","text":"..."}]} (only real facts; if none are known write one general line)' ),
		'results'  => array( 'label' => 'ফলাফল দেখার বাটন', 'ai' => '{"heading":"...","text":"1-2 sentences","button_text":"...","button_url":"link from the source or #"}' ),
		'branches' => array( 'label' => 'শাখা তালিকা (সাইট থেকে স্বয়ংক্রিয়)', 'ai' => '' ),
		'gallery'  => array( 'label' => 'ছবির গ্যালারি (মিডিয়া লাইব্রেরি)', 'ai' => '{"heading":"..."}' ),
		'faq'      => array( 'label' => 'সচরাচর প্রশ্ন (FAQ)', 'ai' => '{"heading":"...","items":[{"q":"...","a":"..."}]} (4 to 6; answers only from facts you know)' ),
		'cta'      => array( 'label' => 'ডাকা-বাটন (CTA)', 'ai' => '{"heading":"...","text":"1 sentence","button_text":"...","button_url":"..."}' ),
		'youtube'  => array( 'label' => 'ইউটিউব ভিডিও', 'ai' => '{"heading":"...","url":"YouTube link ONLY if the owner gave one, else empty","caption":"1 short line"}' ),
		'video'    => array( 'label' => 'নিজস্ব ভিডিও (mp4 ফাইল)', 'ai' => '{"heading":"...","url":"direct .mp4 link ONLY if the owner gave one, else empty","caption":""}' ),
		'fb_video' => array( 'label' => 'ফেসবুক ভিডিও / রিল', 'ai' => '{"heading":"...","url":"Facebook video or reel link ONLY if the owner gave one, else empty"}' ),
		'fb_post'  => array( 'label' => 'ফেসবুক পোস্ট', 'ai' => '{"heading":"...","url":"Facebook post link ONLY if the owner gave one, else empty"}' ),
		'fb_page'  => array( 'label' => 'ফেসবুক পেজ বক্স (লাইক/ফলো)', 'ai' => '{"heading":"...","url":"Facebook page link ONLY if the owner gave one, else empty"}' ),
		'social'   => array( 'label' => 'সোশ্যাল বাটন (লাইক, সাবস্ক্রাইব, ফলো)', 'ai' => '{"heading":"...","items":[{"label":"Facebook or YouTube or Instagram or TikTok","text":"button words, e.g. Like our page / Subscribe","url":"link ONLY if the owner gave one, else empty"}]}' ),
		'form'     => array( 'label' => 'ফর্ম (ইমেইলে আসবে)', 'ai' => '{"heading":"...","text":"1 sentence","button_text":"...","fields":[{"label":"...","type":"text|tel|email|textarea|select","options":"comma separated, only for select"}]} (3 to 6 fields; always a name and a phone)' ),
		'map'      => array( 'label' => 'গুগল ম্যাপ', 'ai' => '{"heading":"...","url":"Google Maps embed link (from Share > Embed a map) ONLY if the owner gave one, else empty"}' ),
		'html'     => array( 'label' => 'নিজের কোড/এমবেড (HTML)', 'ai' => '{"html":"the exact embed or HTML code the owner pasted; empty if none"}' ),
		'contact'  => array( 'label' => 'যোগাযোগ (ফোন/ঠিকানা স্বয়ংক্রিয়)', 'ai' => '{"heading":"...","text":"1 sentence"}' ),
	);
}

function csa_ps_templates() {
	return array(
		'admission'   => array( 'label' => 'ভর্তি', 'icon' => '🎓', 'type' => 'page', 'pal' => 'shaheen', 'sections' => array( 'hero', 'intro', 'features', 'steps', 'faq', 'contact', 'cta' ) ),
		'branch'      => array( 'label' => 'শাখা', 'icon' => '🏫', 'type' => 'page', 'pal' => 'shaheen', 'sections' => array( 'hero', 'intro', 'features', 'gallery', 'contact', 'cta' ) ),
		'result'      => array( 'label' => 'ফলাফল', 'icon' => '📊', 'type' => 'page', 'pal' => 'deep', 'sections' => array( 'hero', 'intro', 'results', 'notice', 'contact' ) ),
		'recruitment' => array( 'label' => 'নিয়োগ', 'icon' => '👩‍🏫', 'type' => 'page', 'pal' => 'shaheen', 'sections' => array( 'hero', 'intro', 'details', 'steps', 'notice', 'contact' ) ),
		'cadet'       => array( 'label' => 'ক্যাডেট কোচিং', 'icon' => '🎖', 'type' => 'page', 'pal' => 'cadet', 'sections' => array( 'hero', 'intro', 'features', 'steps', 'faq', 'contact', 'cta' ) ),
		'event'       => array( 'label' => 'ইভেন্ট', 'icon' => '🎉', 'type' => 'page', 'pal' => 'deep', 'sections' => array( 'hero', 'intro', 'details', 'gallery', 'cta', 'contact' ) ),
		'gallery'     => array( 'label' => 'গ্যালারি', 'icon' => '🖼', 'type' => 'page', 'pal' => 'soft', 'sections' => array( 'hero', 'intro', 'gallery' ) ),
		'notice'      => array( 'label' => 'নোটিশ', 'icon' => '📢', 'type' => 'page', 'pal' => 'shaheen', 'sections' => array( 'hero', 'notice', 'contact' ) ),
		'contact'     => array( 'label' => 'যোগাযোগ', 'icon' => '📞', 'type' => 'page', 'pal' => 'soft', 'sections' => array( 'hero', 'contact', 'branches' ) ),
		'home'        => array( 'label' => 'হোম', 'icon' => '🏠', 'type' => 'page', 'pal' => 'shaheen', 'sections' => array( 'hero', 'features', 'branches', 'results', 'notice', 'gallery', 'cta', 'contact' ) ),
		'article'     => array( 'label' => 'ব্লগ পোস্ট / প্রবন্ধ', 'icon' => '✍️', 'type' => 'post', 'pal' => 'soft', 'sections' => array( 'intro', 'features', 'faq', 'cta' ) ),
	);
}

function csa_ps_e( $s ) {
	return esc_html( (string) $s );
}


/** YouTube / Facebook / map addresses become safe embed addresses; anything else gives an empty string. */
function csa_ps_embed_url( $kind, $url ) {
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

/** Sections that need a link and have none: shown as a warning in the studio. */
function csa_ps_missing( $s ) {
	$k = $s['key'];
	if ( in_array( $k, array( 'youtube', 'video', 'fb_video', 'fb_post', 'fb_page', 'map' ), true ) ) {
		return '' === trim( (string) ( $s['c']['url'] ?? '' ) ) || ( 'video' !== $k && '' === csa_ps_embed_url( $k, $s['c']['url'] ?? '' ) );
	}
	if ( 'html' === $k ) {
		return '' === trim( (string) ( $s['c']['html'] ?? '' ) );
	}
	if ( 'social' === $k ) {
		foreach ( (array) ( $s['c']['items'] ?? array() ) as $it ) {
			if ( ! empty( $it['url'] ) ) {
				return false;
			}
		}
		return true;
	}
	return false;
}

function csa_ps_render_embed( $key, $c, $h ) {
	$cap = ! empty( $c['caption'] ) ? '<p class="ps-cap">' . csa_ps_e( $c['caption'] ) . '</p>' : '';
	if ( 'video' === $key ) {
		$u = esc_url( $c['url'] ?? '' );
		return $u ? '<section class="ps-sec">' . $h . '<video class="ps-vid" controls preload="metadata" src="' . $u . '"></video>' . $cap . '</section>' : '';
	}
	$src = csa_ps_embed_url( $key, $c['url'] ?? '' );
	if ( '' === $src ) {
		if ( 'map' === $key && ! empty( $c['url'] ) ) {
			return '<section class="ps-sec">' . $h . '<p><a class="ps-btn" target="_blank" rel="noopener" href="' . esc_url( $c['url'] ) . '">📍 মানচিত্রে দেখুন</a></p></section>';
		}
		return '';
	}
	$reel = 'fb_video' === $key && false !== strpos( (string) $c['url'], '/reel' );
	$cls  = 'ps-embed' . ( 'youtube' === $key ? ' yt' : '' ) . ( $reel ? ' reel' : '' ) . ( in_array( $key, array( 'fb_post', 'fb_page' ), true ) ? ' fbbox' : '' ) . ( 'fb_video' === $key && ! $reel ? ' yt' : '' ) . ( 'map' === $key ? ' map' : '' );
	$ih   = 'fb_post' === $key ? 560 : ( 'fb_page' === $key ? 600 : ( $reel ? 680 : 420 ) );
	return '<section class="ps-sec">' . $h . '<div class="' . $cls . '"><iframe src="' . esc_url( $src ) . '" loading="lazy" allowfullscreen allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" style="border:0;width:100%;' . ( 'yt' === substr( $cls, 12, 2 ) || false !== strpos( $cls, ' yt' ) ? '' : 'height:' . $ih . 'px' ) . '" title="' . esc_attr( $c['heading'] ?? $key ) . '"></iframe></div>' . $cap . '</section>';
}

function csa_ps_render_form( $c, $t ) {
	static $n = 0;
	$n++;
	$h = ! empty( $c['heading'] ) ? '<h2>' . csa_ps_e( $c['heading'] ) . '</h2>' : '';
	$f = '';
	$fields = ! empty( $c['fields'] ) && is_array( $c['fields'] ) ? $c['fields'] : array( array( 'label' => 'নাম', 'type' => 'text' ), array( 'label' => 'মোবাইল', 'type' => 'tel' ), array( 'label' => 'বার্তা', 'type' => 'textarea' ) );
	foreach ( $fields as $i => $fd ) {
		$lab  = csa_ps_e( $fd['label'] ?? '' );
		$type = in_array( $fd['type'] ?? '', array( 'text', 'tel', 'email', 'textarea', 'select' ), true ) ? $fd['type'] : 'text';
		if ( 'textarea' === $type ) {
			$in = '<textarea name="f' . $i . '" rows="4"></textarea>';
		} elseif ( 'select' === $type ) {
			$in = '<select name="f' . $i . '"><option value="">—</option>';
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $fd['options'] ?? '' ) ) ) ) as $o ) {
				$in .= '<option>' . csa_ps_e( $o ) . '</option>';
			}
			$in .= '</select>';
		} else {
			$in = '<input type="' . $type . '" name="f' . $i . '">';
		}
		$f .= '<label>' . $lab . $in . '<input type="hidden" name="l' . $i . '" value="' . esc_attr( $fd['label'] ?? '' ) . '"></label>';
	}
	$id = 'psf' . $n;
	return '<section class="ps-sec">' . $h . ( ! empty( $c['text'] ) ? '<p>' . csa_ps_e( $c['text'] ) . '</p>' : '' ) . '<form class="ps-form" id="' . $id . '" onsubmit="return false"><div class="ps-hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>' . $f . '<input type="hidden" name="page" value="{{PSID}}"><button type="button" class="ps-btn" data-send>' . csa_ps_e( $c['button_text'] ?? 'পাঠান' ) . '</button><p class="ps-msg" role="status"></p></form>'
		. '<script>(function(){var f=document.getElementById("' . $id . '");if(!f)return;var t=Date.now();f.querySelector("[data-send]").onclick=function(){var b=this,m=f.querySelector(".ps-msg"),d=new FormData(f);d.append("action","csa_ps_form");d.append("tm",Date.now()-t);b.disabled=true;m.textContent="পাঠানো হচ্ছে…";fetch("' . esc_js( admin_url( 'admin-ajax.php' ) ) . '",{method:"POST",body:d}).then(function(r){return r.json()}).then(function(j){m.textContent=j.success?"ধন্যবাদ! আমরা শীঘ্রই যোগাযোগ করব।":(j.data||"পাঠানো যায়নি, আবার চেষ্টা করুন।");if(j.success)f.reset();b.disabled=false}).catch(function(){m.textContent="পাঠানো যায়নি, আবার চেষ্টা করুন।";b.disabled=false})}})();</script></section>';
}

function csa_ps_render_social( $c, $h ) {
	$b = '';
	foreach ( (array) ( $c['items'] ?? array() ) as $it ) {
		if ( empty( $it['url'] ) ) {
			continue;
		}
		$lab = strtolower( (string) ( $it['label'] ?? '' ) );
		$ic  = false !== strpos( $lab, 'face' ) ? '👍' : ( false !== strpos( $lab, 'you' ) ? '▶️' : ( false !== strpos( $lab, 'insta' ) ? '📷' : '🔗' ) );
		$b  .= '<a class="ps-btn ps-soc" target="_blank" rel="noopener" href="' . esc_url( $it['url'] ) . ( false !== strpos( $lab, 'you' ) && false === strpos( $it['url'], 'sub_confirmation' ) ? ( false === strpos( $it['url'], '?' ) ? '?' : '&' ) . 'sub_confirmation=1' : '' ) . '">' . $ic . ' ' . csa_ps_e( ( $it['text'] ?? '' ) ?: ( $it['label'] ?? '' ) ) . '</a> ';
	}
	return $b ? '<section class="ps-sec ps-social">' . $h . '<div class="ps-btns">' . $b . '</div></section>' : '';
}

add_action( 'wp_ajax_nopriv_csa_ps_form', 'csa_ps_form_handler' );
add_action( 'wp_ajax_csa_ps_form', 'csa_ps_form_handler' );
function csa_ps_form_handler() {
	if ( ! empty( $_POST['website'] ) || (int) ( $_POST['tm'] ?? 0 ) < 1500 ) {
		wp_send_json_error( 'পাঠানো যায়নি।' );
	}
	$ip  = md5( $_SERVER['REMOTE_ADDR'] ?? '' );
	$cnt = (int) get_transient( 'csa_psf_' . $ip );
	if ( $cnt >= 5 ) {
		wp_send_json_error( 'অনেকবার পাঠানো হয়েছে, কিছুক্ষণ পরে চেষ্টা করুন।' );
	}
	$pid   = (int) ( $_POST['page'] ?? 0 );
	$rows  = array();
	$phone = '';
	for ( $i = 0; $i < 12; $i++ ) {
		if ( isset( $_POST[ 'f' . $i ] ) ) {
			$v = sanitize_textarea_field( wp_unslash( $_POST[ 'f' . $i ] ) );
			if ( '' !== $v ) {
				$rows[] = sanitize_text_field( wp_unslash( $_POST[ 'l' . $i ] ?? '' ) ) . ': ' . $v;
			}
		}
	}
	if ( ! $rows ) {
		wp_send_json_error( 'ফর্মটি পূরণ করুন।' );
	}
	$to = csa_ps_tokens()['email'];
	set_transient( 'csa_psf_' . $ip, $cnt + 1, HOUR_IN_SECONDS );
	$ok = wp_mail( $to, 'ওয়েবসাইট ফর্ম: ' . ( $pid ? get_the_title( $pid ) : 'Page Studio' ), implode( "\n", $rows ) . "\n\n" . ( $pid ? get_permalink( $pid ) : '' ) );
	$ok ? wp_send_json_success() : wp_send_json_error( 'ইমেইল পাঠানো যায়নি।' );
}

function csa_ps_render_section( $key, $c, $t, $imgs = array() ) {
	$c = is_array( $c ) ? $c : array();
	$h = ! empty( $c['heading'] ) ? '<h2>' . csa_ps_e( $c['heading'] ) . '</h2>' : '';
	switch ( $key ) {
		case 'hero':
			$btn = ! empty( $c['cta_text'] ) ? '<a class="ps-btn" href="' . esc_url( $c['cta_url'] ?? '#contact' ) . '">' . csa_ps_e( $c['cta_text'] ) . '</a>' : '';
			return '<section class="ps-sec ps-hero"><h2>' . csa_ps_e( $c['title'] ?? '' ) . '</h2><p>' . csa_ps_e( $c['subtitle'] ?? '' ) . '</p>' . $btn . '</section>';
		case 'intro':
			$p = '';
			foreach ( (array) ( $c['paragraphs'] ?? array() ) as $x ) {
				$p .= '<p>' . csa_ps_e( $x ) . '</p>';
			}
			return '<section class="ps-sec">' . $h . $p . '</section>';
		case 'features':
		case 'steps':
			$i = '';
			$n = 0;
			foreach ( (array) ( $c['items'] ?? array() ) as $it ) {
				$n++;
				$i .= '<div class="ps-card">' . ( 'steps' === $key ? '<span class="n">' . $n . '</span>' : '' ) . '<h3>' . csa_ps_e( $it['title'] ?? '' ) . '</h3><p>' . csa_ps_e( $it['text'] ?? '' ) . '</p></div>';
			}
			return '<section class="ps-sec">' . $h . '<div class="ps-grid">' . $i . '</div></section>';
		case 'details':
			$r = '';
			foreach ( (array) ( $c['rows'] ?? array() ) as $row ) {
				$r .= '<tr><th>' . csa_ps_e( $row['label'] ?? '' ) . '</th><td>' . csa_ps_e( $row['value'] ?? '' ) . '</td></tr>';
			}
			return '<section class="ps-sec">' . $h . '<table class="ps-table"><tbody>' . $r . '</tbody></table></section>';
		case 'notice':
			$l = '';
			foreach ( (array) ( $c['items'] ?? array() ) as $it ) {
				$l .= '<li>' . ( ! empty( $it['date'] ) ? '<b>' . csa_ps_e( $it['date'] ) . '</b> — ' : '' ) . csa_ps_e( $it['text'] ?? '' ) . '</li>';
			}
			return '<section class="ps-sec">' . $h . '<ul class="ps-list">' . $l . '</ul></section>';
		case 'results':
			$btn = ! empty( $c['button_text'] ) ? '<p><a class="ps-btn" href="' . esc_url( $c['button_url'] ?? '#' ) . '">' . csa_ps_e( $c['button_text'] ) . '</a></p>' : '';
			return '<section class="ps-sec">' . $h . '<p>' . csa_ps_e( $c['text'] ?? '' ) . '</p>' . $btn . '</section>';
		case 'branches':
			$l = '';
			foreach ( function_exists( 'csa_branches' ) ? csa_branches() : array() as $b ) {
				$l .= '<div class="ps-card"><h3><a href="' . esc_url( $b['url'] ) . '">' . csa_ps_e( $b['title'] ) . '</a></h3></div>';
			}
			return $l ? '<section class="ps-sec"><h2>' . csa_ps_e( $c['heading'] ?? 'আমাদের শাখাসমূহ' ) . '</h2><div class="ps-grid">' . $l . '</div></section>' : '';
		case 'gallery':
			$g = '';
			foreach ( $imgs as $aid ) {
				$u = wp_get_attachment_image_url( (int) $aid, 'large' );
				if ( $u ) {
					$g .= '<img loading="lazy" src="' . esc_url( $u ) . '" alt="' . esc_attr( (string) get_post_meta( (int) $aid, '_wp_attachment_image_alt', true ) ) . '">';
				}
			}
			return $g ? '<section class="ps-sec">' . $h . '<div class="ps-gal">' . $g . '</div></section>' : '';
		case 'youtube':
		case 'video':
		case 'fb_video':
		case 'fb_post':
		case 'fb_page':
		case 'map':
			return csa_ps_render_embed( $key, $c, $h );
		case 'social':
			return csa_ps_render_social( $c, $h );
		case 'form':
			return csa_ps_render_form( $c, $t );
		case 'html':
			return ! empty( $c['html'] ) ? '<section class="ps-sec ps-raw">' . $c['html'] . '</section>' : '';
		case 'faq':
			$f = '';
			foreach ( (array) ( $c['items'] ?? array() ) as $it ) {
				$f .= '<details><summary>' . csa_ps_e( $it['q'] ?? '' ) . '</summary><p>' . csa_ps_e( $it['a'] ?? '' ) . '</p></details>';
			}
			return '<section class="ps-sec ps-faq">' . $h . $f . '</section>';
		case 'cta':
			$btn = ! empty( $c['button_text'] ) ? '<a class="ps-btn" href="' . esc_url( $c['button_url'] ?? '#contact' ) . '">' . csa_ps_e( $c['button_text'] ) . '</a>' : '';
			return '<section class="ps-sec ps-cta">' . $h . '<p>' . csa_ps_e( $c['text'] ?? '' ) . '</p>' . $btn . '</section>';
		case 'contact':
			return '<section class="ps-sec" id="contact">' . $h . '<p>' . csa_ps_e( $c['text'] ?? '' ) . '</p><div class="ps-contact"><div><b>ফোন</b>' . csa_ps_e( $t['phone'] ) . '</div><div><b>ইমেইল</b>' . csa_ps_e( $t['email'] ) . '</div><div><b>ঠিকানা</b>' . csa_ps_e( $t['address'] ) . '</div></div></section>';
	}
	return '';
}

/** The whole page HTML from its Studio data. */
function csa_ps_render( $ps, $only = null, $bare = false ) {
	$t    = csa_ps_tokens( $ps['tokens'] ?? array() );
	$body = '';
	foreach ( (array) $ps['sections'] as $n => $s ) {
		if ( ! empty( $s['off'] ) || ( null !== $only && ! in_array( $n, $only, true ) ) ) {
			continue;
		}
		$body .= csa_ps_render_section( $s['key'], $s['c'] ?? array(), $t, $s['imgs'] ?? array() ) . "\n";
	}
	$body = str_replace( '{{PSID}}', (int) ( $ps['id'] ?? 0 ), $body );
	if ( $bare ) {
		return csa_ps_css( $t ) . '<div class="ps-page">' . "\n" . $body . '</div>';
	}
	return "<!-- wp:html -->\n" . csa_ps_css( $t ) . '<div class="ps-page">' . "\n" . $body . "</div>\n<!-- /wp:html -->";
}

/** FAQ schema built from the FAQ section. */
function csa_ps_schema( $id, $ps ) {
	$all = get_post_meta( $id, '_csa_jsonld', true );
	$all = is_array( $all ) ? $all : array();
	unset( $all['FAQPage'] );
	foreach ( (array) $ps['sections'] as $s ) {
		if ( 'faq' === $s['key'] && empty( $s['off'] ) && ! empty( $s['c']['items'] ) ) {
			$q = array();
			foreach ( $s['c']['items'] as $it ) {
				if ( ! empty( $it['q'] ) && ! empty( $it['a'] ) ) {
					$q[] = array( '@type' => 'Question', 'name' => $it['q'], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $it['a'] ) );
				}
			}
			if ( $q ) {
				$all['FAQPage'] = wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $q ), CSA_JSON );
			}
		}
	}
	update_post_meta( $id, '_csa_jsonld', wp_slash( $all ) );
}

/* ------------------------------------------------------------------ */
/* Storage and versions                                                 */
/* ------------------------------------------------------------------ */

function csa_ps_get( $id ) {
	$ps = get_post_meta( $id, '_csa_ps', true );
	return is_array( $ps ) && ! empty( $ps['sections'] ) ? $ps : null;
}

/** Save Studio data, draw the page and keep a version. */
function csa_ps_save( $id, $ps, $label ) {
	$ps['sections'] = array_values( $ps['sections'] );
	$ps['id']       = (int) $id;
	$lg             = isset( $ps['log'] ) && is_array( $ps['log'] ) ? $ps['log'] : array();
	$lg[]           = array( 't' => current_time( 'mysql' ), 'what' => $label );
	$ps['log']      = array_slice( $lg, -60 );
	update_post_meta( $id, '_csa_ps', wp_slash( $ps ) );
	$r = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( csa_ps_render( $ps ) ) ), true );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	csa_ps_schema( $id, $ps );
	csa_clear_cache( $id );
	$v   = get_post_meta( $id, '_csa_ps_ver', true );
	$v   = is_array( $v ) ? $v : array();
	$v[] = array( 'id' => substr( md5( uniqid( '', true ) ), 0, 8 ), 't' => current_time( 'mysql' ), 'label' => $label, 'ps' => $ps );
	update_post_meta( $id, '_csa_ps_ver', wp_slash( array_slice( $v, -10 ) ) );
	return true;
}

function csa_ps_public( $id ) {
	$ps = csa_ps_get( $id );
	if ( ! $ps ) {
		return null;
	}
	$secs = csa_ps_sections();
	$out  = array();
	foreach ( $ps['sections'] as $i => $s ) {
		$out[] = array( 'warn' => csa_ps_missing( $s ) ? 1 : 0, 'c' => $s['c'] ?? new stdClass(), 'i' => $i, 'key' => $s['key'], 'label' => $secs[ $s['key'] ]['label'] ?? $s['key'], 'off' => ! empty( $s['off'] ) ? 1 : 0, 'ai' => ! empty( $secs[ $s['key'] ]['ai'] ) ? 1 : 0 );
	}
	$ver = array();
	foreach ( array_reverse( (array) get_post_meta( $id, '_csa_ps_ver', true ) ) as $v ) {
		$ver[] = array( 'id' => $v['id'], 't' => $v['t'], 'label' => $v['label'] );
	}
	return array(
		'id'      => $id,
		'title'   => get_the_title( $id ),
		'status'  => get_post_status( $id ),
		'preview' => get_preview_post_link( $id ),
		'link'    => get_permalink( $id ),
		'sections' => $out,
		'tokens'  => csa_ps_tokens( $ps['tokens'] ?? array() ),
		'versions' => $ver,
		'brief'   => array(
			'tpl'     => $ps['template'] ?? '',
			'in'      => $ps['in'] ?? array(),
			'secs'    => array_map( function ( $x ) { return $x['key']; }, $ps['sections'] ),
			'log'     => array_reverse( (array) ( $ps['log'] ?? array() ) ),
			'placed'  => (array) ( $ps['placed'] ?? array() ),
		),
		'shortcode' => '[shaheen_studio id="' . $id . '"]',
	);
}

/* ------------------------------------------------------------------ */
/* AI                                                                   */
/* ------------------------------------------------------------------ */

const CSA_PS_RULES = ' Write in Bangla (unless the topic is clearly English) with a warm, clear voice for parents: short plain sentences, concrete details, no hype or stock phrases (no "cutting-edge", "holistic", "unlock", "in today\'s world"). Use ONLY facts from the SOURCE text or the site memory: never invent fees, dates, numbers, names, results or promises; if a fact is unknown leave that row or sentence out. Do NOT write phone numbers, e-mails or addresses (the contact section fills them). Links (YouTube, Facebook, maps, social) must be copied EXACTLY from what the owner wrote: if the owner gave none, leave that url empty, never invent one. Use the focus keyword naturally in the hero title, the first paragraph and one heading, never stuffed.';

function csa_ps_ai_content( $tpl_key, $keys, $in, $source = '' ) {
	$secs = csa_ps_sections();
	$spec = '';
	foreach ( $keys as $k ) {
		if ( ! empty( $secs[ $k ]['ai'] ) ) {
			$spec .= '- "' . $k . '": ' . $secs[ $k ]['ai'] . "\n";
		}
	}
	$sys  = 'You write the content of a school web page made of sections. ' . csa_site_context() . "\n\nReturn ONLY one JSON object whose keys are the section names below, each with exactly the shape shown:\n" . $spec . CSA_PS_RULES;
	$user = 'Page type: ' . $tpl_key . "\nTitle/topic: " . ( $in['title'] ?? '' ) . "\nBranch/area: " . ( $in['area'] ?? '' ) . "\nFocus keyword: " . ( $in['keyword'] ?? '' ) . "\nExtra instructions from the owner: " . ( $in['note'] ?? '' ) . ( '' !== $source ? "\n\nSOURCE (existing page text; keep every fact):\n" . mb_substr( $source, 0, 4500 ) : '' );
	$r    = csa_jobs_ai_json( $sys, $user, 'Page Studio: content', 3800 );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	return $r;
}

/* ------------------------------------------------------------------ */
/* Ajax                                                                 */
/* ------------------------------------------------------------------ */

add_action( 'wp_ajax_csa_ps_meta', function () {
	csa_guard( 'chat' );
	$tpl = array();
	foreach ( csa_ps_templates() as $k => $t ) {
		$tpl[] = array( 'key' => $k, 'label' => $t['label'], 'icon' => $t['icon'], 'type' => $t['type'], 'pal' => $t['pal'], 'sections' => $t['sections'] );
	}
	$secs = array();
	foreach ( csa_ps_sections() as $k => $s ) {
		$secs[ $k ] = $s['label'];
	}
	$br = array();
	foreach ( function_exists( 'csa_branches' ) ? csa_branches() : array() as $b ) {
		$br[] = $b['title'];
	}
	$mine = array();
	foreach ( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => array( 'publish', 'draft', 'private' ), 'numberposts' => 60, 'meta_key' => '_csa_ps', 'orderby' => 'modified', 'fields' => 'ids' ) ) as $pid ) {
		$mine[] = array( 'id' => $pid, 'title' => get_the_title( $pid ), 'status' => get_post_status( $pid ) );
	}
	wp_send_json_success( array( 'templates' => $tpl, 'sections' => $secs, 'branches' => $br, 'presets' => csa_ps_presets(), 'tokens' => csa_ps_tokens(), 'mine' => $mine ) );
} );

/** AI suggests which sections the page should have (and why). */
add_action( 'wp_ajax_csa_ps_suggest', function () {
	csa_guard( 'chat' );
	$tk   = sanitize_key( $_POST['template'] ?? '' );
	$tpls = csa_ps_templates();
	if ( ! isset( $tpls[ $tk ] ) ) {
		wp_send_json_error( 'Unknown template.' );
	}
	$secs = array();
	foreach ( csa_ps_sections() as $k => $s ) {
		$secs[] = $k . ' = ' . $s['label'];
	}
	$sys = 'You plan school web pages. ' . csa_site_context() . "\n\nFrom the section list choose the sections this page should have (3 to 8, in a sensible reading order) and give one short reason each, in Bangla. Also give 3 short tips about what else the owner should prepare (photos, facts, documents). Return ONLY JSON: {\"sections\":[{\"key\":\"hero\",\"why\":\"...\"}],\"tips\":[\"...\"]}\n\nSections:\n" . implode( "\n", $secs );
	$r   = csa_jobs_ai_json( $sys, 'Page type: ' . $tk . "\nTitle: " . sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ) . "\nArea: " . sanitize_text_field( wp_unslash( $_POST['area'] ?? '' ) ) . "\nNotes: " . sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ), 'Page Studio: suggest', 900 );
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( $r->get_error_message() );
	}
	$all = csa_ps_sections();
	$out = array();
	foreach ( (array) ( $r['sections'] ?? array() ) as $s ) {
		if ( isset( $all[ $s['key'] ?? '' ] ) ) {
			$out[] = array( 'key' => $s['key'], 'why' => sanitize_text_field( $s['why'] ?? '' ) );
		}
	}
	wp_send_json_success( array( 'sections' => $out, 'tips' => array_map( 'sanitize_text_field', array_slice( (array) ( $r['tips'] ?? array() ), 0, 4 ) ) ) );
} );

/** Create a new page, or rebuild an existing one. */
add_action( 'wp_ajax_csa_ps_create', function () {
	csa_guard( 'chat' );
	$tk   = sanitize_key( $_POST['template'] ?? '' );
	$tpls = csa_ps_templates();
	if ( ! isset( $tpls[ $tk ] ) ) {
		$tk = 'custom';
		$tpls['custom'] = array( 'type' => 'post' === ( $_POST['ptype'] ?? '' ) ? 'post' : 'page', 'sections' => array( 'hero', 'intro', 'contact' ) );
	}
	$secs  = csa_ps_sections();
	$keys  = array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $_POST['sections'] ?? '' ) ) ), function ( $k ) use ( $secs ) { return isset( $secs[ $k ] ); } ) );
	$keys  = $keys ? $keys : $tpls[ $tk ]['sections'];
	$in    = array(
		'title'   => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
		'area'    => sanitize_text_field( wp_unslash( $_POST['area'] ?? '' ) ),
		'keyword' => sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) ),
		'note'    => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
	);
	if ( '' === $in['title'] ) {
		wp_send_json_error( csa_t( 'Write a title first.' ) );
	}
	$tok = array();
	foreach ( array( 'primary', 'dark', 'accent', 'soft' ) as $k ) {
		if ( ! empty( $_POST[ $k ] ) && preg_match( '/^#[0-9a-f]{6}$/i', $_POST[ $k ] ) ) {
			$tok[ $k ] = $_POST[ $k ];
		}
	}
	$tok['gradient'] = empty( $_POST['gradient'] ) ? 0 : 1;
	$target = (int) ( $_POST['target'] ?? 0 );
	$source = '';
	if ( $target ) {
		$p = csa_valid_post( $target );
		if ( ! $p ) {
			wp_send_json_error( csa_t( 'Page not found.' ) );
		}
		$g = csa_live_guard( $p );
		if ( $g ) {
			wp_send_json_error( $g['error'] ?? 'Live editing is off.' );
		}
		$source = function_exists( 'csa_si_text' ) ? csa_si_text( $p ) : wp_strip_all_tags( $p->post_content );
		if ( csa_is_elementor( $target ) ) {
			$cv = csa_th_convert( $target ); // Backed up, undoable from Activity.
			if ( isset( $cv['error'] ) ) {
				wp_send_json_error( $cv['error'] );
			}
		}
		$undo = csa_snapshot_post( $target );
		csa_log( csa_t( 'Page Studio rebuilt' ) . ' #' . $target . ' ' . $p->post_title, $undo );
	}
	$content = csa_ps_ai_content( $tk, $keys, $in, $source );
	if ( is_wp_error( $content ) ) {
		wp_send_json_error( $content->get_error_message() );
	}
	$sections = array();
	$gal      = array();
	foreach ( get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'numberposts' => 6, 'orderby' => 'date', 'order' => 'DESC' ) ) as $a ) {
		if ( 'image/svg+xml' !== $a->post_mime_type ) {
			$gal[] = $a->ID;
		}
	}
	foreach ( $keys as $k ) {
		$s = array( 'key' => $k, 'c' => $content[ $k ] ?? array() );
		if ( 'gallery' === $k ) {
			$s['imgs'] = $gal;
		}
		$sections[] = $s;
	}
	$ps = array( 'template' => $tk, 'sections' => $sections, 'tokens' => $tok, 'in' => $in );
	if ( $target ) {
		$id = $target;
		wp_update_post( array( 'ID' => $id, 'post_title' => $in['title'] ) );
	} else {
		$id = wp_insert_post( array( 'post_title' => $in['title'], 'post_status' => 'draft', 'post_type' => $tpls[ $tk ]['type'], 'post_content' => '' ), true );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( $id->get_error_message() );
		}
		csa_log( csa_t( 'Page Studio created draft' ) . ' #' . $id . ' ' . $in['title'], array( 'type' => 'created', 'post' => (int) $id ) );
	}
	if ( '' !== $in['keyword'] ) {
		csa_seo_set( $id, array( 'keyword' => $in['keyword'] ) );
	}
	$r = csa_ps_save( $id, $ps, csa_t( 'Created' ) . ': ' . mb_substr( $in['note'] ?: $in['title'], 0, 120 ) );
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( $r->get_error_message() );
	}
	wp_send_json_success( csa_ps_public( $id ) );
} );

add_action( 'wp_ajax_csa_ps_load', function () {
	csa_guard( 'chat' );
	$id = (int) ( $_POST['id'] ?? 0 );
	$d  = csa_valid_post( $id ) ? csa_ps_public( $id ) : null;
	$d ? wp_send_json_success( $d ) : wp_send_json_error( csa_t( 'This page was not made with Page Studio.' ) );
} );

/** Change the page: move / remove / restore a section, change colours, regenerate one section with an instruction. */
add_action( 'wp_ajax_csa_ps_edit', function () {
	csa_guard( 'chat' );
	$id = (int) ( $_POST['id'] ?? 0 );
	$p  = csa_valid_post( $id );
	$ps = $p ? csa_ps_get( $id ) : null;
	if ( ! $ps ) {
		wp_send_json_error( csa_t( 'This page was not made with Page Studio.' ) );
	}
	$g = csa_live_guard( $p );
	if ( $g ) {
		wp_send_json_error( $g['error'] ?? 'Live editing is off.' );
	}
	$act = sanitize_key( $_POST['act'] ?? '' );
	$i   = (int) ( $_POST['i'] ?? -1 );
	$s   =& $ps['sections'];
	$lab = '';
	switch ( $act ) {
		case 'up':
		case 'down':
			$j = 'up' === $act ? $i - 1 : $i + 1;
			if ( ! isset( $s[ $i ], $s[ $j ] ) ) {
				wp_send_json_error( 'Cannot move.' );
			}
			$tmp = $s[ $i ]; $s[ $i ] = $s[ $j ]; $s[ $j ] = $tmp;
			$lab = csa_t( 'Moved a section' );
			break;
		case 'toggle':
			if ( ! isset( $s[ $i ] ) ) {
				wp_send_json_error( 'No such section.' );
			}
			$s[ $i ]['off'] = empty( $s[ $i ]['off'] ) ? 1 : 0;
			$lab = csa_t( 'Section shown/hidden' );
			break;
		case 'colors':
			$tok = $ps['tokens'] ?? array();
			foreach ( array( 'primary', 'dark', 'accent', 'soft' ) as $k ) {
				if ( ! empty( $_POST[ $k ] ) && preg_match( '/^#[0-9a-f]{6}$/i', $_POST[ $k ] ) ) {
					$tok[ $k ] = $_POST[ $k ];
				}
			}
			$tok['gradient'] = empty( $_POST['gradient'] ) ? 0 : 1;
			$tok             = array_merge( $tok, csa_ps_style_in( $_POST ) );
			$ps['tokens']    = $tok;
			$lab             = csa_t( 'Colours / sizes changed' );
			break;
		case 'remove':
			if ( ! isset( $s[ $i ] ) ) {
				wp_send_json_error( 'No such section.' );
			}
			array_splice( $s, $i, 1 );
			$lab = csa_t( 'Section removed' );
			break;
		case 'setc':
			if ( ! isset( $s[ $i ] ) ) {
				wp_send_json_error( 'No such section.' );
			}
			$c = json_decode( wp_unslash( $_POST['c'] ?? '' ), true );
			if ( ! is_array( $c ) ) {
				wp_send_json_error( 'Bad data.' );
			}
			$s[ $i ]['c'] = csa_ps_clean( $c );
			$lab          = csa_t( 'Text edited by hand' );
			break;
		case 'add':
			$key = sanitize_key( $_POST['key'] ?? '' );
			if ( ! isset( csa_ps_sections()[ $key ] ) ) {
				wp_send_json_error( 'Unknown section.' );
			}
			$new = csa_ps_new_section( $key, $p->post_title, sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ) );
			if ( is_wp_error( $new ) ) {
				wp_send_json_error( $new->get_error_message() );
			}
			array_splice( $s, max( 0, min( count( $s ), (int) ( $_POST['at'] ?? count( $s ) ) ) ), 0, array( $new ) );
			$lab = csa_t( 'Section added' ) . ': ' . $key;
			break;
		case 'regen':
			if ( ! isset( $s[ $i ] ) ) {
				wp_send_json_error( 'No such section.' );
			}
			$key  = $s[ $i ]['key'];
			$spec = csa_ps_sections()[ $key ]['ai'] ?? '';
			if ( '' === $spec ) {
				wp_send_json_error( csa_t( 'This section has no text to change.' ) );
			}
			$note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
			$sys  = 'You rewrite ONE section of a school web page. ' . csa_site_context() . "\n\nReturn ONLY one JSON object of this exact shape: " . $spec . CSA_PS_RULES;
			$r    = csa_jobs_ai_json( $sys, 'Current section content: ' . wp_json_encode( $s[ $i ]['c'], CSA_JSON ) . "\nPage title: " . $p->post_title . "\nOwner's instruction: " . $note, 'Page Studio: fix section', 1800 );
			if ( is_wp_error( $r ) || ! is_array( $r ) || ! $r ) {
				wp_send_json_error( is_wp_error( $r ) ? $r->get_error_message() : 'No usable answer. Try again.' );
			}
			$s[ $i ]['c'] = $r;
			$lab          = csa_t( 'Section rewritten' ) . ': ' . mb_substr( $note, 0, 40 );
			break;
		default:
			wp_send_json_error( 'Unknown action.' );
	}
	$undo = csa_snapshot_post( $id );
	$r    = csa_ps_save( $id, $ps, $lab );
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( $r->get_error_message() );
	}
	csa_log( 'Page Studio: ' . $lab . ' #' . $id . ' ' . $p->post_title, $undo );
	wp_send_json_success( csa_ps_public( $id ) );
} );

add_action( 'wp_ajax_csa_ps_restore', function () {
	csa_guard( 'chat' );
	$id = (int) ( $_POST['id'] ?? 0 );
	$vid = sanitize_text_field( $_POST['ver'] ?? '' );
	$p  = csa_valid_post( $id );
	if ( ! $p ) {
		wp_send_json_error( csa_t( 'Page not found.' ) );
	}
	foreach ( (array) get_post_meta( $id, '_csa_ps_ver', true ) as $v ) {
		if ( $v['id'] === $vid ) {
			$r = csa_ps_save( $id, $v['ps'], csa_t( 'Restored an earlier version' ) );
			is_wp_error( $r ) ? wp_send_json_error( $r->get_error_message() ) : wp_send_json_success( csa_ps_public( $id ) );
		}
	}
	wp_send_json_error( 'Version not found.' );
} );

add_action( 'wp_ajax_csa_ps_publish', function () {
	csa_guard( 'chat' );
	$id = (int) ( $_POST['id'] ?? 0 );
	$p  = csa_valid_post( $id );
	if ( ! $p || ! csa_ps_get( $id ) ) {
		wp_send_json_error( csa_t( 'Page not found.' ) );
	}
	wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
	csa_log( csa_t( 'Published' ) . ' #' . $id . ' ' . $p->post_title, array( 'type' => 'post', 'post' => $id, 'key' => '' ) );
	wp_send_json_success( csa_ps_public( $id ) );
} );



/** [shaheen_studio id="12"] or [shaheen_studio id="12" section="2,3"]: put a Studio page (or some of its sections) anywhere, always up to date. */
add_shortcode( 'shaheen_studio', function ( $a ) {
	$a  = shortcode_atts( array( 'id' => 0, 'section' => '' ), $a );
	$ps = csa_ps_get( (int) $a['id'] );
	if ( ! $ps || 'publish' !== get_post_status( (int) $a['id'] ) && ! current_user_can( 'edit_posts' ) ) {
		return '';
	}
	$only = '' === trim( $a['section'] ) ? null : array_map( 'intval', explode( ',', $a['section'] ) );
	return csa_ps_render( $ps, $only, true );
} );

/** Put a Studio page / section into any other page (adds the shortcode at the end; Undo in Activity). */
add_action( 'wp_ajax_csa_ps_place', function () {
	csa_guard( 'chat' );
	$id  = (int) ( $_POST['id'] ?? 0 );
	$tid = (int) ( $_POST['target'] ?? 0 );
	$ps  = csa_ps_get( $id );
	$t   = csa_valid_post( $tid );
	if ( ! $ps || ! $t || $tid === $id ) {
		wp_send_json_error( csa_t( 'Choose another page.' ) );
	}
	$g = csa_live_guard( $t );
	if ( $g ) {
		wp_send_json_error( $g['error'] ?? 'Live editing is off.' );
	}
	$sec = sanitize_text_field( $_POST['section'] ?? 'all' );
	$sc  = '[shaheen_studio id="' . $id . '"' . ( 'all' !== $sec && preg_match( '/^\d+(,\d+)*$/', $sec ) ? ' section="' . $sec . '"' : '' ) . ']';
	if ( false !== strpos( (string) get_post_field( 'post_content', $tid ), $sc ) || ( csa_is_elementor( $tid ) && false !== strpos( (string) get_post_meta( $tid, '_elementor_data', true ), esc_attr( $sc ) ) ) ) {
		wp_send_json_error( csa_t( 'It is already on that page.' ) );
	}
	$u = csa_jobs_append( $tid, "\n" . $sc );
	if ( is_wp_error( $u ) ) {
		wp_send_json_error( $u->get_error_message() );
	}
	csa_log( 'Page Studio: ' . $sc . ' → #' . $tid . ' ' . $t->post_title, $u );
	$pl   = (array) ( $ps['placed'] ?? array() );
	$pl[] = array( 'id' => $tid, 'title' => $t->post_title, 'sc' => $sc );
	$ps['placed'] = array_slice( $pl, -30 );
	update_post_meta( $id, '_csa_ps', wp_slash( $ps ) );
	wp_send_json_success( csa_ps_public( $id ) );
} );

/** Save the shared facts and default colours, and redraw every Studio page. */
add_action( 'wp_ajax_csa_ps_global', function () {
	csa_guard( 'chat' );
	$cur = get_option( 'csa_ps_tokens', array() );
	$cur = is_array( $cur ) ? $cur : array();
	foreach ( array( 'phone', 'email', 'address' ) as $k ) {
		if ( isset( $_POST[ $k ] ) ) {
			$cur[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
		}
	}
	foreach ( array( 'primary', 'dark', 'accent', 'soft' ) as $k ) {
		if ( ! empty( $_POST[ $k ] ) && preg_match( '/^#[0-9a-f]{6}$/i', $_POST[ $k ] ) ) {
			$cur[ $k ] = $_POST[ $k ];
		}
	}
	update_option( 'csa_ps_tokens', $cur, false );
	$n = 0;
	foreach ( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => array( 'publish', 'draft', 'private' ), 'numberposts' => 200, 'meta_key' => '_csa_ps', 'fields' => 'ids' ) ) as $pid ) {
		$ps = csa_ps_get( $pid );
		if ( $ps ) {
			wp_update_post( array( 'ID' => $pid, 'post_content' => wp_slash( csa_ps_render( $ps ) ) ) );
			csa_clear_cache( $pid );
			$n++;
		}
	}
	wp_send_json_success( array( 'redrawn' => $n, 'tokens' => csa_ps_tokens() ) );
} );


/** Number settings (sizes) from a request, kept inside safe ranges. */
function csa_ps_style_in( $src ) {
	$o   = array();
	$lim = array( 'fs' => array( 13, 24 ), 'fs_m' => array( 12, 22 ), 'width' => array( 600, 1600 ), 'gap' => array( 12, 100 ), 'radius' => array( 0, 40 ) );
	foreach ( $lim as $k => $r ) {
		if ( isset( $src[ $k ] ) && is_numeric( $src[ $k ] ) ) {
			$o[ $k ] = max( $r[0], min( $r[1], (int) $src[ $k ] ) );
		}
	}
	if ( isset( $src['lh'] ) && is_numeric( $src['lh'] ) ) {
		$o['lh'] = max( 1.2, min( 2.4, (float) $src['lh'] ) );
	}
	return $o;
}

function csa_ps_clean( $v ) {
	if ( is_array( $v ) ) {
		$o = array();
		foreach ( $v as $k => $x ) {
			$kk = is_int( $k ) ? $k : sanitize_key( $k );
			if ( 'html' === $kk && is_string( $x ) ) {
				$o[ $kk ] = current_user_can( 'unfiltered_html' ) ? $x : wp_kses( $x, array_merge( wp_kses_allowed_html( 'post' ), array( 'iframe' => array( 'src' => true, 'width' => true, 'height' => true, 'allow' => true, 'allowfullscreen' => true, 'loading' => true, 'style' => true, 'title' => true, 'frameborder' => true ) ) ) );
				continue;
			}
			$o[ $kk ] = csa_ps_clean( $x );
		}
		return $o;
	}
	return is_scalar( $v ) ? sanitize_textarea_field( (string) $v ) : '';
}

/** A fresh section with AI-written text (or none for auto sections). */
function csa_ps_new_section( $key, $title, $note ) {
	$spec = csa_ps_sections()[ $key ]['ai'] ?? '';
	$s    = array( 'key' => $key, 'c' => array() );
	if ( 'gallery' === $key ) {
		$s['imgs'] = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'numberposts' => 6, 'fields' => 'ids' ) );
	}
	if ( '' === $spec ) {
		return $s;
	}
	$sys = 'You write ONE section of a school web page. ' . csa_site_context() . "\n\nReturn ONLY one JSON object of this exact shape: " . $spec . CSA_PS_RULES;
	$r   = csa_jobs_ai_json( $sys, 'Page title: ' . $title . "\nOwner's instruction: " . $note, 'Page Studio: new section', 1800 );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$s['c'] = csa_ps_clean( $r );
	return $s;
}

/**
 * First step of the prompt box: the owner says what he wants in plain words; the AI answers with
 * a short summary, up to 3 questions with ready options, and a proposed list of sections (with the reason and place).
 */
add_action( 'wp_ajax_csa_ps_brief', function () {
	csa_guard( 'chat' );
	$prompt = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );
	if ( '' === $prompt ) {
		wp_send_json_error( csa_t( 'Write what you want first.' ) );
	}
	$ptype  = 'post' === ( $_POST['ptype'] ?? '' ) ? 'post' : 'page';
	$target = (int) ( $_POST['target'] ?? 0 );
	$old    = '';
	if ( $target && ( $p = csa_valid_post( $target ) ) ) {
		$old = 'EXISTING PAGE "' . $p->post_title . '" text (it will be rebuilt, keep its facts):' . "\n" . mb_substr( function_exists( 'csa_si_text' ) ? csa_si_text( $p ) : wp_strip_all_tags( $p->post_content ), 0, 2500 );
	}
	$secs = array();
	foreach ( csa_ps_sections() as $k => $x ) {
		$secs[] = $k . ' = ' . $x['label'];
	}
	$tp = array();
	foreach ( csa_ps_templates() as $k => $x ) {
		$tp[] = $k . ' = ' . $x['label'] . ' (' . implode( ',', $x['sections'] ) . ')';
	}
	$pal = implode( ', ', array_keys( csa_ps_presets() ) );
	$sys = 'You are the page planner for a school website. ' . csa_site_context() . "\n\nThe owner describes a page in plain words (Bangla or English). Reply in Bangla. Return ONLY JSON:\n{\"summary\":\"1-2 short sentences: what you understood\",\"title\":\"suggested page title\",\"keyword\":\"focus keyword or empty\",\"area\":\"branch name or empty\",\"template\":\"closest template key or empty\",\"palette\":\"one of: {$pal}\",\"questions\":[{\"q\":\"short question\",\"options\":[\"2 to 4 short ready answers\"]}],\"sections\":[{\"key\":\"section key\",\"where\":\"top|middle|bottom\",\"why\":\"short reason\"}]}\n\nRules: ask at most 3 questions and only about things that change the page (which branch, what colour mood, what the visitor should do, which facts you must not guess). Choose 4 to 9 sections in reading order, only from this list:\n" . implode( "\n", $secs ) . "\nTemplates:\n" . implode( "\n", $tp );
	$r   = csa_jobs_ai_json( $sys, 'Page kind: ' . $ptype . "\nOwner says: " . $prompt . ( $old ? "\n\n" . $old : '' ) . "\nBranches on the site: " . implode( ', ', array_map( function ( $b ) { return $b['title']; }, function_exists( 'csa_branches' ) ? array_slice( csa_branches(), 0, 40 ) : array() ) ), 'Page Studio: brief', 1400 );
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( $r->get_error_message() );
	}
	$all = csa_ps_sections();
	$sec = array();
	foreach ( (array) ( $r['sections'] ?? array() ) as $x ) {
		if ( isset( $all[ $x['key'] ?? '' ] ) ) {
			$sec[] = array( 'key' => $x['key'], 'label' => $all[ $x['key'] ]['label'], 'where' => sanitize_text_field( $x['where'] ?? '' ), 'why' => sanitize_text_field( $x['why'] ?? '' ) );
		}
	}
	$qs = array();
	foreach ( array_slice( (array) ( $r['questions'] ?? array() ), 0, 3 ) as $x ) {
		if ( ! empty( $x['q'] ) ) {
			$qs[] = array( 'q' => sanitize_text_field( $x['q'] ), 'options' => array_map( 'sanitize_text_field', array_slice( (array) ( $x['options'] ?? array() ), 0, 4 ) ) );
		}
	}
	$tpl = sanitize_key( $r['template'] ?? '' );
	$pk  = sanitize_key( $r['palette'] ?? '' );
	wp_send_json_success( array(
		'summary'   => sanitize_text_field( $r['summary'] ?? '' ),
		'title'     => sanitize_text_field( $r['title'] ?? '' ),
		'keyword'   => sanitize_text_field( $r['keyword'] ?? '' ),
		'area'      => sanitize_text_field( $r['area'] ?? '' ),
		'template'  => isset( csa_ps_templates()[ $tpl ] ) ? $tpl : '',
		'palette'   => isset( csa_ps_presets()[ $pk ] ) ? $pk : 'shaheen',
		'questions' => $qs,
		'sections'  => $sec,
	) );
} );

/** The command box on a finished page: the AI turns the owner's words into a list of exact changes, which are applied at once. */
add_action( 'wp_ajax_csa_ps_command', function () {
	csa_guard( 'chat' );
	$id = (int) ( $_POST['id'] ?? 0 );
	$p  = csa_valid_post( $id );
	$ps = $p ? csa_ps_get( $id ) : null;
	if ( ! $ps ) {
		wp_send_json_error( csa_t( 'This page was not made with Page Studio.' ) );
	}
	$g = csa_live_guard( $p );
	if ( $g ) {
		wp_send_json_error( $g['error'] ?? 'Live editing is off.' );
	}
	$text = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
	if ( '' === $text ) {
		wp_send_json_error( csa_t( 'Write what to change.' ) );
	}
	$all = csa_ps_sections();
	$state = array();
	foreach ( $ps['sections'] as $i => $s ) {
		$state[] = array( 'i' => $i, 'key' => $s['key'], 'hidden' => ! empty( $s['off'] ), 'content' => $s['c'] ?? array() );
	}
	$shapes = array();
	foreach ( $all as $k => $x ) {
		if ( $x['ai'] ) {
			$shapes[] = $k . ': ' . $x['ai'];
		}
	}
	$sys = 'You edit a school web page made of sections. ' . csa_site_context() . "\n\nReply in Bangla. Return ONLY JSON: {\"reply\":\"1-2 short sentences: what you did, or what you could not do\",\"ops\":[...]}. Each op is one of:\n"
		. "{\"op\":\"set\",\"i\":N,\"c\":{full new content in that section's shape}}\n{\"op\":\"add\",\"key\":\"...\",\"at\":N,\"c\":{content}}\n{\"op\":\"remove\",\"i\":N}\n{\"op\":\"move\",\"i\":N,\"to\":M}\n{\"op\":\"hide\",\"i\":N} or {\"op\":\"show\",\"i\":N}\n{\"op\":\"colors\",\"primary\":\"#rrggbb\",\"dark\":\"#rrggbb\",\"accent\":\"#rrggbb\",\"soft\":\"#rrggbb\",\"gradient\":0|1}\n{\"op\":\"style\",\"fs\":15-22 (desktop text px),\"fs_m\":13-20 (phone text px),\"width\":700-1400,\"gap\":16-80,\"radius\":0-30,\"lh\":1.4-2.1}\n{\"op\":\"title\",\"v\":\"new page title\"}\n"
		. "Indexes N/M refer to the list as it is after the previous ops. Only include the keys you change. When you change text keep all facts; use the exact shapes:\n" . implode( "\n", $shapes ) . CSA_PS_RULES . "\nIf the request is impossible (a video, a form, a new unsupported block) say so in reply with ops [].";
	$r = csa_jobs_ai_json( $sys, 'What this page was made from (original brief): ' . wp_json_encode( $ps['in'] ?? array(), CSA_JSON ) . "\nEarlier changes: " . wp_json_encode( array_slice( (array) ( $ps['log'] ?? array() ), -15 ), CSA_JSON ) . "\n\n" . 'Current page "' . $p->post_title . '": ' . wp_json_encode( $state, CSA_JSON ) . "\nCurrent settings: " . wp_json_encode( array_intersect_key( csa_ps_tokens( $ps['tokens'] ?? array() ), array_flip( array( 'primary', 'dark', 'accent', 'soft', 'gradient', 'fs', 'fs_m', 'width', 'gap', 'radius', 'lh' ) ) ), CSA_JSON ) . "\n\nOwner says: " . $text, 'Page Studio: command', 3800 );
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( $r->get_error_message() );
	}
	$undo = csa_snapshot_post( $id );
	$s    =& $ps['sections'];
	$tok  = $ps['tokens'] ?? array();
	$n    = 0;
	foreach ( (array) ( $r['ops'] ?? array() ) as $o ) {
		$i  = isset( $o['i'] ) ? (int) $o['i'] : -1;
		switch ( $o['op'] ?? '' ) {
			case 'set':
				if ( isset( $s[ $i ] ) && is_array( $o['c'] ?? null ) ) {
					$s[ $i ]['c'] = csa_ps_clean( $o['c'] ); $n++;
				}
				break;
			case 'add':
				$k = sanitize_key( $o['key'] ?? '' );
				if ( isset( $all[ $k ] ) ) {
					$new = array( 'key' => $k, 'c' => csa_ps_clean( is_array( $o['c'] ?? null ) ? $o['c'] : array() ) );
					if ( 'gallery' === $k ) {
						$new['imgs'] = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'numberposts' => 6, 'fields' => 'ids' ) );
					}
					array_splice( $s, max( 0, min( count( $s ), (int) ( $o['at'] ?? count( $s ) ) ) ), 0, array( $new ) ); $n++;
				}
				break;
			case 'remove':
				if ( isset( $s[ $i ] ) ) {
					array_splice( $s, $i, 1 ); $n++;
				}
				break;
			case 'move':
				$to = (int) ( $o['to'] ?? -1 );
				if ( isset( $s[ $i ] ) && $to >= 0 && $to < count( $s ) ) {
					$m = array_splice( $s, $i, 1 ); array_splice( $s, $to, 0, $m ); $n++;
				}
				break;
			case 'hide':
			case 'show':
				if ( isset( $s[ $i ] ) ) {
					$s[ $i ]['off'] = 'hide' === $o['op'] ? 1 : 0; $n++;
				}
				break;
			case 'colors':
				foreach ( array( 'primary', 'dark', 'accent', 'soft' ) as $k ) {
					if ( ! empty( $o[ $k ] ) && preg_match( '/^#[0-9a-f]{6}$/i', $o[ $k ] ) ) {
						$tok[ $k ] = $o[ $k ]; $n++;
					}
				}
				if ( isset( $o['gradient'] ) ) {
					$tok['gradient'] = empty( $o['gradient'] ) ? 0 : 1;
				}
				break;
			case 'style':
				$st = csa_ps_style_in( $o );
				if ( $st ) {
					$tok = array_merge( $tok, $st ); $n++;
				}
				break;
			case 'title':
				if ( ! empty( $o['v'] ) ) {
					wp_update_post( array( 'ID' => $id, 'post_title' => sanitize_text_field( $o['v'] ) ) ); $n++;
				}
				break;
		}
	}
	$reply = sanitize_text_field( $r['reply'] ?? '' );
	if ( $n ) {
		$ps['tokens'] = $tok;
		$sv           = csa_ps_save( $id, $ps, csa_t( 'Command' ) . ': ' . mb_substr( $text, 0, 40 ) );
		if ( is_wp_error( $sv ) ) {
			wp_send_json_error( $sv->get_error_message() );
		}
		csa_log( 'Page Studio: ' . mb_substr( $text, 0, 60 ) . ' #' . $id, $undo );
	}
	wp_send_json_success( array( 'reply' => $reply, 'changed' => $n, 'page' => csa_ps_public( $id ) ) );
} );

/* ------------------------------------------------------------------ */
/* Screen                                                               */
/* ------------------------------------------------------------------ */

function csa_screen_studio() {
	csa_header( csa_t( 'Page Studio' ) );
	?>
	<div id="csa-studio-app"><div id="ps-body"><p class="csa-muted"><?php echo esc_html( csa_t( 'Loading…' ) ); ?></p></div></div>
	<?php
	csa_footer();
}
