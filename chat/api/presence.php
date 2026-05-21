<?php
// /chat/api/presence.php — heartbeat + 4-state presence (Phase 10).
//
//   POST ?action=ping
//        Updates chat_user_presence.last_seen_at to NOW() for the
//        current user. Called every ~30s by the chat tab while open.
//
//   GET  ?action=list
//        Returns { presence: { <user_id>: 'active'|'idle'|'offline'|'dnd' } }
//        active = last_seen ≤ 60s, idle = ≤ 300s, dnd = pref dnd=1,
//        offline otherwise.

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
    // Return presence + custom-status maps in one round-trip so the JS
    // periodic refresh updates both with a single fetch.
    chat_json([
      'presence' => chat_presence_state_map($ws),
      'statuses' => chat_load_status_map($ws),
    ]);

  default:
    chat_json_error('unknown_action', "Unknown action: $action", 400);
}
