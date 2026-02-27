<?php
require_once __DIR__ . '/layout.php';

$pdo = db();
$ws = auth_workspace_id();
$role = auth_user()['role_name'] ?? '';
$can_manage = in_array($role, ['CEO', 'CTO', 'Super Admin'], true);

$id = (int)($_GET['id'] ?? 0);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
  require_post();
  csrf_verify();

  if (!$can_manage) {
    flash_set('error', 'No permission.');
    redirect("client_view.php?id=$id");
  }

  $name = trim($_POST['name'] ?? '');
  $type_id = (int)($_POST['type_id'] ?? 0);
  $status_id = (int)($_POST['status_id'] ?? 0);

  if ($name === '') {
    flash_set('error', 'Project name required.');
    redirect("client_view.php?id=$id");
  }

  $pdo->prepare('INSERT INTO projects (workspace_id,client_id,name,type_id,status_id,due_date,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
      ->execute([$ws, $id, $name, $type_id, $status_id, $_POST['due_date'] ?: null, null, now(), now()]);

  flash_set('success', 'Project created.');
  redirect("client_view.php?id=$id");
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
  .client-shell {
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 18px;
    background: linear-gradient(155deg, rgba(15, 16, 24, .96), rgba(10, 11, 18, .95));
    box-shadow: 0 24px 64px rgba(0, 0, 0, .42);
    overflow: hidden;
  }
  .client-head {
    display: flex;
    align-items: start;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 20px 14px;
    border-bottom: 1px solid rgba(255, 255, 255, .07);
  }
  .client-title-row { display: flex; align-items: center; gap: 12px; }
  .client-icon {
    width: 36px; height: 36px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    border: 1px solid rgba(244, 205, 92, .68); color: #f4cd5c; font-size: 18px;
    box-shadow: inset 0 0 0 1px rgba(244, 205, 92, .24);
  }
  .client-name { font-size: 2rem; margin: 0; font-weight: 500; letter-spacing: .2px; }
  .client-badge {
    margin-top: 8px; display: inline-flex; align-items: center; gap: 6px;
    background: rgba(102, 83, 211, .18); color: #d2c7ff; border: 1px solid rgba(159, 139, 255, .25);
    border-radius: 8px; padding: 4px 9px; font-size: .83rem;
  }
  .client-tabs {
    display: flex;
    gap: 24px;
    padding: 0 20px;
    border-bottom: 1px solid rgba(255, 255, 255, .07);
  }
  .client-tab {
    color: rgba(255, 255, 255, .76);
    text-decoration: none;
    padding: 10px 0;
    display: inline-block;
    border-bottom: 2px solid transparent;
    font-weight: 500;
  }
  .client-tab.active { color: #f3ca56; border-color: rgba(243, 202, 86, .85); }
  .project-grid {
    padding: 18px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
  }
  .project-card {
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 12px;
    background: linear-gradient(160deg, rgba(28, 29, 40, .68), rgba(18, 19, 27, .58));
    padding: 14px;
  }
  .project-title { margin: 0; font-size: 1.55rem; font-weight: 600; }
  .project-pill {
    margin-top: 8px; display: inline-flex; align-items: center; border-radius: 8px;
    padding: 3px 10px; font-size: .86rem; font-weight: 600;
  }
  .project-pill.is-progress { color: #79d795; background: rgba(61, 154, 91, .24); border: 1px solid rgba(80, 186, 113, .4); }
  .project-pill.is-complete { color: #f0c95c; background: rgba(157, 123, 32, .24); border: 1px solid rgba(222, 181, 71, .4); }
  .project-pill.is-paused { color: #c2c8d5; background: rgba(94, 101, 116, .3); border: 1px solid rgba(152, 160, 175, .35); }
  .project-owner {
    margin-top: 12px; padding: 10px 0; border-top: 1px solid rgba(255, 255, 255, .07); border-bottom: 1px solid rgba(255, 255, 255, .07);
    display: flex; justify-content: space-between; align-items: center; gap: 8px;
  }
  .owner-chip { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .owner-avatar {
    width: 36px; height: 36px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
    background: linear-gradient(140deg, #d9dde7, #aab2c4); color: #1d2230; font-size: .79rem; font-weight: 700;
  }
  .owner-name { font-weight: 600; color: #f1f3f8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px; }
  .owner-sub { font-size: .86rem; color: rgba(255,255,255,.6); }
  .project-kpis { display: flex; gap: 14px; color: rgba(255,255,255,.82); font-size: .95rem; }
  .project-meta {
    margin-top: 11px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    font-size: .92rem;
  }
  .project-meta > div {
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 9px;
    background: rgba(255,255,255,.02);
    padding: 8px;
  }
  .meta-label { color: rgba(255,255,255,.55); font-size: .76rem; text-transform: uppercase; letter-spacing: .5px; }
  .meta-value { margin-top: 2px; color: #e9ecf4; font-weight: 600; }
  .project-note { margin: 11px 0 12px; color: rgba(235, 238, 245, .78); min-height: 48px; }
  .project-open {
    display: block; text-align: center; text-decoration: none;
    background: linear-gradient(180deg, #f4d36a, #ebc84d);
    color: #2f2710; font-weight: 700; padding: 9px 10px; border-radius: 8px;
  }
  .project-open:hover { color: #1f190a; }
  .client-empty {
    padding: 40px 20px; color: rgba(255,255,255,.65); text-align: center;
  }
  @media (max-width: 1180px) {
    .project-grid { grid-template-columns: 1fr; }
    .client-head { flex-direction: column; align-items: stretch; }
  }
  @media (max-width: 760px) {
    .client-name { font-size: 1.45rem; }
    .project-title { font-size: 1.2rem; }
    .project-kpis { font-size: .86rem; gap: 10px; }
    .owner-name { max-width: 140px; }
  }
</style>

<section class="client-shell">
  <header class="client-head">
    <div>
      <div class="client-title-row">
        <span class="client-icon">⚡</span>
        <h1 class="client-name"><?=h($client['name'])?></h1>
      </div>
      <span class="client-badge">◍ <?=h($client['notes'] ? 'Profile loaded' : 'Client workspace')?></span>
    </div>
    <?php if ($can_manage): ?>
      <button class="btn btn-yellow" data-bs-toggle="modal" data-bs-target="#addProject">＋ New Project</button>
    <?php endif; ?>
  </header>

  <nav class="client-tabs">
    <a class="client-tab" href="client_view.php?id=<?=h($id)?>#overview">Overview</a>
    <a class="client-tab active" href="client_view.php?id=<?=h($id)?>">Projects</a>
    <a class="client-tab" href="docs.php">Docs</a>
  </nav>

  <div class="project-grid" id="overview">
    <?php if (!$projects): ?>
      <div class="client-empty">No projects yet for this client.</div>
    <?php else: foreach ($projects as $p):
      $statusClass = project_status_class((string)$p['status_name']);
      $assignees = trim((string)($p['assignees'] ?? ''));
      $leadName = $assignees !== '' ? explode(',', $assignees)[0] : 'Unassigned';
      $leadName = trim((string)$leadName);
      $note = trim((string)($p['notes'] ?? ''));
      if ($note === '') {
        $note = trim((string)($p['type_name'] ?? 'General project')) . ' project for ' . $client['name'] . '.';
      }
    ?>
      <article class="project-card">
        <h2 class="project-title"><?=h($p['name'])?></h2>
        <span class="project-pill <?=h($statusClass)?>"><?=h($p['status_name'])?></span>

        <div class="project-owner">
          <div class="owner-chip">
            <span class="owner-avatar"><?=h(initials_from_names($assignees))?></span>
            <div>
              <div class="owner-name"><?=h($leadName)?></div>
              <div class="owner-sub"><?=h($p['type_name'])?></div>
            </div>
          </div>
          <div class="project-kpis">
            <span><?= (int)$p['task_count'] ?> Tasks</span>
            <span><?= (int)$p['doc_count'] ?> Docs</span>
          </div>
        </div>

        <div class="project-meta">
          <div>
            <div class="meta-label">Last activity</div>
            <div class="meta-value"><?=h($p['last_activity'] ? format_date($p['last_activity']) : '—')?></div>
          </div>
          <div>
            <div class="meta-label">Active tasks</div>
            <div class="meta-value"><?= (int)$p['active_task_count'] ?> active</div>
          </div>
        </div>

        <p class="project-note"><?=h($note)?></p>
        <a class="project-open" href="project_view.php?id=<?=h($p['id'])?>">View Project</a>
      </article>
    <?php endforeach; endif; ?>
  </div>
</section>

<?php if ($can_manage): ?>
<div class="modal fade" id="addProject" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content card p-3">
      <div class="modal-header border-0">
        <h5 class="modal-title">Add Project</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="create_project" value="1">
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Project Name</label><input class="form-control" name="name" required></div>
          <div class="mb-3"><label class="form-label">Type</label>
            <select class="form-select" name="type_id" required><?php foreach($types as $t): ?><option value="<?=h($t['id'])?>"><?=h($t['name'])?></option><?php endforeach; ?></select>
          </div>
          <div class="mb-3"><label class="form-label">Status</label>
            <select class="form-select" name="status_id" required><?php foreach($statuses as $s): ?><option value="<?=h($s['id'])?>"><?=h($s['name'])?></option><?php endforeach; ?></select>
          </div>
          <div class="mb-3"><label class="form-label">Due Date (optional)</label><input class="form-control" type="date" name="due_date"></div>
        </div>
        <div class="modal-footer border-0">
          <button class="btn btn-outline-light" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-yellow" type="submit">Create</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/layout_end.php'; ?>
