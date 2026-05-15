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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post(); csrf_verify();
  $action = $_POST['action'] ?? 'add';

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { flash_set('error', 'Invalid task status selected.'); redirect('settings_task_statuses.php'); }
    try {
      $st = $pdo->prepare('DELETE FROM task_statuses WHERE workspace_id = ? AND id = ?');
      $st->execute([$ws, $id]);
      flash_set('success', $st->rowCount() ? 'Task status deleted permanently.' : 'Task status not found.');
    } catch (Throwable $e) { flash_set('error', 'Unable to delete this status. It may still be in use.'); }
    redirect('settings_task_statuses.php');
  }
  if ($action === 'bulk_delete') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    if (!$ids) { flash_set('error', 'Select at least one status to delete.'); redirect('settings_task_statuses.php'); }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    try {
      $st = $pdo->prepare("DELETE FROM task_statuses WHERE workspace_id = ? AND id IN ($marks)");
      $st->execute(array_merge([$ws], $ids));
      flash_set('success', $st->rowCount() . ' task status(es) deleted permanently.');
    } catch (Throwable $e) { flash_set('error', 'Some selected statuses could not be deleted because they are in use.'); }
    redirect('settings_task_statuses.php');
  }
  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id <= 0 || $name === '') { flash_set('error', 'Valid status name required.'); redirect('settings_task_statuses.php'); }
    $pdo->prepare('UPDATE task_statuses SET name=?, updated_at=? WHERE workspace_id=? AND id=?')
        ->execute([$name, now(), $ws, $id]);
    flash_set('success', 'Task status updated.');
    redirect('settings_task_statuses.php');
  }
  if ($action === 'reorder') {
    $order = (array)($_POST['order'] ?? []);
    if (!$order) { flash_set('error', 'No statuses selected for sorting.'); redirect('settings_task_statuses.php'); }
    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare('UPDATE task_statuses SET sort_order=?, updated_at=? WHERE workspace_id=? AND id=?');
      $i = 1;
      foreach ($order as $idRaw) { $id = (int)$idRaw; if ($id > 0) $st->execute([$i++, now(), $ws, $id]); }
      $pdo->commit();
      flash_set('success', 'Sort order updated.');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash_set('error', 'Unable to update sort order.');
    }
    redirect('settings_task_statuses.php');
  }

  $name = trim($_POST['name'] ?? '');
  if ($name === '') { flash_set('error', 'Name required.'); redirect('settings_task_statuses.php'); }
  $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM task_statuses WHERE workspace_id=$ws")->fetch()['n'];
  $pdo->prepare("INSERT INTO task_statuses (workspace_id,name,sort_order,created_at,updated_at) VALUES (?,?,?,?,?)")
      ->execute([$ws, $name, $sort, now(), now()]);
  flash_set('success', 'Task status added.');
  redirect('settings_task_statuses.php');
}

$rows = $pdo->query("SELECT * FROM task_statuses WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();

// Usage counts per status — tasks.status stores the NAME of the status, not its id
$usage = [];
try {
  $us = $pdo->prepare('SELECT status, COUNT(*) AS c FROM tasks WHERE workspace_id=? GROUP BY status');
  $us->execute([$ws]);
  foreach ($us->fetchAll() as $u) { $usage[(string)$u['status']] = (int)$u['c']; }
} catch (Throwable $e) { $usage = []; }

function sts_status_color(string $name): array {
  $s = strtolower($name);
  if (strpos($s, 'progress') !== false) return ['#facc15', 'In Progress'];
  if (strpos($s, 'review') !== false || strpos($s, 'completed') !== false || strpos($s, 'needs') !== false) return ['#a855f7', 'Review'];
  if (strpos($s, 'approved') !== false || strpos($s, 'ready') !== false) return ['#22c55e', 'Approved'];
  if (strpos($s, 'submit') !== false) return ['#3b82f6', 'Submitted'];
  if (strpos($s, 'block') !== false || strpos($s, 'hold') !== false) return ['#ef4444', 'Blocked'];
  if (strpos($s, 'to do') !== false || strpos($s, 'todo') !== false || strpos($s, 'open') !== false) return ['#6b7280', 'To Do'];
  return ['#facc15', 'Custom'];
}

$pageTitle = 'Task Statuses';
$activeKey = 'settings';
$pageHeadExtra = <<<HTML
<style>
  .settings-topbar { max-width: 1640px; margin: 0 auto; width: 100%; padding: 24px 32px 0; display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-dim); }
  .settings-topbar .crumb { color: var(--text-muted); transition: color 0.12s; text-decoration: none; }
  .settings-topbar .crumb:hover { color: var(--text); }
  .settings-topbar .sep { color: var(--text-dim); opacity: 0.6; }
  .settings-topbar .current { color: var(--text); font-weight: 500; }
  .page-header { padding: 18px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; }
  .page-stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 12px 18px; display: flex; flex-direction: column; align-items: flex-end; gap: 2px; min-width: 132px; }
  .page-stat-card .num { font-size: 24px; font-weight: 700; color: var(--accent); letter-spacing: -0.02em; font-variant-numeric: tabular-nums; line-height: 1; }
  .page-stat-card .lbl { font-size: 10.5px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; margin-top: 2px; }
  .settings-tabs { max-width: 1640px; margin: 0 auto; width: 100%; padding: 22px 32px 0; border-bottom: 1px solid var(--border); display: flex; gap: 4px; overflow-x: auto; }
  .settings-tabs::-webkit-scrollbar { height: 0; }
  .settings-tabs .tab { padding: 10px 14px; font-size: 12.5px; font-weight: 500; color: var(--text-muted); border-bottom: 2px solid transparent; margin-bottom: -1px; transition: all 0.15s; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 7px; background: transparent; border-top: 0; border-left: 0; border-right: 0; text-decoration: none; }
  .settings-tabs .tab svg { width: 13px; height: 13px; }
  .settings-tabs .tab:hover { color: var(--text); }
  .settings-tabs .tab.active { color: var(--text); border-bottom-color: var(--accent); }
  .body-stack { max-width: 1640px; margin: 0 auto; width: 100%; padding: 24px 32px 60px; display: flex; flex-direction: column; gap: 20px; }
  .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  .panel-head { padding: 16px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 16px; }
  .panel-title { font-size: 13px; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 8px; }
  .panel-title .ico { width: 22px; height: 22px; background: var(--accent-soft); color: var(--accent); border: 1px solid rgba(250,204,21,0.2); border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; }
  .panel-title .ico svg { width: 12px; height: 12px; }
  .panel-actions { display: flex; gap: 8px; align-items: center; }
  .add-form { padding: 16px 18px; display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; background: var(--bg); }
  .icon-picker-btn { width: 42px; height: 42px; border-radius: 10px; background: var(--surface); border: 1px solid var(--border); color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; transition: all 0.12s; }
  .icon-picker-btn svg.glyph { width: 18px; height: 18px; }
  .add-input-wrap { position: relative; }
  .add-input-wrap .input { padding: 12px 14px; font-size: 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; height: 42px; width: 100%; }
  .add-input-wrap .hint { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-size: 11px; color: var(--text-dim); font-family: 'JetBrains Mono', ui-monospace, monospace; pointer-events: none; }
  .add-form .btn-primary { padding: 0 18px; height: 42px; font-size: 13.5px; border-radius: 10px; }
  .preview-strip { display: flex; align-items: center; gap: 10px; padding: 10px 18px 16px; background: var(--bg); border-bottom: 1px solid var(--border); }
  .preview-strip .preview-label { font-size: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600; }
  .status-chip { display: inline-flex; align-items: center; gap: 7px; padding: 5px 12px 5px 9px; background: var(--surface); border: 1px solid var(--border-strong); border-radius: 999px; font-size: 12.5px; color: var(--text); font-weight: 500; }
  .status-chip .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 0 2px rgba(250,204,21,0.18); }
  .list-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .list-table thead th { text-align: left; padding: 10px 16px; font-size: 10.5px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-dim); background: var(--bg); border-bottom: 1px solid var(--border); white-space: nowrap; }
  .list-table thead th.right { text-align: right; }
  .list-table thead th.center { text-align: center; }
  .list-table tbody tr { border-bottom: 1px solid var(--border); transition: background 0.12s; }
  .list-table tbody tr:last-child { border-bottom: none; }
  .list-table tbody tr.dirty { background: rgba(250,204,21,0.03); }
  .list-table tbody td { padding: 10px 16px; color: var(--text); vertical-align: middle; }
  .drag-cell { width: 36px; text-align: center; cursor: grab; color: var(--text-dim); transition: color 0.12s; }
  .drag-cell:hover { color: var(--text); }
  .drag-cell svg { width: 14px; height: 14px; }
  .row-check { width: 16px; height: 16px; border-radius: 4px; border: 1.5px solid var(--border-strong); background: var(--bg); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.12s; appearance: none; -webkit-appearance: none; padding: 0; margin: 0; position: relative; }
  .row-check:checked { background: var(--accent); border-color: var(--accent); }
  .row-check:checked::after { content: ''; position: absolute; left: 4px; top: 1px; width: 4px; height: 8px; border: solid #1a1400; border-width: 0 2px 2px 0; transform: rotate(45deg); }
  .status-row { display: flex; align-items: center; gap: 10px; width: 100%; }
  .status-row .swatch { width: 16px; height: 16px; border-radius: 50%; flex-shrink: 0; box-shadow: 0 0 0 2px rgba(255,255,255,0.04); }
  .status-row .name-input { flex: 1; background: transparent; border: 1px solid transparent; border-radius: 7px; padding: 7px 10px; font-size: 13.5px; color: var(--text); font-weight: 500; outline: none; transition: all 0.12s; min-width: 0; }
  .status-row .name-input:hover { border-color: var(--border); }
  .status-row .name-input:focus { border-color: var(--accent); background: var(--bg); }
  .status-kind { font-size: 11px; color: var(--text-dim); padding: 2px 7px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface-2); text-transform: lowercase; }
  .usage-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 500; background: var(--bg); border: 1px solid var(--border); color: var(--text-muted); font-variant-numeric: tabular-nums; }
  .usage-pill .dot { width: 5px; height: 5px; border-radius: 50%; background: var(--accent); }
  .usage-pill.zero { color: var(--text-dim); }
  .usage-pill.zero .dot { background: var(--text-dim); }
  .sort-num { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px; color: var(--text-muted); font-variant-numeric: tabular-nums; width: 28px; height: 22px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 5px; display: inline-flex; align-items: center; justify-content: center; }
  .row-actions { display: flex; align-items: center; gap: 6px; justify-content: flex-end; }
  .btn-tiny { padding: 5px 11px; font-size: 11.5px; font-weight: 500; border-radius: 6px; transition: all 0.12s; border: 1px solid; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; }
  .btn-tiny svg { width: 11px; height: 11px; }
  .btn-tiny.save { background: transparent; color: var(--text-dim); border-color: var(--border); }
  .btn-tiny.save:hover { color: var(--text); border-color: var(--border-strong); }
  .btn-tiny.save.show { background: var(--accent); color: #1a1400; border-color: var(--accent); }
  .btn-tiny.delete { background: transparent; color: #fca5a5; border-color: rgba(239,68,68,0.25); }
  .btn-tiny.delete:hover { background: var(--danger-soft); border-color: rgba(239,68,68,0.4); }
  .order-hint { padding: 12px 18px; background: var(--bg); border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; font-size: 12px; color: var(--text-dim); }
  .order-hint svg { width: 13px; height: 13px; color: var(--text-muted); }
  .order-hint b { color: var(--text-muted); font-weight: 500; }
  .empty-row td { padding: 28px 14px; text-align: center; color: var(--text-dim); font-style: italic; }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="settings-topbar">
  <?= back_button_html('settings.php', 'Settings') ?>
  <a class="crumb" href="settings.php">Settings</a>
  <span class="sep">/</span>
  <span class="current">Task Statuses</span>
</div>

<div class="page-header">
  <div class="page-header-left">
    <div>
      <div class="page-title">Task Statuses</div>
      <div class="page-sub">Status labels used to track task progress across all projects.</div>
    </div>
  </div>
  <div class="page-stat-card">
    <span class="num"><?= (int)count($rows) ?></span>
    <span class="lbl">Total Statuses</span>
  </div>
</div>

<div class="settings-tabs">
  <a class="tab" href="settings.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"></path></svg>Workspace</a>
  <a class="tab" href="settings_project_statuses.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="3"></circle></svg>Project Statuses</a>
  <a class="tab" href="settings_project_types.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>Project Types</a>
  <a class="tab active" href="settings_task_statuses.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg>Task Statuses</a>
</div>

<div class="body-stack">

  <!-- Add Status panel -->
  <div class="panel">
    <div class="panel-head">
      <div class="panel-title">
        <span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg></span>
        Add Task Status
      </div>
    </div>

    <form method="post" class="add-form">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <span class="icon-picker-btn" title="Task status">
        <svg class="glyph" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg>
      </span>

      <div class="add-input-wrap">
        <input class="input" id="newName" name="name" placeholder="e.g. In Progress" maxlength="32" required>
        <span class="hint" id="charHint">0/32</span>
      </div>

      <button class="btn btn-primary save-flash" type="submit">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add Task Status
      </button>
    </form>

    <div class="preview-strip">
      <span class="preview-label">Preview</span>
      <span class="status-chip" id="previewChip">
        <span class="dot" id="previewDot"></span>
        <span id="previewName">New Status</span>
      </span>
    </div>
  </div>

  <!-- Current Statuses -->
  <div class="panel">
    <div class="panel-head">
      <div class="panel-title">
        <span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg></span>
        Current Statuses (<?= count($rows) ?>)
      </div>
      <div class="panel-actions">
        <button type="submit" form="bulkDelForm" class="btn btn-danger" onclick="return confirm('Delete selected task statuses permanently?');">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path></svg>
          Delete Selected
        </button>
        <button type="submit" form="reorderForm" class="btn btn-primary save-flash">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
          Save Order
        </button>
      </div>
    </div>

    <form method="post" id="bulkDelForm"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="bulk_delete"></form>
    <form method="post" id="reorderForm"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="reorder"></form>

    <table class="list-table">
      <thead>
        <tr>
          <th style="width:36px;" class="center">Move</th>
          <th style="width:44px;" class="center"><input type="checkbox" class="row-check" id="checkAll" onclick="document.querySelectorAll('.row-check.row-pick').forEach(cb => cb.checked = this.checked)"></th>
          <th>Status</th>
          <th style="width:120px;">Kind</th>
          <th style="width:120px;">In use</th>
          <th style="width:80px;" class="center">Sort</th>
          <th style="width:200px;" class="right">Actions</th>
        </tr>
      </thead>
      <tbody id="rows">
        <?php if (!$rows): ?>
          <tr class="empty-row"><td colspan="7">No task statuses yet.</td></tr>
        <?php else: foreach ($rows as $r):
          $cnt = $usage[(string)$r['name']] ?? 0;
          [$color, $kind] = sts_status_color((string)$r['name']);
        ?>
          <tr data-id="<?= (int)$r['id'] ?>">
            <td class="drag-cell" draggable="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="5 9 12 2 19 9"></polyline><polyline points="5 15 12 22 19 15"></polyline></svg></td>
            <td style="text-align:center;"><input class="row-check row-pick" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" form="bulkDelForm"></td>
            <td>
              <form method="post" class="status-row" id="updateForm_<?= (int)$r['id'] ?>">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <span class="swatch" style="background:<?= h($color) ?>"></span>
                <input class="name-input" name="name" value="<?= h($r['name']) ?>" data-original="<?= h($r['name']) ?>" required>
              </form>
            </td>
            <td><span class="status-kind"><?= h($kind) ?></span></td>
            <td>
              <span class="usage-pill <?= $cnt === 0 ? 'zero' : '' ?>"><span class="dot"></span><?= (int)$cnt ?> task<?= $cnt === 1 ? '' : 's' ?></span>
            </td>
            <td style="text-align:center;"><span class="sort-num"><?= (int)$r['sort_order'] ?></span></td>
            <td>
              <div class="row-actions">
                <button class="btn-tiny save" type="submit" form="updateForm_<?= (int)$r['id'] ?>">Save</button>
                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this status permanently?');">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn-tiny delete" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path></svg>Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

    <div class="order-hint">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
      <span>Drag the <b>handle</b> to reorder. Color swatches are auto-derived from the status name. Status names should match the values stored in <code>tasks.status</code>.</span>
    </div>
  </div>
</div>

<script>
(function(){
  const newName = document.getElementById('newName');
  const charHint = document.getElementById('charHint');
  const previewName = document.getElementById('previewName');
  if (newName) {
    newName.addEventListener('input', () => {
      if (newName.value.length > 32) newName.value = newName.value.slice(0, 32);
      charHint.textContent = newName.value.length + '/32';
      previewName.textContent = newName.value.trim() || 'New Status';
    });
  }

  document.querySelectorAll('.name-input').forEach(inp => {
    inp.addEventListener('input', () => {
      const dirty = inp.value !== inp.dataset.original;
      const tr = inp.closest('tr');
      if (!tr) return;
      tr.classList.toggle('dirty', dirty);
      const sb = tr.querySelector('.btn-tiny.save');
      if (sb) sb.classList.toggle('show', dirty);
    });
  });

  const tbody = document.getElementById('rows');
  if (tbody) {
    let drag = null;
    function sync() {
      const reorderForm = document.getElementById('reorderForm');
      reorderForm.querySelectorAll('input[name="order[]"]').forEach(el => el.remove());
      tbody.querySelectorAll('tr[data-id]').forEach((tr, i) => {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'order[]';
        inp.value = tr.dataset.id;
        reorderForm.appendChild(inp);
        const sortCell = tr.querySelector('.sort-num');
        if (sortCell) sortCell.textContent = i + 1;
      });
    }
    tbody.querySelectorAll('tr[data-id]').forEach(tr => {
      const handle = tr.querySelector('.drag-cell');
      if (handle) {
        handle.addEventListener('dragstart', () => { drag = tr; tr.style.opacity = '0.4'; });
        handle.addEventListener('dragend', () => { if (drag) drag.style.opacity = ''; drag = null; });
      }
      tr.addEventListener('dragover', e => e.preventDefault());
      tr.addEventListener('drop', e => {
        e.preventDefault();
        if (!drag || drag === tr) return;
        const r = tr.getBoundingClientRect();
        tbody.insertBefore(drag, e.clientY > r.top + r.height / 2 ? tr.nextSibling : tr);
        sync();
      });
    });
    sync();
  }
})();
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
