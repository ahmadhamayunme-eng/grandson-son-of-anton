<?php
// /chat/api/dms.php — direct-message conversation endpoints (Phase 4).
//
// Endpoints (selected by ?action=...):
//   GET  ?action=list             DMs the user is in (for sidebar)
//   GET  ?action=people           workspace users for the "New DM" picker
//   GET  ?action=info&id=N        single DM detail
//   POST ?action=create           { user_ids[] } -> { conversation: {id, is_group}, created }
//                                 - 1-on-1 (one user_id): idempotent via dm_key
//                                 - Group (2+ user_ids):  always creates a new row
//                                 - Max 7 others (8 total including the creator)

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
  case 'list':    dm_action_list($pdo, $ws, $uid);
  case 'people':  dm_action_people($pdo, $ws, $uid);
  case 'info':    dm_action_info($pdo, $ws, $uid);
  case 'create':  dm_action_create($pdo, $ws, $uid);
  default:        chat_json_error('unknown_action', "Unknown action: $action", 400);
}

function dm_action_list(PDO $pdo, int $ws, int $uid): never {
  chat_json(['conversations' => chat_load_user_dms($ws, $uid)]);
}

function dm_action_people(PDO $pdo, int $ws, int $uid): never {
  // Active users in the workspace, excluding self. The "New DM" picker
  // uses this to populate its checkbox list.
  $stmt = $pdo->prepare(
    'SELECT id, name FROM users
     WHERE workspace_id = ? AND id != ? AND is_active = 1
     ORDER BY name ASC'
  );
  $stmt->execute([$ws, $uid]);
  chat_json(['people' => $stmt->fetchAll()]);
}

function dm_action_info(PDO $pdo, int $ws, int $uid): never {
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) chat_json_error('bad_request', 'Missing id', 400);
  $dm = chat_load_dm_by_id($ws, $uid, $id);
  if (!$dm) chat_json_error('not_found', 'DM not found.', 404);
  $dm['member_count'] = chat_dm_member_count((int)$dm['id']);
  chat_json(['conversation' => $dm]);
}

function dm_action_create(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();

  // Accept user_ids[] (multiple) or user_id (single 1-on-1 shortcut).
  $rawIds = $_POST['user_ids'] ?? null;
  if (!is_array($rawIds)) {
    $rawIds = isset($_POST['user_id']) ? [(int)$_POST['user_id']] : [];
  }
  $userIds = [];
  foreach ($rawIds as $r) {
    $n = (int)$r;
    if ($n > 0 && $n !== $uid && !in_array($n, $userIds, true)) $userIds[] = $n;
  }
  if (empty($userIds))     chat_json_error('bad_request', 'Pick at least one other person.', 400);
  if (count($userIds) > 7) chat_json_error('too_many',    'Group DMs are limited to 8 people total.', 400);

  // Confirm every picked user is in this workspace and active.
  $placeholders = implode(',', array_fill(0, count($userIds), '?'));
  $stmt = $pdo->prepare("SELECT id FROM users WHERE workspace_id = ? AND id IN ($placeholders) AND is_active = 1");
  $stmt->execute(array_merge([$ws], $userIds));
  $validIds = array_column($stmt->fetchAll(), 'id');
  if (count($validIds) !== count($userIds)) {
    chat_json_error('forbidden', 'One or more users are not in your workspace.', 403);
  }

  $isGroup = count($userIds) > 1;
  $now     = now();

  $pdo->beginTransaction();
  try {
    if (!$isGroup) {
      // 1-on-1: idempotent on dm_key.
      $dmKey = chat_dm_key_for_pair($uid, $userIds[0]);
      $stmt = $pdo->prepare(
        'SELECT id, is_group FROM chat_direct_conversations
         WHERE workspace_id = ? AND dm_key = ? LIMIT 1'
      );
      $stmt->execute([$ws, $dmKey]);
      $existing = $stmt->fetch();
      if ($existing) {
        $pdo->commit();
        chat_json([
          'conversation' => ['id' => (int)$existing['id'], 'is_group' => (int)$existing['is_group']],
          'created'      => false,
        ]);
      }
      $stmt = $pdo->prepare(
        'INSERT INTO chat_direct_conversations (workspace_id, is_group, dm_key, created_by, created_at)
         VALUES (?, 0, ?, ?, ?)'
      );
      $stmt->execute([$ws, $dmKey, $uid, $now]);
    } else {
      // Group: always a new row. No dedup in this phase.
      $stmt = $pdo->prepare(
        'INSERT INTO chat_direct_conversations (workspace_id, is_group, dm_key, created_by, created_at)
         VALUES (?, 1, NULL, ?, ?)'
      );
      $stmt->execute([$ws, $uid, $now]);
    }
    $convId = (int)$pdo->lastInsertId();

    // Add every member (self + picked others).
    $allIds = array_merge([$uid], $userIds);
    $memStmt = $pdo->prepare(
      'INSERT INTO chat_direct_conversation_members (conversation_id, user_id, joined_at)
       VALUES (?, ?, ?)'
    );
    foreach ($allIds as $mid) { $memStmt->execute([$convId, $mid, $now]); }

    // SSE event so other members' clients can refresh their sidebar later.
    chat_emit_event($ws, 'dm_created', null, $convId, null, $uid, ['member_ids' => $allIds]);

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  chat_json([
    'conversation' => ['id' => $convId, 'is_group' => $isGroup ? 1 : 0],
    'created'      => true,
  ], 201);
}
