<?php
require_once __DIR__ . '/layout.php';
$role=auth_user()['role_name'] ?? '';
if(!in_array($role,['CEO','CTO','Super Admin'],true)){ http_response_code(403); echo "Forbidden"; require __DIR__ . '/layout_end.php'; exit; }
?>
<h2 class="mb-3">Settings</h2>
<div class="row g-3">
  <div class="col-md-4">
    <a class="text-decoration-none" href="settings_project_types.php">
      <div class="card p-4 h-100">
        <div class="fw-semibold">Project Types</div>
        <div class="text-muted small">Manage project type options.</div>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a class="text-decoration-none" href="settings_project_statuses.php">
      <div class="card p-4 h-100">
        <div class="fw-semibold">Project Statuses</div>
        <div class="text-muted small">Manage project status options.</div>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a class="text-decoration-none" href="settings_task_statuses.php">
      <div class="card p-4 h-100">
        <div class="fw-semibold">Task Statuses</div>
        <div class="text-muted small">Manage task status options.</div>
      </div>
    </a>
  </div>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
