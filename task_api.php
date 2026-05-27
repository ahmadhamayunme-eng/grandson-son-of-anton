<?php
// task_api.php — lightweight JSON endpoint powering the global "Edit Task"
// modal (partials/task_edit_modal.php) so a task can be fully edited from
// anywhere it's listed.
//
//   GET  ?action=get&id=N   → task fields + assignees + the project's phases,
//                             workspace statuses and assignable team.
//   POST ?action=update     → update title/description/status/due_date/phase
//                             + assignees. Manager roles only.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';

header('Content-Type: application/json; charset=utf-8');

function tapi_out(array $d): never { echo json_encode($d); exit; }
function tapi_err(string $msg, int $code = 400): never {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg]);
  exit;
}

$user = auth_user();
if (!$user) tapi_err('Not signed in.', 401);

$ws        = (int)($user['workspace_id'] ?? 0);
$uid       = (int)($user['id'] ?? 0);
$role      = (string)($user['role_name'] ?? '');
$canManage = in_array($role, ['CEO', 'Manager', 'Super Admin'], true);
$canFinal  = $canManage;
$pdo       = db();

/** Workspace task-status names, filtered to what this user may set. */
function tapi_status_options(PDO $pdo, int $ws, bool $canFinal): array {
  try {
    $st = $pdo->prepare('SELECT name FROM task_statuses WHERE workspace_id = ? ORDER BY sort_order ASC');
    $st->execute([$ws]);
    $list = array_map(fn($r) => $r['name'], $st->fetchAll());
  } catch (Throwable $e) { $list = []; }
  if (!$list) $list = ['To Do', 'In Progress', 'Completed', 'Approved', 'Submitted to Client'];
  if (!$canFinal) {
    $list = array_values(array_filter($list, fn($s) => !in_array($s, ['Approved', 'Approved (Ready to Submit)', 'Submitted to Client'], true)));
  }
  return $list;
}

$action = (string)($_GET['action'] ?? '');

if ($action === 'get') {
  $id = (int)($_GET['id'] ?? 0);
  $st = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND workspace_id = ?');
  $st->execute([$id, $ws]);
  $task = $st->fetch();
  if (!$task) tapi_err('Task not found.', 404);

  $as = $pdo->prepare('SELECT user_id FROM task_assignees WHERE task_id = ?');
  $as->execute([$id]);
  $assignees = array_map(fn($r) => (int)$r['user_id'], $as->fetchAll());

  $ph = $pdo->prepare('SELECT id, name FROM phases WHERE workspace_id = ? AND project_id = ? ORDER BY sort_order ASC');
  $ph->execute([$ws, (int)$task['project_id']]);
  $phases = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $ph->fetchAll());

  $tm = $pdo->prepare("SELECT u.id, u.name, r.name AS role_name
    FROM users u JOIN roles r ON r.id = u.role_id
    WHERE u.workspace_id = ? AND u.is_active = 1 ORDER BY u.name ASC");
  $tm->execute([$ws]);
  $team = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'role' => (string)$r['role_name']], $tm->fetchAll());

  tapi_out([
    'ok'         => true,
    'can_manage' => $canManage,
    'task'       => [
      'id'          => (int)$task['id'],
      'title'       => (string)$task['title'],
      'description' => (string)($task['description'] ?? ''),
      'status'      => (string)$task['status'],
      'due_date'    => $task['due_date'] ? date('Y-m-d', strtotime((string)$task['due_date'])) : '',
      'phase_id'    => (int)($task['phase_id'] ?? 0),
      'project_id'  => (int)$task['project_id'],
    ],
    'assignees'  => $assignees,
    'phases'     => $phases,
    'statuses'   => tapi_status_options($pdo, $ws, $canFinal),
    'team'       => $team,
  ]);
}

if ($action === 'update') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') tapi_err('POST required.', 405);
  $token = $_POST['csrf'] ?? '';
  if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) tapi_err('Invalid CSRF token.', 403);
  if (!$canManage) tapi_err('You do not have permission to edit tasks.', 403);

  $id = (int)($_POST['id'] ?? 0);
  $st = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND workspace_id = ?');
  $st->execute([$id, $ws]);
  $task = $st->fetch();
  if (!$task) tapi_err('Task not found.', 404);

  $title = trim((string)($_POST['title'] ?? ''));
  if ($title === '') tapi_err('Title is required.');
  if (mb_strlen($title) > 220) $title = mb_substr($title, 0, 220);

  $desc   = trim((string)($_POST['description'] ?? ''));
  $status = trim((string)($_POST['status'] ?? $task['status']));
  $due    = trim((string)($_POST['due_date'] ?? ''));
  $phaseId = (int)($_POST['phase_id'] ?? (int)$task['phase_id']);

  $allowed = tapi_status_options($pdo, $ws, $canFinal);
  if (!in_array($status, $allowed, true)) tapi_err('You cannot set that status.');

  // Phase must belong to the same project (or be left as-is).
  if ($phaseId > 0) {
    $pc = $pdo->prepare('SELECT 1 FROM phases WHERE id = ? AND workspace_id = ? AND project_id = ?');
    $pc->execute([$phaseId, $ws, (int)$task['project_id']]);
    if (!$pc->fetchColumn()) tapi_err('That phase does not belong to this project.');
  } else {
    $phaseId = (int)$task['phase_id'];
  }

  $dueVal = $due !== '' ? date('Y-m-d', strtotime($due)) : null;
  $now = now();

  // Mirror the submitted_at bookkeeping the other task flows rely on so the
  // review / submit / archive queues stay correct after an edit.
  $setSubmitted = ($status === 'Submitted to Client');
  $setReview    = in_array($status, ['Completed', 'Completed (Needs Manager Review)'], true);

  if ($setSubmitted) {
    $pdo->prepare('UPDATE tasks SET title=?, description=?, status=?, due_date=?, phase_id=?, submitted_at=COALESCE(submitted_at, ?), submitted_by=COALESCE(submitted_by, ?), updated_at=? WHERE id=? AND workspace_id=?')
        ->execute([$title, $desc !== '' ? $desc : null, $status, $dueVal, $phaseId, $now, $uid, $now, $id, $ws]);
  } elseif ($setReview) {
    $pdo->prepare('UPDATE tasks SET title=?, description=?, status=?, due_date=?, phase_id=?, submitted_at=COALESCE(submitted_at, ?), submitted_by=COALESCE(submitted_by, ?), updated_at=? WHERE id=? AND workspace_id=?')
        ->execute([$title, $desc !== '' ? $desc : null, $status, $dueVal, $phaseId, $now, $uid, $now, $id, $ws]);
  } else {
    $pdo->prepare('UPDATE tasks SET title=?, description=?, status=?, due_date=?, phase_id=?, updated_at=? WHERE id=? AND workspace_id=?')
        ->execute([$title, $desc !== '' ? $desc : null, $status, $dueVal, $phaseId, $now, $id, $ws]);
  }

  // Replace assignees with the submitted set (validated against the workspace).
  $valid = [];
  try {
    $vstmt = $pdo->prepare('SELECT id FROM users WHERE workspace_id = ? AND is_active = 1');
    $vstmt->execute([$ws]);
    $valid = array_map(fn($r) => (int)$r['id'], $vstmt->fetchAll());
  } catch (Throwable $e) { $valid = []; }
  $selected = array_values(array_unique(array_intersect(array_map('intval', $_POST['assignees'] ?? []), $valid)));
  $pdo->prepare('DELETE FROM task_assignees WHERE task_id = ?')->execute([$id]);
  foreach ($selected as $auid) {
    $pdo->prepare('INSERT INTO task_assignees (task_id, user_id) VALUES (?, ?)')->execute([$id, $auid]);
  }

  // Keep the project chat channel membership in sync (fire-and-forget).
  if (file_exists(__DIR__ . '/chat/includes/project_sync.php')) {
    require_once __DIR__ . '/chat/includes/project_sync.php';
    try { chat_project_sync((int)$task['project_id']); } catch (Throwable $e) {}
  }

  tapi_out(['ok' => true, 'id' => $id]);
}

tapi_err('Unknown action.', 400);
