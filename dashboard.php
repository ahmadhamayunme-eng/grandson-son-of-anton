<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();

$role = auth_user()['role_name'] ?? '';
if (in_array($role, ['Developer', 'SEO'], true)) {
  redirect('dashboard_member.php');
}

$pdo = db();
$ws = auth_workspace_id();

function dash_safe_rows(PDO $pdo, string $sql, array $params = []): array {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
  } catch (Throwable $e) {
    return [];
  }
}

function dashboard_task_variant(string $status, bool $overdue): string {
  $s = strtolower($status);
  if ($overdue || strpos($s, 'block') !== false || strpos($s, 'hold') !== false) return 'crit';
  if (strpos($s, 'review') !== false || strpos($s, 'submitted') !== false) return 'review';
  if (strpos($s, 'approved') !== false || strpos($s, 'complete') !== false || strpos($s, 'done') !== false) return 'ontrack';
  return '';
}
function dashboard_micro_pill_class(string $status): string {
  $s = strtolower($status);
  if (strpos($s, 'progress') !== false) return 'progress';
  if (strpos($s, 'done') !== false || strpos($s, 'complete') !== false || strpos($s, 'approved') !== false) return 'done';
  return 'todo';
}
function dashboard_avatar_gradient(string $seed): string {
  $palette = [
    'linear-gradient(135deg,#22c55e,#16a34a)',
    'linear-gradient(135deg,#a855f7,#7c3aed)',
    'linear-gradient(135deg,#0ea5e9,#0284c7)',
    'linear-gradient(135deg,#f97316,#ea580c)',
    'linear-gradient(135deg,#ec4899,#be185d)',
    'linear-gradient(135deg,#3b82f6,#1d4ed8)',
    'linear-gradient(135deg,#14b8a6,#0f766e)',
    'linear-gradient(135deg,#facc15,#ca8a04)',
  ];
  $h = crc32($seed);
  return $palette[$h % count($palette)];
}

/**
 * 50 wry / motivational quotes for the dashboard header.
 * One is picked at random on each request via array_rand().
 */
function dashboard_random_quote(): string {
  static $quotes = [
    "If you're the smartest person in the room, you're in the wrong room. If you're the dumbest, also leave.",
    "The early bird gets the worm. The late bird avoids the guy who hands out tasks.",
    "Not all who wander are lost. Some are just bad at admitting they're lost.",
    "You miss 100% of the shots you don't take, but also 87% of the ones you do. Consider not taking shots.",
    "Do what you love and you'll never work a day in your life, because no one will pay you for it.",
    "Be yourself. Unless yourself is annoying. Then be someone else.",
    "The universe is under no obligation to make sense to you, and honestly, same.",
    "Follow your dreams. They're running away for a reason — chase them anyway.",
    "A wise man once said nothing. The room still called him difficult.",
    "If life gives you lemons, ask why life is giving you lemons. What does it want from you?",
    "Every expert was once a beginner. Every beginner was once a baby. Think about that.",
    "The best time to plant a tree was 20 years ago. The second best time is still 20 years ago. You had one job.",
    "Pain is temporary. Posting about pain is forever.",
    "The only way out is through — unless there's a window, in which case please use the window.",
    "Know thyself. Regret immediately.",
    "Comparison is the thief of joy, but also the engine of all progress. Pick your poison.",
    "What doesn't kill you makes you stronger. What almost kills you writes your personality.",
    "You are enough. You are also, apparently, too much. Somehow both are true simultaneously.",
    "Success is not final, failure is not fatal, and your GoodReads year-in-review is not real.",
    "A smooth sea never made a skilled sailor, but it did make for a really pleasant afternoon.",
    "Dream big. Sleep bigger.",
    "The truth will set you free, but first it will make everyone at dinner uncomfortable.",
    "Be the change you wish to see in the world, and then watch the world change back immediately.",
    "The greatest risk is not taking one, unless the risk is financial, then consult a professional.",
    "Wherever you go, there you are. This is the worst possible news for some people.",
    "Work smarter, not harder. Also harder. Also smarter and harder. Basically don't stop.",
    "Fall down seven times, get up eight. On the eighth time, consider staying down and reassessing.",
    "You can't pour from an empty cup. You also can't pour from a full cup without spilling. Cups are a trap.",
    "The journey of a thousand miles begins with a single step, and immediately a blister.",
    "Silence is golden. Duct tape is silver. One of these is more versatile.",
    "In the middle of difficulty lies opportunity, and also more difficulty. The difficulty does not end.",
    "If you can dream it, you can do it. If you can't stop dreaming it, that's a different kind of problem.",
    "Act as if what you do makes a difference. It does, but not always the way you intend.",
    "Shoot for the moon. Even if you miss, you'll be in cold airless void.",
    "Attitude is a little thing that makes a big difference, which is frustrating when you're tired.",
    "The secret of getting ahead is getting started. The secret of getting started is unclear.",
    "Don't watch the clock; do what it does. Keep going. Also spin.",
    "We are what we repeatedly do. Check your screen time report and reconsider.",
    "Life is 10% what happens to you and 90% how you respond, which is extremely bad news.",
    "Do not go where the path may lead. Go instead where there is no path, and then complain about it later.",
    "The mind is its own place and can make a heaven of hell or a hell of heaven. It has chosen poorly.",
    "You can't connect the dots looking forward. You can barely see them looking backward either.",
    "Hardship often prepares an ordinary person for an extraordinary destiny. Or just hardship. It's a coin flip.",
    "It does not matter how slowly you go, as long as you do not stop. Unless it's traffic. Speed up.",
    "Live as if you were to die tomorrow. Then call your bank to sort out that recurring charge.",
    "The only place where success comes before work is in the dictionary, and frankly the dictionary is showing off.",
    "Do one thing every day that scares you. Not that. Something else. Not that either.",
    "You have brains in your head and feet in your shoes. What you do with this combination remains to be seen.",
    "The cave you fear to enter holds the treasure you seek, and also, statistically, a lot of bats.",
    "At the end of the day, it's the end of the day. That's it. That's the wisdom.",
  ];
  return $quotes[array_rand($quotes)];
}

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) { $month = date('Y-m'); }
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$calStart = date('Y-m-d', strtotime('monday this week', strtotime($monthStart)));
$calEnd = date('Y-m-d', strtotime('sunday this week', strtotime($monthEnd)));
$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));
$today = date('Y-m-d');

$tasks = dash_safe_rows($pdo, "SELECT t.id, t.title, t.status, t.due_date, p.name AS project_name,
    GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS assignees
  FROM tasks t
  JOIN projects p ON p.id=t.project_id AND p.workspace_id=t.workspace_id
  LEFT JOIN task_assignees ta ON ta.task_id=t.id
  LEFT JOIN users u ON u.id=ta.user_id
  WHERE t.workspace_id=? AND t.due_date IS NOT NULL AND DATE(t.due_date) BETWEEN ? AND ?
  GROUP BY t.id, t.title, t.status, t.due_date, p.name
  ORDER BY t.due_date ASC, t.id DESC", [$ws, $calStart, $calEnd]);

$calendarMap = [];
foreach ($tasks as $t) {
  $d = date('Y-m-d', strtotime((string)$t['due_date']));
  if (!isset($calendarMap[$d])) $calendarMap[$d] = [];
  $calendarMap[$d][] = $t;
}

// "Working now" = any user with at least one open task assigned in this workspace.
// We intentionally only exclude truly terminal states (closed/cancelled/archived/done).
// Tasks in "Submitted to Client" or "Approved (Ready to Submit)" are still in-flight
// for the assignee — they may need revision, follow-up, or payment chase — so they count.
$workingNow = dash_safe_rows($pdo, "SELECT u.id, u.name,
    COUNT(DISTINCT ta.task_id) AS task_count
  FROM task_assignees ta
  JOIN users u ON u.id=ta.user_id
  JOIN tasks t ON t.id=ta.task_id AND t.workspace_id=?
  WHERE LOWER(t.status) NOT LIKE '%closed%'
    AND LOWER(t.status) NOT LIKE '%cancel%'
    AND LOWER(t.status) NOT LIKE '%archiv%'
    AND LOWER(t.status) NOT IN ('done','completed')
  GROUP BY u.id, u.name
  ORDER BY task_count DESC, u.name ASC
  LIMIT 8", [$ws]);

$workerTasks = [];
if ($workingNow) {
  $ids = array_column($workingNow, 'id');
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $rows = dash_safe_rows($pdo,
    "SELECT u.id AS uid, t.id, t.title, t.due_date
     FROM task_assignees ta
     JOIN users u ON u.id=ta.user_id
     JOIN tasks t ON t.id=ta.task_id AND t.workspace_id=?
     WHERE u.id IN ($in)
       AND LOWER(t.status) NOT LIKE '%closed%'
       AND LOWER(t.status) NOT LIKE '%cancel%'
       AND LOWER(t.status) NOT LIKE '%archiv%'
       AND LOWER(t.status) NOT IN ('done','completed')
     ORDER BY t.due_date IS NULL, t.due_date ASC
     LIMIT 200", array_merge([$ws], $ids));
  foreach ($rows as $r) { $workerTasks[(int)$r['uid']][] = $r; }
}

$dueSoon = dash_safe_rows($pdo, "SELECT t.id, t.title, t.status, t.due_date,
    GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS assignees
  FROM tasks t
  LEFT JOIN task_assignees ta ON ta.task_id=t.id
  LEFT JOIN users u ON u.id=ta.user_id
  WHERE t.workspace_id=? AND t.due_date IS NOT NULL AND DATE(t.due_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
  GROUP BY t.id, t.title, t.status, t.due_date
  ORDER BY t.due_date ASC
  LIMIT 10", [$ws]);

$riskBlockers = dash_safe_rows($pdo, "SELECT t.id, t.title, t.status, t.due_date,
    GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS assignees
  FROM tasks t
  LEFT JOIN task_assignees ta ON ta.task_id=t.id
  LEFT JOIN users u ON u.id=ta.user_id
  WHERE t.workspace_id=? AND (
    LOWER(t.status) LIKE '%blocked%' OR LOWER(t.status) LIKE '%hold%' OR LOWER(t.status) LIKE '%pending%' OR
    (t.due_date IS NOT NULL AND DATE(t.due_date) < CURDATE() AND t.status NOT IN ('Approved (Ready to Submit)','Submitted to Client'))
  )
  GROUP BY t.id, t.title, t.status, t.due_date
  ORDER BY (t.due_date IS NULL), t.due_date ASC
  LIMIT 10", [$ws]);

// Workload by team. Start from users (so empty teams still show), then LEFT JOIN tasks
// the team is currently working on. Same "active" definition as workingNow: only
// truly terminal statuses are excluded.
$workload = dash_safe_rows($pdo, "SELECT COALESCE(NULLIF(TRIM(r.name), ''), 'General') AS team,
    COUNT(DISTINCT ta.task_id) AS active_tasks,
    COUNT(DISTINCT u.id) AS members,
    ROUND((COUNT(DISTINCT ta.task_id) / NULLIF(COUNT(DISTINCT u.id) * 5, 0)) * 100, 0) AS capacity
  FROM users u
  LEFT JOIN roles r ON r.id=u.role_id
  LEFT JOIN task_assignees ta ON ta.user_id=u.id
  LEFT JOIN tasks t ON t.id=ta.task_id AND t.workspace_id=?
    AND LOWER(t.status) NOT LIKE '%closed%'
    AND LOWER(t.status) NOT LIKE '%cancel%'
    AND LOWER(t.status) NOT LIKE '%archiv%'
    AND LOWER(t.status) NOT IN ('done','completed')
  WHERE u.workspace_id=? AND u.is_active=1
  GROUP BY team
  ORDER BY active_tasks DESC, members DESC
  LIMIT 6", [$ws, $ws]);

$pageTitle = 'Dashboard';
$activeKey = 'dashboard';
$pageHeadExtra = <<<HTML
<style>
  .content { display: grid; grid-template-columns: 1fr 360px; gap: 28px; padding: 28px 32px 48px; max-width: 1640px; width: 100%; margin: 0 auto; }
  .page-header { padding: 22px 32px 4px; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; }
  .month-pager { display: inline-flex; align-items: center; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
  .month-pager a, .month-pager button { padding: 8px 12px; font-size: 12.5px; color: var(--text-muted); transition: all 0.15s; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; }
  .month-pager a:hover, .month-pager button:hover { background: var(--surface-2); color: var(--text); }
  .month-pager .month-label { padding: 8px 14px; font-size: 13px; font-weight: 600; color: var(--text); border-left: 1px solid var(--border); border-right: 1px solid var(--border); font-variant-numeric: tabular-nums; min-width: 96px; text-align: center; }
  .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  .panel-head { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 16px; }
  .panel-title { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text); }
  .panel-sub { font-size: 11.5px; color: var(--text-dim); font-family: 'JetBrains Mono', ui-monospace, monospace; }
  .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); background: var(--border); gap: 1px; }
  .cal-dow { background: var(--surface); padding: 10px 12px 8px; font-size: 10.5px; font-weight: 600; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.12em; }
  .cal-cell { background: var(--bg); min-height: 132px; padding: 8px 8px 10px; display: flex; flex-direction: column; gap: 6px; position: relative; }
  .cal-cell.dim { background: #060606; }
  .cal-cell.today { background: rgba(250,204,21,0.05); }
  .cal-cell.today::before { content: ''; position: absolute; inset: 0; border: 1px solid rgba(250,204,21,0.3); pointer-events: none; }
  .cal-date { display: flex; align-items: baseline; justify-content: space-between; padding: 0 4px; }
  .cal-day { font-size: 10.5px; color: var(--text-dim); font-family: 'JetBrains Mono', ui-monospace, monospace; letter-spacing: 0.04em; }
  .cal-cell.today .cal-day { color: var(--accent); font-weight: 600; }
  .cal-num { font-size: 11px; color: var(--text-muted); font-weight: 500; }
  .cal-empty { font-size: 11px; color: var(--text-dim); padding: 0 4px; font-style: italic; }
  .cal-task { display: block; background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--text-dim); border-radius: 6px; padding: 6px 8px; cursor: pointer; transition: all 0.12s; text-decoration: none; color: inherit; }
  .cal-task:hover { background: var(--surface-2); border-color: var(--border-strong); transform: translateX(1px); }
  .cal-task .t-title { font-size: 11.5px; font-weight: 500; color: var(--text); line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .cal-task .t-meta { font-size: 10px; color: var(--text-muted); margin-top: 3px; display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
  .cal-task .t-meta .sep { color: var(--text-dim); }
  .cal-task .t-state { font-size: 10px; font-weight: 600; }
  .cal-task.crit { border-left-color: var(--danger); }
  .cal-task.crit .t-state { color: #fca5a5; }
  .cal-task.review { border-left-color: var(--accent); }
  .cal-task.review .t-state { color: var(--accent); }
  .cal-task.ontrack { border-left-color: var(--success); }
  .cal-task.ontrack .t-state { color: #86efac; }
  .cal-more { font-size: 10.5px; color: var(--text-muted); padding: 2px 4px; cursor: pointer; border-radius: 4px; transition: all 0.12s; }
  .cal-more:hover { color: var(--text); background: var(--surface-2); }
  .cal-foot { display: flex; align-items: center; gap: 18px; padding: 14px 20px; border-top: 1px solid var(--border); flex-wrap: wrap; }
  .legend { display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--text-muted); }
  .legend .swatch { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .rail { display: flex; flex-direction: column; gap: 18px; }
  .rail-panel-head { padding: 14px 18px 10px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border); }
  .rail-panel-title { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text); }
  .rail-panel-count { font-size: 10.5px; background: var(--surface-2); color: var(--text-muted); padding: 2px 8px; border-radius: 999px; font-variant-numeric: tabular-nums; }
  .rail-panel-body { padding: 6px 6px 8px; }
  .worker { padding: 12px 14px; border-radius: 10px; transition: background 0.12s; }
  .worker:hover { background: var(--bg); }
  .worker + .worker { border-top: 1px solid var(--border); border-radius: 0; }
  .worker:hover + .worker { border-top-color: transparent; }
  .worker-head { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
  .worker .avatar { width: 28px; height: 28px; font-size: 10.5px; }
  .worker-name { font-size: 13px; font-weight: 600; color: var(--text); }
  .worker-count { font-size: 10.5px; color: var(--text-dim); margin-left: auto; font-variant-numeric: tabular-nums; font-family: 'JetBrains Mono', ui-monospace, monospace; }
  .worker-tasks { font-size: 12px; color: var(--text-muted); line-height: 1.55; padding-left: 38px; }
  .worker-tasks a { color: var(--text); border-bottom: 1px solid var(--border-strong); transition: border-color 0.15s; }
  .worker-tasks a:hover { border-bottom-color: var(--accent); }
  .worker-tasks .when { color: var(--text-dim); font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 10.5px; }
  .worker-tasks .dot { color: var(--text-dim); margin: 0 4px; }
  .due-row, .risk-row { padding: 12px 16px; border-radius: 10px; transition: background 0.12s; display: flex; flex-direction: column; gap: 4px; cursor: pointer; text-decoration: none; color: inherit; }
  .due-row + .due-row, .risk-row + .risk-row { border-top: 1px solid var(--border); border-radius: 0; }
  .due-row:hover, .risk-row:hover { background: var(--bg); }
  .due-row:hover + .due-row, .risk-row:hover + .risk-row { border-top-color: transparent; }
  .due-title, .risk-title { font-size: 13px; font-weight: 500; color: var(--text); line-height: 1.4; }
  .due-meta, .risk-meta { font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-variant-numeric: tabular-nums; }
  .due-meta .date { color: var(--text-dim); font-family: 'JetBrains Mono', ui-monospace, monospace; }
  .due-meta .sep, .risk-meta .sep { color: var(--text-dim); }
  .micro-pill { display: inline-flex; align-items: center; gap: 4px; padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 500; border: 1px solid var(--border); background: var(--surface); color: var(--text-muted); }
  .micro-pill .dot { width: 5px; height: 5px; border-radius: 50%; }
  .micro-pill.todo { color: #d1d5db; }
  .micro-pill.todo .dot { background: #6b7280; }
  .micro-pill.progress { color: var(--accent); border-color: rgba(250,204,21,0.25); background: var(--accent-soft); }
  .micro-pill.progress .dot { background: var(--accent); }
  .micro-pill.done { color: #86efac; border-color: rgba(34,197,94,0.25); background: var(--success-soft); }
  .micro-pill.done .dot { background: var(--success); }
  .risk-badge { width: 8px; height: 8px; border-radius: 50%; background: var(--danger); box-shadow: 0 0 0 3px rgba(239,68,68,0.18); flex-shrink: 0; }
  .risk-row { padding-left: 36px; position: relative; }
  .risk-row .risk-badge { position: absolute; left: 16px; top: 17px; }
  .empty-card { padding: 28px 16px; text-align: center; color: var(--text-dim); font-size: 12.5px; font-style: italic; }
  .workload-row { padding: 12px 16px; }
  .workload-row + .workload-row { border-top: 1px solid var(--border); }
  .workload-head { display: flex; justify-content: space-between; font-size: 12.5px; color: var(--text); margin-bottom: 6px; }
  .workload-head .lbl { font-weight: 500; }
  .workload-head .meta { color: var(--text-dim); font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 11px; }
  .workload-bar { height: 6px; background: var(--surface-2); border-radius: 999px; overflow: hidden; }
  .workload-bar > span { display: block; height: 100%; background: linear-gradient(90deg, var(--accent), var(--success)); border-radius: 999px; }
  .workload-meta { font-size: 11px; color: var(--text-dim); margin-top: 4px; font-variant-numeric: tabular-nums; }
  @media (max-width: 1280px) { .content { grid-template-columns: 1fr; } }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Hello <?= h(auth_user()['name'] ?? 'there') ?></div>
      <div class="page-sub" style="font-style:italic;">&ldquo;<?= h(dashboard_random_quote()) ?>&rdquo;</div>
    </div>
  </div>
  <div class="month-pager">
    <a href="?month=<?= h($prevMonth) ?>" title="Previous month">
      <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
      Prev
    </a>
    <span class="month-label"><?= h(date('F Y', strtotime($monthStart))) ?></span>
    <a href="?month=<?= h($nextMonth) ?>" title="Next month">
      Next
      <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
    </a>
  </div>
</div>

<div class="content">
  <div class="panel">
    <div class="panel-head">
      <div><div class="panel-title">Monthly Task Calendar</div></div>
      <div class="panel-sub"><?= h(date('F Y', strtotime($monthStart))) ?> · Week starts Monday</div>
    </div>

    <div class="cal-grid">
      <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dn): ?>
        <div class="cal-dow"><?= h($dn) ?></div>
      <?php endforeach; ?>

      <?php for ($d = strtotime($calStart); $d <= strtotime($calEnd); $d = strtotime('+1 day', $d)):
        $key = date('Y-m-d', $d);
        $inMonth = (date('Y-m', $d) === $month);
        $isToday = ($key === $today);
        $cls = 'cal-cell';
        if (!$inMonth) $cls .= ' dim';
        if ($isToday) $cls .= ' today';
        $items = $calendarMap[$key] ?? [];
      ?>
        <div class="<?= $cls ?>">
          <div class="cal-date">
            <span class="cal-day"><?= h(date('M j', $d)) ?></span>
            <?php if ($isToday): ?><span class="cal-num">Today</span><?php endif; ?>
          </div>
          <?php if (empty($items)): ?>
            <div class="cal-empty">No tasks</div>
          <?php else:
            $shown = array_slice($items, 0, 3);
            foreach ($shown as $e):
              $overdue = strtotime((string)$e['due_date']) < strtotime($today) && !in_array($e['status'], ['Approved (Ready to Submit)','Submitted to Client'], true);
              $variant = dashboard_task_variant((string)$e['status'], $overdue);
              $taskClass = 'cal-task' . ($variant ? ' ' . $variant : '');
              $stateLabel = $e['status'];
              if ($overdue) $stateLabel = $e['status'] . ' · Overdue';
          ?>
            <a class="<?= $taskClass ?>" href="task_view.php?id=<?= (int)$e['id'] ?>">
              <div class="t-title"><?= h($e['title']) ?></div>
              <div class="t-meta">
                <?= h($e['assignees'] ?: 'Unassigned') ?>
                <span class="sep">·</span>
                <span class="t-state"><?= h($stateLabel) ?></span>
              </div>
            </a>
          <?php endforeach; if (count($items) > 3): ?>
            <span class="cal-more">+<?= count($items) - 3 ?> more</span>
          <?php endif; endif; ?>
        </div>
      <?php endfor; ?>
    </div>

    <div class="cal-foot">
      <span class="legend"><span class="swatch" style="background:var(--danger);"></span>Critical deadline</span>
      <span class="legend"><span class="swatch" style="background:var(--accent);"></span>Needs review</span>
      <span class="legend"><span class="swatch" style="background:var(--success);"></span>On track</span>
      <span class="legend"><span class="swatch" style="background:#818cf8;"></span>Finance / Admin</span>
      <span class="legend"><span class="swatch" style="background:#38bdf8;"></span>Client milestone</span>
    </div>
  </div>

  <aside class="rail">
    <div class="panel">
      <div class="rail-panel-head">
        <span class="rail-panel-title">Who is Working on What</span>
        <span class="rail-panel-count"><?= count($workingNow) ?> active</span>
      </div>
      <div class="rail-panel-body">
        <?php if (!$workingNow): ?>
          <div class="empty-card">No active assignments.</div>
        <?php else: foreach ($workingNow as $w):
          $tasksForUser = $workerTasks[(int)$w['id']] ?? [];
          $initials = user_initials((string)$w['name']);
          $grad = dashboard_avatar_gradient((string)$w['name']);
        ?>
          <div class="worker">
            <div class="worker-head">
              <div class="avatar" style="background:<?= h($grad) ?>"><?= h($initials) ?></div>
              <span class="worker-name"><?= h($w['name']) ?></span>
              <span class="worker-count"><?= (int)$w['task_count'] ?> task<?= ((int)$w['task_count'] === 1 ? '' : 's') ?></span>
            </div>
            <?php if ($tasksForUser): ?>
              <div class="worker-tasks">
                <?php foreach ($tasksForUser as $i => $t):
                  if ($i > 0) echo ' <span class="dot">·</span> ';
                ?>
                  <a href="task_view.php?id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a>
                  <?php if (!empty($t['due_date'])): ?>
                    <span class="when">(<?= h(date('M j', strtotime((string)$t['due_date']))) ?>)</span>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="rail-panel-head">
        <span class="rail-panel-title">Due Soon · Next 7 Days</span>
        <span class="rail-panel-count"><?= count($dueSoon) ?></span>
      </div>
      <div class="rail-panel-body">
        <?php if (!$dueSoon): ?>
          <div class="empty-card">No upcoming deadlines in the next 7 days.</div>
        <?php else: foreach ($dueSoon as $d):
          $cls = dashboard_micro_pill_class((string)$d['status']);
        ?>
          <a class="due-row" href="task_view.php?id=<?= (int)$d['id'] ?>">
            <span class="due-title"><?= h($d['title']) ?></span>
            <span class="due-meta">
              <span class="date"><?= h(format_date((string)$d['due_date'])) ?></span>
              <span class="sep">·</span>
              <?= h($d['assignees'] ?: 'Unassigned') ?>
              <span class="sep">·</span>
              <span class="micro-pill <?= $cls ?>"><span class="dot"></span><?= h($d['status']) ?></span>
            </span>
          </a>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="rail-panel-head">
        <span class="rail-panel-title">Risk &amp; Blockers</span>
        <span class="rail-panel-count"><?= count($riskBlockers) ?></span>
      </div>
      <div class="rail-panel-body">
        <?php if (!$riskBlockers): ?>
          <div class="empty-card">No blocked or at-risk tasks right now.</div>
        <?php else: foreach ($riskBlockers as $r):
          $cls = dashboard_micro_pill_class((string)$r['status']);
        ?>
          <a class="risk-row" href="task_view.php?id=<?= (int)$r['id'] ?>">
            <span class="risk-badge"></span>
            <span class="risk-title"><?= h($r['title']) ?></span>
            <span class="risk-meta">
              <span class="micro-pill <?= $cls ?>"><span class="dot"></span><?= h($r['status']) ?></span>
              <?php if (!empty($r['due_date'])): ?>
                <span class="sep">·</span>Due <?= h(format_date((string)$r['due_date'])) ?>
              <?php endif; ?>
              <span class="sep">·</span><?= h($r['assignees'] ?: 'Unassigned') ?>
            </span>
          </a>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="rail-panel-head">
        <span class="rail-panel-title">Workload Snapshot</span>
      </div>
      <div class="rail-panel-body">
        <?php if (!$workload): ?>
          <div class="empty-card">No workload data available.</div>
        <?php else: foreach ($workload as $wl): $cap = max(0, min(100, (int)$wl['capacity'])); ?>
          <div class="workload-row">
            <div class="workload-head">
              <span class="lbl"><?= h($wl['team']) ?></span>
              <span class="meta"><?= (int)$wl['active_tasks'] ?> tasks · <?= (int)$wl['members'] ?> people</span>
            </div>
            <div class="workload-bar"><span style="width:<?= $cap ?>%"></span></div>
            <div class="workload-meta">Estimated capacity: <?= $cap ?>%</div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </aside>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
