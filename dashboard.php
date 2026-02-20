<?php
require_once __DIR__ . '/layout.php';
$pdo = db();
$ws = auth_workspace_id();
$clients = (int)$pdo->query("SELECT COUNT(*) c FROM clients WHERE workspace_id=$ws")->fetch()['c'];
$projects = (int)$pdo->query("SELECT COUNT(*) c FROM projects WHERE workspace_id=$ws")->fetch()['c'];
$tasks = (int)$pdo->query("SELECT COUNT(*) c FROM tasks WHERE workspace_id=$ws AND status IN ('To Do','In Progress','Completed (Needs CTO Review)','Approved (Ready to Submit)')")->fetch()['c'];

$taskSt = $pdo->prepare('SELECT t.id, t.title, t.status, p.name AS project_name, c.name AS client_name
  FROM tasks t
  JOIN projects p ON p.id=t.project_id
  JOIN clients c ON c.id=p.client_id
  WHERE t.workspace_id=?
  ORDER BY t.updated_at DESC, t.id DESC
  LIMIT 5');
$taskSt->execute([$ws]);
$recentTasks = $taskSt->fetchAll();

$projectSt = $pdo->prepare('SELECT p.id, p.name, c.name AS client_name, ps.name AS status_name, p.updated_at
  FROM projects p
  JOIN clients c ON c.id=p.client_id
  JOIN project_statuses ps ON ps.id=p.status_id
  WHERE p.workspace_id=?
  ORDER BY p.updated_at DESC, p.id DESC
  LIMIT 5');
$projectSt->execute([$ws]);
$recentProjects = $projectSt->fetchAll();

$clientSt = $pdo->prepare('SELECT id, name, updated_at FROM clients WHERE workspace_id=? ORDER BY updated_at DESC, id DESC LIMIT 5');
$clientSt->execute([$ws]);
$recentClients = $clientSt->fetchAll();
?>
<style>
  .dash-shell {
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 18px;
    background: linear-gradient(120deg, rgba(20, 21, 27, 0.9), rgba(11, 12, 17, 0.92));
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.42);
    padding: 24px;
  }
  .dash-title { font-size: 2rem; font-weight: 600; margin-bottom: 16px; }
  .dash-search-link {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: #e8e8ea;
    background: linear-gradient(90deg, rgba(34, 35, 44, 0.95), rgba(20, 21, 28, 0.9));
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 18px;
  }
  .dash-search-link .dash-dot { color: #ffd453; font-size: 1.2rem; line-height: 1; }
  .dash-metric {
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.02);
    padding: 14px 16px;
  }
  .dash-metric-label { font-size: .85rem; color: rgba(255, 255, 255, 0.62); text-transform: uppercase; letter-spacing: .06em; }
  .dash-metric-value { font-size: 1.8rem; font-weight: 600; margin-top: 6px; }
  .dash-block { border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 14px; background: rgba(255, 255, 255, 0.02); overflow: hidden; }
  .dash-block-title { margin: 0; padding: 14px 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); font-size: 1.15rem; font-weight: 600; }
  .dash-item { display: flex; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.06); }
  .dash-item:last-child { border-bottom: 0; }
  .dash-item-title { display: flex; align-items: center; gap: 10px; color: #efefef; text-decoration: none; font-weight: 500; }
  .dash-item-title:hover { color: #ffd453; }
  .dash-item-dot { width: 10px; height: 10px; border-radius: 50%; background: #f6d45d; display: inline-block; }
  .dash-item-meta { color: rgba(255, 255, 255, 0.62); font-size: .88rem; margin-top: 4px; }
  .dash-chip {
    align-self: center;
    border: 1px solid rgba(255, 212, 83, 0.3);
    color: #f7d66f;
    background: rgba(255, 212, 83, 0.12);
    border-radius: 999px;
    padding: 4px 10px;
    font-size: .78rem;
    white-space: nowrap;
  }
</style>

<div class="dash-shell">
  <h1 class="dash-title">Dashboard</h1>
  <a href="search.php" class="dash-search-link">
    <span class="dash-dot">⌕</span>
    <span>Search clients, projects, tasks...</span>
  </a>

  <div class="row g-3 mb-3">
    <div class="col-md-4"><div class="dash-metric"><div class="dash-metric-label">Clients</div><div class="dash-metric-value"><?=h($clients)?></div></div></div>
    <div class="col-md-4"><div class="dash-metric"><div class="dash-metric-label">Projects</div><div class="dash-metric-value"><?=h($projects)?></div></div></div>
    <div class="col-md-4"><div class="dash-metric"><div class="dash-metric-label">Open Tasks</div><div class="dash-metric-value"><?=h($tasks)?></div></div></div>
  </div>

  <div class="row g-3">
    <div class="col-lg-7">
      <section class="dash-block mb-3">
        <h2 class="dash-block-title">Tasks</h2>
        <?php if (!$recentTasks): ?>
          <div class="p-3 text-muted">No tasks yet. Start from <a href="my_tasks.php">My Tasks</a>.</div>
        <?php else: foreach ($recentTasks as $t): ?>
          <article class="dash-item">
            <div>
              <a class="dash-item-title" href="task_view.php?id=<?=$t['id']?>"><span class="dash-item-dot"></span><?=h($t['title'])?></a>
              <div class="dash-item-meta"><?=h($t['project_name'])?> · <?=h($t['client_name'])?></div>
            </div>
            <span class="dash-chip"><?=h($t['status'])?></span>
          </article>
        <?php endforeach; endif; ?>
      </section>

      <section class="dash-block">
        <h2 class="dash-block-title">Projects</h2>
        <?php if (!$recentProjects): ?>
          <div class="p-3 text-muted">No projects yet. Add one from <a href="projects.php">Projects</a>.</div>
        <?php else: foreach ($recentProjects as $p): ?>
          <article class="dash-item">
            <div>
              <a class="dash-item-title" href="project_view.php?id=<?=$p['id']?>"><span class="dash-item-dot"></span><?=h($p['name'])?></a>
              <div class="dash-item-meta"><?=h($p['client_name'])?> · Updated <?=h(date('M j, Y', strtotime($p['updated_at'])))?></div>
            </div>
            <span class="dash-chip"><?=h($p['status_name'])?></span>
          </article>
        <?php endforeach; endif; ?>
      </section>
    </div>

    <div class="col-lg-5">
      <section class="dash-block h-100">
        <h2 class="dash-block-title">Clients</h2>
        <?php if (!$recentClients): ?>
          <div class="p-3 text-muted">No clients yet. Add one from <a href="clients.php">Clients</a>.</div>
        <?php else: foreach ($recentClients as $c): ?>
          <article class="dash-item">
            <div>
              <a class="dash-item-title" href="client_view.php?id=<?=$c['id']?>"><span class="dash-item-dot"></span><?=h($c['name'])?></a>
              <div class="dash-item-meta">Updated <?=h(date('M j, Y', strtotime($c['updated_at'])))?></div>
            </div>
          </article>
        <?php endforeach; endif; ?>
      </section>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
