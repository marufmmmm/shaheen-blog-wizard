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
    return post('sb_img_prompt', { title: c.title, content: c.content, category: c.category, uniform: $('#img-uniform').value, size: $('#img-size').value, post_id: (window.SB_POST && SB_POST.id) || 0 }).then(function (d) {
      p.value = d.prompt; setFields({ alt: d.alt, title: d.title, caption: d.caption, details: d.details }); return d;
    });
  }
  function make() {
    var c = ctx(), f = fields(), job = 'j' + Date.now() + Math.random().toString(36).slice(2, 8);
    return post('sb_img_make', Object.assign({ job: job, prompt: $('#img-prompt').value, model: $('#img-model').value, uniform: $('#img-uniform').value, size: $('#img-size').value,
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
})();
