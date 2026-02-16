<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();
$role=auth_user()['role_name'] ?? '';
$can_manage=in_array($role,['CEO','CTO','Super Admin'],true);

$id=(int)($_GET['id'] ?? 0);
$stmt=$pdo->prepare("SELECT d.*, p.name AS project_name, c.name AS client_name
  FROM docs d
  JOIN projects p ON p.id=d.project_id
  JOIN clients c ON c.id=p.client_id
  WHERE d.id=? AND d.workspace_id=?");
$stmt->execute([$id,$ws]);
$doc=$stmt->fetch();
if(!$doc){ echo "<h3>Doc not found</h3>"; require __DIR__ . '/layout_end.php'; exit; }

if($_SERVER['REQUEST_METHOD']==='POST'){
  require_post(); csrf_verify();
  if(!$can_manage){ flash_set('error','No permission.'); redirect("doc_edit.php?id=$id"); }
  $title=trim($_POST['title'] ?? '');
  $content=trim($_POST['content'] ?? '');
  $pdo->prepare("UPDATE docs SET title=?, content=?, updated_at=? WHERE id=? AND workspace_id=?")
      ->execute([$title,$content,now(),$id,$ws]);
  flash_set('success','Doc saved.');
  redirect("doc_edit.php?id=$id");
}
?>
<div class="mb-3">
  <h2 class="mb-1"><?=h($doc['title'])?></h2>
  <div class="text-muted small">Client: <b><?=h($doc['client_name'])?></b> • Project: <b><?=h($doc['project_name'])?></b></div>
</div>

<div class="card p-3">
  <?php if(!$can_manage): ?>
    <div><?=nl2br(h($doc['content'] ?? ''))?></div>
  <?php else: ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="mb-3"><label class="form-label">Title</label><input class="form-control" name="title" value="<?=h($doc['title'])?>" required></div>
    <div class="mb-3"><label class="form-label">Content</label><textarea class="form-control" name="content" rows="14"><?=h($doc['content'] ?? '')?></textarea></div>
    <button class="btn btn-yellow" type="submit">Save</button>
    <a class="btn btn-outline-light ms-2" href="docs.php?project_id=<?=h($doc['project_id'])?>">Back</a>
  </form>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
