<?php
// /chat/api/messages.php — message-related JSON endpoints.
//
// Endpoints accept either `channel_id` (Phase 2) or `conversation_id`
// (Phase 4 DMs), but not both. Exactly one must be set.
//
//   GET  ?action=list&channel_id=N[&before_id=N][&limit=N]
//   GET  ?action=list&conversation_id=N[&before_id=N][&limit=N]
//        Returns up to N (default 50, max 200) messages, oldest first.
//        before_id paginates backwards.
//   POST ?action=send  { channel_id|conversation_id, content }
//        Inserts one message + one chat_events row.
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

/**
 * Reads channel_id / conversation_id from either $_GET or $_POST and
 * returns [$channelId, $conversationId] with exactly one positive.
 * Sends a JSON error and exits on bad input.
 */
function msg_resolve_target(array $src): array {
  $channelId      = (int)($src['channel_id']      ?? 0);
  $conversationId = (int)($src['conversation_id'] ?? 0);
  if ($channelId > 0 && $conversationId > 0) {
    chat_json_error('bad_request', 'Specify either channel_id or conversation_id, not both.', 400);
  }
  if ($channelId <= 0 && $conversationId <= 0) {
    chat_json_error('bad_request', 'Missing channel_id or conversation_id.', 400);
  }
  return [$channelId, $conversationId];
}

function msg_action_list(PDO $pdo, int $ws, int $uid): never {
  [$channelId, $conversationId] = msg_resolve_target($_GET);
  $beforeId = (int)($_GET['before_id'] ?? 0);
  $limit    = max(1, min(200, (int)($_GET['limit'] ?? 50)));

  // Permission + workspace checks, then pick the WHERE column.
  if ($channelId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM chat_channels WHERE id = ? AND workspace_id = ?');
    $stmt->execute([$channelId, $ws]);
    $ch = $stmt->fetch();
    if (!$ch) chat_json_error('not_found', 'Channel not found.', 404);
    if (!chat_user_can_see_channel($ch, $uid, $ws)) chat_json_error('forbidden', '', 403);
    $whereCol = 'm.channel_id';
    $whereId  = $channelId;
  } else {
    if (!chat_user_is_dm_member($conversationId, $uid)) {
      chat_json_error('forbidden', 'You are not in this conversation.', 403);
    }
    $stmt = $pdo->prepare('SELECT workspace_id FROM chat_direct_conversations WHERE id = ?');
    $stmt->execute([$conversationId]);
    if ((int)$stmt->fetchColumn() !== $ws) chat_json_error('forbidden', '', 403);
    $whereCol = 'm.conversation_id';
    $whereId  = $conversationId;
  }

  $sql = "SELECT m.id, m.user_id, m.content, m.created_at, m.edited_at, m.deleted_at,
                 u.name AS author_name
          FROM chat_messages m
          JOIN users u ON u.id = m.user_id
          WHERE $whereCol = ? AND m.parent_message_id IS NULL";
  $params = [$whereId];
  if ($beforeId > 0) { $sql .= ' AND m.id < ?'; $params[] = $beforeId; }
  $sql .= ' ORDER BY m.id DESC LIMIT ' . (int)$limit;

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = array_reverse($stmt->fetchAll());

  // Soft-deleted: drop content so the client never sees the original text.
  foreach ($rows as &$r) {
    if ($r['deleted_at'] !== null) $r['content'] = '';
  }
  unset($r);

  chat_json([
    'messages'        => $rows,
    'channel_id'      => $channelId      > 0 ? $channelId      : null,
    'conversation_id' => $conversationId > 0 ? $conversationId : null,
  ]);
}

function msg_action_send(PDO $pdo, int $ws, int $uid, array $user): never {
  chat_api_require_post();
  chat_api_require_csrf();

  [$channelId, $conversationId] = msg_resolve_target($_POST);
  $content = trim((string)($_POST['content'] ?? ''));
  if ($content === '')              chat_json_error('bad_request', 'Message is empty.', 400);
  if (strlen($content) > 40000)     chat_json_error('too_long',    'Message exceeds 40,000 characters.', 400);

  if ($channelId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM chat_channels WHERE id = ? AND workspace_id = ?');
    $stmt->execute([$channelId, $ws]);
    $ch = $stmt->fetch();
    if (!$ch) chat_json_error('not_found', 'Channel not found.', 404);
    if (!chat_user_is_channel_member($channelId, $uid)) {
      chat_json_error('not_member', 'Join the channel before posting.', 403);
    }
  } else {
    if (!chat_user_is_dm_member($conversationId, $uid)) {
      chat_json_error('forbidden', 'You are not in this conversation.', 403);
    }
    $stmt = $pdo->prepare('SELECT workspace_id FROM chat_direct_conversations WHERE id = ?');
    $stmt->execute([$conversationId]);
    if ((int)$stmt->fetchColumn() !== $ws) chat_json_error('forbidden', '', 403);
  }

  $now = now();
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare(
      'INSERT INTO chat_messages (workspace_id, channel_id, conversation_id, user_id, content, created_at)
       VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
      $ws,
      $channelId      > 0 ? $channelId      : null,
      $conversationId > 0 ? $conversationId : null,
      $uid, $content, $now,
    ]);
    $messageId = (int)$pdo->lastInsertId();

    chat_emit_event(
      $ws, 'message',
      $channelId      > 0 ? $channelId      : null,
      $conversationId > 0 ? $conversationId : null,
      $messageId,
      $uid
    );

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  chat_json(['message' => [
    'id'              => $messageId,
    'channel_id'      => $channelId      > 0 ? $channelId      : null,
    'conversation_id' => $conversationId > 0 ? $conversationId : null,
    'user_id'         => $uid,
    'author_name'     => $user['name'],
    'content'         => $content,
    'created_at'      => $now,
    'edited_at'       => null,
    'deleted_at'      => null,
  ]], 201);
}
