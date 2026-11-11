const { contextBridge, ipcRenderer } = require('electron');
contextBridge.exposeInMainWorld('sb', {
  go: v => ipcRenderer.invoke('go', v), back: () => ipcRenderer.invoke('back'), fwd: () => ipcRenderer.invoke('fwd'), reload: () => ipcRenderer.invoke('reload'),
  getCfg: () => ipcRenderer.invoke('cfg:get'), setCfg: c => ipcRenderer.invoke('cfg:set', c), collect: () => ipcRenderer.invoke('collect'),
  tab: t => ipcRenderer.invoke('tab', t),
  barH: h => ipcRenderer.invoke('bar:h', h),
  onProg: fn => ipcRenderer.on('prog', (_e, m) => fn(m)),
  onNav: fn => ipcRenderer.on('nav', (_e, d) => fn(d))
});
