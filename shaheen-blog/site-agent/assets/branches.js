/* Branch page: filter, search, nearby (district or GPS). Cards themselves are rendered by PHP. */
(function () {
	'use strict';
	var root = document.getElementById('sbr');
	var C = window.SBR;
	if (!root || !C) { return; }
	function $(id) { return document.getElementById(id); }
	function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
	function bn(n) { return String(n).replace(/[0-9]/g, function (d) { return '০১২৩৪৫৬৭৮৯'.charAt(+d); }); }
	function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
	function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) { } }
	function lsDel(k) { try { localStorage.removeItem(k); } catch (e) { } }
	function toast(m) {
		var t = document.createElement('div'); t.className = 'nb-toast'; t.textContent = m; root.appendChild(t);
		setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 2600);
	}

	/* ---------- filter + search ---------- */
	var sel = $('sbrSel'), q = $('sbrQ'), list = $('sbrList'), empty = $('sbrEmpty');
	var cards = [].slice.call(list.querySelectorAll('.bc'));
	var io = null;
	if ('IntersectionObserver' in window) {
		io = new IntersectionObserver(function (es) {
			es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
		}, { rootMargin: '0px 0px -30px 0px' });
	}
	function reveal(c) { if (io) { io.observe(c); } else { c.classList.add('in'); } }

	function apply() {
		var mode = sel ? sel.value : 'all';
		var term = q ? q.value.trim().toLowerCase() : '';
		var shown = 0;
		cards.forEach(function (c) {
			var ok = true;
			if (mode.indexOf('div:') === 0) { ok = c.getAttribute('data-div') === mode.slice(4); }
			else if (mode.indexOf('type:') === 0) { ok = c.getAttribute('data-type') === mode.slice(5); }
			if (ok && term) { ok = (c.getAttribute('data-q') || '').indexOf(term) > -1; }
			c.hidden = !ok;
			if (ok) { shown++; reveal(c); }
		});
		[].forEach.call(list.querySelectorAll('.bgrid'), function (g) {
			var n = g.querySelectorAll('.bc:not([hidden])').length;
			g.hidden = n === 0;
			var prev = g.previousElementSibling;
			if (prev && prev.classList.contains('type-lbl')) { prev.hidden = n === 0; }
		});
		[].forEach.call(list.querySelectorAll('.dist-grp'), function (g) {
			var n = g.querySelectorAll('.bc:not([hidden])').length;
			g.hidden = n === 0;
			var dn = g.querySelector('.dn'); if (dn) { dn.textContent = '(' + bn(n) + ')'; }
		});
		[].forEach.call(list.querySelectorAll('.div-sec'), function (s) {
			var n = s.querySelectorAll('.bc:not([hidden])').length;
			s.hidden = n === 0;
			var cnt = s.querySelector('.div-cnt'); if (cnt) { cnt.textContent = bn(n) + 'টি শাখা'; }
		});
		if (empty) { empty.hidden = shown !== 0; }
	}
	if (sel) {
		var has = function (v) { if (!v) { return false; } for (var i = 0; i < sel.options.length; i++) { if (sel.options[i].value === v) { return true; } } return false; };
		var saved = lsGet('sbr_view');
		sel.value = has(saved) ? saved : (has(C.def) ? C.def : 'all');
		sel.addEventListener('change', function () { lsSet('sbr_view', sel.value); apply(); });
	}
	var qt = null;
	if (q) { q.addEventListener('input', function () { clearTimeout(qt); qt = setTimeout(apply, 120); }); }
	apply();

	/* ---------- nearby ---------- */
	var near = $('sbrNear'), grid = $('sbrNbG'), prompt = $('sbrLoc');
	if (!near) { return; }
	function card(b, km) {
		var m = b.m ? '<a class="nbc-map" href="' + esc(b.m) + '" target="_blank" rel="noopener">🗺️ Map</a>' : '';
		return '<div class="nbc">' + (km != null ? '<div class="nbc-km">📍 ' + bn(km.toFixed(1)) + ' কি.মি.</div>' : '<div class="nbc-dist">' + esc(b.d) + ' / ' + esc(b.s) + '</div>')
			+ '<div class="nbc-name">' + esc(b.n) + '</div><div class="nbc-addr">' + esc(b.a) + '</div>'
			+ (b.p ? '<div class="nbc-ph">📞 ' + esc(b.p) + '</div>' : '') + m + '</div>';
	}
	function showList(title, html) {
		if (prompt) { prompt.hidden = true; }
		near.classList.add('show');
		$('sbrNbT').textContent = title;
		grid.innerHTML = html;
	}
	function byDistrict(g) {
		g = g.trim();
		if (!g) { return; }
		var hit = null, k;
		var lm = C.lm || {};
		for (k in lm) { if (Object.prototype.hasOwnProperty.call(lm, k)) { if (g.indexOf(k) > -1 || k.indexOf(g) > -1) { hit = lm[k]; break; } } }
		if (!hit) { C.b.forEach(function (b) { if (!hit && b.s) { if (g.indexOf(b.s) > -1 || b.s.indexOf(g) > -1) { hit = b.s; } } }); }
		var rows = [];
		if (hit) { rows = C.b.filter(function (b) { return b.s === hit; }); }
		if (!rows.length) {
			var t = g.toLowerCase();
			rows = C.b.filter(function (b) { return (b.n + ' ' + b.a).toLowerCase().indexOf(t) > -1; });
			hit = g;
		}
		if (!rows.length) { toast('ঐ নামে শাখা পাওয়া যায়নি'); return; }
		showList('📍 ' + hit + '-এর শাখাসমূহ', rows.slice(0, 6).map(function (b) { return card(b, null); }).join(''));
		lsSet('sbr_area', g);
	}
	function hav(a, b, c, d) {
		var R = 6371, r = Math.PI / 180, x = (c - a) * r, y = (d - b) * r;
		var s = Math.sin(x / 2) * Math.sin(x / 2) + Math.cos(a * r) * Math.cos(c * r) * Math.sin(y / 2) * Math.sin(y / 2);
		return 2 * R * Math.atan2(Math.sqrt(s), Math.sqrt(1 - s));
	}
	function byGps() {
		if (!navigator.geolocation) { toast('এই ব্রাউজারে অবস্থান পাওয়া যায় না'); return; }
		var btn = $('sbrGps'); if (btn) { btn.disabled = true; }
		navigator.geolocation.getCurrentPosition(function (p) {
			if (btn) { btn.disabled = false; }
			var la = p.coords.latitude, lo = p.coords.longitude;
			var rows = C.b.filter(function (b) { return b.la !== '' && b.lo !== '' && b.la != null; }).map(function (b) { return { b: b, km: hav(la, lo, +b.la, +b.lo) }; });
			if (!rows.length) { toast('শাখার স্থানাঙ্ক নেই, জেলার নাম লিখুন'); return; }
			rows.sort(function (x, y) { return x.km - y.km; });
			showList('📍 আপনার সবচেয়ে কাছের শাখা', rows.slice(0, 6).map(function (r) { return card(r.b, r.km); }).join(''));
		}, function () {
			if (btn) { btn.disabled = false; }
			toast('অবস্থানের অনুমতি পাওয়া যায়নি — জেলার নাম লিখুন');
		}, { enableHighAccuracy: false, timeout: 12000, maximumAge: 600000 });
	}
	var f = $('sbrFind'), a = $('sbrArea'), g = $('sbrGps'), r = $('sbrReset');
	if (f && a) {
		f.addEventListener('click', function () { byDistrict(a.value); });
		a.addEventListener('keydown', function (e) { if (e.key === 'Enter') { byDistrict(a.value); } });
	}
	if (g) { g.addEventListener('click', byGps); }
	if (r) {
		r.addEventListener('click', function () {
			lsDel('sbr_area'); near.classList.remove('show'); if (prompt) { prompt.hidden = false; }
		});
	}
	var sa = lsGet('sbr_area');
	if (sa && a) { a.value = sa; byDistrict(sa); }
})();
