<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();
$projects=$pdo->query("SELECT p.*, c.name AS client_name, pt.name AS type_name, ps.name AS status_name
  FROM projects p
  JOIN clients c ON c.id=p.client_id
  JOIN project_types pt ON pt.id=p.type_id
  JOIN project_statuses ps ON ps.id=p.status_id
  WHERE p.workspace_id=$ws
  ORDER BY p.id DESC")->fetchAll();
?>
<h2 class="mb-3">Projects</h2>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Project</th><th>Client</th><th>Type</th><th>Status</th><th>Due</th><th></th></tr></thead>
      <tbody>
      <?php foreach($projects as $p): ?>
        <tr>
          <td class="fw-semibold"><?=h($p['name'])?></td>
          <td class="text-muted"><?=h($p['client_name'])?></td>
          <td class="text-muted"><?=h($p['type_name'])?></td>
          <td><span class="badge badge-soft"><?=h($p['status_name'])?></span></td>
          <td class="text-muted"><?=h($p['due_date'] ? format_date($p['due_date']) : '—')?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-light" href="project_view.php?id=<?=h($p['id'])?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$projects): ?><tr><td colspan="6" class="text-muted">No projects yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
