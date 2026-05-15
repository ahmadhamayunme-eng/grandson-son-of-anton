<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();

$pdo = db();
$ws = auth_workspace_id();
$user = auth_user();
$role = $user['role_name'] ?? '';
$canManage = in_array($role, ['CEO','Manager','Super Admin'], true);

$pdo->exec("CREATE TABLE IF NOT EXISTS website_logins (
  id INT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL,
  client_id INT NULL, project_id INT NULL,
  site_name VARCHAR(190) NOT NULL, website_url VARCHAR(255) NULL, login_url VARCHAR(255) NULL,
  login_username VARCHAR(190) NULL, login_password TEXT NULL, notes TEXT NULL,
  created_by INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  INDEX idx_wl_ws (workspace_id), INDEX idx_wl_client (client_id), INDEX idx_wl_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post(); csrf_verify();
  $action = (string)($_POST['action'] ?? 'create');

  if ($action === 'bulk_delete' && $canManage) {
    $ids = is_array($_POST['ids'] ?? null) ? array_values(array_unique(array_filter(array_map('intval', $_POST['ids']), fn($i) => $i > 0))) : [];
    if (!$ids) { flash_set('error', 'Select at least one login to delete.'); redirect('website_logins.php'); }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("DELETE FROM website_logins WHERE workspace_id=? AND id IN ($placeholders)");
    $st->execute(array_merge([$ws], $ids));
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

  if ($siteName === '') { flash_set('error', 'Website name is required.'); redirect('website_logins.php'); }
  if ($projectId > 0) {
    $st = $pdo->prepare('SELECT client_id FROM projects WHERE id=? AND workspace_id=? LIMIT 1');
    $st->execute([$projectId, $ws]);
    $row = $st->fetch();
    if (!$row) { flash_set('error', 'Selected project is invalid.'); redirect('website_logins.php'); }
    $clientId = (int)$row['client_id'];
  } elseif ($clientId > 0) {
    $st = $pdo->prepare('SELECT id FROM clients WHERE id=? AND workspace_id=? LIMIT 1');
    $st->execute([$clientId, $ws]);
    if (!$st->fetch()) { flash_set('error', 'Selected client is invalid.'); redirect('website_logins.php'); }
  }

  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { flash_set('error', 'Invalid login.'); redirect('website_logins.php'); }
    $existingSt = $pdo->prepare('SELECT login_password FROM website_logins WHERE id=? AND workspace_id=? LIMIT 1');
    $existingSt->execute([$id, $ws]);
    $existing = $existingSt->fetch();
    if (!$existing) { flash_set('error', 'Website login not found.'); redirect('website_logins.php'); }
    $finalPassword = trim($password) === '' ? (string)($existing['login_password'] ?? '') : $password;
    $pdo->prepare('UPDATE website_logins SET client_id=?, project_id=?, site_name=?, website_url=?, login_url=?, login_username=?, login_password=?, notes=?, updated_at=? WHERE id=? AND workspace_id=?')
      ->execute([$clientId ?: null, $projectId ?: null, $siteName, $websiteUrl ?: null, $loginUrl ?: null, $username ?: null, $finalPassword ?: null, $notes ?: null, now(), $id, $ws]);
    flash_set('success', 'Website login updated.');
  } else {
    $pdo->prepare('INSERT INTO website_logins (workspace_id,client_id,project_id,site_name,website_url,login_url,login_username,login_password,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
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

$clientsStmt = $pdo->prepare('SELECT id,name FROM clients WHERE workspace_id=? ORDER BY name');
$clientsStmt->execute([$ws]);
$clients = $clientsStmt->fetchAll();
$projectsStmt = $pdo->prepare('SELECT p.id,p.name,c.name AS client_name FROM projects p JOIN clients c ON c.id=p.client_id WHERE p.workspace_id=? ORDER BY p.name');
$projectsStmt->execute([$ws]);
$projects = $projectsStmt->fetchAll();

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

$pageTitle = 'Website Logins';
$activeKey = 'logins';
$pageHeadExtra = <<<HTML
<style>
  .page-header { padding: 28px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; }
  .toolbar { max-width: 1640px; margin: 0 auto; width: 100%; padding: 24px 32px 18px; display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; }
  .search-box { position: relative; }
  .search-box svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--text-dim); }
  .search-box input { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 11px 14px 11px 40px; font-size: 13px; color: var(--text); outline: none; transition: all 0.15s; font-family: inherit; }
  .search-box input::placeholder { color: var(--text-dim); }
  .search-box input:focus { border-color: var(--border-strong); background: var(--surface-2); }
  .form-section { max-width: 1640px; margin: 0 auto 18px; padding: 0 32px; }
  .form-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 20px; }
  .form-card h3 { font-size: 15px; font-weight: 600; color: var(--text); margin-bottom: 14px; }
  .form-grid { display: grid; gap: 12px; grid-template-columns: repeat(12, 1fr); }
  .form-grid > * { display: flex; flex-direction: column; gap: 5px; }
  .c-12 { grid-column: span 12; } .c-6 { grid-column: span 6; } .c-4 { grid-column: span 4; }
  @media (max-width: 768px) { .c-6, .c-4 { grid-column: span 12; } }
  .form-footer { margin-top: 16px; display: flex; gap: 8px; }
  .pw-wrap { position: relative; }
  .pw-wrap input { padding-right: 40px; }
  .pw-toggle { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 28px; height: 28px; border-radius: 6px; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
  .pw-toggle:hover { background: var(--surface-hover); color: var(--text); }
  .pw-toggle svg { width: 14px; height: 14px; }
  .table-wrap { max-width: 1640px; margin: 0 auto 28px; width: 100%; padding: 0 32px; }
  .table-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  table.logins { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.logins thead th { text-align: left; padding: 12px 16px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-dim); background: var(--bg); border-bottom: 1px solid var(--border); }
  table.logins tbody tr { transition: background 0.12s; }
  table.logins tbody tr:hover { background: var(--surface-hover); }
  table.logins tbody td { padding: 14px 16px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
  table.logins tbody tr:last-child td { border-bottom: none; }
  .site-name { font-weight: 600; color: var(--text); font-size: 13.5px; }
  .site-sub { font-size: 11.5px; color: var(--text-dim); margin-top: 2px; }
  .pw-cell { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px; }
  .pw-mask { color: var(--text-muted); }
  .pw-real { color: var(--text); }
  .btn-tiny { padding: 5px 11px; font-size: 11.5px; font-weight: 500; border-radius: 6px; transition: all 0.12s; border: 1px solid var(--border); display: inline-flex; align-items: center; gap: 5px; color: var(--text); text-decoration: none; cursor: pointer; background: var(--surface); }
  .btn-tiny:hover { background: var(--surface-2); border-color: var(--border-strong); }
  .btn-tiny.danger { color: #fca5a5; border-color: rgba(239,68,68,0.25); background: transparent; }
  .btn-tiny.danger:hover { background: var(--danger-soft); border-color: rgba(239,68,68,0.4); }
  .empty-state { padding: 48px 20px; text-align: center; color: var(--text-dim); font-size: 13px; }
  .bulk-bar { max-width: 1640px; margin: 0 auto; padding: 0 32px 12px; display: flex; align-items: center; gap: 12px; }
  .bulk-bar .msg { color: var(--text-dim); font-size: 12.5px; }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Website Logins</div>
      <div class="page-sub">Centralised credentials for client and project websites.</div>
    </div>
  </div>
</div>

<form class="toolbar" method="get">
  <div class="search-box">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
    <input name="q" value="<?= h($q) ?>" placeholder="Search by website name, project, or client…">
  </div>
  <button class="btn btn-primary save-flash" type="submit">Search</button>
</form>

<div class="form-section">
  <div class="form-card">
    <h3><?= $editRow ? 'Edit Website Login' : 'Add Website Login' ?></h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
      <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
      <div class="form-grid">
        <div class="c-6"><label class="field-label">Website Name</label><input class="input" name="site_name" value="<?= h((string)($editRow['site_name'] ?? '')) ?>" required></div>
        <div class="c-6"><label class="field-label">Website URL</label><input class="input" name="website_url" value="<?= h((string)($editRow['website_url'] ?? '')) ?>" placeholder="https://example.com"></div>
        <div class="c-6"><label class="field-label">Production URL</label><input class="input" name="login_url" value="<?= h((string)($editRow['login_url'] ?? '')) ?>" placeholder="https://example.com/wp-admin"></div>
        <div class="c-6"><label class="field-label">Username</label><input class="input" name="login_username" value="<?= h((string)($editRow['login_username'] ?? '')) ?>"></div>
        <div class="c-6"><label class="field-label">Password</label>
          <div class="pw-wrap"><input class="input" type="password" name="login_password" id="new_login_password">
            <button type="button" class="pw-toggle" onclick="(function(b){const i=document.getElementById(b.dataset.t);i.type=i.type==='password'?'text':'password';})(this)" data-t="new_login_password" aria-label="Show password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
          </div>
        </div>
        <div class="c-6"><label class="field-label">Client (optional)</label>
          <select class="select" name="client_id"><option value="0">None</option>
            <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (($editRow ? (int)$editRow['client_id'] : $prefillClientId) === (int)$c['id']) ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="c-12"><label class="field-label">Project (optional)</label>
          <select class="select" name="project_id"><option value="0">None</option>
            <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (($editRow ? (int)$editRow['project_id'] : $prefillProjectId) === (int)$p['id']) ? 'selected' : '' ?>><?= h($p['name']) ?> — <?= h($p['client_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="c-12"><label class="field-label">Notes</label><textarea class="input" name="notes" rows="3" placeholder="2FA notes / owner contact etc."><?= h((string)($editRow['notes'] ?? '')) ?></textarea></div>
      </div>
      <div class="form-footer">
        <button class="btn btn-primary save-flash" type="submit"><?= $editRow ? 'Update Website Login' : 'Save Website Login' ?></button>
        <?php if ($editRow): ?><a class="btn btn-ghost" href="website_logins.php">Cancel Edit</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if ($canManage): ?>
<form method="post" onsubmit="return confirm('Delete selected logins permanently?');" id="bulkForm">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="action" value="bulk_delete">
<?php endif; ?>

<div class="table-wrap">
  <div class="table-card">
    <table class="logins">
      <thead>
        <tr>
          <?php if ($canManage): ?><th style="width:44px;text-align:center;"><input type="checkbox" id="bulk_select_all" aria-label="Select all"></th><?php endif; ?>
          <th>Website</th><th>Client / Project</th><th>Username</th><th>Password</th><th>Production URL</th><th>Notes</th><th style="width:140px;text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="<?= $canManage ? 8 : 7 ?>" class="empty-state">No website logins yet.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <?php if ($canManage): ?><td style="text-align:center;"><input type="checkbox" class="bulk-row" name="ids[]" value="<?= (int)$r['id'] ?>" form="bulkForm" aria-label="Select row"></td><?php endif; ?>
          <td><div class="site-name"><?= h($r['site_name']) ?></div><div class="site-sub"><?= h($r['website_url'] ?: '—') ?></div></td>
          <td><div><?= h($r['client_name'] ?: '—') ?></div><div class="site-sub"><?= h($r['project_name'] ?: '—') ?></div></td>
          <td><?= h($r['login_username'] ?: '—') ?></td>
          <td class="pw-cell"><span class="pw-mask">••••••••</span><span class="pw-real" style="display:none;"><?= h($r['login_password'] ?: '') ?></span> <button type="button" class="btn-tiny reveal-btn">Reveal</button></td>
          <td><?php if (!empty($r['login_url'])): ?><a href="<?= h($r['login_url']) ?>" target="_blank" rel="noopener" style="color:var(--accent);">Open</a><?php else: ?>—<?php endif; ?></td>
          <td style="font-size:12px;color:var(--text-muted);"><?= nl2br(h($r['notes'] ?: '—')) ?></td>
          <td style="text-align:right;">
            <a class="btn-tiny" href="website_logins.php?edit_id=<?= (int)$r['id'] ?>">Edit</a>
            <?php if ($canManage): ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Delete this login permanently?');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn-tiny danger">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
  <div class="bulk-bar">
    <button class="btn btn-danger" type="submit">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"></path></svg>
      Delete Selected
    </button>
  </div>
</form>
<?php endif; ?>

<script>
  document.querySelectorAll('.reveal-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const td = btn.closest('td');
      const mask = td.querySelector('.pw-mask');
      const real = td.querySelector('.pw-real');
      const show = real.style.display === 'none';
      real.style.display = show ? '' : 'none';
      mask.style.display = show ? 'none' : '';
      btn.textContent = show ? 'Hide' : 'Reveal';
    });
  });
  const all = document.getElementById('bulk_select_all');
  if (all) all.addEventListener('change', () => document.querySelectorAll('.bulk-row').forEach(cb => cb.checked = all.checked));
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
