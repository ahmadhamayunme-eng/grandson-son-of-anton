<?php
require_once __DIR__ . '/layout.php';
auth_require_any(['Manager','Super Admin']);
$pdo=db(); $ws=auth_workspace_id(); $u=auth_user();

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['approve_task'])){
  require_post(); csrf_verify();
  $task_id=(int)($_POST['task_id'] ?? 0);
  if($task_id<=0){ flash_set('error','Invalid task selected.'); redirect('manager_review.php'); }
  $st=$pdo->prepare("UPDATE tasks SET status='Approved', updated_at=? WHERE id=? AND workspace_id=? AND status IN ('Completed','Completed (Needs Manager Review)')");
  $st->execute([now(),$task_id,$ws]);
  flash_set($st->rowCount() ? 'success' : 'error', $st->rowCount() ? 'Task approved and moved to Submit to Client.' : 'Task was not waiting for manager review.');
  redirect('manager_submit.php');
}

$stmt=$pdo->prepare("SELECT t.id,t.title,t.status,t.updated_at,p.name AS project_name,c.name AS client_name,
  GROUP_CONCAT(u.name SEPARATOR ', ') AS assignees
  FROM tasks t
  JOIN projects p ON p.id=t.project_id
  JOIN clients c ON c.id=p.client_id
  LEFT JOIN task_assignees ta ON ta.task_id=t.id
  LEFT JOIN users u ON u.id=ta.user_id
  WHERE t.workspace_id=? AND t.status IN ('Completed','Completed (Needs Manager Review)')
  GROUP BY t.id
  ORDER BY t.updated_at DESC");
$stmt->execute([$ws]);
$rows=$stmt->fetchAll();

$total=count($rows);
$unique_clients=[];
foreach($rows as $r){ $unique_clients[$r['client_name']]=1; }
$client_count=count($unique_clients);
?>
<style>
  .manager-shell{border:1px solid rgba(255,255,255,.11);border-radius:16px;background:linear-gradient(180deg,#101010,#070707);overflow:hidden}
  .manager-head{padding:1rem 1.1rem;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap}
  .manager-title{font-size:2rem;font-weight:600;margin:0}
  .manager-sub{color:rgba(220,220,220,.7);margin-top:.2rem}
  .manager-badges{display:flex;gap:.5rem;flex-wrap:wrap}
  .manager-badge{padding:.42rem .7rem;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04);color:#eaeaea;font-size:.88rem}
  .manager-body{padding:1rem 1.1rem}
  .queue-card{border:1px solid rgba(255,255,255,.1);border-radius:12px;background:linear-gradient(160deg,rgba(255,255,255,.04),rgba(255,255,255,.02));margin-bottom:.7rem}
  .queue-row{display:flex;justify-content:space-between;gap:1rem;padding:.9rem 1rem;align-items:center;flex-wrap:wrap}
  .queue-title{font-size:1.25rem;font-weight:600}
  .queue-meta{color:rgba(220,220,220,.72);font-size:.92rem}
  .queue-right{display:flex;align-items:center;gap:.65rem;flex-wrap:wrap}
  .status-chip{padding:.3rem .62rem;border-radius:999px;border:1px solid rgba(246,212,105,.45);background:rgba(246,212,105,.12);color:#ffcc00;font-size:.8rem}
  .assignee-chip{padding:.3rem .62rem;border-radius:999px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.06);color:#e5e5e5;font-size:.8rem;max-width:340px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .empty-box{padding:2rem 1rem;text-align:center;border:1px dashed rgba(255,255,255,.18);border-radius:12px;color:rgba(220,220,220,.72)}
  .inline-action{display:inline;margin:0}
</style>

<div class="manager-shell">
  <div class="manager-head">
    <div>
      <h2 class="manager-title">Manager Review Queue</h2>
      <div class="manager-sub">Completed tasks wait here until a manager approves them.</div>
    </div>
    <div class="manager-badges">
      <span class="manager-badge">Waiting: <?= (int)$total ?></span>
      <span class="manager-badge">Clients: <?= (int)$client_count ?></span>
      <span class="manager-badge">Role: Manager</span>
    </div>
  </div>

  <div class="manager-body">
    <?php if(!$rows): ?>
      <div class="empty-box">No tasks are waiting for Manager review right now.</div>
    <?php else: ?>
      <?php foreach($rows as $r): ?>
        <div class="queue-card">
          <div class="queue-row">
            <div>
              <div class="queue-title"><?=h($r['title'])?></div>
              <div class="queue-meta"><?=h($r['client_name'])?> · <?=h($r['project_name'])?> · Updated <?=h($r['updated_at'])?></div>
            </div>
            <div class="queue-right">
              <span class="status-chip"><?=h($r['status'])?></span>
              <span class="assignee-chip" title="<?=h($r['assignees'] ?? '—')?>"><?=h($r['assignees'] ?? 'Unassigned')?></span>
              <a class="btn btn-outline-light btn-sm" href="task_view.php?id=<?=h($r['id'])?>">Open</a>
              <form class="inline-action" method="post">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="task_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-yellow btn-sm" name="approve_task" value="1">Approved</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
