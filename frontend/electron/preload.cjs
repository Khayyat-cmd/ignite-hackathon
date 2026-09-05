const { contextBridge } = require('electron');

contextBridge.exposeInMainWorld('amanDesktop', Object.freeze({
  platform: process.platform,
  versions: Object.freeze({ electron: process.versions.electron }),
}));
