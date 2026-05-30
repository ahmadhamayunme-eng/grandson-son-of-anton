<?php
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/nav_helpers.php';
$config = file_exists(__DIR__ . '/../config.php') ? (require __DIR__ . '/../config.php') : ['app'=>['name'=>'AntonX']];
$pageTitle = $pageTitle ?? ($config['app']['name'] ?? 'AntonX');
$pageHeadExtra = $pageHeadExtra ?? '';
// Cache-buster for anton.css: changes every time the file is edited, so the
// browser is forced to re-fetch on every deploy. Without this, stylesheet
// changes silently get masked by browser cache even after a "hard refresh".
$_antonCssV = @filemtime(__DIR__ . '/../styles/anton.css') ?: '1';

// Anton Jr. bell — combined chat-unread + task-pending count. Helpers
// safe-fail if the user isn't logged in or the chat tables don't exist yet.
$_navBellUser  = function_exists('auth_user') ? auth_user() : null;
$_navBellUid   = (int)($_navBellUser['id'] ?? 0);
$_navBellWs    = (int)($_navBellUser['workspace_id'] ?? 0);
$_navBellTotal = $_navBellUid > 0 ? (
    nav_chat_unread_total($_navBellUid, $_navBellWs)
  + nav_antonx_pending_total($_navBellUid, $_navBellWs)
) : 0;
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <!-- viewport-fit=cover is required for env(safe-area-inset-*) to resolve on
       notched iPhones / Dynamic-Island devices; interactive-widget=resizes-content
       gives us the same content-resize behavior on Android as iOS so the
       composer / fixed CTAs land above the keyboard predictably. -->
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
  <meta name="format-detection" content="telephone=no">
  <!-- Browser-chrome tint. The same #FACC15 yellow that the .theme-color JS
       below overrides per-mode. -->
  <meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: dark)">
  <meta name="theme-color" content="#f7f7f8" media="(prefers-color-scheme: light)">
  <!-- PWA / iOS home-screen hints so the app feels installable. -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="anton x">
  <meta name="mobile-web-app-capable" content="yes">
  <!-- PWA manifest + installability (item #11). -->
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="apple-touch-icon" href="partials/antonx-favicon.png">
  <!-- FOUC-safe theme init: runs BEFORE any CSS link so the right palette is
       applied on the first paint. Reads `anton-theme` from localStorage; if
       absent, falls back to the user's OS preference. Also syncs the
       theme-color meta so the mobile browser chrome matches the active theme
       (not just the OS preference). -->
  <script>
    (function() {
      try {
        var saved = localStorage.getItem('anton-theme');
        var prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
        var theme = saved || (prefersLight ? 'light' : 'dark');
        if (theme === 'light') document.documentElement.classList.add('light');
        var color = theme === 'light' ? '#f7f7f8' : '#0a0a0a';
        var meta = document.createElement('meta');
        meta.name = 'theme-color';
        meta.content = color;
        meta.setAttribute('data-runtime', '1');
        document.head.appendChild(meta);
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
<!-- Skip link (item #8): first focusable element so keyboard users can jump
     straight to the page content past the nav/utility chrome. -->
<a class="skip-link" href="#main">Skip to main content</a>
<!-- Theme toggle (fixed top-right, visible on every designed page). Icon swap and
     localStorage persistence are handled in partials/footer.php. -->
<button type="button" id="themeToggle" class="theme-toggle" aria-label="Toggle theme" title="Toggle dark / light mode">
  <svg class="ico-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="M4.93 4.93l1.41 1.41"></path><path d="M17.66 17.66l1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="M4.93 19.07l1.41-1.41"></path><path d="M17.66 6.34l1.41-1.41"></path></svg>
  <svg class="ico-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
</button>
<!-- Anton Jr. bell: combined unread chat + open AntonX tasks count. Click
     opens /chat/ (where the inbox view aggregates DMs + mentions). Hidden when
     no user is logged in. -->
<?php if ($_navBellUid > 0):
  // Check if there's a recent @channel/@everyone mention the user hasn't seen
  // yet — if so, we'll start the bell in pulse state on page load.
  $_navBellPulse = false;
  try {
    $st = db()->prepare(
      "SELECT COUNT(*) FROM chat_mentions cm
       JOIN chat_messages m ON m.id = cm.message_id
       JOIN chat_channel_members ccm
         ON ccm.channel_id = m.channel_id AND ccm.user_id = ?
       WHERE cm.mention_type IN ('channel','everyone','here')
         AND m.workspace_id = ?
         AND m.deleted_at IS NULL
         AND m.user_id != ?
         AND m.id > IFNULL(ccm.last_read_message_id, 0)"
    );
    $st->execute([$_navBellUid, $_navBellWs, $_navBellUid]);
    $_navBellPulse = (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) {}
?>
<a id="antonBell" class="anton-bell<?= $_navBellPulse ? ' pulse' : '' ?>" href="chat/" title="Notifications · click to open chat" aria-label="Notifications">
  <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 16v-5a6 6 0 10-12 0v5l-2 3h16l-2-3z"></path><path d="M9 20a3 3 0 006 0"></path></svg>
  <?php if ($_navBellTotal > 0): ?>
    <span class="anton-bell-badge"><?= $_navBellTotal > 99 ? '99+' : (int)$_navBellTotal ?></span>
  <?php endif; ?>
</a>
<style>
  /* Anton Jr. bell — fixed top-right next to the theme toggle. Matches
     theme-toggle dimensions so they sit on the same baseline. */
  .anton-bell {
    position: fixed;
    /* Safe-area-aware: respects the notch / Dynamic Island. Falls back to a
       plain 16px on devices that don't expose the env var. */
    top: calc(env(safe-area-inset-top, 0px) + 16px);
    right: calc(env(safe-area-inset-right, 0px) + 74px); /* 18 + 44 + 12 gap */
    width: 44px;
    height: 44px;
    border-radius: 999px;
    background: var(--surface);
    border: 1px solid var(--border-strong);
    color: var(--text);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 9998;
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(0,0,0,0.35), 0 0 0 1px rgba(255,255,255,0.04) inset;
    transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
  }
  .anton-bell:hover { color: var(--accent); border-color: var(--accent); transform: translateY(-1px); background: var(--surface-2); }
  .anton-bell:active { transform: scale(0.94); }
  .anton-bell svg { display: block; pointer-events: none; }
  .anton-bell-badge {
    position: absolute;
    top: 4px;
    right: 4px;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: oklch(0.70 0.20 25);
    color: #fff;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 10.5px;
    font-weight: 700;
    line-height: 18px;
    text-align: center;
    box-shadow: 0 0 0 2px var(--surface);
  }
  .anton-bell.pulse {
    animation: anton-bell-pulse 1.4s ease-in-out 3;
    color: oklch(0.70 0.20 25);
    border-color: oklch(0.70 0.20 25);
  }
  @keyframes anton-bell-pulse {
    0%, 100% { transform: translateY(0) scale(1); }
    50%      { transform: translateY(-1px) scale(1.06); }
  }
  @media (max-width: 560px) {
    .anton-bell {
      width: 40px; height: 40px;
      top: calc(env(safe-area-inset-top, 0px) + 12px);
      right: calc(env(safe-area-inset-right, 0px) + 62px);
    }
    .anton-bell svg { width: 18px; height: 18px; }
  }

  /* Notification center dropdown (item #10). */
  .anton-notif-panel {
    position: fixed;
    top: calc(env(safe-area-inset-top, 0px) + 66px);
    right: calc(env(safe-area-inset-right, 0px) + 16px);
    width: 340px;
    max-width: calc(100vw - 24px);
    max-height: 70vh;
    background: var(--surface);
    border: 1px solid var(--border-strong);
    border-radius: 14px;
    box-shadow: 0 18px 50px rgba(0,0,0,0.45);
    z-index: 9999;
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }
  .anton-notif-panel[hidden] { display: none; }
  .anton-notif-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 14px; border-bottom: 1px solid var(--border);
    font-weight: 600; font-size: 14px; color: var(--text);
  }
  .anton-notif-mark { background: none; border: 0; color: var(--accent); font-size: 12px; cursor: pointer; padding: 4px 6px; border-radius: 6px; }
  .anton-notif-mark:hover { background: var(--surface-2); }
  .anton-notif-list { overflow-y: auto; }
  .anton-notif-item { display: block; padding: 11px 14px; border-bottom: 1px solid var(--border); color: var(--text); text-decoration: none; font-size: 13px; line-height: 1.4; }
  .anton-notif-item:hover { background: var(--surface-2); }
  .anton-notif-item.unread { background: var(--accent-soft, rgba(250,204,21,0.07)); }
  .anton-notif-item .who { font-weight: 600; }
  .anton-notif-item .when { display: block; color: var(--text-dim); font-size: 11px; margin-top: 2px; }
  .anton-notif-empty { padding: 22px 14px; color: var(--text-muted); font-size: 13px; text-align: center; }
  .anton-notif-foot { display: block; text-align: center; padding: 10px; color: var(--text-muted); text-decoration: none; font-size: 12.5px; border-top: 1px solid var(--border); }
  .anton-notif-foot:hover { color: var(--accent); }
</style>
<div id="antonNotifPanel" class="anton-notif-panel" role="dialog" aria-label="Notifications" hidden data-csrf="<?= h(csrf_token()) ?>">
  <div class="anton-notif-head">
    <span>Notifications</span>
    <button type="button" id="antonNotifMarkRead" class="anton-notif-mark">Mark all read</button>
  </div>
  <div class="anton-notif-list" id="antonNotifList"><div class="anton-notif-empty">Loading…</div></div>
  <a class="anton-notif-foot" href="activity.php">View all activity →</a>
</div>
<script>
  (function(){
    var bell  = document.getElementById('antonBell');
    var panel = document.getElementById('antonNotifPanel');
    if (!bell || !panel) return;
    var list  = document.getElementById('antonNotifList');
    var loaded = false;

    function esc(s){ var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }

    function render(data){
      var items = (data && data.items) || [];
      if (!items.length){ list.innerHTML = '<div class="anton-notif-empty">You’re all caught up.</div>'; return; }
      list.innerHTML = items.map(function(it){
        var label = esc(it.actor) + ' · ' + esc(it.action) + (it.message ? ' — ' + esc(it.message) : '');
        return '<a class="anton-notif-item' + (it.unread ? ' unread' : '') + '" href="' + esc(it.href) + '">' +
               '<span class="who">' + label + '</span>' +
               '<span class="when">' + esc(it.created_at) + '</span></a>';
      }).join('');
    }

    function load(){
      fetch('notifications_feed.php', { headers: { 'X-Requested-With': 'fetch' } })
        .then(function(r){ return r.json(); })
        .then(function(d){ loaded = true; render(d); })
        .catch(function(){ list.innerHTML = '<div class="anton-notif-empty">Could not load notifications.</div>'; });
    }

    function open(){ panel.hidden = false; if (!loaded) load(); }
    function close(){ panel.hidden = true; }

    bell.addEventListener('click', function(e){
      e.preventDefault();           // open the panel instead of navigating to chat
      panel.hidden ? open() : close();
    });
    document.addEventListener('click', function(e){
      if (panel.hidden) return;
      if (!panel.contains(e.target) && !bell.contains(e.target)) close();
    });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });

    document.getElementById('antonNotifMarkRead').addEventListener('click', function(){
      var body = new FormData();
      body.append('action', 'mark_read');
      body.append('csrf', panel.getAttribute('data-csrf') || '');
      fetch('notifications_feed.php', { method: 'POST', body: body })
        .then(function(r){ return r.json(); })
        .then(function(){
          var badge = bell.querySelector('.anton-bell-badge');
          if (badge) badge.remove();
          bell.classList.remove('pulse');
          list.querySelectorAll('.anton-notif-item.unread').forEach(function(el){ el.classList.remove('unread'); });
        }).catch(function(){});
    });
  })();
</script>
<?php endif; ?>
<!-- Hamburger button — hidden on desktop via CSS, shown at ≤768px.
     Toggles `body.nav-open` which slides the sidebar drawer in/out. -->
<button type="button" id="navHamburger" class="nav-hamburger" aria-label="Open navigation menu" aria-expanded="false" aria-controls="antonNav">
  <span></span>
  <span></span>
  <span></span>
</button>
<div class="app" data-screen-label="<?=h($pageTitle)?>">
