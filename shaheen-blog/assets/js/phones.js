(function () {
    var root = document.querySelector('[data-shb-ph]');
    if (!root) { return; }
    var cards = [].slice.call(root.querySelectorAll('.shb-ph-card'));
    var grid = root.querySelector('.shb-ph-grid');
    var q = root.querySelector('.shb-ph-q'), sort = root.querySelector('.shb-ph-sort');
    var more = root.querySelector('.shb-ph-more button'), none = root.querySelector('.shb-ph-none');
    var brand = '', limit = 24, STEP = 24;
    function num(v, big) { var n = parseFloat(v); return isNaN(n) ? big : n; }
    function render() {
        var term = q.value.trim().toLowerCase();
        var list = cards.filter(function (c) {
            return (!brand || c.getAttribute('data-brand') === brand) && (!term || c.getAttribute('data-name').indexOf(term) > -1);
        });
        var s = sort.value;
        list.sort(function (a, b) {
            if (s === 'h') { return num(a.dataset.h, 9999) - num(b.dataset.h, 9999); }
            if (s === 'wt') { return num(a.dataset.wt, 9999) - num(b.dataset.wt, 9999); }
            if (s === 'az') { return a.dataset.name < b.dataset.name ? -1 : 1; }
            return num(a.dataset.i, 0) - num(b.dataset.i, 0);
        });
        cards.forEach(function (c) { c.hidden = true; });
        list.forEach(function (c, i) { grid.appendChild(c); c.hidden = i >= limit; });
        more.hidden = list.length <= limit;
        none.hidden = list.length > 0;
    }
    q.addEventListener('input', function () { limit = STEP; render(); });
    sort.addEventListener('change', function () { limit = STEP; render(); });
    more.addEventListener('click', function () { limit += STEP; render(); });
    [].forEach.call(root.querySelectorAll('.shb-ph-brands button'), function (b) {
        b.addEventListener('click', function () {
            [].forEach.call(root.querySelectorAll('.shb-ph-brands button'), function (x) { x.classList.remove('on'); });
            b.classList.add('on'); brand = b.getAttribute('data-brand'); limit = STEP; render();
        });
    });
    render();
})();
