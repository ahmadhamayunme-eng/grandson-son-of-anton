<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/activity.php';
require_once __DIR__ . '/lib/finance.php';
auth_require_perm('finance.view');
$pdo = db();
finance_ensure_schema($pdo);
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

$profitRows = $pdo->prepare("SELECT p.id, p.name AS project_name, c.name AS client_name,
  COALESCE(SUM(DISTINCT fr.received_amount),0) AS revenue_received,
  COALESCE(SUM(DISTINCT fe.amount),0) AS expense_total
  FROM projects p
  JOIN clients c ON c.id=p.client_id
  LEFT JOIN finance_receivables fr ON fr.project_id=p.id AND fr.workspace_id=p.workspace_id
  LEFT JOIN finance_expenses fe ON fe.project_id=p.id AND fe.workspace_id=p.workspace_id
  WHERE p.workspace_id=?
  GROUP BY p.id, p.name, c.name
  ORDER BY p.id DESC LIMIT 120");
$profitRows->execute([$ws]);
$profitRows = $profitRows->fetchAll();

?>
<style>
  .exp-shell{border:1px solid rgba(255,255,255,.1);border-radius:16px;background:linear-gradient(180deg,#101010,#070707);overflow:hidden}
  .exp-head{padding:1rem 1.1rem;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap}
  .exp-title{font-size:2.1rem;font-weight:600;margin:0}
  .exp-controls{display:flex;gap:.45rem;flex-wrap:wrap}
  .exp-pill{padding:.45rem .7rem;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04);color:#fff}
  .exp-body{padding:1rem 1.1rem;color:#fff}
  .exp-table{border:1px solid rgba(255,255,255,.1);border-radius:12px;overflow:hidden;background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.015))}
  .exp-table table{width:100%;border-collapse:collapse}
  .exp-table th,.exp-table td{padding:.75rem .8rem;border-bottom:1px solid rgba(255,255,255,.08)}
  .exp-table th{background:rgba(255,255,255,.03);color:#fff;font-weight:600}
  .amt{color:#ff8f70;font-weight:600}
  .form-label{color:#fff !important}
  .text-muted{color:#fff !important;opacity:.9}
  details summary{color:#fff}

</style>

<div class="exp-shell">
  <div class="exp-head">
    <h2 class="exp-title">Project Expenses</h2>
    <div class="exp-controls">
      <span class="exp-pill">SpeedX ▾</span><span class="exp-pill"><?=date('F Y')?> ▾</span><span class="exp-pill">◉</span>
      <a class="btn btn-outline-light btn-sm" href="finance.php">Show All</a>
      <a class="btn btn-yellow btn-sm" href="client_reports.php">Export</a>
    </div>
  </div>

  <div class="exp-body">
    <details class="mb-3"><summary class="btn btn-outline-light btn-sm">Add Expense</summary>
      <div class="card p-3 mt-2">
        <form method="post" class="row g-2">
          <input type="hidden" name="action" value="create">
          <div class="col-md-4"><label class="form-label">Project</label><select class="form-select" name="project_id"><option value="">-- optional --</option><?php foreach($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['client_name'].' — '.$p['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-3"><label class="form-label">Category</label><input class="form-control" name="category" required></div>
          <div class="col-md-2"><label class="form-label">Amount</label><input class="form-control" name="amount" type="number" step="0.01" required></div>
          <div class="col-md-3"><label class="form-label">Date</label><input class="form-control" name="expense_date" type="date" value="<?= date('Y-m-d') ?>" required></div>
          <div class="col-md-4"><label class="form-label">Vendor</label><input class="form-control" name="vendor"></div>
          <div class="col-md-5"><label class="form-label">Notes</label><input class="form-control" name="notes"></div>
          <div class="col-md-3 d-flex align-items-end"><button class="btn btn-yellow w-100">Save</button></div>
        </form>
      </div>
    </details>

    <div class="exp-table">
      <table>
        <thead><tr><th>Category</th><th>Description</th><th>Amount</th><th>Date</th><th>Assigned To</th><th></th></tr></thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?=h($r['category'])?></td>
            <td><?=h($r['notes'] ?: ($r['vendor'] ?: 'Project expense'))?></td>
            <td class="amt">$<?=number_format((float)$r['amount'],2)?></td>
            <td><?=h(date('M d', strtotime((string)$r['expense_date'])))?></td>
            <td><?=h(($r['client_name'] ? $r['client_name'].' — ' : '').($r['project_name'] ?? '-'))?></td>
            <td class="text-end"><form method="post" style="display:inline" onsubmit="return confirm('Delete this expense?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$rows): ?><tr><td colspan="6" class="text-muted">No project expenses yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="exp-table mt-3">
      <table>
        <thead><tr><th>Project</th><th>Revenue Received</th><th>Expenses</th><th>Profit</th></tr></thead>
        <tbody>
        <?php foreach($profitRows as $pr): $profitVal=((float)$pr['revenue_received']-(float)$pr['expense_total']); ?>
          <tr>
            <td><?=h($pr['client_name'].' — '.$pr['project_name'])?></td>
            <td>$<?=number_format((float)$pr['revenue_received'],2)?></td>
            <td>$<?=number_format((float)$pr['expense_total'],2)?></td>
            <td class="<?= $profitVal < 0 ? 'amt' : '' ?>">$<?=number_format($profitVal,2)?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$profitRows): ?><tr><td colspan="4" class="text-muted">No project revenue/expense comparisons yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
