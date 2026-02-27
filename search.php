<?php
require_once __DIR__ . '/layout.php';
$pdo = db();
$ws = auth_workspace_id();
$q = trim($_GET['q'] ?? '');
$clients = $projects = $tasks = [];
if ($q !== '') {
  $like = '%' . $q . '%';
  $st = $pdo->prepare('SELECT id,name,updated_at FROM clients WHERE workspace_id=? AND name LIKE ? ORDER BY id DESC LIMIT 20');
  $st->execute([$ws, $like]);
  $clients = $st->fetchAll();

  $st = $pdo->prepare('SELECT p.id,p.name,c.name AS client_name,ps.name AS status_name,p.updated_at
    FROM projects p
    JOIN clients c ON c.id=p.client_id
    JOIN project_statuses ps ON ps.id=p.status_id
    WHERE p.workspace_id=? AND p.name LIKE ? ORDER BY p.id DESC LIMIT 20');
  $st->execute([$ws, $like]);
  $projects = $st->fetchAll();

  $st = $pdo->prepare('SELECT t.id,t.title,t.status,p.name AS project_name,c.name AS client_name,t.updated_at
    FROM tasks t
    JOIN projects p ON p.id=t.project_id
    JOIN clients c ON c.id=p.client_id
    WHERE t.workspace_id=? AND t.title LIKE ? ORDER BY t.id DESC LIMIT 20');
  $st->execute([$ws, $like]);
  $tasks = $st->fetchAll();
}
?>
<style>
  .search-shell {
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 18px;
    background: linear-gradient(130deg, rgba(17, 18, 25, 0.93), rgba(10, 11, 16, 0.93));
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
    padding: 24px;
  }
  .search-title { font-size: 2rem; font-weight: 600; margin-bottom: 16px; }
  .search-form { position: relative; margin-bottom: 18px; }
  .search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #ffd453;
    pointer-events: none;
  }
  .search-input {
    width: 100%;
    background: linear-gradient(90deg, rgba(32, 33, 42, 0.95), rgba(23, 24, 34, 0.92));
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    padding: 12px 52px 12px 44px;
    color: #f3f3f5;
    font-size: 1.07rem;
    font-weight: 500;
  }
  .search-input:focus {
    outline: none;
    border-color: rgba(255, 212, 83, 0.55);
    box-shadow: 0 0 0 3px rgba(255, 212, 83, 0.15);
  }
  .search-clear {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 0;
    background: rgba(255, 255, 255, 0.18);
    color: rgba(255, 255, 255, 0.85);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
  }
  .search-tabs { display: flex; gap: 22px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); margin-bottom: 16px; }
  .search-tab { color: rgba(255, 255, 255, 0.72); text-decoration: none; padding-bottom: 10px; font-size: 1.05rem; font-weight: 500; }
  .search-tab.active { color: #f3d46c; border-bottom: 2px solid #f3d46c; }
  .search-section { margin-bottom: 16px; }
  .search-section h2 { font-size: 1.9rem; margin: 0 0 10px; }
  .search-list {
    border: 1px solid rgba(255, 255, 255, 0.07);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.02);
    overflow: hidden;
  }
  .search-item {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  }
  .search-item:last-child { border-bottom: 0; }
  .search-main { display: flex; gap: 10px; }
  .search-bullet { width: 10px; height: 10px; border-radius: 50%; background: #f2d062; margin-top: 8px; flex: none; }
  .search-name { color: #ededee; font-size: 1.05rem; font-weight: 500; text-decoration: none; }
  .search-name:hover { color: #f7d86f; }
  .search-meta { color: rgba(255, 255, 255, 0.6); font-size: .9rem; margin-top: 2px; }
  .search-chip {
    border: 1px solid rgba(255, 212, 83, 0.26);
    background: rgba(255, 212, 83, 0.11);
    color: #f6d66e;
    border-radius: 999px;
    padding: 4px 10px;
    font-size: .78rem;
    align-self: center;
  }
</style>

<div class="search-shell">
  <h1 class="search-title">Search</h1>

  <form class="search-form" method="get">
    <span class="search-icon">⌕</span>
    <input class="search-input" name="q" value="<?=h($q)?>" placeholder="Search clients, projects, tasks..." autocomplete="off">
    <?php if ($q !== ''): ?>
      <a href="search.php" class="search-clear" aria-label="Clear search">✕</a>
    <?php endif; ?>
  </form>

  <nav class="search-tabs">
    <a class="search-tab active" href="#tasks">Tasks</a>
    <a class="search-tab" href="#projects">Projects</a>
    <a class="search-tab" href="#clients">Clients</a>
  </nav>

  <?php if ($q === ''): ?>
    <div class="text-muted">Type a keyword and press enter to see matching tasks, projects, and clients.</div>
  <?php else: ?>
    <section id="tasks" class="search-section">
      <h2>Tasks</h2>
      <div class="search-list">
        <?php if (!$tasks): ?>
          <div class="p-3 text-muted">No tasks matched “<?=h($q)?>”.</div>
        <?php else: foreach ($tasks as $t): ?>
          <article class="search-item">
            <div class="search-main">
              <span class="search-bullet"></span>
              <div>
                <a class="search-name" href="task_view.php?id=<?=$t['id']?>"><?=h($t['title'])?></a>
                <div class="search-meta"><?=h($t['project_name'])?> · <?=h($t['client_name'])?></div>
              </div>
            </div>
            <span class="search-chip"><?=h($t['status'])?></span>
          </article>
        <?php endforeach; endif; ?>
      </div>
    </section>

    <section id="projects" class="search-section">
      <h2>Projects</h2>
      <div class="search-list">
        <?php if (!$projects): ?>
          <div class="p-3 text-muted">No projects matched “<?=h($q)?>”.</div>
        <?php else: foreach ($projects as $p): ?>
          <article class="search-item">
            <div class="search-main">
              <span class="search-bullet"></span>
              <div>
                <a class="search-name" href="project_view.php?id=<?=$p['id']?>"><?=h($p['name'])?></a>
                <div class="search-meta"><?=h($p['client_name'])?> · Updated <?=h(date('M j, Y', strtotime($p['updated_at'])))?></div>
              </div>
            </div>
            <span class="search-chip"><?=h($p['status_name'])?></span>
          </article>
        <?php endforeach; endif; ?>
      </div>
    </section>

    <section id="clients" class="search-section mb-0">
      <h2>Clients</h2>
      <div class="search-list">
        <?php if (!$clients): ?>
          <div class="p-3 text-muted">No clients matched “<?=h($q)?>”.</div>
        <?php else: foreach ($clients as $c): ?>
          <article class="search-item">
            <div class="search-main">
              <span class="search-bullet"></span>
              <div>
                <a class="search-name" href="client_view.php?id=<?=$c['id']?>"><?=h($c['name'])?></a>
                <div class="search-meta">Updated <?=h(date('M j, Y', strtotime($c['updated_at'])))?></div>
              </div>
            </div>
          </article>
        <?php endforeach; endif; ?>
      </div>
    </section>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
