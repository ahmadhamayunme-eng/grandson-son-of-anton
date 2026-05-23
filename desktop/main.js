const { app, BrowserWindow, shell } = require('electron');
const path = require('path');

// The URL of the hosted PHP app. Change this to your actual domain.
const APP_URL = 'https://anton.speedxmarketing.com';

function createWindow() {
  const win = new BrowserWindow({
    width: 1440,
    height: 900,
    minWidth: 960,
    minHeight: 600,
    icon: path.join(__dirname, 'assets', 'icon.png'),
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      nodeIntegration: false,
      contextIsolation: true,
    },
    titleBarStyle: process.platform === 'darwin' ? 'hiddenInset' : 'default',
    backgroundColor: '#0a0a0a',
    show: false,
  });

  win.loadURL(APP_URL);

  // Show window only after the page has painted — eliminates white flash.
  win.once('ready-to-show', () => win.show());

  // Open external URLs (anything not on APP_URL) in the real browser.
  win.webContents.setWindowOpenHandler(({ url }) => {
    if (!url.startsWith(APP_URL)) {
      shell.openExternal(url);
      return { action: 'deny' };
    }
    return { action: 'allow' };
  });

  // Catch link clicks that navigate away from APP_URL.
  win.webContents.on('will-navigate', (event, url) => {
    if (!url.startsWith(APP_URL)) {
      event.preventDefault();
      shell.openExternal(url);
    }
  });
}

app.whenReady().then(() => {
  createWindow();
  // macOS: re-create window when clicking dock icon with no windows open.
  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

// Quit when all windows are closed (except on macOS).
app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
