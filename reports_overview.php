<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();

$tot_tasks = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE workspace_id=$ws")->fetchColumn();
$open_tasks = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE workspace_id=$ws AND status NOT IN ('Approved (Ready to Submit)','Submitted to Client')")->fetchColumn();
$needs_cto = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE workspace_id=$ws AND status='Completed (Needs CTO Review)'")->fetchColumn();
$projects = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE workspace_id=$ws")->fetchColumn();
?>
<h2 class="mb-3">Reports Overview</h2>
<div class="row g-3 mb-4">
  <div class="col-md-3"><div class="card p-3"><div class="text-muted">Projects</div><div class="h4 mb-0"><?= $projects ?></div></div></div>
  <div class="col-md-3"><div class="card p-3"><div class="text-muted">Total Tasks</div><div class="h4 mb-0"><?= $tot_tasks ?></div></div></div>
  <div class="col-md-3"><div class="card p-3"><div class="text-muted">Open Tasks</div><div class="h4 mb-0"><?= $open_tasks ?></div></div></div>
  <div class="col-md-3"><div class="card p-3"><div class="text-muted">Needs CTO Review</div><div class="h4 mb-0"><?= $needs_cto ?></div></div></div>
</div>

<div class="card p-3">
  <div class="list-group list-group-flush">
    <a class="list-group-item list-group-item-dark" href="review_completed_tasks.php">Review Completed Tasks (CTO)</a>
    <a class="list-group-item list-group-item-dark" href="developer_performance.php">Developer Performance</a>
    <a class="list-group-item list-group-item-dark" href="client_reports.php">Client Reports</a>
    <a class="list-group-item list-group-item-dark" href="task_activity_log.php">Task Activity Log</a>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
