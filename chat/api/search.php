<?php
// /chat/api/search.php — message search (Phase 7).
//
// Endpoints:
//   GET ?action=context
//        Bundles everything the search modal needs in one round-trip:
//        { channels[], conversations[], people[] }. The modal populates
//        its From and In dropdowns from this response.
//
//   GET ?action=query
//        Runs the search. Params:
//          q                 text query (FULLTEXT, BOOLEAN MODE, +word*)
//          from              sender user_id (optional)
//          channel_id        restrict to one channel (optional)
//          conversation_id   restrict to one DM (optional)
//          after             "Y-m-d" lower bound (optional)
//          before            "Y-m-d" upper bound (inclusive) (optional)
//          has_file          "1" to require an attachment (optional)
//          limit / offset    pagination (default 50, max 100)
//        Returns { query, results[], limit, offset, count }.
//
// Permission: same scope as SSE — messages in channels the user is a
// member of, plus public channels in the workspace, plus DMs they're in.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = chat_api_require_login();
$ws   = (int)$user['workspace_id'];
$uid  = (int)$user['id'];
$pdo  = db();

$action = (string)($_GET['action'] ?? 'query');

switch ($action) {
  case 'context': search_action_context($pdo, $ws, $uid);
  case 'query':   search_action_query($pdo, $ws, $uid);
  default:        chat_json_error('unknown_action', "Unknown action: $action", 400);
}

function search_action_context(PDO $pdo, int $ws, int $uid): never {
  // Visible channels: public OR member. Same logic as the sidebar.
  $visibleChannels = chat_load_user_channels($ws, $uid);
  $channels = [];
  foreach ($visibleChannels as $c) {
    $channels[] = [
      'id'         => (int)$c['id'],
      'slug'       => $c['slug'],
      'is_private' => (int)$c['is_private'],
    ];
  }

  // DMs the user is in, with a display name.
  $dms = chat_load_user_dms($ws, $uid);
  $conversations = [];
  foreach ($dms as $d) {
    $conversations[] = [
      'id'       => (int)$d['id'],
      'is_group' => (int)$d['is_group'],
      'display'  => chat_dm_display_name($d),
    ];
  }

  // Workspace people for the "From" dropdown. Include self so the user
  // can search their own messages.
  $stmt = $pdo->prepare(
    'SELECT id, name FROM users
     WHERE workspace_id = ? AND is_active = 1
     ORDER BY name ASC'
  );
  $stmt->execute([$ws]);
  $people = $stmt->fetchAll();

  chat_json([
    'channels'      => $channels,
    'conversations' => $conversations,
    'people'        => $people,
  ]);
}

function search_action_query(PDO $pdo, int $ws, int $uid): never {
  $q              = trim((string)($_GET['q'] ?? ''));
  $from           = (int)($_GET['from']            ?? 0);
  $channelId      = (int)($_GET['channel_id']      ?? 0);
  $conversationId = (int)($_GET['conversation_id'] ?? 0);
  $after          = trim((string)($_GET['after']   ?? ''));
  $before         = trim((string)($_GET['before']  ?? ''));
  $hasFile        = !empty($_GET['has_file']);
  $limit          = max(1, min(100, (int)($_GET['limit']  ?? 50)));
  $offset         = max(0,           (int)($_GET['offset'] ?? 0));

  // Build a BOOLEAN-mode query: each token required, prefix wildcard so
  // "hel" matches "hello". Strip any boolean-mode operators the user
  // might paste (+, -, *, ", etc.) so they can't accidentally invert the
  // query intent.
  $words = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
  $booleanQuery = '';
  foreach ($words as $w) {
    $clean = preg_replace('/[+\-*"<>()@~]/', '', $w);
    if ($clean === '' || strlen($clean) < 1) continue;
    $booleanQuery .= ' +' . $clean . '*';
  }
  $booleanQuery = trim($booleanQuery);

  // If the user typed only filterable noise (e.g. punctuation), require
  // at least one filter so we don't dump the whole workspace.
  $hasFilter = $from > 0 || $channelId > 0 || $conversationId > 0
            || $after !== '' || $before !== '' || $hasFile;
  if ($booleanQuery === '' && !$hasFilter) {
    chat_json(['query' => $q, 'results' => [], 'limit' => $limit, 'offset' => $offset, 'count' => 0]);
  }

  $sql = "SELECT m.id, m.user_id, m.channel_id, m.conversation_id, m.parent_message_id,
                 m.content, m.created_at, u.name AS author_name,
                 c.slug AS channel_slug, c.is_private AS channel_is_private,
                 dc.is_group AS dm_is_group
          FROM chat_messages m
          JOIN users u  ON u.id  = m.user_id
          LEFT JOIN chat_channels c               ON c.id  = m.channel_id
          LEFT JOIN chat_direct_conversations dc  ON dc.id = m.conversation_id
          WHERE m.workspace_id = ?
            AND m.deleted_at IS NULL
            AND (
              (m.channel_id IS NOT NULL AND (c.is_private = 0 OR m.channel_id IN (
                SELECT channel_id FROM chat_channel_members WHERE user_id = ?
              )))
              OR (m.conversation_id IS NOT NULL AND m.conversation_id IN (
                SELECT conversation_id FROM chat_direct_conversation_members WHERE user_id = ?
              ))
            )";
  $params = [$ws, $uid, $uid];

  if ($booleanQuery !== '') {
    $sql .= " AND MATCH(m.content) AGAINST (? IN BOOLEAN MODE)";
    $params[] = $booleanQuery;
  }
  if ($from > 0) {
    $sql .= " AND m.user_id = ?";
    $params[] = $from;
  }
  if ($channelId > 0) {
    $sql .= " AND m.channel_id = ?";
    $params[] = $channelId;
  }
  if ($conversationId > 0) {
    $sql .= " AND m.conversation_id = ?";
    $params[] = $conversationId;
  }
  if ($after !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $after)) {
    $sql .= " AND m.created_at >= ?";
    $params[] = $after . ' 00:00:00';
  }
  if ($before !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) {
    // Inclusive "before" — treat as "anything before midnight the next day".
    $sql .= " AND m.created_at < ?";
    $params[] = date('Y-m-d', strtotime($before . ' +1 day')) . ' 00:00:00';
  }
  if ($hasFile) {
    $sql .= " AND EXISTS (SELECT 1 FROM chat_files WHERE message_id = m.id)";
  }

  // Ordering: relevance (when there's a query) then newest first.
  if ($booleanQuery !== '') {
    $sql .= " ORDER BY MATCH(m.content) AGAINST (? IN BOOLEAN MODE) DESC, m.id DESC";
    $params[] = $booleanQuery;
  } else {
    $sql .= " ORDER BY m.id DESC";
  }
  $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();

  // Cache DM display names to avoid N+1 lookups in this loop.
  $dmDisplayCache = [];

  foreach ($rows as &$r) {
    $r['content_html'] = chat_render_message_content((string)$r['content'], $ws);

    if ($r['conversation_id'] !== null) {
      $cid = (int)$r['conversation_id'];
      if (!isset($dmDisplayCache[$cid])) {
        $dm = chat_load_dm_by_id($ws, $uid, $cid);
        $dmDisplayCache[$cid] = $dm ? chat_dm_display_name($dm) : 'Direct Message';
      }
      $r['dm_display'] = $dmDisplayCache[$cid];
    } else {
      $r['dm_display'] = null;
    }

    // Has-file flag for the result row (badge in the UI).
    $fs = $pdo->prepare('SELECT 1 FROM chat_files WHERE message_id = ? LIMIT 1');
    $fs->execute([(int)$r['id']]);
    $r['has_file'] = (bool)$fs->fetchColumn() ? 1 : 0;
  }
  unset($r);

  chat_json([
    'query'   => $q,
    'results' => $rows,
    'limit'   => $limit,
    'offset'  => $offset,
    'count'   => count($rows),
  ]);
}
