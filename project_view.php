<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();

$pdo = db();
$ws = auth_workspace_id();
$user = auth_user();
$role = $user['role_name'] ?? '';
$can_manage = in_array($role, ['CEO', 'Manager', 'Super Admin'], true);
$can_final_status = $can_manage;
$isSuperAdmin = $role === 'Super Admin';

$id = (int)($_GET['id'] ?? 0);
$loadError = null;
$project = null;
$phases = []; $statuses = []; $team = [];
$tasks = []; $by_phase = [];
$docsQ = trim((string)($_GET['dq'] ?? ''));
$docs = []; $activities = [];
$totalTasks = 0; $doneTasks = 0; $inProgressCount = 0; $todoCount = 0; $progress = 0;

function pv_task_status_options(PDO $pdo, int $ws, bool $can_final_status): array {
  try {
    $st = $pdo->prepare('SELECT name FROM task_statuses WHERE workspace_id = ? ORDER BY sort_order ASC');
    $st->execute([$ws]);
    $list = array_map(fn($r) => $r['name'], $st->fetchAll());
  } catch (Throwable $e) { $list = []; }
  if (!$list) $list = ['To Do','In Progress','Completed','Approved','Submitted to Client'];
  if (!$can_final_status) {
    $list = array_values(array_filter($list, fn($s) => !in_array($s, ['Approved','Approved (Ready to Submit)','Submitted to Client'], true)));
  }
  return $list;
}
function pv_status_pill(string $status): string {
  $s = strtolower($status);
  if (strpos($s, 'progress') !== false) return 'progress';
  if (strpos($s, 'done') !== false || strpos($s, 'complete') !== false || strpos($s, 'approved') !== false || strpos($s, 'submit') !== false) return 'done';
  if (strpos($s, 'review') !== false) return 'review';
  return 'todo';
}
function pv_avatar_gradient(string $seed): string {
  $palette = [
    'linear-gradient(135deg,#10b981,#059669)','linear-gradient(135deg,#f43f5e,#9f1239)',
    'linear-gradient(135deg,#0ea5e9,#0369a1)','linear-gradient(135deg,#a855f7,#7c3aed)',
    'linear-gradient(135deg,#fb923c,#ea580c)','linear-gradient(135deg,#ec4899,#be185d)',
    'linear-gradient(135deg,#7c3aed,#5b21b6)','linear-gradient(135deg,#22c55e,#16a34a)',
    'linear-gradient(135deg,#facc15,#ca8a04)','linear-gradient(135deg,#64748b,#334155)',
  ];
  return $palette[crc32($seed) % count($palette)];
}

try {
  $projectStmt = $pdo->prepare("SELECT p.*, c.name AS client_name, pt.name AS type_name, ps.name AS status_name
    FROM projects p
    JOIN clients c ON c.id = p.client_id
    JOIN project_types pt ON pt.id = p.type_id
    JOIN project_statuses ps ON ps.id = p.status_id
    WHERE p.id = ? AND p.workspace_id = ?");
  $projectStmt->execute([$id, $ws]);
  $project = $projectStmt->fetch();

  if (!$project) {
    $pageTitle = 'Project not found';
    $activeKey = 'projects';
    require_once __DIR__ . '/layout.php';
    echo '<div style="padding:48px 32px;text-align:center;color:var(--text-muted);"><h3 style="margin-bottom:12px;color:var(--text);">Project not found</h3><p>The project you are looking for does not exist or you do not have access.</p><div style="margin-top:18px;">' . back_button_html('projects.php', 'Back to Projects') . '</div></div>';
    require_once __DIR__ . '/layout_end.php';
    exit;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post(); csrf_verify();

    if (isset($_POST['update_task_status'])) {
      if (!$can_manage) { flash_set('error', 'No permission.'); redirect("project_view.php?id=$id"); }
      $taskUpdateId = (int)($_POST['task_id'] ?? 0);
      $newStatus = trim((string)($_POST['status'] ?? ''));
      $allowedStatuses = pv_task_status_options($pdo, $ws, $can_final_status);
      if ($taskUpdateId <= 0 || $newStatus === '' || !in_array($newStatus, $allowedStatuses, true)) {
        flash_set('error', 'You cannot set that status.');
        redirect("project_view.php?id=$id#tasks");
      }
      $now = now();
      if ($newStatus === 'Submitted to Client') {
        $st = $pdo->prepare('UPDATE tasks SET status=?, submitted_at=?, submitted_by=?, updated_at=? WHERE workspace_id=? AND project_id=? AND id=?');
        $st->execute([$newStatus, $now, (int)$user['id'], $now, $ws, $id, $taskUpdateId]);
      } else {
        $st = $pdo->prepare('UPDATE tasks SET status=?, updated_at=? WHERE workspace_id=? AND project_id=? AND id=?');
        $st->execute([$newStatus, $now, $ws, $id, $taskUpdateId]);
      }
      flash_set('success', 'Task status updated.');
      if (in_array($newStatus, ['Completed','Completed (Needs Manager Review)'], true)) redirect('manager_review.php');
      if (in_array($newStatus, ['Approved','Approved (Ready to Submit)'], true)) redirect('manager_submit.php');
      if ($newStatus === 'Submitted to Client') redirect('completed_task_archive.php');
      redirect("project_view.php?id=$id#tasks");
    }

    if (isset($_POST['delete_phase'])) {
      if (!$can_manage) { flash_set('error', 'No permission.'); redirect("project_view.php?id=$id"); }
      $phaseDeleteId = (int)($_POST['phase_id'] ?? 0);
      if ($phaseDeleteId <= 0) { flash_set('error', 'Invalid phase selected.'); redirect("project_view.php?id=$id"); }
      $st = $pdo->prepare('DELETE FROM phases WHERE workspace_id = ? AND project_id = ? AND id = ?');
      $st->execute([$ws, $id, $phaseDeleteId]);
      flash_set('success', $st->rowCount() ? 'Phase deleted permanently.' : 'Phase not found.');
      redirect("project_view.php?id=$id#tasks");
    }
    if (isset($_POST['delete_task'])) {
      if (!$can_manage) { flash_set('error', 'No permission.'); redirect("project_view.php?id=$id"); }
      $taskDeleteId = (int)($_POST['task_id'] ?? 0);
      if ($taskDeleteId <= 0) { flash_set('error', 'Invalid task selected.'); redirect("project_view.php?id=$id"); }
      $st = $pdo->prepare('DELETE FROM tasks WHERE workspace_id = ? AND project_id = ? AND id = ?');
      $st->execute([$ws, $id, $taskDeleteId]);
      flash_set('success', $st->rowCount() ? 'Task deleted permanently.' : 'Task not found.');
      redirect("project_view.php?id=$id#tasks");
    }
    if (isset($_POST['bulk_delete_tasks'])) {
      if (!$can_manage) { flash_set('error', 'No permission.'); redirect("project_view.php?id=$id"); }
      $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['task_ids'] ?? [])))));
      if (!$ids) { flash_set('error', 'Select at least one task to delete.'); redirect("project_view.php?id=$id#tasks"); }
      $marks = implode(',', array_fill(0, count($ids), '?'));
      $st = $pdo->prepare("DELETE FROM tasks WHERE workspace_id = ? AND project_id = ? AND id IN ($marks)");
      $st->execute(array_merge([$ws, $id], $ids));
      flash_set('success', $st->rowCount() . ' task(s) deleted permanently.');
      redirect("project_view.php?id=$id#tasks");
    }
    if (isset($_POST['delete_doc'])) {
      if (!$can_manage) { flash_set('error', 'No permission.'); redirect("project_view.php?id=$id"); }
      $docDeleteId = (int)($_POST['doc_id'] ?? 0);
      if ($docDeleteId <= 0) { flash_set('error', 'Invalid document selected.'); redirect("project_view.php?id=$id#docs"); }
      $st = $pdo->prepare('DELETE FROM docs WHERE workspace_id = ? AND project_id = ? AND id = ?');
      $st->execute([$ws, $id, $docDeleteId]);
      flash_set('success', $st->rowCount() ? 'Document deleted permanently.' : 'Document not found.');
      redirect("project_view.php?id=$id#docs");
    }
    if (isset($_POST['add_phase'])) {
      if (!$can_manage) { flash_set('error', 'No permission.'); redirect("project_view.php?id=$id"); }
      $name = trim($_POST['phase_name'] ?? '');
      if ($name === '') { flash_set('error', 'Phase name required.'); redirect("project_view.php?id=$id#tasks"); }
      $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM phases WHERE project_id = ? AND workspace_id = ?');
      $sortStmt->execute([$id, $ws]);
      $sort = (int)($sortStmt->fetch()['n'] ?? 1);
      $pdo->prepare('INSERT INTO phases (workspace_id,project_id,name,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?)')
        ->execute([$ws, $id, $name, $sort, now(), now()]);
      flash_set('success', 'Phase added.');
      redirect("project_view.php?id=$id#tasks");
    }
    if (isset($_POST['add_task'])) {
      if (!$can_manage) { flash_set('error', 'No permission.'); redirect("project_view.php?id=$id"); }
      $phase_id = (int)($_POST['phase_id'] ?? 0);
      $title = trim($_POST['title'] ?? '');
      $desc = trim($_POST['description'] ?? '');
      $status = trim($_POST['status'] ?? 'To Do');
      $due = $_POST['due_date'] ?? null;
      if ($title === '' || $phase_id <= 0) {
        flash_set('error', 'Task title and phase are required.');
        redirect("project_view.php?id=$id#tasks");
      }
      $pdo->prepare('INSERT INTO tasks (workspace_id,project_id,phase_id,title,description,status,due_date,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$ws, $id, $phase_id, $title, $desc ?: null, $status, $due ?: null, (int)$user['id'], now(), now()]);
      $task_id = (int)$pdo->lastInsertId();
      foreach (($_POST['assignees'] ?? []) as $uid) {
        $pdo->prepare('INSERT INTO task_assignees (task_id,user_id) VALUES (?,?)')->execute([$task_id, (int)$uid]);
      }
      // Attach-on-create: claim any files staged by the popup uploader.
      if (!empty($_POST['attachment_ids'])) {
        require_once __DIR__ . '/lib/task_attachments.php';
        claim_staged_task_attachments($pdo, $ws, (int)$user['id'], $task_id, (array)$_POST['attachment_ids']);
      }
      // Anton Jr. — sync the project's chat channel membership now that
      // a new task (and its assignees) exist. Safe to call repeatedly.
      if (file_exists(__DIR__ . '/chat/includes/project_sync.php')) {
        require_once __DIR__ . '/chat/includes/project_sync.php';
        try { chat_project_sync((int)$id); } catch (Throwable $e) {}
      }
      // DM each assignee with a card. Fire-and-forget.
      if (file_exists(__DIR__ . '/chat/includes/notifications.php')) {
        require_once __DIR__ . '/chat/includes/notifications.php';
        foreach (($_POST['assignees'] ?? []) as $uid) {
          try { chat_notify_task_assigned($task_id, (int)$uid, (int)$user['id']); } catch (Throwable $e) {}
        }
      }
      flash_set('success', 'Task created.');
      if (in_array($status, ['Completed','Completed (Needs Manager Review)'], true)) redirect('manager_review.php');
      if (in_array($status, ['Approved','Approved (Ready to Submit)'], true)) redirect('manager_submit.php');
      if ($status === 'Submitted to Client') redirect('completed_task_archive.php');
      redirect("project_view.php?id=$id#tasks");
    }
    if (isset($_POST['create_doc'])) {
      if (!$can_manage) { flash_set('error', 'No permission.'); redirect("project_view.php?id=$id"); }
      $title = trim($_POST['doc_title'] ?? '');
      $content = trim($_POST['doc_content'] ?? '');
      if ($title === '') { flash_set('error', 'Doc title required.'); redirect("project_view.php?id=$id#docs"); }
      $pdo->prepare('INSERT INTO docs (workspace_id,project_id,title,content,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$ws, $id, $title, $content ?: null, (int)$user['id'], now(), now()]);
      flash_set('success', 'Document created.');
      redirect("project_view.php?id=$id#docs");
    }
  }

  $phasesStmt = $pdo->prepare('SELECT * FROM phases WHERE workspace_id = ? AND project_id = ? ORDER BY sort_order ASC');
  $phasesStmt->execute([$ws, $id]);
  $phases = $phasesStmt->fetchAll();

  $statuses = pv_task_status_options($pdo, $ws, $can_final_status);

  $teamStmt = $pdo->prepare("SELECT u.id,u.name,r.name AS role_name
    FROM users u JOIN roles r ON r.id = u.role_id
    WHERE u.workspace_id = ? AND u.is_active = 1 ORDER BY u.name ASC");
  $teamStmt->execute([$ws]);
  $team = $teamStmt->fetchAll();

  $tasksStmt = $pdo->prepare("SELECT t.*, GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') AS assignee_names, COUNT(DISTINCT ta.user_id) assignee_count
    FROM tasks t
    LEFT JOIN task_assignees ta ON ta.task_id = t.id
    LEFT JOIN users u ON u.id = ta.user_id
    WHERE t.workspace_id = ? AND t.project_id = ?
    GROUP BY t.id
    ORDER BY COALESCE(t.due_date, DATE(t.updated_at)) ASC, t.id DESC");
  $tasksStmt->execute([$ws, $id]);
  $tasks = $tasksStmt->fetchAll();

  foreach ($tasks as $task) {
    $by_phase[$task['phase_id']][] = $task;
    $s = strtolower((string)$task['status']);
    if (strpos($s, 'progress') !== false) $inProgressCount++;
    elseif (strpos($s, 'approved') !== false || strpos($s, 'submitted') !== false || strpos($s, 'done') !== false || strpos($s, 'complete') !== false) $doneTasks++;
    else $todoCount++;
  }

  $docsStmt = $pdo->prepare("SELECT d.*, u.name AS author_name
    FROM docs d LEFT JOIN users u ON u.id = d.created_by
    WHERE d.workspace_id = ? AND d.project_id = ? AND d.title LIKE ?
    ORDER BY d.updated_at DESC, d.id DESC LIMIT 120");
  $docsStmt->execute([$ws, $id, '%' . $docsQ . '%']);
  $docs = $docsStmt->fetchAll();

  $activityStmt = $pdo->prepare("SELECT * FROM (
    SELECT t.updated_at AS happened_at, CONCAT('Task updated: ', t.title) AS message, COALESCE(u.name, 'System') AS actor, 'task' AS source
    FROM tasks t LEFT JOIN users u ON u.id = t.created_by WHERE t.workspace_id = ? AND t.project_id = ?
    UNION ALL
    SELECT d.updated_at AS happened_at, CONCAT('Doc updated: ', d.title) AS message, COALESCE(u.name, 'System') AS actor, 'doc' AS source
    FROM docs d LEFT JOIN users u ON u.id = d.created_by WHERE d.workspace_id = ? AND d.project_id = ?
    UNION ALL
    SELECT p.updated_at AS happened_at, CONCAT('Project updated: ', p.name) AS message, 'System' AS actor, 'project' AS source
    FROM projects p WHERE p.workspace_id = ? AND p.id = ?
  ) x ORDER BY x.happened_at DESC LIMIT 120");
  $activityStmt->execute([$ws, $id, $ws, $id, $ws, $id]);
  $activities = $activityStmt->fetchAll();

  $totalTasks = count($tasks);
  $progress = $totalTasks > 0 ? (int)round(($doneTasks / $totalTasks) * 100) : 0;
} catch (Throwable $e) {
  $loadError = 'Project page failed to load. Please refresh or contact admin.';
  if ($isSuperAdmin) $loadError .= ' Debug: ' . $e->getMessage();
}

$projectGrad = pv_avatar_gradient((string)$project['name']);
$clientGrad = pv_avatar_gradient((string)$project['client_name']);
$pageTitle = $project['name'];
$activeKey = 'projects';
$pageHeadExtra = <<<HTML
<style>
  .project-head { padding: 22px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
  .ph-left { min-width: 0; display: flex; align-items: flex-start; gap: 14px; }
  .ph-left .back-wrap { padding-top: 4px; }
  .project-title { font-size: 28px; font-weight: 700; letter-spacing: -0.025em; line-height: 1.1; display: flex; align-items: center; gap: 12px; }
  .project-title .icon-tile { width: 36px; height: 36px; border-radius: 9px; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; }
  .project-meta { display: flex; align-items: center; gap: 10px; margin-top: 8px; font-size: 13px; color: var(--text-muted); }
  .project-meta .client-mini-logo { width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 600; color: #fff; letter-spacing: 0.02em; }
  .project-meta .sep { color: var(--text-dim); }
  .project-meta .status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 500; color: #86efac; background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.25); }
  .status-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 2px rgba(34,197,94,0.18); }
  .ph-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .section-nav { position: sticky; top: 0; z-index: 30; background: rgba(10,10,10,0.92); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); margin-top: 22px; }
  .section-nav-inner { max-width: 1640px; margin: 0 auto; padding: 0 32px; display: flex; gap: 4px; }
  .section-nav a { padding: 14px 16px; font-size: 13px; font-weight: 500; color: var(--text-muted); border-bottom: 2px solid transparent; margin-bottom: -1px; transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
  .section-nav a:hover { color: var(--text); }
  .section-nav a.active { color: var(--text); border-bottom-color: var(--accent); }
  .section-nav a svg { width: 13px; height: 13px; }
  .section-nav a .count { font-size: 10.5px; background: var(--surface-2); color: var(--text-muted); padding: 1px 7px; border-radius: 999px; font-variant-numeric: tabular-nums; }
  .section-nav a.active .count { background: var(--accent-soft); color: var(--accent); }
  .body-wrap { max-width: 1640px; margin: 0 auto; width: 100%; padding: 32px 32px 60px; display: flex; flex-direction: column; gap: 36px; }
  .section { scroll-margin-top: 70px; }
  .section-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 14px; }
  .section-title { font-size: 18px; font-weight: 600; color: var(--text); letter-spacing: -0.01em; display: inline-flex; align-items: center; gap: 10px; }
  .section-title::before { content: ''; width: 3px; height: 18px; background: var(--accent); border-radius: 2px; }
  .section-title .count-pill { font-size: 11px; background: var(--surface); color: var(--text-muted); border: 1px solid var(--border); padding: 2px 8px; border-radius: 999px; font-variant-numeric: tabular-nums; font-weight: 500; }
  .section-action { font-size: 12.5px; color: var(--text-muted); display: inline-flex; align-items: center; gap: 6px; transition: color 0.12s; text-decoration: none; background: none; border: none; cursor: pointer; }
  .section-action:hover { color: var(--accent); }
  .section-action svg { width: 12px; height: 12px; }
  .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  .overview-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
  .ov-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 20px; display: flex; flex-direction: column; gap: 14px; }
  .ov-card .lbl { font-size: 10.5px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.12em; font-weight: 600; }
  .progress-num { display: flex; align-items: baseline; gap: 8px; }
  .progress-num .pct { font-size: 36px; font-weight: 700; color: var(--accent); letter-spacing: -0.02em; line-height: 1; font-variant-numeric: tabular-nums; }
  .progress-num .rat { font-size: 13px; color: var(--text-muted); font-variant-numeric: tabular-nums; }
  .progress-bar { height: 6px; background: var(--surface-2); border-radius: 3px; overflow: hidden; }
  .progress-fill { height: 100%; background: var(--accent); border-radius: 3px; transition: width 0.4s ease; }
  .progress-breakdown { display: flex; gap: 16px; font-size: 11.5px; color: var(--text-muted); font-variant-numeric: tabular-nums; }
  .progress-breakdown .lab { display: inline-flex; align-items: center; gap: 5px; }
  .progress-breakdown .lab .dot { width: 6px; height: 6px; border-radius: 50%; }
  .summary-text { font-size: 13.5px; color: var(--text); line-height: 1.6; }
  .summary-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding-top: 14px; border-top: 1px solid var(--border); }
  .summary-meta .row .k { font-size: 10.5px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.08em; font-weight: 600; }
  .summary-meta .row .v { font-size: 13px; color: var(--text); margin-top: 2px; font-variant-numeric: tabular-nums; }
  .people-row { display: flex; flex-direction: column; gap: 12px; }
  .person { display: flex; align-items: center; gap: 10px; }
  .person .av { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10.5px; font-weight: 600; color: #fff; letter-spacing: 0.02em; flex-shrink: 0; }
  .person .info { flex: 1; min-width: 0; }
  .person .name { font-size: 13px; font-weight: 500; color: var(--text); }
  .person .role { font-size: 11px; color: var(--text-dim); }
  table.tasks-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.tasks-table thead th { text-align: left; padding: 10px 16px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-dim); background: var(--bg); border-bottom: 1px solid var(--border); }
  table.tasks-table thead th.right { text-align: right; }
  table.tasks-table tbody tr { border-bottom: 1px solid var(--border); transition: background 0.12s; }
  table.tasks-table tbody tr:last-child { border-bottom: none; }
  table.tasks-table tbody tr:hover { background: var(--surface-hover); }
  table.tasks-table tbody td { padding: 14px 16px; color: var(--text); vertical-align: middle; }
  .task-cell { display: flex; align-items: center; gap: 12px; min-width: 0; }
  .task-title-link { font-weight: 600; font-size: 13.5px; color: var(--text); line-height: 1.3; text-decoration: none; }
  .task-title-link:hover { color: var(--accent); }
  .task-id { font-size: 11px; color: var(--text-dim); font-family: 'JetBrains Mono', ui-monospace, monospace; margin-top: 2px; }
  .tsp { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px 4px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 500; border: 1px solid; }
  .tsp .dot { width: 6px; height: 6px; border-radius: 50%; }
  .tsp.progress { color: var(--accent); background: var(--accent-soft); border-color: rgba(250,204,21,0.25); }
  .tsp.progress .dot { background: var(--accent); }
  .tsp.todo { color: #d1d5db; background: var(--surface-2); border-color: var(--border); }
  .tsp.todo .dot { background: #6b7280; }
  .tsp.review { color: #c4b5fd; background: rgba(168,85,247,0.08); border-color: rgba(168,85,247,0.25); }
  .tsp.review .dot { background: #a855f7; }
  .tsp.done { color: #86efac; background: rgba(34,197,94,0.08); border-color: rgba(34,197,94,0.25); }
  .tsp.done .dot { background: #22c55e; }
  .status-inline { display: inline-block; margin: 0; }
  .status-inline-select { appearance: none; -webkit-appearance: none; cursor: pointer; padding: 4px 22px 4px 22px; border-radius: 999px; font-size: 11.5px; font-weight: 500; border: 1px solid; line-height: 1.3; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 8px center; padding-right: 22px; position: relative; }
  .status-inline-select option { background: #1a1a1a; color: var(--text); font-weight: 500; }
  .assignee { display: flex; align-items: center; gap: 9px; }
  .assignee .av { width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: #fff; letter-spacing: 0.02em; }
  .due-mono { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12.5px; font-variant-numeric: tabular-nums; }
  .row-actions { display: flex; gap: 6px; justify-content: flex-end; }
  .btn-tiny { padding: 5px 11px; font-size: 11.5px; font-weight: 500; border-radius: 6px; transition: all 0.12s; border: 1px solid; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; }
  .btn-tiny svg { width: 11px; height: 11px; }
  .btn-tiny.open { background: transparent; color: var(--text); border-color: var(--border); text-decoration: none; }
  .btn-tiny.open:hover { background: var(--accent); color: #1a1400; border-color: var(--accent); }
  .btn-tiny.delete { background: transparent; color: #fca5a5; border-color: rgba(239,68,68,0.25); }
  .btn-tiny.delete:hover { background: var(--danger-soft); border-color: rgba(239,68,68,0.4); }
  .phase-head { padding: 14px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
  .phase-title { font-size: 14px; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 10px; }
  .phase-title .phase-badge { font-size: 10px; padding: 2px 8px; background: var(--accent-soft); color: var(--accent); border: 1px solid rgba(250,204,21,0.2); border-radius: 5px; font-family: 'JetBrains Mono', ui-monospace, monospace; letter-spacing: 0.04em; font-weight: 600; }
  .phase-actions { display: flex; gap: 8px; }
  .phase-wrap { display: flex; flex-direction: column; gap: 16px; }
  .docs-toolbar { padding: 14px 16px; border-bottom: 1px solid var(--border); display: grid; grid-template-columns: 1fr auto auto; gap: 10px; align-items: center; }
  .docs-toolbar .search-box { position: relative; }
  .docs-toolbar .search-box svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: var(--text-dim); }
  .docs-toolbar .search-box input { width: 100%; background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px 8px 36px; font-size: 13px; color: var(--text); outline: none; transition: all 0.12s; font-family: inherit; }
  .docs-toolbar .search-box input::placeholder { color: var(--text-dim); }
  .docs-toolbar .search-box input:focus { border-color: var(--border-strong); }
  .empty-state { padding: 48px 20px; text-align: center; color: var(--text-dim); font-size: 13px; }
  .empty-state svg { width: 32px; height: 32px; color: var(--text-dim); margin-bottom: 12px; }
  .empty-state b { color: var(--text-muted); font-weight: 500; }
  .doc-row { padding: 14px 18px; display: grid; grid-template-columns: 1fr auto auto; gap: 12px; align-items: center; border-bottom: 1px solid var(--border); transition: background 0.12s; }
  .doc-row:last-child { border-bottom: none; }
  .doc-row:hover { background: var(--surface-hover); }
  .doc-title { font-size: 13.5px; font-weight: 500; color: var(--text); }
  .doc-meta { font-size: 11.5px; color: var(--text-dim); margin-top: 3px; }
  .activity-list { padding: 8px 0; }
  .activity-row { display: grid; grid-template-columns: auto 1fr auto; gap: 14px; padding: 14px 18px; border-bottom: 1px solid var(--border); transition: background 0.12s; }
  .activity-row:last-child { border-bottom: none; }
  .activity-row:hover { background: var(--surface-hover); }
  .activity-ico { width: 32px; height: 32px; border-radius: 50%; background: var(--bg); border: 1px solid var(--border); display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: var(--text-muted); flex-shrink: 0; font-family: 'JetBrains Mono', ui-monospace, monospace; }
  .activity-ico.task { background: var(--accent-soft); color: var(--accent); border-color: rgba(250,204,21,0.25); }
  .activity-ico.doc { background: rgba(59,130,246,0.08); color: #93c5fd; border-color: rgba(59,130,246,0.25); }
  .activity-ico.project { background: rgba(168,85,247,0.08); color: #c4b5fd; border-color: rgba(168,85,247,0.25); }
  .activity-body { min-width: 0; }
  .activity-text { font-size: 13px; color: var(--text); line-height: 1.45; }
  .activity-text .verb { color: var(--text-muted); }
  .activity-text .obj { color: var(--text); font-weight: 500; }
  .activity-by { font-size: 11.5px; color: var(--text-dim); margin-top: 4px; display: flex; align-items: center; gap: 6px; }
  .activity-by .av-tiny { width: 18px; height: 18px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 8.5px; font-weight: 600; color: #fff; letter-spacing: 0.02em; }
  .activity-when { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 11.5px; color: var(--text-dim); font-variant-numeric: tabular-nums; text-align: right; flex-shrink: 0; }

  /* Modal styles (same as projects) */
  .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 100; display: none; align-items: flex-start; justify-content: center; padding: 60px 20px; overflow-y: auto; }
  .modal-overlay.open { display: flex; }
  .modal-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; width: 100%; max-width: 720px; overflow: hidden; }
  .modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
  .modal-title { font-size: 16px; font-weight: 600; color: var(--text); }
  .modal-body { padding: 20px; max-height: 70vh; overflow-y: auto; }
  .modal-foot { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; gap: 8px; justify-content: flex-end; background: var(--bg); }
  .modal-grid { display: grid; gap: 12px; grid-template-columns: repeat(12, 1fr); }
  .modal-grid > * { display: flex; flex-direction: column; gap: 5px; }
  .c-12 { grid-column: span 12; } .c-8 { grid-column: span 8; } .c-6 { grid-column: span 6; } .c-4 { grid-column: span 4; }
  @media (max-width: 768px) { .c-8, .c-6, .c-4 { grid-column: span 12; } }
  .icon-close { width: 30px; height: 30px; border-radius: 6px; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; transition: all 0.12s; }
  .icon-close:hover { background: var(--surface-2); color: var(--text); }

  /* ===== Add Task modal (redesign — mirrors task_view.php) ===== */
  .at-card { max-width: 760px; }
  .at-body { padding: 22px 24px 6px; max-height: 76vh; overflow-y: auto; }
  .at-title-field { width: 100%; background: transparent; border: none; outline: none; color: var(--text); font-size: 24px; font-weight: 700; letter-spacing: -0.01em; line-height: 1.25; padding: 4px 0 14px; border-bottom: 1px solid var(--border); transition: border-color 0.15s; font-family: inherit; }
  .at-title-field::placeholder { color: var(--text-dim); font-weight: 600; }
  .at-title-field:focus { border-bottom-color: var(--accent); }
  .at-section { margin-top: 22px; }
  .at-section-label { font-size: 11px; font-weight: 600; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
  .at-section-label svg { width: 12px; height: 12px; }
  .at-desc { width: 100%; background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; color: var(--text); font-size: 14px; line-height: 1.55; min-height: 100px; resize: vertical; outline: none; transition: all 0.15s; font-family: inherit; }
  .at-desc::placeholder { color: var(--text-dim); }
  .at-desc:focus { border-color: var(--border-strong); background: var(--bg); }
  .at-meta-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .at-field { display: flex; flex-direction: column; gap: 6px; }
  .at-field-label { font-size: 11.5px; color: var(--text-muted); font-weight: 500; display: flex; align-items: center; gap: 5px; }
  .at-field-label svg { width: 12px; height: 12px; color: var(--text-dim); }
  .at-status-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; }
  .at-status-opt { padding: 9px 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface-2); font-size: 12.5px; color: var(--text-muted); display: flex; align-items: center; gap: 8px; transition: all 0.15s; cursor: pointer; user-select: none; }
  .at-status-opt:hover { background: var(--surface); color: var(--text); border-color: var(--border-strong); }
  .at-status-opt.active { background: var(--accent-soft); border-color: rgba(250,204,21,0.4); color: var(--accent); font-weight: 500; }
  .at-status-opt .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--text-dim); flex-shrink: 0; }
  .at-status-opt.active .dot { background: var(--accent); box-shadow: 0 0 0 3px rgba(250,204,21,0.18); }
  .at-assignees { border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); max-height: 240px; overflow-y: auto; padding: 6px; }
  .at-assignee-row { display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; border-radius: 8px; cursor: pointer; transition: background 0.12s; }
  .at-assignee-row:hover { background: var(--surface); }
  .at-assignee-row.selected { background: var(--surface); }
  .at-assignee-info { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .at-av { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10.5px; font-weight: 600; color: #fff; letter-spacing: 0.02em; flex-shrink: 0; }
  .at-assignee-name { font-size: 13px; color: var(--text); font-weight: 500; }
  .at-assignee-role { font-size: 11px; color: var(--text-dim); }
  .at-check { width: 18px; height: 18px; border-radius: 5px; border: 1.5px solid var(--border-strong); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.15s; background: transparent; }
  .at-assignee-row.selected .at-check { background: var(--accent); border-color: var(--accent); }
  .at-check svg { width: 12px; height: 12px; color: #1a1400; opacity: 0; transition: opacity 0.15s; }
  .at-assignee-row.selected .at-check svg { opacity: 1; }
  .at-assignee-empty { padding: 18px; text-align: center; color: var(--text-dim); font-size: 12.5px; font-style: italic; }
  .at-count-pill { font-size: 11px; background: var(--surface); color: var(--text-muted); padding: 2px 8px; border-radius: 999px; font-weight: 500; letter-spacing: 0; text-transform: none; border: 1px solid var(--border); margin-left: 6px; }
  .at-count-pill.has { color: var(--accent); border-color: rgba(250,204,21,0.3); background: var(--accent-soft); }
  .at-date-wrap { position: relative; }
  .at-date-wrap input[type=date] { width: 100%; background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; color: var(--text); font-size: 13px; outline: none; transition: all 0.15s; font-family: inherit; color-scheme: dark; }
  .at-date-wrap input[type=date]:focus { border-color: var(--accent); background: var(--bg); }
  .at-phase-wrap select { width: 100%; background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; padding-right: 32px; color: var(--text); font-size: 13px; outline: none; transition: all 0.15s; font-family: inherit; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%238a8a8a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; }
  .at-phase-wrap select:focus { border-color: var(--accent); background-color: var(--bg); }
  .at-phase-wrap select option { background: #1a1a1a; color: var(--text); }
  @media (max-width: 640px) { .at-meta-row { grid-template-columns: 1fr; } .at-status-grid { grid-template-columns: 1fr; } }

  @media (max-width: 1100px) {
    .overview-grid { grid-template-columns: 1fr; }
    .summary-meta { grid-template-columns: 1fr; }
  }

  /* ===== MOBILE (≤768px) =====
     Three problems from the user's screenshots:
     1. .project-head — 4 action buttons (Project Docs / Website Logins /
        Add Phase / Add Task) stacked on the right and overlapped the
        title block ("Elite X" + "Nelson VanDenburgh") on the left.
     2. .phase-head — phase title + P-NN badge + Delete Phase + Add Task
        crammed into a single flex row → buttons overflow / wrap awkwardly.
     3. table.tasks-table — 5-column task grid forced horizontal scroll
        and cropped DUE / ACTIONS off the right edge. */
  @media (max-width: 768px) {
    /* Match the 16px page column the rest of the app uses. */
    .project-head {
      padding: 16px 16px 0;
      flex-direction: column;
      align-items: stretch;
      gap: 14px;
    }
    .ph-left {
      flex-direction: column;
      align-items: flex-start;
      gap: 12px;
    }
    .ph-left > div { width: 100%; min-width: 0; }
    .ph-left .back-wrap { padding-top: 0; }
    .project-title {
      font-size: clamp(20px, 5.6vw, 26px);
      line-height: 1.2;
      gap: 10px;
      align-items: center;
      min-width: 0;
      word-break: break-word;
    }
    .project-title .icon-tile { width: 36px; height: 36px; font-size: 12px; }
    .project-meta {
      font-size: 12px;
      gap: 6px;
      flex-wrap: wrap;
      row-gap: 4px;
    }

    /* Actions: 2-row grid, no horizontal scroll. Two secondary buttons
       (Project Docs / Website Logins) on row 1 sharing 50/50, two primary
       CTAs (Add Phase / Add Task) on row 2 sharing 50/50. Each button
       full-width within its cell, 44px tap target. Non-manager only
       sees the two secondaries → they just take row 1, no awkward gap. */
    .ph-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      width: 100%;
      flex-wrap: unset;
    }
    .ph-actions .btn {
      min-height: 44px;
      padding: 10px 12px;
      font-size: 13px;
      justify-content: center;
      width: 100%;
    }

    /* Section-nav (Overview / Tasks / Docs) — horizontal scroll inside
       its own container so tabs never hide behind anything. */
    .section-nav-inner { padding: 0 16px; gap: 0; overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
    .section-nav-inner::-webkit-scrollbar { display: none; }
    .section-nav a { flex-shrink: 0; padding: 12px 12px; font-size: 12.5px; }

    /* Phase head — title row and actions row stack vertically. */
    .phase-head {
      flex-direction: column;
      align-items: stretch;
      gap: 10px;
      padding: 12px 14px;
    }
    .phase-title { font-size: 15px; }
    .phase-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      width: 100%;
    }
    .phase-actions form,
    .phase-actions > * { width: 100%; }
    .phase-actions .btn-tiny,
    .phase-actions .btn {
      width: 100%;
      min-height: 40px;
      padding: 9px 12px !important;
      font-size: 13px !important;
      justify-content: center;
    }

    /* ===== TASKS TABLE → vertical cards =====
       5 cols (Task / Status / Assignees / Due / Actions) → re-laid as
       grid-areas like my_tasks. Title row 1 full-width, status + due
       row 2, assignees + actions row 3. */
    table.tasks-table thead { display: none; }
    table.tasks-table, table.tasks-table tbody, table.tasks-table tr { display: block; }
    table.tasks-table tbody tr {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      grid-template-areas:
        "title  title"
        "status due"
        "assg   actions";
      gap: 6px 12px;
      padding: 14px 16px;
      border-bottom: 1px solid var(--border);
      align-items: center;
    }
    table.tasks-table tbody tr:last-child { border-bottom: none; }
    table.tasks-table tbody td {
      display: block;
      padding: 0;
      border: none;
      min-width: 0;
      max-width: 100%;
    }
    table.tasks-table tbody td:nth-child(1) { grid-area: title; font-size: 15px; line-height: 1.3; }
    table.tasks-table tbody td:nth-child(1) a { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    table.tasks-table tbody td:nth-child(2) { grid-area: status; min-width: 0; }
    table.tasks-table tbody td:nth-child(3) { grid-area: assg; font-size: 12.5px; color: var(--text-muted); }
    table.tasks-table tbody td:nth-child(4) { grid-area: due; text-align: right; font-size: 12px; color: var(--text-muted); }
    table.tasks-table tbody td:nth-child(5) { grid-area: actions; text-align: right; }

    /* Status pill <select>: on mobile the desktop padding (4px top/bottom +
       line-height 1.3) leaves no room for descenders on values like "In
       Progress" / "Approved (Ready to Submit)" — the 'g'/'p'/'y' get
       clipped by the pill border. Also the select naturally sizes to the
       widest option ("Completed (Needs Manager Review)") and overflows the
       half-width status cell. Constrain to the cell width and give the
       text enough vertical room. */
    .status-inline { display: block; width: 100%; max-width: 100%; }
    .status-inline-select {
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
      padding: 6px 26px 6px 12px;
      font-size: 12px;
      line-height: 1.5;
      min-height: 30px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    /* ===== DOCS TOOLBAR — search + New Doc + Upload =====
       Default `grid-template-columns: 1fr auto auto` squashed the
       search input. Stack: search row 1 full-width, two action buttons
       share row 2. */
    .docs-toolbar {
      grid-template-columns: 1fr 1fr;
      padding: 12px 14px;
      gap: 8px;
    }
    .docs-toolbar .search-box { grid-column: 1 / -1; }
    .docs-toolbar .search-box input { font-size: 16px; padding: 11px 12px 11px 36px; min-height: 44px; }
    .docs-toolbar .btn { min-height: 44px; padding: 10px 12px; font-size: 13px; justify-content: center; width: 100%; }

    /* Doc rows: same vertical-card pattern. */
    .doc-row {
      grid-template-columns: minmax(0, 1fr) auto;
      grid-template-areas:
        "title  meta"
        "actions actions";
      gap: 6px 12px;
      padding: 12px 14px;
    }
    .doc-row .doc-title { font-size: 14px; }

    /* Section headers: title + count + see-all link */
    .section-head { flex-wrap: wrap; gap: 8px; }
    .section-title { font-size: 14px; }

    /* Activity list — keep readable, single-column. */
    .activity-list { padding: 4px 0; }
  }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<?php if ($loadError): ?><div class="flash flash-error" style="margin: 16px 32px;"><?= h($loadError) ?></div><?php endif; ?>

<div class="project-head">
  <div class="ph-left">
    <div class="back-wrap"><?= back_button_html('projects.php', 'Projects') ?></div>
    <div>
      <h1 class="project-title">
        <span class="icon-tile" style="background:<?= h($projectGrad) ?>"><?= h(user_initials((string)$project['name'])) ?></span>
        <?= h($project['name']) ?>
      </h1>
      <div class="project-meta">
        <span style="display:inline-flex;align-items:center;gap:6px;">
          <span class="client-mini-logo" style="background:<?= h($clientGrad) ?>"><?= h(user_initials((string)$project['client_name'])) ?></span>
          <?= h($project['client_name']) ?>
        </span>
        <span class="sep">·</span>
        <span><?= h($project['type_name']) ?></span>
        <span class="sep">·</span>
        <span class="status-pill"><span class="dot"></span><?= h($project['status_name']) ?></span>
      </div>
    </div>
  </div>
  <div class="ph-actions">
    <a class="btn btn-ghost" href="docs.php?project_id=<?= (int)$id ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
      Project Docs
    </a>
    <a class="btn btn-ghost" href="website_logins.php?project_id=<?= (int)$id ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0110 0v4"></path></svg>
      Website Logins
    </a>
    <?php if ($can_manage): ?>
      <button type="button" class="btn btn-primary save-flash" onclick="document.getElementById('addPhase').classList.add('open')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add Phase
      </button>
      <button type="button" class="btn btn-primary save-flash" onclick="document.getElementById('addTask').classList.add('open')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add Task
      </button>
    <?php endif; ?>
  </div>
</div>

<div class="section-nav">
  <div class="section-nav-inner" id="sectionNav">
    <a href="#overview" data-target="overview" class="active">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect></svg>
      Overview
    </a>
    <a href="#tasks" data-target="tasks">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg>
      Tasks <span class="count"><?= count($tasks) ?></span>
    </a>
    <a href="#docs" data-target="docs">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
      Docs <span class="count"><?= count($docs) ?></span>
    </a>
    <a href="#activity" data-target="activity">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
      Activity <span class="count"><?= count($activities) ?></span>
    </a>
  </div>
</div>

<div class="body-wrap">

  <section class="section" id="overview">
    <div class="section-head"><h2 class="section-title">Overview</h2></div>
    <div class="overview-grid">
      <div class="ov-card">
        <span class="lbl">Progress</span>
        <div class="progress-num">
          <span class="pct"><?= $progress ?>%</span>
          <span class="rat"><?= $doneTasks ?> of <?= $totalTasks ?> tasks complete</span>
        </div>
        <div class="progress-bar"><div class="progress-fill" data-target="<?= $progress ?>" style="width:0%;"></div></div>
        <div class="progress-breakdown">
          <span class="lab"><span class="dot" style="background:#6b7280;"></span>To Do · <?= $todoCount ?></span>
          <span class="lab"><span class="dot" style="background:var(--accent);"></span>In Progress · <?= $inProgressCount ?></span>
          <span class="lab"><span class="dot" style="background:#22c55e;"></span>Done · <?= $doneTasks ?></span>
        </div>
      </div>

      <div class="ov-card">
        <span class="lbl">Summary</span>
        <p class="summary-text"><?= h($project['notes'] ?: 'Project overview and delivery milestones are tracked through tasks and docs for this workspace.') ?></p>
        <div class="summary-meta">
          <div class="row"><div class="k">Last updated</div><div class="v"><?= h(format_date($project['updated_at'])) ?></div></div>
          <div class="row"><div class="k">Created</div><div class="v"><?= h(format_date($project['created_at'])) ?></div></div>
          <div class="row"><div class="k">Service type</div><div class="v"><?= h($project['type_name']) ?></div></div>
          <div class="row"><div class="k">Client</div><div class="v"><?= h($project['client_name']) ?></div></div>
        </div>
      </div>

      <div class="ov-card">
        <span class="lbl">People on this project</span>
        <div class="people-row">
          <?php
            $assigneeIds = [];
            foreach ($tasks as $t) {
              if (!empty($t['assignee_names'])) {
                foreach (explode(',', (string)$t['assignee_names']) as $n) {
                  $n = trim($n);
                  if ($n !== '' && !in_array($n, $assigneeIds, true)) $assigneeIds[] = $n;
                }
              }
            }
            if (!$assigneeIds) { echo '<div style="color:var(--text-dim);font-size:12.5px;font-style:italic;">No people assigned yet.</div>'; }
            foreach ($assigneeIds as $name):
              $g = pv_avatar_gradient($name);
              $roleName = '';
              foreach ($team as $tm) if ($tm['name'] === $name) { $roleName = $tm['role_name']; break; }
          ?>
            <div class="person">
              <div class="av" style="background:<?= h($g) ?>"><?= h(user_initials($name)) ?></div>
              <div class="info"><div class="name"><?= h($name) ?></div><div class="role"><?= h($roleName ?: 'Member') ?></div></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="section" id="tasks">
    <div class="section-head">
      <h2 class="section-title">Tasks <span class="count-pill"><?= count($tasks) ?></span></h2>
      <a class="section-action" href="my_tasks.php">View in My Tasks <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></a>
    </div>

    <div class="phase-wrap">
      <?php foreach ($phases as $phIdx => $ph): $phaseBadge = 'P-' . str_pad((string)((int)$ph['sort_order']), 2, '0', STR_PAD_LEFT); ?>
        <div class="panel">
          <div class="phase-head">
            <div class="phase-title">
              <?= h($ph['name']) ?>
              <span class="phase-badge"><?= h($phaseBadge) ?></span>
            </div>
            <div class="phase-actions">
              <?php if ($can_manage): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('This will permanently delete this phase and all tasks under it. Are you sure?');">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="delete_phase" value="1">
                  <input type="hidden" name="phase_id" value="<?= (int)$ph['id'] ?>">
                  <button class="btn-tiny delete">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path></svg>
                    Delete Phase
                  </button>
                </form>
                <button type="button" class="btn btn-primary save-flash" style="padding:6px 12px;font-size:12px;" onclick="document.getElementById('addTask').classList.add('open'); document.querySelector('#addTask [name=phase_id]').value='<?= (int)$ph['id'] ?>';">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                  Add Task
                </button>
              <?php endif; ?>
            </div>
          </div>

          <table class="tasks-table">
            <thead>
              <tr>
                <th>Task</th>
                <th>Status</th>
                <th>Assignees</th>
                <th>Due</th>
                <th class="right" style="width:160px;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php $phaseTasks = $by_phase[$ph['id']] ?? []; if (!$phaseTasks): ?>
              <tr><td colspan="5" style="padding: 28px 16px; text-align: center; color: var(--text-dim); font-style: italic;">No tasks in this phase.</td></tr>
            <?php else: foreach ($phaseTasks as $t):
              $cls = pv_status_pill((string)$t['status']);
              $primaryAssignee = '';
              if (!empty($t['assignee_names'])) {
                $names = array_map('trim', explode(',', (string)$t['assignee_names']));
                $primaryAssignee = $names[0] ?? '';
              }
            ?>
              <tr>
                <td>
                  <div class="task-cell">
                    <div>
                      <a class="task-title-link" href="task_view.php?id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a>
                      <div class="task-id">TASK-<?= str_pad((string)(int)$t['id'], 4, '0', STR_PAD_LEFT) ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <?php if ($can_manage): ?>
                    <form class="status-inline" method="post">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                      <input type="hidden" name="update_task_status" value="1">
                      <select class="status-inline-select tsp <?= $cls ?>" name="status" onchange="this.form.submit()" aria-label="Change task status">
                        <?php foreach ($statuses as $opt): ?>
                          <option value="<?= h($opt) ?>" <?= $t['status'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </form>
                  <?php else: ?>
                    <span class="tsp <?= $cls ?>"><span class="dot"></span><?= h($t['status']) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($primaryAssignee): ?>
                    <div class="assignee">
                      <div class="av" style="background:<?= h(pv_avatar_gradient($primaryAssignee)) ?>"><?= h(user_initials($primaryAssignee)) ?></div>
                      <span><?= h($t['assignee_names']) ?></span>
                    </div>
                  <?php else: ?>
                    <span style="color:var(--text-dim);font-style:italic;">Unassigned</span>
                  <?php endif; ?>
                </td>
                <td><span class="due-mono"><?= h($t['due_date'] ? format_date($t['due_date']) : '—') ?></span></td>
                <td>
                  <div class="row-actions">
                    <?php if ($can_manage): ?>
                      <button type="button" class="btn-tiny open" data-task-edit="<?= (int)$t['id'] ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>Edit</button>
                    <?php endif; ?>
                    <a class="btn-tiny open" href="task_view.php?id=<?= (int)$t['id'] ?>">Open<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></a>
                    <?php if ($can_manage): ?>
                      <form method="post" style="display:inline" onsubmit="return confirm('This will permanently delete this task. Are you sure?');">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="delete_task" value="1">
                        <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                        <button class="btn-tiny delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path></svg>Delete</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
      <?php if (!$phases): ?>
        <div class="panel">
          <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg>
            <div>No phases yet.</div>
            <div style="margin-top:4px;">Add a phase to start organizing tasks.</div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="section" id="docs">
    <div class="section-head">
      <h2 class="section-title">Project Documents <span class="count-pill"><?= count($docs) ?> files</span></h2>
      <a class="section-action" href="docs.php?project_id=<?= (int)$id ?>">All Docs <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></a>
    </div>
    <div class="panel">
      <form class="docs-toolbar" method="get">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <div class="search-box">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input name="dq" value="<?= h($docsQ) ?>" placeholder="Search documents in this project…">
        </div>
        <?php if ($can_manage): ?>
          <button type="button" class="btn btn-primary save-flash" style="padding: 8px 14px;" onclick="document.getElementById('addDoc').classList.add('open')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            New Doc
          </button>
        <?php endif; ?>
        <a class="btn btn-ghost" href="docs.php?project_id=<?= (int)$id ?>" style="padding: 8px 14px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
          Upload
        </a>
      </form>
      <?php if (!$docs): ?>
        <div class="empty-state">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
          <div>No documents yet.</div>
          <div style="margin-top:4px;">Drop a brief, schema or PDF here, or click <b>New Doc</b> to create one inline.</div>
        </div>
      <?php else: foreach ($docs as $d): ?>
        <div class="doc-row">
          <div>
            <div class="doc-title"><?= h($d['title']) ?></div>
            <div class="doc-meta">Updated <?= h(format_date($d['updated_at'])) ?> · by <?= h($d['author_name'] ?: 'Unknown') ?></div>
          </div>
          <span style="color:var(--text-dim);font-size:11.5px;font-family:'JetBrains Mono',ui-monospace,monospace;"><?= strlen((string)($d['content'] ?? '')) ?> chars</span>
          <div class="row-actions">
            <a class="btn-tiny open" href="doc_edit.php?id=<?= (int)$d['id'] ?>">Open<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></a>
            <?php if ($can_manage): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('This will permanently delete this document. Are you sure?');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="delete_doc" value="1">
                <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                <button class="btn-tiny delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path></svg>Delete</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </section>

  <section class="section" id="activity">
    <div class="section-head">
      <h2 class="section-title">Activity <span class="count-pill"><?= count($activities) ?> events</span></h2>
      <span class="section-action" style="cursor:default;">All times in your timezone</span>
    </div>
    <div class="panel">
      <?php if (!$activities): ?>
        <div class="empty-state"><div>No activity yet.</div></div>
      <?php else: ?>
        <div class="activity-list">
          <?php foreach ($activities as $a):
            $src = (string)$a['source'];
            $actor = (string)$a['actor'];
            $actorGrad = pv_avatar_gradient($actor);
            $verb = strpos($a['message'], ':') !== false ? substr($a['message'], 0, strpos($a['message'], ':') + 1) : '';
            $obj = trim(substr($a['message'], strlen($verb)));
          ?>
            <div class="activity-row">
              <div class="activity-ico <?= h($src) ?>"><?= h(strtoupper(substr($src, 0, 1))) ?></div>
              <div class="activity-body">
                <div class="activity-text"><span class="verb"><?= h($verb) ?></span> <span class="obj"><?= h($obj) ?></span></div>
                <div class="activity-by"><span class="av-tiny" style="background:<?= h($actorGrad) ?>"><?= h(user_initials($actor)) ?></span>by <?= h($actor) ?></div>
              </div>
              <div class="activity-when"><?= h(format_date($a['happened_at'])) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

</div>

<?php if ($can_manage): ?>
<div class="modal-overlay" id="addPhase" onclick="if(event.target===this) this.classList.remove('open')">
  <div class="modal-card" style="max-width:480px;">
    <div class="modal-head">
      <span class="modal-title">Add Phase</span>
      <button type="button" class="icon-close" onclick="document.getElementById('addPhase').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="add_phase" value="1">
      <div class="modal-body">
        <label class="field-label">Phase Name</label>
        <input class="input" name="phase_name" required autofocus>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addPhase').classList.remove('open')">Cancel</button>
        <button class="btn btn-primary save-flash" type="submit">Add</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="addTask" onclick="if(event.target===this) this.classList.remove('open')">
  <div class="modal-card at-card">
    <div class="modal-head">
      <span class="modal-title">New task</span>
      <button type="button" class="icon-close" onclick="document.getElementById('addTask').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
    </div>
    <form method="post" id="addTaskForm">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="add_task" value="1">
      <?php $defaultStatus = $statuses[0] ?? 'To Do'; ?>
      <input type="hidden" name="status" id="atStatusInput" value="<?= h($defaultStatus) ?>">

      <div class="at-body">
        <input class="at-title-field" type="text" name="title" placeholder="Task name…" required autocomplete="off">

        <div class="at-section">
          <div class="at-section-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"></line><line x1="4" y1="12" x2="20" y2="12"></line><line x1="4" y1="18" x2="14" y2="18"></line></svg>
            Description
          </div>
          <textarea class="at-desc" name="description" rows="4" placeholder="Add more detail about this task…"></textarea>
        </div>

        <div class="at-section">
          <div class="at-section-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><polyline points="9 12 11 14 15 10"></polyline></svg>
            Status
          </div>
          <div class="at-status-grid" id="atStatusGrid">
            <?php foreach ($statuses as $i => $s): ?>
              <div class="at-status-opt<?= $i === 0 ? ' active' : '' ?>" data-status="<?= h($s) ?>" role="button" tabindex="0">
                <span class="dot"></span>
                <span><?= h($s) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="at-section at-meta-row">
          <div class="at-field">
            <span class="at-field-label">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
              Due date
            </span>
            <div class="at-date-wrap"><input type="date" name="due_date"></div>
          </div>
          <div class="at-field at-phase-wrap">
            <span class="at-field-label">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="9" x2="20" y2="9"></line><line x1="4" y1="15" x2="20" y2="15"></line><line x1="10" y1="3" x2="8" y2="21"></line><line x1="16" y1="3" x2="14" y2="21"></line></svg>
              Phase
            </span>
            <select name="phase_id" required>
              <?php foreach ($phases as $ph): ?><option value="<?= (int)$ph['id'] ?>"><?= h($ph['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="at-section">
          <div class="at-section-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 00-3-3.87"></path><path d="M16 3.13a4 4 0 010 7.75"></path></svg>
            Assignees
            <span class="at-count-pill" id="atAssigneeCount">0 selected</span>
          </div>
          <div class="at-assignees" id="atAssignees">
            <?php if (empty($team)): ?>
              <div class="at-assignee-empty">No teammates available to assign.</div>
            <?php else: foreach ($team as $m):
              $initials = function_exists('user_initials') ? user_initials((string)$m['name']) : strtoupper(substr((string)$m['name'], 0, 2));
              $grad = (function($seed) {
                $p = ['linear-gradient(135deg,#22c55e,#16a34a)','linear-gradient(135deg,#a855f7,#7c3aed)','linear-gradient(135deg,#0ea5e9,#0284c7)','linear-gradient(135deg,#f97316,#ea580c)','linear-gradient(135deg,#ec4899,#be185d)','linear-gradient(135deg,#3b82f6,#1d4ed8)','linear-gradient(135deg,#14b8a6,#0f766e)','linear-gradient(135deg,#facc15,#ca8a04)'];
                return $p[crc32($seed) % count($p)];
              })((string)$m['name']);
            ?>
              <label class="at-assignee-row" data-uid="<?= (int)$m['id'] ?>">
                <span class="at-assignee-info">
                  <span class="at-av" style="background:<?= h($grad) ?>"><?= h($initials) ?></span>
                  <span>
                    <span class="at-assignee-name"><?= h($m['name']) ?></span><br>
                    <span class="at-assignee-role"><?= h($m['role_name']) ?></span>
                  </span>
                </span>
                <span class="at-check">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </span>
                <input type="checkbox" name="assignees[]" value="<?= (int)$m['id'] ?>" hidden>
              </label>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <?php include __DIR__ . '/partials/task_attachments_field.php'; ?>
      </div>

      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addTask').classList.remove('open')">Cancel</button>
        <button class="btn btn-primary save-flash" type="submit">Create task</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="addDoc" onclick="if(event.target===this) this.classList.remove('open')">
  <div class="modal-card">
    <div class="modal-head">
      <span class="modal-title">New Doc</span>
      <button type="button" class="icon-close" onclick="document.getElementById('addDoc').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="create_doc" value="1">
      <div class="modal-body">
        <div class="form-row" style="margin-bottom:12px;"><label class="field-label">Title</label><input class="input" name="doc_title" required></div>
        <div class="form-row"><label class="field-label">Content</label><textarea class="input" name="doc_content" rows="10"></textarea></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addDoc').classList.remove('open')">Cancel</button>
        <button class="btn btn-primary save-flash" type="submit">Create Doc</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
  // Animate progress fill
  setTimeout(() => {
    const fill = document.querySelector('.progress-fill');
    if (fill) fill.style.width = (fill.dataset.target || 0) + '%';
  }, 80);

  // Section nav: scroll + active highlight
  const navLinks = document.querySelectorAll('#sectionNav a');
  navLinks.forEach(a => {
    a.addEventListener('click', (e) => {
      const target = document.getElementById(a.dataset.target);
      if (!target) return;
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
  const sections = document.querySelectorAll('.section');
  const obs = new IntersectionObserver((entries) => {
    entries.forEach(en => {
      if (en.isIntersecting) {
        const id = en.target.id;
        navLinks.forEach(a => a.classList.toggle('active', a.dataset.target === id));
      }
    });
  }, { rootMargin: '-50% 0px -40% 0px', threshold: 0 });
  sections.forEach(s => obs.observe(s));

  // ===== Add Task modal interactivity =====
  (function () {
    const statusGrid = document.getElementById('atStatusGrid');
    const statusInput = document.getElementById('atStatusInput');
    if (statusGrid && statusInput) {
      statusGrid.addEventListener('click', (e) => {
        const opt = e.target.closest('.at-status-opt');
        if (!opt) return;
        statusGrid.querySelectorAll('.at-status-opt').forEach(o => o.classList.remove('active'));
        opt.classList.add('active');
        statusInput.value = opt.dataset.status || '';
      });
      statusGrid.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const opt = e.target.closest('.at-status-opt');
        if (!opt) return;
        e.preventDefault();
        opt.click();
      });
    }

    const assigneeWrap = document.getElementById('atAssignees');
    const assigneeCount = document.getElementById('atAssigneeCount');
    function refreshAssigneeCount() {
      if (!assigneeWrap || !assigneeCount) return;
      const n = assigneeWrap.querySelectorAll('input[type=checkbox]:checked').length;
      assigneeCount.textContent = n + ' selected';
      assigneeCount.classList.toggle('has', n > 0);
    }
    if (assigneeWrap) {
      assigneeWrap.querySelectorAll('.at-assignee-row').forEach(row => {
        const cb = row.querySelector('input[type=checkbox]');
        if (!cb) return;
        if (cb.checked) row.classList.add('selected');
        cb.addEventListener('change', () => {
          row.classList.toggle('selected', cb.checked);
          refreshAssigneeCount();
        });
      });
      refreshAssigneeCount();
    }

    // Reset modal state when it closes so a fresh open starts clean.
    const overlay = document.getElementById('addTask');
    const form = document.getElementById('addTaskForm');
    if (overlay && form) {
      const observer = new MutationObserver(() => {
        if (!overlay.classList.contains('open')) {
          // Closed — reset for next open.
          form.reset();
          if (assigneeWrap) {
            assigneeWrap.querySelectorAll('.at-assignee-row').forEach(r => r.classList.remove('selected'));
            refreshAssigneeCount();
          }
          if (statusGrid && statusInput) {
            const opts = statusGrid.querySelectorAll('.at-status-opt');
            opts.forEach((o, i) => o.classList.toggle('active', i === 0));
            statusInput.value = opts[0] ? (opts[0].dataset.status || '') : '';
          }
        }
      });
      observer.observe(overlay, { attributes: true, attributeFilter: ['class'] });
    }
  })();
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
