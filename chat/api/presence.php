<?php
// /chat/api/presence.php — heartbeat + online-status query (Phase 8).
//
// Endpoints:
//   POST ?action=ping
//        Updates chat_user_presence.last_seen_at to NOW() for the
//        current user. Called every ~30s by the chat tab while open.
//
//   GET  ?action=list
//        Returns { presence: { <user_id>: 0|1 } } for every active user
//        in the workspace. Online = last_seen_at within 90s. Clients
//        poll this every ~30s to refresh sidebar/avatar dots.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = chat_api_require_login();
$ws   = (int)$user['workspace_id'];
$uid  = (int)$user['id'];

$action = (string)($_GET['action'] ?? '');

switch ($action) {
  case 'ping':
    chat_api_require_post();
    chat_api_require_csrf();
    chat_presence_ping($uid);
    chat_json(['ok' => true, 'last_seen_at' => now()]);

  case 'list':
    chat_json(['presence' => chat_presence_map($ws)]);

  default:
    chat_json_error('unknown_action', "Unknown action: $action", 400);
}
