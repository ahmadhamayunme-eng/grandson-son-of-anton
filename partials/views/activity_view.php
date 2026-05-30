<?php
/**
 * Activity view (item #22). Pure presentation — expects $activity (the array
 * returned by activity_page_data). Rendered by the activity.php controller.
 */
$rows       = $activity['rows'] ?? [];
$page       = (int)($activity['page'] ?? 1);
$totalPages = (int)($activity['totalPages'] ?? 1);
$total      = (int)($activity['total'] ?? 0);
?>
<h2 class="mb-3">Activity</h2>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>Message</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['created_at']) ?></td>
          <td><?= h($r['actor'] ?? '-') ?></td>
          <td><?= h($r['action']) ?></td>
          <td><?= h($r['entity_type']) . ' #' . h((string)$r['entity_id']) ?></td>
          <td><?= h($r['message'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-muted">No activity yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <div class="d-flex align-items-center justify-content-between mt-3" style="gap:12px;flex-wrap:wrap;">
      <span class="text-muted" style="font-size:13px;">Page <?= $page ?> of <?= $totalPages ?> · <?= $total ?> events</span>
      <div style="display:flex;gap:8px;">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="?page=<?= $page - 1 ?>">‹ Newer</a><?php endif; ?>
        <?php if ($page < $totalPages): ?><a class="btn btn-ghost btn-sm" href="?page=<?= $page + 1 ?>">Older ›</a><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
