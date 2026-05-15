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
foreach ($rows as $r) $projects_ready[$r['project_name']] = 1;
$project_count = count($projects_ready);

$pageTitle = 'Submit to Client';
$activeKey = 'submit';
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
  .list-wrap { max-width: 1640px; margin: 24px auto 28px; width: 100%; padding: 0 32px; display: flex; flex-direction: column; gap: 12px; }
  .item { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; transition: all 0.15s; }
  .item:hover { border-color: var(--border-strong); }
  .item-left { min-width: 0; flex: 1; }
  .item-title { font-size: 15px; font-weight: 600; color: var(--text); }
  .item-meta { font-size: 12.5px; color: var(--text-muted); margin-top: 4px; }
  .item-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .chip-ready { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 500; color: #86efac; background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.25); }
  .chip-ready .dot { width: 6px; height: 6px; border-radius: 50%; background: #22c55e; }
  .assignee-chip { padding: 4px 10px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text-muted); font-size: 11.5px; max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .empty-state { padding: 48px 20px; text-align: center; color: var(--text-dim); font-size: 13px; }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Submit to Client</div>
      <div class="page-sub">Approved tasks that are ready for final client delivery.</div>
    </div>
  </div>
  <div class="page-stats">
    <div class="stat"><span class="stat-num" style="color:#86efac;"><?= (int)$total ?></span><span class="stat-label">Ready</span></div>
    <div class="stat"><span class="stat-num"><?= (int)$project_count ?></span><span class="stat-label">Projects</span></div>
  </div>
</div>

<div class="list-wrap">
  <?php if (!$rows): ?>
    <div class="empty-state" style="background:var(--surface);border:1px solid var(--border);border-radius:14px;">No tasks are currently ready to submit.</div>
  <?php else: foreach ($rows as $r): ?>
    <div class="item">
      <div class="item-left">
        <div class="item-title"><a href="task_view.php?id=<?= (int)$r['id'] ?>" style="color:inherit;"><?= h($r['title']) ?></a></div>
        <div class="item-meta"><?= h($r['client_name']) ?> · <?= h($r['project_name']) ?> · Updated <?= h(format_date($r['updated_at'])) ?></div>
      </div>
      <div class="item-right">
        <span class="chip-ready"><span class="dot"></span><?= h($r['status']) ?></span>
        <span class="assignee-chip" title="<?= h($r['assignees'] ?? '—') ?>"><?= h($r['assignees'] ?? 'Unassigned') ?></span>
        <a class="btn btn-ghost" href="task_view.php?id=<?= (int)$r['id'] ?>" style="padding:6px 12px;font-size:12px;">Open</a>
        <form method="post" style="display:inline;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="task_id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-primary save-flash" name="submit_task" value="1" style="padding:6px 14px;font-size:12px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            Submitted
          </button>
        </form>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
