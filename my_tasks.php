<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();

$pdo = db();
$ws = auth_workspace_id();
$user = auth_user();
$uid = (int)$user['id'];
$role = $user['role_name'] ?? '';
$canFinalStatus = in_array($role, ['CEO','Manager','Super Admin'], true);

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

$hiddenStatuses = ['Completed','Completed (Needs Manager Review)','Approved','Approved (Ready to Submit)','Submitted to Client'];
$hiddenMarks = implode(',', array_fill(0, count($hiddenStatuses), '?'));

try {
  $taskStatusOptions = $pdo->prepare('SELECT name FROM task_statuses WHERE workspace_id = ? ORDER BY sort_order ASC');
  $taskStatusOptions->execute([$ws]);
  $taskStatusOptions = array_map(fn($r) => $r['name'], $taskStatusOptions->fetchAll());
} catch (Throwable $e) {
  $taskStatusOptions = [];
}
if (!$taskStatusOptions) {
  $taskStatusOptions = ['To Do','In Progress','Completed','Approved','Submitted to Client'];
}
if (!$canFinalStatus) {
  $taskStatusOptions = array_values(array_filter($taskStatusOptions, fn($s) => !in_array($s, ['Approved','Approved (Ready to Submit)','Submitted to Client'], true)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_task_status'])) {
  require_post();
  csrf_verify();
  $taskId = (int)($_POST['task_id'] ?? 0);
  $newStatus = trim((string)($_POST['status'] ?? ''));
  if ($taskId <= 0 || $newStatus === '') { flash_set('error', 'Invalid task selected.'); redirect('my_tasks.php'); }
  if (!in_array($newStatus, $taskStatusOptions, true)) { flash_set('error', 'You cannot set that status.'); redirect('my_tasks.php'); }

  $owned = $pdo->prepare('SELECT t.id FROM tasks t JOIN task_assignees ta ON ta.task_id=t.id AND ta.user_id=? WHERE t.id=? AND t.workspace_id=?');
  $owned->execute([$uid, $taskId, $ws]);
  if (!$owned->fetch()) { flash_set('error', 'Task not found.'); redirect('my_tasks.php'); }

  $now = now();
  if ($newStatus === 'Submitted to Client') {
    $st = $pdo->prepare('UPDATE tasks SET status=?, submitted_at=?, submitted_by=?, updated_at=? WHERE id=? AND workspace_id=?');
    $st->execute([$newStatus, $now, $uid, $now, $taskId, $ws]);
  } else {
    $st = $pdo->prepare('UPDATE tasks SET status=?, updated_at=? WHERE id=? AND workspace_id=?');
    $st->execute([$newStatus, $now, $taskId, $ws]);
  }

  flash_set('success', 'Task status updated.');
  if ($canFinalStatus && in_array($newStatus, ['Completed','Completed (Needs Manager Review)'], true)) { redirect('manager_review.php'); }
  if ($canFinalStatus && in_array($newStatus, ['Approved','Approved (Ready to Submit)'], true)) { redirect('manager_submit.php'); }
  if ($canFinalStatus && $newStatus === 'Submitted to Client') { redirect('completed_task_archive.php'); }
  redirect('my_tasks.php');
}

$sql = "SELECT t.*, p.name AS project_name, c.name AS client_name, c.id AS client_id
  FROM tasks t
  JOIN projects p ON p.id=t.project_id
  JOIN clients c ON c.id=p.client_id
  JOIN task_assignees ta ON ta.task_id=t.id AND ta.user_id=?
  WHERE t.workspace_id=? AND t.status NOT IN ($hiddenMarks)";
$params = array_merge([$uid, $ws], $hiddenStatuses);

if ($status !== '') {
  $sql .= " AND t.status=?";
  $params[] = $status;
}
if ($q !== '') {
  $sql .= " AND (t.title LIKE ? OR p.name LIKE ? OR c.name LIKE ?)";
  $like = '%' . $q . '%';
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}
$sql .= " ORDER BY COALESCE(t.due_date, '9999-12-31') ASC, t.updated_at DESC, t.id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$tasks = $st->fetchAll();

$statusStmt = $pdo->prepare("SELECT t.status, COUNT(*) c
  FROM tasks t
  JOIN task_assignees ta ON ta.task_id=t.id AND ta.user_id=?
  WHERE t.workspace_id=? AND t.status NOT IN ($hiddenMarks)
  GROUP BY t.status
  ORDER BY c DESC");
$statusStmt->execute(array_merge([$uid, $ws], $hiddenStatuses));
$statusRows = $statusStmt->fetchAll();

$statusCounts = [];
foreach ($statusRows as $row) { $statusCounts[$row['status']] = (int)$row['c']; }
$totalTasks = array_sum($statusCounts);
$doneCount = 0;
foreach ($statusCounts as $name => $count) {
  $s = strtolower((string)$name);
  if (strpos($s, 'approved') !== false || strpos($s, 'complete') !== false || $s === 'done') { $doneCount += $count; }
}
$openCount = max($totalTasks - $doneCount, 0);
$dueSoon = 0;
$todayStr = date('Y-m-d');
foreach ($tasks as $t) {
  if (!empty($t['due_date']) && $t['due_date'] <= date('Y-m-d', strtotime('+3 days'))) { $dueSoon++; }
}

function mt_status_pill_class(string $status): string {
  $s = strtolower($status);
  if (strpos($s, 'progress') !== false) return 'progress';
  if (strpos($s, 'done') !== false || strpos($s, 'complete') !== false || strpos($s, 'approved') !== false) return 'done';
  if (strpos($s, 'review') !== false || strpos($s, 'submit') !== false) return 'review';
  return 'todo';
}
function mt_client_gradient(int $id, string $name): string {
  $palette = [
    'linear-gradient(135deg,#f43f5e,#e11d48)',
    'linear-gradient(135deg,#f59e0b,#d97706)',
    'linear-gradient(135deg,#94a3b8,#64748b)',
    'linear-gradient(135deg,#fb7185,#be123c)',
    'linear-gradient(135deg,#10b981,#059669)',
    'linear-gradient(135deg,#0ea5e9,#0369a1)',
    'linear-gradient(135deg,#a855f7,#7c3aed)',
    'linear-gradient(135deg,#ec4899,#be185d)',
  ];
  return $palette[crc32($name . '|' . $id) % count($palette)];
}
function mt_due_cell_class(?string $due, string $todayStr): string {
  if (!$due) return '';
  $d = strtotime((string)$due);
  $t = strtotime($todayStr);
  if ($d < $t) return 'overdue';
  if ($d <= strtotime('+3 days', $t)) return 'soon';
  return '';
}
function mt_relative_due(?string $due, string $todayStr): string {
  if (!$due) return '';
  $d = strtotime((string)$due);
  $t = strtotime($todayStr);
  $diff = (int)floor(($d - $t) / 86400);
  if ($diff === 0) return 'today';
  if ($diff < 0) return abs($diff) . ' day' . (abs($diff) === 1 ? '' : 's') . ' overdue';
  if ($diff === 1) return 'tomorrow';
  return 'in ' . $diff . ' days';
}

$pageTitle = 'My Tasks';
$activeKey = 'my-tasks';
$pageHeadExtra = <<<HTML
<style>
  .page-header { padding: 28px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; max-width: 640px; }
  .toolbar { max-width: 1640px; margin: 0 auto; width: 100%; padding: 24px 32px 18px; display: grid; grid-template-columns: 1fr auto auto; gap: 12px; align-items: center; }
  .search-box { position: relative; }
  .search-box svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--text-dim); }
  .search-box input { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 11px 14px 11px 40px; font-size: 13px; color: var(--text); outline: none; transition: all 0.15s; font-family: inherit; }
  .search-box input::placeholder { color: var(--text-dim); }
  .search-box input:focus { border-color: var(--border-strong); background: var(--surface-2); }
  .filter-select { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 10px 32px 10px 14px; font-size: 13px; color: var(--text); appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%238a8a8a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; cursor: pointer; outline: none; min-width: 150px; font-family: inherit; }
  .filter-select option { background: #1a1a1a; color: var(--text); }
  .stat-tiles { max-width: 1640px; margin: 0 auto; width: 100%; padding: 0 32px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
  .stat-tile { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 16px 20px; display: flex; align-items: center; gap: 16px; cursor: pointer; transition: all 0.15s; text-decoration: none; color: inherit; }
  .stat-tile:hover { background: var(--surface-2); border-color: var(--border-strong); transform: translateY(-1px); }
  .stat-tile.active { border-color: rgba(250,204,21,0.4); background: rgba(250,204,21,0.04); }
  .stat-tile .ico { width: 40px; height: 40px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .stat-tile .ico svg { width: 18px; height: 18px; }
  .stat-tile .ico.amber { background: rgba(250,204,21,0.08); color: var(--accent); border: 1px solid rgba(250,204,21,0.2); }
  .stat-tile .ico.blue { background: rgba(59,130,246,0.08); color: #93c5fd; border: 1px solid rgba(59,130,246,0.2); }
  .stat-tile .ico.danger { background: rgba(239,68,68,0.08); color: #fca5a5; border: 1px solid rgba(239,68,68,0.2); }
  .stat-tile .num { font-size: 26px; font-weight: 700; color: var(--text); letter-spacing: -0.02em; line-height: 1; font-variant-numeric: tabular-nums; }
  .stat-tile.danger .num { color: #fca5a5; }
  .stat-tile .lbl { font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; margin-top: 4px; }
  .table-wrap { max-width: 1640px; margin: 0 auto; width: 100%; padding: 20px 32px 28px; }
  .table-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  table.tasks { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.tasks thead th { text-align: left; padding: 12px 16px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-dim); background: var(--bg); border-bottom: 1px solid var(--border); white-space: nowrap; }
  table.tasks tbody tr { transition: background 0.12s; }
  table.tasks tbody tr:hover { background: var(--surface-hover); }
  table.tasks tbody td { padding: 14px 16px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
  table.tasks tbody tr:last-child td { border-bottom: none; }
  .task-cell { display: flex; align-items: center; gap: 12px; min-width: 0; }
  .task-icon { width: 30px; height: 30px; border-radius: 7px; background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .task-icon svg { width: 14px; height: 14px; }
  .task-icon.amber { background: rgba(250,204,21,0.08); color: var(--accent); border-color: rgba(250,204,21,0.2); }
  .task-title { font-weight: 600; font-size: 13.5px; color: var(--text); line-height: 1.3; text-decoration: none; }
  .task-id { font-size: 11px; color: var(--text-dim); font-family: 'JetBrains Mono', ui-monospace, monospace; margin-top: 2px; }
  .client-mini { display: flex; align-items: center; gap: 9px; color: var(--text); }
  .client-mini-logo { width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 600; color: #fff; letter-spacing: 0.02em; flex-shrink: 0; }
  .client-mini-name { font-size: 12.5px; font-weight: 500; }
  .project-link { color: var(--text); font-size: 12.5px; border-bottom: 1px dashed var(--border-strong); padding-bottom: 1px; transition: all 0.12s; }
  .project-link:hover { color: var(--accent); border-bottom-color: var(--accent); }
  .status-inline { display: inline-block; margin: 0; }
  .status-inline-select { appearance: none; -webkit-appearance: none; cursor: pointer; padding: 4px 22px 4px 26px; border-radius: 999px; font-size: 11.5px; font-weight: 500; border: 1px solid; line-height: 1.3; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 8px center; padding-right: 22px; position: relative; }
  .status-inline-select option { background: #1a1a1a; color: var(--text); font-weight: 500; }
  .status-pill-wrap { position: relative; display: inline-flex; align-items: center; }
  .status-pill-wrap .dot-overlay { position: absolute; left: 9px; top: 50%; transform: translateY(-50%); width: 6px; height: 6px; border-radius: 50%; pointer-events: none; }
  .sp-progress { color: var(--accent); background: var(--accent-soft); border-color: rgba(250,204,21,0.25); }
  .sp-progress .dot-overlay { background: var(--accent); box-shadow: 0 0 0 2px rgba(250,204,21,0.18); }
  .sp-todo { color: #d1d5db; background: var(--surface-2); border-color: var(--border); }
  .sp-todo .dot-overlay { background: #6b7280; }
  .sp-review { color: #c4b5fd; background: rgba(168,85,247,0.08); border-color: rgba(168,85,247,0.25); }
  .sp-review .dot-overlay { background: #a855f7; }
  .sp-done { color: #86efac; background: rgba(34,197,94,0.08); border-color: rgba(34,197,94,0.25); }
  .sp-done .dot-overlay { background: #22c55e; }
  .due-cell { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12.5px; color: var(--text); font-variant-numeric: tabular-nums; display: flex; flex-direction: column; gap: 2px; }
  .due-cell .relative { color: var(--text-dim); font-size: 11px; }
  .due-cell.overdue .relative { color: #fca5a5; font-weight: 500; }
  .due-cell.soon .relative { color: #fbbf24; font-weight: 500; }
  .open-btn { background: transparent; color: var(--text); border: 1px solid var(--border); border-radius: 7px; padding: 6px 14px; font-size: 12px; font-weight: 500; transition: all 0.12s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
  .open-btn:hover { background: var(--accent); color: #1a1400; border-color: var(--accent); }
  .open-btn svg { width: 11px; height: 11px; }
  .table-foot { padding: 12px 18px; border-top: 1px solid var(--border); background: var(--bg); display: flex; align-items: center; justify-content: space-between; font-size: 12px; color: var(--text-dim); font-variant-numeric: tabular-nums; }
  .table-foot b { color: var(--text-muted); font-weight: 500; }
  .empty-row { padding: 32px 16px; text-align: center; color: var(--text-dim); font-size: 13px; font-style: italic; }

  /* ===== Mobile: stack each task as a card =====
     The 6-column table doesn't fit on a 375-430px screen; titles wrap to
     4 lines and Project/Status/Due get crushed. On mobile we re-layout
     each row as a vertical card with title at the top, secondary info
     in a meta strip, and the status/Open button at the bottom. */
  @media (max-width: 768px) {
    .table-card { border-radius: 12px; }
    table.tasks { font-size: 14px; }
    table.tasks thead { display: none; }
    table.tasks, table.tasks tbody, table.tasks tr { display: block; }
    table.tasks tbody tr {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 6px 12px;
      align-items: center;
      padding: 14px 16px;
      border-bottom: 1px solid var(--border);
    }
    table.tasks tbody tr:last-child { border-bottom: none; }
    table.tasks tbody td {
      display: block;
      padding: 0;
      border: none;
      min-width: 0;
    }
    /* Task title cell takes top-left; "Open" button cell hugs top-right */
    table.tasks tbody td:nth-child(1) { grid-column: 1; grid-row: 1; }
    table.tasks tbody td:nth-child(6) { grid-column: 2; grid-row: 1; }
    /* Client + Project on the second row, side by side */
    table.tasks tbody td:nth-child(2),
    table.tasks tbody td:nth-child(3) {
      grid-column: span 1;
      grid-row: 2;
      font-size: 12.5px;
      color: var(--text-muted);
    }
    table.tasks tbody td:nth-child(2) { grid-column: 1; }
    table.tasks tbody td:nth-child(3) { grid-column: 2; text-align: right; }
    /* Status pill on row 3 left, due on row 3 right */
    table.tasks tbody td:nth-child(4) { grid-column: 1; grid-row: 3; }
    table.tasks tbody td:nth-child(5) { grid-column: 2; grid-row: 3; text-align: right; }

    .task-cell { gap: 10px; }
    .task-title { font-size: 15px; line-height: 1.3; }
    .task-id   { font-size: 11px; }
    .open-btn { padding: 8px 10px; font-size: 12px; }
    .open-btn span { display: none; }
    .due-cell { align-items: flex-end; }
    .client-mini-name { font-size: 12.5px; }
    .status-inline-select { font-size: 12.5px; padding: 5px 10px; }
    .empty-row {
      display: block !important;
      grid-column: 1 / -1 !important;
    }
    .table-foot {
      flex-direction: column;
      align-items: stretch;
      gap: 6px;
      text-align: center;
    }
  }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">My Tasks</div>
      <div class="page-sub">Track assigned work, filter by status, and jump directly into task details.</div>
    </div>
  </div>
  <a class="btn btn-ghost" href="projects.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"></path></svg>
    View Projects
  </a>
</div>

<form class="toolbar" method="get">
  <div class="search-box">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
    <input name="q" value="<?= h($q) ?>" placeholder="Search task, client, project…">
  </div>
  <select class="filter-select" name="status">
    <option value="">All statuses</option>
    <?php foreach ($statusRows as $s): ?>
      <option value="<?= h($s['status']) ?>" <?= $status === $s['status'] ? 'selected' : '' ?>><?= h($s['status']) ?> (<?= (int)$s['c'] ?>)</option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-primary save-flash" type="submit">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
    Apply
  </button>
</form>

<div class="stat-tiles">
  <div class="stat-tile">
    <div class="ico amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg></div>
    <div><div class="num"><?= (int)$totalTasks ?></div><div class="lbl">Assigned</div></div>
  </div>
  <div class="stat-tile">
    <div class="ico blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>
    <div><div class="num"><?= (int)$openCount ?></div><div class="lbl">Open</div></div>
  </div>
  <div class="stat-tile <?= $dueSoon > 0 ? 'danger' : '' ?>">
    <div class="ico danger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg></div>
    <div><div class="num"><?= (int)$dueSoon ?></div><div class="lbl">Due in 3 days</div></div>
  </div>
</div>

<div class="table-wrap">
  <div class="table-card">
    <table class="tasks">
      <thead>
        <tr>
          <th>Task</th>
          <th>Client</th>
          <th>Project</th>
          <th>Status</th>
          <th>Due</th>
          <th style="width:90px;"></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$tasks): ?>
        <tr><td colspan="6" class="empty-row">No tasks found for this filter.</td></tr>
      <?php else: foreach ($tasks as $t):
        $statusCls = mt_status_pill_class((string)$t['status']);
        $dueCls = mt_due_cell_class($t['due_date'] ?? null, $todayStr);
        $rel = mt_relative_due($t['due_date'] ?? null, $todayStr);
        $clientGrad = mt_client_gradient((int)($t['client_id'] ?? 0), (string)($t['client_name'] ?? ''));
      ?>
        <tr>
          <td>
            <div class="task-cell">
              <div class="task-icon amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 12 8 16 20 4"></polyline></svg></div>
              <div>
                <a class="task-title" href="task_view.php?id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a>
                <div class="task-id">TASK-<?= str_pad((string)(int)$t['id'], 4, '0', STR_PAD_LEFT) ?></div>
              </div>
            </div>
          </td>
          <td>
            <div class="client-mini">
              <div class="client-mini-logo" style="background:<?= h($clientGrad) ?>"><?= h(user_initials((string)($t['client_name'] ?? ''))) ?></div>
              <span class="client-mini-name"><?= h($t['client_name'] ?? '') ?></span>
            </div>
          </td>
          <td><a class="project-link" href="project_view.php?id=<?= (int)($t['project_id'] ?? 0) ?>"><?= h($t['project_name'] ?? '') ?></a></td>
          <td>
            <form class="status-inline" method="post">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
              <input type="hidden" name="update_task_status" value="1">
              <span class="status-pill-wrap">
                <span class="dot-overlay"></span>
                <select class="status-inline-select sp-<?= $statusCls ?>" name="status" onchange="this.form.submit()" aria-label="Change task status">
                  <?php foreach ($taskStatusOptions as $opt): ?>
                    <option value="<?= h($opt) ?>" <?= $t['status'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                  <?php endforeach; ?>
                </select>
              </span>
            </form>
          </td>
          <td>
            <?php if (!empty($t['due_date'])): ?>
              <div class="due-cell <?= $dueCls ?>">
                <span><?= h(format_date((string)$t['due_date'])) ?></span>
                <span class="relative"><?= h($rel) ?></span>
              </div>
            <?php else: ?>
              <span style="color:var(--text-dim);">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;">
            <a class="open-btn" href="task_view.php?id=<?= (int)$t['id'] ?>">
              Open
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
            </a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <div class="table-foot">
      <span>Showing <b><?= count($tasks) ?></b> task<?= count($tasks) === 1 ? '' : 's' ?></span>
      <span>Updated <?= h(date('M j, H:i')) ?></span>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
