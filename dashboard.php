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
$user = auth_user();
$canManage = in_array($role, ['CEO', 'Manager', 'Super Admin'], true);
$canFinalStatus = $canManage;

function dash_task_statuses(PDO $pdo, int $ws, bool $can_final): array {
  try {
    $st = $pdo->prepare('SELECT name FROM task_statuses WHERE workspace_id = ? ORDER BY sort_order ASC');
    $st->execute([$ws]);
    $list = array_map(fn($r) => $r['name'], $st->fetchAll());
  } catch (Throwable $e) { $list = []; }
  if (!$list) $list = ['To Do', 'In Progress', 'Completed', 'Approved', 'Submitted to Client'];
  if (!$can_final) {
    $list = array_values(array_filter($list, fn($s) => !in_array($s, ['Approved', 'Approved (Ready to Submit)', 'Submitted to Client'], true)));
  }
  return $list;
}

// Handle quick-create POSTs from the dashboard buttons before any other rendering.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
  if (isset($_POST['add_task_dashboard'])) {
    require_post();
    csrf_verify();
    $title    = trim((string)($_POST['title'] ?? ''));
    $clientId = (int)($_POST['client_id'] ?? 0);
    $projectId = (int)($_POST['project_id'] ?? 0);
    $phaseId  = (int)($_POST['phase_id'] ?? 0);
    $desc     = trim((string)($_POST['description'] ?? ''));
    $status   = trim((string)($_POST['status'] ?? 'To Do'));
    $due      = trim((string)($_POST['due_date'] ?? ''));

    if ($title === '' || $projectId <= 0 || $phaseId <= 0) {
      flash_set('error', 'Task title, project, and phase are required.');
      redirect('dashboard.php');
    }
    // Verify the project + phase belong to this workspace (and phase to this project).
    $st = $pdo->prepare('SELECT p.id FROM projects p WHERE p.id=? AND p.workspace_id=? AND p.client_id=?');
    $st->execute([$projectId, $ws, $clientId]);
    if (!$st->fetchColumn()) { flash_set('error', 'Invalid project selection.'); redirect('dashboard.php'); }
    $st = $pdo->prepare('SELECT id FROM phases WHERE id=? AND project_id=? AND workspace_id=?');
    $st->execute([$phaseId, $projectId, $ws]);
    if (!$st->fetchColumn()) { flash_set('error', 'Invalid phase selection.'); redirect('dashboard.php'); }

    $allowed = dash_task_statuses($pdo, $ws, $canFinalStatus);
    if (!in_array($status, $allowed, true)) $status = $allowed[0] ?? 'To Do';

    $pdo->prepare('INSERT INTO tasks (workspace_id,project_id,phase_id,title,description,status,due_date,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$ws, $projectId, $phaseId, $title, $desc ?: null, $status, $due ?: null, (int)$user['id'], now(), now()]);
    $taskId = (int)$pdo->lastInsertId();
    foreach (($_POST['assignees'] ?? []) as $uid) {
      $pdo->prepare('INSERT INTO task_assignees (task_id,user_id) VALUES (?,?)')->execute([$taskId, (int)$uid]);
    }
    // Anton Jr. — sync project channel members for the new assignees.
    if (file_exists(__DIR__ . '/chat/includes/project_sync.php')) {
      require_once __DIR__ . '/chat/includes/project_sync.php';
      try { chat_project_sync((int)$projectId); } catch (Throwable $e) {}
    }
    if (file_exists(__DIR__ . '/chat/includes/notifications.php')) {
      require_once __DIR__ . '/chat/includes/notifications.php';
      foreach (($_POST['assignees'] ?? []) as $uid) {
        try { chat_notify_task_assigned($taskId, (int)$uid, (int)$user['id']); } catch (Throwable $e) {}
      }
    }
    flash_set('success', 'Task created.');
    redirect('task_view.php?id=' . $taskId);
  }

  if (isset($_POST['add_project_dashboard'])) {
    require_post();
    csrf_verify();
    // Mirror the projects.php handler so the dashboard "Add Project" is functionally equivalent.
    $name              = trim((string)($_POST['name'] ?? ''));
    $clientId          = (int)($_POST['client_id'] ?? 0);
    $typeId            = (int)($_POST['type_id'] ?? 0);
    $statusId          = (int)($_POST['status_id'] ?? 0);
    $dueDate           = trim((string)($_POST['due_date'] ?? ''));
    $pricingModel      = trim((string)($_POST['pricing_model'] ?? 'fixed_price'));
    $projectPrice      = ($_POST['project_price'] ?? '') !== '' ? (float)$_POST['project_price'] : null;
    $paymentTerms      = trim((string)($_POST['payment_terms'] ?? ''));
    $projectHourlyRate = ($_POST['project_hourly_rate'] ?? '') !== '' ? (float)$_POST['project_hourly_rate'] : null;
    $wlNotes           = trim((string)($_POST['wl_notes'] ?? ''));

    $isValidUrl = function (string $value): bool {
      if ($value === '') return true;
      if (!filter_var($value, FILTER_VALIDATE_URL)) return false;
      $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
      return in_array($scheme, ['http', 'https'], true);
    };

    $rawLogins = is_array($_POST['logins'] ?? null) ? $_POST['logins'] : [];
    $logins = [];
    foreach ($rawLogins as $row) {
      if (!is_array($row)) continue;
      $entry = [
        'type'      => trim((string)($row['type'] ?? '')),
        'name'      => trim((string)($row['name'] ?? '')),
        'url'       => trim((string)($row['url'] ?? '')),
        'login_url' => trim((string)($row['login_url'] ?? '')),
        'username'  => trim((string)($row['username'] ?? '')),
        'password'  => (string)($row['password'] ?? ''),
      ];
      if ($entry['type'] === '' && $entry['name'] === '' && $entry['url'] === '' && $entry['login_url'] === '' && $entry['username'] === '' && $entry['password'] === '') continue;
      $logins[] = $entry;
    }

    if ($name === '') $name = 'Untitled Project';
    if ($clientId <= 0 || $typeId <= 0 || $statusId <= 0) {
      flash_set('error', 'Client, project type, and status are required.');
      redirect('dashboard.php');
    }
    if (!in_array($pricingModel, ['fixed_price', 'hourly'], true)) {
      flash_set('error', 'Project pricing model is required.');
      redirect('dashboard.php');
    }
    foreach ($logins as $entry) {
      if (!$isValidUrl($entry['url']) || !$isValidUrl($entry['login_url'])) {
        flash_set('error', 'Please enter valid website URLs (http/https).');
        redirect('dashboard.php');
      }
    }

    $websiteDetails = [
      'logins' => array_map(fn($e) => [
        'type'     => $e['type'] !== '' ? $e['type'] : null,
        'name'     => $e['name'] !== '' ? $e['name'] : null,
        'url'      => $e['url'] !== '' ? $e['url'] : null,
        'loginUrl' => $e['login_url'] !== '' ? $e['login_url'] : null,
        'username' => $e['username'] !== '' ? $e['username'] : null,
        'password' => $e['password'] !== '' ? $e['password'] : null,
      ], $logins),
    ];
    $firstSiteUrl = null;
    foreach ($logins as $e) { if ($e['url'] !== '') { $firstSiteUrl = $e['url']; break; } }

    $pdo->prepare('INSERT INTO projects (workspace_id, client_id, name, type_id, status_id, due_date, pricing_model, project_price, payment_terms, hourly_rate, live_website_url, notes, website_details_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$ws, $clientId, $name, $typeId, $statusId, $dueDate ?: null, $pricingModel, $projectPrice, $paymentTerms ?: null, $projectHourlyRate, $firstSiteUrl, null, json_encode($websiteDetails, JSON_UNESCAPED_SLASHES), now(), now()]);
    $newProjectId = (int)$pdo->lastInsertId();

    foreach ($logins as $idx => $entry) {
      $siteName = $entry['name'] !== ''
        ? $entry['name']
        : ($entry['type'] !== '' ? $entry['type'] : $name . ' (Login #' . ($idx + 1) . ')');
      $rowNotes = trim(($entry['type'] !== '' ? 'Type: ' . $entry['type'] : '') . ($wlNotes !== '' ? ($entry['type'] !== '' ? "\n" : '') . $wlNotes : ''));
      $pdo->prepare('INSERT INTO website_logins (workspace_id,client_id,project_id,site_name,website_url,login_url,login_username,login_password,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$ws, $clientId, $newProjectId, $siteName, $entry['url'] ?: null, $entry['login_url'] ?: null, $entry['username'] ?: null, $entry['password'] !== '' ? $entry['password'] : null, $rowNotes ?: null, (int)($user['id'] ?? 0), now(), now()]);
    }

    flash_set('success', 'Project created.');
    redirect('project_view.php?id=' . $newProjectId);
  }
}

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

// "Who is Working on What" — list every user with at least one task whose status
// is "To Do" or "In Progress" (case-insensitive). Names are shown with that user's
// To Do + In Progress task list rendered underneath.
$workingNow = dash_safe_rows($pdo, "SELECT u.id, u.name,
    COUNT(DISTINCT ta.task_id) AS task_count
  FROM task_assignees ta
  JOIN users u ON u.id=ta.user_id
  JOIN tasks t ON t.id=ta.task_id AND t.workspace_id=?
  WHERE LOWER(TRIM(t.status)) IN ('to do','in progress')
  GROUP BY u.id, u.name
  ORDER BY task_count DESC, u.name ASC
  LIMIT 20", [$ws]);

$workerTasks = [];
if ($workingNow) {
  $ids = array_column($workingNow, 'id');
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $rows = dash_safe_rows($pdo,
    "SELECT u.id AS uid, t.id, t.title, t.due_date, t.status
     FROM task_assignees ta
     JOIN users u ON u.id=ta.user_id
     JOIN tasks t ON t.id=ta.task_id AND t.workspace_id=?
     WHERE u.id IN ($in)
       AND LOWER(TRIM(t.status)) IN ('to do','in progress')
     ORDER BY t.due_date IS NULL, t.due_date ASC, t.id DESC
     LIMIT 500", array_merge([$ws], $ids));
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

// Data for the Add Task + Add Project quick-create modals (manager+ only).
$quickClients = [];
$quickProjectsByClient = []; // map: client_id => [{id,name},...]
$quickPhasesByProject = [];  // map: project_id => [{id,name},...]
$quickProjectTypes = [];
$quickProjectStatuses = [];
$quickTaskStatuses = [];
$quickTeam = [];
if ($canManage) {
  $quickClients = dash_safe_rows($pdo, 'SELECT id, name FROM clients WHERE workspace_id=? ORDER BY name ASC', [$ws]);
  $projRows = dash_safe_rows($pdo, 'SELECT id, client_id, name FROM projects WHERE workspace_id=? ORDER BY name ASC', [$ws]);
  foreach ($projRows as $pr) {
    $quickProjectsByClient[(int)$pr['client_id']][] = ['id' => (int)$pr['id'], 'name' => $pr['name']];
  }
  $phaseRows = dash_safe_rows($pdo, 'SELECT id, project_id, name FROM phases WHERE workspace_id=? ORDER BY sort_order ASC, id ASC', [$ws]);
  foreach ($phaseRows as $ph) {
    $quickPhasesByProject[(int)$ph['project_id']][] = ['id' => (int)$ph['id'], 'name' => $ph['name']];
  }
  $quickProjectTypes    = dash_safe_rows($pdo, 'SELECT id, name FROM project_types WHERE workspace_id=? ORDER BY sort_order ASC, id ASC', [$ws]);
  $quickProjectStatuses = dash_safe_rows($pdo, 'SELECT id, name FROM project_statuses WHERE workspace_id=? ORDER BY sort_order ASC, id ASC', [$ws]);
  $quickTaskStatuses    = dash_task_statuses($pdo, $ws, $canFinalStatus);
  $quickTeam = dash_safe_rows($pdo, "SELECT u.id, u.name, r.name AS role_name
    FROM users u JOIN roles r ON r.id=u.role_id
    WHERE u.workspace_id=? AND u.is_active=1 ORDER BY u.name ASC", [$ws]);
}

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

  /* Quick-create buttons in the page header */
  .quick-actions { display: inline-flex; align-items: center; gap: 8px; }
  .quick-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 10px; font-size: 12.5px; font-weight: 600; transition: all 0.15s; border: 1px solid var(--border); background: var(--surface); color: var(--text); cursor: pointer; }
  .quick-btn:hover { background: var(--surface-2); border-color: var(--border-strong); }
  .quick-btn.primary { background: var(--accent); color: #1a1400; border-color: var(--accent); }
  .quick-btn.primary:hover { background: var(--accent-hover); }
  .quick-btn svg { width: 14px; height: 14px; }
  .page-header-right { display: inline-flex; align-items: center; gap: 12px; flex-wrap: wrap; justify-content: flex-end; }

  /* Modal chrome (mirrors project_view.php) */
  .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 100; display: none; align-items: flex-start; justify-content: center; padding: 60px 20px; overflow-y: auto; }
  .modal-overlay.open { display: flex; }
  .modal-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; width: 100%; max-width: 720px; overflow: hidden; }
  .modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
  .modal-title { font-size: 16px; font-weight: 600; color: var(--text); }
  .modal-foot { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; gap: 8px; justify-content: flex-end; background: var(--bg); }
  .icon-close { width: 30px; height: 30px; border-radius: 6px; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; transition: all 0.12s; background: none; border: none; cursor: pointer; }
  .icon-close:hover { background: var(--surface-2); color: var(--text); }

  /* Add Task / Add Project body — same look as task_view.php rail */
  .at-card { max-width: 760px; }
  .at-body { padding: 22px 24px 6px; max-height: 76vh; overflow-y: auto; }
  .at-title-field { width: 100%; background: transparent; border: none; outline: none; color: var(--text); font-size: 24px; font-weight: 700; letter-spacing: -0.01em; line-height: 1.25; padding: 4px 0 14px; border-bottom: 1px solid var(--border); transition: border-color 0.15s; font-family: inherit; }
  .at-title-field::placeholder { color: var(--text-dim); font-weight: 600; }
  .at-title-field:focus { border-bottom-color: var(--accent); }
  .at-section { margin-top: 22px; }
  .at-section-label { font-size: 11px; font-weight: 600; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
  .at-section-label svg { width: 12px; height: 12px; }
  .at-desc { width: 100%; background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; color: var(--text); font-size: 14px; line-height: 1.55; min-height: 100px; resize: vertical; outline: none; transition: all 0.15s; font-family: inherit; }
  .at-desc::placeholder { color: var(--text-dim); }
  .at-desc:focus { border-color: var(--border-strong); background: var(--bg); }
  .at-meta-row { display: grid; gap: 14px; }
  .at-meta-row.cols-2 { grid-template-columns: 1fr 1fr; }
  .at-meta-row.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
  .at-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
  .at-field-label { font-size: 11.5px; color: var(--text-muted); font-weight: 500; display: flex; align-items: center; gap: 5px; }
  .at-field-label svg { width: 12px; height: 12px; color: var(--text-dim); }
  .at-input, .at-select { width: 100%; background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; color: var(--text); font-size: 13px; outline: none; transition: all 0.15s; font-family: inherit; }
  .at-input:focus, .at-select:focus { border-color: var(--accent); background: var(--bg); }
  .at-input[type=date] { color-scheme: dark; }
  .at-select { appearance: none; padding-right: 32px; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%238a8a8a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; }
  .at-select:focus { background-color: var(--bg); }
  .at-select option { background: #1a1a1a; color: var(--text); }
  .at-select:disabled { opacity: 0.5; cursor: not-allowed; }
  .at-status-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; }
  .at-status-opt { padding: 9px 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface-2); font-size: 12.5px; color: var(--text-muted); display: flex; align-items: center; gap: 8px; transition: all 0.15s; cursor: pointer; user-select: none; }
  .at-status-opt:hover { background: var(--surface); color: var(--text); border-color: var(--border-strong); }
  .at-status-opt.active { background: var(--accent-soft); border-color: rgba(250,204,21,0.4); color: var(--accent); font-weight: 500; }
  .at-status-opt .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--text-dim); flex-shrink: 0; }
  .at-status-opt.active .dot { background: var(--accent); box-shadow: 0 0 0 3px rgba(250,204,21,0.18); }
  .at-assignees { border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); max-height: 220px; overflow-y: auto; padding: 6px; }
  .at-assignee-row { display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; border-radius: 8px; cursor: pointer; transition: background 0.12s; }
  .at-assignee-row:hover { background: var(--surface); }
  .at-assignee-row.selected { background: var(--surface); }
  .at-assignee-info { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .at-av { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10.5px; font-weight: 600; color: #fff; letter-spacing: 0.02em; flex-shrink: 0; }
  .at-assignee-name { font-size: 13px; color: var(--text); font-weight: 500; }
  .at-assignee-role { font-size: 11px; color: var(--text-dim); }
  .at-check { width: 18px; height: 18px; border-radius: 5px; border: 1.5px solid var(--border-strong); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.15s; background: transparent; }
  .at-assignee-row.selected .at-check { background: var(--accent); border-color: var(--accent); }
  .at-check svg { width: 12px; height: 12px; color: #1a1400; opacity: 0; transition: opacity 0.15s; }
  .at-assignee-row.selected .at-check svg { opacity: 1; }
  .at-assignee-empty { padding: 18px; text-align: center; color: var(--text-dim); font-size: 12.5px; font-style: italic; }
  .at-count-pill { font-size: 11px; background: var(--surface); color: var(--text-muted); padding: 2px 8px; border-radius: 999px; font-weight: 500; letter-spacing: 0; text-transform: none; border: 1px solid var(--border); margin-left: 6px; }
  .at-count-pill.has { color: var(--accent); border-color: rgba(250,204,21,0.3); background: var(--accent-soft); }
  .at-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; font-size: 12.5px; font-weight: 600; border-radius: 8px; transition: all 0.15s; border: 1px solid var(--border); background: var(--surface); color: var(--text); cursor: pointer; font-family: inherit; }
  .at-btn:hover { background: var(--surface-2); border-color: var(--border-strong); }
  .at-btn.primary { background: var(--accent); color: #1a1400; border-color: var(--accent); }
  .at-btn.primary:hover { background: var(--accent-hover); }
  @media (max-width: 640px) { .at-meta-row.cols-2, .at-meta-row.cols-3 { grid-template-columns: 1fr; } .at-status-grid { grid-template-columns: 1fr; } }

  /* Full Add-Project modal layout (mirrors projects.php; .input/.select/.btn/.field-label come from anton.css) */
  .modal-body { padding: 20px; max-height: 70vh; overflow-y: auto; }
  .modal-grid { display: grid; gap: 12px; grid-template-columns: repeat(12, 1fr); }
  .modal-grid > * { display: flex; flex-direction: column; gap: 5px; }
  .c-12 { grid-column: span 12; } .c-8 { grid-column: span 8; } .c-6 { grid-column: span 6; } .c-4 { grid-column: span 4; } .c-3 { grid-column: span 3; } .c-2 { grid-column: span 2; }
  @media (max-width: 768px) { .c-8, .c-6, .c-4, .c-3, .c-2 { grid-column: span 12; } }
  .modal-section { padding: 14px; border-radius: 10px; border: 1px solid var(--border); margin-top: 14px; }
  .modal-section h6 { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 10px; font-weight: 600; }

  /* Login repeater */
  .login-repeater { margin-top: 14px; }
  .login-repeater-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
  .login-repeater-head h6 { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.08em; font-weight: 600; margin: 0; }
  .login-row { position: relative; padding: 14px; border-radius: 10px; border: 1px solid var(--border); margin-bottom: 10px; }
  .login-row-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; gap: 8px; }
  .login-row-index { font-size: 10.5px; color: var(--text-dim); font-family: 'JetBrains Mono', ui-monospace, monospace; text-transform: uppercase; letter-spacing: 0.1em; }
  .login-row-remove { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; color: var(--text-muted); padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border); background: transparent; cursor: pointer; transition: all 0.12s; }
  .login-row-remove:hover { color: #fca5a5; border-color: rgba(239,68,68,0.3); background: var(--danger-soft); }
  .login-row-remove svg { width: 11px; height: 11px; }
  .login-row.is-only .login-row-remove { display: none; }
  .login-add-row { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; font-size: 12.5px; font-weight: 500; color: var(--text); border-radius: 8px; border: 1px dashed var(--border-strong); background: transparent; cursor: pointer; transition: all 0.15s; }
  .login-add-row:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-soft); }
  .login-add-row svg { width: 13px; height: 13px; }
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
  <div class="page-header-right">
    <?php if ($canManage): ?>
      <div class="quick-actions">
        <button type="button" class="quick-btn" onclick="document.getElementById('quickAddProject').classList.add('open')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 012-2h4l2 3h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"></path><line x1="12" y1="11" x2="12" y2="17"></line><line x1="9" y1="14" x2="15" y2="14"></line></svg>
          Add Project
        </button>
        <button type="button" class="quick-btn primary" onclick="document.getElementById('quickAddTask').classList.add('open')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          Add Task
        </button>
      </div>
    <?php endif; ?>
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
    <?php
      // Anton Jr. — recent unread chat (DMs + @mentions) widget. Built
      // inline so the existing rail layout stays intact. Falls back silently
      // if the chat tables don't exist on a fresh install.
      $unreadChat = [];
      try {
        $stmt = $pdo->prepare(
          "SELECT m.id, m.content, m.created_at, m.channel_id, m.conversation_id,
                  c.slug AS channel_slug, u.name AS author_name
           FROM chat_messages m
           JOIN users u ON u.id = m.user_id
           LEFT JOIN chat_channels c ON c.id = m.channel_id
           WHERE m.workspace_id = ?
             AND m.deleted_at IS NULL
             AND m.parent_message_id IS NULL
             AND m.user_id != ?
             AND (
               -- DMs the user is in
               (m.conversation_id IS NOT NULL AND m.conversation_id IN (
                 SELECT conversation_id FROM chat_direct_conversation_members WHERE user_id = ?
               ) AND m.id > IFNULL((
                 SELECT last_read_message_id FROM chat_direct_conversation_members
                 WHERE conversation_id = m.conversation_id AND user_id = ?
               ), 0))
               -- OR @user mentions
               OR m.id IN (
                 SELECT message_id FROM chat_mentions WHERE mentioned_user_id = ?
               )
             )
           ORDER BY m.id DESC LIMIT 5"
        );
        $stmt->execute([$ws, (int)$user['id'], (int)$user['id'], (int)$user['id'], (int)$user['id']]);
        $unreadChat = $stmt->fetchAll();
      } catch (Throwable $e) {}
    ?>
    <div class="panel">
      <div class="rail-panel-head">
        <span class="rail-panel-title">Unread chat</span>
        <a href="chat/" class="rail-panel-count" style="text-decoration:none">Open chat →</a>
      </div>
      <div class="rail-panel-body">
        <?php if (empty($unreadChat)): ?>
          <div class="empty-card">You're all caught up.</div>
        <?php else: foreach ($unreadChat as $m):
          $snippet = trim(strip_tags((string)$m['content']));
          if (mb_strlen($snippet, 'UTF-8') > 140) $snippet = mb_substr($snippet, 0, 140, 'UTF-8') . '…';
          $where = $m['channel_slug'] ? '#' . $m['channel_slug'] : '@DM';
          $href  = $m['channel_slug'] ? 'chat/?c=' . urlencode((string)$m['channel_slug']) . '#msg-' . (int)$m['id']
                                       : 'chat/?d=' . (int)$m['conversation_id'] . '#msg-' . (int)$m['id'];
          $grad  = dashboard_avatar_gradient((string)$m['author_name']);
          $ini   = user_initials((string)$m['author_name']);
        ?>
          <a class="worker" href="<?= h($href) ?>" style="text-decoration:none;color:inherit;display:block">
            <div class="worker-head">
              <div class="avatar" style="background:<?= h($grad) ?>"><?= h($ini) ?></div>
              <span class="worker-name"><?= h($m['author_name']) ?></span>
              <span class="worker-count"><?= h($where) ?></span>
            </div>
            <div style="margin-top:6px;font-size:13px;color:var(--text-muted);padding-left:44px;line-height:1.4"><?= h($snippet) ?></div>
          </a>
        <?php endforeach; endif; ?>
      </div>
    </div>

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
  </aside>
</div>

<?php if ($canManage): ?>
<?php
  $dashAvatarGradient = function(string $seed): string {
    $p = ['linear-gradient(135deg,#22c55e,#16a34a)','linear-gradient(135deg,#a855f7,#7c3aed)','linear-gradient(135deg,#0ea5e9,#0284c7)','linear-gradient(135deg,#f97316,#ea580c)','linear-gradient(135deg,#ec4899,#be185d)','linear-gradient(135deg,#3b82f6,#1d4ed8)','linear-gradient(135deg,#14b8a6,#0f766e)','linear-gradient(135deg,#facc15,#ca8a04)'];
    return $p[crc32($seed) % count($p)];
  };
?>

<!-- ===== Add Task (quick-create from dashboard) ===== -->
<div class="modal-overlay" id="quickAddTask" onclick="if(event.target===this) this.classList.remove('open')">
  <div class="modal-card at-card" role="dialog" aria-label="Add Task">
    <div class="modal-head">
      <span class="modal-title">New task</span>
      <button type="button" class="icon-close" onclick="document.getElementById('quickAddTask').classList.remove('open')" aria-label="Close">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
      </button>
    </div>
    <form method="post" id="quickAddTaskForm">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="add_task_dashboard" value="1">
      <?php $qatDefaultStatus = $quickTaskStatuses[0] ?? 'To Do'; ?>
      <input type="hidden" name="status" id="qatStatusInput" value="<?= h($qatDefaultStatus) ?>">

      <div class="at-body">
        <input class="at-title-field" type="text" name="title" placeholder="Task name…" required autocomplete="off">

        <div class="at-section">
          <div class="at-section-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"></line><line x1="4" y1="12" x2="20" y2="12"></line><line x1="4" y1="18" x2="14" y2="18"></line></svg>
            Description
          </div>
          <textarea class="at-desc" name="description" rows="3" placeholder="Add more detail about this task…"></textarea>
        </div>

        <div class="at-section at-meta-row cols-3">
          <div class="at-field">
            <span class="at-field-label">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
              Client
            </span>
            <select class="at-select" name="client_id" id="qatClient" required>
              <option value="" disabled selected>Select a client…</option>
              <?php foreach ($quickClients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="at-field">
            <span class="at-field-label">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 012-2h4l2 3h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"></path></svg>
              Project
            </span>
            <select class="at-select" name="project_id" id="qatProject" required disabled>
              <option value="" disabled selected>Select a client first</option>
            </select>
          </div>
          <div class="at-field">
            <span class="at-field-label">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="9" x2="20" y2="9"></line><line x1="4" y1="15" x2="20" y2="15"></line><line x1="10" y1="3" x2="8" y2="21"></line><line x1="16" y1="3" x2="14" y2="21"></line></svg>
              Phase
            </span>
            <select class="at-select" name="phase_id" id="qatPhase" required disabled>
              <option value="" disabled selected>Select a project first</option>
            </select>
          </div>
        </div>

        <div class="at-section">
          <div class="at-section-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><polyline points="9 12 11 14 15 10"></polyline></svg>
            Status
          </div>
          <div class="at-status-grid" id="qatStatusGrid">
            <?php foreach ($quickTaskStatuses as $i => $s): ?>
              <div class="at-status-opt<?= $i === 0 ? ' active' : '' ?>" data-status="<?= h($s) ?>" role="button" tabindex="0">
                <span class="dot"></span>
                <span><?= h($s) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="at-section at-meta-row cols-2">
          <div class="at-field">
            <span class="at-field-label">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
              Due date
            </span>
            <input class="at-input" type="date" name="due_date">
          </div>
          <div class="at-field">
            <span class="at-field-label">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 00-3-3.87"></path><path d="M16 3.13a4 4 0 010 7.75"></path></svg>
              Assignees
              <span class="at-count-pill" id="qatAssigneeCount">0 selected</span>
            </span>
            <div class="at-assignees" id="qatAssignees">
              <?php if (empty($quickTeam)): ?>
                <div class="at-assignee-empty">No teammates available.</div>
              <?php else: foreach ($quickTeam as $m):
                $ini = function_exists('user_initials') ? user_initials((string)$m['name']) : strtoupper(substr((string)$m['name'], 0, 2));
                $grad = $dashAvatarGradient((string)$m['name']);
              ?>
                <label class="at-assignee-row" data-uid="<?= (int)$m['id'] ?>">
                  <span class="at-assignee-info">
                    <span class="at-av" style="background:<?= h($grad) ?>"><?= h($ini) ?></span>
                    <span>
                      <span class="at-assignee-name"><?= h($m['name']) ?><?= user_status_chip((int)$m['id']) ?></span><br>
                      <span class="at-assignee-role"><?= h($m['role_name']) ?></span>
                    </span>
                  </span>
                  <span class="at-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></span>
                  <input type="checkbox" name="assignees[]" value="<?= (int)$m['id'] ?>" hidden>
                </label>
              <?php endforeach; endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-foot">
        <button type="button" class="at-btn" onclick="document.getElementById('quickAddTask').classList.remove('open')">Cancel</button>
        <button class="at-btn primary" type="submit">Create task</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== Add Project (quick-create from dashboard) ===== -->
<!-- Full New Project modal (mirrors projects.php — Client selector is the dashboard-specific addition) -->
<div class="modal-overlay" id="quickAddProject" onclick="if(event.target===this) this.classList.remove('open')">
  <div class="modal-card" style="max-width:920px;" role="dialog" aria-label="New Project">
    <div class="modal-head">
      <span class="modal-title">New Project</span>
      <button type="button" class="icon-close" onclick="document.getElementById('quickAddProject').classList.remove('open')" aria-label="Close">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
      </button>
    </div>
    <form method="post" id="quickAddProjectForm">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="add_project_dashboard" value="1">
      <div class="modal-body">
        <div class="modal-grid">
          <div class="c-4"><label class="field-label">Project Name</label><input class="input" name="name" placeholder="Untitled Project"></div>
          <div class="c-4"><label class="field-label">Client</label>
            <select class="select" name="client_id" required>
              <option value="" disabled selected>Select a client…</option>
              <?php foreach ($quickClients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="c-2"><label class="field-label">Type</label><select class="select" name="type_id" required><?php foreach ($quickProjectTypes as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select></div>
          <div class="c-2"><label class="field-label">Status</label><select class="select" name="status_id" required><?php foreach ($quickProjectStatuses as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?></select></div>
          <div class="c-4"><label class="field-label">Due Date (optional)</label><input class="input" type="date" name="due_date"></div>
          <div class="c-4"><label class="field-label">Pricing Model</label><select class="select" name="pricing_model" id="qap_pricing_model"><option value="fixed_price">Fixed Price</option><option value="hourly">Hourly</option></select></div>
          <div class="c-4" id="qap_price_wrap"><label class="field-label">Project Price</label><input class="input" name="project_price" type="number" min="0" step="0.01"></div>
          <div class="c-4" id="qap_terms_wrap"><label class="field-label">Payment Terms</label><select class="select" name="payment_terms"><option value="full_upfront">Full Upfront</option><option value="50_50">50/50</option><option value="milestones">Milestones</option></select></div>
          <div class="c-4" id="qap_hourly_wrap" style="display:none;"><label class="field-label">Hourly Rate</label><input class="input" name="project_hourly_rate" type="number" min="0" step="0.01"></div>
        </div>

        <div class="login-repeater" id="qapLoginRepeater">
          <div class="login-repeater-head"><h6>Website Logins (optional)</h6></div>
          <div class="login-rows" id="qapLoginRows">
            <div class="login-row is-only">
              <div class="login-row-head">
                <span class="login-row-index">Login #1</span>
                <button type="button" class="login-row-remove" data-remove>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-2 14a2 2 0 01-2 2H9a2 2 0 01-2-2L5 6"></path></svg>
                  Remove
                </button>
              </div>
              <div class="modal-grid">
                <div class="c-4"><label class="field-label">Login Type</label><input class="input" name="logins[0][type]" placeholder="e.g. Current Website, Production"></div>
                <div class="c-4"><label class="field-label">Name</label><input class="input" name="logins[0][name]" placeholder="Main website"></div>
                <div class="c-4"><label class="field-label">Website URL</label><input class="input" type="url" name="logins[0][url]" placeholder="https://example.com"></div>
                <div class="c-6"><label class="field-label">Login URL</label><input class="input" type="url" name="logins[0][login_url]" placeholder="https://example.com/wp-admin"></div>
                <div class="c-3"><label class="field-label">Username</label><input class="input" name="logins[0][username]"></div>
                <div class="c-3"><label class="field-label">Password</label><input class="input" type="password" name="logins[0][password]"></div>
              </div>
            </div>
          </div>
          <button type="button" class="login-add-row" id="qapLoginAddRow">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Add Row
          </button>
        </div>
        <template id="qapLoginRowTemplate">
          <div class="login-row">
            <div class="login-row-head">
              <span class="login-row-index">Login #__N__</span>
              <button type="button" class="login-row-remove" data-remove>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-2 14a2 2 0 01-2 2H9a2 2 0 01-2-2L5 6"></path></svg>
                Remove
              </button>
            </div>
            <div class="modal-grid">
              <div class="c-4"><label class="field-label">Login Type</label><input class="input" name="logins[__I__][type]" placeholder="e.g. Current Website, Production"></div>
              <div class="c-4"><label class="field-label">Name</label><input class="input" name="logins[__I__][name]" placeholder="Main website"></div>
              <div class="c-4"><label class="field-label">Website URL</label><input class="input" type="url" name="logins[__I__][url]" placeholder="https://example.com"></div>
              <div class="c-6"><label class="field-label">Login URL</label><input class="input" type="url" name="logins[__I__][login_url]" placeholder="https://example.com/wp-admin"></div>
              <div class="c-3"><label class="field-label">Username</label><input class="input" name="logins[__I__][username]"></div>
              <div class="c-3"><label class="field-label">Password</label><input class="input" type="password" name="logins[__I__][password]"></div>
            </div>
          </div>
        </template>

        <div class="modal-grid" style="margin-top:14px;">
          <div class="c-12"><label class="field-label">Notes</label><textarea class="input" name="wl_notes" rows="2" placeholder="2FA notes, etc."></textarea></div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('quickAddProject').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary save-flash">Create</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  // Server-provided dependency maps.
  const projectsByClient = <?= json_encode($quickProjectsByClient, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const phasesByProject  = <?= json_encode($quickPhasesByProject, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

  // ----- Add Task modal -----
  const taskOverlay  = document.getElementById('quickAddTask');
  const taskForm     = document.getElementById('quickAddTaskForm');
  const clientSel    = document.getElementById('qatClient');
  const projectSel   = document.getElementById('qatProject');
  const phaseSel     = document.getElementById('qatPhase');
  const statusGrid   = document.getElementById('qatStatusGrid');
  const statusInput  = document.getElementById('qatStatusInput');
  const assigneeWrap = document.getElementById('qatAssignees');
  const assigneeCnt  = document.getElementById('qatAssigneeCount');

  function fillSelect(sel, items, placeholder) {
    sel.innerHTML = '';
    const opt = document.createElement('option');
    opt.value = ''; opt.disabled = true; opt.selected = true; opt.textContent = placeholder;
    sel.appendChild(opt);
    items.forEach(it => {
      const o = document.createElement('option');
      o.value = it.id; o.textContent = it.name;
      sel.appendChild(o);
    });
    sel.disabled = items.length === 0;
  }

  if (clientSel) {
    clientSel.addEventListener('change', () => {
      const cid = parseInt(clientSel.value, 10) || 0;
      const projects = projectsByClient[cid] || [];
      fillSelect(projectSel, projects, projects.length ? 'Select a project…' : 'No projects for this client');
      fillSelect(phaseSel, [], 'Select a project first');
    });
  }
  if (projectSel) {
    projectSel.addEventListener('change', () => {
      const pid = parseInt(projectSel.value, 10) || 0;
      const phases = phasesByProject[pid] || [];
      fillSelect(phaseSel, phases, phases.length ? 'Select a phase…' : 'No phases for this project');
    });
  }

  if (statusGrid && statusInput) {
    statusGrid.addEventListener('click', e => {
      const opt = e.target.closest('.at-status-opt');
      if (!opt) return;
      statusGrid.querySelectorAll('.at-status-opt').forEach(o => o.classList.remove('active'));
      opt.classList.add('active');
      statusInput.value = opt.dataset.status || '';
    });
    statusGrid.addEventListener('keydown', e => {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      const opt = e.target.closest('.at-status-opt');
      if (!opt) return;
      e.preventDefault();
      opt.click();
    });
  }

  function refreshAssigneeCount() {
    if (!assigneeWrap || !assigneeCnt) return;
    const n = assigneeWrap.querySelectorAll('input[type=checkbox]:checked').length;
    assigneeCnt.textContent = n + ' selected';
    assigneeCnt.classList.toggle('has', n > 0);
  }
  if (assigneeWrap) {
    assigneeWrap.querySelectorAll('.at-assignee-row').forEach(row => {
      const cb = row.querySelector('input[type=checkbox]');
      if (!cb) return;
      cb.addEventListener('change', () => {
        row.classList.toggle('selected', cb.checked);
        refreshAssigneeCount();
      });
    });
    refreshAssigneeCount();
  }

  // Reset modal when closed.
  function watchOverlay(overlay, form, onClose) {
    if (!overlay || !form) return;
    const obs = new MutationObserver(() => {
      if (!overlay.classList.contains('open')) {
        form.reset();
        if (onClose) onClose();
      }
    });
    obs.observe(overlay, { attributes: true, attributeFilter: ['class'] });
  }
  watchOverlay(taskOverlay, taskForm, () => {
    if (clientSel) clientSel.value = '';
    fillSelect(projectSel, [], 'Select a client first');
    fillSelect(phaseSel, [], 'Select a project first');
    if (assigneeWrap) {
      assigneeWrap.querySelectorAll('.at-assignee-row').forEach(r => r.classList.remove('selected'));
      refreshAssigneeCount();
    }
    if (statusGrid && statusInput) {
      const opts = statusGrid.querySelectorAll('.at-status-opt');
      opts.forEach((o, i) => o.classList.toggle('active', i === 0));
      statusInput.value = opts[0] ? (opts[0].dataset.status || '') : '';
    }
  });

  // ----- Add Project modal -----
  const projectOverlay = document.getElementById('quickAddProject');
  const projectForm    = document.getElementById('quickAddProjectForm');

  // Pricing model toggle (Fixed Price vs. Hourly — same UX as projects.php).
  const qapModel = document.getElementById('qap_pricing_model');
  if (qapModel) {
    const fixedWrap  = document.getElementById('qap_price_wrap');
    const termsWrap  = document.getElementById('qap_terms_wrap');
    const hourlyWrap = document.getElementById('qap_hourly_wrap');
    function qapSyncPricing() {
      const v = qapModel.value;
      if (fixedWrap)  fixedWrap.style.display  = v === 'fixed_price' ? '' : 'none';
      if (termsWrap)  termsWrap.style.display  = v === 'fixed_price' ? '' : 'none';
      if (hourlyWrap) hourlyWrap.style.display = v === 'hourly' ? '' : 'none';
    }
    qapModel.addEventListener('change', qapSyncPricing);
    qapSyncPricing();
  }

  // Login repeater (Add Row / Remove / re-index).
  const qapRows = document.getElementById('qapLoginRows');
  const qapAdd  = document.getElementById('qapLoginAddRow');
  const qapTmpl = document.getElementById('qapLoginRowTemplate');
  function qapReindex() {
    if (!qapRows) return;
    const rows = qapRows.querySelectorAll('.login-row');
    rows.forEach((row, idx) => {
      row.classList.toggle('is-only', rows.length === 1);
      const lbl = row.querySelector('.login-row-index');
      if (lbl) lbl.textContent = 'Login #' + (idx + 1);
      row.querySelectorAll('[name^="logins["]').forEach(input => {
        input.name = input.name.replace(/^logins\[\d+\]/, 'logins[' + idx + ']');
      });
    });
  }
  if (qapRows && qapAdd && qapTmpl) {
    qapAdd.addEventListener('click', () => {
      const nextIdx = qapRows.querySelectorAll('.login-row').length;
      const html = qapTmpl.innerHTML.replace(/__N__/g, String(nextIdx + 1)).replace(/__I__/g, String(nextIdx));
      const wrap = document.createElement('div');
      wrap.innerHTML = html.trim();
      const newRow = wrap.firstElementChild;
      qapRows.appendChild(newRow);
      qapReindex();
      const firstInput = newRow.querySelector('input');
      if (firstInput) firstInput.focus();
    });
    qapRows.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-remove]');
      if (!btn) return;
      const row = btn.closest('.login-row');
      if (!row) return;
      if (qapRows.querySelectorAll('.login-row').length <= 1) {
        row.querySelectorAll('input').forEach(i => i.value = '');
        return;
      }
      row.remove();
      qapReindex();
    });
    qapReindex();
  }

  // Reset modal on close: collapse extra rows back to one and re-show fixed-price fields.
  watchOverlay(projectOverlay, projectForm, () => {
    if (qapRows) {
      const rows = Array.from(qapRows.querySelectorAll('.login-row'));
      rows.slice(1).forEach(r => r.remove());
      const first = qapRows.querySelector('.login-row');
      if (first) first.querySelectorAll('input').forEach(i => i.value = '');
      qapReindex();
    }
    if (qapModel) { qapModel.value = 'fixed_price'; qapModel.dispatchEvent(new Event('change')); }
  });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/layout_end.php'; ?>
