<?php
// /chat/api/convert.php — "Convert chat message → AntonX task".
//
//   POST ?action=message_to_task { message_id, project_id, phase_id?, title,
//                                  assignees[]?, due_date?, description? }
//   GET  ?action=task_form_context — list of projects/phases/people the
//                                    UI uses to populate the modal selects.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

$user = chat_api_require_login();
$ws   = (int)$user['workspace_id'];
$uid  = (int)$user['id'];
$pdo  = db();
$action = (string)($_GET['action'] ?? '');

switch ($action) {
  case 'task_form_context': convert_form_context($pdo, $ws);
  case 'message_to_task':   convert_message_to_task($pdo, $ws, $uid, $user);
  default: chat_json_error('unknown_action', "Unknown action: $action", 400);
}

function convert_form_context(PDO $pdo, int $ws): never {
  $projStmt = $pdo->prepare('SELECT id, name FROM projects WHERE workspace_id = ? ORDER BY name ASC');
  $projStmt->execute([$ws]);
  $phaseStmt = $pdo->prepare(
    'SELECT id, project_id, name FROM phases WHERE workspace_id = ? ORDER BY project_id, sort_order, id ASC'
  );
  $phaseStmt->execute([$ws]);
  $peopleStmt = $pdo->prepare(
    'SELECT id, name FROM users WHERE workspace_id = ? AND is_active = 1 AND IFNULL(is_system, 0) = 0 ORDER BY name ASC'
  );
  $peopleStmt->execute([$ws]);
  chat_json([
    'projects' => $projStmt->fetchAll(),
    'phases'   => $phaseStmt->fetchAll(),
    'people'   => $peopleStmt->fetchAll(),
  ]);
}

function convert_message_to_task(PDO $pdo, int $ws, int $uid, array $user): never {
  chat_api_require_post();
  chat_api_require_csrf();

  $messageId = (int)($_POST['message_id'] ?? 0);
  $projectId = (int)($_POST['project_id'] ?? 0);
  $phaseId   = (int)($_POST['phase_id']   ?? 0);
  $title     = trim((string)($_POST['title'] ?? ''));
  $desc      = trim((string)($_POST['description'] ?? ''));
  $dueDate   = trim((string)($_POST['due_date'] ?? ''));
  $assignees = $_POST['assignees'] ?? [];
  if (!is_array($assignees)) $assignees = [];
  $assignees = array_values(array_unique(array_map('intval', $assignees)));

  if ($messageId <= 0)  chat_json_error('bad_request', 'Missing message_id.', 400);
  if ($projectId <= 0)  chat_json_error('bad_request', 'Pick a project.', 400);
  if ($title === '')    chat_json_error('bad_request', 'Title is required.', 400);
  if (mb_strlen($title) > 220) chat_json_error('bad_request', 'Title too long.', 400);
  if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
    chat_json_error('bad_request', 'Bad due_date format.', 400);
  }

  // Confirm the message is in the user's workspace + visible.
  $msgStmt = $pdo->prepare('SELECT * FROM chat_messages WHERE id = ? AND workspace_id = ?');
  $msgStmt->execute([$messageId, $ws]);
  $msg = $msgStmt->fetch();
  if (!$msg) chat_json_error('not_found', 'Message not found.', 404);

  // Confirm the project belongs to the workspace.
  $st = $pdo->prepare('SELECT id, name FROM projects WHERE id = ? AND workspace_id = ? LIMIT 1');
  $st->execute([$projectId, $ws]);
  $project = $st->fetch();
  if (!$project) chat_json_error('not_found', 'Project not found.', 404);

  // Auto-pick a phase if the caller didn't pass one (first phase of the project).
  if ($phaseId <= 0) {
    $ph = $pdo->prepare('SELECT id FROM phases WHERE project_id = ? ORDER BY sort_order, id LIMIT 1');
    $ph->execute([$projectId]);
    $phaseId = (int)($ph->fetchColumn() ?: 0);
  }
  if ($phaseId <= 0) chat_json_error('bad_request', 'Project has no phases yet — create one in AntonX first.', 400);

  // Insert the task.
  $now = now();
  $pdo->beginTransaction();
  try {
    $ins = $pdo->prepare(
      'INSERT INTO tasks
         (workspace_id, project_id, phase_id, title, description, status, due_date, created_by, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, "To Do", ?, ?, ?, ?)'
    );
    $ins->execute([$ws, $projectId, $phaseId, $title, $desc ?: null, $dueDate ?: null, $uid, $now, $now]);
    $taskId = (int)$pdo->lastInsertId();

    $insA = $pdo->prepare('INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?, ?)');
    foreach ($assignees as $a) {
      if ($a > 0) $insA->execute([$taskId, $a]);
    }
    chat_link_message_to_entity($ws, $messageId, 'task', $taskId);

    // Append a "Tracked in task#N" footer to the original message so anyone
    // who scrolls past sees the live link. Keeps the existing content intact.
    $newContent = (string)$msg['content']
      . "\n<p class=\"chat-tracked\">📌 Tracked in <a href=\"/task_details.php?id={$taskId}\">task#{$taskId}</a></p>";
    $up = $pdo->prepare('UPDATE chat_messages SET content = ?, edited_at = ? WHERE id = ?');
    $up->execute([$newContent, $now, $messageId]);
    chat_emit_event($ws, 'message_edited',
      $msg['channel_id']      !== null ? (int)$msg['channel_id']      : null,
      $msg['conversation_id'] !== null ? (int)$msg['conversation_id'] : null,
      $messageId, $uid, null
    );

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    chat_json_error('server_error', $e->getMessage(), 500);
  }

  // Sync the project channel + notify the assignees.
  if (file_exists(__DIR__ . '/../includes/project_sync.php')) {
    require_once __DIR__ . '/../includes/project_sync.php';
    try { chat_project_sync($projectId); } catch (Throwable $e) {}
  }
  foreach ($assignees as $a) {
    if ($a > 0 && $a !== $uid) {
      try { chat_notify_task_assigned($taskId, $a, $uid); } catch (Throwable $e) {}
    }
  }

  chat_json([
    'ok'       => true,
    'task_id'  => $taskId,
    'task_url' => '/task_details.php?id=' . $taskId,
    'project_name' => (string)$project['name'],
  ], 201);
}
