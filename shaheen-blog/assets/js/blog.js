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

    document.addEventListener('DOMContentLoaded', function () {
        var blogs = document.querySelectorAll('.shb-blog');
        [].slice.call(blogs).forEach(function (r) { markPage(r); initBlog(r); });
        if (document.querySelector('.shb-single')) { document.body.classList.add('shb-page'); }
        initCopy();
    });
})();
