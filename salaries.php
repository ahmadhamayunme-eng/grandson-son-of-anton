<?php
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

  $pdo->prepare("INSERT INTO finance_salaries (workspace_id, name, amount, paid_on, note, created_by, created_at) VALUES (?,?,?,?,?,?,NOW())")
    ->execute([$ws,$name,$amount,$paidOn,$note !== '' ? $note : null,(int)$u['id']]);

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

$rows = $pdo->prepare("SELECT * FROM finance_salaries WHERE workspace_id=? ORDER BY paid_on DESC, id DESC LIMIT 300");
$rows->execute([$ws]);
$rows = $rows->fetchAll();
?>
<style>
  .sal-shell{border:1px solid rgba(255,255,255,.1);border-radius:16px;background:linear-gradient(180deg,#101010,#070707);overflow:hidden}
  .sal-head{padding:1rem 1.1rem;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap}
  .sal-title{font-size:2.1rem;font-weight:600;margin:0}
  .sal-controls{display:flex;gap:.45rem;flex-wrap:wrap}
  .sal-pill{padding:.45rem .7rem;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04);color:#ececec}
  .sal-body{padding:1rem 1.1rem}
  .sal-table{border:1px solid rgba(255,255,255,.1);border-radius:12px;overflow:hidden;background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.015))}
  .sal-table table{width:100%;border-collapse:collapse}
  .sal-table th,.sal-table td{padding:.75rem .8rem;border-bottom:1px solid rgba(255,255,255,.08)}
  .sal-table th{background:rgba(255,255,255,.03);color:rgba(226,226,226,.82);font-weight:600}
</style>

<div class="sal-shell">
  <div class="sal-head">
    <h2 class="sal-title">Salaries</h2>
    <div class="sal-controls">
      <span class="sal-pill">Filter ▾</span><span class="sal-pill"><?=date('Y')?> ▾</span>
      <a class="btn btn-outline-light btn-sm" href="finance.php">Show All</a>
      <a class="btn btn-yellow btn-sm" href="client_reports.php">Export</a>
    </div>
  </div>
  <div class="sal-body">
    <details class="mb-3"><summary class="btn btn-outline-light btn-sm">Add Salary</summary>
      <div class="card p-3 mt-2">
        <form method="post" class="row g-2">
          <input type="hidden" name="action" value="create">
          <div class="col-md-4"><label class="form-label">Name</label><input class="form-control" name="name" required></div>
          <div class="col-md-2"><label class="form-label">Amount</label><input class="form-control" name="amount" type="number" step="0.01" required></div>
          <div class="col-md-3"><label class="form-label">Paid On</label><input class="form-control" name="paid_on" type="date" value="<?= date('Y-m-d') ?>" required></div>
          <div class="col-md-6"><label class="form-label">Note</label><input class="form-control" name="note"></div>
          <div class="col-md-3 d-flex align-items-end"><button class="btn btn-yellow w-100">Save</button></div>
        </form>
      </div>
    </details>

    <div class="sal-table">
      <table>
        <thead><tr><th>Paid On</th><th>Name</th><th>Amount</th><th>Note</th><th></th></tr></thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?= h($r['paid_on'] ?? '-') ?></td>
            <td><?= h($r['name'] ?? '-') ?></td>
            <td style="color:#f6d469;font-weight:600">$<?= number_format((float)($r['amount'] ?? 0), 2) ?></td>
            <td><?= h($r['note'] ?? '') ?></td>
            <td class="text-end"><form method="post" style="display:inline" onsubmit="return confirm('Delete this salary record?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$rows): ?><tr><td colspan="5" class="text-muted">No salary records yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
