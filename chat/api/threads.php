<?php
// /chat/api/threads.php — threads the user is participating in (Phase 10).
// Distinct from messages.php?action=list&parent_message_id=N which fetches
// replies for one specific thread. This endpoint lists thread parents
// across the workspace where the user authored OR replied.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = chat_api_require_login();
$ws   = (int)$user['workspace_id'];
$uid  = (int)$user['id'];
$action = (string)($_GET['action'] ?? 'list');

switch ($action) {
  case 'list':
    chat_json(['threads' => chat_load_user_threads($ws, $uid, 50)]);
  default:
    chat_json_error('unknown_action', "Unknown action: $action", 400);
}
