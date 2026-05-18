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
$canManage = in_array($role, ['CEO', 'Manager', 'Super Admin'], true);

function projects_has_live_url_col(PDO $pdo): bool {
  try { $st = $pdo->query("SHOW COLUMNS FROM projects LIKE 'live_website_url'"); return (bool)$st->fetch(); } catch (Throwable $e) { return false; }
}
function projects_has_website_details_col(PDO $pdo): bool {
  try { $st = $pdo->query("SHOW COLUMNS FROM projects LIKE 'website_details_json'"); return (bool)$st->fetch(); } catch (Throwable $e) { return false; }
}
function projects_is_valid_url(string $value): bool {
  if ($value === '') return true;
  if (!filter_var($value, FILTER_VALIDATE_URL)) return false;
  $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
  return in_array($scheme, ['http', 'https'], true);
}

if (!projects_has_live_url_col($pdo)) {
  try { $pdo->exec("ALTER TABLE projects ADD COLUMN live_website_url VARCHAR(255) NULL AFTER due_date"); } catch (Throwable $e) {}
}
if (!projects_has_website_details_col($pdo)) {
  try { $pdo->exec("ALTER TABLE projects ADD COLUMN website_details_json LONGTEXT NULL AFTER notes"); } catch (Throwable $e) {}
}
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS website_logins (
    id INT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, client_id INT NULL, project_id INT NULL,
    site_name VARCHAR(190) NOT NULL, website_url VARCHAR(255) NULL, login_url VARCHAR(255) NULL,
    login_username VARCHAR(190) NULL, login_password TEXT NULL, notes TEXT NULL,
    created_by INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    INDEX idx_wl_ws (workspace_id), INDEX idx_wl_client (client_id), INDEX idx_wl_project (project_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$isSuperAdmin = $role === 'Super Admin';
$projects = []; $statusOptions = []; $clients = []; $types = [];
$totalRows = 0; $totalPages = 1; $loadError = null;

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (int)($_GET['status_id'] ?? 0);
$sort = strtolower(trim((string)($_GET['sort'] ?? 'activity')));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPageOptions = [10, 20, 30, 40, 50];
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, $perPageOptions, true)) $perPage = 10;
$offset = ($page - 1) * $perPage;

function projects_avatar_gradient(string $seed): string {
  $palette = [
    'linear-gradient(135deg,#10b981,#059669)','linear-gradient(135deg,#f43f5e,#e11d48)',
    'linear-gradient(135deg,#0ea5e9,#0369a1)','linear-gradient(135deg,#a855f7,#7c3aed)',
    'linear-gradient(135deg,#fb923c,#ea580c)','linear-gradient(135deg,#ec4899,#be185d)',
    'linear-gradient(135deg,#64748b,#334155)','linear-gradient(135deg,#94a3b8,#475569)',
    'linear-gradient(135deg,#78716c,#44403c)','linear-gradient(135deg,#facc15,#ca8a04)',
  ];
  return $palette[crc32($seed) % count($palette)];
}
function projects_status_pill_class(string $status): string {
  $s = strtolower($status);
  if (strpos($s, 'hold') !== false || strpos($s, 'pause') !== false) return 'hold';
  if (strpos($s, 'complete') !== false || strpos($s, 'done') !== false || strpos($s, 'closed') !== false) return 'done';
  if (strpos($s, 'archive') !== false) return 'archived';
  return 'active';
}
function projects_last_activity(?string $date): array {
  if (!$date) return ['—', false];
  $ts = strtotime($date);
  if (!$ts) return ['—', false];
  $delta = time() - $ts;
  if ($delta < 86400) return ['Today, ' . date('H:i', $ts), true];
  if ($delta < 172800) return ['Yesterday', false];
  if ($delta < 1209600) return [((int)floor($delta / 86400)) . ' days ago', false];
  return [date('M j', $ts), false];
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project'])) {
    require_post(); csrf_verify();
    if (!$canManage) { flash_set('error', 'No permission.'); redirect('projects.php'); }
    $deleteId = (int)($_POST['id'] ?? 0);
    if ($deleteId <= 0) { flash_set('error', 'Invalid project selected.'); redirect('projects.php'); }
    $st = $pdo->prepare('DELETE FROM projects WHERE workspace_id = ? AND id = ?');
    $st->execute([$ws, $deleteId]);
    flash_set('success', $st->rowCount() ? 'Project deleted permanently.' : 'Project not found.');
    redirect('projects.php');
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_projects'])) {
    require_post(); csrf_verify();
    if (!$canManage) { flash_set('error', 'No permission.'); redirect('projects.php'); }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    if (!$ids) { flash_set('error', 'Select at least one project to delete.'); redirect('projects.php'); }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("DELETE FROM projects WHERE workspace_id = ? AND id IN ($marks)");
    $st->execute(array_merge([$ws], $ids));
    flash_set('success', $st->rowCount() . ' project(s) deleted permanently.');
    redirect('projects.php');
  }

  $statusOptionsStmt = $pdo->prepare('SELECT id, name FROM project_statuses WHERE workspace_id = ? ORDER BY sort_order ASC, id ASC');
  $statusOptionsStmt->execute([$ws]);
  $statusOptions = $statusOptionsStmt->fetchAll();

  $clientsStmt = $pdo->prepare('SELECT id, name FROM clients WHERE workspace_id = ? ORDER BY name ASC');
  $clientsStmt->execute([$ws]);
  $clients = $clientsStmt->fetchAll();

  $typesStmt = $pdo->prepare('SELECT id, name FROM project_types WHERE workspace_id = ? ORDER BY sort_order ASC, id ASC');
  $typesStmt->execute([$ws]);
  $types = $typesStmt->fetchAll();

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
    require_post(); csrf_verify();
    if (!$canManage) { flash_set('error', 'No permission.'); redirect('projects.php'); }

    $name = trim($_POST['name'] ?? '');
    $clientId = (int)($_POST['client_id'] ?? 0);
    $typeId = (int)($_POST['type_id'] ?? 0);
    $statusId = (int)($_POST['status_id'] ?? 0);
    $dueDate = trim((string)($_POST['due_date'] ?? ''));
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
      // Skip rows where every field is blank.
      if ($entry['type'] === '' && $entry['name'] === '' && $entry['url'] === '' && $entry['login_url'] === '' && $entry['username'] === '' && $entry['password'] === '') continue;
      $logins[] = $entry;
    }

    if ($name === '') $name = 'Untitled Project';
    if ($clientId <= 0 && !empty($clients)) $clientId = (int)$clients[0]['id'];
    if ($typeId <= 0 && !empty($types)) $typeId = (int)$types[0]['id'];
    if ($statusId <= 0 && !empty($statusOptions)) $statusId = (int)$statusOptions[0]['id'];

    if ($clientId <= 0 || $typeId <= 0 || $statusId <= 0) {
      flash_set('error', 'Please set up at least one client, project type, and project status before creating a project.');
      redirect('projects.php');
    }
    if (!in_array($pricingModel, ['fixed_price', 'hourly'], true)) {
      flash_set('error', 'Project pricing model is required.');
      redirect('projects.php');
    }
    foreach ($logins as $entry) {
      if (!projects_is_valid_url($entry['url']) || !projects_is_valid_url($entry['login_url'])) {
        flash_set('error', 'Please enter valid website URLs (http/https).');
        redirect('projects.php');
      }
    }

    // Build the website_details_json blob in a generic shape (was hardcoded current/production before).
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
    // For backward compat with the live_website_url column, use the first non-empty website URL.
    $firstSiteUrl = null;
    foreach ($logins as $e) { if ($e['url'] !== '') { $firstSiteUrl = $e['url']; break; } }

    $pdo->prepare('INSERT INTO projects (workspace_id, client_id, name, type_id, status_id, due_date, pricing_model, project_price, payment_terms, hourly_rate, live_website_url, notes, website_details_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$ws, $clientId, $name, $typeId, $statusId, $dueDate ?: null, $pricingModel, $projectPrice, $paymentTerms ?: null, $projectHourlyRate, $firstSiteUrl, null, json_encode($websiteDetails, JSON_UNESCAPED_SLASHES), now(), now()]);
    $newProjectId = (int)$pdo->lastInsertId();

    // Write each non-empty repeater row to website_logins. Site name prefers explicit Name,
    // falls back to the Type label, then to "<Project> (Login)".
    foreach ($logins as $idx => $entry) {
      $siteName = $entry['name'] !== ''
        ? $entry['name']
        : ($entry['type'] !== '' ? $entry['type'] : $name . ' (Login #' . ($idx + 1) . ')');
      $rowNotes = trim(($entry['type'] !== '' ? 'Type: ' . $entry['type'] : '') . ($wlNotes !== '' ? ($entry['type'] !== '' ? "\n" : '') . $wlNotes : ''));
      $pdo->prepare('INSERT INTO website_logins (workspace_id,client_id,project_id,site_name,website_url,login_url,login_username,login_password,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$ws, $clientId, $newProjectId, $siteName, $entry['url'] ?: null, $entry['login_url'] ?: null, $entry['username'] ?: null, $entry['password'] !== '' ? $entry['password'] : null, $rowNotes ?: null, (int)($user['id'] ?? 0), now(), now()]);
    }

    flash_set('success', 'Project created.');
    redirect('projects.php');
  }

  $allowedSorts = ['activity', 'name', 'status'];
  if (!in_array($sort, $allowedSorts, true)) $sort = 'activity';

  $sortSql = 'last_activity DESC, p.id DESC';
  if ($sort === 'name') $sortSql = 'p.name ASC, p.id DESC';
  elseif ($sort === 'status') $sortSql = 'ps.name ASC, p.id DESC';

  $where = ' WHERE p.workspace_id = :ws AND (p.name LIKE :q_project OR c.name LIKE :q_client) ';
  $params = [':ws' => $ws, ':q_project' => '%' . $q . '%', ':q_client' => '%' . $q . '%'];
  if ($statusFilter > 0) { $where .= ' AND p.status_id = :status_id '; $params[':status_id'] = $statusFilter; }

  $countSql = 'SELECT COUNT(*) FROM projects p JOIN clients c ON c.id = p.client_id ' . $where;
  $countStmt = $pdo->prepare($countSql);
  $countStmt->execute($params);
  $totalRows = (int)$countStmt->fetchColumn();
  $totalPages = max(1, (int)ceil($totalRows / $perPage));
  if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

  $listSql = <<<SQL
SELECT
  p.id, p.name, p.client_id, p.created_at, p.updated_at,
  c.name AS client_name, ps.name AS status_name, pt.name AS type_name,
  (SELECT COUNT(DISTINCT ta.user_id) FROM tasks t LEFT JOIN task_assignees ta ON ta.task_id = t.id WHERE t.workspace_id = p.workspace_id AND t.project_id = p.id) AS assignee_count,
  (SELECT GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') FROM tasks t LEFT JOIN task_assignees ta ON ta.task_id = t.id LEFT JOIN users u ON u.id = ta.user_id WHERE t.workspace_id = p.workspace_id AND t.project_id = p.id) AS assignee_names,
  GREATEST(
    COALESCE(p.updated_at, '1000-01-01 00:00:00'),
    COALESCE((SELECT MAX(t.updated_at) FROM tasks t WHERE t.workspace_id = p.workspace_id AND t.project_id = p.id), '1000-01-01 00:00:00'),
    COALESCE((SELECT MAX(d.updated_at) FROM docs d WHERE d.workspace_id = p.workspace_id AND d.project_id = p.id), '1000-01-01 00:00:00')
  ) AS last_activity
FROM projects p
JOIN clients c ON c.id = p.client_id
JOIN project_statuses ps ON ps.id = p.status_id
LEFT JOIN project_types pt ON pt.id = p.type_id
{$where}
ORDER BY {$sortSql}
LIMIT :limit OFFSET :offset
SQL;

  $listStmt = $pdo->prepare($listSql);
  foreach ($params as $key => $value) {
    $listStmt->bindValue($key, $value, $key === ':ws' || $key === ':status_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
  $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $listStmt->execute();
  $projects = $listStmt->fetchAll();
} catch (Throwable $e) {
  $loadError = 'Projects page failed to load data. Please refresh or contact admin.';
  if ($isSuperAdmin) $loadError .= ' Debug: ' . $e->getMessage();
}

$statusCounts = ['active' => 0, 'hold' => 0, 'done' => 0, 'archived' => 0, 'total' => $totalRows];
foreach ($projects as $p) { $statusCounts[projects_status_pill_class((string)$p['status_name'])]++; }

$openTaskCountStmt = $pdo->prepare("SELECT COUNT(*) FROM tasks t JOIN projects p ON p.id=t.project_id WHERE p.workspace_id=? AND t.status NOT IN ('Submitted to Client','Approved (Ready to Submit)')");
$openTaskCountStmt->execute([$ws]);
$openTasksTotal = (int)$openTaskCountStmt->fetchColumn();

$pageTitle = 'Projects';
$activeKey = 'projects';
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
  .toolbar { max-width: 1640px; margin: 0 auto; width: 100%; padding: 24px 32px 16px; display: grid; grid-template-columns: 1fr auto auto auto auto; gap: 12px; align-items: center; }
  .search-box { position: relative; }
  .search-box svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--text-dim); }
  .search-box input { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 11px 14px 11px 40px; font-size: 13px; color: var(--text); outline: none; transition: all 0.15s; font-family: inherit; }
  .search-box input::placeholder { color: var(--text-dim); }
  .search-box input:focus { border-color: var(--border-strong); background: var(--surface-2); }
  .filter-select { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 10px 32px 10px 14px; font-size: 13px; color: var(--text); appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%238a8a8a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; cursor: pointer; outline: none; min-width: 132px; font-family: inherit; }
  .filter-select option { background: #1a1a1a; color: var(--text); }
  .bulk-bar { max-width: 1640px; margin: 0 auto; width: 100%; padding: 14px 32px 0; display: flex; align-items: center; gap: 12px; min-height: 56px; }
  .bulk-msg { color: var(--text-dim); font-size: 12.5px; }
  .bulk-actions { display: none; gap: 8px; margin-left: auto; }
  .bulk-bar.has-selection .bulk-msg { color: var(--text); font-weight: 500; font-size: 13px; }
  .bulk-bar.has-selection .bulk-actions { display: flex; }
  .selection-count { background: var(--accent); color: #1a1400; font-weight: 600; padding: 2px 8px; border-radius: 999px; font-size: 11.5px; margin-left: 4px; }
  .table-wrap { max-width: 1640px; margin: 0 auto; width: 100%; padding: 0 32px 28px; }
  .table-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  table.projects { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.projects thead th { text-align: left; padding: 12px 16px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-dim); background: var(--bg); border-bottom: 1px solid var(--border); white-space: nowrap; }
  table.projects tbody tr { transition: background 0.12s; }
  table.projects tbody tr:hover { background: var(--surface-hover); }
  table.projects tbody tr.selected { background: rgba(250,204,21,0.04); }
  table.projects tbody td { padding: 14px 16px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
  table.projects tbody tr:last-child td { border-bottom: none; }
  .project-cell { display: flex; align-items: center; gap: 12px; min-width: 0; }
  .project-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 11px; font-weight: 600; color: #fff; border: 1px solid rgba(255,255,255,0.06); letter-spacing: 0.02em; }
  .project-name { font-weight: 600; font-size: 13.5px; color: var(--text); line-height: 1.3; text-decoration: none; }
  .project-name:hover { color: var(--accent); }
  .project-sub { font-size: 11.5px; color: var(--text-dim); margin-top: 2px; display: flex; align-items: center; gap: 6px; }
  .project-sub .dot-sep { width: 2px; height: 2px; background: var(--text-dim); border-radius: 50%; display: inline-block; }
  .project-id { font-family: 'JetBrains Mono', ui-monospace, monospace; }
  .client-mini { display: flex; align-items: center; gap: 9px; color: var(--text-muted); }
  .client-mini-logo { width: 22px; height: 22px; border-radius: 50%; background: var(--surface-2); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 600; color: #fff; flex-shrink: 0; letter-spacing: 0.02em; }
  .client-mini-name { color: var(--text); font-size: 12.5px; font-weight: 500; }
  .status-pill { display: inline-flex; align-items: center; gap: 7px; padding: 4px 10px 4px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 500; border: 1px solid var(--border); background: var(--surface-2); }
  .status-pill .dot { width: 6px; height: 6px; border-radius: 50%; }
  .status-pill.active { color: #86efac; border-color: rgba(34,197,94,0.25); background: rgba(34,197,94,0.08); }
  .status-pill.active .dot { background: #22c55e; box-shadow: 0 0 0 2px rgba(34,197,94,0.18); }
  .status-pill.hold { color: #fcd34d; border-color: rgba(245,158,11,0.25); background: rgba(245,158,11,0.08); }
  .status-pill.hold .dot { background: #f59e0b; }
  .status-pill.done { color: #93c5fd; border-color: rgba(59,130,246,0.25); background: rgba(59,130,246,0.08); }
  .status-pill.done .dot { background: #3b82f6; }
  .status-pill.archived { color: var(--text-dim); }
  .status-pill.archived .dot { background: var(--text-dim); }
  .assignees { display: flex; align-items: center; gap: 8px; }
  .av-stack { display: inline-flex; }
  .av { width: 26px; height: 26px; border-radius: 50%; border: 2px solid var(--bg); display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: #fff; margin-left: -8px; letter-spacing: 0.02em; flex-shrink: 0; }
  .av:first-child { margin-left: 0; }
  table.projects tbody tr:hover .av { border-color: var(--surface-hover); }
  table.projects tbody tr.selected .av { border-color: var(--bg); }
  .av-count { color: var(--text-muted); font-size: 12px; font-variant-numeric: tabular-nums; margin-left: 2px; }
  .av-zero { color: var(--text-dim); font-size: 12px; font-style: italic; }
  .last-activity { color: var(--text-muted); font-size: 12.5px; font-variant-numeric: tabular-nums; display: flex; align-items: center; gap: 8px; }
  .last-activity.recent { color: var(--text); }
  .activity-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 0 3px rgba(250,204,21,0.18); }
  .last-activity:not(.recent) .activity-dot { background: var(--text-dim); box-shadow: none; }
  .row-check { width: 16px; height: 16px; border-radius: 4px; border: 1.5px solid var(--border-strong); background: var(--bg); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.12s; flex-shrink: 0; }
  .row-check svg { width: 11px; height: 11px; color: #1a1400; opacity: 0; transition: opacity 0.12s; }
  .row-check.on { background: var(--accent); border-color: var(--accent); }
  .row-check.on svg { opacity: 1; }
  .row-actions { display: flex; align-items: center; gap: 4px; justify-content: flex-end; opacity: 0.6; transition: opacity 0.15s; }
  table.projects tbody tr:hover .row-actions { opacity: 1; }
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

  /* Modal */
  .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 100; display: none; align-items: flex-start; justify-content: center; padding: 60px 20px; overflow-y: auto; }
  .modal-overlay.open { display: flex; }
  .modal-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; width: 100%; max-width: 920px; overflow: hidden; }
  .modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
  .modal-title { font-size: 16px; font-weight: 600; color: var(--text); }
  .modal-body { padding: 20px; max-height: 70vh; overflow-y: auto; }
  .modal-foot { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; gap: 8px; justify-content: flex-end; background: var(--bg); }
  .modal-section { padding: 14px; border-radius: 10px; border: 1px solid var(--border); margin-top: 14px; }
  .modal-section h6 { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 10px; font-weight: 600; }
  .modal-grid { display: grid; gap: 12px; grid-template-columns: repeat(12, 1fr); }
  .modal-grid > * { display: flex; flex-direction: column; gap: 5px; }
  .c-12 { grid-column: span 12; } .c-6 { grid-column: span 6; } .c-4 { grid-column: span 4; } .c-3 { grid-column: span 3; } .c-2 { grid-column: span 2; }
  @media (max-width: 768px) { .c-6, .c-4, .c-3, .c-2 { grid-column: span 12; } }
  .icon-close { width: 30px; height: 30px; border-radius: 6px; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; transition: all 0.12s; }
  .icon-close:hover { background: var(--surface-2); color: var(--text); }

  /* Login repeater */
  .login-repeater { margin-top: 14px; }
  .login-repeater-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
  .login-repeater-head h6 { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.08em; font-weight: 600; margin: 0; }
  .login-row { position: relative; padding: 14px; border-radius: 10px; border: 1px solid var(--border); margin-bottom: 10px; }
  .login-row + .login-row { margin-top: 0; }
  .login-row-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; gap: 8px; }
  .login-row-index { font-size: 10.5px; color: var(--text-dim); font-family: 'JetBrains Mono', ui-monospace, monospace; text-transform: uppercase; letter-spacing: 0.1em; }
  .login-row-remove { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; color: var(--text-muted); padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border); background: transparent; cursor: pointer; transition: all 0.12s; }
  .login-row-remove:hover { color: #fca5a5; border-color: rgba(239,68,68,0.3); background: var(--danger-soft); }
  .login-row-remove svg { width: 11px; height: 11px; }
  .login-row.is-only .login-row-remove { display: none; }
  .login-add-row { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; font-size: 12.5px; font-weight: 500; color: var(--text); border-radius: 8px; border: 1px dashed var(--border-strong); background: transparent; cursor: pointer; transition: all 0.15s; }
  .login-add-row:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-soft); }
  .login-add-row svg { width: 13px; height: 13px; }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Projects</div>
      <div class="page-sub">Active engagements across all clients.</div>
    </div>
  </div>
  <div class="page-stats">
    <div class="stat"><span class="stat-num"><?= (int)$totalRows ?></span><span class="stat-label">Total</span></div>
    <div class="stat"><span class="stat-num" style="color:#86efac;"><?= (int)$statusCounts['active'] ?></span><span class="stat-label">Active</span></div>
    <div class="stat"><span class="stat-num" style="color:#fcd34d;"><?= (int)$statusCounts['hold'] ?></span><span class="stat-label">On Hold</span></div>
    <div class="stat"><span class="stat-num"><?= (int)$openTasksTotal ?></span><span class="stat-label">Open Tasks</span></div>
  </div>
</div>

<form class="toolbar" method="get">
  <div class="search-box">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
    <input name="q" value="<?= h($q) ?>" placeholder="Search projects or client…" autocomplete="off">
  </div>
  <select class="filter-select" name="status_id" onchange="this.form.submit()">
    <option value="0">All Status</option>
    <?php foreach ($statusOptions as $s): ?>
      <option value="<?= (int)$s['id'] ?>" <?= $statusFilter === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="filter-select" name="sort" onchange="this.form.submit()">
    <option value="activity" <?= $sort === 'activity' ? 'selected' : '' ?>>Last Activity</option>
    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Project Name</option>
    <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>Status</option>
  </select>
  <select class="filter-select" name="per_page" onchange="this.form.submit()" style="min-width:104px;">
    <?php foreach ($perPageOptions as $opt): ?>
      <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>>Show <?= $opt ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($canManage): ?>
    <button type="button" class="btn btn-primary save-flash" onclick="document.getElementById('newProject').classList.add('open')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
      New Project
    </button>
  <?php else: ?>
    <span></span>
  <?php endif; ?>
</form>

<?php if ($canManage): ?>
<form method="post" id="bulkDeleteProjects" class="bulk-bar" data-bulk-form>
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="bulk_delete_projects" value="1">
  <span class="bulk-msg">Select rows to perform bulk actions.</span>
  <div class="bulk-actions">
    <button type="submit" class="btn btn-danger" onclick="return confirm('This will permanently delete selected projects and all linked tasks/docs/phases. Are you sure?');">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path></svg>
      Delete <span class="selection-count" id="selCount">0</span>
    </button>
  </div>
</form>
<?php endif; ?>

<div class="table-wrap">
  <div class="table-card">
    <table class="projects">
      <thead>
        <tr>
          <?php if ($canManage): ?>
            <th style="width:44px;text-align:center;"><span class="row-check" id="checkAll"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></span></th>
          <?php endif; ?>
          <th>Project</th>
          <th>Client</th>
          <th>Status</th>
          <th>Assignees</th>
          <th>Last Activity</th>
          <th style="width:110px;text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$projects): ?>
        <tr><td colspan="<?= $canManage ? 7 : 6 ?>" style="padding: 32px 16px; text-align: center; color: var(--text-dim); font-style: italic;">No projects found.</td></tr>
      <?php else: foreach ($projects as $p):
        $statusCls = projects_status_pill_class((string)$p['status_name']);
        $assignees = array_values(array_filter(array_map('trim', explode(',', (string)($p['assignee_names'] ?? '')))));
        [$activityLabel, $isRecent] = projects_last_activity($p['last_activity']);
        $projGrad = projects_avatar_gradient((string)$p['name']);
        $clientGrad = projects_avatar_gradient((string)$p['client_name']);
      ?>
        <tr>
          <?php if ($canManage): ?>
            <td style="text-align:center;">
              <label class="row-check" data-row-check>
                <input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" form="bulkDeleteProjects" style="display:none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
              </label>
            </td>
          <?php endif; ?>
          <td>
            <div class="project-cell">
              <div class="project-icon" style="background:<?= h($projGrad) ?>"><?= h(user_initials((string)$p['name'])) ?></div>
              <div>
                <a class="project-name" href="project_view.php?id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?></a>
                <div class="project-sub">
                  <span class="project-id">PRJ-<?= str_pad((string)(int)$p['id'], 4, '0', STR_PAD_LEFT) ?></span>
                  <?php if (!empty($p['type_name'])): ?><span class="dot-sep"></span><span><?= h($p['type_name']) ?></span><?php endif; ?>
                </div>
              </div>
            </div>
          </td>
          <td>
            <div class="client-mini">
              <div class="client-mini-logo" style="background:<?= h($clientGrad) ?>"><?= h(user_initials((string)$p['client_name'])) ?></div>
              <span class="client-mini-name"><?= h($p['client_name']) ?></span>
            </div>
          </td>
          <td><span class="status-pill <?= $statusCls ?>"><span class="dot"></span><?= h($p['status_name']) ?></span></td>
          <td>
            <?php if ($assignees): ?>
              <div class="assignees">
                <div class="av-stack">
                  <?php foreach (array_slice($assignees, 0, 3) as $person):
                    $g = projects_avatar_gradient($person);
                  ?>
                    <div class="av" style="background:<?= h($g) ?>" title="<?= h($person) ?>"><?= h(user_initials($person)) ?></div>
                  <?php endforeach; ?>
                </div>
                <?php $extra = max(0, count($assignees) - 3); ?>
                <span class="av-count">+<?= $extra ?></span>
              </div>
            <?php else: ?>
              <span class="av-zero">Unassigned</span>
            <?php endif; ?>
          </td>
          <td><span class="last-activity <?= $isRecent ? 'recent' : '' ?>"><span class="activity-dot"></span><?= h($activityLabel) ?></span></td>
          <td>
            <div class="row-actions">
              <a class="icon-btn-sq" href="project_view.php?id=<?= (int)$p['id'] ?>" title="View">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
              </a>
              <?php if ($canManage): ?>
              <a class="icon-btn-sq" href="project_view.php?id=<?= (int)$p['id'] ?>#edit" title="Edit">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
              </a>
              <form method="post" style="display:inline" onsubmit="return confirm('This will permanently delete this project and all linked tasks/docs/phases. Are you sure?');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="delete_project" value="1">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
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
      <span class="page-info">Showing <b><?= count($projects) ? (($page - 1) * $perPage + 1) . '–' . (($page - 1) * $perPage + count($projects)) : '0' ?></b> of <b><?= (int)$totalRows ?></b> projects</span>
      <div class="page-controls">
        <?php $qsBase = http_build_query(['q' => $q, 'status_id' => $statusFilter, 'sort' => $sort, 'per_page' => $perPage]); ?>
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

<?php if ($canManage): ?>
<div class="modal-overlay" id="newProject" onclick="if(event.target===this) this.classList.remove('open')">
  <div class="modal-card" role="dialog" aria-label="New Project">
    <div class="modal-head">
      <span class="modal-title">New Project</span>
      <button type="button" class="icon-close" onclick="document.getElementById('newProject').classList.remove('open')" aria-label="Close">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
      </button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="create_project" value="1">
      <div class="modal-body">
        <div class="modal-grid">
          <div class="c-4"><label class="field-label">Project Name</label><input class="input" name="name" placeholder="Untitled Project"></div>
          <div class="c-4"><label class="field-label">Client</label><select class="select" name="client_id"><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
          <div class="c-2"><label class="field-label">Type</label><select class="select" name="type_id"><?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select></div>
          <div class="c-2"><label class="field-label">Status</label><select class="select" name="status_id"><?php foreach ($statusOptions as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?></select></div>
          <div class="c-4"><label class="field-label">Due Date (optional)</label><input class="input" type="date" name="due_date"></div>
          <div class="c-4"><label class="field-label">Pricing Model</label><select class="select" name="pricing_model" id="project_pricing_model"><option value="fixed_price">Fixed Price</option><option value="hourly">Hourly</option></select></div>
          <div class="c-4" id="project_price_wrap"><label class="field-label">Project Price</label><input class="input" name="project_price" type="number" min="0" step="0.01"></div>
          <div class="c-4" id="project_terms_wrap"><label class="field-label">Payment Terms</label><select class="select" name="payment_terms"><option value="full_upfront">Full Upfront</option><option value="50_50">50/50</option><option value="milestones">Milestones</option></select></div>
          <div class="c-4" id="project_hourly_wrap" style="display:none;"><label class="field-label">Hourly Rate</label><input class="input" name="project_hourly_rate" type="number" min="0" step="0.01"></div>
        </div>

        <div class="login-repeater" id="loginRepeater">
          <div class="login-repeater-head">
            <h6>Website Logins (optional)</h6>
          </div>
          <div class="login-rows" id="loginRows">
            <!-- Initial row -->
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

        <!-- Template for new rows (cloned by JS) -->
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

        <div class="modal-grid" style="margin-top:14px;">
          <div class="c-12"><label class="field-label">Notes</label><textarea class="input" name="wl_notes" rows="2" placeholder="2FA notes, etc."></textarea></div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('newProject').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary save-flash">Create</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  // Pricing model toggle
  const model = document.getElementById('project_pricing_model');
  if (model) {
    const fixed = document.getElementById('project_price_wrap');
    const terms = document.getElementById('project_terms_wrap');
    const hourly = document.getElementById('project_hourly_wrap');
    function sync() {
      const v = model.value;
      fixed.style.display = v === 'fixed_price' ? '' : 'none';
      terms.style.display = v === 'fixed_price' ? '' : 'none';
      hourly.style.display = v === 'hourly' ? '' : 'none';
    }
    model.addEventListener('change', sync);
    sync();
  }

  // Login repeater (Add Row / Remove / re-index)
  const rowsWrap = document.getElementById('loginRows');
  const addBtn   = document.getElementById('loginAddRow');
  const tmpl     = document.getElementById('loginRowTemplate');
  if (rowsWrap && addBtn && tmpl) {
    function reindex() {
      const rows = rowsWrap.querySelectorAll('.login-row');
      rows.forEach((row, idx) => {
        row.classList.toggle('is-only', rows.length === 1);
        const lbl = row.querySelector('.login-row-index');
        if (lbl) lbl.textContent = 'Login #' + (idx + 1);
        row.querySelectorAll('[name^="logins["]').forEach(input => {
          // Rewrite the array index to the row's current position so post-back is sequential.
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
      const remaining = rowsWrap.querySelectorAll('.login-row').length;
      if (remaining <= 1) {
        // Don't remove the only row — just clear its values.
        row.querySelectorAll('input').forEach(i => i.value = '');
        return;
      }
      row.remove();
      reindex();
    });
    reindex();
  }

  // Row checkboxes
  const bulkForm = document.getElementById('bulkDeleteProjects');
  if (bulkForm) {
    const bulkBar = bulkForm;
    const selCount = document.getElementById('selCount');
    function refreshBulk() {
      const selected = document.querySelectorAll('[data-row-check] input:checked').length;
      bulkBar.classList.toggle('has-selection', selected > 0);
      if (selCount) selCount.textContent = selected;
      const msg = bulkBar.querySelector('.bulk-msg');
      if (msg) msg.textContent = selected > 0 ? (selected + ' project' + (selected === 1 ? '' : 's') + ' selected') : 'Select rows to perform bulk actions.';
      const total = document.querySelectorAll('[data-row-check]').length;
      const all = document.getElementById('checkAll');
      if (all) all.classList.toggle('on', selected === total && total > 0);
    }
    document.querySelectorAll('[data-row-check]').forEach(label => {
      const cb = label.querySelector('input[type=checkbox]');
      label.addEventListener('click', (e) => {
        // Allow native default for the hidden input; toggle visual
        setTimeout(() => {
          label.classList.toggle('on', cb.checked);
          label.closest('tr').classList.toggle('selected', cb.checked);
          refreshBulk();
        }, 0);
      });
    });
    const checkAll = document.getElementById('checkAll');
    if (checkAll) {
      checkAll.addEventListener('click', () => {
        const turnOn = !checkAll.classList.contains('on');
        document.querySelectorAll('[data-row-check]').forEach(label => {
          const cb = label.querySelector('input[type=checkbox]');
          cb.checked = turnOn;
          label.classList.toggle('on', turnOn);
          label.closest('tr').classList.toggle('selected', turnOn);
        });
        refreshBulk();
      });
    }
  }
})();
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
