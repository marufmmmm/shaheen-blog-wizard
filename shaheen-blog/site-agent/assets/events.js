/* Events + home page: reveal on scroll, countdown, gallery filter, photo / YouTube lightbox, theme-gap fix. */
(function () {
	'use strict';
	var roots = [].slice.call(document.querySelectorAll('.sev,.shm'));
	if (!roots.length) { return; }

	/* the theme wraps content in padded boxes: let the page use the full width */
	function gaps() {
		if (document.querySelector('.wp-site-blocks')) { return; } /* block theme: its own full-width rules are enough */
		var m = window.innerWidth <= 800;
		roots.forEach(function (t) {
			var e = t.parentNode, n = 0;
			while (e && e !== document.body && n < 8) {
				if (e.id !== 'page') {
					var s = e.style;
					s.paddingTop = '0'; s.paddingBottom = '0';
					s.marginTop = '0'; s.marginBottom = '0';
					s.paddingLeft = '0'; s.paddingRight = '0'; s.marginLeft = '0'; s.marginRight = '0';
					s.maxWidth = 'none'; s.width = '100%';
				}
				e = e.parentNode; n++;
			}
			var h = document.querySelector('.entry-header,.page-header');
			if (h && !h.textContent.trim()) { h.style.display = 'none'; }
		});
	}
	gaps();
	window.addEventListener('resize', gaps);

	/* reveal */
	var rv = [].slice.call(document.querySelectorAll('.sev .rv,.shm .rv'));
	if ('IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (es) {
			es.forEach(function (x) { if (x.isIntersecting) { x.target.classList.add('vis'); io.unobserve(x.target); } });
		}, { threshold: .12, rootMargin: '0px 0px -40px 0px' });
		rv.forEach(function (el, i) { el.style.transitionDelay = ((i % 4) * 70) + 'ms'; io.observe(el); });
	} else {
		rv.forEach(function (el) { el.classList.add('vis'); });
	}

	/* result slider */
	[].slice.call(document.querySelectorAll('[data-rs]')).forEach(function (rs) {
		var tr = rs.querySelector('.rst'), cs = [].slice.call(tr.children), dt = rs.querySelector('.rdt'), cur = 0, timer, hold = false, busy = 0;
		cs.forEach(function (c, i) { var b = document.createElement('button'); b.type = 'button'; b.setAttribute('aria-label', 'কার্ড ' + (i + 1)); b.addEventListener('click', function () { go(i); }); dt.appendChild(b); });
		function per() { return window.innerWidth > 900 ? 2 : 1; }
		function go(i) {
			var max = Math.max(0, cs.length - per());
			cur = i > max ? 0 : (i < 0 ? max : i);
			busy = Date.now() + 900;
			tr.scrollTo({ left: cs[cur].offsetLeft - cs[0].offsetLeft, behavior: 'smooth' });
			mark();
		}
		function mark() { var mx = Math.max(0, cs.length - per()); [].slice.call(dt.children).forEach(function (b, i) { b.className = i === cur ? 'on' : ''; b.hidden = i > mx; }); }
		window.addEventListener('resize', mark);
		var st;
		tr.addEventListener('scroll', function () {
			clearTimeout(st);
			st = setTimeout(function () {
				if (Date.now() < busy) { return; }
				var best = 0, d = 1e9;
				cs.forEach(function (c, i) { var x = Math.abs(c.offsetLeft - cs[0].offsetLeft - tr.scrollLeft); if (x < d) { d = x; best = i; } });
				cur = best; mark();
			}, 80);
		}, { passive: true });
		rs.querySelector('.rpv').addEventListener('click', function () { go(cur - 1); });
		rs.querySelector('.rnx').addEventListener('click', function () { go(cur + 1); });
		function play() { clearInterval(timer); timer = setInterval(function () { if (!hold && !document.hidden) { go(cur + 1); } }, 5500); }
		['mouseenter', 'touchstart', 'focusin'].forEach(function (e) { rs.addEventListener(e, function () { hold = true; }, { passive: true }); });
		['mouseleave', 'touchend', 'focusout'].forEach(function (e) { rs.addEventListener(e, function () { setTimeout(function () { hold = false; }, 2500); }, { passive: true }); });
		mark(); play();
	});

	/* countdown */
	function bn(n) { return String(n).replace(/[0-9]/g, function (d) { return '০১২৩৪৫৬৭৮৯'.charAt(+d); }); }
	[].slice.call(document.querySelectorAll('.sev .cd[data-until]')).forEach(function (c) {
		var p = c.getAttribute('data-until').split('-');
		var to = new Date(+p[0], +p[1] - 1, +p[2], 0, 0, 0).getTime();
		function tick() {
			var d = Math.max(0, to - Date.now()), day = Math.floor(d / 864e5), h = Math.floor(d % 864e5 / 36e5), m = Math.floor(d % 36e5 / 6e4);
			c.querySelector('[data-k=d]').textContent = bn(day);
			c.querySelector('[data-k=h]').textContent = bn(h);
			c.querySelector('[data-k=m]').textContent = bn(m);
		}
		tick(); setInterval(tick, 30000);
	});

	/* gallery filter */
	[].slice.call(document.querySelectorAll('.sev .flt')).forEach(function (f) {
		var items = [].slice.call(f.parentNode.querySelectorAll('.gi'));
		f.addEventListener('click', function (ev) {
			var b = ev.target.closest('button');
			if (!b) { return; }
			[].forEach.call(f.children, function (x) { x.classList.toggle('on', x === b); });
			var k = b.getAttribute('data-f'), t = b.getAttribute('data-t');
			items.forEach(function (i) {
				var ok = t ? i.getAttribute('data-tag') === t : (k === '*' || i.getAttribute('data-k') === k);
				i.hidden = !ok;
			});
		});
	});

	/* lightbox: photos, YouTube (inside the page); Facebook and others open in a new tab */
	function lb(html) {
		var o = document.createElement('div');
		o.className = 'csa-lb';
		o.innerHTML = '<div><button aria-label="বন্ধ">×</button>' + html + '</div>';
		function close() { if (o.parentNode) { o.parentNode.removeChild(o); } document.removeEventListener('keydown', key); }
		function key(e) { if (e.key === 'Escape') { close(); } }
		o.addEventListener('click', function (e) { if (e.target === o || e.target.tagName === 'BUTTON') { close(); } });
		document.addEventListener('keydown', key);
		document.body.appendChild(o);
	}
	document.addEventListener('click', function (e) {
		var a = e.target.closest('.sev .ep[data-yt],.sev .gi[data-yt],.sev .gi[data-full],.sev .ep[data-fb],.sev .vc[data-fb],.sev .vc[data-yt]');
		if (!a || e.metaKey || e.ctrlKey) { return; }
		var yt = a.getAttribute('data-yt'), full = a.getAttribute('data-full'), fb = a.getAttribute('data-fb');
		if (fb && !yt) {
			e.preventDefault();
			lb('<div class="fr fbv"><iframe src="https://www.facebook.com/plugins/video.php?show_text=false&autoplay=true&width=560&href=' + encodeURIComponent(fb) + '" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" allowfullscreen></iframe></div><a class="lbo" href="' + fb + '" target="_blank" rel="noopener">ভিডিও না চললে Facebook-এ দেখুন ↗</a>');
			return;
		}
		if (yt) {
			e.preventDefault();
			lb('<div class="fr"><iframe src="https://www.youtube-nocookie.com/embed/' + encodeURIComponent(yt) + '?autoplay=1&rel=0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div><a href="' + a.href + '" target="_blank" rel="noopener">YouTube-এ খুলুন ↗</a>');
		} else if (full) {
			e.preventDefault();
			var im = document.createElement('img');
			im.src = full;
			var w = document.createElement('div'); w.appendChild(im);
			lb(w.innerHTML);
		}
	});

	/* sub-navigation: highlight the section being read */
	var links = [].slice.call(document.querySelectorAll('.sev .snav a'));
	if (links.length && 'IntersectionObserver' in window) {
		var map = {};
		links.forEach(function (l) { var s = document.getElementById(l.getAttribute('href').slice(1)); if (s) { map[s.id] = l; } });
		var so = new IntersectionObserver(function (es) {
			es.forEach(function (x) { if (x.isIntersecting && map[x.target.id]) { links.forEach(function (l) { l.classList.remove('on'); }); map[x.target.id].classList.add('on'); } });
		}, { rootMargin: '-40% 0px -55% 0px' });
		Object.keys(map).forEach(function (k) { so.observe(document.getElementById(k)); });
	}
})();
/* v2.87.1 lazy-load embedded videos */
(function(){var f=[].slice.call(document.querySelectorAll('iframe.emb[data-src]'));if(!f.length){return;}
function ld(i){if(i.getAttribute('data-src')){i.src=i.getAttribute('data-src');i.removeAttribute('data-src');}}
if(!('IntersectionObserver' in window)){f.forEach(ld);return;}
var o=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){ld(e.target);o.unobserve(e.target);}});},{rootMargin:'300px'});f.forEach(function(i){o.observe(i);});})();
