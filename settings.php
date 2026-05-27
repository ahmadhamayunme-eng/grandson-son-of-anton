<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();

$role = auth_user()['role_name'] ?? '';
if (!in_array($role, ['CEO','Manager','Super Admin'], true)) {
  http_response_code(403);
  $pageTitle = 'Forbidden';
  $activeKey = 'settings';
  require_once __DIR__ . '/layout.php';
  echo '<div style="padding:48px 32px;text-align:center;color:var(--text-muted);"><h3 style="margin-bottom:12px;color:var(--text);">Forbidden</h3></div>';
  require_once __DIR__ . '/layout_end.php';
  exit;
}

$pdo = db();
$ws = auth_workspace_id();

function settings_fetch_summary(PDO $pdo, string $table, int $ws): array {
  try {
    $st = $pdo->prepare("SELECT id, name, updated_at FROM {$table} WHERE workspace_id=? ORDER BY sort_order ASC LIMIT 5");
    $st->execute([$ws]);
    $rows = $st->fetchAll();
    $cnt = (int)$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE workspace_id=?")->execute([$ws]);
    $cntSt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE workspace_id=?");
    $cntSt->execute([$ws]);
    $total = (int)$cntSt->fetchColumn();
    $lastSt = $pdo->prepare("SELECT MAX(updated_at) FROM {$table} WHERE workspace_id=?");
    $lastSt->execute([$ws]);
    $last = $lastSt->fetchColumn();
    return ['rows' => $rows, 'total' => $total, 'last' => $last ?: null];
  } catch (Throwable $e) {
    return ['rows' => [], 'total' => 0, 'last' => null];
  }
}
function settings_relative_time(?string $dt): string {
  if (!$dt) return 'Not yet edited';
  $t = strtotime($dt);
  if ($t <= 0) return 'Not yet edited';
  $d = time() - $t;
  if ($d < 60) return 'Updated just now';
  if ($d < 3600) return 'Updated ' . (int)floor($d/60) . 'm ago';
  if ($d < 86400) return 'Updated ' . (int)floor($d/3600) . 'h ago';
  if ($d < 86400*2) return 'Updated yesterday';
  if ($d < 86400*7) return 'Updated ' . (int)floor($d/86400) . 'd ago';
  return 'Updated ' . date('M j', $t);
}

$projTypes = settings_fetch_summary($pdo, 'project_types', $ws);
$projStatuses = settings_fetch_summary($pdo, 'project_statuses', $ws);
$taskStatuses = settings_fetch_summary($pdo, 'task_statuses', $ws);

// Color palette for status preview chips (cycle for predictable output)
$statusDots = ['#22c55e', '#facc15', '#ef4444', '#6b7280', '#a855f7', '#3b82f6', '#f59e0b', '#ec4899'];
$taskDots   = ['#6b7280', '#facc15', '#a855f7', '#22c55e', '#ef4444', '#3b82f6', '#f59e0b', '#ec4899'];

$pageTitle = 'Settings';
$activeKey = 'settings';
$pageHeadExtra = <<<HTML
<style>
  .page-header {
    padding: 28px 32px 0;
    max-width: 1640px; margin: 0 auto; width: 100%;
    display: flex; align-items: flex-end; justify-content: space-between; gap: 16px;
  }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; max-width: 600px; }

  .search-wrap {
    max-width: 1640px; margin: 0 auto; width: 100%;
    padding: 24px 32px 0;
  }
  .search-box { position: relative; max-width: 480px; }
  .search-box svg {
    position: absolute; left: 14px; top: 50%;
    transform: translateY(-50%); width: 15px; height: 15px; color: var(--text-dim);
  }
  .search-box input {
    width: 100%; background: var(--surface); border: 1px solid var(--border);
    border-radius: 10px; padding: 11px 14px 11px 40px;
    font-size: 13px; color: var(--text); outline: none;
    transition: all 0.15s; font-family: inherit;
  }
  .search-box input::placeholder { color: var(--text-dim); }
  .search-box input:focus { border-color: var(--border-strong); background: var(--surface-2); }
  .search-box .kbd {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    font-size: 10.5px; color: var(--text-dim); background: var(--surface-2);
    border: 1px solid var(--border); border-radius: 5px; padding: 2px 6px;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
  }

  .body-stack {
    max-width: 1640px; margin: 0 auto; width: 100%;
    padding: 28px 32px 60px;
    display: flex; flex-direction: column; gap: 32px;
  }

  .section-head {
    display: flex; align-items: baseline; justify-content: space-between;
    margin-bottom: 14px;
  }
  .section-title {
    font-size: 11px; font-weight: 600; letter-spacing: 0.12em;
    text-transform: uppercase; color: var(--text-dim);
    display: inline-flex; align-items: center; gap: 8px;
  }
  .section-title::before {
    content: ''; width: 3px; height: 12px;
    background: var(--accent); border-radius: 2px;
  }
  .section-sub { font-size: 12px; color: var(--text-dim); }

  .card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 16px;
  }

  .s-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    transition: all 0.15s;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 16px;
    text-decoration: none;
    color: inherit;
    position: relative;
    overflow: hidden;
  }
  .s-card::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 14px;
    border: 1px solid transparent;
    pointer-events: none;
    transition: border-color 0.15s;
  }
  .s-card:hover {
    background: var(--surface-2);
    transform: translateY(-2px);
    border-color: var(--border-strong);
  }
  .s-card:hover::after { border-color: rgba(250,204,21,0.15); }

  .s-card-top {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
  }
  .s-card-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
  }
  .s-card-icon svg { width: 18px; height: 18px; }
  .s-card-icon.amber { background: rgba(250,204,21,0.08); color: var(--accent); border: 1px solid rgba(250,204,21,0.2); }
  .s-card-icon.blue { background: rgba(59,130,246,0.08); color: #93c5fd; border: 1px solid rgba(59,130,246,0.2); }
  .s-card-icon.violet { background: rgba(168,85,247,0.08); color: #c4b5fd; border: 1px solid rgba(168,85,247,0.2); }

  .s-card-count {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 999px;
    background: var(--bg); border: 1px solid var(--border);
    font-size: 11px; color: var(--text-muted); font-weight: 500;
    font-variant-numeric: tabular-nums;
  }
  .s-card-count .dot { width: 5px; height: 5px; border-radius: 50%; background: var(--accent); }

  .s-card-title {
    font-size: 15px; font-weight: 600;
    color: var(--text); letter-spacing: -0.01em;
    margin-bottom: 4px;
  }
  .s-card-desc {
    font-size: 12.5px; color: var(--text-muted); line-height: 1.55;
  }

  .s-card-preview {
    display: flex; flex-wrap: wrap; gap: 5px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
  }
  .mini-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 8px 3px 7px; border-radius: 999px;
    font-size: 11px; font-weight: 500;
    background: var(--bg); border: 1px solid var(--border);
    color: var(--text-muted);
  }
  .mini-chip .dot { width: 5px; height: 5px; border-radius: 50%; }
  .mini-chip svg { width: 10px; height: 10px; }
  .mini-chip.overflow {
    background: transparent; border-color: transparent;
    color: var(--text-dim); padding-left: 4px;
  }

  .s-card-foot {
    display: flex; align-items: center; justify-content: space-between;
    margin-top: auto;
    padding-top: 14px;
    border-top: 1px solid var(--border);
  }
  .s-card-link {
    color: var(--accent);
    font-size: 12.5px;
    font-weight: 500;
    display: inline-flex; align-items: center; gap: 6px;
    transition: gap 0.15s;
  }
  .s-card:hover .s-card-link { gap: 9px; }
  .s-card-link svg { width: 12px; height: 12px; }
  .s-card-meta {
    font-size: 11px; color: var(--text-dim);
    font-variant-numeric: tabular-nums;
  }

  .empty-preview {
    font-size: 11.5px; color: var(--text-dim); font-style: italic;
    padding-top: 14px; border-top: 1px solid var(--border);
  }

  /* ===== MOBILE (≤768px) =====
     The page had no mobile rules, so .search-wrap and .body-stack kept
     their 32px desktop side padding (the title uses the global 16px
     header column, so cards sat inset from the title). The s-card-top
     also left a tall empty band between the icon and the title. Align
     everything to the 16px column and tighten the cards. */
  @media (max-width: 768px) {
    .page-header {
      flex-direction: column;
      align-items: stretch;
      padding: 16px 16px 0;
      gap: 10px;
    }
    .page-title { font-size: clamp(20px, 5.6vw, 26px); line-height: 1.2; }
    .page-sub { font-size: 12px; }

    .search-wrap { padding: 16px 16px 0; }
    .search-box { max-width: none; }
    .search-box input { font-size: 16px; min-height: 46px; padding: 12px 14px 12px 40px; }
    .search-box .kbd { display: none; }

    .body-stack { padding: 18px 16px 28px; gap: 24px; }

    /* Section head: let the descriptive sub wrap under the label. */
    .section-head { flex-wrap: wrap; gap: 2px 10px; align-items: center; }
    .section-sub { flex-basis: 100%; }

    /* One card per row; tighter padding and a smaller gap so the icon
       isn't marooned above the title. */
    .card-grid { grid-template-columns: 1fr; gap: 12px; }
    .s-card { padding: 16px; gap: 12px; }
    .s-card:hover { transform: none; }
    .s-card-icon { width: 36px; height: 36px; }
    .s-card-foot { gap: 10px; }
    .s-card-link { font-size: 13px; }
  }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Settings</div>
      <div class="page-sub">Manage the reusable workflow lists, options and tags used across projects and tasks.</div>
    </div>
  </div>
</div>

<div class="search-wrap">
  <div class="search-box">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
    <input id="settingsSearch" placeholder="Search settings…">
    <span class="kbd">⌘K</span>
  </div>
</div>

<div class="body-stack">

  <div>
    <div class="section-head">
      <div class="section-title">Workflow</div>
      <span class="section-sub">Used by projects, boards, and reports</span>
    </div>

    <div class="card-grid">

      <a class="s-card" href="settings_project_types.php">
        <div class="s-card-top">
          <div class="s-card-icon amber">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>
          </div>
          <span class="s-card-count"><span class="dot"></span><?= (int)$projTypes['total'] ?> type<?= $projTypes['total'] === 1 ? '' : 's' ?></span>
        </div>
        <div>
          <div class="s-card-title">Project Types</div>
          <div class="s-card-desc">Category options used while creating projects — Design, Maintenance, Branding, etc.</div>
        </div>
        <?php if ($projTypes['rows']): ?>
          <div class="s-card-preview">
            <?php foreach (array_slice($projTypes['rows'], 0, 4) as $r): ?>
              <span class="mini-chip"><?= h($r['name']) ?></span>
            <?php endforeach; ?>
            <?php $extra = max(0, (int)$projTypes['total'] - 4); if ($extra > 0): ?>
              <span class="mini-chip overflow">+<?= $extra ?> more</span>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="empty-preview">No project types yet.</div>
        <?php endif; ?>
        <div class="s-card-foot">
          <span class="s-card-meta"><?= h(settings_relative_time($projTypes['last'])) ?></span>
          <span class="s-card-link">
            Open Project Types
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
          </span>
        </div>
      </a>

      <a class="s-card" href="settings_project_statuses.php">
        <div class="s-card-top">
          <div class="s-card-icon blue">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="3"></circle></svg>
          </div>
          <span class="s-card-count"><span class="dot"></span><?= (int)$projStatuses['total'] ?> status<?= $projStatuses['total'] === 1 ? '' : 'es' ?></span>
        </div>
        <div>
          <div class="s-card-title">Project Statuses</div>
          <div class="s-card-desc">Workflow status values for projects — controls board columns, filters and stage reports.</div>
        </div>
        <?php if ($projStatuses['rows']): ?>
          <div class="s-card-preview">
            <?php foreach (array_slice($projStatuses['rows'], 0, 4) as $i => $r): $color = $statusDots[$i % count($statusDots)]; ?>
              <span class="mini-chip"><span class="dot" style="background:<?= h($color) ?>;"></span><?= h($r['name']) ?></span>
            <?php endforeach; ?>
            <?php $extra = max(0, (int)$projStatuses['total'] - 4); if ($extra > 0): ?>
              <span class="mini-chip overflow">+<?= $extra ?> more</span>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="empty-preview">No project statuses yet.</div>
        <?php endif; ?>
        <div class="s-card-foot">
          <span class="s-card-meta"><?= h(settings_relative_time($projStatuses['last'])) ?></span>
          <span class="s-card-link">
            Open Project Statuses
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
          </span>
        </div>
      </a>

      <a class="s-card" href="settings_task_statuses.php">
        <div class="s-card-top">
          <div class="s-card-icon violet">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg>
          </div>
          <span class="s-card-count"><span class="dot"></span><?= (int)$taskStatuses['total'] ?> status<?= $taskStatuses['total'] === 1 ? '' : 'es' ?></span>
        </div>
        <div>
          <div class="s-card-title">Task Statuses</div>
          <div class="s-card-desc">Task progress labels used by team members — visible on every task card and in My Tasks.</div>
        </div>
        <?php if ($taskStatuses['rows']): ?>
          <div class="s-card-preview">
            <?php foreach (array_slice($taskStatuses['rows'], 0, 4) as $i => $r): $color = $taskDots[$i % count($taskDots)]; ?>
              <span class="mini-chip"><span class="dot" style="background:<?= h($color) ?>;"></span><?= h($r['name']) ?></span>
            <?php endforeach; ?>
            <?php $extra = max(0, (int)$taskStatuses['total'] - 4); if ($extra > 0): ?>
              <span class="mini-chip overflow">+<?= $extra ?> more</span>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="empty-preview">No task statuses yet.</div>
        <?php endif; ?>
        <div class="s-card-foot">
          <span class="s-card-meta"><?= h(settings_relative_time($taskStatuses['last'])) ?></span>
          <span class="s-card-link">
            Open Task Statuses
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
          </span>
        </div>
      </a>

    </div>
  </div>
</div>

<script>
  const search = document.getElementById('settingsSearch');
  if (search) {
    search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      document.querySelectorAll('.s-card').forEach(card => {
        const text = card.textContent.toLowerCase();
        card.style.display = (!q || text.includes(q)) ? '' : 'none';
      });
    });

    document.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        search.focus();
        search.select();
      }
    });
  }
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
