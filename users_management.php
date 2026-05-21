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
$roleCounts = [];
foreach ($users as $urow) {
  if ((int)$urow['is_active'] === 1) $activeUsers++; else $disabledUsers++;
  $rn = (string)($urow['role_name'] ?? '');
  if ($rn !== '') $roleCounts[$rn] = ($roleCounts[$rn] ?? 0) + 1;
}

function um_avatar_gradient(string $seed): string {
  $palette = [
    'linear-gradient(135deg,#22c55e,#16a34a)','linear-gradient(135deg,#a855f7,#7c3aed)',
    'linear-gradient(135deg,#0ea5e9,#0284c7)','linear-gradient(135deg,#f97316,#ea580c)',
    'linear-gradient(135deg,#ec4899,#be185d)','linear-gradient(135deg,#3b82f6,#1d4ed8)',
    'linear-gradient(135deg,#facc15,#ca8a04)','linear-gradient(135deg,#7c3aed,#5b21b6)',
    'linear-gradient(135deg,#f43f5e,#9f1239)','linear-gradient(135deg,#64748b,#334155)',
  ];
  return $palette[crc32($seed) % count($palette)];
}
function um_role_class(string $roleName): string {
  $r = strtolower($roleName);
  if (str_contains($r, 'super') || str_contains($r, 'admin') || str_contains($r, 'manager') || str_contains($r, 'ceo')) return 'super';
  if (str_contains($r, 'dev')) return 'dev';
  if (str_contains($r, 'seo')) return 'seo';
  return 'other';
}

$pageTitle = 'Users';
$activeKey = 'users';
$pageHeadExtra = <<<HTML
<style>
  .settings-topbar {
    max-width: 1640px; margin: 0 auto; width: 100%;
    padding: 24px 32px 0;
    display: flex; align-items: center; gap: 8px;
    font-size: 12.5px; color: var(--text-dim);
  }
  .settings-topbar .crumb { color: var(--text-muted); transition: color 0.12s; text-decoration: none; }
  .settings-topbar .crumb:hover { color: var(--text); }
  .settings-topbar .sep { color: var(--text-dim); opacity: 0.6; }
  .settings-topbar .current { color: var(--text); font-weight: 500; }

  .page-header {
    padding: 18px 32px 0;
    max-width: 1640px; margin: 0 auto; width: 100%;
    display: flex; align-items: flex-end; justify-content: space-between; gap: 16px;
  }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; max-width: 640px; }

  .stats-row {
    max-width: 1640px; margin: 0 auto; width: 100%;
    padding: 24px 32px 0;
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px;
  }
  .stat-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 12px; padding: 16px 18px;
    display: flex; align-items: center; gap: 14px;
  }
  .stat-card .ico {
    width: 38px; height: 38px; border-radius: 9px;
    display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
  }
  .stat-card .ico svg { width: 17px; height: 17px; }
  .stat-card .ico.amber { background: rgba(250,204,21,0.08); color: var(--accent); border: 1px solid rgba(250,204,21,0.2); }
  .stat-card .ico.green { background: rgba(34,197,94,0.08); color: #86efac; border: 1px solid rgba(34,197,94,0.2); }
  .stat-card .ico.gray { background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border); }
  .stat-card .num {
    font-size: 22px; font-weight: 700; line-height: 1;
    color: var(--text); letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
  }
  .stat-card .num.dim { color: var(--text-dim); }
  .stat-card .lbl {
    font-size: 11px; color: var(--text-dim);
    text-transform: uppercase; letter-spacing: 0.08em;
    margin-top: 4px;
  }

  .body-grid {
    max-width: 1640px; margin: 0 auto; width: 100%;
    padding: 24px 32px 60px;
    display: grid; grid-template-columns: 340px 1fr;
    gap: 20px; align-items: start;
  }
  .panel {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; overflow: hidden;
  }
  .panel-head {
    padding: 16px 18px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; gap: 14px;
  }
  .panel-title {
    font-size: 13px; font-weight: 600; color: var(--text);
    display: flex; align-items: center; gap: 8px;
  }
  .panel-title .ico {
    width: 22px; height: 22px;
    background: var(--accent-soft); color: var(--accent);
    border: 1px solid rgba(250,204,21,0.2); border-radius: 6px;
    display: inline-flex; align-items: center; justify-content: center;
  }
  .panel-title .ico svg { width: 12px; height: 12px; }

  .form-body { padding: 18px; }
  .field { margin-bottom: 14px; }
  .field-label {
    display: block; font-size: 11px;
    color: var(--text-muted); margin-bottom: 6px; font-weight: 500;
    text-transform: uppercase; letter-spacing: 0.08em;
  }
  .field-label .req { color: var(--accent); margin-left: 2px; }
  .field input.input, .field select.select {
    height: 40px; font-size: 13.5px;
    padding: 0 12px;
  }
  .field select.select { padding-right: 32px; }
  .pwd-wrap { position: relative; }
  .pwd-wrap .toggle-eye {
    position: absolute; right: 10px; top: 50%;
    transform: translateY(-50%);
    width: 28px; height: 28px;
    border-radius: 6px;
    color: var(--text-muted);
    display: inline-flex; align-items: center; justify-content: center;
    transition: all 0.12s; cursor: pointer;
    background: none; border: none;
  }
  .pwd-wrap .toggle-eye svg { width: 14px; height: 14px; }
  .pwd-wrap .toggle-eye:hover { background: var(--surface-2); color: var(--text); }
  .pwd-wrap input { padding-right: 44px; }
  .checkbox-row { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
  .checkbox-row input { width: 16px; height: 16px; accent-color: var(--accent); }
  .checkbox-row label { font-size: 12.5px; color: var(--text-muted); }

  .form-foot {
    padding: 14px 18px;
    border-top: 1px solid var(--border);
    background: var(--bg);
    display: flex; gap: 8px; flex-direction: column;
  }
  .form-foot .btn-primary {
    justify-content: center;
    padding: 10px 16px; font-size: 13.5px; border-radius: 9px;
  }

  .users-toolbar {
    padding: 14px 18px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  }
  .users-toolbar .search-box { flex: 1; max-width: 380px; position: relative; min-width: 200px; }
  .users-toolbar .search-box svg.s-ico {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    width: 14px; height: 14px; color: var(--text-dim);
  }
  .users-toolbar .search-box input {
    width: 100%;
    background: var(--surface-2); border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 12px 8px 36px;
    font-size: 13px; color: var(--text);
    outline: none; transition: all 0.12s;
    font-family: inherit;
  }
  .users-toolbar .search-box input::placeholder { color: var(--text-dim); }
  .users-toolbar .search-box input:focus { border-color: var(--border-strong); }
  .filter-pills {
    display: flex; gap: 4px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 2px;
    flex-wrap: wrap;
  }
  .filter-pills button {
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    color: var(--text-muted);
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.12s;
    background: none; border: none; cursor: pointer;
  }
  .filter-pills button.on { background: var(--bg); color: var(--text); }
  .filter-pills .pill-count {
    font-size: 10.5px; padding: 1px 6px;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 999px; color: var(--text-muted);
    font-variant-numeric: tabular-nums;
  }
  .filter-pills button.on .pill-count { background: var(--surface-2); color: var(--text); }

  .users-table {
    width: 100%; border-collapse: collapse; font-size: 13px;
  }
  .users-table thead th {
    text-align: left; padding: 10px 16px;
    font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em;
    text-transform: uppercase; color: var(--text-dim);
    background: var(--bg); border-bottom: 1px solid var(--border);
  }
  .users-table thead th.right { text-align: right; }
  .users-table tbody tr {
    border-bottom: 1px solid var(--border);
    transition: background 0.12s;
  }
  .users-table tbody tr:last-child { border-bottom: none; }
  .users-table tbody tr:hover { background: var(--surface-hover); }
  .users-table tbody td { padding: 14px 16px; vertical-align: middle; }

  .user-cell {
    display: flex; align-items: center; gap: 12px;
    min-width: 0;
  }
  .user-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 600; color: #fff;
    letter-spacing: 0.02em; flex-shrink: 0;
  }
  .user-name { font-weight: 600; font-size: 13.5px; color: var(--text); }
  .user-id {
    font-size: 11px; color: var(--text-dim);
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    margin-top: 1px;
  }
  .user-email {
    font-size: 13px; color: var(--text-muted);
    font-family: 'JetBrains Mono', ui-monospace, monospace;
  }

  .role-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11.5px; font-weight: 500;
    border: 1px solid;
  }
  .role-badge .dot { width: 5px; height: 5px; border-radius: 50%; }
  .role-badge.super {
    color: var(--accent);
    background: var(--accent-soft);
    border-color: rgba(250,204,21,0.25);
  }
  .role-badge.super .dot { background: var(--accent); }
  .role-badge.dev {
    color: #93c5fd;
    background: rgba(59,130,246,0.08);
    border-color: rgba(59,130,246,0.25);
  }
  .role-badge.dev .dot { background: #3b82f6; }
  .role-badge.seo {
    color: #c4b5fd;
    background: rgba(168,85,247,0.08);
    border-color: rgba(168,85,247,0.25);
  }
  .role-badge.seo .dot { background: #a855f7; }
  .role-badge.other {
    color: var(--text-muted);
    background: var(--surface-2);
    border-color: var(--border);
  }
  .role-badge.other .dot { background: var(--text-dim); }

  .status-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11.5px; font-weight: 500;
    border: 1px solid;
  }
  .status-badge .dot { width: 6px; height: 6px; border-radius: 50%; }
  .status-badge.active {
    color: #86efac;
    background: rgba(34,197,94,0.08);
    border-color: rgba(34,197,94,0.25);
  }
  .status-badge.active .dot { background: #22c55e; box-shadow: 0 0 0 2px rgba(34,197,94,0.18); }
  .status-badge.disabled {
    color: var(--text-dim);
    background: var(--surface-2);
    border-color: var(--border);
  }
  .status-badge.disabled .dot { background: var(--text-dim); }

  .row-actions {
    display: flex; align-items: center; gap: 6px; justify-content: flex-end;
  }
  .btn-tiny {
    padding: 5px 11px; font-size: 11.5px; font-weight: 500;
    border-radius: 6px; transition: all 0.12s;
    border: 1px solid; display: inline-flex; align-items: center; gap: 5px;
    text-decoration: none; cursor: pointer; background: transparent;
  }
  .btn-tiny svg { width: 11px; height: 11px; }
  .btn-tiny.edit {
    color: var(--text-muted);
    border-color: var(--border);
  }
  .btn-tiny.edit:hover { color: var(--text); border-color: var(--border-strong); background: var(--surface-2); }
  .btn-tiny.delete {
    color: #fca5a5;
    border-color: rgba(239,68,68,0.25);
  }
  .btn-tiny.delete:hover { background: var(--danger-soft); border-color: rgba(239,68,68,0.4); }

  .users-foot {
    padding: 12px 18px; border-top: 1px solid var(--border);
    background: var(--bg);
    display: flex; align-items: center; justify-content: space-between;
    font-size: 12px; color: var(--text-dim);
    font-variant-numeric: tabular-nums;
  }
  .users-foot b { color: var(--text-muted); font-weight: 500; }

  .empty-state { padding: 32px 18px; text-align: center; color: var(--text-dim); font-style: italic; }

  @media (max-width: 1100px) {
    .body-grid { grid-template-columns: 1fr; }
    .stats-row { grid-template-columns: 1fr; }
  }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="settings-topbar">
  <span class="crumb">Admin</span>
  <span class="sep">/</span>
  <span class="current">Users</span>
</div>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Users Management</div>
      <div class="page-sub">Create, update and manage account status and roles. Disabling a user revokes login immediately.</div>
    </div>
  </div>
</div>

<div class="stats-row">
  <div class="stat-card">
    <div class="ico amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 00-3-3.87"></path><path d="M16 3.13a4 4 0 010 7.75"></path></svg></div>
    <div><div class="num"><?= (int)$totalUsers ?></div><div class="lbl">Total Users</div></div>
  </div>
  <div class="stat-card">
    <div class="ico green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></div>
    <div><div class="num" style="color:#86efac;"><?= (int)$activeUsers ?></div><div class="lbl">Active</div></div>
  </div>
  <div class="stat-card">
    <div class="ico gray"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg></div>
    <div><div class="num dim"><?= (int)$disabledUsers ?></div><div class="lbl">Disabled</div></div>
  </div>
</div>

<div class="body-grid">

  <div class="panel">
    <div class="panel-head">
      <div class="panel-title">
        <span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle></svg></span>
        <?= $edit ? 'Edit User' : 'Add User' ?>
      </div>
    </div>
    <form method="post">
      <div class="form-body">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
        <div class="field">
          <label class="field-label">Full name <span class="req">*</span></label>
          <input class="input" name="name" value="<?= h($edit['name'] ?? '') ?>" placeholder="e.g. Sara Khan" required>
        </div>
        <div class="field">
          <label class="field-label">Email <span class="req">*</span></label>
          <input class="input" type="email" name="email" value="<?= h($edit['email'] ?? '') ?>" required>
        </div>
        <div class="field">
          <label class="field-label">Role <span class="req">*</span></label>
          <select class="select" name="role_id" required>
            <option value="">Select role&hellip;</option>
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int)$r['id'] ?>" <?= ($edit && (int)$edit['role_id'] === (int)$r['id']) ? 'selected' : '' ?>><?= h($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" <?= $edit ? '' : 'style="margin-bottom:0;"' ?>>
          <label class="field-label"><?= $edit ? 'Password (leave blank to keep)' : 'Initial password' ?> <?php if (!$edit): ?><span class="req">*</span><?php endif; ?></label>
          <div class="pwd-wrap">
            <input class="input" id="user-password" name="password" type="password" <?= $edit ? '' : 'required' ?>>
            <button type="button" class="toggle-eye" onclick="const i=document.getElementById('user-password');i.type=i.type==='password'?'text':'password';" aria-label="Show password">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            </button>
          </div>
        </div>
        <?php if ($edit): ?>
          <div class="checkbox-row" style="margin-top:14px;margin-bottom:0;"><input type="checkbox" id="is_active" name="is_active" <?= ((int)$edit['is_active'] === 1) ? 'checked' : '' ?>><label for="is_active">Active</label></div>
        <?php endif; ?>
      </div>
      <div class="form-foot">
        <button class="btn btn-primary save-flash" type="submit">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><?php if ($edit): ?><polyline points="20 6 9 17 4 12"></polyline><?php else: ?><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line><?php endif; ?></svg>
          <?= $edit ? 'Save Changes' : 'Create User' ?>
        </button>
        <?php if ($edit): ?><a class="btn btn-ghost btn-block" href="users_management.php">Cancel Edit</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="panel">
    <div class="panel-head">
      <div class="panel-title">
        <span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg></span>
        Users
      </div>
    </div>

    <div class="users-toolbar">
      <div class="search-box">
        <svg class="s-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input id="searchInput" placeholder="Search by name, email or role&hellip;">
      </div>
      <div class="filter-pills" id="filterPills">
        <button type="button" class="on" data-filter="all">All <span class="pill-count"><?= (int)$totalUsers ?></span></button>
        <?php foreach ($roles as $r):
          $cnt = (int)($roleCounts[$r['name']] ?? 0);
          if ($cnt === 0) continue;
        ?>
          <button type="button" data-filter="<?= h(strtolower($r['name'])) ?>"><?= h($r['name']) ?> <span class="pill-count"><?= $cnt ?></span></button>
        <?php endforeach; ?>
      </div>
    </div>

    <table class="users-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>Status</th>
          <th class="right" style="width:170px;">Actions</th>
        </tr>
      </thead>
      <tbody id="rows">
      <?php if (!$users): ?>
        <tr><td colspan="5" class="empty-state">No users found.</td></tr>
      <?php else: foreach ($users as $uu):
        $grad = um_avatar_gradient((string)$uu['name']);
        $roleName = (string)($uu['role_name'] ?? '');
        $roleClass = um_role_class($roleName);
      ?>
        <tr data-role-filter="<?= h(strtolower($roleName)) ?>">
          <td>
            <div class="user-cell">
              <div class="user-avatar" style="background:<?= h($grad) ?>;"><?= h(user_initials((string)$uu['name'])) ?></div>
              <div>
                <div class="user-name"><?= h($uu['name']) ?><?= user_status_chip((int)$uu['id']) ?></div>
                <div class="user-id">USR-<?= str_pad((string)(int)$uu['id'], 4, '0', STR_PAD_LEFT) ?></div>
              </div>
            </div>
          </td>
          <td><span class="user-email"><?= h($uu['email']) ?></span></td>
          <td><span class="role-badge <?= h($roleClass) ?>"><span class="dot"></span><?= h($roleName) ?></span></td>
          <td>
            <?php if ((int)$uu['is_active'] === 1): ?>
              <span class="status-badge active"><span class="dot"></span>Active</span>
            <?php else: ?>
              <span class="status-badge disabled"><span class="dot"></span>Disabled</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="row-actions">
              <a class="btn-tiny edit" href="?edit=<?= (int)$uu['id'] ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>Edit</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Delete user?');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$uu['id'] ?>">
                <button class="btn-tiny delete" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path></svg>Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>

    <div class="users-foot">
      <span>Showing <b id="visibleCount"><?= (int)$totalUsers ?></b> of <b><?= (int)$totalUsers ?></b> users</span>
      <span>Workspace #<?= (int)$ws ?></span>
    </div>
  </div>
</div>

<script>
(function() {
  const search = document.getElementById('searchInput');
  const pills = document.querySelectorAll('.filter-pills button');
  pills.forEach(b => {
    b.addEventListener('click', () => {
      pills.forEach(x => x.classList.remove('on'));
      b.classList.add('on');
      applyFilters();
    });
  });
  if (search) search.addEventListener('input', applyFilters);

  function applyFilters() {
    const active = document.querySelector('.filter-pills button.on');
    const filter = active ? active.dataset.filter : 'all';
    const q = (search ? search.value : '').trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('#rows tr').forEach(tr => {
      if (!tr.dataset.roleFilter) return; // skip empty-state row
      const matchRole = filter === 'all' || tr.dataset.roleFilter === filter;
      const text = tr.textContent.toLowerCase();
      const matchQ = !q || text.includes(q);
      const show = matchRole && matchQ;
      tr.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    const counter = document.getElementById('visibleCount');
    if (counter) counter.textContent = visible;
  }
})();
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
