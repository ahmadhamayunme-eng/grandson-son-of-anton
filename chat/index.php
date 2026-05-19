<?php
// Anton Chat — main app shell.
//
// Phase 2: server-renders the sidebar's channel list and the selected
// channel's initial 50 messages, then JS handles sending new messages,
// switching channels, and the create/browse-channel modals.
//
// Renders its own minimal <head> instead of using partials/header.php so
// it can set <base href="/"> at the top of the document — that makes
// anton.css, the AntonX sidebar's relative hrefs, and avatars resolve
// from the domain root regardless of the chat URL depth.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (ob_get_level() === 0) { ob_start(); }
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/includes/helpers.php';

if (!auth_user()) {
  if (!headers_sent()) { header('Location: /login.php', true, 303); }
  exit;
}

$user = auth_user();
$ws   = auth_workspace_id();
$uid  = (int)$user['id'];
$pdo  = db();

// Workspace name for the sidebar header.
$stmt = $pdo->prepare('SELECT name FROM workspaces WHERE id = ?');
$stmt->execute([$ws]);
$workspaceName = (string)($stmt->fetchColumn() ?: 'Workspace');

// Channels the user is in (for the sidebar).
$userChannels = chat_load_user_channels($ws, $uid);

// Max event id at page-render time — passed to the SSE client so the very
// first connect starts from "now" instead of replaying old events. Reconnects
// after that use the Last-Event-ID header.
$stmt = $pdo->prepare('SELECT IFNULL(MAX(id), 0) FROM chat_events WHERE workspace_id = ?');
$stmt->execute([$ws]);
$lastEventId = (int)$stmt->fetchColumn();

// Selected channel: ?c=<slug>. If none specified but the user has
// channels, redirect to the first one — same default-landing behaviour
// as Slack. If the channel doesn't exist or isn't visible, fall through
// to the "pick a channel" empty state.
$currentSlug    = (string)($_GET['c'] ?? '');
$currentChannel = null;
$currentMessages = [];
$memberCount    = 0;
$isMember       = false;

if ($currentSlug !== '') {
  $ch = chat_load_channel_by_slug($ws, $currentSlug);
  if ($ch && chat_user_can_see_channel($ch, $uid, $ws)) {
    $currentChannel  = $ch;
    $isMember        = chat_user_is_channel_member((int)$ch['id'], $uid);
    $currentMessages = chat_load_recent_messages((int)$ch['id'], 50);
    $memberCount     = chat_channel_member_count((int)$ch['id']);
  }
}
if ($currentChannel === null && $currentSlug === '' && !empty($userChannels)) {
  header('Location: /chat/?c=' . urlencode($userChannels[0]['slug']), true, 303);
  exit;
}

$pageTitle = $currentChannel ? '#' . $currentChannel['slug'] : 'Chat';
$activeKey = 'chat';

$_antonCssV = @filemtime(__DIR__ . '/../styles/anton.css') ?: '1';
$_chatCssV  = @filemtime(__DIR__ . '/assets/css/chat.css') ?: '1';
$_chatJsV   = @filemtime(__DIR__ . '/assets/js/chat.js') ?: '1';
?><!doctype html>
<html lang="en">
<head>
  <base href="/">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
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
        <div class="chat-section-head">
          <span>Channels</span>
          <button type="button" class="chat-section-add" title="Create channel" data-action="open-create-channel" aria-label="Create channel">+</button>
        </div>
        <?php if (empty($userChannels)): ?>
          <div class="chat-section-empty">No channels yet</div>
        <?php else: ?>
          <?php foreach ($userChannels as $c):
            $active = $currentChannel && (int)$currentChannel['id'] === (int)$c['id'];
          ?>
            <a class="chat-row<?= $active ? ' active' : '' ?>" href="/chat/?c=<?= h(urlencode($c['slug'])) ?>">
              <span class="chat-row-prefix" aria-hidden="true"><?= $c['is_private'] ? '🔒' : '#' ?></span><?= h($c['slug']) ?>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
        <a href="#" class="chat-section-link" data-action="open-browse-channels">Browse channels</a>
      </section>

      <section class="chat-section">
        <div class="chat-section-head"><span>Direct Messages</span></div>
        <div class="chat-section-empty">DMs land in Phase 4</div>
      </section>
    </nav>
  </aside>

  <section class="chat-pane">
    <?php if ($currentChannel): ?>
      <header class="chat-header">
        <h1 class="chat-header-title">
          <span class="chat-header-prefix" aria-hidden="true"><?= $currentChannel['is_private'] ? '🔒' : '#' ?></span><?= h($currentChannel['slug']) ?>
        </h1>
        <div class="chat-header-meta">
          <span class="chat-header-members"><?= (int)$memberCount ?> member<?= $memberCount === 1 ? '' : 's' ?></span>
          <?php if (!empty($currentChannel['topic'])): ?>
            <span class="chat-header-sep">·</span>
            <span class="chat-header-topic"><?= h($currentChannel['topic']) ?></span>
          <?php endif; ?>
        </div>
      </header>

      <div class="chat-msgs" id="chatMsgs" data-channel-id="<?= (int)$currentChannel['id'] ?>">
        <?php if (empty($currentMessages)): ?>
          <div class="chat-msgs-empty">
            <div class="chat-msgs-empty-title">This is the beginning of #<?= h($currentChannel['slug']) ?>.</div>
            <div class="chat-msgs-empty-sub">Be the first to say something.</div>
          </div>
        <?php else: ?>
          <?php
            $prevAuthor = null; $prevTs = 0;
            foreach ($currentMessages as $m):
              $msgTs   = strtotime($m['created_at']);
              $grouped = ($m['user_id'] === $prevAuthor) && (($msgTs - $prevTs) < 300);
              $deleted = $m['deleted_at'] !== null;
              $edited  = $m['edited_at']  !== null;
          ?>
            <div class="chat-msg<?= $grouped ? ' chat-msg-grouped' : '' ?>" data-msg-id="<?= (int)$m['id'] ?>" data-user-id="<?= (int)$m['user_id'] ?>" data-msg-ts="<?= (int)$msgTs ?>">
              <div class="chat-msg-avatar">
                <?php if (!$grouped): ?>
                  <?= user_avatar_html((int)$m['user_id'], $m['author_name'], 'chat-avatar') ?>
                <?php endif; ?>
              </div>
              <div class="chat-msg-body">
                <?php if (!$grouped): ?>
                  <div class="chat-msg-meta">
                    <span class="chat-msg-name"><?= h($m['author_name']) ?></span>
                    <span class="chat-msg-time"><?= h(chat_format_message_time($m['created_at'])) ?></span>
                  </div>
                <?php endif; ?>
                <div class="chat-msg-text<?= $deleted ? ' chat-msg-deleted' : '' ?>">
                  <?php if ($deleted): ?>
                    <em>This message was deleted.</em>
                  <?php else: ?>
                    <?= nl2br(h($m['content'])) ?><?php if ($edited): ?> <span class="chat-msg-edited">(edited)</span><?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php
              $prevAuthor = $m['user_id'];
              $prevTs     = $msgTs;
            endforeach;
          ?>
        <?php endif; ?>
      </div>

      <?php if ($isMember): ?>
        <form class="chat-composer" id="chatComposer" data-channel-id="<?= (int)$currentChannel['id'] ?>" autocomplete="off">
          <textarea
            class="chat-composer-input"
            id="chatComposerInput"
            name="content"
            placeholder="Message #<?= h($currentChannel['slug']) ?>"
            rows="1"
            maxlength="40000"
            required></textarea>
          <button type="submit" class="chat-composer-send" aria-label="Send">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
          </button>
        </form>
      <?php else: ?>
        <div class="chat-join-prompt">
          You're not in <span class="chat-join-channel">#<?= h($currentChannel['slug']) ?></span>.
          <?php if (empty($currentChannel['is_private'])): ?>
            <button type="button" class="chat-cta" data-action="join-channel" data-channel-id="<?= (int)$currentChannel['id'] ?>" data-channel-slug="<?= h($currentChannel['slug']) ?>">Join channel</button>
          <?php else: ?>
            <span class="chat-join-note">It's a private channel — ask a member to invite you.</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php elseif (empty($userChannels)): ?>
      <div class="chat-empty">
        <div class="chat-empty-title">Welcome to Anton Chat.</div>
        <div class="chat-empty-sub">Get started by creating your first channel.</div>
        <button type="button" class="chat-cta" data-action="open-create-channel">Create a channel</button>
      </div>
    <?php else: ?>
      <div class="chat-empty">
        <div class="chat-empty-title">Pick a channel.</div>
        <div class="chat-empty-sub">Choose a channel in the sidebar, or browse all public channels.</div>
        <button type="button" class="chat-cta" data-action="open-browse-channels">Browse channels</button>
      </div>
    <?php endif; ?>
  </section>
</div>

<!-- ===== Modals ===== -->
<dialog class="chat-modal" id="modalCreateChannel" aria-labelledby="modalCreateChannelTitle">
  <form class="chat-modal-form" id="createChannelForm">
    <h2 class="chat-modal-title" id="modalCreateChannelTitle">Create a channel</h2>
    <p class="chat-modal-help">Channels are where your team talks. Use lowercase, dashes, and underscores — like #project-falcon.</p>

    <label class="chat-modal-field">
      <span class="chat-modal-label">Name</span>
      <div class="chat-modal-input-with-prefix">
        <span class="chat-modal-prefix">#</span>
        <input type="text" name="slug" id="createChannelSlug" maxlength="80" pattern="[a-z0-9_\-]+" required autofocus placeholder="e.g. project-falcon">
      </div>
    </label>

    <label class="chat-modal-field">
      <span class="chat-modal-label">Topic <span class="chat-modal-optional">(optional)</span></span>
      <input type="text" name="topic" maxlength="255" placeholder="A short one-liner for the channel header">
    </label>

    <label class="chat-modal-checkbox">
      <input type="checkbox" name="is_private" value="1">
      <span>Make this channel private (invite-only)</span>
    </label>

    <div class="chat-modal-err" id="createChannelErr" role="alert" hidden></div>

    <div class="chat-modal-actions">
      <button type="button" class="chat-btn" data-action="close-modal">Cancel</button>
      <button type="submit" class="chat-btn chat-btn-primary">Create channel</button>
    </div>
  </form>
</dialog>

<dialog class="chat-modal" id="modalBrowseChannels" aria-labelledby="modalBrowseChannelsTitle">
  <div class="chat-modal-form">
    <h2 class="chat-modal-title" id="modalBrowseChannelsTitle">Browse channels</h2>
    <div class="chat-modal-body" id="browseChannelsList">
      <div class="chat-modal-loading">Loading…</div>
    </div>
    <div class="chat-modal-actions">
      <button type="button" class="chat-btn" data-action="close-modal">Close</button>
    </div>
  </div>
</dialog>

</main>
</div>

<script>
  // Boot-time data for chat.js. CSRF token also lives in the meta tag so JS
  // can pick it up either way.
  window.CHAT_BOOT = {
    csrf: <?= json_encode(csrf_token()) ?>,
    currentUserId: <?= (int)$uid ?>,
    currentChannelId: <?= $currentChannel ? (int)$currentChannel['id'] : 'null' ?>,
    currentChannelSlug: <?= $currentChannel ? json_encode($currentChannel['slug']) : 'null' ?>,
    lastEventId: <?= (int)$lastEventId ?>
  };
</script>
<script>
  // Theme toggle — same as partials/footer.php.
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
