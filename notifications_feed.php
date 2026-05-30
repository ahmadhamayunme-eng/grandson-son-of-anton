<?php
/**
 * Notification feed (item #10). Backs the header bell's dropdown.
 *   GET                       -> { unread: int, items: [...] }
 *   POST action=mark_read     -> marks everything up to the latest as read
 * Built on the existing activity_log; read-state lives in notification_reads.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();
header('Content-Type: application/json');

$u   = auth_user();
$uid = (int)$u['id'];
$ws  = (int)$u['workspace_id'];
$pdo = db();

function notif_last_read(PDO $pdo, int $uid): int {
  try {
    $st = $pdo->prepare("SELECT last_read_id FROM notification_reads WHERE user_id=?");
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
  } catch (Throwable $e) {
    app_log('notif_last_read', $e);
    return 0;
  }
}

/** Map an activity_log row to a destination URL. */
function notif_href(string $type, ?int $id): string {
  $id = (int)$id;
  switch ($type) {
    case 'task':    return $id ? 'task_view.php?id=' . $id : 'my_tasks.php';
    case 'project': return $id ? 'project_view.php?id=' . $id : 'projects.php';
    case 'client':  return $id ? 'client_view.php?id=' . $id : 'clients.php';
    case 'doc':     return 'docs.php';
    default:        return 'activity.php';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  if (($_POST['action'] ?? '') === 'mark_read') {
    try {
      $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM activity_log WHERE workspace_id=" . $ws)->fetchColumn();
      $pdo->prepare(
        "INSERT INTO notification_reads (user_id, last_read_id, updated_at) VALUES (?,?,NOW())
         ON DUPLICATE KEY UPDATE last_read_id=GREATEST(last_read_id, VALUES(last_read_id)), updated_at=NOW()"
      )->execute([$uid, $maxId]);
      echo json_encode(['ok' => true, 'last_read_id' => $maxId]);
    } catch (Throwable $e) {
      app_log('notif_mark_read', $e);
      echo json_encode(['ok' => false]);
    }
    exit;
  }
  echo json_encode(['ok' => false, 'error' => 'unknown action']);
  exit;
}

// GET: recent items + unread count. Exclude the user's own actions.
$lastRead = notif_last_read($pdo, $uid);
$items = [];
$unread = 0;
try {
  $st = $pdo->prepare(
    "SELECT al.id, al.entity_type, al.entity_id, al.action, al.message, al.created_at, u.name AS actor
     FROM activity_log al LEFT JOIN users u ON u.id = al.actor_user_id
     WHERE al.workspace_id=? AND (al.actor_user_id IS NULL OR al.actor_user_id <> ?)
     ORDER BY al.id DESC LIMIT 20"
  );
  $st->execute([$ws, $uid]);
  foreach ($st->fetchAll() as $r) {
    $items[] = [
      'id'      => (int)$r['id'],
      'actor'   => $r['actor'] ?? 'Someone',
      'action'  => (string)$r['action'],
      'message' => (string)($r['message'] ?? ''),
      'entity'  => (string)$r['entity_type'],
      'href'    => notif_href((string)$r['entity_type'], $r['entity_id'] !== null ? (int)$r['entity_id'] : null),
      'created_at' => (string)$r['created_at'],
      'unread'  => ((int)$r['id'] > $lastRead),
    ];
  }
  $cnt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE workspace_id=? AND id>? AND (actor_user_id IS NULL OR actor_user_id <> ?)");
  $cnt->execute([$ws, $lastRead, $uid]);
  $unread = (int)$cnt->fetchColumn();
} catch (Throwable $e) {
  app_log('notif_feed', $e);
}

echo json_encode(['ok' => true, 'unread' => $unread, 'items' => $items]);
