<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * Saved views / filters (item #13). A "view" is just a named query string for
 * a given list page (e.g. my_tasks, projects), scoped to the current user +
 * workspace. Degrades to an empty list if the table isn't present yet.
 */
function saved_views_list(string $page): array {
  $u = auth_user();
  if (!$u) return [];
  try {
    $stmt = db()->prepare(
      "SELECT id, name, query_string FROM saved_views
       WHERE workspace_id=? AND user_id=? AND page=?
       ORDER BY name ASC"
    );
    $stmt->execute([(int)$u['workspace_id'], (int)$u['id'], $page]);
    return $stmt->fetchAll() ?: [];
  } catch (Throwable $e) {
    app_log('saved_views_list', $e, ['page' => $page]);
    return [];
  }
}

function saved_view_create(string $page, string $name, string $queryString): bool {
  $u = auth_user();
  if (!$u) return false;
  $name = trim($name);
  if ($name === '') return false;
  // Normalise: drop a leading '?' and any saved_view control params.
  $queryString = ltrim($queryString, '?');
  try {
    db()->prepare(
      "INSERT INTO saved_views (workspace_id, user_id, page, name, query_string, created_at)
       VALUES (?,?,?,?,?,NOW())"
    )->execute([(int)$u['workspace_id'], (int)$u['id'], $page, mb_substr($name, 0, 120), mb_substr($queryString, 0, 1000)]);
    return true;
  } catch (Throwable $e) {
    app_log('saved_view_create', $e, ['page' => $page]);
    return false;
  }
}

function saved_view_delete(int $id): bool {
  $u = auth_user();
  if (!$u) return false;
  try {
    db()->prepare("DELETE FROM saved_views WHERE id=? AND workspace_id=? AND user_id=?")
       ->execute([$id, (int)$u['workspace_id'], (int)$u['id']]);
    return true;
  } catch (Throwable $e) {
    app_log('saved_view_delete', $e);
    return false;
  }
}
