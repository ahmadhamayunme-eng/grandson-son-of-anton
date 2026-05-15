<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();
auth_require_perm('settings.manage');

$pdo = db();
$ws = auth_workspace_id();

$defaultPerms = [
  ['finance.view', 'View Finance'],
  ['settings.manage', 'Manage Settings'],
  ['users.manage', 'Manage Users'],
  ['projects.manage', 'Manage Projects'],
  ['tasks.manage', 'Manage Tasks'],
  ['docs.manage', 'Manage Docs'],
];
foreach ($defaultPerms as $p) {
  try { $pdo->prepare("INSERT IGNORE INTO permissions (perm_key,label) VALUES (?,?)")->execute([$p[0], $p[1]]); } catch (Throwable $e) {}
}

$roles = $pdo->query("SELECT id,name FROM roles ORDER BY id")->fetchAll();
$perms = $pdo->query("SELECT id,perm_key,label FROM permissions ORDER BY perm_key")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post(); csrf_verify();
  $action = (string)($_POST['action'] ?? 'save_permissions');

  if ($action === 'add_role') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') { flash_set('error', 'Role name is required.'); redirect(basename(__FILE__)); }
    $exists = $pdo->prepare('SELECT id FROM roles WHERE LOWER(name)=LOWER(?) LIMIT 1');
    $exists->execute([$name]);
    if ($exists->fetch()) { flash_set('error', 'A role with this name already exists.'); redirect(basename(__FILE__)); }
    $pdo->prepare('INSERT INTO roles (name) VALUES (?)')->execute([$name]);
    $newRoleId = (int)$pdo->lastInsertId();
    flash_set('success', 'Role added.');
    redirect(basename(__FILE__) . '?role_id=' . $newRoleId);
  }
  if ($action === 'edit_role') {
    $roleId = (int)($_POST['role_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($roleId <= 0 || $name === '') { flash_set('error', 'Role and new name are required.'); redirect(basename(__FILE__)); }
    $exists = $pdo->prepare('SELECT id FROM roles WHERE LOWER(name)=LOWER(?) AND id<>? LIMIT 1');
    $exists->execute([$name, $roleId]);
    if ($exists->fetch()) { flash_set('error', 'Another role already has this name.'); redirect(basename(__FILE__) . '?role_id=' . $roleId); }
    $pdo->prepare('UPDATE roles SET name=? WHERE id=?')->execute([$name, $roleId]);
    flash_set('success', 'Role updated.');
    redirect(basename(__FILE__) . '?role_id=' . $roleId);
  }
  if ($action === 'delete_role') {
    $roleId = (int)($_POST['role_id'] ?? 0);
    if ($roleId <= 0) { flash_set('error', 'Invalid role selected.'); redirect(basename(__FILE__)); }
    $inUse = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id=?');
    $inUse->execute([$roleId]);
    if ((int)$inUse->fetchColumn() > 0) {
      flash_set('error', 'Role cannot be deleted because it is assigned to one or more users.');
      redirect(basename(__FILE__) . '?role_id=' . $roleId);
    }
    $pdo->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$roleId]);
    $pdo->prepare('DELETE FROM roles WHERE id=?')->execute([$roleId]);
    flash_set('success', 'Role deleted.');
    redirect(basename(__FILE__));
  }

  $role_id = (int)($_POST['role_id'] ?? 0);
  if ($action === 'save_permissions' && $role_id > 0) {
    foreach ($perms as $perm) {
      $key = 'perm_' . $perm['id'];
      $allowed = isset($_POST[$key]) ? 1 : 0;
      $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id,is_allowed) VALUES (?,?,?) ON DUPLICATE KEY UPDATE is_allowed=VALUES(is_allowed)")
          ->execute([$role_id, (int)$perm['id'], $allowed]);
    }
    flash_set('success', 'Permissions saved');
    redirect(basename(__FILE__) . '?role_id=' . $role_id);
  }
}

$role_id = (int)($_GET['role_id'] ?? ($roles[0]['id'] ?? 0));
$selectedRole = null;
foreach ($roles as $roleRow) { if ((int)$roleRow['id'] === $role_id) { $selectedRole = $roleRow; break; } }
$allowedMap = [];
if ($role_id > 0) {
  $st = $pdo->prepare("SELECT permission_id,is_allowed FROM role_permissions WHERE role_id=?");
  $st->execute([$role_id]);
  foreach ($st->fetchAll() as $r) $allowedMap[(int)$r['permission_id']] = (int)$r['is_allowed'];
}
$allowedCount = 0;
foreach ($perms as $perm) if (!empty($allowedMap[(int)$perm['id']])) $allowedCount++;
$totalPerms = count($perms);

$pageTitle = 'Roles & Permissions';
$activeKey = 'roles';
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
  .body-wrap { max-width: 1640px; margin: 24px auto; padding: 0 32px 60px; display: grid; grid-template-columns: 290px 1fr; gap: 16px; }
  .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 18px; }
  .panel h3 { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 14px; }
  .role-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 18px; }
  .role-row { display: flex; align-items: center; gap: 6px; }
  .role-edit { width: 28px; height: 28px; border-radius: 6px; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); background: var(--surface-2); cursor: pointer; flex-shrink: 0; }
  .role-edit:hover { background: var(--surface-hover); color: var(--text); }
  .role-edit svg { width: 12px; height: 12px; }
  .role-link { display: block; flex: 1; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface-2); font-size: 13px; color: var(--text-muted); text-decoration: none; transition: all 0.12s; }
  .role-link:hover { background: var(--surface-hover); color: var(--text); }
  .role-link.active { background: var(--accent-soft); border-color: rgba(250,204,21,0.4); color: var(--accent); }
  .role-actions { display: flex; flex-direction: column; gap: 8px; padding-top: 14px; border-top: 1px solid var(--border); }
  .perm-list { display: flex; flex-direction: column; gap: 8px; }
  .perm-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); transition: all 0.12s; }
  .perm-row:hover { border-color: var(--border-strong); }
  .perm-name { font-size: 13.5px; font-weight: 500; color: var(--text); }
  .perm-key { font-size: 11px; color: var(--text-dim); font-family: 'JetBrains Mono', ui-monospace, monospace; margin-top: 2px; }
  .toggle { position: relative; width: 36px; height: 20px; background: var(--surface); border: 1px solid var(--border-strong); border-radius: 999px; cursor: pointer; transition: all 0.15s; flex-shrink: 0; }
  .toggle::after { content: ''; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; background: var(--text-muted); border-radius: 50%; transition: all 0.15s; }
  .toggle input { position: absolute; opacity: 0; pointer-events: none; }
  .toggle:has(input:checked) { background: var(--accent); border-color: var(--accent); }
  .toggle:has(input:checked)::after { left: 18px; background: #1a1400; }
  .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 100; display: none; align-items: flex-start; justify-content: center; padding: 60px 20px; }
  .modal-overlay.open { display: flex; }
  .modal-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; width: 100%; max-width: 480px; overflow: hidden; }
  .modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
  .modal-title { font-size: 16px; font-weight: 600; }
  .modal-body { padding: 20px; }
  .modal-foot { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; gap: 8px; justify-content: flex-end; background: var(--bg); }
  .icon-close { width: 30px; height: 30px; border-radius: 6px; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; }
  .icon-close:hover { background: var(--surface-2); color: var(--text); }
  @media (max-width: 1100px) { .body-wrap { grid-template-columns: 1fr; } }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Roles &amp; Permissions</div>
      <div class="page-sub">Control access by role using workspace-wide permission toggles.</div>
    </div>
  </div>
  <div class="page-stats">
    <div class="stat"><span class="stat-num"><?= count($roles) ?></span><span class="stat-label">Roles</span></div>
    <div class="stat"><span class="stat-num"><?= (int)$totalPerms ?></span><span class="stat-label">Permissions</span></div>
    <div class="stat"><span class="stat-num" style="color:var(--accent);"><?= (int)$allowedCount ?></span><span class="stat-label">Allowed</span></div>
  </div>
</div>

<div class="body-wrap">
  <div class="panel">
    <h3>Roles</h3>
    <div class="role-list">
      <?php foreach ($roles as $r): ?>
        <div class="role-row">
          <button type="button" class="role-edit" onclick="openEditRole('<?= (int)$r['id'] ?>', '<?= h(addslashes($r['name'])) ?>')" aria-label="Edit <?= h($r['name']) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
          </button>
          <a class="role-link <?= $role_id === (int)$r['id'] ? 'active' : '' ?>" href="?role_id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="role-actions">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_role">
        <label class="field-label" style="margin-bottom:5px;display:block;">Add New Role</label>
        <div style="display:flex;gap:8px;">
          <input class="input" name="name" placeholder="Role name" required>
          <button class="btn btn-primary save-flash" type="submit">Add</button>
        </div>
      </form>
      <?php if ($role_id > 0): ?>
        <form method="post" onsubmit="return confirm('Delete this role permanently?');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete_role">
          <input type="hidden" name="role_id" value="<?= (int)$role_id ?>">
          <button class="btn btn-danger btn-block" type="submit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"></path></svg>
            Delete Selected Role
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <h3>Permissions<?= $selectedRole ? ' · ' . h($selectedRole['name']) : '' ?></h3>
    <?php if ($role_id <= 0): ?>
      <div style="color:var(--text-dim);padding:32px;text-align:center;">No roles found.</div>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_permissions">
        <input type="hidden" name="role_id" value="<?= (int)$role_id ?>">
        <div class="perm-list">
          <?php if (!$perms): ?>
            <div style="color:var(--text-dim);padding:14px;">No permissions found.</div>
          <?php else: foreach ($perms as $p): ?>
            <div class="perm-row">
              <div>
                <div class="perm-name"><?= h($p['label']) ?></div>
                <div class="perm-key"><?= h($p['perm_key']) ?></div>
              </div>
              <label class="toggle">
                <input type="checkbox" name="perm_<?= (int)$p['id'] ?>" <?= (!empty($allowedMap[(int)$p['id']])) ? 'checked' : '' ?>>
              </label>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:18px;">
          <button class="btn btn-primary save-flash" type="submit">Save Permissions</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="modal-overlay" id="editRoleModal" onclick="if(event.target===this) this.classList.remove('open')">
  <div class="modal-card">
    <div class="modal-head">
      <span class="modal-title">Edit Role</span>
      <button type="button" class="icon-close" onclick="document.getElementById('editRoleModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
    </div>
    <form method="post">
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="edit_role">
        <input type="hidden" name="role_id" id="modal_role_id" value="">
        <label class="field-label">Role Name</label>
        <input class="input" name="name" id="modal_role_name" required>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('editRoleModal').classList.remove('open')">Cancel</button>
        <button class="btn btn-primary save-flash" type="submit">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditRole(id, name) {
  document.getElementById('modal_role_id').value = id;
  document.getElementById('modal_role_name').value = name;
  document.getElementById('editRoleModal').classList.add('open');
}
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
