<?php
require_once __DIR__ . '/layout.php';
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
  try {
    $pdo->prepare("INSERT IGNORE INTO permissions (perm_key,label) VALUES (?,?)")->execute([$p[0], $p[1]]);
  } catch (Throwable $e) {
  }
}

$roles = $pdo->query("SELECT id,name FROM roles ORDER BY id")->fetchAll();
$perms = $pdo->query("SELECT id,perm_key,label FROM permissions ORDER BY perm_key")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  csrf_verify();
  $role_id = (int)($_POST['role_id'] ?? 0);
  if ($role_id > 0) {
    foreach ($perms as $perm) {
      $key = 'perm_' . $perm['id'];
      $allowed = isset($_POST[$key]) ? 1 : 0;
      $pdo->prepare("INSERT INTO role_permissions (role_id,permission_id,is_allowed) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE is_allowed=VALUES(is_allowed)")->execute([$role_id, (int)$perm['id'], $allowed]);
    }
    flash_set('success', 'Permissions saved');
    redirect(basename(__FILE__) . '?role_id=' . $role_id);
  }
}

$role_id = (int)($_GET['role_id'] ?? ($roles[0]['id'] ?? 0));
$allowedMap = [];
if ($role_id > 0) {
  $st = $pdo->prepare("SELECT permission_id,is_allowed FROM role_permissions WHERE role_id=?");
  $st->execute([$role_id]);
  foreach ($st->fetchAll() as $r) {
    $allowedMap[(int)$r['permission_id']] = (int)$r['is_allowed'];
  }
}

$allowedCount = 0;
foreach ($perms as $perm) {
  if (!empty($allowedMap[(int)$perm['id']])) $allowedCount++;
}
$totalPerms = count($perms);
?>

<style>
  .rp-shell{border:1px solid rgba(255,255,255,.1);border-radius:18px;background:linear-gradient(180deg,#111111,#090909);padding:1.15rem;box-shadow:0 24px 60px rgba(0,0,0,.4)}
  .rp-head{display:flex;justify-content:space-between;align-items:center;gap:.8rem;margin-bottom:.95rem}
  .rp-title{margin:0;font-size:2rem;font-weight:700}
  .rp-sub{color:rgba(232,232,232,.68);font-size:.92rem}
  .rp-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.65rem;margin-bottom:.95rem}
  .rp-metric{border:1px solid rgba(255,255,255,.1);border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,.045),rgba(255,255,255,.02));padding:.72rem .85rem}
  .rp-metric-label{font-size:.82rem;color:rgba(232,232,232,.68)}
  .rp-metric-value{font-size:1.55rem;font-weight:700;line-height:1.1}
  .rp-grid{display:grid;grid-template-columns:290px minmax(0,1fr);gap:.8rem}
  .rp-card{border:1px solid rgba(255,255,255,.1);border-radius:12px;background:rgba(255,255,255,.02);padding:.85rem}
  .rp-card-title{font-size:1.02rem;font-weight:600;margin-bottom:.6rem}
  .role-list{display:flex;flex-direction:column;gap:.35rem}
  .role-link{display:block;border:1px solid rgba(255,255,255,.09);border-radius:10px;background:rgba(255,255,255,.02);padding:.55rem .65rem;text-decoration:none;color:#e7e7e7}
  .role-link:hover{background:rgba(255,255,255,.05);color:#fff}
  .role-link.active{border-color:rgba(246,212,105,.35);background:rgba(246,212,105,.12);color:#f6d469}
  .perm-table{overflow:hidden;border:1px solid rgba(255,255,255,.08);border-radius:10px;background:rgba(255,255,255,.015)}
  .perm-table table{width:100%;border-collapse:collapse}
  .perm-table th,.perm-table td{padding:.68rem .72rem;border-bottom:1px solid rgba(255,255,255,.07)}
  .perm-table th{background:rgba(255,255,255,.03);font-size:.85rem;font-weight:600;color:rgba(228,228,228,.82)}
  .perm-table tr:last-child td{border-bottom:0}
  .perm-key{color:rgba(220,220,220,.65);font-size:.82rem}
  @media (max-width: 1100px){.rp-grid{grid-template-columns:1fr}.rp-metrics{grid-template-columns:1fr}}
</style>

<div class="rp-shell">
  <div class="rp-head">
    <div>
      <h1 class="rp-title">Roles & Permissions</h1>
      <div class="rp-sub">Control access by role using clear, workspace-wide permission toggles.</div>
    </div>
  </div>

  <div class="rp-metrics">
    <div class="rp-metric"><div class="rp-metric-label">Roles</div><div class="rp-metric-value"><?= count($roles) ?></div></div>
    <div class="rp-metric"><div class="rp-metric-label">Permissions</div><div class="rp-metric-value"><?= (int)$totalPerms ?></div></div>
    <div class="rp-metric"><div class="rp-metric-label">Allowed for selected role</div><div class="rp-metric-value"><?= (int)$allowedCount ?></div></div>
  </div>

  <div class="rp-grid">
    <div class="rp-card">
      <div class="rp-card-title">Roles</div>
      <div class="role-list">
        <?php foreach ($roles as $r): ?>
          <a class="role-link <?= $role_id === (int)$r['id'] ? 'active' : '' ?>" href="?role_id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="rp-card">
      <div class="rp-card-title">Permissions</div>
      <?php if ($role_id <= 0): ?>
        <div class="text-muted">No roles found.</div>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="role_id" value="<?= (int)$role_id ?>">
          <div class="perm-table">
            <table>
              <thead><tr><th>Permission</th><th>Key</th><th class="text-end">Allowed</th></tr></thead>
              <tbody>
              <?php foreach ($perms as $p): ?>
                <tr>
                  <td class="fw-semibold"><?= h($p['label']) ?></td>
                  <td class="perm-key"><?= h($p['perm_key']) ?></td>
                  <td class="text-end">
                    <input class="form-check-input" type="checkbox" name="perm_<?= (int)$p['id'] ?>" <?= (!empty($allowedMap[(int)$p['id']])) ? 'checked' : '' ?>>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$perms): ?><tr><td colspan="3" class="text-muted">No permissions found.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="d-flex justify-content-end mt-3">
            <button class="btn btn-yellow">Save Permissions</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
