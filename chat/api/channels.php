<?php
// /chat/api/channels.php — channel-related JSON endpoints.
//
// Endpoints (selected by ?action=...):
//   GET  ?action=list                 channels the current user is a member of
//   GET  ?action=directory            public channels in the workspace (browse)
//   GET  ?action=info&id=N            single channel detail
//   POST ?action=create               { slug, topic?, is_private? } -> { channel: { id, slug } }
//   POST ?action=join                 { channel_id } -> { ok: true }
//   POST ?action=leave                { channel_id } -> { ok: true }
//
// All endpoints require login. POST endpoints also require CSRF
// (X-CSRF-Token header or "csrf" form field).

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = chat_api_require_login();
$ws   = (int)$user['workspace_id'];
$uid  = (int)$user['id'];
$pdo  = db();

$action = (string)($_GET['action'] ?? '');

switch ($action) {
  case 'list':       chat_action_list($pdo, $ws, $uid);
  case 'directory':  chat_action_directory($pdo, $ws, $uid);
  case 'info':       chat_action_info($pdo, $ws, $uid);
  case 'create':     chat_action_create($pdo, $ws, $uid);
  case 'join':       chat_action_join($pdo, $ws, $uid);
  case 'leave':      chat_action_leave($pdo, $ws, $uid);
  default:           chat_json_error('unknown_action', "Unknown action: $action", 400);
}

function chat_action_list(PDO $pdo, int $ws, int $uid): never {
  $stmt = $pdo->prepare(
    'SELECT c.id, c.slug, c.topic, c.is_private,
            (SELECT COUNT(*) FROM chat_channel_members WHERE channel_id = c.id) AS member_count
     FROM chat_channels c
     JOIN chat_channel_members m ON m.channel_id = c.id AND m.user_id = ?
     WHERE c.workspace_id = ? AND c.archived_at IS NULL
     ORDER BY c.slug ASC'
  );
  $stmt->execute([$uid, $ws]);
  chat_json(['channels' => $stmt->fetchAll()]);
}

function chat_action_directory(PDO $pdo, int $ws, int $uid): never {
  // Public channels only — private channels are invisible unless you're in them.
  $stmt = $pdo->prepare(
    'SELECT c.id, c.slug, c.topic, c.is_private,
            (SELECT COUNT(*) FROM chat_channel_members WHERE channel_id = c.id) AS member_count,
            EXISTS(SELECT 1 FROM chat_channel_members WHERE channel_id = c.id AND user_id = ?) AS is_member
     FROM chat_channels c
     WHERE c.workspace_id = ? AND c.archived_at IS NULL AND c.is_private = 0
     ORDER BY c.slug ASC'
  );
  $stmt->execute([$uid, $ws]);
  chat_json(['channels' => $stmt->fetchAll()]);
}

function chat_action_info(PDO $pdo, int $ws, int $uid): never {
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) chat_json_error('bad_request', 'Missing id', 400);
  $stmt = $pdo->prepare('SELECT * FROM chat_channels WHERE id = ? AND workspace_id = ?');
  $stmt->execute([$id, $ws]);
  $ch = $stmt->fetch();
  if (!$ch) chat_json_error('not_found', 'Channel not found.', 404);
  if (!chat_user_can_see_channel($ch, $uid, $ws)) chat_json_error('forbidden', 'You do not have access to this channel.', 403);
  $ch['member_count'] = chat_channel_member_count((int)$ch['id']);
  $ch['is_member']    = chat_user_is_channel_member((int)$ch['id'], $uid) ? 1 : 0;
  chat_json(['channel' => $ch]);
}

function chat_action_create(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();

  $slug = chat_normalize_slug((string)($_POST['slug'] ?? ''));
  if ($slug === null) {
    chat_json_error('bad_slug',
      'Channel names use lowercase letters, numbers, dashes, and underscores (max 80 chars).', 400);
  }

  $topic = trim((string)($_POST['topic'] ?? ''));
  if (strlen($topic) > 255) $topic = substr($topic, 0, 255);

  $isPrivate = !empty($_POST['is_private']) ? 1 : 0;
  $now = now();

  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare(
      'INSERT INTO chat_channels (workspace_id, slug, topic, is_private, created_by, created_at)
       VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$ws, $slug, ($topic === '' ? null : $topic), $isPrivate, $uid, $now]);
    $channelId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare(
      'INSERT INTO chat_channel_members (channel_id, user_id, joined_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$channelId, $uid, $now]);

    chat_emit_event($ws, 'channel_created',      $channelId, null, null, $uid);
    chat_emit_event($ws, 'channel_member_added', $channelId, null, null, $uid, ['user_id' => $uid]);

    $pdo->commit();
  } catch (PDOException $e) {
    $pdo->rollBack();
    // 1062 = duplicate key (the UNIQUE on workspace_id + slug)
    if (($e->errorInfo[1] ?? 0) === 1062) {
      chat_json_error('slug_taken', 'A channel with that name already exists.', 409);
    }
    throw $e;
  }

  chat_json(['channel' => ['id' => $channelId, 'slug' => $slug]], 201);
}

function chat_action_join(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();

  $id = (int)($_POST['channel_id'] ?? 0);
  if ($id <= 0) chat_json_error('bad_request', 'Missing channel_id', 400);

  $stmt = $pdo->prepare('SELECT * FROM chat_channels WHERE id = ? AND workspace_id = ? AND archived_at IS NULL');
  $stmt->execute([$id, $ws]);
  $ch = $stmt->fetch();
  if (!$ch) chat_json_error('not_found', 'Channel not found.', 404);
  if ($ch['is_private']) {
    chat_json_error('forbidden', 'This channel is private. Ask a member to invite you.', 403);
  }

  $stmt = $pdo->prepare('INSERT IGNORE INTO chat_channel_members (channel_id, user_id, joined_at) VALUES (?, ?, ?)');
  $stmt->execute([$id, $uid, now()]);

  if ($stmt->rowCount() > 0) {
    chat_emit_event($ws, 'channel_member_added', $id, null, null, $uid, ['user_id' => $uid]);
  }
  chat_json(['ok' => true]);
}

function chat_action_leave(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();

  $id = (int)($_POST['channel_id'] ?? 0);
  if ($id <= 0) chat_json_error('bad_request', 'Missing channel_id', 400);

  $stmt = $pdo->prepare('DELETE FROM chat_channel_members WHERE channel_id = ? AND user_id = ?');
  $stmt->execute([$id, $uid]);

  if ($stmt->rowCount() > 0) {
    chat_emit_event($ws, 'channel_member_removed', $id, null, null, $uid, ['user_id' => $uid]);
  }
  chat_json(['ok' => true]);
}
