<?php
require_once __DIR__ . '/layout.php';
$role = auth_user()['role_name'] ?? '';
if (!in_array($role, ['CEO','CTO','Super Admin'], true)) { http_response_code(403); echo 'Forbidden'; require __DIR__ . '/layout_end.php'; exit; }
?>

<style>
  .set-shell{border:1px solid rgba(255,255,255,.1);border-radius:18px;background:linear-gradient(180deg,#111111,#090909);padding:1.15rem;box-shadow:0 24px 60px rgba(0,0,0,.4)}
  .set-head{margin-bottom:1rem}
  .set-title{margin:0;font-size:2rem;font-weight:700}
  .set-sub{color:rgba(232,232,232,.68);font-size:.92rem}
  .set-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem}
  .set-link{text-decoration:none;color:inherit}
  .set-card{height:100%;border:1px solid rgba(255,255,255,.1);border-radius:14px;background:linear-gradient(170deg,rgba(255,255,255,.04),rgba(255,255,255,.015));padding:1rem;transition:.2s ease}
  .set-card:hover{border-color:rgba(246,212,105,.32);background:linear-gradient(170deg,rgba(246,212,105,.09),rgba(255,255,255,.03));transform:translateY(-1px)}
  .set-card-title{font-size:1.1rem;font-weight:600;color:#ececf0;margin-bottom:.25rem}
  .set-card-sub{color:rgba(232,232,232,.68);font-size:.88rem}
  .set-card-action{margin-top:.7rem;font-size:.84rem;color:#f6d469}
  @media (max-width: 992px){.set-grid{grid-template-columns:1fr}}
</style>

<div class="set-shell">
  <div class="set-head">
    <h1 class="set-title">Settings</h1>
    <div class="set-sub">Manage reusable workflow lists used across projects and tasks.</div>
  </div>

  <div class="set-grid">
    <a class="set-link" href="settings_project_types.php">
      <div class="set-card">
        <div class="set-card-title">Project Types</div>
        <div class="set-card-sub">Maintain project type options used while creating projects.</div>
        <div class="set-card-action">Open Project Types →</div>
      </div>
    </a>

    <a class="set-link" href="settings_project_statuses.php">
      <div class="set-card">
        <div class="set-card-title">Project Statuses</div>
        <div class="set-card-sub">Control the status values available on projects.</div>
        <div class="set-card-action">Open Project Statuses →</div>
      </div>
    </a>

    <a class="set-link" href="settings_task_statuses.php">
      <div class="set-card">
        <div class="set-card-title">Task Statuses</div>
        <div class="set-card-sub">Control task progress status labels used by teams.</div>
        <div class="set-card-action">Open Task Statuses →</div>
      </div>
    </a>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
