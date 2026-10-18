/* Shaheen Blog: front-end behaviour (category filter, load more, copy link). */
(function () {
    'use strict';

    // Make sure page-level classes exist even when a page builder hides the shortcode from PHP.
    function markPage(root) {
        document.body.classList.add('shb-page');
        if (root && root.getAttribute('data-hide-title') === '1') {
            document.body.classList.add('shb-hide-title');
        }
    }

    function initBlog(root) {
        var grid = root.querySelector('.blog-grid');
        var cards = [].slice.call(grid.querySelectorAll('.blog-card'));
        var empty = grid.querySelector('.empty-state');
        var more = root.querySelector('.load-more-container');
        var btn = root.querySelector('.load-more-btn');
        var label = btn.querySelector('span');
        var buttons = [].slice.call(root.querySelectorAll('.category-btn'));
        var per = parseInt(root.getAttribute('data-per-page'), 10) || 12;
        var filter = 'all';
        var shown = per;

        function render() {
            var matched = cards.filter(function (c) {
                return filter === 'all' || (' ' + c.getAttribute('data-cat') + ' ').indexOf(' ' + filter + ' ') > -1;
            });
            cards.forEach(function (c) { c.hidden = true; });
            matched.slice(0, shown).forEach(function (c) { c.hidden = false; });
            if (empty) { empty.hidden = matched.length > 0; }
            more.hidden = matched.length <= shown;
            btn.disabled = false;
            btn.classList.remove('loading');
            label.textContent = 'আরও পোস্ট লোড করুন';
        }

        buttons.forEach(function (b) {
            b.addEventListener('click', function () {
                filter = b.getAttribute('data-cat');
                shown = per;
                buttons.forEach(function (x) {
                    var on = x === b;
                    x.classList.toggle('active', on);
                    x.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
                render();
            });
        });

        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.classList.add('loading');
            label.textContent = 'লোড হচ্ছে...';
            setTimeout(function () { shown += per; render(); }, 250);
        });

        render();
    }

    function initCopy() {
        [].slice.call(document.querySelectorAll('.js-copy-link')).forEach(function (b) {
            b.addEventListener('click', function () {
                var url = b.getAttribute('data-url');
                var done = function () {
                    var old = b.textContent;
                    b.textContent = '✔ কপি হয়েছে';
                    setTimeout(function () { b.textContent = old; }, 1800);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(done, function () { window.prompt('লিংক কপি করুন:', url); });
                } else {
                    window.prompt('লিংক কপি করুন:', url);
                }
            });
        });
    }

    /* ---- Match the theme header: same content width, no gap under it ---- */
    function themeHeader() {
        var list = [].slice.call(document.querySelectorAll('.elementor-location-header, header, #masthead, .site-header'));
        for (var i = 0; i < list.length; i++) {
            if (!list[i].closest('.shb') && list[i].getBoundingClientRect().height > 20) { return list[i]; }
        }
        return null;
    }
    function contentBox(el) {
        var cs = getComputedStyle(el), r = el.getBoundingClientRect();
        return r.width - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    }
    function align() {
        var roots = [].slice.call(document.querySelectorAll('.shb'));
        if (!roots.length) { return; }
        var hdr = themeHeader();
        // 1) content width = the header's inner box (desktop only)
        var fixed = roots[0].getAttribute('data-fixedw');
        if (hdr && !fixed && window.innerWidth >= 961) {
            var best = 0;
            [].slice.call(hdr.querySelectorAll('.e-con-inner, .elementor-container, .container, .ast-container, .wp-block-group__inner-container, .inside-header, .header-inner, .site-header-inner, .row')).forEach(function (c) {
                var w = Math.round(contentBox(c));
                if (w >= 600 && w < window.innerWidth - 8 && w > best) { best = w; }
            });
            if (best) { roots.forEach(function (r) { r.style.setProperty('--shb-w', best + 'px'); }); }
        }
        // 2) no empty band between header and our first block (blog page and single posts only)
        var first = roots[0], off = parseInt(first.getAttribute('data-offset'), 10) || 0;
        first.style.marginTop = '0px';
        var mt = 0;
        if (hdr && first.getAttribute('data-gapfix') === '1') {
            var gap = first.getBoundingClientRect().top - hdr.getBoundingClientRect().bottom;
            if (gap > 1 && gap < 400) { mt = -Math.round(gap); }
        }
        mt += (first.getAttribute('data-gapfix') === '1') ? off : 0;
        if (mt) { first.style.marginTop = mt + 'px'; }
    }
    function scheduleAlign() {
        align();
        [200, 700, 1500].forEach(function (t) { setTimeout(align, t); });
        window.addEventListener('load', align);
        var tm;
        window.addEventListener('resize', function () { clearTimeout(tm); tm = setTimeout(align, 150); });
    }

    /* ---- Reads counter (once per visitor per 6 hours) ---- */
    function countView() {
        var art = document.querySelector('.shb-single[data-post]');
        if (!art || typeof SBF === 'undefined') { return; }
        var id = art.getAttribute('data-post'), key = 'sb_v_' + id, now = Date.now();
        try {
            var last = parseInt(localStorage.getItem(key), 10);
            if (last && now - last < 6 * 3600 * 1000) { return; }
            localStorage.setItem(key, String(now));
        } catch (e) { /* private mode: count anyway */ }
        var fd = new FormData();
        fd.append('action', 'sb_view'); fd.append('id', id);
        fetch(SBF.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.success) {
                    [].slice.call(document.querySelectorAll('.js-views')).forEach(function (e) { e.textContent = d.data.text; });
                }
            }).catch(function () {});
    }

    /* ---- Social box: one latest post at a time, rotating ---- */
    function initSocial(box) {
        var slides = [].slice.call(box.querySelectorAll('.sb-soc-slide'));
        if (!slides.length) { return; }
        var dotsEl = box.querySelector('.sb-soc-dots'), cur = 0, timer = null, paused = false;
        var stack = box.getAttribute('data-layout') === 'stack';
        var every = (parseInt(box.getAttribute('data-interval'), 10) || 8) * 1000;
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function fit(slide) {
            // Facebook plugins need an explicit pixel width (180–500); match it to the box so nothing is left blank at the sides.
            var f = slide.querySelector('iframe'), src = slide.getAttribute('data-src');
            if (!f || slide.getAttribute('data-wa') !== '1') { return; }
            var w = Math.max(180, Math.min(500, Math.floor(slide.clientWidth || box.clientWidth || 320)));
            // Height follows the width when the owner left it on "auto" (phones get a shorter box, wide screens a taller one).
            var auto = slide.getAttribute('data-auto') === '1', h = auto ? Math.max(420, Math.min(680, Math.round(w * 1.3))) : 0;
            if (parseInt(slide.getAttribute('data-w'), 10) === w && (!auto || parseInt(slide.getAttribute('data-ah'), 10) === h)) { return; }
            slide.setAttribute('data-w', w);
            var url = src.replace(/([?&])width=\d+/, '$1width=' + w);
            if (auto) { slide.setAttribute('data-ah', h); url = url.replace(/([?&])height=\d+/, '$1height=' + h); f.style.height = h + 'px'; }
            f.src = url;
            f.style.width = w + 'px';
        }
        function mount(slide) {
            if (slide.getAttribute('data-ready')) { return; }
            var kind = slide.getAttribute('data-kind'), src = slide.getAttribute('data-src');
            if (kind === 'yt' || kind === 'igcard' || kind === 'fblink' || !src) { slide.setAttribute('data-ready', '1'); return; }
            var f = document.createElement('iframe');
            f.title = slide.getAttribute('data-title') || '';
            f.setAttribute('allow', 'encrypted-media; picture-in-picture; clipboard-write');
            f.setAttribute('allowfullscreen', 'true'); f.setAttribute('scrolling', 'no');
            var wa = slide.getAttribute('data-wa') === '1';
            f.style.cssText = 'width:' + (wa ? '100%' : '100%') + ';max-width:100%;border:0;display:block;margin:0 auto;height:' + (parseInt(slide.getAttribute('data-h'), 10) || 420) + 'px';
            slide.appendChild(f); slide.setAttribute('data-ready', '1');
            if (slide.getAttribute('data-r')) { f.src = src; f.style.height = '100%'; slide.style.width = '100%'; if (window.SBRATIO) { window.SBRATIO.fit(slide, f); } }
            else if (wa) { fit(slide); } else { f.src = src; if (window.SBFB) { window.SBFB.upgrade(f); } }
        }
        function show(i) {
            cur = (i + slides.length) % slides.length;
            if (!stack) { slides.forEach(function (s, k) { s.hidden = k !== cur; }); }
            mount(slides[cur]); fit(slides[cur]);
            if (dotsEl) { [].slice.call(dotsEl.children).forEach(function (d, k) { d.classList.toggle('on', k === cur); }); }
        }
        function arm() {
            clearInterval(timer);
            if (slides.length > 1 && !reduce && !stack) { timer = setInterval(function () { if (!paused && !document.hidden) { show(cur + 1); } }, every); }
        }
        if (dotsEl) {
            slides.forEach(function (_, k) {
                var d = document.createElement('button'); d.type = 'button'; d.setAttribute('aria-label', 'স্লাইড ' + (k + 1));
                d.addEventListener('click', function () { show(k); arm(); }); dotsEl.appendChild(d);
            });
        }
        var p = box.querySelector('.sb-soc-prev'), n = box.querySelector('.sb-soc-next');
        if (p) { p.addEventListener('click', function () { show(cur - 1); arm(); }); }
        if (n) { n.addEventListener('click', function () { show(cur + 1); arm(); }); }
        slides.forEach(function (s) {
            var y = s.querySelector('.sb-soc-yt');
            if (y) {
                y.addEventListener('click', function () {
                    var f = document.createElement('iframe');
                    f.src = s.getAttribute('data-src') + '?autoplay=1&rel=0'; f.title = s.getAttribute('data-title') || '';
                    f.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture'); f.setAttribute('allowfullscreen', 'true');
                    f.style.cssText = 'width:100%;aspect-ratio:' + ((s.getAttribute('data-r') || '16:9').replace(':', '/')) + ';border:0;display:block';
                    y.replaceWith(f); paused = true;   // keep the video on screen while it plays
                });
            }
        });
        ['mouseenter', 'focusin', 'touchstart'].forEach(function (ev) { box.addEventListener(ev, function () { paused = true; }, { passive: true }); });
        ['mouseleave', 'focusout'].forEach(function (ev) { box.addEventListener(ev, function () { if (!box.querySelector('.sb-soc-slide iframe[src*="autoplay"]')) { paused = false; } }); });
        if (stack) { slides.forEach(mount); }
        show(0); arm();
        var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(function () { slides.forEach(function (sl) { if (sl.getAttribute('data-ready') && !sl.hidden) { fit(sl); } }); }, 200); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var blogs = document.querySelectorAll('.shb-blog');
        [].slice.call(blogs).forEach(function (r) { markPage(r); initBlog(r); });
        if (document.querySelector('.shb-single')) { document.body.classList.add('shb-page'); }
        initCopy();
        [].slice.call(document.querySelectorAll('.sb-social')).forEach(initSocial);
        scheduleAlign();
        countView();
    });
})();

/* PDF reader inside a post (PDF.js; falls back to the open/download links) */
(function () {
    var boxes = [].slice.call(document.querySelectorAll('.sb-pdf'));
    if (!boxes.length) { return; }
    function start(box) {
        var pages = box.querySelector('.sb-pdf-pages'), more = box.querySelector('.sb-pdf-more button');
        function fail() { pages.innerHTML = '<p class="sb-pdf-wait">এই ডিভাইসে পিডিএফ এখানে দেখানো যায়নি। ওপরের "খুলুন" বা "ডাউনলোড" চাপুন।</p>'; }
        if (!window.pdfjsLib) { fail(); return; }
        pdfjsLib.GlobalWorkerOptions.workerSrc = (window.SBPDF && SBPDF.worker) || '';
        pdfjsLib.getDocument(box.getAttribute('data-src')).promise.then(function (pdf) {
            pages.innerHTML = '';
            var next = 1, STEP = 3;
            function renderOne(n) {
                return pdf.getPage(n).then(function (pg) {
                    var vp = pg.getViewport({ scale: 1 });
                    var scale = Math.min(2, Math.max(1, (pages.clientWidth * (window.devicePixelRatio || 1)) / vp.width));
                    var v = pg.getViewport({ scale: scale });
                    var c = document.createElement('canvas'); c.width = v.width; c.height = v.height;
                    pages.appendChild(c);
                    return pg.render({ canvasContext: c.getContext('2d'), viewport: v }).promise;
                });
            }
            function batch() {
                var last = Math.min(pdf.numPages, next + STEP - 1), chain = Promise.resolve();
                for (var n = next; n <= last; n++) { (function (k) { chain = chain.then(function () { return renderOne(k); }); })(n); }
                next = last + 1; more.hidden = next > pdf.numPages;
            }
            more.onclick = batch; batch();
        }).catch(fail);
    }
    var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
        es.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); start(e.target); } });
    }, { rootMargin: '300px' }) : null;
    boxes.forEach(function (b) { if (io) { io.observe(b); } else { start(b); } });
})();


/* Facebook post/video embeds: let Facebook's own SDK size them (height follows the post, width follows the box; mobile + desktop).
   If the SDK can't load (ad-blocker, offline) the normal iframe stays. */
(function () {
    var sdkState = 0, queue = [];
    function loadSdk(cb) {
        if (window.FB && window.FB.XFBML) { cb(true); return; }
        queue.push(cb);
        if (sdkState) { return; }
        sdkState = 1;
        window.fbAsyncInit = function () { try { FB.init({ xfbml: false, version: 'v21.0' }); } catch (e) {} sdkState = 2; var q = queue.splice(0); q.forEach(function (f) { f(true); }); };
        var s = document.createElement('script');
        s.async = true; s.defer = true; s.crossOrigin = 'anonymous'; s.src = 'https://connect.facebook.net/en_US/sdk.js';
        s.onerror = function () { sdkState = 3; var q = queue.splice(0); q.forEach(function (f) { f(false); }); };
        document.head.appendChild(s);
        setTimeout(function () { if (sdkState === 1) { sdkState = 3; var q = queue.splice(0); q.forEach(function (f) { f(false); }); } }, 8000);
    }
    function upgrade(f) {
        if (!f || f.getAttribute('data-sbfb') || (f.closest && (f.closest('.sb-embed--ratio') || f.closest('.sb-soc-slide[data-r]')))) { return; }
        var m = /facebook\.com\/plugins\/(post|video)\.php\?([^#]*)/.exec(f.getAttribute('src') || f.getAttribute('data-src') || '');
        if (!m) { return; }
        var href = (/(?:^|&)href=([^&]+)/.exec(m[2]) || [])[1];
        if (!href) { return; }
        f.setAttribute('data-sbfb', '1');
        try { href = decodeURIComponent(href); } catch (e) { return; }
        loadSdk(function (ok) {
            if (!ok || !f.parentNode) { return; }
            var d = document.createElement('div');
            d.className = 'sb-fbdyn ' + (m[1] === 'video' ? 'fb-video' : 'fb-post');
            d.setAttribute('data-href', href); d.setAttribute('data-width', 'auto');
            if (m[1] === 'video') { d.setAttribute('data-allowfullscreen', 'true'); d.setAttribute('data-show-text', 'false'); }
            else { d.setAttribute('data-show-text', 'true'); }
            f.parentNode.insertBefore(d, f);
            try { FB.XFBML.parse(d.parentNode); } catch (e) { d.remove(); return; }
            var tries = 0, t = setInterval(function () {
                tries++;
                if (d.querySelector('iframe')) {
                    clearInterval(t); f.style.display = 'none';
                    var box = f.closest('.sb-embed'); if (box) { box.classList.add('is-dyn'); }
                    var slide = f.closest('.sb-soc-slide'); if (slide) { slide.classList.add('is-dyn'); }
                } else if (tries > 24) { clearInterval(t); d.remove(); }   // SDK gave nothing: keep the plain iframe
            }, 250);
        });
    }
    window.SBFB = { upgrade: upgrade };
    document.addEventListener('DOMContentLoaded', function () {
        [].slice.call(document.querySelectorAll('.sb-embed iframe[src*="facebook.com/plugins/post.php"], .sb-embed iframe[src*="facebook.com/plugins/video.php"]')).forEach(upgrade);
    });
})();


/* Box shapes (16:9, 1:1, 4:5, 9:16 …) chosen by the owner for video/post embeds */
(function () {
    var CAP = { '16:9': 0, '4:3': 640, '1:1': 540, '4:5': 480, '9:16': 360 };
    function fit(box, f) {
        var r = box.getAttribute('data-r'); if (!r || !f) { return; }
        var p = r.split(':'), a = +p[0], b = +p[1]; if (!a || !b) { return; }
        var cap = CAP[r];
        if (cap) { box.style.maxWidth = cap + 'px'; box.style.marginLeft = 'auto'; box.style.marginRight = 'auto'; }
        box.style.aspectRatio = a + ' / ' + b;
        var w = Math.round(box.clientWidth), h = Math.round(w * b / a);
        f.style.width = '100%'; f.style.height = '100%';
        var src = f.getAttribute('src') || f.getAttribute('data-fbsrc') || '';
        if (/facebook\.com\/plugins\/video\.php/.test(src)) { box.classList.add('sb-embed--vid'); }
        if (/facebook\.com\/plugins\/(post|video)\.php/.test(src) && w > 0 && box.getAttribute('data-fw') !== w + 'x' + h) {
            box.setAttribute('data-fw', w + 'x' + h);
            var u = src.replace(/([?&])width=\d+/, '$1width=' + Math.max(180, Math.min(750, w)));
            u = /([?&])height=\d+/.test(u) ? u.replace(/([?&])height=\d+/, '$1height=' + h) : u + '&height=' + h;
            if (u !== src) { f.setAttribute('src', u); }
        }
    }
    window.SBRATIO = { fit: fit };
    function run() {
        [].slice.call(document.querySelectorAll('.sb-embed--ratio, .sb-soc-slide[data-r][data-ready]:not([hidden])')).forEach(function (b) { fit(b, b.querySelector('iframe')); });
    }
    document.addEventListener('DOMContentLoaded', run);
    var t; window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(run, 200); });
})();

/* Page fit: the theme's own wrappers must not leave a white band between the blog design and the footer. */
(function () {
    'use strict';
    var GAP = 24;           // the gap we want between the end of the design and the next block (px)
    var busy = false;

    function lum(c) {
        var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/.exec(c || '');
        if (!m) { return -1; }
        if (m[4] !== undefined && parseFloat(m[4]) < 0.05) { return -1; }
        return (0.2126 * m[1] + 0.7152 * m[2] + 0.0722 * m[3]) / 255;
    }
    function force(el, prop, val) { el.style.setProperty(prop, val, 'important'); }
    function isSpacer(s) {
        if (/^(SCRIPT|STYLE|LINK|NOSCRIPT|TEMPLATE)$/.test(s.tagName)) { return true; }
        if ((s.textContent || '').replace(/\s+/g, '') !== '') { return false; }
        if (s.querySelector('img,svg,iframe,video,canvas,picture,input,button,a')) { return false; }
        return s.offsetHeight <= 300;
    }
    function findAfter(root) {
        var n = root;
        while (n && n !== document.body && n.parentElement) {
            var s = n.nextElementSibling;
            while (s) {
                if (isSpacer(s)) { if (!/^(SCRIPT|STYLE|LINK|NOSCRIPT|TEMPLATE)$/.test(s.tagName)) { force(s, 'display', 'none'); } s = s.nextElementSibling; continue; }
                if (s.offsetHeight > 0) { return { el: s, branch: n }; }
                s = s.nextElementSibling;
            }
            n = n.parentElement;
        }
        return null;
    }
    function fit() {
        if (busy) { return; }
        var root = document.querySelector('.shb');
        if (!root || !document.body.classList.contains('shb-page')) { return; }
        busy = true;
        try {
            // 1) light theme wrappers around the design must not paint a white block of their own
            for (var a = root.parentElement; a && a !== document.documentElement; a = a.parentElement) {
                if (a === document.body) { break; }
                if (lum(getComputedStyle(a).backgroundColor) > 0.93 && !a.querySelector('footer, [role=contentinfo]')) { force(a, 'background-color', 'transparent'); }
            }
            // 2) find what comes after the design and remove the theme's empty space in between
            var f = findAfter(root);
            if (!f) { return; }
            force(root, 'margin-bottom', '0');
            for (var p = root.parentElement, b = root; b && b !== f.branch.parentElement; b = p, p = b ? b.parentElement : null) {
                if (b !== root) { force(b, 'padding-bottom', '0'); force(b, 'margin-bottom', '0'); }
                if (b === f.branch) { break; }
            }
            force(f.el, 'margin-top', '0');
            // 3) what is left: bring the next block up to GAP px
            var gap = f.el.getBoundingClientRect().top - root.getBoundingClientRect().bottom;
            if (gap > GAP + 4) {
                var cur = parseFloat(getComputedStyle(f.branch).marginBottom) || 0;
                force(f.branch, 'margin-bottom', (cur - (gap - GAP)) + 'px');
            }
        } catch (e) { /* design must never break because of this */ } finally { busy = false; }
    }
    var t;
    function later() { clearTimeout(t); t = setTimeout(fit, 120); }
    document.addEventListener('DOMContentLoaded', function () { fit(); setTimeout(fit, 600); });
    window.addEventListener('load', function () { fit(); setTimeout(fit, 800); });
    window.addEventListener('resize', later);
    if (window.ResizeObserver) { new ResizeObserver(later).observe(document.body); }
    window.SBFIT = { fit: fit };
})();
