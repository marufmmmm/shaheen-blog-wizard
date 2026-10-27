/* Page Builder > শাখা পেজ : admin app */
(function () {
	'use strict';
	var app = document.getElementById('br-app');
	if (!app || !window.BR) { return; }
	var S = { list: BR.list || [], o: BR.o, tab: 'list', q: '', tf: 'all', msg: '', modal: null };
	var TABS = [['list', 'শাখা তালিকা'], ['nomap', 'Map / স্থানাঙ্ক'], ['hero', 'হিরো'], ['hq', 'প্রধান কার্যালয়'], ['btns', 'বোতাম ও CTA'], ['set', 'সেটিংস'], ['data', 'ডেটা']];

	function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
	function attr(t) { return esc(t).replace(/"/g, '&quot;'); }
	function bn(n) { return String(n).replace(/[0-9]/g, function (d) { return '০১২৩৪৫৬৭৮৯'.charAt(+d); }); }
	function q(s, el) { return (el || app).querySelector(s); }
	function qa(s, el) { return [].slice.call((el || app).querySelectorAll(s)); }
	function val(id) { var e = document.getElementById(id); return e ? e.value : ''; }

	function post(op, data) {
		var body = new URLSearchParams({ action: 'csa_br_op', nonce: BR.nonce, op: op, data: JSON.stringify(data == null ? null : data) });
		return fetch(BR.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); }).then(function (j) {
			if (!j.success) { throw new Error(typeof j.data === 'string' ? j.data : 'ত্রুটি'); }
			if (j.data.list) { S.list = j.data.list; }
			if (j.data.o) { S.o = j.data.o; }
			if (j.data.page !== undefined) { BR.page = j.data.page; }
			return j.data;
		});
	}
	function say(m, bad) { S.msg = m ? '<div class="notice ' + (bad ? 'notice-error' : 'notice-success') + ' is-dismissible"><p>' + esc(m) + '</p></div>' : ''; }
	function run(p, ok) {
		return p.then(function (d) { say(ok || (d && d.msg) || 'সংরক্ষিত ✓'); draw(); }).catch(function (e) { say(e.message, true); draw(); });
	}

	function stats() {
		var l = S.list, m = 0, c = 0, p = 0, ds = {}, dv = {};
		l.forEach(function (b) { if (b.map) { m++; } if (b.lat !== '' && b.lng !== '' && b.lat != null) { c++; } if (b.pageLink && b.pageLinkActive) { p++; } ds[b.district] = 1; dv[b.div] = 1; });
		return { n: l.length, m: m, c: c, p: p, ds: Object.keys(ds).length, dv: Object.keys(dv).length };
	}
	function hasC(b) { return b.lat !== '' && b.lng !== '' && b.lat != null; }

	/* ---------- tabs ---------- */
	function draw() {
		var s = stats();
		var h = S.msg + '<div class="br-top"><span class="br-chip"><b>' + bn(s.n) + '</b> শাখা</span><span class="br-chip"><b>' + bn(s.ds) + '</b> জেলা</span><span class="br-chip"><b>' + bn(s.dv) + '</b> বিভাগ</span><span class="br-chip ' + (s.m < s.n ? 'warn' : '') + '">🗺 Map আছে <b>' + bn(s.m) + '</b>/' + bn(s.n) + '</span><span class="br-chip">📡 স্থানাঙ্ক <b>' + bn(s.c) + '</b></span><span class="br-chip">🔗 শাখা-পেজ <b>' + bn(s.p) + '</b></span>';
		if (BR.page) { h += '<a class="button" href="' + attr(BR.page.url) + '" target="_blank">পেজ দেখুন ↗</a>'; }
		h += '</div><h2 class="nav-tab-wrapper">' + TABS.map(function (t) { return '<a href="#" class="nav-tab' + (S.tab === t[0] ? ' nav-tab-active' : '') + '" data-tab="' + t[0] + '">' + t[1] + '</a>'; }).join('') + '</h2><div class="br-body">';
		h += ({ list: tList, nomap: tNoMap, hero: tHero, hq: tHq, btns: tBtns, set: tSet, data: tData })[S.tab]();
		h += '</div>' + (S.modal ? modal() : '');
		app.innerHTML = h;
		bind();
	}

	/* ---------- list ---------- */
	function typeTag(t) { return t === 'islamic' ? '<span class="br-tt i">☽ ইসলামিক</span>' : (t === 'english' ? '<span class="br-tt e">EN</span>' : ''); }
	function tList() {
		var types = S.o.types, term = S.q.toLowerCase();
		var rows = S.list.filter(function (b) {
			if (S.tf !== 'all' && b.type !== S.tf) { return false; }
			return !term || (b.name + ' ' + b.addr + ' ' + b.district + ' ' + b.div).toLowerCase().indexOf(term) > -1;
		});
		var h = '<div class="br-bar"><input id="br-q" type="search" placeholder="🔍 শাখার নাম বা ঠিকানা…" value="' + attr(S.q) + '"><select id="br-tf"><option value="all">সব ধরন</option>' + Object.keys(types).map(function (k) { return '<option value="' + k + '"' + (S.tf === k ? ' selected' : '') + '>' + esc(types[k].label) + '</option>'; }).join('') + '</select><button class="button button-primary" data-act="new">+ নতুন শাখা</button><span class="br-cnt">' + bn(rows.length) + 'টি দেখাচ্ছে</span></div>';
		if (!S.list.length) {
			return h + '<div class="br-empty"><p>এখনো কোনো শাখা নেই।</p><button class="button button-primary button-hero" data-act="seed">📥 আপনার ' + bn(BR.seedN) + 'টি শাখা এক ক্লিকে আনুন</button> <button class="button" data-tabgo="data">অন্য উপায়ে আনুন</button></div>';
		}
		var g = {};
		rows.forEach(function (b) { var dv = b.div || 'অন্যান্য', ds = b.district || dv; (g[dv] = g[dv] || {}); (g[dv][ds] = g[dv][ds] || []).push(b); });
		var order = S.o.div_order.slice();
		Object.keys(g).forEach(function (k) { if (order.indexOf(k) < 0) { order.push(k); } });
		order.forEach(function (dv) {
			if (!g[dv]) { return; }
			var n = 0; Object.keys(g[dv]).forEach(function (d) { n += g[dv][d].length; });
			h += '<details class="br-dv"' + (term || S.tf !== 'all' ? ' open' : '') + '><summary><b>📍 ' + esc(dv) + ' বিভাগ</b> <span class="br-n">' + bn(n) + '</span></summary>';
			Object.keys(g[dv]).sort(function (a, b) { var x = S.o.dist_order.indexOf(a), y = S.o.dist_order.indexOf(b); x = x < 0 ? 99 : x; y = y < 0 ? 99 : y; return x - y || a.localeCompare(b, 'bn'); }).forEach(function (ds) {
				h += '<details class="br-ds"' + (term ? ' open' : '') + '><summary>' + esc(ds) + ' <span class="br-n">' + bn(g[dv][ds].length) + '</span></summary>';
				g[dv][ds].forEach(function (b) {
					h += '<div class="br-row"><span class="br-nm">' + esc(b.name) + typeTag(b.type) + '</span><span class="br-dots"><i class="' + (b.map ? 'y' : 'n') + '" title="Map">🗺</i><i class="' + (hasC(b) ? 'y' : 'n') + '" title="স্থানাঙ্ক (GPS)">📡</i><i class="' + (b.pageLink ? (b.pageLinkActive ? 'y' : 'p') : 'n') + '" title="শাখার পেজ">🔗</i></span><span class="br-act"><button class="button button-small" data-mv="-1" data-id="' + b.id + '" title="উপরে">↑</button><button class="button button-small" data-mv="1" data-id="' + b.id + '" title="নিচে">↓</button><button class="button button-small" data-edit="' + b.id + '">✎ বদলান</button></span></div>';
				});
				h += '</details>';
			});
			h += '</details>';
		});
		return h;
	}

	/* ---------- modal ---------- */
	function modal() {
		var b = S.modal, t = S.o.types, dists = {};
		S.list.forEach(function (x) { if (x.district) { dists[x.district] = 1; } });
		var h = '<div class="br-ov"><div class="br-mb"><div class="br-mh"><h3>' + (b.id ? '✎ ' + esc(b.name) : '+ নতুন শাখা') + '</h3><button class="button" data-act="close">✕</button></div><div class="br-mbody">';
		h += '<div class="br-tp">' + Object.keys(t).map(function (k) { return '<label class="' + (b.type === k ? 'on' : '') + '"><input type="radio" name="m-type" value="' + k + '"' + (b.type === k ? ' checked' : '') + '> ' + esc(t[k].icon + ' ' + t[k].label) + '</label>'; }).join('') + '</div>';
		h += f('শাখার নাম', '<input id="m-name" value="' + attr(b.name) + '" placeholder="যেমন: উত্তরা শাখা">');
		h += '<div class="br-2">' + f('বিভাগ', '<select id="m-div">' + S.o.div_order.map(function (d) { return '<option' + (b.div === d ? ' selected' : '') + '>' + esc(d) + '</option>'; }).join('') + '</select>') + f('জেলা', '<input id="m-dist" list="m-dl" value="' + attr(b.district) + '" placeholder="যেমন: গাজীপুর"><datalist id="m-dl">' + Object.keys(dists).map(function (d) { return '<option value="' + attr(d) + '">'; }).join('') + '</datalist>') + '</div>';
		h += f('ঠিকানা', '<textarea id="m-addr" rows="2">' + esc(b.addr) + '</textarea>');
		h += f('ফোন (একাধিক হলে কমা বা নতুন লাইন দিয়ে)', '<textarea id="m-ph" rows="2" placeholder="০১৮০৫-০৩০৩৫৬, ০১৩৩৪-৯৩৩২৫৪">' + esc((b.phones || []).join(', ')) + '</textarea>');
		h += f('🗺️ Google Map লিংক', '<input id="m-map" value="' + attr(b.map) + '" placeholder="https://maps.app.goo.gl/…"><span class="br-st ' + (b.map ? 'y' : 'n') + '" id="m-mapst">' + (b.map ? 'Map আছে ✓' : 'Map নেই') + '</span>');
		h += '<div class="br-2">' + f('📡 অক্ষাংশ (lat)', '<input id="m-lat" value="' + attr(b.lat) + '" placeholder="23.8753">') + f('📡 দ্রাঘিমাংশ (lng)', '<input id="m-lng" value="' + attr(b.lng) + '" placeholder="90.3928"></div>') + '<p class="description">লম্বা Google Map লিংক দিলে স্থানাঙ্ক নিজে বসে যাবে। ছোট (maps.app.goo.gl) লিংকে স্থানাঙ্ক থাকে না — Google Maps-এ জায়গায় ডান-ক্লিক করে সংখ্যাদুটো কপি করে এখানে বসান। স্থানাঙ্ক না থাকলে এই শাখা GPS-এ আসবে না, জেলা দিয়ে আসবে।</p>';
		h += '<div class="br-pl"><label>🔗 শাখার নিজের পেজ (পরে বানানো হবে)</label><input id="m-pl" value="' + attr(b.pageLink) + '" placeholder="https://shaheen.edu.bd/branch/…"><label class="br-ck"><input type="checkbox" id="m-pla"' + (b.pageLinkActive ? ' checked' : '') + '> Active করুন (তালিকায় "শাখা দেখুন →" বোতাম আসবে)</label></div>';
		h += '<div class="br-mf"><button class="button button-primary button-large" data-act="save">💾 সংরক্ষণ</button>' + (b.id ? '<button class="button button-link-delete" data-act="del">🗑 মুছুন</button>' : '') + '</div></div></div></div>';
		return h;
	}
	function f(l, i) { return '<div class="br-f"><label>' + l + '</label>' + i + '</div>'; }
	function readModal() {
		var t = qa('input[name=m-type]:checked')[0];
		return { id: S.modal.id || 0, type: t ? t.value : 'main', name: val('m-name'), div: val('m-div'), district: val('m-dist'), addr: val('m-addr'), phones: val('m-ph'), map: val('m-map'), lat: val('m-lat'), lng: val('m-lng'), pageLink: val('m-pl'), pageLinkActive: document.getElementById('m-pla').checked };
	}
	function blank() { return { id: 0, type: 'main', name: '', div: S.o.div_order[0] || '', district: '', addr: '', phones: [], map: '', lat: '', lng: '', pageLink: '', pageLinkActive: false }; }

	/* ---------- Map / coords ---------- */
	function tNoMap() {
		var nm = S.list.filter(function (b) { return !b.map; }), nc = S.list.filter(function (b) { return b.map && !hasC(b); });
		var h = '<p class="description">যেসব শাখায় Map লিংক নেই, লিংক বসান — সংরক্ষণ চাপলেই হবে।</p>';
		if (!nm.length) { h += '<p><b>✓ সব শাখায় Map আছে।</b></p>'; }
		nm.forEach(function (b) { h += '<div class="br-nm-item"><b>' + esc(b.name) + '</b> <small>' + esc(b.addr) + '</small><div class="br-inl"><input data-nm="' + b.id + '" placeholder="Google Map লিংক"><button class="button" data-nmsave="' + b.id + '">সংরক্ষণ</button></div></div>'; });
		h += '<hr><h3>📡 GPS-এর জন্য স্থানাঙ্ক</h3><p class="description">Map আছে কিন্তু স্থানাঙ্ক নেই: <b>' + bn(nc.length) + '</b>টি। প্রথমে নিচের বোতামে লম্বা লিংক থেকে নিজে বের করুন। যেগুলো বের হবে না (ছোট লিংক), সেগুলোতে হাতে lat, lng বসান।</p><p><button class="button button-primary" data-act="coords">📡 Map লিংক থেকে স্থানাঙ্ক বের করুন</button></p>';
		nc.forEach(function (b) { h += '<div class="br-nm-item"><b>' + esc(b.name) + '</b> <small>' + esc(b.district) + '</small><div class="br-inl"><input data-ll="' + b.id + '" placeholder="অক্ষাংশ, দ্রাঘিমাংশ — যেমন: 23.8753, 90.3928"><button class="button" data-llsave="' + b.id + '">সংরক্ষণ</button></div></div>'; });
		return h;
	}

	/* ---------- hero ---------- */
	function tHero() {
		var o = S.o.hero, st = o.stats.slice();
		while (st.length < 4) { st.push({ n: '', l: '' }); }
		var h = '<div class="br-2">' + f('শিরোনাম — সাদা অংশ', '<input id="h-a" value="' + attr(o.h1a) + '">') + f('শিরোনাম — সবুজ অংশ', '<input id="h-b" value="' + attr(o.h1b) + '">') + '</div>' + f('নিচের ছোট লেখা', '<input id="h-s" value="' + attr(o.sub) + '">');
		st.slice(0, 4).forEach(function (s, i) { h += '<div class="br-2">' + f('স্ট্যাট ' + bn(i + 1) + ' — সংখ্যা', '<input id="h-n' + i + '" value="' + attr(s.n) + '">') + f('লেখা', '<input id="h-l' + i + '" value="' + attr(s.l) + '">') + '</div>'; });
		return h + '<p><button class="button" data-act="count">🔢 আসল সংখ্যা বসান (শাখা, জেলা, বিভাগ)</button> <button class="button button-primary" data-act="hero">💾 সংরক্ষণ</button></p>';
	}

	/* ---------- hq ---------- */
	function tHq() {
		var o = S.o.hq;
		return f('কার্যালয়ের নাম', '<input id="q-n" value="' + attr(o.name) + '">') + f('ঠিকানা', '<input id="q-a" value="' + attr(o.addr) + '">') + f('ফোন (কমা দিয়ে আলাদা)', '<textarea id="q-p" rows="2">' + esc((o.phones || []).join(', ')) + '</textarea>') + '<div class="br-2">' + f('WhatsApp নম্বর', '<input id="q-w" value="' + attr(o.wa) + '" placeholder="8801978703936">') + f('Google Map লিংক', '<input id="q-m" value="' + attr(o.map) + '">') + '</div><p><button class="button button-primary" data-act="hq">💾 সংরক্ষণ</button></p>';
	}

	/* ---------- buttons + CTA ---------- */
	function tBtns() {
		var a = S.o.actions.slice(), c = S.o.cta;
		while (a.length < 4) { a.push({ ic: '', t: '', u: '' }); }
		var h = '<h3>চারটা দ্রুত বোতাম</h3><p class="description">লিংক খালি রাখলে বোতামটা পেজে দেখাবে না।</p>';
		a.slice(0, 4).forEach(function (x, i) { h += '<div class="br-3"><input id="a-i' + i + '" value="' + attr(x.ic) + '" placeholder="আইকন"><input id="a-t' + i + '" value="' + attr(x.t) + '" placeholder="লেখা"><input id="a-u' + i + '" value="' + attr(x.u) + '" placeholder="https://…"></div>'; });
		h += '<h3>নিচের হলুদ বক্স (CTA)</h3>' + f('শিরোনাম (খালি রাখলে বক্স থাকবে না)', '<input id="c-t" value="' + attr(c.t) + '">') + f('ছোট লেখা', '<input id="c-p" value="' + attr(c.p) + '">') + '<div class="br-2">' + f('বোতামের লেখা', '<input id="c-bt" value="' + attr(c.bt) + '">') + f('বোতামের লিংক', '<input id="c-bu" value="' + attr(c.bu) + '">') + '</div>';
		return h + '<p><button class="button button-primary" data-act="btns">💾 সংরক্ষণ</button></p>';
	}

	/* ---------- settings ---------- */
	function tSet() {
		var o = S.o, h = '';
		h += '<h3>🔔 পেজ</h3>';
		if (BR.page) {
			h += '<p>পেজ: <a href="' + attr(BR.page.url) + '" target="_blank"><b>' + esc(BR.page.title) + '</b> <small>' + esc(BR.page.url) + '</small></a> — অবস্থা: ' + (o.replace ? '<b style="color:#1a6b3e">plugin চালাচ্ছে ✓</b>' : '<b style="color:#b06">এখনো আপনার পুরোনো পেজ দেখাচ্ছে</b>') + '</p><p>' + (o.replace ? '<button class="button" data-act="off">পুরোনো পেজে ফেরান</button>' : '<button class="button button-primary button-large" data-act="on">এই পেজ plugin-এর হাতে দিন</button>') + '</p><p class="description">আগে "ডেটা" ট্যাব থেকে শাখাগুলো আনুন। পেজের ঠিকানা বদলাবে না। আপনার পুরোনো HTML পেজেই সংরক্ষিত থাকে, "পুরোনো পেজে ফেরান" চাপলে আগের মতো হয়ে যাবে।</p>';
		} else {
			h += '<p>"branch" নামের পেজ পাওয়া যায়নি। ওয়ার্ডপ্রেসে পেজটা বানিয়ে নিন (ঠিকানা: <code>branch</code>), অথবা যেকোনো পেজে <code>[shaheen_branches]</code> বসান।</p>';
		}
		h += '<h3>📍 "কাছের শাখা"</h3><p>' + [['both', 'জেলা লেখা + GPS দুটোই'], ['district', 'শুধু জেলা লেখা'], ['gps', 'শুধু GPS'], ['none', 'বন্ধ']].map(function (x) { return '<label class="br-ck"><input type="radio" name="s-nb" value="' + x[0] + '"' + (o.nearby === x[0] ? ' checked' : '') + '> ' + x[1] + '</label>'; }).join(' &nbsp; ') + '</p>';
		h += f('প্রথমে কোন বিভাগ দেখাবে', '<select id="s-def"><option value="all"' + (o.default_view === 'all' ? ' selected' : '') + '>সকল বিভাগ</option>' + o.div_order.map(function (d) { return '<option value="div:' + attr(d) + '"' + (o.default_view === 'div:' + d ? ' selected' : '') + '>' + esc(d) + '</option>'; }).join('') + '</select>');
		if (BR.isAdmin) {
			h += '<h3>👥 কারা শাখা বদলাতে পারবে</h3><p class="description">অ্যাডমিন সবসময় পারবেন। এখানে আরও কোন ভূমিকার (role) ব্যবহারকারী পারবেন, টিক দিন।</p><p>' + BR.roles.filter(function (r) { return r.k !== 'administrator'; }).map(function (r) { return '<label class="br-ck"><input type="checkbox" data-role="' + r.k + '"' + (o.roles.indexOf(r.k) > -1 ? ' checked' : '') + '> ' + esc(r.l) + '</label>'; }).join(' &nbsp; ') + '</p>';
		}
		h += '<h3>বিভাগ ও জেলার ক্রম</h3>' + f('বিভাগের ক্রম (প্রতি লাইনে একটা)', '<textarea id="s-dv" rows="5">' + esc(o.div_order.join('\n')) + '</textarea>') + f('যে জেলাগুলো সবার আগে (প্রতি লাইনে একটা, বাকিগুলো বর্ণানুক্রমে)', '<textarea id="s-ds" rows="3">' + esc(o.dist_order.join('\n')) + '</textarea>');
		h += '<h3>এলাকার ডাকনাম (জেলার নাম লিখলে কোন জেলা দেখাবে)</h3>' + f('প্রতি লাইনে: এলাকা = জেলা  (যেমন: উত্তরা = ঢাকা)', '<textarea id="s-lm" rows="6">' + esc(Object.keys(o.lm).map(function (k) { return k + ' = ' + o.lm[k]; }).join('\n')) + '</textarea>');
		h += '<h3>শাখার ধরনের লেখা</h3>';
		Object.keys(o.types).forEach(function (k) { var t = o.types[k]; h += '<div class="br-4" data-ty="' + k + '"><input data-f="icon" value="' + attr(t.icon) + '" placeholder="আইকন"><input data-f="label" value="' + attr(t.label) + '" placeholder="নাম"><input data-f="kicker" value="' + attr(t.kicker) + '" placeholder="ঠিকানার আগের লেখা"><input data-f="tag" value="' + attr(t.tag) + '" placeholder="ট্যাগ"></div>'; });
		return h + '<p><button class="button button-primary button-large" data-act="settings">💾 সংরক্ষণ</button></p>';
	}

	/* ---------- data ---------- */
	function tData() {
		return '<h3>📥 শাখা আনুন</h3><p><button class="button button-primary" data-act="seed">আপনার ' + bn(BR.seedN) + 'টি শাখা আনুন (plugin-এ রাখা)</button> <button class="button" data-act="page">পেজ থেকে পড়ে আনুন</button></p><p class="description">আগে থাকা শাখা (একই নাম, জেলা ও ধরন) দ্বিগুণ হবে না — তবে ওগুলো ইমপোর্টের তথ্য দিয়ে বদলে যাবে, তাই আপনি হাতে বদলালে আবার ইমপোর্ট করবেন না। হিরো ও কার্যালয়ের তথ্যও ইমপোর্টের তথ্যে ফিরে যায়। "পেজ থেকে পড়ে আনুন" সবচেয়ে হালনাগাদ তালিকা আনে, তবে পেজ plugin-এর হাতে যাওয়ার আগে করতে হবে।</p>'
			+ '<h3>JSON ফাইল</h3><p><input type="file" id="d-file" accept=".json"> <button class="button" data-act="json">আনুন</button> <a class="button" href="' + attr(BR.export) + '">📤 JSON ব্যাকআপ নামান</a></p><p class="description">আগের পেজের অ্যাডমিন থেকে নেওয়া JSON ব্যাকআপও চলবে।</p>'
			+ (BR.isAdmin ? '<h3>⚠️ সব শাখা মুছুন</h3><p><button class="button button-link-delete" data-act="wipe">সব শাখা মুছুন</button> <small>আগে ব্যাকআপ নামিয়ে নিন।</small></p>' : '');
	}

	/* ---------- events ---------- */
	function bind() {
		qa('[data-tab]').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); S.tab = a.getAttribute('data-tab'); S.msg = ''; draw(); }); });
		qa('[data-tabgo]').forEach(function (a) { a.addEventListener('click', function () { S.tab = a.getAttribute('data-tabgo'); draw(); }); });
		var sq = document.getElementById('br-q');
		if (sq) { sq.addEventListener('input', function () { S.q = sq.value; clearTimeout(bind.t); bind.t = setTimeout(function () { draw(); var n = document.getElementById('br-q'); if (n) { n.focus(); n.setSelectionRange(n.value.length, n.value.length); } }, 250); }); }
		var tf = document.getElementById('br-tf');
		if (tf) { tf.addEventListener('change', function () { S.tf = tf.value; draw(); }); }
		qa('[data-edit]').forEach(function (b) { b.addEventListener('click', function () { var id = +b.getAttribute('data-edit'); S.modal = JSON.parse(JSON.stringify(S.list.filter(function (x) { return x.id === id; })[0])); draw(); }); });
		qa('[data-mv]').forEach(function (b) { b.addEventListener('click', function () { run(post('move', { id: +b.getAttribute('data-id'), dir: +b.getAttribute('data-mv') }), ' '); }); });
		qa('[data-nmsave]').forEach(function (b) { b.addEventListener('click', function () { var id = +b.getAttribute('data-nmsave'), u = q('[data-nm="' + id + '"]').value, x = S.list.filter(function (y) { return y.id === id; })[0]; x = JSON.parse(JSON.stringify(x)); x.map = u; run(post('upsert', x)); }); });
		qa('[data-llsave]').forEach(function (b) { b.addEventListener('click', function () { var id = +b.getAttribute('data-llsave'), v = q('[data-ll="' + id + '"]').value.split(/[,\s]+/).filter(Boolean), x = JSON.parse(JSON.stringify(S.list.filter(function (y) { return y.id === id; })[0])); if (v.length < 2) { say('দুটো সংখ্যা দিন (অক্ষাংশ, দ্রাঘিমাংশ)', true); draw(); return; } x.lat = v[0]; x.lng = v[1]; run(post('upsert', x)); }); });
		var mm = document.getElementById('m-map');
		if (mm) {
			mm.addEventListener('input', function () {
				var u = mm.value, st = document.getElementById('m-mapst'), m = decodeURIComponent(u).match(/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)|@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/);
				st.className = 'br-st ' + (u ? 'y' : 'n'); st.textContent = u ? 'Map আছে ✓' + (m ? ' — স্থানাঙ্ক পাওয়া গেছে' : '') : 'Map নেই';
				if (m) { document.getElementById('m-lat').value = m[1] || m[3]; document.getElementById('m-lng').value = m[2] || m[4]; }
			});
		}
		qa('input[name=m-type]').forEach(function (r) { r.addEventListener('change', function () { qa('.br-tp label').forEach(function (l) { l.classList.toggle('on', l.querySelector('input').checked); }); }); });
		qa('[data-act]').forEach(function (b) { b.addEventListener('click', function () { act(b.getAttribute('data-act')); }); });
	}

	function act(a) {
		if (a === 'new') { S.modal = blank(); draw(); return; }
		if (a === 'close') { S.modal = null; draw(); return; }
		if (a === 'save') { var d = readModal(); if (!d.name.trim()) { alert('শাখার নাম লিখুন'); return; } S.modal = null; run(post('upsert', d), 'শাখা সংরক্ষিত ✓'); return; }
		if (a === 'del') { if (confirm('এই শাখা মুছে ফেলবেন?')) { var id = S.modal.id; S.modal = null; run(post('delete', id), 'শাখা মোছা হয়েছে'); } return; }
		if (a === 'seed') { run(post('import_seed')); return; }
		if (a === 'page') { run(post('import_page')); return; }
		if (a === 'coords') { run(post('coords')); return; }
		if (a === 'on' || a === 'off') { run(post('connect', { on: a === 'on' ? 1 : 0 }), a === 'on' ? 'পেজ এখন plugin চালাচ্ছে ✓ (LiteSpeed Purge All করুন)' : 'পুরোনো অবস্থায় ফেরানো হয়েছে'); return; }
		if (a === 'wipe') { if (confirm('সত্যিই সব শাখা মুছবেন? ব্যাকআপ নিয়েছেন তো?')) { run(post('wipe'), 'সব শাখা মোছা হয়েছে'); } return; }
		if (a === 'json') {
			var fl = document.getElementById('d-file').files[0];
			if (!fl) { say('একটা JSON ফাইল বাছুন', true); draw(); return; }
			var rd = new FileReader();
			rd.onload = function () { var j; try { j = JSON.parse(rd.result); } catch (e) { say('ফাইলটা ঠিক JSON নয়', true); draw(); return; } run(post('import_json', j)); };
			rd.readAsText(fl); return;
		}
		if (a === 'count') { var s = stats(); document.getElementById('h-n0').value = bn(s.n) + '+'; document.getElementById('h-n1').value = bn(s.ds); document.getElementById('h-n2').value = bn(s.dv); return; }
		if (a === 'hero') { var st = []; for (var i = 0; i < 4; i++) { st.push({ n: val('h-n' + i), l: val('h-l' + i) }); } run(post('sec', { key: 'hero', val: { h1a: val('h-a'), h1b: val('h-b'), sub: val('h-s'), stats: st } })); return; }
		if (a === 'hq') { run(post('sec', { key: 'hq', val: { name: val('q-n'), addr: val('q-a'), phones: val('q-p'), wa: val('q-w'), map: val('q-m') } })); return; }
		if (a === 'btns') {
			var ac = []; for (var k = 0; k < 4; k++) { ac.push({ ic: val('a-i' + k), t: val('a-t' + k), u: val('a-u' + k) }); }
			post('sec', { key: 'actions', val: ac }).then(function () { return post('sec', { key: 'cta', val: { t: val('c-t'), p: val('c-p'), bt: val('c-bt'), bu: val('c-bu') } }); }).then(function () { say('সংরক্ষিত ✓'); draw(); }).catch(function (e) { say(e.message, true); draw(); });
			return;
		}
		if (a === 'settings') {
			var nb = q('input[name=s-nb]:checked'), types = {};
			qa('[data-ty]').forEach(function (r) { var t = {}; qa('[data-f]', r).forEach(function (i) { t[i.getAttribute('data-f')] = i.value; }); types[r.getAttribute('data-ty')] = t; });
			run(post('sec', { key: 'settings', val: { nearby: nb ? nb.value : 'both', default_view: val('s-def'), div_order: val('s-dv'), dist_order: val('s-ds'), lm: val('s-lm'), roles: qa('[data-role]:checked').map(function (c) { return c.getAttribute('data-role'); }), types: types } }));
		}
	}
	draw();
})();
