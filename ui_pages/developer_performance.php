<?php
require_once __DIR__ . '/../layout.php';
$pdo=db(); $ws=auth_workspace_id();

$stmt=$pdo->prepare("SELECT u.name, u.email,
  SUM(CASE WHEN t.status='Approved (Ready to Submit)' THEN 1 ELSE 0 END) AS approved,
  SUM(CASE WHEN t.status='Submitted to Client' THEN 1 ELSE 0 END) AS submitted,
  COUNT(*) AS total
  FROM task_assignees ta
  JOIN users u ON u.id=ta.user_id
  JOIN tasks t ON t.id=ta.task_id
  WHERE t.workspace_id=?
  GROUP BY u.id
  ORDER BY submitted DESC, approved DESC, total DESC");
$stmt->execute([$ws]);
$rows=$stmt->fetchAll();
?>
<h2 class="mb-3">Developer Performance</h2>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead><tr><th>User</th><th>Approved</th><th>Submitted</th><th>Total Assigned</th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= h($r['name']).' <span class="text-muted small">('.h($r['email']).')</span>' ?></td>
          <td><?= (int)$r['approved'] ?></td>
          <td><?= (int)$r['submitted'] ?></td>
          <td><?= (int)$r['total'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="4" class="text-muted">No data yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../layout_end.php'; ?>
