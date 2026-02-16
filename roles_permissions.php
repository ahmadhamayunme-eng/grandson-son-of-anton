<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();

// Ensure permission keys exist
$defaultPerms = [
  ['finance.view','View Finance'],
  ['settings.manage','Manage Settings'],
  ['users.manage','Manage Users'],
  ['projects.manage','Manage Projects'],
  ['tasks.manage','Manage Tasks'],
  ['docs.manage','Manage Docs'],
];
foreach($defaultPerms as $p){
  try {
    $pdo->prepare("INSERT IGNORE INTO permissions (perm_key,label) VALUES (?,?)")->execute([$p[0],$p[1]]);
  } catch(Throwable $e){}
}

$roles=$pdo->query("SELECT id,name FROM roles ORDER BY id")->fetchAll();
$perms=$pdo->query("SELECT id,perm_key,label FROM permissions ORDER BY perm_key")->fetchAll();

if($_SERVER['REQUEST_METHOD']==='POST'){
  require_post(); csrf_verify();
  $role_id=(int)($_POST['role_id'] ?? 0);
  if($role_id>0){
    foreach($perms as $perm){
      $key='perm_'.$perm['id'];
      $allowed = isset($_POST[$key]) ? 1 : 0;
      $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id,is_allowed) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE is_allowed=VALUES(is_allowed)")->execute([$role_id,(int)$perm['id'],$allowed]);
    }
    flash_set('success','Permissions saved');
    redirect(basename(__FILE__).'?role_id='.$role_id);
  }
}

$role_id=(int)($_GET['role_id'] ?? ($roles[0]['id'] ?? 0));
$allowedMap=[];
if($role_id>0){
  $st=$pdo->prepare("SELECT permission_id,is_allowed FROM role_permissions WHERE role_id=?");
  $st->execute([$role_id]);
  foreach($st->fetchAll() as $r){ $allowedMap[(int)$r['permission_id']] = (int)$r['is_allowed']; }
}
?>
<h2 class="mb-3">Roles & Permissions</h2>

<div class="row g-3">
  <div class="col-md-4">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Roles</div>
      <div class="list-group list-group-flush">
        <?php foreach($roles as $r): ?>
          <a class="list-group-item list-group-item-dark <?= $role_id===(int)$r['id']?'active':'' ?>" href="?role_id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-md-8">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Permissions</div>
      <?php if($role_id<=0): ?>
        <div class="text-muted">No roles found.</div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="role_id" value="<?= (int)$role_id ?>">
        <div class="table-responsive">
          <table class="table table-dark table-hover align-middle mb-0">
            <thead><tr><th>Permission</th><th>Key</th><th class="text-end">Allowed</th></tr></thead>
            <tbody>
              <?php foreach($perms as $p): ?>
              <tr>
                <td><?= h($p['label']) ?></td>
                <td class="text-muted small"><?= h($p['perm_key']) ?></td>
                <td class="text-end">
                  <input class="form-check-input" type="checkbox" name="perm_<?= (int)$p['id'] ?>" <?= (!empty($allowedMap[(int)$p['id']]))?'checked':'' ?>>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="d-flex justify-content-end mt-3">
          <button class="btn btn-light">Save</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
