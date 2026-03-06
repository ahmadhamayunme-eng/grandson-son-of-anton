<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/activity.php';
require_once __DIR__ . '/lib/finance.php';
auth_require_perm('finance.view');
$pdo = db();
finance_ensure_schema($pdo);
$ws = auth_workspace_id();
$u = auth_user();

$clients = $pdo->prepare("SELECT id,name FROM clients WHERE workspace_id=? ORDER BY name");
$clients->execute([$ws]);
$clients = $clients->fetchAll();
$projects = $pdo->prepare("SELECT p.id,p.name,c.name AS client_name FROM projects p JOIN clients c ON c.id=p.client_id WHERE p.workspace_id=? ORDER BY p.id DESC");
$projects->execute([$ws]);
$projects = $projects->fetchAll();
$invoicesStmt = $pdo->prepare("SELECT fr.id, fr.invoice_no, fr.invoice_type, c.name AS client_name, p.name AS project_name FROM finance_receivables fr LEFT JOIN clients c ON c.id=fr.client_id LEFT JOIN projects p ON p.id=fr.project_id WHERE fr.workspace_id=? ORDER BY fr.id DESC LIMIT 300");
$invoicesStmt->execute([$ws]);
$invoices = $invoicesStmt->fetchAll();

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
  $invoiceId = ($_POST['invoice_id'] ?? '') !== '' ? (int)$_POST['invoice_id'] : null;
  if ($invoiceId) {
    $inv = $pdo->prepare("SELECT id, client_id, project_id FROM finance_receivables WHERE id=? AND workspace_id=?");
    $inv->execute([$invoiceId, $ws]);
    $iv = $inv->fetch();
    if ($iv) { $client_id = $iv['client_id'] ?: $client_id; $project_id = $iv['project_id'] ?: $project_id; }
  }

  $pdo->prepare("INSERT INTO finance_payments (workspace_id,client_id,project_id,receivable_id,invoice_id,amount,received_date,method,reference,notes,created_by,created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
    ->execute([$ws,$client_id,$project_id,$invoiceId,$invoiceId,$amount,$received_date,$method?:null,$reference?:null,$notes?:null,(int)$u['id']]);
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

$rows = $pdo->prepare("SELECT fp.*, c.name AS client_name, p.name AS project_name, fr.invoice_no, fr.invoice_type
  FROM finance_payments fp
  LEFT JOIN clients c ON c.id=fp.client_id
  LEFT JOIN projects p ON p.id=fp.project_id
  LEFT JOIN finance_receivables fr ON fr.id = COALESCE(fp.receivable_id, fp.invoice_id)
  WHERE fp.workspace_id=?
  ORDER BY fp.received_date DESC, fp.id DESC LIMIT 300");
$rows->execute([$ws]);
$rows = $rows->fetchAll();
?>
<style>
  .pay-shell{border:1px solid rgba(255,255,255,.1);border-radius:16px;background:linear-gradient(180deg,#101010,#070707);overflow:hidden}
  .pay-head{padding:1rem 1.1rem;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap}
  .pay-title{font-size:2.2rem;font-weight:600;margin:0}
  .pay-controls{display:flex;gap:.5rem;flex-wrap:wrap}
  .pay-pill{padding:.45rem .72rem;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04);color:#fff}
  .pay-body{padding:1rem 1.1rem;color:#fff}
  .pay-table{border:1px solid rgba(255,255,255,.1);border-radius:12px;overflow:hidden;background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.015))}
  .pay-table table{width:100%;border-collapse:collapse}
  .pay-table th,.pay-table td{padding:.75rem .8rem;border-bottom:1px solid rgba(255,255,255,.08)}
  .pay-table th{background:rgba(255,255,255,.03);color:#fff;font-weight:600}
  .amt-chip{display:inline-block;padding:.28rem .58rem;border-radius:8px;background:rgba(87,200,143,.15);border:1px solid rgba(87,200,143,.35);color:#9de8bf}
  .method-chip{display:inline-block;padding:.28rem .58rem;border-radius:8px;background:rgba(246,212,105,.12);border:1px solid rgba(246,212,105,.35);color:#f6d469}
  .form-label{color:#fff !important}
  .text-muted{color:#fff !important;opacity:.9}
  details summary{color:#fff}

</style>

<div class="pay-shell">
  <div class="pay-head">
    <h2 class="pay-title">Payments Received</h2>
    <div class="pay-controls">
      <span class="pay-pill">All Clients ▾</span>
      <span class="pay-pill">All Payment Methods ▾</span>
      <span class="pay-pill"><?=date('F Y')?> ▾</span>
      <a class="btn btn-yellow btn-sm" href="client_reports.php">Export</a>
    </div>
  </div>

  <div class="pay-body">
    <details class="mb-3">
      <summary class="btn btn-outline-light btn-sm">Add Payment</summary>
      <div class="card p-3 mt-2">
        <form method="post" class="row g-2">
          <input type="hidden" name="action" value="create">
          <div class="col-md-3"><label class="form-label">Client</label><select class="form-select" name="client_id"><option value="">-- optional --</option><?php foreach($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-3"><label class="form-label">Project</label><select class="form-select" name="project_id"><option value="">-- optional --</option><?php foreach($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['client_name'].' — '.$p['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-3"><label class="form-label">Invoice</label><select class="form-select" name="invoice_id"><option value="">-- optional --</option><?php foreach($invoices as $iv): ?><option value="<?= (int)$iv['id'] ?>"><?= h(($iv['invoice_no'] ?: ('INV-'.$iv['id'])) . " • " . strtoupper((string)$iv['invoice_type']) . " • " . ($iv['client_name'] ?: '-')) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-2"><label class="form-label">Amount</label><input class="form-control" name="amount" type="number" step="0.01" required></div>
          <div class="col-md-2"><label class="form-label">Date</label><input class="form-control" name="received_date" type="date" value="<?= date('Y-m-d') ?>" required></div>
          <div class="col-md-2"><label class="form-label">Method</label><input class="form-control" name="method" placeholder="Bank/Stripe/Cash"></div>
          <div class="col-md-3"><label class="form-label">Reference</label><input class="form-control" name="reference"></div>
          <div class="col-md-6"><label class="form-label">Notes</label><input class="form-control" name="notes"></div>
          <div class="col-md-3 d-flex align-items-end"><button class="btn btn-yellow w-100">Save Payment</button></div>
        </form>
      </div>
    </details>

    <div class="pay-table">
      <table>
        <thead><tr><th>Receipt</th><th>Client</th><th>Amount</th><th>Payment Method</th><th>Date Received</th><th></th></tr></thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?= h($r['reference'] ?: ('REC-'.str_pad((string)$r['id'],5,'0',STR_PAD_LEFT))) ?></td>
            <td><?= h($r['client_name'] ?? '-') ?></td>
            <td><span class="amt-chip">$<?= number_format((float)$r['amount'],2) ?></span></td>
            <td><span class="method-chip"><?= h($r['method'] ?? 'Bank Transfer') ?></span></td>
            <td><?= h(date('M d', strtotime((string)$r['received_date']))) ?></td>
            <td class="text-end"><form method="post" style="display:inline" onsubmit="return confirm('Delete this payment?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$rows): ?><tr><td colspan="6" class="text-muted">No payments received yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
