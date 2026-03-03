<?php
require_once __DIR__ . '/layout.php';
auth_require_perm('users.manage');
$pdo = db();
$ws = auth_workspace_id();

$roles = $pdo->query("SELECT id,name FROM roles ORDER BY id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  csrf_verify();
  $action = $_POST['action'] ?? '';
  if ($action === 'create') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role_id = (int)($_POST['role_id'] ?? 0);
    $pass = $_POST['password'] ?? '';
    if ($name === '' || $email === '' || $role_id <= 0 || $pass === '') {
      flash_set('error', 'Fill all fields');
      redirect(basename(__FILE__));
    }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (workspace_id,role_id,name,email,password_hash,is_active,created_at,updated_at)
      VALUES (?,?,?,?,?,1,NOW(),NOW())")
      ->execute([$ws, $role_id, $name, $email, $hash]);
    flash_set('success', 'User created');
    redirect(basename(__FILE__));
  }
  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role_id = (int)($_POST['role_id'] ?? 0);
    $active = isset($_POST['is_active']) ? 1 : 0;
    $pdo->prepare("UPDATE users SET name=?, email=?, role_id=?, is_active=?, updated_at=NOW() WHERE id=? AND workspace_id=?")
      ->execute([$name, $email, $role_id, $active, $id, $ws]);
    if (($_POST['password'] ?? '') !== '') {
      $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
      $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? AND workspace_id=?")->execute([$hash, $id, $ws]);
    }
    flash_set('success', 'User updated');
    redirect(basename(__FILE__));
  }
  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id === (int)auth_user()['id']) {
      flash_set('error', 'Cannot delete yourself');
      redirect(basename(__FILE__));
    }
    $pdo->prepare("DELETE FROM users WHERE id=? AND workspace_id=?")->execute([$id, $ws]);
    flash_set('success', 'User deleted');
    redirect(basename(__FILE__));
  }
}

$users = $pdo->prepare("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.workspace_id=? ORDER BY u.id DESC");
$users->execute([$ws]);
$users = $users->fetchAll();

$edit_id = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($edit_id > 0) {
  foreach ($users as $uu) {
    if ((int)$uu['id'] === $edit_id) {
      $edit = $uu;
      break;
    }
  }
}

$totalUsers = count($users);
$activeUsers = 0;
$disabledUsers = 0;
foreach ($users as $urow) {
  if ((int)$urow['is_active'] === 1) $activeUsers++;
  else $disabledUsers++;
}
?>

<style>
  .users-shell{border:1px solid rgba(255,255,255,.1);border-radius:18px;background:linear-gradient(180deg,#111111,#090909);padding:1.15rem;box-shadow:0 24px 60px rgba(0,0,0,.4)}
  .users-head{display:flex;justify-content:space-between;align-items:center;gap:.8rem;margin-bottom:.95rem}
  .users-title{margin:0;font-size:2rem;font-weight:700}
  .users-sub{color:rgba(232,232,232,.68);font-size:.92rem}
  .users-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.65rem;margin-bottom:.95rem}
  .users-metric{border:1px solid rgba(255,255,255,.1);border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,.045),rgba(255,255,255,.02));padding:.72rem .85rem}
  .users-metric-label{font-size:.82rem;color:rgba(232,232,232,.68)}
  .users-metric-value{font-size:1.55rem;font-weight:700;line-height:1.1}
  .users-grid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:.8rem}
  .users-card{border:1px solid rgba(255,255,255,.1);border-radius:12px;background:rgba(255,255,255,.02);padding:.85rem}
  .users-card-title{font-size:1.02rem;font-weight:600;margin-bottom:.6rem}
  .users-form .form-label{font-size:.82rem;color:rgba(232,232,232,.72)}
  .users-table{overflow:hidden;border:1px solid rgba(255,255,255,.08);border-radius:10px;background:rgba(255,255,255,.015)}
  .users-table table{width:100%;border-collapse:collapse}
  .users-table th,.users-table td{padding:.68rem .72rem;border-bottom:1px solid rgba(255,255,255,.07)}
  .users-table th{background:rgba(255,255,255,.03);font-size:.85rem;font-weight:600;color:rgba(228,228,228,.82)}
  .users-table tr:last-child td{border-bottom:0}
  .status-pill{display:inline-flex;padding:.23rem .6rem;border-radius:999px;border:1px solid transparent;font-size:.75rem;font-weight:600}
  .status-active{color:#78dfab;border-color:rgba(87,200,143,.35);background:rgba(87,200,143,.12)}
  .status-disabled{color:#d5d5d5;border-color:rgba(255,255,255,.22);background:rgba(255,255,255,.08)}

  .password-input-wrap{position:relative}
  .password-input-wrap .form-control{padding-right:2.5rem}
  .password-toggle-btn{position:absolute;right:.45rem;top:50%;transform:translateY(-50%);border:0;background:transparent;color:rgba(232,232,232,.7);display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;padding:0;cursor:pointer}
  .password-toggle-btn:hover{color:#f0cb47}
  .password-toggle-btn:focus{outline:none;color:#f0cb47}
  .password-toggle-btn svg{width:18px;height:18px}
  @media (max-width: 1100px){.users-grid{grid-template-columns:1fr}.users-metrics{grid-template-columns:1fr}}
</style>

<div class="users-shell">
  <div class="users-head">
    <div>
      <h1 class="users-title">Users Management</h1>
      <div class="users-sub">Create, update, and manage account status and roles.</div>
    </div>
  </div>

  <div class="users-metrics">
    <div class="users-metric"><div class="users-metric-label">Total Users</div><div class="users-metric-value"><?= (int)$totalUsers ?></div></div>
    <div class="users-metric"><div class="users-metric-label">Active</div><div class="users-metric-value"><?= (int)$activeUsers ?></div></div>
    <div class="users-metric"><div class="users-metric-label">Disabled</div><div class="users-metric-value"><?= (int)$disabledUsers ?></div></div>
  </div>

  <div class="users-grid">
    <div class="users-card">
      <div class="users-card-title"><?= $edit ? 'Edit User' : 'Add User' ?></div>
      <form class="users-form" method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
        <div class="mb-2"><label class="form-label">Name</label><input class="form-control" name="name" value="<?= h($edit['name'] ?? '') ?>" required></div>
        <div class="mb-2"><label class="form-label">Email</label><input class="form-control" name="email" value="<?= h($edit['email'] ?? '') ?>" required></div>
        <div class="mb-2">
          <label class="form-label">Role</label>
          <select class="form-select" name="role_id" required>
            <option value="">Select role...</option>
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int)$r['id'] ?>" <?= ($edit && (int)$edit['role_id'] === (int)$r['id']) ? 'selected' : '' ?>><?= h($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2"><label class="form-label">Password <?= $edit ? '(leave blank to keep)' : '' ?></label><div class="password-input-wrap"><input class="form-control" id="user-password" name="password" type="password" <?= $edit ? '' : 'required' ?>><button class="password-toggle-btn" type="button" aria-label="Show password" data-toggle-password="user-password" data-show-label="Show password" data-hide-label="Hide password"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M2.2 12C3.9 8.6 7.4 6.3 12 6.3C16.6 6.3 20.1 8.6 21.8 12C20.1 15.4 16.6 17.7 12 17.7C7.4 17.7 3.9 15.4 2.2 12Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg></button></div></div>
        <?php if ($edit): ?>
          <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_active" <?= ((int)$edit['is_active'] === 1) ? 'checked' : '' ?>> <label class="form-check-label">Active</label></div>
        <?php endif; ?>
        <button class="btn btn-yellow w-100"><?= $edit ? 'Save Changes' : 'Create User' ?></button>
      </form>
    </div>

    <div class="users-card">
      <div class="users-card-title">Users</div>
      <div class="users-table">
        <table>
          <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($users as $uu): ?>
              <tr>
                <td class="fw-semibold"><?= h($uu['name']) ?></td>
                <td class="text-muted"><?= h($uu['email']) ?></td>
                <td><?= h($uu['role_name']) ?></td>
                <td><?= ((int)$uu['is_active'] === 1) ? '<span class="status-pill status-active">Active</span>' : '<span class="status-pill status-disabled">Disabled</span>' ?></td>
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
            <?php if (!$users): ?><tr><td colspan="5" class="text-muted">No users found.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>


<script>
  (function(){
    function eyeSvg(){
      return '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M2.2 12C3.9 8.6 7.4 6.3 12 6.3C16.6 6.3 20.1 8.6 21.8 12C20.1 15.4 16.6 17.7 12 17.7C7.4 17.7 3.9 15.4 2.2 12Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>';
    }
    function eyeOffSvg(){
      return '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M3.2 3.2L20.8 20.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M10.6 6.4C11 6.33 11.5 6.3 12 6.3C16.6 6.3 20.1 8.6 21.8 12C21 13.6 19.8 15 18.3 16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M14.1 14.1C13.6 14.6 12.9 15 12 15C10.3 15 9 13.7 9 12C9 11.1 9.4 10.4 9.9 9.9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M6.1 7.6C4.5 8.6 3.2 10.1 2.2 12C3.9 15.4 7.4 17.7 12 17.7C13.8 17.7 15.4 17.3 16.8 16.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
    }

    document.querySelectorAll('[data-toggle-password]').forEach(function(btn){
      var input = document.getElementById(btn.getAttribute('data-toggle-password'));
      if (!input) return;
      btn.addEventListener('click', function(){
        var hidden = input.type === 'password';
        input.type = hidden ? 'text' : 'password';
        btn.innerHTML = hidden ? eyeOffSvg() : eyeSvg();
        btn.setAttribute('aria-label', hidden ? (btn.dataset.hideLabel || 'Hide password') : (btn.dataset.showLabel || 'Show password'));
      });
    });
  })();
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
