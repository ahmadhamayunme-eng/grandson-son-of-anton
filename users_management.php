<?php
require_once __DIR__ . '/layout.php';
auth_require_perm('users.manage');
$pdo=db(); $ws=auth_workspace_id();

$roles=$pdo->query("SELECT id,name FROM roles ORDER BY id")->fetchAll();

if($_SERVER['REQUEST_METHOD']==='POST'){
  require_post(); csrf_verify();
  $action=$_POST['action'] ?? '';
  if($action==='create'){
    $name=trim($_POST['name'] ?? '');
    $email=trim($_POST['email'] ?? '');
    $role_id=(int)($_POST['role_id'] ?? 0);
    $pass=$_POST['password'] ?? '';
    if($name===''||$email===''||$role_id<=0||$pass===''){ flash_set('error','Fill all fields'); redirect(basename(__FILE__)); }
    $hash=password_hash($pass, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (workspace_id,role_id,name,email,password_hash,is_active,created_at,updated_at)
      VALUES (?,?,?,?,?,1,NOW(),NOW())")
      ->execute([$ws,$role_id,$name,$email,$hash]);
    flash_set('success','User created');
    redirect(basename(__FILE__));
  }
  if($action==='update'){
    $id=(int)($_POST['id'] ?? 0);
    $name=trim($_POST['name'] ?? '');
    $email=trim($_POST['email'] ?? '');
    $role_id=(int)($_POST['role_id'] ?? 0);
    $active = isset($_POST['is_active']) ? 1 : 0;
    $pdo->prepare("UPDATE users SET name=?, email=?, role_id=?, is_active=?, updated_at=NOW() WHERE id=? AND workspace_id=?")
      ->execute([$name,$email,$role_id,$active,$id,$ws]);
    if(($_POST['password'] ?? '')!==''){
      $hash=password_hash($_POST['password'], PASSWORD_DEFAULT);
      $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? AND workspace_id=?")->execute([$hash,$id,$ws]);
    }
    flash_set('success','User updated');
    redirect(basename(__FILE__));
  }
  if($action==='delete'){
    $id=(int)($_POST['id'] ?? 0);
    if($id === (int)auth_user()['id']){ flash_set('error','Cannot delete yourself'); redirect(basename(__FILE__)); }
    $pdo->prepare("DELETE FROM users WHERE id=? AND workspace_id=?")->execute([$id,$ws]);
    flash_set('success','User deleted');
    redirect(basename(__FILE__));
  }
}

$users=$pdo->prepare("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.workspace_id=? ORDER BY u.id DESC");
$users->execute([$ws]);
$users=$users->fetchAll();

$edit_id=(int)($_GET['edit'] ?? 0);
$edit=null;
if($edit_id>0){
  foreach($users as $uu){ if((int)$uu['id']===$edit_id){ $edit=$uu; break; } }
}
?>
<h2 class="mb-3">Users Management</h2>

<div class="row g-3">
  <div class="col-md-5">
    <div class="card p-3">
      <div class="fw-semibold mb-2"><?= $edit ? 'Edit User' : 'Add User' ?></div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
        <div class="mb-2"><label class="form-label">Name</label><input class="form-control" name="name" value="<?= h($edit['name'] ?? '') ?>" required></div>
        <div class="mb-2"><label class="form-label">Email</label><input class="form-control" name="email" value="<?= h($edit['email'] ?? '') ?>" required></div>
        <div class="mb-2"><label class="form-label">Role</label>
          <select class="form-select" name="role_id" required>
            <option value="">Select role...</option>
            <?php foreach($roles as $r): ?>
              <option value="<?= (int)$r['id'] ?>" <?= ($edit && (int)$edit['role_id']===(int)$r['id'])?'selected':'' ?>><?= h($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2"><label class="form-label">Password <?= $edit ? '(leave blank to keep)' : '' ?></label><input class="form-control" name="password" type="password" <?= $edit ? '' : 'required' ?>></div>
        <?php if($edit): ?>
          <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_active" <?= ((int)$edit['is_active']===1)?'checked':'' ?>> <label class="form-check-label">Active</label></div>
        <?php endif; ?>
        <button class="btn btn-light w-100"><?= $edit ? 'Save Changes' : 'Create User' ?></button>
      </form>
    </div>
  </div>
  <div class="col-md-7">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Users</div>
      <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0">
          <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
            <?php foreach($users as $uu): ?>
            <tr>
              <td><?= h($uu['name']) ?></td>
              <td><?= h($uu['email']) ?></td>
              <td><?= h($uu['role_name']) ?></td>
              <td><?= ((int)$uu['is_active']===1)?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Disabled</span>' ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-light" href="?edit=<?= (int)$uu['id'] ?>">Edit</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete user?');">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$uu['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if(!$users): ?><tr><td colspan="5" class="text-muted">No users.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
