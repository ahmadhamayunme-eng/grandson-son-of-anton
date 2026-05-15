<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();

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
    FROM tasks t JOIN projects p ON p.id=t.project_id JOIN clients c ON c.id=p.client_id
    WHERE t.workspace_id=? AND t.title LIKE ? ORDER BY t.id DESC LIMIT 20');
  $st->execute([$ws, $like]);
  $tasks = $st->fetchAll();
}

$totalResults = count($clients) + count($projects) + count($tasks);

$pageTitle = 'Search';
$activeKey = 'search';
$pageHeadExtra = <<<HTML
<style>
  .page-header { padding: 28px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; }
  .search-form-wrap { max-width: 1640px; margin: 0 auto; width: 100%; padding: 24px 32px 18px; }
  .big-search { position: relative; }
  .big-search svg { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; color: var(--accent); }
  .big-search input { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 48px 14px 50px; font-size: 15px; color: var(--text); outline: none; transition: all 0.15s; font-family: inherit; font-weight: 500; }
  .big-search input::placeholder { color: var(--text-dim); }
  .big-search input:focus { border-color: var(--accent); background: var(--bg); }
  .big-search .clear { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); width: 28px; height: 28px; border-radius: 50%; background: var(--surface-2); color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; text-decoration: none; border: 1px solid var(--border); }
  .big-search .clear:hover { color: var(--text); background: var(--surface-hover); }
  .body-wrap { max-width: 1640px; margin: 0 auto; width: 100%; padding: 0 32px 60px; display: flex; flex-direction: column; gap: 28px; }
  .section { display: flex; flex-direction: column; gap: 12px; }
  .section-head { display: flex; align-items: baseline; gap: 8px; }
  .section-title { font-size: 16px; font-weight: 600; color: var(--text); letter-spacing: -0.01em; }
  .section-count { font-size: 11px; background: var(--surface); color: var(--text-muted); border: 1px solid var(--border); padding: 2px 8px; border-radius: 999px; font-variant-numeric: tabular-nums; font-weight: 500; }
  .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
  .result { padding: 14px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 12px; transition: background 0.12s; }
  .result:last-child { border-bottom: none; }
  .result:hover { background: var(--surface-hover); }
  .result-main { display: flex; align-items: flex-start; gap: 10px; min-width: 0; }
  .result-bullet { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); flex-shrink: 0; margin-top: 8px; box-shadow: 0 0 0 3px rgba(250,204,21,0.18); }
  .result-name { font-size: 14px; font-weight: 500; color: var(--text); text-decoration: none; }
  .result-name:hover { color: var(--accent); }
  .result-meta { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }
  .result-chip { font-size: 11px; padding: 3px 10px; border-radius: 999px; background: var(--accent-soft); color: var(--accent); border: 1px solid rgba(250,204,21,0.25); }
  .empty-state { padding: 28px 18px; text-align: center; color: var(--text-dim); font-size: 13px; font-style: italic; }
  .start-card { padding: 48px 24px; text-align: center; color: var(--text-muted); font-size: 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; max-width: 720px; margin: 18px auto; }
  .start-card svg { width: 36px; height: 36px; color: var(--text-dim); margin-bottom: 14px; }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Search</div>
      <div class="page-sub">Find clients, projects, and tasks across the workspace.</div>
    </div>
  </div>
</div>

<div class="search-form-wrap">
  <form class="big-search" method="get">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
    <input name="q" value="<?= h($q) ?>" placeholder="Search clients, projects, tasks..." autocomplete="off" autofocus>
    <?php if ($q !== ''): ?><a href="search.php" class="clear" aria-label="Clear search">×</a><?php endif; ?>
  </form>
</div>

<div class="body-wrap">
  <?php if ($q === ''): ?>
    <div class="start-card">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
      <div>Type a keyword and press enter to see matching tasks, projects, and clients.</div>
    </div>
  <?php else: ?>
    <section class="section">
      <div class="section-head"><span class="section-title">Tasks</span><span class="section-count"><?= count($tasks) ?></span></div>
      <div class="panel">
        <?php if (!$tasks): ?>
          <div class="empty-state">No tasks matched "<?= h($q) ?>".</div>
        <?php else: foreach ($tasks as $t): ?>
          <div class="result">
            <div class="result-main">
              <span class="result-bullet"></span>
              <div>
                <a class="result-name" href="task_view.php?id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a>
                <div class="result-meta"><?= h($t['project_name']) ?> · <?= h($t['client_name']) ?></div>
              </div>
            </div>
            <span class="result-chip"><?= h($t['status']) ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </section>

    <section class="section">
      <div class="section-head"><span class="section-title">Projects</span><span class="section-count"><?= count($projects) ?></span></div>
      <div class="panel">
        <?php if (!$projects): ?>
          <div class="empty-state">No projects matched "<?= h($q) ?>".</div>
        <?php else: foreach ($projects as $p): ?>
          <div class="result">
            <div class="result-main">
              <span class="result-bullet"></span>
              <div>
                <a class="result-name" href="project_view.php?id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?></a>
                <div class="result-meta"><?= h($p['client_name']) ?> · Updated <?= h(date('M j, Y', strtotime((string)$p['updated_at']))) ?></div>
              </div>
            </div>
            <span class="result-chip"><?= h($p['status_name']) ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </section>

    <section class="section">
      <div class="section-head"><span class="section-title">Clients</span><span class="section-count"><?= count($clients) ?></span></div>
      <div class="panel">
        <?php if (!$clients): ?>
          <div class="empty-state">No clients matched "<?= h($q) ?>".</div>
        <?php else: foreach ($clients as $c): ?>
          <div class="result">
            <div class="result-main">
              <span class="result-bullet"></span>
              <div>
                <a class="result-name" href="client_view.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a>
                <div class="result-meta">Updated <?= h(date('M j, Y', strtotime((string)$c['updated_at']))) ?></div>
              </div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </section>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
