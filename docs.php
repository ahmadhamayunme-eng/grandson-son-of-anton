<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();
$role=auth_user()['role_name'] ?? '';
$can_manage=in_array($role,['CEO','CTO','Super Admin'],true);

$project_id=(int)($_GET['project_id'] ?? 0);
$q="SELECT d.*, p.name AS project_name, c.name AS client_name, u.name AS author
  FROM docs d
  JOIN projects p ON p.id=d.project_id
  JOIN clients c ON c.id=p.client_id
  JOIN users u ON u.id=d.created_by
  WHERE d.workspace_id=$ws";
$params=[];
if($project_id){ $q.=" AND d.project_id=?"; $params[]=$project_id; }
$q.=" ORDER BY d.id DESC";
$stmt=$pdo->prepare($q); $stmt->execute($params); $docs=$stmt->fetchAll();

if($_SERVER['REQUEST_METHOD']==='POST'){
  require_post(); csrf_verify();
  if(!$can_manage){ flash_set('error','No permission.'); redirect('docs.php'.($project_id?('?project_id='.$project_id):'')); }
  $title=trim($_POST['title'] ?? '');
  $pid=(int)($_POST['project_id'] ?? 0);
  $content=trim($_POST['content'] ?? '');
  if($title==='' || !$pid){ flash_set('error','Title and project required.'); redirect('docs.php'); }
  $pdo->prepare("INSERT INTO docs (workspace_id,project_id,title,content,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?)")
      ->execute([$ws,$pid,$title,$content,auth_user()['id'],now(),now()]);
  flash_set('success','Doc created.');
  redirect('docs.php'.($project_id?('?project_id='.$project_id):''));
}

$projects=$pdo->query("SELECT id,name FROM projects WHERE workspace_id=$ws ORDER BY id DESC")->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2 class="mb-0">Docs</h2>
  <?php if($can_manage): ?><button class="btn btn-yellow" data-bs-toggle="modal" data-bs-target="#addDoc">Add Doc</button><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Title</th><th>Client</th><th>Project</th><th>Author</th><th>Updated</th><th></th></tr></thead>
      <tbody>
        <?php foreach($docs as $d): ?>
          <tr>
            <td class="fw-semibold"><?=h($d['title'])?></td>
            <td class="text-muted"><?=h($d['client_name'])?></td>
            <td class="text-muted"><?=h($d['project_name'])?></td>
            <td class="text-muted"><?=h($d['author'])?></td>
            <td class="text-muted"><?=h($d['updated_at'])?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-light" href="doc_edit.php?id=<?=h($d['id'])?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$docs): ?><tr><td colspan="6" class="text-muted">No docs yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if($can_manage): ?>
<div class="modal fade" id="addDoc" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content card p-3">
      <div class="modal-header border-0"><h5 class="modal-title">Add Doc</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Title</label><input class="form-control" name="title" required></div>
            <div class="col-md-4"><label class="form-label">Project</label>
              <select class="form-select" name="project_id" required>
                <?php foreach($projects as $p): ?><option value="<?=h($p['id'])?>" <?= $project_id===(int)$p['id'] ? 'selected' : '' ?>><?=h($p['name'])?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-12"><label class="form-label">Content</label><textarea class="form-control" name="content" rows="10" placeholder="Write doc..."></textarea></div>
          </div>
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
