<?php
require_once __DIR__ . '/layout.php';

$pdo = db();
$ws = auth_workspace_id();
$user = auth_user();
$userId = (int)($user['id'] ?? 0);
$role = $user['role_name'] ?? '';
$can_manage = in_array($role, ['CEO', 'CTO', 'Super Admin'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post();
  csrf_verify();
  if (!$can_manage) {
    flash_set('error', 'No permission.');
    redirect('clients.php');
  }

  $name = trim($_POST['name'] ?? '');
  $notes = trim($_POST['notes'] ?? '');
  if ($name === '') {
    flash_set('error', 'Client name required.');
    redirect('clients.php');
  }

  $pdo->prepare('INSERT INTO clients (workspace_id,name,notes,created_at,updated_at) VALUES (?,?,?,?,?)')
      ->execute([$ws, $name, $notes ?: null, now(), now()]);
  flash_set('success', 'Client created.');
  redirect('clients.php');
}

$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;
$like = '%' . $q . '%';

$params = [':ws' => $ws, ':like' => $like];

$countSql = 'SELECT COUNT(*) FROM clients c WHERE c.workspace_id = :ws AND c.name LIKE :like';
$countSt = $pdo->prepare($countSql);
$countSt->execute($params);
$totalRows = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
  $page = $totalPages;
  $offset = ($page - 1) * $perPage;
}

$listSql = "
SELECT
  c.id,
  c.name,
  c.notes,
  c.updated_at,
  COUNT(DISTINCT p.id) AS project_count,
  MAX(COALESCE(p.updated_at, c.updated_at)) AS last_active,
  MAX(ps.name) AS status_name,
  MAX(pt.name) AS type_name
FROM clients c
LEFT JOIN projects p ON p.client_id = c.id AND p.workspace_id = c.workspace_id
LEFT JOIN project_statuses ps ON ps.id = p.status_id
LEFT JOIN project_types pt ON pt.id = p.type_id
WHERE c.workspace_id = :ws AND c.name LIKE :like
GROUP BY c.id, c.name, c.notes, c.updated_at
ORDER BY last_active DESC, c.id DESC
LIMIT :limit OFFSET :offset
";
$listSt = $pdo->prepare($listSql);
foreach ($params as $k => $v) {
  $listSt->bindValue($k, $v, PDO::PARAM_STR);
}
$listSt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listSt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listSt->execute();
$clients = $listSt->fetchAll();

$allCount = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE workspace_id={$ws}")->fetchColumn();

function client_last_active_label(?string $date): string {
  if (!$date) return '—';
  $ts = strtotime($date);
  if (!$ts) return '—';
  $diff = time() - $ts;
  if ($diff < 3600) return max(1, (int)floor($diff / 60)) . ' min ago';
  if ($diff < 86400) return (int)floor($diff / 3600) . ' hours ago';
  if ($diff < 172800) return 'Yesterday';
  return date('M j, Y', $ts);
}

function client_status_label(array $row): string {
  $raw = strtolower((string)($row['status_name'] ?? ''));
  if ($raw === '') return 'Active';
  if (strpos($raw, 'close') !== false || strpos($raw, 'done') !== false || strpos($raw, 'cancel') !== false) return 'Completed';
  if (strpos($raw, 'hold') !== false || strpos($raw, 'pause') !== false) return 'Paused';
  return 'Active';
}

function client_status_class(string $status): string {
  $s = strtolower($status);
  if ($s === 'completed') return 'is-completed';
  if ($s === 'paused') return 'is-paused';
  return 'is-active';
}
?>

<style>
  .clients-shell {
    border: 1px solid rgba(255, 255, 255, 0.07);
    border-radius: 18px;
    background: linear-gradient(130deg, rgba(14, 15, 22, 0.96), rgba(9, 10, 15, 0.96));
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
    padding: 18px;
  }
  .clients-title { font-size: 2rem; font-weight: 600; margin: 0 0 14px; }
  .clients-toolbar { display: grid; grid-template-columns: 1fr auto; gap: 12px; margin-bottom: 14px; }
  .clients-search-wrap { position: relative; }
  .clients-search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #f3cb58; }
  .clients-search {
    width: 100%; background: linear-gradient(90deg, rgba(31, 32, 43, 0.95), rgba(23, 24, 35, 0.93));
    border: 1px solid rgba(255, 255, 255, 0.09); border-radius: 10px; color: #f2f2f4;
    padding: 10px 12px 10px 40px;
  }
  .clients-search:focus { outline: none; border-color: rgba(255, 212, 83, 0.55); box-shadow: 0 0 0 3px rgba(255, 212, 83, 0.13); }
  .clients-new-btn {
    border: 1px solid rgba(255, 255, 255, 0.08); background: linear-gradient(160deg, rgba(42, 43, 57, 0.92), rgba(31, 32, 44, 0.9));
    border-radius: 10px; color: #efeff2; font-weight: 600; padding: 10px 14px; text-decoration: none;
  }
  .clients-new-btn:hover { color: #fff; border-color: rgba(255, 212, 83, 0.35); }

  .clients-card {
    border: 1px solid rgba(255, 255, 255, 0.07);
    border-radius: 14px;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.015);
  }
  .clients-tabs { display: flex; gap: 24px; padding: 0 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); }
  .clients-tab { display: inline-block; color: rgba(255,255,255,0.74); text-decoration: none; padding: 11px 0; font-weight: 500; border-bottom: 2px solid transparent; }
  .clients-tab.active { color: #f2cb5f; border-color: #f2cb5f; }
  .clients-meta-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); color: rgba(255,255,255,0.62); font-size: .88rem;
  }
  .clients-table { width: 100%; border-collapse: collapse; }
  .clients-table th, .clients-table td { padding: 13px 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.06); }
  .clients-table th { font-size: .86rem; color: rgba(255, 255, 255, 0.62); font-weight: 600; background: rgba(255, 255, 255, 0.02); }
  .clients-table td { color: rgba(237,237,241,0.9); }
  .clients-logo {
    width: 30px; height: 30px; border-radius: 50%; border: 1px solid rgba(255,212,83,.6); color: #f3ce62;
    display: inline-flex; align-items: center; justify-content: center; margin-right: 9px; font-size: .9rem;
  }
  .clients-name { color: #f5f5f6; text-decoration: none; font-weight: 600; }
  .clients-name:hover { color: #ffd96f; }
  .chip {
    display: inline-flex; align-items: center; border-radius: 999px; padding: 3px 8px; font-size: .74rem;
    border: 1px solid rgba(255, 255, 255, 0.12); background: rgba(255,255,255,.07); color: #ddd;
  }
  .chip.tag { background: rgba(255, 212, 83, 0.14); border-color: rgba(255, 212, 83, 0.34); color: #f3d471; }
  .status-dot { width: 8px; height: 8px; border-radius: 50%; margin-right: 7px; display: inline-block; }
  .is-active .status-dot { background: #f2cb5f; }
  .is-completed .status-dot { background: #67d091; }
  .is-paused .status-dot { background: #9aa0ad; }
  .clients-footer {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 16px; color: rgba(255,255,255,.6); font-size: .92rem;
  }
  .clients-footer a { color: rgba(255,255,255,.78); text-decoration: none; margin: 0 8px; }
  .clients-footer a:hover { color: #f5d36e; }

  @media (max-width: 1040px) {
    .clients-toolbar { grid-template-columns: 1fr; }
    .clients-table { min-width: 860px; }
    .clients-responsive { overflow-x: auto; }
  }
</style>

<div class="clients-shell">
  <h1 class="clients-title">Clients</h1>

  <form class="clients-toolbar" method="get">
    <div class="clients-search-wrap">
      <span class="clients-search-icon">⌕</span>
      <input class="clients-search" name="q" value="<?=h($q)?>" placeholder="Search clients..." autocomplete="off">
    </div>
    <?php if ($can_manage): ?>
      <button type="button" class="clients-new-btn" data-bs-toggle="modal" data-bs-target="#addClient">＋ New Client</button>
    <?php endif; ?>
  </form>

  <section class="clients-card">
    <nav class="clients-tabs">
      <a class="clients-tab active" href="clients.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>">All Clients</a>
    </nav>

    <div class="clients-meta-row">
      <div>
        Showing <?=count($clients)?> of <?=$allCount?>
        <?php if ($q !== ''): ?>for “<?=h($q)?>”<?php endif; ?>
      </div>
      <div>Page <?=$page?> of <?=$totalPages?></div>
    </div>

    <div class="clients-responsive">
      <table class="clients-table">
        <thead>
          <tr>
            <th>Logo</th>
            <th>Contacts</th>
            <th>Tags</th>
            <th>Status</th>
            <th>Projects</th>
            <th>Last Active</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$clients): ?>
            <tr><td colspan="7" class="text-muted">No clients found.</td></tr>
          <?php else: foreach ($clients as $c):
            $status = client_status_label($c);
            $statusClass = client_status_class($status);
            $tag = trim((string)($c['type_name'] ?? ''));
            if ($tag === '') $tag = 'General';
          ?>
            <tr>
              <td>
                <span class="clients-logo">⚡</span>
                <a class="clients-name" href="client_view.php?id=<?=$c['id']?>"><?=h($c['name'])?></a>
              </td>
              <td class="text-muted">—</td>
              <td><span class="chip tag"><?=h($tag)?></span></td>
              <td><span class="<?=$statusClass?>"><span class="status-dot"></span><?=h($status)?></span></td>
              <td><?= (int)$c['project_count'] ?></td>
              <td class="text-muted"><?=h(client_last_active_label($c['last_active']))?></td>
              <td class="text-end"><a class="text-decoration-none" href="client_view.php?id=<?=$c['id']?>">›</a></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <footer class="clients-footer">
      <div>
        <?php if ($page > 1): ?><a href="clients.php?q=<?=urlencode($q)?>&amp;page=<?=$page - 1?>">Previous</a><?php else: ?><span class="opacity-50">Previous</span><?php endif; ?>
        <?php if ($page < $totalPages): ?><a href="clients.php?q=<?=urlencode($q)?>&amp;page=<?=$page + 1?>">Next</a><?php else: ?><span class="opacity-50 ms-2">Next</span><?php endif; ?>
      </div>
      <div>Page <?=$page?> of <?=$totalPages?></div>
    </footer>
  </section>
</div>

<?php if ($can_manage): ?>
<div class="modal fade" id="addClient" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content card p-3">
      <div class="modal-header border-0">
        <h5 class="modal-title">Add Client</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Client Name</label><input class="form-control" name="name" required></div>
          <div class="mb-3"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="3"></textarea></div>
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
