<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();
$clients=(int)$pdo->query("SELECT COUNT(*) c FROM clients WHERE workspace_id=$ws")->fetch()['c'];
$projects=(int)$pdo->query("SELECT COUNT(*) c FROM projects WHERE workspace_id=$ws")->fetch()['c'];
$tasks=(int)$pdo->query("SELECT COUNT(*) c FROM tasks WHERE workspace_id=$ws AND status IN ('To Do','In Progress','Completed (Needs CTO Review)','Approved (Ready to Submit)')")->fetch()['c'];
?>
<h2 class="mb-3">Dashboard</h2>
<div class="row g-3">
  <div class="col-md-4"><div class="card p-3"><div class="text-muted">Clients</div><div class="fs-3 fw-semibold"><?=h($clients)?></div></div></div>
  <div class="col-md-4"><div class="card p-3"><div class="text-muted">Projects</div><div class="fs-3 fw-semibold"><?=h($projects)?></div></div></div>
  <div class="col-md-4"><div class="card p-3"><div class="text-muted">Open Tasks</div><div class="fs-3 fw-semibold"><?=h($tasks)?></div></div></div>
</div>
<div class="mt-4 card p-3">
  <div class="d-flex justify-content-between align-items-center">
    <div><div class="fw-semibold">Start here</div><div class="text-muted small">Create a client → create a project → add phases → add tasks.</div></div>
    <div class="d-flex gap-2">
      <a class="btn btn-yellow" href="clients.php">Clients</a>
      <a class="btn btn-outline-light" href="my_tasks.php">My Tasks</a>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
