<?php
require_once __DIR__ . '/../lib/helpers.php';
$config = file_exists(__DIR__ . '/../config.php') ? (require __DIR__ . '/../config.php') : ['app'=>['name'=>'AntonX']];
$pageTitle = $pageTitle ?? ($config['app']['name'] ?? 'AntonX');
$pageHeadExtra = $pageHeadExtra ?? '';
// Cache-buster for anton.css: changes every time the file is edited, so the
// browser is forced to re-fetch on every deploy. Without this, stylesheet
// changes silently get masked by browser cache even after a "hard refresh".
$_antonCssV = @filemtime(__DIR__ . '/../styles/anton.css') ?: '1';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- FOUC-safe theme init: runs BEFORE any CSS link so the right palette is
       applied on the first paint. Reads `anton-theme` from localStorage; if
       absent, falls back to the user's OS preference. -->
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
  <title>anton x — <?=h($pageTitle)?></title>
  <link rel="icon" type="image/png" href="partials/antonx-favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <!-- Bootstrap is loaded first as a compatibility shim for pages not yet rebuilt against the new design system. -->
  <!-- anton.css and the shim block follow so the new design system wins all property collisions on shared class names (.btn, .btn-primary, .nav, body, ...). -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* Tone-down Bootstrap defaults for legacy (non-redesigned) pages so they blend with anton.css. */
    body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
    .btn-yellow { background: var(--accent); border-color: var(--accent); color: #1a1400; font-weight: 600; }
    .btn-yellow:hover, .btn-yellow:focus { background: var(--accent-hover); border-color: var(--accent-hover); color: #1a1400; }
    .form-control, .form-select { background: var(--surface-2); border-color: var(--border); color: var(--text); }
    .form-control:focus, .form-select:focus { background: var(--bg); border-color: var(--accent); box-shadow: 0 0 0 0.15rem rgba(250,204,21,0.18); }
    .modal-content { background: var(--surface); border: 1px solid var(--border); color: var(--text); }
    .modal-header, .modal-footer { border-color: var(--border); }
    .table { color: var(--text); --bs-table-bg: transparent; --bs-table-color: var(--text); }
    .alert-success { background: var(--success-soft); border-color: rgba(34,197,94,0.25); color: #86efac; }
    .alert-danger { background: var(--danger-soft); border-color: rgba(239,68,68,0.25); color: #fca5a5; }
  </style>
  <link rel="stylesheet" href="styles/anton.css?v=<?=h((string)$_antonCssV)?>">
  <?=$pageHeadExtra?>
</head>
<body>
<!-- Theme toggle (fixed top-right, visible on every designed page). Icon swap and
     localStorage persistence are handled in partials/footer.php. -->
<button type="button" id="themeToggle" class="theme-toggle" aria-label="Toggle theme" title="Toggle dark / light mode">
  <svg class="ico-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="M4.93 4.93l1.41 1.41"></path><path d="M17.66 17.66l1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="M4.93 19.07l1.41-1.41"></path><path d="M17.66 6.34l1.41-1.41"></path></svg>
  <svg class="ico-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
</button>
<div class="app" data-screen-label="<?=h($pageTitle)?>">
