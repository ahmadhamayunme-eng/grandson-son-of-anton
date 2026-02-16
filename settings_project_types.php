<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();
$role=auth_user()['role_name'] ?? '';
if(!in_array($role,['CEO','CTO','Super Admin'],true)){ http_response_code(403); echo "Forbidden"; require __DIR__ . '/layout_end.php'; exit; }

if($_SERVER['REQUEST_METHOD']==='POST'){
  require_post(); csrf_verify();
  $name=trim($_POST['name'] ?? '');
  if($name===''){ flash_set('error','Name required.'); redirect('settings_project_types.php'); }
  $sort=(int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM project_types WHERE workspace_id=$ws")->fetch()['n'];
  $pdo->prepare("INSERT INTO project_types (workspace_id,name,sort_order,created_at,updated_at) VALUES (?,?,?,?,?)")
      ->execute([$ws,$name,$sort,now(),now()]);
  flash_set('success','Added.');
  redirect('settings_project_types.php');
}
$rows=$pdo->query("SELECT * FROM project_types WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();
?>
<h2 class="mb-3">Project Types</h2>
<div class="card p-3 mb-3">
  <form method="post" class="row g-2">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="col-md-10"><input class="form-control" name="name" placeholder="e.g. Website Redesign" required></div>
    <div class="col-md-2"><button class="btn btn-yellow w-100">Add</button></div>
  </form>
</div>
<div class="card p-3">
  <table class="table table-hover align-middle mb-0">
    <thead><tr><th>Name</th><th class="text-muted">Sort</th></tr></thead>
    <tbody>
      <?php foreach($rows as $r): ?><tr><td class="fw-semibold"><?=h($r['name'])?></td><td class="text-muted"><?=h($r['sort_order'])?></td></tr><?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="2" class="text-muted">No types.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
