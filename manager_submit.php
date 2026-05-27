<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();
auth_require_any(['Manager','Super Admin']);

$pdo = db(); $ws = auth_workspace_id(); $u = auth_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_task'])) {
  require_post(); csrf_verify();
  $task_id = (int)($_POST['task_id'] ?? 0);
  if ($task_id <= 0) { flash_set('error', 'Invalid task selected.'); redirect('manager_submit.php'); }
  $st = $pdo->prepare("UPDATE tasks SET status='Submitted to Client', submitted_at=?, submitted_by=?, updated_at=? WHERE id=? AND workspace_id=? AND status IN ('Approved','Approved (Ready to Submit)')");
  $now = now();
  $st->execute([$now, (int)$u['id'], $now, $task_id, $ws]);
  flash_set($st->rowCount() ? 'success' : 'error', $st->rowCount() ? 'Task submitted to client and archived.' : 'Task was not ready to submit.');
  redirect('completed_task_archive.php');
}

$stmt = $pdo->prepare("SELECT t.id,t.title,t.status,t.updated_at,p.name AS project_name,c.name AS client_name,
  GROUP_CONCAT(u.name SEPARATOR ', ') AS assignees
  FROM tasks t
  JOIN projects p ON p.id=t.project_id
  JOIN clients c ON c.id=p.client_id
  LEFT JOIN task_assignees ta ON ta.task_id=t.id
  LEFT JOIN users u ON u.id=ta.user_id
  WHERE t.workspace_id=? AND t.status IN ('Approved','Approved (Ready to Submit)')
  GROUP BY t.id
  ORDER BY t.updated_at DESC");
$stmt->execute([$ws]);
$rows = $stmt->fetchAll();

$total = count($rows);
$projects_ready = [];
$clients_ready = [];
foreach ($rows as $r) {
  $projects_ready[$r['project_name']] = 1;
  $clients_ready[$r['client_name']] = 1;
}
$project_count = count($projects_ready);

$clientList = array_keys($clients_ready); sort($clientList);
$projectList = array_keys($projects_ready); sort($projectList);

function ms_gradient(string $seed): string {
  $palette = [
    'linear-gradient(135deg,#10b981,#059669)','linear-gradient(135deg,#7c3aed,#5b21b6)',
    'linear-gradient(135deg,#0ea5e9,#0369a1)','linear-gradient(135deg,#f43f5e,#9f1239)',
    'linear-gradient(135deg,#fb923c,#ea580c)','linear-gradient(135deg,#ec4899,#be185d)',
    'linear-gradient(135deg,#facc15,#ca8a04)','linear-gradient(135deg,#22c55e,#15803d)',
  ];
  return $palette[crc32($seed) % count($palette)];
}

$pageTitle = 'Submit to Client';
$activeKey = 'submit';
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
  .h-pill.status .v { color: #86efac; }

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
  .ready-card {
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
  .ready-card::before {
    content: '';
    position: absolute; left: 0; top: 0; bottom: 0;
    width: 3px; background: #22c55e;
  }
  .ready-card:hover {
    background: var(--surface-2);
    border-color: var(--border-strong);
    transform: translateY(-1px);
  }

  .ready-main { min-width: 0; }
  .ready-title {
    font-size: 15px; font-weight: 600; color: var(--text);
    line-height: 1.35; letter-spacing: -0.01em;
    margin-bottom: 8px;
  }
  .ready-title a { color: inherit; text-decoration: none; }
  .ready-title a:hover { color: var(--accent); }
  .ready-meta {
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

  .ready-actions {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end;
  }
  .status-pill-approved {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 11px;
    border-radius: 999px;
    font-size: 11.5px; font-weight: 500;
    color: #86efac;
    background: rgba(34,197,94,0.08);
    border: 1px solid rgba(34,197,94,0.25);
  }
  .status-pill-approved .dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: #22c55e; box-shadow: 0 0 0 2px rgba(34,197,94,0.18);
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
  .btn-open:hover { background: var(--surface-2); color: var(--text); border-color: var(--border-strong); }
  .btn-open svg { width: 11px; height: 11px; }
  .btn-submit {
    background: var(--accent); color: #1a1400;
    border: 1px solid var(--accent); border-radius: 7px;
    padding: 6px 12px; font-size: 12px; font-weight: 600;
    transition: all 0.12s;
    display: inline-flex; align-items: center; gap: 5px;
    cursor: pointer;
  }
  .btn-submit:hover { background: var(--accent-hover); }
  .btn-submit svg { width: 11px; height: 11px; }

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
    font-size: 11px; font-weight: 700;
    font-variant-numeric: tabular-nums;
    flex-shrink: 0;
  }
  .howto-text strong { color: var(--text); font-weight: 500; }

  .empty-card {
    padding: 40px 20px;
    text-align: center;
    color: var(--text-dim);
    font-size: 13px;
    background: var(--surface); border: 1px solid var(--border); border-radius: 14px;
  }
  .empty-card svg { width: 32px; height: 32px; color: var(--text-dim); margin-bottom: 10px; }

  @media (max-width: 1100px) {
    .body-wrap { grid-template-columns: 1fr; }
    .rail { position: static; }
  }

  /* ===== MOBILE (≤768px) =====
     Same issue as manager_review: the ready-card kept its desktop
     2-column grid (1fr title | auto actions), so the Approved pill +
     assignee + Open / Submit buttons hogged the auto column and crushed
     the title's 1fr down to ~80px — "Update text on the Home page."
     wrapped one word per line. Stack the card and lay the actions out
     as a button grid; stack the toolbar too. */
  @media (max-width: 768px) {
    .page-header {
      flex-direction: column;
      align-items: stretch;
      padding: 16px 16px 0;
      gap: 12px;
    }
    .page-title { font-size: clamp(20px, 5.6vw, 26px); line-height: 1.2; }
    .page-sub { font-size: 12px; }

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

    .ready-card {
      grid-template-columns: 1fr;
      gap: 14px;
      padding: 16px 16px 16px 18px;
    }
    .ready-title { font-size: 15.5px; margin-bottom: 10px; }
    .ready-meta { gap: 8px 14px; }

    /* Actions → 2-col button grid: status pill + assignee on row 1,
       Open + Submit share row 2. */
    .ready-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      align-items: center;
      justify-content: stretch;
    }
    .ready-actions .status-pill-approved { grid-column: 1; justify-self: start; }
    .ready-actions .av-pill { grid-column: 2; justify-self: start; max-width: 100%; min-width: 0; }
    .ready-actions .btn-edit {
      grid-column: 1;
      justify-content: center; min-height: 42px; padding: 9px 12px; font-size: 13px;
    }
    .ready-actions .btn-open:not(.btn-edit) {
      grid-column: 2;
      justify-content: center; min-height: 42px; padding: 9px 12px; font-size: 13px;
    }
    .ready-actions form {
      grid-column: 1 / -1;
      display: flex;
    }
    .ready-actions form .btn-submit {
      width: 100%; justify-content: center; min-height: 42px; padding: 9px 12px; font-size: 13px;
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
      <div class="page-title">Submit to Client</div>
      <div class="page-sub">Approved tasks ready for final client delivery. Each submission moves the task to the archive.</div>
    </div>
  </div>
  <div class="header-pills">
    <span class="h-pill"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>Ready Tasks: <span class="v"><?= (int)$total ?></span></span>
    <span class="h-pill"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"></path></svg>Projects: <span class="v"><?= (int)$project_count ?></span></span>
    <span class="h-pill status"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>Status: <span class="v">Approved</span></span>
  </div>
</div>

<div class="toolbar">
  <div class="search-box">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
    <input id="searchInp" placeholder="Search approved tasks&hellip;">
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
    <button type="button" class="tab active">Ready <span class="count"><?= (int)$total ?></span></button>
  </div>
  <span class="tabs-meta">Sorted by Approval Date &middot; Newest first</span>
</div>

<div class="body-wrap">
  <div class="cards" id="cards">
    <?php if (!$rows): ?>
      <div class="empty-card">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
        <div>No tasks are currently ready to submit.</div>
      </div>
    <?php else: foreach ($rows as $r):
      $clientGrad = ms_gradient((string)$r['client_name']);
      $assigneesText = (string)($r['assignees'] ?? '');
      $firstAssignee = $assigneesText !== '' ? trim(explode(',', $assigneesText)[0]) : '';
      $avGrad = ms_gradient($firstAssignee !== '' ? $firstAssignee : (string)$r['id']);
    ?>
      <div class="ready-card" data-client="<?= h($r['client_name']) ?>" data-project="<?= h($r['project_name']) ?>">
        <div class="ready-main">
          <div class="ready-title"><a href="task_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a></div>
          <div class="ready-meta">
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
          </div>
        </div>
        <div class="ready-actions">
          <span class="status-pill-approved"><span class="dot"></span><?= h($r['status']) ?></span>
          <?php if ($assigneesText !== ''): ?>
            <span class="av-pill" title="<?= h($assigneesText) ?>">
              <span class="av" style="background:<?= h($avGrad) ?>;"><?= h(user_initials($firstAssignee)) ?></span>
              <span class="av-text"><?= h($firstAssignee) ?></span>
            </span>
          <?php endif; ?>
          <button type="button" class="btn-open btn-edit" data-task-edit="<?= (int)$r['id'] ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>Edit</button>
          <a class="btn-open" href="task_view.php?id=<?= (int)$r['id'] ?>">Open<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></a>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="task_id" value="<?= (int)$r['id'] ?>">
            <button class="btn-submit save-flash" name="submit_task" value="1">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
              Submit
            </button>
          </form>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <aside class="rail">
    <div class="panel">
      <div class="panel-head"><span class="panel-title">Today's queue</span></div>
      <div class="panel-body">
        <div class="stat-row"><span class="lbl">Ready to submit</span><span class="v accent"><?= (int)$total ?></span></div>
        <div class="stat-row"><span class="lbl">Projects involved</span><span class="v"><?= (int)$project_count ?></span></div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><span class="panel-title">How submission works</span></div>
      <div class="howto">
        <div class="howto-step">
          <span class="howto-num">1</span>
          <span class="howto-text">A manager marks the task <strong>Approved</strong> in the right-rail review panel.</span>
        </div>
        <div class="howto-step">
          <span class="howto-num">2</span>
          <span class="howto-text">It lands here, in <strong>Ready to submit</strong>.</span>
        </div>
        <div class="howto-step">
          <span class="howto-num">3</span>
          <span class="howto-text">Click <strong>Submit</strong> to mark it delivered. It moves to the <strong>Completed Task Archive</strong>.</span>
        </div>
      </div>
    </div>
  </aside>
</div>

<script>
(function() {
  const search = document.getElementById('searchInp');
  const clientF = document.getElementById('clientFilter');
  const projectF = document.getElementById('projectFilter');
  function applyFilters() {
    const q = (search.value || '').trim().toLowerCase();
    const c = clientF.value || '';
    const p = projectF.value || '';
    document.querySelectorAll('.ready-card').forEach(card => {
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
