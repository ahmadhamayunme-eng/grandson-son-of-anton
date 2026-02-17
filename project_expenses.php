<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
echo "START\n";
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/activity.php';
auth_require_perm('finance.view');
$pdo = db();
$ws = auth_workspace_id();
$u = auth_user();

$projects = $pdo->prepare("SELECT p.id,p.name,c.name AS client_name FROM projects p JOIN clients c ON c.id=p.client_id WHERE p.workspace_id=? ORDER BY p.id DESC");
$projects->execute([$ws]);
$projects = $projects->fetchAll();

$action = $_POST['action'] ?? null;
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($action === 'create') {
  $project_id = ($_POST['project_id'] ?? '') !== '' ? (int)$_POST['project_id'] : null;
  $category = trim($_POST['category'] ?? 'General');
  $amount = (float)($_POST['amount'] ?? 0);
  $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
  $vendor = trim($_POST['vendor'] ?? '');
  $notes = trim($_POST['notes'] ?? '');

  $pdo->prepare("INSERT INTO finance_expenses (workspace_id,project_id,category,amount,expense_date,vendor,notes,created_by,created_at)
    VALUES (?,?,?,?,?,?,?,?,NOW())")
    ->execute([$ws,$project_id,$category,$amount,$expense_date,$vendor?:null,$notes?:null,(int)$u['id']]);
  activity_log('finance_expense', (int)$pdo->lastInsertId(), 'create', 'Expense added');
  flash_set('success','Expense saved');
  redirect(basename(__FILE__));
}

if ($action === 'delete' && $id>0) {
  $pdo->prepare("DELETE FROM finance_expenses WHERE id=? AND workspace_id=?")->execute([$id,$ws]);
  activity_log('finance_expense', $id, 'delete', 'Expense deleted');
  flash_set('success','Deleted');
  redirect(basename(__FILE__));
}

$rows = $pdo->prepare("SELECT fe.*, p.name AS project_name, c.name AS client_name
  FROM finance_expenses fe
  LEFT JOIN projects p ON p.id=fe.project_id
  LEFT JOIN clients c ON c.id=p.client_id
  WHERE fe.workspace_id=?
  ORDER BY fe.expense_date DESC, fe.id DESC LIMIT 300");
$rows->execute([$ws]);
$rows = $rows->fetchAll();
?>

<h2 class="mb-3">Project Expenses</h2>

<div class="card p-3 mb-4">
  <form method="post" class="row g-2">
    <input type="hidden" name="action" value="create">

    <div class="col-md-4">
      <label class="form-label">Project</label>
      <select class="form-select" name="project_id">
        <option value="">-- optional --</option>
        <?php foreach($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= h($p['client_name'].' — '.$p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label">Category</label>
      <input class="form-control" name="category" placeholder="Hosting, Ads, Tools" required>
    </div>

    <div class="col-md-2">
      <label class="form-label">Amount</label>
      <input class="form-control" name="amount" type="number" step="0.01" required>
    </div>

    <div class="col-md-3">
      <label class="form-label">Date</label>
      <input class="form-control" name="expense_date" type="date" value="<?= date('Y-m-d') ?>" required>
    </div>

    <div class="col-md-4">
      <label class="form-label">Vendor</label>
      <input class="form-control" name="vendor" placeholder="Optional">
    </div>

    <div class="col-md-5">
      <label class="form-label">Notes</label>
      <input class="form-control" name="notes" placeholder="Optional">
    </div>

    <div class="col-md-3 d-flex align-items-end">
      <button class="btn btn-light w-100">Add Expense</button>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Date</th><th>Amount</th><th>Category</th><th>Project</th><th>Vendor</th><th>Notes</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= h($r['expense_date']) ?></td>
          <td><?= number_format((float)$r['amount'],2) ?></td>
          <td><?= h($r['category']) ?></td>
          <td><?= h(($r['client_name'] ? $r['client_name'].' — ' : '').($r['project_name'] ?? '-')) ?></td>
          <td><?= h($r['vendor'] ?? '-') ?></td>
          <td><?= h($r['notes'] ?? '') ?></td>
          <td class="text-end">
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this expense?');">
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
