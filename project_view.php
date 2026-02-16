<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();
$role=auth_user()['role_name'] ?? '';
$can_manage=in_array($role,['CEO','CTO','Super Admin'],true);

$id=(int)($_GET['id'] ?? 0);
$stmt=$pdo->prepare("SELECT p.*, c.name AS client_name, pt.name AS type_name, ps.name AS status_name
  FROM projects p
  JOIN clients c ON c.id=p.client_id
  JOIN project_types pt ON pt.id=p.type_id
  JOIN project_statuses ps ON ps.id=p.status_id
  WHERE p.id=? AND p.workspace_id=?");
$stmt->execute([$id,$ws]);
$project=$stmt->fetch();
if(!$project){ echo "<h3>Project not found</h3>"; require __DIR__ . '/layout_end.php'; exit; }

if($_SERVER['REQUEST_METHOD']==='POST'){
  require_post(); csrf_verify();

  if(isset($_POST['add_phase'])){
    if(!$can_manage){ flash_set('error','No permission.'); redirect("project_view.php?id=$id"); }
    $name=trim($_POST['phase_name'] ?? '');
    if($name===''){ flash_set('error','Phase name required.'); redirect("project_view.php?id=$id"); }
    $sort=(int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM phases WHERE project_id=$id AND workspace_id=$ws")->fetch()['n'];
    $pdo->prepare("INSERT INTO phases (workspace_id,project_id,name,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?)")
        ->execute([$ws,$id,$name,$sort,now(),now()]);
    flash_set('success','Phase added.');
    redirect("project_view.php?id=$id");
  }

  if(isset($_POST['add_task'])){
    if(!$can_manage){ flash_set('error','No permission.'); redirect("project_view.php?id=$id"); }
    $phase_id=(int)($_POST['phase_id'] ?? 0);
    $title=trim($_POST['title'] ?? '');
    $desc=trim($_POST['description'] ?? '');
    $status=trim($_POST['status'] ?? 'To Do');
    $due=$_POST['due_date'] ?? null;
    if($title===''){ flash_set('error','Task title required.'); redirect("project_view.php?id=$id"); }
    $pdo->prepare("INSERT INTO tasks (workspace_id,project_id,phase_id,title,description,status,due_date,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$ws,$id,$phase_id,$title,$desc?:null,$status,$due?:null,auth_user()['id'],now(),now()]);
    $task_id=(int)$pdo->lastInsertId();
    $assignees=$_POST['assignees'] ?? [];
    foreach($assignees as $uid){
      $pdo->prepare("INSERT INTO task_assignees (task_id,user_id) VALUES (?,?)")->execute([$task_id,(int)$uid]);
    }
    flash_set('success','Task created.');
    redirect("project_view.php?id=$id");
  }
}

$phases=$pdo->query("SELECT * FROM phases WHERE workspace_id=$ws AND project_id=$id ORDER BY sort_order ASC")->fetchAll();
$statuses=$pdo->query("SELECT name FROM task_statuses WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();
$statuses=array_map(fn($r)=>$r['name'],$statuses);
if(!$statuses){ $statuses=['Backlog','To Do','In Progress','Completed (Needs CTO Review)','Approved (Ready to Submit)','Submitted to Client']; }

$team=$pdo->query("SELECT u.id,u.name,r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.workspace_id=$ws AND u.is_active=1 AND r.name IN ('Developer','SEO','CTO')
  ORDER BY u.name ASC")->fetchAll();

$tasks=$pdo->query("SELECT t.*, GROUP_CONCAT(u.name SEPARATOR ', ') AS assignee_names
  FROM tasks t
  LEFT JOIN task_assignees ta ON ta.task_id=t.id
  LEFT JOIN users u ON u.id=ta.user_id
  WHERE t.workspace_id=$ws AND t.project_id=$id
  GROUP BY t.id
  ORDER BY t.id DESC")->fetchAll();
$by_phase=[];
foreach($tasks as $t){ $by_phase[$t['phase_id']][]=$t; }
?>
<div class="d-flex justify-content-between align-items-start mb-2">
  <div>
    <h2 class="mb-0"><?=h($project['name'])?></h2>
    <div class="text-muted small">
      Client: <b><?=h($project['client_name'])?></b> • Type: <?=h($project['type_name'])?> • Status: <span class="badge badge-soft"><?=h($project['status_name'])?></span>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-light" href="docs.php?project_id=<?=h($id)?>">Project Docs</a>
    <?php if($can_manage): ?>
      <button class="btn btn-yellow" data-bs-toggle="modal" data-bs-target="#addPhase">Add Phase</button>
      <button class="btn btn-yellow" data-bs-toggle="modal" data-bs-target="#addTask">Add Task</button>
    <?php endif; ?>
  </div>
</div>

<?php if(!$phases): ?>
  <div class="card p-4 text-muted">No phases yet. Add a phase to start.</div>
<?php endif; ?>

<?php foreach($phases as $ph): ?>
  <div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="fw-semibold"><?=h($ph['name'])?></div>
      <div class="text-muted small">Phase</div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead><tr><th>Task</th><th>Status</th><th>Assignees</th><th>Due</th><th></th></tr></thead>
        <tbody>
        <?php foreach(($by_phase[$ph['id']] ?? []) as $t): ?>
          <tr>
            <td class="fw-semibold"><?=h($t['title'])?> <?php if($t['locked_at']): ?><span class="badge bg-secondary">Locked</span><?php endif; ?></td>
            <td><span class="badge badge-soft"><?=h($t['status'])?></span></td>
            <td class="text-muted"><?=h($t['assignee_names'] ?? '—')?></td>
            <td class="text-muted"><?=h($t['due_date'] ? format_date($t['due_date']) : '—')?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-light" href="task_view.php?id=<?=h($t['id'])?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!($by_phase[$ph['id']] ?? [])): ?><tr><td colspan="5" class="text-muted">No tasks in this phase.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<?php if($can_manage): ?>
<div class="modal fade" id="addPhase" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content card p-3">
      <div class="modal-header border-0"><h5 class="modal-title">Add Phase</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="add_phase" value="1">
        <div class="modal-body">
          <label class="form-label">Phase Name</label>
          <input class="form-control" name="phase_name" required placeholder="e.g. Onboarding">
        </div>
        <div class="modal-footer border-0">
          <button class="btn btn-outline-light" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-yellow" type="submit">Add</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="addTask" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content card p-3">
      <div class="modal-header border-0"><h5 class="modal-title">Add Task</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="add_task" value="1">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Title</label><input class="form-control" name="title" required></div>
            <div class="col-md-4"><label class="form-label">Phase</label>
              <select class="form-select" name="phase_id" required><?php foreach($phases as $ph): ?><option value="<?=h($ph['id'])?>"><?=h($ph['name'])?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="4"></textarea></div>
            <div class="col-md-4"><label class="form-label">Status</label>
              <select class="form-select" name="status"><?php foreach($statuses as $s): ?><option value="<?=h($s)?>"><?=h($s)?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-4"><label class="form-label">Due Date</label><input class="form-control" type="date" name="due_date"></div>
            <div class="col-md-4"><label class="form-label">Assignees</label>
              <select class="form-select" name="assignees[]" multiple><?php foreach($team as $m): ?><option value="<?=h($m['id'])?>"><?=h($m['name'])?> (<?=h($m['role_name'])?>)</option><?php endforeach; ?></select>
              <div class="small-help mt-1">Hold Ctrl to select multiple.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer border-0">
          <button class="btn btn-outline-light" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-yellow" type="submit">Create Task</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/layout_end.php'; ?>
