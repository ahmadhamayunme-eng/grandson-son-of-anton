<?php
// Anton Chat — main entry point (Phase 10 design migration).
//
// Embedded mode: the AntonX sidebar stays on the far left via
// partials/nav.php. Inside AntonX's .main we render the design's
// top-bar + sidebar + main + (optional) details panel.
//
// URL routing — server picks what to render:
//   /chat/?c=<slug>            channel, Messages tab (default)
//   /chat/?c=<slug>&tab=threads channel threads
//   /chat/?c=<slug>&tab=pinned  channel pinned
//   /chat/?d=<id>              DM
//   /chat/?view=threads        all threads I'm in (workspace-wide)
//   /chat/?view=inbox          mentions + DMs

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

$stmt = $pdo->prepare('SELECT name FROM workspaces WHERE id = ?');
$stmt->execute([$ws]);
$workspaceName = (string)($stmt->fetchColumn() ?: 'Workspace');

$userChannels = chat_load_user_channels($ws, $uid);
$userDms      = chat_load_user_dms($ws, $uid);

$stmt = $pdo->prepare('SELECT IFNULL(MAX(id), 0) FROM chat_events WHERE workspace_id = ?');
$stmt->execute([$ws]);
$lastEventId = (int)$stmt->fetchColumn();

$currentSlug = (string)($_GET['c'] ?? '');
$currentDmId = (int)($_GET['d'] ?? 0);
$globalView  = (string)($_GET['view'] ?? '');
$channelTab  = (string)($_GET['tab'] ?? 'messages');
if ($currentSlug !== '' && $currentDmId > 0) $currentDmId = 0;
$validTabs   = ['messages', 'threads', 'pinned'];
if (!in_array($channelTab, $validTabs, true)) $channelTab = 'messages';
$validViews  = ['threads', 'inbox', 'saved'];
if ($globalView !== '' && !in_array($globalView, $validViews, true)) $globalView = '';

$currentChannel  = null;
$currentDm       = null;
$currentMessages = [];
$memberCount     = 0;
$isMember        = false;
$pinnedMessages  = [];
$channelThreads  = [];
$globalThreads   = [];
$inboxMessages   = [];
$savedMessages   = [];

if ($globalView === '') {
  if ($currentSlug !== '') {
    $ch = chat_load_channel_by_slug($ws, $currentSlug);
    if ($ch && chat_user_can_see_channel($ch, $uid, $ws)) {
      $currentChannel = $ch;
      $isMember       = chat_user_is_channel_member((int)$ch['id'], $uid);
      $memberCount    = chat_channel_member_count((int)$ch['id']);
      $cid            = (int)$ch['id'];
      if ($channelTab === 'pinned') {
        $pinnedMessages = chat_decorate_messages_phase10(
          chat_load_pinned_messages_for_channel($cid, $ws), $ws, $uid, $cid
        );
      } elseif ($channelTab === 'threads') {
        // Threads inside this specific channel.
        $stmt = $pdo->prepare(
          "SELECT DISTINCT r.parent_message_id AS pid
           FROM chat_messages r
           JOIN chat_messages p ON p.id = r.parent_message_id
           WHERE r.parent_message_id IS NOT NULL
             AND r.deleted_at IS NULL
             AND p.channel_id = ?
             AND (r.user_id = ? OR p.user_id = ?)
           ORDER BY (SELECT MAX(id) FROM chat_messages rr
                     WHERE rr.parent_message_id = r.parent_message_id) DESC
           LIMIT 50"
        );
        $stmt->execute([$cid, $uid, $uid]);
        $pids = array_column($stmt->fetchAll(), 'pid');
        if ($pids) {
          $ph = implode(',', array_fill(0, count($pids), '?'));
          $stmt = $pdo->prepare(
            "SELECT m.id, m.user_id, m.content, m.created_at, m.edited_at, m.deleted_at,
                    m.parent_message_id, m.reply_to_message_id, m.channel_id, m.conversation_id,
                    u.name AS author_name
             FROM chat_messages m JOIN users u ON u.id = m.user_id
             WHERE m.id IN ($ph)"
          );
          $stmt->execute($pids);
          $byId = [];
          foreach ($stmt->fetchAll() as $r) $byId[(int)$r['id']] = $r;
          $rows = [];
          foreach ($pids as $pid) if (isset($byId[(int)$pid])) $rows[] = $byId[(int)$pid];
          $channelThreads = chat_decorate_messages_phase10($rows, $ws, $uid, $cid);
        }
      } else {
        $currentMessages = chat_decorate_messages_phase10(
          chat_load_recent_messages($cid, 50), $ws, $uid, $cid
        );
      }
    }
  } elseif ($currentDmId > 0) {
    $dm = chat_load_dm_by_id($ws, $uid, $currentDmId);
    if ($dm) {
      $currentDm       = $dm;
      $memberCount     = chat_dm_member_count((int)$dm['id']);
      $currentMessages = chat_decorate_messages_phase10(
        chat_load_dm_messages((int)$dm['id'], 50), $ws, $uid, null
      );
    }
  }
} elseif ($globalView === 'threads') {
  $globalThreads = chat_load_user_threads($ws, $uid, 50);
} elseif ($globalView === 'inbox') {
  $inboxMessages = chat_load_user_inbox($ws, $uid, 50);
} elseif ($globalView === 'saved') {
  // Initial saved-messages render — JS could refresh via the API too.
  $stmt = $pdo->prepare(
    "SELECT m.id, m.user_id, m.content, m.created_at, m.edited_at, m.deleted_at,
            m.parent_message_id, m.channel_id, m.conversation_id, m.reply_to_message_id,
            u.name AS author_name
     FROM chat_saved_messages s
     JOIN chat_messages m ON m.id = s.message_id
     JOIN users u         ON u.id = m.user_id
     WHERE s.user_id = ? AND m.workspace_id = ? AND m.deleted_at IS NULL
     ORDER BY s.saved_at DESC LIMIT 50"
  );
  $stmt->execute([$uid, $ws]);
  $savedMessages = chat_decorate_messages_phase10($stmt->fetchAll(), $ws, $uid, null);
}

// Default landing if nothing specified.
if ($currentChannel === null && $currentDm === null && $currentSlug === '' && $currentDmId === 0 && $globalView === '') {
  if (!empty($userChannels)) {
    header('Location: /chat/?c=' . urlencode($userChannels[0]['slug']), true, 303);
    exit;
  } elseif (!empty($userDms)) {
    header('Location: /chat/?d=' . (int)$userDms[0]['id'], true, 303);
    exit;
  }
}

// Update presence + mark current view as read.
chat_presence_ping($uid);
if ($currentChannel && $isMember) chat_mark_channel_read((int)$currentChannel['id'], $uid);
if ($currentDm)                   chat_mark_dm_read((int)$currentDm['id'], $uid);

$unreadCounts  = chat_unread_counts_for_user($ws, $uid);
$mentionCounts = chat_mention_counts_for_user($ws, $uid);
$presenceMap   = chat_presence_state_map($ws);
$userPrefs     = chat_load_user_prefs($uid);

// Inbox unread = total unread DMs + mention count across channels.
$inboxBadge = array_sum($unreadCounts['dms']) + array_sum($mentionCounts);

// All channels visible (for the channel-header member stack & details panel).
$channelMembers = [];
if ($currentChannel) {
  $stmt = $pdo->prepare(
    'SELECT u.id, u.name, u.role_id, r.name AS role_name
     FROM chat_channel_members m
     JOIN users u ON u.id = m.user_id
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE m.channel_id = ?
     ORDER BY u.name ASC
     LIMIT 50'
  );
  $stmt->execute([(int)$currentChannel['id']]);
  $channelMembers = $stmt->fetchAll();
}

// Page title.
if ($currentChannel)      $pageTitle = '#' . $currentChannel['slug'];
elseif ($currentDm)       $pageTitle = chat_dm_display_name($currentDm);
elseif ($globalView !== '') $pageTitle = ucfirst($globalView);
else                      $pageTitle = 'Chat';
$activeKey = 'chat';

$_antonCssV = @filemtime(__DIR__ . '/../styles/anton.css')   ?: '1';
$_chatCssV  = @filemtime(__DIR__ . '/assets/css/chat.css')   ?: '1';
$_chatJsV   = @filemtime(__DIR__ . '/assets/js/chat.js')     ?: '1';

// Live tabs count (best-effort).
$channelTabCounts = ['messages' => 0, 'threads' => 0, 'pinned' => 0];
if ($currentChannel) {
  $cid = (int)$currentChannel['id'];
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM chat_pinned_messages WHERE channel_id = ?');
  $stmt->execute([$cid]);
  $channelTabCounts['pinned'] = (int)$stmt->fetchColumn();
  $stmt = $pdo->prepare(
    'SELECT COUNT(DISTINCT m.parent_message_id) FROM chat_messages m
     JOIN chat_messages p ON p.id = m.parent_message_id
     WHERE m.parent_message_id IS NOT NULL AND m.deleted_at IS NULL AND p.channel_id = ?'
  );
  $stmt->execute([$cid]);
  $channelTabCounts['threads'] = (int)$stmt->fetchColumn();
}
?><!doctype html>
<html lang="en">
<head>
  <base href="/">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
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
  <title>anton x — <?= h($pageTitle) ?></title>
  <link rel="icon" type="image/png" href="partials/antonx-favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles/anton.css?v=<?= h((string)$_antonCssV) ?>">
  <link rel="stylesheet" href="chat/assets/css/chat.css?v=<?= h((string)$_chatCssV) ?>">
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
<div class="cx-app">

  <!-- ============ TOP UTILITY BAR ============ -->
  <div class="top-bar">
    <button class="icon-btn" data-action="toggle-sidebar" title="Toggle sidebar" aria-label="Toggle sidebar">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg>
    </button>
    <button class="icon-btn" onclick="history.back()" title="Back">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
    </button>
    <button class="icon-btn" onclick="history.forward()" title="Forward">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </button>
    <button class="icon-btn" data-action="open-activity" title="Recent activity">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/></svg>
    </button>
    <div class="top-bar-center">
      <button class="top-bar-search" data-action="open-cmdk">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
        <span>Search <?= h($workspaceName) ?></span>
        <span class="kbd">⌘K</span>
      </button>
    </div>
    <button class="icon-btn" data-action="open-prefs" title="Preferences">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 015.8 1c0 2-3 3-3 3M12 17h.01"/></svg>
    </button>
    <button class="icon-btn" title="Notifications">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 16v-5a6 6 0 10-12 0v5l-2 3h16l-2-3z"/><path d="M9 20a3 3 0 006 0"/></svg>
    </button>
  </div>

  <!-- ============ SIDEBAR ============ -->
  <aside class="cx-sidebar">
    <div class="ws-header">
      <div class="ws-name"><span><?= h($workspaceName) ?></span></div>
      <div class="ws-meta">
        <span class="dot-presence presence-active" data-user-id="<?= (int)$uid ?>"></span>
        <span><?= h($user['name']) ?> · <span class="ws-role"><?= h($user['role_name'] ?? 'Member') ?></span></span>
      </div>
    </div>

    <div class="side-search">
      <button class="side-search-input" data-action="open-cmdk">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
        <span>Jump to…</span>
        <span class="kbd">⌘K</span>
      </button>
    </div>

    <div class="side-scroll">
      <div class="side-section">
        <a class="side-item<?= $globalView === 'threads' ? ' active' : '' ?>" href="/chat/?view=threads">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18M3 9h14M3 14h10"/><path d="M14 17h7M21 20l-3-3 3-3"/></svg>
          <span class="name">Threads</span>
        </a>
        <a class="side-item<?= $globalView === 'inbox' ? ' active' : '' ?><?= $inboxBadge > 0 ? ' unread' : '' ?>" href="/chat/?view=inbox">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 13l3-9h12l3 9v6a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><path d="M3 13h5l2 3h4l2-3h5"/></svg>
          <span class="name">Inbox</span>
          <?php if ($inboxBadge > 0): ?><span class="badge"><?= $inboxBadge > 99 ? '99+' : $inboxBadge ?></span><?php endif; ?>
        </a>
        <a class="side-item<?= $globalView === 'saved' ? ' active' : '' ?>" href="/chat/?view=saved">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v18l-6-4-6 4z"/></svg>
          <span class="name">Saved</span>
        </a>
      </div>

      <div class="side-section">
        <div class="side-section-head">
          <button class="label" data-action="toggle-section" data-section="channels">
            <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
            Channels
          </button>
          <span class="spacer"></span>
          <button class="add" title="Create channel" data-action="open-create-channel" aria-label="Create channel">
            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
          </button>
        </div>
        <?php if (empty($userChannels)): ?>
          <div class="side-empty">No channels yet</div>
        <?php else: ?>
          <?php foreach ($userChannels as $c):
            $active   = !$globalView && $currentChannel && (int)$currentChannel['id'] === (int)$c['id'];
            $unjoined = !((int)($c['is_member'] ?? 0));
            $unread   = (int)($unreadCounts['channels'][(int)$c['id']] ?? 0);
            $mentions = (int)($mentionCounts[(int)$c['id']] ?? 0);
          ?>
            <a class="side-item<?= $active ? ' active' : '' ?><?= $unjoined ? ' unjoined' : '' ?><?= ($unread > 0 || $mentions > 0) ? ' unread' : '' ?>"
               href="/chat/?c=<?= h(urlencode($c['slug'])) ?>"
               data-channel-id="<?= (int)$c['id'] ?>">
              <?php if ((int)$c['is_private']): ?>
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>
              <?php else: ?>
                <span class="ch-glyph">#</span>
              <?php endif; ?>
              <span class="name"><?= h($c['slug']) ?></span>
              <?php if ($mentions > 0): ?>
                <span class="badge mention"><?= $mentions ?></span>
              <?php elseif ($unread > 0): ?>
                <span class="badge"><?= $unread > 99 ? '99+' : $unread ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
        <button class="side-item add-row" data-action="open-browse-channels">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
          <span class="name">Add channels</span>
        </button>
      </div>

      <div class="side-section">
        <div class="side-section-head">
          <button class="label" data-action="toggle-section" data-section="dms">
            <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
            Direct messages
          </button>
          <span class="spacer"></span>
          <button class="add" title="New direct message" data-action="open-new-dm" aria-label="New direct message">
            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
          </button>
        </div>
        <?php if (empty($userDms)): ?>
          <div class="side-empty">No direct messages yet</div>
        <?php else: ?>
          <?php foreach ($userDms as $d):
            $active = !$globalView && $currentDm && (int)$currentDm['id'] === (int)$d['id'];
            $name   = chat_dm_display_name($d);
            $unread = (int)($unreadCounts['dms'][(int)$d['id']] ?? 0);
            $otherIds = !empty($d['other_user_ids']) ? array_map('intval', explode(',', (string)$d['other_user_ids'])) : [];
            $isOneOnOne = !((int)$d['is_group']) && count($otherIds) === 1;
            $otherId  = $isOneOnOne ? (int)$otherIds[0] : 0;
            $state    = $otherId ? ($presenceMap[$otherId] ?? 'offline') : 'offline';
          ?>
            <a class="side-item<?= $active ? ' active' : '' ?><?= $unread > 0 ? ' unread' : '' ?>"
               href="/chat/?d=<?= (int)$d['id'] ?>"
               data-conversation-id="<?= (int)$d['id'] ?>"
               title="<?= h($name) ?>">
              <?php if ($isOneOnOne): ?>
                <span class="dot-presence presence-<?= h($state) ?>" data-user-id="<?= $otherId ?>"></span>
              <?php else: ?>
                <span class="dot-presence" style="background:var(--fg-3)"></span>
              <?php endif; ?>
              <span class="name"><?= h($name) ?></span>
              <?php if ($unread > 0): ?><span class="badge"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
        <button class="side-item add-row" data-action="open-new-dm">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
          <span class="name">New message</span>
        </button>
      </div>
    </div>
  </aside>

  <!-- ============ MAIN CONTENT ============ -->
  <main class="cx-main">
  <?php
    // Routing fork — single composer at bottom for channel/DM views.
    if ($globalView === 'threads'):
  ?>
    <header class="ch-header">
      <h1 class="ch-title-static">Threads</h1>
      <span class="spacer"></span>
    </header>
    <div class="thread-scroll" id="chatMsgs">
      <?php if (empty($globalThreads)): ?>
        <div class="cx-empty">
          <div class="cx-empty-title">No threads yet.</div>
          <div class="cx-empty-sub">Threads you start or reply to will appear here.</div>
        </div>
      <?php else:
        $currentMessages = $globalThreads;
        $emptyTitle = '';
        $emptySub   = '';
        include __DIR__ . '/includes/messages_partial.php';
      endif; ?>
    </div>
  <?php elseif ($globalView === 'inbox'): ?>
    <header class="ch-header">
      <h1 class="ch-title-static">Inbox</h1>
      <span class="spacer"></span>
    </header>
    <div class="thread-scroll" id="chatMsgs">
      <?php if (empty($inboxMessages)): ?>
        <div class="cx-empty">
          <div class="cx-empty-title">Inbox zero.</div>
          <div class="cx-empty-sub">@mentions and direct messages addressed to you will land here.</div>
        </div>
      <?php else:
        $currentMessages = $inboxMessages;
        include __DIR__ . '/includes/messages_partial.php';
      endif; ?>
    </div>
  <?php elseif ($globalView === 'saved'): ?>
    <header class="ch-header">
      <h1 class="ch-title-static">Saved</h1>
      <span class="spacer"></span>
    </header>
    <div class="thread-scroll" id="chatMsgs">
      <?php if (empty($savedMessages)): ?>
        <div class="cx-empty">
          <div class="cx-empty-title">Nothing saved yet.</div>
          <div class="cx-empty-sub">Tap the bookmark on any message to keep it for later.</div>
        </div>
      <?php else:
        $currentMessages = $savedMessages;
        include __DIR__ . '/includes/messages_partial.php';
      endif; ?>
    </div>
  <?php elseif ($currentChannel): ?>
    <header class="ch-header">
      <button class="ch-title" data-action="open-channel-menu" data-channel-id="<?= (int)$currentChannel['id'] ?>" data-channel-slug="<?= h($currentChannel['slug']) ?>">
        <span class="glyph"><?= $currentChannel['is_private'] ? '🔒' : '#' ?></span>
        <span><?= h($currentChannel['slug']) ?></span>
        <span class="chev"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></span>
      </button>
      <span class="divider"></span>
      <button class="members" data-action="toggle-details">
        <span class="stack">
          <?php
            $stackUsers = array_slice($channelMembers, 0, 3);
            foreach ($stackUsers as $mu):
              $initials = function_exists('user_initials') ? user_initials($mu['name']) : strtoupper(substr($mu['name'], 0, 2));
              $stackColor = ((int)$mu['id']) % 8;
          ?>
            <span class="stack-av" data-av-color="<?= $stackColor ?>"><?= h($initials) ?></span>
          <?php endforeach; ?>
        </span>
        <span><?= (int)$memberCount ?></span>
      </button>
      <span class="spacer"></span>
      <a class="icon-btn<?= $channelTab === 'pinned' ? ' active' : '' ?>" href="/chat/?c=<?= h(urlencode($currentChannel['slug'])) ?>&tab=pinned" title="Pinned">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2l7 7-3 1-5 5-1 5-4-4-6 6 6-6-4-4 5-1 5-5z"/></svg>
      </a>
      <span class="divider"></span>
      <button class="icon-btn" data-action="toggle-details" title="Channel details">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v.01M12 11v5"/></svg>
      </button>
    </header>

    <div class="ch-tabs">
      <?php foreach (['messages', 'threads', 'pinned'] as $tabKey):
        $tabLabel = ucfirst($tabKey);
        $href = '/chat/?c=' . urlencode($currentChannel['slug']) . ($tabKey === 'messages' ? '' : '&tab=' . $tabKey);
        $isActive = $channelTab === $tabKey;
        $count = $channelTabCounts[$tabKey] ?? 0;
      ?>
        <a class="ch-tab<?= $isActive ? ' active' : '' ?>" href="<?= h($href) ?>">
          <?= h($tabLabel) ?>
          <?php if ($tabKey !== 'messages' && $count > 0): ?><span class="count"><?= $count ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($channelTab === 'pinned'): ?>
      <div class="thread-scroll" id="chatMsgs">
        <?php if (empty($pinnedMessages)): ?>
          <div class="cx-empty">
            <div class="cx-empty-title">Nothing pinned in #<?= h($currentChannel['slug']) ?>.</div>
            <div class="cx-empty-sub">Tap the pin on any message to anchor it here.</div>
          </div>
        <?php else:
          $currentMessages = $pinnedMessages;
          include __DIR__ . '/includes/messages_partial.php';
        endif; ?>
      </div>
    <?php elseif ($channelTab === 'threads'): ?>
      <div class="thread-scroll" id="chatMsgs">
        <?php if (empty($channelThreads)): ?>
          <div class="cx-empty">
            <div class="cx-empty-title">No threads in #<?= h($currentChannel['slug']) ?>.</div>
            <div class="cx-empty-sub">Reply in thread on any message and it'll show up here.</div>
          </div>
        <?php else:
          $currentMessages = $channelThreads;
          include __DIR__ . '/includes/messages_partial.php';
        endif; ?>
      </div>
    <?php else: ?>
      <div class="thread-scroll" id="chatMsgs" data-channel-id="<?= (int)$currentChannel['id'] ?>">
        <?php
          $emptyTitle = 'This is the beginning of #' . $currentChannel['slug'] . '.';
          $emptySub   = 'Be the first to say something.';
          include __DIR__ . '/includes/messages_partial.php';
        ?>
      </div>
      <?php if ($isMember): ?>
        <?php
          $composerTarget = ['channel_id' => (int)$currentChannel['id'], 'placeholder' => 'Message #' . $currentChannel['slug']];
          include __DIR__ . '/includes/composer_partial.php';
        ?>
      <?php else: ?>
        <div class="chat-join-prompt">
          You're not in <span class="chat-join-channel">#<?= h($currentChannel['slug']) ?></span>.
          <?php if (empty($currentChannel['is_private'])): ?>
            <button type="button" class="cx-btn cx-btn-primary" data-action="join-channel" data-channel-id="<?= (int)$currentChannel['id'] ?>" data-channel-slug="<?= h($currentChannel['slug']) ?>">Join channel</button>
          <?php else: ?>
            <span class="chat-join-note">It's a private channel — ask a member to invite you.</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

  <?php elseif ($currentDm): ?>
    <header class="ch-header">
      <button class="ch-title">
        <span class="glyph">@</span>
        <span><?= h(chat_dm_display_name($currentDm)) ?></span>
      </button>
      <span class="divider"></span>
      <span class="ch-header-meta">
        <?= (int)$currentDm['is_group'] ? ('Group · ' . (int)$memberCount . ' people') : 'Direct message' ?>
      </span>
      <span class="spacer"></span>
    </header>
    <div class="thread-scroll" id="chatMsgs" data-conversation-id="<?= (int)$currentDm['id'] ?>">
      <?php
        $emptyTitle = 'This is the beginning of your conversation with ' . chat_dm_display_name($currentDm) . '.';
        $emptySub   = 'Say hi.';
        include __DIR__ . '/includes/messages_partial.php';
      ?>
    </div>
    <?php
      $composerTarget = ['conversation_id' => (int)$currentDm['id'], 'placeholder' => 'Message ' . chat_dm_display_name($currentDm)];
      include __DIR__ . '/includes/composer_partial.php';
    ?>

  <?php elseif (empty($userChannels) && empty($userDms)): ?>
    <div class="cx-empty">
      <div class="cx-empty-title">Welcome to Anton Chat.</div>
      <div class="cx-empty-sub">Get started by creating a channel, or sending someone a direct message.</div>
      <div class="cx-empty-actions">
        <button type="button" class="cx-btn cx-btn-primary" data-action="open-create-channel">Create a channel</button>
        <button type="button" class="cx-btn" data-action="open-new-dm">Start a DM</button>
      </div>
    </div>
  <?php else: ?>
    <div class="cx-empty">
      <div class="cx-empty-title">Pick a conversation.</div>
      <div class="cx-empty-sub">Choose a channel or DM in the sidebar, or browse all public channels.</div>
      <div class="cx-empty-actions">
        <button type="button" class="cx-btn cx-btn-primary" data-action="open-browse-channels">Browse channels</button>
        <button type="button" class="cx-btn" data-action="open-new-dm">Start a DM</button>
      </div>
    </div>
  <?php endif; ?>
  </main>

  <!-- ============ DETAILS PANEL ============ -->
  <?php if ($currentChannel): ?>
    <?php
      $detailsChannel = $currentChannel;
      $detailsMembers = $channelMembers;
      $detailsMemberCount = $memberCount;
      include __DIR__ . '/includes/details_partial.php';
    ?>
  <?php else: ?>
    <aside class="details" hidden></aside>
  <?php endif; ?>

  <!-- ============ THREAD PANEL ============ -->
  <aside class="cx-thread" id="chatThread" hidden>
    <header class="cx-thread-header">
      <h2 class="cx-thread-title">Thread</h2>
      <button class="icon-btn" data-action="close-thread" aria-label="Close thread">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </header>
    <div class="cx-thread-list" id="chatThreadList">
      <div class="cx-modal-loading">Loading…</div>
    </div>
    <?php
      $composerTarget = ['placeholder' => 'Reply…', 'thread' => true];
      $composerId     = 'chatThreadComposer';
      $composerInputId= 'chatThreadInput';
      $composerPendId = 'chatThreadPending';
      $composerFileId = 'chatThreadFileInput';
      include __DIR__ . '/includes/composer_partial.php';
    ?>
  </aside>

</div><!-- /.cx-app -->

<!-- ===== Floating popups (mention, emoji, msg menu, channel menu) ===== -->
<div class="cx-mention-popup" id="chatMentionPopup" hidden></div>
<div class="cx-emoji-popup" id="chatEmojiPopup" hidden></div>
<div class="cx-popmenu" id="chatMsgMenu" hidden>
  <button type="button" data-action="edit-message"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit message</button>
  <button type="button" data-action="delete-message" class="cx-popmenu-danger"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg> Delete message</button>
</div>
<div class="cx-popmenu" id="chatChannelMenu" hidden>
  <button type="button" data-action="leave-channel" class="cx-popmenu-danger"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Leave channel</button>
</div>

<!-- ===== Modals — re-styled to design tokens ===== -->
<dialog class="cx-modal" id="modalCmdk" aria-label="Quick switcher">
  <div class="cx-modal-form cmdk-frame">
    <div class="cmdk-input">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
      <input id="cmdkInput" autocomplete="off" placeholder="Jump to a channel, DM, or run a command…">
      <span class="kbd">ESC</span>
    </div>
    <div class="cmdk-list" id="cmdkResults"></div>
  </div>
</dialog>

<dialog class="cx-modal" id="modalPrefs" aria-labelledby="modalPrefsTitle">
  <form class="cx-modal-form" id="prefsForm">
    <h2 class="cx-modal-title" id="modalPrefsTitle">Preferences</h2>
    <label class="cx-modal-checkbox">
      <input type="checkbox" name="enter_to_send" value="1" <?= (int)$userPrefs['enter_to_send'] ? 'checked' : '' ?>>
      <span><strong>Enter</strong> sends — uncheck to send with <strong>Ctrl/⌘+Enter</strong></span>
    </label>
    <label class="cx-modal-checkbox">
      <input type="checkbox" name="notify_dm" value="1" <?= (int)$userPrefs['notify_dm'] ? 'checked' : '' ?>>
      <span>Browser notification on new DMs</span>
    </label>
    <label class="cx-modal-checkbox">
      <input type="checkbox" name="notify_mention" value="1" <?= (int)$userPrefs['notify_mention'] ? 'checked' : '' ?>>
      <span>Browser notification on @mentions</span>
    </label>
    <label class="cx-modal-checkbox">
      <input type="checkbox" name="dnd" value="1" <?= (int)$userPrefs['dnd'] ? 'checked' : '' ?>>
      <span>Do Not Disturb — pause notifications, show others your status</span>
    </label>
    <div class="cx-modal-err" id="prefsErr" role="alert" hidden></div>
    <div class="cx-modal-actions">
      <button type="button" class="cx-btn" data-action="close-modal">Cancel</button>
      <button type="submit" class="cx-btn cx-btn-primary">Save</button>
    </div>
  </form>
</dialog>

<dialog class="cx-modal" id="modalCreateChannel" aria-labelledby="modalCreateChannelTitle">
  <form class="cx-modal-form" id="createChannelForm">
    <h2 class="cx-modal-title" id="modalCreateChannelTitle">Create a channel</h2>
    <p class="cx-modal-help">Channels are where your team talks. Use lowercase, dashes, and underscores — like #project-falcon.</p>
    <label class="cx-modal-field">
      <span class="cx-modal-label">Name</span>
      <div class="cx-modal-input-with-prefix">
        <span class="cx-modal-prefix">#</span>
        <input type="text" name="slug" id="createChannelSlug" maxlength="80" pattern="[a-z0-9_\-]+" required autofocus placeholder="e.g. project-falcon">
      </div>
    </label>
    <label class="cx-modal-field">
      <span class="cx-modal-label">Topic <span class="cx-modal-optional">(optional)</span></span>
      <input type="text" name="topic" maxlength="255" placeholder="A short one-liner for the channel header">
    </label>
    <label class="cx-modal-checkbox">
      <input type="checkbox" name="is_private" value="1">
      <span>Make this channel private (invite-only)</span>
    </label>
    <div class="cx-modal-err" id="createChannelErr" role="alert" hidden></div>
    <div class="cx-modal-actions">
      <button type="button" class="cx-btn" data-action="close-modal">Cancel</button>
      <button type="submit" class="cx-btn cx-btn-primary">Create channel</button>
    </div>
  </form>
</dialog>

<dialog class="cx-modal cx-modal-wide" id="modalBrowseChannels" aria-labelledby="modalBrowseChannelsTitle">
  <div class="cx-modal-form">
    <h2 class="cx-modal-title" id="modalBrowseChannelsTitle">Browse channels</h2>
    <div class="cx-modal-body" id="browseChannelsList">
      <div class="cx-modal-loading">Loading…</div>
    </div>
    <div class="cx-modal-actions">
      <button type="button" class="cx-btn" data-action="close-modal">Close</button>
    </div>
  </div>
</dialog>

<dialog class="cx-modal cx-modal-wide" id="modalNewDm" aria-labelledby="modalNewDmTitle">
  <div class="cx-modal-form">
    <h2 class="cx-modal-title" id="modalNewDmTitle">New direct message</h2>
    <p class="cx-modal-help">Pick one person for a private 1-on-1, or several for a group (up to 8 total including you).</p>
    <input type="text" id="newDmSearch" class="cx-modal-search" placeholder="Search people…" autocomplete="off">
    <div class="cx-modal-body" id="newDmPeopleList"><div class="cx-modal-loading">Loading…</div></div>
    <div class="cx-modal-footer-meta" id="newDmSelectedCount">No one selected</div>
    <div class="cx-modal-err" id="newDmErr" role="alert" hidden></div>
    <div class="cx-modal-actions">
      <button type="button" class="cx-btn" data-action="close-modal">Cancel</button>
      <button type="button" class="cx-btn cx-btn-primary" id="newDmStartBtn" disabled>Start DM</button>
    </div>
  </div>
</dialog>

<dialog class="cx-modal cx-modal-wide" id="modalSearch" aria-labelledby="modalSearchTitle">
  <div class="cx-modal-form">
    <h2 class="cx-modal-title" id="modalSearchTitle">Search messages</h2>
    <input type="text" id="searchInput" class="cx-modal-search" placeholder="Type to search…" autocomplete="off">
    <div class="cx-search-filters">
      <label class="cx-search-filter">
        <span>From</span>
        <select id="searchFrom"><option value="">Anyone</option></select>
      </label>
      <label class="cx-search-filter">
        <span>In</span>
        <select id="searchIn"><option value="">Everywhere</option></select>
      </label>
      <label class="cx-search-filter"><span>After</span><input type="date" id="searchAfter"></label>
      <label class="cx-search-filter"><span>Before</span><input type="date" id="searchBefore"></label>
      <label class="cx-search-filter cx-search-checkbox"><input type="checkbox" id="searchHasFile"><span>Has file</span></label>
    </div>
    <div class="cx-search-results" id="searchResults">
      <div class="cx-search-empty">Start typing or pick a filter to search across every channel and DM you can see.</div>
    </div>
    <div class="cx-modal-actions">
      <button type="button" class="cx-btn" data-action="close-modal">Close</button>
    </div>
  </div>
</dialog>

<dialog class="cx-modal" id="modalSchedule" aria-labelledby="modalScheduleTitle">
  <form class="cx-modal-form" id="scheduleForm">
    <h2 class="cx-modal-title" id="modalScheduleTitle">Schedule message</h2>
    <p class="cx-modal-help">Pick when this message should be sent. It'll be queued and delivered automatically.</p>
    <label class="cx-modal-field">
      <span class="cx-modal-label">Send at</span>
      <input type="datetime-local" id="scheduleAt" required>
    </label>
    <div class="cx-modal-err" id="scheduleErr" role="alert" hidden></div>
    <div class="cx-modal-actions">
      <button type="button" class="cx-btn" data-action="close-modal">Cancel</button>
      <button type="submit" class="cx-btn cx-btn-primary">Schedule</button>
    </div>
  </form>
</dialog>

<dialog class="cx-modal" id="modalForward" aria-labelledby="modalForwardTitle">
  <form class="cx-modal-form" id="forwardForm">
    <h2 class="cx-modal-title" id="modalForwardTitle">Forward message</h2>
    <label class="cx-modal-field">
      <span class="cx-modal-label">To</span>
      <select id="forwardTarget" required>
        <option value="">Choose channel or DM…</option>
      </select>
    </label>
    <label class="cx-modal-field">
      <span class="cx-modal-label">Add a note <span class="cx-modal-optional">(optional)</span></span>
      <input type="text" id="forwardNote" placeholder="Optional comment to send along with the quote">
    </label>
    <div class="cx-modal-err" id="forwardErr" role="alert" hidden></div>
    <div class="cx-modal-actions">
      <button type="button" class="cx-btn" data-action="close-modal">Cancel</button>
      <button type="submit" class="cx-btn cx-btn-primary">Forward</button>
    </div>
  </form>
</dialog>

</main>
</div>

<script>
  window.CHAT_BOOT = {
    csrf:               <?= json_encode(csrf_token()) ?>,
    currentUserId:      <?= (int)$uid ?>,
    currentUserName:    <?= json_encode($user['name']) ?>,
    currentChannelId:   <?= $currentChannel ? (int)$currentChannel['id'] : 'null' ?>,
    currentChannelSlug: <?= $currentChannel ? json_encode($currentChannel['slug']) : 'null' ?>,
    currentConversationId: <?= $currentDm ? (int)$currentDm['id'] : 'null' ?>,
    currentTab:         <?= json_encode($channelTab) ?>,
    currentView:        <?= json_encode($globalView) ?>,
    lastEventId:        <?= (int)$lastEventId ?>,
    unreadCounts:       <?= json_encode($unreadCounts) ?>,
    mentionCounts:      <?= json_encode($mentionCounts) ?>,
    presenceMap:        <?= json_encode($presenceMap) ?>,
    prefs:              <?= json_encode($userPrefs) ?>
  };
</script>
<script>
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
