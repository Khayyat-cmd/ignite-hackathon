const { app, BrowserWindow, shell } = require('electron');
const path = require('node:path');

// A packaged app loads the deployed console rather than a bundled copy: a file://
// page sends Origin: null, which the API's CORS allowlist rejects, and loading the
// site keeps the desktop app on whatever console was last deployed.
const consoleUrl = 'https://aman.baraaelbaba.com/';
const rendererUrl = app.isPackaged ? consoleUrl : 'http://localhost:3000';

function createWindow() {
  const window = new BrowserWindow({
    title: 'AMAN Command Center',
    width: 1500,
    height: 960,
    minWidth: 1180,
    minHeight: 700,
    backgroundColor: '#0a0c0f',
    autoHideMenuBar: true,
    webPreferences: {
      preload: path.join(__dirname, 'preload.cjs'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
    },
  });

  window.webContents.setWindowOpenHandler(({ url }) => {
    if (url.startsWith('https://')) shell.openExternal(url);
    return { action: 'deny' };
  });
  window.webContents.on('will-navigate', (event, url) => {
    if (!url.startsWith(rendererUrl)) event.preventDefault();
  });

  window.loadURL(rendererUrl);
}

app.whenReady().then(() => {
  createWindow();
  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
