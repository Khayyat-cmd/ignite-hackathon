const { app, BrowserWindow, shell } = require('electron');
const path = require('node:path');

const rendererUrl = 'http://localhost:3000';

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
    const allowed = app.isPackaged ? url.startsWith('file:') : url.startsWith(rendererUrl);
    if (!allowed) event.preventDefault();
  });

  if (app.isPackaged) window.loadFile(path.join(__dirname, '..', 'dist', 'index.html'));
  else window.loadURL(rendererUrl);
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
