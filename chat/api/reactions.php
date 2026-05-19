<?php
// /chat/api/reactions.php — emoji reactions on messages (Phase 5).
//
// Endpoints:
//   GET  ?action=list&message_id=N
//        Reactions for one message — array of { emoji, count, user_ids }.
//        user_ids is comma-separated; the client computes me_reacted itself.
//   POST ?action=toggle
//        { message_id, emoji } — add the reaction if it doesn't exist for
//        the current user, or remove it if it does. Returns the post-toggle
//        reactions list and emits the corresponding chat_events row.
//
// Permission: a user can react to any message they can SEE (member of the
// channel, OR channel is public, OR member of the DM). Same scope rule as
// reading.

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
  case 'list':   rx_action_list($pdo, $ws, $uid);
  case 'toggle': rx_action_toggle($pdo, $ws, $uid);
  default:       chat_json_error('unknown_action', "Unknown action: $action", 400);
}

/**
 * Verify the user can see the message (and return the message row).
 * Sends 403/404 on failure.
 */
function rx_require_visible(PDO $pdo, int $ws, int $uid, int $messageId): array {
  $stmt = $pdo->prepare(
    'SELECT id, workspace_id, channel_id, conversation_id
     FROM chat_messages WHERE id = ?'
  );
  $stmt->execute([$messageId]);
  $msg = $stmt->fetch();
  if (!$msg) chat_json_error('not_found', 'Message not found.', 404);
  if ((int)$msg['workspace_id'] !== $ws) chat_json_error('forbidden', '', 403);

  if ($msg['channel_id'] !== null) {
    $stmt = $pdo->prepare('SELECT is_private FROM chat_channels WHERE id = ?');
    $stmt->execute([(int)$msg['channel_id']]);
    $isPrivate = (int)$stmt->fetchColumn();
    if ($isPrivate && !chat_user_is_channel_member((int)$msg['channel_id'], $uid)) {
      chat_json_error('forbidden', '', 403);
    }
  } elseif ($msg['conversation_id'] !== null) {
    if (!chat_user_is_dm_member((int)$msg['conversation_id'], $uid)) {
      chat_json_error('forbidden', '', 403);
    }
  }
  return $msg;
}

function rx_action_list(PDO $pdo, int $ws, int $uid): never {
  $messageId = (int)($_GET['message_id'] ?? 0);
  if ($messageId <= 0) chat_json_error('bad_request', 'Missing message_id', 400);
  rx_require_visible($pdo, $ws, $uid, $messageId);
  chat_json(['message_id' => $messageId, 'reactions' => chat_load_message_reactions($messageId)]);
}

function rx_action_toggle(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();

  $messageId = (int)($_POST['message_id'] ?? 0);
  $emoji     = trim((string)($_POST['emoji'] ?? ''));

  if ($messageId <= 0)       chat_json_error('bad_request', 'Missing message_id.', 400);
  if ($emoji === '')         chat_json_error('bad_request', 'Missing emoji.', 400);
  if (mb_strlen($emoji) > 32) chat_json_error('too_long',    'Emoji too long.', 400);

  $msg = rx_require_visible($pdo, $ws, $uid, $messageId);

  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare(
      'SELECT 1 FROM chat_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?'
    );
    $stmt->execute([$messageId, $uid, $emoji]);
    $exists = (bool)$stmt->fetchColumn();

    if ($exists) {
      $stmt = $pdo->prepare(
        'DELETE FROM chat_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?'
      );
      $stmt->execute([$messageId, $uid, $emoji]);
      $eventType = 'reaction_removed';
      $resultAction = 'removed';
    } else {
      $stmt = $pdo->prepare(
        'INSERT INTO chat_reactions (message_id, user_id, emoji, created_at)
         VALUES (?, ?, ?, ?)'
      );
      $stmt->execute([$messageId, $uid, $emoji, now()]);
      $eventType = 'reaction_added';
      $resultAction = 'added';
    }

    // Snapshot reactions inside the transaction so the event payload
    // matches the just-applied state. Recipients use this directly — no
    // extra fetch.
    $reactions = chat_load_message_reactions($messageId);

    chat_emit_event(
      $ws, $eventType,
      $msg['channel_id']      !== null ? (int)$msg['channel_id']      : null,
      $msg['conversation_id'] !== null ? (int)$msg['conversation_id'] : null,
      $messageId,
      $uid,
      ['emoji' => $emoji, 'reactions' => $reactions]
    );

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  chat_json([
    'action'     => $resultAction,
    'message_id' => $messageId,
    'emoji'      => $emoji,
    'reactions'  => $reactions,
  ]);
}
