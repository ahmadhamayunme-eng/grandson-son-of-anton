<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();

// Pagination (item #15) — replaces the previous unbounded LIMIT 300 fetch.
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;
$rows = [];
$totalRows = 0;
try {
  $cnt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE workspace_id=?");
  $cnt->execute([$ws]);
  $totalRows = (int)$cnt->fetchColumn();

  $stmt=$pdo->prepare("SELECT al.*, u.name AS actor FROM activity_log al LEFT JOIN users u ON u.id=al.actor_user_id WHERE al.workspace_id=? ORDER BY al.id DESC LIMIT ? OFFSET ?");
  $stmt->bindValue(1, $ws, PDO::PARAM_INT);
  $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
  $stmt->bindValue(3, $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows=$stmt->fetchAll();
} catch (Throwable $e) {
  app_log('activity.list', $e);
  $rows=[];
}
$totalPages = max(1, (int)ceil($totalRows / $perPage));
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
  <?php if ($totalPages > 1): ?>
    <div class="d-flex align-items-center justify-content-between mt-3" style="gap:12px;flex-wrap:wrap;">
      <span class="text-muted" style="font-size:13px;">
        Page <?= (int)$page ?> of <?= (int)$totalPages ?> · <?= (int)$totalRows ?> events
      </span>
      <div style="display:flex;gap:8px;">
        <?php if ($page > 1): ?>
          <a class="btn btn-ghost btn-sm" href="?page=<?= (int)($page - 1) ?>">‹ Newer</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a class="btn btn-ghost btn-sm" href="?page=<?= (int)($page + 1) ?>">Older ›</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
