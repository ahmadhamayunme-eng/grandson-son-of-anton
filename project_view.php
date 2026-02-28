<?php
require_once __DIR__ . '/layout.php';

$pdo = db();
$ws = auth_workspace_id();
$user = auth_user();
$role = $user['role_name'] ?? '';
$can_manage = in_array($role, ['CEO', 'CTO', 'Super Admin'], true);
$isSuperAdmin = $role === 'Super Admin';

$id = (int)($_GET['id'] ?? 0);
$tab = strtolower(trim((string)($_GET['tab'] ?? 'overview')));
if (!in_array($tab, ['overview', 'tasks', 'docs', 'activity'], true)) {
  $tab = 'overview';
}

$loadError = null;
$project = null;
$phases = [];
$statuses = [];
$team = [];
$tasks = [];
$by_phase = [];
$docsQ = trim((string)($_GET['dq'] ?? ''));
$docs = [];
$activities = [];
$totalTasks = 0;
$doneTasks = 0;
$progress = 0;

function pv_status_class(string $status): string {
  $s = strtolower($status);
  if (strpos($s, 'block') !== false) return 'is-blocked';
  if (strpos($s, 'progress') !== false || strpos($s, 'review') !== false) return 'is-progress';
  if (strpos($s, 'done') !== false || strpos($s, 'approve') !== false || strpos($s, 'submit') !== false || strpos($s, 'complete') !== false) return 'is-done';
  return 'is-todo';
}

try {
  $projectStmt = $pdo->prepare("SELECT p.*, c.name AS client_name, pt.name AS type_name, ps.name AS status_name
    FROM projects p
    JOIN clients c ON c.id = p.client_id
    JOIN project_types pt ON pt.id = p.type_id
    JOIN project_statuses ps ON ps.id = p.status_id
    WHERE p.id = ? AND p.workspace_id = ?");
  $projectStmt->execute([$id, $ws]);
  $project = $projectStmt->fetch();

  if (!$project) {
    echo '<h3>Project not found</h3>';
    require __DIR__ . '/layout_end.php';
    exit;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    csrf_verify();

    if (isset($_POST['add_phase'])) {
      if (!$can_manage) {
        flash_set('error', 'No permission.');
        redirect("project_view.php?id=$id&tab=tasks");
      }

      $name = trim($_POST['phase_name'] ?? '');
      if ($name === '') {
        flash_set('error', 'Phase name required.');
        redirect("project_view.php?id=$id&tab=tasks");
      }

      $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM phases WHERE project_id = ? AND workspace_id = ?');
      $sortStmt->execute([$id, $ws]);
      $sort = (int)($sortStmt->fetch()['n'] ?? 1);

      $pdo->prepare('INSERT INTO phases (workspace_id,project_id,name,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?)')
        ->execute([$ws, $id, $name, $sort, now(), now()]);

      flash_set('success', 'Phase added.');
      redirect("project_view.php?id=$id&tab=tasks");
    }

    if (isset($_POST['add_task'])) {
      if (!$can_manage) {
        flash_set('error', 'No permission.');
        redirect("project_view.php?id=$id&tab=tasks");
      }

      $phase_id = (int)($_POST['phase_id'] ?? 0);
      $title = trim($_POST['title'] ?? '');
      $desc = trim($_POST['description'] ?? '');
      $status = trim($_POST['status'] ?? 'To Do');
      $due = $_POST['due_date'] ?? null;

      if ($title === '' || $phase_id <= 0) {
        flash_set('error', 'Task title and phase are required.');
        redirect("project_view.php?id=$id&tab=tasks");
      }

      $pdo->prepare('INSERT INTO tasks (workspace_id,project_id,phase_id,title,description,status,due_date,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$ws, $id, $phase_id, $title, $desc ?: null, $status, $due ?: null, (int)$user['id'], now(), now()]);

      $task_id = (int)$pdo->lastInsertId();
      foreach (($_POST['assignees'] ?? []) as $uid) {
        $pdo->prepare('INSERT INTO task_assignees (task_id,user_id) VALUES (?,?)')->execute([$task_id, (int)$uid]);
      }

      flash_set('success', 'Task created.');
      redirect("project_view.php?id=$id&tab=tasks");
    }

    if (isset($_POST['create_doc'])) {
      if (!$can_manage) {
        flash_set('error', 'No permission.');
        redirect("project_view.php?id=$id&tab=docs");
      }

      $title = trim($_POST['doc_title'] ?? '');
      $content = trim($_POST['doc_content'] ?? '');
      if ($title === '') {
        flash_set('error', 'Doc title required.');
        redirect("project_view.php?id=$id&tab=docs");
      }

      $pdo->prepare('INSERT INTO docs (workspace_id,project_id,title,content,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$ws, $id, $title, $content ?: null, (int)$user['id'], now(), now()]);

      flash_set('success', 'Document created.');
      redirect("project_view.php?id=$id&tab=docs");
    }
  }

  $phasesStmt = $pdo->prepare('SELECT * FROM phases WHERE workspace_id = ? AND project_id = ? ORDER BY sort_order ASC');
  $phasesStmt->execute([$ws, $id]);
  $phases = $phasesStmt->fetchAll();

  $statusesStmt = $pdo->prepare('SELECT name FROM task_statuses WHERE workspace_id = ? ORDER BY sort_order ASC');
  $statusesStmt->execute([$ws]);
  $statuses = array_map(fn($r) => $r['name'], $statusesStmt->fetchAll());
  if (!$statuses) {
    $statuses = ['Backlog','To Do','In Progress','Completed (Needs CTO Review)','Approved (Ready to Submit)','Submitted to Client'];
  }

  $teamStmt = $pdo->prepare("SELECT u.id,u.name,r.name AS role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.workspace_id = ? AND u.is_active = 1
    ORDER BY u.name ASC");
  $teamStmt->execute([$ws]);
  $team = $teamStmt->fetchAll();

  $tasksStmt = $pdo->prepare("SELECT t.*, GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') AS assignee_names, COUNT(DISTINCT ta.user_id) assignee_count
    FROM tasks t
    LEFT JOIN task_assignees ta ON ta.task_id = t.id
    LEFT JOIN users u ON u.id = ta.user_id
    WHERE t.workspace_id = ? AND t.project_id = ?
    GROUP BY t.id
    ORDER BY COALESCE(t.due_date, DATE(t.updated_at)) ASC, t.id DESC");
  $tasksStmt->execute([$ws, $id]);
  $tasks = $tasksStmt->fetchAll();

  foreach ($tasks as $task) {
    $by_phase[$task['phase_id']][] = $task;
  }

  $docsStmt = $pdo->prepare("SELECT d.*, u.name AS author_name
    FROM docs d
    LEFT JOIN users u ON u.id = d.created_by
    WHERE d.workspace_id = ? AND d.project_id = ? AND d.title LIKE ?
    ORDER BY d.updated_at DESC, d.id DESC
    LIMIT 120");
  $docsStmt->execute([$ws, $id, '%' . $docsQ . '%']);
  $docs = $docsStmt->fetchAll();

  $activityStmt = $pdo->prepare("SELECT * FROM (
    SELECT t.updated_at AS happened_at, CONCAT('Task updated: ', t.title) AS message, COALESCE(u.name, 'System') AS actor, 'task' AS source
    FROM tasks t
    LEFT JOIN users u ON u.id = t.created_by
    WHERE t.workspace_id = ? AND t.project_id = ?

    UNION ALL

    SELECT d.updated_at AS happened_at, CONCAT('Doc updated: ', d.title) AS message, COALESCE(u.name, 'System') AS actor, 'doc' AS source
    FROM docs d
    LEFT JOIN users u ON u.id = d.created_by
    WHERE d.workspace_id = ? AND d.project_id = ?

    UNION ALL

    SELECT p.updated_at AS happened_at, CONCAT('Project updated: ', p.name) AS message, 'System' AS actor, 'project' AS source
    FROM projects p
    WHERE p.workspace_id = ? AND p.id = ?
  ) x
  ORDER BY x.happened_at DESC
  LIMIT 120");
  $activityStmt->execute([$ws, $id, $ws, $id, $ws, $id]);
  $activities = $activityStmt->fetchAll();

  $totalTasks = count($tasks);
  foreach ($tasks as $task) {
    $status = strtolower((string)$task['status']);
    if (strpos($status, 'approved') !== false || strpos($status, 'submitted') !== false || strpos($status, 'done') !== false || strpos($status, 'complete') !== false) {
      $doneTasks++;
    }
  }
  $progress = $totalTasks > 0 ? (int)round(($doneTasks / $totalTasks) * 100) : 0;
} catch (Throwable $e) {
  $loadError = 'Project page failed to load. Please refresh or contact admin.';
  if ($isSuperAdmin) {
    $loadError .= ' Debug: ' . $e->getMessage();
  }
}
?>

<style>
  .pv-shell{border:1px solid rgba(255,255,255,.08);border-radius:18px;background:linear-gradient(160deg,rgba(13,14,22,.97),rgba(10,11,18,.96));box-shadow:0 24px 60px rgba(0,0,0,.45);padding:16px}
  .pv-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:10px}
  .pv-title{margin:0;font-size:2rem;font-weight:600}
  .pv-sub{color:rgba(255,255,255,.7);display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:5px}
  .pv-status{display:inline-flex;padding:3px 10px;border-radius:8px;font-weight:600;background:rgba(61,154,91,.24);border:1px solid rgba(80,186,113,.4);color:#79d795}
  .pv-tabs{display:flex;gap:22px;padding:0 2px 9px;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:14px}
  .pv-tab{color:rgba(255,255,255,.75);text-decoration:none;padding:7px 0;border-bottom:2px solid transparent}
  .pv-tab.active{color:#f5d66c;border-color:#f5d66c}
  .glass{border:1px solid rgba(255,255,255,.08);border-radius:12px;background:linear-gradient(160deg,rgba(26,27,38,.7),rgba(18,19,27,.62));overflow:hidden}
  .card-h{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.07)}
  .card-h h3{margin:0;font-size:1.35rem}
  .pv-grid{display:grid;grid-template-columns:1fr 1.45fr;gap:14px}
  .ov-body{padding:12px 14px}
  .prog{height:10px;background:rgba(255,255,255,.08);border-radius:999px;overflow:hidden;margin:6px 0 7px}
  .prog > span{display:block;height:100%;background:linear-gradient(90deg,#f3d46d,#8c7b3a)}
  .meta{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;color:rgba(255,255,255,.78)}
  .list{list-style:none;margin:0;padding:0}.list li{display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;padding:11px 14px;border-top:1px solid rgba(255,255,255,.07)}
  .list .sub,.sub{font-size:.9rem;color:rgba(255,255,255,.6)}
  .pill{display:inline-flex;padding:2px 9px;border-radius:8px;font-size:.82rem;font-weight:600;border:1px solid rgba(255,255,255,.18)}
  .is-blocked{background:rgba(161,131,47,.24);color:#f0d071}.is-progress{background:rgba(101,78,157,.26);color:#c9b4ff}.is-done{background:rgba(62,141,98,.24);color:#82d79f}.is-todo{background:rgba(68,97,137,.24);color:#8eb7f2}
  .phase-wrap{display:grid;gap:12px}.phase{border:1px solid rgba(255,255,255,.08);border-radius:12px;background:linear-gradient(160deg,rgba(26,27,38,.7),rgba(18,19,27,.62));overflow:hidden}
  .phase h4{margin:0}.pv-table{width:100%;border-collapse:collapse;table-layout:fixed}.pv-table th,.pv-table td{padding:9px 12px;border-top:1px solid rgba(255,255,255,.07);vertical-align:middle}.pv-table th{color:rgba(255,255,255,.65);font-size:.82rem;text-transform:uppercase}
  .pv-table .col-task{width:38%}.pv-table .col-status{width:17%}.pv-table .col-assignees{width:24%}.pv-table .col-due{width:9%;text-align:center}.pv-table .col-actions{width:12%;text-align:center}
  .task-title{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.3;word-break:break-word}
  .task-cell,.assignee-cell{text-align:left}.status-cell,.due-cell,.action-cell{text-align:center}
  .docs-toolbar{display:grid;grid-template-columns:1fr auto auto;gap:10px;margin-bottom:12px}.docs-search{padding:10px 12px;border:1px solid rgba(255,255,255,.1);border-radius:10px;background:rgba(255,255,255,.03);color:#eee}.docs-section{display:grid;gap:10px}.doc-item{display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;padding:10px 14px;border-top:1px solid rgba(255,255,255,.07)}
  .activity .row-item{display:grid;grid-template-columns:36px 1fr auto;gap:10px;align-items:flex-start;padding:11px 14px;border-top:1px solid rgba(255,255,255,.07)}.ico{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:rgba(255,255,255,.1)}
  @media (max-width:1100px){.pv-grid{grid-template-columns:1fr}.docs-toolbar{grid-template-columns:1fr}}
</style>

<section class="pv-shell">
  <?php if($loadError): ?><div class="alert alert-danger mb-3"><?=h($loadError)?></div><?php endif; ?>

  <?php if($project): ?>
  <header class="pv-top">
    <div>
      <h1 class="pv-title"><?=h($project['name'])?></h1>
      <div class="pv-sub">
        <span><?=h($project['client_name'])?></span><span>•</span><span><?=h($project['type_name'])?></span>
        <span class="pv-status"><?=h($project['status_name'])?></span>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-light" href="docs.php?project_id=<?=h($id)?>">Project Docs</a>
      <?php if($can_manage): ?><button class="btn btn-yellow" data-bs-toggle="modal" data-bs-target="#addPhase">Add Phase</button><button class="btn btn-yellow" data-bs-toggle="modal" data-bs-target="#addTask">Add Task</button><?php endif; ?>
    </div>
  </header>

  <nav class="pv-tabs">
    <a class="pv-tab <?=$tab==='overview'?'active':''?>" href="project_view.php?id=<?=$id?>&tab=overview">Overview</a>
    <a class="pv-tab <?=$tab==='tasks'?'active':''?>" href="project_view.php?id=<?=$id?>&tab=tasks">Tasks</a>
    <a class="pv-tab <?=$tab==='docs'?'active':''?>" href="project_view.php?id=<?=$id?>&tab=docs">Docs</a>
    <a class="pv-tab <?=$tab==='activity'?'active':''?>" href="project_view.php?id=<?=$id?>&tab=activity">Activity</a>
  </nav>

  <?php if($tab==='overview'): ?>
    <div class="pv-grid">
      <section class="glass"><div class="card-h"><h3>Overview</h3></div><div class="ov-body"><div class="text-muted">Progress</div><div class="prog"><span style="width: <?=$progress?>%"></span></div><div><?=$progress?>% complete (<?=$doneTasks?>/<?=$totalTasks?> tasks)</div><div class="mt-3 text-muted">Summary</div><p class="mb-0"><?=h($project['notes'] ?: 'Project overview and delivery milestones are tracked through tasks and docs for this workspace.')?></p><div class="meta"><div><div class="text-muted small">Last Updated</div><div><?=h(format_date($project['updated_at']))?></div></div><div><div class="text-muted small">Created</div><div><?=h(format_date($project['created_at']))?></div></div></div></div></section>
      <div class="d-grid gap-3">
        <section class="glass"><div class="card-h"><h3>Active Tasks</h3><a class="btn btn-sm btn-outline-light" href="project_view.php?id=<?=$id?>&tab=tasks">View</a></div><ul class="list"><?php foreach(array_slice($tasks,0,5) as $t): ?><li><div><div><?=h($t['title'])?></div><div class="sub"><?=h($t['assignee_names'] ?: 'Unassigned')?></div></div><span class="pill <?=pv_status_class((string)$t['status'])?>"><?=h($t['status'])?></span><span><?=h($t['due_date']?format_date($t['due_date']):'—')?></span></li><?php endforeach; ?><?php if(!$tasks): ?><li><div class="text-muted">No tasks yet.</div></li><?php endif; ?></ul></section>
        <section class="glass"><div class="card-h"><h3>Docs</h3><a class="btn btn-sm btn-outline-light" href="project_view.php?id=<?=$id?>&tab=docs">Open</a></div><ul class="list"><?php foreach(array_slice($docs,0,5) as $d): ?><li><div><div><?=h($d['title'])?></div><div class="sub">By <?=h($d['author_name'] ?: 'Unknown')?></div></div><span class="sub"><?=h(format_date($d['updated_at']))?></span><a class="btn btn-sm btn-outline-light" href="doc_edit.php?id=<?=$d['id']?>">Open</a></li><?php endforeach; ?><?php if(!$docs): ?><li><div class="text-muted">No documents yet.</div></li><?php endif; ?></ul></section>
      </div>
    </div>
  <?php elseif($tab==='tasks'): ?>
    <section class="phase-wrap">
      <?php foreach($phases as $ph): ?>
        <article class="phase">
          <div class="card-h">
            <h4><?=h($ph['name'])?></h4>
            <?php if($can_manage): ?><button class="btn btn-sm btn-yellow" data-bs-toggle="modal" data-bs-target="#addTask">+ Add Task</button><?php endif; ?>
          </div>

          <table class="pv-table">
            <colgroup>
              <col class="col-task"><col class="col-status"><col class="col-assignees"><col class="col-due"><col class="col-actions">
            </colgroup>
            <thead>
              <tr><th class="task-cell">Task</th><th class="status-cell">Status</th><th class="assignee-cell">Assignees</th><th class="due-cell">Due</th><th class="action-cell">Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach(($by_phase[$ph['id']] ?? []) as $t): ?>
                <tr>
                  <td class="task-cell"><div class="task-title"><?=h($t['title'])?></div></td>
                  <td class="status-cell"><span class="pill <?=pv_status_class((string)$t['status'])?>"><?=h($t['status'])?></span></td>
                  <td class="assignee-cell"><div class="task-title"><?=h($t['assignee_names'] ?: '—')?></div></td>
                  <td class="due-cell"><?=h($t['due_date'] ? format_date($t['due_date']) : '—')?></td>
                  <td class="action-cell"><a class="btn btn-sm btn-outline-light" href="task_view.php?id=<?=$t['id']?>">Open</a></td>
                </tr>
              <?php endforeach; ?>
              <?php if(!($by_phase[$ph['id']] ?? [])): ?><tr><td colspan="5" class="text-muted">No tasks in this phase.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </article>
      <?php endforeach; ?>
      <?php if(!$phases): ?><div class="glass p-4 text-muted">No phases yet. Add a phase to start tasks.</div><?php endif; ?>
    </section>
  <?php elseif($tab==='docs'): ?>
    <section><form class="docs-toolbar" method="get"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="tab" value="docs"><input class="docs-search" name="dq" value="<?=h($docsQ)?>" placeholder="Search documents..."><?php if($can_manage): ?><button type="button" class="btn btn-yellow" data-bs-toggle="modal" data-bs-target="#addDoc">+ New Doc</button><?php endif; ?><a class="btn btn-outline-light" href="docs.php?project_id=<?=$id?>">Upload</a></form><div class="glass docs-section"><div class="card-h"><h3>Project Documents</h3><span><?=count($docs)?> files</span></div><?php foreach($docs as $d): ?><div class="doc-item"><div><div><?=h($d['title'])?></div><div class="sub">Updated <?=h(format_date($d['updated_at']))?> by <?=h($d['author_name'] ?: 'Unknown')?></div></div><span class="text-muted"><?=strlen((string)($d['content'] ?? ''))?> chars</span><a class="btn btn-sm btn-outline-light" href="doc_edit.php?id=<?=$d['id']?>">Open</a></div><?php endforeach; ?><?php if(!$docs): ?><div class="p-4 text-muted">No documents found.</div><?php endif; ?></div></section>
  <?php else: ?>
    <section class="glass activity"><div class="card-h"><h3>Activity</h3><span><?=count($activities)?> events</span></div><?php foreach($activities as $a): ?><div class="row-item"><div class="ico"><?=h(strtoupper(substr((string)$a['source'],0,1)))?></div><div><div><?=h($a['message'])?></div><div class="sub">by <?=h($a['actor'])?></div></div><div class="text-muted"><?=h(format_date($a['happened_at']))?></div></div><?php endforeach; ?><?php if(!$activities): ?><div class="p-4 text-muted">No activity yet.</div><?php endif; ?></section>
  <?php endif; ?>
  <?php endif; ?>
</section>

<?php if($can_manage && $project): ?>
<div class="modal fade" id="addPhase" tabindex="-1"><div class="modal-dialog"><div class="modal-content card p-3"><div class="modal-header border-0"><h5 class="modal-title">Add Phase</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="add_phase" value="1"><div class="modal-body"><label class="form-label">Phase Name</label><input class="form-control" name="phase_name" required></div><div class="modal-footer border-0"><button class="btn btn-outline-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-yellow" type="submit">Add</button></div></form></div></div></div>
<div class="modal fade" id="addTask" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content card p-3"><div class="modal-header border-0"><h5 class="modal-title">Add Task</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="add_task" value="1"><div class="modal-body"><div class="row g-3"><div class="col-md-8"><label class="form-label">Title</label><input class="form-control" name="title" required></div><div class="col-md-4"><label class="form-label">Phase</label><select class="form-select" name="phase_id" required><?php foreach($phases as $ph): ?><option value="<?=$ph['id']?>"><?=h($ph['name'])?></option><?php endforeach; ?></select></div><div class="col-md-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="4"></textarea></div><div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach($statuses as $s): ?><option value="<?=h($s)?>"><?=h($s)?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Due Date</label><input class="form-control" type="date" name="due_date"></div><div class="col-md-4"><label class="form-label">Assignees</label><select class="form-select" name="assignees[]" multiple><?php foreach($team as $m): ?><option value="<?=$m['id']?>"><?=h($m['name'])?> (<?=h($m['role_name'])?>)</option><?php endforeach; ?></select></div></div></div><div class="modal-footer border-0"><button class="btn btn-outline-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-yellow" type="submit">Create Task</button></div></form></div></div></div>
<div class="modal fade" id="addDoc" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content card p-3"><div class="modal-header border-0"><h5 class="modal-title">New Doc</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="create_doc" value="1"><div class="modal-body"><div class="mb-3"><label class="form-label">Title</label><input class="form-control" name="doc_title" required></div><div class="mb-3"><label class="form-label">Content</label><textarea class="form-control" name="doc_content" rows="10"></textarea></div></div><div class="modal-footer border-0"><button class="btn btn-outline-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-yellow" type="submit">Create Doc</button></div></form></div></div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/layout_end.php'; ?>
