const { app, BaseWindow, WebContentsView, ipcMain, net, shell, safeStorage } = require('electron');
const path = require('path');
const fs = require('fs');

const TOOLBAR_H = 52;
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
  const out = [], seen = new Set();
  const root = document.querySelector('#rso') || document.querySelector('#search') || document.body;
  root.querySelectorAll('a[href]').forEach(a => {
    const h = a.querySelector('h3,[role="heading"]');
    if (!h) return;
    let u = a.href;
    try { const x = new URL(u); if (/(^|\\.)google\\./.test(x.hostname) && x.pathname === '/url') { u = x.searchParams.get('q') || x.searchParams.get('url') || u; } } catch (e) {}
    try { const x = new URL(u); if (!/^https?:$/.test(x.protocol) || /(^|\\.)google\\./.test(x.hostname) || /(^|\\.)gstatic\\./.test(x.hostname)) return; } catch (e) { return; }
    if (seen.has(u)) return; seen.add(u); out.push({ url: u, title: (h.innerText || '').trim() || u });
  });
  return out;
})()`;
const isResults = u => /^https?:\/\/(www\.)?google\.[a-z.]+\/search/i.test(u || '');

async function postCollect(c, data) {
  const r = await net.fetch(c.site + '/wp-json/sb/v1/collect', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-SB-Key': c.key }, body: JSON.stringify(data) });
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
  const T = { filed: 0, blocked: 0, skipped: 0, error: 0, dup: 0 }; let got = 0, years = 0, searched = 0, empty = 0, lastT = '';
  try {
    outer: for (let y = from; y <= to; y++) {
      for (const qq of qs) {
        for (let p = 0; p < pages; p++) {
          if (stopHist) break outer;
          const u = 'https://www.google.com/search?hl=bn&num=10&start=' + (p * 10) + '&q=' + encodeURIComponent(qq) + '&tbs=' + encodeURIComponent('cdr:1,cd_min:1/1/' + y + ',cd_max:12/31/' + y);
          send('prog', '📚 ' + y + ' সাল · "' + qq.slice(0, 25) + '" · পেজ ' + (p + 1) + ' খোলা হচ্ছে…');
          view.webContents.loadURL(u).catch(() => {});
          await waitLoad(view.webContents, 25000);
          const cur = view.webContents.getURL();
          if (/google\.[a-z.]+\/sorry|consent\./i.test(cur)) { histRun = false; return { ok: false, msg: '⚠ Google যাচাই (captcha) চাইছে। ওয়েব ট্যাবে সেটা মিটিয়ে আবার শুরু করুন। এ পর্যন্ত ' + got + 'টি সংগ্রহ।' }; }
          let list = [];
          try { list = await view.webContents.executeJavaScript(RESULTS); } catch (e) { list = []; }
          searched++;
          if (!list.length) { empty++; try { lastT = await view.webContents.executeJavaScript('document.title+" | "+location.href.slice(0,90)'); } catch (e) {} break; }
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

ipcMain.handle('collect', async () => {
  stopHist = false;
  const c = loadCfg();
  if (!c.site || !c.key) return { ok: false, msg: 'আগে ⚙ সেটিং-এ সাইট ও চাবি দিন।' };
  /* Google results page: collect every result automatically */
  if (isResults(view.webContents.getURL())) {
    let list = [];
    try { list = await view.webContents.executeJavaScript(RESULTS); } catch (e) { return { ok: false, msg: 'রেজাল্ট পড়া যায়নি: ' + e.message }; }
    list = list.slice(0, 10);
    if (!list.length) return { ok: false, msg: 'এই পেজে কোনো রেজাল্ট লিংক পাওয়া যায়নি।' };
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
