const { app, BaseWindow, WebContentsView, ipcMain, net, shell, safeStorage, clipboard } = require('electron');
const path = require('path');
const fs = require('fs');

const TOOLBAR_H = 52;
const LOG = [];
function log(m) { LOG.push(new Date().toLocaleTimeString('en-GB') + ' ' + String(m).replace(/\s+/g, ' ').slice(0, 900)); if (LOG.length > 600) LOG.shift(); }
process.on('uncaughtException', e => log('UNCAUGHT ' + (e && e.message)));
let win, bar, view, webV, wpV, tab = 'web';
let barH = TOOLBAR_H;
const cfgFile = () => path.join(app.getPath('userData'), 'settings.json');

function loadCfg() {
  try {
    const j = JSON.parse(fs.readFileSync(cfgFile(), 'utf8'));
    let key = '';
    if (j.key) { key = j.enc && safeStorage.isEncryptionAvailable() ? safeStorage.decryptString(Buffer.from(j.key, 'base64')) : j.key; }
    return { site: j.site || '', key };
  } catch (e) { return { site: '', key: '' }; }
}
function saveCfg(site, key) {
  const enc = safeStorage.isEncryptionAvailable();
  fs.writeFileSync(cfgFile(), JSON.stringify({ site, enc, key: enc ? safeStorage.encryptString(key).toString('base64') : key }));
}

function normalize(input) {
  const t = String(input || '').trim();
  if (!t) return 'https://www.google.com/';
  if (/^https?:\/\//i.test(t)) return t;
  if (/^[^\s]+\.[a-z]{2,}([\/?#].*)?$/i.test(t)) return 'https://' + t;
  return 'https://www.google.com/search?q=' + encodeURIComponent(t);
}

function layout() {
  const [w, h] = win.getContentSize();
  bar.setBounds({ x: 0, y: 0, width: w, height: barH });
  const vb = { x: 0, y: TOOLBAR_H, width: w, height: Math.max(100, h - TOOLBAR_H) };
  webV.setBounds(vb); wpV.setBounds(vb);
}
function send(ch, data) { if (bar && !bar.webContents.isDestroyed()) bar.webContents.send(ch, data); }

function showTab(t) {
  tab = t === 'wp' ? 'wp' : 'web';
  view = tab === 'wp' ? wpV : webV;
  webV.setVisible(tab === 'web'); wpV.setVisible(tab === 'wp');
  const wc = view.webContents;
  send('nav', { url: wc.getURL(), title: wc.getTitle(), back: wc.navigationHistory.canGoBack(), fwd: wc.navigationHistory.canGoForward(), tab });
}

function createWindow() {
  win = new BaseWindow({ width: 1280, height: 860, title: 'Shaheen Browser' });
  bar = new WebContentsView({ webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, sandbox: true } });
  bar.setBackgroundColor('#00000000');
  const mk = () => new WebContentsView({ webPreferences: { partition: 'persist:shaheen', contextIsolation: true, sandbox: true } });
  webV = mk(); wpV = mk();
  win.contentView.addChildView(webV);
  win.contentView.addChildView(wpV);
  win.contentView.addChildView(bar);
  bar.webContents.loadFile(path.join(__dirname, 'ui.html'));
  const nav = () => send('nav', { url: view.webContents.getURL(), title: view.webContents.getTitle(), back: view.webContents.navigationHistory.canGoBack(), fwd: view.webContents.navigationHistory.canGoForward(), tab });
  [webV, wpV].forEach(v => {
    const wc = v.webContents;
    wc.setWindowOpenHandler(({ url }) => { wc.loadURL(url); return { action: 'deny' }; });
    ['did-navigate', 'did-navigate-in-page', 'page-title-updated', 'did-finish-load'].forEach(ev => wc.on(ev, () => { if (v === view) nav(); }));
  });
  webV.webContents.loadURL('https://www.google.com/');
  const c0 = loadCfg();
  if (c0.site) { wpV.webContents.loadURL(c0.site + '/wp-admin/'); }
  showTab(c0.site ? 'wp' : 'web');
  win.on('resize', layout); layout();
  win.on('closed', () => app.quit());
}

ipcMain.handle('go', (_e, input) => { view.webContents.loadURL(normalize(input)); });
ipcMain.handle('back', () => { if (view.webContents.navigationHistory.canGoBack()) view.webContents.navigationHistory.goBack(); });
ipcMain.handle('fwd', () => { if (view.webContents.navigationHistory.canGoForward()) view.webContents.navigationHistory.goForward(); });
ipcMain.handle('bar:h', (_e, h) => { barH = Math.max(TOOLBAR_H, Math.min(600, Math.round(Number(h) || TOOLBAR_H))); if (win) layout(); });
ipcMain.handle('tab', (_e, t) => {
  if (t === 'wp') { const c = loadCfg(); if (c.site && !/\/wp-admin|\/wp-login/.test(wpV.webContents.getURL())) { wpV.webContents.loadURL(c.site + '/wp-admin/'); } }
  showTab(t);
});
ipcMain.handle('reload', () => view.webContents.reload());
ipcMain.handle('cfg:get', () => { const c = loadCfg(); return { site: c.site, hasKey: !!c.key }; });
ipcMain.handle('cfg:set', async (_e, { site, key }) => {
  site = String(site || '').trim().replace(/\/+$/, '');
  const old = loadCfg();
  key = String(key || '').trim() || old.key;
  if (!/^https?:\/\//i.test(site) || !key) return { ok: false, msg: 'সাইটের ঠিকানা (https://…) ও চাবি দুটিই লাগবে।' };
  try {
    const r = await net.fetch(site + '/wp-json/sb/v1/ping', { headers: { 'X-SB-Key': key } });
    if (!r.ok) return { ok: false, msg: r.status === 401 ? 'চাবি ভুল। প্লাগইন থেকে নতুন চাবি নিন।' : 'সাইট সাড়া দেয়নি (' + r.status + ')।' };
    const j = await r.json();
    saveCfg(site, key);
    if (!/^https?:/.test(wpV.webContents.getURL())) { wpV.webContents.loadURL(site + '/wp-admin/'); }
    return { ok: true, msg: '✅ সংযুক্ত: ' + (j.site || site) };
  } catch (e) { return { ok: false, msg: 'সংযোগ হয়নি: ' + e.message }; }
});

const EXTRACT = `(() => {
  const abs = u => { try { return new URL(u, location.href).href; } catch (e) { return ''; } };
  const meta = n => { const m = document.querySelector('meta[property="' + n + '"],meta[name="' + n + '"]'); return m ? m.content : ''; };
  const sel = String(window.getSelection() || '').trim();
  let root = document.querySelector('article') || document.querySelector('[role="main"]') || document.querySelector('main') || document.body;
  let text = sel || (root.innerText || '');
  text = text.replace(/\\n{3,}/g, '\\n\\n').trim();
  const imgs = new Set(); const og = meta('og:image'); if (og) imgs.add(abs(og));
  [...root.querySelectorAll('img')].filter(i => i.naturalWidth >= 300 && i.naturalHeight >= 200).slice(0, 12).forEach(i => imgs.add(abs(i.currentSrc || i.src)));
  const vids = new Set(); const ov = meta('og:video:url') || meta('og:video'); if (ov) vids.add(abs(ov));
  if (/(youtube\\.com\\/watch|youtu\\.be\\/|facebook\\.com\\/.*(\\/videos\\/|\\/reel\\/|watch))/i.test(location.href)) vids.add(location.href);
  document.querySelectorAll('a[href]').forEach(a => { const h = abs(a.href); if (/(youtube\\.com\\/watch|youtu\\.be\\/|facebook\\.com\\/(reel|watch)|facebook\\.com\\/[^/]+\\/videos\\/)/i.test(h)) vids.add(h.split('&')[0]); });
  document.querySelectorAll('iframe[src*="youtube"],iframe[src*="facebook.com/plugins/video"]').forEach(f => vids.add(abs(f.src)));
  document.querySelectorAll('video[src]').forEach(v => { if (!/^blob:/.test(v.src)) vids.add(abs(v.src)); });
  return { url: location.href, title: meta('og:title') || document.title, text: text.slice(0, 8000), images: [...imgs].slice(0, 8), videos: [...vids].slice(0, 8), selected: !!sel };
})()`;

const RESULTS = `(() => {
  const bad = /(^|\\.)(google|gstatic|googleusercontent|googleapis|gvt1|youtube-nocookie)\\./;
  const norm = a => { let u = a.href; try { const x = new URL(u); if (/(^|\\.)google\\./.test(x.hostname) && x.pathname === '/url') { u = x.searchParams.get('q') || x.searchParams.get('url') || u; } } catch (e) {} return u; };
  const ok = u => { try { const x = new URL(u); return /^https?:$/.test(x.protocol) && !bad.test(x.hostname + '.'); } catch (e) { return false; } };
  const run = (anchors, strict) => {
    const out = [], seen = new Set();
    anchors.forEach(a => {
      const u = norm(a); if (!ok(u) || seen.has(u)) return;
      const h = a.querySelector('h3,[role="heading"]');
      let t = h ? (h.innerText || '').trim() : '';
      if (!t && !strict) { t = (a.getAttribute('aria-label') || a.innerText || '').trim(); if (t.length < 20) return; }
      if (!t) return;
      seen.add(u); out.push({ url: u, title: t.slice(0, 160) });
    });
    return out;
  };
  const roots = [...document.querySelectorAll('#rso,#search,#center_col,[role="main"],main')];
  const scope = roots.length ? roots : [document.body];
  const anchors = []; scope.forEach(r => r.querySelectorAll('a[href]').forEach(a => { if (!anchors.includes(a)) anchors.push(a); }));
  let out = run(anchors, true);
  const have = new Set(out.map(x => x.url));
  run(anchors, false).forEach(x => { if (!have.has(x.url)) { have.add(x.url); out.push(x); } });
  if (!out.length) run([...document.querySelectorAll('a[href]')], false).forEach(x => out.push(x));
  return out.slice(0, 30);
})()`;

const DIAG = `(() => {
  const as = [...document.querySelectorAll('a[href]')];
  const ext = as.filter(a => { try { return !/(^|\\.)google\\./.test(new URL(a.href).hostname); } catch (e) { return false; } });
  return JSON.stringify({ title: document.title.slice(0, 60), ready: document.readyState, a: as.length, ext: ext.length, rso: !!document.querySelector('#rso'), search: !!document.querySelector('#search'), main: !!document.querySelector('main,[role=main]'), h3: document.querySelectorAll('h3').length, text: (document.body.innerText || '').length,
    sample: ext.slice(0, 8).map(a => a.href.slice(0, 70) + ' [' + (a.innerText || '').trim().slice(0, 25).replace(/\\s+/g, ' ') + ']'), head: (document.body.innerText || '').slice(0, 150).replace(/\\s+/g, ' ') });
})()`;
async function getResults(wc) {
  let list = [];
  for (let i = 0; i < 6; i++) {   // Google fills the page after load: poll up to ~9s
    try { list = await wc.executeJavaScript(RESULTS); } catch (e) { list = []; }
    if (list.length) break;
    await new Promise(r => setTimeout(r, 1500));
  }
  if (!list.length) { try { log('NO RESULTS DOM ' + await wc.executeJavaScript(DIAG)); } catch (e) { log('NO RESULTS, diag failed ' + e.message); } }
  return list;
}
const isResults = u => /^https?:\/\/(www\.)?google\.[a-z.]+\/search/i.test(u || '');

async function postCollect(c, data) {
  const r = await net.fetch(c.site + '/wp-json/sb/v1/collect', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-SB-Key': c.key }, body: JSON.stringify(data) });
  let snip = ''; try { snip = (await r.clone().text()).slice(0, 160); } catch (e) {}
  log('SEND ' + (data && data.url ? data.url.slice(0, 80) : '?') + ' -> HTTP ' + r.status + ' ' + snip);
  if (!r.ok) { const e = new Error(r.status === 401 ? 'চাবি ভুল বা বাতিল হয়েছে।' : 'পাঠানো যায়নি (' + r.status + ')।'); e.fatal = true; throw e; }
  return r.json();
}

/* open a link in a hidden tab of the same profile (keeps Facebook/Google logins), read it, close it */
function readHidden(url) {
  return new Promise(resolve => {
    const v = new WebContentsView({ webPreferences: { partition: 'persist:shaheen', contextIsolation: true, sandbox: true } });
    const wc = v.webContents;
    let done = false, status = 200;
    wc.on('did-navigate', (_e, _u, code) => { if (code) status = code; });
    const fin = async ok => {
      if (done) return; done = true;
      let d = null;
      if (ok && status < 400) { try { await new Promise(r => setTimeout(r, 1500)); d = await wc.executeJavaScript(EXTRACT); } catch (e) { d = null; } }
      log('READ ' + url.slice(0, 80) + ' -> status ' + status + (ok ? '' : ' (load failed)') + (d ? ', text ' + (d.text || '').length + ' chars, title: ' + (d.title || '').slice(0, 40) : ', nothing read'));
      try { wc.close(); } catch (e) {}
      resolve(d);
    };
    wc.once('did-finish-load', () => fin(true));
    wc.on('did-fail-load', (_e, code, _d, _u, isMain) => { if (isMain !== false && code !== -3) fin(false); });
    setTimeout(() => fin(true), 20000);
    wc.loadURL(url).catch(() => {});
  });
}

async function collectList(c, list, label, T0) {
  let n = 0, bad = 0; const T = T0 || { filed: 0, blocked: 0, skipped: 0, error: 0, dup: 0 };
  for (let i = 0; i < list.length; i++) {
    if (stopHist) break;
    send('prog', label + 'সংগ্রহ চলছে… ' + (i + 1) + '/' + list.length + ' · ' + list[i].title.slice(0, 40));
    const d = await readHidden(list[i].url);
    if (!d || !(d.text || d.title)) { bad++; continue; }
    try { const j = await postCollect(c, d); n++; if (j.st && T[j.st] !== undefined) T[j.st]++; } catch (e) { if (e.fatal) { return { ok: false, msg: e.message }; } bad++; }
  }
  return { ok: n > 0, n, bad, T, msg: '✅ ' + n + 'টি পেজ সংগ্রহ' + (bad ? ' (' + bad + 'টি পড়া যায়নি)' : '') + ' · বসানো ' + T.filed + ', ⛔ আটকানো ' + T.blocked + ', বাদ ' + (T.skipped + T.dup) + (T.error ? ', ⚠ যাচাই হয়নি ' + T.error : '') };
}

/* history: one Google search per query x year (date range), several pages each, every result collected */
let stopHist = false, histRun = false;
function waitLoad(wc, ms) { return new Promise(r => { let d = false; const f = () => { if (!d) { d = true; r(); } }; wc.once('did-finish-load', () => setTimeout(f, 1500)); wc.once('did-fail-load', f); setTimeout(f, ms); }); }
ipcMain.handle('hist:stop', () => { stopHist = true; });
ipcMain.handle('hist', async (_e, { q, from, to, pages }) => {
  const c = loadCfg();
  if (!c.site || !c.key) return { ok: false, msg: 'আগে ⚙ সেটিং-এ সাইট ও চাবি দিন।' };
  if (histRun) return { ok: false, msg: 'ইতিহাস খোঁজা আগে থেকেই চলছে।' };
  const qs = String(q || '').split('\n').map(x => x.trim()).filter(Boolean);
  from = Math.max(2000, Math.min(2026, +from || 2010)); to = Math.max(from, Math.min(2026, +to || 2026)); pages = Math.max(1, Math.min(5, +pages || 2));
  if (!qs.length) return { ok: false, msg: 'কী খুঁজব লিখুন।' };
  histRun = true; stopHist = false;
  log('HIST start q=' + JSON.stringify(qs) + ' ' + from + '-' + to + ' pages ' + pages);
  showTab('web');
  const T = { filed: 0, blocked: 0, skipped: 0, error: 0, dup: 0 }; let got = 0, years = 0, searched = 0, empty = 0, lastT = '';
  try {
    outer: for (let y = from; y <= to; y++) {
      for (const qq of qs) {
        for (let p = 0; p < pages; p++) {
          if (stopHist) break outer;
          const u = 'https://www.google.com/search?hl=bn&num=10&start=' + (p * 10) + '&q=' + encodeURIComponent(qq) + '&tbs=' + encodeURIComponent('cdr:1,cd_min:1/1/' + y + ',cd_max:12/31/' + y);
          send('prog', '📚 ' + y + ' সাল · "' + qq.slice(0, 25) + '" · পেজ ' + (p + 1) + ' খোলা হচ্ছে…');
          webV.webContents.loadURL(u).catch(() => {});
          await waitLoad(webV.webContents, 25000);
          const cur = webV.webContents.getURL();
          if (/google\.[a-z.]+\/sorry|consent\./i.test(cur)) { histRun = false; return { ok: false, msg: '⚠ Google যাচাই (captcha) চাইছে। ওয়েব ট্যাবে সেটা মিটিয়ে আবার শুরু করুন। এ পর্যন্ত ' + got + 'টি সংগ্রহ।' }; }
          const list = await getResults(webV.webContents);
          searched++;
          log('HIST ' + y + ' q="' + qq.slice(0, 30) + '" page ' + (p + 1) + ' url=' + cur.slice(0, 100) + ' links=' + list.length);
          if (!list.length) { empty++; try { lastT = await webV.webContents.executeJavaScript('document.title+" | "+location.href.slice(0,90)'); } catch (e) {} break; }
          const r = await collectList(c, list, '📚 ' + y + ' · ', T);
          if (r.msg && r.ok === false && !r.n && /চাবি|পাঠানো/.test(r.msg)) { histRun = false; return r; }
          got += r.n || 0;
        }
      }
      years++;
    }
  } finally { histRun = false; }
  return { ok: true, msg: (stopHist ? '⏸ থামানো হয়েছে' : '✅ শেষ') + ' · ' + years + ' বছর · ' + got + 'টি পেজ সংগ্রহ · বসানো ' + T.filed + ', ⛔ আটকানো ' + T.blocked + ', বাদ ' + (T.skipped + T.dup) + (T.error ? ', ⚠ যাচাই হয়নি ' + T.error : '') + (empty ? ' · ' + searched + 'টি সার্চের ' + empty + 'টিতে রেজাল্ট পাওয়া যায়নি' + (lastT ? ' [' + lastT + ']' : '') : '') };
});

ipcMain.handle('report', async () => {
  const c = loadCfg(); let ping = '';
  if (c.site && c.key) {
    try { const r = await net.fetch(c.site + '/wp-json/sb/v1/ping', { headers: { 'X-SB-Key': c.key } }); ping = 'HTTP ' + r.status + ' ' + (await r.text()).slice(0, 200); } catch (e) { ping = 'FAILED ' + e.message; }
  } else { ping = 'not tested (no site/key)'; }
  const tabs = []; try { tabs.push('web: ' + webV.webContents.getURL().slice(0, 110)); tabs.push('wp: ' + wpV.webContents.getURL().slice(0, 110)); } catch (e) {}
  const txt = ['=== Shaheen Browser report ===', 'time: ' + new Date().toISOString(), 'app: ' + app.getVersion() + ' electron ' + process.versions.electron + ' ' + process.platform,
    'site: ' + (c.site || '(none)') + ' | key set: ' + !!c.key, 'ping: ' + ping, 'active tab: ' + tab, ...tabs, 'history running: ' + histRun, '--- log (' + LOG.length + ') ---', ...LOG.slice(-200)].join('\n');
  clipboard.writeText(txt);
  return { ok: true, n: LOG.length };
});

ipcMain.handle('collect', async () => {
  stopHist = false;
  const c = loadCfg();
  if (!c.site || !c.key) return { ok: false, msg: 'আগে ⚙ সেটিং-এ সাইট ও চাবি দিন।' };
  /* Google results page: collect every result automatically */
  if (isResults(view.webContents.getURL())) {
    let list = [];
    list = await getResults(view.webContents);
    log('COLLECT google page ' + view.webContents.getURL().slice(0, 100) + ' links found ' + list.length);
    list = list.slice(0, 10);
    if (!list.length) { let st = ''; try { st = await view.webContents.executeJavaScript("document.querySelectorAll('a[href]').length + ' লিংক, #rso=' + !!document.querySelector('#rso') + ', #search=' + !!document.querySelector('#search')"); } catch (e) {} return { ok: false, msg: 'এই পেজে কোনো রেজাল্ট লিংক পাওয়া যায়নি। (' + st + ')' }; }
    return await collectList(c, list, '');
  }
  let data;
  try { data = await view.webContents.executeJavaScript(EXTRACT); } catch (e) { return { ok: false, msg: 'পেজ পড়া যায়নি: ' + e.message }; }
  try {
    const j = await postCollect(c, data);
    const R = { filed: ' · ✅ বসানো হয়েছে (' + j.note + ')', blocked: ' · ⛔ আটকানো হয়েছে: ' + j.note, skipped: ' · বাদ: ' + j.note, dup: ' · আগেই ছিল', error: ' · ⚠ ' + j.note };
    return { ok: true, msg: '✅ সংগ্রহ হয়েছে' + (data.selected ? ' (শুধু নির্বাচিত অংশ)' : '') + (R[j.st] || ' · ছবি ' + data.images.length + ', ভিডিও ' + data.videos.length + ' · মোট ' + j.count) };
  } catch (e) { return { ok: false, msg: e.fatal ? e.message : 'সংযোগ হয়নি: ' + e.message }; }
});

app.whenReady().then(createWindow);
app.on('window-all-closed', () => app.quit());
