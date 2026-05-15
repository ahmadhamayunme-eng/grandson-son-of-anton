<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();

$role = auth_user()['role_name'] ?? '';
if (!in_array($role, ['CEO','Manager','Super Admin'], true)) {
  http_response_code(403);
  $pageTitle = 'Forbidden';
  $activeKey = 'settings';
  require_once __DIR__ . '/layout.php';
  echo '<div style="padding:48px 32px;text-align:center;color:var(--text-muted);"><h3 style="margin-bottom:12px;color:var(--text);">Forbidden</h3></div>';
  require_once __DIR__ . '/layout_end.php';
  exit;
}

$pageTitle = 'Settings';
$activeKey = 'settings';
$pageHeadExtra = <<<HTML
<style>
  .page-header { padding: 28px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; }
  .settings-grid { max-width: 1640px; margin: 0 auto; width: 100%; padding: 28px 32px 60px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
  .set-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 22px; transition: all 0.15s; text-decoration: none; color: inherit; display: flex; flex-direction: column; gap: 10px; }
  .set-card:hover { border-color: rgba(250,204,21,0.4); background: var(--surface-2); transform: translateY(-1px); }
  .set-card .ico { width: 40px; height: 40px; border-radius: 10px; background: var(--accent-soft); color: var(--accent); display: inline-flex; align-items: center; justify-content: center; border: 1px solid rgba(250,204,21,0.2); margin-bottom: 4px; }
  .set-card .ico svg { width: 18px; height: 18px; }
  .set-card .t { font-size: 15px; font-weight: 600; color: var(--text); }
  .set-card .d { font-size: 12.5px; color: var(--text-muted); line-height: 1.55; }
  .set-card .a { font-size: 12px; color: var(--accent); margin-top: 4px; display: inline-flex; align-items: center; gap: 4px; }
  .set-card .a svg { width: 12px; height: 12px; }
  @media (max-width: 992px) { .settings-grid { grid-template-columns: 1fr; } }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Settings</div>
      <div class="page-sub">Manage reusable workflow lists used across projects and tasks.</div>
    </div>
  </div>
</div>

<div class="settings-grid">
  <a class="set-card" href="settings_project_types.php">
    <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"></path></svg></div>
    <div class="t">Project Types</div>
    <div class="d">Maintain project type options used while creating projects.</div>
    <div class="a">Open Project Types<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></div>
  </a>

  <a class="set-card" href="settings_project_statuses.php">
    <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>
    <div class="t">Project Statuses</div>
    <div class="d">Control the status values available on projects.</div>
    <div class="a">Open Project Statuses<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></div>
  </a>

  <a class="set-card" href="settings_task_statuses.php">
    <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg></div>
    <div class="t">Task Statuses</div>
    <div class="d">Control task progress status labels used by teams.</div>
    <div class="a">Open Task Statuses<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></div>
  </a>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
