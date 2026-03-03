<?php
require_once __DIR__ . '/layout.php';
$pdo = db(); $ws = auth_workspace_id();
$role = auth_user()['role_name'] ?? '';
if (!in_array($role, ['CEO','CTO','Super Admin'], true)) { http_response_code(403); echo 'Forbidden'; require __DIR__ . '/layout_end.php'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post(); csrf_verify();
  $name = trim($_POST['name'] ?? '');
  if ($name === '') { flash_set('error', 'Name required.'); redirect('settings_project_statuses.php'); }
  $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM project_statuses WHERE workspace_id=$ws")->fetch()['n'];
  $pdo->prepare("INSERT INTO project_statuses (workspace_id,name,sort_order,created_at,updated_at) VALUES (?,?,?,?,?)")
      ->execute([$ws, $name, $sort, now(), now()]);
  flash_set('success', 'Added.');
  redirect('settings_project_statuses.php');
}
$rows = $pdo->query("SELECT * FROM project_statuses WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();
?>

<style>
  .sps-shell{border:1px solid rgba(255,255,255,.1);border-radius:18px;background:linear-gradient(180deg,#111111,#090909);padding:1.15rem;box-shadow:0 24px 60px rgba(0,0,0,.4)}
  .sps-head{display:flex;justify-content:space-between;align-items:end;gap:.7rem;margin-bottom:1rem}
  .sps-title{margin:0;font-size:2rem;font-weight:700}
  .sps-sub{color:rgba(232,232,232,.68);font-size:.92rem}
  .sps-kpi{border:1px solid rgba(255,255,255,.1);border-radius:10px;background:rgba(255,255,255,.03);padding:.45rem .65rem;text-align:center;min-width:120px}
  .sps-kpi .n{font-weight:700;font-size:1.25rem;line-height:1}
  .sps-kpi .l{font-size:.78rem;color:rgba(232,232,232,.68)}
  .sps-grid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:.8rem}
  .sps-card{border:1px solid rgba(255,255,255,.1);border-radius:12px;background:rgba(255,255,255,.02);padding:.85rem}
  .sps-card-title{font-weight:600;margin-bottom:.55rem}
  .sps-table{overflow:hidden;border:1px solid rgba(255,255,255,.08);border-radius:10px;background:rgba(255,255,255,.015)}
  .sps-table table{width:100%;border-collapse:collapse}
  .sps-table th,.sps-table td{padding:.7rem .72rem;border-bottom:1px solid rgba(255,255,255,.07)}
  .sps-table th{background:rgba(255,255,255,.03);color:rgba(228,228,228,.82);font-size:.85rem;font-weight:600}
  .sps-table tr:last-child td{border-bottom:0}
  @media (max-width: 1100px){.sps-grid{grid-template-columns:1fr}}
</style>

<div class="sps-shell">
  <div class="sps-head">
    <div>
      <h1 class="sps-title">Project Statuses</h1>
      <div class="sps-sub">Define project workflow statuses used across boards and reports.</div>
    </div>
    <div class="sps-kpi"><div class="n"><?= count($rows) ?></div><div class="l">Total Statuses</div></div>
  </div>

  <div class="sps-grid">
    <div class="sps-card">
      <div class="sps-card-title">Add Status</div>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <div class="col-12"><input class="form-control" name="name" placeholder="e.g. In Progress" required></div>
        <div class="col-12"><button class="btn btn-yellow w-100">Add Project Status</button></div>
      </form>
    </div>

    <div class="sps-card">
      <div class="sps-card-title">Current Statuses</div>
      <div class="sps-table">
        <table>
          <thead><tr><th>Name</th><th class="text-muted">Sort</th></tr></thead>
          <tbody>
            <?php foreach($rows as $r): ?><tr><td class="fw-semibold"><?=h($r['name'])?></td><td class="text-muted"><?=h($r['sort_order'])?></td></tr><?php endforeach; ?>
            <?php if(!$rows): ?><tr><td colspan="2" class="text-muted">No statuses yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
