<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();

$stmt=$pdo->prepare("SELECT u.name, u.email,
  SUM(CASE WHEN t.status='Approved (Ready to Submit)' THEN 1 ELSE 0 END) AS approved,
  SUM(CASE WHEN t.status='Submitted to Client' THEN 1 ELSE 0 END) AS submitted,
  COUNT(*) AS total
  FROM task_assignees ta
  JOIN users u ON u.id=ta.user_id
  JOIN tasks t ON t.id=ta.task_id
  WHERE t.workspace_id=?
  GROUP BY u.id
  ORDER BY submitted DESC, approved DESC, total DESC");
$stmt->execute([$ws]);
$rows=$stmt->fetchAll();

// Chart data (item #19): top 12 contributors by total assigned.
$chartRows = array_slice($rows, 0, 12);
$chartLabels = array_map(fn($r) => (string)$r['name'], $chartRows);
$chartApproved = array_map(fn($r) => (int)$r['approved'], $chartRows);
$chartSubmitted = array_map(fn($r) => (int)$r['submitted'], $chartRows);
?>
<div class="d-flex align-items-center justify-content-between mb-3" style="gap:12px;flex-wrap:wrap;">
  <h2 class="mb-0">Developer Performance</h2>
  <div style="display:flex;gap:8px;">
    <a class="btn btn-ghost btn-sm" href="reports_export.php?type=developer_performance">⬇ Export CSV</a>
    <button type="button" class="btn btn-ghost btn-sm" onclick="window.print()">🖶 Print / PDF</button>
  </div>
</div>

<?php if ($chartRows): ?>
<div class="card p-3 mb-3">
  <canvas id="perfChart" height="110"></canvas>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  (function(){
    var el = document.getElementById('perfChart');
    if (!el || !window.Chart) return;
    var css = getComputedStyle(document.documentElement);
    var textColor = (css.getPropertyValue('--text-muted') || '#9a9a9a').trim();
    new Chart(el, {
      type: 'bar',
      data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [
          { label: 'Submitted', data: <?= json_encode($chartSubmitted) ?>, backgroundColor: '#22c55e' },
          { label: 'Approved',  data: <?= json_encode($chartApproved) ?>,  backgroundColor: '#facc15' }
        ]
      },
      options: {
        responsive: true,
        plugins: { legend: { labels: { color: textColor } } },
        scales: {
          x: { stacked: true, ticks: { color: textColor }, grid: { display: false } },
          y: { stacked: true, beginAtZero: true, ticks: { color: textColor, precision: 0 }, grid: { color: 'rgba(255,255,255,0.06)' } }
        }
      }
    });
  })();
</script>
<?php endif; ?>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-dark table-hover align-middle mb-0">
      <thead><tr><th>User</th><th>Approved</th><th>Submitted</th><th>Total Assigned</th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= h($r['name']).' <span class="text-muted small">('.h($r['email']).')</span>' ?></td>
          <td><?= (int)$r['approved'] ?></td>
          <td><?= (int)$r['submitted'] ?></td>
          <td><?= (int)$r['total'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="4" class="text-muted">No data yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
