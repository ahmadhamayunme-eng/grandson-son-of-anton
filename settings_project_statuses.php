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
    if ($id <= 0) { flash_set('error', 'Invalid project type selected.'); redirect('settings_project_statuses.php'); }
    try {
      $st = $pdo->prepare('DELETE FROM project_statuses WHERE workspace_id = ? AND id = ?');
      $st->execute([$ws, $id]);
      flash_set('success', $st->rowCount() ? 'Project status deleted permanently.' : 'Project status not found.');
    } catch (Throwable $e) { flash_set('error', 'Unable to delete this project type. It may still be used by projects.'); }
    redirect('settings_project_statuses.php');
  }

  if ($action === 'bulk_delete') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    if (!$ids) { flash_set('error', 'Select at least one project type to delete.'); redirect('settings_project_statuses.php'); }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    try {
      $st = $pdo->prepare("DELETE FROM project_statuses WHERE workspace_id = ? AND id IN ($marks)");
      $st->execute(array_merge([$ws], $ids));
      flash_set('success', $st->rowCount() . ' statuses deleted permanently.');
    } catch (Throwable $e) { flash_set('error', 'Some selected project types could not be deleted because they are in use.'); }
    redirect('settings_project_statuses.php');
  }

  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0); $name = trim($_POST['name'] ?? '');
    if ($id <= 0 || $name === '') { flash_set('error', 'Valid type name required.'); redirect('settings_project_statuses.php'); }
    $st = $pdo->prepare('UPDATE project_statuses SET name=?, updated_at=? WHERE workspace_id=? AND id=?');
    $st->execute([$name, now(), $ws, $id]);
    flash_set('success', 'Project status updated.');
    redirect('settings_project_statuses.php');
  }

  if ($action === 'reorder') {
    $order = (array)($_POST['order'] ?? []);
    if (!$order) { flash_set('error', 'No project types supplied for reordering.'); redirect('settings_project_statuses.php'); }
    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare('UPDATE project_statuses SET sort_order=?, updated_at=? WHERE workspace_id=? AND id=?');
      $i = 1;
      foreach ($order as $idRaw) { $id=(int)$idRaw; if($id>0){ $st->execute([$i++, now(), $ws, $id]); } }
      $pdo->commit(); flash_set('success','Sort order updated.');
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash_set('error','Unable to update sort order.'); }
    redirect('settings_project_statuses.php');
  }

  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id <= 0 || $name === '') { flash_set('error', 'Valid project status name required.'); redirect('settings_project_statuses.php'); }
    $pdo->prepare('UPDATE project_statuses SET name=?, updated_at=? WHERE workspace_id=? AND id=?')->execute([$name, now(), $ws, $id]);
    flash_set('success', 'Project status updated.');
    redirect('settings_project_statuses.php');
  }

  if ($action === 'reorder') {
    $order = (array)($_POST['order'] ?? []);
    if (!$order) { flash_set('error', 'No project statuses selected for sorting.'); redirect('settings_project_statuses.php'); }
    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare('UPDATE project_statuses SET sort_order=?, updated_at=? WHERE workspace_id=? AND id=?');
      $i = 1;
      foreach ($order as $idRaw) { $id = (int)$idRaw; if ($id > 0) $st->execute([$i++, now(), $ws, $id]); }
      $pdo->commit();
      flash_set('success', 'Project status order updated.');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash_set('error', 'Unable to update project status sort order.');
    }
    redirect('settings_project_statuses.php');
  }

  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id <= 0 || $name === '') { flash_set('error', 'Valid project status name required.'); redirect('settings_project_statuses.php'); }
    $pdo->prepare('UPDATE project_statuses SET name=?, updated_at=? WHERE workspace_id=? AND id=?')->execute([$name, now(), $ws, $id]);
    flash_set('success', 'Project status updated.');
    redirect('settings_project_statuses.php');
  }

  if ($action === 'reorder') {
    $order = (array)($_POST['order'] ?? []);
    if (!$order) { flash_set('error', 'No project statuses selected for sorting.'); redirect('settings_project_statuses.php'); }
    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare('UPDATE project_statuses SET sort_order=?, updated_at=? WHERE workspace_id=? AND id=?');
      $i = 1;
      foreach ($order as $idRaw) { $id = (int)$idRaw; if ($id > 0) $st->execute([$i++, now(), $ws, $id]); }
      $pdo->commit();
      flash_set('success', 'Project status order updated.');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash_set('error', 'Unable to update project status sort order.');
    }
    redirect('settings_project_statuses.php');
  }

  $name = trim($_POST['name'] ?? '');
  if ($name === '') { flash_set('error', 'Name required.'); redirect('settings_project_statuses.php'); }
  $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM project_statuses WHERE workspace_id=$ws")->fetch()['n'];
  $pdo->prepare("INSERT INTO project_statuses (workspace_id,name,sort_order,created_at,updated_at) VALUES (?,?,?,?,?)")->execute([$ws, $name, $sort, now(), now()]);
  flash_set('success', 'Added.');
  redirect('settings_project_statuses.php');
}
$rows = $pdo->query("SELECT * FROM project_statuses WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();
?>

<style>
  .stype-shell{border:1px solid rgba(255,255,255,.1);border-radius:18px;background:linear-gradient(180deg,#111111,#090909);padding:1.15rem;box-shadow:0 24px 60px rgba(0,0,0,.4)}
  .stype-head{display:flex;justify-content:space-between;align-items:end;gap:.7rem;margin-bottom:1rem}
  .stype-title{margin:0;font-size:2rem;font-weight:700}
  .stype-sub{color:rgba(232,232,232,.68);font-size:.92rem}
  .stype-kpi{border:1px solid rgba(255,255,255,.1);border-radius:10px;background:rgba(255,255,255,.03);padding:.45rem .65rem;text-align:center;min-width:100px}
  .stype-kpi .n{font-weight:700;font-size:1.25rem;line-height:1}
  .stype-kpi .l{font-size:.78rem;color:rgba(232,232,232,.68)}
  .stype-grid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:.8rem}
  .stype-card{border:1px solid rgba(255,255,255,.1);border-radius:12px;background:rgba(255,255,255,.02);padding:.85rem}
  .stype-card-title{font-weight:600;margin-bottom:.55rem}
  .stype-table{overflow:hidden;border:1px solid rgba(255,255,255,.08);border-radius:10px;background:rgba(255,255,255,.015)}
  .stype-table table{width:100%;border-collapse:collapse}
  .stype-table th,.stype-table td{padding:.7rem .72rem;border-bottom:1px solid rgba(255,255,255,.07)}
  .stype-table th{background:rgba(255,255,255,.03);color:rgba(228,228,228,.82);font-size:.85rem;font-weight:600}
  .stype-table tr:last-child td{border-bottom:0}
  .drag-handle{cursor:grab}
  @media (max-width: 1100px){.stype-grid{grid-template-columns:1fr}}
</style>

<div class="stype-shell">
  <div class="stype-head">
    <div>
      <h1 class="stype-title">Project Statuses</h1>
      <div class="stype-sub">Define project workflow statuses used across boards and reports.</div>
    </div>
    <div class="stype-kpi"><div class="n"><?= count($rows) ?></div><div class="l">Total Statuses</div></div>
  </div>

  <div class="stype-grid">
    <div class="stype-card">
      <div class="stype-card-title">Add Status</div>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <div class="col-12"><input class="form-control" name="name" placeholder="e.g. Website Redesign" required></div>
        <div class="col-12"><button class="btn btn-yellow w-100">Add Project Status</button></div>
      </form>
    </div>

    <div class="stype-card">
      <div class="stype-card-title d-flex justify-content-between align-items-center">Current Statuses
        <span>
          <button type="submit" form="bulkDeleteTypes" class="btn btn-sm btn-outline-danger" onclick="return confirm('This will permanently delete selected project statuses. Are you sure?');">Delete Selected</button>
          <button type="submit" form="reorderTypes" class="btn btn-sm btn-outline-warning">Save Order</button>
        </span>
      </div>
      <div class="stype-table">
        <form method="post" id="bulkDeleteTypes"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="bulk_delete"></form>
        <form method="post" id="reorderTypes"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="reorder"></form>
        <table>
          <thead><tr><th style="width:56px;">Move</th><th style="width:44px;"><input type="checkbox" onclick="document.querySelectorAll('.status-check').forEach(cb=>cb.checked=this.checked)"></th><th>Name</th><th class="text-muted">Sort</th><th class="text-end">Actions</th></tr></thead>
          <tbody id="sortableRows">
            <?php foreach($rows as $r): ?><tr draggable="true" data-id="<?= (int)$r['id'] ?>"><td class="drag-handle">↕</td><td><input class="status-check" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" form="bulkDeleteTypes"></td><td><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input class="form-control form-control-sm" name="name" value="<?=h($r['name'])?>" required><button class="btn btn-sm btn-outline-secondary">Save</button></form></td><td class="text-muted"><?=h($r['sort_order'])?></td><td class="text-end"><form method="post" style="display:inline" onsubmit="return confirm('This will permanently delete this project status. Are you sure?');"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td></tr><?php endforeach; ?>
            <?php if(!$rows): ?><tr><td colspan="5" class="text-muted">No statuses yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
(function(){const tbody=document.getElementById('sortableRows'); if(!tbody) return; let drag=null;
function sync(){document.querySelectorAll('#reorderTypes input[name="order[]"]').forEach(el=>el.remove());tbody.querySelectorAll('tr[data-id]').forEach(tr=>{const i=document.createElement('input');i.type='hidden';i.name='order[]';i.value=tr.dataset.id;i.form=document.getElementById('reorderTypes');});}
tbody.querySelectorAll('tr[data-id]').forEach(tr=>{tr.addEventListener('dragstart',()=>{drag=tr;});tr.addEventListener('dragover',e=>e.preventDefault());tr.addEventListener('drop',e=>{e.preventDefault();if(!drag||drag===tr)return;const r=tr.getBoundingClientRect();tbody.insertBefore(drag,e.clientY>r.top+r.height/2?tr.nextSibling:tr);sync();});});sync();})();
</script>
<?php require_once __DIR__ . '/layout_end.php'; ?>
