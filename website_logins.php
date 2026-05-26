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
$projectsStmt = $pdo->prepare('SELECT p.id,p.name,c.name AS client_name,c.id AS client_id FROM projects p JOIN clients c ON c.id=p.client_id WHERE p.workspace_id=? ORDER BY p.name');
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

$totalLogins = (int)$pdo->query("SELECT COUNT(*) FROM website_logins WHERE workspace_id={$ws}")->fetchColumn();
$totalClientsWithLogins = (int)$pdo->query("SELECT COUNT(DISTINCT client_id) FROM website_logins WHERE workspace_id={$ws} AND client_id IS NOT NULL")->fetchColumn();

function wl_gradient(string $seed): string {
  $palette = [
    'linear-gradient(135deg,#fb923c,#ea580c)','linear-gradient(135deg,#f43f5e,#e11d48)',
    'linear-gradient(135deg,#ec4899,#be185d)','linear-gradient(135deg,#64748b,#334155)',
    'linear-gradient(135deg,#94a3b8,#475569)','linear-gradient(135deg,#78716c,#44403c)',
    'linear-gradient(135deg,#3b82f6,#1d4ed8)','linear-gradient(135deg,#10b981,#059669)',
    'linear-gradient(135deg,#22c55e,#16a34a)','linear-gradient(135deg,#a855f7,#7c3aed)',
    'linear-gradient(135deg,#0ea5e9,#0369a1)','linear-gradient(135deg,#f59e0b,#d97706)',
  ];
  return $palette[crc32($seed) % count($palette)];
}
function wl_host(?string $url): string {
  if (!$url) return '';
  $h = parse_url($url, PHP_URL_HOST);
  return $h ?: trim($url, '/');
}

$pageTitle = 'Website Logins';
$activeKey = 'logins';
$pageHeadExtra = <<<HTML
<style>
  .page-header { padding: 28px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; max-width: 640px; }
  .page-stats { display: flex; gap: 24px; font-variant-numeric: tabular-nums; }
  .stat { display: flex; flex-direction: column; gap: 2px; text-align: right; }
  .stat-num { font-size: 20px; font-weight: 600; color: var(--text); letter-spacing: -0.01em; }
  .stat-label { font-size: 10.5px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; }
  .top-search-wrap { max-width: 1640px; margin: 0 auto; width: 100%; padding: 22px 32px 0; }
  .top-search { position: relative; }
  .top-search svg { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); width: 17px; height: 17px; color: var(--text-dim); }
  .top-search input { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 14px 14px 48px; font-size: 14px; color: var(--text); outline: none; transition: all 0.15s; font-family: inherit; }
  .top-search input::placeholder { color: var(--text-dim); }
  .top-search input:focus { border-color: var(--border-strong); background: var(--surface-2); }
  .body-wrap { max-width: 1640px; margin: 0 auto; width: 100%; padding: 20px 32px 28px; display: flex; flex-direction: column; gap: 18px; }
  .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  .panel-head { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 14px; }
  .panel-title { font-size: 13px; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 8px; }
  .panel-title .ico { width: 22px; height: 22px; background: var(--accent-soft); color: var(--accent); border: 1px solid rgba(250,204,21,0.2); border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; }
  .panel-title .ico svg { width: 12px; height: 12px; }
  .panel-collapse { color: var(--text-dim); width: 28px; height: 28px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.12s; background: none; border: 0; cursor: pointer; }
  .panel-collapse:hover { background: var(--surface-2); color: var(--text); }
  .panel-collapse svg { width: 14px; height: 14px; transition: transform 0.2s; }
  .panel.collapsed .panel-collapse svg { transform: rotate(180deg); }
  .panel.collapsed .form-body, .panel.collapsed .form-foot { display: none; }
  .form-body { padding: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 16px 24px; }
  @media (max-width: 760px) { .form-body { grid-template-columns: 1fr; } }
  .field { display: flex; flex-direction: column; gap: 6px; }
  .field.full { grid-column: 1 / -1; }
  .field-label { font-size: 11.5px; color: var(--text-muted); font-weight: 500; display: flex; align-items: center; gap: 6px; }
  .field-label .opt { font-size: 9.5px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.08em; font-weight: 600; }
  .field-label .req { color: var(--accent); }
  .field-label svg { width: 12px; height: 12px; color: var(--text-dim); }
  .field .input, .field .select, .field textarea.input { height: 42px; font-size: 13.5px; padding: 0 14px; background: var(--surface-2); border-radius: 9px; border: 1px solid var(--border); color: var(--text); outline: none; transition: all 0.15s; font-family: inherit; }
  .field textarea.input { height: auto; padding: 12px 14px; resize: vertical; min-height: 84px; }
  .field .select { padding-right: 36px; }
  .field .input:focus, .field .select:focus, .field textarea.input:focus { border-color: var(--accent); background: var(--bg); }
  .pwd-wrap { position: relative; }
  .pwd-wrap .input { padding-right: 44px; }
  .pwd-wrap .toggle-eye { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); width: 28px; height: 28px; border-radius: 6px; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; transition: all 0.12s; cursor: pointer; background: none; border: 0; }
  .pwd-wrap .toggle-eye svg { width: 14px; height: 14px; }
  .pwd-wrap .toggle-eye:hover { background: var(--bg); color: var(--text); }
  .form-foot { padding: 14px 20px; border-top: 1px solid var(--border); background: var(--bg); display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
  .save-hint { font-size: 11.5px; color: var(--text-dim); display: inline-flex; align-items: center; gap: 6px; }
  .save-hint svg { width: 13px; height: 13px; }
  .form-foot-actions { display: flex; gap: 8px; }
  .table-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--border); flex-wrap: wrap; gap: 10px; }
  .filter-chips { display: flex; gap: 4px; flex-wrap: wrap; }
  .filter-chips button { padding: 6px 12px; border-radius: 7px; font-size: 12px; color: var(--text-muted); background: transparent; border: 1px solid transparent; transition: all 0.12s; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
  .filter-chips button:hover { color: var(--text); }
  .filter-chips button.on { background: var(--surface-2); border-color: var(--border); color: var(--text); }
  .filter-chips .pill-count { font-size: 10.5px; padding: 1px 6px; background: var(--bg); border: 1px solid var(--border); border-radius: 999px; color: var(--text-muted); font-variant-numeric: tabular-nums; }
  .bulk-strip { padding: 12px 20px; border-bottom: 1px solid var(--border); background: rgba(250,204,21,0.04); display: none; align-items: center; gap: 12px; }
  .bulk-strip.show { display: flex; }
  .bulk-strip .count { background: var(--accent); color: #1a1400; font-weight: 600; padding: 2px 8px; border-radius: 999px; font-size: 11.5px; }
  .bulk-strip .msg { font-size: 13px; color: var(--text); font-weight: 500; }
  .bulk-strip .actions { margin-left: auto; display: flex; gap: 8px; }
  table.logins { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.logins thead th { text-align: left; padding: 12px 16px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-dim); background: var(--bg); border-bottom: 1px solid var(--border); white-space: nowrap; }
  table.logins thead th.right { text-align: right; }
  table.logins tbody tr { transition: background 0.12s; }
  table.logins tbody tr:hover { background: var(--surface-hover); }
  table.logins tbody tr.selected { background: rgba(250,204,21,0.04); }
  table.logins tbody td { padding: 12px 16px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
  table.logins tbody tr:last-child td { border-bottom: none; }
  .row-check { width: 16px; height: 16px; border-radius: 4px; border: 1.5px solid var(--border-strong); background: var(--bg); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.12s; }
  .row-check svg { width: 11px; height: 11px; color: #1a1400; opacity: 0; transition: opacity 0.12s; }
  .row-check.on { background: var(--accent); border-color: var(--accent); }
  .row-check.on svg { opacity: 1; }
  .site-cell { display: flex; align-items: center; gap: 12px; min-width: 0; }
  .site-favicon { width: 32px; height: 32px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 11px; font-weight: 700; color: #fff; letter-spacing: 0.02em; }
  .site-name { font-weight: 600; font-size: 13.5px; color: var(--text); }
  .site-url { font-size: 11.5px; color: var(--text-dim); margin-top: 2px; font-family: 'JetBrains Mono', ui-monospace, monospace; display: flex; align-items: center; gap: 5px; }
  .site-url svg { width: 10px; height: 10px; }
  .cp-cell { display: flex; flex-direction: column; gap: 2px; }
  .cp-client { font-size: 13px; color: var(--text); font-weight: 500; display: flex; align-items: center; gap: 8px; }
  .cp-client .client-mini-logo { width: 18px; height: 18px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 8.5px; font-weight: 600; color: #fff; letter-spacing: 0.02em; flex-shrink: 0; }
  .cp-project { font-size: 11.5px; color: var(--text-dim); padding-left: 26px; }
  .username-cell { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12.5px; color: var(--text); display: flex; align-items: center; gap: 6px; }
  .copy-btn { width: 22px; height: 22px; border-radius: 5px; color: var(--text-dim); display: inline-flex; align-items: center; justify-content: center; transition: all 0.12s; opacity: 0; flex-shrink: 0; background: none; border: 0; cursor: pointer; }
  tr:hover .copy-btn { opacity: 0.7; }
  .copy-btn:hover { opacity: 1 !important; background: var(--surface-2); color: var(--text); }
  .copy-btn svg { width: 11px; height: 11px; }
  .pw-cell { display: flex; align-items: center; gap: 8px; }
  .pw-dots { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 14px; color: var(--text-dim); letter-spacing: 1px; min-width: 100px; }
  .pw-cell.revealed .pw-dots { color: var(--text); font-size: 12.5px; letter-spacing: 0; }
  .reveal-btn { background: transparent; color: var(--text-muted); border: 1px solid var(--border); border-radius: 6px; padding: 4px 10px; font-size: 11px; font-weight: 500; display: inline-flex; align-items: center; gap: 5px; transition: all 0.12s; cursor: pointer; }
  .reveal-btn:hover { color: var(--text); border-color: var(--border-strong); background: var(--surface-2); }
  .reveal-btn svg { width: 10px; height: 10px; }
  .reveal-btn.on { background: var(--accent-soft); color: var(--accent); border-color: rgba(250,204,21,0.3); }
  .open-link { display: inline-flex; align-items: center; gap: 5px; color: var(--accent); font-size: 12px; font-weight: 500; text-decoration: none; transition: all 0.12s; }
  .open-link:hover { text-decoration: underline; }
  .open-link svg { width: 11px; height: 11px; }
  .em-dash { color: var(--text-dim); }
  .notes-cell { font-size: 12px; color: var(--text-muted); max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .notes-cell.empty { color: var(--text-dim); font-style: italic; }
  .row-actions { display: flex; gap: 6px; justify-content: flex-end; }
  .btn-tiny { padding: 5px 11px; font-size: 11.5px; font-weight: 500; border-radius: 6px; transition: all 0.12s; border: 1px solid; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; text-decoration: none; }
  .btn-tiny svg { width: 11px; height: 11px; }
  .btn-tiny.edit { background: transparent; color: var(--text-muted); border-color: var(--border); }
  .btn-tiny.edit:hover { color: var(--text); border-color: var(--border-strong); background: var(--surface-2); }
  .btn-tiny.delete { background: transparent; color: #fca5a5; border-color: rgba(239,68,68,0.25); }
  .btn-tiny.delete:hover { background: var(--danger-soft); border-color: rgba(239,68,68,0.4); }
  .table-foot { padding: 12px 18px; border-top: 1px solid var(--border); background: var(--bg); display: flex; align-items: center; justify-content: space-between; font-size: 12px; color: var(--text-dim); font-variant-numeric: tabular-nums; flex-wrap: wrap; gap: 8px; }
  .table-foot b { color: var(--text-muted); font-weight: 500; }
  .table-foot .sec-note { display: inline-flex; align-items: center; gap: 6px; }
  .table-foot .sec-note svg { width: 12px; height: 12px; color: var(--accent); }
  .empty-state { padding: 48px 20px; text-align: center; color: var(--text-dim); font-size: 13px; }

  /* ===== MOBILE (≤768px) =====
     Screenshot showed the 7/8-column logins table clipped inside the
     panel — only Client/Project + (truncated) Username visible, Website
     / Password / URL / Notes / Actions all hidden. Convert to vertical
     cards. Also tighten the header + filter chips + form for the
     standard 16px page column. */
  @media (max-width: 768px) {
    .page-header {
      flex-direction: column;
      align-items: stretch;
      padding: 16px 16px 0;
      gap: 12px;
    }
    .page-header-left { gap: 12px; }
    .page-title { font-size: clamp(20px, 5.6vw, 26px); line-height: 1.2; }
    .page-sub { font-size: 12px; }

    .top-search-wrap { padding: 14px 16px 0; }
    .top-search input { font-size: 16px; padding: 12px 14px 12px 44px; min-height: 48px; border-radius: 10px; }
    .top-search svg { left: 14px; width: 16px; height: 16px; }

    .body-wrap { padding: 14px 16px 24px; gap: 14px; }

    /* Add / Edit Login panel + form */
    .panel-head { padding: 12px 14px; }
    .panel-title { font-size: 13px; }
    .form-body { padding: 14px; gap: 12px; }
    .field .input, .field .select { font-size: 16px; min-height: 44px; padding: 0 12px; }
    .field textarea.input { font-size: 14px; min-height: 88px; padding: 12px; }
    .pwd-wrap .input { padding-right: 46px; }
    .form-foot { padding: 12px 14px; gap: 10px; }
    .form-foot-actions { width: 100%; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .form-foot-actions .btn { min-height: 44px; justify-content: center; font-size: 13px; width: 100%; }
    .save-hint { width: 100%; order: -1; }

    /* Filter chips: wrap into a tidy row, no horizontal scroll trap */
    .table-toolbar { padding: 12px 14px; }
    .filter-chips { width: 100%; gap: 6px; }
    .filter-chips button { font-size: 12px; padding: 7px 10px; min-height: 36px; }

    /* Bulk strip — stack the count msg and Clear/Delete buttons */
    .bulk-strip { padding: 10px 14px; gap: 8px; flex-wrap: wrap; }
    .bulk-strip .actions { margin-left: 0; width: 100%; }
    .bulk-strip .actions .btn { flex: 1; min-height: 40px; justify-content: center; }

    /* ===== TABLE → VERTICAL CARDS =====
       Hide thead, drop the bulk-select checkbox column on mobile (the
       bulk-delete UX needs a wider screen anyway). Each <tr> becomes a
       stack: site / client-project / username + password / url + notes
       / actions. Long emails and usernames wrap instead of overflowing. */
    table.logins thead { display: none; }
    table.logins, table.logins tbody, table.logins tr { display: block; }
    table.logins tbody tr {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
      grid-template-areas:
        "site    site"
        "client  client"
        "user    pw"
        "url     notes"
        "actions actions";
      gap: 10px 12px;
      padding: 14px;
      border-bottom: 1px solid var(--border);
      align-items: start;
    }
    table.logins tbody tr:last-child { border-bottom: none; }
    table.logins tbody td {
      display: block;
      padding: 0;
      border: none;
      min-width: 0;
      max-width: 100%;
    }

    /* Hide the bulk-select checkbox td (it's always the td whose direct
       child is the .row-check label) */
    table.logins tbody td:has(> .row-check) { display: none; }

    /* Manager rows have 8 tds (with checkbox in slot 1); non-manager rows
       have 7. Map each role by nth-of-type so the layout works for both. */
    table.logins tbody tr:has(.row-check) td:nth-child(2) { grid-area: site; }
    table.logins tbody tr:has(.row-check) td:nth-child(3) { grid-area: client; }
    table.logins tbody tr:has(.row-check) td:nth-child(4) { grid-area: user; }
    table.logins tbody tr:has(.row-check) td:nth-child(5) { grid-area: pw; }
    table.logins tbody tr:has(.row-check) td:nth-child(6) { grid-area: url; }
    table.logins tbody tr:has(.row-check) td:nth-child(7) { grid-area: notes; }
    table.logins tbody tr:has(.row-check) td:nth-child(8) { grid-area: actions; }

    table.logins tbody tr:not(:has(.row-check)) td:nth-child(1) { grid-area: site; }
    table.logins tbody tr:not(:has(.row-check)) td:nth-child(2) { grid-area: client; }
    table.logins tbody tr:not(:has(.row-check)) td:nth-child(3) { grid-area: user; }
    table.logins tbody tr:not(:has(.row-check)) td:nth-child(4) { grid-area: pw; }
    table.logins tbody tr:not(:has(.row-check)) td:nth-child(5) { grid-area: url; }
    table.logins tbody tr:not(:has(.row-check)) td:nth-child(6) { grid-area: notes; }
    table.logins tbody tr:not(:has(.row-check)) td:nth-child(7) { grid-area: actions; }

    /* Cell contents — let long strings wrap, surface the labels that
       were in the thead. */
    .site-cell { gap: 10px; }
    .site-favicon { width: 36px; height: 36px; font-size: 12px; }
    .site-name { font-size: 14.5px; word-break: break-word; }
    .site-url { font-size: 11px; word-break: break-all; }

    .cp-cell { gap: 4px; }
    .cp-client { font-size: 13px; }
    .cp-project { font-size: 11.5px; padding-left: 26px; }

    .username-cell {
      flex-wrap: wrap;
      font-size: 12.5px;
      word-break: break-all;
      min-width: 0;
    }
    .username-cell span[data-copy-text] { min-width: 0; overflow-wrap: anywhere; }
    .copy-btn { opacity: 1 !important; width: 28px; height: 28px; flex-shrink: 0; }
    .copy-btn svg { width: 13px; height: 13px; }

    .pw-cell { flex-wrap: wrap; gap: 6px; min-width: 0; }
    .pw-dots { min-width: 0; font-size: 13px; }
    .reveal-btn { min-height: 32px; padding: 5px 10px; font-size: 11.5px; }

    .open-link { font-size: 12.5px; }
    .notes-cell { max-width: none; white-space: normal; line-height: 1.4; font-size: 12px; }

    .row-actions {
      width: 100%;
      gap: 8px;
      justify-content: stretch;
    }
    .row-actions form { flex: 1; display: flex; }
    .btn-tiny.edit, .btn-tiny.delete {
      flex: 1;
      min-height: 40px;
      justify-content: center;
      padding: 8px 12px;
      font-size: 12.5px;
    }

    .table-foot {
      padding: 12px 14px;
      font-size: 11.5px;
      gap: 6px;
      flex-direction: column;
      align-items: flex-start;
    }
  }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Website Logins</div>
      <div class="page-sub">Encrypted vault of admin / WordPress credentials per project. Reveals are logged.</div>
    </div>
  </div>
  <div class="page-stats">
    <div class="stat"><span class="stat-num"><?= (int)$totalLogins ?></span><span class="stat-label">Logins</span></div>
    <div class="stat"><span class="stat-num"><?= (int)$totalClientsWithLogins ?></span><span class="stat-label">Clients</span></div>
    <div class="stat"><span class="stat-num" style="color:var(--accent);">AES-256</span><span class="stat-label">Encryption</span></div>
  </div>
</div>

<form class="top-search-wrap" method="get" id="topSearchForm">
  <div class="top-search">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
    <input name="q" id="topSearch" value="<?= h($q) ?>" placeholder="Search by website name, URL, project, or client…">
  </div>
</form>

<div class="body-wrap">

  <!-- Add / Edit Login form -->
  <div class="panel" id="addPanel">
    <div class="panel-head">
      <div class="panel-title">
        <span class="ico">
          <?php if ($editRow): ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
          <?php else: ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          <?php endif; ?>
        </span>
        <?= $editRow ? 'Edit Website Login' : 'Add Website Login' ?>
      </div>
      <button type="button" class="panel-collapse" onclick="document.getElementById('addPanel').classList.toggle('collapsed')" title="Collapse">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"></polyline></svg>
      </button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
      <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
      <div class="form-body">
        <div class="field">
          <label class="field-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>Website name <span class="req">*</span></label>
          <input class="input" name="site_name" value="<?= h((string)($editRow['site_name'] ?? '')) ?>" placeholder="e.g. Rose Law" required>
        </div>
        <div class="field">
          <label class="field-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"></path></svg>Website URL</label>
          <input class="input" name="website_url" value="<?= h((string)($editRow['website_url'] ?? '')) ?>" placeholder="https://example.com">
        </div>
        <div class="field">
          <label class="field-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"></polygon></svg>Production URL <span class="opt">Optional</span></label>
          <input class="input" name="login_url" value="<?= h((string)($editRow['login_url'] ?? '')) ?>" placeholder="https://example.com/wp-admin">
        </div>
        <div class="field">
          <label class="field-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>Username</label>
          <input class="input" name="login_username" value="<?= h((string)($editRow['login_username'] ?? '')) ?>">
        </div>
        <div class="field">
          <label class="field-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0110 0v4"></path></svg>Password <?= $editRow ? '<span class="opt">leave blank to keep</span>' : '<span class="req">*</span>' ?></label>
          <div class="pwd-wrap">
            <input class="input" type="password" name="login_password" id="pwdInput" <?= $editRow ? '' : 'required' ?>>
            <button type="button" class="toggle-eye" id="toggleEye" title="Show password" onclick="const i=document.getElementById('pwdInput');i.type=i.type==='password'?'text':'password';">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            </button>
          </div>
        </div>
        <div class="field">
          <label class="field-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>Client <span class="opt">Optional</span></label>
          <select class="select" name="client_id">
            <option value="0">None</option>
            <?php foreach ($clients as $c): $sel = ($editRow ? (int)$editRow['client_id'] : $prefillClientId) === (int)$c['id']; ?>
              <option value="<?= (int)$c['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"></path></svg>Project <span class="opt">Optional</span></label>
          <select class="select" name="project_id">
            <option value="0">None</option>
            <?php foreach ($projects as $p): $sel = ($editRow ? (int)$editRow['project_id'] : $prefillProjectId) === (int)$p['id']; ?>
              <option value="<?= (int)$p['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= h($p['name']) ?> — <?= h($p['client_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field full">
          <label class="field-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>Notes</label>
          <textarea class="input" name="notes" placeholder="2FA notes / owner contact / recovery email…"><?= h((string)($editRow['notes'] ?? '')) ?></textarea>
        </div>
      </div>
      <div class="form-foot">
        <span class="save-hint">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0110 0v4"></path></svg>
          Encrypted at rest. Reveals are logged with your name and timestamp.
        </span>
        <div class="form-foot-actions">
          <?php if ($editRow): ?><a class="btn btn-ghost" href="website_logins.php">Cancel</a><?php endif; ?>
          <button class="btn btn-primary save-flash" type="submit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline></svg>
            <?= $editRow ? 'Update Website Login' : 'Save Website Login' ?>
          </button>
        </div>
      </div>
    </form>
  </div>

  <!-- Logins list -->
  <?php if ($canManage): ?>
  <form method="post" onsubmit="return confirm('Delete selected logins permanently?');" id="bulkForm">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="bulk_delete">
  <?php endif; ?>
  <div class="panel">
    <div class="panel-head">
      <div class="panel-title">
        <span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0110 0v4"></path></svg></span>
        Saved Logins
      </div>
    </div>
    <div class="table-toolbar">
      <div class="filter-chips" id="filterChips">
        <button type="button" class="on" data-filter="all">All <span class="pill-count"><?= count($rows) ?></span></button>
        <button type="button" data-filter="missing">Missing notes <span class="pill-count"><?= count(array_filter($rows, fn($r) => empty($r['notes']))) ?></span></button>
        <button type="button" data-filter="recent">Recently updated <span class="pill-count"><?= count(array_filter($rows, fn($r) => !empty($r['updated_at']) && strtotime((string)$r['updated_at']) > time() - 604800)) ?></span></button>
      </div>
    </div>

    <?php if ($canManage): ?>
    <div class="bulk-strip" id="bulkStrip">
      <span class="count" id="selCount">0</span>
      <span class="msg" id="selMsg">0 logins selected</span>
      <div class="actions">
        <button type="button" class="btn btn-ghost" id="clearSel">Clear</button>
        <button class="btn btn-danger" type="submit">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"></path></svg>
          Delete
        </button>
      </div>
    </div>
    <?php endif; ?>

    <table class="logins">
      <thead>
        <tr>
          <?php if ($canManage): ?><th style="width:44px;"><span class="row-check" id="checkAll"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></span></th><?php endif; ?>
          <th>Website</th>
          <th>Client / Project</th>
          <th>Username</th>
          <th>Password</th>
          <th>Production URL</th>
          <th>Notes</th>
          <th class="right" style="width:160px;">Actions</th>
        </tr>
      </thead>
      <tbody id="rows">
      <?php if (!$rows): ?>
        <tr><td colspan="<?= $canManage ? 8 : 7 ?>" class="empty-state">No website logins yet.</td></tr>
      <?php else: foreach ($rows as $r):
        $grad = wl_gradient((string)$r['site_name']);
        $host = wl_host((string)($r['website_url'] ?? ''));
        $clientGrad = wl_gradient((string)($r['client_name'] ?? ''));
        $isMissingNotes = empty($r['notes']) ? 'true' : 'false';
        $isRecent = !empty($r['updated_at']) && strtotime((string)$r['updated_at']) > time() - 604800 ? 'true' : 'false';
      ?>
        <tr data-missing="<?= $isMissingNotes ?>" data-recent="<?= $isRecent ?>">
          <?php if ($canManage): ?>
            <td><label class="row-check" data-row-check><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" form="bulkForm" style="display:none;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></label></td>
          <?php endif; ?>
          <td>
            <div class="site-cell">
              <div class="site-favicon" style="background:<?= h($grad) ?>"><?= h(user_initials((string)$r['site_name'])) ?></div>
              <div>
                <div class="site-name"><?= h($r['site_name']) ?></div>
                <?php if ($host): ?>
                  <div class="site-url"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"></path></svg><?= h($host) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td>
            <div class="cp-cell">
              <?php if (!empty($r['client_name'])): ?>
                <div class="cp-client"><span class="client-mini-logo" style="background:<?= h($clientGrad) ?>"><?= h(user_initials((string)$r['client_name'])) ?></span><?= h($r['client_name']) ?></div>
              <?php else: ?>
                <div class="cp-client" style="color:var(--text-dim);font-style:italic;">No client</div>
              <?php endif; ?>
              <?php if (!empty($r['project_name'])): ?>
                <div class="cp-project"><?= h($r['project_name']) ?></div>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <?php if (!empty($r['login_username'])): ?>
              <div class="username-cell"><span data-copy-text="<?= h($r['login_username']) ?>"><?= h($r['login_username']) ?></span>
                <button type="button" class="copy-btn" title="Copy" data-copy="<?= h($r['login_username']) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"></path></svg></button>
              </div>
            <?php else: ?><span class="em-dash">—</span><?php endif; ?>
          </td>
          <td>
            <?php if (!empty($r['login_password'])): ?>
              <div class="pw-cell">
                <span class="pw-dots" data-real="<?= h($r['login_password']) ?>">••••••••</span>
                <button type="button" class="reveal-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>Reveal</button>
              </div>
            <?php else: ?><span class="em-dash">—</span><?php endif; ?>
          </td>
          <td>
            <?php if (!empty($r['login_url'])): ?>
              <a class="open-link" href="<?= h($r['login_url']) ?>" target="_blank" rel="noopener">Open <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg></a>
            <?php else: ?><span class="em-dash">—</span><?php endif; ?>
          </td>
          <td>
            <?php if (!empty($r['notes'])): ?>
              <span class="notes-cell" title="<?= h($r['notes']) ?>"><?= h($r['notes']) ?></span>
            <?php else: ?><span class="notes-cell empty">—</span><?php endif; ?>
          </td>
          <td>
            <div class="row-actions">
              <a class="btn-tiny edit" href="website_logins.php?edit_id=<?= (int)$r['id'] ?>#addPanel"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>Edit</a>
              <?php if ($canManage): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this login permanently?');">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn-tiny delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"></path></svg>Delete</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <div class="table-foot">
      <span>Showing <b><?= count($rows) ?></b> of <b><?= (int)$totalLogins ?></b> logins</span>
      <span class="sec-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0110 0v4"></path></svg>All credentials encrypted · reveal events are logged</span>
    </div>
  </div>
  <?php if ($canManage): ?></form><?php endif; ?>

</div>

<script>
  // Auto-submit search on enter / live filter
  const topSearchInp = document.getElementById('topSearch');
  topSearchInp.addEventListener('input', () => {
    const q = topSearchInp.value.trim().toLowerCase();
    document.querySelectorAll('#rows tr').forEach(tr => {
      tr.style.display = (!q || tr.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
  });

  // Filter chips
  document.querySelectorAll('#filterChips button').forEach(b => {
    b.addEventListener('click', () => {
      document.querySelectorAll('#filterChips button').forEach(x => x.classList.remove('on'));
      b.classList.add('on');
      const f = b.dataset.filter;
      document.querySelectorAll('#rows tr').forEach(tr => {
        if (f === 'all') { tr.style.display = ''; return; }
        if (f === 'missing') { tr.style.display = tr.dataset.missing === 'true' ? '' : 'none'; return; }
        if (f === 'recent') { tr.style.display = tr.dataset.recent === 'true' ? '' : 'none'; return; }
        tr.style.display = '';
      });
    });
  });

  // Password reveal
  document.querySelectorAll('.reveal-btn').forEach(b => {
    const original = b.innerHTML;
    b.addEventListener('click', () => {
      const cell = b.closest('.pw-cell');
      const dots = cell.querySelector('.pw-dots');
      const real = dots.dataset.real || '';
      if (b.classList.contains('on')) {
        b.classList.remove('on');
        cell.classList.remove('revealed');
        dots.textContent = '••••••••';
        b.innerHTML = original;
      } else {
        b.classList.add('on');
        cell.classList.add('revealed');
        dots.textContent = real;
        b.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>Hide';
        setTimeout(() => { if (b.classList.contains('on')) b.click(); }, 12000);
      }
    });
  });

  // Copy buttons
  document.querySelectorAll('.copy-btn').forEach(b => {
    b.addEventListener('click', () => {
      const text = b.dataset.copy || '';
      navigator.clipboard.writeText(text).catch(() => {});
      const original = b.innerHTML;
      b.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
      b.style.color = 'var(--accent)';
      setTimeout(() => { b.innerHTML = original; b.style.color = ''; }, 1000);
    });
  });

  // Bulk select
  <?php if ($canManage): ?>
  const bulkStrip = document.getElementById('bulkStrip');
  function refreshSel() {
    const sel = document.querySelectorAll('[data-row-check] input:checked').length;
    bulkStrip.classList.toggle('show', sel > 0);
    document.getElementById('selCount').textContent = sel;
    document.getElementById('selMsg').textContent = sel + ' login' + (sel === 1 ? '' : 's') + ' selected';
    const all = document.getElementById('checkAll');
    const total = document.querySelectorAll('[data-row-check]').length;
    all.classList.toggle('on', sel === total && total > 0);
  }
  document.querySelectorAll('[data-row-check]').forEach(label => {
    const cb = label.querySelector('input[type=checkbox]');
    label.addEventListener('click', () => setTimeout(() => {
      label.classList.toggle('on', cb.checked);
      label.closest('tr').classList.toggle('selected', cb.checked);
      refreshSel();
    }, 0));
  });
  document.getElementById('checkAll').addEventListener('click', () => {
    const all = document.getElementById('checkAll');
    const turnOn = !all.classList.contains('on');
    document.querySelectorAll('[data-row-check]').forEach(label => {
      const cb = label.querySelector('input[type=checkbox]');
      cb.checked = turnOn;
      label.classList.toggle('on', turnOn);
      label.closest('tr').classList.toggle('selected', turnOn);
    });
    refreshSel();
  });
  document.getElementById('clearSel').addEventListener('click', () => {
    document.querySelectorAll('[data-row-check] input:checked').forEach(cb => {
      cb.checked = false;
      cb.closest('label').classList.remove('on');
      cb.closest('tr').classList.remove('selected');
    });
    refreshSel();
  });
  <?php endif; ?>
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
