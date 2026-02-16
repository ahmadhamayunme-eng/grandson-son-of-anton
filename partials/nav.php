<?php
require_once __DIR__ . '/../lib/auth.php';
$u = auth_user();
$role = $u['role_name'] ?? '';
$path = basename($_SERVER['PHP_SELF']);
function active($p, $path){ return $p===$path ? 'active' : ''; }
?>
<div class="sidebar p-3">
  <div class="d-flex align-items-center mb-3">
    <div class="me-2" style="width:10px;height:10px;border-radius:50%;background:#FFD000;"></div>
    <div class="brand">AntonX</div>
  </div>
  <div class="small-help mb-3">
    Logged in as <b><?=h($u['name'] ?? '')?></b><br>
    <span class="badge badge-soft"><?=h($role)?></span>
  </div>
  <nav class="nav flex-column gap-1">
    <a class="nav-link <?=active('dashboard.php',$path)?>" href="dashboard.php">Dashboard</a>
    <a class="nav-link <?=active('search.php',$path)?>" href="search.php">Search</a>
    <a class="nav-link <?=active('my_tasks.php',$path)?>" href="my_tasks.php">My Tasks</a>
    <a class="nav-link <?=active('clients.php',$path)?>" href="clients.php">Clients</a>
    <a class="nav-link <?=active('projects.php',$path)?>" href="projects.php">Projects</a>
    <a class="nav-link <?=active('docs.php',$path)?>" href="docs.php">Docs</a>
<a class="nav-link <?=active('ui_pages/reports_overview.php',$path)?>" href="ui_pages/reports_overview.php">Reports</a>

    <?php if ($role === 'CTO' || $role === 'Super Admin'): ?>
      <div class="mt-3 text-muted small">CTO</div>
      <a class="nav-link <?=active('cto_review.php',$path)?>" href="cto_review.php">Review Completed Tasks</a>
      <a class="nav-link <?=active('cto_submit.php',$path)?>" href="cto_submit.php">Submit to Client</a>
    <?php endif; ?>

    <?php if (auth_can_finance()): ?>
      <div class="mt-3 text-muted small">Finance</div>
      <a class="nav-link <?=active('finance.php',$path)?>" href="finance.php">Finance Dashboard</a>
      <a class="nav-link <?=active('payments_received.php',$path)?>" href="payments_received.php">Payments Received</a>
      <a class="nav-link <?=active('unreceived_payments.php',$path)?>" href="unreceived_payments.php">Unreceived Payments</a>
      <a class="nav-link <?=active('project_expenses.php',$path)?>" href="project_expenses.php">Project Expenses</a>
      <a class="nav-link <?=active('salaries.php',$path)?>" href="salaries.php">Salaries</a>
      <a class="nav-link <?=active('overhead_cost.php',$path)?>" href="overhead_cost.php">Overhead</a>
      <a class="nav-link <?=active('billing_records.php',$path)?>" href="billing_records.php">Billing Records</a>
    <?php endif; ?>

    <?php if (in_array($role, ['CEO','CTO','Super Admin'], true)): ?>
      <div class="mt-3 text-muted small">Admin</div>
      <a class="nav-link <?=active('users_management.php',$path)?>" href="users_management.php">Users</a>
      <a class="nav-link <?=active('roles_permissions.php',$path)?>" href="roles_permissions.php">Roles & Permissions</a>
      <a class="nav-link <?=active('settings.php',$path)?>" href="settings.php">Settings</a>
      <a class="nav-link" href="ui_pages/index.php">All UI Screens</a>
    <?php endif; ?>

    <div class="mt-3"></div>
    <a class="nav-link" href="logout.php">Logout</a>
  </nav>
</div>
