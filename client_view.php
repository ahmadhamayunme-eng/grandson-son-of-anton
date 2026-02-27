<?php
require_once __DIR__ . '/layout.php';

$pdo = db();
$ws = auth_workspace_id();
$user = auth_user();
$role = $user['role_name'] ?? '';
$can_manage = in_array($role, ['CEO', 'CTO', 'Super Admin'], true);

$id = (int)($_GET['id'] ?? 0);
$tab = strtolower(trim((string)($_GET['tab'] ?? 'overview')));
if (!in_array($tab, ['overview', 'projects', 'docs'], true)) {
  $tab = 'overview';
}

$clientStmt = $pdo->prepare('SELECT * FROM clients WHERE id = ? AND workspace_id = ?');
$clientStmt->execute([$id, $ws]);
$client = $clientStmt->fetch();
if (!$client) {
  echo '<h3>Client not found</h3>';
  require __DIR__ . '/layout_end.php';
  exit;
}

$typeStmt = $pdo->prepare('SELECT id, name FROM project_types WHERE workspace_id = ? ORDER BY sort_order ASC');
$typeStmt->execute([$ws]);
$types = $typeStmt->fetchAll();

$statusStmt = $pdo->prepare('SELECT id, name FROM project_statuses WHERE workspace_id = ? ORDER BY sort_order ASC');
$statusStmt->execute([$ws]);
$statuses = $statusStmt->fetchAll();

$clientProjectsStmt = $pdo->prepare('SELECT id, name FROM projects WHERE workspace_id = ? AND client_id = ? ORDER BY id DESC');
$clientProjectsStmt->execute([$ws, $id]);
$clientProjects = $clientProjectsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
  require_post();
  csrf_verify();

  if (!$can_manage) {
    flash_set('error', 'No permission.');
    redirect("client_view.php?id=$id&tab=$tab");
  }

  $name = trim($_POST['name'] ?? '');
  $type_id = (int)($_POST['type_id'] ?? 0);
  $status_id = (int)($_POST['status_id'] ?? 0);

  if ($name === '') {
    flash_set('error', 'Project name required.');
    redirect("client_view.php?id=$id&tab=$tab");
  }

  $pdo->prepare('INSERT INTO projects (workspace_id,client_id,name,type_id,status_id,due_date,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
      ->execute([$ws, $id, $name, $type_id, $status_id, $_POST['due_date'] ?: null, null, now(), now()]);

  flash_set('success', 'Project created.');
  redirect("client_view.php?id=$id&tab=$tab");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_doc'])) {
  require_post();
  csrf_verify();

  if (!$can_manage) {
    flash_set('error', 'No permission.');
    redirect("client_view.php?id=$id&tab=docs");
  }

  $title = trim($_POST['doc_title'] ?? '');
  $content = trim($_POST['doc_content'] ?? '');
  $projectId = (int)($_POST['doc_project_id'] ?? 0);

  $projectGuard = $pdo->prepare('SELECT id FROM projects WHERE id = ? AND client_id = ? AND workspace_id = ?');
  $projectGuard->execute([$projectId, $id, $ws]);
  $validProjectId = (int)($projectGuard->fetchColumn() ?: 0);

  if ($title === '' || $validProjectId <= 0) {
    flash_set('error', 'Document title and valid project are required.');
    redirect("client_view.php?id=$id&tab=docs");
  }

  $pdo->prepare('INSERT INTO docs (workspace_id, project_id, title, content, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')
      ->execute([$ws, $validProjectId, $title, $content, (int)($user['id'] ?? 0), now(), now()]);

  flash_set('success', 'Doc created.');
  redirect("client_view.php?id=$id&tab=docs");
}

$projectsStmt = $pdo->prepare(<<<SQL
SELECT
  p.*,
  pt.name AS type_name,
  ps.name AS status_name,
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

$recentDocsStmt = $pdo->prepare(<<<SQL
SELECT d.id, d.title, d.updated_at, u.name AS owner_name
FROM docs d
JOIN projects p ON p.id = d.project_id AND p.workspace_id = d.workspace_id
LEFT JOIN users u ON u.id = d.created_by
WHERE d.workspace_id = ? AND p.client_id = ?
ORDER BY d.updated_at DESC, d.id DESC
LIMIT 4
SQL
);
$recentDocsStmt->execute([$ws, $id]);
$recentDocs = $recentDocsStmt->fetchAll();

$recentTasksStmt = $pdo->prepare(<<<SQL
SELECT t.id, t.title, t.status, t.due_date, p.name AS project_name
FROM tasks t
JOIN projects p ON p.id = t.project_id AND p.workspace_id = t.workspace_id
WHERE t.workspace_id = ? AND p.client_id = ?
ORDER BY COALESCE(t.due_date, DATE(t.updated_at)) ASC, t.id DESC
LIMIT 5
SQL
);
$recentTasksStmt->execute([$ws, $id]);
$recentTasks = $recentTasksStmt->fetchAll();

$projectActivity = array_slice($projects, 0, 4);

$projectTypeTags = [];
foreach ($projects as $projectRow) {
  $typeName = trim((string)($projectRow['type_name'] ?? ''));
  if ($typeName !== '') {
    $projectTypeTags[$typeName] = true;
  }
}
$projectTypeTags = array_keys($projectTypeTags);

$docQ = trim((string)($_GET['dq'] ?? ''));
$docPage = max(1, (int)($_GET['dp'] ?? 1));
$docPerPage = 10;
$docOffset = ($docPage - 1) * $docPerPage;
$docLike = '%' . $docQ . '%';

$docsCountStmt = $pdo->prepare(<<<SQL
SELECT COUNT(*)
FROM docs d
JOIN projects p ON p.id = d.project_id AND p.workspace_id = d.workspace_id
WHERE d.workspace_id = ? AND p.client_id = ? AND (d.title LIKE ? OR p.name LIKE ?)
SQL
);
$docsCountStmt->execute([$ws, $id, $docLike, $docLike]);
$docsTotalRows = (int)$docsCountStmt->fetchColumn();
$docsTotalPages = max(1, (int)ceil($docsTotalRows / $docPerPage));
if ($docPage > $docsTotalPages) {
  $docPage = $docsTotalPages;
  $docOffset = ($docPage - 1) * $docPerPage;
}

$docsListStmt = $pdo->prepare(<<<SQL
SELECT d.id, d.title, d.updated_at, d.project_id, p.name AS project_name, u.name AS author_name
FROM docs d
JOIN projects p ON p.id = d.project_id AND p.workspace_id = d.workspace_id
LEFT JOIN users u ON u.id = d.created_by
WHERE d.workspace_id = ? AND p.client_id = ? AND (d.title LIKE ? OR p.name LIKE ?)
ORDER BY d.updated_at DESC, d.id DESC
LIMIT ? OFFSET ?
SQL
);
$docsListStmt->bindValue(1, $ws, PDO::PARAM_INT);
$docsListStmt->bindValue(2, $id, PDO::PARAM_INT);
$docsListStmt->bindValue(3, $docLike, PDO::PARAM_STR);
$docsListStmt->bindValue(4, $docLike, PDO::PARAM_STR);
$docsListStmt->bindValue(5, $docPerPage, PDO::PARAM_INT);
$docsListStmt->bindValue(6, $docOffset, PDO::PARAM_INT);
$docsListStmt->execute();
$docsRows = $docsListStmt->fetchAll();

function project_status_class(string $status): string {
  $s = strtolower($status);
  if (strpos($s, 'complete') !== false || strpos($s, 'done') !== false || strpos($s, 'closed') !== false) {
    return 'is-complete';
  }
  if (strpos($s, 'hold') !== false || strpos($s, 'pause') !== false) {
    return 'is-paused';
  }
  return 'is-progress';
}

function task_status_class(string $status): string {
  $s = strtolower($status);
  if (strpos($s, 'approve') !== false || strpos($s, 'submit') !== false || strpos($s, 'done') !== false || strpos($s, 'complete') !== false) {
    return 'is-complete';
  }
  if (strpos($s, 'progress') !== false || strpos($s, 'review') !== false) {
    return 'is-progress';
  }
  return 'is-paused';
}

function initials_from_names(string $names): string {
  $first = trim(explode(',', $names)[0] ?? '');
  if ($first === '') {
    return 'NA';
  }
  $parts = preg_split('/\s+/', $first);
  $left = strtoupper(substr($parts[0] ?? '', 0, 1));
  $right = strtoupper(substr($parts[1] ?? '', 0, 1));
  return trim($left . $right) ?: 'NA';
}
?>

<style>
  .client-shell { border: 1px solid rgba(255, 255, 255, .08); border-radius: 18px; background: linear-gradient(155deg, rgba(15, 16, 24, .96), rgba(10, 11, 18, .95)); box-shadow: 0 24px 64px rgba(0, 0, 0, .42); overflow: hidden; }
  .client-head { display: flex; align-items: start; justify-content: space-between; gap: 16px; padding: 18px 20px 14px; border-bottom: 1px solid rgba(255, 255, 255, .07); }
  .client-title-row { display: flex; align-items: center; gap: 12px; }
  .client-icon { width: 36px; height: 36px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; border: 1px solid rgba(244, 205, 92, .68); color: #f4cd5c; font-size: 18px; box-shadow: inset 0 0 0 1px rgba(244, 205, 92, .24); }
  .client-name { font-size: 2rem; margin: 0; font-weight: 500; }
  .client-badge { margin-top: 8px; display: inline-flex; align-items: center; gap: 6px; background: rgba(102, 83, 211, .18); color: #d2c7ff; border: 1px solid rgba(159, 139, 255, .25); border-radius: 8px; padding: 4px 9px; font-size: .83rem; }
  .client-tabs { display: flex; gap: 24px; padding: 0 20px; border-bottom: 1px solid rgba(255, 255, 255, .07); }
  .client-tab { color: rgba(255, 255, 255, .76); text-decoration: none; padding: 10px 0; display: inline-block; border-bottom: 2px solid transparent; font-weight: 500; }
  .client-tab.active { color: #f3ca56; border-color: rgba(243, 202, 86, .85); }

  .overview-grid { display: grid; grid-template-columns: 1.06fr .94fr; gap: 16px; padding: 18px; }
  .stack { display: grid; gap: 14px; }
  .glass-card { border: 1px solid rgba(255, 255, 255, .08); border-radius: 12px; background: linear-gradient(160deg, rgba(28, 29, 40, .68), rgba(18, 19, 27, .58)); overflow: hidden; }
  .card-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,.07); }
  .card-title { margin: 0; font-size: 1.35rem; font-weight: 600; }
  .tiny-cta { color: rgba(255, 255, 255, .78); text-decoration: none; border: 1px solid rgba(255,255,255,.15); border-radius: 6px; padding: 2px 8px; font-size: .88rem; }

  .overview-body { padding: 12px 14px; }
  .type-tags { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
  .type-tags span { display: inline-flex; border-radius: 7px; padding: 4px 10px; font-size: .84rem; border: 1px solid rgba(255,255,255,.15); background: rgba(255,255,255,.04); color: #e1e5ef; }
  .type-tags span:first-child { background: rgba(243, 202, 86, .18); border-color: rgba(243, 202, 86, .44); color: #f3ca56; }
  .overview-metrics { display: grid; grid-template-columns: 1fr auto; row-gap: 6px; column-gap: 16px; font-size: 1.03rem; }
  .overview-metrics .label { color: rgba(255,255,255,.7); }
  .overview-metrics .value { font-weight: 600; color: #f0f3fb; text-align: right; }

  .projects-table { width: 100%; border-collapse: collapse; }
  .projects-table th, .projects-table td { border-top: 1px solid rgba(255, 255, 255, .07); padding: 10px 14px; }
  .projects-table th { font-size: .82rem; color: rgba(255,255,255,.56); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
  .projects-table td { vertical-align: middle; }
  .status-badge { display: inline-flex; align-items: center; border-radius: 8px; padding: 2px 9px; font-size: .8rem; font-weight: 600; }
  .status-badge.is-progress { color: #79d795; background: rgba(61, 154, 91, .24); border: 1px solid rgba(80, 186, 113, .4); }
  .status-badge.is-complete { color: #f0c95c; background: rgba(157, 123, 32, .24); border: 1px solid rgba(222, 181, 71, .4); }
  .status-badge.is-paused { color: #c2c8d5; background: rgba(94, 101, 116, .3); border: 1px solid rgba(152, 160, 175, .35); }

  .line-items { list-style: none; margin: 0; padding: 0; }
  .line-items li { border-top: 1px solid rgba(255,255,255,.07); padding: 10px 14px; }
  .line-main { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
  .line-label { color: #f0f2f7; text-decoration: none; font-weight: 600; }
  .line-sub { color: rgba(255,255,255,.62); font-size: .9rem; margin-top: 2px; }

  .docs-shell { padding: 16px 18px 18px; }
  .docs-toolbar { display: grid; grid-template-columns: 1fr auto; gap: 12px; margin-bottom: 12px; }
  .docs-search-wrap { position: relative; }
  .docs-search { width: 100%; background: linear-gradient(90deg, rgba(31, 32, 43, 0.95), rgba(23, 24, 35, 0.93)); border: 1px solid rgba(255,255,255,.09); border-radius: 10px; color: #f2f2f4; padding: 10px 40px 10px 38px; }
  .docs-search:focus { outline: none; border-color: rgba(255, 212, 83, 0.55); box-shadow: 0 0 0 3px rgba(255,212,83,.13); }
  .docs-search-icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #f3cb58; }
  .docs-search-go { position: absolute; right: 7px; top: 50%; transform: translateY(-50%); border: 0; background: rgba(255,255,255,.08); color: rgba(255,255,255,.78); border-radius: 6px; width: 28px; height: 28px; }
  .docs-btn { border: 1px solid rgba(237, 200, 78, .6); background: linear-gradient(180deg, #f4d36a, #ebc84d); color: #2f2710; border-radius: 10px; font-weight: 700; padding: 9px 14px; }
  .docs-card { border: 1px solid rgba(255,255,255,.08); border-radius: 12px; background: linear-gradient(160deg, rgba(28, 29, 40, .68), rgba(18, 19, 27, .58)); overflow: hidden; }
  .docs-table { width: 100%; border-collapse: collapse; }
  .docs-table th, .docs-table td { border-top: 1px solid rgba(255,255,255,.07); padding: 12px 14px; vertical-align: middle; }
  .docs-table th { color: rgba(255,255,255,.64); font-weight: 600; font-size: .85rem; text-transform: uppercase; }
  .doc-name { color: #f2f4f9; font-weight: 600; text-decoration: none; }
  .doc-project { margin-top: 2px; color: rgba(255,255,255,.58); font-size: .9rem; }
  .doc-tag { display: inline-flex; margin-left: 8px; border: 1px solid rgba(255,255,255,.18); border-radius: 7px; padding: 2px 7px; color: #c8cfdd; font-size: .76rem; }
  .author-chip { display: inline-flex; align-items: center; gap: 8px; }
  .author-avatar { width: 28px; height: 28px; border-radius: 50%; background: linear-gradient(140deg, #d9dde7, #aab2c4); color: #1d2230; font-size: .7rem; font-weight: 700; display: inline-flex; justify-content: center; align-items: center; }
  .docs-foot { display: flex; justify-content: space-between; align-items: center; color: rgba(255,255,255,.66); padding: 12px 14px; border-top: 1px solid rgba(255,255,255,.07); }
  .docs-foot a { color: rgba(255,255,255,.76); text-decoration: none; margin-right: 10px; }

  .project-grid { padding: 18px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
  .project-card { border: 1px solid rgba(255, 255, 255, .08); border-radius: 12px; background: linear-gradient(160deg, rgba(28, 29, 40, .68), rgba(18, 19, 27, .58)); padding: 14px; }
  .project-title { margin: 0; font-size: 1.55rem; font-weight: 600; }
  .project-pill { margin-top: 8px; display: inline-flex; align-items: center; border-radius: 8px; padding: 3px 10px; font-size: .86rem; font-weight: 600; }
  .project-pill.is-progress { color: #79d795; background: rgba(61, 154, 91, .24); border: 1px solid rgba(80, 186, 113, .4); }
  .project-pill.is-complete { color: #f0c95c; background: rgba(157, 123, 32, .24); border: 1px solid rgba(222, 181, 71, .4); }
  .project-pill.is-paused { color: #c2c8d5; background: rgba(94, 101, 116, .3); border: 1px solid rgba(152, 160, 175, .35); }
  .project-owner { margin-top: 12px; padding: 10px 0; border-top: 1px solid rgba(255, 255, 255, .07); border-bottom: 1px solid rgba(255, 255, 255, .07); display: flex; justify-content: space-between; align-items: center; gap: 8px; }
  .owner-chip { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .owner-avatar { width: 36px; height: 36px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: linear-gradient(140deg, #d9dde7, #aab2c4); color: #1d2230; font-size: .79rem; font-weight: 700; }
  .owner-name { font-weight: 600; color: #f1f3f8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px; }
  .owner-sub { font-size: .86rem; color: rgba(255,255,255,.6); }
  .project-kpis { display: flex; gap: 14px; color: rgba(255,255,255,.82); font-size: .95rem; }
  .project-meta { margin-top: 11px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: .92rem; }
  .project-meta > div { border: 1px solid rgba(255,255,255,.07); border-radius: 9px; background: rgba(255,255,255,.02); padding: 8px; }
  .meta-label { color: rgba(255,255,255,.55); font-size: .76rem; text-transform: uppercase; letter-spacing: .5px; }
  .meta-value { margin-top: 2px; color: #e9ecf4; font-weight: 600; }
  .project-note { margin: 11px 0 12px; color: rgba(235, 238, 245, .78); min-height: 48px; }
  .project-open { display: block; text-align: center; text-decoration: none; background: linear-gradient(180deg, #f4d36a, #ebc84d); color: #2f2710; font-weight: 700; padding: 9px 10px; border-radius: 8px; }

  .client-empty { padding: 24px 20px; color: rgba(255,255,255,.65); text-align: center; }
  @media (max-width: 1180px) { .overview-grid, .project-grid { grid-template-columns: 1fr; } .client-head { flex-direction: column; align-items: stretch; } .docs-toolbar { grid-template-columns: 1fr; } }
  @media (max-width: 760px) { .client-name { font-size: 1.45rem; } .project-title { font-size: 1.2rem; } .project-kpis { font-size: .86rem; gap: 10px; } .owner-name { max-width: 140px; } .docs-table { min-width: 760px; } }
</style>

<section class="client-shell">
  <header class="client-head">
    <div>
      <div class="client-title-row"><span class="client-icon">⚡</span><h1 class="client-name"><?=h($client['name'])?></h1></div>
      <span class="client-badge">◍ <?=h(format_date($client['created_at'] ?? now()))?></span>
    </div>
    <?php if ($can_manage): ?><button class="btn btn-yellow" data-bs-toggle="modal" data-bs-target="#addProject">＋ New Project</button><?php endif; ?>
  </header>

  <nav class="client-tabs">
    <a class="client-tab <?=$tab === 'overview' ? 'active' : ''?>" href="client_view.php?id=<?=h($id)?>&tab=overview">Overview</a>
    <a class="client-tab <?=$tab === 'projects' ? 'active' : ''?>" href="client_view.php?id=<?=h($id)?>&tab=projects">Projects</a>
    <a class="client-tab <?=$tab === 'docs' ? 'active' : ''?>" href="client_view.php?id=<?=h($id)?>&tab=docs">Docs</a>
  </nav>

  <?php if ($tab === 'overview'): ?>
    <div class="overview-grid">
      <div class="stack">
        <section class="glass-card"><div class="card-head"><h2 class="card-title">Overview</h2></div><div class="overview-body"><div class="type-tags"><?php if (!$projectTypeTags): ?><span>General</span><?php else: foreach ($projectTypeTags as $typeTag): ?><span><?=h($typeTag)?></span><?php endforeach; endif; ?></div><div class="overview-metrics"><div class="label">Monthly Revenue</div><div class="value">$<?=number_format((float)($overview['monthly_revenue'] ?? 0), 2)?></div><div class="label">Pending Invoices</div><div class="value">$<?=number_format((float)($overview['pending_invoices'] ?? 0), 2)?></div><div class="label">Date Added</div><div class="value"><?=h($client['created_at'] ? format_date($client['created_at']) : '—')?></div></div></div></section>
        <section class="glass-card">
          <div class="card-head"><h2 class="card-title">Projects</h2></div>
          <?php if (!$projects): ?><div class="client-empty">No projects yet for this client.</div><?php else: ?>
            <table class="projects-table"><thead><tr><th>Project</th><th>Lead</th><th>Last Activity</th></tr></thead><tbody>
            <?php foreach ($projects as $p): $statusClass = project_status_class((string)$p['status_name']); $leadName = trim((string)explode(',', (string)($p['assignees'] ?? ''))[0]); if ($leadName === '') { $leadName = 'Unassigned'; } ?>
              <tr><td><a class="line-label" href="project_view.php?id=<?=h($p['id'])?>"><?=h($p['name'])?></a><div class="line-sub"><span class="status-badge <?=h($statusClass)?>"><?=h($p['status_name'])?></span></div></td><td><?=h($leadName)?></td><td><?=h($p['last_activity'] ? format_date($p['last_activity']) : '—')?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
          <?php endif; ?>
        </section>
      </div>
      <div class="stack">
        <section class="glass-card"><div class="card-head"><h2 class="card-title">Projects</h2><a class="tiny-cta" href="client_view.php?id=<?=h($id)?>&tab=projects">View</a></div><ul class="line-items"><?php if (!$projectActivity): ?><li class="client-empty">No project activity.</li><?php else: foreach ($projectActivity as $p): ?><li><div class="line-main"><a class="line-label" href="project_view.php?id=<?=h($p['id'])?>"><?=h($p['name'])?></a><span class="status-badge <?=h(project_status_class((string)$p['status_name']))?>"><?=h($p['status_name'])?></span></div><div class="line-sub"><?=h($p['assignees'] ?: 'Unassigned')?> · <?= (int)$p['active_task_count'] ?> active tasks</div></li><?php endforeach; endif; ?></ul></section>
        <section class="glass-card"><div class="card-head"><h2 class="card-title">Docs</h2><a class="tiny-cta" href="client_view.php?id=<?=h($id)?>&tab=docs">Open</a></div><ul class="line-items"><?php if (!$recentDocs): ?><li class="client-empty">No documents found.</li><?php else: foreach ($recentDocs as $doc): ?><li><div class="line-main"><a class="line-label" href="doc_edit.php?id=<?=h($doc['id'])?>"><?=h($doc['title'])?></a></div><div class="line-sub"><?=h($doc['owner_name'] ?: 'Unknown')?> · <?=h($doc['updated_at'] ? format_date($doc['updated_at']) : '—')?></div></li><?php endforeach; endif; ?></ul></section>
        <section class="glass-card"><div class="card-head"><h2 class="card-title">Tasks</h2><a class="tiny-cta" href="my_tasks.php">Open</a></div><ul class="line-items"><?php if (!$recentTasks): ?><li class="client-empty">No tasks found.</li><?php else: foreach ($recentTasks as $task): ?><li><div class="line-main"><a class="line-label" href="task_view.php?id=<?=h($task['id'])?>"><?=h($task['title'])?></a><span class="status-badge <?=h(task_status_class((string)$task['status']))?>"><?=h($task['status'])?></span></div><div class="line-sub"><?=h($task['project_name'])?> · <?=h($task['due_date'] ? format_date($task['due_date']) : 'No due date')?></div></li><?php endforeach; endif; ?></ul></section>
      </div>
    </div>
  <?php elseif ($tab === 'docs'): ?>
    <section class="docs-shell">
      <form class="docs-toolbar" method="get">
        <input type="hidden" name="id" value="<?=h($id)?>"><input type="hidden" name="tab" value="docs">
        <div class="docs-search-wrap">
          <span class="docs-search-icon">⌕</span>
          <input class="docs-search" name="dq" value="<?=h($docQ)?>" placeholder="Search docs by title or project..." autocomplete="off">
          <button class="docs-search-go" type="submit">↵</button>
        </div>
        <?php if ($can_manage): ?><button class="docs-btn" type="button" data-bs-toggle="modal" data-bs-target="#addDoc">Create Doc</button><?php endif; ?>
      </form>
      <div class="docs-card">
        <table class="docs-table">
          <thead><tr><th>Doc Name</th><th>Last Updated</th><th>Updated By</th><th></th></tr></thead>
          <tbody>
            <?php if (!$docsRows): ?><tr><td colspan="4" class="client-empty">No docs found.</td></tr>
            <?php else: foreach ($docsRows as $doc): ?>
              <tr>
                <td>
                  <a class="doc-name" href="doc_edit.php?id=<?=h($doc['id'])?>">📄 <?=h($doc['title'])?></a>
                  <span class="doc-tag">#<?=h(strtolower(trim((string)$doc['project_name'])))?></span>
                  <div class="doc-project"><?=h($doc['project_name'])?></div>
                </td>
                <td><?=h($doc['updated_at'] ? format_date($doc['updated_at']) : '—')?></td>
                <td><span class="author-chip"><span class="author-avatar"><?=h(initials_from_names((string)($doc['author_name'] ?: 'Unknown')))?></span><?=h($doc['author_name'] ?: 'Unknown')?></span></td>
                <td class="text-end"><a class="tiny-cta" href="doc_edit.php?id=<?=h($doc['id'])?>">Open</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <div class="docs-foot">
          <div>
            <?php if ($docPage > 1): ?><a href="client_view.php?id=<?=h($id)?>&tab=docs&dq=<?=urlencode($docQ)?>&dp=<?=$docPage - 1?>">Previous</a><?php else: ?><span class="opacity-50">Previous</span><?php endif; ?>
            <?php if ($docPage < $docsTotalPages): ?><a href="client_view.php?id=<?=h($id)?>&tab=docs&dq=<?=urlencode($docQ)?>&dp=<?=$docPage + 1?>">Next</a><?php else: ?><span class="opacity-50 ms-2">Next</span><?php endif; ?>
          </div>
          <div>Page <?=$docPage?> of <?=$docsTotalPages?></div>
        </div>
      </div>
    </section>
  <?php else: ?>
    <div class="project-grid">
      <?php if (!$projects): ?><div class="client-empty">No projects yet for this client.</div>
      <?php else: foreach ($projects as $p): $statusClass = project_status_class((string)$p['status_name']); $assignees = trim((string)($p['assignees'] ?? '')); $leadName = $assignees !== '' ? trim((string)explode(',', $assignees)[0]) : 'Unassigned'; $note = trim((string)($p['notes'] ?? '')); if ($note === '') { $note = trim((string)($p['type_name'] ?? 'General project')) . ' project for ' . $client['name'] . '.'; } ?>
        <article class="project-card"><h2 class="project-title"><?=h($p['name'])?></h2><span class="project-pill <?=h($statusClass)?>"><?=h($p['status_name'])?></span><div class="project-owner"><div class="owner-chip"><span class="owner-avatar"><?=h(initials_from_names($assignees))?></span><div><div class="owner-name"><?=h($leadName)?></div><div class="owner-sub"><?=h($p['type_name'])?></div></div></div><div class="project-kpis"><span><?= (int)$p['task_count'] ?> Tasks</span><span><?= (int)$p['doc_count'] ?> Docs</span></div></div><div class="project-meta"><div><div class="meta-label">Last activity</div><div class="meta-value"><?=h($p['last_activity'] ? format_date($p['last_activity']) : '—')?></div></div><div><div class="meta-label">Active tasks</div><div class="meta-value"><?= (int)$p['active_task_count'] ?> active</div></div></div><p class="project-note"><?=h($note)?></p><a class="project-open" href="project_view.php?id=<?=h($p['id'])?>">View Project</a></article>
      <?php endforeach; endif; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($can_manage): ?>
<div class="modal fade" id="addProject" tabindex="-1"><div class="modal-dialog"><div class="modal-content card p-3"><div class="modal-header border-0"><h5 class="modal-title">Add Project</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="create_project" value="1"><div class="modal-body"><div class="mb-3"><label class="form-label">Project Name</label><input class="form-control" name="name" required></div><div class="mb-3"><label class="form-label">Type</label><select class="form-select" name="type_id" required><?php foreach($types as $t): ?><option value="<?=h($t['id'])?>"><?=h($t['name'])?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">Status</label><select class="form-select" name="status_id" required><?php foreach($statuses as $s): ?><option value="<?=h($s['id'])?>"><?=h($s['name'])?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">Due Date (optional)</label><input class="form-control" type="date" name="due_date"></div></div><div class="modal-footer border-0"><button class="btn btn-outline-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-yellow" type="submit">Create</button></div></form></div></div></div>

<div class="modal fade" id="addDoc" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content card p-3"><div class="modal-header border-0"><h5 class="modal-title">Create Doc</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="create_doc" value="1"><div class="modal-body"><div class="row g-3"><div class="col-md-8"><label class="form-label">Title</label><input class="form-control" name="doc_title" required></div><div class="col-md-4"><label class="form-label">Project</label><select class="form-select" name="doc_project_id" required><?php foreach($clientProjects as $cp): ?><option value="<?=h($cp['id'])?>"><?=h($cp['name'])?></option><?php endforeach; ?></select></div><div class="col-12"><label class="form-label">Content</label><textarea class="form-control" name="doc_content" rows="9" placeholder="Write document..."></textarea></div></div></div><div class="modal-footer border-0"><button class="btn btn-outline-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-yellow" type="submit">Create Doc</button></div></form></div></div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/layout_end.php'; ?>
