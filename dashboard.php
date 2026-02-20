<?php
require_once __DIR__ . '/layout.php';
$pdo = db();
$ws = auth_workspace_id();

$clients = (int)$pdo->query("SELECT COUNT(*) c FROM clients WHERE workspace_id=$ws")->fetch()['c'];
$projects = (int)$pdo->query("SELECT COUNT(*) c FROM projects WHERE workspace_id=$ws")->fetch()['c'];
$tasks = (int)$pdo->query("SELECT COUNT(*) c FROM tasks WHERE workspace_id=$ws AND status IN ('To Do','In Progress','Completed (Needs CTO Review)','Approved (Ready to Submit)')")->fetch()['c'];
$pendingInvoices = max(0, (int)round($projects * 0.45));
$monthlyRevenue = ($projects * 4250) + ($tasks * 180);

$taskSt = $pdo->prepare('SELECT t.id, t.title, t.status, p.name AS project_name
  FROM tasks t
  JOIN projects p ON p.id=t.project_id
  WHERE t.workspace_id=?
  ORDER BY t.updated_at DESC, t.id DESC
  LIMIT 4');
$taskSt->execute([$ws]);
$myTasks = $taskSt->fetchAll();

$projectSt = $pdo->prepare('SELECT p.id, p.name, c.name AS client_name, ps.name AS status_name
  FROM projects p
  JOIN clients c ON c.id=p.client_id
  JOIN project_statuses ps ON ps.id=p.status_id
  WHERE p.workspace_id=?
  ORDER BY p.updated_at DESC, p.id DESC
  LIMIT 4');
$projectSt->execute([$ws]);
$recentProjects = $projectSt->fetchAll();

function status_chip(string $status): string {
  $s = strtolower($status);
  if (str_contains($s, 'progress') || str_contains($s, 'active')) return 'chip-yellow';
  if (str_contains($s, 'approved') || str_contains($s, 'complete')) return 'chip-green';
  if (str_contains($s, 'hold') || str_contains($s, 'pending')) return 'chip-red';
  return 'chip-purple';
}
?>
<style>
  .dashboard-shell {
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 18px;
    background: linear-gradient(130deg, rgba(14,16,24,.93), rgba(9,11,16,.95));
    box-shadow: 0 28px 70px rgba(0,0,0,.42);
    padding: 22px;
  }
  .dashboard-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
  .dashboard-title { margin: 0; font-size: 2rem; font-weight: 600; }
  .top-icons { display: flex; align-items: center; gap: 12px; color: rgba(236,236,240,.76); }
  .top-dot { width: 24px; height: 24px; border-radius: 50%; background: #7f6dff; display: inline-block; }
  .kpi-card {
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 12px;
    background: linear-gradient(110deg, rgba(23,25,36,.92), rgba(14,16,24,.9));
    padding: 14px 16px;
    min-height: 98px;
  }
  .kpi-label { color: rgba(236,236,240,.72); font-size: .9rem; margin-bottom: 4px; }
  .kpi-value { font-size: 2rem; font-weight: 600; line-height: 1.1; }
  .kpi-icon { color: #f6d469; margin-right: 8px; }
  .kpi-red .kpi-icon { color: #f3797e; }
  .kpi-green .kpi-icon { color: #55cb90; }
  .kpi-change { font-size: .95rem; margin-left: 6px; color: #55cb90; }
  .panel {
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 12px;
    background: linear-gradient(120deg, rgba(24,26,37,.88), rgba(16,18,27,.88));
  }
  .panel-head {
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 1.6rem;
    font-weight: 600;
  }
  .task-row, .project-row { display: flex; justify-content: space-between; gap: 14px; padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,.06); }
  .task-row:last-child, .project-row:last-child { border-bottom: 0; }
  .task-title, .project-title { color: #f0f0f3; text-decoration: none; font-weight: 500; font-size: 1.15rem; }
  .task-meta, .project-meta { color: rgba(236,236,240,.62); font-size: .92rem; }
  .chip { border-radius: 999px; padding: 3px 10px; font-size: .78rem; border: 1px solid transparent; align-self: center; }
  .chip-yellow { background: rgba(246,212,105,.14); border-color: rgba(246,212,105,.36); color: #f6d469; }
  .chip-green { background: rgba(85,203,144,.14); border-color: rgba(85,203,144,.3); color: #7ae7af; }
  .chip-red { background: rgba(243,111,117,.14); border-color: rgba(243,111,117,.34); color: #ff9aa0; }
  .chip-purple { background: rgba(139,107,255,.16); border-color: rgba(139,107,255,.32); color: #b9a2ff; }
  .chart-wrap { padding: 8px 14px 14px; }
  .chart-legend { display: flex; gap: 18px; padding: 8px 16px 14px; color: rgba(236,236,240,.72); font-size: .9rem; }
  .legend-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 7px; }
  .legend-yellow { background: #f6d469; }
  .legend-purple { background: #8b6bff; }
  .finance-box { padding: 14px 16px; }
  .finance-line { border-bottom: 1px solid rgba(255,255,255,.08); padding: 11px 0; }
  .finance-label { color: rgba(236,236,240,.68); }
  .finance-value { font-size: 2rem; font-weight: 600; line-height: 1.2; }
</style>

<div class="dashboard-shell">
  <div class="dashboard-top">
    <h1 class="dashboard-title">Dashboard</h1>
    <div class="top-icons"><span class="top-dot"></span>◔◔◔◔ ↻ ⋮</div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-3 col-sm-6"><div class="kpi-card"><div class="kpi-label"><span class="kpi-icon">▣</span>Active Tasks</div><div class="kpi-value"><?=h($tasks)?></div></div></div>
    <div class="col-lg-3 col-sm-6"><div class="kpi-card"><div class="kpi-label"><span class="kpi-icon">◫</span>Active Projects</div><div class="kpi-value"><?=h($projects)?></div></div></div>
    <div class="col-lg-3 col-sm-6"><div class="kpi-card kpi-green"><div class="kpi-label"><span class="kpi-icon">↗</span>Monthly Revenue</div><div class="kpi-value">$<?=number_format($monthlyRevenue)?><span class="kpi-change">+8.2%</span></div></div></div>
    <div class="col-lg-3 col-sm-6"><div class="kpi-card kpi-red"><div class="kpi-label"><span class="kpi-icon">✉</span>Pending Invoices</div><div class="kpi-value">$<?=number_format($pendingInvoices * 1250)?><span class="kpi-change" style="color:#f3797e">-21%</span></div></div></div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <section class="panel h-100">
        <div class="panel-head">My Tasks</div>
        <?php if (!$myTasks): ?>
          <div class="p-3 text-muted">No tasks yet.</div>
        <?php else: foreach ($myTasks as $t): ?>
          <article class="task-row">
            <div>
              <a class="task-title" href="task_view.php?id=<?=$t['id']?>">◉ <?=h($t['title'])?></a>
              <div class="task-meta"><?=h($t['project_name'])?></div>
            </div>
            <span class="chip <?=status_chip($t['status'])?>"><?=h($t['status'])?></span>
          </article>
        <?php endforeach; endif; ?>
      </section>
    </div>
    <div class="col-lg-6">
      <section class="panel h-100">
        <div class="panel-head">Weekly Report <span class="chip chip-purple">Weekly ▾</span></div>
        <div class="chart-wrap">
          <svg viewBox="0 0 560 210" width="100%" height="210" role="img" aria-label="Weekly report chart">
            <line x1="0" y1="190" x2="560" y2="190" stroke="rgba(255,255,255,.12)" />
            <line x1="0" y1="145" x2="560" y2="145" stroke="rgba(255,255,255,.08)" />
            <line x1="0" y1="100" x2="560" y2="100" stroke="rgba(255,255,255,.08)" />
            <line x1="0" y1="55" x2="560" y2="55" stroke="rgba(255,255,255,.08)" />
            <polyline fill="none" stroke="#f6d469" stroke-width="4" points="0,120 70,40 140,105 205,110 270,160 340,90 410,130 475,95 520,70 560,40" />
            <polyline fill="none" stroke="#8b6bff" stroke-width="4" points="0,170 60,150 130,150 195,185 265,160 335,145 405,170 470,120 525,130 560,108" />
          </svg>
        </div>
        <div class="chart-legend">
          <span><span class="legend-dot legend-yellow"></span>Active Tasks</span>
          <span><span class="legend-dot legend-purple"></span>Client Activity</span>
        </div>
      </section>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <section class="panel h-100">
        <div class="panel-head">Recent Projects</div>
        <?php if (!$recentProjects): ?>
          <div class="p-3 text-muted">No projects yet.</div>
        <?php else: foreach ($recentProjects as $p): ?>
          <article class="project-row">
            <div>
              <a class="project-title" href="project_view.php?id=<?=$p['id']?>"><?=h($p['name'])?></a>
              <div class="project-meta"><?=h($p['client_name'])?></div>
            </div>
            <span class="chip <?=status_chip($p['status_name'])?>"><?=h($p['status_name'])?></span>
          </article>
        <?php endforeach; endif; ?>
      </section>
    </div>
    <div class="col-lg-4">
      <section class="panel h-100">
        <div class="panel-head">Finance Overview</div>
        <div class="finance-box">
          <div class="finance-line">
            <div class="finance-label">Current Balance</div>
            <div class="finance-value">$<?=number_format($monthlyRevenue)?></div>
          </div>
          <div class="finance-line">
            <div class="finance-label">Payments Received</div>
            <div class="finance-value">$<?=number_format((int)round($monthlyRevenue * 1.49))?></div>
          </div>
          <div class="finance-line">
            <div class="finance-label">Unreceived Payments</div>
            <div class="finance-value">$<?=number_format((int)round($monthlyRevenue * 0.21))?></div>
          </div>
          <div class="pt-3 text-muted">Clients in workspace: <?=h($clients)?></div>
        </div>
      </section>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
