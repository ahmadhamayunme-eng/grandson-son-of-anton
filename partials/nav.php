<?php
require_once __DIR__ . '/../lib/auth.php';
$u = auth_user();
$role = $u['role_name'] ?? '';
$path = basename($_SERVER['PHP_SELF']);

function active($p, $path){ return $p === $path ? 'active' : ''; }
function nav_item($href, $label, $icon, $path){
  $cls = active($href, $path);
  return '<a class="nav-link sidebar-link '.$cls.'" href="'.$href.'"><span class="sidebar-icon">'.$icon.'</span><span>'.h($label).'</span></a>';
}
$initials = strtoupper(substr($u['name'] ?? 'U', 0, 1));
?>
<style>
  .sidebar-wrap { display: flex; flex-direction: column; height: 100%; }
  .sidebar-brand { display: flex; align-items: center; gap: 10px; padding: 8px 8px 16px; margin-bottom: 6px; border-bottom: 1px solid rgba(255,255,255,.06); }
  .brand-badge { width: 34px; height: 34px; border-radius: 999px; display: grid; place-items: center; border: 1px solid rgba(246,212,105,.55); color: #f6d469; font-size: 1.25rem; line-height: 1; }
  .sidebar-brand-name { font-size: 2.6rem; font-weight: 600; color: #f0f0f3; letter-spacing: .01em; }
  .sidebar-link { display: flex; align-items: center; gap: 10px; font-size: 1.02rem; margin-bottom: 3px; }
  .sidebar-icon { width: 22px; text-align: center; opacity: .86; color: inherit; font-size: 1rem; line-height: 1; }
  .sidebar-label { margin-top: 14px; margin-bottom: 6px; text-transform: uppercase; font-size: .82rem; color: rgba(236,236,240,.62); letter-spacing: .08em; font-weight: 600; padding: 0 10px; }
  .sidebar-dot-item { color: rgba(236,236,240,.72); display: block; text-decoration: none; border-radius: 10px; padding: 8px 12px 8px 28px; position: relative; margin-bottom: 2px; }
  .sidebar-dot-item::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: rgba(240,240,244,.85); position: absolute; left: 12px; top: 50%; transform: translateY(-50%); }
  .sidebar-dot-item:hover { background: rgba(255,255,255,.04); color: #fff; }
  .sidebar-dot-item.active { background: rgba(246,212,105,.12); color: #f6d469; }
  .sidebar-dot-item.active::before { background: #f6d469; }
  .sidebar-footer { margin-top: auto; border: 1px solid rgba(255,255,255,.08); border-radius: 12px; background: linear-gradient(90deg, rgba(255,255,255,.08), rgba(255,255,255,.03)); padding: 10px; display: flex; gap: 10px; align-items: center; }
  .sidebar-avatar { width: 36px; height: 36px; border-radius: 999px; background: linear-gradient(135deg, #5b6c9b, #374263); color: #fff; font-weight: 600; display: grid; place-items: center; border: 1px solid rgba(255,255,255,.2); flex: none; }
  .sidebar-user-name { font-size: .95rem; font-weight: 600; line-height: 1.2; color: #efeff2; }
  .sidebar-user-role { font-size: .82rem; color: rgba(236,236,240,.62); line-height: 1.15; }
</style>
<div class="sidebar p-3">
  <div class="sidebar-wrap">
    <div class="sidebar-brand">
      <div class="brand-badge">⚡</div>
      <div class="sidebar-brand-name">AntonX</div>
    </div>

    <nav class="nav flex-column">
      <?=nav_item('dashboard.php', 'Dashboard', '◉', $path)?>
      <?=nav_item('search.php', 'Search', '⌘', $path)?>
      <?=nav_item('my_tasks.php', 'My Tasks', '☑', $path)?>
      <?=nav_item('clients.php', 'Clients', '✉', $path)?>
      <?=nav_item('projects.php', 'Projects', '⌂', $path)?>
      <?=nav_item('docs.php', 'Docs', '⌕', $path)?>
      <?=nav_item('reports_overview.php', 'Reports', '◫', $path)?>

      <?php if ($role === 'CTO' || $role === 'Super Admin'): ?>
        <div class="sidebar-label">CTO</div>
        <a class="sidebar-dot-item <?=active('cto_review.php', $path)?>" href="cto_review.php">Review Completed Tasks</a>
        <a class="sidebar-dot-item <?=active('cto_submit.php', $path)?>" href="cto_submit.php">Submit to Client</a>
      <?php endif; ?>

      <?php if (auth_can_finance()): ?>
        <div class="sidebar-label">Finance</div>
        <a class="sidebar-dot-item <?=active('finance.php', $path)?>" href="finance.php">Finance Dashboard</a>
        <a class="sidebar-dot-item <?=active('payments_received.php', $path)?>" href="payments_received.php">Payments Received</a>
        <a class="sidebar-dot-item <?=active('unreceived_payments.php', $path)?>" href="unreceived_payments.php">Unreceived Payments</a>
        <a class="sidebar-dot-item <?=active('project_expenses.php', $path)?>" href="project_expenses.php">Project Expenses</a>
        <a class="sidebar-dot-item <?=active('salaries.php', $path)?>" href="salaries.php">Salaries</a>
        <a class="sidebar-dot-item <?=active('overhead_cost.php', $path)?>" href="overhead_cost.php">Overhead</a>
      <?php endif; ?>

      <?php if (in_array($role, ['CEO','CTO','Super Admin'], true)): ?>
        <div class="sidebar-label">Admin</div>
        <a class="sidebar-dot-item <?=active('users_management.php', $path)?>" href="users_management.php">Users</a>
        <a class="sidebar-dot-item <?=active('roles_permissions.php', $path)?>" href="roles_permissions.php">Roles & Permissions</a>
        <a class="sidebar-dot-item <?=active('settings.php', $path)?>" href="settings.php">Settings</a>
      <?php endif; ?>

      <a class="sidebar-dot-item mt-2" href="logout.php">Logout</a>
    </nav>

    <div class="sidebar-footer mt-3">
      <div class="sidebar-avatar"><?=h($initials)?></div>
      <div>
        <div class="sidebar-user-name"><?=h($u['name'] ?? 'User')?></div>
        <div class="sidebar-user-role"><?=h($role ?: 'Member')?></div>
      </div>
    </div>
  </div>
</div>
