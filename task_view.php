<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();
$u=auth_user(); $role=isset($u['role_name']) ? $u['role_name'] : '';
$can_cto=in_array($role,['CTO','Super Admin'],true);
$can_manage=in_array($role,['CEO','CTO','Super Admin'],true);

function tv_column_exists($pdo, $table, $column) {
  try {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch();
  } catch (Exception $e) {
    return false;
  }
}


function tv_pluck($rows, $key) {
  $out = array();
  foreach ((array)$rows as $row) {
    if (is_array($row) && isset($row[$key])) {
      $out[] = $row[$key];
    }
  }
  return $out;
}

$has_comment_parent = tv_column_exists($pdo, 'comments', 'parent_comment_id');

$id=isset($_GET['id']) ? (int)$_GET['id'] : 0;
try {
  $stmt=$pdo->prepare("SELECT t.*, p.name AS project_name, c.name AS client_name, ph.name AS phase_name
    FROM tasks t
    JOIN projects p ON p.id=t.project_id
    JOIN clients c ON c.id=p.client_id
    LEFT JOIN phases ph ON ph.id=t.phase_id
    WHERE t.id=? AND t.workspace_id=?");
  $stmt->execute([$id,$ws]);
  $task=$stmt->fetch();
} catch (Exception $e) {
  $stmt=$pdo->prepare("SELECT t.*, p.name AS project_name, c.name AS client_name, NULL AS phase_name
    FROM tasks t
    JOIN projects p ON p.id=t.project_id
    JOIN clients c ON c.id=p.client_id
    WHERE t.id=? AND t.workspace_id=?");
  $stmt->execute([$id,$ws]);
  $task=$stmt->fetch();
}
if(!$task){ echo "<h3>Task not found</h3>"; require __DIR__ . '/layout_end.php'; exit; }

$assignable_users=[];
try {
  $assignable_users=$pdo->query("SELECT u.id,u.name,r.name AS role_name
    FROM users u JOIN roles r ON r.id=u.role_id
    WHERE u.workspace_id=$ws AND u.is_active=1 AND r.name IN ('Developer','SEO')
    ORDER BY u.name ASC")->fetchAll();
} catch (Exception $e) {
  $assignable_users=[];
}
$assignable_user_ids=array_map('intval', tv_pluck($assignable_users,'id'));

$assignees=[];
try {
  $assigneesStmt=$pdo->prepare("SELECT u.id,u.name FROM task_assignees ta JOIN users u ON u.id=ta.user_id WHERE ta.task_id=? ORDER BY u.name ASC");
  $assigneesStmt->execute([$id]);
  $assignees=$assigneesStmt->fetchAll();
} catch (Exception $e) {
  $assignees=[];
}
$assignee_ids=array_map('intval', tv_pluck($assignees,'id'));
$assignee_names=array_map(function($r){ return $r['name']; },$assignees);
$is_assignee=in_array($u['name'], $assignee_names, true);

$locked= (bool)$task['locked_at'];

try {
  $statuses=$pdo->query("SELECT name FROM task_statuses WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();
  $statuses=array_map(function($r){ return $r['name']; },$statuses);
} catch (Exception $e) {
  $statuses=[];
}
if(!$statuses){ $statuses=['Backlog','To Do','In Progress','Completed (Needs CTO Review)','Approved (Ready to Submit)','Submitted to Client']; }

if($_SERVER['REQUEST_METHOD']==='POST'){
  require_post(); csrf_verify();

  if(isset($_POST['assign_task'])){
    if(!$can_manage){ flash_set('error','No permission.'); redirect("task_view.php?id=$id"); }
    $selected=array_map('intval', isset($_POST['assignees']) ? $_POST['assignees'] : []);
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
    $new_status=trim(isset($_POST['status']) ? $_POST['status'] : $task['status']);
    $new_note=trim(isset($_POST['internal_note']) ? $_POST['internal_note'] : '');
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
    $body=trim(isset($_POST['comment']) ? $_POST['comment'] : '');
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

  if(isset($_POST['cto_action'])){
    if(!$can_cto){ flash_set('error','No permission.'); redirect("task_view.php?id=$id"); }
    $action=$_POST['cto_action'];
    if($action==='approve'){
      $pdo->prepare("UPDATE tasks SET status='Approved (Ready to Submit)', updated_at=? WHERE id=? AND workspace_id=?")->execute([now(),$id,$ws]);
      flash_set('success','Task approved.');
    } elseif($action==='reject'){
      $reason=trim(isset($_POST['cto_reason']) ? $_POST['cto_reason'] : 'Needs changes.');
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

$comments=[];
try {
  if($has_comment_parent){
    $commentsStmt=$pdo->prepare("SELECT c.id,c.parent_comment_id,c.body,c.created_at,u.name AS author,u.id AS author_id
      FROM comments c JOIN users u ON u.id=c.author_user_id
      WHERE c.task_id=? AND c.workspace_id=?
      ORDER BY c.id ASC");
  } else {
    $commentsStmt=$pdo->prepare("SELECT c.id,NULL AS parent_comment_id,c.body,c.created_at,u.name AS author,u.id AS author_id
      FROM comments c JOIN users u ON u.id=c.author_user_id
      WHERE c.task_id=? AND c.workspace_id=?
      ORDER BY c.id ASC");
  }
  $commentsStmt->execute([$id,$ws]);
  $comments=$commentsStmt->fetchAll();
} catch (Exception $e) {
  $comments=[];
}

$attachments=[];
$attachments_ready=false;
try {
  $probe=$pdo->query("SELECT id FROM task_attachments LIMIT 1");
  $attachments_ready=($probe instanceof PDOStatement);
  if($attachments_ready){
    $attStmt=$pdo->prepare("SELECT id,original_name,size_bytes,created_at FROM task_attachments WHERE workspace_id=? AND task_id=? ORDER BY id DESC LIMIT 8");
    if($attStmt instanceof PDOStatement){
      $attStmt->execute([$ws,$id]);
      $rows=$attStmt->fetchAll();
      $attachments=is_array($rows) ? $rows : [];
    }
  }
} catch (Exception $e) {
  $attachments=[];
  $attachments_ready=false;
}

function tv_initials($name){
  $name=trim((string)$name);
  if($name==='') return '?';
  $parts=preg_split('/\s+/', $name);
  $a=isset($parts[0][0]) ? strtoupper($parts[0][0]) : '';
  $b='';
  if(count($parts)>1){
    $last=$parts[count($parts)-1];
    $b=isset($last[0]) ? strtoupper($last[0]) : '';
  } elseif(isset($parts[0][1])) {
    $b=strtoupper($parts[0][1]);
  }
  return $a.$b;
}

function tv_priority_from_task($task){
  $status=strtolower((string)(isset($task['status']) ? $task['status'] : ''));
  if(strpos($status,'submitted')!==false || strpos($status,'approved')!==false || strpos($status,'completed')!==false){
    return ['label'=>'Low','class'=>'priority-low'];
  }
  if(empty($task['due_date'])) return ['label'=>'Medium','class'=>'priority-medium'];
  $dueTs=strtotime((string)$task['due_date']);
  $todayTs=strtotime(date('Y-m-d'));
  $diff=(int)floor(($dueTs-$todayTs)/86400);
  if($diff<=1) return ['label'=>'Critical','class'=>'priority-critical'];
  if($diff<=3) return ['label'=>'High','class'=>'priority-high'];
  if($diff<=7) return ['label'=>'Medium','class'=>'priority-medium'];
  return ['label'=>'Low','class'=>'priority-low'];
}

$priority=tv_priority_from_task($task);
$section_name=isset($task['phase_name']) && $task['phase_name'] ? $task['phase_name'] : 'General';
$due_display=$task['due_date'] ? format_date($task['due_date']) : 'Not set';

// build comment tree
$byParent=[];
foreach($comments as $c){ $pid = isset($c['parent_comment_id']) ? $c['parent_comment_id'] : 0; $pid = $pid ? (int)$pid : 0; $byParent[$pid][]=$c; }
function render_comment_tree($parentId,$byParent,$level=0,$allowReply=true,&$visited=array()){
  if($level > 30) return;
  if(!isset($byParent[$parentId])) return;
  foreach($byParent[$parentId] as $c){
    $cid = (int)$c['id'];
    if(isset($visited[$cid])) continue;
    $visited[$cid] = 1;
    $pad = min(60, $level*20);
    $author=(string)$c['author'];
    $initials = tv_initials($author);
    echo '<div class="comment-thread-item mb-3" style="margin-left:'.$pad.'px;">';
    echo '<div class="d-flex gap-3">';
    echo '<div class="comment-avatar">'.h($initials).'</div>';
    echo '<div class="comment-body-wrap flex-grow-1">';
    echo '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2"><div class="fw-semibold">'.h($author).'</div><div class="text-muted small">'.h($c['created_at']).'</div></div>';
    echo '<div class="comment-copy mt-2">'.nl2br(h($c['body'])).'</div>';
    if($allowReply){
      echo '<div class="mt-2"><button class="btn btn-sm task-btn-outline" type="button" data-author="'.h($author).'" onclick="setReply('.$cid.', this.dataset.author)">Reply</button></div>';
    }
    echo '</div></div></div>';
    render_comment_tree($cid,$byParent,$level+1,$allowReply,$visited);
  }
}

?>
<style>
  .task-page{position:relative;padding:1.25rem;border:1px solid rgba(255,255,255,.15);border-radius:18px;background:
    radial-gradient(1200px 600px at 15% -5%, rgba(255,255,255,.06), transparent 65%),
    radial-gradient(900px 500px at 95% 0, rgba(255,255,255,.04), transparent 70%),
    linear-gradient(180deg, rgba(10,10,10,.98), rgba(7,7,7,.96));
    box-shadow:0 24px 44px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.05)}
  .task-topbar{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;border-bottom:1px solid rgba(255,255,255,.1);padding-bottom:1rem;margin-bottom:1rem}
  .task-title{font-size:2.1rem;font-weight:500;line-height:1.2;margin:0}
  .task-meta{color:rgba(214,221,242,.78);font-size:1rem;margin-top:.25rem}
  .task-tabs{display:flex;gap:.45rem;flex-wrap:wrap;margin-top:.9rem}
  .task-tab{padding:.5rem .95rem;border-radius:.65rem;border:1px solid rgba(255,255,255,.17);text-decoration:none;color:rgba(224,224,224,.88);background:rgba(255,255,255,.05)}
  .task-tab.active{color:#ffe5a4;border-color:rgba(248,217,120,.5);box-shadow:inset 0 -2px 0 #f8d978;background:rgba(248,217,120,.16)}
  .task-actions{display:flex;gap:.55rem;flex-wrap:wrap;justify-content:flex-end}
  .task-btn-outline{border:1px solid rgba(255,255,255,.26);color:#eff3ff;background:rgba(255,255,255,.05)}
  .task-btn-outline:hover{background:rgba(255,255,255,.13);color:#fff}
  .task-grid{display:grid;grid-template-columns:1.8fr 1fr;gap:1rem}
  .task-card{border:1px solid rgba(255,255,255,.14);border-radius:14px;background:linear-gradient(165deg, rgba(28,28,28,.86), rgba(16,16,16,.92));overflow:hidden}
  .task-card .head{padding:.85rem 1rem;border-bottom:1px solid rgba(255,255,255,.1);display:flex;justify-content:space-between;align-items:center;gap:.5rem}
  .task-card .body{padding:1rem}
  .task-chip{display:inline-flex;align-items:center;padding:.36rem .7rem;border-radius:999px;border:1px solid rgba(255,255,255,.15);font-size:.84rem;background:rgba(255,255,255,.06)}
  .priority-critical{border-color:rgba(255,123,142,.45);color:#ffb3c0}
  .priority-high{border-color:rgba(255,188,116,.42);color:#ffd09e}
  .priority-medium{border-color:rgba(246,212,105,.42);color:#f8d978}
  .priority-low{border-color:rgba(132,235,169,.35);color:#8de9b2}
  .assignee-stack{display:flex;flex-wrap:wrap;gap:.4rem}
  .avatar-pill{width:33px;height:33px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;background:linear-gradient(140deg,#f8d978,#9d9d9d);color:#161b2a;border:1px solid rgba(255,255,255,.35)}
  .task-section-title{font-size:1.15rem;font-weight:600;margin-bottom:.65rem}
  .task-description{padding:1rem;border:1px solid rgba(255,255,255,.14);border-radius:12px;background:rgba(18,18,18,.68);line-height:1.7;color:rgba(233,233,233,.93)}
  .task-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:.7rem;margin-top:.85rem}
  .task-info-box{border:1px solid rgba(255,255,255,.11);border-radius:10px;background:rgba(16,16,16,.62);padding:.65rem .75rem}
  .task-info-box .label{color:rgba(204,204,204,.7);font-size:.8rem}
  .task-info-box .value{font-size:.93rem;margin-top:.2rem}
  .file-row{display:flex;justify-content:space-between;align-items:center;gap:.8rem;padding:.65rem .8rem;border:1px solid rgba(255,255,255,.12);border-radius:10px;background:rgba(14,14,14,.62);margin-bottom:.5rem}
  .comment-avatar{width:38px;height:38px;flex:0 0 38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;background:linear-gradient(140deg,#f8d978,#9d9d9d);color:#12131b}
  .comment-body-wrap{padding:.75rem .9rem;border:1px solid rgba(255,255,255,.12);border-radius:12px;background:rgba(255,255,255,.03)}
  .comment-copy{color:rgba(242,242,242,.92);line-height:1.62}
  .task-panel .form-control,.task-panel .form-select,.task-comment-form textarea{background:rgba(16,16,16,.82);border-color:rgba(255,255,255,.2);color:#ececf0}
  .small-help{color:rgba(205,205,205,.72);font-size:.82rem}
  @media (max-width: 1200px){.task-grid{grid-template-columns:1fr}}
</style>

<div class="task-page">
  <div class="task-topbar">
    <div>
      <h2 class="task-title"><?=h($task['title'])?></h2>
      <div class="task-meta"><?=h($task['project_name'])?> / <?=h($section_name)?></div>
      <div class="task-tabs">
        <span class="task-tab active">Docs</span>
      </div>
    </div>
    <div class="task-actions">
      <?php if($can_manage && !$locked): ?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><button class="btn task-btn-outline" name="lock_task" value="1">Lock</button></form><?php endif; ?>
      <?php if($can_manage && $locked): ?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><button class="btn task-btn-outline" name="unlock_task" value="1">Unlock</button></form><?php endif; ?>
      <button class="btn btn-yellow" type="button" onclick="document.getElementById('status').focus();document.getElementById('updateStatusForm').scrollIntoView({behavior:'smooth',block:'center'});">Save</button>
    </div>
  </div>

  <div class="task-grid">
    <div>
      <div class="task-card mb-3">
        <div class="head">
          <div class="d-flex gap-2 flex-wrap">
            <span class="task-chip"># <?=h($section_name)?></span>
            <span class="task-chip <?=h($priority['class'])?>">Priority: <?=h($priority['label'])?></span>
            <span class="task-chip"><?=h($task['status'])?></span>
          </div>
          <div class="assignee-stack">
            <?php foreach($assignees as $asg): ?>
              <span class="avatar-pill" title="<?=h($asg['name'])?>"><?=h(tv_initials($asg['name']))?></span>
            <?php endforeach; ?>
            <?php if(!$assignees): ?><span class="text-muted small">No assignees</span><?php endif; ?>
          </div>
        </div>
        <div class="body">
          <div class="task-section-title">Task Description</div>
          <div class="task-description"><?= nl2br(h(isset($task['description']) ? $task['description'] : 'No description provided.')) ?></div>
          <div class="task-info-grid">
            <div class="task-info-box"><div class="label">Client</div><div class="value"><?=h($task['client_name'])?></div></div>
            <div class="task-info-box"><div class="label">Due Date</div><div class="value"><?=h($due_display)?></div></div>
            <div class="task-info-box"><div class="label">Created</div><div class="value"><?=h(isset($task['created_at']) ? $task['created_at'] : '-')?></div></div>
            <div class="task-info-box"><div class="label">Updated</div><div class="value"><?=h(isset($task['updated_at']) ? $task['updated_at'] : '-')?></div></div>
          </div>
        </div>
      </div>

      <div class="task-card mb-3">
        <div class="head"><div class="fw-semibold">Task Docs</div></div>
        <div class="body">
          <?php if(!$attachments_ready): ?>
            <div class="text-muted">Attachment module not available in this workspace.</div>
          <?php elseif(!$attachments): ?>
            <div class="text-muted">No docs uploaded for this task yet.</div>
          <?php else: ?>
            <?php foreach($attachments as $att): ?>
              <div class="file-row">
                <div>
                  <div class="fw-semibold"><?=h($att['original_name'])?></div>
                  <div class="small text-muted"><?=h($att['created_at'])?></div>
                </div>
                <div class="small text-muted"><?= isset($att['size_bytes']) ? h(number_format((int)$att['size_bytes']/1024,1).' KB') : '-' ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="task-card mb-3">
        <div class="head"><div class="fw-semibold">Team Discussion</div></div>
        <div class="body">
          <form method="post" class="task-comment-form mb-3">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="parent_comment_id" id="parent_comment_id" value="">
            <div class="small-help mb-1" id="reply_hint"></div>
            <textarea class="form-control mb-2" rows="3" name="comment" placeholder="Write a comment..."></textarea>
            <button class="btn btn-yellow" name="add_comment" value="1">Add Comment</button>
          </form>
          <?php if(!$comments): ?>
            <div class="text-muted">No comments yet.</div>
          <?php else: ?>
            <?php $visited=array(); render_comment_tree(0,$byParent,0,true,$visited); ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="task-panel">
      <?php if($can_manage): ?>
      <div class="task-card mb-3">
        <div class="head"><div class="fw-semibold">Assign Task</div></div>
        <div class="body">
          <?php if(!$assignable_users): ?>
            <div class="text-muted">No active Developer or SEO users found in this workspace.</div>
          <?php else: ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <label class="form-label">Assignees (Developer / SEO)</label>
            <select class="form-select mb-2" name="assignees[]" multiple>
              <?php foreach($assignable_users as $au): ?>
                <option value="<?= (int)$au['id'] ?>" <?= in_array((int)$au['id'],$assignee_ids,true) ? 'selected' : '' ?>><?= h($au['name']) ?> (<?= h($au['role_name']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <div class="small-help mb-2">Hold Ctrl (or Cmd on Mac) to select multiple users.</div>
            <button class="btn btn-yellow w-100" name="assign_task" value="1">Save Assignees</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="task-card mb-3">
        <div class="head"><div class="fw-semibold">Update Status</div></div>
        <div class="body">
          <?php if($locked && !$can_manage): ?>
            <div class="text-muted">This task is locked.</div>
          <?php else: ?>
          <form method="post" id="updateStatusForm">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <label class="form-label">Status</label>
            <select class="form-select mb-2" name="status" id="status">
              <?php foreach($statuses as $s): ?><option value="<?=h($s)?>" <?= $task['status']===$s ? 'selected' : '' ?>><?=h($s)?></option><?php endforeach; ?>
            </select>
            <label class="form-label">Internal Note</label>
            <textarea class="form-control mb-2" name="internal_note" rows="3"><?=h(isset($task['internal_note']) ? $task['internal_note'] : '')?></textarea>
            <button class="btn btn-yellow w-100" name="update_task" value="1">Save</button>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <?php if($can_cto): ?>
      <div class="task-card mb-3">
        <div class="head"><div class="fw-semibold">CTO Actions</div></div>
        <div class="body">
          <form method="post" class="mb-2">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <button class="btn task-btn-outline w-100" name="cto_action" value="approve">Approve (Ready to Submit)</button>
          </form>
          <form method="post">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <textarea class="form-control mb-2" name="cto_reason" rows="2" placeholder="Reason to send back..."></textarea>
            <button class="btn task-btn-outline w-100" name="cto_action" value="reject">Send Back (In Progress)</button>
          </form>
        </div>
      </div>

      <div class="task-card">
        <div class="head"><div class="fw-semibold">Submit to Client</div></div>
        <div class="body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <button class="btn btn-yellow w-100" name="submit_to_client" value="1">Mark Submitted</button>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function setReply(commentId, author){
  var parent=document.getElementById('parent_comment_id');
  var hint=document.getElementById('reply_hint');
  if(parent){ parent.value=commentId || ''; }
  if(hint){ hint.textContent = commentId ? ('Replying to ' + author) : ''; }
}
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
