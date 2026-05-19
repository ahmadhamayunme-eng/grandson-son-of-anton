<?php
// /chat/api/messages.php — message-related JSON endpoints.
//
// Endpoints:
//   GET  ?action=list&channel_id=N[&before_id=N][&limit=N]
//        Returns up to N (default 50, max 200) messages in the channel,
//        oldest first. before_id paginates backwards.
//   POST ?action=send
//        { channel_id, content } -> { message: { id, ... } }
//
// All endpoints require login. POST also requires CSRF.

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
  case 'list': msg_action_list($pdo, $ws, $uid);
  case 'send': msg_action_send($pdo, $ws, $uid, $user);
  default:     chat_json_error('unknown_action', "Unknown action: $action", 400);
}

function msg_action_list(PDO $pdo, int $ws, int $uid): never {
  $channelId = (int)($_GET['channel_id'] ?? 0);
  $beforeId  = (int)($_GET['before_id'] ?? 0);
  $limit     = max(1, min(200, (int)($_GET['limit'] ?? 50)));

  if ($channelId <= 0) chat_json_error('bad_request', 'Missing channel_id', 400);

  $stmt = $pdo->prepare('SELECT * FROM chat_channels WHERE id = ? AND workspace_id = ?');
  $stmt->execute([$channelId, $ws]);
  $ch = $stmt->fetch();
  if (!$ch) chat_json_error('not_found', 'Channel not found.', 404);
  if (!chat_user_can_see_channel($ch, $uid, $ws)) chat_json_error('forbidden', '', 403);

  $sql = 'SELECT m.id, m.user_id, m.content, m.created_at, m.edited_at, m.deleted_at,
                 u.name AS author_name
          FROM chat_messages m
          JOIN users u ON u.id = m.user_id
          WHERE m.channel_id = ? AND m.parent_message_id IS NULL';
  $params = [$channelId];
  if ($beforeId > 0) {
    $sql .= ' AND m.id < ?';
    $params[] = $beforeId;
  }
  $sql .= ' ORDER BY m.id DESC LIMIT ' . (int)$limit;

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
  $rows = array_reverse($rows);  // oldest first for display

  // Soft-deleted: drop content so the client never sees the original text.
  foreach ($rows as &$r) {
    if ($r['deleted_at'] !== null) $r['content'] = '';
  }
  unset($r);

  chat_json(['messages' => $rows, 'channel_id' => $channelId]);
}

function msg_action_send(PDO $pdo, int $ws, int $uid, array $user): never {
  chat_api_require_post();
  chat_api_require_csrf();

  $channelId = (int)($_POST['channel_id'] ?? 0);
  $content   = trim((string)($_POST['content'] ?? ''));

  if ($channelId <= 0) chat_json_error('bad_request', 'Missing channel_id', 400);
  if ($content === '') chat_json_error('bad_request', 'Message is empty.', 400);
  if (strlen($content) > 40000) chat_json_error('too_long', 'Message exceeds 40,000 characters.', 400);

  $stmt = $pdo->prepare('SELECT * FROM chat_channels WHERE id = ? AND workspace_id = ?');
  $stmt->execute([$channelId, $ws]);
  $ch = $stmt->fetch();
  if (!$ch) chat_json_error('not_found', 'Channel not found.', 404);

  // Must be a member to post — matches Slack.
  if (!chat_user_is_channel_member($channelId, $uid)) {
    chat_json_error('not_member', 'Join the channel before posting.', 403);
  }

  $now = now();
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare(
      'INSERT INTO chat_messages (workspace_id, channel_id, user_id, content, created_at)
       VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$ws, $channelId, $uid, $content, $now]);
    $messageId = (int)$pdo->lastInsertId();

    chat_emit_event($ws, 'message', $channelId, null, $messageId, $uid);

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  chat_json(['message' => [
    'id'          => $messageId,
    'channel_id'  => $channelId,
    'user_id'     => $uid,
    'author_name' => $user['name'],
    'content'     => $content,
    'created_at'  => $now,
    'edited_at'   => null,
    'deleted_at'  => null,
  ]], 201);
}
