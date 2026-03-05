<?php
require_once __DIR__ . '/layout.php';
$pdo = db(); $ws = auth_workspace_id();
$role = auth_user()['role_name'] ?? '';
if (!in_array($role, ['CEO','CTO','Super Admin'], true)) { http_response_code(403); echo 'Forbidden'; require __DIR__ . '/layout_end.php'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post(); csrf_verify();
  $action = $_POST['action'] ?? 'add';

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { flash_set('error', 'Invalid task status selected.'); redirect('settings_task_statuses.php'); }
    try {
      $st = $pdo->prepare('DELETE FROM task_statuses WHERE workspace_id = ? AND id = ?');
      $st->execute([$ws, $id]);
      flash_set('success', $st->rowCount() ? 'Task status deleted permanently.' : 'Task status not found.');
    } catch (Throwable $e) {
      flash_set('error', 'Unable to delete this task status. It may still be in use.');
    }
    redirect('settings_task_statuses.php');
  }

  if ($action === 'bulk_delete') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    if (!$ids) { flash_set('error', 'Select at least one task status to delete.'); redirect('settings_task_statuses.php'); }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    try {
      $st = $pdo->prepare("DELETE FROM task_statuses WHERE workspace_id = ? AND id IN ($marks)");
      $st->execute(array_merge([$ws], $ids));
      flash_set('success', $st->rowCount() . ' task status(s) deleted permanently.');
    } catch (Throwable $e) {
      flash_set('error', 'Some selected task status values could not be deleted because they are in use.');
    }
    redirect('settings_task_statuses.php');
  }

  $name = trim($_POST['name'] ?? '');
  if ($name === '') { flash_set('error', 'Name required.'); redirect('settings_task_statuses.php'); }
  $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM task_statuses WHERE workspace_id=$ws")->fetch()['n'];
  $pdo->prepare("INSERT INTO task_statuses (workspace_id,name,sort_order,created_at,updated_at) VALUES (?,?,?,?,?)")
      ->execute([$ws, $name, $sort, now(), now()]);
  flash_set('success', 'Added.');
  redirect('settings_task_statuses.php');
}
$rows = $pdo->query("SELECT * FROM task_statuses WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();
?>

<style>
  .sts-shell{border:1px solid rgba(255,255,255,.1);border-radius:18px;background:linear-gradient(180deg,#111111,#090909);padding:1.15rem;box-shadow:0 24px 60px rgba(0,0,0,.4)}
  .sts-head{display:flex;justify-content:space-between;align-items:end;gap:.7rem;margin-bottom:1rem}
  .sts-title{margin:0;font-size:2rem;font-weight:700}
  .sts-sub{color:rgba(232,232,232,.68);font-size:.92rem}
  .sts-kpi{border:1px solid rgba(255,255,255,.1);border-radius:10px;background:rgba(255,255,255,.03);padding:.45rem .65rem;text-align:center;min-width:120px}
  .sts-kpi .n{font-weight:700;font-size:1.25rem;line-height:1}
  .sts-kpi .l{font-size:.78rem;color:rgba(232,232,232,.68)}
  .sts-grid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:.8rem}
  .sts-card{border:1px solid rgba(255,255,255,.1);border-radius:12px;background:rgba(255,255,255,.02);padding:.85rem}
  .sts-card-title{font-weight:600;margin-bottom:.55rem}
  .sts-table{overflow:hidden;border:1px solid rgba(255,255,255,.08);border-radius:10px;background:rgba(255,255,255,.015)}
  .sts-table table{width:100%;border-collapse:collapse}
  .sts-table th,.sts-table td{padding:.7rem .72rem;border-bottom:1px solid rgba(255,255,255,.07)}
  .sts-table th{background:rgba(255,255,255,.03);color:rgba(228,228,228,.82);font-size:.85rem;font-weight:600}
  .sts-table tr:last-child td{border-bottom:0}
  @media (max-width: 1100px){.sts-grid{grid-template-columns:1fr}}
</style>

<div class="sts-shell">
  <div class="sts-head">
    <div>
      <h1 class="sts-title">Task Statuses</h1>
      <div class="sts-sub">Define task statuses used by assignees and dashboard views.</div>
    </div>
    <div class="sts-kpi"><div class="n"><?= count($rows) ?></div><div class="l">Total Statuses</div></div>
  </div>

  <div class="sts-grid">
    <div class="sts-card">
      <div class="sts-card-title">Add Status</div>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <div class="col-12"><input class="form-control" name="name" placeholder="e.g. To Do" required></div>
        <div class="col-12"><button class="btn btn-yellow w-100">Add Task Status</button></div>
      </form>
    </div>

    <div class="sts-card">
      <div class="sts-card-title d-flex justify-content-between align-items-center">Current Statuses<button type="submit" form="bulkDeleteStatuses" class="btn btn-sm btn-outline-danger" onclick="return confirm('This will permanently delete selected task statuses. Are you sure?');">Delete Selected</button></div>
      <div class="sts-table">
        <form method="post" id="bulkDeleteStatuses">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="bulk_delete">
        </form>
        <table>
          <thead><tr><th style="width:44px;"><input type="checkbox" onclick="document.querySelectorAll('.status-check').forEach(cb=>cb.checked=this.checked)"></th><th>Name</th><th class="text-muted">Sort</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
            <?php foreach($rows as $r): ?><tr><td><input class="status-check" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" form="bulkDeleteStatuses"></td><td class="fw-semibold"><?=h($r['name'])?></td><td class="text-muted"><?=h($r['sort_order'])?></td><td class="text-end"><form method="post" style="display:inline" onsubmit="return confirm('This will permanently delete this status. Are you sure?');"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td></tr><?php endforeach; ?>
            <?php if(!$rows): ?><tr><td colspan="4" class="text-muted">No statuses yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
