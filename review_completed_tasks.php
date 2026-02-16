<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();

$stmt=$pdo->prepare("SELECT t.id,t.title,t.updated_at,p.name AS project_name,c.name AS client_name
  FROM tasks t
  JOIN projects p ON p.id=t.project_id
  JOIN clients c ON c.id=p.client_id
  WHERE t.workspace_id=? AND t.status='Completed (Needs CTO Review)'
  ORDER BY t.updated_at DESC");
$stmt->execute([$ws]);
$rows=$stmt->fetchAll();
?>
<h2 class="mb-3">Completed Tasks (Needs CTO Review)</h2>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead><tr><th>Task</th><th>Client</th><th>Project</th><th>Updated</th><th class="text-end">Open</th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= h($r['title']) ?></td>
          <td><?= h($r['client_name']) ?></td>
          <td><?= h($r['project_name']) ?></td>
          <td><?= h($r['updated_at']) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-light" href="../task_view.php?id=<?= (int)$r['id'] ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="5" class="text-muted">Nothing to review.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
