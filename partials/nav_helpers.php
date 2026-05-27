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
   * Open tasks assigned to this user — matches the My Tasks listing
   * (tasks assigned to the user whose status is not one of the
   * completed/approved/submitted "hidden" states).
   */
  function nav_antonx_pending_total(int $userId, int $workspaceId): int {
    if ($userId <= 0 || $workspaceId <= 0) return 0;
    try {
      $pdo = db();
      $hidden = ['Completed','Completed (Needs Manager Review)','Approved','Approved (Ready to Submit)','Submitted to Client'];
      $marks  = implode(',', array_fill(0, count($hidden), '?'));
      $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT t.id) FROM tasks t
         JOIN task_assignees ta ON ta.task_id = t.id
         WHERE ta.user_id = ?
           AND t.workspace_id = ?
           AND t.status NOT IN ($marks)"
      );
      $stmt->execute(array_merge([$userId, $workspaceId], $hidden));
      return (int)$stmt->fetchColumn();
    } catch (Throwable $e) { return 0; }
  }
}

if (!function_exists('nav_reviews_pending_total')) {
  /**
   * Tasks awaiting manager review — matches the Manager Review queue
   * (status Completed / Completed (Needs Manager Review)). Manager roles
   * only; everyone else gets 0 so the badge stays hidden.
   */
  function nav_reviews_pending_total(int $workspaceId, string $roleName): int {
    if ($workspaceId <= 0) return 0;
    if (!in_array($roleName, ['Manager','Super Admin','CEO'], true)) return 0;
    try {
      $stmt = db()->prepare(
        "SELECT COUNT(*) FROM tasks
         WHERE workspace_id = ?
           AND status IN ('Completed','Completed (Needs Manager Review)')"
      );
      $stmt->execute([$workspaceId]);
      return (int)$stmt->fetchColumn();
    } catch (Throwable $e) { return 0; }
  }
}

if (!function_exists('nav_submit_pending_total')) {
  /**
   * Approved tasks ready to submit to the client — matches the Submit to
   * Client queue (status Approved / Approved (Ready to Submit)). Manager
   * roles only.
   */
  function nav_submit_pending_total(int $workspaceId, string $roleName): int {
    if ($workspaceId <= 0) return 0;
    if (!in_array($roleName, ['Manager','Super Admin','CEO'], true)) return 0;
    try {
      $stmt = db()->prepare(
        "SELECT COUNT(*) FROM tasks
         WHERE workspace_id = ?
           AND status IN ('Approved','Approved (Ready to Submit)')"
      );
      $stmt->execute([$workspaceId]);
      return (int)$stmt->fetchColumn();
    } catch (Throwable $e) { return 0; }
  }
}
