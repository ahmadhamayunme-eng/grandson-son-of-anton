<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();

$pdo = db(); $ws = auth_workspace_id();
$user = auth_user();
$role = $user['role_name'] ?? '';
$can_manage = in_array($role, ['CEO','Manager','Super Admin'], true);

$project_id = (int)($_GET['project_id'] ?? 0);
$search = trim((string)($_GET['q'] ?? ''));
$view = ($_GET['view'] ?? 'recent') === 'all' ? 'all' : 'recent';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post(); csrf_verify();
  if (!$can_manage) { flash_set('error', 'No permission.'); redirect('docs.php' . ($project_id ? ('?project_id=' . $project_id) : '')); }
  $title = trim($_POST['title'] ?? '');
  $pid = (int)($_POST['project_id'] ?? 0);
  $content = trim($_POST['content'] ?? '');
  if ($title === '' || !$pid) { flash_set('error', 'Title and project required.'); redirect('docs.php'); }
  $pdo->prepare("INSERT INTO docs (workspace_id,project_id,title,content,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?)")
      ->execute([$ws, $pid, $title, $content, auth_user()['id'], now(), now()]);
  flash_set('success', 'Doc created.');
  redirect('docs.php' . ($project_id ? ('?project_id=' . $project_id) : ''));
}

$q = "SELECT d.*, p.name AS project_name, c.name AS client_name, u.name AS author, u.id AS author_id
  FROM docs d
  JOIN projects p ON p.id=d.project_id
  JOIN clients c ON c.id=p.client_id
  JOIN users u ON u.id=d.created_by
  WHERE d.workspace_id=$ws";
$params = [];
if ($project_id) { $q .= " AND d.project_id=?"; $params[] = $project_id; }
if ($search !== '') {
  $q .= " AND (d.title LIKE ? OR p.name LIKE ? OR c.name LIKE ? OR u.name LIKE ?)";
  $like = '%' . $search . '%';
  $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
$q .= " ORDER BY d.updated_at DESC, d.id DESC";
$stmt = $pdo->prepare($q); $stmt->execute($params); $docs = $stmt->fetchAll();

$projects = $pdo->query("SELECT id,name FROM projects WHERE workspace_id=$ws ORDER BY id DESC")->fetchAll();

$folderCounts = [];
foreach ($docs as $d) {
  $key = $d['project_name'];
  if (!isset($folderCounts[$key])) $folderCounts[$key] = ['name' => $key, 'count' => 0, 'project_id' => (int)$d['project_id']];
  $folderCounts[$key]['count']++;
}
$folderCards = array_slice(array_values($folderCounts), 0, 6);
$shownDocs = $view === 'recent' ? array_slice($docs, 0, 10) : $docs;

function docs_query(array $extra = []): string {
  $base = [];
  if (isset($_GET['project_id']) && $_GET['project_id'] !== '') $base['project_id'] = $_GET['project_id'];
  if (isset($_GET['q']) && $_GET['q'] !== '') $base['q'] = $_GET['q'];
  $params = array_merge($base, $extra);
  return 'docs.php' . ($params ? ('?' . http_build_query($params)) : '');
}

function docs_avatar_gradient(string $seed): string {
  $palette = [
    'linear-gradient(135deg,#22c55e,#16a34a)','linear-gradient(135deg,#a855f7,#7c3aed)',
    'linear-gradient(135deg,#0ea5e9,#0284c7)','linear-gradient(135deg,#f97316,#ea580c)',
    'linear-gradient(135deg,#ec4899,#be185d)','linear-gradient(135deg,#3b82f6,#1d4ed8)',
    'linear-gradient(135deg,#facc15,#ca8a04)',
  ];
  return $palette[crc32($seed) % count($palette)];
}

$pageTitle = 'Docs';
$activeKey = 'docs';
$pageHeadExtra = <<<HTML
<style>
  .page-header { padding: 28px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; }
  .toolbar { max-width: 1640px; margin: 0 auto; width: 100%; padding: 24px 32px 18px; display: grid; grid-template-columns: 1fr auto auto; gap: 12px; align-items: center; }
  .search-box { position: relative; }
  .search-box svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--text-dim); }
  .search-box input { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 11px 14px 11px 40px; font-size: 13px; color: var(--text); outline: none; transition: all 0.15s; font-family: inherit; }
  .search-box input::placeholder { color: var(--text-dim); }
  .search-box input:focus { border-color: var(--border-strong); background: var(--surface-2); }
  .filter-select { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 10px 32px 10px 14px; font-size: 13px; color: var(--text); appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%238a8a8a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; cursor: pointer; outline: none; min-width: 180px; font-family: inherit; }
  .filter-select option { background: #1a1a1a; color: var(--text); }
  .folders { max-width: 1640px; margin: 0 auto; width: 100%; padding: 0 32px 18px; display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
  .folder { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px; cursor: pointer; transition: all 0.15s; display: flex; flex-direction: column; gap: 4px; text-decoration: none; color: inherit; }
  .folder:hover { background: var(--surface-2); border-color: var(--border-strong); transform: translateY(-1px); }
  .folder .ico { width: 28px; height: 28px; border-radius: 7px; background: var(--accent-soft); color: var(--accent); display: inline-flex; align-items: center; justify-content: center; border: 1px solid rgba(250,204,21,0.2); margin-bottom: 4px; }
  .folder .ico svg { width: 14px; height: 14px; }
  .folder .t { font-size: 13.5px; font-weight: 600; color: var(--text); }
  .folder .c { font-size: 11.5px; color: var(--text-dim); font-variant-numeric: tabular-nums; }
  .tabs-row { max-width: 1640px; margin: 0 auto; width: 100%; padding: 0 32px; border-bottom: 1px solid var(--border); display: flex; gap: 4px; }
  .tabs-row a { padding: 12px 16px; font-size: 13px; font-weight: 500; color: var(--text-muted); border-bottom: 2px solid transparent; margin-bottom: -1px; transition: all 0.15s; text-decoration: none; }
  .tabs-row a:hover { color: var(--text); }
  .tabs-row a.active { color: var(--text); border-bottom-color: var(--accent); }
  .table-wrap { max-width: 1640px; margin: 18px auto 28px; width: 100%; padding: 0 32px; }
  .table-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  table.docs { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.docs thead th { text-align: left; padding: 12px 16px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-dim); background: var(--bg); border-bottom: 1px solid var(--border); }
  table.docs tbody tr { transition: background 0.12s; }
  table.docs tbody tr:hover { background: var(--surface-hover); }
  table.docs tbody td { padding: 14px 16px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
  table.docs tbody tr:last-child td { border-bottom: none; }
  .doc-name { font-weight: 600; color: var(--text); font-size: 13.5px; text-decoration: none; }
  .doc-name:hover { color: var(--accent); }
  .doc-sub { font-size: 11.5px; color: var(--text-dim); margin-top: 2px; }
  .person { display: flex; align-items: center; gap: 8px; }
  .person .avatar { width: 26px; height: 26px; font-size: 10px; }
  .due-mono { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12.5px; color: var(--text-muted); }
  .btn-tiny { padding: 5px 11px; font-size: 11.5px; font-weight: 500; border-radius: 6px; transition: all 0.12s; border: 1px solid var(--border); display: inline-flex; align-items: center; gap: 5px; color: var(--text); text-decoration: none; }
  .btn-tiny:hover { background: var(--accent); color: #1a1400; border-color: var(--accent); }
  .btn-tiny svg { width: 11px; height: 11px; }
  .empty-state { padding: 48px 20px; text-align: center; color: var(--text-dim); font-size: 13px; }

  .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 100; display: none; align-items: flex-start; justify-content: center; padding: 60px 20px; overflow-y: auto; }
  .modal-overlay.open { display: flex; }
  .modal-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; width: 100%; max-width: 720px; overflow: hidden; }
  .modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
  .modal-title { font-size: 16px; font-weight: 600; color: var(--text); }
  .modal-body { padding: 20px; max-height: 70vh; overflow-y: auto; }
  .modal-foot { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; gap: 8px; justify-content: flex-end; background: var(--bg); }
  .modal-grid { display: grid; gap: 12px; grid-template-columns: repeat(12, 1fr); }
  .modal-grid > * { display: flex; flex-direction: column; gap: 5px; }
  .c-12 { grid-column: span 12; } .c-8 { grid-column: span 8; } .c-4 { grid-column: span 4; }
  @media (max-width: 768px) { .c-8, .c-4 { grid-column: span 12; } }
  .icon-close { width: 30px; height: 30px; border-radius: 6px; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; transition: all 0.12s; }
  .icon-close:hover { background: var(--surface-2); color: var(--text); }

  /* ===== MOBILE (≤768px) =====
     Screenshot showed the docs table forced 5 columns ("Doc / Client /
     Updated / Author / Open") into ~360px of phone width — every cell
     wrapped to 2-3 lines and the row heights jumped around. The fix is
     the same "table → vertical card" pattern used on my_tasks /
     client_view / project_view: hide thead, lay each row out as a grid
     of named areas (title / meta / actions) so nothing has to scroll
     sideways. Also tighten the header, toolbar and folder grid for the
     16px page column. */
  @media (max-width: 768px) {
    .page-header {
      flex-direction: column;
      align-items: stretch;
      padding: 16px 16px 0;
      gap: 12px;
    }
    .page-header-left { gap: 12px; }
    .page-title { font-size: clamp(20px, 5.6vw, 26px); line-height: 1.2; }
    .page-sub { font-size: 12px; }
    .page-header > .btn { width: 100%; min-height: 44px; justify-content: center; }

    /* Toolbar: search row 1 full-width, project filter + Apply share row 2. */
    .toolbar {
      grid-template-columns: 1fr 1fr;
      padding: 14px 16px 12px;
      gap: 8px;
    }
    .toolbar .search-box { grid-column: 1 / -1; }
    .toolbar .search-box input { font-size: 16px; padding: 11px 14px 11px 40px; min-height: 44px; }
    .filter-select { min-width: 0; width: 100%; font-size: 14px; min-height: 44px; padding: 10px 32px 10px 12px; }
    .toolbar .btn { width: 100%; min-height: 44px; justify-content: center; padding: 10px 12px; font-size: 13px; }

    /* Folder grid: keep two-up where possible, one-up on narrow phones. */
    .folders {
      grid-template-columns: 1fr;
      padding: 0 16px 16px;
      gap: 10px;
    }

    /* Tabs: horizontal-scroll inside their own container so they never
       hide behind anything, never push the page wider. */
    .tabs-row {
      padding: 0 16px;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: none;
      flex-wrap: nowrap;
    }
    .tabs-row::-webkit-scrollbar { display: none; }
    .tabs-row a { flex-shrink: 0; padding: 12px 12px; font-size: 12.5px; }

    .table-wrap { padding: 0 16px; margin: 12px auto 24px; }

    /* Table → cards. Hide thead, each <tr> becomes a 3-row stack:
       title (full width) / client + updated / author + open button.
       No horizontal scroll, every doc is fully visible. */
    table.docs { font-size: 13px; }
    table.docs thead { display: none; }
    table.docs, table.docs tbody, table.docs tr { display: block; }
    table.docs tbody tr {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      grid-template-areas:
        "title  title"
        "client updated"
        "author actions";
      gap: 6px 12px;
      padding: 14px 16px;
      border-bottom: 1px solid var(--border);
      align-items: center;
    }
    table.docs tbody tr:last-child { border-bottom: none; }
    table.docs tbody td {
      display: block;
      padding: 0;
      border: none;
      min-width: 0;
      max-width: 100%;
    }
    table.docs tbody td:nth-child(1) { grid-area: title; }
    table.docs tbody td:nth-child(1) .doc-name { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 14.5px; }
    table.docs tbody td:nth-child(2) { grid-area: client; font-size: 12.5px; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    table.docs tbody td:nth-child(3) { grid-area: updated; text-align: right; font-size: 12px; color: var(--text-muted); }
    table.docs tbody td:nth-child(4) { grid-area: author; font-size: 12.5px; min-width: 0; }
    table.docs tbody td:nth-child(4) .person { min-width: 0; }
    table.docs tbody td:nth-child(4) .person span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    table.docs tbody td:nth-child(5) { grid-area: actions; text-align: right !important; }
    table.docs tbody td:nth-child(5) .btn-tiny { min-height: 32px; padding: 6px 12px; font-size: 12px; }
  }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Docs</div>
      <div class="page-sub">All documents across clients and projects.</div>
    </div>
  </div>
  <?php if ($can_manage): ?>
    <button type="button" class="btn btn-primary save-flash" onclick="document.getElementById('addDoc').classList.add('open')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
      New Doc
    </button>
  <?php endif; ?>
</div>

<form class="toolbar" method="get">
  <div class="search-box">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
    <input name="q" value="<?= h($search) ?>" placeholder="Search docs, client, project, author…">
  </div>
  <select class="filter-select" name="project_id" onchange="this.form.submit()">
    <option value="">All projects</option>
    <?php foreach ($projects as $p): ?>
      <option value="<?= (int)$p['id'] ?>" <?= $project_id === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-primary save-flash" type="submit">Apply</button>
</form>

<?php if ($folderCards): ?>
<div class="folders">
  <?php foreach ($folderCards as $f): ?>
    <a class="folder" href="<?= h(docs_query(['project_id' => $f['project_id']])) ?>">
      <span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"></path></svg></span>
      <div class="t"><?= h($f['name']) ?></div>
      <div class="c"><?= (int)$f['count'] ?> docs</div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="tabs-row">
  <a class="<?= $view === 'recent' ? 'active' : '' ?>" href="<?= h(docs_query(['view' => 'recent'])) ?>">Recent Docs</a>
  <a class="<?= $view === 'all' ? 'active' : '' ?>" href="<?= h(docs_query(['view' => 'all'])) ?>">All Docs</a>
</div>

<div class="table-wrap">
  <div class="table-card">
    <?php if (!$shownDocs): ?>
      <div class="empty-state">No docs found.</div>
    <?php else: ?>
      <table class="docs">
        <thead><tr><th>Doc</th><th>Client</th><th>Updated</th><th>Author</th><th style="width:90px;text-align:right;"></th></tr></thead>
        <tbody>
        <?php foreach ($shownDocs as $d): $grad = docs_avatar_gradient((string)$d['author']); ?>
          <tr>
            <td>
              <a class="doc-name" href="doc_edit.php?id=<?= (int)$d['id'] ?>"><?= h($d['title']) ?></a>
              <div class="doc-sub"><?= h($d['project_name']) ?></div>
            </td>
            <td><?= h($d['client_name']) ?></td>
            <td><span class="due-mono"><?= h(date('M j, Y', strtotime((string)$d['updated_at']))) ?></span></td>
            <td><div class="person"><div class="avatar" style="background:<?= h($grad) ?>"><?= h(user_initials((string)$d['author'])) ?></div><span><?= h($d['author']) ?></span></div></td>
            <td style="text-align:right;"><a class="btn-tiny" href="doc_edit.php?id=<?= (int)$d['id'] ?>">Open<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php if ($can_manage): ?>
<div class="modal-overlay" id="addDoc" onclick="if(event.target===this) this.classList.remove('open')">
  <div class="modal-card">
    <div class="modal-head">
      <span class="modal-title">Add Doc</span>
      <button type="button" class="icon-close" onclick="document.getElementById('addDoc').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <div class="modal-body">
        <div class="modal-grid">
          <div class="c-8"><label class="field-label">Title</label><input class="input" name="title" required></div>
          <div class="c-4"><label class="field-label">Project</label>
            <select class="select" name="project_id" required>
              <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $project_id === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="c-12"><label class="field-label">Content</label><textarea class="input" name="content" rows="10" placeholder="Write doc..."></textarea></div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addDoc').classList.remove('open')">Cancel</button>
        <button class="btn btn-primary save-flash" type="submit">Create</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/layout_end.php'; ?>
