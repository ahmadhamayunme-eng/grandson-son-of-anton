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

$rows = $pdo->prepare("SELECT * FROM finance_salaries WHERE workspace_id=? ORDER BY paid_on DESC, id DESC LIMIT 300");
$rows->execute([$ws]);
$rows = $rows->fetchAll();

$action = $_POST['action'] ?? null;
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($action === 'create') {
  $user_id = ($_POST['user_id'] ?? '') !== '' ? (int)$_POST['user_id'] : null;
  $salary_month = preg_replace('/[^0-9\-]/','', $_POST['salary_month'] ?? date('Y-m'));
  $amount = (float)($_POST['amount'] ?? 0);
  $notes = trim($_POST['notes'] ?? '');

  $pdo->prepare("INSERT INTO finance_salaries (workspace_id,user_id,salary_month,amount,notes,created_by,created_at)
    VALUES (?,?,?,?,?,?,NOW())")
    ->execute([$ws,$user_id,$salary_month,$amount,$notes?:null,(int)$u['id']]);
  activity_log('finance_salary', (int)$pdo->lastInsertId(), 'create', 'Salary record added');
  flash_set('success','Saved');
  redirect(basename(__FILE__));
}

if ($action === 'delete' && $id>0) {
  $pdo->prepare("DELETE FROM finance_salaries WHERE id=? AND workspace_id=?")->execute([$id,$ws]);
  activity_log('finance_salary', $id, 'delete', 'Salary record deleted');
  flash_set('success','Deleted');
  redirect(basename(__FILE__));
}

$rows = $pdo->prepare("SELECT fs.*, u.name AS user_name FROM finance_salaries fs LEFT JOIN users u ON u.id=fs.user_id WHERE fs.workspace_id=? ORDER BY fs.salary_month DESC, fs.id DESC LIMIT 300");
$rows->execute([$ws]);
$rows = $rows->fetchAll();
?>

<h2 class="mb-3">Salaries</h2>

<div class="card p-3 mb-4">
  <form method="post" class="row g-2">
    <input type="hidden" name="action" value="create">

    <div class="col-md-4">
      <label class="form-label">Team Member</label>
      <select class="form-select" name="user_id">
        <option value="">-- optional --</option>
        <?php foreach($users as $uu): ?>
          <option value="<?= (int)$uu['id'] ?>"><?= h($uu['name'].' ('.$uu['email'].')') ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-2">
      <label class="form-label">Month (YYYY-MM)</label>
      <input class="form-control" name="salary_month" value="<?= date('Y-m') ?>" required>
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
      <button class="btn btn-light w-100">Add Salary</button>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Month</th><th>Amount</th><th>User</th><th>Notes</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= h($r['salary_month']) ?></td>
          <td><?= number_format((float)$r['amount'],2) ?></td>
          <td><?= h($r['user_name'] ?? '-') ?></td>
          <td><?= h($r['notes'] ?? '') ?></td>
          <td class="text-end">
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this salary record?');">
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
