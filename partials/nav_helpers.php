<?php
// partials/nav_helpers.php — small helpers shared between partials/header.php
// (top-right bell) and partials/nav.php (sidebar Chat badge).
//
// Each helper is wrapped in try/catch so a half-migrated DB doesn't fatal the
// nav. Idempotent: defining twice is harmless thanks to function_exists.

if (!function_exists('nav_chat_unread_total')) {
  /**
   * Total unread chat messages across every channel + DM the user belongs to.
   */
  function nav_chat_unread_total(int $userId, int $workspaceId): int {
    if ($userId <= 0 || $workspaceId <= 0) return 0;
    try {
      $pdo = db();
      $chStmt = $pdo->prepare(
        "SELECT IFNULL(SUM(
           (SELECT COUNT(*) FROM chat_messages m
            WHERE m.channel_id = cm.channel_id
              AND m.parent_message_id IS NULL
              AND m.deleted_at IS NULL
              AND m.user_id != ?
              AND m.id > IFNULL(cm.last_read_message_id, 0))
         ), 0) AS n
         FROM chat_channel_members cm
         JOIN chat_channels c ON c.id = cm.channel_id
         WHERE cm.user_id = ? AND c.workspace_id = ? AND c.archived_at IS NULL"
      );
      $chStmt->execute([$userId, $userId, $workspaceId]);
      $ch = (int)$chStmt->fetchColumn();

      $dmStmt = $pdo->prepare(
        "SELECT IFNULL(SUM(
           (SELECT COUNT(*) FROM chat_messages m
            WHERE m.conversation_id = dm.conversation_id
              AND m.parent_message_id IS NULL
              AND m.deleted_at IS NULL
              AND m.user_id != ?
              AND m.id > IFNULL(dm.last_read_message_id, 0))
         ), 0) AS n
         FROM chat_direct_conversation_members dm
         JOIN chat_direct_conversations c ON c.id = dm.conversation_id
         WHERE dm.user_id = ? AND c.workspace_id = ?"
      );
      $dmStmt->execute([$userId, $userId, $workspaceId]);
      $dm = (int)$dmStmt->fetchColumn();
      return $ch + $dm;
    } catch (Throwable $e) { return 0; }
  }
}

if (!function_exists('nav_antonx_pending_total')) {
  /**
   * Open tasks assigned to this user across their workspace.
   */
  function nav_antonx_pending_total(int $userId, int $workspaceId): int {
    if ($userId <= 0 || $workspaceId <= 0) return 0;
    try {
      $pdo = db();
      $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM tasks t
         JOIN task_assignees ta ON ta.task_id = t.id
         WHERE ta.user_id = ?
           AND t.workspace_id = ?
           AND LOWER(t.status) NOT IN ('done','completed','closed','approved','submitted')"
      );
      $stmt->execute([$userId, $workspaceId]);
      return (int)$stmt->fetchColumn();
    } catch (Throwable $e) { return 0; }
  }
}

if (!function_exists('nav_reviews_pending_total')) {
  /**
   * Tasks awaiting manager review (only relevant when the user is a manager).
   * Counts tasks with submitted_at set but no manager_feedback yet.
   */
  function nav_reviews_pending_total(int $workspaceId, string $roleName): int {
    if ($workspaceId <= 0) return 0;
    if (!in_array($roleName, ['Manager','Super Admin','CEO'], true)) return 0;
    try {
      $stmt = db()->prepare(
        "SELECT COUNT(*) FROM tasks
         WHERE workspace_id = ?
           AND submitted_at IS NOT NULL
           AND (manager_feedback IS NULL OR manager_feedback = '')"
      );
      $stmt->execute([$workspaceId]);
      return (int)$stmt->fetchColumn();
    } catch (Throwable $e) { return 0; }
  }
}
