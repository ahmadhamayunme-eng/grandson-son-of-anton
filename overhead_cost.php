<?php
 require_once __DIR__ . '/layout.php';
 require_once __DIR__ . '/lib/activity.php';
 auth_require_perm('finance.view');
 $pdo = db();
 $ws = auth_workspace_id();
 $u = auth_user();
 
 $action = $_POST['action'] ?? null;
 $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
 
 if ($action === 'create') {
   $overhead_month = preg_replace('/[^0-9\-]/','', $_POST['overhead_month'] ?? date('Y-m'));
   $category = trim($_POST['category'] ?? 'General');
   $amount = (float)($_POST['amount'] ?? 0);
   $notes = trim($_POST['notes'] ?? '');
 
   $pdo->prepare("INSERT INTO finance_overheads (workspace_id,overhead_month,category,amount,notes,created_by,created_at)
     VALUES (?,?,?,?,?,?,NOW())")
     ->execute([$ws,$overhead_month,$category,$amount,$notes?:null,(int)$u['id']]);
   activity_log('finance_overhead', (int)$pdo->lastInsertId(), 'create', 'Overhead added');
   flash_set('success','Saved');
   redirect(basename(__FILE__));
 }
 
 if ($action === 'delete' && $id>0) {

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/activity.php';
auth_require_perm('finance.view');
$pdo = db();
$ws = auth_workspace_id();
$u = auth_user();

$action = $_POST['action'] ?? null;
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($action === 'create') {
  $overhead_month = preg_replace('/[^0-9\-]/','', $_POST['overhead_month'] ?? date('Y-m'));
  $category = trim($_POST['category'] ?? 'General');
  $amount = (float)($_POST['amount'] ?? 0);
  $notes = trim($_POST['notes'] ?? '');

  $pdo->prepare("INSERT INTO finance_overheads (workspace_id,overhead_month,category,amount,notes,created_by,created_at)
    VALUES (?,?,?,?,?,?,NOW())")
    ->execute([$ws,$overhead_month,$category,$amount,$notes?:null,(int)$u['id']]);
  activity_log('finance_overhead', (int)$pdo->lastInsertId(), 'create', 'Overhead added');
  flash_set('success','Saved');
  redirect(basename(__FILE__));
}

if ($action === 'delete' && $id>0) {
  $pdo->prepare("DELETE FROM finance_overheads WHERE id=? AND workspace_id=?")->execute([$id,$ws]);
  activity_log('finance_overhead', $id, 'delete', 'Overhead deleted');
  flash_set('success','Deleted');
  redirect(basename(__FILE__));
}

$rows = $pdo->prepare("SELECT * FROM finance_overheads WHERE workspace_id=? ORDER BY overhead_month DESC, id DESC LIMIT 300");
$rows->execute([$ws]);
$rows = $rows->fetchAll();
?>

<h2 class="mb-3">Overhead Costs</h2>

<div class="card p-3 mb-4">
  <form method="post" class="row g-2">
    <input type="hidden" name="action" value="create">

    <div class="col-md-2">
      <label class="form-label">Month (YYYY-MM)</label>
      <input class="form-control" name="overhead_month" value="<?= date('Y-m') ?>" required>
    </div>

    <div class="col-md-4">
      <label class="form-label">Category</label>
      <input class="form-control" name="category" placeholder="Office, Internet, Tools" required>
    </div>

    <div class="col-md-2">
      <label class="form-label">Amount</label>
      <input class="form-control" name="amount" type="number" step="0.01" required>
    </div>

    <div class="col-md-4">
      <label class="form-label">Notes</label>
      <input class="form-control" name="notes" placeholder="Optional">
    </div>

    <div class="col-md-3 d-flex align-items-end">
      <button class="btn btn-light w-100">Add Overhead</button>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Month</th><th>Category</th><th>Amount</th><th>Notes</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= h($r['overhead_month']) ?></td>
          <td><?= h($r['category']) ?></td>
          <td><?= number_format((float)$r['amount'],2) ?></td>
          <td><?= h($r['notes'] ?? '') ?></td>
          <td class="text-end">
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this overhead record?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
