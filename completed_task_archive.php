<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();
auth_require_any(['Manager','Super Admin']);

$pdo = db();
$ws = auth_workspace_id();

$stmt = $pdo->prepare("SELECT t.id,t.title,t.status,t.updated_at,t.submitted_at,p.name AS project_name,c.name AS client_name,
  GROUP_CONCAT(u.name SEPARATOR ', ') AS assignees
  FROM tasks t
  JOIN projects p ON p.id=t.project_id
  JOIN clients c ON c.id=p.client_id
  LEFT JOIN task_assignees ta ON ta.task_id=t.id
  LEFT JOIN users u ON u.id=ta.user_id
  WHERE t.workspace_id=? AND t.status='Submitted to Client'
  GROUP BY t.id
  ORDER BY COALESCE(t.submitted_at,t.updated_at) DESC, t.id DESC");
$stmt->execute([$ws]);
$rows = $stmt->fetchAll();

$total = count($rows);
$clients = [];
$projectsArchived = [];
foreach ($rows as $r) {
  if (!empty($r['client_name'])) $clients[$r['client_name']] = 1;
  if (!empty($r['project_name'])) $projectsArchived[$r['project_name']] = 1;
}
$clientList = array_keys($clients); sort($clientList);
$projectList = array_keys($projectsArchived); sort($projectList);

$weekAgo = strtotime('-7 days');
$monthAgo = strtotime('-30 days');
$thisWeek = 0; $thisMonth = 0;
foreach ($rows as $r) {
  $ts = strtotime((string)($r['submitted_at'] ?: $r['updated_at']));
  if ($ts >= $weekAgo) $thisWeek++;
  if ($ts >= $monthAgo) $thisMonth++;
}

function cta_gradient(string $seed): string {
  $palette = [
    'linear-gradient(135deg,#10b981,#059669)','linear-gradient(135deg,#7c3aed,#5b21b6)',
    'linear-gradient(135deg,#0ea5e9,#0369a1)','linear-gradient(135deg,#f43f5e,#9f1239)',
    'linear-gradient(135deg,#fb923c,#ea580c)','linear-gradient(135deg,#fb7185,#be123c)',
    'linear-gradient(135deg,#facc15,#ca8a04)','linear-gradient(135deg,#22c55e,#15803d)',
    'linear-gradient(135deg,#64748b,#334155)',
  ];
  return $palette[crc32($seed) % count($palette)];
}
function cta_ago(string $ts): string {
  $secs = max(0, time() - strtotime($ts));
  if ($secs < 60) return 'just now';
  $mins = (int)floor($secs / 60);
  if ($mins < 60) return $mins . 'm ago';
  $hrs = (int)floor($mins / 60);
  if ($hrs < 24) return $hrs . 'h ago';
  $days = (int)floor($hrs / 24);
  if ($days < 30) return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
  $months = (int)floor($days / 30);
  if ($months < 12) return $months . ' month' . ($months === 1 ? '' : 's') . ' ago';
  return (int)floor($months / 12) . 'y ago';
}

$pageTitle = 'Completed Task Archive';
$activeKey = 'archive';
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
    padding: 8px 14px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 999px;
    font-size: 12px; color: var(--text-muted);
  }
  .h-pill svg { width: 12px; height: 12px; color: var(--text-dim); }
  .h-pill .v {
    color: var(--text); font-weight: 600;
    font-variant-numeric: tabular-nums;
  }
  .h-pill.status .v { color: #86efac; }

  .toolbar {
    max-width: 1640px; margin: 0 auto; width: 100%;
    padding: 24px 32px 16px;
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 12px;
    align-items: center;
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
  .tabs-meta { font-size: 12px; color: var(--text-dim); font-variant-numeric: tabular-nums; }

  .table-wrap {
    max-width: 1640px; margin: 0 auto; width: 100%;
    padding: 18px 32px 28px;
  }
  .table-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; overflow: hidden;
  }
  table.archive { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.archive thead th {
    text-align: left; padding: 12px 16px;
    font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em;
    text-transform: uppercase; color: var(--text-dim);
    background: var(--bg); border-bottom: 1px solid var(--border); white-space: nowrap;
  }
  table.archive thead th .sort-arrow { margin-left: 4px; color: var(--text-muted); }
  table.archive tbody tr {
    transition: background 0.12s;
  }
  table.archive tbody tr:hover { background: var(--surface-hover); }
  table.archive tbody td {
    padding: 14px 16px; border-bottom: 1px solid var(--border);
    color: var(--text); vertical-align: middle;
  }
  table.archive tbody tr:last-child td { border-bottom: none; }

  .task-cell {
    display: flex; align-items: center; gap: 12px; min-width: 0;
  }
  .task-icon {
    width: 30px; height: 30px; border-radius: 7px;
    background: rgba(34,197,94,0.08); color: #86efac;
    border: 1px solid rgba(34,197,94,0.2);
    display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
  }
  .task-icon svg { width: 14px; height: 14px; }
  .task-title { font-weight: 600; font-size: 13.5px; color: var(--text); line-height: 1.3; }
  .task-title a { color: inherit; text-decoration: none; }
  .task-title a:hover { color: var(--accent); }
  .task-id {
    font-size: 11px; color: var(--text-dim);
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    margin-top: 2px;
  }

  .client-mini { display: flex; align-items: center; gap: 9px; color: var(--text); }
  .client-mini-logo {
    width: 22px; height: 22px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 9.5px; font-weight: 600; color: #fff; letter-spacing: 0.02em;
    flex-shrink: 0;
  }
  .client-mini-name { font-size: 12.5px; font-weight: 500; }

  .project-link {
    color: var(--text-muted); font-size: 12.5px;
    border-bottom: 1px dashed var(--border-strong);
    padding-bottom: 1px; transition: all 0.12s;
    text-decoration: none;
  }
  .project-link:hover { color: var(--accent); border-bottom-color: var(--accent); }

  .assignee { display: flex; align-items: center; gap: 9px; }
  .av {
    width: 24px; height: 24px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 600; color: #fff;
    letter-spacing: 0.02em; flex-shrink: 0;
  }
  .assignee-name { font-size: 12.5px; color: var(--text); }

  .status-pill {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 4px 10px 4px 9px; border-radius: 999px;
    font-size: 11.5px; font-weight: 500;
    color: #86efac;
    background: rgba(34,197,94,0.08);
    border: 1px solid rgba(34,197,94,0.25);
  }
  .status-pill .dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: #22c55e;
    box-shadow: 0 0 0 2px rgba(34,197,94,0.18);
  }

  .ts-cell {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 12.5px; color: var(--text);
    font-variant-numeric: tabular-nums;
    display: flex; flex-direction: column; gap: 2px;
  }
  .ts-cell .date { color: var(--text); }
  .ts-cell .ago { color: var(--text-dim); font-size: 11px; }

  .open-btn {
    background: transparent; color: var(--text);
    border: 1px solid var(--border); border-radius: 7px;
    padding: 6px 14px; font-size: 12px; font-weight: 500;
    transition: all 0.12s;
    display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none;
  }
  .open-btn:hover { background: var(--accent); color: #1a1400; border-color: var(--accent); }
  .open-btn svg { width: 11px; height: 11px; }

  .archive-foot {
    padding: 12px 18px; border-top: 1px solid var(--border);
    background: var(--bg);
    display: flex; align-items: center; justify-content: space-between;
    font-size: 12px; color: var(--text-dim);
    font-variant-numeric: tabular-nums;
  }
  .archive-foot b { color: var(--text-muted); font-weight: 500; }
  .archive-foot .right { display: inline-flex; align-items: center; gap: 8px; }
  .archive-foot .right .dot {
    width: 5px; height: 5px; border-radius: 50%;
    background: #22c55e; box-shadow: 0 0 0 2px rgba(34,197,94,0.18);
  }

  .empty-state { padding: 48px 20px; text-align: center; color: var(--text-dim); font-size: 13px; }
  .empty-state svg { width: 32px; height: 32px; color: var(--text-dim); margin-bottom: 12px; }

  /* ===== MOBILE (≤768px) =====
     Screenshot showed the 7-column archive table (Task / Client /
     Project / Assignees / Status / Submitted / Open) overflowing — the
     Project column was clipped at the right edge and the rest scrolled
     off. Convert to vertical cards and tidy the header/toolbar/tabs. */
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

    /* Tabs scroll horizontally inside their own row. */
    .tabs-row { padding: 0 16px; flex-wrap: wrap; }
    .tabs-row .tabs { overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; max-width: 100%; }
    .tabs-row .tabs::-webkit-scrollbar { display: none; }
    .tabs-row .tab { flex-shrink: 0; padding: 12px 12px; }
    .tabs-meta { font-size: 11px; }

    .table-wrap { padding: 12px 16px 24px; }

    /* ===== TABLE → VERTICAL CARDS =====
       Desktop column order is Task / Client / Project / Assignees /
       Status / Submitted / Open. Hide thead and lay each row out as a
       stack so nothing scrolls sideways. */
    table.archive thead { display: none; }
    table.archive, table.archive tbody, table.archive tr { display: block; }
    table.archive tbody tr {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      grid-template-areas:
        "task    task"
        "client  submitted"
        "proj    status"
        "assg    assg"
        "actions actions";
      gap: 8px 12px;
      padding: 14px 16px;
      border-bottom: 1px solid var(--border);
      align-items: center;
    }
    table.archive tbody tr:last-child { border-bottom: none; }
    table.archive tbody td {
      display: block;
      padding: 0;
      border: none;
      min-width: 0;
      max-width: 100%;
    }
    table.archive tbody td:nth-child(1) { grid-area: task; }
    table.archive tbody td:nth-child(2) { grid-area: client; }
    table.archive tbody td:nth-child(3) { grid-area: proj; }
    table.archive tbody td:nth-child(4) { grid-area: assg; }
    table.archive tbody td:nth-child(5) { grid-area: status; text-align: right; }
    table.archive tbody td:nth-child(6) { grid-area: submitted; text-align: right; }
    table.archive tbody td:nth-child(7) { grid-area: actions; text-align: right !important; }

    .task-title { font-size: 14.5px; }
    .task-title a { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .client-mini-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .project-link { font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; max-width: 100%; }
    .ts-cell { font-size: 11.5px; align-items: flex-end; }
    .open-btn { min-height: 38px; padding: 8px 14px; font-size: 12.5px; justify-content: center; width: 100%; }

    .archive-foot { flex-direction: column; align-items: flex-start; gap: 6px; padding: 12px 16px; }
  }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Completed Task Archive</div>
      <div class="page-sub">Final archive for tasks already submitted to the client. Read-only once archived &mdash; open a task to view the full history.</div>
    </div>
  </div>
  <div class="header-pills">
    <span class="h-pill"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8"></path><path d="M1 3h22v5H1z"></path><line x1="10" y1="12" x2="14" y2="12"></line></svg>Archived: <span class="v"><?= (int)$total ?></span></span>
    <span class="h-pill"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>Clients: <span class="v"><?= (int)count($clientList) ?></span></span>
    <span class="h-pill status"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>Status: <span class="v">Submitted to Client</span></span>
  </div>
</div>

<div class="toolbar">
  <div class="search-box">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
    <input id="searchInp" placeholder="Search archived tasks&hellip;">
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
    <button type="button" class="tab active">All <span class="count"><?= (int)$total ?></span></button>
    <button type="button" class="tab">This week <span class="count"><?= (int)$thisWeek ?></span></button>
    <button type="button" class="tab">This month <span class="count"><?= (int)$thisMonth ?></span></button>
  </div>
  <span class="tabs-meta">Read-only &middot; Submitted to Client</span>
</div>

<div class="table-wrap">
  <div class="table-card">
    <?php if (!$rows): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8"></path><path d="M1 3h22v5H1z"></path><line x1="10" y1="12" x2="14" y2="12"></line></svg>
        <div>No submitted tasks have been archived yet.</div>
      </div>
    <?php else: ?>
      <table class="archive">
        <thead>
          <tr>
            <th>Task</th>
            <th>Client</th>
            <th>Project</th>
            <th>Assignees</th>
            <th>Status</th>
            <th>Submitted <span class="sort-arrow">&darr;</span></th>
            <th style="width:90px;"></th>
          </tr>
        </thead>
        <tbody id="rows">
        <?php foreach ($rows as $r):
          $clientGrad = cta_gradient((string)$r['client_name']);
          $assigneesText = (string)($r['assignees'] ?? '');
          $firstAssignee = $assigneesText !== '' ? trim(explode(',', $assigneesText)[0]) : '';
          $avGrad = cta_gradient($firstAssignee !== '' ? $firstAssignee : (string)$r['id']);
          $tsRaw = (string)($r['submitted_at'] ?: $r['updated_at']);
        ?>
          <tr data-client="<?= h($r['client_name']) ?>" data-project="<?= h($r['project_name']) ?>">
            <td>
              <div class="task-cell">
                <div class="task-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></div>
                <div>
                  <div class="task-title"><a href="task_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a></div>
                  <div class="task-id">TASK-<?= str_pad((string)(int)$r['id'], 4, '0', STR_PAD_LEFT) ?></div>
                </div>
              </div>
            </td>
            <td>
              <div class="client-mini">
                <div class="client-mini-logo" style="background:<?= h($clientGrad) ?>;"><?= h(user_initials((string)$r['client_name'])) ?></div>
                <span class="client-mini-name"><?= h($r['client_name']) ?></span>
              </div>
            </td>
            <td><span class="project-link"><?= h($r['project_name']) ?></span></td>
            <td>
              <?php if ($firstAssignee !== ''): ?>
                <div class="assignee" title="<?= h($assigneesText) ?>">
                  <div class="av" style="background:<?= h($avGrad) ?>;"><?= h(user_initials($firstAssignee)) ?></div>
                  <span class="assignee-name"><?= h($firstAssignee) ?></span>
                </div>
              <?php else: ?>
                <span style="color:var(--text-dim);">Unassigned</span>
              <?php endif; ?>
            </td>
            <td><span class="status-pill"><span class="dot"></span><?= h($r['status']) ?></span></td>
            <td>
              <div class="ts-cell">
                <span class="date"><?= h($tsRaw) ?></span>
                <?php if ($tsRaw): ?><span class="ago"><?= h(cta_ago($tsRaw)) ?></span><?php endif; ?>
              </div>
            </td>
            <td style="text-align:right;">
              <a class="open-btn" href="task_view.php?id=<?= (int)$r['id'] ?>">Open <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="archive-foot">
        <span>Showing <b id="visibleCount"><?= (int)$total ?></b> of <b><?= (int)$total ?></b> archived tasks</span>
        <span class="right"><span class="dot"></span><?= (int)$thisWeek ?> archived in the last week</span>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function() {
  const search = document.getElementById('searchInp');
  const clientF = document.getElementById('clientFilter');
  const projectF = document.getElementById('projectFilter');
  const counter = document.getElementById('visibleCount');
  function applyFilters() {
    if (!search) return;
    const q = (search.value || '').trim().toLowerCase();
    const c = clientF ? clientF.value : '';
    const p = projectF ? projectF.value : '';
    let visible = 0;
    document.querySelectorAll('#rows tr').forEach(tr => {
      const matchQ = !q || tr.textContent.toLowerCase().includes(q);
      const matchC = !c || tr.dataset.client === c;
      const matchP = !p || tr.dataset.project === p;
      const show = matchQ && matchC && matchP;
      tr.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    if (counter) counter.textContent = visible;
  }
  if (search) search.addEventListener('input', applyFilters);
  if (clientF) clientF.addEventListener('change', applyFilters);
  if (projectF) projectF.addEventListener('change', applyFilters);
})();
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
