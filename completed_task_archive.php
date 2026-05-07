<?php
require_once __DIR__ . '/layout.php';
auth_require_any(['Manager','Super Admin']);
$pdo=db(); $ws=auth_workspace_id();

$stmt=$pdo->prepare("SELECT t.id,t.title,t.status,t.updated_at,t.submitted_at,p.name AS project_name,c.name AS client_name,
  GROUP_CONCAT(u.name SEPARATOR ', ') AS assignees
  FROM tasks t
  JOIN projects p ON p.id=t.project_id
  JOIN clients c ON c.id=p.client_id
  LEFT JOIN task_assignees ta ON ta.task_id=t.id
  LEFT JOIN users u ON u.id=ta.user_id
  WHERE t.workspace_id=? AND t.status='Submitted to Client'
  GROUP BY t.id
  ORDER BY COALESCE(t.submitted_at,t.updated_at) DESC, t.id DESC");
$stmt->execute([$ws]);
$rows=$stmt->fetchAll();

$total=count($rows);
$clients=[];
foreach($rows as $r){ $clients[$r['client_name']]=1; }
?>
<style>
  .archive-shell{border:1px solid rgba(255,255,255,.11);border-radius:16px;background:linear-gradient(180deg,#101010,#070707);overflow:hidden}
  .archive-head{padding:1rem 1.1rem;border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap}
  .archive-title{font-size:2rem;font-weight:600;margin:0}
  .archive-sub{color:rgba(220,220,220,.7);margin-top:.2rem}
  .archive-stats{display:flex;gap:.5rem;flex-wrap:wrap}
  .archive-stat{padding:.42rem .7rem;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04);color:#eaeaea;font-size:.88rem}
  .archive-body{padding:1rem 1.1rem}
  .archive-table{width:100%;border-collapse:collapse;border:1px solid rgba(255,255,255,.1);border-radius:12px;overflow:hidden;background:rgba(255,255,255,.02)}
  .archive-table th,.archive-table td{padding:.72rem .8rem;border-bottom:1px solid rgba(255,255,255,.08);vertical-align:middle}
  .archive-table th{background:rgba(255,255,255,.04);color:rgba(236,236,236,.82);font-weight:600;text-align:left}
  .archive-table tr:last-child td{border-bottom:0}
  .archive-title-link{color:#ececf0;text-decoration:none;font-weight:600}
  .archive-title-link:hover{color:#ffcc00}
  .archive-chip{display:inline-flex;padding:.28rem .62rem;border-radius:999px;border:1px solid rgba(87,200,143,.45);background:rgba(87,200,143,.14);color:#8fe8b4;font-size:.8rem;font-weight:600;white-space:nowrap}
  .archive-muted{color:rgba(220,220,220,.72);font-size:.9rem}
  .empty-box{padding:2rem 1rem;text-align:center;border:1px dashed rgba(255,255,255,.18);border-radius:12px;color:rgba(220,220,220,.72)}
</style>

<div class="archive-shell">
  <div class="archive-head">
    <div>
      <h2 class="archive-title">Completed Task Archive</h2>
      <div class="archive-sub">Final archive for tasks already submitted to the client.</div>
    </div>
    <div class="archive-stats">
      <span class="archive-stat">Archived Tasks: <?= (int)$total ?></span>
      <span class="archive-stat">Clients: <?= (int)count($clients) ?></span>
      <span class="archive-stat">Status: Submitted to Client</span>
    </div>
  </div>
  <div class="archive-body">
    <?php if(!$rows): ?>
      <div class="empty-box">No submitted tasks have been archived yet.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="archive-table">
          <thead>
            <tr><th>Task</th><th>Client</th><th>Project</th><th>Assignees</th><th>Status</th><th>Submitted</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><a class="archive-title-link" href="task_view.php?id=<?= (int)$r['id'] ?>"><?=h($r['title'])?></a></td>
                <td><?=h($r['client_name'])?></td>
                <td><?=h($r['project_name'])?></td>
                <td class="archive-muted"><?=h($r['assignees'] ?? 'Unassigned')?></td>
                <td><span class="archive-chip"><?=h($r['status'])?></span></td>
                <td class="archive-muted"><?=h($r['submitted_at'] ?: $r['updated_at'])?></td>
                <td class="text-end"><a class="btn btn-outline-light btn-sm" href="task_view.php?id=<?= (int)$r['id'] ?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/layout_end.php'; ?>
