<?php
// /chat/api/file.php — gated file download endpoint (Phase 6).
//
// GET ?id=N — streams a chat_files row to the browser, IF the user is
// allowed to see it. Permission rules:
//   - File is staged (message_id IS NULL) → only the uploader can see it.
//   - File is attached to a channel message → user must be able to see the
//     channel (member, OR public channel in workspace).
//   - File is attached to a DM message → user must be in the conversation.
//
// Content-Disposition: inline for images (so they preview in the message),
// attachment for everything else. Filename is sanitised to ASCII; modern
// browsers also honour filename* with the original UTF-8 name.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = auth_user();
if (!$user) {
  http_response_code(401);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Login required.';
  exit;
}
$ws  = (int)$user['workspace_id'];
$uid = (int)$user['id'];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Bad request.';
  exit;
}

$pdo = db();
$stmt = $pdo->prepare(
  'SELECT id, workspace_id, message_id, user_id, original_name, stored_name, mime_type, size_bytes
   FROM chat_files WHERE id = ?'
);
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row || (int)$row['workspace_id'] !== $ws) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Not found.';
  exit;
}

// Permission check
if ($row['message_id'] === null) {
  // Staged — only the uploader.
  if ((int)$row['user_id'] !== $uid) { http_response_code(403); echo 'Forbidden.'; exit; }
} else {
  // Attached — load the message and check the channel/DM scope.
  $stmt = $pdo->prepare(
    'SELECT channel_id, conversation_id, workspace_id, deleted_at
     FROM chat_messages WHERE id = ?'
  );
  $stmt->execute([(int)$row['message_id']]);
  $msg = $stmt->fetch();
  if (!$msg || (int)$msg['workspace_id'] !== $ws) {
    http_response_code(404); echo 'Not found.'; exit;
  }
  if ($msg['deleted_at'] !== null) { http_response_code(404); echo 'Not found.'; exit; }

  if ($msg['channel_id'] !== null) {
    $cs = $pdo->prepare('SELECT * FROM chat_channels WHERE id = ?');
    $cs->execute([(int)$msg['channel_id']]);
    $ch = $cs->fetch();
    if (!$ch || !chat_user_can_see_channel($ch, $uid, $ws)) {
      http_response_code(403); echo 'Forbidden.'; exit;
    }
  } elseif ($msg['conversation_id'] !== null) {
    if (!chat_user_is_dm_member((int)$msg['conversation_id'], $uid)) {
      http_response_code(403); echo 'Forbidden.'; exit;
    }
  }
}

$diskPath = __DIR__ . '/../uploads/' . $row['stored_name'];
if (!is_file($diskPath)) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'File is gone.';
  exit;
}

// Disposition: inline for images so they render in the message body;
// attachment for everything else so the browser downloads to disk.
$inline   = strpos((string)$row['mime_type'], 'image/') === 0;
$disposition = $inline ? 'inline' : 'attachment';

// RFC 6266 / 5987: ASCII fallback filename + UTF-8 filename*.
$asciiName = preg_replace('/[^\w.\- ]/', '_', (string)$row['original_name']);
if ($asciiName === '') $asciiName = 'file';
$utf8Enc   = rawurlencode((string)$row['original_name']);

header('Content-Type: ' . $row['mime_type']);
header('Content-Length: ' . (int)$row['size_bytes']);
header('Content-Disposition: ' . $disposition
     . '; filename="' . $asciiName . '"'
     . "; filename*=UTF-8''" . $utf8Enc);
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');

// Stream — readfile is fine for files up to a few hundred MB; we cap at 25 MB.
while (ob_get_level() > 0) { @ob_end_clean(); }
readfile($diskPath);
exit;
