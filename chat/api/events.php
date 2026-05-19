<?php
// /chat/api/events.php — Server-Sent Events stream for Anton Chat.
//
// Holds the connection open for ~25s, polling chat_events every second for
// new rows visible to the current user, and flushing each one to the
// browser as an SSE event. The client (EventSource) auto-reconnects when
// we close — gives us liveness without persistent background processes,
// which Hostinger Cloud Startup doesn't allow.
//
// Resumption: the client sends Last-Event-ID on reconnect, so no events
// are missed or replayed across reconnects.
//
// Fallback poll mode (?poll=1): when SSE is blocked (proxy stripping the
// stream, etc.), JS retries this same endpoint as a regular JSON GET.
//
// Tuning for managed PHP:
//   - session_write_close() up front so we don't hold the session lock
//     for 25s and block the user's other tabs / send requests.
//   - set_time_limit(35), ignore_user_abort(false): hard ceilings.
//   - output buffering disabled + a 2 KB initial padding to force any
//     upstream buffer to start flushing.
//
// Visible scope per user (matches Phase 2 behaviour):
//   - All events in channels they're a member of (any visibility).
//   - All events in public channels in their workspace (Slack-style
//     preview).
//   - DM events come in Phase 4.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = auth_user();
if (!$user) {
  // EventSource can't follow a 303 redirect to login.php, so emit a one-shot
  // 'error' event the client can detect and handle.
  http_response_code(401);
  header('Content-Type: text/event-stream; charset=utf-8');
  echo "event: error\n";
  echo 'data: {"error":"unauthorized"}' . "\n\n";
  exit;
}
$ws  = (int)$user['workspace_id'];
$uid = (int)$user['id'];

// Release the session lock immediately so a 25-second SSE doesn't pin the
// session for other requests from the same user.
session_write_close();

@set_time_limit(35);
ignore_user_abort(false);

// Disable every layer of buffering we can reach from PHP.
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
@ini_set('output_buffering', 'Off');
@ob_implicit_flush(true);
while (ob_get_level() > 0) { @ob_end_flush(); }

header('Cache-Control: no-cache, no-store, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');   // nginx
header('Content-Encoding: none');

$isPoll = !empty($_GET['poll']);
$pdo    = db();

// Resolve the starting event id.
// 1. EventSource sends Last-Event-ID on every reconnect — most authoritative.
// 2. ?since=N from the initial JS open (read from CHAT_BOOT.lastEventId).
// 3. If neither, start from "now" so we don't replay history.
$since = -1;
if (isset($_SERVER['HTTP_LAST_EVENT_ID']) && $_SERVER['HTTP_LAST_EVENT_ID'] !== '') {
  $since = (int)$_SERVER['HTTP_LAST_EVENT_ID'];
} elseif (isset($_GET['since']) && $_GET['since'] !== '') {
  $since = (int)$_GET['since'];
}
if ($since < 0) {
  $stmt = $pdo->prepare('SELECT IFNULL(MAX(id), 0) FROM chat_events WHERE workspace_id = ?');
  $stmt->execute([$ws]);
  $since = (int)$stmt->fetchColumn();
}

/**
 * One pass over chat_events for events strictly after $since that the user
 * is allowed to see. Joins chat_messages + users in the same round trip so
 * the client doesn't have to fetch each new message separately.
 */
function chat_sse_fetch(PDO $pdo, int $ws, int $uid, int $since): array {
  $stmt = $pdo->prepare(
    'SELECT e.id AS event_id, e.event_type, e.channel_id, e.conversation_id, e.message_id,
            e.actor_user_id, e.payload, e.created_at,
            m.content       AS message_content,
            m.user_id       AS message_user_id,
            m.created_at    AS message_created_at,
            m.edited_at     AS message_edited_at,
            m.deleted_at    AS message_deleted_at,
            m.parent_message_id AS message_parent_id,
            u.name          AS author_name,
            ch.slug         AS channel_slug
     FROM chat_events e
     LEFT JOIN chat_messages m  ON m.id  = e.message_id
     LEFT JOIN users u          ON u.id  = m.user_id
     LEFT JOIN chat_channels ch ON ch.id = e.channel_id
     WHERE e.workspace_id = ?
       AND e.id > ?
       AND (
         e.channel_id IN (
           SELECT id FROM chat_channels
           WHERE workspace_id = ? AND archived_at IS NULL
             AND (is_private = 0 OR id IN (
               SELECT channel_id FROM chat_channel_members WHERE user_id = ?
             ))
         )
         OR e.conversation_id IN (
           SELECT conversation_id FROM chat_direct_conversation_members WHERE user_id = ?
         )
       )
     ORDER BY e.id ASC
     LIMIT 200'
  );
  $stmt->execute([$ws, $since, $ws, $uid, $uid]);
  $rows = $stmt->fetchAll();
  foreach ($rows as &$r) {
    if ($r['payload'] !== null) {
      $decoded = json_decode($r['payload'], true);
      $r['payload'] = $decoded === null ? $r['payload'] : $decoded;
    }
    // Soft-deleted message: blank the content so the client never sees
    // the original text.
    if ($r['message_deleted_at'] !== null) {
      $r['message_content']      = '';
      $r['message_content_html'] = '';
    } elseif ($r['message_content'] !== null) {
      // Pre-render @mentions etc. so SSE clients can append HTML directly
      // without a second round trip per new message.
      $r['message_content_html'] = chat_render_message_content((string)$r['message_content'], $ws);
    } else {
      $r['message_content_html'] = null;
    }
  }
  unset($r);

  // Attach file lists. Single batch query for every message_id referenced
  // in this set of events, so an SSE poll never grows beyond two queries.
  $msgIds = [];
  foreach ($rows as $r) {
    if ($r['message_id'] !== null) $msgIds[] = (int)$r['message_id'];
  }
  if (!empty($msgIds)) {
    $filesByMsg = chat_load_files_for_messages($msgIds);
    foreach ($rows as &$r) {
      $mid = $r['message_id'] !== null ? (int)$r['message_id'] : null;
      $r['message_files'] = ($mid !== null && isset($filesByMsg[$mid])) ? $filesByMsg[$mid] : [];
    }
    unset($r);
  }

  return $rows;
}

if ($isPoll) {
  // Fallback path: single JSON response with everything new since `since`.
  // No streaming, no holding. Used when SSE is blocked.
  $events   = chat_sse_fetch($pdo, $ws, $uid, $since);
  $lastSeen = $since;
  foreach ($events as $ev) { $lastSeen = max($lastSeen, (int)$ev['event_id']); }
  chat_json(['events' => $events, 'last_event_id' => $lastSeen]);
}

// ===== SSE stream =====
header('Content-Type: text/event-stream; charset=utf-8');

// Tell the browser how long to wait before reconnecting after a close.
echo "retry: 3000\n";
echo ": connected\n\n";
// Forced-flush padding: a few KB of whitespace makes most upstream buffers
// (LiteSpeed, fastcgi) start flushing immediately instead of holding the
// whole 25s of bytes.
echo str_repeat(' ', 2048) . "\n";
@flush();

$start      = time();
$maxRuntime = 25;   // close before set_time_limit / proxy timeouts bite

while ((time() - $start) < $maxRuntime) {
  if (connection_aborted()) break;

  $events = chat_sse_fetch($pdo, $ws, $uid, $since);
  if (!empty($events)) {
    foreach ($events as $ev) {
      $eventId = (int)$ev['event_id'];
      echo 'id: '    . $eventId       . "\n";
      echo 'event: ' . $ev['event_type'] . "\n";
      echo 'data: '  . json_encode($ev)  . "\n\n";
      $since = $eventId;
    }
    @flush();
  } else {
    // Heartbeat — SSE comment ("`:`") is ignored by EventSource but keeps
    // the socket alive through any intermediate proxy.
    echo ': ping ' . time() . "\n\n";
    @flush();
  }
  sleep(1);
}
exit;
