<?php
require_once __DIR__ . '/../layout.php';
$pdo=db(); $ws=auth_workspace_id();

$task_id = (int)($_GET['task_id'] ?? 0);
$tasks = $pdo->prepare("SELECT id,title FROM tasks WHERE workspace_id=? ORDER BY id DESC LIMIT 200");
$tasks->execute([$ws]);
$tasks = $tasks->fetchAll();

$comments=[]; $atts=[];
if ($task_id>0) {
  $c=$pdo->prepare("SELECT c.created_at,u.name AS author,c.body FROM comments c JOIN users u ON u.id=c.author_user_id WHERE c.workspace_id=? AND c.task_id=? ORDER BY c.id DESC");
  $c->execute([$ws,$task_id]);
  $comments=$c->fetchAll();
  $a=$pdo->prepare("SELECT a.created_at,u.name AS uploader,a.original_name FROM task_attachments a JOIN users u ON u.id=a.uploaded_by WHERE a.workspace_id=? AND a.task_id=? ORDER BY a.id DESC");
  $a->execute([$ws,$task_id]);
  $atts=$a->fetchAll();
}
?>
<h2 class="mb-3">Task Activity Log</h2>

<div class="card p-3 mb-3">
  <form method="get" class="row g-2">
    <div class="col-md-8">
      <label class="form-label">Task</label>
      <select class="form-select" name="task_id">
        <option value="0">Select a task...</option>
        <?php foreach($tasks as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $task_id===(int)$t['id']?'selected':'' ?>>#<?= (int)$t['id'] ?> — <?= h($t['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4 d-flex align-items-end">
      <button class="btn btn-light w-100">View</button>
    </div>
  </form>
</div>

<?php if($task_id>0): ?>
<div class="row g-3">
  <div class="col-md-6">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Comments</div>
      <?php foreach($comments as $c): ?>
        <div class="p-2 rounded mb-2" style="background:#0f0f0f;border:1px solid rgba(255,255,255,.08);">
          <div class="d-flex justify-content-between"><b><?= h($c['author']) ?></b><span class="text-muted small"><?= h($c['created_at']) ?></span></div>
          <div class="mt-1"><?= nl2br(h($c['body'])) ?></div>
        </div>
      <?php endforeach; ?>
      <?php if(!$comments): ?><div class="text-muted">No comments.</div><?php endif; ?>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Attachments</div>
      <?php foreach($atts as $a): ?>
        <div class="d-flex justify-content-between mb-2">
          <div><?= h($a['original_name']) ?><div class="text-muted small">by <?= h($a['uploader']) ?> • <?= h($a['created_at']) ?></div></div>
        </div>
      <?php endforeach; ?>
      <?php if(!$atts): ?><div class="text-muted">No attachments.</div><?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../layout_end.php'; ?>
