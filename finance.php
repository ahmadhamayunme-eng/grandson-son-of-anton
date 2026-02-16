<?php
require_once __DIR__ . '/layout.php';
if(!auth_can_finance()){ http_response_code(403); echo "Forbidden"; require __DIR__ . '/layout_end.php'; exit; }
?>
<h2 class="mb-3">Finance Dashboard</h2>
<div class="card p-4">
  <div class="fw-semibold mb-2">Coming next</div>
  <div class="text-muted">This MVP includes the role gate only. Add invoices, payments, and reporting here.</div>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
