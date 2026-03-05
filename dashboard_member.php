<?php
require_once __DIR__ . '/layout.php';

$pdo = db();
$ws = auth_workspace_id();
$u = auth_user();
$userId = (int)($u['id'] ?? 0);

function member_safe_scalar(PDO $pdo, string $sql, array $params = [], int $default = 0): int {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
  } catch (Throwable $e) {
    return $default;
  }
}

function member_safe_rows(PDO $pdo, string $sql, array $params = []): array {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
  } catch (Throwable $e) {
    return [];
  }
}

$clients = member_safe_scalar($pdo, "SELECT COUNT(*) FROM clients WHERE workspace_id=?", [$ws]);
$projects = member_safe_scalar($pdo, "SELECT COUNT(*) FROM projects WHERE workspace_id=?", [$ws]);
$activeTasks = member_safe_scalar($pdo, "SELECT COUNT(*) FROM tasks WHERE workspace_id=? AND status IN ('To Do','In Progress','Completed (Needs Manager Review)','Approved (Ready to Submit)')", [$ws]);

$myAssignedTasks = member_safe_rows($pdo, "
  SELECT t.id, t.title, t.status, t.due_date, p.name AS project_name
  FROM task_assignees ta
  JOIN tasks t ON t.id = ta.task_id
  JOIN projects p ON p.id = t.project_id
  WHERE ta.user_id=?
    AND t.workspace_id=?
    AND t.status IN ('To Do','In Progress','Completed (Needs Manager Review)','Approved (Ready to Submit)')
  ORDER BY
    CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END,
    t.due_date ASC,
    t.updated_at DESC
  LIMIT 6
", [$userId, $ws]);

$recentProjects = member_safe_rows($pdo, "
  SELECT p.id, p.name, ps.name AS status_name, c.name AS client_name
  FROM projects p
  JOIN project_statuses ps ON ps.id = p.status_id
  JOIN clients c ON c.id = p.client_id
  WHERE p.workspace_id=?
  ORDER BY p.updated_at DESC, p.id DESC
  LIMIT 5
", [$ws]);

$recentTasks = member_safe_rows($pdo, "
  SELECT t.id, t.title, t.status, p.name AS project_name
  FROM tasks t
  JOIN projects p ON p.id = t.project_id
  WHERE t.workspace_id=?
  ORDER BY t.updated_at DESC, t.id DESC
  LIMIT 5
", [$ws]);

function member_status_chip(string $status): string {
  $s = strtolower($status);
  if (strpos($s, 'progress') !== false || $s === 'to do' || strpos($s, 'active') !== false) return 'chip-yellow';
  if (strpos($s, 'approved') !== false || strpos($s, 'complete') !== false || $s === 'done') return 'chip-green';
  if (strpos($s, 'hold') !== false || strpos($s, 'pending') !== false || strpos($s, 'blocked') !== false) return 'chip-red';
  return 'chip-purple';
}
?>

<style>
  .member-shell { border: 1px solid rgba(255,255,255,.08); border-radius: 18px; background: linear-gradient(130deg, rgba(14,14,14,.93), rgba(9,9,9,.95)); box-shadow: 0 28px 70px rgba(0,0,0,.42); padding: 22px; }
  .member-title { margin: 0 0 16px; font-size: 2rem; font-weight: 600; }
  .kpi-card { border: 1px solid rgba(255,255,255,.08); border-radius: 12px; background: linear-gradient(110deg, rgba(23,23,23,.92), rgba(14,14,14,.9)); padding: 14px 16px; min-height: 94px; }
  .kpi-label { color: rgba(236,236,236,.72); font-size: .9rem; margin-bottom: 6px; }
  .kpi-value { font-size: 2rem; font-weight: 600; line-height: 1.1; }
  .panel { border: 1px solid rgba(255,255,255,.08); border-radius: 12px; background: linear-gradient(120deg, rgba(24,24,24,.88), rgba(16,16,16,.88)); }
  .panel-head { padding: 14px 16px; border-bottom: 1px solid rgba(255,255,255,.07); font-size: 1.25rem; font-weight: 600; }
  .row-item { display: flex; justify-content: space-between; gap: 14px; padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,.06); }
  .row-item:last-child { border-bottom: 0; }
  .item-title { color: #f0f0f3; text-decoration: none; font-weight: 500; font-size: 1.04rem; }
  .item-meta { color: rgba(236,236,240,.62); font-size: .9rem; }
  .chip { border-radius: 999px; padding: 3px 10px; font-size: .78rem; border: 1px solid transparent; align-self: center; }
  .chip-yellow { background: rgba(246,212,105,.14); border-color: rgba(246,212,105,.36); color: #f6d469; }
  .chip-green { background: rgba(85,203,144,.14); border-color: rgba(85,203,144,.3); color: #7ae7af; }
  .chip-red { background: rgba(243,111,117,.14); border-color: rgba(243,111,117,.34); color: #ff9aa0; }
  .chip-purple { background: rgba(175,175,175,.16); border-color: rgba(175,175,175,.32); color: #d0d0d0; }
</style>

<div class="member-shell">
  <h1 class="member-title">Dashboard</h1>

  <div class="row g-3 mb-3">
    <div class="col-lg-4 col-sm-6"><div class="kpi-card"><div class="kpi-label">Clients</div><div class="kpi-value"><?=h($clients)?></div></div></div>
    <div class="col-lg-4 col-sm-6"><div class="kpi-card"><div class="kpi-label">Projects</div><div class="kpi-value"><?=h($projects)?></div></div></div>
    <div class="col-lg-4 col-sm-12"><div class="kpi-card"><div class="kpi-label">Active Tasks</div><div class="kpi-value"><?=h($activeTasks)?></div></div></div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-12">
      <section class="panel">
        <div class="panel-head">My Assigned Current Tasks</div>
        <?php if (!$myAssignedTasks): ?>
          <div class="p-3 text-muted">No currently assigned active tasks.</div>
        <?php else: foreach ($myAssignedTasks as $t): ?>
          <article class="row-item">
            <div>
              <a class="item-title" href="task_view.php?id=<?=$t['id']?>"><?=h($t['title'])?></a>
              <div class="item-meta"><?=h($t['project_name'])?><?php if (!empty($t['due_date'])): ?> • Due <?=h(format_date($t['due_date']))?><?php endif; ?></div>
            </div>
            <span class="chip <?=member_status_chip($t['status'])?>"><?=h($t['status'])?></span>
          </article>
        <?php endforeach; endif; ?>
      </section>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <section class="panel h-100">
        <div class="panel-head">Recent Projects</div>
        <?php if (!$recentProjects): ?>
          <div class="p-3 text-muted">No projects yet.</div>
        <?php else: foreach ($recentProjects as $p): ?>
          <article class="row-item">
            <div>
              <a class="item-title" href="project_view.php?id=<?=$p['id']?>"><?=h($p['name'])?></a>
              <div class="item-meta"><?=h($p['client_name'])?></div>
            </div>
            <span class="chip <?=member_status_chip($p['status_name'])?>"><?=h($p['status_name'])?></span>
          </article>
        <?php endforeach; endif; ?>
      </section>
    </div>
    <div class="col-lg-6">
      <section class="panel h-100">
        <div class="panel-head">Recently Updated Tasks</div>
        <?php if (!$recentTasks): ?>
          <div class="p-3 text-muted">No tasks yet.</div>
        <?php else: foreach ($recentTasks as $t): ?>
          <article class="row-item">
            <div>
              <a class="item-title" href="task_view.php?id=<?=$t['id']?>"><?=h($t['title'])?></a>
              <div class="item-meta"><?=h($t['project_name'])?></div>
            </div>
            <span class="chip <?=member_status_chip($t['status'])?>"><?=h($t['status'])?></span>
          </article>
        <?php endforeach; endif; ?>
      </section>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
