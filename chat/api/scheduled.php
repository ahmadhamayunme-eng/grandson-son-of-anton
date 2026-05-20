<?php
// /chat/api/scheduled.php — send-later queue (Phase 10).
//
//   POST ?action=create { scheduled_for, content, channel_id|conversation_id|parent_message_id, reply_to_message_id? }
//   GET  ?action=list                      — my pending scheduled messages
//   POST ?action=cancel  { id }            — drop a pending message
//
// Delivery happens lazily via the SSE worker (chat_deliver_due_scheduled_messages
// is called every poll iteration), so no OS cron is required.

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
  case 'create': sched_action_create($pdo, $ws, $uid);
  case 'list':   sched_action_list($pdo, $ws, $uid);
  case 'cancel': sched_action_cancel($pdo, $ws, $uid);
  default:       chat_json_error('unknown_action', "Unknown action: $action", 400);
}

function sched_action_create(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();

  $channelId          = (int)($_POST['channel_id']          ?? 0);
  $conversationId     = (int)($_POST['conversation_id']     ?? 0);
  $parentMessageId    = (int)($_POST['parent_message_id']   ?? 0);
  $replyToMessageId   = (int)($_POST['reply_to_message_id'] ?? 0);
  $scheduledForRaw    = trim((string)($_POST['scheduled_for'] ?? ''));
  $contentRaw         = (string)($_POST['content']           ?? '');

  if ($scheduledForRaw === '') chat_json_error('bad_request', 'Missing scheduled_for.', 400);
  $ts = strtotime($scheduledForRaw);
  if ($ts === false || $ts <= time() - 30) {
    chat_json_error('bad_request', 'scheduled_for must be a valid future time.', 400);
  }
  $scheduledFor = date('Y-m-d H:i:s', $ts);

  $content = chat_sanitize_html($contentRaw);
  if (trim($content) === '') chat_json_error('bad_request', 'Message is empty.', 400);
  if (strlen($content) > 40000) chat_json_error('too_long', 'Message exceeds 40,000 characters.', 400);

  // Same target validation as msg_action_send.
  if ($parentMessageId > 0) {
    $stmt = $pdo->prepare('SELECT id, workspace_id, channel_id, conversation_id FROM chat_messages WHERE id = ?');
    $stmt->execute([$parentMessageId]);
    $parent = $stmt->fetch();
    if (!$parent || (int)$parent['workspace_id'] !== $ws) chat_json_error('forbidden', '', 403);
    $channelId      = $parent['channel_id']      !== null ? (int)$parent['channel_id']      : 0;
    $conversationId = $parent['conversation_id'] !== null ? (int)$parent['conversation_id'] : 0;
  }
  if (($channelId > 0) === ($conversationId > 0)) {
    chat_json_error('bad_request', 'Specify exactly one of channel_id or conversation_id.', 400);
  }
  if ($channelId > 0 && !chat_user_is_channel_member($channelId, $uid)) {
    chat_json_error('forbidden', 'Join the channel first.', 403);
  }
  if ($conversationId > 0 && !chat_user_is_dm_member($conversationId, $uid)) {
    chat_json_error('forbidden', '', 403);
  }

  $stmt = $pdo->prepare(
    'INSERT INTO chat_scheduled_messages
       (workspace_id, user_id, channel_id, conversation_id, parent_message_id, reply_to_message_id, content_html, scheduled_for, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->execute([
    $ws, $uid,
    $channelId      > 0 ? $channelId      : null,
    $conversationId > 0 ? $conversationId : null,
    $parentMessageId  > 0 ? $parentMessageId  : null,
    $replyToMessageId > 0 ? $replyToMessageId : null,
    $content, $scheduledFor, now(),
  ]);
  chat_json(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'scheduled_for' => $scheduledFor], 201);
}

function sched_action_list(PDO $pdo, int $ws, int $uid): never {
  $stmt = $pdo->prepare(
    'SELECT s.id, s.channel_id, s.conversation_id, s.parent_message_id, s.reply_to_message_id,
            s.content_html, s.scheduled_for, s.created_at,
            c.slug AS channel_slug, c.is_private AS channel_is_private
     FROM chat_scheduled_messages s
     LEFT JOIN chat_channels c ON c.id = s.channel_id
     WHERE s.user_id = ? AND s.workspace_id = ? AND s.sent_at IS NULL
     ORDER BY s.scheduled_for ASC'
  );
  $stmt->execute([$uid, $ws]);
  chat_json(['messages' => $stmt->fetchAll()]);
}

function sched_action_cancel(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) chat_json_error('bad_request', 'Missing id.', 400);
  $stmt = $pdo->prepare('DELETE FROM chat_scheduled_messages WHERE id = ? AND user_id = ? AND workspace_id = ? AND sent_at IS NULL');
  $stmt->execute([$id, $uid, $ws]);
  chat_json(['ok' => true, 'deleted' => $stmt->rowCount() > 0]);
}
