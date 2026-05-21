<?php
// chat/includes/notifications.php — Anton Connect ↔ AntonX event helpers.
//
// Each function is a small, idempotent shim that an AntonX page calls after
// it commits a relevant mutation. They:
//   1. resolve / lazy-create the recipient's DM with Anton Connect
//   2. post a friendly card-style HTML message there
//   3. swallow every Throwable so a chat outage never breaks AntonX
//
// Functions:
//   chat_notify_task_assigned($taskId, $userId)
//   chat_notify_task_comment($taskId, $commentBody, $commentAuthorId)
//   chat_notify_task_due_soon($taskId, $userId)   (used by the SSE worker)
//   chat_notify_submission_review_needed($taskId, $submittedById)
//   chat_notify_submission_approved($taskId)
//   chat_notify_submission_rejected($taskId, $reason)
//   chat_notify_submitted_to_client($taskId, $submittedById)

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/project_sync.php';

/**
 * Build a small "task card" HTML used as the body of every task-related DM.
 * Renders title + project + status + due date with a link to the task page.
 */
function _ac_task_card(array $task): string {
  $due = !empty($task['due_date']) ? date('M j', strtotime((string)$task['due_date'])) : '';
  $status = (string)($task['status'] ?? '');
  $statusCls = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $status) ?? 'todo');
  return '<a class="entity-card entity-card-task" href="/task_details.php?id=' . (int)$task['id'] . '">'
    .   '<span class="entity-card-kind">TASK</span>'
    .   '<span class="entity-card-title">' . h((string)$task['title']) . '</span>'
    .   '<span class="entity-card-meta">'
    .     ($status !== '' ? '<span class="entity-card-status entity-card-status-' . h($statusCls) . '">' . h($status) . '</span>' : '')
    .     (!empty($task['project_name']) ? '<span class="entity-card-proj">' . h((string)$task['project_name']) . '</span>' : '')
    .     ($due ? '<span class="entity-card-due">Due ' . h($due) . '</span>' : '')
    .   '</span>'
    . '</a>';
}

/**
 * Fetch a task with its project name. Returns null if it doesn't exist or is
 * outside the given workspace (defensive — never DM about a foreign task).
 */
function _ac_load_task(int $taskId, ?int $workspaceId = null): ?array {
  try {
    $sql = 'SELECT t.id, t.workspace_id, t.title, t.status, t.due_date, t.project_id, p.name AS project_name
            FROM tasks t LEFT JOIN projects p ON p.id = t.project_id
            WHERE t.id = ?';
    $params = [$taskId];
    if ($workspaceId !== null) { $sql .= ' AND t.workspace_id = ?'; $params[] = $workspaceId; }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $r = $stmt->fetch();
    return $r ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

/**
 * "@you were assigned a task" DM.
 */
function chat_notify_task_assigned(int $taskId, int $assigneeUserId, ?int $assignerUserId = null): void {
  try {
    $task = _ac_load_task($taskId);
    if (!$task || $assigneeUserId <= 0) return;
    $ws = (int)$task['workspace_id'];
    $assignerName = '';
    if ($assignerUserId) {
      $st = db()->prepare('SELECT name FROM users WHERE id = ?');
      $st->execute([$assignerUserId]);
      $assignerName = (string)($st->fetchColumn() ?: '');
    }
    $intro = $assignerName !== ''
      ? '<p><strong>' . h($assignerName) . '</strong> assigned you a task:</p>'
      : '<p>You\'ve been assigned a new task:</p>';
    chat_dm_user_as_system($ws, $assigneeUserId, $intro . _ac_task_card($task));
  } catch (Throwable $e) { /* swallow */ }
}

/**
 * Someone commented on a task → DM each assignee (except the commenter).
 */
function chat_notify_task_comment(int $taskId, string $commentBody, int $commentAuthorId): void {
  try {
    $task = _ac_load_task($taskId);
    if (!$task) return;
    $ws = (int)$task['workspace_id'];

    $st = db()->prepare('SELECT user_id FROM task_assignees WHERE task_id = ?');
    $st->execute([$taskId]);
    $assignees = array_map('intval', array_column($st->fetchAll(), 'user_id'));
    if ($task['workspace_id'] && !empty($task['project_id'])) {
      // Loop through assignees AND the task creator.
      $creator = db()->prepare('SELECT created_by FROM tasks WHERE id = ?');
      $creator->execute([$taskId]);
      $cid = (int)$creator->fetchColumn();
      if ($cid > 0 && !in_array($cid, $assignees, true)) $assignees[] = $cid;
    }

    $authorName = '';
    if ($commentAuthorId) {
      $au = db()->prepare('SELECT name FROM users WHERE id = ?');
      $au->execute([$commentAuthorId]);
      $authorName = (string)($au->fetchColumn() ?: '');
    }

    $snippet = trim(strip_tags($commentBody));
    if (mb_strlen($snippet, 'UTF-8') > 180) $snippet = mb_substr($snippet, 0, 180, 'UTF-8') . '…';
    $intro = '<p><strong>' . h($authorName !== '' ? $authorName : 'Someone') . '</strong> commented on '
           . '<strong>' . h((string)$task['title']) . '</strong>:</p>'
           . '<blockquote><p>' . h($snippet) . '</p></blockquote>';

    foreach ($assignees as $u) {
      if ($u === $commentAuthorId) continue;
      chat_dm_user_as_system($ws, (int)$u, $intro . _ac_task_card($task));
    }
  } catch (Throwable $e) { /* swallow */ }
}

/**
 * Task is due in <24h → DM each assignee. Called by the SSE worker tick.
 */
function chat_notify_task_due_soon(int $taskId, int $assigneeUserId): void {
  try {
    $task = _ac_load_task($taskId);
    if (!$task) return;
    chat_dm_user_as_system((int)$task['workspace_id'], $assigneeUserId,
      '<p>⏰ Your task is due soon:</p>' . _ac_task_card($task));
  } catch (Throwable $e) {}
}

/**
 * Manager: a task is waiting for your review.
 */
function chat_notify_submission_review_needed(int $taskId, int $submittedById): void {
  try {
    $task = _ac_load_task($taskId);
    if (!$task) return;
    $ws = (int)$task['workspace_id'];

    // Authors of the submission for personalization.
    $submitterName = '';
    if ($submittedById) {
      $st = db()->prepare('SELECT name FROM users WHERE id = ?');
      $st->execute([$submittedById]);
      $submitterName = (string)($st->fetchColumn() ?: '');
    }
    $intro = '<p>📋 <strong>' . h($submitterName !== '' ? $submitterName : 'A teammate') . '</strong> '
           . 'submitted a task for review.</p>';
    $card = _ac_task_card($task);

    // DM every manager in the workspace.
    $mgr = db()->prepare(
      "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
       WHERE u.workspace_id = ? AND r.name IN ('Manager','Super Admin','CEO') AND u.is_active = 1 AND u.id != ?"
    );
    $mgr->execute([$ws, $submittedById]);
    foreach ($mgr->fetchAll() as $row) {
      chat_dm_user_as_system($ws, (int)$row['id'], $intro . $card);
    }
  } catch (Throwable $e) {}
}

/**
 * Submission was approved → DM the submitter.
 */
function chat_notify_submission_approved(int $taskId, ?int $reviewerId = null): void {
  try {
    $task = _ac_load_task($taskId);
    if (!$task) return;
    $ws = (int)$task['workspace_id'];
    $submittedBy = (int)(db()->query('SELECT submitted_by FROM tasks WHERE id = ' . (int)$taskId)->fetchColumn() ?: 0);
    if ($submittedBy <= 0) return;
    $reviewerName = '';
    if ($reviewerId) {
      $st = db()->prepare('SELECT name FROM users WHERE id = ?');
      $st->execute([$reviewerId]);
      $reviewerName = (string)($st->fetchColumn() ?: '');
    }
    chat_dm_user_as_system($ws, $submittedBy,
      '<p>✅ Your submission was <strong>approved</strong>'
      . ($reviewerName !== '' ? ' by <strong>' . h($reviewerName) . '</strong>' : '') . '.</p>'
      . _ac_task_card($task));
  } catch (Throwable $e) {}
}

/**
 * Submission was rejected → DM the submitter with the manager_feedback.
 */
function chat_notify_submission_rejected(int $taskId, string $reason = '', ?int $reviewerId = null): void {
  try {
    $task = _ac_load_task($taskId);
    if (!$task) return;
    $ws = (int)$task['workspace_id'];
    $submittedBy = (int)(db()->query('SELECT submitted_by FROM tasks WHERE id = ' . (int)$taskId)->fetchColumn() ?: 0);
    if ($submittedBy <= 0) return;
    $reviewerName = '';
    if ($reviewerId) {
      $st = db()->prepare('SELECT name FROM users WHERE id = ?');
      $st->execute([$reviewerId]);
      $reviewerName = (string)($st->fetchColumn() ?: '');
    }
    $reasonHtml = trim($reason) !== ''
      ? '<blockquote><p>' . nl2br(h($reason)) . '</p></blockquote>'
      : '';
    chat_dm_user_as_system($ws, $submittedBy,
      '<p>⚠️ Your submission was sent back'
      . ($reviewerName !== '' ? ' by <strong>' . h($reviewerName) . '</strong>' : '') . '.</p>'
      . $reasonHtml
      . _ac_task_card($task));
  } catch (Throwable $e) {}
}

/**
 * Task was submitted to client → post to #submissions channel + DM the
 * manager who clicked submit.
 */
function chat_notify_submitted_to_client(int $taskId, int $submittedById): void {
  try {
    $task = _ac_load_task($taskId);
    if (!$task) return;
    $ws = (int)$task['workspace_id'];

    // Ensure / get the submissions channel.
    $channelId = chat_ensure_submissions_channel($ws);

    $submitterName = '';
    if ($submittedById) {
      $st = db()->prepare('SELECT name FROM users WHERE id = ?');
      $st->execute([$submittedById]);
      $submitterName = (string)($st->fetchColumn() ?: '');
    }

    if ($channelId > 0) {
      chat_post_as_system(
        $ws, $channelId, null,
        '<p>🚀 <strong>' . h($submitterName !== '' ? $submitterName : 'A teammate') . '</strong> '
        . 'submitted a task to the client.</p>' . _ac_task_card($task)
      );
    }
    if ($submittedById > 0) {
      chat_dm_user_as_system($ws, $submittedById,
        '<p>✅ Your submission was sent to the client.</p>' . _ac_task_card($task));
    }
  } catch (Throwable $e) {}
}

/**
 * Auto-create / find the workspace's #submissions channel. Idempotent.
 * Returns 0 if the system user couldn't be resolved.
 */
function chat_ensure_submissions_channel(int $workspaceId): int {
  try {
    $cfg = chat_load_automation_config($workspaceId);
    $existing = (int)($cfg['submissions_channel_id'] ?? 0);
    if ($existing > 0) {
      // Confirm the channel still exists.
      $st = db()->prepare('SELECT id FROM chat_channels WHERE id = ? AND workspace_id = ?');
      $st->execute([$existing, $workspaceId]);
      if ((int)$st->fetchColumn() === $existing) return $existing;
    }

    // Look up an existing channel with slug 'submissions'.
    $st = db()->prepare('SELECT id FROM chat_channels WHERE workspace_id = ? AND slug = "submissions" LIMIT 1');
    $st->execute([$workspaceId]);
    $found = (int)$st->fetchColumn();

    if ($found === 0) {
      $system = chat_get_system_user($workspaceId);
      if (!$system) return 0;
      $now = now();
      $ins = db()->prepare(
        'INSERT INTO chat_channels (workspace_id, slug, is_private, topic, description, created_by, created_at)
         VALUES (?, "submissions", 0, "Client submissions", "Auto-posted when tasks are submitted to a client.", ?, ?)'
      );
      $ins->execute([$workspaceId, (int)$system['id'], $now]);
      $found = (int)db()->lastInsertId();
      // Add Anton Connect as a member so its posts are valid.
      db()->prepare('INSERT IGNORE INTO chat_channel_members (channel_id, user_id, joined_at) VALUES (?, ?, ?)')
        ->execute([$found, (int)$system['id'], $now]);
      // Add every manager too so they see the channel in their sidebar.
      $mgr = db()->prepare(
        "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.workspace_id = ? AND r.name IN ('Manager','Super Admin','CEO') AND u.is_active = 1"
      );
      $mgr->execute([$workspaceId]);
      $addMember = db()->prepare('INSERT IGNORE INTO chat_channel_members (channel_id, user_id, joined_at) VALUES (?, ?, ?)');
      foreach ($mgr->fetchAll() as $row) {
        $addMember->execute([$found, (int)$row['id'], $now]);
      }
      chat_emit_event($workspaceId, 'channel_created', $found, null, null, (int)$system['id']);
    }

    // Persist on the config row.
    db()->prepare('UPDATE chat_automations SET submissions_channel_id = ?, updated_at = ? WHERE workspace_id = ?')
      ->execute([$found, now(), $workspaceId]);
    return $found;
  } catch (Throwable $e) {
    return 0;
  }
}
