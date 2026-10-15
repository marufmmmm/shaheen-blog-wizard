(function () {
  var C = window.SBMEM; if (!C) { return; }
  var $ = function (s) { return document.querySelector(s); };
  var items = C.items.slice();
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function post(action, data) {
    var fd = new FormData(); fd.append('action', action); fd.append('nonce', SB.nonce);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(SB.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function msg(t, ok) { var m = $('#mem-msg'); m.textContent = t; m.style.color = ok ? '#0a7a3d' : '#b32d2e'; }
  function render() {
    var box = $('#mem-list'); box.innerHTML = '';
    if (!items.length) { box.innerHTML = '<p class="sba-muted">এখনো কিছু মনে রাখা নেই। লেখা ঠিক করে সেভ করলে এখানে জমা হবে, বা নিজে যোগ করুন।</p>'; return; }
    items.forEach(function (it, i) {
      var row = document.createElement('div');
      row.style.cssText = 'display:flex;gap:8px;align-items:center;margin:6px 0;flex-wrap:wrap';
      row.innerHTML = '<span style="min-width:62px;font-weight:600">' + (it.type === 'fact' ? '📌 তথ্য' : '📝 নিয়ম') + '</span>' +
        '<input type="text" class="m-text" style="flex:1;min-width:240px" maxlength="400" value="' + esc(it.text) + '">' +
        '<label class="sba-inline-check"><input type="checkbox" class="m-on"' + (it.on ? ' checked' : '') + '> চালু</label>' +
        '<small class="sba-muted">' + (it.src === 'auto' ? 'নিজে শিখেছে' : 'আপনার দেওয়া') + '</small>' +
        '<button type="button" class="button m-del">মুছুন</button>';
      row.querySelector('.m-text').oninput = function () { it.text = this.value; };
      row.querySelector('.m-on').onchange = function () { it.on = this.checked ? 1 : 0; };
      row.querySelector('.m-del').onclick = function () { items.splice(i, 1); render(); };
      box.appendChild(row);
    });
  }
  function add(type) { items.unshift({ id: Math.random().toString(36).slice(2, 10), type: type, text: '', on: 1, src: 'manual' }); render(); var f = document.querySelector('.m-text'); if (f) { f.focus(); } }
  $('#mem-add-rule').onclick = function () { add('rule'); };
  $('#mem-add-fact').onclick = function () { add('fact'); };
  $('#mem-save').onclick = function () {
    var clean = items.filter(function (i) { return i.text.trim() !== ''; });
    msg('সেভ হচ্ছে…', true);
    post('sb_mem_save', { items: JSON.stringify(clean), auto: $('#mem-auto').checked ? '1' : '0' }).then(function (r) {
      if (r && r.success) { items = r.data.items; render(); msg('✔ সেভ হয়েছে।', true); } else { msg((r && r.data && r.data.message) || 'সেভ হয়নি।', false); }
    }).catch(function () { msg('সংযোগ ব্যর্থ।', false); });
  };
  render();

  // website memory
  var st = $('#site-status');
  function crawl(start) {
    var d = { start: start ? '1' : '0' };
    if (start) { d.urls = $('#site-urls').value; }
    return post('sb_site_crawl', d).then(function (r) {
      if (!r || !r.success) { st.textContent = '❌ ' + ((r && r.data && r.data.message) || 'পড়া যায়নি।'); $('#site-go').disabled = false; return; }
      var d2 = r.data;
      st.textContent = '⏳ পড়া হচ্ছে… ' + d2.pages + 'টি পেজ মনে রাখা হয়েছে, বাকি লিংক ' + d2.queue + 'টি';
      if (d2.finished) { st.textContent = '✔ শেষ। মোট ' + d2.pages + 'টি পেজ মনে রাখা হয়েছে।'; $('#site-go').disabled = false; setTimeout(function () { location.reload(); }, 1200); return; }
      return crawl(false);
    }).catch(function () { st.textContent = '❌ সংযোগ ব্যর্থ। আবার চেষ্টা করুন।'; $('#site-go').disabled = false; });
  }
  $('#site-go').onclick = function () {
    this.disabled = true; st.textContent = '⏳ শুরু হচ্ছে…';
    post('sb_site_cfg', { max_pages: $('#site-max').value }).then(function () { crawl(true); });
  };
  $('#site-clear').onclick = function () {
    post('sb_site_clear').then(function () { location.reload(); });
  };
})();
