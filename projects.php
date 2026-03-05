<?php
require_once __DIR__ . '/layout.php';

$pdo = db();
$ws = auth_workspace_id();
$user = auth_user();
$role = $user['role_name'] ?? '';
$canManage = in_array($role, ['CEO', 'Manager', 'Super Admin'], true);


function projects_has_live_url_col(PDO $pdo): bool {
  try {
    $st = $pdo->query("SHOW COLUMNS FROM projects LIKE 'live_website_url'");
    return (bool)$st->fetch();
  } catch (Throwable $e) {
    return false;
  }
}

if (!projects_has_live_url_col($pdo)) {
  try { $pdo->exec("ALTER TABLE projects ADD COLUMN live_website_url VARCHAR(255) NULL AFTER due_date"); } catch (Throwable $e) {}
}

try {
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
} catch (Throwable $e) {}

$isSuperAdmin = $role === 'Super Admin';

$projects = [];
$statusOptions = [];
$clients = [];
$types = [];
$totalRows = 0;
$totalPages = 1;
$loadError = null;

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (int)($_GET['status_id'] ?? 0);
$sort = strtolower(trim((string)($_GET['sort'] ?? 'activity')));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

function project_badge_class(string $status): string {
  $value = strtolower($status);
  if (strpos($value, 'complete') !== false || strpos($value, 'done') !== false || strpos($value, 'closed') !== false) return 'is-complete';
  if (strpos($value, 'hold') !== false || strpos($value, 'pause') !== false) return 'is-paused';
  return 'is-progress';
}

function project_last_activity_label(?string $date): string {
  if (!$date) return '—';
  $ts = strtotime($date);
  if (!$ts) return '—';
  $delta = time() - $ts;
  if ($delta < 86400) return 'Today';
  if ($delta < 172800) return 'Yesterday';
  if ($delta < 1209600) return (int)floor($delta / 86400) . ' days ago';
  return date('M d', $ts);
}

function project_initials(string $name): string {
  $trimmed = trim($name);
  if ($trimmed === '') return 'NA';
  $parts = preg_split('/\s+/', $trimmed);
  $left = strtoupper(substr($parts[0] ?? '', 0, 1));
  $right = strtoupper(substr($parts[1] ?? '', 0, 1));
  return trim($left . $right) ?: 'NA';
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project'])) {
    require_post();
    csrf_verify();
    if (!$canManage) {
      flash_set('error', 'No permission.');
      redirect('projects.php');
    }
    $deleteId = (int)($_POST['id'] ?? 0);
    if ($deleteId <= 0) {
      flash_set('error', 'Invalid project selected.');
      redirect('projects.php');
    }
    $st = $pdo->prepare('DELETE FROM projects WHERE workspace_id = ? AND id = ?');
    $st->execute([$ws, $deleteId]);
    flash_set('success', $st->rowCount() ? 'Project deleted permanently.' : 'Project not found.');
    redirect('projects.php');
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_projects'])) {
    require_post();
    csrf_verify();
    if (!$canManage) {
      flash_set('error', 'No permission.');
      redirect('projects.php');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    if (!$ids) {
      flash_set('error', 'Select at least one project to delete.');
      redirect('projects.php');
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("DELETE FROM projects WHERE workspace_id = ? AND id IN ($marks)");
    $st->execute(array_merge([$ws], $ids));
    flash_set('success', $st->rowCount() . ' project(s) deleted permanently.');
    redirect('projects.php');
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
    require_post();
    csrf_verify();
    if (!$canManage) {
      flash_set('error', 'No permission.');
      redirect('projects.php');
    }

    $name = trim($_POST['name'] ?? '');
    $clientId = (int)($_POST['client_id'] ?? 0);
    $typeId = (int)($_POST['type_id'] ?? 0);
    $statusId = (int)($_POST['status_id'] ?? 0);
    $dueDate = trim((string)($_POST['due_date'] ?? ''));
    $liveWebsiteUrl = trim((string)($_POST['live_website_url'] ?? ''));
    $wlSiteName = trim((string)($_POST['wl_site_name'] ?? ''));
    $wlLoginUrl = trim((string)($_POST['wl_login_url'] ?? ''));
    $wlUsername = trim((string)($_POST['wl_login_username'] ?? ''));
    $wlPassword = (string)($_POST['wl_login_password'] ?? '');
    $wlNotes = trim((string)($_POST['wl_notes'] ?? ''));

    if ($name === '' || $clientId <= 0 || $typeId <= 0 || $statusId <= 0) {
      flash_set('error', 'Project name, client, type and status are required.');
      redirect('projects.php');
    }

    $pdo->prepare('INSERT INTO projects (workspace_id, client_id, name, type_id, status_id, due_date, live_website_url, notes, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$ws, $clientId, $name, $typeId, $statusId, $dueDate ?: null, $liveWebsiteUrl ?: null, null, now(), now()]);

    $newProjectId = (int)$pdo->lastInsertId();
    if ($wlSiteName !== '' || $wlUsername !== '' || $wlPassword !== '' || $wlLoginUrl !== '') {
      $siteName = $wlSiteName !== '' ? $wlSiteName : $name;
      $pdo->prepare('INSERT INTO website_logins (workspace_id,client_id,project_id,site_name,website_url,login_url,login_username,login_password,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$ws, $clientId, $newProjectId, $siteName, $liveWebsiteUrl ?: null, $wlLoginUrl ?: null, $wlUsername ?: null, $wlPassword ?: null, $wlNotes ?: null, (int)($user['id'] ?? 0), now(), now()]);
    }

    flash_set('success', 'Project created.');
    redirect('projects.php');
  }

  $allowedSorts = ['activity', 'name', 'status'];
  if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'activity';
  }

  $sortSql = 'last_activity DESC, p.id DESC';
  if ($sort === 'name') {
    $sortSql = 'p.name ASC, p.id DESC';
  } elseif ($sort === 'status') {
    $sortSql = 'ps.name ASC, p.id DESC';
  }

  $where = ' WHERE p.workspace_id = :ws AND (p.name LIKE :q_project OR c.name LIKE :q_client) ';
  $params = [
    ':ws' => $ws,
    ':q_project' => '%' . $q . '%',
    ':q_client' => '%' . $q . '%',
  ];
  if ($statusFilter > 0) {
    $where .= ' AND p.status_id = :status_id ';
    $params[':status_id'] = $statusFilter;
  }

  $countSql = 'SELECT COUNT(*) FROM projects p JOIN clients c ON c.id = p.client_id ' . $where;
  $countStmt = $pdo->prepare($countSql);
  $countStmt->execute($params);
  $totalRows = (int)$countStmt->fetchColumn();
  $totalPages = max(1, (int)ceil($totalRows / $perPage));
  if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
  }

  $listSql = <<<SQL
SELECT
  p.id,
  p.name,
  p.created_at,
  p.updated_at,
  c.name AS client_name,
  ps.name AS status_name,
  (
    SELECT COUNT(DISTINCT ta.user_id)
    FROM tasks t
    LEFT JOIN task_assignees ta ON ta.task_id = t.id
    WHERE t.workspace_id = p.workspace_id AND t.project_id = p.id
  ) AS assignee_count,
  (
    SELECT GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ')
    FROM tasks t
    LEFT JOIN task_assignees ta ON ta.task_id = t.id
    LEFT JOIN users u ON u.id = ta.user_id
    WHERE t.workspace_id = p.workspace_id AND t.project_id = p.id
  ) AS assignee_names,
  GREATEST(
    COALESCE(p.updated_at, '1000-01-01 00:00:00'),
    COALESCE((SELECT MAX(t.updated_at) FROM tasks t WHERE t.workspace_id = p.workspace_id AND t.project_id = p.id), '1000-01-01 00:00:00'),
    COALESCE((SELECT MAX(d.updated_at) FROM docs d WHERE d.workspace_id = p.workspace_id AND d.project_id = p.id), '1000-01-01 00:00:00')
  ) AS last_activity
FROM projects p
JOIN clients c ON c.id = p.client_id
JOIN project_statuses ps ON ps.id = p.status_id
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

  $statusOptionsStmt = $pdo->prepare('SELECT id, name FROM project_statuses WHERE workspace_id = ? ORDER BY sort_order ASC, id ASC');
  $statusOptionsStmt->execute([$ws]);
  $statusOptions = $statusOptionsStmt->fetchAll();

  $clientsStmt = $pdo->prepare('SELECT id, name FROM clients WHERE workspace_id = ? ORDER BY name ASC');
  $clientsStmt->execute([$ws]);
  $clients = $clientsStmt->fetchAll();

  $typesStmt = $pdo->prepare('SELECT id, name FROM project_types WHERE workspace_id = ? ORDER BY sort_order ASC, id ASC');
  $typesStmt->execute([$ws]);
  $types = $typesStmt->fetchAll();
} catch (Throwable $e) {
  $loadError = 'Projects page failed to load data. Please refresh or contact admin.';
  if ($isSuperAdmin) {
    $loadError .= ' Debug: ' . $e->getMessage();
  }
}
?>

<style>
  .projects-shell { border: 1px solid rgba(255,255,255,.08); border-radius: 18px; background: linear-gradient(150deg, rgba(13,13,13,.97), rgba(10,10,10,.96)); box-shadow: 0 24px 58px rgba(0,0,0,.42); padding: 16px; }
  .projects-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 12px; }
  .projects-title { margin: 0; font-size: 2rem; font-weight: 600; }
  .projects-toolbar { display: grid; grid-template-columns: minmax(260px, 1fr) auto auto; gap: 10px; margin-bottom: 12px; }
  .tool-search-wrap { position: relative; }
  .tool-search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #f3cb58; }
  .tool-search { width: 100%; padding: 10px 12px 10px 36px; border-radius: 10px; border: 1px solid rgba(255,255,255,.09); background: rgba(255,255,255,.03); color: #f2f2f5; }
  .tool-select { padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(255,255,255,.09); background: rgba(255,255,255,.04); color: #eceef5; min-width: 132px; }
  .tool-select:focus, .tool-search:focus { outline: none; border-color: rgba(255,212,83,.5); box-shadow: 0 0 0 3px rgba(255,212,83,.12); }

  .projects-card { border: 1px solid rgba(255,255,255,.08); border-radius: 12px; overflow: hidden; background: linear-gradient(160deg, rgba(24,24,24,.68), rgba(16,16,16,.58)); }
  .projects-table { width: 100%; border-collapse: collapse; }
  .projects-table th, .projects-table td { border-top: 1px solid rgba(255,255,255,.07); padding: 12px 14px; vertical-align: middle; }
  .projects-table th { font-size: .86rem; text-transform: uppercase; letter-spacing: .5px; color: rgba(255,255,255,.64); font-weight: 600; }
  .name-link { color: #f2f3f9; font-size: 1.05rem; font-weight: 600; text-decoration: none; line-height: 1.1; }
  .sub-client { color: rgba(255,255,255,.56); margin-top: 2px; font-size: .92rem; }
  .badge-status { display: inline-flex; align-items: center; border-radius: 8px; padding: 2px 10px; font-size: .83rem; font-weight: 600; }
  .badge-status.is-progress { color: #f1d26f; background: rgba(164,133,46,.22); border: 1px solid rgba(225,184,67,.4); }
  .badge-status.is-complete { color: #d0d4df; background: rgba(91,96,111,.28); border: 1px solid rgba(149,156,173,.36); }
  .badge-status.is-paused { color: #c2c8d5; background: rgba(94,101,116,.3); border: 1px solid rgba(152,160,175,.35); }

  .avatars { display: inline-flex; align-items: center; }
  .avatar { width: 28px; height: 28px; border-radius: 50%; background: linear-gradient(140deg, #ded8c8, #a89d86); color: #2e2a20; display: inline-flex; align-items: center; justify-content: center; font-size: .69rem; font-weight: 700; border: 1px solid rgba(255,255,255,.18); margin-right: -6px; }
  .assignee-count { margin-left: 10px; color: rgba(255,255,255,.72); }

  .foot { border-top: 1px solid rgba(255,255,255,.08); display: flex; justify-content: space-between; align-items: center; padding: 12px 14px; color: rgba(255,255,255,.66); }
  .foot a { color: rgba(255,255,255,.8); text-decoration: none; margin-right: 10px; }

  @media (max-width: 1080px) { .projects-toolbar { grid-template-columns: 1fr; } .projects-table { min-width: 980px; } .projects-scroll { overflow-x: auto; } }
</style>

<section class="projects-shell">
  <header class="projects-head">
    <h1 class="projects-title">Projects</h1>
    <?php if ($canManage): ?><button class="btn btn-yellow" data-bs-toggle="modal" data-bs-target="#newProject">＋ New Project</button><?php endif; ?>
  </header>

  <?php if ($loadError): ?>
    <div class="alert alert-danger"><?=h($loadError)?></div>
  <?php endif; ?>

  <form class="projects-toolbar" method="get">
    <div class="tool-search-wrap">
      <span class="tool-search-icon">⌕</span>
      <input class="tool-search" name="q" value="<?=h($q)?>" placeholder="Search projects or client..." autocomplete="off">
    </div>
    <select class="tool-select" name="status_id" onchange="this.form.submit()">
      <option value="0">All Status</option>
      <?php foreach ($statusOptions as $status): ?>
        <option value="<?=h($status['id'])?>" <?=$statusFilter === (int)$status['id'] ? 'selected' : ''?>><?=h($status['name'])?></option>
      <?php endforeach; ?>
    </select>
    <select class="tool-select" name="sort" onchange="this.form.submit()">
      <option value="activity" <?=$sort === 'activity' ? 'selected' : ''?>>Last Activity</option>
      <option value="name" <?=$sort === 'name' ? 'selected' : ''?>>Project Name</option>
      <option value="status" <?=$sort === 'status' ? 'selected' : ''?>>Status</option>
    </select>
  </form>

  <?php if($canManage): ?><form method="post" id="bulkDeleteProjects" class="mb-2"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="bulk_delete_projects" value="1"><button class="btn btn-sm btn-outline-danger" onclick="return confirm('This will permanently delete selected projects and all linked tasks/docs/phases. Are you sure?');">Delete Selected</button></form><?php endif; ?>
  <section class="projects-card">
    <div class="projects-scroll">
      <table class="projects-table">
        <thead>
          <tr><th class="text-center" style="width:44px;"><?php if($canManage): ?><input type="checkbox" onclick="document.querySelectorAll('.project-check').forEach(cb=>cb.checked=this.checked)"><?php endif; ?></th><th>Project</th><th>Client</th><th>Status</th><th>Assignees</th><th>Last Activity</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if (!$projects): ?>
            <tr><td colspan="7" class="text-muted">No projects found.</td></tr>
          <?php else: foreach ($projects as $p):
            $badge = project_badge_class((string)$p['status_name']);
            $assignees = array_values(array_filter(array_map('trim', explode(',', (string)($p['assignee_names'] ?? '')))));
          ?>
            <tr>
              <td class="text-center"><?php if($canManage): ?><input class="project-check" type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" form="bulkDeleteProjects"><?php endif; ?></td>
              <td>
                <a class="name-link" href="project_view.php?id=<?=h($p['id'])?>"><?=h($p['name'])?></a>
                <div class="sub-client"><?=h($p['client_name'])?></div>
              </td>
              <td><?=h($p['client_name'])?></td>
              <td><span class="badge-status <?=h($badge)?>"><?=h($p['status_name'])?></span></td>
              <td>
                <span class="avatars">
                  <?php foreach (array_slice($assignees, 0, 3) as $person): ?><span class="avatar" title="<?=h($person)?>"><?=h(project_initials($person))?></span><?php endforeach; ?>
                </span>
                <span class="assignee-count"><?= (int)$p['assignee_count'] ?></span>
              </td>
              <td><?=h(project_last_activity_label($p['last_activity']))?></td>
              <td class="text-end"><?php if($canManage): ?><form method="post" style="display:inline" onsubmit="return confirm('This will permanently delete this project and all linked tasks/docs/phases. Are you sure?');"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="delete_project" value="1"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form><?php endif; ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <footer class="foot">
      <div>
        <?php if ($page > 1): ?><a href="projects.php?q=<?=urlencode($q)?>&status_id=<?=$statusFilter?>&sort=<?=urlencode($sort)?>&page=<?=$page - 1?>">Previous</a><?php else: ?><span class="opacity-50">Previous</span><?php endif; ?>
        <?php if ($page < $totalPages): ?><a href="projects.php?q=<?=urlencode($q)?>&status_id=<?=$statusFilter?>&sort=<?=urlencode($sort)?>&page=<?=$page + 1?>">Next</a><?php else: ?><span class="opacity-50 ms-2">Next</span><?php endif; ?>
      </div>
      <div>Showing <?=count($projects)?> of <?=$totalRows?></div>
    </footer>
  </section>
</section>

<?php if ($canManage): ?>
<div class="modal fade" id="newProject" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content card p-3">
      <div class="modal-header border-0"><h5 class="modal-title">New Project</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="create_project" value="1">
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Project Name</label><input class="form-control" name="name" required></div>
          <div class="mb-3"><label class="form-label">Client</label><select class="form-select" name="client_id" required><?php foreach($clients as $client): ?><option value="<?=h($client['id'])?>"><?=h($client['name'])?></option><?php endforeach; ?></select></div>
          <div class="mb-3"><label class="form-label">Type</label><select class="form-select" name="type_id" required><?php foreach($types as $type): ?><option value="<?=h($type['id'])?>"><?=h($type['name'])?></option><?php endforeach; ?></select></div>
          <div class="mb-3"><label class="form-label">Status</label><select class="form-select" name="status_id" required><?php foreach($statusOptions as $status): ?><option value="<?=h($status['id'])?>"><?=h($status['name'])?></option><?php endforeach; ?></select></div>
          <div class="mb-3"><label class="form-label">Due Date (optional)</label><input class="form-control" type="date" name="due_date"></div>
          <div class="mb-3"><label class="form-label">Current Live Website URL (optional)</label><input class="form-control" name="live_website_url" placeholder="https://example.com"></div>
          <hr>
          <h6 class="mb-2">Website Login (optional)</h6>
          <div class="mb-3"><label class="form-label">Website Name</label><input class="form-control" name="wl_site_name" placeholder="Main website"></div>
          <div class="mb-3"><label class="form-label">Login URL</label><input class="form-control" name="wl_login_url" placeholder="https://example.com/wp-admin"></div>
          <div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="wl_login_username"></div>
          <div class="mb-3"><label class="form-label">Password</label><input class="form-control" type="password" name="wl_login_password"></div>
          <div class="mb-3"><label class="form-label">Notes</label><textarea class="form-control" name="wl_notes" rows="2" placeholder="2FA notes etc."></textarea></div>
        </div>
        <div class="modal-footer border-0"><button class="btn btn-outline-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-yellow" type="submit">Create</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/layout_end.php'; ?>
