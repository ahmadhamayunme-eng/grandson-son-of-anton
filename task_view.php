<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();
$u=auth_user(); $role=$u['role_name'] ?? '';
$can_cto=in_array($role,['CTO','Super Admin'],true);
$can_manage=in_array($role,['CEO','CTO','Super Admin'],true);

function table_exists(PDO $pdo, string $table): bool {
  try { $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1"); return true; }
  catch (Throwable $e) { return false; }
}
function column_exists(PDO $pdo, string $table, string $column): bool {
  try {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch();
  } catch (Throwable $e) {
    return false;
  }
}

$has_comment_parent = column_exists($pdo, 'comments', 'parent_comment_id');
$has_attachments_table = table_exists($pdo, 'task_attachments');

$id=(int)($_GET['id'] ?? 0);
try {
  $stmt=$pdo->prepare("SELECT t.*, p.name AS project_name, c.name AS client_name, ph.name AS phase_name
    FROM tasks t
    JOIN projects p ON p.id=t.project_id
    JOIN clients c ON c.id=p.client_id
    LEFT JOIN phases ph ON ph.id=t.phase_id
    WHERE t.id=? AND t.workspace_id=?");
  $stmt->execute([$id,$ws]);
  $task=$stmt->fetch();
} catch (Throwable $e) {
  $stmt=$pdo->prepare("SELECT t.*, p.name AS project_name, c.name AS client_name, NULL AS phase_name
    FROM tasks t
    JOIN projects p ON p.id=t.project_id
    JOIN clients c ON c.id=p.client_id
    WHERE t.id=? AND t.workspace_id=?");
  $stmt->execute([$id,$ws]);
  $task=$stmt->fetch();
}
if(!$task){ echo "<h3>Task not found</h3>"; require __DIR__ . '/layout_end.php'; exit; }

$assignable_users=$pdo->query("SELECT u.id,u.name,r.name AS role_name
  FROM users u JOIN roles r ON r.id=u.role_id
  WHERE u.workspace_id=$ws AND u.is_active=1 AND r.name IN ('Developer','SEO')
  ORDER BY u.name ASC")->fetchAll();
$assignable_user_ids=array_map('intval',array_column($assignable_users,'id'));

$assignees=$pdo->prepare("SELECT u.id,u.name FROM task_assignees ta JOIN users u ON u.id=ta.user_id WHERE ta.task_id=? ORDER BY u.name ASC");
$assignees->execute([$id]);
$assignees=$assignees->fetchAll();
$assignee_ids=array_map('intval',array_column($assignees,'id'));
$assignee_names=array_map(fn($r)=>$r['name'],$assignees);
$is_assignee=in_array($u['name'], $assignee_names, true);

$locked= (bool)$task['locked_at'];

try {
  $statuses=$pdo->query("SELECT name FROM task_statuses WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();
  $statuses=array_map(fn($r)=>$r['name'],$statuses);
} catch (Throwable $e) {
  $statuses=[];
}
if(!$statuses){ $statuses=['Backlog','To Do','In Progress','Completed (Needs CTO Review)','Approved (Ready to Submit)','Submitted to Client']; }

if($_SERVER['REQUEST_METHOD']==='POST'){
  require_post(); csrf_verify();

  if(isset($_POST['assign_task'])){
    if(!$can_manage){ flash_set('error','No permission.'); redirect("task_view.php?id=$id"); }
    $selected=array_map('intval', $_POST['assignees'] ?? []);
    $selected=array_values(array_unique(array_intersect($selected,$assignable_user_ids)));
    $pdo->prepare("DELETE FROM task_assignees WHERE task_id=?")->execute([$id]);
    foreach($selected as $uid){
      $pdo->prepare("INSERT INTO task_assignees (task_id,user_id) VALUES (?,?)")->execute([$id,$uid]);
    }
    flash_set('success','Task assignees updated.');
    redirect("task_view.php?id=$id");
  }

  if(isset($_POST['update_task'])){
    if($locked && !$can_manage){ flash_set('error','Task is locked.'); redirect("task_view.php?id=$id"); }
    $new_status=trim($_POST['status'] ?? $task['status']);
    $new_note=trim($_POST['internal_note'] ?? '');
    $pdo->prepare("UPDATE tasks SET status=?, internal_note=?, updated_at=? WHERE id=? AND workspace_id=?")
        ->execute([$new_status,$new_note?:null,now(),$id,$ws]);
    flash_set('success','Task updated.');
    redirect("task_view.php?id=$id");
  }

  if(isset($_POST['lock_task'])){
    if(!$can_manage){ flash_set('error','No permission.'); redirect("task_view.php?id=$id"); }
    $pdo->prepare("UPDATE tasks SET locked_at=?, locked_by=? WHERE id=? AND workspace_id=?")
        ->execute([now(),$u['id'],$id,$ws]);
    flash_set('success','Task locked.');
    redirect("task_view.php?id=$id");
  }

  if(isset($_POST['unlock_task'])){
    if(!$can_manage){ flash_set('error','No permission.'); redirect("task_view.php?id=$id"); }
    $pdo->prepare("UPDATE tasks SET locked_at=NULL, locked_by=NULL WHERE id=? AND workspace_id=?")->execute([$id,$ws]);
    flash_set('success','Task unlocked.');
    redirect("task_view.php?id=$id");
  }

  if(isset($_POST['add_comment'])){
    $body=trim($_POST['comment'] ?? '');
    $parent_id = isset($_POST['parent_comment_id']) && $_POST['parent_comment_id'] !== ''
      ? (int)$_POST['parent_comment_id']
      : null;
    if($body===''){ flash_set('error','Comment cannot be empty.'); redirect("task_view.php?id=$id"); }
    if($has_comment_parent){
      $pdo->prepare("INSERT INTO comments (workspace_id,task_id,author_user_id,body,parent_comment_id,created_at) VALUES (?,?,?,?,?,?)")
          ->execute([$ws,$id,$u['id'],$body,$parent_id,now()]);
    } else {
      $pdo->prepare("INSERT INTO comments (workspace_id,task_id,author_user_id,body,created_at) VALUES (?,?,?,?,?)")
          ->execute([$ws,$id,$u['id'],$body,now()]);
    }
    flash_set('success','Comment added.');
    redirect("task_view.php?id=$id");
  }

  if(isset($_POST['delete_attachment'])){
    if(!$has_attachments_table){
      flash_set('error','Attachments are not available on this database yet.');
      redirect("task_view.php?id=$id");
    }
    $att_id = (int)($_POST['attachment_id'] ?? 0);
    if($att_id>0){
      $a = $pdo->prepare("SELECT stored_name FROM task_attachments WHERE id=? AND workspace_id=? AND task_id=?");
      $a->execute([$att_id,$ws,$id]);
      $a = $a->fetch();
      if($a){
        @unlink(__DIR__."/uploads/task_attachments/".$a['stored_name']);
        $pdo->prepare("DELETE FROM task_attachments WHERE id=? AND workspace_id=?")->execute([$att_id,$ws]);
        flash_set('success','Attachment deleted.');
      }
    }
    redirect("task_view.php?id=$id");
  }

  if(isset($_POST['cto_action'])){
    if(!$can_cto){ flash_set('error','No permission.'); redirect("task_view.php?id=$id"); }
    $action=$_POST['cto_action'];
    if($action==='approve'){
      $pdo->prepare("UPDATE tasks SET status='Approved (Ready to Submit)', updated_at=? WHERE id=? AND workspace_id=?")->execute([now(),$id,$ws]);
      flash_set('success','Task approved.');
    } elseif($action==='reject'){
      $reason=trim($_POST['cto_reason'] ?? 'Needs changes.');
      $pdo->prepare("UPDATE tasks SET status='In Progress', cto_feedback=?, updated_at=? WHERE id=? AND workspace_id=?")
          ->execute([$reason,now(),$id,$ws]);
      flash_set('success','Task sent back to In Progress.');
    }
    redirect("task_view.php?id=$id");
  }

  if(isset($_POST['submit_to_client'])){
    if(!$can_cto){ flash_set('error','No permission.'); redirect("task_view.php?id=$id"); }
    $pdo->prepare("UPDATE tasks SET status='Submitted to Client', submitted_at=?, submitted_by=? WHERE id=? AND workspace_id=?")
        ->execute([now(),$u['id'],$id,$ws]);
    flash_set('success','Task marked as Submitted to Client.');
    redirect("task_view.php?id=$id");
  }
}

if($has_comment_parent){
  $comments=$pdo->prepare("SELECT c.id,c.parent_comment_id,c.body,c.created_at,u.name AS author,u.id AS author_id
    FROM comments c JOIN users u ON u.id=c.author_user_id
    WHERE c.task_id=? AND c.workspace_id=?
    ORDER BY c.id ASC");
} else {
  $comments=$pdo->prepare("SELECT c.id,NULL AS parent_comment_id,c.body,c.created_at,u.name AS author,u.id AS author_id
    FROM comments c JOIN users u ON u.id=c.author_user_id
    WHERE c.task_id=? AND c.workspace_id=?
    ORDER BY c.id ASC");
}
$comments->execute([$id,$ws]);
$comments=$comments->fetchAll();

$attachments=[];
if($has_attachments_table){
  $attachments=$pdo->prepare("SELECT a.*, u.name AS uploader FROM task_attachments a JOIN users u ON u.id=a.uploaded_by WHERE a.task_id=? AND a.workspace_id=? ORDER BY a.id DESC");
  $attachments->execute([$id,$ws]);
  $attachments=$attachments->fetchAll();
}

// build comment tree
$byParent=[];
foreach($comments as $c){ $pid = $c['parent_comment_id'] ?? 0; $pid = $pid ? (int)$pid : 0; $byParent[$pid][]=$c; }
function render_comment_tree($parentId,$byParent,$level=0,$allowReply=true){
  if(!isset($byParent[$parentId])) return;
  foreach($byParent[$parentId] as $c){
    $pad = min(40, $level*18);
    echo '<div class="p-3 rounded mb-2" style="margin-left:'.$pad.'px;background:#0f0f0f;border:1px solid rgba(255,255,255,.08);">';
    echo '<div class="d-flex justify-content-between"><div class="fw-semibold">'.h($c['author']).'</div><div class="text-muted small">'.h($c['created_at']).'</div></div>';
    echo '<div class="mt-2">'.nl2br(h($c['body'])).'</div>';
    if($allowReply){
      echo '<div class="mt-2"><button class="btn btn-sm btn-outline-light" type="button" data-author="'.h((string)$c['author']).'" onclick="setReply('.(int)$c['id'].', this.dataset.author)">Reply</button></div>';
    }
    echo '</div>';
    // Keep reply capability consistent through nested levels (used during conflict resolution).
    render_comment_tree((int)$c['id'],$byParent,$level+1,$allowReply);
  }
}

?>
<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h2 class="mb-1"><?=h($task['title'])?></h2>
    <div class="text-muted small">
      Client: <b><?=h($task['client_name'])?></b> • Project: <b><?=h($task['project_name'])?></b> • Phase: <?=h($task['phase_name'] ?? '—')?>
    </div>
    <?php if($task['cto_feedback']): ?><div class="alert alert-warning mt-3 mb-0"><b>CTO Feedback:</b> <?=h($task['cto_feedback'])?></div><?php endif; ?>
  </div>
  <div class="d-flex gap-2">
    <?php if($can_manage && !$locked): ?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><button class="btn btn-outline-light" name="lock_task" value="1">Lock</button></form><?php endif; ?>
    <?php if($can_manage && $locked): ?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><button class="btn btn-outline-light" name="unlock_task" value="1">Unlock</button></form><?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card p-3 mb-3">
      <div class="text-muted small mb-1">Description</div>
      <div><?=nl2br(h($task['description'] ?? '—'))?></div>
    </div>

    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-semibold">Comments</div>
        <div class="text-muted small"><?=count($comments)?> total</div>
      </div>
      <form method="post" class="mb-3" id="commentForm">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="parent_comment_id" id="parent_comment_id" value="">
        <?php if($has_comment_parent): ?>
        <div class="d-flex justify-content-between align-items-center mb-1">
          <div class="text-muted small" id="replyLabel"></div>
          <button type="button" class="btn btn-sm btn-outline-light" onclick="clearReply()">Clear Reply</button>
        </div>
        <?php else: ?>
        <div class="text-muted small mb-1">Threaded replies are unavailable until the latest DB migration is applied.</div>
        <?php endif; ?>
        <textarea class="form-control" name="comment" rows="3" placeholder="Write an internal comment..."></textarea>
        <div class="d-flex justify-content-end mt-2"><button class="btn btn-yellow" name="add_comment" value="1">Post</button></div>
      </form>
      <script>
        function setReply(id, author){
          document.getElementById("parent_comment_id").value = id;
          document.getElementById("replyLabel").textContent = "Replying to " + author + " (#"+id+")";
          document.querySelector("textarea[name=comment]").focus();
        }
        function clearReply(){
          document.getElementById("parent_comment_id").value = "";
          document.getElementById("replyLabel").textContent = "";
        }
      </script>
      <div class="d-flex flex-column">
        <?php render_comment_tree(0, $byParent, 0, $has_comment_parent); ?>
        <?php if(!$comments): ?><div class="text-muted">No comments yet.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card p-3 mb-3">
      <div class="fw-semibold mb-2">Task Details</div>
      <div class="mb-2"><span class="text-muted">Status:</span> <span class="badge badge-soft"><?=h($task['status'])?></span></div>
      <div class="mb-2"><span class="text-muted">Assignees:</span> <?=h($assignee_names ? implode(', ',$assignee_names) : '—')?></div>
      <div class="mb-2"><span class="text-muted">Due:</span> <?=h($task['due_date'] ? format_date($task['due_date']) : '—')?></div>
      <div class="mb-2"><span class="text-muted">Locked:</span> <?= $locked ? '<span class="badge bg-secondary">Yes</span>' : '<span class="text-muted">No</span>' ?></div>
    </div>
    <div class="card p-3 mb-3">
      <div class="fw-semibold mb-2">Attachments</div>
      <?php if($has_attachments_table): ?>
      <form method="post" action="upload_task_attachment.php" enctype="multipart/form-data" class="mb-3">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="task_id" value="<?= (int)$id ?>">
        <input class="form-control" type="file" name="file" required>
        <div class="d-flex justify-content-end mt-2"><button class="btn btn-outline-light">Upload</button></div>
      </form>
      <?php else: ?>
      <div class="text-muted mb-3">Attachments are unavailable until the latest DB migration is applied.</div>
      <?php endif; ?>
      <div class="d-flex flex-column gap-2">
        <?php foreach($attachments as $a): ?>
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <a class="link-light" href="download.php?id=<?= (int)$a['id'] ?>"><?= h($a['original_name']) ?></a>
              <div class="text-muted small">by <?= h($a['uploader']) ?> • <?= h($a['created_at']) ?></div>
            </div>
            <form method="post" onsubmit="return confirm('Delete attachment?');">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="attachment_id" value="<?= (int)$a['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" name="delete_attachment" value="1">Delete</button>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if(!$attachments): ?><div class="text-muted">No attachments yet.</div><?php endif; ?>
      </div>
    </div>


    <?php if($can_manage): ?>
    <div class="card p-3 mb-3">
      <div class="fw-semibold mb-2">Assign Task</div>
      <?php if(!$assignable_users): ?>
        <div class="text-muted">No active Developer or SEO users found in this workspace.</div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <div class="mb-2">
          <label class="form-label">Assignees (Developer / SEO)</label>
          <select class="form-select" name="assignees[]" multiple>
            <?php foreach($assignable_users as $au): ?>
              <option value="<?= (int)$au['id'] ?>" <?= in_array((int)$au['id'],$assignee_ids,true) ? 'selected' : '' ?>><?= h($au['name']) ?> (<?= h($au['role_name']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <div class="small-help mt-1">Hold Ctrl (or Cmd on Mac) to select multiple users.</div>
        </div>
        <button class="btn btn-yellow w-100" name="assign_task" value="1">Save Assignees</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card p-3 mb-3">
      <div class="fw-semibold mb-2">Update Status</div>
      <?php if($locked && !$can_manage): ?>
        <div class="text-muted">This task is locked.</div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <div class="mb-2">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <?php foreach($statuses as $s): ?><option value="<?=h($s)?>" <?= $task['status']===$s ? 'selected' : '' ?>><?=h($s)?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Internal Note</label>
          <textarea class="form-control" name="internal_note" rows="3"><?=h($task['internal_note'] ?? '')?></textarea>
        </div>
        <button class="btn btn-yellow w-100" name="update_task" value="1">Save</button>
      </form>
      <?php endif; ?>
    </div>

    <?php if($can_cto): ?>
      <div class="card p-3 mb-3">
        <div class="fw-semibold mb-2">CTO Actions</div>
        <div class="small-help mb-2">Use these when a task is marked <b>Completed (Needs CTO Review)</b>.</div>
        <form method="post" class="mb-2">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <button class="btn btn-outline-light w-100" name="cto_action" value="approve">Approve (Ready to Submit)</button>
        </form>
        <form method="post">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <textarea class="form-control mb-2" name="cto_reason" rows="2" placeholder="Reason to send back..."></textarea>
          <button class="btn btn-outline-light w-100" name="cto_action" value="reject">Send Back (In Progress)</button>
        </form>
      </div>

      <div class="card p-3">
        <div class="fw-semibold mb-2">Submit to Client</div>
        <div class="small-help mb-2">Mark task as <b>Submitted to Client</b> after you push to production / deliver.</div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <button class="btn btn-yellow w-100" name="submit_to_client" value="1">Mark Submitted</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>
