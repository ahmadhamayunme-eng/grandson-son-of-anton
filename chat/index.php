<?php
// Anton Chat — main app shell (Phase 1).
//
// This page renders its own minimal layout (instead of using layout.php /
// partials/header.php) so it can set <base href="/"> at the very top of
// <head>. Without that, anton.css and the AntonX sidebar's relative links
// resolve against /chat/ and 404. The 4 existing AntonX pages that use
// fragment links (#overview etc.) keep using layout.php and are unaffected.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (ob_get_level() === 0) { ob_start(); }
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/includes/helpers.php';

// auth_require_login() uses redirect('login.php') which the browser resolves
// relative to the current URL. From /chat/ that lands on /chat/login.php (404).
// Send to /login.php explicitly so a logged-out deeplink works.
if (!auth_user()) {
  if (!headers_sent()) { header('Location: /login.php', true, 303); }
  exit;
}

$user = auth_user();
$ws   = auth_workspace_id();
$pdo  = db();

$stmt = $pdo->prepare('SELECT name FROM workspaces WHERE id = ?');
$stmt->execute([$ws]);
$workspaceName = (string)($stmt->fetchColumn() ?: 'Workspace');

$pageTitle = 'Chat';
$activeKey = 'chat';  // partials/nav.php reads this to highlight the Chat row.

$_antonCssV = @filemtime(__DIR__ . '/../styles/anton.css') ?: '1';
$_chatCssV  = @filemtime(__DIR__ . '/assets/css/chat.css') ?: '1';
$_chatJsV   = @filemtime(__DIR__ . '/assets/js/chat.js') ?: '1';
?><!doctype html>
<html lang="en">
<head>
  <base href="/">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script>
    // FOUC-safe theme init — mirrors partials/header.php exactly.
    (function() {
      try {
        var saved = localStorage.getItem('anton-theme');
        var prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
        var theme = saved || (prefersLight ? 'light' : 'dark');
        if (theme === 'light') document.documentElement.classList.add('light');
      } catch (e) {}
    })();
  </script>
  <title>anton x — <?= h($pageTitle) ?></title>
  <link rel="icon" type="image/png" href="partials/antonx-favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles/anton.css?v=<?= h((string)$_antonCssV) ?>">
  <link rel="stylesheet" href="chat/assets/css/chat.css?v=<?= h((string)$_chatCssV) ?>">
</head>
<body>
<!-- Theme toggle — mirrors partials/header.php so the icon swap + localStorage
     persistence behave identically across AntonX and Chat. -->
<button type="button" id="themeToggle" class="theme-toggle" aria-label="Toggle theme" title="Toggle dark / light mode">
  <svg class="ico-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="M4.93 4.93l1.41 1.41"></path><path d="M17.66 17.66l1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="M4.93 19.07l1.41-1.41"></path><path d="M17.66 6.34l1.41-1.41"></path></svg>
  <svg class="ico-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
</button>

<div class="app" data-screen-label="<?= h($pageTitle) ?>">
<?php include __DIR__ . '/../partials/nav.php'; ?>
<main class="main">

<div class="chat-shell">
  <aside class="chat-sidebar">
    <header class="chat-ws">
      <div class="chat-ws-name"><?= h($workspaceName) ?></div>
      <div class="chat-ws-user"><?= h($user['name']) ?> <span class="chat-ws-sep">·</span> <span class="chat-ws-role"><?= h($user['role_name'] ?? 'Member') ?></span></div>
    </header>

    <nav class="chat-nav">
      <section class="chat-section">
        <div class="chat-section-head"><span>Channels</span></div>
        <div class="chat-section-empty">No channels yet</div>
      </section>

      <section class="chat-section">
        <div class="chat-section-head"><span>Direct Messages</span></div>
        <div class="chat-section-empty">No direct messages yet</div>
      </section>
    </nav>
  </aside>

  <section class="chat-pane">
    <div class="chat-empty">
      <div class="chat-empty-title">Welcome, <?= h($user['name']) ?>.</div>
      <div class="chat-empty-sub">Anton Chat is online. Channels and DMs come in the next phase.</div>
    </div>
  </section>
</div>

</main>
</div>

<script>
  // Theme toggle click handler — copied verbatim from partials/footer.php so
  // chat behaves identically to AntonX (toggles html.light, persists in
  // localStorage under "anton-theme").
  (function(){
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    btn.addEventListener('click', function(){
      var isLight = document.documentElement.classList.toggle('light');
      try { localStorage.setItem('anton-theme', isLight ? 'light' : 'dark'); } catch (e) {}
    });
  })();
</script>
<script src="chat/assets/js/chat.js?v=<?= h((string)$_chatJsV) ?>"></script>
<?php if (ob_get_level() > 0) { ob_end_flush(); } ?>
</body>
</html>
