<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();

$pdo = db();
$ws = auth_workspace_id();
$q = trim($_GET['q'] ?? '');
$scope = $_GET['scope'] ?? 'all';
if (!in_array($scope, ['all','tasks','projects','clients','docs'], true)) $scope = 'all';

$clients = $projects = $tasks = $docs = [];
if ($q !== '') {
  $like = '%' . $q . '%';
  $st = $pdo->prepare('SELECT id,name,updated_at FROM clients WHERE workspace_id=? AND name LIKE ? ORDER BY id DESC LIMIT 20');
  $st->execute([$ws, $like]);
  $clients = $st->fetchAll();

  $st = $pdo->prepare('SELECT p.id,p.name,c.name AS client_name,ps.name AS status_name,p.updated_at
    FROM projects p
    JOIN clients c ON c.id=p.client_id
    JOIN project_statuses ps ON ps.id=p.status_id
    WHERE p.workspace_id=? AND (p.name LIKE ? OR c.name LIKE ?) ORDER BY p.id DESC LIMIT 20');
  $st->execute([$ws, $like, $like]);
  $projects = $st->fetchAll();

  $st = $pdo->prepare('SELECT t.id,t.title,t.status,t.due_date,p.name AS project_name,c.name AS client_name,t.updated_at
    FROM tasks t JOIN projects p ON p.id=t.project_id JOIN clients c ON c.id=p.client_id
    WHERE t.workspace_id=? AND (t.title LIKE ? OR p.name LIKE ? OR c.name LIKE ?) ORDER BY t.id DESC LIMIT 20');
  $st->execute([$ws, $like, $like, $like]);
  $tasks = $st->fetchAll();

  try {
    $st = $pdo->prepare('SELECT d.id,d.title,d.updated_at,p.name AS project_name
      FROM docs d JOIN projects p ON p.id=d.project_id AND p.workspace_id=d.workspace_id
      WHERE d.workspace_id=? AND (d.title LIKE ? OR p.name LIKE ?) ORDER BY d.id DESC LIMIT 20');
    $st->execute([$ws, $like, $like]);
    $docs = $st->fetchAll();
  } catch (Throwable $e) { $docs = []; }
}

$cntT = count($tasks); $cntP = count($projects); $cntC = count($clients); $cntD = count($docs);
$cntAll = $cntT + $cntP + $cntC + $cntD;

// Totals for the unfilled state (used in the "Jump to" tiles)
function search_count(PDO $pdo, string $sql, array $params = []): int {
  try { $st = $pdo->prepare($sql); $st->execute($params); return (int)$st->fetchColumn(); }
  catch (Throwable $e) { return 0; }
}
$totMyTasks = search_count($pdo, "SELECT COUNT(*) FROM tasks t JOIN task_assignees ta ON ta.task_id=t.id WHERE t.workspace_id=? AND ta.user_id=? AND t.status NOT IN ('Approved','Approved (Ready to Submit)','Submitted to Client')", [$ws, (int)auth_user()['id']]);
$totMyOverdue = search_count($pdo, "SELECT COUNT(*) FROM tasks t JOIN task_assignees ta ON ta.task_id=t.id WHERE t.workspace_id=? AND ta.user_id=? AND t.due_date IS NOT NULL AND DATE(t.due_date) < CURDATE() AND t.status NOT IN ('Approved (Ready to Submit)','Submitted to Client')", [$ws, (int)auth_user()['id']]);
$totProjects = search_count($pdo, "SELECT COUNT(*) FROM projects WHERE workspace_id=?", [$ws]);
$totClients = search_count($pdo, "SELECT COUNT(*) FROM clients WHERE workspace_id=?", [$ws]);
$totOverdue = search_count($pdo, "SELECT COUNT(*) FROM tasks t WHERE t.workspace_id=? AND t.due_date IS NOT NULL AND DATE(t.due_date) < CURDATE() AND t.status NOT IN ('Approved (Ready to Submit)','Submitted to Client')", [$ws]);
$totOverdueProjects = search_count($pdo, "SELECT COUNT(DISTINCT t.project_id) FROM tasks t WHERE t.workspace_id=? AND t.due_date IS NOT NULL AND DATE(t.due_date) < CURDATE() AND t.status NOT IN ('Approved (Ready to Submit)','Submitted to Client')", [$ws]);
$totRecords = $totProjects + $totClients + search_count($pdo, "SELECT COUNT(*) FROM tasks WHERE workspace_id=?", [$ws]) + search_count($pdo, "SELECT COUNT(*) FROM docs WHERE workspace_id=?", [$ws]);

function search_avatar_gradient(string $seed): string {
  $palette = [
    'linear-gradient(135deg,#7c3aed,#5b21b6)','linear-gradient(135deg,#10b981,#059669)',
    'linear-gradient(135deg,#0ea5e9,#0369a1)','linear-gradient(135deg,#f43f5e,#e11d48)',
    'linear-gradient(135deg,#fb923c,#ea580c)','linear-gradient(135deg,#ec4899,#be185d)',
    'linear-gradient(135deg,#facc15,#ca8a04)',
  ];
  return $palette[crc32($seed) % count($palette)];
}
function search_highlight(string $text, string $q): string {
  $safe = h($text);
  if ($q === '') return $safe;
  $pattern = '/(' . preg_quote($q, '/') . ')/i';
  return preg_replace($pattern, '<mark>$1</mark>', $safe);
}
function search_due_label(?string $due): string {
  if (!$due) return '';
  $diff = (int)floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400);
  if ($diff < 0) return abs($diff) . 'd overdue';
  if ($diff === 0) return 'Due today';
  if ($diff === 1) return 'Due tomorrow';
  return 'Due ' . date('M j', strtotime($due));
}

$pageTitle = 'Search';
$activeKey = 'search';
$pageHeadExtra = <<<HTML
<style>
  .search-shell { max-width: 980px; margin: 0 auto; width: 100%; padding: 56px 32px 32px; }
  .search-back-row { margin-bottom: 18px; }
  .search-eyebrow { font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.15em; font-weight: 500; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
  .search-eyebrow::before { content: ''; width: 4px; height: 4px; background: var(--accent); border-radius: 50%; box-shadow: 0 0 0 3px rgba(250,204,21,0.15); }
  .search-title { font-size: 32px; font-weight: 700; letter-spacing: -0.025em; line-height: 1.1; margin-bottom: 6px; }
  .search-sub { color: var(--text-muted); font-size: 14px; margin-bottom: 28px; }
  .big-search { position: relative; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; transition: all 0.2s; }
  .big-search:focus-within { border-color: var(--accent); background: var(--surface-2); box-shadow: 0 0 0 4px rgba(250,204,21,0.08); }
  .big-search .icon { position: absolute; left: 22px; top: 50%; transform: translateY(-50%); width: 20px; height: 20px; color: var(--text-muted); }
  .big-search input { width: 100%; background: transparent; border: 0; outline: 0; padding: 22px 130px 22px 56px; font-size: 17px; color: var(--text); font-family: inherit; letter-spacing: -0.005em; }
  .big-search input::placeholder { color: var(--text-muted); }
  .big-search .right-side { position: absolute; right: 16px; top: 50%; transform: translateY(-50%); display: flex; align-items: center; gap: 8px; }
  .big-search .clear-btn { width: 26px; height: 26px; border-radius: 50%; background: var(--surface-2); color: var(--text-muted); display: none; align-items: center; justify-content: center; transition: all 0.12s; border: 0; cursor: pointer; }
  .big-search .clear-btn svg { width: 13px; height: 13px; }
  .big-search .clear-btn:hover { background: var(--border); color: var(--text); }
  .big-search.has-text .clear-btn { display: inline-flex; }
  .big-search .kbd-pair { display: inline-flex; gap: 4px; align-items: center; color: var(--text-dim); font-size: 11px; }
  .big-search .kbd { background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; padding: 3px 7px; font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 10.5px; color: var(--text-muted); }
  .scope-row { margin-top: 22px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); }
  .scope-tabs { display: flex; gap: 4px; margin-bottom: -1px; }
  .scope-tabs .tab { padding: 12px 16px; font-size: 13px; font-weight: 500; color: var(--text-muted); border: 0; border-bottom: 2px solid transparent; transition: all 0.15s; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; background: none; text-decoration: none; }
  .scope-tabs .tab:hover { color: var(--text); }
  .scope-tabs .tab.active { color: var(--text); border-bottom-color: var(--accent); }
  .scope-tabs .tab svg { width: 13px; height: 13px; }
  .scope-tabs .count { font-size: 10.5px; background: var(--surface-2); color: var(--text-muted); padding: 1px 7px; border-radius: 999px; font-variant-numeric: tabular-nums; }
  .scope-tabs .tab.active .count { background: var(--accent-soft); color: var(--accent); }
  .scope-meta { color: var(--text-dim); font-size: 12px; }
  .scope-meta b { color: var(--text-muted); font-weight: 500; }
  .body-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 28px; }
  @media (max-width: 760px) { .body-grid { grid-template-columns: 1fr; } }
  .panel-title { font-size: 11px; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: var(--text-dim); margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; }
  .panel-title .clear-link { font-size: 11px; color: var(--text-muted); font-weight: 500; text-transform: none; letter-spacing: 0; transition: color 0.12s; cursor: pointer; }
  .panel-title .clear-link:hover { color: var(--text); }
  .recent-list { display: flex; flex-direction: column; gap: 4px; }
  .recent-row { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 8px; transition: background 0.12s; cursor: pointer; text-decoration: none; color: inherit; }
  .recent-row:hover { background: var(--surface); }
  .recent-row .leading { width: 28px; height: 28px; border-radius: 7px; background: var(--surface); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; color: var(--text-muted); flex-shrink: 0; }
  .recent-row .leading svg { width: 13px; height: 13px; }
  .recent-row .body { flex: 1; min-width: 0; }
  .recent-row .body .q { font-size: 13.5px; color: var(--text); font-weight: 500; }
  .recent-row .body .meta { font-size: 11.5px; color: var(--text-dim); margin-top: 2px; display: flex; align-items: center; gap: 6px; }
  .meta .dot-sep { width: 2px; height: 2px; background: var(--text-dim); border-radius: 50%; }
  .recent-row .trailing { color: var(--text-dim); font-size: 11px; font-variant-numeric: tabular-nums; }
  .chips { display: flex; flex-wrap: wrap; gap: 6px; }
  .chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; background: var(--surface); border: 1px solid var(--border); border-radius: 999px; font-size: 12px; color: var(--text-muted); transition: all 0.12s; cursor: pointer; font-family: 'JetBrains Mono', ui-monospace, monospace; text-decoration: none; }
  .chip:hover { border-color: var(--border-strong); color: var(--text); background: var(--surface-2); }
  .chip svg { width: 11px; height: 11px; opacity: 0.7; }
  .chip-key { color: var(--accent); font-weight: 600; }
  .pinned-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .pinned-tile { display: flex; align-items: center; gap: 12px; padding: 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; transition: all 0.12s; cursor: pointer; text-decoration: none; color: inherit; }
  .pinned-tile:hover { border-color: var(--border-strong); background: var(--surface-2); transform: translateY(-1px); }
  .pinned-tile .ico { width: 36px; height: 36px; border-radius: 8px; background: var(--bg); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; color: var(--text-muted); flex-shrink: 0; }
  .pinned-tile .ico svg { width: 16px; height: 16px; }
  .pinned-tile .ico.amber { background: rgba(250,204,21,0.06); color: var(--accent); border-color: rgba(250,204,21,0.2); }
  .pinned-tile .ico.danger { background: rgba(239,68,68,0.06); color: #fca5a5; border-color: rgba(239,68,68,0.2); }
  .pinned-tile .ico.blue { background: rgba(59,130,246,0.06); color: #93c5fd; border-color: rgba(59,130,246,0.2); }
  .pinned-tile .ico.green { background: rgba(34,197,94,0.06); color: #86efac; border-color: rgba(34,197,94,0.2); }
  .pinned-tile .label { font-size: 13px; color: var(--text); font-weight: 500; }
  .pinned-tile .sub { font-size: 11.5px; color: var(--text-dim); margin-top: 2px; font-variant-numeric: tabular-nums; }
  .results-shell { margin-top: 28px; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  .results-section { padding: 14px 18px; border-bottom: 1px solid var(--border); }
  .results-section:last-child { border-bottom: 0; }
  .results-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
  .results-head .label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-dim); font-weight: 600; display: flex; align-items: center; gap: 8px; }
  .results-head .label .count-pill { background: var(--bg); border: 1px solid var(--border); border-radius: 999px; padding: 1px 8px; color: var(--text-muted); font-size: 10.5px; letter-spacing: 0.04em; }
  .results-head .see-all { font-size: 11.5px; color: var(--text-muted); font-weight: 500; transition: color 0.12s; text-decoration: none; }
  .results-head .see-all:hover { color: var(--accent); }
  .result-row { display: flex; align-items: center; gap: 12px; padding: 8px 4px; border-radius: 8px; transition: background 0.12s; cursor: pointer; text-decoration: none; color: inherit; }
  .result-row:hover { background: var(--surface-hover); padding-left: 8px; padding-right: 8px; }
  .result-row .ico { width: 28px; height: 28px; border-radius: 6px; background: var(--bg); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; color: var(--text-muted); flex-shrink: 0; font-size: 10.5px; font-weight: 600; }
  .result-row .body { flex: 1; min-width: 0; }
  .result-row .body .title { font-size: 13.5px; color: var(--text); font-weight: 500; }
  .result-row .body .title mark { background: rgba(250,204,21,0.18); color: var(--accent); padding: 0 2px; border-radius: 3px; }
  .result-row .body .meta { font-size: 11.5px; color: var(--text-dim); margin-top: 2px; display: flex; align-items: center; gap: 6px; }
  .result-row .trailing { font-size: 11.5px; color: var(--text-dim); font-variant-numeric: tabular-nums; flex-shrink: 0; }
  .keyhints { margin-top: 32px; display: flex; align-items: center; justify-content: center; gap: 22px; color: var(--text-dim); font-size: 11.5px; border-top: 1px solid var(--border); padding-top: 18px; flex-wrap: wrap; }
  .keyhints .hint { display: inline-flex; align-items: center; gap: 6px; }
  .keyhints .k { background: var(--surface); border: 1px solid var(--border); border-radius: 5px; padding: 2px 6px; font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 10.5px; color: var(--text-muted); min-width: 18px; text-align: center; }
  .empty-results { padding: 32px 0; text-align: center; color: var(--text-dim); font-size: 13px; }

  /* ===== MOBILE =====
     Two issues seen on a phone:
     1. ".scope-meta" (Indexing N records · last sync now) was crammed
        into the right side of the scope-tabs row and wrapped to 3 awkward
        lines next to the tab pills. Hide it on mobile — it's a status
        nicety, not actionable, and the tabs row needs all the horizontal
        room it can get.
     2. ".result-row .body .meta" is a flex row of [project · client ·
        TASK-id]. With no flex-wrap and narrow viewport space, each
        word-set wraps inside its own column, producing the column-stack
        "jumbled words" effect. Let it wrap properly + truncate. */
  @media (max-width: 768px) {
    .scope-row { flex-wrap: wrap; gap: 0; }
    .scope-meta { display: none; }
    .scope-tabs {
      width: 100%;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: none;
    }
    .scope-tabs::-webkit-scrollbar { display: none; }
    .scope-tabs .tab { flex-shrink: 0; padding: 12px 14px; }

    .results-section { padding: 12px 14px; }
    .results-head { margin-bottom: 6px; }

    /* Result rows: convert from flex-row to a stack. Title on top,
       meta on its own line (now allowed to wrap and ellipsis on
       overflow), date on the right of meta line. */
    .result-row {
      align-items: flex-start;
      gap: 10px;
      padding: 10px 4px;
    }
    .result-row .body { min-width: 0; flex: 1 1 auto; }
    .result-row .body .title {
      font-size: 14px;
      line-height: 1.35;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
      word-break: break-word;
    }
    .result-row .body .meta {
      font-size: 11.5px;
      flex-wrap: wrap;
      row-gap: 2px;
      column-gap: 6px;
      align-items: center;
      min-width: 0;
    }
    /* Each meta segment stays on one line; the row wraps between
       segments instead of breaking words mid-segment. */
    .result-row .body .meta > * {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 100%;
    }
    .result-row .trailing {
      align-self: flex-start;
      margin-top: 2px;
      font-size: 11px;
      white-space: nowrap;
    }
  }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="search-shell">
  <div class="search-back-row"><?= back_button_html() ?></div>
  <div class="search-eyebrow">Search across anton x</div>
  <h1 class="search-title">Find anything, fast.</h1>
  <p class="search-sub">Tasks, projects, clients, docs — all in one place. Use filters or natural language.</p>

  <form id="searchForm" method="get" action="search.php">
    <div class="big-search <?= $q !== '' ? 'has-text' : '' ?>" id="bigSearch">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
      <input id="searchInput" name="q" value="<?= h($q) ?>" placeholder='Try "overdue tasks for Rose Law" or "blogs"' autocomplete="off" autofocus>
      <div class="right-side">
        <button type="button" class="clear-btn" id="clearBtn" title="Clear">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
        <span class="kbd-pair"><span class="kbd">⌘</span><span class="kbd">K</span></span>
      </div>
    </div>
    <input type="hidden" name="scope" id="scopeInput" value="<?= h($scope) ?>">
  </form>

  <div class="scope-row">
    <div class="scope-tabs" id="scopeTabs">
      <button type="button" class="tab <?= $scope === 'all' ? 'active' : '' ?>" data-scope="all">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h16"></path></svg>
        All <span class="count"><?= $q === '' ? '—' : (int)$cntAll ?></span>
      </button>
      <button type="button" class="tab <?= $scope === 'tasks' ? 'active' : '' ?>" data-scope="tasks">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg>
        Tasks <span class="count"><?= $q === '' ? '—' : (int)$cntT ?></span>
      </button>
      <button type="button" class="tab <?= $scope === 'projects' ? 'active' : '' ?>" data-scope="projects">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"></path></svg>
        Projects <span class="count"><?= $q === '' ? '—' : (int)$cntP ?></span>
      </button>
      <button type="button" class="tab <?= $scope === 'clients' ? 'active' : '' ?>" data-scope="clients">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
        Clients <span class="count"><?= $q === '' ? '—' : (int)$cntC ?></span>
      </button>
      <button type="button" class="tab <?= $scope === 'docs' ? 'active' : '' ?>" data-scope="docs">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
        Docs <span class="count"><?= $q === '' ? '—' : (int)$cntD ?></span>
      </button>
    </div>
    <div class="scope-meta">Indexing <b><?= number_format($totRecords) ?></b> records · last sync <b>now</b></div>
  </div>

  <?php if ($q === ''): ?>
    <div class="body-grid">
      <div>
        <div class="panel-title">Quick filters</div>
        <div class="chips">
          <a class="chip" href="?q=overdue"><span class="chip-key">status:</span>overdue</a>
          <a class="chip" href="?q=today"><span class="chip-key">due:</span>today</a>
          <a class="chip" href="?q=<?= urlencode(auth_user()['name'] ?? '') ?>"><span class="chip-key">assignee:</span>me</a>
          <a class="chip" href="?q=critical"><span class="chip-key">priority:</span>critical</a>
          <a class="chip" href="?q=in+progress"><span class="chip-key">status:</span>in-progress</a>
          <a class="chip" href="?q=submitted"><span class="chip-key">status:</span>submitted</a>
        </div>

        <div class="panel-title" style="margin-top:24px;">Suggested searches</div>
        <div class="chips">
          <a class="chip" href="?q=tasks">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            tasks
          </a>
          <a class="chip" href="?q=blog">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            blogs
          </a>
          <a class="chip" href="?q=website">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            website
          </a>
          <a class="chip" href="?q=seo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            seo
          </a>
        </div>
      </div>

      <div>
        <div class="panel-title">Jump to</div>
        <div class="pinned-grid">
          <a class="pinned-tile" href="my_tasks.php">
            <div class="ico amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg></div>
            <div>
              <div class="label">My Tasks</div>
              <div class="sub"><?= (int)$totMyTasks ?> open<?= $totMyOverdue > 0 ? ' · ' . (int)$totMyOverdue . ' overdue' : '' ?></div>
            </div>
          </a>
          <a class="pinned-tile" href="projects.php">
            <div class="ico blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"></path></svg></div>
            <div>
              <div class="label">Projects</div>
              <div class="sub"><?= (int)$totProjects ?> active</div>
            </div>
          </a>
          <a class="pinned-tile" href="clients.php">
            <div class="ico green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg></div>
            <div>
              <div class="label">Clients</div>
              <div class="sub"><?= (int)$totClients ?> in roster</div>
            </div>
          </a>
          <a class="pinned-tile" href="my_tasks.php?status=overdue">
            <div class="ico danger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg></div>
            <div>
              <div class="label">Overdue</div>
              <div class="sub"><?= (int)$totOverdue ?> across <?= (int)$totOverdueProjects ?> projects</div>
            </div>
          </a>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="results-shell">
      <?php if (($scope === 'all' || $scope === 'tasks') && $tasks): ?>
        <div class="results-section">
          <div class="results-head">
            <div class="label">Tasks <span class="count-pill"><?= $cntT ?></span></div>
            <a class="see-all" href="my_tasks.php?q=<?= urlencode($q) ?>">See all →</a>
          </div>
          <?php foreach (array_slice($tasks, 0, 8) as $t):
            $overdue = !empty($t['due_date']) && strtotime((string)$t['due_date']) < strtotime(date('Y-m-d')) && !in_array($t['status'], ['Approved (Ready to Submit)','Submitted to Client'], true);
            $dueLabel = $overdue ? abs((int)floor((strtotime((string)$t['due_date']) - strtotime(date('Y-m-d'))) / 86400)) . 'd overdue' : ($t['due_date'] ? 'Due ' . date('M j', strtotime((string)$t['due_date'])) : '');
          ?>
            <a class="result-row" href="task_view.php?id=<?= (int)$t['id'] ?>">
              <div class="ico" <?= $overdue ? 'style="background:rgba(239,68,68,0.08);color:#fca5a5;border-color:rgba(239,68,68,0.2);"' : '' ?>>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg>
              </div>
              <div class="body">
                <div class="title"><?= search_highlight((string)$t['title'], $q) ?></div>
                <div class="meta"><?= h($t['project_name']) ?><span class="dot-sep"></span><?= h($t['client_name']) ?><span class="dot-sep"></span>TASK-<?= str_pad((string)(int)$t['id'], 4, '0', STR_PAD_LEFT) ?></div>
              </div>
              <div class="trailing" <?= $overdue ? 'style="color:#fca5a5;"' : '' ?>><?= h($dueLabel) ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (($scope === 'all' || $scope === 'projects') && $projects): ?>
        <div class="results-section">
          <div class="results-head">
            <div class="label">Projects <span class="count-pill"><?= $cntP ?></span></div>
            <a class="see-all" href="projects.php?q=<?= urlencode($q) ?>">See all →</a>
          </div>
          <?php foreach (array_slice($projects, 0, 6) as $p): ?>
            <a class="result-row" href="project_view.php?id=<?= (int)$p['id'] ?>">
              <div class="ico" style="background:rgba(59,130,246,0.08);color:#93c5fd;border-color:rgba(59,130,246,0.2);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"></path></svg>
              </div>
              <div class="body">
                <div class="title"><?= search_highlight((string)$p['name'], $q) ?></div>
                <div class="meta"><?= h($p['client_name']) ?><span class="dot-sep"></span>PRJ-<?= str_pad((string)(int)$p['id'], 4, '0', STR_PAD_LEFT) ?></div>
              </div>
              <div class="trailing"><?= h($p['status_name']) ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (($scope === 'all' || $scope === 'clients') && $clients): ?>
        <div class="results-section">
          <div class="results-head">
            <div class="label">Clients <span class="count-pill"><?= $cntC ?></span></div>
            <a class="see-all" href="clients.php?q=<?= urlencode($q) ?>">See all →</a>
          </div>
          <?php foreach (array_slice($clients, 0, 6) as $c):
            $grad = search_avatar_gradient((string)$c['name']);
          ?>
            <a class="result-row" href="client_view.php?id=<?= (int)$c['id'] ?>">
              <div class="ico" style="background:<?= h($grad) ?>;color:#fff;border-color:transparent;"><?= h(user_initials((string)$c['name'])) ?></div>
              <div class="body">
                <div class="title"><?= search_highlight((string)$c['name'], $q) ?></div>
                <div class="meta">CLI-<?= str_pad((string)(int)$c['id'], 4, '0', STR_PAD_LEFT) ?></div>
              </div>
              <div class="trailing">Updated <?= h(date('M j', strtotime((string)$c['updated_at']))) ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (($scope === 'all' || $scope === 'docs') && $docs): ?>
        <div class="results-section">
          <div class="results-head">
            <div class="label">Docs <span class="count-pill"><?= $cntD ?></span></div>
            <a class="see-all" href="docs.php?q=<?= urlencode($q) ?>">See all →</a>
          </div>
          <?php foreach (array_slice($docs, 0, 6) as $d): ?>
            <a class="result-row" href="doc_edit.php?id=<?= (int)$d['id'] ?>">
              <div class="ico" style="background:rgba(168,85,247,0.08);color:#c4b5fd;border-color:rgba(168,85,247,0.2);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
              </div>
              <div class="body">
                <div class="title"><?= search_highlight((string)$d['title'], $q) ?></div>
                <div class="meta"><?= h($d['project_name']) ?></div>
              </div>
              <div class="trailing"><?= h(date('M j', strtotime((string)$d['updated_at']))) ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($cntAll === 0): ?>
        <div class="results-section">
          <div class="empty-results">No matches for &ldquo;<?= h($q) ?>&rdquo;. Try different keywords or remove filters.</div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="keyhints">
    <span class="hint"><span class="k">⌘</span><span class="k">K</span> Focus</span>
    <span class="hint"><span class="k">↵</span> Search</span>
    <span class="hint"><span class="k">Tab</span> Switch scope</span>
    <span class="hint"><span class="k">Esc</span> Clear</span>
  </div>
</div>

<script>
  const input = document.getElementById('searchInput');
  const big = document.getElementById('bigSearch');
  const clearBtn = document.getElementById('clearBtn');
  const scopeInput = document.getElementById('scopeInput');
  const form = document.getElementById('searchForm');

  input.addEventListener('input', () => { big.classList.toggle('has-text', input.value.length > 0); });
  clearBtn.addEventListener('click', () => {
    input.value = '';
    big.classList.remove('has-text');
    if (location.search) location.href = 'search.php';
    input.focus();
  });

  document.querySelectorAll('#scopeTabs .tab').forEach(t => {
    t.addEventListener('click', () => {
      scopeInput.value = t.dataset.scope;
      if (input.value) form.submit();
      else {
        document.querySelectorAll('#scopeTabs .tab').forEach(x => x.classList.remove('active'));
        t.classList.add('active');
      }
    });
  });

  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      input.focus(); input.select();
    } else if (e.key === 'Escape' && document.activeElement === input) {
      if (input.value) { input.value = ''; location.href = 'search.php'; }
    } else if (e.key === 'Tab' && document.activeElement === input && input.value) {
      e.preventDefault();
      const tabs = [...document.querySelectorAll('#scopeTabs .tab')];
      const i = tabs.findIndex(t => t.classList.contains('active'));
      tabs[(i + 1) % tabs.length].click();
    }
  });
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
