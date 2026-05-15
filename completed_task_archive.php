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
foreach ($rows as $r) $clients[$r['client_name']] = 1;

$pageTitle = 'Completed Task Archive';
$activeKey = 'archive';
$pageHeadExtra = <<<HTML
<style>
  .page-header { padding: 28px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; }
  .page-stats { display: flex; gap: 24px; font-variant-numeric: tabular-nums; }
  .stat { display: flex; flex-direction: column; gap: 2px; text-align: right; }
  .stat-num { font-size: 20px; font-weight: 600; color: var(--text); }
  .stat-label { font-size: 10.5px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; }
  .table-wrap { max-width: 1640px; margin: 24px auto 28px; width: 100%; padding: 0 32px; }
  .table-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  table.archive { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.archive thead th { text-align: left; padding: 12px 16px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-dim); background: var(--bg); border-bottom: 1px solid var(--border); }
  table.archive tbody tr { transition: background 0.12s; }
  table.archive tbody tr:hover { background: var(--surface-hover); }
  table.archive tbody td { padding: 14px 16px; border-bottom: 1px solid var(--border); color: var(--text); }
  table.archive tbody tr:last-child td { border-bottom: none; }
  .task-link { color: var(--text); font-weight: 600; text-decoration: none; }
  .task-link:hover { color: var(--accent); }
  .chip-done { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 500; color: #86efac; background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.25); }
  .chip-done .dot { width: 6px; height: 6px; border-radius: 50%; background: #22c55e; }
  .empty-state { padding: 48px 20px; text-align: center; color: var(--text-dim); font-size: 13px; }
  .empty-state svg { width: 32px; height: 32px; color: var(--text-dim); margin-bottom: 12px; }
  .btn-tiny { padding: 5px 11px; font-size: 11.5px; font-weight: 500; border-radius: 6px; transition: all 0.12s; border: 1px solid var(--border); display: inline-flex; align-items: center; gap: 5px; color: var(--text); text-decoration: none; }
  .btn-tiny:hover { background: var(--accent); color: #1a1400; border-color: var(--accent); }
  .btn-tiny svg { width: 11px; height: 11px; }
  .due-mono { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px; color: var(--text-muted); }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Completed Task Archive</div>
      <div class="page-sub">Final archive for tasks already submitted to the client.</div>
    </div>
  </div>
  <div class="page-stats">
    <div class="stat"><span class="stat-num"><?= (int)$total ?></span><span class="stat-label">Archived</span></div>
    <div class="stat"><span class="stat-num"><?= (int)count($clients) ?></span><span class="stat-label">Clients</span></div>
  </div>
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
        <thead><tr><th>Task</th><th>Client</th><th>Project</th><th>Assignees</th><th>Status</th><th>Submitted</th><th style="text-align:right;width:90px;"></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a class="task-link" href="task_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a></td>
            <td><?= h($r['client_name']) ?></td>
            <td><?= h($r['project_name']) ?></td>
            <td style="color:var(--text-muted);"><?= h($r['assignees'] ?? 'Unassigned') ?></td>
            <td><span class="chip-done"><span class="dot"></span><?= h($r['status']) ?></span></td>
            <td><span class="due-mono"><?= h($r['submitted_at'] ?: $r['updated_at']) ?></span></td>
            <td style="text-align:right;">
              <a class="btn-tiny" href="task_view.php?id=<?= (int)$r['id'] ?>">Open<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
