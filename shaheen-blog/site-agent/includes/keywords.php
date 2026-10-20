<?php
/**
 * Site-wide keyword strategy. Every AI job (SEO Agent, chat agent, blog writer, new pages)
 * receives it, so generated text uses the right words for the right brand.
 * Branch/area names are read from the site's own branch pages, so they stay current.
 */
defined( 'ABSPATH' ) || exit;

function csa_kw_default() {
	return <<<'TXT'
BRAND 1 — Shaheen School (general school)
Main: শাহীন স্কুল | Shaheen School | শাহীন এডুকেশন ফ্যামিলি
Admission: স্কুলে ভর্তি ২০২৭ | school admission 2027 | ভর্তি বিজ্ঞপ্তি ২০২৭ | নার্সারি ভর্তি | প্লে গ্রুপ ভর্তি | ক্লাস ১ ভর্তি | ভর্তির নিয়ম | ভর্তি ফরম | ভর্তি পরীক্ষা | স্কুল ভর্তির কাগজপত্র | স্কুলে ভর্তির বয়স | স্কুল ভর্তি ফি
Best/compare: সেরা স্কুল [AREA] | best school in [AREA] | ভালো ইংলিশ ভার্সন স্কুল | সেরা ইংরেজি মিডিয়াম স্কুল | সেরা বাংলা মিডিয়াম স্কুল | নামকরা স্কুল
Facilities/quality: নিরাপদ ক্যাম্পাস | অভিজ্ঞ শিক্ষক | ভালো ফলাফল | স্মার্ট ক্লাসরুম | আধুনিক ল্যাব | স্কুল বাস | ডে-কেয়ার সুবিধা | কম্পিউটার ও কোডিং
Local/contact: শাহীন স্কুল ঠিকানা | শাহীন স্কুল ফোন নম্বর | [AREA]-এর স্কুল | school near me

BRAND 2 — Shaheen Cadet (cadet school and coaching)
Main: শাহীন ক্যাডেট | Shaheen Cadet | শাহীন ক্যাডেট কোচিং
Best: সেরা ক্যাডেট কোচিং | best cadet coaching | best cadet coaching in [AREA] | সেরা ক্যাডেট স্কুল | best cadet school in Bangladesh
Admission prep: ক্যাডেট কলেজ ভর্তি প্রস্তুতি | ক্যাডেট কলেজ ভর্তি সিলেবাস | ক্যাডেট কলেজ ভর্তি পরীক্ষা ২০২৭ | ক্যাডেট কলেজ ভর্তি বিজ্ঞপ্তি ২০২৭ | ক্যাডেট কলেজ মডেল টেস্ট | ক্যাডেট কলেজ বিগত প্রশ্ন | ক্যাডেট কলেজ ভাইভা | ক্যাডেট কলেজ মেডিকেল টেস্ট | cadet college admission | cadet college coaching | cadet college preparation
Subjects/classes: ক্যাডেট কলেজ গণিত প্রস্তুতি | ক্যাডেট কলেজ ইংরেজি প্রস্তুতি | ক্যাডেট কলেজ সাধারণ জ্ঞান | ক্যাডেট কোচিং ক্লাস ৫ | ক্লাস ৬ | ক্লাস ৭ | অনলাইন ক্যাডেট কোচিং | রেসিডেনশিয়াল ক্যাডেট কোচিং | ক্যাডেট কোচিং ফি | ক্যাডেট কোচিং ব্যাচ
Trust/parents: ক্যাডেট কোচিংয়ের সাফল্য | ক্যাডেট কলেজে ভর্তির বয়স | ক্যাডেট কলেজে ভর্তির খরচ | ক্যাডেট কলেজ ও সাধারণ স্কুলের পার্থক্য | ছেলেকে ক্যাডেট কলেজে ভর্তি করাতে কী করব

BLOG TOPICS (parents' questions; ONE main keyword per post)
সন্তানের জন্য সেরা স্কুল কীভাবে বাছবেন | ভালো স্কুলের লক্ষণ | স্কুল ভর্তির আগে অভিভাবকের করণীয় | ক্যাডেট কলেজ কী | ক্যাডেট কলেজে ভর্তির যোগ্যতা | ভর্তি পরীক্ষার ভয় কাটানোর উপায় | শিশুকে স্কুলের জন্য প্রস্তুত করা | পরীক্ষার আগের দিন করণীয় | পড়ায় মনোযোগ বাড়ানোর উপায়

RULES
- Use the main keyword of the right brand (a school page uses Brand 1 words, a cadet page uses Brand 2 words). Never mix the two brands' keywords on one page.
- One main (focus) keyword per page, post or blog. Two pages must not compete for the same keyword.
- Put the main keyword in the SEO title, the H1, the first paragraph and the meta description. Use 2-3 related words from the same group naturally in the body, never stuffed.
- [AREA] means the branch or area of the page. On a branch page use that branch's name; on other pages use an area only if the page is really about it. Never invent an area that is not in the branch list.
- Do not claim "best / সেরা" without proof from the site memory (a result, a number, an award). Say what is true: results, teachers, campus, facilities.
- Competitive words (সেরা স্কুল, best cadet coaching) are hard to rank: prefer admission, fees, address and local words for new pages.
- Name, address and phone must be the same on every page.
TXT;
}

function csa_kw_strategy() {
	$t = trim( (string) csa_opt( 'kw_strategy' ) );
	return '' !== $t ? $t : csa_kw_default();
}

function csa_kw_branch_words() {
	$t = trim( (string) csa_opt( 'kw_branch_words' ) );
	$w = array_filter( array_map( 'trim', preg_split( '/[,\n]+/u', '' !== $t ? $t : 'branch, campus, শাখা, ক্যাম্পাস' ) ) );
	return array_values( $w );
}

/** Branch pages found on the site: array of array( id, title, url ). */
function csa_branches() {
	$c = get_transient( 'csa_branches' );
	if ( is_array( $c ) ) {
		return $c;
	}
	$words = csa_kw_branch_words();
	$out   = array();
	$posts = get_posts( array( 'post_type' => array( 'page' ), 'post_status' => 'publish', 'numberposts' => 400, 'orderby' => 'title', 'order' => 'ASC' ) );
	foreach ( $posts as $p ) {
		$hay  = mb_strtolower( $p->post_title . ' ' . $p->post_name );
		$par  = $p->post_parent ? get_post( $p->post_parent ) : null;
		$phay = $par ? mb_strtolower( $par->post_title . ' ' . $par->post_name ) : '';
		foreach ( $words as $w ) {
			$w = mb_strtolower( $w );
			if ( '' === $w ) {
				continue;
			}
			// A page about branches ("Our branches") is the hub, not a branch: only count it when it has a branch parent or its own name.
			if ( false !== mb_strpos( $hay, $w ) || false !== mb_strpos( $phay, $w ) ) {
				$out[ $p->ID ] = array( 'id' => $p->ID, 'title' => $p->post_title, 'url' => get_permalink( $p ), 'child' => $par ? 1 : 0 );
				break;
			}
		}
	}
	// If branch pages are children of a hub page, the hub itself is not a branch.
	$hubs = array();
	foreach ( $out as $b ) {
		if ( $b['child'] ) {
			$hubs[ wp_get_post_parent_id( $b['id'] ) ] = true;
		}
	}
	foreach ( array_keys( $hubs ) as $h ) {
		unset( $out[ $h ] );
	}
	$out = array_slice( array_values( $out ), 0, 40 );
	set_transient( 'csa_branches', $out, 10 * MINUTE_IN_SECONDS );
	return $out;
}

/** Text added to every AI call. */
function csa_kw_context() {
	$s = "\n\n## KEYWORD STRATEGY (admin instruction: always follow when you create or change any page, post or blog)\n" . csa_kw_strategy();
	$b = csa_branches();
	if ( $b ) {
		$s .= "\n\nBranch pages on this site right now (use these names for [AREA]; this list is read live from the website):\n";
		foreach ( $b as $x ) {
			$s .= '- ' . $x['title'] . ' (' . $x['url'] . ")\n";
		}
	} else {
		$s .= "\n\nNo branch pages were found on the site, so do not use any area name.";
	}
	return $s;
}

add_action( 'save_post_page', function () {
	delete_transient( 'csa_branches' );
} );
