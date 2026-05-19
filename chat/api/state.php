<?php
// /chat/api/state.php — per-user channel/DM state mutations (Phase 8).
//
// Currently only mark_read; future per-user state like channel mute or
// notification overrides slot in here.
//
//   POST ?action=mark_read
//        { channel_id | conversation_id }
//        Sets last_read_message_id to the channel/DM's current MAX(id),
//        which zeroes the unread badge until the next new message lands.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = chat_api_require_login();
chat_api_require_post();
chat_api_require_csrf();

$ws  = (int)$user['workspace_id'];
$uid = (int)$user['id'];

$action = (string)($_GET['action'] ?? '');

switch ($action) {
  case 'mark_read':
    $channelId      = (int)($_POST['channel_id']      ?? 0);
    $conversationId = (int)($_POST['conversation_id'] ?? 0);
    if ($channelId > 0) {
      if (!chat_user_is_channel_member($channelId, $uid)) {
        chat_json_error('not_member', '', 403);
      }
      chat_mark_channel_read($channelId, $uid);
      chat_json(['ok' => true, 'channel_id' => $channelId]);
    } elseif ($conversationId > 0) {
      if (!chat_user_is_dm_member($conversationId, $uid)) {
        chat_json_error('forbidden', '', 403);
      }
      chat_mark_dm_read($conversationId, $uid);
      chat_json(['ok' => true, 'conversation_id' => $conversationId]);
    } else {
      chat_json_error('bad_request', 'Missing channel_id or conversation_id', 400);
    }

  default:
    chat_json_error('unknown_action', "Unknown action: $action", 400);
}
