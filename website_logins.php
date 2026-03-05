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

  $pdo->prepare('INSERT INTO website_logins (workspace_id,client_id,project_id,site_name,website_url,login_url,login_username,login_password,notes,created_by,created_at,updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$ws, $clientId ?: null, $projectId ?: null, $siteName, $websiteUrl ?: null, $loginUrl ?: null, $username ?: null, $password ?: null, $notes ?: null, (int)($user['id'] ?? 0), now(), now()]);
  flash_set('success', 'Website login saved.');
  redirect('website_logins.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$prefillClientId = (int)($_GET['client_id'] ?? 0);
$prefillProjectId = (int)($_GET['project_id'] ?? 0);

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
?>

<style>
  .wl-shell{border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:16px;background:linear-gradient(160deg, rgba(13,13,13,.96), rgba(9,9,9,.96));}
  .pw{font-family:ui-monospace, SFMono-Regular, Menlo, monospace}
</style>

<div class="wl-shell">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="m-0">Website Logins</h2>
  </div>

  <form class="mb-3" method="get">
    <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search by website name, project, or client...">
  </form>

  <div class="card p-3 mb-3">
    <h5>Add Website Login</h5>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Website Name</label><input class="form-control" name="site_name" required></div>
        <div class="col-md-6"><label class="form-label">Website URL</label><input class="form-control" name="website_url" placeholder="https://example.com"></div>
        <div class="col-md-6"><label class="form-label">Login URL</label><input class="form-control" name="login_url" placeholder="https://example.com/wp-admin"></div>
        <div class="col-md-6"><label class="form-label">Username</label><input class="form-control" name="login_username"></div>
        <div class="col-md-6"><label class="form-label">Password</label><input class="form-control" type="password" name="login_password"></div>
        <div class="col-md-6"><label class="form-label">Client (optional)</label><select class="form-select" name="client_id"><option value="0">None</option><?php foreach($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $prefillClientId===(int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-12"><label class="form-label">Project (optional)</label><select class="form-select" name="project_id"><option value="0">None</option><?php foreach($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $prefillProjectId===(int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?> — <?= h($p['client_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="3" placeholder="2FA notes / owner contact etc."></textarea></div>
      </div>
      <button class="btn btn-yellow mt-3">Save Website Login</button>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-dark table-striped align-middle">
      <thead><tr><th>Website</th><th>Client / Project</th><th>Username</th><th>Password</th><th>Login URL</th><th>Notes</th><th></th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><div class="fw-semibold"><?= h($r['site_name']) ?></div><div class="small text-muted"><?= h($r['website_url'] ?: '—') ?></div></td>
          <td><div><?= h($r['client_name'] ?: '—') ?></div><div class="small text-muted"><?= h($r['project_name'] ?: '—') ?></div></td>
          <td><?= h($r['login_username'] ?: '—') ?></td>
          <td class="pw"><span class="pw-mask">••••••••</span><span class="pw-real d-none"><?= h($r['login_password'] ?: '') ?></span> <button type="button" class="btn btn-sm btn-outline-light reveal-btn">Reveal</button></td>
          <td><?php if (!empty($r['login_url'])): ?><a href="<?= h($r['login_url']) ?>" target="_blank" rel="noopener">Open</a><?php else: ?>—<?php endif; ?></td>
          <td><?= nl2br(h($r['notes'] ?: '—')) ?></td>
          <td><?php if ($canManage): ?><form method="post" onsubmit="return confirm('Delete this login permanently?');"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="7" class="text-muted">No website logins yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
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
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
