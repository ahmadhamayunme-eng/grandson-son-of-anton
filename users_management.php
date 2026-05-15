<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();
auth_require_perm('users.manage');

$pdo = db();
$ws = auth_workspace_id();
$roles = $pdo->query("SELECT id,name FROM roles ORDER BY id")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post(); csrf_verify();
  $action = $_POST['action'] ?? '';
  if ($action === 'create') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role_id = (int)($_POST['role_id'] ?? 0);
    $pass = $_POST['password'] ?? '';
    if ($name === '' || $email === '' || $role_id <= 0 || $pass === '') { flash_set('error', 'Fill all fields'); redirect(basename(__FILE__)); }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (workspace_id,role_id,name,email,password_hash,is_active,created_at,updated_at) VALUES (?,?,?,?,?,1,NOW(),NOW())")
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
    if ($id === (int)auth_user()['id']) { flash_set('error', 'Cannot delete yourself'); redirect(basename(__FILE__)); }
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
foreach ($users as $uu) { if ((int)$uu['id'] === $edit_id) { $edit = $uu; break; } }

$totalUsers = count($users);
$activeUsers = 0; $disabledUsers = 0;
foreach ($users as $urow) { if ((int)$urow['is_active'] === 1) $activeUsers++; else $disabledUsers++; }

function um_avatar_gradient(string $seed): string {
  $palette = [
    'linear-gradient(135deg,#22c55e,#16a34a)','linear-gradient(135deg,#a855f7,#7c3aed)',
    'linear-gradient(135deg,#0ea5e9,#0284c7)','linear-gradient(135deg,#f97316,#ea580c)',
    'linear-gradient(135deg,#ec4899,#be185d)','linear-gradient(135deg,#3b82f6,#1d4ed8)',
    'linear-gradient(135deg,#facc15,#ca8a04)',
  ];
  return $palette[crc32($seed) % count($palette)];
}

$pageTitle = 'Users';
$activeKey = 'users';
$pageHeadExtra = <<<HTML
<style>
  .page-header { padding: 28px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; }
  .page-stats { display: flex; gap: 24px; font-variant-numeric: tabular-nums; }
  .stat { display: flex; flex-direction: column; gap: 2px; text-align: right; }
  .stat-num { font-size: 20px; font-weight: 600; color: var(--text); }
  .stat-label { font-size: 10.5px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; }
  .body-wrap { max-width: 1640px; margin: 24px auto; padding: 0 32px 60px; display: grid; grid-template-columns: 340px 1fr; gap: 16px; }
  .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 18px; }
  .panel h3 { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 14px; }
  .form-row { margin-bottom: 12px; display: flex; flex-direction: column; gap: 5px; }
  .pw-wrap { position: relative; }
  .pw-wrap input { padding-right: 40px; }
  .pw-toggle { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 28px; height: 28px; border-radius: 6px; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; background: none; border: none; }
  .pw-toggle:hover { background: var(--surface-hover); color: var(--text); }
  .pw-toggle svg { width: 14px; height: 14px; }
  .checkbox-row { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
  .checkbox-row input { width: 16px; height: 16px; accent-color: var(--accent); }
  .checkbox-row label { font-size: 12.5px; color: var(--text-muted); }
  table.users-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.users-table thead th { text-align: left; padding: 12px 16px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-dim); background: var(--bg); border-bottom: 1px solid var(--border); }
  table.users-table tbody tr { transition: background 0.12s; }
  table.users-table tbody tr:hover { background: var(--surface-hover); }
  table.users-table tbody td { padding: 14px 16px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
  table.users-table tbody tr:last-child td { border-bottom: none; }
  .user-cell { display: flex; align-items: center; gap: 10px; }
  .user-cell .avatar { width: 30px; height: 30px; font-size: 10.5px; }
  .user-name { font-weight: 600; }
  .user-email { color: var(--text-muted); }
  .status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 500; border: 1px solid; }
  .status-pill .dot { width: 6px; height: 6px; border-radius: 50%; }
  .status-pill.active { color: #86efac; background: rgba(34,197,94,0.08); border-color: rgba(34,197,94,0.25); }
  .status-pill.active .dot { background: #22c55e; }
  .status-pill.disabled { color: var(--text-muted); background: var(--surface-2); border-color: var(--border); }
  .status-pill.disabled .dot { background: var(--text-dim); }
  .btn-tiny { padding: 5px 11px; font-size: 11.5px; font-weight: 500; border-radius: 6px; transition: all 0.12s; border: 1px solid var(--border); display: inline-flex; align-items: center; gap: 5px; color: var(--text); text-decoration: none; background: var(--surface); cursor: pointer; }
  .btn-tiny:hover { background: var(--surface-2); border-color: var(--border-strong); }
  .btn-tiny.danger { color: #fca5a5; border-color: rgba(239,68,68,0.25); background: transparent; }
  .btn-tiny.danger:hover { background: var(--danger-soft); }
  .row-actions { display: flex; gap: 6px; justify-content: flex-end; }
  .empty-state { padding: 32px 18px; text-align: center; color: var(--text-dim); font-style: italic; }
  @media (max-width: 1100px) { .body-wrap { grid-template-columns: 1fr; } }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Users Management</div>
      <div class="page-sub">Create, update, and manage account status and roles.</div>
    </div>
  </div>
  <div class="page-stats">
    <div class="stat"><span class="stat-num"><?= (int)$totalUsers ?></span><span class="stat-label">Total</span></div>
    <div class="stat"><span class="stat-num" style="color:#86efac;"><?= (int)$activeUsers ?></span><span class="stat-label">Active</span></div>
    <div class="stat"><span class="stat-num"><?= (int)$disabledUsers ?></span><span class="stat-label">Disabled</span></div>
  </div>
</div>

<div class="body-wrap">
  <div class="panel">
    <h3><?= $edit ? 'Edit User' : 'Add User' ?></h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div class="form-row"><label class="field-label">Name</label><input class="input" name="name" value="<?= h($edit['name'] ?? '') ?>" required></div>
      <div class="form-row"><label class="field-label">Email</label><input class="input" type="email" name="email" value="<?= h($edit['email'] ?? '') ?>" required></div>
      <div class="form-row">
        <label class="field-label">Role</label>
        <select class="select" name="role_id" required>
          <option value="">Select role...</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?= (int)$r['id'] ?>" <?= ($edit && (int)$edit['role_id'] === (int)$r['id']) ? 'selected' : '' ?>><?= h($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label class="field-label">Password <?= $edit ? '(leave blank to keep)' : '' ?></label>
        <div class="pw-wrap">
          <input class="input" id="user-password" name="password" type="password" <?= $edit ? '' : 'required' ?>>
          <button type="button" class="pw-toggle" onclick="const i=document.getElementById('user-password');i.type=i.type==='password'?'text':'password';" aria-label="Show password">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
          </button>
        </div>
      </div>
      <?php if ($edit): ?>
        <div class="checkbox-row"><input type="checkbox" id="is_active" name="is_active" <?= ((int)$edit['is_active'] === 1) ? 'checked' : '' ?>><label for="is_active">Active</label></div>
      <?php endif; ?>
      <button class="btn btn-primary btn-block save-flash" type="submit"><?= $edit ? 'Save Changes' : 'Create User' ?></button>
      <?php if ($edit): ?><a class="btn btn-ghost btn-block" href="users_management.php" style="margin-top:8px;">Cancel Edit</a><?php endif; ?>
    </form>
  </div>

  <div class="panel" style="padding:0;overflow:hidden;">
    <table class="users-table">
      <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Status</th><th style="text-align:right;width:140px;">Actions</th></tr></thead>
      <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="5" class="empty-state">No users found.</td></tr>
      <?php else: foreach ($users as $uu): $grad = um_avatar_gradient((string)$uu['name']); ?>
        <tr>
          <td><div class="user-cell"><div class="avatar" style="background:<?= h($grad) ?>"><?= h(user_initials((string)$uu['name'])) ?></div><div class="user-name"><?= h($uu['name']) ?></div></div></td>
          <td class="user-email"><?= h($uu['email']) ?></td>
          <td><?= h($uu['role_name']) ?></td>
          <td>
            <?php if ((int)$uu['is_active'] === 1): ?>
              <span class="status-pill active"><span class="dot"></span>Active</span>
            <?php else: ?>
              <span class="status-pill disabled"><span class="dot"></span>Disabled</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="row-actions">
              <a class="btn-tiny" href="?edit=<?= (int)$uu['id'] ?>">Edit</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Delete user?');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$uu['id'] ?>">
                <button class="btn-tiny danger">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
