<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();
auth_require_perm('settings.manage');

$pdo = db();
$ws = auth_workspace_id();

// Seed default permissions on first load.
$defaultPerms = [
  ['finance.view', 'View Finance', 'Read access to Finance dashboards, payments and expenses.'],
  ['settings.manage', 'Manage Settings', 'Edit workspace settings, statuses, types and integrations.'],
  ['users.manage', 'Manage Users', 'Invite, edit, deactivate, and delete users in this workspace.'],
  ['projects.manage', 'Manage Projects', 'Create, archive and reassign projects. Edit project metadata.'],
  ['tasks.manage', 'Manage Tasks', 'Create, assign, edit and delete tasks across all projects.'],
  ['docs.manage', 'Manage Docs', 'Create, edit and delete documents across projects.'],
];
foreach ($defaultPerms as $p) {
  try { $pdo->prepare("INSERT IGNORE INTO permissions (perm_key,label) VALUES (?,?)")->execute([$p[0], $p[1]]); } catch (Throwable $e) {}
}
$permDescriptions = [];
foreach ($defaultPerms as $p) { $permDescriptions[$p[0]] = $p[2]; }

$roles = $pdo->query("SELECT id,name FROM roles ORDER BY id")->fetchAll();
$perms = $pdo->query("SELECT id,perm_key,label FROM permissions ORDER BY perm_key")->fetchAll();

// User counts per role
$userCounts = [];
try {
  $st = $pdo->prepare("SELECT role_id, COUNT(*) c FROM users WHERE workspace_id=? GROUP BY role_id");
  $st->execute([$ws]);
  foreach ($st->fetchAll() as $r) { $userCounts[(int)$r['role_id']] = (int)$r['c']; }
} catch (Throwable $e) { $userCounts = []; }

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
    flash_set('success', 'Role added.');
    redirect(basename(__FILE__) . '?role_id=' . (int)$pdo->lastInsertId());
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

function rp_perm_icon(string $key): string {
  $icons = [
    'finance.view'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"></path></svg>',
    'settings.manage'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9"></path></svg>',
    'users.manage'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>',
    'projects.manage'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"></path></svg>',
    'tasks.manage'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg>',
    'docs.manage'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>',
  ];
  return $icons[$key] ?? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle></svg>';
}

$pageTitle = 'Roles & Permissions';
$activeKey = 'roles';
$pageHeadExtra = <<<HTML
<style>
  .settings-topbar { max-width: 1640px; margin: 0 auto; width: 100%; padding: 24px 32px 0; display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-dim); }
  .settings-topbar .crumb { color: var(--text-muted); transition: color 0.12s; text-decoration: none; }
  .settings-topbar .crumb:hover { color: var(--text); }
  .settings-topbar .sep { color: var(--text-dim); opacity: 0.6; }
  .settings-topbar .current { color: var(--text); font-weight: 500; }
  .page-header { padding: 18px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; max-width: 640px; }
  .stats-row { max-width: 1640px; margin: 0 auto; width: 100%; padding: 24px 32px 0; display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
  .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; display: flex; align-items: center; gap: 14px; }
  .stat-card .ico { width: 38px; height: 38px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .stat-card .ico svg { width: 17px; height: 17px; }
  .stat-card .ico.amber { background: rgba(250,204,21,0.08); color: var(--accent); border: 1px solid rgba(250,204,21,0.2); }
  .stat-card .ico.blue { background: rgba(59,130,246,0.08); color: #93c5fd; border: 1px solid rgba(59,130,246,0.2); }
  .stat-card .ico.green { background: rgba(34,197,94,0.08); color: #86efac; border: 1px solid rgba(34,197,94,0.2); }
  .stat-card .num { font-size: 22px; font-weight: 700; line-height: 1; color: var(--text); letter-spacing: -0.02em; font-variant-numeric: tabular-nums; }
  .stat-card .lbl { font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.08em; margin-top: 4px; }
  .body-grid { max-width: 1640px; margin: 0 auto; width: 100%; padding: 24px 32px 60px; display: grid; grid-template-columns: 340px 1fr; gap: 20px; align-items: start; }
  .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  .panel-head { padding: 16px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 14px; }
  .panel-title { font-size: 13px; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 8px; }
  .panel-title .ico { width: 22px; height: 22px; background: var(--accent-soft); color: var(--accent); border: 1px solid rgba(250,204,21,0.2); border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; }
  .panel-title .ico svg { width: 12px; height: 12px; }
  .roles-list { padding: 8px 8px 0; }
  .role-row { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 9px; transition: background 0.12s; border: 1px solid transparent; text-decoration: none; color: inherit; }
  .role-row:hover { background: var(--surface-2); }
  .role-row.active { background: rgba(250,204,21,0.06); border-color: rgba(250,204,21,0.2); }
  .role-edit { width: 26px; height: 26px; border-radius: 6px; background: var(--surface-2); border: 1px solid var(--border); color: var(--accent); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.12s; cursor: pointer; }
  .role-edit svg { width: 12px; height: 12px; }
  .role-edit:hover { background: var(--surface-hover); }
  .role-name { flex: 1; font-size: 13px; font-weight: 500; color: var(--text); display: flex; align-items: center; gap: 8px; min-width: 0; }
  .role-row.active .role-name { color: var(--accent); }
  .role-meta { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 10.5px; color: var(--text-dim); background: var(--bg); border: 1px solid var(--border); border-radius: 5px; padding: 2px 6px; font-variant-numeric: tabular-nums; }
  .add-role { padding: 12px 18px 14px; margin-top: 8px; border-top: 1px solid var(--border); }
  .add-role-label { font-size: 10.5px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600; margin-bottom: 8px; }
  .add-role-row { display: grid; grid-template-columns: 1fr auto; gap: 8px; }
  .add-role-row input { background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; padding: 9px 12px; font-size: 13px; color: var(--text); outline: none; font-family: inherit; transition: all 0.12s; }
  .add-role-row input::placeholder { color: var(--text-dim); }
  .add-role-row input:focus { border-color: var(--accent); background: var(--bg); }
  .add-role-row .btn-primary { padding: 0 18px; font-size: 13px; border-radius: 8px; }
  .roles-foot { padding: 12px 18px; border-top: 1px solid var(--border); background: var(--bg); }
  .btn-danger-soft { width: 100%; background: transparent; color: #fca5a5; border: 1px solid rgba(239,68,68,0.25); border-radius: 8px; padding: 9px; font-size: 12.5px; font-weight: 500; transition: all 0.12s; display: inline-flex; align-items: center; justify-content: center; gap: 7px; cursor: pointer; }
  .btn-danger-soft svg { width: 13px; height: 13px; }
  .btn-danger-soft:hover { background: var(--danger-soft); border-color: rgba(239,68,68,0.4); }
  .btn-danger-soft.disabled { opacity: 0.4; pointer-events: none; }
  .selected-role-banner { padding: 14px 18px; border-bottom: 1px solid var(--border); background: var(--bg); display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .selected-role-banner .lbl { font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.08em; font-weight: 600; }
  .selected-role-banner .role-pill { display: inline-flex; align-items: center; gap: 7px; background: var(--accent-soft); color: var(--accent); border: 1px solid rgba(250,204,21,0.25); border-radius: 999px; padding: 4px 12px; font-size: 12.5px; font-weight: 500; }
  .selected-role-banner .role-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--accent); }
  .selected-role-banner .count { margin-left: auto; font-size: 12px; color: var(--text-muted); font-variant-numeric: tabular-nums; }
  .selected-role-banner .count b { color: var(--text); font-weight: 600; }
  .perms-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .perms-table thead th { text-align: left; padding: 10px 16px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-dim); background: var(--bg); border-bottom: 1px solid var(--border); }
  .perms-table thead th.right { text-align: right; }
  .perms-table tbody tr { border-bottom: 1px solid var(--border); transition: background 0.12s; }
  .perms-table tbody tr:last-child { border-bottom: none; }
  .perms-table tbody tr.allowed { background: rgba(34,197,94,0.025); }
  .perms-table tbody td { padding: 14px 16px; vertical-align: middle; }
  .perm-name { display: flex; align-items: center; gap: 12px; }
  .perm-icon { width: 28px; height: 28px; border-radius: 7px; background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.12s; }
  .perm-icon svg { width: 13px; height: 13px; }
  tr.allowed .perm-icon { background: rgba(34,197,94,0.08); color: #86efac; border-color: rgba(34,197,94,0.2); }
  .perm-label { font-size: 13.5px; font-weight: 500; color: var(--text); }
  .perm-desc { font-size: 11.5px; color: var(--text-dim); margin-top: 2px; }
  .perm-key { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px; color: var(--text-muted); background: var(--bg); border: 1px solid var(--border); border-radius: 5px; padding: 3px 8px; display: inline-block; }
  .toggle { position: relative; width: 38px; height: 22px; background: var(--surface-2); border: 1px solid var(--border-strong); border-radius: 999px; cursor: pointer; transition: all 0.15s; flex-shrink: 0; display: inline-block; }
  .toggle::after { content: ''; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; background: var(--text-muted); border-radius: 50%; transition: all 0.18s cubic-bezier(.4,.2,.2,1); }
  .toggle input { position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; }
  .toggle:has(input:checked), .toggle.on { background: var(--accent); border-color: var(--accent); }
  .toggle:has(input:checked)::after, .toggle.on::after { background: #1a1400; left: 18px; }
  .toggle:hover::after { background: var(--text); }
  .perms-foot { padding: 14px 18px; border-top: 1px solid var(--border); background: var(--bg); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .save-hint { font-size: 12px; color: var(--text-dim); display: flex; align-items: center; gap: 8px; }
  .save-hint svg { width: 13px; height: 13px; color: var(--text-muted); }
  .save-hint.dirty { color: var(--accent); }
  .save-hint.dirty svg { color: var(--accent); }
  .empty-state { padding: 32px 18px; text-align: center; color: var(--text-dim); font-style: italic; font-size: 13px; }
  .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 100; display: none; align-items: flex-start; justify-content: center; padding: 60px 20px; }
  .modal-overlay.open { display: flex; }
  .modal-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; width: 100%; max-width: 480px; overflow: hidden; }
  .modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
  .modal-title { font-size: 16px; font-weight: 600; }
  .modal-body { padding: 20px; }
  .modal-foot { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; gap: 8px; justify-content: flex-end; background: var(--bg); }
  .icon-close { width: 30px; height: 30px; border-radius: 6px; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
  .icon-close:hover { background: var(--surface-2); color: var(--text); }
  @media (max-width: 1100px) { .body-grid { grid-template-columns: 1fr; } .stats-row { grid-template-columns: 1fr; } }

  /* ===== MOBILE (≤768px) =====
     Screenshot showed the 3-column permissions table (name+desc / key /
     toggle) squeezing the name column to ~30% so "Manage Projects" and
     its description wrapped one or two words per line. Convert each row
     to a card: name + toggle on row 1, the perm-key pill on row 2. */
  @media (max-width: 768px) {
    .page-header { flex-direction: column; align-items: stretch; padding: 16px 16px 0; gap: 10px; }
    .page-title { font-size: clamp(20px, 5.6vw, 26px); line-height: 1.2; }
    .page-sub { font-size: 12px; }
    .body-grid { padding: 16px 16px 28px; gap: 16px; }

    .perms-table thead { display: none; }
    .perms-table, .perms-table tbody, .perms-table tr { display: block; }
    .perms-table tbody tr {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      grid-template-areas:
        "name toggle"
        "key  key";
      gap: 10px 12px;
      padding: 14px 16px;
      align-items: center;
    }
    .perms-table tbody td {
      display: block;
      padding: 0;
      border: none;
      min-width: 0;
      max-width: 100%;
    }
    .perms-table tbody td:nth-child(1) { grid-area: name; }
    .perms-table tbody td:nth-child(2) { grid-area: key; }
    .perms-table tbody td:nth-child(3) { grid-area: toggle; text-align: right !important; justify-self: end; }
    .perm-desc { font-size: 12px; line-height: 1.45; margin-top: 3px; }

    .perms-foot { flex-direction: column; align-items: stretch; gap: 10px; padding: 14px 16px; }
    .perms-foot .save-hint { order: -1; }
    .perms-foot .btn { width: 100%; min-height: 46px; justify-content: center; }
  }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="settings-topbar">
  <?= back_button_html('settings.php', 'Settings') ?>
  <a class="crumb" href="settings.php">Admin</a>
  <span class="sep">/</span>
  <span class="current">Roles &amp; Permissions</span>
</div>

<div class="page-header">
  <div class="page-header-left">
    <div>
      <div class="page-title">Roles &amp; Permissions</div>
      <div class="page-sub">Control access by role using clear, workspace-wide permission toggles. Changes apply to every member assigned that role.</div>
    </div>
  </div>
</div>

<div class="stats-row">
  <div class="stat-card">
    <div class="ico amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg></div>
    <div><div class="num"><?= count($roles) ?></div><div class="lbl">Roles</div></div>
  </div>
  <div class="stat-card">
    <div class="ico blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg></div>
    <div><div class="num"><?= (int)$totalPerms ?></div><div class="lbl">Permissions</div></div>
  </div>
  <div class="stat-card">
    <div class="ico green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg></div>
    <div><div class="num"><?= (int)$allowedCount ?></div><div class="lbl">Allowed for <?= h($selectedRole['name'] ?? '—') ?></div></div>
  </div>
</div>

<div class="body-grid">

  <!-- Roles list -->
  <div class="panel">
    <div class="panel-head">
      <div class="panel-title">
        <span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg></span>
        Roles
      </div>
    </div>

    <div class="roles-list">
      <?php if (!$roles): ?>
        <div class="empty-state">No roles defined yet.</div>
      <?php else: foreach ($roles as $r):
        $isActive = (int)$r['id'] === $role_id;
        $userCount = $userCounts[(int)$r['id']] ?? 0;
      ?>
        <a class="role-row <?= $isActive ? 'active' : '' ?>" href="?role_id=<?= (int)$r['id'] ?>">
          <button type="button" class="role-edit" onclick="event.preventDefault(); event.stopPropagation(); openEditRole('<?= (int)$r['id'] ?>', '<?= h(addslashes($r['name'])) ?>');" title="Rename role">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
          </button>
          <div class="role-name"><?= h($r['name']) ?> <span class="role-meta"><?= (int)$userCount ?> user<?= $userCount === 1 ? '' : 's' ?></span></div>
        </a>
      <?php endforeach; endif; ?>
    </div>

    <form method="post" class="add-role">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_role">
      <div class="add-role-label">Add new role</div>
      <div class="add-role-row">
        <input name="name" id="newRole" placeholder="Role name" required>
        <button class="btn btn-primary save-flash" type="submit">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          Add
        </button>
      </div>
    </form>

    <?php if ($role_id > 0): ?>
      <form method="post" class="roles-foot" onsubmit="return confirm('Delete this role permanently?');">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete_role">
        <input type="hidden" name="role_id" value="<?= (int)$role_id ?>">
        <button class="btn-danger-soft" type="submit">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path></svg>
          Delete Selected Role
        </button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Permissions -->
  <div class="panel">
    <div class="panel-head">
      <div class="panel-title">
        <span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg></span>
        Permissions
      </div>
    </div>

    <?php if ($role_id <= 0): ?>
      <div class="empty-state">Select a role on the left to edit its permissions.</div>
    <?php else: ?>
      <form method="post" id="permsForm">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_permissions">
        <input type="hidden" name="role_id" value="<?= (int)$role_id ?>">

        <div class="selected-role-banner">
          <span class="lbl">Editing</span>
          <span class="role-pill"><span class="dot"></span><?= h($selectedRole['name'] ?? '') ?></span>
          <span class="count"><b id="allowedRowCount"><?= (int)$allowedCount ?></b> of <b><?= (int)$totalPerms ?></b> permissions allowed</span>
        </div>

        <table class="perms-table">
          <thead>
            <tr>
              <th>Permission</th>
              <th>Key</th>
              <th class="right">Allowed</th>
            </tr>
          </thead>
          <tbody id="permRows">
            <?php if (!$perms): ?>
              <tr><td colspan="3" class="empty-state">No permissions defined.</td></tr>
            <?php else: foreach ($perms as $p):
              $isAllowed = !empty($allowedMap[(int)$p['id']]);
              $desc = $permDescriptions[(string)$p['perm_key']] ?? '';
            ?>
              <tr class="<?= $isAllowed ? 'allowed' : '' ?>" data-perm="<?= h($p['perm_key']) ?>">
                <td>
                  <div class="perm-name">
                    <span class="perm-icon"><?= rp_perm_icon((string)$p['perm_key']) ?></span>
                    <div>
                      <div class="perm-label"><?= h($p['label']) ?></div>
                      <?php if ($desc !== ''): ?><div class="perm-desc"><?= h($desc) ?></div><?php endif; ?>
                    </div>
                  </div>
                </td>
                <td><span class="perm-key"><?= h($p['perm_key']) ?></span></td>
                <td style="text-align:right;">
                  <label class="toggle <?= $isAllowed ? 'on' : '' ?>">
                    <input type="checkbox" name="perm_<?= (int)$p['id'] ?>" <?= $isAllowed ? 'checked' : '' ?>>
                  </label>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>

        <div class="perms-foot">
          <span class="save-hint" id="saveHint">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            All changes saved
          </span>
          <button class="btn btn-primary save-flash" type="submit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
            Save Permissions
          </button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Edit role modal -->
<div class="modal-overlay" id="editRoleModal" onclick="if(event.target===this) this.classList.remove('open')">
  <div class="modal-card">
    <div class="modal-head">
      <span class="modal-title">Rename Role</span>
      <button type="button" class="icon-close" onclick="document.getElementById('editRoleModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
    </div>
    <form method="post">
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="edit_role">
        <input type="hidden" name="role_id" id="modal_role_id" value="">
        <label class="field-label" style="display:block;margin-bottom:6px;color:var(--text-muted);font-size:11.5px;">Role Name</label>
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

// Toggle interaction + dirty state tracking
(function(){
  const form = document.getElementById('permsForm');
  if (!form) return;
  const hint = document.getElementById('saveHint');
  const countEl = document.getElementById('allowedRowCount');
  function recount() {
    const n = document.querySelectorAll('#permRows input[type=checkbox]:checked').length;
    if (countEl) countEl.textContent = n;
  }
  document.querySelectorAll('#permRows .toggle').forEach(t => {
    t.addEventListener('click', e => {
      // Browsers without :has() — manually toggle
      const cb = t.querySelector('input[type=checkbox]');
      if (e.target !== cb) { cb.checked = !cb.checked; }
      t.classList.toggle('on', cb.checked);
      t.closest('tr').classList.toggle('allowed', cb.checked);
      if (hint) {
        hint.classList.add('dirty');
        hint.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>Unsaved changes';
      }
      recount();
    });
  });
})();
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
