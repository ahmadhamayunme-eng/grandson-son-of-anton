<?php
require_once __DIR__ . '/../layout.php';
$pdo=db(); $ws=auth_workspace_id();

try {
  $stmt=$pdo->prepare("SELECT al.*, u.name AS actor FROM activity_log al LEFT JOIN users u ON u.id=al.actor_user_id WHERE al.workspace_id=? ORDER BY al.id DESC LIMIT 300");
  $stmt->execute([$ws]);
  $rows=$stmt->fetchAll();
} catch (Throwable $e) {
  $rows=[];
}
?>
<h2 class="mb-3">Activity</h2>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>Message</th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= h($r['created_at']) ?></td>
          <td><?= h($r['actor'] ?? '-') ?></td>
          <td><?= h($r['action']) ?></td>
          <td><?= h($r['entity_type']).' #'.h((string)$r['entity_id']) ?></td>
          <td><?= h($r['message'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="5" class="text-muted">No activity yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../layout_end.php'; ?>
