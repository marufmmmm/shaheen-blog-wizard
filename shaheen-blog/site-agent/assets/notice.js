/* Notice form (edit screen of the "নোটিশ" post type) */
(function () {
	'use strict';
	var app = document.getElementById('sn-app'), inp = document.getElementById('sn_data');
	if (!app || !inp || !window.SN) return;
	var D = {};
	try { D = JSON.parse(inp.value || '{}') || {}; } catch (e) { D = {}; }
	if (Array.isArray(D)) D = {};
	D.blocks = D.blocks || [];
	var TYPES = SN.types;
	var KIND = { cards: 'তথ্য-কার্ড', list: 'তালিকা (শর্ত ইত্যাদি)', text: 'লেখা', note: 'বিশেষ নোট', image: 'ছবি', buttons: 'বোতাম (আবেদন, PDF, ফেসবুক)', phones: 'ফোন নম্বর', video: 'ভিডিও (YouTube/Facebook)', html: 'নিজের কোড', gform: 'অনলাইন আবেদন ফরম (Google Form)' };
	var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ESC[c]; }); }
	function save() { inp.value = JSON.stringify(D); }
	function typ() { return TYPES.filter(function (t) { return t.key === D.type; })[0] || TYPES[0] || { key: '', topic: '', cards: [] }; }
	function $$(s, r) { return [].slice.call((r || app).querySelectorAll(s)); }

	function defaults() {
		var t = typ();
		return [
			{ t: 'cards', rows: t.cards.map(function (l) { return { l: l, v: '' }; }) },
			{ t: 'list', h: 'শর্তসমূহ', lines: '' },
			{ t: 'note', body: '' },
			{ t: 'image', ids: [], urls: [], cap: '' },
			{ t: 'buttons', items: [{ label: 'অনলাইনে আবেদন', url: '', kind: 'apply' }, { label: 'PDF ডাউনলোড', url: '', kind: 'pdf' }, { label: 'ফেসবুক পোস্ট', url: '', kind: 'fb' }] },
			{ t: 'phones', h: 'বিস্তারিত যোগাযোগ করুন:', nums: '' }
		];
	}
	function blank(t) {
		return { cards: { t: 'cards', rows: [{ l: '', v: '' }] }, list: { t: 'list', h: '', lines: '' }, text: { t: 'text', h: '', body: '' }, note: { t: 'note', body: '' }, image: { t: 'image', ids: [], urls: [], cap: '' }, buttons: { t: 'buttons', items: [{ label: '', url: '', kind: 'oth' }] }, phones: { t: 'phones', h: '', nums: '' }, video: { t: 'video', h: '', url: '' }, html: { t: 'html', code: '' }, gform: { t: 'gform', h: 'অনলাইনে আবেদন করুন', url: '', height: 900 } }[t];
	}
	function cardHint(l) {
		l = l || '';
		if (/ভেন্যু|স্থান|ঠিকানা|কেন্দ্র/.test(l)) return { ph: 'পূর্ণ ঠিকানা লিখুন', tip: '💡 পূর্ণ ঠিকানা লিখুন: প্রতিষ্ঠানের নাম, এলাকা, উপজেলা, জেলা। যেমন: শাহীন স্কুল এন্ড ক্যাডেট কোচিং, ঝিলমিল, মাদারীপুর সদর, মাদারীপুর। (প্রার্থী যেন সহজে খুঁজে পায়)' };
		if (/সময়|তারিখ|দিন/.test(l)) return { ph: 'যেমন: ১৮ নভেম্বর ২০২৬, রাত ১০টা', tip: '' };
		return { ph: 'মান লিখুন', tip: '' };
	}
	function blankRow(t) { return t === 'cards' ? { l: '', v: '' } : { label: '', url: '', kind: 'oth' }; }
	if (!D.type) D.type = TYPES[0] ? TYPES[0].key : '';
	if (!D.year) D.year = SN.year;
	if (!D.blocks.length) D.blocks = defaults();

	function field(label, html) { return '<label class="sn-f"><span>' + label + '</span>' + html + '</label>'; }
	function aif(label, key, inner) { return '<div class="sn-f sn-af"><span>' + label + ' <button type="button" class="button button-small" data-aif="' + key + '">✨ AI</button></span>' + inner + '</div>'; }
	function opt(v, cur, lab) { return '<option value="' + v + '"' + (v === cur ? ' selected' : '') + '>' + lab + '</option>'; }

	function blockHTML(b, i) {
		var h = '<div class="sn-b" data-i="' + i + '"><div class="sn-bh"><b>' + esc(KIND[b.t] || b.t) + '</b><span><button type="button" class="button button-small" data-m="up">↑</button> <button type="button" class="button button-small" data-m="down">↓</button> <button type="button" class="button button-small" data-m="del">🗑</button></span></div><div class="sn-bb">';
		if (b.t === 'cards') {
			h += b.rows.map(function (r, k) { return '<div class="sn-r"><input data-p="rows.' + k + '.l" value="' + esc(r.l) + '" placeholder="নাম (যেমন: আবেদনের শেষ সময়)"><input data-p="rows.' + k + '.v" value="' + esc(r.v) + '" placeholder="' + esc(cardHint(r.l).ph) + '">' + (/ভেন্যু|স্থান|ঠিকানা|কেন্দ্র/.test(r.l) ? '<input data-p="rows.' + k + '.m" value="' + esc(r.m || '') + '" placeholder="ম্যাপ লিংক (Google Maps, ঐচ্ছিক — খালি রাখলে ঠিকানা দিয়ে নিজে খুঁজবে)">' : '') + '<button type="button" class="button button-small" data-rd="rows.' + k + '">✕</button></div>' + (cardHint(r.l).tip ? '<div class="sn-hint">' + esc(cardHint(r.l).tip) + '</div>' : ''); }).join('') + '<button type="button" class="button button-small" data-ra="rows">+ কার্ড</button>';
		} else if (b.t === 'list') {
			h += '<input data-p="h" value="' + esc(b.h) + '" placeholder="শিরোনাম (যেমন: শর্তসমূহ)"><textarea data-p="lines" rows="6" placeholder="প্রতি লাইনে একটা শর্ত। পুরো লেখা একসাথে পেস্ট করলেও প্রতি লাইন আলাদা শর্ত হবে।">' + esc(b.lines) + '</textarea>';
		} else if (b.t === 'text') {
			h += '<input data-p="h" value="' + esc(b.h) + '" placeholder="শিরোনাম"><textarea data-p="body" rows="5" placeholder="প্রতি লাইন একটা অনুচ্ছেদ">' + esc(b.body) + '</textarea>';
		} else if (b.t === 'note') {
			h += '<textarea data-p="body" rows="2" placeholder="যেমন: প্রশ্ন থাকলে ফেসবুক পোস্টে কমেন্ট করুন">' + esc(b.body) + '</textarea>';
		} else if (b.t === 'image') {
			h += '<div class="sn-th">' + (b.urls || []).map(function (u, k) { return '<span><img src="' + esc(u) + '"><button type="button" data-id="' + k + '">✕</button></span>'; }).join('') + '</div><button type="button" class="button" data-pick="1">🖼 ছবি বাছুন / যোগ করুন (একাধিক হতে পারে)</button> <input data-p="cap" value="' + esc(b.cap) + '" placeholder="ছবির নিচে লেখা (ঐচ্ছিক)">';
		} else if (b.t === 'buttons') {
			h += b.items.map(function (x, k) { return '<div class="sn-r"><select data-p="items.' + k + '.kind">' + opt('apply', x.kind, 'আবেদন') + opt('pdf', x.kind, 'PDF (Drive লিংক চলবে)') + opt('fb', x.kind, 'ফেসবুক') + opt('oth', x.kind, 'অন্য') + '</select><input data-p="items.' + k + '.label" value="' + esc(x.label) + '" placeholder="বোতামের লেখা"><input data-p="items.' + k + '.url" value="' + esc(x.url) + '" placeholder="লিংক পেস্ট করুন (খালি রাখলে বোতাম দেখাবে না)"><button type="button" class="button button-small" data-rd="items.' + k + '">✕</button></div>'; }).join('') + '<button type="button" class="button button-small" data-ra="items">+ বোতাম</button>';
		} else if (b.t === 'phones') {
			h += '<input data-p="h" value="' + esc(b.h) + '" placeholder="শিরোনাম"><textarea data-p="nums" rows="3" placeholder="মোবাইল নম্বর বা ইমেইল — কমা (,) দিয়ে আলাদা করুন অথবা প্রতি লাইনে একটা। যেমন: 01712-558311, 01800-000000, hr@shaheen.edu.bd">' + esc(b.nums) + '</textarea>';
		} else if (b.t === 'video') {
			h += '<input data-p="h" value="' + esc(b.h) + '" placeholder="শিরোনাম (ঐচ্ছিক)"><input data-p="url" value="' + esc(b.url) + '" placeholder="YouTube বা Facebook ভিডিও/রিল লিংক">';
		} else if (b.t === 'gform') {
			h += '<input data-p="h" value="' + esc(b.h) + '" placeholder="শিরোনাম (যেমন: অনলাইনে আবেদন করুন)"><input data-p="url" value="' + esc(b.url) + '" placeholder="Google Form-এর লিংক (Send বাটন থেকে কপি করা)"><input data-p="height" type="number" min="400" max="3000" value="' + esc(b.height || 900) + '" placeholder="ফরমের উচ্চতা (px)"><div class="sn-hint">💡 Google Form → Send → লিংক কপি করে এখানে বসান। পেজের ভেতরেই ফরম খুলবে; নিচে "নতুন ট্যাবে খুলুন" বোতামও থাকবে। ফরমে ফাইল আপলোড থাকলে বা সাইন-ইন দরকার হলে ভেতরে নাও চলতে পারে, তখন ওই বোতাম ব্যবহার করুন।</div>';
		} else if (b.t === 'html') {
			h += '<textarea data-p="code" rows="5" placeholder="এমবেড বা HTML কোড">' + esc(b.code) + '</textarea>';
		}
		return h + '</div></div>';
	}


	function brName(id) { var r = (SN.brlist || []).filter(function (b) { return b.i === id; })[0]; return r ? r.n : ''; }
	function brSync() {
		var n = (D.br || []).map(brName).filter(Boolean);
		if (n.length) { D.branch = n.join(', '); var i = app.querySelector('#sn-br'); if (i) i.value = D.branch; }
	}
	function brUI() {
		var chips = app.querySelector('#sn-brchips'), inp = app.querySelector('#sn-brq'), res = app.querySelector('#sn-brres');
		if (!chips) return;
		D.br = D.br || [];
		function paint() {
			chips.innerHTML = D.br.length ? D.br.map(function (id) { return '<span class="sn-bchip">' + esc(brName(id) || ('#' + id)) + ' <b data-x="' + id + '">×</b></span>'; }).join('') : '<small>কোনো শাখা বাছা হয়নি' + ((SN.brlist || []).length ? '' : ' — শাখা-পেজে এখনো শাখা নেই' + (SN.brpage ? ' (<a href="' + esc(SN.brpage) + '" target="_blank">শাখা আনুন</a>)' : '')) + '</small>';
			[].forEach.call(chips.querySelectorAll('[data-x]'), function (x) { x.onclick = function () { var id = +x.getAttribute('data-x'); D.br = D.br.filter(function (v) { return v !== id; }); brSync(); paint(); save(); }; });
		}
		function find() {
			var t = inp.value.trim().toLowerCase();
			if (!t) { res.hidden = true; return; }
			var hit = (SN.brlist || []).filter(function (b) { return D.br.indexOf(b.i) < 0 && (b.n + ' ' + b.s).toLowerCase().indexOf(t) > -1; }).slice(0, 12);
			res.innerHTML = hit.length ? hit.map(function (b) { return '<div class="sn-bopt" data-i="' + b.i + '">' + esc(b.n) + ' <small>' + esc(b.s) + '</small></div>'; }).join('') : '<div class="sn-bopt"><small>পাওয়া যায়নি</small></div>';
			res.hidden = false;
			[].forEach.call(res.querySelectorAll('[data-i]'), function (o) { o.onclick = function () { D.br.push(+o.getAttribute('data-i')); inp.value = ''; res.hidden = true; brSync(); paint(); save(); }; });
		}
		inp.oninput = find;
		paint();
	}
	var AIM = [];
	function curModel() { var s = document.getElementById('sn-model'); return s ? s.value : (SN.model || ''); }
	function draw() {
		var t = typ();
		var brList = '<datalist id="sn-brl">' + SN.branches.map(function (b) { return '<option value="' + esc(b) + '">'; }).join('') + '</datalist>';
		app.innerHTML = '<div class="sn-mdl"><label><b>🤖 AI মডেল (সব AI কাজ এটা দিয়ে হবে):</b> <select id="sn-model"><option value="">স্বয়ংক্রিয় (SEO-র মডেল)</option>' + (SN.models || []).map(function (m) { return '<option value="' + esc(m.k) + '"' + (m.k === SN.model ? ' selected' : '') + '>' + esc(m.l) + '</option>'; }).join('') + '</select></label></div><div class="sn-ai"><b>✨ Claude দিয়ে ঘর পূরণ (ঐচ্ছিক)</b><textarea id="sn-aitx" rows="3" placeholder="ফেসবুক পোস্ট বা নোটিশের লেখা এখানে পেস্ট করুন, অথবা নিচে পোস্টারের ছবি বাছুন। Claude ঘরগুলো পূরণ করে দেবে, আপনি মিলিয়ে নেবেন।"></textarea><button type="button" class="button" id="sn-aipic">🖼 পোস্টারের ছবি বাছুন</button> <span id="sn-aipn">' + (AIM.length ? AIM.length + 'টা ছবি বাছা হয়েছে' : '') + '</span> <button type="button" class="button button-primary" id="sn-aigo">পূরণ করুন</button> <span id="sn-aimsg"></span></div>' +
			'<div class="sn-top">' +
			field('ধরন (<a href="' + esc(SN.settings) + '" target="_blank">নতুন ধরন যোগ / বদল</a>)', '<select id="sn-type">' + TYPES.map(function (x) { return opt(x.key, D.type, esc(x.label)); }).join('') + '</select>') +
			'<div class="sn-f sn-brbox"><span>🏫 শাখা বাছুন (একটা বা একাধিক — শাখা-পেজের তালিকা থেকে)</span><div id="sn-brchips"></div><input id="sn-brq" type="search" placeholder="🔍 শাখার নাম লিখে খুঁজুন, ক্লিক করে যোগ করুন…" autocomplete="off"><div id="sn-brres" hidden></div></div>' +
			field('শাখার নাম (পেজে যে লেখা দেখাবে — বাছাই করলে নিজে বসে যায়; চাইলে বদলান)', '<input id="sn-br" list="sn-brl" value="' + esc(D.branch || '') + '" placeholder="যেমন: টাঙ্গাইল শাখা" autocomplete="off">' + brList) +
			field('বিষয়', '<input id="sn-topic" value="' + esc(D.topic || '') + '" placeholder="' + esc(t.topic) + '">') +
			field('সাল', '<input id="sn-year" value="' + esc(D.year || '') + '">') +
			field('আবেদনের শেষ তারিখ (মেয়াদ শেষ ব্যাজের জন্য)', '<input type="date" id="sn-exp" value="' + esc(D.expiry || '') + '">') +
			'</div>' +
			'<h3>পেজের অংশ (ওপর থেকে নিচে এই ক্রমে দেখাবে)</h3><div id="sn-bl">' + D.blocks.map(blockHTML).join('') + '</div>' +
			'<p class="sn-add">যোগ করুন: <select id="sn-addk">' + Object.keys(KIND).map(function (k) { return '<option value="' + k + '">' + KIND[k] + '</option>'; }).join('') + '</select> <button type="button" class="button" id="sn-addb">+ অংশ যোগ</button></p>' +
			'<div class="sn-seo"><h3>✨ শিরোনাম, লিংক ও Google (SEO) — AI দিয়ে লিখুন</h3><p class="description">সব ঘরের পাশে আলাদা ✨AI বাটন আছে, চাইলে একটা একটা করে লেখান, বা নিচের বড় বাটনে সব একসাথে। প্রতিবার চাপলে নতুন করে লেখে। সব ঘর নিজে বদলানোও যায়। খালি রাখলে সেভের সময় নিজে পূরণ হবে।</p>' +
			'<p><button type="button" class="button button-primary button-large" data-aif="all">✨ সব ঘর AI দিয়ে লিখুন</button> <span id="sn-summsg"></span></p>' +
			aif('পোস্টের শিরোনাম', 'title', '<input id="sn-title" value="' + esc(D.title || '') + '" placeholder="খালি রাখলে বিষয় + সাল + শাখা থেকে হবে">') +
			aif('লিংক (ইংরেজি, যেমন kumilla-assistant-teacher-2026)', 'slug', '<input id="sn-slug" value="' + esc(D.slug || '') + '" placeholder="kumilla-assistant-teacher-2026">') +
			aif('সারসংক্ষেপ (তালিকা ও Google-এর জন্য, ১-২ লাইন)', 'summary', '<textarea id="sn-sum" rows="2">' + esc(D.summary || '') + '</textarea>') +
			aif('SEO শিরোনাম (Google-এ যা দেখাবে, ~৬০ অক্ষর)', 'seo_t', '<input id="sn-st" value="' + esc(D.seo_t || '') + '">') +
			aif('মেটা বিবরণ (~১২০-১৫৫ অক্ষর)', 'seo_d', '<textarea id="sn-sd" rows="2">' + esc(D.seo_d || '') + '</textarea>') +
			aif('ফোকাস কিওয়ার্ড', 'seo_k', '<input id="sn-sk" value="' + esc(D.seo_k || '') + '" placeholder="যেমন: কুমিল্লা সহকারী শিক্ষক নিয়োগ ২০২৬">') + '</div>' +
			'<p class="sn-det"><label><input type="checkbox" id="sn-det"' + (D.detached ? ' checked' : '') + '> হাতে পুরো HTML এডিট করব (ফর্ম থেকে আলাদা হবে, এরপর শুধু WordPress এডিটরে বদলাবে)</label></p>';
		var ed = document.getElementById('postdivrich'); if (ed) ed.style.display = D.detached ? '' : 'none';
		bind();
		save();
	}

	function setP(b, path, v) { var pp = path.split('.'), o = b; while (pp.length > 1) o = o[pp.shift()]; o[pp[0]] = v; }

	function bind() {
		var q = function (s) { return app.querySelector(s); };
		q('#sn-type').onchange = function () {
			var old = typ(); D.type = this.value;
			if (!D.topic || D.topic === old.topic) D.topic = '';
			var cb = D.blocks.filter(function (b) { return b.t === 'cards'; })[0];
			if (cb && cb.rows.every(function (r) { return !r.v; })) cb.rows = typ().cards.map(function (l) { return { l: l, v: '' }; });
			draw();
		};
		q('#sn-br').oninput = q('#sn-br').onchange = function () { D.branch = this.value; save(); };
		brUI();
		q('#sn-aipic').onclick = function () {
			if (!window.wp || !wp.media) return;
			var f = wp.media({ title: 'পোস্টারের ছবি (সর্বোচ্চ ৩টা)', multiple: true, library: { type: 'image' } });
			f.on('select', function () { AIM = f.state().get('selection').map(function (a) { return a.toJSON().id; }).slice(0, 3); q('#sn-aipn').textContent = AIM.length + 'টা ছবি বাছা হয়েছে'; });
			f.open();
		};
		[['#sn-topic', 'topic'], ['#sn-title', 'title'], ['#sn-year', 'year'], ['#sn-exp', 'expiry'], ['#sn-slug', 'slug'], ['#sn-sum', 'summary'], ['#sn-st', 'seo_t'], ['#sn-sd', 'seo_d'], ['#sn-sk', 'seo_k']].forEach(function (x) { var e = q(x[0]); e.oninput = e.onchange = function () { D[x[1]] = this.value; save(); }; });
		q('#sn-det').onchange = function () { D.detached = this.checked ? 1 : 0; save(); };
		q('#sn-addb').onclick = function () { D.blocks.push(blank(q('#sn-addk').value)); draw(); };
		q('#sn-aigo').onclick = ai;
		$$('[data-aif]').forEach(function (bt) { bt.onclick = function () { aiSeo(bt.dataset.aif); }; });
		var tt = document.getElementById('title');
		if (tt && !tt.dataset.sn) { tt.dataset.sn = 1; if (!D.title && tt.value) D.title = tt.value; tt.addEventListener('input', function () { D.title = tt.value; var f = document.getElementById('sn-title'); if (f) f.value = tt.value; save(); }); }
		if (tt && D.title && tt.value !== D.title) { tt.value = D.title; var lb = document.getElementById('title-prompt-text'); if (lb) lb.className += ' screen-reader-text'; }
		$$('.sn-b').forEach(function (el) {
			var i = +el.dataset.i, b = D.blocks[i];
			$$('[data-p]', el).forEach(function (n) { n.oninput = n.onchange = function () { setP(b, n.dataset.p, n.value); save(); }; if (/^rows\.\d+\.l$/.test(n.dataset.p)) { var wasV = /ভেন্যু|স্থান|ঠিকানা|কেন্দ্র/.test(n.value); n.addEventListener('change', function () { if (/ভেন্যু|স্থান|ঠিকানা|কেন্দ্র/.test(n.value) !== wasV) draw(); }); } });
			$$('[data-m]', el).forEach(function (n) {
				n.onclick = function () {
					var m = n.dataset.m;
					if (m === 'del') D.blocks.splice(i, 1);
					else { var j = m === 'up' ? i - 1 : i + 1; if (j < 0 || j >= D.blocks.length) return; var t = D.blocks[i]; D.blocks[i] = D.blocks[j]; D.blocks[j] = t; }
					draw();
				};
			});
			$$('[data-ra]', el).forEach(function (n) { n.onclick = function () { b[n.dataset.ra].push(blankRow(b.t)); draw(); }; });
			$$('[data-rd]', el).forEach(function (n) { n.onclick = function () { var pp = n.dataset.rd.split('.'); b[pp[0]].splice(+pp[1], 1); draw(); }; });
			$$('[data-id]', el).forEach(function (n) { n.onclick = function () { var k = +n.dataset.id; b.ids.splice(k, 1); b.urls.splice(k, 1); draw(); }; });
			$$('[data-pick]', el).forEach(function (n) { n.onclick = function () { pick(b); }; });
		});
	}

	function pick(b) {
		if (!window.wp || !wp.media) return;
		b.ids = b.ids || []; b.urls = b.urls || [];
		var f = wp.media({ title: 'ছবি বাছুন', multiple: true, library: { type: 'image' } });
		f.on('select', function () {
			f.state().get('selection').each(function (a) {
				var j = a.toJSON();
				if (b.ids.indexOf(j.id) < 0) { b.ids.push(j.id); b.urls.push(j.sizes && j.sizes.medium ? j.sizes.medium.url : j.url); }
			});
			draw();
		});
		f.open();
	}

	async function aiSeo(only) {
		var m = app.querySelector('#sn-summsg'); m.textContent = 'লিখছি…';
		$$('[data-aif]').forEach(function (b) { b.disabled = true; });
		try {
			var r = await fetch(SN.ajax, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'csa_sn_sum', model: curModel(), nonce: SN.nonce, data: JSON.stringify(D) }) }), j = await r.json();
			if (!j.success) { m.textContent = '⚠ ' + j.data; $$('[data-aif]').forEach(function (b) { b.disabled = false; }); return; }
			['title', 'slug', 'summary', 'seo_t', 'seo_d', 'seo_k'].forEach(function (k) { if (j.data[k] && (only === 'all' || only === k)) D[k] = j.data[k]; });
			draw(); app.querySelector('#sn-summsg').textContent = '✓ লেখা হয়েছে, দেখে নিন';
		} catch (e) { m.textContent = '⚠ ' + e.message; $$('[data-aif]').forEach(function (b) { b.disabled = false; }); }
	}

	async function ai() {
		var tx = app.querySelector('#sn-aitx').value.trim(), msg = app.querySelector('#sn-aimsg'), btn = app.querySelector('#sn-aigo');
		if (!tx && !AIM.length) { msg.textContent = 'লেখা পেস্ট করুন অথবা পোস্টারের ছবি বাছুন'; return; }
		btn.disabled = true; msg.textContent = 'পড়ছি…';
		try {
			var r = await fetch(SN.ajax, { method: 'POST', credentials: 'same-origin', body: (function () { var u = new URLSearchParams({ action: 'csa_sn_ai', model: curModel(), nonce: SN.nonce, text: tx }); AIM.forEach(function (i) { u.append('imgs[]', i); }); return u; })() }), j = await r.json();
			if (!j.success) { msg.textContent = '⚠ ' + j.data; btn.disabled = false; return; }
			var d = j.data;
			if (d.type && TYPES.some(function (t) { return t.key === d.type; })) D.type = d.type;
			['branch', 'topic', 'year', 'expiry', 'summary'].forEach(function (k) { if (d[k]) D[k] = d[k]; });
			if (d.branch && !(D.br || []).length) { var bm = (SN.brlist || []).filter(function (b) { return b.n === d.branch || b.n === d.branch.trim(); }); if (bm.length === 1) { D.br = [bm[0].i]; } }
			var blk = function (t) { var b = D.blocks.filter(function (x) { return x.t === t; })[0]; if (!b) { b = blank(t); D.blocks.push(b); } return b; };
			if (d.cards && d.cards.length) blk('cards').rows = d.cards.map(function (x) { return { l: x.l || '', v: x.v || '' }; });
			if (d.conditions && d.conditions.length) { var l = blk('list'); l.h = l.h || 'শর্তসমূহ'; l.lines = d.conditions.join('\n'); }
			if (d.note) blk('note').body = d.note;
			if (d.phones && d.phones.length) { var p = blk('phones'); p.h = p.h || 'বিস্তারিত যোগাযোগ করুন:'; p.nums = d.phones.join('\n'); }
			if (d.links && d.links.length) {
				var bt = blk('buttons');
				d.links.forEach(function (x) {
					if (!x.url) return;
					var e = bt.items.filter(function (y) { return y.kind === x.kind && !y.url; })[0];
					if (e) { e.url = x.url; if (x.label) e.label = x.label; } else bt.items.push({ label: x.label || 'লিংক', url: x.url, kind: x.kind || 'oth' });
				});
			}
			draw(); app.querySelector('#sn-aimsg').textContent = '✓ পূরণ হয়েছে, মিলিয়ে নিন';
		} catch (e) { msg.textContent = '⚠ ' + e.message; btn.disabled = false; }
	}
	draw();
})();
