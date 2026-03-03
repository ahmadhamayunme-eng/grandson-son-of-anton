<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();
$debug_errors=[];

function reports_safe_scalar($pdo, $sql, $label, &$debug_errors){
  try {
    $v=$pdo->query($sql)->fetchColumn();
    return $v !== false ? $v : 0;
  } catch (Throwable $e) {
    $debug_errors[] = $label . ': ' . $e->getMessage();
    return 0;
  }
}

$tot_tasks = (int)reports_safe_scalar($pdo, "SELECT COUNT(*) FROM tasks WHERE workspace_id=$ws", 'Total tasks query failed', $debug_errors);
$open_tasks = (int)reports_safe_scalar($pdo, "SELECT COUNT(*) FROM tasks WHERE workspace_id=$ws AND status NOT IN ('Approved (Ready to Submit)','Submitted to Client')", 'Open tasks query failed', $debug_errors);
$needs_cto = (int)reports_safe_scalar($pdo, "SELECT COUNT(*) FROM tasks WHERE workspace_id=$ws AND status='Completed (Needs CTO Review)'", 'Needs CTO query failed', $debug_errors);
$projects = (int)reports_safe_scalar($pdo, "SELECT COUNT(*) FROM projects WHERE workspace_id=$ws", 'Projects query failed', $debug_errors);

$income = (float)reports_safe_scalar($pdo, "SELECT COALESCE(SUM(amount),0) FROM finance_payments WHERE workspace_id=$ws", 'Income query failed', $debug_errors);
$expenses = (float)reports_safe_scalar($pdo, "SELECT COALESCE(SUM(amount),0) FROM project_expenses WHERE workspace_id=$ws", 'Expenses query failed', $debug_errors);
$profit = $income - $expenses;
$outstanding = max(0, $expenses - $income);
?>
<style>
  .reports-shell{border:1px solid rgba(255,255,255,.1);border-radius:16px;background:linear-gradient(180deg,#111,#080808);overflow:hidden}
  .reports-head{padding:1rem 1.1rem;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap}
  .reports-title{font-size:2.05rem;font-weight:500;margin:0}
  .reports-top-controls{display:flex;gap:.45rem;flex-wrap:wrap;align-items:center}
  .reports-pill{padding:.45rem .7rem;border-radius:8px;border:1px solid rgba(255,255,255,.11);background:rgba(255,255,255,.04);color:#e8e8e8}
  .reports-toggle{width:54px;height:30px;border-radius:99px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.08);position:relative}
  .reports-toggle:before{content:'';position:absolute;left:4px;top:4px;width:20px;height:20px;border-radius:50%;background:#d0d0d0}
  .reports-body{padding:1rem 1.1rem}
  .section-title{font-size:2rem;font-weight:500;margin:0 0 .75rem}
  .metric-card{border:1px solid rgba(255,255,255,.1);border-radius:10px;background:linear-gradient(150deg, rgba(255,255,255,.05), rgba(255,255,255,.02));padding:1rem .95rem}
  .metric-label{font-size:1.02rem;color:rgba(235,235,235,.78);margin-bottom:.35rem}
  .metric-value{font-size:3rem;line-height:1.1}
  .metric-value.gold{color:#f6d469}
  .reports-chart-wrap{margin-top:1rem;border:1px solid rgba(255,255,255,.1);border-radius:10px;overflow:hidden;background:linear-gradient(180deg,#0e0e0e,#111)}
  .reports-chart-head{padding:.85rem 1rem;border-bottom:1px solid rgba(255,255,255,.07);display:flex;justify-content:space-between;align-items:center}
  .reports-chart-area{height:360px;display:flex;align-items:center;justify-content:center;color:rgba(230,230,230,.7);font-size:2rem;text-align:center;padding:1rem}
  .reports-chart-foot{padding:.55rem .8rem;border-top:1px solid rgba(255,255,255,.07);display:flex;justify-content:space-between;color:rgba(232,232,232,.72)}
</style>
<?php if($debug_errors): ?>
<div class="alert alert-danger mb-3">
  <div class="fw-semibold">Debug mode: reports_overview.php query errors detected</div>
  <ul class="mb-0 mt-2">
    <?php foreach($debug_errors as $err): ?>
      <li><?=h($err)?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="reports-shell">
  <div class="reports-head">
    <h2 class="reports-title">Reports Overview</h2>
    <div class="reports-top-controls">
      <span class="reports-pill">⬆ 11</span>
      <span class="reports-pill">📊 <?= (int)$tot_tasks ?></span>
      <span class="reports-pill">🗂</span>
    </div>
  </div>

  <div class="reports-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div class="d-flex gap-2 flex-wrap">
        <span class="reports-pill">SpeedX ▾</span>
        <span class="reports-pill">Year to Date ▾</span>
        <span class="reports-pill">▾</span>
        <span class="reports-toggle"></span>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <a class="text-decoration-none text-light" href="client_reports.php">Show All</a>
        <a class="btn btn-yellow btn-sm" href="client_reports.php">Export</a>
      </div>
    </div>

    <h3 class="section-title">Reports Overview</h3>
    <div class="row g-3 mb-4">
      <div class="col-md-6 col-xl-3"><div class="metric-card"><div class="metric-label">✍ Total Income</div><div class="metric-value">$<?= number_format($income, 0) ?></div></div></div>
      <div class="col-md-6 col-xl-3"><div class="metric-card"><div class="metric-label">📈 Total Profit</div><div class="metric-value">$<?= number_format($profit, 0) ?></div></div></div>
      <div class="col-md-6 col-xl-3"><div class="metric-card"><div class="metric-label">💳 Expenses</div><div class="metric-value">-$<?= number_format($expenses, 0) ?></div></div></div>
      <div class="col-md-6 col-xl-3"><div class="metric-card"><div class="metric-label">👤 Outstanding</div><div class="metric-value gold">$<?= number_format($outstanding, 0) ?></div></div></div>
    </div>

    <h3 class="section-title">Reports & Charts</h3>
    <div class="reports-chart-wrap">
      <div class="reports-chart-head">
        <div class="small text-muted">Quick Links</div>
        <div class="d-flex gap-2">
          <a class="btn btn-sm btn-outline-light" href="review_completed_tasks.php">CTO Review</a>
          <a class="btn btn-sm btn-outline-light" href="developer_performance.php">Developer</a>
          <a class="btn btn-sm btn-outline-light" href="task_activity_log.php">Activity</a>
        </div>
      </div>
      <div class="reports-chart-area">
        <div>
          <div>Reports & Charts</div>
          <div class="fs-5 mt-2">Area for dynamic charts and detailed reports of the system.</div>
        </div>
      </div>
      <div class="reports-chart-foot">
        <div>1–<?= min(46, max(1, $projects + $tot_tasks)) ?> of 46</div>
        <div>Projects: <?= $projects ?> · Open: <?= $open_tasks ?> · CTO: <?= $needs_cto ?></div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
