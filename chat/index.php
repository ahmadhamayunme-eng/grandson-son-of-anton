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

// Channels and DMs the user can see (sidebar lists).
$userChannels = chat_load_user_channels($ws, $uid);
$userDms      = chat_load_user_dms($ws, $uid);

// Max event id at page-render time — passed to the SSE client so the very
// first connect starts from "now" instead of replaying old events. Reconnects
// after that use the Last-Event-ID header.
$stmt = $pdo->prepare('SELECT IFNULL(MAX(id), 0) FROM chat_events WHERE workspace_id = ?');
$stmt->execute([$ws]);
$lastEventId = (int)$stmt->fetchColumn();

// Selected target: ?c=<slug> (channel) or ?d=<id> (DM). They're mutually
// exclusive — channel wins if both happen to be set.
$currentSlug = (string)($_GET['c'] ?? '');
$currentDmId = (int)($_GET['d'] ?? 0);
if ($currentSlug !== '' && $currentDmId > 0) $currentDmId = 0;

$currentChannel  = null;
$currentDm       = null;
$currentMessages = [];
$memberCount     = 0;
$isMember        = false;

if ($currentSlug !== '') {
  $ch = chat_load_channel_by_slug($ws, $currentSlug);
  if ($ch && chat_user_can_see_channel($ch, $uid, $ws)) {
    $currentChannel  = $ch;
    $isMember        = chat_user_is_channel_member((int)$ch['id'], $uid);
    $currentMessages = chat_decorate_messages(chat_load_recent_messages((int)$ch['id'], 50), $ws);
    $memberCount     = chat_channel_member_count((int)$ch['id']);
  }
} elseif ($currentDmId > 0) {
  $dm = chat_load_dm_by_id($ws, $uid, $currentDmId);
  if ($dm) {
    $currentDm       = $dm;
    $currentMessages = chat_decorate_messages(chat_load_dm_messages((int)$dm['id'], 50), $ws);
    $memberCount     = chat_dm_member_count((int)$dm['id']);
  }
}

// Default landing — first channel alphabetically, else first DM by recent
// activity. Falls through to the empty state if the user has neither.
if ($currentChannel === null && $currentDm === null && $currentSlug === '' && $currentDmId === 0) {
  if (!empty($userChannels)) {
    header('Location: /chat/?c=' . urlencode($userChannels[0]['slug']), true, 303);
    exit;
  } elseif (!empty($userDms)) {
    header('Location: /chat/?d=' . (int)$userDms[0]['id'], true, 303);
    exit;
  }
}

if ($currentChannel)   $pageTitle = '#' . $currentChannel['slug'];
elseif ($currentDm)    $pageTitle = chat_dm_display_name($currentDm);
else                   $pageTitle = 'Chat';
$activeKey = 'chat';

// Phase 8 — presence + unread counts.
// Refresh this user's presence on every page render so opening the tab
// counts as activity even before the JS ping fires.
chat_presence_ping($uid);

// Auto-mark-read the channel/DM the user is currently viewing.
if ($currentChannel && $isMember) chat_mark_channel_read((int)$currentChannel['id'], $uid);
if ($currentDm)                   chat_mark_dm_read((int)$currentDm['id'],    $uid);

// Compute unread badges + workspace presence map AFTER mark-read so the
// current view shows zero unread on first paint.
$unreadCounts = chat_unread_counts_for_user($ws, $uid);
$presenceMap  = chat_presence_map($ws);

// Phase 9 — user prefs (enter_to_send, notify_dm, notify_mention, timezone).
$userPrefs = chat_load_user_prefs($uid);

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
  <!-- Emoji picker (Phase 5). Web component; instantiated on demand by chat.js. -->
  <script type="module" src="https://cdn.jsdelivr.net/npm/emoji-picker-element@^1.21.0/index.js"></script>
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
      <button type="button" class="chat-ws-prefs" data-action="open-prefs" title="Preferences" aria-label="Preferences">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
      </button>
      <div class="chat-ws-name"><?= h($workspaceName) ?></div>
      <div class="chat-ws-user"><?= h($user['name']) ?> <span class="chat-ws-sep">·</span> <span class="chat-ws-role"><?= h($user['role_name'] ?? 'Member') ?></span></div>
    </header>

    <button type="button" class="chat-sidebar-search-trigger" data-action="open-search" aria-label="Search messages">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
      <span>Search messages…</span>
    </button>

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
            $active   = $currentChannel && (int)$currentChannel['id'] === (int)$c['id'];
            $unjoined = !((int)($c['is_member'] ?? 0));
            $unread   = (int)($unreadCounts['channels'][(int)$c['id']] ?? 0);
          ?>
            <a class="chat-row<?= $active ? ' active' : '' ?><?= $unjoined ? ' chat-row-unjoined' : '' ?><?= $unread > 0 ? ' chat-row-unread' : '' ?>"
               href="/chat/?c=<?= h(urlencode($c['slug'])) ?>"
               data-channel-id="<?= (int)$c['id'] ?>"
               title="<?= $unjoined ? 'You haven\'t joined this channel yet' : '' ?>">
              <span class="chat-row-prefix" aria-hidden="true"><?= $c['is_private'] ? '🔒' : '#' ?></span><span class="chat-row-name"><?= h($c['slug']) ?></span>
              <?php if ($unread > 0): ?><span class="chat-row-badge" data-unread-badge><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
        <a href="#" class="chat-section-link" data-action="open-browse-channels">Browse channels</a>
      </section>

      <section class="chat-section">
        <div class="chat-section-head">
          <span>Direct Messages</span>
          <button type="button" class="chat-section-add" title="New direct message" data-action="open-new-dm" aria-label="New direct message">+</button>
        </div>
        <?php if (empty($userDms)): ?>
          <div class="chat-section-empty">No direct messages yet</div>
        <?php else: ?>
          <?php foreach ($userDms as $d):
            $active = $currentDm && (int)$currentDm['id'] === (int)$d['id'];
            $name   = chat_dm_display_name($d);
            $unread = (int)($unreadCounts['dms'][(int)$d['id']] ?? 0);
            // 1-on-1 DMs: show presence dot for the other person.
            $otherIds = !empty($d['other_user_ids']) ? array_map('intval', explode(',', (string)$d['other_user_ids'])) : [];
            $showDot  = !((int)$d['is_group']) && count($otherIds) === 1;
            $otherId  = $showDot ? (int)$otherIds[0] : 0;
            $otherOnline = $showDot && !empty($presenceMap[$otherId]);
          ?>
            <a class="chat-row<?= $active ? ' active' : '' ?><?= $unread > 0 ? ' chat-row-unread' : '' ?>"
               href="/chat/?d=<?= (int)$d['id'] ?>"
               data-conversation-id="<?= (int)$d['id'] ?>"
               title="<?= h($name) ?>">
              <span class="chat-row-prefix chat-row-prefix-dm" aria-hidden="true">
                <?php if ($showDot): ?>
                  <span class="chat-presence-dot<?= $otherOnline ? ' chat-presence-online' : '' ?>" data-user-id="<?= $otherId ?>"></span>
                <?php else: ?>
                  @
                <?php endif; ?>
              </span><span class="chat-row-name"><?= h($name) ?></span>
              <?php if ($unread > 0): ?><span class="chat-row-badge" data-unread-badge><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
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
        <?php if ($isMember): ?>
          <button type="button" class="chat-header-kebab" data-action="open-channel-menu" data-channel-id="<?= (int)$currentChannel['id'] ?>" data-channel-slug="<?= h($currentChannel['slug']) ?>" title="Channel options" aria-label="Channel options">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg>
          </button>
        <?php endif; ?>
      </header>

      <div class="chat-msgs" id="chatMsgs" data-channel-id="<?= (int)$currentChannel['id'] ?>">
        <?php
          $emptyTitle = 'This is the beginning of #' . $currentChannel['slug'] . '.';
          $emptySub   = 'Be the first to say something.';
          include __DIR__ . '/includes/messages_partial.php';
        ?>
      </div>

      <?php if ($isMember): ?>
        <?php
          $composerId  = 'chatComposer';
          $inputId     = 'chatComposerInput';
          $pendingId   = 'chatComposerPending';
          $fileInputId = 'chatComposerFileInput';
          $placeholder = 'Message #' . $currentChannel['slug'];
          $channelId   = (int)$currentChannel['id'];
          $conversationId = null;
          $parentMessageId = null;
          include __DIR__ . '/includes/composer_partial.php';
        ?>
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
    <?php elseif ($currentDm): ?>
      <header class="chat-header">
        <h1 class="chat-header-title">
          <span class="chat-header-prefix" aria-hidden="true">@</span><?= h(chat_dm_display_name($currentDm)) ?>
        </h1>
        <div class="chat-header-meta">
          <?php if ((int)$currentDm['is_group']): ?>
            <span class="chat-header-members">Group · <?= (int)$memberCount ?> people</span>
          <?php else: ?>
            <span class="chat-header-members">Direct message</span>
          <?php endif; ?>
        </div>
      </header>

      <div class="chat-msgs" id="chatMsgs" data-conversation-id="<?= (int)$currentDm['id'] ?>">
        <?php
          $emptyTitle = 'This is the beginning of your conversation with ' . chat_dm_display_name($currentDm) . '.';
          $emptySub   = 'Say hi.';
          include __DIR__ . '/includes/messages_partial.php';
        ?>
      </div>

      <?php
        $composerId      = 'chatComposer';
        $inputId         = 'chatComposerInput';
        $pendingId       = 'chatComposerPending';
        $fileInputId     = 'chatComposerFileInput';
        $placeholder     = 'Message ' . chat_dm_display_name($currentDm);
        $channelId       = null;
        $conversationId  = (int)$currentDm['id'];
        $parentMessageId = null;
        include __DIR__ . '/includes/composer_partial.php';
      ?>
    <?php elseif (empty($userChannels) && empty($userDms)): ?>
      <div class="chat-empty">
        <div class="chat-empty-title">Welcome to Anton Chat.</div>
        <div class="chat-empty-sub">Get started by creating a channel, or sending someone a direct message.</div>
        <div class="chat-empty-actions">
          <button type="button" class="chat-cta" data-action="open-create-channel">Create a channel</button>
          <button type="button" class="chat-btn" data-action="open-new-dm">Start a DM</button>
        </div>
      </div>
    <?php else: ?>
      <div class="chat-empty">
        <div class="chat-empty-title">Pick a conversation.</div>
        <div class="chat-empty-sub">Choose a channel or DM in the sidebar, or browse all public channels.</div>
        <div class="chat-empty-actions">
          <button type="button" class="chat-cta" data-action="open-browse-channels">Browse channels</button>
          <button type="button" class="chat-btn" data-action="open-new-dm">Start a DM</button>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <!-- Thread panel — slides in on the right when a user clicks "Reply in
       thread" or a "N replies" count. JS hydrates it via the messages.php
       ?action=list&parent_message_id=... endpoint. -->
  <aside class="chat-thread" id="chatThread" hidden>
    <header class="chat-thread-header">
      <h2 class="chat-thread-title">Thread</h2>
      <button type="button" class="chat-thread-close" data-action="close-thread" aria-label="Close thread">×</button>
    </header>
    <div class="chat-thread-list" id="chatThreadList">
      <div class="chat-modal-loading">Loading…</div>
    </div>
    <?php
      $composerId      = 'chatThreadComposer';
      $inputId         = 'chatThreadInput';
      $pendingId       = 'chatThreadPending';
      $fileInputId     = 'chatThreadFileInput';
      $placeholder     = 'Reply…';
      $channelId       = null;
      $conversationId  = null;
      $parentMessageId = null;  // JS sets the data attribute at runtime when opening a thread.
      include __DIR__ . '/includes/composer_partial.php';
    ?>
  </aside>
</div>

<!-- @mention autocomplete — one popup, JS positions it above the active
     composer (main or thread). Page-level so it isn't clipped by the
     composer's overflow. -->
<div class="chat-mention-popup" id="chatMentionPopup" hidden></div>

<!-- Emoji picker holder — JS lazily instantiates an <emoji-picker> inside
     this container when the user opens the picker, and positions it near
     the clicked "Add reaction" button. -->
<div class="chat-emoji-popup" id="chatEmojiPopup" hidden></div>

<!-- Phase 9 message-action menu (Edit / Delete) — shared, JS positions it
     near the kebab the user clicked. -->
<div class="chat-msg-menu" id="chatMsgMenu" hidden>
  <button type="button" data-action="edit-message">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
    Edit message
  </button>
  <button type="button" data-action="delete-message" class="chat-msg-menu-danger">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg>
    Delete message
  </button>
</div>

<!-- Channel header menu (Leave channel) — shared, JS positions near the kebab. -->
<div class="chat-msg-menu" id="chatChannelMenu" hidden>
  <button type="button" data-action="leave-channel" class="chat-msg-menu-danger">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
    Leave channel
  </button>
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

<!-- Phase 9 — Cmd/Ctrl+K quick channel/DM switcher. -->
<dialog class="chat-modal chat-modal-switcher" id="modalSwitcher" aria-labelledby="modalSwitcherTitle">
  <div class="chat-modal-form chat-modal-form-tight">
    <h2 class="chat-modal-title chat-sr-only" id="modalSwitcherTitle">Jump to a channel or DM</h2>
    <input type="text" id="switcherInput" class="chat-switcher-input" placeholder="Jump to a channel or DM…" autocomplete="off">
    <div class="chat-switcher-results" id="switcherResults"></div>
  </div>
</dialog>

<!-- Phase 9 — Preferences modal. -->
<dialog class="chat-modal" id="modalPrefs" aria-labelledby="modalPrefsTitle">
  <form class="chat-modal-form" id="prefsForm">
    <h2 class="chat-modal-title" id="modalPrefsTitle">Preferences</h2>

    <label class="chat-modal-checkbox">
      <input type="checkbox" name="enter_to_send" value="1" <?= (int)$userPrefs['enter_to_send'] ? 'checked' : '' ?>>
      <span><strong>Enter</strong> sends the message <span class="chat-modal-optional">(off = use <strong>Ctrl/⌘+Enter</strong> to send instead)</span></span>
    </label>

    <label class="chat-modal-checkbox">
      <input type="checkbox" name="notify_dm" value="1" <?= (int)$userPrefs['notify_dm'] ? 'checked' : '' ?>>
      <span>Browser notification on new direct messages</span>
    </label>

    <label class="chat-modal-checkbox">
      <input type="checkbox" name="notify_mention" value="1" <?= (int)$userPrefs['notify_mention'] ? 'checked' : '' ?>>
      <span>Browser notification on @mentions</span>
    </label>

    <div class="chat-modal-err" id="prefsErr" role="alert" hidden></div>

    <div class="chat-modal-actions">
      <button type="button" class="chat-btn" data-action="close-modal">Cancel</button>
      <button type="submit" class="chat-btn chat-btn-primary">Save</button>
    </div>
  </form>
</dialog>

<dialog class="chat-modal chat-modal-wide chat-modal-search-dialog" id="modalSearch" aria-labelledby="modalSearchTitle">
  <div class="chat-modal-form">
    <h2 class="chat-modal-title" id="modalSearchTitle">Search messages</h2>

    <div class="chat-search-controls">
      <input type="text" id="searchInput" class="chat-modal-search" placeholder="Type to search…" autocomplete="off">

      <div class="chat-search-filters">
        <label class="chat-search-filter">
          <span>From</span>
          <select id="searchFrom">
            <option value="">Anyone</option>
          </select>
        </label>
        <label class="chat-search-filter">
          <span>In</span>
          <select id="searchIn">
            <option value="">Everywhere</option>
          </select>
        </label>
        <label class="chat-search-filter">
          <span>After</span>
          <input type="date" id="searchAfter">
        </label>
        <label class="chat-search-filter">
          <span>Before</span>
          <input type="date" id="searchBefore">
        </label>
        <label class="chat-search-filter chat-search-filter-checkbox">
          <input type="checkbox" id="searchHasFile">
          <span>Has file</span>
        </label>
      </div>
    </div>

    <div class="chat-search-results" id="searchResults">
      <div class="chat-search-empty">Start typing or pick a filter to search across every channel and DM you can see.</div>
    </div>

    <div class="chat-modal-actions">
      <button type="button" class="chat-btn" data-action="close-modal">Close</button>
    </div>
  </div>
</dialog>

<dialog class="chat-modal chat-modal-wide" id="modalNewDm" aria-labelledby="modalNewDmTitle">
  <div class="chat-modal-form">
    <h2 class="chat-modal-title" id="modalNewDmTitle">New direct message</h2>
    <p class="chat-modal-help">Pick one person for a private 1-on-1, or several for a group (up to 8 total including you).</p>

    <input type="text" id="newDmSearch" class="chat-modal-search" placeholder="Search people…" autocomplete="off">

    <div class="chat-modal-body" id="newDmPeopleList">
      <div class="chat-modal-loading">Loading…</div>
    </div>

    <div class="chat-modal-footer-meta" id="newDmSelectedCount">No one selected</div>

    <div class="chat-modal-err" id="newDmErr" role="alert" hidden></div>

    <div class="chat-modal-actions">
      <button type="button" class="chat-btn" data-action="close-modal">Cancel</button>
      <button type="button" class="chat-btn chat-btn-primary" id="newDmStartBtn" disabled>Start DM</button>
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
    currentConversationId: <?= $currentDm ? (int)$currentDm['id'] : 'null' ?>,
    lastEventId: <?= (int)$lastEventId ?>,
    unreadCounts: <?= json_encode($unreadCounts) ?>,
    presenceMap:  <?= json_encode($presenceMap) ?>,
    prefs:        <?= json_encode($userPrefs) ?>
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
