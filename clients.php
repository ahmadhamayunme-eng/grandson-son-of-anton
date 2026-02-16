<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();
$role=auth_user()['role_name'] ?? '';
$can_manage=in_array($role,['CEO','CTO','Super Admin'],true);

if($_SERVER['REQUEST_METHOD']==='POST'){
  require_post(); csrf_verify();
  if(!$can_manage){ flash_set('error','No permission.'); redirect('clients.php'); }
  $name=trim($_POST['name'] ?? '');
  $notes=trim($_POST['notes'] ?? '');
  if($name===''){ flash_set('error','Client name required.'); redirect('clients.php'); }
  $pdo->prepare("INSERT INTO clients (workspace_id,name,notes,created_at,updated_at) VALUES (?,?,?,?,?)")
      ->execute([$ws,$name,$notes?:null,now(),now()]);
  flash_set('success','Client created.');
  redirect('clients.php');
}
$clients=$pdo->query("SELECT * FROM clients WHERE workspace_id=$ws ORDER BY id DESC")->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2 class="mb-0">Clients</h2>
  <?php if($can_manage): ?><button class="btn btn-yellow" data-bs-toggle="modal" data-bs-target="#addClient">Add Client</button><?php endif; ?>
</div>
<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Name</th><th class="text-muted">Notes</th><th></th></tr></thead>
      <tbody>
        <?php foreach($clients as $c): ?>
          <tr>
            <td class="fw-semibold"><?=h($c['name'])?></td>
            <td class="text-muted"><?=h(mb_strimwidth($c['notes'] ?? '',0,70,'…'))?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-light" href="client_view.php?id=<?=h($c['id'])?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$clients): ?><tr><td colspan="3" class="text-muted">No clients yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if($can_manage): ?>
<div class="modal fade" id="addClient" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content card p-3">
      <div class="modal-header border-0">
        <h5 class="modal-title">Add Client</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Client Name</label><input class="form-control" name="name" required></div>
          <div class="mb-3"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="3"></textarea></div>
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
