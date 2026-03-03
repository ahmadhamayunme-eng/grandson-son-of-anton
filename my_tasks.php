<?php
require_once __DIR__ . '/layout.php';
$pdo = db();
$ws = auth_workspace_id();
$uid = (int)auth_user()['id'];

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

$sql = "SELECT t.*, p.name AS project_name, c.name AS client_name
  FROM tasks t
  JOIN projects p ON p.id=t.project_id
  JOIN clients c ON c.id=p.client_id
  JOIN task_assignees ta ON ta.task_id=t.id AND ta.user_id=?
  WHERE t.workspace_id=?";
$params = [$uid, $ws];

if ($status !== '') {
  $sql .= " AND t.status=?";
  $params[] = $status;
}
if ($q !== '') {
  $sql .= " AND (t.title LIKE ? OR p.name LIKE ? OR c.name LIKE ?)";
  $like = '%' . $q . '%';
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}
$sql .= " ORDER BY COALESCE(t.due_date, '9999-12-31') ASC, t.updated_at DESC, t.id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$tasks = $st->fetchAll();

$statusStmt = $pdo->prepare("SELECT t.status, COUNT(*) c
  FROM tasks t
  JOIN task_assignees ta ON ta.task_id=t.id AND ta.user_id=?
  WHERE t.workspace_id=?
  GROUP BY t.status
  ORDER BY c DESC");
$statusStmt->execute([$uid, $ws]);
$statusRows = $statusStmt->fetchAll();

$statusCounts = [];
foreach ($statusRows as $row) {
  $statusCounts[$row['status']] = (int)$row['c'];
}
$totalTasks = array_sum($statusCounts);
$doneCount = 0;
foreach ($statusCounts as $name => $count) {
  $s = strtolower((string)$name);
  if (strpos($s, 'approved') !== false || strpos($s, 'complete') !== false || $s === 'done') {
    $doneCount += $count;
  }
}
$openCount = max($totalTasks - $doneCount, 0);
$dueSoon = 0;
foreach ($tasks as $t) {
  if (!empty($t['due_date']) && $t['due_date'] <= date('Y-m-d', strtotime('+3 days'))) {
    $dueSoon++;
  }
}

function task_badge_class(string $status): string {
  $s = strtolower($status);
  if (strpos($s, 'progress') !== false || $s === 'to do') return 'pill-warn';
  if (strpos($s, 'approved') !== false || strpos($s, 'complete') !== false || $s === 'done') return 'pill-ok';
  if (strpos($s, 'review') !== false || strpos($s, 'submit') !== false) return 'pill-neutral';
  if (strpos($s, 'hold') !== false || strpos($s, 'blocked') !== false || strpos($s, 'cancel') !== false) return 'pill-bad';
  return 'pill-neutral';
}
?>

<style>
  .tasks-shell{border:1px solid rgba(255,255,255,.1);border-radius:18px;background:linear-gradient(180deg,#111111,#090909);padding:1.15rem;box-shadow:0 24px 60px rgba(0,0,0,.4)}
  .tasks-head{display:flex;justify-content:space-between;align-items:center;gap:.8rem;margin-bottom:1rem}
  .tasks-title{font-size:2rem;font-weight:700;margin:0}
  .tasks-sub{color:rgba(232,232,232,.68);font-size:.92rem}
  .tasks-filters{display:grid;grid-template-columns:1fr 220px auto;gap:.6rem;margin-bottom:1rem}
  .tasks-input,.tasks-select{border:1px solid rgba(255,255,255,.12);border-radius:10px;background:rgba(255,255,255,.03);color:#ececf0;padding:.6rem .72rem}
  .tasks-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.65rem;margin-bottom:.95rem}
  .tasks-card{border:1px solid rgba(255,255,255,.1);border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,.045),rgba(255,255,255,.02));padding:.75rem .85rem}
  .tasks-label{color:rgba(232,232,232,.68);font-size:.83rem}
  .tasks-value{font-size:1.6rem;font-weight:700;line-height:1.1}
  .tasks-table{border:1px solid rgba(255,255,255,.1);border-radius:12px;overflow:hidden;background:rgba(255,255,255,.02)}
  .tasks-table table{width:100%;border-collapse:collapse}
  .tasks-table th,.tasks-table td{padding:.7rem .75rem;border-bottom:1px solid rgba(255,255,255,.08)}
  .tasks-table th{font-weight:600;color:rgba(228,228,228,.82);background:rgba(255,255,255,.03);text-align:left}
  .tasks-table tr:last-child td{border-bottom:0}
  .task-name{font-weight:600;color:#ececf0;text-decoration:none}
  .task-name:hover{color:#f6d469}
  .pill{display:inline-flex;border:1px solid transparent;border-radius:999px;padding:.25rem .62rem;font-size:.75rem;font-weight:600}
  .pill-warn{color:#f6d469;border-color:rgba(246,212,105,.35);background:rgba(246,212,105,.12)}
  .pill-ok{color:#78dfab;border-color:rgba(87,200,143,.35);background:rgba(87,200,143,.12)}
  .pill-bad{color:#ff9aa0;border-color:rgba(243,111,117,.35);background:rgba(243,111,117,.12)}
  .pill-neutral{color:#d9d9d9;border-color:rgba(255,255,255,.2);background:rgba(255,255,255,.07)}
  .due-soon{color:#f6d469}
  @media (max-width: 992px){.tasks-filters{grid-template-columns:1fr}.tasks-cards{grid-template-columns:1fr}}
</style>

<div class="tasks-shell">
  <div class="tasks-head">
    <div>
      <h1 class="tasks-title">My Tasks</h1>
      <div class="tasks-sub">Track assigned work, filter by status, and jump directly into task details.</div>
    </div>
    <a class="btn btn-outline-light btn-sm" href="projects.php">View Projects</a>
  </div>

  <form class="tasks-filters" method="get">
    <input class="tasks-input" name="q" value="<?=h($q)?>" placeholder="Search task, client, project...">
    <select class="tasks-select" name="status">
      <option value="">All statuses</option>
      <?php foreach ($statusRows as $s): ?>
        <option value="<?=h($s['status'])?>" <?=$status === $s['status'] ? 'selected' : ''?>><?=h($s['status'])?> (<?= (int)$s['c'] ?>)</option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-yellow" type="submit">Apply</button>
  </form>

  <div class="tasks-cards">
    <div class="tasks-card"><div class="tasks-label">Assigned</div><div class="tasks-value"><?= (int)$totalTasks ?></div></div>
    <div class="tasks-card"><div class="tasks-label">Open</div><div class="tasks-value"><?= (int)$openCount ?></div></div>
    <div class="tasks-card"><div class="tasks-label">Due in 3 days</div><div class="tasks-value due-soon"><?= (int)$dueSoon ?></div></div>
  </div>

  <div class="tasks-table">
    <table>
      <thead><tr><th>Task</th><th>Client</th><th>Project</th><th>Status</th><th>Due</th><th></th></tr></thead>
      <tbody>
        <?php foreach($tasks as $t): ?>
          <tr>
            <td><a class="task-name" href="task_view.php?id=<?=h($t['id'])?>"><?=h($t['title'])?></a></td>
            <td class="text-muted"><?=h($t['client_name'])?></td>
            <td class="text-muted"><?=h($t['project_name'])?></td>
            <td><span class="pill <?=task_badge_class((string)$t['status'])?>"><?=h($t['status'])?></span></td>
            <td class="text-muted"><?=h($t['due_date'] ? format_date($t['due_date']) : '—')?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-light" href="task_view.php?id=<?=h($t['id'])?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$tasks): ?><tr><td colspan="6" class="text-muted">No tasks found for this filter.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
