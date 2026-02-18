<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/activity.php';
auth_require_perm('finance.view');

$pdo = db();
$ws = auth_workspace_id();
$u = auth_user();

// Ensure table exists for environments where schema migrations were not applied yet.
$pdo->exec("CREATE TABLE IF NOT EXISTS finance_receivables (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL,
  client_id INT NULL,
  project_id INT NULL,
  expected_amount DECIMAL(12,2) NOT NULL,
  received_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  due_date DATE NOT NULL,
  status ENUM('pending','partial','received','cancelled') NOT NULL DEFAULT 'pending',
  description VARCHAR(190) NULL,
  reference VARCHAR(120) NULL,
  notes TEXT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_fr_ws (workspace_id),
  INDEX idx_fr_due (due_date),
  INDEX idx_fr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$clientsStmt = $pdo->prepare("SELECT id,name FROM clients WHERE workspace_id=? ORDER BY name");
$clientsStmt->execute([$ws]);
$clients = $clientsStmt->fetchAll();

$projectsStmt = $pdo->prepare("SELECT p.id,p.name,c.name AS client_name
  FROM projects p
  JOIN clients c ON c.id=p.client_id
  WHERE p.workspace_id=?
  ORDER BY p.id DESC");
$projectsStmt->execute([$ws]);
$projects = $projectsStmt->fetchAll();

$action = $_POST['action'] ?? null;
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($action === 'create') {
  $client_id = ($_POST['client_id'] ?? '') !== '' ? (int)$_POST['client_id'] : null;
  $project_id = ($_POST['project_id'] ?? '') !== '' ? (int)$_POST['project_id'] : null;
  $expected_amount = max(0, (float)($_POST['expected_amount'] ?? 0));
  $due_date = $_POST['due_date'] ?? date('Y-m-d');
  $description = trim($_POST['description'] ?? '');
  $reference = trim($_POST['reference'] ?? '');
  $notes = trim($_POST['notes'] ?? '');

  if ($expected_amount <= 0) {
    flash_set('error', 'Expected amount must be greater than 0.');
    redirect(basename(__FILE__));
  }

  $pdo->prepare("INSERT INTO finance_receivables
    (workspace_id,client_id,project_id,expected_amount,received_amount,due_date,status,description,reference,notes,created_by,created_at)
    VALUES (?,?,?,?,0,?,'pending',?,?,?,?,NOW())")
    ->execute([$ws, $client_id, $project_id, $expected_amount, $due_date, $description ?: null, $reference ?: null, $notes ?: null, (int)$u['id']]);

  activity_log('finance_receivable', (int)$pdo->lastInsertId(), 'create', 'Receivable added');
  flash_set('success', 'Unreceived payment saved.');
  redirect(basename(__FILE__));
}

if ($action === 'record_received' && $id > 0) {
  $payment_amount = max(0, (float)($_POST['payment_amount'] ?? 0));
  $received_date = $_POST['received_date'] ?? date('Y-m-d');
  $method = trim($_POST['method'] ?? '');
  $reference = trim($_POST['payment_reference'] ?? '');
  $notes = trim($_POST['payment_notes'] ?? '');

  $stmt = $pdo->prepare("SELECT * FROM finance_receivables WHERE id=? AND workspace_id=?");
  $stmt->execute([$id, $ws]);
  $row = $stmt->fetch();

  if (!$row) {
    flash_set('error', 'Receivable not found.');
    redirect(basename(__FILE__));
  }

  if ($row['status'] === 'received' || $row['status'] === 'cancelled') {
    flash_set('error', 'This receivable can no longer receive payments.');
    redirect(basename(__FILE__));
  }

  if ($payment_amount <= 0) {
    flash_set('error', 'Payment amount must be greater than 0.');
    redirect(basename(__FILE__));
  }

  $currentReceived = (float)$row['received_amount'];
  $expected = (float)$row['expected_amount'];
  $newReceived = min($expected, $currentReceived + $payment_amount);
  $newStatus = $newReceived >= $expected ? 'received' : 'partial';

  $pdo->beginTransaction();
  try {
    $pdo->prepare("UPDATE finance_receivables
      SET received_amount=?, status=?, notes=?
      WHERE id=? AND workspace_id=?")
      ->execute([
        $newReceived,
        $newStatus,
        trim(($row['notes'] ? $row['notes'] . "\n" : '') . 'Payment recorded ' . date('Y-m-d H:i') . ' amount ' . number_format($payment_amount, 2)) ?: null,
        $id,
        $ws,
      ]);

    $paymentReference = $reference ?: ($row['reference'] ?: ('receivable#' . $id));
    $paymentNotes = trim(($notes ? $notes . "\n" : '') . 'Linked receivable #' . $id . (($row['description'] ?? '') !== '' ? (' - ' . $row['description']) : ''));

    $pdo->prepare("INSERT INTO finance_payments
      (workspace_id,client_id,project_id,amount,received_date,method,reference,notes,created_by,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,NOW())")
      ->execute([
        $ws,
        $row['client_id'] ?: null,
        $row['project_id'] ?: null,
        $payment_amount,
        $received_date,
        $method ?: null,
        $paymentReference ?: null,
        $paymentNotes ?: null,
        (int)$u['id'],
      ]);

    activity_log('finance_receivable', $id, 'update', 'Payment applied to receivable');
    activity_log('finance_payment', (int)$pdo->lastInsertId(), 'create', 'Payment from receivable workflow');
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  flash_set('success', $newStatus === 'received' ? 'Payment recorded. Receivable fully received.' : 'Payment recorded as partial.');
  redirect(basename(__FILE__));
}

if ($action === 'mark_cancelled' && $id > 0) {
  $pdo->prepare("UPDATE finance_receivables SET status='cancelled' WHERE id=? AND workspace_id=?")
    ->execute([$id, $ws]);
  activity_log('finance_receivable', $id, 'update', 'Receivable cancelled');
  flash_set('success', 'Receivable marked as cancelled.');
  redirect(basename(__FILE__));
}

if ($action === 'delete' && $id > 0) {
  $pdo->prepare("DELETE FROM finance_receivables WHERE id=? AND workspace_id=?")
    ->execute([$id, $ws]);
  activity_log('finance_receivable', $id, 'delete', 'Receivable deleted');
  flash_set('success', 'Receivable deleted.');
  redirect(basename(__FILE__));
}

$statusFilter = $_GET['status'] ?? 'open';
$allowedFilters = ['open', 'pending', 'partial', 'received', 'cancelled', 'all'];
if (!in_array($statusFilter, $allowedFilters, true)) {
  $statusFilter = 'open';
}

$whereSql = "fr.workspace_id=?";
$params = [$ws];
if ($statusFilter === 'open') {
  $whereSql .= " AND fr.status IN ('pending','partial')";
} elseif ($statusFilter !== 'all') {
  $whereSql .= " AND fr.status=?";
  $params[] = $statusFilter;
}

$rowsStmt = $pdo->prepare("SELECT fr.*, c.name AS client_name, p.name AS project_name,
    (fr.expected_amount - fr.received_amount) AS balance_due,
    CASE
      WHEN fr.status IN ('pending','partial') AND fr.due_date < CURDATE() THEN 1
      ELSE 0
    END AS is_overdue
  FROM finance_receivables fr
  LEFT JOIN clients c ON c.id=fr.client_id
  LEFT JOIN projects p ON p.id=fr.project_id
  WHERE $whereSql
  ORDER BY fr.due_date ASC, fr.id DESC
  LIMIT 400");
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

$summaryStmt = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN status IN ('pending','partial') THEN expected_amount - received_amount ELSE 0 END), 0) AS open_balance,
    COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
    COALESCE(SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END), 0) AS partial_count,
    COALESCE(SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END), 0) AS received_count,
    COALESCE(SUM(CASE WHEN status IN ('pending','partial') AND due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_count
  FROM finance_receivables
  WHERE workspace_id=?");
$summaryStmt->execute([$ws]);
$summary = $summaryStmt->fetch() ?: [
  'open_balance' => 0,
  'pending_count' => 0,
  'partial_count' => 0,
  'received_count' => 0,
  'overdue_count' => 0,
];
?>

<h2 class="mb-3">Unreceived Payments</h2>

<div class="row g-3 mb-4">
  <div class="col-md-3"><div class="card p-3"><div class="text-muted">Open Balance</div><div class="h4 mb-0"><?= number_format((float)$summary['open_balance'], 2) ?></div></div></div>
  <div class="col-md-2"><div class="card p-3"><div class="text-muted">Pending</div><div class="h4 mb-0"><?= (int)$summary['pending_count'] ?></div></div></div>
  <div class="col-md-2"><div class="card p-3"><div class="text-muted">Partial</div><div class="h4 mb-0"><?= (int)$summary['partial_count'] ?></div></div></div>
  <div class="col-md-2"><div class="card p-3"><div class="text-muted">Received</div><div class="h4 mb-0"><?= (int)$summary['received_count'] ?></div></div></div>
  <div class="col-md-3"><div class="card p-3"><div class="text-muted">Overdue Open</div><div class="h4 mb-0 text-warning"><?= (int)$summary['overdue_count'] ?></div></div></div>
</div>

<div class="card p-3 mb-4">
  <form method="post" class="row g-2">
    <input type="hidden" name="action" value="create">
    <div class="col-md-3">
      <label class="form-label">Client</label>
      <select class="form-select" name="client_id">
        <option value="">-- optional --</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Project</label>
      <select class="form-select" name="project_id">
        <option value="">-- optional --</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= h($p['client_name'] . ' — ' . $p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Expected Amount</label>
      <input class="form-control" name="expected_amount" type="number" min="0" step="0.01" required>
    </div>
    <div class="col-md-2">
      <label class="form-label">Due Date</label>
      <input class="form-control" name="due_date" type="date" value="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="col-md-2">
      <label class="form-label">Reference</label>
      <input class="form-control" name="reference" placeholder="#invoice">
    </div>
    <div class="col-md-4">
      <label class="form-label">Description</label>
      <input class="form-control" name="description" placeholder="Milestone / invoice description">
    </div>
    <div class="col-md-5">
      <label class="form-label">Notes</label>
      <input class="form-control" name="notes" placeholder="Optional notes">
    </div>
    <div class="col-md-3 d-flex align-items-end">
      <button class="btn btn-light w-100">Add Unreceived Payment</button>
    </div>
  </form>
</div>

<div class="card p-3 mb-3">
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <span class="text-muted">Filter:</span>
    <?php
      $filters = [
        'open' => 'Open',
        'pending' => 'Pending',
        'partial' => 'Partial',
        'received' => 'Received',
        'cancelled' => 'Cancelled',
        'all' => 'All',
      ];
      foreach ($filters as $key => $label):
        $active = $statusFilter === $key;
    ?>
      <a class="btn btn-sm <?= $active ? 'btn-light' : 'btn-outline-light' ?>" href="unreceived_payments.php?status=<?= h($key) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
    <a class="btn btn-sm btn-outline-info ms-auto" href="payments_received.php">Go to Payments Received</a>
  </div>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Due</th><th>Expected</th><th>Received</th><th>Balance</th><th>Status</th><th>Client</th><th>Project</th><th>Reference</th><th>Description</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $status = (string)$r['status'];
          $badgeClass = $status === 'received' ? 'bg-success' : ($status === 'partial' ? 'bg-info text-dark' : ($status === 'cancelled' ? 'bg-secondary' : 'bg-warning text-dark'));
          $isOpen = in_array($status, ['pending', 'partial'], true);
        ?>
        <tr>
          <td>
            <?php if ((int)$r['is_overdue'] === 1): ?><span class="badge bg-danger me-1">Overdue</span><?php endif; ?>
            <?= h($r['due_date']) ?>
          </td>
          <td><?= number_format((float)$r['expected_amount'], 2) ?></td>
          <td><?= number_format((float)$r['received_amount'], 2) ?></td>
          <td><?= number_format(max(0, (float)$r['balance_due']), 2) ?></td>
          <td><span class="badge <?= $badgeClass ?>"><?= h(ucfirst($status)) ?></span></td>
          <td><?= h($r['client_name'] ?? '-') ?></td>
          <td><?= h($r['project_name'] ?? '-') ?></td>
          <td><?= h($r['reference'] ?? '-') ?></td>
          <td><?= h($r['description'] ?? '') ?></td>
          <td>
            <?php if ($isOpen): ?>
              <details>
                <summary class="btn btn-sm btn-outline-light">Record Receipt</summary>
                <form method="post" class="mt-2" onsubmit="return confirm('Record this payment and push it to Payments Received?');">
                  <input type="hidden" name="action" value="record_received">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <div class="mb-2">
                    <input class="form-control form-control-sm" type="number" name="payment_amount" min="0" step="0.01" max="<?= h((string)max(0, (float)$r['balance_due'])) ?>" placeholder="Amount" required>
                  </div>
                  <div class="mb-2">
                    <input class="form-control form-control-sm" type="date" name="received_date" value="<?= date('Y-m-d') ?>" required>
                  </div>
                  <div class="mb-2">
                    <input class="form-control form-control-sm" name="method" placeholder="Bank/Stripe/Cash">
                  </div>
                  <div class="mb-2">
                    <input class="form-control form-control-sm" name="payment_reference" placeholder="Txn ref (optional)">
                  </div>
                  <div class="mb-2">
                    <input class="form-control form-control-sm" name="payment_notes" placeholder="Note (optional)">
                  </div>
                  <button class="btn btn-sm btn-success w-100">Save Receipt</button>
                </form>
              </details>
              <form method="post" class="mt-2" onsubmit="return confirm('Cancel this receivable?');">
                <input type="hidden" name="action" value="mark_cancelled">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-warning w-100">Cancel</button>
              </form>
            <?php endif; ?>
            <form method="post" class="mt-2" onsubmit="return confirm('Delete this receivable?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger w-100">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="10" class="text-center text-muted">No receivables found for this filter.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
