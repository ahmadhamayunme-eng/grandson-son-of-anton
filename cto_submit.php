<?php
require_once __DIR__ . '/layout.php';
auth_require_any(['CTO','Super Admin']);
$pdo=db(); $ws=auth_workspace_id();

$rows=$pdo->query("SELECT t.id,t.title,t.status,t.updated_at,p.name AS project_name,c.name AS client_name,
  GROUP_CONCAT(u.name SEPARATOR ', ') AS assignees
  FROM tasks t
  JOIN projects p ON p.id=t.project_id
  JOIN clients c ON c.id=p.client_id
  LEFT JOIN task_assignees ta ON ta.task_id=t.id
  LEFT JOIN users u ON u.id=ta.user_id
  WHERE t.workspace_id=$ws AND t.status='Approved (Ready to Submit)'
  GROUP BY t.id
  ORDER BY t.updated_at DESC")->fetchAll();

$total=count($rows);
$projects_ready=[];
foreach($rows as $r){ $projects_ready[$r['project_name']]=1; }
$project_count=count($projects_ready);
?>
<style>
  .submit-shell{border:1px solid rgba(255,255,255,.11);border-radius:16px;background:linear-gradient(180deg,#101010,#070707);overflow:hidden}
  .submit-head{padding:1rem 1.1rem;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap}
  .submit-title{font-size:2rem;font-weight:600;margin:0}
  .submit-sub{color:rgba(220,220,220,.7);margin-top:.2rem}
  .submit-stats{display:flex;gap:.5rem;flex-wrap:wrap}
  .submit-stat{padding:.42rem .7rem;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04);color:#eaeaea;font-size:.88rem}
  .submit-body{padding:1rem 1.1rem}
  .submit-card{border:1px solid rgba(255,255,255,.1);border-radius:12px;background:linear-gradient(160deg,rgba(255,255,255,.04),rgba(255,255,255,.02));margin-bottom:.7rem}
  .submit-row{display:flex;justify-content:space-between;gap:1rem;padding:.9rem 1rem;align-items:center;flex-wrap:wrap}
  .submit-task{font-size:1.2rem;font-weight:600}
  .submit-meta{color:rgba(220,220,220,.72);font-size:.92rem}
  .submit-right{display:flex;align-items:center;gap:.65rem;flex-wrap:wrap}
  .ready-chip{padding:.3rem .62rem;border-radius:999px;border:1px solid rgba(87,200,143,.45);background:rgba(87,200,143,.14);color:#8fe8b4;font-size:.8rem}
  .assignee-chip{padding:.3rem .62rem;border-radius:999px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.06);color:#e5e5e5;font-size:.8rem;max-width:340px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .empty-box{padding:2rem 1rem;text-align:center;border:1px dashed rgba(255,255,255,.18);border-radius:12px;color:rgba(220,220,220,.72)}
</style>

<div class="submit-shell">
  <div class="submit-head">
    <div>
      <h2 class="submit-title">Submit to Client</h2>
      <div class="submit-sub">Approved tasks that are ready for final client delivery.</div>
    </div>
    <div class="submit-stats">
      <span class="submit-stat">Ready Tasks: <?= (int)$total ?></span>
      <span class="submit-stat">Projects: <?= (int)$project_count ?></span>
      <span class="submit-stat">Status: Approved</span>
    </div>
  </div>

  <div class="submit-body">
    <?php if(!$rows): ?>
      <div class="empty-box">No tasks are currently ready to submit.</div>
    <?php else: ?>
      <?php foreach($rows as $r): ?>
        <div class="submit-card">
          <div class="submit-row">
            <div>
              <div class="submit-task"><?=h($r['title'])?></div>
              <div class="submit-meta"><?=h($r['client_name'])?> · <?=h($r['project_name'])?> · Updated <?=h($r['updated_at'])?></div>
            </div>
            <div class="submit-right">
              <span class="ready-chip">Ready to Submit</span>
              <span class="assignee-chip" title="<?=h($r['assignees'] ?? '—')?>"><?=h($r['assignees'] ?? 'Unassigned')?></span>
              <a class="btn btn-yellow btn-sm" href="task_view.php?id=<?=h($r['id'])?>">Open & Submit</a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
