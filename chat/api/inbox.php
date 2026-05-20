<?php
// /chat/api/inbox.php — unified inbox: @mentions + DMs (Phase 10).

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
    chat_json(['messages' => chat_load_user_inbox($ws, $uid, 50)]);
  default:
    chat_json_error('unknown_action', "Unknown action: $action", 400);
}
