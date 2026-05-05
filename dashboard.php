<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
auth_require_login();

$role = auth_user()['role_name'] ?? '';
if (in_array($role, ['Developer', 'SEO'], true)) {
  redirect('dashboard_member.php');
}

require_once __DIR__ . '/layout.php';
$pdo = db();
$ws = auth_workspace_id();

function safe_rows(PDO $pdo, string $sql, array $params = []): array {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
  } catch (Throwable $e) {
    return [];
  }
}

function status_class(string $status): string {
  $s = strtolower($status);
  if (strpos($s, 'block') !== false || strpos($s, 'hold') !== false || strpos($s, 'pending') !== false || strpos($s, 'overdue') !== false) return 'event-critical';
  if (strpos($s, 'review') !== false || strpos($s, 'need') !== false) return 'event-review';
  if (strpos($s, 'approved') !== false || strpos($s, 'submit') !== false || strpos($s, 'done') !== false || strpos($s, 'complete') !== false) return 'event-ontrack';
  return 'event-milestone';
}

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
  $month = date('Y-m');
}
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$calStart = date('Y-m-d', strtotime('monday this week', strtotime($monthStart)));
$calEnd = date('Y-m-d', strtotime('sunday this week', strtotime($monthEnd)));
$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));

$tasks = safe_rows($pdo, "SELECT t.id, t.title, t.status, t.due_date, p.name AS project_name,
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

$workingNow = safe_rows($pdo, "SELECT u.id, u.name,
    GROUP_CONCAT(DISTINCT CONCAT(t.title, ' (', DATE_FORMAT(t.due_date, '%b %e'), ')') ORDER BY t.due_date ASC SEPARATOR ' • ') AS items
  FROM task_assignees ta
  JOIN users u ON u.id=ta.user_id
  JOIN tasks t ON t.id=ta.task_id AND t.workspace_id=?
  WHERE t.status IN ('To Do','In Progress','Completed (Needs Manager Review)')
  GROUP BY u.id, u.name
  ORDER BY u.name ASC
  LIMIT 8", [$ws]);

$dueSoon = safe_rows($pdo, "SELECT t.id, t.title, t.status, t.due_date,
    GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS assignees
  FROM tasks t
  LEFT JOIN task_assignees ta ON ta.task_id=t.id
  LEFT JOIN users u ON u.id=ta.user_id
  WHERE t.workspace_id=? AND t.due_date IS NOT NULL AND DATE(t.due_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
  GROUP BY t.id, t.title, t.status, t.due_date
  ORDER BY t.due_date ASC
  LIMIT 10", [$ws]);

$riskBlockers = safe_rows($pdo, "SELECT t.id, t.title, t.status, t.due_date,
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

$workload = safe_rows($pdo, "SELECT COALESCE(NULLIF(TRIM(u.role), ''), 'General') AS team,
    COUNT(DISTINCT ta.task_id) AS active_tasks,
    COUNT(DISTINCT u.id) AS members,
    ROUND((COUNT(DISTINCT ta.task_id) / NULLIF(COUNT(DISTINCT u.id) * 5, 0)) * 100, 0) AS capacity
  FROM users u
  LEFT JOIN task_assignees ta ON ta.user_id=u.id
  LEFT JOIN tasks t ON t.id=ta.task_id AND t.workspace_id=? AND t.status IN ('To Do','In Progress','Completed (Needs Manager Review)')
  WHERE u.workspace_id=? AND u.is_active=1
  GROUP BY team
  ORDER BY active_tasks DESC
  LIMIT 6", [$ws, $ws]);
?>
<style>
.dashboard-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px}.dash-card{border:1px solid rgba(255,255,255,.08);border-radius:14px;background:linear-gradient(120deg,rgba(24,24,24,.88),rgba(16,16,16,.88))}.dash-head{padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.08);font-size:1.3rem;font-weight:600;display:flex;justify-content:space-between;align-items:center}.cal-wrap{padding:10px}.cal-week{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px}.day-name{padding:6px 8px;color:rgba(236,236,240,.66);font-size:.85rem}.day{border:1px solid rgba(255,255,255,.09);border-radius:10px;min-height:130px;padding:8px;background:rgba(255,255,255,.02)}.day.muted{opacity:.45}.date{font-size:.82rem;color:rgba(236,236,240,.75);margin-bottom:6px}.event{display:block;padding:5px 6px;border-radius:8px;margin-bottom:6px;font-size:.78rem;text-decoration:none;color:#f0f0f3;border:1px solid transparent}.event small{display:block;color:rgba(236,236,240,.7)}.event-critical{background:rgba(243,111,117,.14);border-color:rgba(243,111,117,.34)}.event-review{background:rgba(246,212,105,.14);border-color:rgba(246,212,105,.36)}.event-ontrack{background:rgba(85,203,144,.14);border-color:rgba(85,203,144,.3)}.event-milestone{background:rgba(135,208,255,.14);border-color:rgba(135,208,255,.34)}.side-stack{display:grid;gap:16px}.list{padding:12px 14px}.row-item{padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)}.row-item:last-child{border-bottom:0}.meta{font-size:.82rem;color:rgba(236,236,240,.65)}.pill{font-size:.72rem;border-radius:999px;padding:2px 8px;border:1px solid rgba(255,255,255,.2)}.overdue{color:#ff9aa0}.legend{display:flex;flex-wrap:wrap;gap:10px;padding:10px 14px 14px}.dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px}.bar{height:8px;background:rgba(255,255,255,.1);border-radius:999px;overflow:hidden}.bar>span{display:block;height:100%;background:linear-gradient(90deg,#ffcc00,#55cb90)}@media (max-width:1100px){.dashboard-grid{grid-template-columns:1fr}.cal-week{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (max-width:700px){.cal-week{grid-template-columns:1fr}}
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="m-0">Agency Calendar Command Center</h1>
  <div class="d-flex gap-2"><a class="btn btn-sm btn-outline-light" href="?month=<?=h($prevMonth)?>">← Prev</a><span class="btn btn-sm btn-dark disabled"><?=h(date('F Y', strtotime($monthStart)))?></span><a class="btn btn-sm btn-outline-light" href="?month=<?=h($nextMonth)?>">Next →</a></div>
</div>

<div class="dashboard-grid">
  <section class="dash-card">
    <div class="dash-head">Monthly Task Calendar</div>
    <div class="cal-wrap">
      <div class="cal-week"><?php foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dn): ?><div class="day-name"><?=h($dn)?></div><?php endforeach; ?></div>
      <div class="cal-week">
        <?php for($d=strtotime($calStart); $d<=strtotime($calEnd); $d=strtotime('+1 day',$d)):
          $key=date('Y-m-d',$d); $inMonth=(date('Y-m',$d)===$month); ?>
          <div class="day <?=$inMonth?'':'muted'?>">
            <div class="date"><?=h(date('M j',$d))?></div>
            <?php if (empty($calendarMap[$key])): ?><div class="meta">No tasks</div><?php else: foreach(array_slice($calendarMap[$key],0,3) as $e):
              $isOverdue = strtotime((string)$e['due_date']) < strtotime(date('Y-m-d')) && !in_array($e['status'], ['Approved (Ready to Submit)','Submitted to Client'], true); ?>
              <a class="event <?=status_class((string)$e['status'])?>" href="task_view.php?id=<?=$e['id']?>">
                <?=h($e['title'])?>
                <small><?=h($e['assignees'] ?: 'Unassigned')?> · <?=h($e['status'])?><?= $isOverdue ? ' · <span class="overdue">Overdue</span>' : '' ?></small>
              </a>
            <?php endforeach; if(count($calendarMap[$key])>3): ?><div class="meta">+<?=count($calendarMap[$key])-3?> more</div><?php endif; endif; ?>
          </div>
        <?php endfor; ?>
      </div>
    </div>
    <div class="legend">
      <span><span class="dot" style="background:#f3797e"></span>Critical deadline</span>
      <span><span class="dot" style="background:#ffcc00"></span>Needs review</span>
      <span><span class="dot" style="background:#55cb90"></span>On track</span>
      <span><span class="dot" style="background:#a4a4ff"></span>Finance/Admin</span>
      <span><span class="dot" style="background:#87d0ff"></span>Client milestone</span>
    </div>
  </section>

  <div class="side-stack">
    <section class="dash-card"><div class="dash-head">Who is Working on What</div><div class="list"><?php if(!$workingNow): ?><div class="meta">No active assignments.</div><?php else: foreach($workingNow as $w): ?><div class="row-item"><div><?=h($w['name'])?></div><div class="meta"><?=h($w['items'] ?: 'No active tasks')?></div></div><?php endforeach; endif; ?></div></section>
    <section class="dash-card"><div class="dash-head">Due Soon (next 7 days)</div><div class="list"><?php if(!$dueSoon): ?><div class="meta">No upcoming deadlines in the next 7 days.</div><?php else: foreach($dueSoon as $d): ?><div class="row-item"><div><a href="task_view.php?id=<?=$d['id']?>"><?=h($d['title'])?></a></div><div class="meta"><?=h(format_date($d['due_date']))?> · <?=h($d['assignees'] ?: 'Unassigned')?> · <span class="pill"><?=h($d['status'])?></span></div></div><?php endforeach; endif; ?></div></section>
    <section class="dash-card"><div class="dash-head">Risk & Blockers</div><div class="list"><?php if(!$riskBlockers): ?><div class="meta">No blocked or at-risk tasks right now.</div><?php else: foreach($riskBlockers as $r): ?><div class="row-item"><div><a href="task_view.php?id=<?=$r['id']?>"><?=h($r['title'])?></a></div><div class="meta <?= (!empty($r['due_date']) && strtotime((string)$r['due_date']) < strtotime(date('Y-m-d'))) ? 'overdue' : '' ?>"><?=h($r['status'])?><?= !empty($r['due_date']) ? ' · Due '.h(format_date($r['due_date'])) : '' ?> · <?=h($r['assignees'] ?: 'Unassigned')?></div></div><?php endforeach; endif; ?></div></section>
    <section class="dash-card"><div class="dash-head">Workload Snapshot</div><div class="list"><?php if(!$workload): ?><div class="meta">No workload data available.</div><?php else: foreach($workload as $wl): $cap=max(0,min(100,(int)$wl['capacity'])); ?><div class="row-item"><div class="d-flex justify-content-between"><span><?=h($wl['team'])?></span><span class="meta"><?=h((string)$wl['active_tasks'])?> tasks / <?=h((string)$wl['members'])?> people</span></div><div class="bar mt-1"><span style="width:<?=$cap?>%"></span></div><div class="meta mt-1">Estimated capacity: <?=$cap?>%</div></div><?php endforeach; endif; ?></div></section>
  </div>
</div>
