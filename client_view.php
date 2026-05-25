<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/finance.php';
auth_require_login();

$pdo = db();
finance_ensure_schema($pdo);
$ws = auth_workspace_id();
$user = auth_user();
$role = $user['role_name'] ?? '';
$can_manage = in_array($role, ['CEO', 'Manager', 'Super Admin'], true);

$id = (int)($_GET['id'] ?? 0);

$clientStmt = $pdo->prepare('SELECT * FROM clients WHERE id = ? AND workspace_id = ?');
$clientStmt->execute([$id, $ws]);
$client = $clientStmt->fetch();

if (!$client) {
  $pageTitle = 'Client not found';
  $activeKey = 'clients';
  require_once __DIR__ . '/layout.php';
  echo '<div style="padding:48px 32px;text-align:center;color:var(--text-muted);"><h3 style="margin-bottom:12px;color:var(--text);">Client not found</h3><div style="margin-top:18px;">' . back_button_html('clients.php', 'Back to Clients') . '</div></div>';
  require_once __DIR__ . '/layout_end.php';
  exit;
}

function cv_avatar_gradient(string $seed): string {
  $palette = [
    'linear-gradient(135deg,#10b981,#059669)','linear-gradient(135deg,#f43f5e,#e11d48)',
    'linear-gradient(135deg,#0ea5e9,#0369a1)','linear-gradient(135deg,#a855f7,#7c3aed)',
    'linear-gradient(135deg,#fb923c,#ea580c)','linear-gradient(135deg,#ec4899,#be185d)',
    'linear-gradient(135deg,#fb7185,#be123c)','linear-gradient(135deg,#94a3b8,#475569)',
    'linear-gradient(135deg,#facc15,#ca8a04)','linear-gradient(135deg,#f59e0b,#d97706)',
  ];
  return $palette[crc32($seed) % count($palette)];
}
function cv_project_status_class(string $status): string {
  $s = strtolower($status);
  if (strpos($s, 'hold') !== false || strpos($s, 'pause') !== false) return 'hold';
  if (strpos($s, 'complete') !== false || strpos($s, 'done') !== false || strpos($s, 'closed') !== false) return 'done';
  if (strpos($s, 'archive') !== false) return 'archived';
  return 'active';
}
function cv_task_status_class(string $status): string {
  $s = strtolower($status);
  if (strpos($s, 'progress') !== false) return 'progress';
  if (strpos($s, 'done') !== false || strpos($s, 'complete') !== false || strpos($s, 'approved') !== false || strpos($s, 'submit') !== false) return 'done';
  if (strpos($s, 'review') !== false) return 'review';
  return 'todo';
}
function cv_is_valid_url(string $value): bool {
  if ($value === '') return true;
  if (!filter_var($value, FILTER_VALIDATE_URL)) return false;
  $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
  return in_array($scheme, ['http', 'https'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_client'])) {
  require_post(); csrf_verify();
  if (!$can_manage) { flash_set('error', 'No permission.'); redirect("client_view.php?id=$id"); }
  $newName = trim((string)($_POST['client_name'] ?? ''));
  $newNotes = trim((string)($_POST['client_notes'] ?? ''));
  $billingModel = trim((string)($_POST['billing_model'] ?? ''));
  $billingCycle = trim((string)($_POST['billing_cycle'] ?? ''));
  $retainerAmount = ($_POST['retainer_amount'] ?? '') !== '' ? (float)$_POST['retainer_amount'] : null;
  $hourlyRate = ($_POST['hourly_rate'] ?? '') !== '' ? (float)$_POST['hourly_rate'] : null;
  if ($newName === '') { flash_set('error', 'Client name is required.'); redirect("client_view.php?id=$id"); }
  if (!in_array($billingModel, ['monthly_retainer', 'hourly', 'fixed_project', 'hybrid'], true)) {
    flash_set('error', 'Billing model is required.'); redirect("client_view.php?id=$id");
  }
  $pdo->prepare('UPDATE clients SET name=?, notes=?, billing_model=?, billing_cycle=?, retainer_amount=?, hourly_rate=?, updated_at=? WHERE id=? AND workspace_id=?')
      ->execute([$newName, $newNotes ?: null, $billingModel, $billingCycle ?: null, $retainerAmount, $hourlyRate, now(), $id, $ws]);
  if ((string)($_POST['remove_client_logo'] ?? '') === '1') {
    foreach (['png','jpg','jpeg','webp','gif','svg'] as $ext) {
      $f = __DIR__ . '/uploads/client_logos/' . $id . '.' . $ext;
      if (is_file($f)) @unlink($f);
    }
  }
  if (isset($_FILES['client_logo']) && (int)($_FILES['client_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $tmp = (string)($_FILES['client_logo']['tmp_name'] ?? '');
    $size = (int)($_FILES['client_logo']['size'] ?? 0);
    $info = @getimagesize($tmp);
    $mime = strtolower((string)($info['mime'] ?? ''));
    $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'];
    if ($size > 0 && $size <= 2*1024*1024 && isset($allowed[$mime])) {
      $dir = __DIR__ . '/uploads/client_logos';
      if (!is_dir($dir)) @mkdir($dir, 0775, true);
      foreach (['png','jpg','jpeg','webp','gif','svg'] as $ext) {
        $f = $dir . '/' . $id . '.' . $ext;
        if (is_file($f)) @unlink($f);
      }
      @move_uploaded_file($tmp, $dir . '/' . $id . '.' . $allowed[$mime]);
    }
  }
  flash_set('success', 'Client updated successfully.'); redirect("client_view.php?id=$id");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
  require_post(); csrf_verify();
  if (!$can_manage) { flash_set('error', 'No permission.'); redirect("client_view.php?id=$id"); }
  $name = trim($_POST['name'] ?? '');
  $type_id = (int)($_POST['type_id'] ?? 0);
  $status_id = (int)($_POST['status_id'] ?? 0);
  $dueDate = $_POST['due_date'] ?: null;
  $pricingModel = trim((string)($_POST['pricing_model'] ?? 'fixed_price'));
  $projectPrice = ($_POST['project_price'] ?? '') !== '' ? (float)$_POST['project_price'] : null;
  $paymentTerms = trim((string)($_POST['payment_terms'] ?? ''));
  $projectHourlyRate = ($_POST['project_hourly_rate'] ?? '') !== '' ? (float)$_POST['project_hourly_rate'] : null;
  $wlNotes = trim((string)($_POST['wl_notes'] ?? ''));

  // Normalize repeater rows: trim, drop fully-empty rows, validate URLs.
  $rawLogins = is_array($_POST['logins'] ?? null) ? $_POST['logins'] : [];
  $logins = [];
  foreach ($rawLogins as $row) {
    if (!is_array($row)) continue;
    $entry = [
      'type'      => trim((string)($row['type'] ?? '')),
      'name'      => trim((string)($row['name'] ?? '')),
      'url'       => trim((string)($row['url'] ?? '')),
      'login_url' => trim((string)($row['login_url'] ?? '')),
      'username'  => trim((string)($row['username'] ?? '')),
      'password'  => (string)($row['password'] ?? ''),
    ];
    if ($entry['type'] === '' && $entry['name'] === '' && $entry['url'] === '' && $entry['login_url'] === '' && $entry['username'] === '' && $entry['password'] === '') continue;
    $logins[] = $entry;
  }

  if ($name === '') { flash_set('error', 'Project name required.'); redirect("client_view.php?id=$id"); }
  if (!in_array($pricingModel, ['fixed_price', 'hourly'], true)) { flash_set('error', 'Project pricing model is required.'); redirect("client_view.php?id=$id"); }
  foreach ($logins as $entry) {
    if (!cv_is_valid_url($entry['url']) || !cv_is_valid_url($entry['login_url'])) {
      flash_set('error', 'Please enter valid website URLs (http/https).'); redirect("client_view.php?id=$id");
    }
  }

  $websiteDetails = [
    'logins' => array_map(fn($e) => [
      'type'     => $e['type'] !== '' ? $e['type'] : null,
      'name'     => $e['name'] !== '' ? $e['name'] : null,
      'url'      => $e['url'] !== '' ? $e['url'] : null,
      'loginUrl' => $e['login_url'] !== '' ? $e['login_url'] : null,
      'username' => $e['username'] !== '' ? $e['username'] : null,
      'password' => $e['password'] !== '' ? $e['password'] : null,
    ], $logins),
  ];
  $firstSiteUrl = null;
  foreach ($logins as $e) { if ($e['url'] !== '') { $firstSiteUrl = $e['url']; break; } }

  $pdo->prepare('INSERT INTO projects (workspace_id,client_id,name,type_id,status_id,due_date,pricing_model,project_price,payment_terms,hourly_rate,live_website_url,notes,website_details_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$ws, $id, $name, $type_id, $status_id, $dueDate, $pricingModel, $projectPrice, $paymentTerms ?: null, $projectHourlyRate, $firstSiteUrl, null, json_encode($websiteDetails, JSON_UNESCAPED_SLASHES), now(), now()]);
  $newProjectId = (int)$pdo->lastInsertId();

  foreach ($logins as $idx => $entry) {
    $siteName = $entry['name'] !== ''
      ? $entry['name']
      : ($entry['type'] !== '' ? $entry['type'] : $name . ' (Login #' . ($idx + 1) . ')');
    $rowNotes = trim(($entry['type'] !== '' ? 'Type: ' . $entry['type'] : '') . ($wlNotes !== '' ? ($entry['type'] !== '' ? "\n" : '') . $wlNotes : ''));
    $pdo->prepare('INSERT INTO website_logins (workspace_id,client_id,project_id,site_name,website_url,login_url,login_username,login_password,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$ws, $id, $newProjectId, $siteName, $entry['url'] ?: null, $entry['login_url'] ?: null, $entry['username'] ?: null, $entry['password'] !== '' ? $entry['password'] : null, $rowNotes ?: null, (int)($user['id'] ?? 0), now(), now()]);
  }
  flash_set('success', 'Project created.'); redirect("client_view.php?id=$id");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project'])) {
  require_post(); csrf_verify();
  if (!$can_manage) { flash_set('error', 'No permission.'); redirect("client_view.php?id=$id"); }
  $deleteId = (int)($_POST['project_id'] ?? 0);
  if ($deleteId <= 0) { flash_set('error', 'Invalid project selected.'); redirect("client_view.php?id=$id"); }
  $st = $pdo->prepare('DELETE FROM projects WHERE workspace_id = ? AND client_id = ? AND id = ?');
  $st->execute([$ws, $id, $deleteId]);
  flash_set('success', $st->rowCount() ? 'Project deleted permanently.' : 'Project not found.');
  redirect("client_view.php?id=$id");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_doc'])) {
  require_post(); csrf_verify();
  if (!$can_manage) { flash_set('error', 'No permission.'); redirect("client_view.php?id=$id"); }
  $title = trim($_POST['doc_title'] ?? '');
  $content = trim($_POST['doc_content'] ?? '');
  $projectId = (int)($_POST['doc_project_id'] ?? 0);
  $projectGuard = $pdo->prepare('SELECT id FROM projects WHERE id = ? AND client_id = ? AND workspace_id = ?');
  $projectGuard->execute([$projectId, $id, $ws]);
  $validProjectId = (int)($projectGuard->fetchColumn() ?: 0);
  if ($title === '' || $validProjectId <= 0) { flash_set('error', 'Document title and valid project are required.'); redirect("client_view.php?id=$id"); }
  $pdo->prepare('INSERT INTO docs (workspace_id, project_id, title, content, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')
      ->execute([$ws, $validProjectId, $title, $content, (int)($user['id'] ?? 0), now(), now()]);
  flash_set('success', 'Doc created.'); redirect("client_view.php?id=$id");
}

$typeStmt = $pdo->prepare('SELECT id, name FROM project_types WHERE workspace_id = ? ORDER BY sort_order ASC');
$typeStmt->execute([$ws]);
$types = $typeStmt->fetchAll();

$statusStmt = $pdo->prepare('SELECT id, name FROM project_statuses WHERE workspace_id = ? ORDER BY sort_order ASC');
$statusStmt->execute([$ws]);
$statuses = $statusStmt->fetchAll();

$projectsStmt = $pdo->prepare(<<<SQL
SELECT p.*, pt.name AS type_name, ps.name AS status_name,
  COUNT(DISTINCT t.id) AS task_count,
  SUM(CASE WHEN t.status IS NOT NULL AND t.status NOT IN ('Approved (Ready to Submit)', 'Submitted to Client') THEN 1 ELSE 0 END) AS active_task_count,
  COUNT(DISTINCT d.id) AS doc_count,
  GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS assignees,
  MAX(COALESCE(t.updated_at, d.updated_at, p.updated_at, p.created_at)) AS last_activity
FROM projects p
JOIN project_types pt ON pt.id = p.type_id
JOIN project_statuses ps ON ps.id = p.status_id
LEFT JOIN tasks t ON t.project_id = p.id AND t.workspace_id = p.workspace_id
LEFT JOIN task_assignees ta ON ta.task_id = t.id
LEFT JOIN users u ON u.id = ta.user_id
LEFT JOIN docs d ON d.project_id = p.id AND d.workspace_id = p.workspace_id
WHERE p.workspace_id = ? AND p.client_id = ?
GROUP BY p.id, p.workspace_id, p.client_id, p.name, p.type_id, p.status_id, p.due_date, p.notes, p.created_at, p.updated_at, pt.name, ps.name
ORDER BY p.updated_at DESC, p.id DESC
SQL
);
$projectsStmt->execute([$ws, $id]);
$projects = $projectsStmt->fetchAll();

$overviewStmt = $pdo->prepare(<<<SQL
SELECT
  COALESCE(SUM(fp.amount), 0) AS monthly_revenue,
  COALESCE(SUM(fr.expected_amount - fr.received_amount), 0) AS pending_invoices,
  COUNT(DISTINCT p.id) AS total_projects,
  COUNT(DISTINCT CASE WHEN t.status IS NOT NULL AND t.status NOT IN ('Approved (Ready to Submit)', 'Submitted to Client') THEN t.id END) AS active_tasks,
  COUNT(DISTINCT d.id) AS total_docs
FROM clients c
LEFT JOIN finance_payments fp ON fp.client_id = c.id AND fp.workspace_id = c.workspace_id AND DATE_FORMAT(fp.received_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
LEFT JOIN finance_receivables fr ON fr.client_id = c.id AND fr.workspace_id = c.workspace_id AND fr.status IN ('pending', 'partial')
LEFT JOIN projects p ON p.client_id = c.id AND p.workspace_id = c.workspace_id
LEFT JOIN tasks t ON t.project_id = p.id AND t.workspace_id = p.workspace_id
LEFT JOIN docs d ON d.project_id = p.id AND d.workspace_id = p.workspace_id
WHERE c.id = ? AND c.workspace_id = ?
SQL
);
$overviewStmt->execute([$id, $ws]);
$overview = $overviewStmt->fetch() ?: [];

$docsStmt = $pdo->prepare("SELECT d.id, d.title, d.updated_at, d.project_id, p.name AS project_name, u.name AS author_name
  FROM docs d
  JOIN projects p ON p.id = d.project_id AND p.workspace_id = d.workspace_id
  LEFT JOIN users u ON u.id = d.created_by
  WHERE d.workspace_id = ? AND p.client_id = ?
  ORDER BY d.updated_at DESC, d.id DESC LIMIT 50");
$docsStmt->execute([$ws, $id]);
$docs = $docsStmt->fetchAll();

$clientProjectsStmt = $pdo->prepare('SELECT id, name FROM projects WHERE workspace_id = ? AND client_id = ? ORDER BY id DESC');
$clientProjectsStmt->execute([$ws, $id]);
$clientProjects = $clientProjectsStmt->fetchAll();

$logoUrl = client_logo_url((int)$client['id']);
$clientGrad = cv_avatar_gradient((string)$client['name']);

$pageTitle = $client['name'];
$activeKey = 'clients';
$pageHeadExtra = <<<HTML
<style>
  .client-head { padding: 22px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
  .ch-left { min-width: 0; display: flex; align-items: flex-start; gap: 14px; }
  .ch-left .back-wrap { padding-top: 4px; }
  .client-title { font-size: 28px; font-weight: 700; letter-spacing: -0.025em; line-height: 1.1; display: flex; align-items: center; gap: 12px; }
  .client-title .icon-tile { width: 40px; height: 40px; border-radius: 10px; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; overflow: hidden; }
  .client-title .icon-tile img { width: 100%; height: 100%; object-fit: cover; }
  .client-meta { display: flex; align-items: center; gap: 10px; margin-top: 8px; font-size: 13px; color: var(--text-muted); }
  .client-meta .sep { color: var(--text-dim); }
  .ch-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .section-nav { position: sticky; top: 0; z-index: 30; background: rgba(10,10,10,0.92); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); margin-top: 22px; }
  .section-nav-inner { max-width: 1640px; margin: 0 auto; padding: 0 32px; display: flex; gap: 4px; }
  .section-nav a { padding: 14px 16px; font-size: 13px; font-weight: 500; color: var(--text-muted); border-bottom: 2px solid transparent; margin-bottom: -1px; transition: all 0.15s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
  .section-nav a:hover { color: var(--text); }
  .section-nav a.active { color: var(--text); border-bottom-color: var(--accent); }
  .section-nav a .count { font-size: 10.5px; background: var(--surface-2); color: var(--text-muted); padding: 1px 7px; border-radius: 999px; }
  .section-nav a.active .count { background: var(--accent-soft); color: var(--accent); }
  .body-wrap { max-width: 1640px; margin: 0 auto; width: 100%; padding: 32px 32px 60px; display: flex; flex-direction: column; gap: 36px; }
  .section { scroll-margin-top: 70px; }
  .section-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 14px; }
  .section-title { font-size: 18px; font-weight: 600; color: var(--text); letter-spacing: -0.01em; display: inline-flex; align-items: center; gap: 10px; }
  .section-title::before { content: ''; width: 3px; height: 18px; background: var(--accent); border-radius: 2px; }
  .section-title .count-pill { font-size: 11px; background: var(--surface); color: var(--text-muted); border: 1px solid var(--border); padding: 2px 8px; border-radius: 999px; font-variant-numeric: tabular-nums; font-weight: 500; }
  .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  .overview-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .ov-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 20px; display: flex; flex-direction: column; gap: 14px; }
  .ov-card .lbl { font-size: 10.5px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.12em; font-weight: 600; }
  .metric-row { display: grid; grid-template-columns: 1fr auto; row-gap: 8px; font-size: 13.5px; }
  .metric-row .label { color: var(--text-muted); }
  .metric-row .value { font-weight: 600; color: var(--text); text-align: right; font-variant-numeric: tabular-nums; }
  table.gen { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.gen thead th { text-align: left; padding: 10px 16px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-dim); background: var(--bg); border-bottom: 1px solid var(--border); }
  table.gen tbody tr { transition: background 0.12s; }
  table.gen tbody tr:hover { background: var(--surface-hover); }
  table.gen tbody td { padding: 14px 16px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
  table.gen tbody tr:last-child td { border-bottom: none; }
  .status-pill { display: inline-flex; align-items: center; gap: 7px; padding: 4px 10px 4px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 500; border: 1px solid var(--border); background: var(--surface-2); }
  .status-pill .dot { width: 6px; height: 6px; border-radius: 50%; }
  .status-pill.active { color: #86efac; border-color: rgba(34,197,94,0.25); background: rgba(34,197,94,0.08); }
  .status-pill.active .dot { background: #22c55e; }
  .status-pill.hold { color: #fcd34d; border-color: rgba(245,158,11,0.25); background: rgba(245,158,11,0.08); }
  .status-pill.hold .dot { background: #f59e0b; }
  .status-pill.done { color: #93c5fd; border-color: rgba(59,130,246,0.25); background: rgba(59,130,246,0.08); }
  .status-pill.done .dot { background: #3b82f6; }
  .row-actions { display: flex; gap: 6px; justify-content: flex-end; }
  .btn-tiny { padding: 5px 11px; font-size: 11.5px; font-weight: 500; border-radius: 6px; transition: all 0.12s; border: 1px solid; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; }
  .btn-tiny svg { width: 11px; height: 11px; }
  .btn-tiny.open { background: transparent; color: var(--text); border-color: var(--border); text-decoration: none; }
  .btn-tiny.open:hover { background: var(--accent); color: #1a1400; border-color: var(--accent); }
  .btn-tiny.delete { background: transparent; color: #fca5a5; border-color: rgba(239,68,68,0.25); }
  .btn-tiny.delete:hover { background: var(--danger-soft); border-color: rgba(239,68,68,0.4); }
  .empty-state { padding: 48px 20px; text-align: center; color: var(--text-dim); font-size: 13px; }
  .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 100; display: none; align-items: flex-start; justify-content: center; padding: 60px 20px; overflow-y: auto; }
  .modal-overlay.open { display: flex; }
  .modal-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; width: 100%; max-width: 920px; overflow: hidden; }
  .modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
  .modal-title { font-size: 16px; font-weight: 600; color: var(--text); }
  .modal-body { padding: 20px; max-height: 70vh; overflow-y: auto; }
  .modal-foot { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; gap: 8px; justify-content: flex-end; background: var(--bg); }
  .modal-section { padding: 14px; border-radius: 10px; border: 1px solid var(--border); margin-top: 14px; }
  .modal-section h6 { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 10px; font-weight: 600; }
  .login-repeater { margin-top: 14px; }
  .login-repeater-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
  .login-repeater-head h6 { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.08em; font-weight: 600; margin: 0; }
  .login-row { position: relative; padding: 14px; border-radius: 10px; border: 1px solid var(--border); margin-bottom: 10px; }
  .login-row-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; gap: 8px; }
  .login-row-index { font-size: 10.5px; color: var(--text-dim); font-family: 'JetBrains Mono', ui-monospace, monospace; text-transform: uppercase; letter-spacing: 0.1em; }
  .login-row-remove { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; color: var(--text-muted); padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border); background: transparent; cursor: pointer; transition: all 0.12s; }
  .login-row-remove:hover { color: #fca5a5; border-color: rgba(239,68,68,0.3); background: var(--danger-soft); }
  .login-row-remove svg { width: 11px; height: 11px; }
  .login-row.is-only .login-row-remove { display: none; }
  .login-add-row { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; font-size: 12.5px; font-weight: 500; color: var(--text); border-radius: 8px; border: 1px dashed var(--border-strong); background: transparent; cursor: pointer; transition: all 0.15s; }
  .login-add-row:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-soft); }
  .login-add-row svg { width: 13px; height: 13px; }
  .modal-grid { display: grid; gap: 12px; grid-template-columns: repeat(12, 1fr); }
  .modal-grid > * { display: flex; flex-direction: column; gap: 5px; }
  .c-12 { grid-column: span 12; } .c-8 { grid-column: span 8; } .c-6 { grid-column: span 6; } .c-4 { grid-column: span 4; } .c-3 { grid-column: span 3; }
  @media (max-width: 768px) { .c-8, .c-6, .c-4, .c-3 { grid-column: span 12; } .overview-grid { grid-template-columns: 1fr; } }
  .icon-close { width: 30px; height: 30px; border-radius: 6px; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; transition: all 0.12s; }
  .icon-close:hover { background: var(--surface-2); color: var(--text); }

  /* ===== MOBILE =====
     The client header was a flex row [title-block | actions-block] with
     space-between. On a narrow viewport the 3 action buttons (Website
     Logins / Edit Client / + New Project) wrapped to a column on the
     right and overlapped the wrapping client name on the left — total
     visual chaos as shown in the user screenshot.
     Stack the whole header vertically on mobile: back-button row,
     title row, meta row, then a horizontal scrolling actions row at
     the bottom so all three buttons stay reachable without
     overlapping anything. */
  @media (max-width: 768px) {
    /* Match the 16px page column the rest of the app uses. */
    .client-head {
      padding: 16px 16px 0;
      flex-direction: column;
      align-items: stretch;
      gap: 14px;
    }
    .ch-left {
      flex-direction: column;
      align-items: flex-start;
      gap: 12px;
    }
    .ch-left > div { width: 100%; min-width: 0; }
    .ch-left .back-wrap { padding-top: 0; }
    .client-title {
      font-size: clamp(20px, 5.6vw, 26px);
      line-height: 1.2;
      gap: 10px;
      align-items: center;
      min-width: 0;
      word-break: break-word;
    }
    .client-title .icon-tile { width: 36px; height: 36px; font-size: 12px; }
    .client-meta {
      font-size: 12px;
      gap: 6px;
      flex-wrap: wrap;
      row-gap: 2px;
    }
    /* Actions: stacked 2-row grid instead of a horizontal scroll strip.
       The scroll strip was clipping the primary "+ New Project" CTA on
       the right edge (the "t" was hidden), and a button getting cut is
       a worse UX failure than a slightly taller header block. Layout:
         Row 1: [ Website Logins ] [ Edit Client ]   (secondary, 50/50)
         Row 2: [    + New Project    ]              (primary, full-width)
       If a role only sees 1 or 2 buttons (e.g. non-manager), the auto
       grid columns still fit cleanly. */
    .ch-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      width: 100%;
      flex-wrap: unset;
      overflow: visible;
      margin: 0;
      padding: 0;
    }
    .ch-actions::-webkit-scrollbar { display: none; }
    .ch-actions .btn {
      min-height: 44px;
      padding: 10px 12px;
      font-size: 13px;
      justify-content: center;
      width: 100%;
    }
    /* The primary CTA (the yellow + New Project button) spans both
       columns on its own row. */
    .ch-actions .btn-primary { grid-column: 1 / -1; }

    .section-nav-inner { padding: 0 16px; }
    .section-nav a { padding: 12px 12px; font-size: 12.5px; }

    /* ===== TABLE → CARD LAYOUT on mobile =====
       The Projects and Documents tables on this page both use
       table.gen — 5-6 narrow columns crammed sideways and the user
       had to horizontally scroll to see the rest. Convert each row
       to a vertical card so all the info is visible without scroll.
       Project layout:
         Row 1: [ project name (link) ] [ status pill ]
         Row 2: [ Type · Active count · Last activity ]
         Row 3: [ Open ]   [ Delete ]
       Docs layout (same pattern):
         Row 1: title
         Row 2: project · author
         Row 3: updated date / actions row
    */
    table.gen { font-size: 14px; }
    table.gen thead { display: none; }
    table.gen, table.gen tbody, table.gen tr { display: block; }
    table.gen tbody tr {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 6px 12px;
      padding: 14px 16px;
      border-bottom: 1px solid var(--border);
      align-items: center;
    }
    table.gen tbody tr:last-child { border-bottom: none; }
    table.gen tbody td {
      display: block;
      padding: 0;
      border: none;
      min-width: 0;
      max-width: 100%;
    }
    /* Project table — 6 columns: name | type | status | active | last act | actions */
    table.gen tbody tr td:nth-child(1) { grid-column: 1; grid-row: 1; font-size: 15px; }
    table.gen tbody tr td:nth-child(1) a { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    table.gen tbody tr td:nth-child(2),
    table.gen tbody tr td:nth-child(3),
    table.gen tbody tr td:nth-child(4),
    table.gen tbody tr td:nth-child(5) {
      grid-column: 1 / -1;
      grid-row: auto;
      font-size: 12px;
      color: var(--text-muted);
    }
    /* Status pill keeps its position next to the title on row 1. */
    table.gen tbody tr td:nth-child(3) {
      grid-column: 2;
      grid-row: 1;
      text-align: right;
    }
    /* Type / Active / Last activity collapse to a single meta row. */
    table.gen tbody tr td:nth-child(2),
    table.gen tbody tr td:nth-child(4),
    table.gen tbody tr td:nth-child(5) {
      display: inline-block;
      margin-right: 8px;
    }
    /* Actions row: full width, right-aligned. */
    table.gen tbody tr td:last-child {
      grid-column: 1 / -1;
      grid-row: auto;
      margin-top: 4px;
    }
    .row-actions { justify-content: flex-end; gap: 8px; }
    .btn-tiny { padding: 8px 14px; font-size: 13px; min-height: 36px; }
    .empty-state {
      display: block !important;
      grid-column: 1 / -1 !important;
      grid-row: auto !important;
      padding: 18px 8px !important;
      text-align: center;
    }
  }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="client-head">
  <div class="ch-left">
    <div class="back-wrap"><?= back_button_html('clients.php', 'Clients') ?></div>
    <div>
      <h1 class="client-title">
        <span class="icon-tile" style="background:<?= h($clientGrad) ?>">
          <?php if ($logoUrl): ?><img src="<?= h($logoUrl) ?>" alt="<?= h($client['name']) ?>"><?php else: ?><?= h(user_initials((string)$client['name'])) ?><?php endif; ?>
        </span>
        <?= h($client['name']) ?>
      </h1>
      <div class="client-meta">
        <span>CLI-<?= str_pad((string)(int)$client['id'], 4, '0', STR_PAD_LEFT) ?></span>
        <span class="sep">·</span>
        <span>Added <?= h(format_date($client['created_at'] ?? now())) ?></span>
        <?php if (!empty($client['billing_model'])): ?>
          <span class="sep">·</span>
          <span><?= h(ucwords(str_replace('_', ' ', (string)$client['billing_model']))) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="ch-actions">
    <a class="btn btn-ghost" href="website_logins.php?client_id=<?= (int)$id ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0110 0v4"></path></svg>
      Website Logins
    </a>
    <?php if ($can_manage): ?>
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('editClient').classList.add('open')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
        Edit Client
      </button>
      <button type="button" class="btn btn-primary save-flash" onclick="document.getElementById('addProject').classList.add('open')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        New Project
      </button>
    <?php endif; ?>
  </div>
</div>

<div class="section-nav">
  <div class="section-nav-inner" id="sectionNav">
    <a href="#overview" data-target="overview" class="active">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect></svg>
      Overview
    </a>
    <a href="#projects" data-target="projects">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"></path></svg>
      Projects <span class="count"><?= count($projects) ?></span>
    </a>
    <a href="#docs" data-target="docs">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
      Docs <span class="count"><?= count($docs) ?></span>
    </a>
  </div>
</div>

<div class="body-wrap">
  <section class="section" id="overview">
    <div class="section-head"><h2 class="section-title">Overview</h2></div>
    <div class="overview-grid">
      <div class="ov-card">
        <span class="lbl">Financials (current month)</span>
        <div class="metric-row">
          <div class="label">Monthly Revenue</div><div class="value">$<?= number_format((float)($overview['monthly_revenue'] ?? 0), 2) ?></div>
          <div class="label">Pending Invoices</div><div class="value">$<?= number_format((float)($overview['pending_invoices'] ?? 0), 2) ?></div>
          <div class="label">Total Projects</div><div class="value"><?= (int)($overview['total_projects'] ?? 0) ?></div>
          <div class="label">Active Tasks</div><div class="value"><?= (int)($overview['active_tasks'] ?? 0) ?></div>
          <div class="label">Total Docs</div><div class="value"><?= (int)($overview['total_docs'] ?? 0) ?></div>
        </div>
      </div>
      <div class="ov-card">
        <span class="lbl">Client Notes</span>
        <p style="font-size: 13.5px; color: var(--text); line-height: 1.6;"><?= !empty($client['notes']) ? nl2br(h($client['notes'])) : '<em style="color:var(--text-dim);">No notes for this client yet.</em>' ?></p>
      </div>
    </div>
  </section>

  <section class="section" id="projects">
    <div class="section-head">
      <h2 class="section-title">Projects <span class="count-pill"><?= count($projects) ?></span></h2>
    </div>
    <div class="panel">
      <table class="gen">
        <thead>
          <tr><th>Project</th><th>Type</th><th>Status</th><th>Active Tasks</th><th>Last Activity</th><th style="text-align:right;width:140px;">Actions</th></tr>
        </thead>
        <tbody>
        <?php if (!$projects): ?>
          <tr><td colspan="6" class="empty-state">No projects yet for this client.</td></tr>
        <?php else: foreach ($projects as $p):
          $statusCls = cv_project_status_class((string)$p['status_name']);
        ?>
          <tr>
            <td><a style="font-weight:600;color:var(--text);" href="project_view.php?id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?></a></td>
            <td><?= h($p['type_name']) ?></td>
            <td><span class="status-pill <?= $statusCls ?>"><span class="dot"></span><?= h($p['status_name']) ?></span></td>
            <td><?= (int)$p['active_task_count'] ?> active</td>
            <td style="font-family:'JetBrains Mono',ui-monospace,monospace;font-size:12.5px;"><?= h($p['last_activity'] ? format_date($p['last_activity']) : '—') ?></td>
            <td>
              <div class="row-actions">
                <a class="btn-tiny open" href="project_view.php?id=<?= (int)$p['id'] ?>">Open<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></a>
                <?php if ($can_manage): ?>
                  <form method="post" style="display:inline;" onsubmit="return confirm('Delete project permanently?');">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="delete_project" value="1">
                    <input type="hidden" name="project_id" value="<?= (int)$p['id'] ?>">
                    <button class="btn-tiny delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"></path></svg></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="section" id="docs">
    <div class="section-head">
      <h2 class="section-title">Documents <span class="count-pill"><?= count($docs) ?></span></h2>
      <?php if ($can_manage && $clientProjects): ?>
        <button type="button" class="section-action" onclick="document.getElementById('addDoc').classList.add('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>New Doc</button>
      <?php endif; ?>
    </div>
    <div class="panel">
      <table class="gen">
        <thead><tr><th>Title</th><th>Project</th><th>Author</th><th>Updated</th><th style="text-align:right;width:90px;">Actions</th></tr></thead>
        <tbody>
        <?php if (!$docs): ?>
          <tr><td colspan="5" class="empty-state">No documents yet for this client.</td></tr>
        <?php else: foreach ($docs as $d): ?>
          <tr>
            <td><a style="font-weight:600;color:var(--text);" href="doc_edit.php?id=<?= (int)$d['id'] ?>"><?= h($d['title']) ?></a></td>
            <td><?= h($d['project_name']) ?></td>
            <td><?= h($d['author_name'] ?: 'Unknown') ?></td>
            <td style="font-family:'JetBrains Mono',ui-monospace,monospace;font-size:12.5px;"><?= h(format_date($d['updated_at'])) ?></td>
            <td><div class="row-actions"><a class="btn-tiny open" href="doc_edit.php?id=<?= (int)$d['id'] ?>">Open<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></a></div></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php if ($can_manage): ?>
<div class="modal-overlay" id="addProject" onclick="if(event.target===this) this.classList.remove('open')">
  <div class="modal-card">
    <div class="modal-head">
      <span class="modal-title">Add Project for <?= h($client['name']) ?></span>
      <button type="button" class="icon-close" onclick="document.getElementById('addProject').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="create_project" value="1">
      <div class="modal-body">
        <div class="modal-grid">
          <div class="c-6"><label class="field-label">Project Name</label><input class="input" name="name" required></div>
          <div class="c-3"><label class="field-label">Type</label><select class="select" name="type_id" required><?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select></div>
          <div class="c-3"><label class="field-label">Status</label><select class="select" name="status_id" required><?php foreach ($statuses as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?></select></div>
          <div class="c-4"><label class="field-label">Due Date</label><input class="input" type="date" name="due_date"></div>
          <div class="c-4"><label class="field-label">Pricing Model</label><select class="select" name="pricing_model"><option value="fixed_price">Fixed Price</option><option value="hourly">Hourly</option></select></div>
          <div class="c-4"><label class="field-label">Project Price</label><input class="input" name="project_price" type="number" min="0" step="0.01"></div>
        </div>
        <div class="login-repeater" id="loginRepeater">
          <div class="login-repeater-head"><h6>Website Logins (optional)</h6></div>
          <div class="login-rows" id="loginRows">
            <div class="login-row is-only">
              <div class="login-row-head">
                <span class="login-row-index">Login #1</span>
                <button type="button" class="login-row-remove" data-remove>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-2 14a2 2 0 01-2 2H9a2 2 0 01-2-2L5 6"></path></svg>
                  Remove
                </button>
              </div>
              <div class="modal-grid">
                <div class="c-4"><label class="field-label">Login Type</label><input class="input" name="logins[0][type]" placeholder="e.g. Current Website, Production"></div>
                <div class="c-4"><label class="field-label">Name</label><input class="input" name="logins[0][name]" placeholder="Main website"></div>
                <div class="c-4"><label class="field-label">Website URL</label><input class="input" type="url" name="logins[0][url]" placeholder="https://example.com"></div>
                <div class="c-6"><label class="field-label">Login URL</label><input class="input" type="url" name="logins[0][login_url]" placeholder="https://example.com/wp-admin"></div>
                <div class="c-3"><label class="field-label">Username</label><input class="input" name="logins[0][username]"></div>
                <div class="c-3"><label class="field-label">Password</label><input class="input" type="password" name="logins[0][password]"></div>
              </div>
            </div>
          </div>
          <button type="button" class="login-add-row" id="loginAddRow">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Add Row
          </button>
        </div>
        <template id="loginRowTemplate">
          <div class="login-row">
            <div class="login-row-head">
              <span class="login-row-index">Login #__N__</span>
              <button type="button" class="login-row-remove" data-remove>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-2 14a2 2 0 01-2 2H9a2 2 0 01-2-2L5 6"></path></svg>
                Remove
              </button>
            </div>
            <div class="modal-grid">
              <div class="c-4"><label class="field-label">Login Type</label><input class="input" name="logins[__I__][type]" placeholder="e.g. Current Website, Production"></div>
              <div class="c-4"><label class="field-label">Name</label><input class="input" name="logins[__I__][name]" placeholder="Main website"></div>
              <div class="c-4"><label class="field-label">Website URL</label><input class="input" type="url" name="logins[__I__][url]" placeholder="https://example.com"></div>
              <div class="c-6"><label class="field-label">Login URL</label><input class="input" type="url" name="logins[__I__][login_url]" placeholder="https://example.com/wp-admin"></div>
              <div class="c-3"><label class="field-label">Username</label><input class="input" name="logins[__I__][username]"></div>
              <div class="c-3"><label class="field-label">Password</label><input class="input" type="password" name="logins[__I__][password]"></div>
            </div>
          </div>
        </template>
        <div class="modal-grid" style="margin-top:14px;"><div class="c-12"><label class="field-label">Notes</label><textarea class="input" name="wl_notes" rows="2"></textarea></div></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addProject').classList.remove('open')">Cancel</button>
        <button class="btn btn-primary save-flash" type="submit">Create</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="editClient" onclick="if(event.target===this) this.classList.remove('open')">
  <div class="modal-card" style="max-width:600px;">
    <div class="modal-head">
      <span class="modal-title">Edit Client</span>
      <button type="button" class="icon-close" onclick="document.getElementById('editClient').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="update_client" value="1">
      <div class="modal-body">
        <div class="form-row" style="margin-bottom:12px;"><label class="field-label">Client Name</label><input class="input" name="client_name" value="<?= h($client['name']) ?>" required></div>
        <div class="form-row" style="margin-bottom:12px;">
          <label class="field-label">Billing Model</label>
          <select class="select" name="billing_model" id="edit_billing_model" required>
            <option value="monthly_retainer" <?= ($client['billing_model'] ?? '') === 'monthly_retainer' ? 'selected' : '' ?>>Monthly Retainer</option>
            <option value="hourly" <?= ($client['billing_model'] ?? '') === 'hourly' ? 'selected' : '' ?>>Hourly</option>
            <option value="fixed_project" <?= ($client['billing_model'] ?? '') === 'fixed_project' ? 'selected' : '' ?>>Fixed Project</option>
            <option value="hybrid" <?= ($client['billing_model'] ?? '') === 'hybrid' ? 'selected' : '' ?>>Hybrid</option>
          </select>
        </div>
        <div class="form-grid cols-2">
          <div class="form-row" id="edit_cycle_wrap"><label class="field-label">Billing Cycle</label><select class="select" name="billing_cycle" id="edit_billing_cycle"><option value="monthly" <?= ($client['billing_cycle'] ?? '') === 'monthly' ? 'selected' : '' ?>>Monthly</option><option value="every_15_days" <?= ($client['billing_cycle'] ?? '') === 'every_15_days' ? 'selected' : '' ?>>Every 15 Days</option></select></div>
          <div class="form-row" id="edit_retainer_wrap"><label class="field-label">Retainer Amount</label><input class="input" name="retainer_amount" type="number" min="0" step="0.01" value="<?= h((string)($client['retainer_amount'] ?? '')) ?>"></div>
          <div class="form-row" id="edit_hourly_wrap"><label class="field-label">Hourly Rate</label><input class="input" name="hourly_rate" type="number" min="0" step="0.01" value="<?= h((string)($client['hourly_rate'] ?? '')) ?>"></div>
        </div>
        <div class="form-row" style="margin-top:12px;"><label class="field-label">Notes</label><textarea class="input" name="client_notes" rows="3"><?= h((string)($client['notes'] ?? '')) ?></textarea></div>
        <div class="form-row" style="margin-top:12px;"><label class="field-label">Client Logo</label><input class="input" type="file" name="client_logo" accept="image/png,image/jpeg,image/webp,image/gif"></div>
        <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:12.5px;color:var(--text-muted);"><input type="checkbox" name="remove_client_logo" value="1"> Remove current logo</label>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('editClient').classList.remove('open')">Cancel</button>
        <button class="btn btn-primary save-flash" type="submit">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<?php if ($clientProjects): ?>
<div class="modal-overlay" id="addDoc" onclick="if(event.target===this) this.classList.remove('open')">
  <div class="modal-card">
    <div class="modal-head">
      <span class="modal-title">Create Doc</span>
      <button type="button" class="icon-close" onclick="document.getElementById('addDoc').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="create_doc" value="1">
      <div class="modal-body">
        <div class="modal-grid">
          <div class="c-8"><label class="field-label">Title</label><input class="input" name="doc_title" required></div>
          <div class="c-4"><label class="field-label">Project</label><select class="select" name="doc_project_id" required><?php foreach ($clientProjects as $cp): ?><option value="<?= (int)$cp['id'] ?>"><?= h($cp['name']) ?></option><?php endforeach; ?></select></div>
          <div class="c-12"><label class="field-label">Content</label><textarea class="input" name="doc_content" rows="9"></textarea></div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addDoc').classList.remove('open')">Cancel</button>
        <button class="btn btn-primary save-flash" type="submit">Create Doc</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
  // Login repeater (Add Row / Remove / re-index)
  (function () {
    const rowsWrap = document.getElementById('loginRows');
    const addBtn   = document.getElementById('loginAddRow');
    const tmpl     = document.getElementById('loginRowTemplate');
    if (!rowsWrap || !addBtn || !tmpl) return;
    function reindex() {
      const rows = rowsWrap.querySelectorAll('.login-row');
      rows.forEach((row, idx) => {
        row.classList.toggle('is-only', rows.length === 1);
        const lbl = row.querySelector('.login-row-index');
        if (lbl) lbl.textContent = 'Login #' + (idx + 1);
        row.querySelectorAll('[name^="logins["]').forEach(input => {
          input.name = input.name.replace(/^logins\[\d+\]/, 'logins[' + idx + ']');
        });
      });
    }
    addBtn.addEventListener('click', () => {
      const nextIdx = rowsWrap.querySelectorAll('.login-row').length;
      const html = tmpl.innerHTML.replace(/__N__/g, String(nextIdx + 1)).replace(/__I__/g, String(nextIdx));
      const wrap = document.createElement('div');
      wrap.innerHTML = html.trim();
      const newRow = wrap.firstElementChild;
      rowsWrap.appendChild(newRow);
      reindex();
      const firstInput = newRow.querySelector('input');
      if (firstInput) firstInput.focus();
    });
    rowsWrap.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-remove]');
      if (!btn) return;
      const row = btn.closest('.login-row');
      if (!row) return;
      if (rowsWrap.querySelectorAll('.login-row').length <= 1) {
        row.querySelectorAll('input').forEach(i => i.value = '');
        return;
      }
      row.remove();
      reindex();
    });
    reindex();
  })();

  const navLinks = document.querySelectorAll('#sectionNav a');
  navLinks.forEach(a => a.addEventListener('click', (e) => {
    const t = document.getElementById(a.dataset.target);
    if (!t) return;
    e.preventDefault();
    t.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }));
  const sections = document.querySelectorAll('.section');
  const obs = new IntersectionObserver((entries) => {
    entries.forEach(en => { if (en.isIntersecting) { const id = en.target.id; navLinks.forEach(a => a.classList.toggle('active', a.dataset.target === id)); } });
  }, { rootMargin: '-50% 0px -40% 0px', threshold: 0 });
  sections.forEach(s => obs.observe(s));

  const billingModel = document.getElementById('edit_billing_model');
  if (billingModel) {
    const cycle = document.getElementById('edit_cycle_wrap');
    const retainer = document.getElementById('edit_retainer_wrap');
    const hourly = document.getElementById('edit_hourly_wrap');
    function sync() {
      const v = billingModel.value;
      if (retainer) retainer.style.display = v === 'monthly_retainer' ? '' : 'none';
      if (hourly) hourly.style.display = (v === 'hourly' || v === 'hybrid') ? '' : 'none';
      if (cycle) cycle.style.display = (v === 'monthly_retainer' || v === 'hourly') ? '' : 'none';
    }
    billingModel.addEventListener('change', sync); sync();
  }
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
