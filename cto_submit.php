<?php
require_once __DIR__ . '/layout.php';
auth_require_any(['CTO','Super Admin']);
$pdo=db(); $ws=auth_workspace_id();

$rows=$pdo->query("SELECT t.id,t.title,t.status,t.updated_at,p.name AS project_name,c.name AS client_name,
  GROUP_CONCAT(u.name SEPARATOR ', ') AS assignees
  FROM tasks t
  JOIN projects p ON p.id=t.project_id
  JOIN clients c ON c.id=p.client_id
  LEFT JOIN task_assignees ta ON ta.task_id=t.id
  LEFT JOIN users u ON u.id=ta.user_id
  WHERE t.workspace_id=$ws AND t.status='Approved (Ready to Submit)'
  GROUP BY t.id
  ORDER BY t.updated_at DESC")->fetchAll();
?>
<h2 class="mb-3">Submit to Client</h2>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Task</th><th>Client</th><th>Project</th><th>Assignees</th><th>Updated</th><th></th></tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td class="fw-semibold"><?=h($r['title'])?></td>
            <td class="text-muted"><?=h($r['client_name'])?></td>
            <td class="text-muted"><?=h($r['project_name'])?></td>
            <td class="text-muted"><?=h($r['assignees'] ?? '—')?></td>
            <td class="text-muted"><?=h($r['updated_at'])?></td>
            <td class="text-end"><a class="btn btn-sm btn-yellow" href="task_view.php?id=<?=h($r['id'])?>">Submit</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$rows): ?><tr><td colspan="6" class="text-muted">No tasks ready to submit.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
