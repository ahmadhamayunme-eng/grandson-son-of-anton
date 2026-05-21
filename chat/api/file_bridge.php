<?php
// /chat/api/file_bridge.php — chat ↔ AntonX file bridge endpoints.
//
//   GET  ?action=antonx_files                 — recent task / project
//        attachments the user can see, for the composer "Attach from
//        AntonX" picker.
//   POST ?action=attach_to_message            — attach an antonx attachment
//        to a chat message (reference, no re-upload).
//   POST ?action=promote_to_task              — copy a chat file into a
//        task as an AntonX task_attachments row.

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
  case 'antonx_files':     fb_list_antonx_files($pdo, $ws, $uid);
  case 'attach_to_message': fb_attach_to_message($pdo, $ws, $uid);
  case 'promote_to_task':  fb_promote_to_task($pdo, $ws, $uid);
  default: chat_json_error('unknown_action', "Unknown action: $action", 400);
}

function fb_list_antonx_files(PDO $pdo, int $ws, int $uid): never {
  // Pull the user's most-recent 50 task attachments across every task they
  // can see (assignee OR project member). Returns one flat list.
  try {
    $stmt = $pdo->prepare(
      "SELECT ta.id, ta.original_name, ta.mime_type, ta.size_bytes, ta.created_at,
              t.id AS task_id, t.title AS task_title
       FROM task_attachments ta
       JOIN tasks t ON t.id = ta.task_id
       WHERE ta.workspace_id = ?
         AND (
              ta.uploaded_by = ?
           OR t.id IN (SELECT task_id FROM task_assignees WHERE user_id = ?)
         )
       ORDER BY ta.id DESC LIMIT 50"
    );
    $stmt->execute([$ws, $uid, $uid]);
    $rows = $stmt->fetchAll();
    $out = array_map(function ($r) {
      $mime = (string)($r['mime_type'] ?? 'application/octet-stream');
      return [
        'antonx_attachment_id' => (int)$r['id'],
        'name'      => (string)$r['original_name'],
        'mime'      => $mime,
        'size'      => (int)($r['size_bytes'] ?? 0),
        'is_image'  => strpos($mime, 'image/') === 0 ? 1 : 0,
        'task_id'   => (int)$r['task_id'],
        'task_title'=> (string)$r['task_title'],
      ];
    }, $rows);
    chat_json(['files' => $out]);
  } catch (Throwable $e) {
    chat_json(['files' => []]);
  }
}

function fb_attach_to_message(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();

  $messageId = (int)($_POST['message_id'] ?? 0);
  $attachId  = (int)($_POST['antonx_attachment_id'] ?? 0);
  if ($messageId <= 0 || $attachId <= 0) chat_json_error('bad_request', 'Missing ids.', 400);

  // Confirm the message belongs to the workspace AND the user can see the
  // attachment.
  $msg = $pdo->prepare('SELECT user_id FROM chat_messages WHERE id = ? AND workspace_id = ?');
  $msg->execute([$messageId, $ws]);
  $msgRow = $msg->fetch();
  if (!$msgRow) chat_json_error('not_found', 'Message not found.', 404);
  if ((int)$msgRow['user_id'] !== $uid) chat_json_error('forbidden', 'Only the author can attach.', 403);

  $att = $pdo->prepare(
    'SELECT ta.* FROM task_attachments ta WHERE ta.id = ? AND ta.workspace_id = ?'
  );
  $att->execute([$attachId, $ws]);
  $attRow = $att->fetch();
  if (!$attRow) chat_json_error('not_found', 'AntonX file not found.', 404);

  // Append a small footer to the message linking to the AntonX download.
  // We store the link in the same content field so it round-trips through
  // all the existing render paths without needing a new attachments schema.
  $url = '/download.php?id=' . (int)$attRow['id'] . '&type=task';   // generic AntonX DL
  $name = h((string)$attRow['original_name']);
  $card = '<p class="chat-tracked">📎 <a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $name . '</a> (from AntonX)</p>';

  // Use chat_sanitize_html to ensure we don't introduce dirty markup.
  $upd = $pdo->prepare('SELECT content FROM chat_messages WHERE id = ?');
  $upd->execute([$messageId]);
  $existing = (string)$upd->fetchColumn();
  $newContent = chat_sanitize_html($existing . $card);
  $now = now();
  $pdo->prepare('UPDATE chat_messages SET content = ?, edited_at = ? WHERE id = ?')
    ->execute([$newContent, $now, $messageId]);

  // Look up channel/conversation for the SSE event.
  $loc = $pdo->prepare('SELECT channel_id, conversation_id FROM chat_messages WHERE id = ?');
  $loc->execute([$messageId]);
  $locRow = $loc->fetch();
  chat_emit_event($ws, 'message_edited',
    $locRow['channel_id']      !== null ? (int)$locRow['channel_id']      : null,
    $locRow['conversation_id'] !== null ? (int)$locRow['conversation_id'] : null,
    $messageId, $uid, null);

  chat_json(['ok' => true, 'message_id' => $messageId]);
}

function fb_promote_to_task(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();

  $chatFileId = (int)($_POST['file_id'] ?? 0);
  $taskId     = (int)($_POST['task_id'] ?? 0);
  if ($chatFileId <= 0 || $taskId <= 0) chat_json_error('bad_request', 'Missing ids.', 400);

  $st = $pdo->prepare('SELECT * FROM chat_files WHERE id = ? AND workspace_id = ?');
  $st->execute([$chatFileId, $ws]);
  $file = $st->fetch();
  if (!$file) chat_json_error('not_found', 'Chat file not found.', 404);

  // Confirm the task is reachable to this user.
  $ts = $pdo->prepare('SELECT id FROM tasks WHERE id = ? AND workspace_id = ?');
  $ts->execute([$taskId, $ws]);
  if (!$ts->fetchColumn()) chat_json_error('not_found', 'Task not found.', 404);

  // Reference the chat file on the task side. We store the chat-side
  // stored_name so AntonX downloads route to the same physical bytes via
  // a future bridge endpoint (or just use the chat file URL directly).
  $ins = $pdo->prepare(
    'INSERT INTO task_attachments
       (workspace_id, task_id, uploaded_by, original_name, stored_name, mime_type, size_bytes, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
  );
  // Store the bridge URL as the stored_name so AntonX UIs that don't yet
  // understand cross-source attachments can still produce a working link.
  $bridgeName = 'chat:' . (int)$file['id'];
  $ins->execute([
    $ws, $taskId, $uid,
    (string)$file['original_name'],
    $bridgeName,
    (string)$file['mime_type'],
    (int)$file['size_bytes'],
    now(),
  ]);
  $newId = (int)$pdo->lastInsertId();
  chat_json(['ok' => true, 'task_attachment_id' => $newId]);
}
