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

$pageTitle = 'Review Completed Tasks';
$activeKey = 'review';
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
  .item { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 16px 20px; transition: all 0.15s; }
  .item:hover { border-color: var(--border-strong); }
  .item-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
  .item-left { min-width: 0; flex: 1; }
  .item-title { font-size: 15px; font-weight: 600; color: var(--text); }
  .item-meta { font-size: 12.5px; color: var(--text-muted); margin-top: 4px; }
  .item-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .chip-review { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 500; color: var(--accent); background: var(--accent-soft); border: 1px solid rgba(250,204,21,0.25); }
  .chip-review .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--accent); }
  .assignee-chip { padding: 4px 10px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text-muted); font-size: 11.5px; max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .disapprove-panel { display: none; padding-top: 14px; margin-top: 14px; border-top: 1px solid var(--border); gap: 8px; align-items: flex-start; flex-wrap: wrap; }
  .disapprove-panel.open { display: flex; }
  .disapprove-panel textarea { flex: 1; min-width: 260px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; font-size: 13px; color: var(--text); outline: none; resize: vertical; min-height: 56px; font-family: inherit; }
  .empty-state { padding: 48px 20px; text-align: center; color: var(--text-dim); font-size: 13px; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Manager Review Queue</div>
      <div class="page-sub">Completed tasks wait here until a manager approves them.</div>
    </div>
  </div>
  <div class="page-stats">
    <div class="stat"><span class="stat-num" style="color:var(--accent);"><?= (int)$total ?></span><span class="stat-label">Waiting</span></div>
    <div class="stat"><span class="stat-num"><?= (int)$client_count ?></span><span class="stat-label">Clients</span></div>
  </div>
</div>

<div class="list-wrap">
  <?php if (!$rows): ?>
    <div class="empty-state">No tasks are waiting for Manager review right now.</div>
  <?php else: foreach ($rows as $r): ?>
    <div class="item">
      <div class="item-head">
        <div class="item-left">
          <div class="item-title"><a href="task_view.php?id=<?= (int)$r['id'] ?>" style="color:inherit;"><?= h($r['title']) ?></a></div>
          <div class="item-meta"><?= h($r['client_name']) ?> · <?= h($r['project_name']) ?> · Updated <?= h(format_date($r['updated_at'])) ?></div>
        </div>
        <div class="item-right">
          <span class="chip-review"><span class="dot"></span><?= h($r['status']) ?></span>
          <span class="assignee-chip" title="<?= h($r['assignees'] ?? '—') ?>"><?= h($r['assignees'] ?? 'Unassigned') ?></span>
          <a class="btn btn-ghost" href="task_view.php?id=<?= (int)$r['id'] ?>" style="padding:6px 12px;font-size:12px;">Open</a>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="task_id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-primary save-flash" name="approve_task" value="1" style="padding:6px 14px;font-size:12px;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
              Approve
            </button>
          </form>
          <button type="button" class="btn btn-danger" onclick="toggleDisapprove(<?= (int)$r['id'] ?>)" style="padding:6px 12px;font-size:12px;">Disapprove</button>
        </div>
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
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
