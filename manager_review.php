<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();
auth_require_any(['Manager','Super Admin']);

$pdo = db(); $ws = auth_workspace_id(); $u = auth_user();

function mr_task_column_exists(PDO $pdo, string $column): bool {
  try { $st = $pdo->prepare("SHOW COLUMNS FROM tasks LIKE ?"); $st->execute([$column]); return (bool)$st->fetch(); }
  catch (Throwable $e) { return false; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disapprove_task'])) {
  require_post(); csrf_verify();
  $task_id = (int)($_POST['task_id'] ?? 0);
  $note = trim((string)($_POST['disapproval_note'] ?? ''));
  if ($task_id <= 0) { flash_set('error', 'Invalid task selected.'); redirect('manager_review.php'); }
  if ($note === '') { flash_set('error', 'Please add a note before disapproving the task.'); redirect('manager_review.php'); }

  $find = $pdo->prepare("SELECT id,project_id FROM tasks WHERE id=? AND workspace_id=? AND status IN ('Completed','Completed (Needs Manager Review)')");
  $find->execute([$task_id, $ws]);
  $task = $find->fetch();
  if (!$task) { flash_set('error', 'Task was not waiting for manager review.'); redirect('manager_review.php'); }

  $sets = ["status='In Progress'"];
  $params = [];
  if (mr_task_column_exists($pdo, 'manager_feedback')) { $sets[] = 'manager_feedback=?'; $params[] = $note; }
  if (mr_task_column_exists($pdo, 'internal_note')) { $sets[] = 'internal_note=?'; $params[] = $note; }
  $sets[] = 'updated_at=?';
  $params[] = now();
  $params[] = $task_id;
  $params[] = $ws;

  try {
    $st = $pdo->prepare('UPDATE tasks SET ' . implode(', ', $sets) . ' WHERE id=? AND workspace_id=?');
    $st->execute($params);
    flash_set('success', 'Task disapproved and returned to In Progress with your note.');
    redirect('project_view.php?id=' . (int)$task['project_id']);
  } catch (Throwable $e) {
    flash_set('error', 'Could not disapprove this task. Please try again or contact admin.');
    redirect('manager_review.php');
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_task'])) {
  require_post(); csrf_verify();
  $task_id = (int)($_POST['task_id'] ?? 0);
  if ($task_id <= 0) { flash_set('error', 'Invalid task selected.'); redirect('manager_review.php'); }
  $st = $pdo->prepare("UPDATE tasks SET status='Approved', updated_at=? WHERE id=? AND workspace_id=? AND status IN ('Completed','Completed (Needs Manager Review)')");
  $st->execute([now(), $task_id, $ws]);
  flash_set($st->rowCount() ? 'success' : 'error', $st->rowCount() ? 'Task approved and moved to Submit to Client.' : 'Task was not waiting for manager review.');
  redirect('manager_submit.php');
}

$stmt = $pdo->prepare("SELECT t.id,t.title,t.status,t.updated_at,p.name AS project_name,c.name AS client_name,
  GROUP_CONCAT(u.name SEPARATOR ', ') AS assignees
  FROM tasks t
  JOIN projects p ON p.id=t.project_id
  JOIN clients c ON c.id=p.client_id
  LEFT JOIN task_assignees ta ON ta.task_id=t.id
  LEFT JOIN users u ON u.id=ta.user_id
  WHERE t.workspace_id=? AND t.status IN ('Completed','Completed (Needs Manager Review)')
  GROUP BY t.id
  ORDER BY t.updated_at DESC");
$stmt->execute([$ws]);
$rows = $stmt->fetchAll();

$total = count($rows);
$unique_clients = [];
foreach ($rows as $r) $unique_clients[$r['client_name']] = 1;
$client_count = count($unique_clients);
$managerName = (string)($u['name'] ?? '');
$managerRole = (string)($u['role_name'] ?? 'Manager');

function mr_gradient(string $seed): string {
  $palette = [
    'linear-gradient(135deg,#10b981,#059669)','linear-gradient(135deg,#7c3aed,#5b21b6)',
    'linear-gradient(135deg,#0ea5e9,#0369a1)','linear-gradient(135deg,#f43f5e,#9f1239)',
    'linear-gradient(135deg,#fb923c,#ea580c)','linear-gradient(135deg,#ec4899,#be185d)',
    'linear-gradient(135deg,#facc15,#ca8a04)','linear-gradient(135deg,#22c55e,#15803d)',
  ];
  return $palette[crc32($seed) % count($palette)];
}
function mr_waiting_days(?string $ts): int {
  if (!$ts) return 0;
  $diff = (int)floor((time() - strtotime($ts)) / 86400);
  return max(0, $diff);
}

// Distinct clients / projects for filter selects
$clientList = [];
$projectList = [];
foreach ($rows as $r) {
  if (!empty($r['client_name'])) $clientList[$r['client_name']] = true;
  if (!empty($r['project_name'])) $projectList[$r['project_name']] = true;
}
$clientList = array_keys($clientList); sort($clientList);
$projectList = array_keys($projectList); sort($projectList);

$pageTitle = 'Manager Review Queue';
$activeKey = 'review';
$pageHeadExtra = <<<HTML
<style>
  .page-header {
    padding: 28px 32px 0;
    max-width: 1640px; margin: 0 auto; width: 100%;
    display: flex; align-items: flex-end; justify-content: space-between; gap: 16px;
  }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; max-width: 640px; }
  .header-pills { display: flex; gap: 8px; flex-wrap: wrap; }
  .h-pill {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 14px; background: var(--surface);
    border: 1px solid var(--border); border-radius: 999px;
    font-size: 12px; color: var(--text-muted);
  }
  .h-pill svg { width: 12px; height: 12px; color: var(--text-dim); }
  .h-pill .v { color: var(--text); font-weight: 600; font-variant-numeric: tabular-nums; }
  .h-pill.role .v { color: var(--accent); }

  .toolbar {
    max-width: 1640px; margin: 0 auto; width: 100%;
    padding: 24px 32px 16px;
    display: grid; grid-template-columns: 1fr auto auto; gap: 12px; align-items: center;
  }
  .search-box { position: relative; }
  .search-box svg {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    width: 15px; height: 15px; color: var(--text-dim);
  }
  .search-box input {
    width: 100%; background: var(--surface); border: 1px solid var(--border);
    border-radius: 10px; padding: 11px 14px 11px 40px;
    font-size: 13px; color: var(--text); outline: none;
    transition: all 0.15s; font-family: inherit;
  }
  .search-box input::placeholder { color: var(--text-dim); }
  .search-box input:focus { border-color: var(--border-strong); background: var(--surface-2); }
  .filter-select {
    background: var(--surface); border: 1px solid var(--border); border-radius: 10px;
    padding: 10px 32px 10px 14px; font-size: 13px; color: var(--text);
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%238a8a8a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center;
    cursor: pointer; outline: none; min-width: 140px;
  }

  .tabs-row {
    max-width: 1640px; margin: 0 auto; width: 100%;
    padding: 0 32px;
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    border-bottom: 1px solid var(--border);
  }
  .tabs-row .tabs { display: flex; gap: 4px; margin-bottom: -1px; }
  .tabs-row .tab {
    padding: 12px 16px; font-size: 13px; font-weight: 500; color: var(--text-muted);
    border-bottom: 2px solid transparent; transition: all 0.15s; cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    background: none; border-top: 0; border-left: 0; border-right: 0;
  }
  .tabs-row .tab:hover { color: var(--text); }
  .tabs-row .tab.active { color: var(--text); border-bottom-color: var(--accent); }
  .tabs-row .tab .count {
    font-size: 10.5px; background: var(--surface-2); color: var(--text-muted);
    padding: 1px 7px; border-radius: 999px; font-variant-numeric: tabular-nums;
  }
  .tabs-row .tab.active .count { background: var(--accent-soft); color: var(--accent); }
  .tabs-meta { font-size: 12px; color: var(--text-dim); }

  .body-wrap {
    max-width: 1640px; margin: 0 auto; width: 100%;
    padding: 18px 32px 28px;
    display: grid; grid-template-columns: 1fr 320px;
    gap: 20px; align-items: start;
  }

  .cards { display: flex; flex-direction: column; gap: 12px; }
  .review-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; padding: 18px 20px;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 18px;
    align-items: center;
    transition: all 0.15s;
    position: relative;
    overflow: hidden;
  }
  .review-card::before {
    content: '';
    position: absolute; left: 0; top: 0; bottom: 0;
    width: 3px; background: var(--accent);
  }
  .review-card:hover {
    background: var(--surface-2);
    border-color: var(--border-strong);
  }

  .r-main { min-width: 0; }
  .r-title {
    font-size: 15px; font-weight: 600; color: var(--text);
    line-height: 1.35; letter-spacing: -0.01em;
    margin-bottom: 8px;
  }
  .r-title a { color: inherit; text-decoration: none; }
  .r-title a:hover { color: var(--accent); }
  .r-meta {
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
    font-size: 12px; color: var(--text-muted);
  }
  .meta-item { display: inline-flex; align-items: center; gap: 6px; }
  .meta-item svg { width: 12px; height: 12px; color: var(--text-dim); }
  .meta-item .client-dot {
    width: 18px; height: 18px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 8.5px; font-weight: 600; color: #fff; letter-spacing: 0.02em;
  }
  .meta-item.updated {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 11.5px; color: var(--text-dim);
  }
  .meta-item.due-soon {
    color: #fbbf24;
    font-weight: 500;
  }

  .r-actions {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end;
  }
  .status-pill-completed {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 11px; border-radius: 999px;
    font-size: 11.5px; font-weight: 500;
    color: var(--accent);
    background: var(--accent-soft);
    border: 1px solid rgba(250,204,21,0.3);
  }
  .status-pill-completed .dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--accent); box-shadow: 0 0 0 2px rgba(250,204,21,0.18);
  }
  .av-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px 4px 4px;
    border-radius: 999px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    font-size: 11.5px; color: var(--text);
    max-width: 240px;
  }
  .av-pill .av-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .av-pill .av {
    width: 20px; height: 20px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 9px; font-weight: 600; color: #fff;
    flex-shrink: 0;
  }
  .btn-open {
    background: transparent; color: var(--text);
    border: 1px solid var(--border); border-radius: 7px;
    padding: 6px 12px; font-size: 12px; font-weight: 500;
    transition: all 0.12s;
    display: inline-flex; align-items: center; gap: 5px;
    text-decoration: none;
  }
  .btn-open:hover { background: var(--bg); border-color: var(--border-strong); }
  .btn-open svg { width: 11px; height: 11px; }

  .btn-approve {
    background: var(--accent); color: #1a1400;
    border: 1px solid var(--accent); border-radius: 7px;
    padding: 6px 14px; font-size: 12px; font-weight: 600;
    transition: all 0.12s;
    display: inline-flex; align-items: center; gap: 5px;
    cursor: pointer;
  }
  .btn-approve:hover { background: var(--accent-hover); }
  .btn-approve svg { width: 11px; height: 11px; }

  .btn-disapprove {
    background: transparent; color: #fca5a5;
    border: 1px solid rgba(239,68,68,0.3);
    border-radius: 7px;
    padding: 6px 12px; font-size: 12px; font-weight: 500;
    transition: all 0.12s;
    display: inline-flex; align-items: center; gap: 5px;
    cursor: pointer;
  }
  .btn-disapprove:hover { background: var(--danger-soft); border-color: rgba(239,68,68,0.5); }
  .btn-disapprove svg { width: 11px; height: 11px; }

  .disapprove-panel {
    display: none; grid-column: 1 / -1; padding-top: 14px; margin-top: 14px;
    border-top: 1px solid var(--border); gap: 8px; flex-wrap: wrap;
    align-items: flex-start;
  }
  .disapprove-panel.open { display: flex; }
  .disapprove-panel textarea {
    flex: 1; min-width: 260px;
    background: var(--surface-2); border: 1px solid var(--border);
    border-radius: 8px; padding: 8px 12px;
    font-size: 13px; color: var(--text);
    outline: none; resize: vertical; min-height: 56px; font-family: inherit;
  }

  .rail { display: flex; flex-direction: column; gap: 16px; position: sticky; top: 24px; }
  .panel {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; overflow: hidden;
  }
  .panel-head { padding: 14px 18px 10px; }
  .panel-title {
    font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.1em;
    color: var(--text-dim);
  }
  .panel-body { padding: 6px 18px 18px; }
  .stat-row {
    display: flex; align-items: baseline; gap: 8px;
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
  }
  .stat-row:last-child { border-bottom: none; }
  .stat-row .lbl { font-size: 12.5px; color: var(--text-muted); flex: 1; }
  .stat-row .v {
    font-size: 14px; font-weight: 600; color: var(--text);
    font-variant-numeric: tabular-nums;
  }
  .stat-row .v.accent { color: var(--accent); }
  .stat-row .v.success { color: #86efac; }
  .stat-row .v.danger { color: #fca5a5; }

  .howto {
    padding: 18px;
    font-size: 12.5px;
    color: var(--text-muted);
    line-height: 1.6;
  }
  .howto-step { display: flex; gap: 10px; padding: 8px 0; }
  .howto-num {
    width: 20px; height: 20px;
    background: var(--accent-soft);
    color: var(--accent);
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums;
    flex-shrink: 0;
  }
  .howto-text strong { color: var(--text); font-weight: 500; }

  .empty-state {
    padding: 48px 20px; text-align: center;
    color: var(--text-dim); font-size: 13px;
    background: var(--surface); border: 1px solid var(--border); border-radius: 14px;
  }

  @media (max-width: 1100px) {
    .body-wrap { grid-template-columns: 1fr; }
    .rail { position: static; }
  }

  /* ===== MOBILE (≤768px) =====
     Screenshot showed the review card stuck on its desktop 2-column grid
     (1fr title | auto actions): the status pill + assignee + Open /
     Approve / Disapprove buttons hogged the right column, crushing the
     title's 1fr down to ~80px so "Check Lee Yousaf PDF and Submit."
     wrapped one word per line. Stack the card, give the title full width,
     and lay the actions out as a tidy button grid. Also stack the
     toolbar so the search box isn't squeezed to an icon. */
  @media (max-width: 768px) {
    .page-header {
      flex-direction: column;
      align-items: stretch;
      padding: 16px 16px 0;
      gap: 12px;
    }
    .page-title { font-size: clamp(20px, 5.6vw, 26px); line-height: 1.2; }
    .page-sub { font-size: 12px; }

    /* Toolbar: search row 1 full-width, two filters share row 2. */
    .toolbar {
      grid-template-columns: 1fr 1fr;
      padding: 14px 16px 12px;
      gap: 8px;
    }
    .search-box { grid-column: 1 / -1; }
    .search-box input { font-size: 16px; padding: 12px 14px 12px 40px; min-height: 46px; }
    .filter-select { min-width: 0; width: 100%; font-size: 14px; min-height: 46px; }

    .tabs-row { padding: 0 16px; flex-wrap: wrap; }
    .tabs-meta { font-size: 11.5px; }

    .body-wrap { padding: 14px 16px 24px; gap: 14px; }

    /* The actual fix: single-column card. */
    .review-card {
      grid-template-columns: 1fr;
      gap: 14px;
      padding: 16px 16px 16px 18px;
    }
    .r-title { font-size: 15.5px; margin-bottom: 10px; }
    .r-meta { gap: 8px 14px; }

    /* Actions → 2-col button grid: status pill + assignee on row 1,
       Open + Approve on row 2, Disapprove full-width on row 3. */
    .r-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      align-items: center;
      justify-content: stretch;
    }
    .r-actions .status-pill-completed { justify-self: start; }
    .r-actions .av-pill { justify-self: start; max-width: 100%; min-width: 0; }
    .r-actions .btn-open {
      grid-column: 1;
      justify-content: center; min-height: 42px; padding: 9px 12px; font-size: 13px;
    }
    .r-actions form {
      grid-column: 2;
      display: flex;
    }
    .r-actions form .btn-approve {
      width: 100%; justify-content: center; min-height: 42px; padding: 9px 12px; font-size: 13px;
    }
    .r-actions .btn-disapprove {
      grid-column: 1 / -1;
      justify-content: center; min-height: 42px; padding: 9px 12px; font-size: 13px;
    }

    .disapprove-panel textarea { min-width: 0; }
    .disapprove-panel .btn { width: 100%; }
  }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Manager Review Queue</div>
      <div class="page-sub">Completed tasks wait here until a manager approves them &mdash; approved tasks move to <em>Submit to Client</em>.</div>
    </div>
  </div>
  <div class="header-pills">
    <span class="h-pill"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>Waiting: <span class="v"><?= (int)$total ?></span></span>
    <span class="h-pill"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>Clients: <span class="v"><?= (int)$client_count ?></span></span>
    <span class="h-pill role"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>Role: <span class="v"><?= h($managerRole) ?></span></span>
  </div>
</div>

<div class="toolbar">
  <div class="search-box">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
    <input id="searchInp" placeholder="Search completed tasks&hellip;">
  </div>
  <select class="filter-select" id="clientFilter">
    <option value="">All Clients</option>
    <?php foreach ($clientList as $cn): ?>
      <option value="<?= h($cn) ?>"><?= h($cn) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="filter-select" id="projectFilter">
    <option value="">All Projects</option>
    <?php foreach ($projectList as $pn): ?>
      <option value="<?= h($pn) ?>"><?= h($pn) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div class="tabs-row">
  <div class="tabs">
    <button type="button" class="tab active">Waiting <span class="count"><?= (int)$total ?></span></button>
  </div>
  <span class="tabs-meta">Oldest first<?= $managerName !== '' ? ' &middot; Reviewed by ' . h($managerName) : '' ?></span>
</div>

<div class="body-wrap">
  <div class="cards" id="cards">
    <?php if (!$rows): ?>
      <div class="empty-state">No tasks are waiting for Manager review right now.</div>
    <?php else: foreach ($rows as $r):
      $clientGrad = mr_gradient((string)$r['client_name']);
      $assigneesText = (string)($r['assignees'] ?? '');
      $firstAssignee = $assigneesText !== '' ? trim(explode(',', $assigneesText)[0]) : '';
      $avGrad = mr_gradient($firstAssignee !== '' ? $firstAssignee : (string)$r['id']);
      $wait = mr_waiting_days((string)$r['updated_at']);
    ?>
      <div class="review-card" data-client="<?= h($r['client_name']) ?>" data-project="<?= h($r['project_name']) ?>">
        <div class="r-main">
          <div class="r-title"><a href="task_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a></div>
          <div class="r-meta">
            <span class="meta-item">
              <span class="client-dot" style="background:<?= h($clientGrad) ?>;"><?= h(user_initials((string)$r['client_name'])) ?></span>
              <?= h($r['client_name']) ?>
            </span>
            <span class="meta-item">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"></path></svg>
              <?= h($r['project_name']) ?>
            </span>
            <span class="meta-item updated">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"></path></svg>
              Updated <?= h((string)$r['updated_at']) ?>
            </span>
            <?php if ($wait > 0): ?>
            <span class="meta-item due-soon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="6" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
              Waiting <?= (int)$wait ?> day<?= $wait === 1 ? '' : 's' ?>
            </span>
            <?php endif; ?>
          </div>
        </div>
        <div class="r-actions">
          <span class="status-pill-completed"><span class="dot"></span><?= h($r['status']) ?></span>
          <?php if ($assigneesText !== ''): ?>
            <span class="av-pill" title="<?= h($assigneesText) ?>">
              <span class="av" style="background:<?= h($avGrad) ?>;"><?= h(user_initials($firstAssignee)) ?></span>
              <span class="av-text"><?= h($firstAssignee) ?></span>
            </span>
          <?php endif; ?>
          <a class="btn-open" href="task_view.php?id=<?= (int)$r['id'] ?>">Open<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></a>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="task_id" value="<?= (int)$r['id'] ?>">
            <button class="btn-approve save-flash" name="approve_task" value="1">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
              Approve
            </button>
          </form>
          <button type="button" class="btn-disapprove" onclick="toggleDisapprove(<?= (int)$r['id'] ?>)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"></polyline><path d="M20 20v-7a4 4 0 00-4-4H4"></path></svg>
            Disapprove
          </button>
        </div>
        <form method="post" class="disapprove-panel" id="disapprove-<?= (int)$r['id'] ?>">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="task_id" value="<?= (int)$r['id'] ?>">
          <textarea name="disapproval_note" required placeholder="Why is this disapproved?"></textarea>
          <button class="btn btn-danger" name="disapprove_task" value="1" style="padding:8px 14px;">Submit Disapproval</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <aside class="rail">
    <div class="panel">
      <div class="panel-head"><span class="panel-title">Today's review queue</span></div>
      <div class="panel-body">
        <div class="stat-row"><span class="lbl">Waiting on you</span><span class="v accent"><?= (int)$total ?></span></div>
        <div class="stat-row"><span class="lbl">Distinct clients</span><span class="v"><?= (int)$client_count ?></span></div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><span class="panel-title">How review works</span></div>
      <div class="howto">
        <div class="howto-step">
          <span class="howto-num">1</span>
          <span class="howto-text">A team member marks a task <strong>Completed</strong>. It enters this queue.</span>
        </div>
        <div class="howto-step">
          <span class="howto-num">2</span>
          <span class="howto-text">Click <strong>Open</strong> to inspect the task, then <strong>Approve</strong> or <strong>Disapprove</strong>.</span>
        </div>
        <div class="howto-step">
          <span class="howto-num">3</span>
          <span class="howto-text">Approved tasks land in <strong>Submit to Client</strong>. Disapproved tasks return to the assignee with your note.</span>
        </div>
      </div>
    </div>
  </aside>
</div>

<script>
function toggleDisapprove(taskId) {
  const panel = document.getElementById('disapprove-' + taskId);
  if (!panel) return;
  panel.classList.toggle('open');
  if (panel.classList.contains('open')) {
    const note = panel.querySelector('textarea');
    if (note) note.focus();
  }
}

(function() {
  const search = document.getElementById('searchInp');
  const clientF = document.getElementById('clientFilter');
  const projectF = document.getElementById('projectFilter');
  function applyFilters() {
    const q = (search.value || '').trim().toLowerCase();
    const c = clientF.value || '';
    const p = projectF.value || '';
    document.querySelectorAll('.review-card').forEach(card => {
      const matchQ = !q || card.textContent.toLowerCase().includes(q);
      const matchC = !c || card.dataset.client === c;
      const matchP = !p || card.dataset.project === p;
      card.style.display = (matchQ && matchC && matchP) ? '' : 'none';
    });
  }
  if (search) search.addEventListener('input', applyFilters);
  if (clientF) clientF.addEventListener('change', applyFilters);
  if (projectF) projectF.addEventListener('change', applyFilters);
})();
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
