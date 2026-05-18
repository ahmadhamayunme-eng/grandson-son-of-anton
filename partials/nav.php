<?php
require_once __DIR__ . '/../lib/auth.php';
$u = auth_user();
$role = $u['role_name'] ?? '';
$path = basename($_SERVER['PHP_SELF']);
$showFinanceNav = false; // Finance section is intentionally hidden — pages remain accessible.

$activeKey = $activeKey ?? '';
$initials = user_initials($u['name'] ?? '?');
$avatarUrl = user_avatar_url((int)($u['id'] ?? 0));

function nav_active(string $key, string $current): string {
  return $key === $current ? ' active' : '';
}
function nav_item_html(string $href, string $key, string $label, string $iconSvg, string $activeKey): string {
  $cls = 'nav-item' . nav_active($key, $activeKey);
  return '<a class="' . $cls . '" data-key="' . h($key) . '" href="' . h($href) . '">'
       . $iconSvg
       . h($label) . '</a>';
}

$icons = [
  'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect></svg>',
  'search'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>',
  'my-tasks'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg>',
  'clients'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 00-3-3.87"></path><path d="M16 3.13a4 4 0 010 7.75"></path></svg>',
  'projects'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"></path></svg>',
  'docs'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>',
  'logins'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0110 0v4"></path></svg>',
  'account'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z"></path></svg>',
  'review'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg>',
  'submit'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>',
  'archive'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8"></path><path d="M1 3h22v5H1z"></path><line x1="10" y1="12" x2="14" y2="12"></line></svg>',
  'users'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>',
  'roles'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>',
  'settings'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z"></path></svg>',
  'logout'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>',
];
?>
<aside class="sidebar">
  <a class="brand" href="dashboard.php" aria-label="anton x">
    <img src="partials/antonx-logo.png" alt="anton x" class="brand-logo">
  </a>
  <nav class="nav" id="antonNav">
    <?= nav_item_html('dashboard.php', 'dashboard', 'Dashboard', $icons['dashboard'], $activeKey) ?>
    <?= nav_item_html('search.php', 'search', 'Search', $icons['search'], $activeKey) ?>
    <?= nav_item_html('my_tasks.php', 'my-tasks', 'My Tasks', $icons['my-tasks'], $activeKey) ?>
    <?= nav_item_html('clients.php', 'clients', 'Clients', $icons['clients'], $activeKey) ?>
    <?= nav_item_html('projects.php', 'projects', 'Projects', $icons['projects'], $activeKey) ?>
    <?= nav_item_html('docs.php', 'docs', 'Docs', $icons['docs'], $activeKey) ?>
    <?= nav_item_html('website_logins.php', 'logins', 'Website Logins', $icons['logins'], $activeKey) ?>
    <?= nav_item_html('profile_account_settings_overview.php', 'account', 'Account Settings', $icons['account'], $activeKey) ?>

    <?php if ($role === 'Manager' || $role === 'Super Admin'): ?>
      <div class="nav-section">Manager</div>
      <?= nav_item_html('manager_review.php', 'review', 'Review Completed Tasks', $icons['review'], $activeKey) ?>
      <?= nav_item_html('manager_submit.php', 'submit', 'Submit to Client', $icons['submit'], $activeKey) ?>
      <?= nav_item_html('completed_task_archive.php', 'archive', 'Completed Task Archive', $icons['archive'], $activeKey) ?>
    <?php endif; ?>

    <?php if ($showFinanceNav && auth_can_finance()): ?>
      <div class="nav-section">Finance</div>
      <?= nav_item_html('finance.php', 'finance', 'Finance Dashboard', '', $activeKey) ?>
    <?php endif; ?>

    <?php if (in_array($role, ['CEO','Manager','Super Admin'], true)): ?>
      <div class="nav-section">Admin</div>
      <?= nav_item_html('users_management.php', 'users', 'Users', $icons['users'], $activeKey) ?>
      <?= nav_item_html('roles_permissions.php', 'roles', 'Roles & Permissions', $icons['roles'], $activeKey) ?>
      <?= nav_item_html('settings.php', 'settings', 'Settings', $icons['settings'], $activeKey) ?>
    <?php endif; ?>

    <?= nav_item_html('logout.php', 'logout', 'Logout', $icons['logout'], $activeKey) ?>
  </nav>
  <div class="user-card">
    <div class="avatar">
      <?php if ($avatarUrl): ?>
        <img src="<?= h($avatarUrl) ?>" alt="<?= h($u['name'] ?? 'User') ?>">
      <?php else: ?>
        <?= h($initials) ?>
      <?php endif; ?>
    </div>
    <div>
      <div class="name"><?= h($u['name'] ?? 'User') ?></div>
      <div class="role"><?= h($role ?: 'Member') ?></div>
    </div>
  </div>
</aside>
