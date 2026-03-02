<?php
require_once __DIR__ . '/layout.php';
$pdo=db(); $ws=auth_workspace_id();
$user=auth_user();
$role=$user['role_name'] ?? '';
$can_manage=in_array($role,['CEO','CTO','Super Admin'],true);

$project_id=(int)($_GET['project_id'] ?? 0);
$search=trim((string)($_GET['q'] ?? ''));
$view=($_GET['view'] ?? 'recent') === 'all' ? 'all' : 'recent';

$q="SELECT d.*, p.name AS project_name, c.name AS client_name, u.name AS author
  FROM docs d
  JOIN projects p ON p.id=d.project_id
  JOIN clients c ON c.id=p.client_id
  JOIN users u ON u.id=d.created_by
  WHERE d.workspace_id=$ws";
$params=[];
if($project_id){ $q.=" AND d.project_id=?"; $params[]=$project_id; }
if($search!==''){
  $q.=" AND (d.title LIKE ? OR p.name LIKE ? OR c.name LIKE ? OR u.name LIKE ?)";
  $like='%'.$search.'%';
  $params[]=$like; $params[]=$like; $params[]=$like; $params[]=$like;
}
$q.=" ORDER BY d.updated_at DESC, d.id DESC";
$stmt=$pdo->prepare($q); $stmt->execute($params); $docs=$stmt->fetchAll();

if($_SERVER['REQUEST_METHOD']==='POST'){
  require_post(); csrf_verify();
  if(!$can_manage){ flash_set('error','No permission.'); redirect('docs.php'.($project_id?('?project_id='.$project_id):'')); }
  $title=trim($_POST['title'] ?? '');
  $pid=(int)($_POST['project_id'] ?? 0);
  $content=trim($_POST['content'] ?? '');
  if($title==='' || !$pid){ flash_set('error','Title and project required.'); redirect('docs.php'); }
  $pdo->prepare("INSERT INTO docs (workspace_id,project_id,title,content,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?)")
      ->execute([$ws,$pid,$title,$content,auth_user()['id'],now(),now()]);
  flash_set('success','Doc created.');
  redirect('docs.php'.($project_id?('?project_id='.$project_id):''));
}

$projects=$pdo->query("SELECT id,name FROM projects WHERE workspace_id=$ws ORDER BY id DESC")->fetchAll();

$folderCounts=[];
foreach($docs as $d){
  $key=$d['project_name'];
  if(!isset($folderCounts[$key])){ $folderCounts[$key]=['name'=>$key,'count'=>0]; }
  $folderCounts[$key]['count']++;
}
$folderCards=array_slice(array_values($folderCounts),0,4);
$shownDocs=$view==='recent' ? array_slice($docs,0,8) : $docs;

function doc_initials($name){
  $name=trim((string)$name);
  if($name==='') return '?';
  $parts=preg_split('/\s+/', $name);
  $a=strtoupper(substr($parts[0],0,1));
  $b='';
  if(count($parts)>1){ $b=strtoupper(substr($parts[count($parts)-1],0,1)); }
  return $a.$b;
}

function docs_query(array $extra=[]){
  $base=[];
  if(isset($_GET['project_id']) && $_GET['project_id']!==''){ $base['project_id']=$_GET['project_id']; }
  if(isset($_GET['q']) && $_GET['q']!==''){ $base['q']=$_GET['q']; }
  $params=array_merge($base,$extra);
  return 'docs.php'.($params ? ('?'.http_build_query($params)) : '');
}
?>
<style>
  .docs-content-shell{border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:1.1rem 1.15rem;background:linear-gradient(180deg,#0f1222,#0a0d19);box-shadow:0 26px 50px rgba(0,0,0,.38)}
  .docs-top{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:.95rem}
  .docs-title{font-size:2rem;font-weight:700}
  .docs-actions{display:flex;align-items:center;gap:.5rem}
  .docs-bubble{width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(130deg,#f8d978,#8870ff);color:#111426;font-weight:700;font-size:.67rem;border:1px solid rgba(255,255,255,.35);margin-left:-8px}
  .docs-bar{display:grid;grid-template-columns:1fr 220px;gap:.65rem;margin-bottom:.95rem}
  .docs-search{display:flex;align-items:center;border:1px solid rgba(255,255,255,.12);border-radius:10px;background:rgba(255,255,255,.04)}
  .docs-search input{flex:1;background:transparent;border:0;color:#e8ecff;padding:.62rem .7rem;outline:none}
  .docs-filter{border:1px solid rgba(255,255,255,.12);border-radius:10px;background:rgba(255,255,255,.04);color:#e8ecff;padding:.62rem .7rem}
  .docs-panel{border:1px solid rgba(255,255,255,.11);border-radius:14px;background:rgba(255,255,255,.02)}
  .docs-tabs{display:flex;gap:.2rem;padding:.65rem .8rem;border-bottom:1px solid rgba(255,255,255,.08)}
  .docs-tab{padding:.4rem .7rem;border-radius:8px;color:rgba(223,227,246,.74);text-decoration:none;border:1px solid transparent}
  .docs-tab.active{color:#f8d978;border-color:rgba(248,217,120,.36);background:rgba(248,217,120,.1)}
  .docs-folders{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.7rem;padding:.9rem .8rem}
  .docs-folder{border:1px solid rgba(255,255,255,.11);border-radius:10px;padding:.7rem;background:rgba(255,255,255,.03)}
  .docs-folder .count{color:rgba(214,221,242,.72);font-size:.83rem}
  .docs-table-wrap{padding:.8rem}
  .docs-table{width:100%;border-collapse:collapse}
  .docs-table th,.docs-table td{padding:.65rem .6rem;border-bottom:1px solid rgba(255,255,255,.08)}
  .docs-table th{font-weight:600;color:rgba(214,221,242,.82);background:rgba(255,255,255,.03);text-align:left}
  .docs-muted{color:rgba(203,211,236,.72)}
  .docs-person{display:flex;align-items:center;gap:.45rem}
  .docs-avatar{width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(140deg,#f8d978,#8f6bff);color:#141827;font-size:.66rem;font-weight:700;border:1px solid rgba(255,255,255,.35)}
  @media (max-width: 992px){.docs-bar{grid-template-columns:1fr}.docs-folders{grid-template-columns:1fr 1fr}}
</style>

<div class="docs-content-shell">
  <div class="docs-top">
    <div class="docs-title">Docs</div>
    <div class="docs-actions">
      <div>
        <span class="docs-bubble">A</span><span class="docs-bubble">N</span><span class="docs-bubble">X</span>
      </div>
      <?php if($can_manage): ?><button class="btn btn-yellow btn-sm" data-bs-toggle="modal" data-bs-target="#addDoc">Add Doc</button><?php endif; ?>
    </div>
  </div>

  <form class="docs-bar" method="get">
    <div class="docs-search">
      <span class="px-2">🔎</span>
      <input name="q" value="<?=h($search)?>" placeholder="Search docs, client, project, author">
    </div>
    <select class="docs-filter" name="project_id" onchange="this.form.submit()">
      <option value="">All documents</option>
      <?php foreach($projects as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= $project_id===(int)$p['id'] ? 'selected' : '' ?>><?=h($p['name'])?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <section class="docs-panel">
    <div class="docs-tabs">
      <a class="docs-tab <?= $view==='recent' ? 'active' : '' ?>" href="<?=h(docs_query(['view'=>'recent']))?>">Recent Docs</a>
      <a class="docs-tab <?= $view==='all' ? 'active' : '' ?>" href="<?=h(docs_query(['view'=>'all']))?>">All Docs</a>
    </div>
    <div class="docs-folders">
      <?php foreach($folderCards as $f): ?>
        <div class="docs-folder">
          <div class="fw-semibold">📁 <?=h($f['name'])?></div>
          <div class="count"><?= (int)$f['count'] ?> docs</div>
        </div>
      <?php endforeach; ?>
      <div class="docs-folder d-flex align-items-center justify-content-center fw-semibold">＋</div>
    </div>

    <div class="docs-table-wrap">
      <div class="fw-semibold mb-2">Documents</div>
      <div class="table-responsive">
        <table class="docs-table">
          <thead>
            <tr><th>Doc Name</th><th>Client</th><th>Last Updated</th><th>Updated By</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach($shownDocs as $d): ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?=h($d['title'])?></div>
                  <div class="docs-muted small"><?=h($d['project_name'])?></div>
                </td>
                <td class="docs-muted"><?=h($d['client_name'])?></td>
                <td class="docs-muted"><?=h(date('M d', strtotime($d['updated_at'])))?></td>
                <td>
                  <div class="docs-person"><span class="docs-avatar"><?=h(doc_initials($d['author']))?></span><span><?=h($d['author'])?></span></div>
                </td>
                <td class="text-end"><a class="btn btn-sm btn-outline-light" href="doc_edit.php?id=<?=h($d['id'])?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if(!$shownDocs): ?><tr><td colspan="5" class="docs-muted">No docs found.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>

<?php if($can_manage): ?>
<div class="modal fade" id="addDoc" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content card p-3">
      <div class="modal-header border-0"><h5 class="modal-title">Add Doc</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Title</label><input class="form-control" name="title" required></div>
            <div class="col-md-4"><label class="form-label">Project</label>
              <select class="form-select" name="project_id" required>
                <?php foreach($projects as $p): ?><option value="<?=h($p['id'])?>" <?= $project_id===(int)$p['id'] ? 'selected' : '' ?>><?=h($p['name'])?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-12"><label class="form-label">Content</label><textarea class="form-control" name="content" rows="10" placeholder="Write doc..."></textarea></div>
          </div>
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
