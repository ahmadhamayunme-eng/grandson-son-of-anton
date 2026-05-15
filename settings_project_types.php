<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();

$role = auth_user()['role_name'] ?? '';
if (!in_array($role, ['CEO','Manager','Super Admin'], true)) {
  http_response_code(403);
  $pageTitle = 'Forbidden';
  $activeKey = 'settings';
  require_once __DIR__ . '/layout.php';
  echo '<div style="padding:48px 32px;text-align:center;color:var(--text-muted);"><h3 style="margin-bottom:12px;color:var(--text);">Forbidden</h3></div>';
  require_once __DIR__ . '/layout_end.php';
  exit;
}

$pdo = db();
$ws = auth_workspace_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post(); csrf_verify();
  $action = $_POST['action'] ?? 'add';

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { flash_set('error', 'Invalid project type selected.'); redirect('settings_project_types.php'); }
    try {
      $st = $pdo->prepare('DELETE FROM project_types WHERE workspace_id = ? AND id = ?');
      $st->execute([$ws, $id]);
      flash_set('success', $st->rowCount() ? 'Project type deleted permanently.' : 'Project type not found.');
    } catch (Throwable $e) { flash_set('error', 'Unable to delete this project type. It may still be in use.'); }
    redirect('settings_project_types.php');
  }
  if ($action === 'bulk_delete') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    if (!$ids) { flash_set('error', 'Select at least one project type to delete.'); redirect('settings_project_types.php'); }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    try {
      $st = $pdo->prepare("DELETE FROM project_types WHERE workspace_id = ? AND id IN ($marks)");
      $st->execute(array_merge([$ws], $ids));
      flash_set('success', $st->rowCount() . ' project type(s) deleted permanently.');
    } catch (Throwable $e) { flash_set('error', 'Some selected project types could not be deleted because they are in use.'); }
    redirect('settings_project_types.php');
  }
  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id <= 0 || $name === '') { flash_set('error', 'Valid type name required.'); redirect('settings_project_types.php'); }
    $pdo->prepare('UPDATE project_types SET name=?, updated_at=? WHERE workspace_id=? AND id=?')
        ->execute([$name, now(), $ws, $id]);
    flash_set('success', 'Project type updated.');
    redirect('settings_project_types.php');
  }
  if ($action === 'reorder') {
    $order = (array)($_POST['order'] ?? []);
    if (!$order) { flash_set('error', 'No project types selected for sorting.'); redirect('settings_project_types.php'); }
    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare('UPDATE project_types SET sort_order=?, updated_at=? WHERE workspace_id=? AND id=?');
      $i = 1;
      foreach ($order as $idRaw) { $id = (int)$idRaw; if ($id > 0) $st->execute([$i++, now(), $ws, $id]); }
      $pdo->commit();
      flash_set('success', 'Sort order updated.');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash_set('error', 'Unable to update sort order.');
    }
    redirect('settings_project_types.php');
  }

  $name = trim($_POST['name'] ?? '');
  if ($name === '') { flash_set('error', 'Name required.'); redirect('settings_project_types.php'); }
  $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM project_types WHERE workspace_id=$ws")->fetch()['n'];
  $pdo->prepare("INSERT INTO project_types (workspace_id,name,sort_order,created_at,updated_at) VALUES (?,?,?,?,?)")
      ->execute([$ws, $name, $sort, now(), now()]);
  flash_set('success', 'Project type added.');
  redirect('settings_project_types.php');
}

$rows = $pdo->query("SELECT * FROM project_types WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();

$pageTitle = 'Project Types';
$activeKey = 'settings';
$pageHeadExtra = <<<HTML
<style>
  .page-header { padding: 28px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; }
  .body-wrap { max-width: 1640px; margin: 24px auto; padding: 0 32px 60px; display: grid; grid-template-columns: 340px 1fr; gap: 16px; }
  .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 18px; }
  .panel h3 { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 14px; }
  .panel-actions { display: flex; gap: 6px; }
  .form-row { margin-bottom: 12px; display: flex; flex-direction: column; gap: 5px; }
  table.gen { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.gen thead th { text-align: left; padding: 10px 14px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-dim); background: var(--bg); border-bottom: 1px solid var(--border); }
  table.gen tbody tr { transition: background 0.12s; }
  table.gen tbody tr:hover { background: var(--surface-hover); }
  table.gen tbody td { padding: 10px 14px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
  table.gen tbody tr:last-child td { border-bottom: none; }
  .drag-handle { cursor: grab; color: var(--text-dim); user-select: none; font-size: 16px; }
  .inline-edit { display: flex; gap: 6px; align-items: center; }
  .inline-edit input { background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; padding: 6px 10px; font-size: 13px; color: var(--text); outline: none; flex: 1; }
  .inline-edit input:focus { border-color: var(--border-strong); }
  .btn-tiny { padding: 5px 11px; font-size: 11.5px; font-weight: 500; border-radius: 6px; transition: all 0.12s; border: 1px solid var(--border); display: inline-flex; align-items: center; gap: 5px; color: var(--text); text-decoration: none; background: var(--surface); cursor: pointer; }
  .btn-tiny:hover { background: var(--surface-2); border-color: var(--border-strong); }
  .btn-tiny.danger { color: #fca5a5; border-color: rgba(239,68,68,0.25); background: transparent; }
  .btn-tiny.danger:hover { background: var(--danger-soft); }
  .empty-state { padding: 28px 14px; text-align: center; color: var(--text-dim); font-style: italic; }
  @media (max-width: 1100px) { .body-wrap { grid-template-columns: 1fr; } }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html('settings.php', 'Settings') ?>
    <div>
      <div class="page-title">Project Types</div>
      <div class="page-sub">Maintain project type options used while creating projects.</div>
    </div>
  </div>
</div>

<div class="body-wrap">
  <div class="panel">
    <h3>Add Project Type</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <div class="form-row"><input class="input" name="name" placeholder="e.g. Monthly SEO" required></div>
      <button class="btn btn-primary btn-block save-flash" type="submit">Add Project Type</button>
    </form>
  </div>

  <div class="panel" style="padding:0;overflow:hidden;">
    <div style="padding:14px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);">
      <div style="font-size:14px;font-weight:600;color:var(--text);">Current Project Types (<?= count($rows) ?>)</div>
      <div class="panel-actions">
        <button type="submit" form="reorderForm" class="btn-tiny">Save Order</button>
        <button type="submit" form="bulkDelForm" class="btn-tiny danger" onclick="return confirm('Delete selected types permanently?');">Delete Selected</button>
      </div>
    </div>
    <form method="post" id="bulkDelForm"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="bulk_delete"></form>
    <form method="post" id="reorderForm"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="reorder"></form>
    <table class="gen">
      <thead><tr><th style="width:48px;">Move</th><th style="width:44px;text-align:center;"><input type="checkbox" onclick="document.querySelectorAll('.type-check').forEach(cb => cb.checked = this.checked)"></th><th>Name</th><th>Sort</th><th style="text-align:right;width:140px;">Actions</th></tr></thead>
      <tbody id="sortableRows">
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="empty-state">No project types yet.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr draggable="true" data-id="<?= (int)$r['id'] ?>">
          <td><span class="drag-handle">⠿</span></td>
          <td style="text-align:center;"><input class="type-check" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" form="bulkDelForm"></td>
          <td>
            <form method="post" class="inline-edit">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input name="name" value="<?= h($r['name']) ?>" required>
              <button class="btn-tiny" type="submit">Save</button>
            </form>
          </td>
          <td style="color:var(--text-muted);font-family:'JetBrains Mono',ui-monospace,monospace;font-size:12px;"><?= (int)$r['sort_order'] ?></td>
          <td style="text-align:right;">
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this project type permanently?');">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn-tiny danger">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  const tbody = document.getElementById('sortableRows');
  if (!tbody) return;
  let drag = null;
  function sync() {
    document.querySelectorAll('#reorderForm input[name="order[]"]').forEach(el => el.remove());
    tbody.querySelectorAll('tr[data-id]').forEach(tr => {
      const i = document.createElement('input');
      i.type = 'hidden';
      i.name = 'order[]';
      i.value = tr.dataset.id;
      document.getElementById('reorderForm').appendChild(i);
    });
  }
  tbody.querySelectorAll('tr[data-id]').forEach(tr => {
    tr.addEventListener('dragstart', () => { drag = tr; });
    tr.addEventListener('dragover', e => e.preventDefault());
    tr.addEventListener('drop', e => {
      e.preventDefault();
      if (!drag || drag === tr) return;
      const r = tr.getBoundingClientRect();
      tbody.insertBefore(drag, e.clientY > r.top + r.height / 2 ? tr.nextSibling : tr);
      sync();
    });
  });
  sync();
})();
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
