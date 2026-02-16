<?php
require_once __DIR__ . '/layout.php';
$pdo = db(); $ws = auth_workspace_id();
$q = trim($_GET['q'] ?? '');
$clients = $projects = $tasks = [];
if ($q !== '') {
  $like = '%' . $q . '%';
  $st = $pdo->prepare('SELECT id,name FROM clients WHERE workspace_id=? AND name LIKE ? ORDER BY id DESC LIMIT 20');
  $st->execute([$ws,$like]); $clients=$st->fetchAll();
  $st = $pdo->prepare('SELECT id,name FROM projects WHERE workspace_id=? AND name LIKE ? ORDER BY id DESC LIMIT 20');
  $st->execute([$ws,$like]); $projects=$st->fetchAll();
  $st = $pdo->prepare('SELECT id,title,status FROM tasks WHERE workspace_id=? AND title LIKE ? ORDER BY id DESC LIMIT 20');
  $st->execute([$ws,$like]); $tasks=$st->fetchAll();
}
?>
<h1 class="mb-3">Search</h1>
<form class="row g-2 mb-4" method="get">
  <div class="col-md-8"><input class="form-control" name="q" value="<?=h($q)?>" placeholder="Search clients, projects, tasks..."></div>
  <div class="col-md-4"><button class="btn btn-yellow">Search</button></div>
</form>
<?php if ($q===''): ?>
  <div class="text-muted">Type a keyword to search.</div>
<?php else: ?>
  <div class="row g-3">
    <div class="col-md-4"><div class="card p-3"><h5>Clients</h5><ul class="mb-0"><?php foreach($clients as $c): ?><li><a href="client_view.php?id=<?=$c['id']?>"><?=h($c['name'])?></a></li><?php endforeach; ?></ul></div></div>
    <div class="col-md-4"><div class="card p-3"><h5>Projects</h5><ul class="mb-0"><?php foreach($projects as $p): ?><li><a href="project_view.php?id=<?=$p['id']?>"><?=h($p['name'])?></a></li><?php endforeach; ?></ul></div></div>
    <div class="col-md-4"><div class="card p-3"><h5>Tasks</h5><ul class="mb-0"><?php foreach($tasks as $t): ?><li><a href="task_view.php?id=<?=$t['id']?>"><?=h($t['title'])?></a> <span class="text-muted">(<?=h($t['status'])?>)</span></li><?php endforeach; ?></ul></div></div>
  </div>
<?php endif; ?>
<?php require_once __DIR__ . '/layout_end.php';
?>
