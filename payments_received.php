<?php
require_once __DIR__ . '/../layout.php';
require_once __DIR__ . '/../lib/activity.php';
auth_require_perm('finance.view');
$pdo = db();
$ws = auth_workspace_id();
$u = auth_user();

// Load dropdowns
$clients = $pdo->prepare("SELECT id,name FROM clients WHERE workspace_id=? ORDER BY name");
$clients->execute([$ws]);
$clients = $clients->fetchAll();
$projects = $pdo->prepare("SELECT p.id,p.name,c.name AS client_name FROM projects p JOIN clients c ON c.id=p.client_id WHERE p.workspace_id=? ORDER BY p.id DESC");
$projects->execute([$ws]);
$projects = $projects->fetchAll();

$action = $_POST['action'] ?? null;
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($action === 'create') {
  $client_id = ($_POST['client_id'] ?? '') !== '' ? (int)$_POST['client_id'] : null;
  $project_id = ($_POST['project_id'] ?? '') !== '' ? (int)$_POST['project_id'] : null;
  $amount = (float)($_POST['amount'] ?? 0);
  $received_date = $_POST['received_date'] ?? date('Y-m-d');
  $method = trim($_POST['method'] ?? '');
  $reference = trim($_POST['reference'] ?? '');
  $notes = trim($_POST['notes'] ?? '');

  $pdo->prepare("INSERT INTO finance_payments (workspace_id,client_id,project_id,amount,received_date,method,reference,notes,created_by,created_at)
    VALUES (?,?,?,?,?,?,?,?,?,NOW())")
    ->execute([$ws,$client_id,$project_id,$amount,$received_date,$method?:null,$reference?:null,$notes?:null,(int)$u['id']]);
  activity_log('finance_payment', (int)$pdo->lastInsertId(), 'create', 'Payment added');
  flash_set('success','Payment saved');
  redirect(basename(__FILE__));
}

if ($action === 'delete' && $id>0) {
  $pdo->prepare("DELETE FROM finance_payments WHERE id=? AND workspace_id=?")->execute([$id,$ws]);
  activity_log('finance_payment', $id, 'delete', 'Payment deleted');
  flash_set('success','Deleted');
  redirect(basename(__FILE__));
}

$rows = $pdo->prepare("SELECT fp.*, c.name AS client_name, p.name AS project_name
  FROM finance_payments fp
  LEFT JOIN clients c ON c.id=fp.client_id
  LEFT JOIN projects p ON p.id=fp.project_id
  WHERE fp.workspace_id=?
  ORDER BY fp.received_date DESC, fp.id DESC LIMIT 300");
$rows->execute([$ws]);
$rows = $rows->fetchAll();
?>

<h2 class="mb-3">Payments Received</h2>

<div class="card p-3 mb-4">
  <form method="post" class="row g-2">
    <input type="hidden" name="action" value="create">
    <div class="col-md-3">
      <label class="form-label">Client</label>
      <select class="form-select" name="client_id">
        <option value="">-- optional --</option>
        <?php foreach($clients as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Project</label>
      <select class="form-select" name="project_id">
        <option value="">-- optional --</option>
        <?php foreach($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= h($p['client_name'].' — '.$p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Amount</label>
      <input class="form-control" name="amount" type="number" step="0.01" required>
    </div>
    <div class="col-md-2">
      <label class="form-label">Date</label>
      <input class="form-control" name="received_date" type="date" value="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="col-md-2">
      <label class="form-label">Method</label>
      <input class="form-control" name="method" placeholder="Bank/Stripe/Cash">
    </div>
    <div class="col-md-3">
      <label class="form-label">Reference</label>
      <input class="form-control" name="reference" placeholder="#invoice / txn id">
    </div>
    <div class="col-md-6">
      <label class="form-label">Notes</label>
      <input class="form-control" name="notes" placeholder="Optional">
    </div>
    <div class="col-md-3 d-flex align-items-end">
      <button class="btn btn-light w-100">Add Payment</button>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Date</th><th>Amount</th><th>Client</th><th>Project</th><th>Method</th><th>Reference</th><th>Notes</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= h($r['received_date']) ?></td>
          <td><?= number_format((float)$r['amount'],2) ?></td>
          <td><?= h($r['client_name'] ?? '-') ?></td>
          <td><?= h($r['project_name'] ?? '-') ?></td>
          <td><?= h($r['method'] ?? '-') ?></td>
          <td><?= h($r['reference'] ?? '-') ?></td>
          <td><?= h($r['notes'] ?? '') ?></td>
          <td class="text-end">
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this payment?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../layout_end.php'; ?>
