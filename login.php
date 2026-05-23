<?php
session_start();
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
if (auth_user()) redirect('dashboard.php');

// Cache-buster for anton.css so the browser is forced to refresh on every
// deploy of the stylesheet, regardless of what's sitting in disk cache.
$_antonCssV = @filemtime(__DIR__ . '/styles/anton.css') ?: '1';
$error = null;
$prefillEmail = trim((string)($_POST['email'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $email = trim($_POST['email'] ?? '');
  $pass = $_POST['password'] ?? '';
  if (auth_login($email, $pass, false)) redirect('dashboard.php');
  $error = 'Invalid email or password. Try again.';
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="format-detection" content="telephone=no">
  <meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: dark)">
  <meta name="theme-color" content="#f7f7f8" media="(prefers-color-scheme: light)">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="apple-touch-icon" href="partials/antonx-favicon.png">
  <!-- FOUC-safe theme init — mirrors the one in partials/header.php so the
       login screen uses the same persisted theme as the rest of the app. -->
  <script>
    (function() {
      try {
        var saved = localStorage.getItem('anton-theme');
        var prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
        var theme = saved || (prefersLight ? 'light' : 'dark');
        if (theme === 'light') document.documentElement.classList.add('light');
      } catch (e) {}
    })();
  </script>
  <title>anton x — Sign in</title>
  <link rel="icon" type="image/png" href="partials/antonx-favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles/anton.css?v=<?= h((string)$_antonCssV) ?>">
  <style>
    /* Self-contained auth layout — does NOT use the sidebar `.app` grid. */
    html, body { background: var(--bg); }
    .auth-shell {
      min-height: 100vh;
      min-height: 100dvh;
      display: grid;
      place-items: center;
      padding:
        max(32px, env(safe-area-inset-top, 0px))
        max(16px, env(safe-area-inset-right, 0px))
        max(32px, env(safe-area-inset-bottom, 0px))
        max(16px, env(safe-area-inset-left, 0px));
      position: relative;
      overflow: hidden;
      background:
        radial-gradient(900px 540px at 50% -10%, rgba(250,204,21,0.06), transparent 70%),
        radial-gradient(700px 420px at 0% 100%, rgba(168,85,247,0.05), transparent 65%),
        radial-gradient(700px 420px at 100% 100%, rgba(59,130,246,0.04), transparent 65%);
    }
    /* Subtle grid pattern for visual texture, matches dark surface aesthetic. */
    .auth-shell::before {
      content: '';
      position: absolute;
      inset: 0;
      background-image:
        linear-gradient(rgba(255,255,255,0.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.025) 1px, transparent 1px);
      background-size: 56px 56px;
      mask-image: radial-gradient(ellipse 70% 60% at 50% 50%, #000 40%, transparent 100%);
      -webkit-mask-image: radial-gradient(ellipse 70% 60% at 50% 50%, #000 40%, transparent 100%);
      pointer-events: none;
    }
    .auth-card {
      position: relative;
      width: 100%;
      max-width: 420px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 32px 32px 28px;
      box-shadow:
        0 24px 60px rgba(0,0,0,0.55),
        0 0 0 1px rgba(255,255,255,0.02) inset;
    }
    .auth-brand {
      display: flex; align-items: center; justify-content: center; gap: 2px;
      font-weight: 700; font-size: 28px; letter-spacing: -0.025em;
      color: var(--text);
      margin-bottom: 24px;
    }
    .auth-brand .x {
      color: var(--accent);
      margin-left: 4px;
    }
    /* Inline SVG. "anton" text uses currentColor (set via .color below);
       "X" stays #F2CC2F from the SVG's hardcoded fill — same yellow in
       every theme, no filter math. */
    .auth-brand-logo {
      display: inline-block;
      width: 100%;
      max-width: 200px;
      line-height: 0;
      color: var(--text);
    }
    .auth-brand-logo svg {
      display: block;
      width: 100%;
      height: auto;
    }
    .auth-eyebrow {
      font-size: 11px;
      color: var(--text-dim);
      text-transform: uppercase;
      letter-spacing: 0.15em;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 8px;
    }
    .auth-eyebrow::before {
      content: '';
      width: 4px; height: 4px;
      background: var(--accent);
      border-radius: 50%;
      box-shadow: 0 0 0 3px rgba(250,204,21,0.18);
    }
    .auth-title {
      font-size: 22px;
      font-weight: 700;
      color: var(--text);
      letter-spacing: -0.02em;
      line-height: 1.2;
      margin-bottom: 4px;
    }
    .auth-sub {
      font-size: 13px;
      color: var(--text-muted);
      margin-bottom: 22px;
    }

    .auth-field { margin-bottom: 14px; }
    .auth-label {
      display: flex; align-items: center; justify-content: space-between;
      font-size: 11.5px; font-weight: 500; color: var(--text-muted);
      margin-bottom: 6px;
    }
    .input-shell {
      position: relative;
      display: flex; align-items: center;
      background: var(--surface-2);
      border: 1px solid var(--border);
      border-radius: 10px;
      transition: all 0.15s;
    }
    .input-shell:focus-within {
      border-color: var(--accent);
      background: var(--bg);
      box-shadow: 0 0 0 3px rgba(250,204,21,0.12);
    }
    .input-shell svg.leading {
      width: 16px; height: 16px;
      color: var(--text-dim);
      margin-left: 14px;
      flex-shrink: 0;
      transition: color 0.15s;
    }
    .input-shell:focus-within svg.leading { color: var(--accent); }
    .auth-input {
      flex: 1;
      background: transparent;
      border: 0; outline: 0;
      color: var(--text);
      font-size: 14px;
      font-family: inherit;
      padding: 13px 14px;
      letter-spacing: -0.005em;
    }
    .auth-input::placeholder { color: var(--text-dim); }
    /* Suppress browser autofill yellow */
    .auth-input:-webkit-autofill,
    .auth-input:-webkit-autofill:hover,
    .auth-input:-webkit-autofill:focus {
      -webkit-text-fill-color: var(--text);
      -webkit-box-shadow: 0 0 0 1000px var(--surface-2) inset;
      caret-color: var(--text);
      transition: background-color 9999s ease-out 0s;
    }
    .pw-toggle {
      width: 32px; height: 32px;
      border-radius: 7px;
      color: var(--text-dim);
      display: inline-flex; align-items: center; justify-content: center;
      transition: all 0.12s;
      margin-right: 6px;
      background: none; border: 0; cursor: pointer; flex-shrink: 0;
    }
    .pw-toggle:hover { color: var(--text); background: var(--surface); }
    .pw-toggle svg { width: 16px; height: 16px; }

    .auth-row {
      display: flex; align-items: center; justify-content: space-between;
      margin: 4px 0 18px;
    }
    .auth-row .remember {
      display: inline-flex; align-items: center; gap: 8px;
      font-size: 12.5px; color: var(--text-muted);
      cursor: pointer;
      user-select: none;
    }
    .auth-row .remember input { display: none; }
    .auth-row .remember .box {
      width: 16px; height: 16px;
      border: 1.5px solid var(--border-strong);
      background: var(--bg);
      border-radius: 4px;
      display: inline-flex; align-items: center; justify-content: center;
      transition: all 0.12s;
      flex-shrink: 0;
    }
    .auth-row .remember .box svg {
      width: 11px; height: 11px;
      color: #1a1400;
      opacity: 0;
      transition: opacity 0.12s;
    }
    .auth-row .remember input:checked ~ .box {
      background: var(--accent);
      border-color: var(--accent);
    }
    .auth-row .remember input:checked ~ .box svg { opacity: 1; }
    .forgot-link {
      font-size: 12.5px;
      color: var(--text-muted);
      text-decoration: none;
      transition: color 0.12s;
    }
    .forgot-link:hover { color: var(--accent); }

    .auth-submit {
      width: 100%;
      padding: 13px 14px;
      border-radius: 10px;
      background: var(--accent);
      color: #1a1400;
      font-size: 14px;
      font-weight: 600;
      border: 0;
      cursor: pointer;
      display: inline-flex;
      align-items: center; justify-content: center;
      gap: 8px;
      transition: all 0.15s;
      font-family: inherit;
    }
    .auth-submit:hover { background: var(--accent-hover); }
    .auth-submit:active { transform: scale(0.98); }
    .auth-submit svg { width: 14px; height: 14px; }
    .auth-submit[disabled] {
      opacity: 0.7;
      cursor: wait;
    }
    .auth-submit .spinner {
      display: none;
      width: 14px; height: 14px;
      border: 2px solid rgba(26,20,0,0.25);
      border-top-color: #1a1400;
      border-radius: 50%;
      animation: spin 0.6s linear infinite;
    }
    .auth-submit.loading .label-arrow { display: none; }
    .auth-submit.loading .spinner { display: inline-block; }
    @keyframes spin { to { transform: rotate(360deg); } }

    .auth-error {
      display: flex; align-items: flex-start; gap: 10px;
      padding: 11px 13px;
      background: var(--danger-soft);
      border: 1px solid rgba(239,68,68,0.25);
      border-radius: 10px;
      color: #fca5a5;
      font-size: 12.5px;
      line-height: 1.5;
      margin-bottom: 16px;
    }
    .auth-error svg {
      width: 14px; height: 14px; flex-shrink: 0; margin-top: 1px;
    }

    .caps-hint {
      font-size: 11.5px;
      color: var(--accent);
      margin-top: 6px;
      padding: 6px 10px;
      background: var(--accent-soft);
      border: 1px solid rgba(250,204,21,0.25);
      border-radius: 7px;
      display: none;
      align-items: center;
      gap: 6px;
    }
    .caps-hint svg { width: 11px; height: 11px; }
    .caps-hint.show { display: inline-flex; }

    .auth-foot {
      margin-top: 22px;
      padding-top: 18px;
      border-top: 1px solid var(--border);
      text-align: center;
      font-size: 11.5px;
      color: var(--text-dim);
      letter-spacing: 0.04em;
    }
    .auth-foot .kbd {
      background: var(--surface-2);
      border: 1px solid var(--border);
      border-radius: 4px;
      padding: 1px 5px;
      font-family: 'JetBrains Mono', ui-monospace, monospace;
      font-size: 10px;
      color: var(--text-muted);
      margin: 0 2px;
    }
    .auth-foot a { color: var(--text-muted); text-decoration: none; transition: color 0.12s; }
    .auth-foot a:hover { color: var(--accent); }

    @media (max-width: 480px) {
      .auth-card { padding: 26px 22px 22px; }
      .auth-title { font-size: 19px; }
      /* 16px minimum prevents iOS viewport auto-zoom on input focus */
      .auth-input { font-size: 16px; min-height: 48px; }
      .auth-submit { font-size: 16px; min-height: 52px; }
    }
    @media (max-width: 375px) {
      .auth-shell { padding: 20px 12px; }
      .auth-card { padding: 22px 16px 18px; }
    }
  </style>
</head>
<body>

<!-- Theme toggle (same fixed top-right button as the rest of the app). -->
<button type="button" id="themeToggle" class="theme-toggle" aria-label="Toggle theme" title="Toggle dark / light mode">
  <svg class="ico-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="M4.93 4.93l1.41 1.41"></path><path d="M17.66 17.66l1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="M4.93 19.07l1.41-1.41"></path><path d="M17.66 6.34l1.41-1.41"></path></svg>
  <svg class="ico-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
</button>

<div class="auth-shell">
  <div class="auth-card">
    <div class="auth-brand" aria-label="anton x">
      <span class="auth-brand-logo"><?= file_get_contents(__DIR__ . '/partials/antonx-logo.svg') ?></span>
    </div>

    <div class="auth-eyebrow">Welcome back</div>
    <h1 class="auth-title">Sign in to your workspace</h1>
    <p class="auth-sub">Use your work email and password to continue.</p>

    <?php if ($error): ?>
      <div class="auth-error" role="alert">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        <span><?= h($error) ?></span>
      </div>
    <?php endif; ?>

    <form method="post" id="loginForm" autocomplete="on" novalidate>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

      <div class="auth-field">
        <label class="auth-label" for="login-email">Email</label>
        <div class="input-shell">
          <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
          <input class="auth-input" id="login-email" name="email" type="email" placeholder="you@company.com" value="<?= h($prefillEmail) ?>" autocomplete="email" autofocus required>
        </div>
      </div>

      <div class="auth-field" style="margin-bottom: 6px;">
        <label class="auth-label" for="login-password">
          Password
          <a class="forgot-link" href="forgot_password.php">Forgot?</a>
        </label>
        <div class="input-shell">
          <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0110 0v4"></path></svg>
          <input class="auth-input" id="login-password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required>
          <button class="pw-toggle" type="button" id="pwToggle" aria-label="Show password">
            <svg id="pwEye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
          </button>
        </div>
        <div class="caps-hint" id="capsHint" role="status">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4l8 9H4l8-9z"></path><line x1="4" y1="17" x2="20" y2="17"></line><line x1="4" y1="20" x2="20" y2="20"></line></svg>
          Caps Lock is on
        </div>
      </div>

      <div class="auth-row">
        <label class="remember">
          <input type="checkbox" name="remember" value="1">
          <span class="box"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></span>
          Keep me signed in
        </label>
      </div>

      <button class="auth-submit" type="submit" id="submitBtn">
        <span class="label-arrow" style="display:inline-flex;align-items:center;gap:8px;">
          Sign in
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
        </span>
        <span class="spinner" aria-hidden="true"></span>
      </button>
    </form>

    <div class="auth-foot">
      Press <span class="kbd">Enter</span> to sign in · powered by <a href="https://speedxmarketing.com" target="_blank" rel="noopener">SpeedX</a>
    </div>
  </div>
</div>

<script>
  // Password show/hide
  const pwInput = document.getElementById('login-password');
  const pwToggle = document.getElementById('pwToggle');
  const eyeOpen = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
  const eyeOff  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0112 20c-7 0-11-8-11-8a18.5 18.5 0 014.06-5.94"></path><path d="M9.9 4.24A10.93 10.93 0 0112 4c7 0 11 8 11 8a18.45 18.45 0 01-2.16 3.19"></path><path d="M14.12 14.12a3 3 0 11-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
  pwToggle.addEventListener('click', () => {
    const shown = pwInput.type === 'text';
    pwInput.type = shown ? 'password' : 'text';
    pwToggle.innerHTML = shown ? eyeOpen : eyeOff;
    pwToggle.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
  });

  // Caps Lock detection
  const capsHint = document.getElementById('capsHint');
  function checkCaps(e) {
    if (e.getModifierState && e.getModifierState('CapsLock')) {
      capsHint.classList.add('show');
    } else {
      capsHint.classList.remove('show');
    }
  }
  pwInput.addEventListener('keyup', checkCaps);
  pwInput.addEventListener('keydown', checkCaps);
  pwInput.addEventListener('blur', () => capsHint.classList.remove('show'));

  // Loading state on submit (re-enabled on a failed POST that returns to this page)
  const form = document.getElementById('loginForm');
  const submitBtn = document.getElementById('submitBtn');
  form.addEventListener('submit', () => {
    submitBtn.classList.add('loading');
    submitBtn.disabled = true;
  });

  // Auto-focus password if email is prefilled and present
  window.addEventListener('DOMContentLoaded', () => {
    const emailEl = document.getElementById('login-email');
    if (emailEl.value.trim() !== '') pwInput.focus();
  });

  // Theme toggle (same behaviour as the rest of the app — persists to localStorage).
  (function(){
    const btn = document.getElementById('themeToggle');
    if (!btn) return;
    btn.addEventListener('click', () => {
      const isLight = document.documentElement.classList.toggle('light');
      try { localStorage.setItem('anton-theme', isLight ? 'light' : 'dark'); } catch (e) {}
    });
  })();
</script>

</body>
</html>
