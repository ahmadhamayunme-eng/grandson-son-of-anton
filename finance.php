<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/finance.php';
auth_require_perm('finance.view');
$ws = auth_workspace_id();
$tot = finance_totals($ws);
?>
<h2 class="mb-3">Finance Dashboard</h2>

<div class="row g-3 mb-4">
  <div class="col-md-3"><div class="card p-3"><div class="text-muted">Payments</div><div class="h4 mb-0"><?=number_format($tot['payments'],2)?></div></div></div>
  <div class="col-md-3"><div class="card p-3"><div class="text-muted">Expenses</div><div class="h4 mb-0"><?=number_format($tot['expenses'],2)?></div></div></div>
  <div class="col-md-3"><div class="card p-3"><div class="text-muted">Salaries</div><div class="h4 mb-0"><?=number_format($tot['salaries'],2)?></div></div></div>
  <div class="col-md-3"><div class="card p-3"><div class="text-muted">Overhead</div><div class="h4 mb-0"><?=number_format($tot['overheads'],2)?></div></div></div>
</div>

<div class="card p-3 mb-4">
  <div class="d-flex justify-content-between align-items-center">
    <div>
      <div class="text-muted">Net Profit</div>
      <div class="h3 mb-0"><?=number_format($tot['profit'],2)?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-light" href="payments_received.php">Payments</a>
      <a class="btn btn-outline-light" href="project_expenses.php">Expenses</a>
      <a class="btn btn-outline-light" href="salaries.php">Salaries</a>
      <a class="btn btn-outline-light" href="overhead_cost.php">Overhead</a>
    </div>
  </div>
</div>

<div class="card p-3">
  <div class="fw-semibold mb-2">Recent activity</div>
  <div class="text-muted">Use the module links above to add and review transactions.</div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
