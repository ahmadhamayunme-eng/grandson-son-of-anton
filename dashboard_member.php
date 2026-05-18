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
  /* All theme-sensitive colors go through the anton.css token system so this
     page flips between light and dark automatically. No hardcoded rgba()
     surfaces — that was the bug the user flagged. */
  .member-shell { border: 1px solid var(--border); border-radius: 18px; background: var(--surface); box-shadow: var(--shadow-card); padding: 22px; margin: 22px 32px; }
  .member-title { margin: 0 0 16px; font-size: 2rem; font-weight: 600; color: var(--text); letter-spacing: -0.01em; }
  .kpi-card { border: 1px solid var(--border); border-radius: 12px; background: var(--surface-2); padding: 14px 16px; min-height: 94px; }
  .kpi-label { color: var(--text-muted); font-size: .9rem; margin-bottom: 6px; }
  .kpi-value { font-size: 2rem; font-weight: 600; line-height: 1.1; color: var(--text); font-variant-numeric: tabular-nums; }
  .panel { border: 1px solid var(--border); border-radius: 12px; background: var(--surface-2); }
  .panel-head { padding: 14px 16px; border-bottom: 1px solid var(--border); font-size: 1.25rem; font-weight: 600; color: var(--text); }
  .row-item { display: flex; justify-content: space-between; gap: 14px; padding: 12px 16px; border-bottom: 1px solid var(--border); }
  .row-item:last-child { border-bottom: 0; }
  .item-title { color: var(--text); text-decoration: none; font-weight: 500; font-size: 1.04rem; }
  .item-title:hover { color: var(--accent); }
  .item-meta { color: var(--text-muted); font-size: .9rem; }
  .chip { border-radius: 999px; padding: 3px 10px; font-size: .78rem; border: 1px solid transparent; align-self: center; }
  .chip-yellow { background: var(--accent-soft); border-color: rgba(250,204,21,0.36); color: var(--accent); }
  .chip-green { background: var(--success-soft); border-color: rgba(34,197,94,0.30); color: var(--success); }
  .chip-red { background: var(--danger-soft); border-color: rgba(239,68,68,0.30); color: var(--danger); }
  .chip-purple { background: rgba(168,85,247,0.14); border-color: rgba(168,85,247,0.30); color: #a855f7; }
  /* Yellow chip text is overridden globally in anton.css's light-mode block to a dark neutral — no need for a per-page color here. */
  /* Make sure the "No tasks" Bootstrap helper reads correctly in light mode. */
  html.light .member-shell .text-muted { color: var(--text-muted) !important; }
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
