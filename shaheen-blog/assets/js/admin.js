(function () {
  'use strict';
  if (typeof SB === 'undefined') return;

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var BN = '০১২৩৪৫৬৭৮৯';
  function bn(n) { return String(n).replace(/\d/g, function (d) { return BN[d]; }); }
  function fmt(n) { return bn(Number(n).toLocaleString('en-US')); }

  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', SB.nonce);
    Object.keys(data || {}).forEach(function (k) {
      var v = data[k];
      if (Array.isArray(v)) {
        v.forEach(function (x, i) {
          if (x && typeof x === 'object') {
            Object.keys(x).forEach(function (kk) { fd.append(k + '[' + i + '][' + kk + ']', x[kk]); });
          } else { fd.append(k + '[]', x); }
        });
      } else if (v !== undefined && v !== null) { fd.append(k, v); }
    });
    return fetch(SB.ajax, { method: 'POST', credentials: 'same-origin', body: fd })
      .catch(function () { throw new Error('ইন্টারনেট সংযোগে সমস্যা হয়েছে।'); })
      .then(function (r) {
        return r.text().then(function (t) {
          try { return JSON.parse(t); } catch (e) {
            throw new Error('সার্ভার থেকে সঠিক উত্তর আসেনি (HTTP ' + r.status + ')। সময় শেষ হয়ে থাকতে পারে, আবার চেষ্টা করুন।');
          }
        });
      })
      .then(function (j) {
        if (j && j.data && j.data.balance) updateBalance(j.data.balance);
        if (!j || !j.success) throw new Error((j && j.data && j.data.message) || 'কিছু একটা ভুল হয়েছে।');
        return j.data;
      });
  }

  function updateBalance(b) {
    SB.balance = b;
    $$('[data-sb="remaining"]').forEach(function (e) { e.textContent = fmt(b.remaining); });
    $$('[data-sb="bar"]').forEach(function (e) { e.style.width = b.percent + '%'; });
    $$('[data-sb="reset"]').forEach(function (e) { e.textContent = b.next_reset_bn; });
  }

  function say(el, text, kind) {
    if (!el) return;
    el.textContent = text || '';
    el.className = 'sba-msg' + (kind ? ' sba-msg--' + kind : '');
  }

  /* ---------------- categories (page + wizard) ---------------- */
  function addCategory(bnEl, enEl, msgEl, done) {
    var b = bnEl.value.trim();
    if (!b) { say(msgEl, 'বাংলা নাম লিখুন।', 'err'); return; }
    post('sb_add_category', { name_bn: b, name_en: enEl.value.trim() }).then(function (d) {
      bnEl.value = ''; enEl.value = '';
      say(msgEl, '✓ যোগ হয়েছে', 'ok');
      done(d);
    }).catch(function (e) { say(msgEl, e.message, 'err'); });
  }
  var ncAddPage = $('#nc-add-page');
  if (ncAddPage) {
    ncAddPage.addEventListener('click', function () {
      addCategory($('#nc-bn'), $('#nc-en'), $('#msg'), function () { location.reload(); });
    });
  }
  $$('.sba-cat-del').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      if (!a.dataset.sure) { a.dataset.sure = 1; a.textContent = 'নিশ্চিত? আবার ক্লিক'; return; }
      post('sb_delete_category', { id: a.dataset.id }).then(function () {
        a.closest('tr,li').remove();
      }).catch(function (er) { a.textContent = er.message; });
    });
  });

  /* ---------------- posts list ---------------- */
  $$('.sba-trash').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      if (!a.dataset.sure) { a.dataset.sure = 1; a.textContent = 'নিশ্চিত? আবার ক্লিক'; return; }
      post('sb_trash_post', { id: a.dataset.id }).then(function () {
        a.closest('tr,li').remove();
      }).catch(function (er) { a.textContent = er.message; });
    });
  });

  /* ---------------- press coverage ---------------- */
  var prSave = $('#pr-save');
  if (prSave) {
    $('#pr-pick').addEventListener('click', function () {
      if (!window.wp || !wp.media) return;
      var fr = wp.media({ title: 'সংবাদের ছবি', library: { type: 'image' }, multiple: false });
      fr.on('select', function () {
        var a = fr.state().get('selection').first().toJSON();
        $('#pr-image-id').value = a.id; var im = $('#pr-img'); im.src = (a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url; im.hidden = false; $('#pr-unpick').hidden = false;
      });
      fr.open();
    });
    $('#pr-unpick').addEventListener('click', function () { $('#pr-image-id').value = 0; $('#pr-img').hidden = true; this.hidden = true; });
    prSave.addEventListener('click', function () {
      post('sb_save_press', {
        id: $('#pr-id').value, title: $('#pr-title').value, paper: $('#pr-paper').value, url: $('#pr-url').value,
        date: $('#pr-date').value, category: $('#pr-cat').value, excerpt: $('#pr-excerpt').value, image_id: $('#pr-image-id').value
      }).then(function (d) {
        say($('#msg'), '✓ সেভ হয়েছে', 'ok');
        location.href = location.pathname + '?page=sb-press';
      }).catch(function (e) { say($('#msg'), e.message, 'err'); });
    });
  }

  /* ---------------- settings tools ---------------- */
  var subSave = $('#sub-save');
  if (subSave) {
    subSave.addEventListener('click', function () {
      post('sb_set_subscription', {
        user_id: $('#sub-user').value, start_date: $('#sub-date').value, limit: $('#sub-limit').value
      }).then(function (d) {
        say($('#sub-msg'), '✓ সেট হয়েছে। পরবর্তী রিসেট: ' + d.balance.next_reset_bn, 'ok');
      }).catch(function (e) { say($('#sub-msg'), e.message, 'err'); });
    });
  }
  var demoDel = $('#demo-del');
  if (demoDel) {
    demoDel.addEventListener('click', function () {
      if (!demoDel.dataset.sure) { demoDel.dataset.sure = 1; demoDel.textContent = 'নিশ্চিত? আবার ক্লিক করুন'; return; }
      post('sb_delete_demo', {}).then(function (d) {
        say($('#demo-msg'), '✓ ' + bn(d.deleted) + 'টি ডেমো পোস্ট মুছেছে।', 'ok');
        demoDel.textContent = 'ডেমো পোস্ট মুছুন'; delete demoDel.dataset.sure;
      }).catch(function (e) { say($('#demo-msg'), e.message, 'err'); });
    });
  }

  /* "Give an instruction" box: correct the existing text, or write it again */
  function editBox(p, getFn, setFn, ctxFn) {
    var go = $('#' + p + '-go'), undo = $('#' + p + '-undo'), prev = null;
    if (!go) return;
    go.addEventListener('click', function () {
      var ins = val('#' + p + '-ins').trim(), m = $('input[name="' + p + '-mode"]:checked'), mode = m ? m.value : 'edit', cost = SB.costs[mode];
      if (ins.length < 4) { toast('কী বদলাতে চান তা লিখুন।', 'err'); return; }
      if (SB.balance.remaining < cost) { toast('ক্রেডিট যথেষ্ট নয়। প্রয়োজন ' + fmt(cost) + ', আছে ' + fmt(SB.balance.remaining) + '।', 'err'); return; }
      var ctx = ctxFn(), old = go.innerHTML; go.disabled = true; go.textContent = '⏳ হচ্ছে… (১ মিনিট পর্যন্ত লাগতে পারে)';
      post('sb_ai', { feature: mode, post_id: 0, instruction: ins, content: getFn(), title: ctx.title, keywords: ctx.keywords }).then(function (d) {
        prev = getFn(); setFn(d.result.text); undo.hidden = false;
        toast('✓ হয়েছে। ' + fmt(d.cost) + ' ক্রেডিট কেটেছে। পছন্দ না হলে "আগের লেখা ফিরিয়ে আনুন" চাপুন।', 'ok');
        if (d.result.missing && d.result.missing.length) { toast('লেখায় ' + bn(d.result.missing.length) + 'টি জায়গায় আপনার তথ্য দরকার ([তথ্য দরকার: …] খুঁজুন)।', 'err'); }
      }).catch(function (e) { toast(e.message, 'err'); })
        .then(function () { go.disabled = false; go.innerHTML = old; });
    });
    undo.addEventListener('click', function () { if (prev !== null) { setFn(prev); prev = null; undo.hidden = true; toast('আগের লেখা ফিরে এসেছে।', 'ok'); } });
  }

  /* ---------------- link / ad blocks ---------------- */
  function blockEditor(listEl, data, lib) {
    var rows = [];
    function row(b) {
      b = b || {};
      var d = document.createElement('div'); d.className = 'sba-blk';
      d.dataset.id = b.id || '';
      d.innerHTML =
        (lib ? '<label>ব্লকের নাম (শুধু আপনার চেনার জন্য)<input type="text" class="b-name" placeholder="যেমন: ক্যাডেট ভর্তি বাটন"></label>' : '') +
        '<div class="sba-blk-top"><label class="sba-inline-check"><input type="checkbox" class="b-on"> চালু</label>' +
        (lib ? '<label class="sba-inline-check"><input type="checkbox" class="b-auto"> সব পোস্টে অটো দেখাবে</label>' : '') +
        '<select class="b-pos"><option value="start">প্রথম অনুচ্ছেদের পর</option><option value="middle">লেখার মাঝখানে</option><option value="end">লেখার শেষে</option></select>' +
        '<button type="button" class="button-link-delete b-del">মুছুন</button></div>' +
        '<div class="sba-blk-checks">' +
        '<label class="sba-inline-check"><input type="checkbox" class="b-st"> টেক্সট</label>' +
        '<label class="sba-inline-check"><input type="checkbox" class="b-sb"> বাটন</label>' +
        '<label class="sba-inline-check"><input type="checkbox" class="b-lt"> টেক্সটে ক্লিক করলে লিংকে যাবে</label>' +
        '<label class="sba-inline-check"><input type="checkbox" class="b-nt"> নতুন ট্যাবে খুলবে</label>' +
        '<label class="sba-inline-check"><input type="checkbox" class="b-lb"> "বিজ্ঞাপন" লেবেল</label></div>' +
        '<label class="b-text-wrap">টেক্সট<input type="text" class="b-text" placeholder="যেমন: ক্যাডেট ভর্তি চলছে, আজই আবেদন করুন"></label>' +
        '<label class="b-btn-wrap">বাটনের লেখা<input type="text" class="b-btn" placeholder="যেমন: আবেদন করুন"></label>' +
        '<label>লিংক (URL)<input type="text" class="b-url" placeholder="https://…"></label>';
      var q = function (s) { return d.querySelector(s); };
      q('.b-on').checked = b.on === undefined ? true : !!Number(b.on);
      q('.b-pos').value = b.pos || 'middle';
      q('.b-st').checked = b.show_text === undefined ? true : !!Number(b.show_text);
      q('.b-sb').checked = !!Number(b.show_btn || 0);
      q('.b-lt').checked = !!Number(b.link_text || 0);
      q('.b-nt').checked = b.new_tab === undefined ? true : !!Number(b.new_tab);
      q('.b-lb').checked = !!Number(b.label || 0);
      if (lib) { q('.b-name').value = b.name || ''; q('.b-auto').checked = !!Number(b.auto || 0); }
      q('.b-text').value = b.text || ''; q('.b-btn').value = b.btn || ''; q('.b-url').value = b.url || '';
      function vis() {
        q('.b-text-wrap').style.display = q('.b-st').checked ? '' : 'none';
        q('.b-btn-wrap').style.display = q('.b-sb').checked ? '' : 'none';
        q('.b-lt').parentNode.style.display = q('.b-st').checked ? '' : 'none';
      }
      ['.b-st', '.b-sb'].forEach(function (s) { q(s).addEventListener('change', vis); }); vis();
      q('.b-del').addEventListener('click', function () { d.remove(); rows = rows.filter(function (x) { return x !== d; }); });
      listEl.appendChild(d); rows.push(d);
    }
    (data || []).forEach(row);
    return {
      add: function () { row(); },
      get: function () {
        return rows.filter(function (d) { return d.isConnected; }).map(function (d) {
          var q = function (s) { return d.querySelector(s); };
          return { on: q('.b-on').checked ? 1 : 0, pos: q('.b-pos').value, show_text: q('.b-st').checked ? 1 : 0,
            show_btn: q('.b-sb').checked ? 1 : 0, link_text: q('.b-lt').checked ? 1 : 0, new_tab: q('.b-nt').checked ? 1 : 0,
            label: q('.b-lb').checked ? 1 : 0, text: q('.b-text').value, btn: q('.b-btn').value, url: q('.b-url').value,
            id: d.dataset.id, name: lib ? q('.b-name').value : '', auto: lib && q('.b-auto').checked ? 1 : 0 };
        });
      }
    };
  }
  var gList = $('#gblk-list');
  if (gList) {
    var gEd = blockEditor(gList, SB.gblocks, true);
    $('#gblk-add').addEventListener('click', function () { gEd.add(); });
    $('#gblk-save').addEventListener('click', function () {
      var bl = gEd.get(), bad = bl.filter(function (b) { return (b.show_btn || b.link_text) && !b.url.trim(); });
      if (bad.length) { say($('#msg'), 'বাটন বা ক্লিকযোগ্য টেক্সটের জন্য লিংক দিন।', 'err'); return; }
      post('sb_save_global_blocks', { blocks: bl }).then(function () { say($('#msg'), '✓ সেভ হয়েছে', 'ok'); })
        .catch(function (e) { say($('#msg'), e.message, 'err'); });
    });
  }

  /* ---------------- settings PIN ---------------- */
  var pinGo = $('#pin-go');
  if (pinGo) {
    var tryPin = function () {
      post('sb_unlock', { pin: $('#pin').value }).then(function () { location.reload(); })
        .catch(function (e) { say($('#pin-msg'), e.message, 'err'); });
    };
    pinGo.addEventListener('click', tryPin);
    $('#pin').addEventListener('keydown', function (e) { if (e.key === 'Enter') tryPin(); });
  }
  var pinLock = $('#pin-lock');
  if (pinLock) pinLock.addEventListener('click', function () { post('sb_lock', {}).then(function () { location.reload(); }); });

  /* ---------------- rewrite & post page ---------------- */
  if ($('#rw-page')) {
    var rwMsg = $('#msg'), rwId = 0;
    var rwEd = function () { return window.tinymce && tinymce.get('rw_content') && !tinymce.get('rw_content').isHidden() ? tinymce.get('rw_content') : null; };
    var rwGet = function () { var e = rwEd(); return e ? e.getContent() : ($('#rw_content') ? $('#rw_content').value : ''); };
    var rwSet = function (h) { var e = rwEd(); if (e) e.setContent(h); if ($('#rw_content')) $('#rw_content').value = h; };
    var rwKw = function () { return val('#rw-kw').split(/[,،，;；|।\n]+/).map(function (x) { return x.trim(); }).filter(Boolean).slice(0, 10); };
    function toastR(t, k) { toast(t, k); say(rwMsg, t, k); }
    editBox('rwed', rwGet, rwSet, function () { return { title: val('#rw-new-title'), keywords: rwKw() }; });
    $('#rw-pick').addEventListener('click', function () {
      if (!window.wp || !wp.media) return;
      var fr = wp.media({ title: 'ফিচার্ড ছবি', library: { type: 'image' }, multiple: false });
      fr.on('select', function () {
        var a = fr.state().get('selection').first().toJSON();
        $('#rw-image-id').value = a.id; var im = $('#rw-img'); im.src = (a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url; im.hidden = false; $('#rw-unpick').hidden = false;
      });
      fr.open();
    });
    $('#rw-unpick').addEventListener('click', function () { $('#rw-image-id').value = 0; $('#rw-img').hidden = true; this.hidden = true; });

    $('#rw-go').addEventListener('click', function () {
      var btn = this, src = val('#rw-source').trim(), cost = Number(btn.dataset.cost);
      if (src.length < 200) { toastR('কমপক্ষে ২০০ অক্ষরের মূল লেখা পেস্ট করুন।', 'err'); return; }
      if (SB.balance.remaining < cost) { toastR('ক্রেডিট যথেষ্ট নয়। প্রয়োজন ' + fmt(cost) + ', আছে ' + fmt(SB.balance.remaining) + '।', 'err'); return; }
      var old = btn.innerHTML; btn.disabled = true; btn.textContent = '⏳ লেখা হচ্ছে… (১ মিনিট পর্যন্ত লাগতে পারে)'; say(rwMsg, '');
      post('sb_ai', { feature: 'rewrite_post', post_id: 0, title: val('#rw-title'), source: src, keywords: rwKw() }).then(function (d) {
        var r = d.result;
        $('#rw-new-title').value = r.headline || ''; $('#rw-new-excerpt').value = r.excerpt || '';
        var html = r.text, nm = val('#rw-src-name').trim(), url = val('#rw-src-url').trim();
        if (nm) {
          var esc = function (s) { var x = document.createElement('div'); x.textContent = s; return x.innerHTML; };
          html += '<p><em>সূত্র: ' + (url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener nofollow">' + esc(nm) + '</a>' : esc(nm)) + '</em></p>';
        }
        $('#rw-result').hidden = false; rwSet(html);
        var fb = $('#rw-facts'); fb.innerHTML = '';
        if (r.facts_missing && r.facts_missing.length) {
          var s = document.createElement('strong'); s.textContent = '⚠️ মূল লেখার এই সংখ্যা/তারিখগুলো নতুন লেখায় পাওয়া যায়নি, মিলিয়ে নিন: ' + r.facts_missing.join(', '); fb.appendChild(s); fb.hidden = false;
        } else { fb.hidden = true; }
        toastR('✓ হয়েছে। ' + fmt(d.cost) + ' ক্রেডিট কেটেছে। প্রকাশের আগে তথ্য মিলিয়ে নিন।', 'ok');
        $('#rw-result').scrollIntoView({ behavior: 'smooth' });
      }).catch(function (e) { toastR(e.message, 'err'); })
        .then(function () { btn.disabled = false; btn.innerHTML = old; });
    });

    $('#rw-save').addEventListener('click', function () {
      var btn = this, t = val('#rw-new-title').trim();
      if (!t) { toast('শিরোনাম লিখুন।', 'err'); return; }
      var st = $('input[name="rw-status"]:checked');
      btn.disabled = true;
      post('sb_save_post', {
        id: rwId, title: t, excerpt: val('#rw-new-excerpt'), meta_desc: val('#rw-new-excerpt'), content: rwGet(), category: val('#rw-cat'),
        keywords: rwKw(), image_id: val('#rw-image-id'), status: st ? st.value : 'draft'
      }).then(function (d) {
        rwId = d.id; var box = $('#rw-saved'); box.innerHTML = '';
        var p = document.createElement('div'); p.className = 'notice notice-success inline';
        var tx = document.createElement('p'); tx.textContent = d.status === 'publish' ? '✓ প্রকাশিত হয়েছে। ' : '✓ খসড়া সেভ হয়েছে। ';
        if (d.status === 'publish') { var a = document.createElement('a'); a.href = d.url; a.target = '_blank'; a.textContent = 'পোস্ট দেখুন ↗'; tx.appendChild(a); tx.appendChild(document.createTextNode('  ')); }
        var e2 = document.createElement('a'); e2.href = d.edit; e2.textContent = 'আরও এডিট (ওয়িজার্ডে)'; tx.appendChild(e2);
        p.appendChild(tx); box.appendChild(p);
      }).catch(function (e) { toast(e.message, 'err'); }).then(function () { btn.disabled = false; });
    });
  }

  /* ---------------- wizard ---------------- */
  var form = $('#sb-form');
  if (!form) return;
  var P = window.SB_POST || {};
  var step = 1, TOTAL = 5, postId = P.id || 0, msg = $('#msg');

  function editorContent() {
    if (window.tinymce && tinymce.get('sb_content') && !tinymce.get('sb_content').isHidden()) {
      return tinymce.get('sb_content').getContent();
    }
    var t = $('#sb_content'); return t ? t.value : '';
  }
  function setEditorContent(html) {
    if (window.tinymce && tinymce.get('sb_content') && !tinymce.get('sb_content').isHidden()) {
      tinymce.get('sb_content').setContent(html);
    }
    var t = $('#sb_content'); if (t) t.value = html;
  }
  function keywords() {
    var seen = {};
    return val('#f-kw').split(/[,،，;；|।\n]+/).map(function (x) { return x.trim(); })
      .filter(function (x) { if (!x || seen[x]) return false; seen[x] = 1; return true; }).slice(0, 10);
  }
  function chips() {
    var box = $('#kw-chips'); box.innerHTML = '';
    keywords().forEach(function (k) { var c = document.createElement('span'); c.textContent = k; box.appendChild(c); });
  }
  $('#f-kw').addEventListener('input', chips); chips();
  function toast(text, kind) {
    var t = $('#sb-toast');
    if (!t) { t = document.createElement('div'); t.id = 'sb-toast'; document.body.appendChild(t); }
    t.className = 'sba-toast' + (kind ? ' sba-toast--' + kind : ''); t.textContent = text; t.style.display = 'block';
    clearTimeout(t._h); t._h = setTimeout(function () { t.style.display = 'none'; }, 6000);
  }
  function val(id) { var e = $(id); return e ? e.value : ''; }

  function validate(s) {
    if (s === 1 && !val('#f-title').trim()) return 'শিরোনাম লিখুন।';
    if (s === 2 && !postId && keywords().length < 2) return 'কমপক্ষে ২টি কীওয়ার্ড দিন, প্রতিটির মাঝে কমা (,) দিন। এখন পাওয়া গেছে: ' + bn(keywords().length) + 'টি।';
    return '';
  }

  function show(n) {
    step = n;
    $$('.sba-step').forEach(function (s) { s.hidden = Number(s.dataset.step) !== n; });
    $$('#sb-steps li').forEach(function (li, i) { li.classList.toggle('is-active', i + 1 === n); li.classList.toggle('is-done', i + 1 < n); });
    $('#btn-prev').style.visibility = n === 1 ? 'hidden' : 'visible';
    $('#btn-next').style.display = n === TOTAL ? 'none' : '';
    say(msg, '');
    if (n === TOTAL) buildReview();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  function go(n) {
    if (n > step) { for (var s = step; s < n; s++) { var er = validate(s); if (er) { show(s); say(msg, er, 'err'); return; } } }
    show(n);
  }
  $('#btn-next').addEventListener('click', function () { go(step + 1); });
  $('#btn-prev').addEventListener('click', function () { go(step - 1); });
  $$('#sb-steps [data-go]').forEach(function (b) { b.addEventListener('click', function () { go(Number(b.dataset.go)); }); });

  /* media picker */
  var imgId = P.image_id || 0;
  var pv = $('#f-image-preview'), rm = $('#f-image-remove');
  $('#f-image-pick').addEventListener('click', function () {
    if (!window.wp || !wp.media) return;
    var frame = wp.media({ title: 'ফিচার্ড ছবি', library: { type: 'image' }, multiple: false });
    frame.on('select', function () {
      var a = frame.state().get('selection').first().toJSON();
      imgId = a.id;
      pv.src = (a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url;
      pv.hidden = false; rm.hidden = false;
    });
    frame.open();
  });
  rm.addEventListener('click', function () { imgId = 0; pv.hidden = true; pv.removeAttribute('src'); rm.hidden = true; });

  /* FAQ */
  var faqList = $('#faq-list');
  function addFaq(q, a) {
    var d = document.createElement('div'); d.className = 'sba-faq';
    d.innerHTML = '<input type="text" class="faq-q" placeholder="প্রশ্ন"><textarea class="faq-a" rows="2" placeholder="উত্তর"></textarea><button type="button" class="button-link-delete">মুছুন</button>';
    d.querySelector('.faq-q').value = q || ''; d.querySelector('.faq-a').value = a || '';
    d.querySelector('button').addEventListener('click', function () { d.remove(); });
    faqList.appendChild(d);
  }
  (P.faq || []).forEach(function (f) { addFaq(f.q, f.a); });
  $('#faq-add').addEventListener('click', function () { addFaq(); });
  function faqData() {
    return $$('.sba-faq').map(function (d) { return { q: d.querySelector('.faq-q').value.trim(), a: d.querySelector('.faq-a').value.trim() }; })
      .filter(function (x) { return x.q && x.a; });
  }

  /* category quick add */
  var ncAdd = $('#nc-add');
  if (ncAdd) {
    ncAdd.addEventListener('click', function () {
      addCategory($('#nc-bn'), $('#nc-en'), msg, function (d) {
        var o = document.createElement('option'); o.value = d.id; o.textContent = d.name + (d.name_en ? ' (' + d.name_en + ')' : '');
        $('#f-category').appendChild(o); $('#f-category').value = d.id;
      });
    });
  }

  var pEd = blockEditor($('#blk-list'), P.blocks || []);
  var pickBox = $('#blk-pick'), picked = (P.block_ids || []).slice();
  if (!SB.gblocks.length) { pickBox.textContent = 'লাইব্রেরিতে এখনো কোনো ব্লক নেই।'; }
  SB.gblocks.forEach(function (b) {
    var l = document.createElement('label'), c = document.createElement('input'); c.type = 'checkbox'; c.value = b.id; c.checked = picked.indexOf(b.id) > -1;
    c.addEventListener('change', function () { var i = picked.indexOf(b.id); if (c.checked && i < 0) picked.push(b.id); if (!c.checked && i > -1) picked.splice(i, 1); });
    l.className = 'sba-inline-check'; l.style.marginRight = '14px';
    l.appendChild(c); l.appendChild(document.createTextNode(' ' + (b.name || (b.text || b.btn || 'ব্লক')) + (Number(b.auto) ? ' (অটো)' : '')));
    pickBox.appendChild(l);
  });
  $('#blk-add').addEventListener('click', function () { pEd.add(); });
  $('#no-global').checked = !!Number(P.no_global || 0);
  $('#no-social').checked = !!Number(P.no_social || 0);

  /* related posts/pages picker */
  var rel = (P.related || []).slice();
  function drawRel() {
    var box = $('#rel-chosen'); box.innerHTML = '';
    rel.forEach(function (r, i) {
      var c = document.createElement('span'); c.textContent = r.title + ' ✕'; c.style.cursor = 'pointer';
      c.addEventListener('click', function () { rel.splice(i, 1); drawRel(); }); box.appendChild(c);
    });
  }
  drawRel();
  var relT;
  $('#rel-q').addEventListener('input', function () {
    var q = this.value.trim(), ul = $('#rel-results'); clearTimeout(relT);
    if (q.length < 2) { ul.innerHTML = ''; return; }
    relT = setTimeout(function () {
      post('sb_search_content', { q: q, exclude: postId }).then(function (d) {
        ul.innerHTML = '';
        d.items.forEach(function (it) {
          if (rel.some(function (r) { return r.id === it.id; })) return;
          var li = document.createElement('li'), b = document.createElement('button');
          b.type = 'button'; b.textContent = '+ ' + it.title + ' (' + it.type + ')';
          b.addEventListener('click', function () { rel.push({ id: it.id, title: it.title }); drawRel(); li.remove(); });
          li.appendChild(b); ul.appendChild(li);
        });
      }).catch(function () {});
    }, 300);
  });

  /* meta counter */
  function metaCount() { var n = val('#f-meta').length; $('#meta-count').textContent = n ? bn(n) + ' অক্ষর' : ''; }
  $('#f-meta').addEventListener('input', metaCount); metaCount();

  /* AI */
  function aiPayload(feature) {
    return {
      feature: feature, post_id: postId, title: val('#f-title'), topic: val('#f-topic'), excerpt: val('#f-excerpt'),
      content: editorContent(), keywords: keywords(), length: val('#f-length'),
      source: val('#f-source'),
      research: ($('#f-research') && $('#f-research').checked) ? '1' : '0'
    };
  }
  $$('[data-ai]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var f = btn.dataset.ai;
      if (f === 'rewrite') {
        if (val('#f-source').trim().length < 200) { toast('আগে কপি করা লেখা (কমপক্ষে ২০০ অক্ষর) পেস্ট করুন।', 'err'); return; }
      } else if ((f === 'headlines' || f === 'subtitle' || f === 'meta' || f === 'article') && keywords().length < 2) {
        toast('আগে ধাপ ২-এ কমা দিয়ে কমপক্ষে ২টি কীওয়ার্ড লিখুন।', 'err'); say(msg, 'কীওয়ার্ড দিন (ধাপ ২)।', 'err'); return;
      }
      if (f !== 'headlines' && f !== 'rewrite' && !val('#f-title').trim()) { toast('আগে ধাপ ১-এ শিরোনাম লিখুন।', 'err'); return; }
      if (f === 'summary' && editorContent().replace(/<[^>]+>/g, '').trim().length < 80) {
        toast('সামারি মূল পোস্ট থেকে তৈরি হয়। আগে আর্টিকেল লিখুন বা তৈরি করুন।', 'err'); return;
      }
      var cost = SB.costs[f] + (f === 'article' && $('#f-research') && $('#f-research').checked ? SB.costs.research : 0);
      if (SB.balance.remaining < cost) { toast('ক্রেডিট যথেষ্ট নয়। প্রয়োজন ' + fmt(cost) + ', আছে ' + fmt(SB.balance.remaining) + '।', 'err'); return; }
      var old = btn.innerHTML; btn.disabled = true; btn.textContent = '⏳ তৈরি হচ্ছে… (আর্টিকেলে ১ মিনিট লাগতে পারে)';
      say(msg, '');
      post('sb_ai', aiPayload(f)).then(function (d) {
        applyAI(f, d.result);
        say(msg, '✓ হয়েছে। ' + fmt(d.cost) + ' ক্রেডিট কেটেছে।', 'ok'); toast('✓ হয়েছে। ' + fmt(d.cost) + ' ক্রেডিট কেটেছে।', 'ok');
      }).catch(function (e) { say(msg, e.message, 'err'); toast(e.message, 'err'); })
        .then(function () { btn.disabled = false; btn.innerHTML = old; });
    });
  });
  function applyAI(f, r) {
    if (f === 'headlines') {
      var ul = $('#headline-results'); ul.innerHTML = '';
      r.headlines.forEach(function (h) {
        var li = document.createElement('li'), b = document.createElement('button');
        b.type = 'button'; b.textContent = h.text;
        var tag = document.createElement('small'); tag.textContent = ' ' + bn(h.keywords_used) + 'টি কীওয়ার্ড';
        b.appendChild(tag);
        b.addEventListener('click', function () {
          $('#f-title').value = h.text;
          $$('button', ul).forEach(function (x) { x.classList.remove('is-picked'); }); b.classList.add('is-picked');
        });
        li.appendChild(b); ul.appendChild(li);
      });
    } else if (f === 'subtitle') { $('#f-subtitle').value = r.text; }
    else if (f === 'meta') { $('#f-meta').value = r.text; metaCount(); }
    else if (f === 'article' || f === 'rewrite') {
      setEditorContent(r.text);
      var sb = $('#article-sources'), mb = $('#article-missing');
      sb.innerHTML = ''; mb.innerHTML = '';
      if (r.sources && r.sources.length) {
        var h = document.createElement('strong'); h.textContent = '🌐 যেসব সূত্র থেকে তথ্য নেওয়া হয়েছে (প্রকাশের আগে যাচাই করুন):'; sb.appendChild(h);
        var ul = document.createElement('ul');
        r.sources.forEach(function (s) { var li = document.createElement('li'), a = document.createElement('a'); a.href = s.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = s.title || s.url; li.appendChild(a); ul.appendChild(li); });
        sb.appendChild(ul); sb.hidden = false;
      } else { sb.hidden = true; }
      if (r.missing && r.missing.length) {
        var m = document.createElement('strong'); m.textContent = '⚠️ AI-এর এই তথ্যগুলো দরকার, আর্টিকেলে লাল জায়গায় নিজে লিখে দিন:'; mb.appendChild(m);
        var ml = document.createElement('ul');
        r.missing.forEach(function (x) { var li = document.createElement('li'); li.textContent = x; ml.appendChild(li); });
        mb.appendChild(ml); mb.hidden = false;
        toast('আর্টিকেলে ' + bn(r.missing.length) + 'টি জায়গায় আপনার তথ্য দরকার ([তথ্য দরকার: …] খুঁজুন)।', 'err');
      } else { mb.hidden = true; }
    }
    else if (f === 'summary') { $('#f-summary').value = r.text; }
  }

  /* review */
  function buildReview() {
    var dl = $('#review'); dl.innerHTML = '';
    var cat = $('#f-category'), catText = cat.value !== '0' ? cat.options[cat.selectedIndex].text : '—';
    var plain = editorContent().replace(/<[^>]+>/g, ' ').trim();
    [['শিরোনাম', val('#f-title')], ['সাবটাইটেল', val('#f-subtitle') || '—'], ['কীওয়ার্ড', keywords().join(', ') || '—'],
     ['ক্যাটাগরি', catText], ['তারিখ', val('#f-date')], ['আর্টিকেল', plain ? bn(plain.split(/\s+/).length) + ' শব্দ' : 'খালি'],
     ['ছবি', imgId ? 'আছে' : 'নেই']].forEach(function (p) {
      var dt = document.createElement('dt'), dd = document.createElement('dd');
      dt.textContent = p[0]; dd.textContent = p[1]; dl.appendChild(dt); dl.appendChild(dd);
    });
  }

  /* save */
  $('#f-save').addEventListener('click', function () {
    var er = validate(1) || validate(2);
    if (er) { say(msg, er, 'err'); return; }
    var btn = this; btn.disabled = true;
    var st = $('input[name="f-status"]:checked');
    post('sb_save_post', {
      id: postId, title: val('#f-title'), slug: val('#f-slug'), excerpt: val('#f-excerpt'), content: editorContent(),
      subtitle: val('#f-subtitle'), meta_desc: val('#f-meta'), summary: val('#f-summary'), author_name: val('#f-author'),
      date: val('#f-date'), topic: val('#f-topic'), keywords: keywords(), category: val('#f-category'),
      image_id: imgId, faq: faqData(), related: rel.map(function (r) { return r.id; }), blocks: pEd.get(), block_ids: picked, no_global: $('#no-global').checked ? '1' : '0', no_social: $('#no-social').checked ? '1' : '0', status: st ? st.value : 'draft'
    }).then(function (d) {
      postId = d.id;
      var box = $('#save-result');
      box.innerHTML = '';
      var p = document.createElement('div'); p.className = 'notice notice-success inline';
      var t = document.createElement('p'); t.textContent = d.status === 'publish' ? '✓ প্রকাশিত হয়েছে। ' : '✓ খসড়া সেভ হয়েছে। ';
      if (d.status === 'publish') { var a = document.createElement('a'); a.href = d.url; a.target = '_blank'; a.textContent = 'পোস্ট দেখুন ↗'; t.appendChild(a); }
      p.appendChild(t); box.appendChild(p);
      if (history.replaceState) history.replaceState(null, '', d.edit);
    }).catch(function (e) { say(msg, e.message, 'err'); })
      .then(function () { btn.disabled = false; });
  });

  editBox('ed', editorContent, setEditorContent, function () { return { title: val('#f-title'), keywords: keywords() }; });

  show(1);
})();
