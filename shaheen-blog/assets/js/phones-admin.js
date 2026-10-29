(function () {
    var $ = function (s) { return document.querySelector(s); };
    var msg = $('#ph-msg');
    function say(t, ok) { msg.textContent = t; msg.style.color = ok ? '#1a6b3e' : '#b32d2e'; }
    function post(action, data) {
        var fd = new FormData();
        fd.append('action', action); fd.append('nonce', SB.nonce);
        Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(SB.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    var F = ['brand', 'model', 'year', 'h', 'w', 't', 'weight', 'screen', 'ram', 'storage', 'battery', 'chipset', 'price', 'url'];
    function formData() { var d = { id: $('#ph-id').value }; F.forEach(function (f) { d[f] = $('#ph-' + f).value; }); return d; }

    $('#ph-save').addEventListener('click', function () {
        say('সেভ হচ্ছে…', true);
        post('sb_phone_save', formData()).then(function (r) {
            if (r.success) { location.href = SBPH.url; } else { say(r.data.message, false); }
        });
    });
    [].forEach.call(document.querySelectorAll('[data-del]'), function (b) {
        b.addEventListener('click', function () {
            if (!confirm('এই মডেল মুছবেন?')) { return; }
            post('sb_phone_delete', { id: b.dataset.del }).then(function () { b.closest('tr').remove(); });
        });
    });
    [].forEach.call(document.querySelectorAll('[data-pub]'), function (b) {
        b.addEventListener('click', function () { post('sb_phone_publish', { id: b.dataset.pub }).then(function () { location.reload(); }); });
    });
    $('#ph-cfg-save').addEventListener('click', function () {
        post('sb_phones_cfg', {
            auto: $('#c-auto').checked ? '1' : '0', review: $('#c-review').checked ? '1' : '0',
            days: $('#c-days').value, per_run: $('#c-per').value, initial: $('#c-init').value,
            brands: $('#c-brands').value, market: $('#c-market').value
        }).then(function (r) { say(r.success ? 'সেটিংস সেভ হয়েছে।' : 'সেভ হয়নি।', r.success); });
    });
    $('#ph-run').addEventListener('click', function () {
        var b = this; b.disabled = true; say('AI ওয়েবে খুঁজছে… ১-৩ মিনিট লাগতে পারে, পেজ বন্ধ করবেন না।', true);
        post('sb_phones_run', {}).then(function (r) {
            b.disabled = false;
            if (r.success) {
                say('✅ ' + r.data.added + 'টি নতুন মডেল যোগ হয়েছে (বিল: ' + (r.data.cost_usd || '') + ')।', true);
                setTimeout(function () { location.reload(); }, 1200);
            } else { say(r.data.message, false); }
        }).catch(function () { b.disabled = false; say('সংযোগে সমস্যা — আবার চেষ্টা করুন।', false); });
    });
    $('#ph-page').addEventListener('click', function () {
        post('sb_phones_page', {}).then(function (r) {
            if (r.success) { say('পেজ তৈরি: ' + r.data.url, true); window.open(r.data.url, '_blank'); } else { say(r.data.message, false); }
        });
    });
})();
