<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Activity-feed data loader (item #22).
 *
 * Demonstrates the thin controller/data separation: page-level data fetching
 * lives in lib/ as a testable function, while the page file becomes a thin
 * controller that hands the result to a view partial. File-based URLs are
 * preserved — this is an incremental step, not a router cutover.
 *
 * @return array{rows: array, total: int, page: int, perPage: int, totalPages: int}
 */
function activity_page_data(int $ws, int $page, int $perPage = 50): array {
  $page    = max(1, $page);
  $perPage = max(1, $perPage);
  $offset  = ($page - 1) * $perPage;

  $rows = [];
  $total = 0;
  try {
    $cnt = db()->prepare("SELECT COUNT(*) FROM activity_log WHERE workspace_id=?");
    $cnt->execute([$ws]);
    $total = (int)$cnt->fetchColumn();

    $stmt = db()->prepare(
      "SELECT al.*, u.name AS actor
       FROM activity_log al LEFT JOIN users u ON u.id = al.actor_user_id
       WHERE al.workspace_id=? ORDER BY al.id DESC LIMIT ? OFFSET ?"
    );
    $stmt->bindValue(1, $ws, PDO::PARAM_INT);
    $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
  } catch (Throwable $e) {
    app_log('activity_page_data', $e);
  }

  return [
    'rows'       => $rows,
    'total'      => $total,
    'page'       => $page,
    'perPage'    => $perPage,
    'totalPages' => max(1, (int)ceil($total / $perPage)),
  ];
}
