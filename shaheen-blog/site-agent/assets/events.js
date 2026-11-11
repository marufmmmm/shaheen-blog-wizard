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
		var a = e.target.closest('.sev .ep[data-yt],.sev .gi[data-yt],.sev .gi[data-full]');
		if (!a || e.metaKey || e.ctrlKey) { return; }
		var yt = a.getAttribute('data-yt'), full = a.getAttribute('data-full');
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
