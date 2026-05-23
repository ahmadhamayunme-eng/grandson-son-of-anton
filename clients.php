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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post(); csrf_verify();
  if (!$can_manage) { flash_set('error', 'No permission.'); redirect('clients.php'); }

  $action = $_POST['action'] ?? 'create';
  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { flash_set('error', 'Invalid client selected.'); redirect('clients.php'); }
    $st = $pdo->prepare('DELETE FROM clients WHERE workspace_id = ? AND id = ?');
    $st->execute([$ws, $id]);
    flash_set('success', $st->rowCount() ? 'Client deleted permanently.' : 'Client not found.');
    redirect('clients.php');
  }
  if ($action === 'bulk_delete') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    if (!$ids) { flash_set('error', 'Select at least one client to delete.'); redirect('clients.php'); }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("DELETE FROM clients WHERE workspace_id = ? AND id IN ($marks)");
    $st->execute(array_merge([$ws], $ids));
    flash_set('success', $st->rowCount() . ' client(s) deleted permanently.');
    redirect('clients.php');
  }

  $name = trim($_POST['name'] ?? '');
  $notes = trim($_POST['notes'] ?? '');
  $billingModel = trim((string)($_POST['billing_model'] ?? ''));
  $billingCycle = trim((string)($_POST['billing_cycle'] ?? ''));
  $retainerAmount = ($_POST['retainer_amount'] ?? '') !== '' ? (float)$_POST['retainer_amount'] : null;
  $hourlyRate = ($_POST['hourly_rate'] ?? '') !== '' ? (float)$_POST['hourly_rate'] : null;
  if ($name === '') { flash_set('error', 'Client name required.'); redirect('clients.php'); }
  if (!in_array($billingModel, ['monthly_retainer', 'hourly', 'fixed_project', 'hybrid'], true)) {
    flash_set('error', 'Billing model is required.'); redirect('clients.php');
  }

  $pdo->prepare('INSERT INTO clients (workspace_id,name,notes,billing_model,billing_cycle,retainer_amount,hourly_rate,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
      ->execute([$ws, $name, $notes ?: null, $billingModel, $billingCycle ?: null, $retainerAmount, $hourlyRate, now(), now()]);
  $newClientId = (int)$pdo->lastInsertId();

  if (isset($_FILES['logo']) && is_array($_FILES['logo']) && (int)($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $tmp = (string)($_FILES['logo']['tmp_name'] ?? '');
    $size = (int)($_FILES['logo']['size'] ?? 0);
    if ($size > 0 && $size <= 2 * 1024 * 1024) {
      $info = @getimagesize($tmp);
      $mime = strtolower((string)($info['mime'] ?? ''));
      $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'];
      if (isset($allowed[$mime])) {
        $dir = __DIR__ . '/uploads/client_logos';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        foreach (['png','jpg','jpeg','webp','gif','svg'] as $ext) {
          $oldLogo = $dir . '/' . $newClientId . '.' . $ext;
          if (is_file($oldLogo)) { @unlink($oldLogo); }
        }
        @move_uploaded_file($tmp, $dir . '/' . $newClientId . '.' . $allowed[$mime]);
      }
    }
  }

  flash_set('success', 'Client created.');
  redirect('clients.php');
}

$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPageOptions = [10, 20, 30, 40, 50];
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, $perPageOptions, true)) $perPage = 10;
$offset = ($page - 1) * $perPage;
$like = '%' . $q . '%';

$params = [':ws' => $ws, ':like' => $like];
$countSql = 'SELECT COUNT(*) FROM clients c WHERE c.workspace_id = :ws AND c.name LIKE :like';
$countSt = $pdo->prepare($countSql);
$countSt->execute($params);
$totalRows = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$listSql = "
SELECT
  c.id, c.name, c.notes, c.updated_at,
  COUNT(DISTINCT p.id) AS project_count,
  MAX(COALESCE(p.updated_at, c.updated_at)) AS last_active,
  MAX(ps.name) AS status_name,
  MAX(pt.name) AS type_name
FROM clients c
LEFT JOIN projects p ON p.client_id = c.id AND p.workspace_id = c.workspace_id
LEFT JOIN project_statuses ps ON ps.id = p.status_id
LEFT JOIN project_types pt ON pt.id = p.type_id
WHERE c.workspace_id = :ws AND c.name LIKE :like
GROUP BY c.id, c.name, c.notes, c.updated_at
ORDER BY last_active DESC, c.id DESC
LIMIT :limit OFFSET :offset
";
$listSt = $pdo->prepare($listSql);
foreach ($params as $k => $v) $listSt->bindValue($k, $v, PDO::PARAM_STR);
$listSt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listSt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listSt->execute();
$clients = $listSt->fetchAll();

$allCount = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE workspace_id={$ws}")->fetchColumn();
$projectsTotal = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE workspace_id={$ws}")->fetchColumn();

$statusBuckets = ['active' => 0, 'paused' => 0, 'archived' => 0];
foreach ($clients as $c) {
  $raw = strtolower((string)($c['status_name'] ?? ''));
  if (strpos($raw, 'hold') !== false || strpos($raw, 'pause') !== false) $statusBuckets['paused']++;
  elseif (strpos($raw, 'close') !== false || strpos($raw, 'cancel') !== false || strpos($raw, 'archive') !== false) $statusBuckets['archived']++;
  else $statusBuckets['active']++;
}

function clients_status_key(array $row): string {
  $raw = strtolower((string)($row['status_name'] ?? ''));
  if ($raw === '') return 'active';
  if (strpos($raw, 'close') !== false || strpos($raw, 'cancel') !== false || strpos($raw, 'archive') !== false) return 'archived';
  if (strpos($raw, 'hold') !== false || strpos($raw, 'pause') !== false) return 'paused';
  return 'active';
}
function clients_status_label(string $key): string {
  return $key === 'paused' ? 'Paused' : ($key === 'archived' ? 'Archived' : 'Active');
}
function clients_tag_class(string $tag): string {
  $s = strtolower($tag);
  if (strpos($s, 'maint') !== false) return 'maintenance';
  if (strpos($s, 'seo') !== false) return 'seo';
  if (strpos($s, 'design') !== false) return 'design';
  return 'general';
}
function clients_last_active(?string $date): array {
  if (!$date) return ['—', false];
  $ts = strtotime($date);
  if (!$ts) return ['—', false];
  $diff = time() - $ts;
  if ($diff < 86400) return ['Today, ' . date('H:i', $ts), true];
  if ($diff < 172800) return ['Yesterday', false];
  if ($diff < 1209600) return [((int)floor($diff / 86400)) . ' days ago', false];
  return [date('M j, Y', $ts), false];
}
function clients_avatar_gradient(string $seed): string {
  $palette = [
    'linear-gradient(135deg,#f43f5e,#e11d48)','linear-gradient(135deg,#10b981,#059669)',
    'linear-gradient(135deg,#0ea5e9,#0369a1)','linear-gradient(135deg,#a855f7,#7c3aed)',
    'linear-gradient(135deg,#fb923c,#ea580c)','linear-gradient(135deg,#ec4899,#be185d)',
    'linear-gradient(135deg,#fb7185,#be123c)','linear-gradient(135deg,#94a3b8,#64748b)',
    'linear-gradient(135deg,#facc15,#ca8a04)','linear-gradient(135deg,#f59e0b,#d97706)',
  ];
  return $palette[crc32($seed) % count($palette)];
}

$pageTitle = 'Clients';
$activeKey = 'clients';
$pageHeadExtra = <<<HTML
<style>
  .page-header { padding: 28px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; }
  .page-stats { display: flex; gap: 24px; font-variant-numeric: tabular-nums; }
  .stat { display: flex; flex-direction: column; gap: 2px; text-align: right; }
  .stat-num { font-size: 20px; font-weight: 600; color: var(--text); letter-spacing: -0.01em; }
  .stat-label { font-size: 10.5px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; }
  .toolbar { max-width: 1640px; margin: 0 auto; width: 100%; padding: 24px 32px 16px; display: grid; grid-template-columns: 1fr auto auto; gap: 12px; align-items: center; }
  .search-box { position: relative; }
  .search-box svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--text-dim); }
  .search-box input { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 11px 14px 11px 40px; font-size: 13px; color: var(--text); outline: none; transition: all 0.15s; font-family: inherit; }
  .search-box input::placeholder { color: var(--text-dim); }
  .search-box input:focus { border-color: var(--border-strong); background: var(--surface-2); }
  .filter-select { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 10px 32px 10px 14px; font-size: 13px; color: var(--text); appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%238a8a8a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; cursor: pointer; outline: none; min-width: 110px; font-family: inherit; }
  .filter-select option { background: #1a1a1a; color: var(--text); }
  .bulk-bar { max-width: 1640px; margin: 0 auto; width: 100%; padding: 14px 32px 0; display: flex; align-items: center; gap: 12px; min-height: 56px; }
  .bulk-msg { color: var(--text-dim); font-size: 12.5px; }
  .bulk-actions { display: none; gap: 8px; margin-left: auto; }
  .bulk-bar.has-selection .bulk-msg { color: var(--text); font-weight: 500; font-size: 13px; }
  .bulk-bar.has-selection .bulk-actions { display: flex; }
  .selection-count { background: var(--accent); color: #1a1400; font-weight: 600; padding: 2px 8px; border-radius: 999px; font-size: 11.5px; margin-left: 4px; }
  .table-wrap { max-width: 1640px; margin: 0 auto; width: 100%; padding: 0 32px 28px; }
  .table-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  table.clients { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.clients thead th { text-align: left; padding: 12px 16px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-dim); background: var(--bg); border-bottom: 1px solid var(--border); white-space: nowrap; }
  table.clients thead th.num { text-align: right; }
  table.clients tbody tr { transition: background 0.12s; }
  table.clients tbody tr:hover { background: var(--surface-hover); }
  table.clients tbody tr.selected { background: rgba(250,204,21,0.04); }
  table.clients tbody td { padding: 14px 16px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
  table.clients tbody tr:last-child td { border-bottom: none; }
  .client-cell { display: flex; align-items: center; gap: 12px; min-width: 0; }
  .client-logo { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; color: #fff; flex-shrink: 0; letter-spacing: 0.02em; overflow: hidden; }
  .client-logo img { width: 100%; height: 100%; object-fit: cover; }
  .client-name { font-weight: 600; font-size: 13.5px; color: var(--text); text-decoration: none; }
  .client-name:hover { color: var(--accent); }
  .client-id { font-size: 11px; color: var(--text-dim); font-family: 'JetBrains Mono', ui-monospace, monospace; margin-top: 1px; }
  .em-dash { color: var(--text-dim); }
  .tag { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 500; border: 1px solid var(--border); background: var(--surface-2); color: var(--text-muted); }
  .tag.maintenance { color: #fbbf24; border-color: rgba(251,191,36,0.25); background: rgba(251,191,36,0.08); }
  .tag.seo { color: #a78bfa; border-color: rgba(167,139,250,0.25); background: rgba(167,139,250,0.08); }
  .tag.design { color: #38bdf8; border-color: rgba(56,189,248,0.25); background: rgba(56,189,248,0.08); }
  .tag.general { color: #94a3b8; border-color: rgba(148,163,184,0.25); background: rgba(148,163,184,0.08); }
  .status-cell { display: inline-flex; align-items: center; gap: 7px; font-size: 12.5px; font-weight: 500; }
  .status-cell .dot { width: 7px; height: 7px; border-radius: 50%; box-shadow: 0 0 0 3px transparent; }
  .status-cell.active { color: #86efac; }
  .status-cell.active .dot { background: var(--success); box-shadow: 0 0 0 3px rgba(34,197,94,0.18); }
  .status-cell.paused { color: #fcd34d; }
  .status-cell.paused .dot { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,0.18); }
  .status-cell.archived { color: var(--text-dim); }
  .status-cell.archived .dot { background: var(--text-dim); }
  .num { font-variant-numeric: tabular-nums; text-align: right; }
  .projects-link { color: var(--text); border-bottom: 1px dashed var(--border-strong); padding-bottom: 1px; transition: border-color 0.15s; font-weight: 500; text-decoration: none; }
  .projects-link:hover { border-bottom-color: var(--accent); color: var(--accent); }
  .projects-zero { color: var(--text-dim); font-variant-numeric: tabular-nums; }
  .last-active { color: var(--text-muted); font-size: 12.5px; font-variant-numeric: tabular-nums; }
  .last-active.recent { color: var(--text); }
  .row-check { width: 16px; height: 16px; border-radius: 4px; border: 1.5px solid var(--border-strong); background: var(--bg); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.12s; flex-shrink: 0; }
  .row-check svg { width: 11px; height: 11px; color: #1a1400; opacity: 0; transition: opacity 0.12s; }
  .row-check.on { background: var(--accent); border-color: var(--accent); }
  .row-check.on svg { opacity: 1; }
  .row-actions { display: flex; align-items: center; gap: 4px; justify-content: flex-end; opacity: 0.6; transition: opacity 0.15s; }
  table.clients tbody tr:hover .row-actions { opacity: 1; }
  .icon-btn-sq { width: 28px; height: 28px; border-radius: 6px; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; border: 1px solid transparent; transition: all 0.12s; background: none; cursor: pointer; }
  .icon-btn-sq:hover { background: var(--surface-2); color: var(--text); border-color: var(--border); }
  .icon-btn-sq.danger:hover { background: var(--danger-soft); color: #fca5a5; border-color: rgba(239,68,68,0.25); }
  .icon-btn-sq svg { width: 13px; height: 13px; }
  .pagination { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-top: 1px solid var(--border); background: var(--bg); }
  .page-info { font-size: 12px; color: var(--text-muted); font-variant-numeric: tabular-nums; }
  .page-info b { color: var(--text); font-weight: 500; }
  .page-controls { display: inline-flex; align-items: center; gap: 6px; }
  .page-btn { min-width: 28px; height: 28px; padding: 0 8px; border-radius: 6px; font-size: 12px; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); transition: all 0.12s; font-variant-numeric: tabular-nums; display: inline-flex; align-items: center; justify-content: center; gap: 4px; text-decoration: none; }
  .page-btn:hover { color: var(--text); border-color: var(--border-strong); }
  .page-btn.active { background: var(--surface-2); color: var(--text); border-color: var(--border-strong); }
  .page-btn.disabled { opacity: 0.4; pointer-events: none; }
  .page-btn svg { width: 12px; height: 12px; }

  .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 100; display: none; align-items: flex-start; justify-content: center; padding: 60px 20px; overflow-y: auto; }
  .modal-overlay.open { display: flex; }
  .modal-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; width: 100%; max-width: 600px; overflow: hidden; }
  .modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
  .modal-title { font-size: 16px; font-weight: 600; color: var(--text); }
  .modal-body { padding: 20px; max-height: 70vh; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
  .modal-foot { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; gap: 8px; justify-content: flex-end; background: var(--bg); }
  .icon-close { width: 30px; height: 30px; border-radius: 6px; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; transition: all 0.12s; }
  .icon-close:hover { background: var(--surface-2); color: var(--text); }

  /* ===== Mobile: clients table → card stack =====
     The 7-col table (checkbox + Client + Tag + Status + Projects + Last
     Active + Actions) doesn't fit on a 375px viewport. Each row becomes
     a card with the client identity on top, secondary metadata in a
     wrap-friendly row, and action buttons below. */
  @media (max-width: 768px) {
    table.clients { font-size: 14px; }
    table.clients thead { display: none; }
    table.clients, table.clients tbody, table.clients tr { display: block; }
    table.clients tbody tr {
      padding: 12px 14px;
      border-bottom: 1px solid var(--border);
    }
    table.clients tbody td {
      display: inline-block;
      border: none;
      padding: 2px 0;
      vertical-align: middle;
      margin-right: 8px;
      min-width: 0;
    }
    table.clients tbody td:first-child { float: right; margin-right: 0; }
    /* The Client column (with avatar + name) takes the full first line. */
    table.clients tbody td:nth-child(2),
    table.clients tbody td:nth-child(2 of :not(:first-child)) {
      display: block;
      margin-bottom: 6px;
    }
    /* Action buttons row at the bottom — full width, right-aligned. */
    .row-actions {
      opacity: 1 !important;
      justify-content: flex-end;
      margin-top: 6px;
    }
    /* Tabs strip needs to clear the hamburger zone. */
    .scope-tabs { gap: 6px; }
    /* Stats strip (TOTAL/ACTIVE/PAUSED/PROJECTS) stays as a 2×2 grid
       (anton.css mobile rule handles it via the generic selector). */
  }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Clients</div>
      <div class="page-sub">Everyone you're working with, in one place.</div>
    </div>
  </div>
  <div class="page-stats">
    <div class="stat"><span class="stat-num"><?= (int)$allCount ?></span><span class="stat-label">Total</span></div>
    <div class="stat"><span class="stat-num" style="color:#86efac;"><?= (int)$statusBuckets['active'] ?></span><span class="stat-label">Active</span></div>
    <div class="stat"><span class="stat-num" style="color:#fcd34d;"><?= (int)$statusBuckets['paused'] ?></span><span class="stat-label">Paused</span></div>
    <div class="stat"><span class="stat-num"><?= (int)$projectsTotal ?></span><span class="stat-label">Projects</span></div>
  </div>
</div>

<form class="toolbar" method="get">
  <div class="search-box">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
    <input name="q" value="<?= h($q) ?>" placeholder="Search clients by name, tag, or status…" autocomplete="off">
  </div>
  <select class="filter-select" name="per_page" onchange="this.form.submit()">
    <?php foreach ($perPageOptions as $opt): ?>
      <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>>Show <?= $opt ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($can_manage): ?>
    <button type="button" class="btn btn-primary save-flash" onclick="document.getElementById('addClient').classList.add('open')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
      New Client
    </button>
  <?php else: ?>
    <span></span>
  <?php endif; ?>
</form>

<?php if ($can_manage): ?>
<form method="post" id="bulkDeleteClients" class="bulk-bar">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="action" value="bulk_delete">
  <span class="bulk-msg">Select rows to perform bulk actions.</span>
  <div class="bulk-actions">
    <button type="submit" class="btn btn-danger" onclick="return confirm('This will permanently delete selected clients and all their linked projects/tasks/docs. Are you sure?');">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path></svg>
      Delete <span class="selection-count" id="selCount">0</span>
    </button>
  </div>
</form>
<?php endif; ?>

<div class="table-wrap">
  <div class="table-card">
    <table class="clients">
      <thead>
        <tr>
          <?php if ($can_manage): ?><th style="width:44px;text-align:center;"><span class="row-check" id="checkAll"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></span></th><?php endif; ?>
          <th>Client</th>
          <th>Tag</th>
          <th>Status</th>
          <th class="num">Projects</th>
          <th>Last Active</th>
          <th style="width:110px;text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$clients): ?>
        <tr><td colspan="<?= $can_manage ? 7 : 6 ?>" style="padding:32px 16px;text-align:center;color:var(--text-dim);font-style:italic;">No clients found.</td></tr>
      <?php else: foreach ($clients as $c):
        $statusKey = clients_status_key($c);
        $statusLabel = clients_status_label($statusKey);
        $tag = trim((string)($c['type_name'] ?? '')); if ($tag === '') $tag = 'General';
        $tagClass = clients_tag_class($tag);
        $logoUrl = client_logo_url((int)$c['id']);
        $clientGrad = clients_avatar_gradient((string)$c['name']);
        [$activityLabel, $isRecent] = clients_last_active((string)($c['last_active'] ?? ''));
      ?>
        <tr>
          <?php if ($can_manage): ?>
            <td style="text-align:center;">
              <label class="row-check" data-row-check>
                <input type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>" form="bulkDeleteClients" style="display:none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
              </label>
            </td>
          <?php endif; ?>
          <td>
            <div class="client-cell">
              <div class="client-logo" style="background:<?= h($clientGrad) ?>">
                <?php if ($logoUrl): ?><img src="<?= h($logoUrl) ?>" alt="<?= h($c['name']) ?>"><?php else: ?><?= h(user_initials((string)$c['name'])) ?><?php endif; ?>
              </div>
              <div>
                <a class="client-name" href="client_view.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a>
                <div class="client-id">CLI-<?= str_pad((string)(int)$c['id'], 4, '0', STR_PAD_LEFT) ?></div>
              </div>
            </div>
          </td>
          <td><span class="tag <?= h($tagClass) ?>"><?= h($tag) ?></span></td>
          <td><span class="status-cell <?= h($statusKey) ?>"><span class="dot"></span><?= h($statusLabel) ?></span></td>
          <td class="num">
            <?php if ((int)$c['project_count'] > 0): ?>
              <a class="projects-link" href="projects.php?q=<?= urlencode($c['name']) ?>"><?= (int)$c['project_count'] ?></a>
            <?php else: ?>
              <span class="projects-zero">0</span>
            <?php endif; ?>
          </td>
          <td><span class="last-active <?= $isRecent ? 'recent' : '' ?>"><?= h($activityLabel) ?></span></td>
          <td>
            <div class="row-actions">
              <a class="icon-btn-sq" href="client_view.php?id=<?= (int)$c['id'] ?>" title="View">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
              </a>
              <?php if ($can_manage): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('This will permanently delete this client and all linked projects/tasks/docs. Are you sure?');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="icon-btn-sq danger" title="Delete">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path></svg>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <div class="pagination">
      <span class="page-info">Showing <b><?= count($clients) ? (($page - 1) * $perPage + 1) . '–' . (($page - 1) * $perPage + count($clients)) : '0' ?></b> of <b><?= (int)$totalRows ?></b> clients</span>
      <div class="page-controls">
        <?php $qsBase = http_build_query(['q' => $q, 'per_page' => $perPage]); ?>
        <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= $qsBase ?>&page=<?= max(1, $page - 1) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
          Prev
        </a>
        <?php for ($pp = 1; $pp <= $totalPages; $pp++): if ($totalPages > 7 && $pp > 3 && $pp < $totalPages - 2 && $pp !== $page) { if ($pp === 4) echo '<span class="page-btn disabled">…</span>'; continue; } ?>
          <a class="page-btn <?= $pp === $page ? 'active' : '' ?>" href="?<?= $qsBase ?>&page=<?= $pp ?>"><?= $pp ?></a>
        <?php endfor; ?>
        <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= $qsBase ?>&page=<?= min($totalPages, $page + 1) ?>">
          Next
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
        </a>
      </div>
    </div>
  </div>
</div>

<?php if ($can_manage): ?>
<div class="modal-overlay" id="addClient" onclick="if(event.target===this) this.classList.remove('open')">
  <div class="modal-card">
    <div class="modal-head">
      <span class="modal-title">Add Client</span>
      <button type="button" class="icon-close" onclick="document.getElementById('addClient').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <div class="modal-body">
        <div class="form-row"><label class="field-label">Client Name</label><input class="input" name="name" required></div>
        <div class="form-row">
          <label class="field-label">Billing Model</label>
          <select class="select" name="billing_model" id="create_billing_model" required>
            <option value="">Select Billing Model</option>
            <option value="monthly_retainer">Monthly Retainer</option>
            <option value="hourly">Hourly</option>
            <option value="fixed_project">Fixed Project</option>
            <option value="hybrid">Hybrid</option>
          </select>
        </div>
        <div class="form-grid cols-2">
          <div class="form-row" id="create_cycle_wrap">
            <label class="field-label">Billing Cycle</label>
            <select class="select" name="billing_cycle" id="create_billing_cycle">
              <option value="monthly">Monthly</option>
              <option value="every_15_days">Every 15 Days</option>
            </select>
          </div>
          <div class="form-row" id="create_retainer_wrap"><label class="field-label">Retainer Amount</label><input class="input" name="retainer_amount" type="number" min="0" step="0.01"></div>
          <div class="form-row" id="create_hourly_wrap"><label class="field-label">Hourly Rate</label><input class="input" name="hourly_rate" type="number" min="0" step="0.01"></div>
        </div>
        <div class="form-row"><label class="field-label">Notes</label><textarea class="input" name="notes" rows="3"></textarea></div>
        <div class="form-row"><label class="field-label">Business Logo (optional)</label><input class="input" type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif"></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addClient').classList.remove('open')">Cancel</button>
        <button class="btn btn-primary save-flash" type="submit">Create</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  const model = document.getElementById('create_billing_model');
  if (model) {
    const cycle = document.getElementById('create_cycle_wrap');
    const retainer = document.getElementById('create_retainer_wrap');
    const hourly = document.getElementById('create_hourly_wrap');
    const cycleEl = document.getElementById('create_billing_cycle');
    function sync(){
      const v = model.value;
      if (retainer) retainer.style.display = v === 'monthly_retainer' ? '' : 'none';
      if (hourly) hourly.style.display = (v === 'hourly' || v === 'hybrid') ? '' : 'none';
      if (cycle) cycle.style.display = (v === 'monthly_retainer' || v === 'hourly') ? '' : 'none';
      if (cycleEl && v === 'monthly_retainer') cycleEl.value = 'monthly';
    }
    model.addEventListener('change', sync); sync();
  }

  const bulkForm = document.getElementById('bulkDeleteClients');
  if (bulkForm) {
    const selCount = document.getElementById('selCount');
    function refresh() {
      const sel = document.querySelectorAll('[data-row-check] input:checked').length;
      bulkForm.classList.toggle('has-selection', sel > 0);
      if (selCount) selCount.textContent = sel;
      const msg = bulkForm.querySelector('.bulk-msg');
      if (msg) msg.textContent = sel > 0 ? (sel + ' client' + (sel === 1 ? '' : 's') + ' selected') : 'Select rows to perform bulk actions.';
      const total = document.querySelectorAll('[data-row-check]').length;
      const all = document.getElementById('checkAll');
      if (all) all.classList.toggle('on', sel === total && total > 0);
    }
    document.querySelectorAll('[data-row-check]').forEach(label => {
      const cb = label.querySelector('input[type=checkbox]');
      label.addEventListener('click', () => setTimeout(() => { label.classList.toggle('on', cb.checked); label.closest('tr').classList.toggle('selected', cb.checked); refresh(); }, 0));
    });
    const all = document.getElementById('checkAll');
    if (all) all.addEventListener('click', () => {
      const turnOn = !all.classList.contains('on');
      document.querySelectorAll('[data-row-check]').forEach(label => {
        const cb = label.querySelector('input[type=checkbox]');
        cb.checked = turnOn;
        label.classList.toggle('on', turnOn);
        label.closest('tr').classList.toggle('selected', turnOn);
      });
      refresh();
    });
  }
})();
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
