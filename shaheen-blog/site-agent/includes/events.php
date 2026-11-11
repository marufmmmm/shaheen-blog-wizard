<?php
/**
 * Events (Spelling Star, Khude Star, any future programme) and the new home page.
 * Everything is plugin-managed HTML, controlled from Page Builder > ইভেন্ট ও হোম.
 * An event has its own WordPress page (real slug, Yoast works); events show on the home page by switch and date window.
 * The new home page is a separate draft page; the old Elementor home page is never touched. Going live only changes
 * the "front page" setting and can be reversed with one click.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CSA_EV_OPT  = 'csa_ev';
const CSA_EV_LIST = 'csa_ev_list';

/* ---------- Home defaults (taken from the live page, owner can change everything) ---------- */
function csa_ev_home_defaults() {
	return array(
		'order'   => array( 'hero', 'events', 'notice', 'act', 'branch', 'ach', 'cadet', 'why', 'ideal', 'blog', 'cta' ),
		'off'     => array(),
		'hero'    => array(
			'kicker' => '🏫 বাংলাদেশের বিশ্বস্ত শিক্ষা পরিবার',
			'h1a'    => 'মেধা বিকাশে শাহীন শিক্ষা পরিবার —',
			'h1b'    => 'বাংলাদেশের শ্রেষ্ঠ শিক্ষা প্রতিষ্ঠান',
			'sub'    => 'দেশের ৮টি বিভাগীয় শহরসহ ৩৫টি জেলায়, {branches}টি ক্যাম্পাসে আমরা দিচ্ছি আধুনিক ও মানসম্মত শিক্ষা।',
			'b1'     => array( 't' => 'ভর্তির তথ্য', 'u' => '/branch/' ),
			'b2'     => array( 't' => 'অনলাইন ক্যাডেট ও প্রি-ক্যাডেট কোচিং', 'u' => '/cadet-online/' ),
			'img'    => '',
			'stats'  => array( array( 'n' => '{branches}', 'l' => 'ক্যাম্পাস' ), array( 'n' => '৩৫', 'l' => 'জেলায় আমরা আছি' ), array( 'n' => '৩৩৬', 'l' => 'ক্যাডেটে লিখিত কৃতকার্য (২০২৬)' ), array( 'n' => '৩৫', 'l' => 'বছরের অভিজ্ঞতা' ) ),
		),
		'events'  => array( 't' => 'আমাদের চলমান আয়োজন', 'p' => 'কীভাবে অংশ নেবেন, কবে কী হবে, ফলাফল ও ভিডিও — সব পাবেন প্রতিটি আয়োজনের নিজস্ব পেজে', 'max' => 6 ),
		'notice'  => array( 't' => 'সর্বশেষ নোটিশ', 'p' => '', 'n' => 5, 'bt' => 'সব নোটিশ দেখুন →', 'bu' => '' ),
		'act'     => array(
			't'     => 'আমাদের কার্যক্রমসমূহ',
			'p'     => 'স্কুল থেকে ক্যাডেট কলেজ প্রস্তুতি — সবকিছু এক পরিবারে',
			'bars'  => array(
				array( 'ic' => '📚', 't' => 'আমাদের নিজস্ব প্রকাশনীর ক্যাডেট কলেজ ভর্তি গাইড সংগ্রহ করতে এখানে ক্লিক করুন', 'u' => 'https://store.shaheen.edu.bd/', 'c' => 'g' ),
				array( 'ic' => '💻', 't' => 'অনলাইন ক্যাডেট কোচিং প্রোগ্রামে ভর্তি হতে এখানে ক্লিক করুন', 'u' => 'https://cadetcoaching.shaheen.edu.bd/signup', 'c' => 'y' ),
			),
			'cards' => array(
				array( 'ic' => '🏫', 'img' => '', 't' => 'স্কুল (প্লে–১০ম)', 'd' => 'পড়াশোনার সঙ্গে আঁকা ও বিতর্কের চর্চা', 'u' => '/school/', 'tag' => '' ),
				array( 'ic' => '🎖️', 'img' => '', 't' => 'ক্যাডেট কোচিং', 'd' => '২০২৬ সালে ৩৩৬ জন ক্যাডেট কলেজে লিখিত কৃতকার্য', 'u' => '/cadet/', 'tag' => '★ সেরা' ),
				array( 'ic' => '💻', 'img' => '', 't' => 'ক্যাডেট কোচিং অনলাইন', 'd' => 'Zoom-এ লাইভ ক্লাস (৬ষ্ঠ–৭ম শ্রেণি)', 'u' => '/cadet-online/', 'tag' => '' ),
				array( 'ic' => '🎓', 'img' => '', 't' => 'প্রি-ক্যাডেট কোচিং', 'd' => '৫ম শ্রেণির জন্য বিশেষ প্রোগ্রাম', 'u' => '/pre-cadet/', 'tag' => '' ),
				array( 'ic' => '📘', 'img' => '', 't' => 'একাডেমিক কোচিং', 'd' => 'সহজ ও প্রাঞ্জল ভাষায় পাঠদান', 'u' => '/academic/', 'tag' => '' ),
				array( 'ic' => '☪️', 'img' => '', 't' => 'ইসলামিক স্কুল ও মাদ্রাসা', 'd' => 'স্কুল ও মাদ্রাসার সমন্বিত শিক্ষা', 'u' => '/islamic_school/', 'tag' => '' ),
				array( 'ic' => '🔤', 'img' => '', 't' => 'School (English Version)', 'd' => 'ইংরেজি ভার্সনে পূর্ণাঙ্গ আধুনিক শিক্ষা', 'u' => '/school-english-version/', 'tag' => '' ),
			),
		),
		'branch'  => array( 't' => 'আপনার কাছের শাখা কোনটি?', 'p' => 'দেশের ৮টি বিভাগীয় শহরসহ ৩৫টি জেলায় আমাদের {branches}টি ক্যাম্পাস', 'bt' => 'সব শাখা দেখুন →', 'bu' => '/branch/', 'map' => '/wp-content/uploads/2026/11/Map-2026-4.png', 'y1' => '১৯৯২', 'y2' => '২০২৭', 'yn' => '৩৫', 'yl' => 'বছরের সাফল্য', 'n4' => '৪ বার', 'n4l' => 'জাতীয় পর্যায়ে প্রথম', 'n4s' => 'প্রাথমিক সমাপনীতে: ২০০৪, ২০০৮, ২০০৯ ও ২০১০' ),
		'ach'     => array(
			't'     => 'আমাদের গৌরবের অধ্যায়',
			'p'     => '৩৫ বছরের পথচলায় ধারাবাহিক সাফল্য',
			'nums'  => array( array( 'n' => '১৯', 'l' => 'বার টাঙ্গাইলে প্রথম' ), array( 'n' => '১৩৫০', 'l' => 'টাঙ্গাইলে এসএসসি-তে A+' ) ),
			'items' => array(
				array( 'ic' => '🎖️', 't' => 'ক্যাডেট কলেজ ২০২৬', 'big' => 'শুধুমাত্র ২০২৬ সালে শাহীন শিক্ষা পরিবার থেকে ক্যাডেট কলেজে লিখিত পরীক্ষায় কৃতকার্য ৩৩৬ জন শিক্ষার্থী।', 'd' => '২০২৬ সালে দেশের ১২টি ক্যাডেট কলেজে চূড়ান্তভাবে চান্স পেয়েছে ৬৮২ জন। এর মধ্যে শুধু শাহীন শিক্ষা পরিবার থেকেই চান্স পেয়েছে ১২১ জন।', 'img' => '' ),
				array( 'ic' => '🏅', 't' => 'প্রাথমিক বৃত্তি পরীক্ষা ২০২২', 'big' => 'শাহীন শিক্ষা পরিবার থেকে ট্যালেন্টপুলে ৩৭১ জন ও সাধারণ গ্রেডে ২৬৭ জন, মোট ৬৩৮ জন শিক্ষার্থীর বৃত্তি প্রাপ্তি।', 'd' => '', 'img' => '' ),
				array( 'ic' => '🏆', 't' => 'প্রাথমিক সমাপনী পরীক্ষায়', 'big' => '২০০৪, ২০০৮, ২০০৯ ও ২০১০ সালে সারা বাংলাদেশে প্রথম।', 'd' => '২০১১ সালে সম্মিলিত তৃতীয়। টাঙ্গাইল থেকে ১৯ বার প্রথম স্থান। এছাড়া গাজীপুর, বগুড়া ও ময়মনসিংহে প্রথম, আর ২০১০ ও ২০১১ সালে বরিশালে প্রথম স্থান।', 'img' => '' ),
				array( 'ic' => '📈', 't' => 'জেএসসি পরীক্ষায়', 'big' => '২০১৮ সালে শাহীন শিক্ষা পরিবারের ৩০৬ জন A+ পেয়ে শতভাগ পাস করে।', 'd' => 'সর্বশেষ বছর টাঙ্গাইলে আনুপাতিক A+ প্রাপ্তির হারে শাহীন স্কুল প্রথম। সর্বশেষ ময়মনসিংহ অঞ্চলে প্রথম ও দ্বিতীয় স্থান।', 'img' => '' ),
				array( 'ic' => '🎓', 't' => 'এসএসসি পরীক্ষায়', 'big' => 'শুধু টাঙ্গাইল থেকেই এ পর্যন্ত ১৩৫০ জন A+ পেয়েছে।', 'd' => 'গ্রেডিং পদ্ধতি চালুর প্রথম বছরে টাঙ্গাইলের একমাত্র A+ পেয়েছিল জিতু, দ্বিতীয় বছরে সুমি — দুজনই আমাদের শিক্ষার্থী।', 'img' => '' ),
				array( 'ic' => '✍️', 't' => 'হাতের লেখা প্রতিযোগিতায়', 'big' => '২০১১ সালে সারা বাংলাদেশে প্রথম হয়েছে শাহীন ক্যাডেট স্কুল টাঙ্গাইলের নাফিজা।', 'd' => '', 'img' => '' ),
			),
			'bt'    => 'ক্যাডেটের ফলাফল দেখুন →',
			'bu'    => '/cadet-result-new/',
		),
		'cadet'   => array(
			't'     => 'ক্যাডেট কোচিং ও সেরা সাফল্য',
			'p'     => 'এক বছরের কোচিংয়ে ক্যাডেট কলেজে চান্স — প্রস্তুতির বই ও অনলাইন কোচিং এক জায়গায়',
			'nums'  => array( array( 'n' => '৩৩৬', 'l' => '২০২৬ সালে লিখিত পরীক্ষায় কৃতকার্য' ) ),
			'bt'    => 'ফলাফল দেখুন →',
			'bu'    => '/cadet-result-new/',
			'cards' => array(
				array( 'ic' => '📚', 'img' => '', 't' => 'ক্যাডেট কলেজ ভর্তি গাইড', 'd' => 'আমাদের নিজস্ব প্রকাশনীর ক্যাডেট কলেজ ভর্তি গাইড সংগ্রহ করুন', 'bt' => 'বুক স্টোরে যান →', 'u' => 'https://store.shaheen.edu.bd/' ),
				array( 'ic' => '💻', 'img' => '', 't' => 'অনলাইন ক্যাডেট কোচিং', 'd' => 'ঘরে বসেই ক্যাডেট কলেজের প্রস্তুতি — অনলাইন প্রোগ্রামে ভর্তি হোন', 'bt' => 'ভর্তি হোন →', 'u' => 'https://cadetcoaching.shaheen.edu.bd/signup' ),
			),
		),
		'why'     => array(
			't'     => 'কেন শাহীন শিক্ষা পরিবার?',
			'p'     => '',
			'cards' => array(
				array( 'ic' => '❓', 'img' => '', 't' => 'শাহীন স্কুল ও ক্যাডেট কোচিংয়ে কেন ভর্তি হবেন?', 'd' => '', 'u' => 'https://shaheen.edu.bd/%e0%a6%95%e0%a7%87%e0%a6%a8-%e0%a6%ad%e0%a6%b0%e0%a7%8d%e0%a6%a4%e0%a6%bf-%e0%a6%b9%e0%a6%ac%e0%a7%87/' ),
				array( 'ic' => '🏛️', 'img' => '', 't' => 'শাহীন স্কুল ও ক্যাডেট কোচিংয়ের ক্যাম্পাস পরিচালনা পদ্ধতি', 'd' => '', 'u' => 'https://shaheen.edu.bd/%e0%a6%b8%e0%a7%8d%e0%a6%95%e0%a7%81%e0%a6%b2-%e0%a6%aa%e0%a6%b0%e0%a6%bf%e0%a6%9a%e0%a6%be%e0%a6%b2%e0%a6%a8%e0%a6%be-%e0%a6%aa%e0%a6%a6%e0%a7%8d%e0%a6%a7%e0%a6%a4%e0%a6%bf/' ),
				array( 'ic' => '✨', 'img' => '', 't' => 'শাহীন স্কুল ও ক্যাডেট কোচিংয়ের ব্যতিক্রমী দিকসমূহ', 'd' => '', 'u' => 'https://shaheen.edu.bd/%e0%a6%ac%e0%a7%8d%e0%a6%af%e0%a6%a4%e0%a6%bf%e0%a6%95%e0%a7%8d%e0%a6%b0%e0%a6%ae%e0%a7%80-%e0%a6%a6%e0%a6%bf%e0%a6%95%e0%a6%b8%e0%a6%ae%e0%a7%82%e0%a6%b9%e0%a6%83/' ),
			),
		),
		'ideal'   => array(
			't'     => 'সফলতার তিন স্তম্ভ',
			'p'     => 'সন্তানের সাফল্য আসে শিক্ষার্থী, অভিভাবক ও শিক্ষক — তিনজনের মিলিত চেষ্টায়',
			'cards' => array(
				array( 'ic' => '🎒', 'img' => '', 't' => 'আদর্শ শিক্ষার্থীর করণীয়', 'd' => 'লক্ষ্য ছুঁতে ও স্বপ্ন পূরণে যা জরুরি', 'u' => 'https://shaheen.edu.bd/%e0%a6%b6%e0%a6%bf%e0%a6%95%e0%a7%8d%e0%a6%b7%e0%a6%be%e0%a6%b0%e0%a7%8d%e0%a6%a5%e0%a7%80%e0%a6%b0-%e0%a6%95%e0%a6%b0%e0%a6%a3%e0%a7%80%e0%a7%9f/' ),
				array( 'ic' => '👨‍👩‍👧', 'img' => '', 't' => 'আদর্শ অভিভাবকের গুণাবলি', 'd' => 'সন্তানের সঠিক বিকাশের জন্য যা জানা ও করা উচিত', 'u' => 'https://shaheen.edu.bd/%e0%a6%85%e0%a6%ad%e0%a6%bf%e0%a6%ad%e0%a6%be%e0%a6%ac%e0%a6%95%e0%a7%87%e0%a6%b0-%e0%a6%95%e0%a6%b0%e0%a6%a3%e0%a7%80%e0%a7%9f/' ),
				array( 'ic' => '👩‍🏫', 'img' => '', 't' => 'আদর্শ শিক্ষকের পরিচয়', 'd' => 'পড়াশোনার মান বাড়াতে ও শিক্ষার্থীর আগ্রহ জাগাতে মূল কৌশল', 'u' => 'https://shaheen.edu.bd/%e0%a6%b6%e0%a6%bf%e0%a6%95%e0%a7%8d%e0%a6%b7%e0%a6%95%e0%a7%87%e0%a6%b0-%e0%a6%95%e0%a6%b0%e0%a6%a3%e0%a7%80%e0%a7%9f/' ),
			),
		),
		'blog'    => array( 't' => 'আমাদের ব্লগ থেকে', 'p' => 'শিক্ষা, ভর্তি ও পরীক্ষার প্রস্তুতি নিয়ে কাজের লেখা', 'n' => 3, 'bt' => 'সব লেখা পড়ুন →', 'bu' => '' ),
		'seo'     => array( 't' => 'শাহীন শিক্ষা পরিবার | ক্যাডেট কোচিং, স্কুল ও বৃত্তি প্রস্তুতি — বাংলাদেশ', 'd' => '৩৫ বছরের অভিজ্ঞতা, দেশজুড়ে {branches}টি ক্যাম্পাস। ক্যাডেট কলেজ ভর্তি কোচিং (২০২৬ সালে ৩৩৬ জন ক্যাডেট কলেজে লিখিত কৃতকার্য), স্কুল, প্রি-ক্যাডেট ও অনলাইন কোচিং। Spelling Star ও ক্ষুদে স্টারের আয়োজক।', 'k' => 'ক্যাডেট কোচিং' ),
		'cta'     => array( 't' => 'আপনার সন্তানের সোনালি ভবিষ্যৎ শুরু হোক আজই', 'p' => 'কাছের শাখায় যোগাযোগ করুন অথবা অনলাইনে ভর্তি হোন', 'b1' => array( 't' => '📍 শাখা খুঁজুন', 'u' => '/branch/' ), 'b2' => array( 't' => '💻 অনলাইনে ভর্তি হোন', 'u' => 'https://cadetcoaching.shaheen.edu.bd/signup' ), 'phone' => '+880 1622-222111', 'mail' => 'corporate@shaheen.edu.bd' ),
	);
}

function csa_ev_defaults() {
	return array(
		'home'     => csa_ev_home_defaults(),
		'home_id'  => 0,
		'old_front' => null,
		'live'     => 0,
		'roles'    => array( 'editor' ),
		'next'     => 1,
	);
}

function csa_ev_opts() {
	$o = get_option( CSA_EV_OPT, array() );
	$o = is_array( $o ) ? $o : array();
	$d = csa_ev_defaults();
	foreach ( $d as $k => $v ) {
		if ( ! isset( $o[ $k ] ) ) {
			$o[ $k ] = $v;
		}
	}
	// a section the owner has never touched keeps the default text; a key added later in an update is filled in
	foreach ( $d['home'] as $k => $v ) {
		if ( ! isset( $o['home'][ $k ] ) ) {
			$o['home'][ $k ] = $v;
		}
	}
	return $o;
}

function csa_ev_opt( $k ) {
	return csa_ev_opts()[ $k ] ?? null;
}

function csa_ev_set( $o ) {
	update_option( CSA_EV_OPT, $o, false );
	csa_ev_purge();
}

function csa_ev_list() {
	$l = get_option( CSA_EV_LIST, array() );
	return is_array( $l ) ? array_values( $l ) : array();
}

function csa_ev_put( $l ) {
	update_option( CSA_EV_LIST, array_values( $l ), false );
	csa_ev_purge();
}

function csa_ev_get( $id ) {
	foreach ( csa_ev_list() as $e ) {
		if ( (int) $e['id'] === (int) $id ) {
			return $e;
		}
	}
	return null;
}

function csa_ev_can() {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	$u = wp_get_current_user();
	return $u && $u->exists() && array_intersect( (array) $u->roles, (array) csa_ev_opt( 'roles' ) );
}

/** Pages are cached by the host: clear them after every change. */
function csa_ev_purge() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	if ( function_exists( 'csa_clear_cache' ) ) {
		$o = get_option( CSA_EV_OPT, array() );
		if ( ! empty( $o['home_id'] ) ) {
			csa_clear_cache( (int) $o['home_id'] );
		}
		foreach ( csa_ev_list() as $e ) {
			if ( ! empty( $e['page'] ) ) {
				csa_clear_cache( (int) $e['page'] );
			}
		}
		$fp = (int) get_option( 'page_on_front' );
		if ( $fp ) {
			csa_clear_cache( $fp );
		}
	}
	do_action( 'litespeed_purge_all' );
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
}

/* ---------- small helpers ---------- */
function csa_ev_bn( $s ) {
	return strtr( (string) $s, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
}

function csa_ev_date( $ymd ) {
	$ts = $ymd ? strtotime( $ymd . ' 12:00:00' ) : 0;
	if ( ! $ts ) {
		return '';
	}
	$m = array( 1 => 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর' );
	return csa_ev_bn( (int) gmdate( 'j', $ts ) ) . ' ' . $m[ (int) gmdate( 'n', $ts ) ] . ' ' . csa_ev_bn( gmdate( 'Y', $ts ) );
}

function csa_ev_today() {
	return wp_date( 'Y-m-d' );
}

function csa_ev_url( $u ) {
	$u = trim( (string) $u );
	if ( '' === $u ) {
		return '';
	}
	if ( '/' === $u[0] && ( ! isset( $u[1] ) || '/' !== $u[1] ) ) {
		return home_url( $u );
	}
	return $u;
}

function csa_ev_clean_url( $u ) {
	$u = trim( (string) $u );
	if ( '' !== $u && '/' === $u[0] && ( ! isset( $u[1] ) || '/' !== $u[1] ) ) {
		return '/' . ltrim( preg_replace( '/[^A-Za-z0-9_\-\/%.?=&#]/', '', $u ), '/' ); // a path on this site
	}
	return esc_url_raw( $u );
}

function csa_ev_hex( $c, $d ) {
	$c = trim( (string) $c );
	return preg_match( '/^#[0-9a-fA-F]{6}$/', $c ) ? strtolower( $c ) : $d;
}

function csa_ev_txt( $s ) {
	return sanitize_text_field( (string) $s );
}

function csa_ev_area( $s ) {
	return sanitize_textarea_field( (string) $s );
}

function csa_ev_ymd( $s ) {
	$s = trim( (string) $s );
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) ? $s : '';
}

/** YouTube video id from any YouTube link, '' otherwise. */
function csa_ev_yt( $u ) {
	if ( preg_match( '#(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{11})#', (string) $u, $m ) ) {
		return $m[1];
	}
	return '';
}

function csa_ev_is_fb( $u ) {
	return (bool) preg_match( '#(facebook\.com|fb\.watch|fb\.com)#i', (string) $u );
}

/* ---------- Event record: clean + defaults ---------- */
function csa_ev_blank() {
	return array(
		'id' => 0, 'slug' => '', 'page' => 0, 'ext' => '',
		'title' => '', 'full' => '', 'card_more' => 1, 'series' => '', 'season' => '', 'tagline' => '', 'badge' => '', 'card' => '',
		'logo' => '', 'cover' => '', 'c1' => '#0d2e1c', 'c2' => '#1a6b3e', 'ac' => '#f5a623', 'font' => 'baloo',
		'show_home' => 1, 'order' => 10, 'home_from' => '', 'home_to' => '',
		'facts' => array(), 'blocks' => array(),
		'prizes' => array( 'on' => 0, 't' => 'পুরস্কারসমূহ', 'items' => array(), 'note' => '' ),
		'tv' => array( 'on' => 0, 't' => '', 'p' => '', 'ch' => '', 'img' => '' ),
		'org' => array( 'name' => '', 'logo' => '', 'url' => '' ),
		'partners' => array(), 'miles' => array(), 'btns' => array(), 'eps' => array(), 'vsec' => array(), 'gal' => array(),
		'nq' => '', 'links' => array(), 'seo' => '', 'foot' => '',
	);
}

function csa_ev_clean( $in, $keep = null ) {
	$b = csa_ev_blank();
	$e = array();
	$e['id']        = (int) ( $in['id'] ?? 0 );
	$e['slug']      = sanitize_title( (string) ( $in['slug'] ?? '' ) );
	$e['page']      = (int) ( $in['page'] ?? ( $keep['page'] ?? 0 ) );
	$e['ext']       = csa_ev_clean_url( $in['ext'] ?? '' );
	foreach ( array( 'title', 'full', 'series', 'season', 'tagline', 'badge', 'card', 'nq', 'foot', 'seo' ) as $k ) {
		$e[ $k ] = csa_ev_txt( $in[ $k ] ?? '' );
	}
	$e['logo']      = esc_url_raw( (string) ( $in['logo'] ?? '' ) );
	$e['cover']     = esc_url_raw( (string) ( $in['cover'] ?? '' ) );
	$e['c1']        = csa_ev_hex( $in['c1'] ?? '', $b['c1'] );
	$e['c2']        = csa_ev_hex( $in['c2'] ?? '', $b['c2'] );
	$e['ac']        = csa_ev_hex( $in['ac'] ?? '', $b['ac'] );
	$e['font']      = ( ! empty( $in['font'] ) && function_exists( 'csa_font' ) && csa_font( sanitize_key( $in['font'] ) ) ) ? sanitize_key( $in['font'] ) : 'baloo';
	$e['show_home'] = empty( $in['show_home'] ) ? 0 : 1;
	$e['card_more'] = empty( $in['card_more'] ) ? 0 : 1;
	$e['order']     = (int) ( $in['order'] ?? 10 );
	$e['home_from'] = csa_ev_ymd( $in['home_from'] ?? '' );
	$e['home_to']   = csa_ev_ymd( $in['home_to'] ?? '' );
	$e['facts']     = array();
	foreach ( (array) ( $in['facts'] ?? array() ) as $f ) {
		if ( is_array( $f ) && '' !== trim( ( $f['l'] ?? '' ) . ( $f['v'] ?? '' ) ) ) {
			$e['facts'][] = array( 'ic' => csa_ev_txt( $f['ic'] ?? '' ), 'l' => csa_ev_txt( $f['l'] ?? '' ), 'v' => csa_ev_txt( $f['v'] ?? '' ) );
		}
	}
	$e['blocks'] = array();
	foreach ( (array) ( $in['blocks'] ?? array() ) as $f ) {
		if ( is_array( $f ) && '' !== trim( ( $f['t'] ?? '' ) . ( $f['d'] ?? '' ) ) ) {
			$e['blocks'][] = array( 'ic' => csa_ev_txt( $f['ic'] ?? '' ), 't' => csa_ev_txt( $f['t'] ?? '' ), 'd' => csa_ev_area( $f['d'] ?? '' ), 'img' => esc_url_raw( (string) ( $f['img'] ?? '' ) ) );
		}
	}
	$p           = (array) ( $in['prizes'] ?? array() );
	$e['prizes'] = array( 'on' => empty( $p['on'] ) ? 0 : 1, 't' => csa_ev_txt( $p['t'] ?? '' ), 'items' => array(), 'note' => csa_ev_txt( $p['note'] ?? '' ) );
	foreach ( (array) ( $p['items'] ?? array() ) as $f ) {
		if ( is_array( $f ) && '' !== trim( ( $f['l'] ?? '' ) . ( $f['v'] ?? '' ) ) ) {
			$e['prizes']['items'][] = array( 'l' => csa_ev_txt( $f['l'] ?? '' ), 'v' => csa_ev_txt( $f['v'] ?? '' ) );
		}
	}
	$t        = (array) ( $in['tv'] ?? array() );
	$e['tv']  = array( 'on' => empty( $t['on'] ) ? 0 : 1, 't' => csa_ev_txt( $t['t'] ?? '' ), 'p' => csa_ev_area( $t['p'] ?? '' ), 'ch' => csa_ev_txt( $t['ch'] ?? '' ), 'img' => esc_url_raw( (string) ( $t['img'] ?? '' ) ) );
	$g        = (array) ( $in['org'] ?? array() );
	$e['org'] = array( 'name' => csa_ev_txt( $g['name'] ?? '' ), 'logo' => esc_url_raw( (string) ( $g['logo'] ?? '' ) ), 'url' => csa_ev_clean_url( $g['url'] ?? '' ) );
	$e['partners'] = array();
	foreach ( (array) ( $in['partners'] ?? array() ) as $f ) {
		if ( is_array( $f ) && '' !== trim( ( $f['name'] ?? '' ) . ( $f['logo'] ?? '' ) ) ) {
			$e['partners'][] = array( 'name' => csa_ev_txt( $f['name'] ?? '' ), 'role' => csa_ev_txt( $f['role'] ?? '' ), 'logo' => esc_url_raw( (string) ( $f['logo'] ?? '' ) ), 'url' => csa_ev_clean_url( $f['url'] ?? '' ) );
		}
	}
	$e['miles'] = array();
	foreach ( (array) ( $in['miles'] ?? array() ) as $f ) {
		if ( is_array( $f ) && '' !== trim( $f['t'] ?? '' ) ) {
			$st           = in_array( $f['st'] ?? '', array( 'live', 'done', 'soon' ), true ) ? $f['st'] : '';
			$e['miles'][] = array( 'ic' => csa_ev_txt( $f['ic'] ?? '' ), 't' => csa_ev_txt( $f['t'] ), 'd1' => csa_ev_ymd( $f['d1'] ?? '' ), 'd2' => csa_ev_ymd( $f['d2'] ?? '' ), 'note' => csa_ev_area( $f['note'] ?? '' ), 'st' => $st, 'lbl' => csa_ev_txt( $f['lbl'] ?? '' ) );
		}
	}
	$e['btns'] = array();
	foreach ( (array) ( $in['btns'] ?? array() ) as $f ) {
		if ( is_array( $f ) && '' !== trim( $f['t'] ?? '' ) ) {
			$e['btns'][] = array( 't' => csa_ev_txt( $f['t'] ), 'u' => csa_ev_clean_url( $f['u'] ?? '' ), 'k' => in_array( $f['k'] ?? '', array( 'p', 's', 'g' ), true ) ? $f['k'] : 'p', 'until' => csa_ev_ymd( $f['until'] ?? '' ), 'home' => empty( $f['home'] ) ? 0 : 1 );
		}
	}
	$e['eps'] = array();
	foreach ( (array) ( $in['eps'] ?? array() ) as $f ) {
		if ( is_array( $f ) && '' !== trim( ( $f['t'] ?? '' ) . ( $f['u'] ?? '' ) ) ) {
			$e['eps'][] = array( 'n' => csa_ev_txt( $f['n'] ?? '' ), 't' => csa_ev_txt( $f['t'] ?? '' ), 'u' => esc_url_raw( (string) ( $f['u'] ?? '' ) ), 'd' => csa_ev_ymd( $f['d'] ?? '' ), 'img' => esc_url_raw( (string) ( $f['img'] ?? '' ) ) );
		}
	}
	$e['vsec'] = array();
	foreach ( (array) ( $in['vsec'] ?? array() ) as $f ) {
		if ( is_array( $f ) && '' !== trim( ( $f['t'] ?? '' ) . ( $f['lines'] ?? '' ) ) ) {
			$e['vsec'][] = array( 'ic' => csa_ev_txt( $f['ic'] ?? '' ), 't' => csa_ev_txt( $f['t'] ?? '' ), 'p' => csa_ev_txt( $f['p'] ?? '' ), 'lines' => csa_ev_area( $f['lines'] ?? '' ) );
		}
	}
	$e['gal'] = array();
	foreach ( (array) ( $in['gal'] ?? array() ) as $f ) {
		if ( is_array( $f ) && '' !== trim( ( $f['img'] ?? '' ) . ( $f['u'] ?? '' ) . ( $f['t'] ?? '' ) ) ) {
			$e['gal'][] = array( 'k' => in_array( $f['k'] ?? '', array( 'photo', 'post', 'video' ), true ) ? $f['k'] : 'photo', 't' => csa_ev_txt( $f['t'] ?? '' ), 'u' => esc_url_raw( (string) ( $f['u'] ?? '' ) ), 'img' => esc_url_raw( (string) ( $f['img'] ?? '' ) ), 'tag' => csa_ev_txt( $f['tag'] ?? '' ) );
		}
	}
	$e['links'] = array_values( array_filter( array_map( 'intval', (array) ( $in['links'] ?? array() ) ) ) );
	return $e;
}

/** Merge the saved record over the blank one so older records never miss a key. */
function csa_ev_full( $e ) {
	$b = csa_ev_blank();
	$e = array_merge( $b, (array) $e );
	foreach ( array( 'prizes', 'tv', 'org' ) as $k ) {
		$e[ $k ] = array_merge( $b[ $k ], (array) $e[ $k ] );
	}
	return $e;
}

/* ---------- Status from the milestones (automatic, with manual override) ---------- */
function csa_ev_miles( $e ) {
	$today = csa_ev_today();
	$out   = array();
	foreach ( (array) $e['miles'] as $m ) {
		$d1 = $m['d1'];
		$d2 = $m['d2'] ? $m['d2'] : $d1;
		if ( $m['st'] ) {
			$s = $m['st'];
		} elseif ( ! $d1 ) {
			$s = 'soon';
		} elseif ( $today < $d1 ) {
			$s = 'soon';
		} elseif ( $today > $d2 ) {
			$s = 'done';
		} else {
			$s = 'live';
		}
		$m['s']  = $s;
		$m['dt'] = $d1 ? ( $d2 && $d2 !== $d1 ? csa_ev_date( $d1 ) . ' – ' . csa_ev_date( $d2 ) : csa_ev_date( $d1 ) ) : '';
		$out[]   = $m;
	}
	return $out;
}

/** [label, class, next-date or ''] : the badge shown on the card and the page. */
function csa_ev_stage( $e ) {
	if ( '' !== $e['badge'] ) {
		return array( $e['badge'], 'live', '' );
	}
	$ms   = csa_ev_miles( $e );
	$live = null;
	$soon = null;
	foreach ( $ms as $m ) {
		if ( 'live' === $m['s'] ) {
			$live = $m;
		}
		if ( 'soon' === $m['s'] && ! $soon ) {
			$soon = $m;
		}
	}
	if ( $live ) {
		return array( '● ' . ( $live['lbl'] ? $live['lbl'] : $live['t'] . ' চলছে' ), 'live', '' );
	}
	if ( $soon ) {
		return array( 'আসছে: ' . $soon['t'], 'soon', $soon['d1'] );
	}
	if ( $ms ) {
		return array( 'সম্পন্ন', 'done', '' );
	}
	return array( '', '', '' );
}

function csa_ev_btns( $e, $home = false ) {
	$today = csa_ev_today();
	$out   = array();
	foreach ( (array) $e['btns'] as $b ) {
		if ( '' === $b['u'] || ( $b['until'] && $today > $b['until'] ) ) {
			continue;
		}
		if ( $home && empty( $b['home'] ) ) {
			continue;
		}
		$out[] = $b;
	}
	return $out;
}

/* ---------- Event pages ---------- */
/** Full name of an event: the owner's own full name, else title + season. */
function csa_ev_fullname( $e ) {
	return '' !== trim( (string) ( $e['full'] ?? '' ) ) ? $e['full'] : trim( $e['title'] . ( $e['season'] ? ' ' . $e['season'] : '' ) );
}

/** {branches} in any text becomes the live number of branches (Bangla digits). */
function csa_ev_tok( $t ) {
	if ( false === strpos( (string) $t, '{branches}' ) ) {
		return $t;
	}
	$n = function_exists( 'csa_br_list' ) ? count( csa_br_list() ) : 0;
	return str_replace( '{branches}', csa_ev_bn( $n ? $n : 150 ), $t );
}

function csa_ev_page_url( $e ) {
	if ( $e['ext'] ) {
		return csa_ev_url( $e['ext'] );
	}
	if ( $e['page'] ) {
		$st = get_post_status( $e['page'] );
		if ( 'publish' === $st ) {
			return get_permalink( $e['page'] );
		}
		if ( $st ) {
			return get_preview_post_link( $e['page'] );
		}
	}
	return '';
}

function csa_ev_page_live( $e ) {
	return $e['ext'] ? true : ( $e['page'] && 'publish' === get_post_status( $e['page'] ) );
}

/** Create (or repair) the WordPress page that shows this event; it stays a draft until the owner publishes. */
function csa_ev_ensure_page( &$e ) {
	if ( $e['ext'] ) {
		return;
	}
	$pid = (int) $e['page'];
	if ( $pid && get_post( $pid ) && 'trash' !== get_post_status( $pid ) ) {
		wp_update_post( array( 'ID' => $pid, 'post_title' => csa_ev_fullname( $e ), 'post_content' => '[shaheen_event id="' . (int) $e['id'] . '"]' ) );
		update_post_meta( $pid, '_csa_ev', (int) $e['id'] );
		return;
	}
	$slug = $e['slug'] ? $e['slug'] : sanitize_title( $e['title'] );
	if ( '' === $slug ) {
		$slug = 'event-' . (int) $e['id'];
	}
	$pid = wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'draft',
		'post_title'   => csa_ev_fullname( $e ),
		'post_name'    => wp_unique_post_slug( $slug, 0, 'draft', 'page', 0 ),
		'post_content' => '[shaheen_event id="' . (int) $e['id'] . '"]',
	) );
	if ( $pid && ! is_wp_error( $pid ) ) {
		$e['page'] = (int) $pid;
		update_post_meta( $pid, '_csa_ev', (int) $e['id'] );
	}
}

function csa_ev_page_event_id( $post_id ) {
	return (int) get_post_meta( (int) $post_id, '_csa_ev', true );
}

function csa_ev_is_home_page( $post_id ) {
	return $post_id && (int) $post_id === (int) csa_ev_opt( 'home_id' );
}

add_filter( 'template_include', function ( $tpl ) {
	if ( ! is_page() || wp_is_block_theme() || ! empty( $_GET['snr'] ) ) {
		return $tpl;
	}
	$id = (int) get_queried_object_id();
	if ( csa_ev_page_event_id( $id ) || csa_ev_is_home_page( $id ) ) {
		return CSA_DIR . 'includes/events-template.php';
	}
	return $tpl;
}, 99 );

add_filter( 'the_content', function ( $c ) {
	if ( ! is_page() || ! in_the_loop() || ! is_main_query() || ! empty( $GLOBALS['csa_ev_done'] ) ) {
		return $c;
	}
	if ( ! empty( $_GET['snr'] ) && function_exists( 'csa_sn_read_ok' ) && csa_sn_read_ok( sanitize_text_field( wp_unslash( $_GET['snr'] ) ) ) ) {
		return $c;
	}
	$id = (int) get_the_ID();
	if ( csa_ev_is_home_page( $id ) ) {
		return csa_ev_home_html();
	}
	$ev = csa_ev_page_event_id( $id );
	if ( $ev ) {
		$e = csa_ev_get( $ev );
		return $e ? csa_ev_event_html( csa_ev_full( $e ) ) : $c;
	}
	return $c;
}, 30 );

add_shortcode( 'shaheen_event', function ( $a ) {
	$a = shortcode_atts( array( 'id' => 0 ), $a );
	$e = csa_ev_get( (int) $a['id'] );
	$GLOBALS['csa_ev_done'] = true;
	return $e ? csa_ev_event_html( csa_ev_full( $e ) ) : '';
} );

add_shortcode( 'shaheen_home', function () {
	$GLOBALS['csa_ev_done'] = true;
	return csa_ev_home_html();
} );

add_shortcode( 'shaheen_events', function ( $a ) {
	$a = shortcode_atts( array( 'max' => 6 ), $a );
	return csa_ev_assets_tag() . csa_ev_events_strip( (int) $a['max'], array() );
} );

/** Keep Yoast / SEO fields and the page title sensible; add a meta description from the event. */
add_filter( 'wpseo_metadesc', function ( $d ) {
	if ( is_page() ) {
		$ev = csa_ev_page_event_id( get_queried_object_id() );
		if ( $ev && ( $e = csa_ev_get( $ev ) ) && ! empty( $e['seo'] ) && '' === (string) $d ) {
			return $e['seo'];
		}
	}
	return $d;
} );

add_action( 'wp_head', function () {
	if ( ! is_page() ) {
		return;
	}
	$ev = csa_ev_page_event_id( get_queried_object_id() );
	if ( $ev && ( $e = csa_ev_get( $ev ) ) && ! empty( $e['seo'] ) && ! defined( 'WPSEO_VERSION' ) ) {
		echo '<meta name="description" content="' . esc_attr( $e['seo'] ) . '">' . "\n";
	}
}, 6 );


/* ---------- SEO: structured data (Event / Organization) + home title & description ---------- */
function csa_ev_jsonld( $d ) {
	echo '<script type="application/ld+json">' . wp_json_encode( $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
}

add_action( 'wp_head', function () {
	if ( ! is_page() ) {
		return;
	}
	$id = (int) get_queried_object_id();
	$ev = csa_ev_page_event_id( $id );
	if ( $ev && ( $e = csa_ev_get( $ev ) ) ) {
		$e     = csa_ev_full( $e );
		$dates = array();
		foreach ( (array) $e['miles'] as $m ) {
			foreach ( array( 'd1', 'd2' ) as $k ) {
				if ( ! empty( $m[ $k ] ) ) {
					$dates[] = $m[ $k ];
				}
			}
		}
		sort( $dates );
		$d = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'name'                => csa_ev_fullname( $e ),
			'description'         => $e['seo'] ? $e['seo'] : ( $e['tagline'] ? $e['tagline'] : csa_ev_fullname( $e ) ),
			'url'                 => get_permalink( $id ),
			'eventAttendanceMode' => 'https://schema.org/MixedEventAttendanceMode',
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'location'            => array( '@type' => 'VirtualLocation', 'url' => get_permalink( $id ) ),
			'organizer'           => array( '@type' => 'Organization', 'name' => ! empty( $e['org']['name'] ) ? $e['org']['name'] : 'শাহীন শিক্ষা পরিবার', 'url' => home_url( '/' ) ),
		);
		if ( $dates ) {
			$d['startDate'] = $dates[0];
			$d['endDate']   = end( $dates );
		}
		if ( $e['logo'] ) {
			$d['image'] = $e['logo'];
		}
		if ( $dates ) {
			csa_ev_jsonld( $d );
		}
	} elseif ( csa_ev_is_home_page( $id ) ) {
		csa_ev_jsonld( array(
			'@context'    => 'https://schema.org',
			'@type'       => 'EducationalOrganization',
			'name'        => 'শাহীন শিক্ষা পরিবার',
			'alternateName' => 'Shaheen Education Family',
			'url'         => home_url( '/' ),
		) );
	}
}, 7 );

/** The home page's own title and description (set in the panel) — also pushed into Yoast fields. */
function csa_ev_home_seo() {
	$o = csa_ev_opts();
	$x = (array) ( $o['home']['seo'] ?? array() );
	return array( 't' => csa_ev_tok( $x['t'] ?? '' ), 'd' => csa_ev_tok( $x['d'] ?? '' ), 'k' => $x['k'] ?? '' );
}

function csa_ev_home_seo_sync() {
	$pid = (int) csa_ev_opt( 'home_id' );
	if ( ! $pid || ! get_post( $pid ) ) {
		return;
	}
	$x = csa_ev_home_seo();
	update_post_meta( $pid, '_yoast_wpseo_title', $x['t'] );
	update_post_meta( $pid, '_yoast_wpseo_metadesc', $x['d'] );
	update_post_meta( $pid, '_yoast_wpseo_focuskw', $x['k'] );
}

add_filter( 'pre_get_document_title', function ( $t ) {
	if ( is_page() && csa_ev_is_home_page( get_queried_object_id() ) && ! defined( 'WPSEO_VERSION' ) ) {
		$x = csa_ev_home_seo();
		return $x['t'] ? $x['t'] : $t;
	}
	return $t;
}, 20 );

add_action( 'wp_head', function () {
	if ( is_page() && csa_ev_is_home_page( get_queried_object_id() ) && ! defined( 'WPSEO_VERSION' ) ) {
		$x = csa_ev_home_seo();
		if ( $x['d'] ) {
			echo '<meta name="description" content="' . esc_attr( $x['d'] ) . '">' . "\n";
		}
	}
}, 6 );

/* ---------- Front assets ---------- */
function csa_ev_is_front() {
	if ( ! is_page() ) {
		return false;
	}
	$id = (int) get_queried_object_id();
	return csa_ev_page_event_id( $id ) || csa_ev_is_home_page( $id );
}

function csa_ev_fonts_url( $e = null ) {
	$fam = array( 'family=Hind+Siliguri:wght@400;500;600;700', 'family=Baloo+Da+2:wght@500;600;700;800' );
	if ( $e && $e['font'] && function_exists( 'csa_font' ) && 'baloo' !== $e['font'] && 'hind' !== $e['font'] ) {
		$f = csa_font( $e['font'] );
		if ( $f ) {
			$fam[] = 'family=' . str_replace( ' ', '+', $f[0] ) . ':wght@' . $f[1];
		}
	}
	return 'https://fonts.googleapis.com/css2?' . implode( '&', $fam ) . '&display=swap';
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! csa_ev_is_front() ) {
		return;
	}
	$e = null;
	$id = csa_ev_page_event_id( get_queried_object_id() );
	if ( $id && ( $x = csa_ev_get( $id ) ) ) {
		$e = csa_ev_full( $x );
	}
	wp_enqueue_style( 'csa-ev-fonts', csa_ev_fonts_url( $e ), array(), null );
	wp_enqueue_style( 'csa-ev', CSA_URL . 'assets/events.css', array(), CSA_VER );
	wp_enqueue_script( 'csa-ev', CSA_URL . 'assets/events.js', array(), CSA_VER, true );
}, 30 );

/** Fallback when the shortcode is used on another page: the CSS and script are printed right where they are needed. */
function csa_ev_assets_tag() {
	static $done = false;
	if ( $done || csa_ev_is_front() ) {
		return '';
	}
	$done = true;
	return '<link rel="stylesheet" href="' . esc_url( csa_ev_fonts_url() ) . '"><link rel="stylesheet" href="' . esc_url( CSA_URL . 'assets/events.css?ver=' . CSA_VER ) . '"><script src="' . esc_url( CSA_URL . 'assets/events.js?ver=' . CSA_VER ) . '" defer></script>';
}

/* ---------- Home page: draft page, preview, go live, go back ---------- */
function csa_ev_home_ensure() {
	$o   = csa_ev_opts();
	$pid = (int) $o['home_id'];
	if ( $pid && get_post( $pid ) && 'trash' !== get_post_status( $pid ) ) {
		return $pid;
	}
	$pid = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'নতুন হোম পেজ', 'post_name' => wp_unique_post_slug( 'new-home', 0, 'draft', 'page', 0 ), 'post_content' => '[shaheen_home]' ) );
	if ( $pid && ! is_wp_error( $pid ) ) {
		update_post_meta( $pid, '_csa_home', 1 );
		$o['home_id'] = (int) $pid;
		csa_ev_set( $o );
		return (int) $pid;
	}
	return 0;
}

function csa_ev_home_state() {
	$o   = csa_ev_opts();
	$pid = (int) $o['home_id'];
	return array(
		'id'      => $pid,
		'exists'  => $pid && get_post( $pid ),
		'preview' => $pid ? get_preview_post_link( $pid ) : '',
		'live'    => $pid && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $pid,
		'old'     => $o['old_front'],
		'oldt'    => ( $o['old_front'] && ! empty( $o['old_front']['id'] ) ) ? get_the_title( (int) $o['old_front']['id'] ) : '',
		'cur'     => (int) get_option( 'page_on_front' ),
		'curt'    => get_option( 'page_on_front' ) ? get_the_title( (int) get_option( 'page_on_front' ) ) : '',
	);
}

function csa_ev_go_live() {
	$pid = csa_ev_home_ensure();
	if ( ! $pid ) {
		return 'পেজ বানানো যায়নি।';
	}
	$o = csa_ev_opts();
	if ( (int) get_option( 'page_on_front' ) !== $pid ) {
		$o['old_front'] = array( 'mode' => (string) get_option( 'show_on_front' ), 'id' => (int) get_option( 'page_on_front' ) );
	}
	wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish' ) );
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $pid );
	$o['live'] = 1;
	csa_ev_set( $o );
	return '';
}

function csa_ev_go_back() {
	$o = csa_ev_opts();
	$u = $o['old_front'];
	if ( ! is_array( $u ) ) {
		return 'আগের হোম পেজের তথ্য নেই।';
	}
	update_option( 'show_on_front', 'page' === $u['mode'] ? 'page' : 'posts' );
	update_option( 'page_on_front', (int) $u['id'] );
	$o['live'] = 0;
	csa_ev_set( $o );
	return '';
}

/* ---------- Home options: clean ---------- */
function csa_ev_clean_home( $in ) {
	$d = csa_ev_home_defaults();
	$h = array();
	$sec = array_keys( array_diff_key( $d, array( 'order' => 1, 'off' => 1 ) ) );
	$h['order'] = array();
	foreach ( (array) ( $in['order'] ?? array() ) as $k ) {
		$k = sanitize_key( $k );
		if ( in_array( $k, $d['order'], true ) && ! in_array( $k, $h['order'], true ) ) {
			$h['order'][] = $k;
		}
	}
	foreach ( $d['order'] as $k ) {
		if ( ! in_array( $k, $h['order'], true ) ) {
			$h['order'][] = $k;
		}
	}
	$h['off'] = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['off'] ?? array() ) ), $d['order'] ) );
	$lnk = function ( $x ) {
		return array( 't' => csa_ev_txt( $x['t'] ?? '' ), 'u' => csa_ev_clean_url( $x['u'] ?? '' ) );
	};
	$cards = function ( $arr, $f ) {
		$o = array();
		foreach ( (array) $arr as $c ) {
			if ( ! is_array( $c ) || '' === trim( ( $c['t'] ?? '' ) . ( $c['d'] ?? '' ) ) ) {
				continue;
			}
			$r = array();
			foreach ( $f as $k => $type ) {
				$v       = $c[ $k ] ?? '';
				$r[ $k ] = 'u' === $type ? csa_ev_clean_url( $v ) : ( 'i' === $type ? esc_url_raw( (string) $v ) : ( 'a' === $type ? csa_ev_area( $v ) : csa_ev_txt( $v ) ) );
			}
			$o[] = $r;
		}
		return $o;
	};
	$hr          = (array) ( $in['hero'] ?? array() );
	$st          = array();
	foreach ( (array) ( $hr['stats'] ?? array() ) as $s ) {
		if ( is_array( $s ) && '' !== trim( ( $s['n'] ?? '' ) . ( $s['l'] ?? '' ) ) ) {
			$st[] = array( 'n' => csa_ev_txt( $s['n'] ?? '' ), 'l' => csa_ev_txt( $s['l'] ?? '' ) );
		}
	}
	$h['hero'] = array( 'kicker' => csa_ev_txt( $hr['kicker'] ?? '' ), 'h1a' => csa_ev_txt( $hr['h1a'] ?? '' ), 'h1b' => csa_ev_txt( $hr['h1b'] ?? '' ), 'sub' => csa_ev_txt( $hr['sub'] ?? '' ), 'b1' => $lnk( (array) ( $hr['b1'] ?? array() ) ), 'b2' => $lnk( (array) ( $hr['b2'] ?? array() ) ), 'img' => esc_url_raw( (string) ( $hr['img'] ?? '' ) ), 'stats' => $st );
	$x         = (array) ( $in['events'] ?? array() );
	$h['events'] = array( 't' => csa_ev_txt( $x['t'] ?? '' ), 'p' => csa_ev_txt( $x['p'] ?? '' ), 'max' => max( 1, min( 12, (int) ( $x['max'] ?? 6 ) ) ) );
	$x         = (array) ( $in['notice'] ?? array() );
	$h['notice'] = array( 't' => csa_ev_txt( $x['t'] ?? '' ), 'p' => csa_ev_txt( $x['p'] ?? '' ), 'n' => max( 1, min( 12, (int) ( $x['n'] ?? 5 ) ) ), 'bt' => csa_ev_txt( $x['bt'] ?? '' ), 'bu' => csa_ev_clean_url( $x['bu'] ?? '' ) );
	$x         = (array) ( $in['act'] ?? array() );
	$bars = array();
	foreach ( (array) ( $x['bars'] ?? array() ) as $q ) {
		if ( is_array( $q ) && '' !== trim( (string) ( $q['t'] ?? '' ) ) ) {
			$bars[] = array( 'ic' => csa_ev_txt( $q['ic'] ?? '' ), 't' => csa_ev_txt( $q['t'] ?? '' ), 'u' => csa_ev_clean_url( $q['u'] ?? '' ), 'c' => 'y' === ( $q['c'] ?? '' ) ? 'y' : 'g' );
		}
	}
	$h['act']  = array( 'bars' => $bars, 't' => csa_ev_txt( $x['t'] ?? '' ), 'p' => csa_ev_txt( $x['p'] ?? '' ), 'cards' => $cards( $x['cards'] ?? array(), array( 'ic' => 's', 'img' => 'i', 't' => 's', 'd' => 's', 'u' => 'u', 'tag' => 's' ) ) );
	$x         = (array) ( $in['branch'] ?? array() );
	$h['branch'] = array( 't' => csa_ev_txt( $x['t'] ?? '' ), 'p' => csa_ev_txt( $x['p'] ?? '' ), 'bt' => csa_ev_txt( $x['bt'] ?? '' ), 'bu' => csa_ev_clean_url( $x['bu'] ?? '' ), 'map' => csa_ev_clean_url( $x['map'] ?? '' ) );
	foreach ( array( 'y1', 'y2', 'yn', 'yl', 'n4', 'n4l', 'n4s' ) as $k ) {
		$h['branch'][ $k ] = csa_ev_txt( $x[ $k ] ?? '' );
	}
	$x         = (array) ( $in['ach'] ?? array() );
	$nums      = array();
	foreach ( (array) ( $x['nums'] ?? array() ) as $s ) {
		if ( is_array( $s ) && '' !== trim( ( $s['n'] ?? '' ) . ( $s['l'] ?? '' ) ) ) {
			$nums[] = array( 'n' => csa_ev_txt( $s['n'] ?? '' ), 'l' => csa_ev_txt( $s['l'] ?? '' ) );
		}
	}
	$h['ach']  = array( 't' => csa_ev_txt( $x['t'] ?? '' ), 'p' => csa_ev_txt( $x['p'] ?? '' ), 'nums' => $nums, 'items' => $cards( $x['items'] ?? array(), array( 'ic' => 's', 't' => 's', 'big' => 's', 'd' => 'a', 'img' => 'i' ) ), 'bt' => csa_ev_txt( $x['bt'] ?? '' ), 'bu' => csa_ev_clean_url( $x['bu'] ?? '' ) );
	$x         = (array) ( $in['cadet'] ?? array() );
	$cn = array();
	foreach ( (array) ( $x['nums'] ?? array() ) as $q ) {
		if ( is_array( $q ) && '' !== trim( ( $q['n'] ?? '' ) . ( $q['l'] ?? '' ) ) ) {
			$cn[] = array( 'n' => csa_ev_txt( $q['n'] ?? '' ), 'l' => csa_ev_txt( $q['l'] ?? '' ) );
		}
	}
	$h['cadet'] = array( 'nums' => $cn, 'bt' => csa_ev_txt( $x['bt'] ?? '' ), 'bu' => csa_ev_clean_url( $x['bu'] ?? '' ), 't' => csa_ev_txt( $x['t'] ?? '' ), 'p' => csa_ev_txt( $x['p'] ?? '' ), 'cards' => $cards( $x['cards'] ?? array(), array( 'ic' => 's', 'img' => 'i', 't' => 's', 'd' => 's', 'bt' => 's', 'u' => 'u' ) ) );
	foreach ( array( 'why', 'ideal' ) as $k ) {
		$x       = (array) ( $in[ $k ] ?? array() );
		$h[ $k ] = array( 't' => csa_ev_txt( $x['t'] ?? '' ), 'p' => csa_ev_txt( $x['p'] ?? '' ), 'cards' => $cards( $x['cards'] ?? array(), array( 'ic' => 's', 'img' => 'i', 't' => 's', 'd' => 's', 'u' => 'u' ) ) );
	}
	$x         = (array) ( $in['blog'] ?? array() );
	$h['blog'] = array( 't' => csa_ev_txt( $x['t'] ?? '' ), 'p' => csa_ev_txt( $x['p'] ?? '' ), 'n' => max( 1, min( 9, (int) ( $x['n'] ?? 3 ) ) ), 'bt' => csa_ev_txt( $x['bt'] ?? '' ), 'bu' => csa_ev_clean_url( $x['bu'] ?? '' ) );
	$x         = (array) ( $in['cta'] ?? array() );
	$h['cta']  = array( 't' => csa_ev_txt( $x['t'] ?? '' ), 'p' => csa_ev_txt( $x['p'] ?? '' ), 'b1' => $lnk( (array) ( $x['b1'] ?? array() ) ), 'b2' => $lnk( (array) ( $x['b2'] ?? array() ) ), 'phone' => csa_ev_txt( $x['phone'] ?? '' ), 'mail' => sanitize_email( $x['mail'] ?? '' ) );
	$x        = (array) ( $in['seo'] ?? array() );
	$h['seo'] = array( 't' => csa_ev_txt( $x['t'] ?? '' ), 'd' => csa_ev_txt( $x['d'] ?? '' ), 'k' => csa_ev_txt( $x['k'] ?? '' ) );
	unset( $sec );
	return $h;
}

/* ---------- Seed: Spelling Star Season 1 and Khude Star, from the live home page ---------- */
function csa_ev_seed_events() {
	$ss = array_merge( csa_ev_blank(), array(
		'slug'    => 'spelling-star', 'title' => 'Spelling Star', 'full' => 'Shaheen Education Family Channel i Spelling Star', 'series' => 'Channel i Spelling Star', 'season' => 'Season 1',
		'tagline' => 'বাংলাদেশ খুঁজছে তার পরবর্তী ✦ Spelling Star ✦ — হতে পারে আপনার সন্তান, আগামী দিনের সেই তারকা।',
		'c1' => '#0d2e1c', 'c2' => '#1a6b3e', 'ac' => '#f5a623', 'font' => 'baloo', 'order' => 1, 'show_home' => 1,
		'card'    => 'বাংলাদেশ খুঁজছে তার পরবর্তী Spelling Star।',
		'foot'    => 'Spelling Star 2026 — Classroom to the World',
		'seo'     => 'Spelling Star Season 1 — শাহীন শিক্ষা পরিবার আয়োজিত জাতীয় বানান প্রতিযোগিতা: সব পর্ব, ফলাফল, পুরস্কার ও তথ্য এক জায়গায়।',
		'facts'   => array(),

		'miles'   => array(
			array( 'ic' => '📝', 't' => 'রেজিস্ট্রেশন', 'd1' => '2026-07-01', 'd2' => '2026-08-14', 'note' => 'শুরু ১ জুলাই ২০২৬, শেষ ১৪ আগস্ট ২০২৬', 'st' => '', 'lbl' => '' ),
			array( 'ic' => '🏛️', 't' => 'বিভাগীয় পর্যায়ের বাছাই', 'd1' => '2026-08-31', 'd2' => '2026-09-22', 'note' => 'দেশের ৮ বিভাগীয় সদরে ধাপে ধাপে অডিশন রাউন্ড', 'st' => '', 'lbl' => '' ),
			array( 'ic' => '📺', 't' => 'টিভিতে সম্প্রচার', 'd1' => '', 'd2' => '', 'note' => '', 'st' => 'live', 'lbl' => 'টিভিতে সম্প্রচার চলছে' ),
		),
		'btns'    => array(
			array( 't' => 'বিভাগীয় ফলাফল দেখুন', 'u' => '/spl-result-division/', 'k' => 's', 'until' => '', 'home' => 1 ),
			array( 't' => 'কেন্দ্রভিত্তিক ফলাফল দেখুন', 'u' => '/spl-result-centre/', 'k' => 's', 'until' => '', 'home' => 0 ),
			array( 't' => 'Spelling Star ওয়েবসাইট', 'u' => 'https://spellingstar.shaheen.edu.bd/', 'k' => 'g', 'until' => '', 'home' => 0 ),
			array( 't' => 'ফেসবুক পেজ ফলো করুন', 'u' => 'https://www.facebook.com/shaheen.edu.bd', 'k' => 'g', 'until' => '', 'home' => 0 ),
		),
		'prizes'  => array( 'on' => 1, 't' => 'পুরস্কারসমূহ', 'items' => array( array( 'l' => '১ম স্থান', 'v' => '৩ লক্ষ টাকা' ), array( 'l' => '২য় স্থান', 'v' => '২ লক্ষ টাকা' ), array( 'l' => '৩য় স্থান', 'v' => '১ লক্ষ টাকা' ), array( 'l' => '৪র্থ স্থান', 'v' => '৫০ হাজার টাকা' ), array( 'l' => '৫ম স্থান', 'v' => '৫০ হাজার টাকা' ) ), 'note' => 'প্রথম–দশম স্থান প্রত্যেকে পাবে একটি ল্যাপটপ। প্রতি কেন্দ্রে ৩০–৫০ জনকে ক্রেস্ট ও সার্টিফিকেট প্রদান করা হবে।' ),
		'tv'      => array( 'on' => 1, 't' => 'টেলিভিশনে চলছে Spelling Star', 'p' => 'চ্যানেল আই-এ সম্প্রচারিত হচ্ছে। পর্ব ও সময়সূচির তথ্য এই পেজেই যোগ করা হবে।', 'ch' => 'Channel i', 'img' => '' ),
		'org'     => array( 'name' => 'শাহীন শিক্ষা পরিবার (SEF)', 'logo' => '', 'url' => '/' ),
		'partners' => array( array( 'name' => 'চ্যানেল আই', 'role' => 'মিডিয়া পার্টনার', 'logo' => '', 'url' => '' ), array( 'name' => 'দৈনিক যুগান্তর', 'role' => 'মিডিয়া পার্টনার', 'logo' => '', 'url' => '' ) ),
		'blocks'  => array(
			array( 'ic' => '🗓️', 't' => 'বিভাগভিত্তিক বাছাই পরীক্ষার তারিখ', 'd' => "খুলনা: ৩১ আগস্ট\nবরিশাল: ১ সেপ্টেম্বর\nরাজশাহী: ৭ সেপ্টেম্বর\nরংপুর: ৮ সেপ্টেম্বর\nচট্টগ্রাম: ১৪ সেপ্টেম্বর\nসিলেট: ১৫ সেপ্টেম্বর\nময়মনসিংহ: ২০ সেপ্টেম্বর\nঢাকা: ২২ সেপ্টেম্বর", 'img' => '' ),
			array( 'ic' => '⏰', 't' => 'পরীক্ষার নিয়ম', 'd' => "প্রতিটি কেন্দ্রে সকাল ১০টায় লিখিত পরীক্ষা অনুষ্ঠিত হবে।\nলিখিত পরীক্ষার ১৫–৩০ মিনিট পর মৌখিক পরীক্ষা অনুষ্ঠিত হবে।", 'img' => '' ),
		),
		'nq'      => 'Spelling Star',
	) );
	$ks = array_merge( csa_ev_blank(), array(
		'slug'    => 'khude-star', 'title' => 'ক্ষুদে স্টার', 'series' => 'জাতীয় শিশু শিক্ষামূলক আয়োজন', 'season' => '২০২৬',
		'tagline' => 'খেলতে খেলতে শিখবে শিশু, আনন্দ হবে আবিষ্কার — প্রতিভার আলোয় জ্বলে উঠবে আমাদের ক্ষুদে স্টার',
		'ext'     => '/khude-star/',
		'c1' => '#6b2a00', 'c2' => '#d9650f', 'ac' => '#ffd34d', 'font' => 'baloo', 'order' => 2, 'show_home' => 1,
		'card'    => 'খেলাই হোক শেখার প্রথম পাঠ। রেজিস্ট্রেশন ফি মাত্র ১০০ টাকা। ৪,০০০+ অভিভাবক ইতিমধ্যে যুক্ত হয়েছেন।',
		'btns'    => array(
			array( 't' => '📄 আবেদন ফরম ডাউনলোড করুন', 'u' => 'https://drive.google.com/uc?export=download&id=1Asbv1z0FgV8WHq58Zl7wOHyYIVTshR_l', 'k' => 'p', 'until' => '', 'home' => 1 ),
		),
		'prizes'  => array( 'on' => 1, 't' => 'পুরস্কারসমূহ', 'items' => array( array( 'l' => '১ম পুরস্কার', 'v' => '১,০০,০০০/-' ), array( 'l' => '২য় পুরস্কার', 'v' => '৭৫,০০০/-' ), array( 'l' => '৩য় পুরস্কার', 'v' => '৫০,০০০/-' ), array( 'l' => '৪র্থ পুরস্কার', 'v' => '৪০,০০০/-' ), array( 'l' => '৫ম পুরস্কার', 'v' => '২৫,০০০/-' ) ), 'note' => 'চূড়ান্ত পর্যায়ে ৬ষ্ঠ থেকে ৩০তম স্থান অর্জনকারী প্রত্যেকের জন্য রয়েছে আকর্ষণীয় পুরস্কারের ব্যবস্থা।' ),
		'facts'   => array( array( 'ic' => '💸', 'l' => 'রেজিস্ট্রেশন ফি', 'v' => 'মাত্র ১০০ টাকা' ) ),
	) );
	return array( $ss, $ks );
}

function csa_ev_import_seed() {
	$o    = csa_ev_opts();
	$list = csa_ev_list();
	$have = array();
	foreach ( $list as $e ) {
		$have[ $e['slug'] ] = 1;
	}
	$n = 0;
	foreach ( csa_ev_seed_events() as $s ) {
		if ( isset( $have[ $s['slug'] ] ) ) {
			continue;
		}
		$s['id'] = (int) $o['next']++;
		$c       = csa_ev_clean( $s );
		$c['id'] = $s['id'];
		csa_ev_ensure_page( $c );
		$list[]  = $c;
		$n++;
	}
	$o['next'] = max( (int) $o['next'], 1 );
	csa_ev_set( $o );
	csa_ev_put( $list );
	return $n;
}

require_once __DIR__ . '/events-render.php';
require_once __DIR__ . '/events-admin.php';
