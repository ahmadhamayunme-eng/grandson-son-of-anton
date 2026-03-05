<?php
require_once __DIR__ . '/layout.php';
$pdo = db(); $ws = auth_workspace_id();
$role = auth_user()['role_name'] ?? '';
if (!in_array($role, ['CEO','Manager','Super Admin'], true)) { http_response_code(403); echo 'Forbidden'; require __DIR__ . '/layout_end.php'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post(); csrf_verify();
  $action = $_POST['action'] ?? 'add';

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { flash_set('error', 'Invalid project status selected.'); redirect('settings_project_statuses.php'); }
    try {
      $st = $pdo->prepare('DELETE FROM project_statuses WHERE workspace_id = ? AND id = ?');
      $st->execute([$ws, $id]);
      flash_set('success', $st->rowCount() ? 'Project status deleted permanently.' : 'Project status not found.');
    } catch (Throwable $e) {
      flash_set('error', 'Unable to delete this project status. It may still be in use.');
    }
    redirect('settings_project_statuses.php');
  }

  if ($action === 'bulk_delete') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    if (!$ids) { flash_set('error', 'Select at least one project status to delete.'); redirect('settings_project_statuses.php'); }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    try {
      $st = $pdo->prepare("DELETE FROM project_statuses WHERE workspace_id = ? AND id IN ($marks)");
      $st->execute(array_merge([$ws], $ids));
      flash_set('success', $st->rowCount() . ' project status(s) deleted permanently.');
    } catch (Throwable $e) {
      flash_set('error', 'Some selected project status values could not be deleted because they are in use.');
    }
    redirect('settings_project_statuses.php');
  }

  $name = trim($_POST['name'] ?? '');
  if ($name === '') { flash_set('error', 'Name required.'); redirect('settings_project_statuses.php'); }
  $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM project_statuses WHERE workspace_id=$ws")->fetch()['n'];
  $pdo->prepare("INSERT INTO project_statuses (workspace_id,name,sort_order,created_at,updated_at) VALUES (?,?,?,?,?)")
      ->execute([$ws, $name, $sort, now(), now()]);
  flash_set('success', 'Added.');
  redirect('settings_project_statuses.php');
}
$rows = $pdo->query("SELECT * FROM project_statuses WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();
?>

<style>
  .sps-shell{border:1px solid rgba(255,255,255,.1);border-radius:18px;background:linear-gradient(180deg,#111111,#090909);padding:1.15rem;box-shadow:0 24px 60px rgba(0,0,0,.4)}
  .sps-head{display:flex;justify-content:space-between;align-items:end;gap:.7rem;margin-bottom:1rem}
  .sps-title{margin:0;font-size:2rem;font-weight:700}
  .sps-sub{color:rgba(232,232,232,.68);font-size:.92rem}
  .sps-kpi{border:1px solid rgba(255,255,255,.1);border-radius:10px;background:rgba(255,255,255,.03);padding:.45rem .65rem;text-align:center;min-width:120px}
  .sps-kpi .n{font-weight:700;font-size:1.25rem;line-height:1}
  .sps-kpi .l{font-size:.78rem;color:rgba(232,232,232,.68)}
  .sps-grid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:.8rem}
  .sps-card{border:1px solid rgba(255,255,255,.1);border-radius:12px;background:rgba(255,255,255,.02);padding:.85rem}
  .sps-card-title{font-weight:600;margin-bottom:.55rem}
  .sps-table{overflow:hidden;border:1px solid rgba(255,255,255,.08);border-radius:10px;background:rgba(255,255,255,.015)}
  .sps-table table{width:100%;border-collapse:collapse}
  .sps-table th,.sps-table td{padding:.7rem .72rem;border-bottom:1px solid rgba(255,255,255,.07)}
  .sps-table th{background:rgba(255,255,255,.03);color:rgba(228,228,228,.82);font-size:.85rem;font-weight:600}
  .sps-table tr:last-child td{border-bottom:0}
  @media (max-width: 1100px){.sps-grid{grid-template-columns:1fr}}
</style>

<div class="sps-shell">
  <div class="sps-head">
    <div>
      <h1 class="sps-title">Project Statuses</h1>
      <div class="sps-sub">Define project workflow statuses used across boards and reports.</div>
    </div>
    <div class="sps-kpi"><div class="n"><?= count($rows) ?></div><div class="l">Total Statuses</div></div>
  </div>

  <div class="sps-grid">
    <div class="sps-card">
      <div class="sps-card-title">Add Status</div>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <div class="col-12"><input class="form-control" name="name" placeholder="e.g. In Progress" required></div>
        <div class="col-12"><button class="btn btn-yellow w-100">Add Project Status</button></div>
      </form>
    </div>

    <div class="sps-card">
      <div class="sps-card-title d-flex justify-content-between align-items-center">Current Statuses<button type="submit" form="bulkDeleteStatuses" class="btn btn-sm btn-outline-danger" onclick="return confirm('This will permanently delete selected project statuses. Are you sure?');">Delete Selected</button></div>
      <div class="sps-table">
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
