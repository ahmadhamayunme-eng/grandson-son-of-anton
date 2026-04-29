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
    if ($id <= 0) { flash_set('error', 'Invalid project type selected.'); redirect('settings_task_statuses.php'); }
    try {
      $st = $pdo->prepare('DELETE FROM task_statuses WHERE workspace_id = ? AND id = ?');
      $st->execute([$ws, $id]);
      flash_set('success', $st->rowCount() ? 'Task status deleted permanently.' : 'Task status not found.');
    } catch (Throwable $e) { flash_set('error', 'Unable to delete this project type. It may still be used by projects.'); }
    redirect('settings_task_statuses.php');
  }

  if ($action === 'bulk_delete') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    if (!$ids) { flash_set('error', 'Select at least one project type to delete.'); redirect('settings_task_statuses.php'); }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    try {
      $st = $pdo->prepare("DELETE FROM task_statuses WHERE workspace_id = ? AND id IN ($marks)");
      $st->execute(array_merge([$ws], $ids));
      flash_set('success', $st->rowCount() . ' task statuses deleted permanently.');
    } catch (Throwable $e) { flash_set('error', 'Some selected project types could not be deleted because they are in use.'); }
    redirect('settings_task_statuses.php');
  }

  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0); $name = trim($_POST['name'] ?? '');
    if ($id <= 0 || $name === '') { flash_set('error', 'Valid type name required.'); redirect('settings_task_statuses.php'); }
    $st = $pdo->prepare('UPDATE task_statuses SET name=?, updated_at=? WHERE workspace_id=? AND id=?');
    $st->execute([$name, now(), $ws, $id]);
    flash_set('success', 'Task status updated.');
    redirect('settings_task_statuses.php');
  }

  if ($action === 'reorder') {
    $order = (array)($_POST['order'] ?? []);
    if (!$order) { flash_set('error', 'No project types supplied for reordering.'); redirect('settings_task_statuses.php'); }
    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare('UPDATE task_statuses SET sort_order=?, updated_at=? WHERE workspace_id=? AND id=?');
      $i = 1;
      foreach ($order as $idRaw) { $id=(int)$idRaw; if($id>0){ $st->execute([$i++, now(), $ws, $id]); } }
      $pdo->commit(); flash_set('success','Sort order updated.');
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash_set('error','Unable to update sort order.'); }
    redirect('settings_task_statuses.php');
  }

  $name = trim($_POST['name'] ?? '');
  if ($name === '') { flash_set('error', 'Name required.'); redirect('settings_task_statuses.php'); }
  $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM task_statuses WHERE workspace_id=$ws")->fetch()['n'];
  $pdo->prepare("INSERT INTO task_statuses (workspace_id,name,sort_order,created_at,updated_at) VALUES (?,?,?,?,?)")->execute([$ws, $name, $sort, now(), now()]);
  flash_set('success', 'Added.');
  redirect('settings_task_statuses.php');
}
$rows = $pdo->query("SELECT * FROM task_statuses WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();
?>
<div class="stype-shell">
  <div class="stype-head"><div><h1 class="stype-title">Task statuses</h1></div><div class="stype-kpi"><div class="n"><?= count($rows) ?></div></div></div>
  <div class="stype-grid">
    <div class="stype-card"><div class="stype-card-title">Add Status</div><form method="post" class="row g-2"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><div class="col-12"><input class="form-control" name="name" required></div><div class="col-12"><button class="btn btn-yellow w-100">Add</button></div></form></div>
    <div class="stype-card">
      <div class="stype-card-title d-flex justify-content-between align-items-center">Current Statuses <span><button type="submit" form="bulkDeleteTypes" class="btn btn-sm btn-outline-danger">Delete Selected</button> <button type="submit" form="reorderTypes" class="btn btn-sm btn-outline-warning">Save Order</button></span></div>
      <div class="stype-table">
        <form method="post" id="bulkDeleteTypes"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="bulk_delete"></form>
        <form method="post" id="reorderTypes"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="reorder"></form>
        <table>
          <thead><tr><th>Move</th><th></th><th>Name</th><th>Sort</th><th class="text-end">Actions</th></tr></thead>
          <tbody id="sortable-types">
            <?php foreach($rows as $r): ?><tr draggable="true" data-id="<?= (int)$r['id'] ?>"><td style="cursor:grab">↕</td><td><input class="status-check" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" form="bulkDeleteTypes"></td><td><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input class="form-control form-control-sm" name="name" value="<?=h($r['name'])?>"><button class="btn btn-sm btn-outline-secondary">Save</button></form></td><td><?=h($r['sort_order'])?></td><td class="text-end"><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td></tr><?php endforeach; ?>
            <?php if(!$rows): ?><tr><td colspan="5">No types yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
(function(){const tb=document.getElementById('sortable-types');if(!tb)return;let drag=null;function sync(){document.querySelectorAll('#reorderTypes input[name="order[]"]').forEach(el=>el.remove());tb.querySelectorAll('tr[data-id]').forEach(tr=>{const i=document.createElement('input');i.type='hidden';i.name='order[]';i.value=tr.dataset.id;i.form=document.getElementById('reorderTypes');});}
tb.querySelectorAll('tr[data-id]').forEach(tr=>{tr.addEventListener('dragstart',()=>{drag=tr;});tr.addEventListener('dragover',e=>e.preventDefault());tr.addEventListener('drop',e=>{e.preventDefault();if(!drag||drag===tr)return;const rect=tr.getBoundingClientRect();tb.insertBefore(drag, e.clientY > rect.top + rect.height/2 ? tr.nextSibling : tr);sync();});});sync();})();
</script>
<?php require_once __DIR__ . '/layout_end.php'; ?>
