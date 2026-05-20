<?php
// /chat/api/saved.php — bookmark / save messages (Phase 10).
//
//   POST ?action=toggle { message_id } — add if missing, remove if present
//   GET  ?action=list                  — saved messages, newest first

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
  case 'toggle': saved_action_toggle($pdo, $ws, $uid);
  case 'list':   saved_action_list($pdo, $ws, $uid);
  default:       chat_json_error('unknown_action', "Unknown action: $action", 400);
}

function saved_action_toggle(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();
  $messageId = (int)($_POST['message_id'] ?? 0);
  if ($messageId <= 0) chat_json_error('bad_request', 'Missing message_id.', 400);

  // Confirm the user can see this message (channel public OR member, DM member).
  $stmt = $pdo->prepare('SELECT * FROM chat_messages WHERE id = ?');
  $stmt->execute([$messageId]);
  $msg = $stmt->fetch();
  if (!$msg || (int)$msg['workspace_id'] !== $ws) chat_json_error('not_found', '', 404);
  if ($msg['channel_id'] !== null) {
    $cs = $pdo->prepare('SELECT * FROM chat_channels WHERE id = ?');
    $cs->execute([(int)$msg['channel_id']]);
    $ch = $cs->fetch();
    if (!$ch || !chat_user_can_see_channel($ch, $uid, $ws)) chat_json_error('forbidden', '', 403);
  } elseif ($msg['conversation_id'] !== null && !chat_user_is_dm_member((int)$msg['conversation_id'], $uid)) {
    chat_json_error('forbidden', '', 403);
  }

  $exists = $pdo->prepare('SELECT 1 FROM chat_saved_messages WHERE user_id = ? AND message_id = ?');
  $exists->execute([$uid, $messageId]);
  if ($exists->fetchColumn()) {
    $del = $pdo->prepare('DELETE FROM chat_saved_messages WHERE user_id = ? AND message_id = ?');
    $del->execute([$uid, $messageId]);
    chat_json(['action' => 'removed', 'message_id' => $messageId, 'is_saved' => 0]);
  }
  $ins = $pdo->prepare('INSERT INTO chat_saved_messages (user_id, message_id, saved_at) VALUES (?, ?, ?)');
  $ins->execute([$uid, $messageId, now()]);
  chat_json(['action' => 'added', 'message_id' => $messageId, 'is_saved' => 1]);
}

function saved_action_list(PDO $pdo, int $ws, int $uid): never {
  // Most recently saved first, newest 100.
  $stmt = $pdo->prepare(
    'SELECT m.id, m.user_id, m.content, m.created_at, m.edited_at, m.deleted_at,
            m.parent_message_id, m.channel_id, m.conversation_id, m.reply_to_message_id,
            u.name AS author_name,
            c.slug AS channel_slug, c.is_private AS channel_is_private,
            s.saved_at
     FROM chat_saved_messages s
     JOIN chat_messages m ON m.id = s.message_id
     JOIN users u         ON u.id = m.user_id
     LEFT JOIN chat_channels c ON c.id = m.channel_id
     WHERE s.user_id = ? AND m.workspace_id = ? AND m.deleted_at IS NULL
     ORDER BY s.saved_at DESC
     LIMIT 100'
  );
  $stmt->execute([$uid, $ws]);
  $rows = chat_decorate_messages_phase10($stmt->fetchAll(), $ws, $uid, null);
  chat_json(['messages' => $rows]);
}
