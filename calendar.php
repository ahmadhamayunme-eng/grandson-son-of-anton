<?php
// Calendar / timeline view (item #20): a full-page month grid of tasks by due
// date, grouped per day and coloured by status. Reuses the month-grid maths
// the dashboard panel already uses; this is the dedicated, larger surface.
require_once __DIR__ . '/layout.php';
$pdo = db();
$ws  = auth_workspace_id();

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) { $month = date('Y-m'); }
$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$calStart   = date('Y-m-d', strtotime('monday this week', strtotime($monthStart)));
$calEnd     = date('Y-m-d', strtotime('sunday this week', strtotime($monthEnd)));
$prevMonth  = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth  = date('Y-m', strtotime($monthStart . ' +1 month'));
$today      = date('Y-m-d');

$tasks = [];
try {
  $st = $pdo->prepare("SELECT t.id, t.title, t.status, t.due_date, p.name AS project_name
    FROM tasks t JOIN projects p ON p.id=t.project_id AND p.workspace_id=t.workspace_id
    WHERE t.workspace_id=? AND t.due_date IS NOT NULL AND DATE(t.due_date) BETWEEN ? AND ?
    ORDER BY t.due_date ASC, t.id DESC");
  $st->execute([$ws, $calStart, $calEnd]);
  $tasks = $st->fetchAll();
} catch (Throwable $e) {
  app_log('calendar.tasks', $e);
}

$byDay = [];
foreach ($tasks as $t) {
  $day = date('Y-m-d', strtotime((string)$t['due_date']));
  $byDay[$day][] = $t;
}

function cal_status_class(string $status): string {
  $s = strtolower($status);
  if (str_contains($s, 'submit') || str_contains($s, 'approved') || str_contains($s, 'done') || str_contains($s, 'complete')) return 'ok';
  if (str_contains($s, 'progress') || str_contains($s, 'review')) return 'wip';
  return 'todo';
}

$pageTitle = 'Calendar';
$activeKey = 'calendar';
$pageHeadExtra = <<<HTML
<style>
  .cal-wrap { max-width: 1100px; }
  .cal-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
  .cal-title { font-size:22px; font-weight:700; }
  .cal-nav { display:flex; gap:8px; align-items:center; }
  .cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:6px; }
  .cal-dow { font-size:11px; text-transform:uppercase; letter-spacing:0.08em; color:var(--text-dim); text-align:center; padding:6px 0; }
  .cal-cell { min-height:104px; background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:6px; display:flex; flex-direction:column; gap:4px; }
  .cal-cell.dim { opacity:0.45; }
  .cal-cell.today { border-color:var(--accent); box-shadow:0 0 0 2px rgba(250,204,21,0.18); }
  .cal-daynum { font-size:12px; color:var(--text-muted); font-weight:600; }
  .cal-task { font-size:11.5px; line-height:1.25; padding:3px 6px; border-radius:6px; text-decoration:none; color:var(--text); background:var(--surface-2); border-left:3px solid var(--border-strong); display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .cal-task.ok   { border-left-color:#22c55e; }
  .cal-task.wip  { border-left-color:#facc15; }
  .cal-task.todo { border-left-color:#ef4444; }
  .cal-task:hover { background:var(--surface-hover); }
  .cal-more { font-size:11px; color:var(--text-dim); padding:2px 6px; }
  @media (max-width:768px){
    .cal-cell { min-height:72px; }
    .cal-dow:nth-child(n){ font-size:10px; }
  }
</style>
HTML;
?>
<div class="cal-wrap">
  <div class="cal-head">
    <div>
      <?= back_button_html() ?>
      <div class="cal-title"><?= h(date('F Y', strtotime($monthStart))) ?></div>
    </div>
    <div class="cal-nav">
      <a class="btn btn-ghost btn-sm" href="?month=<?= h($prevMonth) ?>">‹ Prev</a>
      <a class="btn btn-ghost btn-sm" href="?month=<?= h(date('Y-m')) ?>">Today</a>
      <a class="btn btn-ghost btn-sm" href="?month=<?= h($nextMonth) ?>">Next ›</a>
    </div>
  </div>

  <div class="cal-grid">
    <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dow): ?>
      <div class="cal-dow"><?= $dow ?></div>
    <?php endforeach; ?>

    <?php
    for ($d = $calStart; $d <= $calEnd; $d = date('Y-m-d', strtotime($d . ' +1 day'))):
      $inMonth = (date('Y-m', strtotime($d)) === $month);
      $isToday = ($d === $today);
      $dayTasks = $byDay[$d] ?? [];
    ?>
      <div class="cal-cell<?= $inMonth ? '' : ' dim' ?><?= $isToday ? ' today' : '' ?>">
        <div class="cal-daynum"><?= (int)date('j', strtotime($d)) ?></div>
        <?php foreach (array_slice($dayTasks, 0, 4) as $t): ?>
          <a class="cal-task <?= cal_status_class((string)$t['status']) ?>"
             href="task_view.php?id=<?= (int)$t['id'] ?>"
             title="<?= h($t['title']) ?> · <?= h($t['project_name']) ?> · <?= h($t['status']) ?>">
            <?= h($t['title']) ?>
          </a>
        <?php endforeach; ?>
        <?php if (count($dayTasks) > 4): ?>
          <span class="cal-more">+<?= count($dayTasks) - 4 ?> more</span>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
