<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();
$role=auth_user()['role_name'] ?? '';
$can_manage=in_array($role,['CEO','CTO','Super Admin'],true);

$id=(int)($_GET['id'] ?? 0);
$stmt=$pdo->prepare("SELECT * FROM clients WHERE id=? AND workspace_id=?");
$stmt->execute([$id,$ws]);
$client=$stmt->fetch();
if(!$client){ echo "<h3>Client not found</h3>"; require __DIR__ . '/layout_end.php'; exit; }

$types=$pdo->query("SELECT id,name FROM project_types WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();
$statuses=$pdo->query("SELECT id,name FROM project_statuses WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_project'])){
  require_post(); csrf_verify();
  if(!$can_manage){ flash_set('error','No permission.'); redirect("client_view.php?id=$id"); }
  $name=trim($_POST['name'] ?? '');
  $type_id=(int)($_POST['type_id'] ?? 0);
  $status_id=(int)($_POST['status_id'] ?? 0);
  if($name===''){ flash_set('error','Project name required.'); redirect("client_view.php?id=$id"); }
  $pdo->prepare("INSERT INTO projects (workspace_id,client_id,name,type_id,status_id,due_date,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)")
      ->execute([$ws,$id,$name,$type_id,$status_id,$_POST['due_date']?:null,null,now(),now()]);
  flash_set('success','Project created.');
  redirect("client_view.php?id=$id");
}

$projects=$pdo->query("SELECT p.*, pt.name AS type_name, ps.name AS status_name
  FROM projects p
  JOIN project_types pt ON pt.id=p.type_id
  JOIN project_statuses ps ON ps.id=p.status_id
  WHERE p.workspace_id=$ws AND p.client_id=$id
  ORDER BY p.id DESC")->fetchAll();
?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h2 class="mb-0"><?=h($client['name'])?></h2>
    <div class="text-muted small">Client</div>
  </div>
  <?php if($can_manage): ?><button class="btn btn-yellow" data-bs-toggle="modal" data-bs-target="#addProject">Add Project</button><?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <div class="text-muted small">Notes</div>
  <div><?=h($client['notes'] ?? '—')?></div>
</div>

<div class="card p-3">
  <h5 class="mb-3">Projects</h5>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Name</th><th>Type</th><th>Status</th><th>Due</th><th></th></tr></thead>
      <tbody>
        <?php foreach($projects as $p): ?>
          <tr>
            <td class="fw-semibold"><?=h($p['name'])?></td>
            <td class="text-muted"><?=h($p['type_name'])?></td>
            <td><span class="badge badge-soft"><?=h($p['status_name'])?></span></td>
            <td class="text-muted"><?=h($p['due_date'] ? format_date($p['due_date']) : '—')?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-light" href="project_view.php?id=<?=h($p['id'])?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$projects): ?><tr><td colspan="5" class="text-muted">No projects yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if($can_manage): ?>
<div class="modal fade" id="addProject" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content card p-3">
      <div class="modal-header border-0"><h5 class="modal-title">Add Project</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="create_project" value="1">
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Project Name</label><input class="form-control" name="name" required></div>
          <div class="mb-3"><label class="form-label">Type</label>
            <select class="form-select" name="type_id" required><?php foreach($types as $t): ?><option value="<?=h($t['id'])?>"><?=h($t['name'])?></option><?php endforeach; ?></select>
          </div>
          <div class="mb-3"><label class="form-label">Status</label>
            <select class="form-select" name="status_id" required><?php foreach($statuses as $s): ?><option value="<?=h($s['id'])?>"><?=h($s['name'])?></option><?php endforeach; ?></select>
          </div>
          <div class="mb-3"><label class="form-label">Due Date (optional)</label><input class="form-control" type="date" name="due_date"></div>
        </div>
        <div class="modal-footer border-0">
          <button class="btn btn-outline-light" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-yellow" type="submit">Create</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/layout_end.php'; ?>
