const { app, BaseWindow, WebContentsView, ipcMain, net, shell, safeStorage } = require('electron');
const path = require('path');
const fs = require('fs');

const TOOLBAR_H = 52;
let win, bar, view;
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
  view.setBounds({ x: 0, y: TOOLBAR_H, width: w, height: Math.max(100, h - TOOLBAR_H) });
}
function send(ch, data) { if (bar && !bar.webContents.isDestroyed()) bar.webContents.send(ch, data); }

function createWindow() {
  win = new BaseWindow({ width: 1280, height: 860, title: 'Shaheen Browser' });
  bar = new WebContentsView({ webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, sandbox: true } });
  bar.setBackgroundColor('#00000000');
  view = new WebContentsView({ webPreferences: { partition: 'persist:shaheen', contextIsolation: true, sandbox: true } });
  win.contentView.addChildView(view);
  win.contentView.addChildView(bar);
  bar.webContents.loadFile(path.join(__dirname, 'ui.html'));
  const wc = view.webContents;
  wc.setWindowOpenHandler(({ url }) => { wc.loadURL(url); return { action: 'deny' }; });
  const nav = () => send('nav', { url: wc.getURL(), title: wc.getTitle(), back: wc.navigationHistory.canGoBack(), fwd: wc.navigationHistory.canGoForward() });
  wc.on('did-navigate', nav); wc.on('did-navigate-in-page', nav); wc.on('page-title-updated', nav); wc.on('did-finish-load', nav);
  wc.loadURL('https://www.google.com/');
  win.on('resize', layout); layout();
  win.on('closed', () => app.quit());
}

ipcMain.handle('go', (_e, input) => { view.webContents.loadURL(normalize(input)); });
ipcMain.handle('back', () => { if (view.webContents.navigationHistory.canGoBack()) view.webContents.navigationHistory.goBack(); });
ipcMain.handle('fwd', () => { if (view.webContents.navigationHistory.canGoForward()) view.webContents.navigationHistory.goForward(); });
ipcMain.handle('bar:h', (_e, h) => { barH = Math.max(TOOLBAR_H, Math.min(600, Math.round(Number(h) || TOOLBAR_H))); if (win) layout(); });
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

ipcMain.handle('collect', async () => {
  const c = loadCfg();
  if (!c.site || !c.key) return { ok: false, msg: 'আগে ⚙ সেটিং-এ সাইট ও চাবি দিন।' };
  let data;
  try { data = await view.webContents.executeJavaScript(EXTRACT); } catch (e) { return { ok: false, msg: 'পেজ পড়া যায়নি: ' + e.message }; }
  try {
    const r = await net.fetch(c.site + '/wp-json/sb/v1/collect', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-SB-Key': c.key }, body: JSON.stringify(data) });
    if (!r.ok) return { ok: false, msg: r.status === 401 ? 'চাবি ভুল বা বাতিল হয়েছে।' : 'পাঠানো যায়নি (' + r.status + ')।' };
    const j = await r.json();
    return { ok: true, msg: '✅ সংগ্রহ হয়েছে' + (data.selected ? ' (শুধু নির্বাচিত অংশ)' : '') + ' · ছবি ' + data.images.length + ', ভিডিও ' + data.videos.length + ' · মোট ' + j.count };
  } catch (e) { return { ok: false, msg: 'সংযোগ হয়নি: ' + e.message }; }
});

app.whenReady().then(createWindow);
app.on('window-all-closed', () => app.quit());
