# AntonX Desktop App

Electron wrapper for the AntonX BMS web app. Produces a native installer (.exe / .dmg / .AppImage) that opens the hosted app in a dedicated desktop window.

## Setup

1. **Set your domain** — open `main.js` and replace `https://your-domain.com` with the actual URL where the PHP app is hosted (e.g. `https://system.speedxmarketing.com`).

2. **Install dependencies**
   ```bash
   npm install
   ```

## Run locally (for testing)

```bash
npm start
```

Opens a window loading the live app. No build step needed.

## Build installers

| Command | Output | Platform to build on |
|---|---|---|
| `npm run build:win` | `dist/AntonX Setup 1.0.0.exe` | Windows |
| `npm run build:mac` | `dist/AntonX-1.0.0.dmg` | macOS |
| `npm run build:linux` | `dist/AntonX-1.0.0.AppImage` | Linux |

> Building a macOS `.dmg` must be done on macOS. Building `.exe` can be done on Windows or Linux (via Wine). Use GitHub Actions for cross-platform CI builds.

## Updating the icon

Replace `assets/icon.png` with a new 512×512 PNG, then regenerate the platform-specific formats:

```bash
npx electron-icon-builder --input=../antonx-logo.png --output=assets/ && \
  cp assets/icons/png/512x512.png assets/icon.png && \
  cp assets/icons/mac/icon.icns assets/icon.icns && \
  cp assets/icons/win/icon.ico assets/icon.ico
```
