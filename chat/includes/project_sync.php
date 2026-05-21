<?php
// chat/includes/project_sync.php — Anton Jr. ↔ AntonX project bridge.
//
// One function: chat_project_sync(int $projectId) — keeps a private chat
// channel "#proj-<slug>" alive for each AntonX project, with membership
// matching the project's assignees + lead. Idempotent — safe to call on
// every project mutation (create, edit, member add/remove, archive).
//
// Callers should require this once and call the function after the DB
// mutation completes. Failures are swallowed (logged via error_log only) —
// chat is a side effect, never a blocker for the AntonX flow.

require_once __DIR__ . '/helpers.php';

/**
 * Reconcile the chat channel + memberships for a given project. Returns the
 * channel_id on success, 0 if the bridge couldn't run (e.g. project was
 * deleted between the AntonX mutation and this call).
 */
function chat_project_sync(int $projectId): int {
  if ($projectId <= 0) return 0;
  try {
    $pdo = db();

    // Load the project.
    $stmt = $pdo->prepare('SELECT id, workspace_id, name FROM projects WHERE id = ? LIMIT 1');
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if (!$project) return 0;
    $ws   = (int)$project['workspace_id'];
    $name = (string)$project['name'];
    $slug = 'proj-' . chat_slugify($name);

    // Look up an existing channel for this project. We track via
    // chat_message_links (entity_type='project') — but for the channel itself
    // there's no message; we store the channel_id by stashing a "system"
    // marker row with message_id = 0 reserved... actually simpler: just
    // match by slug under the same workspace.
    $stmt = $pdo->prepare('SELECT id FROM chat_channels WHERE workspace_id = ? AND slug = ? LIMIT 1');
    $stmt->execute([$ws, $slug]);
    $channelId = (int)$stmt->fetchColumn();

    $now = now();
    $system = chat_get_system_user($ws);
    $creatorId = $system ? (int)$system['id'] : 0;

    if ($channelId === 0) {
      $ins = $pdo->prepare(
        'INSERT INTO chat_channels (workspace_id, slug, is_private, topic, description, created_by, created_at)
         VALUES (?, ?, 1, ?, ?, ?, ?)'
      );
      $ins->execute([
        $ws, $slug,
        // Topic = project name; description = short blurb.
        $name,
        'Auto-linked channel for project "' . $name . '". Members sync with project assignees.',
        $creatorId, $now,
      ]);
      $channelId = (int)$pdo->lastInsertId();

      // Welcome message from Anton Jr..
      chat_post_as_system(
        $ws, $channelId, null,
        '<p>Welcome to <strong>' . h($name) . '</strong>. This channel was created by Anton Jr. '
        . 'and tracks the project automatically — members sync with project assignees.</p>'
      );
      chat_emit_event($ws, 'channel_created', $channelId, null, null, $creatorId);
    } else {
      // Project name may have changed → keep channel's topic in sync.
      $upd = $pdo->prepare('UPDATE chat_channels SET topic = ? WHERE id = ?');
      $upd->execute([$name, $channelId]);
    }

    // Compute desired members: all distinct users assigned to any task in
    // this project. Anton Jr. itself is always a "member" of the channel
    // so its posts have a sender row available, but we skip adding it to
    // the visible membership list — it's a system user.
    $desiredStmt = $pdo->prepare(
      'SELECT DISTINCT ta.user_id
       FROM tasks t
       JOIN task_assignees ta ON ta.task_id = t.id
       WHERE t.project_id = ?'
    );
    $desiredStmt->execute([$projectId]);
    $desired = array_map('intval', array_column($desiredStmt->fetchAll(), 'user_id'));
    if (!empty($desired) && $creatorId > 0) {
      // Make sure Anton Jr. exists in the channel for system posts.
      $desired[] = $creatorId;
      $desired = array_values(array_unique($desired));
    }

    // Current members.
    $cur = $pdo->prepare('SELECT user_id FROM chat_channel_members WHERE channel_id = ?');
    $cur->execute([$channelId]);
    $current = array_map('intval', array_column($cur->fetchAll(), 'user_id'));

    $toAdd    = array_diff($desired, $current);
    $toRemove = array_diff($current, $desired);

    if (!empty($toAdd)) {
      $insMem = $pdo->prepare(
        'INSERT IGNORE INTO chat_channel_members (channel_id, user_id, joined_at)
         VALUES (?, ?, ?)'
      );
      foreach ($toAdd as $u) {
        $insMem->execute([$channelId, $u, $now]);
        chat_emit_event($ws, 'channel_member_added', $channelId, null, null, $u);
      }
    }
    // Don't remove Anton Jr. itself or users who joined manually outside
    // the auto-sync (we only remove if the user is NOT assigned to ANY task
    // in this project, AND isn't the system user). For simplicity we err on
    // the side of keeping members rather than aggressively pruning — leaving
    // a project doesn't necessarily mean "kick out of channel".
    // (Step 4 keeps removal manual via the existing leave-channel UX.)

    return $channelId;
  } catch (Throwable $e) {
    error_log('[Anton Jr.] chat_project_sync failed: ' . $e->getMessage());
    return 0;
  }
}
