<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();

$stmt=$pdo->prepare("SELECT c.id,c.name,
  COUNT(DISTINCT p.id) AS projects,
  SUM(CASE WHEN t.status NOT IN ('Approved (Ready to Submit)','Submitted to Client') THEN 1 ELSE 0 END) AS open_tasks,
  SUM(CASE WHEN t.status='Completed (Needs CTO Review)' THEN 1 ELSE 0 END) AS needs_cto
  FROM clients c
  LEFT JOIN projects p ON p.client_id=c.id
  LEFT JOIN tasks t ON t.project_id=p.id AND t.workspace_id=c.workspace_id
  WHERE c.workspace_id=?
  GROUP BY c.id
  ORDER BY c.name");
$stmt->execute([$ws]);
$rows=$stmt->fetchAll();
?>
<h2 class="mb-3">Client Reports</h2>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead><tr><th>Client</th><th>Projects</th><th>Open Tasks</th><th>Needs CTO</th><th class="text-end">Open</th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= h($r['name']) ?></td>
          <td><?= (int)$r['projects'] ?></td>
          <td><?= (int)$r['open_tasks'] ?></td>
          <td><?= (int)$r['needs_cto'] ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-light" href="../client_view.php?id=<?= (int)$r['id'] ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="5" class="text-muted">No clients.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
