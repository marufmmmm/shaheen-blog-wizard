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
                return filter === 'all' || c.getAttribute('data-cat') === filter;
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

    document.addEventListener('DOMContentLoaded', function () {
        var blogs = document.querySelectorAll('.shb-blog');
        [].slice.call(blogs).forEach(function (r) { markPage(r); initBlog(r); });
        if (document.querySelector('.shb-single')) { document.body.classList.add('shb-page'); }
        initCopy();
        scheduleAlign();
        countView();
    });
})();
