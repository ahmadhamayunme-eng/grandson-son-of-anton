<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id(); $uid=auth_user()['id'];

$tasks=$pdo->prepare("SELECT t.*, p.name AS project_name, c.name AS client_name
  FROM tasks t
  JOIN projects p ON p.id=t.project_id
  JOIN clients c ON c.id=p.client_id
  JOIN task_assignees ta ON ta.task_id=t.id AND ta.user_id=?
  WHERE t.workspace_id=?
  ORDER BY t.id DESC");
$tasks->execute([$uid,$ws]);
$tasks=$tasks->fetchAll();
?>
<h2 class="mb-3">My Tasks</h2>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Task</th><th>Client</th><th>Project</th><th>Status</th><th>Due</th><th></th></tr></thead>
      <tbody>
        <?php foreach($tasks as $t): ?>
          <tr>
            <td class="fw-semibold"><?=h($t['title'])?></td>
            <td class="text-muted"><?=h($t['client_name'])?></td>
            <td class="text-muted"><?=h($t['project_name'])?></td>
            <td><span class="badge badge-soft"><?=h($t['status'])?></span></td>
            <td class="text-muted"><?=h($t['due_date'] ? format_date($t['due_date']) : '—')?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-light" href="task_view.php?id=<?=h($t['id'])?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$tasks): ?><tr><td colspan="6" class="text-muted">No assigned tasks yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
