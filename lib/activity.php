<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function activity_log(string $entityType, ?int $entityId, string $action, ?string $message=null): void {
  $u = auth_user();
  if (!$u) return;
  try {
    $pdo = db();
    $pdo->prepare("INSERT INTO activity_log (workspace_id, actor_user_id, entity_type, entity_id, action, message, created_at)
      VALUES (?,?,?,?,?,?,NOW())")->execute([
        (int)$u['workspace_id'], (int)$u['id'], $entityType, $entityId, $action, $message
      ]);
  } catch (Throwable $e) {
    // ignore if table doesn't exist yet
  }
}
