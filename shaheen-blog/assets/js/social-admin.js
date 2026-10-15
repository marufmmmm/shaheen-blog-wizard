(function () {
  var C = window.SBSOC; if (!C) { return; }
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var list = $('#soc-items'), items = C.items.slice();
  var NEEDS_SRC = { fb_post: 1, yt_video: 1, ig: 1, code: 1 };
  var OPT_SRC = { fb_like: 1, fb_card: 1, fb_timeline: 1, yt_latest: 1, ig_card: 1 };
  function uid() { return Math.random().toString(36).slice(2, 10); }
  function el(t, c, h) { var e = document.createElement(t); if (c) { e.className = c; } if (h != null) { e.innerHTML = h; } return e; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function post(action, data) {
    var fd = new FormData(); fd.append('action', action); fd.append('nonce', SB.nonce);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(SB.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function sync() { $('#soc-json').value = JSON.stringify(items); }
  function render() {
    list.innerHTML = '';
    if (!items.length) { list.appendChild(el('p', 'sba-muted', 'এখনো কোনো আইটেম নেই। নিচের "＋ নতুন আইটেম" চাপুন।')); }
    items.forEach(function (it, i) {
      var row = el('div', 'sba-card'); row.style.cssText = 'margin:10px 0;padding:12px;background:#fafcfb';
      var opts = Object.keys(C.types).map(function (k) { return '<option value="' + k + '"' + (k === it.type ? ' selected' : '') + '>' + esc(C.types[k]) + '</option>'; }).join('');
      var src = NEEDS_SRC[it.type]
        ? '<label>' + (it.type === 'code' ? 'এমবেড কোড' : 'লিংক <small>(একাধিক পোস্ট/ভিডিও দেখাতে প্রতি লাইনে একটি লিংক দিন)</small>') + '<textarea class="i-src" rows="3" style="font-family:monospace;font-size:12px">' + esc(it.src) + '</textarea></label>'
        : '<label>লিংক <small>(ফাঁকা রাখলে ওপরের সাধারণ পেজ লিংক)</small><input type="text" class="i-src" value="' + esc(it.src) + '"></label>';
      var hh = (it.type in C.h || it.type === 'code') ? '<label>উচ্চতা (px) <small>(০ = নিজে ঠিক)</small><input type="number" class="i-h" min="0" max="1400" value="' + (it.h || 0) + '" style="max-width:110px"></label>' : '';
      var RATIO_T = { fb_post: 1, yt_video: 1, ig: 1, yt_latest: 1 };
      var rr = RATIO_T[it.type] ? '<label>বক্সের আকার (ফরম্যাট) <small>(ভিডিও/পোস্ট দেখে বেছে নিন)</small><select class="i-r">' + Object.keys(SB.ratios || {}).map(function (k) { return '<option value="' + k + '"' + (k === (it.r || '') ? ' selected' : '') + '>' + esc(SB.ratios[k]) + '</option>'; }).join('') + '</select></label>' : '';
      var TABS = { 'timeline': 'শুধু পোস্ট (Timeline)', 'events': 'শুধু ইভেন্ট (Events)', 'timeline,events': 'পোস্ট + ইভেন্ট', 'messages': 'শুধু মেসেজ বাটন', 'timeline,messages': 'পোস্ট + মেসেজ', 'timeline,events,messages': 'পোস্ট + ইভেন্ট + মেসেজ' };
      var tb = it.type === 'fb_timeline' ? '<label>কী কী ট্যাব দেখাবে <small>(পোস্ট না এলে "ইভেন্ট" চেষ্টা করুন)</small><select class="i-tabs">' + Object.keys(TABS).map(function (k) { return '<option value="' + k + '"' + (k === (it.tabs || 'timeline') ? ' selected' : '') + '>' + esc(TABS[k]) + '</option>'; }).join('') + '</select></label>' : '';
      var nn = it.type === 'yt_latest' ? '<label>কয়টি ভিডিও<input type="number" class="i-n" min="1" max="5" value="' + (it.n || 3) + '" style="max-width:90px"></label>' : '';
      row.innerHTML =
        '<div class="sba-row"><label>ধরন<select class="i-type">' + opts + '</select></label><label>নাম (শুধু আপনার জন্য)<input type="text" class="i-name" value="' + esc(it.name) + '"></label></div>' +
        (it.type === 'fb_timeline' ? '<p class="sba-muted" style="margin:6px 0">ℹ️ ফেসবুক নিজে ঠিক করে পেজের সর্বশেষ পোস্ট দেখাবে কি না। অনেক পেজে সে শুধু পেজের নাম ও Follow বাটন দেখায়, পোস্ট দেখায় না। এমন হলে নির্দিষ্ট পোস্ট/ভিডিও ধরন ব্যবহার করুন (প্রতি লাইনে একটি লিংক)।</p>' : '') +
        src + '<div class="sba-row">' + hh + nn + rr + tb + '</div>' +
        '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><label class="sba-inline-check"><input type="checkbox" class="i-on"' + (it.on ? ' checked' : '') + '> চালু</label>' +
        '<button type="button" class="button i-prev">👁 দেখুন</button><button type="button" class="button i-up" title="ওপরে">↑</button><button type="button" class="button i-dn" title="নিচে">↓</button><button type="button" class="button i-del">🗑 মুছুন</button></div>' +
        '<div class="i-view" style="margin-top:10px;max-width:520px"></div>';
      var q = function (s) { return $(s, row); };
      function read() {
        it.type = q('.i-type').value; it.name = q('.i-name').value; it.src = q('.i-src').value; it.on = q('.i-on').checked ? 1 : 0;
        if (q('.i-r')) { it.r = q('.i-r').value; } else { it.r = ''; }
        if (q('.i-tabs')) { it.tabs = q('.i-tabs').value; }
        if (q('.i-h')) { it.h = parseInt(q('.i-h').value, 10) || 0; } if (q('.i-n')) { it.n = parseInt(q('.i-n').value, 10) || 3; }
        sync();
      }
      row.addEventListener('input', read); row.addEventListener('change', read);
      q('.i-type').addEventListener('change', function () { read(); render(); });
      q('.i-up').addEventListener('click', function () { if (i) { items.splice(i - 1, 0, items.splice(i, 1)[0]); render(); sync(); } });
      q('.i-dn').addEventListener('click', function () { if (i < items.length - 1) { items.splice(i + 1, 0, items.splice(i, 1)[0]); render(); sync(); } });
      q('.i-del').addEventListener('click', function () { items.splice(i, 1); render(); sync(); });
      q('.i-prev').addEventListener('click', function () {
        read(); var v = q('.i-view'); v.innerHTML = '<em>লোড হচ্ছে…</em>';
        post('sb_social_preview', { item: JSON.stringify(it) }).then(function (r) {
          if (!r.success) { v.innerHTML = '<span style="color:#b32d2e">' + esc(r.data.message) + '</span>'; return; }
          v.innerHTML = '';
          r.data.slides.forEach(function (sl) {
            var w = el('div'); w.style.cssText = 'margin-bottom:10px;text-align:center';
            if (sl.kind === 'fblink') { w.innerHTML = '<a href="' + esc(sl.url) + '" target="_blank" rel="noopener" style="display:inline-block;background:#1877f2;color:#fff;padding:10px 20px;border-radius:999px;font-weight:700;text-decoration:none">' + esc(sl.title) + '</a>'; }
            else if (sl.kind === 'igcard') { w.innerHTML = '<a href="' + esc(sl.url) + '" target="_blank" rel="noopener">📸 ' + esc(sl.title) + '</a>'; }
            else if (sl.kind === 'yt') { w.innerHTML = '<img src="' + esc(sl.thumb) + '" style="max-width:100%"><div>' + esc(sl.title) + '</div>'; }
            else {
              var f = document.createElement('iframe'), pw = Math.max(180, Math.min(500, v.clientWidth || 400));
              f.src = sl.wa ? sl.src.replace(/([?&])width=\d+/, '$1width=' + pw) : sl.src;
              f.style.cssText = 'border:0;max-width:100%;width:' + (sl.wa ? pw + 'px' : '100%') + ';height:' + (sl.h || 420) + 'px';
              if (sl.r) { var rp = sl.r.split(':'); f.style.height = Math.round((v.clientWidth > 520 ? 520 : (v.clientWidth || 400)) * rp[1] / rp[0]) + 'px'; } f.setAttribute('scrolling', 'no'); w.appendChild(f);
            }
            v.appendChild(w);
          });
        });
      });
      list.appendChild(row);
    });
    sync();
  }
  $('#soc-add').addEventListener('click', function () {
    items.push({ id: uid(), on: 1, type: 'fb_like', name: '', src: '', h: 0, n: 3, r: '' }); render();
  });
  render();
})();
