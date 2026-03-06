<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/finance.php';
auth_require_perm('finance.view');
$ws = auth_workspace_id();
$tot = finance_totals($ws);

$payments = (float)($tot['payments'] ?? 0);
$expenses = (float)($tot['expenses'] ?? 0);
$salaries = (float)($tot['salaries'] ?? 0);
$overheads = (float)($tot['overheads'] ?? 0);
$profit = (float)($tot['profit'] ?? 0);
$outstanding = (float)($tot['total_unreceived'] ?? 0);

$recent_unreceived=[];
try {
  $stmt=db()->prepare("SELECT fr.id, fr.invoice_no, fr.invoice_type, fr.status, fr.due_date, (fr.expected_amount-fr.received_amount) AS balance_due, c.name AS client_name, p.name AS project_name FROM finance_receivables fr LEFT JOIN clients c ON c.id=fr.client_id LEFT JOIN projects p ON p.id=fr.project_id WHERE fr.workspace_id=? AND fr.status IN ('pending','partial') ORDER BY fr.due_date ASC LIMIT 6");
  $stmt->execute([$ws]);
  $recent_unreceived=$stmt->fetchAll();
} catch (Throwable $e) {
  $recent_unreceived=[];
}
?>
<style>
  .fin-shell{border:1px solid rgba(255,255,255,.1);border-radius:16px;background:linear-gradient(180deg,#101010,#070707);overflow:hidden}
  .fin-head{padding:1rem 1.1rem;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap}
  .fin-title{font-size:2.2rem;font-weight:600;margin:0}
  .fin-controls{display:flex;gap:.45rem;flex-wrap:wrap}
  .fin-pill{border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04);color:#ededed;padding:.45rem .72rem;border-radius:8px}
  .fin-body{padding:1rem 1.1rem}
  .fin-subhead{display:flex;justify-content:space-between;align-items:center;gap:.7rem;flex-wrap:wrap;margin-bottom:.8rem}
  .fin-subtitle{font-size:2rem;font-weight:500;margin:0}
  .metric-card{border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:1rem;background:linear-gradient(160deg,rgba(255,255,255,.05),rgba(255,255,255,.02))}
  .metric-label{font-size:.92rem;color:rgba(225,225,225,.68);text-transform:uppercase;letter-spacing:.02em}
  .metric-value{font-size:2.7rem;line-height:1.12;margin-top:.3rem}
  .metric-trend{font-size:.95rem;margin-left:.35rem}
  .trend-up{color:#70d39a}.trend-warn{color:#f6d469}.trend-down{color:#ff8f70}
  .fin-grid{display:grid;grid-template-columns:1fr 1.25fr;gap:1rem;margin-top:1rem}
  .panel{border:1px solid rgba(255,255,255,.1);border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.015));overflow:hidden}
  .panel-head{padding:.85rem 1rem;border-bottom:1px solid rgba(255,255,255,.08);font-size:1.9rem;font-weight:500}
  .panel-body{padding:.95rem 1rem}
  .donut-wrap{height:315px;display:flex;align-items:center;justify-content:center;position:relative}
  .donut{width:250px;height:250px;border-radius:50%;background:conic-gradient(#f6d469 0 45%, #f0bf46 45% 70%, #d17a3f 70% 84%, #617c76 84% 100%);position:relative}
  .donut:after{content:'';position:absolute;inset:24%;background:#101010;border-radius:50%;border:1px solid rgba(255,255,255,.08)}
  .donut-center{position:absolute;text-align:center;z-index:2}
  .donut-center .small{color:rgba(220,220,220,.68)}
  .donut-center .val{font-size:2.8rem;font-weight:600}
  .legend li{margin-bottom:.45rem;color:rgba(231,231,231,.82)}
  .legend .dot{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:8px}
  .chart-box{height:315px;position:relative}
  .chart-grid{position:absolute;inset:0;background:repeating-linear-gradient(to top, transparent 0 51px, rgba(255,255,255,.08) 51px 52px)}
  .chart-bars{position:absolute;left:7%;right:7%;bottom:16%;height:68%;display:flex;align-items:flex-end;justify-content:space-between}
  .bar{width:11%;background:linear-gradient(180deg,rgba(246,212,105,.62),rgba(246,212,105,.2));border:1px solid rgba(246,212,105,.24);border-bottom:0;border-radius:8px 8px 0 0}
  .bar1{height:24%}.bar2{height:29%}.bar3{height:38%}.bar4{height:44%}.bar5{height:62%}.bar6{height:46%}
  .line{position:absolute;left:7%;right:7%;top:8%;bottom:16%}
  .fin-table{margin-top:1rem;border:1px solid rgba(255,255,255,.1);border-radius:12px;overflow:hidden;background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.015))}
  .fin-table table{width:100%;border-collapse:collapse}
  .fin-table th,.fin-table td{padding:.75rem .8rem;border-bottom:1px solid rgba(255,255,255,.08)}
  .fin-table th{background:rgba(255,255,255,.03);color:rgba(226,226,226,.82);font-weight:600}
  .amount-badge{display:inline-block;padding:.3rem .55rem;border-radius:8px;background:rgba(246,212,105,.16);border:1px solid rgba(246,212,105,.35);color:#f6d469}
  .overdue{color:#ff8f70}
  @media (max-width: 1200px){.fin-grid{grid-template-columns:1fr}}
</style>

<div class="fin-shell">
  <div class="fin-head">
    <h2 class="fin-title">Finance Dashboard</h2>
    <div class="fin-controls">
      <span class="fin-pill">Filter ▾</span>
      <span class="fin-pill">SpeedX ▾</span>
      <span class="fin-pill">Q1 2024</span>
    </div>
  </div>

  <div class="fin-body">
    <div class="fin-subhead">
      <h3 class="fin-subtitle">Summary</h3>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-light btn-sm" href="payments_received.php">Show All</a>
        <a class="btn btn-yellow btn-sm" href="client_reports.php">Export</a>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-md-6 col-xl-3"><div class="metric-card"><div class="metric-label">Total Received Payments</div><div class="metric-value">$<?=number_format($payments,0)?></div></div></div>
      <div class="col-md-6 col-xl-3"><div class="metric-card"><div class="metric-label">Total Profit</div><div class="metric-value">$<?=number_format($profit,0)?></div></div></div>
      <div class="col-md-6 col-xl-3"><div class="metric-card"><div class="metric-label">Expenses</div><div class="metric-value">$<?=number_format($expenses+$salaries+$overheads,0)?></div></div></div>
      <div class="col-md-6 col-xl-3"><div class="metric-card"><div class="metric-label">Unreceived / Unpaid</div><div class="metric-value" style="color:#f6d469">$<?=number_format($outstanding,0)?></div></div></div>
    </div>

    <div class="fin-grid">
      <div class="panel">
        <div class="panel-head">Income Breakdown</div>
        <div class="panel-body">
          <div class="row">
            <div class="col-md-5">
              <ul class="list-unstyled legend mt-3">
                <li><span class="dot" style="background:#f6d469"></span>Retainer: $<?=number_format((float)($tot['retainer_revenue'] ?? 0),2)?></li>
                <li><span class="dot" style="background:#f0bf46"></span>Hourly: $<?=number_format((float)($tot['hourly_revenue'] ?? 0),2)?></li>
                <li><span class="dot" style="background:#d17a3f"></span>Fixed Project: $<?=number_format((float)($tot['fixed_revenue'] ?? 0),2)?></li>
              </ul>
            </div>
            <div class="col-md-7">
              <div class="donut-wrap">
                <div class="donut"></div>
                <div class="donut-center"><div class="small">Total</div><div class="val">$<?=number_format($payments,0)?></div></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head">Profit & Expense Overview</div>
        <div class="panel-body">
          <div class="chart-box">
            <div class="chart-grid"></div>
            <svg class="line" viewBox="0 0 100 100" preserveAspectRatio="none">
              <polyline points="0,78 18,72 36,58 54,53 72,30 90,27" fill="none" stroke="#f6d469" stroke-width="1.2" />
              <circle cx="0" cy="78" r="1.8" fill="#f6d469"/><circle cx="18" cy="72" r="1.8" fill="#f6d469"/><circle cx="36" cy="58" r="1.8" fill="#f6d469"/><circle cx="54" cy="53" r="1.8" fill="#f6d469"/><circle cx="72" cy="30" r="1.8" fill="#f6d469"/><circle cx="90" cy="27" r="1.8" fill="#f6d469"/>
            </svg>
            <div class="chart-bars"><div class="bar bar1"></div><div class="bar bar2"></div><div class="bar bar3"></div><div class="bar bar4"></div><div class="bar bar5"></div><div class="bar bar6"></div></div>
          </div>
        </div>
      </div>
    </div>

    <div class="fin-table mt-3">
      <table>
        <thead><tr><th>Client / Project</th><th>Invoice</th><th>Type</th><th>Status</th><th>Amount Due</th><th></th></tr></thead>
        <tbody>
          <?php foreach($recent_unreceived as $r): ?>
            <tr>
              <td><?=h(($r['client_name'] ?? '-') . (!empty($r['project_name']) ? (' — ' . $r['project_name']) : ''))?></td>
              <td><?=h($r['invoice_no'] ?: ('INV-'.str_pad((string)$r['id'],4,'0',STR_PAD_LEFT)))?></td>
              <td><?=h($r['invoice_type'] ?? 'project_fixed')?></td>
              <td class="<?= (stripos((string)$r['status'],'overdue')!==false) ? 'overdue' : '' ?>"><?=h($r['status'] ?: 'Pending')?></td>
              <td><span class="amount-badge"><?=number_format((float)$r['balance_due'],2)?></span></td>
              <td class="text-end"><a class="btn btn-sm btn-outline-light" href="unreceived_payments.php">View</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$recent_unreceived): ?>
            <tr><td colspan="6" class="text-muted">No pending invoices. Great work.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
