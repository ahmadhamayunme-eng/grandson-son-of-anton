<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();

$pdo = db();
$ws = auth_workspace_id();
$u = auth_user();
$role = $u['role_name'] ?? '';
$can_manager = in_array($role, ['Manager','Super Admin'], true);
$can_manage = in_array($role, ['CEO','Manager','Super Admin'], true);
$can_final_status = $can_manage;

function tv_column_exists(PDO $pdo, string $table, string $column): bool {
  try { $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?"); $stmt->execute([$column]); return (bool)$stmt->fetch(); }
  catch (Throwable $e) { return false; }
}
function tv_pluck($rows, string $key): array {
  $out = [];
  foreach ((array)$rows as $row) { if (is_array($row) && isset($row[$key])) $out[] = $row[$key]; }
  return $out;
}
function tv_avatar_gradient(string $seed): string {
  $palette = [
    'linear-gradient(135deg,#22c55e,#16a34a)','linear-gradient(135deg,#a855f7,#7c3aed)',
    'linear-gradient(135deg,#0ea5e9,#0284c7)','linear-gradient(135deg,#f97316,#ea580c)',
    'linear-gradient(135deg,#ec4899,#be185d)','linear-gradient(135deg,#3b82f6,#1d4ed8)',
    'linear-gradient(135deg,#14b8a6,#0f766e)','linear-gradient(135deg,#facc15,#ca8a04)',
  ];
  return $palette[crc32($seed) % count($palette)];
}
function tv_status_key(string $status): string {
  $s = strtolower($status);
  if (strpos($s, 'progress') !== false) return 'progress';
  if (strpos($s, 'review') !== false) return 'review';
  if (strpos($s, 'done') !== false || strpos($s, 'complete') !== false || strpos($s, 'approved') !== false || strpos($s, 'submitted') !== false) return 'done';
  return 'todo';
}
function tv_priority(array $task): array {
  $status = strtolower((string)($task['status'] ?? ''));
  if (strpos($status,'submitted')!==false || strpos($status,'approved')!==false || strpos($status,'completed')!==false) return ['Low','low'];
  if (empty($task['due_date'])) return ['Medium','medium'];
  $dueTs = strtotime((string)$task['due_date']);
  $todayTs = strtotime(date('Y-m-d'));
  $diff = (int)floor(($dueTs - $todayTs) / 86400);
  if ($diff <= 1) return ['Critical','critical'];
  if ($diff <= 3) return ['High','high'];
  if ($diff <= 7) return ['Medium','medium'];
  return ['Low','low'];
}

$has_comment_parent = tv_column_exists($pdo, 'comments', 'parent_comment_id');
$id = (int)($_GET['id'] ?? 0);

try {
  $stmt = $pdo->prepare("SELECT t.*, p.name AS project_name, p.id AS project_id, c.name AS client_name, c.id AS client_id, ph.name AS phase_name
    FROM tasks t
    JOIN projects p ON p.id=t.project_id
    JOIN clients c ON c.id=p.client_id
    LEFT JOIN phases ph ON ph.id=t.phase_id
    WHERE t.id=? AND t.workspace_id=?");
  $stmt->execute([$id, $ws]);
  $task = $stmt->fetch();
} catch (Throwable $e) {
  $stmt = $pdo->prepare("SELECT t.*, p.name AS project_name, p.id AS project_id, c.name AS client_name, c.id AS client_id, NULL AS phase_name
    FROM tasks t JOIN projects p ON p.id=t.project_id JOIN clients c ON c.id=p.client_id
    WHERE t.id=? AND t.workspace_id=?");
  $stmt->execute([$id, $ws]);
  $task = $stmt->fetch();
}

if (!$task) {
  $pageTitle = 'Task not found';
  $activeKey = 'my-tasks';
  require_once __DIR__ . '/layout.php';
  echo '<div style="padding:48px 32px;text-align:center;color:var(--text-muted);"><h3 style="margin-bottom:12px;color:var(--text);">Task not found</h3><div style="margin-top:18px;">' . back_button_html('my_tasks.php', 'Back to My Tasks') . '</div></div>';
  require_once __DIR__ . '/layout_end.php';
  exit;
}

$assignable_users = [];
try {
  $assignable_users = $pdo->query("SELECT u.id,u.name,r.name AS role_name
    FROM users u JOIN roles r ON r.id=u.role_id
    WHERE u.workspace_id=$ws AND u.is_active=1 AND r.name IN ('Developer','SEO')
    ORDER BY u.name ASC")->fetchAll();
} catch (Throwable $e) { $assignable_users = []; }
$assignable_user_ids = array_map('intval', tv_pluck($assignable_users, 'id'));

$assignees = [];
try {
  $assigneesStmt = $pdo->prepare("SELECT u.id,u.name,r.name AS role_name FROM task_assignees ta JOIN users u ON u.id=ta.user_id LEFT JOIN roles r ON r.id=u.role_id WHERE ta.task_id=? ORDER BY u.name ASC");
  $assigneesStmt->execute([$id]);
  $assignees = $assigneesStmt->fetchAll();
} catch (Throwable $e) { $assignees = []; }
$assignee_ids = array_map('intval', tv_pluck($assignees, 'id'));
$assignee_names = array_map(fn($r) => $r['name'], $assignees);

$locked = (bool)$task['locked_at'];

try {
  $statusRows = $pdo->query("SELECT name FROM task_statuses WHERE workspace_id=$ws ORDER BY sort_order ASC")->fetchAll();
  $statuses = array_map(fn($r) => $r['name'], $statusRows);
} catch (Throwable $e) { $statuses = []; }
if (!$statuses) $statuses = ['To Do','In Progress','Completed','Approved','Submitted to Client'];
if (!$can_final_status) {
  $statuses = array_values(array_filter($statuses, fn($s) => !in_array($s, ['Approved','Approved (Ready to Submit)','Submitted to Client'], true)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post(); csrf_verify();

  if (isset($_POST['assign_task'])) {
    if (!$can_manage) { flash_set('error', 'No permission.'); redirect("task_view.php?id=$id"); }
    $selected = array_map('intval', $_POST['assignees'] ?? []);
    $selected = array_values(array_unique(array_intersect($selected, $assignable_user_ids)));
    $pdo->prepare("DELETE FROM task_assignees WHERE task_id=?")->execute([$id]);
    foreach ($selected as $uid) $pdo->prepare("INSERT INTO task_assignees (task_id,user_id) VALUES (?,?)")->execute([$id, $uid]);
    flash_set('success', 'Task assignees updated.');
    redirect("task_view.php?id=$id");
  }

  if (isset($_POST['update_task'])) {
    if ($locked && !$can_manage) { flash_set('error', 'Task is locked.'); redirect("task_view.php?id=$id"); }
    $new_status = trim($_POST['status'] ?? $task['status']);
    if (!in_array($new_status, $statuses, true)) { flash_set('error', 'You cannot set that status.'); redirect("task_view.php?id=$id"); }
    $new_note = trim($_POST['internal_note'] ?? '');
    $now = now();
    if ($new_status === 'Submitted to Client') {
      $pdo->prepare("UPDATE tasks SET status=?, internal_note=?, submitted_at=?, submitted_by=?, updated_at=? WHERE id=? AND workspace_id=?")
          ->execute([$new_status, $new_note ?: null, $now, $u['id'], $now, $id, $ws]);
      if (file_exists(__DIR__ . '/chat/includes/notifications.php')) {
        require_once __DIR__ . '/chat/includes/notifications.php';
        try { chat_notify_submitted_to_client((int)$id, (int)$u['id']); } catch (Throwable $e) {}
      }
    } else {
      $pdo->prepare("UPDATE tasks SET status=?, internal_note=?, updated_at=? WHERE id=? AND workspace_id=?")
          ->execute([$new_status, $new_note ?: null, $now, $id, $ws]);
      // If the user is the assignee setting status to a review-pending state,
      // ping the managers so they know.
      if (in_array($new_status, ['Completed','Completed (Needs Manager Review)'], true)) {
        // Record the submission timestamp + author so reviews logic can find them.
        $pdo->prepare("UPDATE tasks SET submitted_at = COALESCE(submitted_at, ?), submitted_by = COALESCE(submitted_by, ?) WHERE id = ?")
            ->execute([$now, (int)$u['id'], $id]);
        if (file_exists(__DIR__ . '/chat/includes/notifications.php')) {
          require_once __DIR__ . '/chat/includes/notifications.php';
          try { chat_notify_submission_review_needed((int)$id, (int)$u['id']); } catch (Throwable $e) {}
        }
      }
    }
    flash_set('success', 'Task updated.');
    if ($can_manager && in_array($new_status, ['Completed','Completed (Needs Manager Review)'], true)) redirect('manager_review.php');
    if ($can_manager && in_array($new_status, ['Approved','Approved (Ready to Submit)'], true)) redirect('manager_submit.php');
    if ($can_manager && $new_status === 'Submitted to Client') redirect('completed_task_archive.php');
    redirect("task_view.php?id=$id");
  }

  if (isset($_POST['lock_task'])) {
    if (!$can_manage) { flash_set('error', 'No permission.'); redirect("task_view.php?id=$id"); }
    $pdo->prepare("UPDATE tasks SET locked_at=?, locked_by=? WHERE id=? AND workspace_id=?")->execute([now(), $u['id'], $id, $ws]);
    flash_set('success', 'Task locked.'); redirect("task_view.php?id=$id");
  }
  if (isset($_POST['unlock_task'])) {
    if (!$can_manage) { flash_set('error', 'No permission.'); redirect("task_view.php?id=$id"); }
    $pdo->prepare("UPDATE tasks SET locked_at=NULL, locked_by=NULL WHERE id=? AND workspace_id=?")->execute([$id, $ws]);
    flash_set('success', 'Task unlocked.'); redirect("task_view.php?id=$id");
  }
  if (isset($_POST['add_comment'])) {
    $body = trim($_POST['comment'] ?? '');
    $parent_id = isset($_POST['parent_comment_id']) && $_POST['parent_comment_id'] !== '' ? (int)$_POST['parent_comment_id'] : null;
    if ($body === '') { flash_set('error', 'Comment cannot be empty.'); redirect("task_view.php?id=$id"); }
    if ($has_comment_parent) {
      $pdo->prepare("INSERT INTO comments (workspace_id,task_id,author_user_id,body,parent_comment_id,created_at) VALUES (?,?,?,?,?,?)")
          ->execute([$ws, $id, $u['id'], $body, $parent_id, now()]);
    } else {
      $pdo->prepare("INSERT INTO comments (workspace_id,task_id,author_user_id,body,created_at) VALUES (?,?,?,?,?)")
          ->execute([$ws, $id, $u['id'], $body, now()]);
    }
    $newCommentId = (int)$pdo->lastInsertId();
    // Anton Connect — DM the assignees + creator about the new comment.
    if (file_exists(__DIR__ . '/chat/includes/notifications.php')) {
      require_once __DIR__ . '/chat/includes/notifications.php';
      try { chat_notify_task_comment((int)$id, $body, (int)$u['id']); } catch (Throwable $e) {}
    }
    // Mirror this comment as a chat thread reply on the anchor message (if any).
    if (file_exists(__DIR__ . '/chat/includes/comment_mirror.php')) {
      require_once __DIR__ . '/chat/includes/comment_mirror.php';
      try { chat_mirror_comment_to_thread((int)$id, $newCommentId, $body, (int)$u['id']); } catch (Throwable $e) {}
    }
    flash_set('success', 'Comment added.'); redirect("task_view.php?id=$id");
  }
  if (isset($_POST['manager_action'])) {
    if (!$can_manager) { flash_set('error', 'No permission.'); redirect("task_view.php?id=$id"); }
    $action = $_POST['manager_action'];
    if ($action === 'approve') {
      $pdo->prepare("UPDATE tasks SET status='Approved', updated_at=? WHERE id=? AND workspace_id=?")->execute([now(), $id, $ws]);
      if (file_exists(__DIR__ . '/chat/includes/notifications.php')) {
        require_once __DIR__ . '/chat/includes/notifications.php';
        try { chat_notify_submission_approved((int)$id, (int)$u['id']); } catch (Throwable $e) {}
      }
      flash_set('success', 'Task approved and moved to Submit to Client.');
      redirect('manager_submit.php');
    } elseif ($action === 'reject') {
      $reason = trim($_POST['manager_reason'] ?? 'Needs changes.');
      $pdo->prepare("UPDATE tasks SET status='In Progress', internal_note=?, updated_at=? WHERE id=? AND workspace_id=?")
          ->execute([$reason, now(), $id, $ws]);
      if (file_exists(__DIR__ . '/chat/includes/notifications.php')) {
        require_once __DIR__ . '/chat/includes/notifications.php';
        try { chat_notify_submission_rejected((int)$id, $reason, (int)$u['id']); } catch (Throwable $e) {}
      }
      flash_set('success', 'Task sent back to In Progress.');
    }
    redirect("task_view.php?id=$id");
  }
  if (isset($_POST['submit_to_client'])) {
    if (!$can_manager) { flash_set('error', 'No permission.'); redirect("task_view.php?id=$id"); }
    $now = now();
    $pdo->prepare("UPDATE tasks SET status='Submitted to Client', submitted_at=?, submitted_by=?, updated_at=? WHERE id=? AND workspace_id=?")
        ->execute([$now, $u['id'], $now, $id, $ws]);
    if (file_exists(__DIR__ . '/chat/includes/notifications.php')) {
      require_once __DIR__ . '/chat/includes/notifications.php';
      try { chat_notify_submitted_to_client((int)$id, (int)$u['id']); } catch (Throwable $e) {}
    }
    flash_set('success', 'Task marked as Submitted to Client and archived.');
    redirect('completed_task_archive.php');
  }
}

$comments = [];
try {
  if ($has_comment_parent) {
    $commentsStmt = $pdo->prepare("SELECT c.id,c.parent_comment_id,c.body,c.created_at,u.name AS author,u.id AS author_id,r.name AS author_role
      FROM comments c JOIN users u ON u.id=c.author_user_id LEFT JOIN roles r ON r.id=u.role_id
      WHERE c.task_id=? AND c.workspace_id=? ORDER BY c.id ASC");
  } else {
    $commentsStmt = $pdo->prepare("SELECT c.id,NULL AS parent_comment_id,c.body,c.created_at,u.name AS author,u.id AS author_id,r.name AS author_role
      FROM comments c JOIN users u ON u.id=c.author_user_id LEFT JOIN roles r ON r.id=u.role_id
      WHERE c.task_id=? AND c.workspace_id=? ORDER BY c.id ASC");
  }
  $commentsStmt->execute([$id, $ws]);
  $comments = $commentsStmt->fetchAll();
} catch (Throwable $e) { $comments = []; }

$attachments = [];
$attachments_ready = false;
try {
  $probe = $pdo->query("SELECT id FROM task_attachments LIMIT 1");
  $attachments_ready = ($probe instanceof PDOStatement);
  if ($attachments_ready) {
    $attStmt = $pdo->prepare("SELECT id,original_name,size_bytes,created_at FROM task_attachments WHERE workspace_id=? AND task_id=? ORDER BY id DESC LIMIT 20");
    $attStmt->execute([$ws, $id]);
    $attachments = $attStmt->fetchAll() ?: [];
  }
} catch (Throwable $e) { $attachments = []; $attachments_ready = false; }

[$priorityLabel, $priorityKey] = tv_priority($task);
$statusKey = tv_status_key((string)$task['status']);
$section_name = $task['phase_name'] ?? 'General';
$due_display = $task['due_date'] ? format_date($task['due_date']) : 'Not set';
$is_overdue = $task['due_date'] && strtotime($task['due_date']) < strtotime(date('Y-m-d')) && !in_array($task['status'], ['Approved (Ready to Submit)','Submitted to Client'], true);

$byParent = [];
foreach ($comments as $c) { $pid = (int)($c['parent_comment_id'] ?? 0); $byParent[$pid][] = $c; }

$pageTitle = $task['title'];
$activeKey = 'my-tasks';
$pageHeadExtra = <<<HTML
<style>
  .topbar { height: 64px; border-bottom: 1px solid var(--border); padding: 0 32px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; background: rgba(10,10,10,0.85); backdrop-filter: blur(12px); z-index: 10; }
  .breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-muted); }
  .breadcrumb a { color: var(--text-muted); transition: color 0.15s; }
  .breadcrumb a:hover { color: var(--text); }
  .breadcrumb .sep { color: var(--text-dim); }
  .breadcrumb .current { color: var(--text); font-weight: 500; }
  .breadcrumb-left { display: flex; align-items: center; gap: 14px; }
  .topbar-actions { display: flex; gap: 8px; align-items: center; }

  .content { display: grid; grid-template-columns: 1fr 340px; gap: 32px; padding: 32px 40px 60px; max-width: 1480px; width: 100%; margin: 0 auto; }
  .task-header { margin-bottom: 28px; }
  .task-meta-top { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
  .task-title-h { font-size: 28px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.2; color: var(--text); margin-bottom: 10px; }
  .task-subactions { display: flex; align-items: center; gap: 16px; color: var(--text-dim); font-size: 12.5px; }
  .task-subactions .icon-btn { display: inline-flex; align-items: center; gap: 6px; color: var(--text-muted); padding: 4px 0; transition: color 0.15s; background: none; border: none; cursor: pointer; }
  .task-subactions .icon-btn:hover { color: var(--text); }
  .task-subactions svg { width: 14px; height: 14px; }
  .meta-strip { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; margin-bottom: 28px; }
  .meta-cell { padding: 16px 20px; border-right: 1px solid var(--border); display: flex; flex-direction: column; gap: 4px; }
  .meta-cell:last-child { border-right: none; }
  .meta-label { font-size: 11px; font-weight: 500; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.08em; display: flex; align-items: center; gap: 6px; }
  .meta-label svg { width: 12px; height: 12px; }
  .meta-value { font-size: 13.5px; color: var(--text); font-weight: 500; }
  .meta-value.muted { color: var(--text-muted); font-weight: 400; }
  .section { margin-bottom: 32px; }
  .section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
  .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 18px; }
  .tab { padding: 8px 14px; font-size: 13px; font-weight: 500; color: var(--text-muted); border-bottom: 2px solid transparent; margin-bottom: -1px; transition: all 0.15s; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; background: none; border-left: none; border-right: none; border-top: none; }
  .tab:hover { color: var(--text); }
  .tab.active { color: var(--text); border-bottom-color: var(--accent); }
  .tab .count { font-size: 11px; background: var(--surface-2); color: var(--text-muted); padding: 1px 7px; border-radius: 999px; }
  .description { color: var(--text-muted); font-size: 14px; line-height: 1.65; padding: 4px 0; white-space: pre-wrap; }
  .description.empty { color: var(--text-dim); font-style: italic; display: flex; align-items: center; gap: 8px; padding: 8px 0; }
  .description.empty svg { width: 14px; height: 14px; }
  .docs-empty { border: 1px dashed var(--border-strong); border-radius: 10px; padding: 28px; text-align: center; color: var(--text-muted); font-size: 13px; display: flex; flex-direction: column; align-items: center; gap: 10px; transition: all 0.15s; }
  .docs-empty:hover { border-color: var(--accent); color: var(--text); background: rgba(250,204,21,0.03); }
  .docs-empty svg { width: 22px; height: 22px; color: var(--text-dim); }
  .file-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); margin-bottom: 8px; }
  .file-row .name { font-weight: 500; color: var(--text); font-size: 13px; }
  .file-row .meta { font-size: 11.5px; color: var(--text-dim); margin-top: 2px; }
  .composer { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; transition: border-color 0.15s; }
  .composer:focus-within { border-color: var(--border-strong); }
  .composer textarea { width: 100%; background: transparent; border: none; outline: none; resize: none; padding: 14px 16px; font-size: 14px; color: var(--text); min-height: 64px; font-family: inherit; }
  .composer textarea::placeholder { color: var(--text-dim); }
  .composer-foot { display: flex; justify-content: space-between; align-items: center; padding: 8px 10px 8px 12px; border-top: 1px solid var(--border); }
  .composer-tools { display: flex; gap: 2px; }
  .composer-tools button { width: 30px; height: 30px; border-radius: 6px; color: var(--text-dim); display: inline-flex; align-items: center; justify-content: center; transition: all 0.15s; background: none; border: none; cursor: pointer; }
  .composer-tools button:hover { background: var(--surface-2); color: var(--text); }
  .composer-tools svg { width: 15px; height: 15px; }
  .reply-hint { font-size: 11.5px; color: var(--accent); padding: 4px 12px; display: none; }
  .reply-hint.show { display: block; }
  .comment { display: flex; gap: 12px; padding: 14px 0; border-top: 1px solid var(--border); }
  .comment .avatar { width: 30px; height: 30px; font-size: 11px; flex-shrink: 0; }
  .comment-body { flex: 1; min-width: 0; }
  .comment-head { display: flex; align-items: baseline; gap: 8px; margin-bottom: 4px; flex-wrap: wrap; }
  .comment-name { font-size: 13.5px; font-weight: 600; color: var(--text); }
  .comment-time { font-size: 11.5px; color: var(--text-dim); }
  .comment-text { font-size: 13.5px; color: var(--text-muted); line-height: 1.55; white-space: pre-wrap; }
  .comment-reply-btn { margin-top: 6px; font-size: 11.5px; color: var(--text-dim); padding: 3px 8px; border-radius: 6px; background: var(--surface-2); border: 1px solid var(--border); cursor: pointer; transition: all 0.12s; }
  .comment-reply-btn:hover { color: var(--text); border-color: var(--border-strong); }

  .rail { display: flex; flex-direction: column; gap: 20px; }
  .field { margin-bottom: 12px; }
  .field:last-child { margin-bottom: 0; }
  .field-label { display: block; font-size: 11.5px; color: var(--text-muted); margin-bottom: 6px; font-weight: 500; }

  .assignee-row { display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; border-radius: 8px; cursor: pointer; transition: background 0.12s; }
  .assignee-row:hover { background: var(--surface-2); }
  .assignee-row.selected { background: var(--surface-2); }
  .assignee-info { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .assignee-info .avatar { width: 26px; height: 26px; font-size: 10px; }
  .assignee-name { font-size: 13px; color: var(--text); font-weight: 500; }
  .assignee-role { font-size: 11px; color: var(--text-dim); }
  .check { width: 16px; height: 16px; border-radius: 4px; border: 1.5px solid var(--border-strong); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.15s; }
  .assignee-row.selected .check { background: var(--accent); border-color: var(--accent); }
  .check svg { width: 11px; height: 11px; color: #1a1400; opacity: 0; transition: opacity 0.15s; }
  .assignee-row.selected .check svg { opacity: 1; }

  .status-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; }
  .status-opt { padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface-2); font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; transition: all 0.15s; cursor: pointer; }
  .status-opt:hover { background: var(--surface-hover); color: var(--text); }
  .status-opt.active { background: var(--accent-soft); border-color: rgba(250,204,21,0.35); color: var(--accent); }
  .status-opt .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--text-dim); }
  .status-opt.active .dot { background: var(--accent); box-shadow: 0 0 0 3px rgba(250,204,21,0.18); }

  .segmented-actions { display: flex; gap: 6px; margin-bottom: 10px; }
  .seg-btn { flex: 1; padding: 8px; border-radius: 8px; font-size: 12px; font-weight: 500; border: 1px solid var(--border); background: var(--surface-2); color: var(--text-muted); transition: all 0.15s; display: inline-flex; align-items: center; justify-content: center; gap: 6px; cursor: pointer; }
  .seg-btn svg { width: 12px; height: 12px; }
  .seg-btn:hover { color: var(--text); border-color: var(--border-strong); }
  .seg-btn.approve.active { background: var(--success-soft); border-color: rgba(34,197,94,0.3); color: #86efac; }
  .seg-btn.send-back.active { background: var(--danger-soft); border-color: rgba(239,68,68,0.3); color: #fca5a5; }

  .submit-state { display: flex; align-items: center; justify-content: space-between; background: var(--success-soft); border: 1px solid rgba(34,197,94,0.25); border-radius: 10px; padding: 12px 14px; margin-bottom: 10px; }
  .submit-state .label { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #86efac; font-weight: 500; }
  .submit-state .label svg { width: 14px; height: 14px; }
  .submit-state .when { font-size: 11px; color: var(--text-dim); }

  @media (max-width: 1100px) {
    .content { grid-template-columns: 1fr; }
    .meta-strip { grid-template-columns: repeat(2, 1fr); }
    .meta-cell:nth-child(2) { border-right: none; }
    .meta-cell:nth-child(1), .meta-cell:nth-child(2) { border-bottom: 1px solid var(--border); }
  }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="topbar">
  <div class="breadcrumb-left">
    <?= back_button_html() ?>
    <div class="breadcrumb">
      <a href="projects.php">Projects</a>
      <span class="sep">/</span>
      <a href="project_view.php?id=<?= (int)$task['project_id'] ?>"><?= h($task['project_name']) ?></a>
      <span class="sep">/</span>
      <span class="current"><?= h($task['title']) ?></span>
    </div>
  </div>
  <div class="topbar-actions">
    <?php if ($can_manage && !$locked): ?>
      <form method="post" style="display:inline;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <button class="btn btn-ghost" name="lock_task" value="1">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0110 0v4"></path></svg>
          Lock
        </button>
      </form>
    <?php endif; ?>
    <?php if ($can_manage && $locked): ?>
      <form method="post" style="display:inline;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <button class="btn btn-ghost" name="unlock_task" value="1">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 1110 0"></path></svg>
          Unlock
        </button>
      </form>
    <?php endif; ?>
    <button class="btn btn-primary save-flash" type="button" onclick="document.getElementById('updateStatusForm').submit();">Save changes</button>
  </div>
</div>

<div class="content">
  <div>
    <div class="task-header">
      <div class="task-meta-top">
        <span class="pill">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="9" x2="20" y2="9"></line><line x1="4" y1="15" x2="20" y2="15"></line><line x1="10" y1="3" x2="8" y2="21"></line><line x1="16" y1="3" x2="14" y2="21"></line></svg>
          <?= h($section_name) ?>
        </span>
        <span class="pill priority"><span class="dot"></span><?= h($priorityLabel) ?> priority</span>
        <span class="pill status"><span class="dot"></span><?= h($task['status']) ?></span>
        <span class="pill">TASK-<?= str_pad((string)(int)$task['id'], 4, '0', STR_PAD_LEFT) ?></span>
        <?php if ($locked): ?><span class="pill" style="color:#fca5a5;border-color:rgba(239,68,68,0.25);background:var(--danger-soft);"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0110 0v4"></path></svg>Locked</span><?php endif; ?>
      </div>
      <h1 class="task-title-h"><?= h($task['title']) ?></h1>
    </div>

    <div class="meta-strip">
      <div class="meta-cell">
        <div class="meta-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>Client</div>
        <div class="meta-value"><a href="client_view.php?id=<?= (int)$task['client_id'] ?>" style="color:inherit;"><?= h($task['client_name']) ?></a></div>
      </div>
      <div class="meta-cell">
        <div class="meta-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>Due date</div>
        <div class="meta-value">
          <?= h($due_display) ?>
          <?php if ($is_overdue): $diffDays = (int)floor((strtotime(date('Y-m-d')) - strtotime((string)$task['due_date'])) / 86400); ?>
            <span style="color:var(--danger);font-size:11px;font-weight:500;margin-left:4px;">· <?= $diffDays ?>d overdue</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="meta-cell">
        <div class="meta-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>Created</div>
        <div class="meta-value muted"><?= h($task['created_at'] ? date('M j, Y · H:i', strtotime($task['created_at'])) : '-') ?></div>
      </div>
      <div class="meta-cell">
        <div class="meta-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"></path></svg>Updated</div>
        <div class="meta-value muted"><?= h($task['updated_at'] ? date('M j, Y · H:i', strtotime($task['updated_at'])) : '-') ?></div>
      </div>
    </div>

    <div class="tabs">
      <button type="button" class="tab active" data-tab="details">Details</button>
      <button type="button" class="tab" data-tab="docs">Docs <span class="count"><?= count($attachments) ?></span></button>
      <button type="button" class="tab" data-tab="activity">Activity <span class="count"><?= count($comments) ?></span></button>
    </div>

    <div data-panel="details">
      <div class="section">
        <div class="section-head">
          <div class="section-title">Description</div>
        </div>
        <?php if (!empty($task['description'])): ?>
          <div class="description"><?= nl2br(h($task['description'])) ?></div>
        <?php else: ?>
          <div class="description empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="17" y1="10" x2="3" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="17" y1="18" x2="3" y2="18"></line></svg>
            No description provided.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div data-panel="docs" style="display:none;">
      <div class="section">
        <div class="section-head">
          <div class="section-title">Attachments <span class="count"><?= count($attachments) ?></span></div>
          <a class="section-action" href="upload_task_attachment.php?task_id=<?= (int)$id ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>Upload</a>
        </div>
        <?php if (!$attachments_ready): ?>
          <div class="docs-empty">Attachment module not available in this workspace.</div>
        <?php elseif (!$attachments): ?>
          <a href="upload_task_attachment.php?task_id=<?= (int)$id ?>" class="docs-empty" style="text-decoration:none;cursor:pointer;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
            <div>Drop files here, or <span style="color:var(--accent);font-weight:500;">click to browse</span></div>
            <div style="font-size:11.5px;color:var(--text-dim);">PDF, DOC, PNG, JPG up to 25MB</div>
          </a>
        <?php else: foreach ($attachments as $att): ?>
          <div class="file-row">
            <div>
              <div class="name"><?= h($att['original_name']) ?></div>
              <div class="meta"><?= h($att['created_at']) ?></div>
            </div>
            <div style="font-size:11.5px;color:var(--text-dim);font-family:'JetBrains Mono',ui-monospace,monospace;">
              <?= isset($att['size_bytes']) ? h(number_format((int)$att['size_bytes'] / 1024, 1) . ' KB') : '-' ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div data-panel="activity" style="display:none;">
      <div class="section">
        <div class="section-head">
          <div class="section-title">Discussion <span class="count"><?= count($comments) ?></span></div>
        </div>
        <form method="post" class="composer">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="parent_comment_id" id="parent_comment_id" value="">
          <textarea name="comment" placeholder="Write a comment…  Use @ to mention someone." required></textarea>
          <div class="reply-hint" id="reply_hint"></div>
          <div class="composer-foot">
            <div class="composer-tools">
              <button type="button" title="Mention"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"></circle><path d="M16 8v5a3 3 0 006 0v-1a10 10 0 10-3.92 7.94"></path></svg></button>
              <button type="button" title="Attach"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"></path></svg></button>
              <button type="button" title="Emoji"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M8 14s1.5 2 4 2 4-2 4-2"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="15" y1="9" x2="15.01" y2="9"></line></svg></button>
            </div>
            <button class="btn btn-primary save-flash" name="add_comment" value="1" style="padding:6px 14px;font-size:12.5px;">Comment</button>
          </div>
        </form>
        <?php
        function tv_render_tree(int $parentId, array $byParent, int $level, array &$visited) {
          if ($level > 30 || !isset($byParent[$parentId])) return;
          foreach ($byParent[$parentId] as $c) {
            $cid = (int)$c['id'];
            if (isset($visited[$cid])) continue;
            $visited[$cid] = true;
            $author = (string)$c['author'];
            $grad = tv_avatar_gradient($author);
            $authorRole = (string)($c['author_role'] ?? '');
            $pad = min(60, $level * 20);
            echo '<div class="comment" style="margin-left:' . $pad . 'px;">';
            echo '<div class="avatar" style="background:' . h($grad) . '">' . h(user_initials($author)) . '</div>';
            echo '<div class="comment-body">';
            echo '<div class="comment-head"><span class="comment-name">' . h($author) . user_status_chip((int)($c['author_id'] ?? 0)) . '</span><span class="comment-time">' . ($authorRole !== '' ? h($authorRole) . ' · ' : '') . h($c['created_at']) . '</span></div>';
            echo '<div class="comment-text">' . nl2br(h($c['body'])) . '</div>';
            echo '<button type="button" class="comment-reply-btn" data-author="' . h($author) . '" onclick="tvSetReply(' . $cid . ', this.dataset.author)">Reply</button>';
            echo '</div></div>';
            tv_render_tree($cid, $byParent, $level + 1, $visited);
          }
        }
        $visited = [];
        tv_render_tree(0, $byParent, 0, $visited);
        ?>
        <?php if (!$comments): ?>
          <div style="padding: 14px 0; color: var(--text-dim); font-size: 13px; border-top: 1px solid var(--border);">No comments yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <aside class="rail">
    <div class="rail-card">
      <div class="rail-card-head"><span class="rail-card-title">Status</span></div>
      <div class="rail-card-body">
        <form method="post" id="updateStatusForm">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="status" id="statusInp" value="<?= h((string)$task['status']) ?>">
          <input type="hidden" name="update_task" value="1">
          <div class="status-grid" id="statusGrid">
            <?php foreach ($statuses as $s): $isActive = $s === $task['status']; ?>
              <button type="button" class="status-opt <?= $isActive ? 'active' : '' ?>" data-status="<?= h($s) ?>"><span class="dot"></span><?= h($s) ?></button>
            <?php endforeach; ?>
          </div>
          <div class="field" style="margin-top:14px;">
            <label class="field-label">Internal note</label>
            <textarea class="input" name="internal_note" placeholder="Optional note for the team…"><?= h($task['internal_note'] ?? '') ?></textarea>
          </div>
          <button class="btn btn-primary btn-block save-flash" style="margin-top:10px;">Save Status</button>
        </form>
      </div>
    </div>

    <?php if ($can_manage): ?>
    <div class="rail-card">
      <div class="rail-card-head"><span class="rail-card-title">Assignees</span></div>
      <div class="rail-card-body">
        <?php if (!$assignable_users): ?>
          <p class="helper-text">No active Developer/SEO users.</p>
        <?php else: ?>
          <form method="post" id="assignForm">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="assign_task" value="1">
            <div id="assigneeList">
              <?php foreach ($assignable_users as $au):
                $isSel = in_array((int)$au['id'], $assignee_ids, true);
                $grad = tv_avatar_gradient((string)$au['name']);
              ?>
                <label class="assignee-row <?= $isSel ? 'selected' : '' ?>">
                  <input type="checkbox" name="assignees[]" value="<?= (int)$au['id'] ?>" style="display:none;" <?= $isSel ? 'checked' : '' ?>>
                  <div class="assignee-info">
                    <div class="avatar" style="background:<?= h($grad) ?>"><?= h(user_initials((string)$au['name'])) ?></div>
                    <div>
                      <div class="assignee-name"><?= h($au['name']) ?><?= user_status_chip((int)$au['id']) ?></div>
                      <div class="assignee-role"><?= h($au['role_name']) ?></div>
                    </div>
                  </div>
                  <span class="check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></span>
                </label>
              <?php endforeach; ?>
            </div>
            <button class="btn btn-primary btn-block save-flash" style="margin-top:10px;">Save Assignees</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($can_manager): ?>
    <div class="rail-card">
      <div class="rail-card-head"><span class="rail-card-title">Manager review</span></div>
      <div class="rail-card-body">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <div class="segmented-actions">
            <button class="seg-btn approve" type="submit" name="manager_action" value="approve">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
              Approve
            </button>
            <button class="seg-btn send-back" type="submit" name="manager_action" value="reject">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"></polyline><path d="M20 20v-7a4 4 0 00-4-4H4"></path></svg>
              Send back
            </button>
          </div>
          <textarea class="input" name="manager_reason" placeholder="Reason for sending back (optional)…"></textarea>
          <p class="helper-text">Approving will mark the task ready for client submission.</p>
        </form>
      </div>
    </div>

    <div class="rail-card">
      <div class="rail-card-head"><span class="rail-card-title">Client delivery</span></div>
      <div class="rail-card-body">
        <?php if (!empty($task['submitted_at'])): ?>
          <div class="submit-state">
            <span class="label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>Submitted</span>
            <span class="when"><?= h(date('M j · H:i', strtotime($task['submitted_at']))) ?></span>
          </div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="submit_to_client" value="1">
          <button class="btn btn-secondary btn-block btn-lg" type="submit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
            <?= !empty($task['submitted_at']) ? 'Resubmit' : 'Submit to Client' ?>
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </aside>
</div>

<script>
  document.querySelectorAll('.tab').forEach(t => {
    t.addEventListener('click', () => {
      const target = t.dataset.tab;
      document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
      t.classList.add('active');
      document.querySelectorAll('[data-panel]').forEach(p => p.style.display = (p.dataset.panel === target ? '' : 'none'));
    });
  });

  document.querySelectorAll('#statusGrid .status-opt').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('#statusGrid .status-opt').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById('statusInp').value = btn.dataset.status;
    });
  });

  document.querySelectorAll('#assigneeList .assignee-row').forEach(row => {
    const cb = row.querySelector('input[type=checkbox]');
    row.addEventListener('click', (e) => {
      e.preventDefault();
      cb.checked = !cb.checked;
      row.classList.toggle('selected', cb.checked);
    });
  });

  window.tvSetReply = function(commentId, author) {
    document.getElementById('parent_comment_id').value = commentId || '';
    const hint = document.getElementById('reply_hint');
    if (commentId) { hint.textContent = 'Replying to ' + author; hint.classList.add('show'); }
    else { hint.textContent = ''; hint.classList.remove('show'); }
    document.querySelector('.composer textarea').focus();
  };
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>
