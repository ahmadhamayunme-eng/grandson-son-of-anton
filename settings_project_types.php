<?php
require_once __DIR__ . '/layout.php';
$pdo = db(); $ws = auth_workspace_id();
$role = auth_user()['role_name'] ?? '';
if (!in_array($role, ['CEO','CTO','Super Admin'], true)) { http_response_code(403); echo 'Forbidden'; require __DIR__ . '/layout_end.php'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post(); csrf_verify();
  $name = trim($_POST['name'] ?? '');
  if ($name === '') { flash_set('error', 'Name required.'); redirect('settings_project_types.php'); }
  $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM project_types WHERE workspace_id=$ws")->fetch()['n'];
  $pdo->prepare("INSERT INTO project_types (workspace_id,name,sort_order,created_at,updated_at) VALUES (?,?,?,?,?)")
      ->execute([$ws, $name, $sort, now(), now()]);
  flash_set('success', 'Added.');
  redirect('settings_project_types.php');
}
$rows = $pdo->query("SELECT * FROM project_types WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();
?>

<style>
  .stype-shell{border:1px solid rgba(255,255,255,.1);border-radius:18px;background:linear-gradient(180deg,#111111,#090909);padding:1.15rem;box-shadow:0 24px 60px rgba(0,0,0,.4)}
  .stype-head{display:flex;justify-content:space-between;align-items:end;gap:.7rem;margin-bottom:1rem}
  .stype-title{margin:0;font-size:2rem;font-weight:700}
  .stype-sub{color:rgba(232,232,232,.68);font-size:.92rem}
  .stype-kpi{border:1px solid rgba(255,255,255,.1);border-radius:10px;background:rgba(255,255,255,.03);padding:.45rem .65rem;text-align:center;min-width:100px}
  .stype-kpi .n{font-weight:700;font-size:1.25rem;line-height:1}
  .stype-kpi .l{font-size:.78rem;color:rgba(232,232,232,.68)}
  .stype-grid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:.8rem}
  .stype-card{border:1px solid rgba(255,255,255,.1);border-radius:12px;background:rgba(255,255,255,.02);padding:.85rem}
  .stype-card-title{font-weight:600;margin-bottom:.55rem}
  .stype-table{overflow:hidden;border:1px solid rgba(255,255,255,.08);border-radius:10px;background:rgba(255,255,255,.015)}
  .stype-table table{width:100%;border-collapse:collapse}
  .stype-table th,.stype-table td{padding:.7rem .72rem;border-bottom:1px solid rgba(255,255,255,.07)}
  .stype-table th{background:rgba(255,255,255,.03);color:rgba(228,228,228,.82);font-size:.85rem;font-weight:600}
  .stype-table tr:last-child td{border-bottom:0}
  @media (max-width: 1100px){.stype-grid{grid-template-columns:1fr}}
</style>

<div class="stype-shell">
  <div class="stype-head">
    <div>
      <h1 class="stype-title">Project Types</h1>
      <div class="stype-sub">Define type options used in project creation forms.</div>
    </div>
    <div class="stype-kpi"><div class="n"><?= count($rows) ?></div><div class="l">Total Types</div></div>
  </div>

  <div class="stype-grid">
    <div class="stype-card">
      <div class="stype-card-title">Add Type</div>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <div class="col-12"><input class="form-control" name="name" placeholder="e.g. Website Redesign" required></div>
        <div class="col-12"><button class="btn btn-yellow w-100">Add Project Type</button></div>
      </form>
    </div>

    <div class="stype-card">
      <div class="stype-card-title">Current Types</div>
      <div class="stype-table">
        <table>
          <thead><tr><th>Name</th><th class="text-muted">Sort</th></tr></thead>
          <tbody>
            <?php foreach($rows as $r): ?><tr><td class="fw-semibold"><?=h($r['name'])?></td><td class="text-muted"><?=h($r['sort_order'])?></td></tr><?php endforeach; ?>
            <?php if(!$rows): ?><tr><td colspan="2" class="text-muted">No types yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
