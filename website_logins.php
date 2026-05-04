<?php
require_once __DIR__ . '/layout.php';

$pdo = db();
$ws = auth_workspace_id();
$user = auth_user();
$role = $user['role_name'] ?? '';
$canManage = in_array($role, ['CEO','Manager','Super Admin'], true);

$pdo->exec("CREATE TABLE IF NOT EXISTS website_logins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL,
  client_id INT NULL,
  project_id INT NULL,
  site_name VARCHAR(190) NOT NULL,
  website_url VARCHAR(255) NULL,
  login_url VARCHAR(255) NULL,
  login_username VARCHAR(190) NULL,
  login_password TEXT NULL,
  notes TEXT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_wl_ws (workspace_id),
  INDEX idx_wl_client (client_id),
  INDEX idx_wl_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  csrf_verify();
  $action = (string)($_POST['action'] ?? 'create');

  if ($action === 'bulk_delete' && $canManage) {
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids)) {
      $ids = [];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function($id){
      return $id > 0;
    })));
    if (!$ids) {
      flash_set('error', 'Select at least one login to delete.');
      redirect('website_logins.php');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$ws], $ids);
    $st = $pdo->prepare("DELETE FROM website_logins WHERE workspace_id=? AND id IN ($placeholders)");
    $st->execute($params);
    flash_set('success', $st->rowCount() . ' website login(s) deleted permanently.');
    redirect('website_logins.php');
  }

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $st = $pdo->prepare('DELETE FROM website_logins WHERE id=? AND workspace_id=?');
      $st->execute([$id, $ws]);
      flash_set('success', $st->rowCount() ? 'Website login deleted permanently.' : 'Login not found.');
    }
    redirect('website_logins.php');
  }

  $siteName = trim((string)($_POST['site_name'] ?? ''));
  $clientId = (int)($_POST['client_id'] ?? 0);
  $projectId = (int)($_POST['project_id'] ?? 0);
  $websiteUrl = trim((string)($_POST['website_url'] ?? ''));
  $loginUrl = trim((string)($_POST['login_url'] ?? ''));
  $username = trim((string)($_POST['login_username'] ?? ''));
  $password = (string)($_POST['login_password'] ?? '');
  $notes = trim((string)($_POST['notes'] ?? ''));

  if ($siteName === '') {
    flash_set('error', 'Website name is required.');
    redirect('website_logins.php');
  }

  if ($projectId > 0) {
    $st = $pdo->prepare('SELECT client_id FROM projects WHERE id=? AND workspace_id=? LIMIT 1');
    $st->execute([$projectId, $ws]);
    $row = $st->fetch();
    if (!$row) {
      flash_set('error', 'Selected project is invalid.');
      redirect('website_logins.php');
    }
    $clientId = (int)$row['client_id'];
  } elseif ($clientId > 0) {
    $st = $pdo->prepare('SELECT id FROM clients WHERE id=? AND workspace_id=? LIMIT 1');
    $st->execute([$clientId, $ws]);
    if (!$st->fetch()) {
      flash_set('error', 'Selected client is invalid.');
      redirect('website_logins.php');
    }
  }

  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      flash_set('error', 'Invalid login selected for update.');
      redirect('website_logins.php');
    }
    $existingSt = $pdo->prepare('SELECT login_password FROM website_logins WHERE id=? AND workspace_id=? LIMIT 1');
    $existingSt->execute([$id, $ws]);
    $existing = $existingSt->fetch();
    if (!$existing) {
      flash_set('error', 'Website login not found.');
      redirect('website_logins.php');
    }
    $finalPassword = trim($password) === '' ? (string)($existing['login_password'] ?? '') : $password;
    $pdo->prepare('UPDATE website_logins SET client_id=?, project_id=?, site_name=?, website_url=?, login_url=?, login_username=?, login_password=?, notes=?, updated_at=? WHERE id=? AND workspace_id=?')
      ->execute([$clientId ?: null, $projectId ?: null, $siteName, $websiteUrl ?: null, $loginUrl ?: null, $username ?: null, $finalPassword ?: null, $notes ?: null, now(), $id, $ws]);
    flash_set('success', 'Website login updated.');
    redirect('website_logins.php');
  } else {
    $pdo->prepare('INSERT INTO website_logins (workspace_id,client_id,project_id,site_name,website_url,login_url,login_username,login_password,notes,created_by,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$ws, $clientId ?: null, $projectId ?: null, $siteName, $websiteUrl ?: null, $loginUrl ?: null, $username ?: null, $password ?: null, $notes ?: null, (int)($user['id'] ?? 0), now(), now()]);
    flash_set('success', 'Website login saved.');
  }
  redirect('website_logins.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$prefillClientId = (int)($_GET['client_id'] ?? 0);
$prefillProjectId = (int)($_GET['project_id'] ?? 0);
$editId = (int)($_GET['edit_id'] ?? 0);
$editRow = null;

$clients = $pdo->prepare('SELECT id,name FROM clients WHERE workspace_id=? ORDER BY name');
$clients->execute([$ws]);
$clients = $clients->fetchAll();
$projects = $pdo->prepare('SELECT p.id,p.name,c.name AS client_name FROM projects p JOIN clients c ON c.id=p.client_id WHERE p.workspace_id=? ORDER BY p.name');
$projects->execute([$ws]);
$projects = $projects->fetchAll();

$like = '%' . $q . '%';
$list = $pdo->prepare("SELECT wl.*, c.name AS client_name, p.name AS project_name
  FROM website_logins wl
  LEFT JOIN clients c ON c.id=wl.client_id
  LEFT JOIN projects p ON p.id=wl.project_id
  WHERE wl.workspace_id=? AND (wl.site_name LIKE ? OR COALESCE(p.name,'') LIKE ? OR COALESCE(c.name,'') LIKE ?)
  ORDER BY wl.updated_at DESC, wl.id DESC");
$list->execute([$ws, $like, $like, $like]);
$rows = $list->fetchAll();
if ($editId > 0) {
  $st = $pdo->prepare('SELECT * FROM website_logins WHERE id=? AND workspace_id=? LIMIT 1');
  $st->execute([$editId, $ws]);
  $editRow = $st->fetch() ?: null;
}
?>

<style>
  .wl-shell{border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:16px;background:linear-gradient(160deg, rgba(13,13,13,.96), rgba(9,9,9,.96));color:#f4f4f6;}
  .wl-shell h2,.wl-shell h5,.wl-shell .form-label,.wl-shell .table,.wl-shell .table th,.wl-shell .table td{color:#fff !important;}
  .wl-shell .card{background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.12);}
  .wl-shell .form-control,.wl-shell .form-select{background:rgba(0,0,0,.2);border-color:rgba(255,255,255,.16);color:#fff;}
  .wl-shell .form-control::placeholder{color:#fff;opacity:1;}
  .wl-shell .text-muted,.wl-shell .small.text-muted{color:rgba(255,255,255,.72) !important;}
  .pw{font-family:ui-monospace, SFMono-Regular, Menlo, monospace}
  .pw-input-wrap{position:relative;}
  .pw-input-wrap .form-control{padding-right:44px;}
  .pw-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#111;font-size:18px;line-height:1;cursor:pointer;padding:4px 6px;}
  .pw-toggle:focus{outline:none;box-shadow:0 0 0 2px rgba(255,212,83,.28);border-radius:6px;}
</style>

<div class="wl-shell">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="m-0">Website Logins</h2>
  </div>

  <form class="mb-3" method="get">
    <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search by website name, project, or client...">
  </form>

  <div class="card p-3 mb-3">
    <h5><?= $editRow ? 'Edit Website Login' : 'Add Website Login' ?></h5>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
      <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Website Name</label><input class="form-control" name="site_name" value="<?= h((string)($editRow['site_name'] ?? '')) ?>" required></div>
        <div class="col-md-6"><label class="form-label">Website URL</label><input class="form-control" name="website_url" value="<?= h((string)($editRow['website_url'] ?? '')) ?>" placeholder="https://example.com"></div>
        <div class="col-md-6"><label class="form-label">Production URL</label><input class="form-control" name="login_url" value="<?= h((string)($editRow['login_url'] ?? '')) ?>" placeholder="https://staging.example.com or https://new.example.com/wp-admin"></div>
        <div class="col-md-6"><label class="form-label">Username</label><input class="form-control" name="login_username" value="<?= h((string)($editRow['login_username'] ?? '')) ?>"></div>
        <div class="col-md-6"><label class="form-label">Password</label><div class="pw-input-wrap"><input class="form-control" type="password" name="login_password" id="new_login_password"><button type="button" class="pw-toggle" data-target="new_login_password" aria-label="Show password" title="Show/Hide Password">👁</button></div></div>
        <div class="col-md-6"><label class="form-label">Client (optional)</label><select class="form-select" name="client_id"><option value="0">None</option><?php foreach($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (($editRow ? (int)$editRow['client_id'] : $prefillClientId)===(int)$c['id']) ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-12"><label class="form-label">Project (optional)</label><select class="form-select" name="project_id"><option value="0">None</option><?php foreach($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (($editRow ? (int)$editRow['project_id'] : $prefillProjectId)===(int)$p['id']) ? 'selected' : '' ?>><?= h($p['name']) ?> — <?= h($p['client_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="3" placeholder="2FA notes / owner contact etc."><?= h((string)($editRow['notes'] ?? '')) ?></textarea></div>
      </div>
      <button class="btn btn-yellow mt-3"><?= $editRow ? 'Update Website Login' : 'Save Website Login' ?></button>
      <?php if ($editRow): ?><a href="website_logins.php" class="btn btn-outline-light mt-3 ms-2">Cancel Edit</a><?php endif; ?>
    </form>
  </div>

  <?php if ($canManage): ?>
  <form method="post" onsubmit="return confirm('Delete selected logins permanently?');" class="mb-2">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="bulk_delete">
  <?php endif; ?>
  <div class="table-responsive">
    <table class="table table-dark table-striped align-middle">
      <thead><tr><?php if ($canManage): ?><th><input type="checkbox" id="bulk_select_all" aria-label="Select all"></th><?php endif; ?><th>Website</th><th>Client / Project</th><th>Username</th><th>Password</th><th>Production URL</th><th>Notes</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <?php if ($canManage): ?><td><input type="checkbox" class="bulk-row" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select row"></td><?php endif; ?>
          <td><div class="fw-semibold"><?= h($r['site_name']) ?></div><div class="small text-muted"><?= h($r['website_url'] ?: '—') ?></div></td>
          <td><div><?= h($r['client_name'] ?: '—') ?></div><div class="small text-muted"><?= h($r['project_name'] ?: '—') ?></div></td>
          <td><?= h($r['login_username'] ?: '—') ?></td>
          <td class="pw"><span class="pw-mask">••••••••</span><span class="pw-real d-none"><?= h($r['login_password'] ?: '') ?></span> <button type="button" class="btn btn-sm btn-outline-light reveal-btn">Reveal</button></td>
          <td><?php if (!empty($r['login_url'])): ?><a href="<?= h($r['login_url']) ?>" target="_blank" rel="noopener">Open</a><?php else: ?>—<?php endif; ?></td>
          <td><?= nl2br(h($r['notes'] ?: '—')) ?></td>
          <td>
            <a class="btn btn-sm btn-outline-light" href="website_logins.php?edit_id=<?= (int)$r['id'] ?>">Edit</a>
            <?php if ($canManage): ?><form method="post" class="d-inline" onsubmit="return confirm('Delete this login permanently?');"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="<?= $canManage ? '8' : '7' ?>" class="text-muted">No website logins yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($canManage): ?>
    <button class="btn btn-outline-danger btn-sm">Delete Selected</button>
  </form>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('.pw-toggle').forEach(function(btn){
  btn.addEventListener('click', function(){
    var id = btn.getAttribute('data-target');
    var input = document.getElementById(id);
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? '🙈' : '👁';
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });
});

document.querySelectorAll('.reveal-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    var td = btn.closest('td');
    var mask = td.querySelector('.pw-mask');
    var real = td.querySelector('.pw-real');
    var show = real.classList.contains('d-none');
    real.classList.toggle('d-none', !show);
    mask.classList.toggle('d-none', show);
    btn.textContent = show ? 'Hide' : 'Reveal';
  });
});

var bulkSelectAll = document.getElementById('bulk_select_all');
if (bulkSelectAll) {
  bulkSelectAll.addEventListener('change', function(){
    document.querySelectorAll('.bulk-row').forEach(function(cb){
      cb.checked = bulkSelectAll.checked;
    });
  });
}
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
