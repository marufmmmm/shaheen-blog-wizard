/* AI Blog Image Studio (phase 1): featured image metadata + one AI image per click. */
(function () {
  'use strict';
  var $ = function (s) { return document.querySelector(s); };
  if (!$('#img-studio') || !window.SB) { return; }
  var busy = false;

  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action); fd.append('nonce', SB.nonce);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(SB.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.success) { throw new Error((j && j.data && j.data.message) || 'কিছু ভুল হয়েছে, আবার চেষ্টা করুন।'); }
      return j.data;
    });
  }
  function say(t, bad) { var m = $('#img-msg'); m.textContent = t || ''; m.style.color = bad ? '#b32d2e' : ''; }
  function ctx() {
    var cat = $('#f-category'); var catName = cat && cat.options && cat.selectedIndex >= 0 ? cat.options[cat.selectedIndex].text : '';
    return { title: ($('#f-title') || {}).value || '', content: window.sbEditorContent ? window.sbEditorContent() : '', category: catName };
  }
  function fields() { return { alt: $('#img-alt').value, imgtitle: $('#img-title').value, caption: $('#img-caption').value, details: $('#img-details').value }; }
  function setFields(o) { $('#img-alt').value = o.alt || ''; $('#img-title').value = o.title || ''; $('#img-caption').value = o.caption || ''; $('#img-details').value = o.details || ''; }

  // SEO fields follow the chosen image (also for images picked by hand)
  window.sbImgOnPick = function (a) {
    if (!a) { setFields({}); return; }
    setFields({ alt: a.alt, title: a.title, caption: a.caption, details: a.description });
  };
  var saveT;
  ['#img-alt', '#img-title', '#img-caption', '#img-details'].forEach(function (s) {
    $(s).addEventListener('change', function () {
      var id = window.sbFeat ? sbFeat.get() : 0; if (!id) { return; }
      clearTimeout(saveT);
      saveT = setTimeout(function () { post('sb_img_meta', Object.assign({ id: id }, fields())).catch(function () {}); }, 200);
    });
  });

  function mkPrompt() {
    var c = ctx(), p = $('#img-prompt');
    return post('sb_img_prompt', { title: c.title, content: c.content, category: c.category, uniform: $('#img-uniform').value, subject: $('#img-subject').value, logo: $('#img-logo').value, logo_at: $('#img-logoat').value, size: $('#img-size').value, post_id: (window.SB_POST && SB_POST.id) || 0 }).then(function (d) {
      p.value = d.prompt; setFields({ alt: d.alt, title: d.title, caption: d.caption, details: d.details }); return d;
    });
  }
  function make() {
    var c = ctx(), f = fields(), job = 'j' + Date.now() + Math.random().toString(36).slice(2, 8);
    return post('sb_img_make', Object.assign({ job: job, prompt: $('#img-prompt').value, model: $('#img-model').value, uniform: $('#img-uniform').value, subject: $('#img-subject').value, logo: $('#img-logo').value, logo_at: $('#img-logoat').value, size: $('#img-size').value,
      quality: $('#img-quality').value, title: c.title, content: c.content, category: c.category, post_id: (window.SB_POST && SB_POST.id) || 0 }, f)).then(function (d) {
      if (window.sbFeat) { sbFeat.set(d.id, d.url); }
      return d;
    });
  }

  $('#img-describe').addEventListener('click', function () {
    var id = window.sbFeat ? sbFeat.get() : 0;
    if (!id) { say('আগে ফিচার্ড ছবি বেছে নিন বা AI দিয়ে বানান।', true); return; }
    var f = fields();
    if ((f.alt || f.imgtitle || f.caption || f.details) && !window.confirm('আগের লেখা বদলে যাবে। ঠিক আছে?')) { return; }
    if (busy) { return; } busy = true; var b = this; b.disabled = true; say('⏳ AI ছবিটা দেখে লিখছে…');
    var c = ctx();
    post('sb_img_describe', { id: id, prompt: $('#img-prompt').value, title: c.title, content: c.content, category: c.category }).then(function (d) {
      setFields(d); return post('sb_img_meta', Object.assign({ id: id }, { alt: d.alt, imgtitle: d.title, caption: d.caption, details: d.details }));
    }).then(function () { say('✓ লেখা হয়েছে এবং ছবিতে সেভ হয়েছে। দরকার হলে বদলে নিন।'); })
      .catch(function (e) { say(e.message, true); }).then(function () { busy = false; b.disabled = false; });
  });
  $('#img-mkprompt').addEventListener('click', function () {
    if (busy) { return; } busy = true; var b = this; b.disabled = true; say('⏳ প্রম্পট তৈরি হচ্ছে…');
    mkPrompt().then(function (d) { say('✓ প্রম্পট তৈরি হয়েছে' + (d.uniforms ? ' (ইউনিফর্ম রেফারেন্স সহ)' : '') + '। ঠিক থাকলে "ছবি বানান" চাপুন।' + (d.note ? ' ' + d.note : '')); })
      .catch(function (e) { say(e.message, true); }).then(function () { busy = false; b.disabled = false; });
  });
  $('#img-make').addEventListener('click', function () {
    if (busy) { return; } busy = true; var b = this; b.disabled = true; say('⏳ ছবি তৈরি হচ্ছে… (১ মিনিট পর্যন্ত লাগতে পারে, পেজ বন্ধ করবেন না)');
    make().then(function (d) { say('✓ ' + d.model + ' দিয়ে ছবি তৈরি হয়েছে এবং ফিচার্ড ছবিতে বসেছে। এই ছবির বিল ' + d.bill + (d.estimated ? ' (আনুমানিক)' : '') + '।' + (d.note ? ' ' + d.note : '')); })
      .catch(function (e) { say(e.message, true); }).then(function () { busy = false; b.disabled = false; });
  });

  // "AI দিয়ে ফিচার্ড ছবিও বানান" tick: after the article, only if no image is chosen yet
  window.sbImgAuto = function () {
    if (window.sbFeat && sbFeat.get()) { return Promise.resolve(0); }
    $('#img-studio').open = true;
    return mkPrompt().then(function () { return make(); }).then(function (d) { return parseFloat(String(d.bill).replace(/[^0-9.]/g, '')) || 0; })
      .catch(function (e) { say('ছবি বানানো যায়নি: ' + e.message, true); return 0; });
  };

  /* ================= Extra images inside the article ================= */
  var box = $('#img-extra'), slotsEl = $('#img-slots'), OPT = {}, slots = [];
  try { OPT = JSON.parse(box.getAttribute('data-opts') || '{}'); } catch (e) { OPT = {}; }
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function sel(cls, list, v) { return '<select class="' + cls + '">' + (list || []).map(function (o) { return '<option value="' + esc(o[0]) + '"' + (o[0] === v ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>'; }

  // The article as blocks: where an image can go
  function blocks() {
    var d = document.createElement('div'); d.innerHTML = window.sbEditorContent ? window.sbEditorContent() : '';
    return { root: d, kids: [].slice.call(d.children) };
  }
  function txt(n) { return (n.textContent || '').replace(/\s+/g, ' ').trim(); }
  function positions() {
    var b = blocks(), out = [['top|', 'লেখার একদম শুরুতে']];
    b.kids.forEach(function (n) {
      var t = txt(n), tag = n.tagName;
      if ((tag === 'H2' || tag === 'H3') && t) { out.push(['h|' + t.slice(0, 60), '▸ ' + tag + ': ' + t.slice(0, 50) + ' — এর নিচে (প্রথম অনুচ্ছেদের পর)']); }
      else if (tag === 'P' && t.length > 40) { out.push(['p|' + t.slice(0, 60), '¶ ' + t.slice(0, 40) + '… — এই অনুচ্ছেদের পর']); }
    });
    out.push(['end|', 'লেখার একদম শেষে']);
    return out;
  }
  function findAnchor(b, val) {
    var i = val.indexOf('|'), type = val.slice(0, i), key = val.slice(i + 1), k = b.kids, j;
    if (type === 'top') { return { before: k[0] || null }; }
    if (type === 'end') { return { after: k[k.length - 1] || null }; }
    for (j = 0; j < k.length; j++) {
      var ok = (type === 'h' ? /^H[23]$/.test(k[j].tagName) : k[j].tagName === 'P') && txt(k[j]).slice(0, 60) === key;
      if (ok) {
        if (type === 'h' && k[j + 1] && k[j + 1].tagName === 'P') { return { after: k[j + 1] }; }
        return { after: k[j] };
      }
    }
    return null;
  }
  function sectionText(val) {
    var b = blocks(), i = val.indexOf('|'), type = val.slice(0, i), key = val.slice(i + 1), k = b.kids, j, s = '';
    if (type === 'top') { return k.slice(0, 3).map(txt).join(' ').slice(0, 700); }
    if (type === 'end') { return k.slice(-3).map(txt).join(' ').slice(0, 700); }
    for (j = 0; j < k.length; j++) {
      if (txt(k[j]).slice(0, 60) === key) { s = txt(k[j]) + (type === 'h' && k[j + 1] ? ' ' + txt(k[j + 1]) : ''); break; }
    }
    return s.slice(0, 800);
  }
  function figure(sl) {
    var f = document.createElement('figure'); f.className = 'wp-block-image size-large sb-fig'; f.setAttribute('data-sbimg', sl.id);
    var im = document.createElement('img'); im.src = sl.src; im.alt = sl.q('.s-alt').value; im.className = 'wp-image-' + sl.id; im.loading = 'lazy';
    if (sl.w && sl.h) { im.width = sl.w; im.height = sl.h; }
    if (sl.q('.s-title').value) { im.title = sl.q('.s-title').value; }
    f.appendChild(im);
    if (sl.q('.s-showcap').checked && sl.q('.s-caption').value.trim()) { var c = document.createElement('figcaption'); c.textContent = sl.q('.s-caption').value.trim(); f.appendChild(c); }
    return f;
  }
  // put (or move/refresh) this slot's figure in the editor
  function place(sl) {
    if (!sl.id) { return false; }
    var b = blocks(), old = b.root.querySelector('figure[data-sbimg="' + sl.id + '"]'), fig = figure(sl), where = findAnchor(b, sl.q('.s-pos').value), ok = true;
    if (old) { old.parentNode.removeChild(old); where = findAnchor(blocks2(b), sl.q('.s-pos').value); }
    if (!where) { b.root.appendChild(fig); ok = false; }
    else if (where.before) { b.root.insertBefore(fig, where.before); }
    else if (where.after) { where.after.parentNode.insertBefore(fig, where.after.nextSibling); }
    else { b.root.appendChild(fig); }
    window.sbEditorSet(b.root.innerHTML);
    return ok;
  }
  function blocks2(b) { b.kids = [].slice.call(b.root.children); return b; }
  function unplace(sl) {
    var b = blocks(), old = sl.id ? b.root.querySelector('figure[data-sbimg="' + sl.id + '"]') : null;
    if (old) { old.parentNode.removeChild(old); window.sbEditorSet(b.root.innerHTML); }
  }

  function addSlot(n) {
    var el = document.createElement('div'); el.className = 'sb-slot'; el.style.cssText = 'border:1px solid #c3c4c7;border-radius:4px;padding:10px 12px;margin:10px 0;background:#fcfcfc';
    el.innerHTML = '<strong>ছবি ' + (n + 1) + '</strong> <span class="s-state sba-muted"></span>'
      + '<div class="sba-row" style="margin-top:6px"><label>কোথায় বসবে<select class="s-pos"></select></label>'
      + '<label style="max-width:240px"><input type="checkbox" class="s-showcap" checked> ক্যাপশন ছবির নিচে ছোট করে দেখাবে<br><small class="sba-muted">না চাইলে ক্যাপশন ও বিবরণ শুধু ব্যাকএন্ডে (মিডিয়া লাইব্রেরিতে) থাকবে</small></label></div>'
      + '<div class="sba-row"><label>কার ছবি' + sel('s-subject', OPT.subjects) + '</label><label>ড্রেস' + sel('s-uniform', OPT.uniforms) + '</label>'
      + '<label>লোগো' + sel('s-logo', OPT.logos, 'none') + '</label><label>লোগো কোথায়' + sel('s-logoat', OPT.places) + '</label></div>'
      + '<div class="sba-row"><label>মডেল<select class="s-model"><option value="auto">স্বয়ংক্রিয় (প্রস্তাবিত)</option><option value="gpt-image-2.5-sunburst">Sunburst — সবচেয়ে নির্ভুল</option><option value="gpt-image-2.5-flare">Flare — দ্রুত</option></select></label>'
      + '<label>মাপ<select class="s-size"><option value="1536x1024">আড়াআড়ি</option><option value="1024x1024">চৌকো</option><option value="1024x1536">লম্বা</option></select></label>'
      + '<label>মান<select class="s-quality"><option value="low">কম (সস্তা)</option><option value="medium" selected>মাঝারি</option><option value="high">উচ্চ (দামি)</option></select></label></div>'
      + '<label>প্রম্পট <small>(ইংরেজিতে; AI দিয়ে তৈরি করুন বা নিজে লিখুন)</small><textarea class="s-prompt" rows="3"></textarea></label>'
      + '<p><button type="button" class="button s-mkp">✍️ প্রম্পট তৈরি করুন</button> <button type="button" class="button button-primary s-make">🎨 ছবি বানান ও বসান</button> <span class="s-msg sba-muted"></span></p>'
      + '<div class="s-res" hidden><img class="s-thumb" style="max-width:220px;max-height:140px;border:1px solid #ddd;vertical-align:top;margin-right:10px">'
      + '<div style="display:inline-block;width:calc(100% - 240px);min-width:260px"><div class="sba-row"><label>Alt text<input type="text" class="s-alt"></label><label>শিরোনাম<input type="text" class="s-title"></label><label>ক্যাপশন<input type="text" class="s-caption"></label></div>'
      + '<label>বিবরণ (শুধু ব্যাকএন্ড)<textarea class="s-details" rows="2"></textarea></label>'
      + '<p><button type="button" class="button s-desc">🤖 AI দিয়ে alt, শিরোনাম, ক্যাপশন লিখুন</button> <button type="button" class="button s-upd">🔁 আর্টিকেলে আপডেট / জায়গা বদলান</button> <button type="button" class="button-link-delete s-del">ছবিটি আর্টিকেল থেকে সরান</button></p></div></div>';
    slotsEl.appendChild(el);
    var sl = { el: el, id: 0, src: '', w: 0, h: 0, busy: false, q: function (s) { return el.querySelector(s); } };
    fillPos(sl);
    sl.q('.s-pos').addEventListener('focus', function () { var v = this.value; fillPos(sl, v); });
    function msg(t, bad) { var m = sl.q('.s-msg'); m.textContent = t || ''; m.style.color = bad ? '#b32d2e' : ''; }
    function common() {
      var c = ctx();
      return { title: c.title, content: c.content, category: c.category, post_id: (window.SB_POST && SB_POST.id) || 0, subject: sl.q('.s-subject').value, uniform: sl.q('.s-uniform').value,
        logo: sl.q('.s-logo').value, logo_at: sl.q('.s-logoat').value, size: sl.q('.s-size').value, section: sectionText(sl.q('.s-pos').value) };
    }
    function run(btn, wait, fn) {
      if (busy || sl.busy) { msg('আরেকটি ছবির কাজ চলছে, শেষ হলে চাপুন।', true); return; }
      busy = sl.busy = true; btn.disabled = true; msg(wait);
      fn().catch(function (e) { msg(e.message, true); }).then(function () { busy = sl.busy = false; btn.disabled = false; });
    }
    function setF(o) { sl.q('.s-alt').value = o.alt || ''; sl.q('.s-title').value = o.title || ''; sl.q('.s-caption').value = o.caption || ''; sl.q('.s-details').value = o.details || ''; }
    function saveMeta() { return post('sb_img_meta', { id: sl.id, alt: sl.q('.s-alt').value, imgtitle: sl.q('.s-title').value, caption: sl.q('.s-caption').value, details: sl.q('.s-details').value }); }
    sl.q('.s-mkp').addEventListener('click', function () {
      run(this, '⏳ প্রম্পট তৈরি হচ্ছে…', function () {
        return post('sb_img_prompt', common()).then(function (d) {
          sl.q('.s-prompt').value = d.prompt; setF(d); sl.q('.s-res').hidden = !sl.id;
          sl.draft = { alt: d.alt, title: d.title, caption: d.caption, details: d.details };
          msg('✓ প্রম্পট তৈরি হয়েছে' + (d.uniforms ? ' (ড্রেস রেফারেন্স সহ)' : '') + (d.logo ? ' (লোগো সহ)' : '') + '। ঠিক থাকলে "ছবি বানান ও বসান" চাপুন।' + (d.note ? ' ' + d.note : ''));
        });
      });
    });
    sl.q('.s-make').addEventListener('click', function () {
      if (sl.id && !window.confirm('এই জায়গার ছবি আগেই আছে। নতুন ছবি বানালে পুরনোটির বদলে বসবে এবং নতুন বিল যোগ হবে। এগোবেন?')) { return; }
      run(this, '⏳ ছবি তৈরি হচ্ছে… (১ মিনিট পর্যন্ত লাগতে পারে, পেজ বন্ধ করবেন না)', function () {
        var c = common(), job = 'j' + Date.now() + Math.random().toString(36).slice(2, 8), f = sl.draft || {};
        return post('sb_img_make', Object.assign(c, { job: job, prompt: sl.q('.s-prompt').value, model: sl.q('.s-model').value, quality: sl.q('.s-quality').value,
          alt: sl.q('.s-alt').value || f.alt || '', imgtitle: sl.q('.s-title').value || f.title || '', caption: sl.q('.s-caption').value || f.caption || '', details: sl.q('.s-details').value || f.details || '' })).then(function (d) {
          var oldId = sl.id; if (oldId) { unplace(sl); }
          sl.id = d.id; sl.src = d.src; sl.w = d.w; sl.h = d.h; sl.q('.s-thumb').src = d.url; sl.q('.s-res').hidden = false;
          var okp = place(sl);
          sl.q('.s-state').textContent = '✓ বসানো হয়েছে';
          msg('✓ ' + d.model + ' দিয়ে বানিয়ে আর্টিকেলে বসানো হয়েছে। এই ছবির বিল ' + d.bill + (d.estimated ? ' (আনুমানিক)' : '') + '।' + (okp ? '' : ' (জায়গা খুঁজে পাইনি, তাই শেষে বসেছে; "জায়গা বদলান" দিয়ে ঠিক করুন)') + (d.note ? ' ' + d.note : ''));
        });
      });
    });
    sl.q('.s-desc').addEventListener('click', function () {
      run(this, '⏳ AI ছবিটা দেখে লিখছে…', function () {
        var c = ctx();
        return post('sb_img_describe', { id: sl.id, prompt: sl.q('.s-prompt').value, title: c.title, content: c.content, category: c.category }).then(function (d) {
          setF(d); return saveMeta();
        }).then(function () { place(sl); msg('✓ লেখা হয়েছে, ছবিতে সেভ হয়েছে ও আর্টিকেলে আপডেট হয়েছে।'); });
      });
    });
    sl.q('.s-upd').addEventListener('click', function () {
      run(this, '⏳…', function () { return saveMeta().then(function () { var ok = place(sl); msg(ok ? '✓ আর্টিকেলে আপডেট হয়েছে।' : 'জায়গা খুঁজে পাইনি, শেষে বসেছে।', !ok); }); });
    });
    sl.q('.s-del').addEventListener('click', function () {
      if (!window.confirm('ছবিটি আর্টিকেল থেকে সরবে (মিডিয়া লাইব্রেরিতে থাকবে)। ঠিক আছে?')) { return; }
      unplace(sl); sl.id = 0; sl.q('.s-res').hidden = true; sl.q('.s-state').textContent = ''; msg('সরানো হয়েছে।');
    });
    slots.push(sl);
  }
  function fillPos(sl, keep) {
    var s = sl.q('.s-pos'), v = keep || s.value, list = positions();
    s.innerHTML = list.map(function (o) { return '<option value="' + esc(o[0]) + '">' + esc(o[1]) + '</option>'; }).join('');
    if (v && [].some.call(s.options, function (o) { return o.value === v; })) { s.value = v; }
    else if (!keep) { s.selectedIndex = Math.min(s.options.length - 1, Math.max(1, slots.indexOf(sl) + 1) * 2); }
  }
  $('#img-count').addEventListener('change', function () {
    var n = parseInt(this.value, 10) || 0;
    while (slots.length < n) { addSlot(slots.length); }
    while (slots.length > n) {
      var last = slots[slots.length - 1];
      if (last.id) { this.value = String(slots.length); window.alert('ছবি ' + slots.length + ' এ ছবি আছে। আগে "ছবিটি আর্টিকেল থেকে সরান" চাপুন, তারপর সংখ্যা কমান।'); return; }
      slotsEl.removeChild(last.el); slots.pop();
    }
  });
})();