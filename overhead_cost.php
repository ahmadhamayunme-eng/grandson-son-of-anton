<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/activity.php';

auth_require_perm('finance.view');
$pdo = db();
$ws = auth_workspace_id();
$u = auth_user();

$action = $_POST['action'] ?? null;
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($action === 'create') {
  $overhead_month = preg_replace('/[^0-9\-]/', '', $_POST['overhead_month'] ?? date('Y-m'));
  $category = trim($_POST['category'] ?? 'General');
  $amount = (float)($_POST['amount'] ?? 0);
  $notes = trim($_POST['notes'] ?? '');

  try {
    $pdo->prepare("INSERT INTO finance_overheads (workspace_id,overhead_month,category,amount,notes,created_by,created_at) VALUES (?,?,?,?,?,?,NOW())")
      ->execute([$ws, $overhead_month, $category, $amount, $notes ?: null, (int)$u['id']]);
    activity_log('finance_overhead', (int)$pdo->lastInsertId(), 'create', 'Overhead added');
    flash_set('success', 'Saved');
  } catch (Throwable $e) {
    flash_set('error', 'Unable to save overhead right now. Please check database setup for finance_overheads.');
  }
  redirect(basename(__FILE__));
}

if ($action === 'delete' && $id > 0) {
  try {
    $pdo->prepare("DELETE FROM finance_overheads WHERE id=? AND workspace_id=?")->execute([$id, $ws]);
    activity_log('finance_overhead', $id, 'delete', 'Overhead deleted');
    flash_set('success', 'Deleted');
  } catch (Throwable $e) {
    flash_set('error', 'Unable to delete overhead right now. Please check database setup for finance_overheads.');
  }
  redirect(basename(__FILE__));
}

$rows = [];
$loadError = null;
try {
  $rows = $pdo->prepare("SELECT * FROM finance_overheads WHERE workspace_id=? ORDER BY overhead_month DESC, id DESC LIMIT 300");
  $rows->execute([$ws]);
  $rows = $rows->fetchAll();
} catch (Throwable $e) {
  $loadError = 'Overhead table is unavailable. Please run/update schema.sql to create finance_overheads.';
}
?>
<style>
  .oh-shell{border:1px solid rgba(255,255,255,.1);border-radius:16px;background:linear-gradient(180deg,#101010,#070707);overflow:hidden}
  .oh-head{padding:1rem 1.1rem;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap}
  .oh-title{font-size:2.1rem;font-weight:600;margin:0}
  .oh-controls{display:flex;gap:.45rem;flex-wrap:wrap}
  .oh-pill{padding:.45rem .7rem;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04);color:#fff}
  .oh-body{padding:1rem 1.1rem;color:#fff}
  .oh-table{border:1px solid rgba(255,255,255,.1);border-radius:12px;overflow:hidden;background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.015))}
  .oh-table table{width:100%;border-collapse:collapse}
  .oh-table th,.oh-table td{padding:.75rem .8rem;border-bottom:1px solid rgba(255,255,255,.08)}
  .oh-table th{background:rgba(255,255,255,.03);color:#fff;font-weight:600}
  .form-label{color:#fff !important}
  .text-muted{color:#fff !important;opacity:.9}
  details summary{color:#fff}

</style>

<div class="oh-shell">
  <div class="oh-head">
    <h2 class="oh-title">Overhead Costs</h2>
    <div class="oh-controls">
      <span class="oh-pill">SpeedX ▾</span><span class="oh-pill">Year to Date ▾</span><span class="oh-pill">◉</span>
      <a class="btn btn-outline-light btn-sm" href="finance.php">Show All</a>
      <a class="btn btn-yellow btn-sm" href="client_reports.php">Export</a>
    </div>
  </div>

  <div class="oh-body">
    <?php if ($loadError): ?><div class="alert alert-danger"><?= h($loadError) ?></div><?php endif; ?>

    <details class="mb-3"><summary class="btn btn-outline-light btn-sm">Add Overhead</summary>
      <div class="card p-3 mt-2">
        <form method="post" class="row g-2">
          <input type="hidden" name="action" value="create">
          <div class="col-md-2"><label class="form-label">Month (YYYY-MM)</label><input class="form-control" name="overhead_month" value="<?= date('Y-m') ?>" required></div>
          <div class="col-md-4"><label class="form-label">Category</label><input class="form-control" name="category" required></div>
          <div class="col-md-2"><label class="form-label">Amount</label><input class="form-control" name="amount" type="number" step="0.01" required></div>
          <div class="col-md-4"><label class="form-label">Notes</label><input class="form-control" name="notes"></div>
          <div class="col-md-3 d-flex align-items-end"><button class="btn btn-yellow w-100">Add Overhead</button></div>
        </form>
      </div>
    </details>

    <div class="oh-table">
      <table>
        <thead><tr><th>Category</th><th>Description</th><th>Amount</th><th>Last Paid</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= h($r['category']) ?></td>
            <td><?= h($r['notes'] ?: $r['category']) ?></td>
            <td style="color:#f6d469;font-weight:600">$<?= number_format((float)$r['amount'], 2) ?></td>
            <td><?= h($r['overhead_month']) ?></td>
            <td class="text-end"><form method="post" style="display:inline" onsubmit="return confirm('Delete this overhead record?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-muted">No overhead records yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
