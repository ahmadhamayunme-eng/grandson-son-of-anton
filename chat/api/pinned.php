<?php
// /chat/api/pinned.php — channel pins (Phase 10).
//
//   POST ?action=toggle { message_id } — pin / unpin (only channel members)
//   GET  ?action=list&channel_id=N     — pinned messages for the channel
//
// Emits chat_events on toggle so the channel's pinned indicator and the
// details panel update live.

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
  case 'toggle': pin_action_toggle($pdo, $ws, $uid);
  case 'list':   pin_action_list($pdo, $ws, $uid);
  default:       chat_json_error('unknown_action', "Unknown action: $action", 400);
}

function pin_action_toggle(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();
  $messageId = (int)($_POST['message_id'] ?? 0);
  if ($messageId <= 0) chat_json_error('bad_request', 'Missing message_id.', 400);

  $stmt = $pdo->prepare('SELECT * FROM chat_messages WHERE id = ?');
  $stmt->execute([$messageId]);
  $msg = $stmt->fetch();
  if (!$msg)                                  chat_json_error('not_found', '', 404);
  if ((int)$msg['workspace_id'] !== $ws)      chat_json_error('forbidden', '', 403);
  if ($msg['channel_id'] === null)            chat_json_error('bad_request', 'Pinning is for channel messages.', 400);
  $channelId = (int)$msg['channel_id'];
  if (!chat_user_is_channel_member($channelId, $uid)) chat_json_error('forbidden', 'Join the channel to pin.', 403);

  $now    = now();
  $exists = $pdo->prepare('SELECT 1 FROM chat_pinned_messages WHERE channel_id = ? AND message_id = ?');
  $exists->execute([$channelId, $messageId]);
  if ($exists->fetchColumn()) {
    $del = $pdo->prepare('DELETE FROM chat_pinned_messages WHERE channel_id = ? AND message_id = ?');
    $del->execute([$channelId, $messageId]);
    chat_emit_event($ws, 'message_unpinned', $channelId, null, $messageId, $uid);
    chat_json(['action' => 'removed', 'message_id' => $messageId, 'is_pinned' => 0]);
  }
  $ins = $pdo->prepare('INSERT INTO chat_pinned_messages (channel_id, message_id, pinned_by, pinned_at) VALUES (?, ?, ?, ?)');
  $ins->execute([$channelId, $messageId, $uid, $now]);
  chat_emit_event($ws, 'message_pinned', $channelId, null, $messageId, $uid);
  chat_json(['action' => 'added', 'message_id' => $messageId, 'is_pinned' => 1]);
}

function pin_action_list(PDO $pdo, int $ws, int $uid): never {
  $channelId = (int)($_GET['channel_id'] ?? 0);
  if ($channelId <= 0) chat_json_error('bad_request', 'Missing channel_id.', 400);
  $cs = $pdo->prepare('SELECT * FROM chat_channels WHERE id = ?');
  $cs->execute([$channelId]);
  $ch = $cs->fetch();
  if (!$ch || (int)$ch['workspace_id'] !== $ws) chat_json_error('not_found', '', 404);
  if (!chat_user_can_see_channel($ch, $uid, $ws)) chat_json_error('forbidden', '', 403);

  $rows = chat_load_pinned_messages_for_channel($channelId, $ws);
  $rows = chat_decorate_messages_phase10($rows, $ws, $uid, $channelId);
  chat_json(['messages' => $rows, 'channel_id' => $channelId]);
}
