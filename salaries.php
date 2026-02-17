<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/activity.php';

auth_require_perm('finance.view');
$pdo = db();
$ws  = auth_workspace_id();
$u   = auth_user();

$action = $_POST['action'] ?? null;
$id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($action === 'create') {
  $name   = trim($_POST['name'] ?? '');
  $amount = (float)($_POST['amount'] ?? 0);
  $paidOn = $_POST['paid_on'] ?? date('Y-m-d');
  $note   = trim($_POST['note'] ?? '');

  if ($name === '') {
    flash_set('error', 'Name is required');
    redirect(basename(__FILE__));
  }

  $pdo->prepare("
    INSERT INTO finance_salaries (workspace_id, name, amount, paid_on, note, created_by, created_at)
    VALUES (?,?,?,?,?,?,NOW())
  ")->execute([
    $ws,
    $name,
    $amount,
    $paidOn,
    $note !== '' ? $note : null,
    (int)$u['id'],
  ]);

  activity_log('finance_salary', (int)$pdo->lastInsertId(), 'create', 'Salary record added');
  flash_set('success', 'Saved');
  redirect(basename(__FILE__));
}

if ($action === 'delete' && $id > 0) {
  $pdo->prepare("DELETE FROM finance_salaries WHERE id=? AND workspace_id=?")->execute([$id, $ws]);
  activity_log('finance_salary', $id, 'delete', 'Salary record deleted');
  flash_set('success', 'Deleted');
  redirect(basename(__FILE__));
}

$rows = $pdo->prepare("
  SELECT *
  FROM finance_salaries
  WHERE workspace_id=?
  ORDER BY paid_on DESC, id DESC
  LIMIT 300
");
$rows->execute([$ws]);
$rows = $rows->fetchAll();
?>

<h2 class="mb-3">Salaries</h2>

<div class="card p-3 mb-4">
  <form method="post" class="row g-2">
    <input type="hidden" name="action" value="create">

    <div class="col-md-4">
      <label class="form-label">Name</label>
      <input class="form-control" name="name" placeholder="Dev A / Designer / PM" required>
    </div>

    <div class="col-md-2">
      <label class="form-label">Amount</label>
      <input class="form-control" name="amount" type="number" step="0.01" required>
    </div>

    <div class="col-md-3">
      <label class="form-label">Paid On</label>
      <input class="form-control" name="paid_on" type="date" value="<?= date('Y-m-d') ?>" required>
    </div>

    <div class="col-md-6">
      <label class="form-label">Note</label>
      <input class="form-control" name="note" placeholder="Optional">
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
          <th>Paid On</th>
          <th>Name</th>
          <th>Amount</th>
          <th>Note</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= h($r['paid_on'] ?? '-') ?></td>
          <td><?= h($r['name'] ?? '-') ?></td>
          <td><?= number_format((float)($r['amount'] ?? 0), 2) ?></td>
          <td><?= h($r['note'] ?? '') ?></td>
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
