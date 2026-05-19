<?php
// /chat/api/messages.php — message-related JSON endpoints.
//
// Endpoints (selected by ?action=...):
//
//   GET ?action=list&channel_id=N            top-level messages in a channel
//   GET ?action=list&conversation_id=N       top-level messages in a DM
//   GET ?action=list&parent_message_id=N     replies to a thread parent
//        Optional: &before_id=N, &limit=N (default 50, max 200).
//        Returns oldest first.
//
//   POST ?action=send
//        { content, plus exactly one of: channel_id | conversation_id |
//          parent_message_id (for thread reply) }
//        Inserts message, parses @mentions into chat_mentions, emits a
//        chat_events row, returns the message with content_html.
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
  $channelId       = (int)($_GET['channel_id']        ?? 0);
  $conversationId  = (int)($_GET['conversation_id']   ?? 0);
  $parentMessageId = (int)($_GET['parent_message_id'] ?? 0);
  $beforeId        = (int)($_GET['before_id']         ?? 0);
  $limit           = max(1, min(200, (int)($_GET['limit'] ?? 50)));

  $modes = ($channelId > 0) + ($conversationId > 0) + ($parentMessageId > 0);
  if ($modes !== 1) {
    chat_json_error('bad_request', 'Specify exactly one of channel_id, conversation_id, parent_message_id.', 400);
  }

  if ($parentMessageId > 0) {
    // Thread replies: derive channel/DM scope from the parent.
    $stmt = $pdo->prepare(
      'SELECT id, workspace_id, channel_id, conversation_id
       FROM chat_messages WHERE id = ?'
    );
    $stmt->execute([$parentMessageId]);
    $parent = $stmt->fetch();
    if (!$parent) chat_json_error('not_found', 'Thread parent not found.', 404);
    if ((int)$parent['workspace_id'] !== $ws) chat_json_error('forbidden', '', 403);
    if ($parent['channel_id'] !== null) {
      $stmt = $pdo->prepare('SELECT is_private FROM chat_channels WHERE id = ?');
      $stmt->execute([(int)$parent['channel_id']]);
      $isPrivate = (int)$stmt->fetchColumn();
      if ($isPrivate && !chat_user_is_channel_member((int)$parent['channel_id'], $uid)) {
        chat_json_error('forbidden', '', 403);
      }
    } elseif ($parent['conversation_id'] !== null) {
      if (!chat_user_is_dm_member((int)$parent['conversation_id'], $uid)) {
        chat_json_error('forbidden', '', 403);
      }
    }

    $sql = 'SELECT m.id, m.user_id, m.content, m.created_at, m.edited_at, m.deleted_at,
                   m.parent_message_id, u.name AS author_name
            FROM chat_messages m
            JOIN users u ON u.id = m.user_id
            WHERE m.parent_message_id = ?';
    $params = [$parentMessageId];
    if ($beforeId > 0) { $sql .= ' AND m.id < ?'; $params[] = $beforeId; }
    $sql .= ' ORDER BY m.id ASC LIMIT ' . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    chat_json([
      'messages'          => chat_decorate_messages($rows, $ws),
      'parent_message_id' => $parentMessageId,
    ]);
  }

  // Channel or DM listing — top-level messages only.
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
                 m.parent_message_id, u.name AS author_name
          FROM chat_messages m
          JOIN users u ON u.id = m.user_id
          WHERE $whereCol = ? AND m.parent_message_id IS NULL";
  $params = [$whereId];
  if ($beforeId > 0) { $sql .= ' AND m.id < ?'; $params[] = $beforeId; }
  $sql .= ' ORDER BY m.id DESC LIMIT ' . (int)$limit;

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = array_reverse($stmt->fetchAll());

  chat_json([
    'messages'        => chat_decorate_messages($rows, $ws),
    'channel_id'      => $channelId > 0 ? $channelId : null,
    'conversation_id' => $conversationId > 0 ? $conversationId : null,
  ]);
}

function msg_action_send(PDO $pdo, int $ws, int $uid, array $user): never {
  chat_api_require_post();
  chat_api_require_csrf();

  $channelId       = (int)($_POST['channel_id']        ?? 0);
  $conversationId  = (int)($_POST['conversation_id']   ?? 0);
  $parentMessageId = (int)($_POST['parent_message_id'] ?? 0);
  $content         = trim((string)($_POST['content']   ?? ''));

  if ($content === '')          chat_json_error('bad_request', 'Message is empty.', 400);
  if (strlen($content) > 40000) chat_json_error('too_long',    'Message exceeds 40,000 characters.', 400);

  // Thread reply: derive channel/DM scope from the parent (and verify access).
  if ($parentMessageId > 0) {
    $stmt = $pdo->prepare(
      'SELECT id, workspace_id, channel_id, conversation_id
       FROM chat_messages WHERE id = ?'
    );
    $stmt->execute([$parentMessageId]);
    $parent = $stmt->fetch();
    if (!$parent) chat_json_error('not_found', 'Thread parent not found.', 404);
    if ((int)$parent['workspace_id'] !== $ws) chat_json_error('forbidden', '', 403);
    $channelId      = $parent['channel_id']      !== null ? (int)$parent['channel_id']      : 0;
    $conversationId = $parent['conversation_id'] !== null ? (int)$parent['conversation_id'] : 0;
  }

  if (($channelId > 0) === ($conversationId > 0)) {
    chat_json_error('bad_request', 'Specify exactly one of channel_id or conversation_id.', 400);
  }

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
      'INSERT INTO chat_messages
         (workspace_id, channel_id, conversation_id, parent_message_id, user_id, content, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
      $ws,
      $channelId      > 0 ? $channelId      : null,
      $conversationId > 0 ? $conversationId : null,
      $parentMessageId > 0 ? $parentMessageId : null,
      $uid, $content, $now,
    ]);
    $messageId = (int)$pdo->lastInsertId();

    chat_parse_and_store_mentions($messageId, $content, $ws);

    chat_emit_event(
      $ws, 'message',
      $channelId      > 0 ? $channelId      : null,
      $conversationId > 0 ? $conversationId : null,
      $messageId,
      $uid,
      $parentMessageId > 0 ? ['parent_message_id' => $parentMessageId] : null
    );

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  chat_json(['message' => [
    'id'                => $messageId,
    'channel_id'        => $channelId      > 0 ? $channelId      : null,
    'conversation_id'   => $conversationId > 0 ? $conversationId : null,
    'parent_message_id' => $parentMessageId > 0 ? $parentMessageId : null,
    'user_id'           => $uid,
    'author_name'       => $user['name'],
    'content'           => $content,
    'content_html'      => chat_render_message_content($content, $ws),
    'created_at'        => $now,
    'edited_at'         => null,
    'deleted_at'        => null,
    'reactions'         => [],
    'reply_count'       => 0,
  ]], 201);
}
