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
        a.closest('tr').remove();
      }).catch(function (er) { a.textContent = er.message; });
    });
  });

  /* ---------------- posts list ---------------- */
  $$('.sba-trash').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      if (!a.dataset.sure) { a.dataset.sure = 1; a.textContent = 'নিশ্চিত? আবার ক্লিক'; return; }
      post('sb_trash_post', { id: a.dataset.id }).then(function () {
        a.closest('tr').remove();
      }).catch(function (er) { a.textContent = er.message; });
    });
  });

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
    return val('#f-kw').split(/[,،，\n]+/).map(function (x) { return x.trim(); })
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
    if (s === 2 && !postId && keywords().length < 2) return 'কমপক্ষে ২টি কীওয়ার্ড দিন।';
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

  /* meta counter */
  function metaCount() { var n = val('#f-meta').length; $('#meta-count').textContent = n ? bn(n) + ' অক্ষর' : ''; }
  $('#f-meta').addEventListener('input', metaCount); metaCount();

  /* AI */
  function aiPayload(feature) {
    return {
      feature: feature, post_id: postId, title: val('#f-title'), topic: val('#f-topic'), excerpt: val('#f-excerpt'),
      content: editorContent(), keywords: keywords(), length: val('#f-length')
    };
  }
  $$('[data-ai]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var f = btn.dataset.ai;
      if ((f === 'headlines' || f === 'subtitle' || f === 'meta' || f === 'article') && keywords().length < 2) {
        toast('আগে ধাপ ২-এ কমা দিয়ে কমপক্ষে ২টি কীওয়ার্ড লিখুন।', 'err'); say(msg, 'কীওয়ার্ড দিন (ধাপ ২)।', 'err'); return;
      }
      if (f !== 'headlines' && !val('#f-title').trim()) { toast('আগে ধাপ ১-এ শিরোনাম লিখুন।', 'err'); return; }
      if (f === 'summary' && editorContent().replace(/<[^>]+>/g, '').trim().length < 80) {
        toast('সামারি মূল পোস্ট থেকে তৈরি হয়। আগে আর্টিকেল লিখুন বা তৈরি করুন।', 'err'); return;
      }
      var cost = SB.costs[f];
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
    else if (f === 'article') { setEditorContent(r.text); }
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
      image_id: imgId, faq: faqData(), status: st ? st.value : 'draft'
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

  show(1);
})();
