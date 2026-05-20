<?php
// /chat/api/status.php — custom user status (Slack-style).
//
//   POST ?action=set   { emoji, text, expires_in? }
//        expires_in is seconds-from-now (60..86400). Omit for "Don't clear".
//   POST ?action=clear                — drop emoji + text in one shot
//   GET  ?action=list                 — workspace-wide status map
//   GET  ?action=mine                 — current user's row

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
  case 'set':   status_action_set($pdo, $ws, $uid);
  case 'clear': status_action_clear($pdo, $ws, $uid);
  case 'list':  status_action_list($pdo, $ws);
  case 'mine':  chat_json(['prefs' => chat_load_user_prefs($uid)]);
  default:      chat_json_error('unknown_action', "Unknown action: $action", 400);
}

function status_action_set(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();

  // Trim + cap the emoji to a few code points so we don't store something
  // wild. mb_substr is fine for emoji ZWJ sequences (counts as one).
  $emoji = trim((string)($_POST['emoji'] ?? ''));
  if ($emoji === '') chat_json_error('bad_request', 'Emoji is required.', 400);
  if (mb_strlen($emoji, 'UTF-8') > 8) $emoji = mb_substr($emoji, 0, 8, 'UTF-8');

  $text  = trim((string)($_POST['text'] ?? ''));
  if (mb_strlen($text, 'UTF-8') > 120) {
    $text = mb_substr($text, 0, 120, 'UTF-8');
  }

  // Optional auto-expiry. Clamp to [60s, 24h]. If absent or 0, never expires.
  $expiresIn = (int)($_POST['expires_in'] ?? 0);
  $expiresAt = null;
  if ($expiresIn > 0) {
    $expiresIn = max(60, min(86400, $expiresIn));
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
  }

  $now = now();
  $sql = 'INSERT INTO chat_user_prefs (user_id, status_emoji, status_text, status_expires_at, updated_at)
          VALUES (?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE
            status_emoji      = VALUES(status_emoji),
            status_text       = VALUES(status_text),
            status_expires_at = VALUES(status_expires_at),
            updated_at        = VALUES(updated_at)';
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$uid, $emoji, $text !== '' ? $text : null, $expiresAt, $now]);

  chat_json([
    'ok'     => true,
    'status' => [
      'emoji'      => $emoji,
      'text'       => $text,
      'expires_at' => $expiresAt,
      'user_id'    => $uid,
    ],
  ]);
}

function status_action_clear(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();
  $now = now();
  $sql = 'INSERT INTO chat_user_prefs (user_id, status_emoji, status_text, status_expires_at, updated_at)
          VALUES (?, NULL, NULL, NULL, ?)
          ON DUPLICATE KEY UPDATE
            status_emoji      = NULL,
            status_text       = NULL,
            status_expires_at = NULL,
            updated_at        = VALUES(updated_at)';
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$uid, $now]);
  chat_json(['ok' => true, 'user_id' => $uid]);
}

function status_action_list(PDO $pdo, int $ws): never {
  chat_json(['statuses' => chat_load_status_map($ws)]);
}
