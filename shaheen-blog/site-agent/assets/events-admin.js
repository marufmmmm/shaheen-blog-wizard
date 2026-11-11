/* Page Builder > ইভেন্ট ও হোম : admin app (one generic form builder drives every tab) */
(function () {
	'use strict';
	var app = document.getElementById('ev-app');
	if (!app || !window.EV) { return; }
	var S = { s: EV.s, tab: 'list', ed: null, etab: 'main', msg: '', busy: false };

	function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
	function at(t) { return esc(t).replace(/"/g, '&quot;'); }
	function bn(n) { return String(n).replace(/[0-9]/g, function (d) { return '০১২৩৪৫৬৭৮৯'.charAt(+d); }); }
	function q(s, el) { return (el || app).querySelector(s); }
	function qa(s, el) { return [].slice.call((el || app).querySelectorAll(s)); }
	function kids(el, sel) { return [].slice.call(el.children).filter(function (c) { return c.matches(sel); }); }

	function post(op, data) {
		var body = new URLSearchParams({ action: 'csa_ev_op', nonce: EV.nonce, op: op, data: JSON.stringify(data == null ? null : data) });
		S.busy = true;
		return fetch(EV.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); }).then(function (j) {
			S.busy = false;
			if (!j.success) { throw new Error(typeof j.data === 'string' ? j.data : 'ত্রুটি'); }
			if (j.data.s) { S.s = j.data.s; }
			return j.data;
		}, function (e) { S.busy = false; throw e; });
	}
	function say(m, bad) { S.msg = m ? '<div class="notice ' + (bad ? 'notice-error' : 'notice-success') + ' is-dismissible"><p>' + esc(m) + '</p></div>' : ''; }
	function run(p, after) {
		return p.then(function (d) { say(d.msg); if (after) { after(d); } draw(); window.scrollTo({ top: 0, behavior: 'smooth' }); }).catch(function (e) { say(e.message, true); draw(); });
	}

	/* ---------------- field definitions ---------------- */
	var KIND = [['p', 'মূল (সোনালি)'], ['s', 'দ্বিতীয় (সাদা)'], ['g', 'হালকা']];
	var ST = [['', 'নিজে ঠিক হবে (তারিখ দেখে)'], ['live', 'চলমান (হাতে ঠিক)'], ['done', 'সম্পন্ন (হাতে ঠিক)'], ['soon', 'আসছে (হাতে ঠিক)']];
	var GK = [['photo', '🖼️ ছবি'], ['video', '🎬 ভিডিও'], ['post', '📝 পোস্ট']];

	var LINK = [{ k: 't', l: 'বোতামের লেখা', t: 'text' }, { k: 'u', l: 'লিংক', t: 'url' }];
	var CARD = function (extra) { return [{ k: 'ic', l: 'ইমোজি/আইকন', t: 'text', w: 1 }, { k: 'img', l: 'অথবা ছবি/লোগো', t: 'img' }, { k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'd', l: 'ছোট লেখা', t: 'text' }].concat(extra || []); };

	var EVENT = {
		main: ['মূল তথ্য', [
			{ k: 'title', l: 'ইভেন্টের নাম (যেমন: Spelling Star)', t: 'text' },
			{ k: 'series', l: 'সিরিজ / ছোট শিরোনাম (যেমন: Channel i Spelling Star)', t: 'text' },
			{ k: 'season', l: 'সিজন / বছর (যেমন: Season 1)', t: 'text', w: 1 },
			{ k: 'tagline', l: 'ট্যাগলাইন (পেজের ওপরে বড় লেখা)', t: 'area' },
			{ k: 'card', l: 'হোম পেজের কার্ডে ছোট বিবরণ (খালি থাকলে ট্যাগলাইন)', t: 'area' },
			{ k: 'badge', l: 'ব্যাজ (খালি রাখলে সময়রেখা দেখে নিজে "● রেজিস্ট্রেশন চলছে" ইত্যাদি বসবে)', t: 'text' },
			{ k: 'slug', l: 'পেজের ঠিকানা (যেমন: spelling-star)', t: 'text', w: 1 },
			{ k: 'ext', l: 'আগে থেকে তৈরি পেজ থাকলে তার লিংক (দিলে নতুন পেজ হবে না, হোম কার্ড ওই পেজে যাবে)', t: 'url' },
			{ k: 'foot', l: 'পেজের নিচের স্লোগান', t: 'text' },
			{ k: 'seo', l: 'Google-এর জন্য ছোট বিবরণ (SEO, ১৫০ অক্ষরের মধ্যে ভালো)', t: 'area' }
		]],
		look: ['লোগো, রং ও ফন্ট', [
			{ k: 'logo', l: 'ইভেন্টের লোগো', t: 'img' },
			{ k: 'cover', l: 'ওপরের পটভূমি ছবি (ঐচ্ছিক)', t: 'img' },
			{ k: 'c1', l: 'গাঢ় রং', t: 'color', w: 1 }, { k: 'c2', l: 'হালকা রং', t: 'color', w: 1 }, { k: 'ac', l: 'বোতাম/হাইলাইট রং', t: 'color', w: 1 },
			{ k: 'font', l: 'শিরোনামের ফন্ট', t: 'sel', o: 'fonts' }
		]],
		home: ['হোম পেজে দেখানো', [
			{ k: 'show_home', l: 'হোম পেজের ইভেন্ট সারিতে দেখাও', t: 'bool' },
			{ k: 'order', l: 'ক্রম (ছোট সংখ্যা আগে)', t: 'num', w: 1 },
			{ k: 'home_from', l: 'এই তারিখ থেকে দেখাও (ঐচ্ছিক)', t: 'date', w: 1 },
			{ k: 'home_to', l: 'এই তারিখ পর্যন্ত দেখাও (ঐচ্ছিক, তারপর নিজে সরে যাবে)', t: 'date', w: 1 }
		]],
		miles: ['সময়রেখা', [
			{ k: 'miles', l: 'ধাপসমূহ (রেজিস্ট্রেশন, বাছাই, টিভিতে সম্প্রচার…)', t: 'list', n: 'ধাপ', f: [
				{ k: 'ic', l: 'ইমোজি', t: 'text', w: 1 }, { k: 't', l: 'ধাপের নাম', t: 'text' }, { k: 'd1', l: 'শুরুর তারিখ', t: 'date', w: 1 }, { k: 'd2', l: 'শেষ তারিখ', t: 'date', w: 1 },
				{ k: 'st', l: 'অবস্থা', t: 'sel', o: ST }, { k: 'lbl', l: 'ব্যাজে/ট্যাগে যে লেখা দেখাবে (ঐচ্ছিক, যেমন: টিভিতে সম্প্রচার চলছে)', t: 'text' }, { k: 'note', l: 'ছোট বিবরণ', t: 'area' }
			] }
		]],
		btns: ['বোতামসমূহ', [
			{ k: 'btns', l: 'বোতাম (রেজিস্ট্রেশন, লগ ইন, ফলাফল…)। "পর্যন্ত" তারিখের পর বোতামটি নিজে সরে যায়।', t: 'list', n: 'বোতাম', f: [
				{ k: 't', l: 'বোতামের লেখা', t: 'text' }, { k: 'u', l: 'লিংক', t: 'url' }, { k: 'k', l: 'ধরন', t: 'sel', o: KIND, w: 1 }, { k: 'until', l: 'এই তারিখ পর্যন্ত থাকবে (ঐচ্ছিক)', t: 'date', w: 1 }, { k: 'home', l: 'হোম পেজের কার্ডেও দেখাও', t: 'bool' }
			] }
		]],
		info: ['তথ্য ও ব্লক', [
			{ k: 'facts', l: 'দ্রুত তথ্য (কারা অংশ নেবে, ফি, সম্প্রচার…)', t: 'list', n: 'তথ্য', f: [{ k: 'ic', l: 'ইমোজি', t: 'text', w: 1 }, { k: 'l', l: 'শিরোনাম', t: 'text' }, { k: 'v', l: 'মান', t: 'text' }] },
			{ k: 'blocks', l: 'বিস্তারিত ব্লক (প্রতি লাইনে একটি পয়েন্ট; যেমন বিভাগভিত্তিক তারিখ)', t: 'list', n: 'ব্লক', f: [{ k: 'ic', l: 'ইমোজি', t: 'text', w: 1 }, { k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'd', l: 'লেখা (প্রতি লাইনে একটি)', t: 'area' }, { k: 'img', l: 'ছবি (ঐচ্ছিক)', t: 'img' }] }
		]],
		prizes: ['পুরস্কার', [
			{ k: 'prizes', l: 'পুরস্কার', t: 'grp', f: [
				{ k: 'on', l: 'পুরস্কার অংশ দেখাও', t: 'bool' }, { k: 't', l: 'শিরোনাম', t: 'text' },
				{ k: 'items', l: 'তালিকা', t: 'list', n: 'পুরস্কার', f: [{ k: 'l', l: 'স্থান', t: 'text', w: 1 }, { k: 'v', l: 'পুরস্কার', t: 'text', w: 1 }] },
				{ k: 'note', l: 'নিচের নোট', t: 'text' }
			] }
		]],
		tv: ['টিভি সম্প্রচার', [
			{ k: 'tv', l: 'টিভি সম্প্রচার', t: 'grp', f: [{ k: 'on', l: 'সম্প্রচার অংশ দেখাও', t: 'bool' }, { k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'ch', l: 'চ্যানেলের নাম', t: 'text' }, { k: 'p', l: 'বিবরণ / সময়সূচি', t: 'area' }, { k: 'img', l: 'ছবি / চ্যানেলের ব্যানার', t: 'img' }] }
		]],
		by: ['আয়োজক ও পার্টনার', [
			{ k: 'org', l: 'আয়োজক', t: 'grp', f: [{ k: 'name', l: 'নাম', t: 'text' }, { k: 'logo', l: 'লোগো', t: 'img' }, { k: 'url', l: 'লিংক', t: 'url' }] },
			{ k: 'partners', l: 'মিডিয়া পার্টনার / স্পন্সর', t: 'list', n: 'পার্টনার', f: [{ k: 'name', l: 'নাম', t: 'text' }, { k: 'role', l: 'ভূমিকা (মিডিয়া পার্টনার…)', t: 'text' }, { k: 'logo', l: 'লোগো', t: 'img' }, { k: 'url', l: 'লিংক', t: 'url' }] }
		]],
		eps: ['এপিসোড ভিডিও', [
			{ k: 'eps', l: 'পর্ব (YouTube বা Facebook ভিডিওর লিংক বসান)। YouTube হলে ছবি নিজে আসবে, Facebook হলে ছবি আপলোড করতে পারেন।', t: 'list', n: 'পর্ব', f: [{ k: 'n', l: 'পর্ব নম্বর (যেমন: পর্ব ১)', t: 'text', w: 1 }, { k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'u', l: 'ভিডিও লিংক', t: 'url' }, { k: 'd', l: 'তারিখ', t: 'date', w: 1 }, { k: 'img', l: 'থাম্বনেইল (ঐচ্ছিক)', t: 'img' }] }
		]],
		gal: ['গ্যালারি', [
			{ k: 'gal', l: 'ছবি / পোস্ট / ভিডিও (ট্যাগ দিলে ফিল্টার বোতাম আসে)', t: 'list', n: 'আইটেম', f: [{ k: 'k', l: 'ধরন', t: 'sel', o: GK, w: 1 }, { k: 't', l: 'ক্যাপশন', t: 'text', w: 1 }, { k: 'img', l: 'ছবি (পোস্ট/ভিডিওর থাম্বনেইলও)', t: 'img' }, { k: 'u', l: 'লিংক (পোস্ট বা ভিডিওর)', t: 'url' }, { k: 'tag', l: 'ট্যাগ (যেমন: ঢাকা বিভাগ)', t: 'text', w: 1 }] }
		]],
		more: ['নোটিশ ও সংযোগ', [
			{ k: 'nq', l: 'এই শব্দ থাকা নোটিশ পেজে দেখাও (নোটিশ সিস্টেম থেকে)', t: 'text' },
			{ k: 'links', l: 'যুক্ত ইভেন্ট (পেজের নিচে "আরও আয়োজন" হিসেবে দেখাবে)', t: 'evlinks' }
		]]
	};

	var HOME = {
		hero: ['ওপরের হিরো ও সংখ্যা', [{ k: 'kicker', l: 'ওপরের ছোট ট্যাগ', t: 'text' }, { k: 'h1a', l: 'বড় শিরোনাম (প্রথম লাইন)', t: 'text' }, { k: 'h1b', l: 'বড় শিরোনাম (রঙিন দ্বিতীয় লাইন)', t: 'text' }, { k: 'sub', l: 'নিচের লেখা', t: 'area' }, { k: 'b1', l: 'প্রথম বোতাম', t: 'grp', f: LINK }, { k: 'b2', l: 'দ্বিতীয় বোতাম', t: 'grp', f: LINK }, { k: 'img', l: 'পটভূমি ছবি (ঐচ্ছিক)', t: 'img' }, { k: 'stats', l: 'সংখ্যা (১৫০+ ক্যাম্পাস…)', t: 'list', n: 'সংখ্যা', f: [{ k: 'n', l: 'সংখ্যা', t: 'text', w: 1 }, { k: 'l', l: 'নিচের লেখা', t: 'text', w: 1 }] }]],
		events: ['ইভেন্ট সারি (ওপরের দিকে)', [{ k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'p', l: 'ছোট লেখা', t: 'text' }, { k: 'max', l: 'সর্বোচ্চ কয়টি ইভেন্ট', t: 'num', w: 1 }]],
		notice: ['নোটিশ বোর্ড', [{ k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'p', l: 'ছোট লেখা', t: 'text' }, { k: 'n', l: 'কয়টি নোটিশ', t: 'num', w: 1 }, { k: 'bt', l: 'বোতামের লেখা', t: 'text' }, { k: 'bu', l: 'সব নোটিশের পেজের লিংক (খালি থাকলে নিজে খুঁজবে)', t: 'url' }]],
		act: ['আমাদের কার্যক্রম', [{ k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'p', l: 'ছোট লেখা', t: 'text' }, { k: 'cards', l: 'কার্ড', t: 'list', n: 'কার্ড', f: CARD([{ k: 'u', l: 'লিংক', t: 'url' }, { k: 'tag', l: 'কোণার ট্যাগ (যেমন: ★ সেরা)', t: 'text', w: 1 }]) }]],
		branch: ['কাছের শাখা খুঁজুন', [{ k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'p', l: 'ছোট লেখা', t: 'text' }, { k: 'bt', l: 'বোতামের লেখা', t: 'text' }, { k: 'bu', l: 'শাখা পেজের লিংক', t: 'url' }]],
		ach: ['গৌরবোজ্জ্বল অর্জন', [{ k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'p', l: 'ছোট লেখা', t: 'text' }, { k: 'nums', l: 'বড় সংখ্যা (প্রতি বছর বদলান)', t: 'list', n: 'সংখ্যা', f: [{ k: 'n', l: 'সংখ্যা', t: 'text', w: 1 }, { k: 'l', l: 'লেখা', t: 'text', w: 1 }] }, { k: 'items', l: 'অর্জনের তালিকা', t: 'list', n: 'অর্জন', f: [{ k: 'ic', l: 'ইমোজি', t: 'text', w: 1 }, { k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'd', l: 'বিবরণ', t: 'area' }] }, { k: 'bt', l: 'বোতামের লেখা', t: 'text' }, { k: 'bu', l: 'বোতামের লিংক', t: 'url' }]],
		cadet: ['ক্যাডেট প্রস্তুতি (বই ও অনলাইন)', [{ k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'p', l: 'ছোট লেখা', t: 'text' }, { k: 'cards', l: 'কার্ড', t: 'list', n: 'কার্ড', f: CARD([{ k: 'bt', l: 'বোতামের লেখা', t: 'text', w: 1 }, { k: 'u', l: 'লিংক', t: 'url' }]) }]],
		why: ['কেন শাহীন (তিনটি কার্ড)', [{ k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'p', l: 'ছোট লেখা', t: 'text' }, { k: 'cards', l: 'কার্ড', t: 'list', n: 'কার্ড', f: CARD([{ k: 'u', l: 'বিস্তারিত পেজের লিংক', t: 'url' }]) }]],
		ideal: ['শিক্ষার্থী-অভিভাবক-শিক্ষক', [{ k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'p', l: 'ছোট লেখা', t: 'text' }, { k: 'cards', l: 'কার্ড', t: 'list', n: 'কার্ড', f: CARD([{ k: 'u', l: 'বিস্তারিত পেজের লিংক', t: 'url' }]) }]],
		blog: ['সর্বশেষ ব্লগ', [{ k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'p', l: 'ছোট লেখা', t: 'text' }, { k: 'n', l: 'কয়টি লেখা', t: 'num', w: 1 }, { k: 'bt', l: 'বোতামের লেখা', t: 'text' }, { k: 'bu', l: 'সব লেখার লিংক (ঐচ্ছিক)', t: 'url' }]],
		cta: ['নিচের ভর্তি ও যোগাযোগ ব্যান্ড', [{ k: 't', l: 'শিরোনাম', t: 'text' }, { k: 'p', l: 'ছোট লেখা', t: 'text' }, { k: 'b1', l: 'প্রথম বোতাম', t: 'grp', f: LINK }, { k: 'b2', l: 'দ্বিতীয় বোতাম', t: 'grp', f: LINK }, { k: 'phone', l: 'হেল্পলাইন', t: 'text', w: 1 }, { k: 'mail', l: 'ইমেইল', t: 'text', w: 1 }]]
	};

	/* ---------------- generic form builder ---------------- */
	function opts(o, v) {
		var list = o === 'fonts' ? EV.fonts.map(function (f) { return [f.k, f.l]; }) : o;
		return list.map(function (x) { return '<option value="' + at(x[0]) + '"' + (String(v) === String(x[0]) ? ' selected' : '') + '>' + esc(x[1]) + '</option>'; }).join('');
	}

	function fld(s, v, ctx) {
		var w = s.w ? ' half' : '';
		var h = '<div class="f f-' + s.t + w + '" data-f="' + s.k + '">';
		if (s.t === 'text' || s.t === 'url' || s.t === 'date' || s.t === 'num') {
			h += '<label>' + esc(s.l) + '</label><input class="v" type="' + (s.t === 'date' ? 'date' : (s.t === 'num' ? 'number' : 'text')) + '" value="' + at(v == null ? '' : v) + '"' + (s.t === 'url' ? ' placeholder="https://… বা /পেজ-নাম/"' : '') + '>';
		} else if (s.t === 'color') {
			h += '<label>' + esc(s.l) + '</label><input class="v" type="color" value="' + at(v || '#1a6b3e') + '">';
		} else if (s.t === 'area') {
			h += '<label>' + esc(s.l) + '</label><textarea class="v" rows="3">' + esc(v == null ? '' : v) + '</textarea>';
		} else if (s.t === 'sel') {
			h += '<label>' + esc(s.l) + '</label><select class="v">' + opts(s.o, v) + '</select>';
		} else if (s.t === 'bool') {
			h += '<label class="chk"><input class="v" type="checkbox"' + (+v ? ' checked' : '') + '> ' + esc(s.l) + '</label>';
		} else if (s.t === 'img') {
			h += '<label>' + esc(s.l) + '</label><div class="im"><span class="pv">' + (v ? '<img src="' + at(v) + '" alt="">' : '🖼️') + '</span><input class="v" type="text" value="' + at(v || '') + '" placeholder="ছবির লিংক বা পাশের বোতামে বাছুন"><button type="button" class="button pick">ছবি বাছুন / আপলোড</button><button type="button" class="button clr">✕</button></div>';
		} else if (s.t === 'grp') {
			h += '<fieldset class="g"><legend>' + esc(s.l) + '</legend><div class="gb">' + s.f.map(function (c) { return fld(c, (v || {})[c.k]); }).join('') + '</div></fieldset>';
		} else if (s.t === 'list') {
			h += '<div class="lst"><label class="lt">' + esc(s.l) + '</label><div class="items">' + (v || []).map(function (it, i) { return li(s, it, i); }).join('') + '</div><button type="button" class="button add">+ নতুন ' + esc(s.n || 'আইটেম') + ' যোগ করুন</button></div>';
		} else if (s.t === 'evlinks') {
			var cur = (v || []).map(Number);
			var others = S.s.list.filter(function (e) { return !ctx || +e.id !== +ctx.id; });
			h += '<label>' + esc(s.l) + '</label><div class="evl">' + others.map(function (e) { return '<label class="chk"><input type="checkbox" class="ev-l" value="' + e.id + '"' + (cur.indexOf(+e.id) > -1 ? ' checked' : '') + '> ' + esc(e.title + ' ' + (e.season || '')) + '</label>'; }).join('') + (others.length ? '' : '<small>অন্য ইভেন্ট তৈরি হলে এখানে দেখা যাবে।</small>') + '</div>';
		}
		return h + '</div>';
	}
	function liTitle(s, it, i) {
		var t = it && (it.t || it.title || it.name || it.l || it.n);
		return esc(s.n || 'আইটেম') + ' ' + bn(i + 1) + (t ? ' — ' + esc(String(t).slice(0, 40)) : '');
	}
	function li(s, it, i) {
		return '<div class="li"><div class="lh"><b>' + liTitle(s, it, i) + '</b><span><button type="button" class="button up" title="ওপরে">↑</button><button type="button" class="button dn" title="নিচে">↓</button><button type="button" class="button del" title="মুছুন">🗑</button></span></div><div class="lb">' + s.f.map(function (c) { return fld(c, (it || {})[c.k]); }).join('') + '</div></div>';
	}

	function rd(host, specs) {
		var o = {};
		specs.forEach(function (s) {
			var f = kids(host, '.f').filter(function (x) { return x.getAttribute('data-f') === s.k; })[0];
			if (!f) { return; }
			if (s.t === 'bool') { o[s.k] = f.querySelector('.v').checked ? 1 : 0; }
			else if (s.t === 'grp') { o[s.k] = rd(f.querySelector(':scope > fieldset > .gb'), s.f); }
			else if (s.t === 'list') { o[s.k] = kids(f.querySelector(':scope > .lst > .items'), '.li').map(function (l) { return rd(l.querySelector(':scope > .lb'), s.f); }); }
			else if (s.t === 'evlinks') { o[s.k] = qa('.ev-l', f).filter(function (c) { return c.checked; }).map(function (c) { return +c.value; }); }
			else { o[s.k] = f.querySelector('.v').value; }
		});
		return o;
	}

	/* ---------------- views ---------------- */
	function draw() {
		var tabs = [['list', '🎪 ইভেন্টসমূহ'], ['home', '🏠 নতুন হোম পেজ'], ['set', '⚙️ সেটিংস']];
		var h = S.msg + '<h2 class="nav-tab-wrapper">' + tabs.map(function (t) { return '<a href="#" class="nav-tab' + (S.tab === t[0] ? ' nav-tab-active' : '') + '" data-tab="' + t[0] + '">' + t[1] + '</a>'; }).join('') + '</h2><div class="ev-body">';
		h += S.tab === 'list' ? (S.ed !== null ? vEdit() : vList()) : (S.tab === 'home' ? vHome() : vSet());
		app.innerHTML = h + '</div>';
		qa('[data-tab]').forEach(function (a) { a.onclick = function (e) { e.preventDefault(); S.tab = a.getAttribute('data-tab'); S.ed = null; S.msg = ''; draw(); }; });
		qa('[data-et]').forEach(function (a) { a.onclick = function (e) { e.preventDefault(); S.etab = a.getAttribute('data-et'); qa('.et').forEach(function (x) { x.classList.toggle('on', x === a); }); qa('.pn').forEach(function (p) { p.hidden = p.getAttribute('data-p') !== S.etab; }); }; });
	}

	function vList() {
		var L = S.s.list, h = '<div class="ev-bar"><button class="button button-primary button-large" data-act="new">+ নতুন ইভেন্ট</button>';
		var have = {};
		L.forEach(function (e) { have[e.slug] = 1; });
		if (!have['spelling-star'] || !have['khude-star']) { h += ' <button class="button button-large" data-act="seed">📥 Spelling Star Season 1 ও ক্ষুদে স্টার আনুন</button>'; }
		h += '</div>';
		if (!L.length) { return h + '<div class="ev-empty"><p>এখনো কোনো ইভেন্ট নেই। উপরের বোতামে প্রথম ইভেন্ট বানান, অথবা Spelling Star ও ক্ষুদে স্টারের বর্তমান তথ্য এক ক্লিকে আনুন।</p></div>'; }
		h += '<div class="ev-grid">';
		L.slice().sort(function (a, b) { return a.order - b.order; }).forEach(function (e) {
			h += '<div class="ev-card" style="--c1:' + at(e.c1) + ';--c2:' + at(e.c2) + '"><div class="hd"><span class="lg">' + (e.logo ? '<img src="' + at(e.logo) + '" alt="">' : '🎪') + '</span><div><b>' + esc(e.title) + (e.season ? ' <em>' + esc(e.season) + '</em>' : '') + '</b><small>' + esc(e.series || '') + '</small></div></div><div class="bd">';
			h += '<p><span class="tg">' + (e._stage ? esc(e._stage) : 'সময়রেখা নেই') + '</span> ' + (e._live ? '<span class="tg ok">পেজ চালু</span>' : '<span class="tg wn">পেজ ড্রাফট</span>') + ' ' + (e._home ? '<span class="tg ok">হোমে দেখাচ্ছে</span>' : '<span class="tg">হোমে নেই</span>') + (e.ext ? ' <span class="tg">পুরোনো পেজে লিংক</span>' : '') + '</p>';
			h += '<p class="ac"><button class="button button-primary" data-act="edit" data-id="' + e.id + '">✏️ সম্পাদনা</button>' + (e._url ? ' <a class="button" href="' + at(e._url) + '" target="_blank">👁 দেখুন ↗</a>' : '');
			if (e.page && !e.ext) { h += e._live ? ' <button class="button" data-act="draft" data-id="' + e.id + '">ড্রাফটে নিন</button>' : ' <button class="button button-primary" data-act="pub" data-id="' + e.id + '">🚀 প্রকাশ করুন</button>'; }
			h += ' <button class="button-link-delete" data-act="del" data-id="' + e.id + '">মুছুন</button></p></div></div>';
		});
		return h + '</div><p class="description">নতুন ইভেন্টের পেজ প্রথমে <b>ড্রাফট</b> থাকে — "দেখুন" দিয়ে নিজে দেখে নিয়ে তারপর "প্রকাশ করুন" চাপুন। প্রকাশের আগে হোম পেজে কার্ড শুধু আপনি দেখবেন, দর্শক দেখবে না।</p>';
	}

	function vEdit() {
		var isNew = S.ed === 'new';
		var e = isNew ? JSON.parse(JSON.stringify(EV.blank)) : JSON.parse(JSON.stringify(S.s.list.filter(function (x) { return +x.id === +S.ed; })[0] || EV.blank));
		var keys = Object.keys(EVENT);
		var h = '<p><button class="button" data-act="back">← সব ইভেন্ট</button> <b class="ev-h">' + (isNew ? 'নতুন ইভেন্ট' : esc(e.title)) + '</b></p><input type="hidden" id="ev-id" value="' + (+e.id || 0) + '"><div class="ev-tabs">' + keys.map(function (k) { return '<a href="#" class="et' + (S.etab === k ? ' on' : '') + '" data-et="' + k + '">' + esc(EVENT[k][0]) + '</a>'; }).join('') + '</div><div id="ev-form">';
		keys.forEach(function (k) {
			h += '<div class="pn" data-p="' + k + '"' + (S.etab === k ? '' : ' hidden') + '><div class="fl">' + EVENT[k][1].map(function (s) { return fld(s, e[s.k], e); }).join('') + '</div></div>';
		});
		return h + '</div><p class="ev-save"><button class="button button-primary button-large" data-act="save">💾 সংরক্ষণ করুন</button> <button class="button button-large" data-act="back">বাতিল</button></p>';
	}

	function readEvent() {
		var all = {};
		Object.keys(EVENT).forEach(function (k) {
			var part = rd(q('.pn[data-p="' + k + '"] .fl'), EVENT[k][1]);
			Object.keys(part).forEach(function (x) { all[x] = part[x]; });
		});
		all.id = +q('#ev-id').value || 0;
		return all;
	}

	function vHome() {
		var hs = S.s.hs, H = S.s.home;
		var h = '<div class="ev-live ' + (hs.live ? 'on' : '') + '"><div><b>' + (hs.live ? '✅ নতুন হোম পেজ এখন সবার জন্য চালু' : '📝 নতুন হোম পেজ এখনো খসড়া — দর্শক পুরোনো হোম পেজই দেখছে') + '</b><p>এখন চালু হোম পেজ: <b>' + esc(hs.curt || 'কিছু নেই') + '</b>' + (hs.old && hs.oldt ? ' · আগের হোম পেজ: <b>' + esc(hs.oldt) + '</b> (একটুও বদলানো হয়নি)' : '') + '</p></div><div class="ac">';
		if (!hs.exists) { h += '<button class="button button-primary" data-act="hmake">নতুন হোম পেজের খসড়া বানান</button>'; }
		else {
			h += '<a class="button button-large" href="' + at(hs.preview) + '" target="_blank">👁 প্রিভিউ দেখুন ↗</a>';
			if (EV.isAdmin) {
				h += hs.live ? ' <button class="button button-large" data-act="hback">↩ পুরোনো হোম পেজ ফিরিয়ে আনুন</button>' : ' <button class="button button-primary button-large" data-act="hlive">🚀 নতুন হোম পেজ চালু করুন</button>';
			}
		}
		h += '</div></div>';
		h += '<h3>অংশগুলোর ক্রম ও দেখানো/লুকানো</h3><ul class="ev-ord" id="ev-ord">' + H.order.map(function (k) { return '<li data-k="' + k + '"><label><input type="checkbox" class="on"' + (H.off.indexOf(k) < 0 ? ' checked' : '') + '> ' + esc(EV.sections[k]) + '</label><span><button type="button" class="button up">↑</button><button type="button" class="button dn">↓</button></span></li>'; }).join('') + '</ul>';
		h += '<h3>প্রতিটি অংশের লেখা, লিংক ও ছবি</h3><div id="ev-hform">';
		Object.keys(HOME).forEach(function (k) {
			h += '<details class="hs" data-p="' + k + '"><summary>' + esc(HOME[k][0]) + '</summary><div class="fl">' + HOME[k][1].map(function (s) { return fld(s, H[k][s.k]); }).join('') + '</div></details>';
		});
		return h + '</div><p class="ev-save"><button class="button button-primary button-large" data-act="hsave">💾 হোম পেজ সংরক্ষণ করুন</button> <button class="button" data-act="hreset">আসল লেখায় ফিরুন</button></p><p class="description">ইভেন্ট সারির কার্ডগুলো "🎪 ইভেন্টসমূহ" ট্যাব থেকে আসে। নোটিশ, শাখার সংখ্যা ও ব্লগ নিজে নিজে হালনাগাদ হয়।</p>';
	}
	function readHome() {
		var o = {};
		Object.keys(HOME).forEach(function (k) { o[k] = rd(q('.hs[data-p="' + k + '"] .fl'), HOME[k][1]); });
		var ord = qa('#ev-ord li');
		o.order = ord.map(function (l) { return l.getAttribute('data-k'); });
		o.off = ord.filter(function (l) { return !l.querySelector('.on').checked; }).map(function (l) { return l.getAttribute('data-k'); });
		return o;
	}

	function vSet() {
		var h = '<h3>কারা এই পেজ বদলাতে পারবে</h3><p>অ্যাডমিন সবসময় পারেন। নিচের ভূমিকার ব্যক্তিরাও ইভেন্ট ও হোম পেজের লেখা বদলাতে পারবেন (হোম পেজ চালু/বন্ধ শুধু অ্যাডমিন)।</p><div class="evl">';
		EV.allRoles.forEach(function (r) { if (r.k !== 'administrator') { h += '<label class="chk"><input type="checkbox" class="role" value="' + r.k + '"' + (S.s.roles.indexOf(r.k) > -1 ? ' checked' : '') + (EV.isAdmin ? '' : ' disabled') + '> ' + esc(r.l) + '</label>'; } });
		h += '</div>' + (EV.isAdmin ? '<p><button class="button button-primary" data-act="roles">সংরক্ষণ</button></p>' : '');
		return h + '<h3>অন্য পেজে বসানোর শর্টকোড</h3><p><code>[shaheen_events max="3"]</code> — ইভেন্ট কার্ডের সারি। <code>[shaheen_event id="১"]</code> — একটি ইভেন্টের পুরো পেজ। <code>[shaheen_home]</code> — নতুন হোম পেজ।</p>';
	}

	/* ---------------- actions ---------------- */
	app.addEventListener('click', function (e) {
		var t = e.target.closest('button,a');
		if (!t) { return; }
		var act = t.getAttribute('data-act'), id = +t.getAttribute('data-id');
		if (t.classList.contains('pick')) { e.preventDefault(); return pick(t); }
		if (t.classList.contains('clr')) { var f = t.closest('.im'); f.querySelector('.v').value = ''; f.querySelector('.pv').innerHTML = '🖼️'; return; }
		if (t.classList.contains('add')) { e.preventDefault(); return addItem(t); }
		if (t.classList.contains('del') && t.closest('.li')) { if (confirm('এই আইটেমটি মুছবেন?')) { t.closest('.li').remove(); } return; }
		if ((t.classList.contains('up') || t.classList.contains('dn')) && t.closest('.li')) { return move(t.closest('.li'), t.classList.contains('up') ? -1 : 1); }
		if ((t.classList.contains('up') || t.classList.contains('dn')) && t.closest('#ev-ord li')) { return move(t.closest('li'), t.classList.contains('up') ? -1 : 1); }
		if (!act) { return; }
		e.preventDefault();
		if (act === 'new') { S.ed = 'new'; S.etab = 'main'; S.msg = ''; draw(); }
		else if (act === 'edit') { S.ed = id; S.etab = 'main'; S.msg = ''; draw(); }
		else if (act === 'back') { S.ed = null; draw(); }
		else if (act === 'save') { run(post('save', readEvent()), function () { S.ed = null; }); }
		else if (act === 'seed') { run(post('seed')); }
		else if (act === 'pub') { run(post('page', { id: id, to: 'publish' })); }
		else if (act === 'draft') { run(post('page', { id: id, to: 'draft' })); }
		else if (act === 'del') { if (confirm('এই ইভেন্টটি তালিকা থেকে মুছবেন? (তার পেজটি মুছবে না)')) { run(post('delete', id)); } }
		else if (act === 'hsave') { run(post('home', readHome())); }
		else if (act === 'hreset') { if (confirm('হোম পেজের সব লেখা আসল (সাইট থেকে নেওয়া) অবস্থায় ফিরবে। নিশ্চিত?')) { run(post('home_reset')); } }
		else if (act === 'hmake') { run(post('home_make')); }
		else if (act === 'hlive') { if (confirm('নতুন হোম পেজ সবার জন্য চালু হবে। পুরোনো হোম পেজ একটুও বদলাবে না; "ফিরিয়ে আনুন" চাপলে যেকোনো সময় আগেরটা ফিরবে। চালু করবেন?')) { run(post('live')); } }
		else if (act === 'hback') { if (confirm('পুরোনো হোম পেজ আবার চালু হবে। নিশ্চিত?')) { run(post('back')); } }
		else if (act === 'roles') { run(post('roles', qa('.role').filter(function (c) { return c.checked; }).map(function (c) { return c.value; }))); }
	});

	app.addEventListener('input', function (e) {
		var i = e.target;
		if (i.matches && i.matches('.im .v')) { i.closest('.im').querySelector('.pv').innerHTML = i.value ? '<img src="' + at(i.value) + '" alt="">' : '🖼️'; }
	});

	function pick(btn) {
		if (!window.wp || !wp.media) { alert('মিডিয়া লাইব্রেরি লোড হয়নি; ছবির লিংক সরাসরি বসান।'); return; }
		var box = btn.closest('.im');
		var fr = wp.media({ title: 'ছবি বাছুন বা আপলোড করুন', button: { text: 'এই ছবি ব্যবহার করুন' }, library: { type: 'image' }, multiple: false });
		fr.on('select', function () {
			var a = fr.state().get('selection').first().toJSON();
			box.querySelector('.v').value = a.url;
			box.querySelector('.pv').innerHTML = '<img src="' + at(a.url) + '" alt="">';
		});
		fr.open();
	}

	/* find the field spec of a list by walking up the data-f path to its tab / section */
	function findSpec(fEl) {
		var path = [], el = fEl;
		while (el && !(el.matches && el.matches('.pn,.hs'))) {
			if (el.matches && el.matches('.f') && el.hasAttribute('data-f')) { path.unshift(el.getAttribute('data-f')); }
			el = el.parentNode;
		}
		if (!el) { return null; }
		var specs = el.matches('.pn') ? EVENT[el.getAttribute('data-p')][1] : HOME[el.getAttribute('data-p')][1];
		var cur = null;
		path.forEach(function (k) {
			var list = cur ? cur.f : specs;
			cur = list.filter(function (s) { return s.k === k; })[0];
		});
		return cur;
	}
	function addItem(btn) {
		var host = btn.closest('.f');
		var s = findSpec(host);
		if (!s) { return; }
		var items = host.querySelector(':scope > .lst > .items');
		var blank = {};
		if (s.k === 'miles') { blank.st = ''; }
		if (s.k === 'btns') { blank.k = 'p'; blank.home = 1; }
		if (s.k === 'gal') { blank.k = 'photo'; }
		items.insertAdjacentHTML('beforeend', li(s, blank, items.children.length));
		var last = items.lastElementChild;
		last.scrollIntoView({ behavior: 'smooth', block: 'center' });
		var first = last.querySelector('input.v:not([type=checkbox]),textarea.v');
		if (first) { first.focus({ preventScroll: true }); }
	}
	function move(el, d) {
		var sib = d < 0 ? el.previousElementSibling : el.nextElementSibling;
		if (!sib) { return; }
		if (d < 0) { el.parentNode.insertBefore(el, sib); } else { el.parentNode.insertBefore(sib, el); }
	}

	draw();
})();
