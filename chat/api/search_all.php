<?php
// /chat/api/search_all.php — unified Anton Jr. search.
//
// GET ?q=<text>&limit=8
//   Searches across People, Tasks, Projects, Clients, Docs, Channels, and
//   Chat messages within the user's workspace. Returns one JSON payload
//   grouped by entity kind. Each entity is at most `limit` rows.
//
// This is the backend for the AntonX top-bar search dropdown AND the new
// "All of Anton" tab in the chat Cmd+K palette.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = chat_api_require_login();
$ws   = (int)$user['workspace_id'];
$uid  = (int)$user['id'];

$q     = trim((string)($_GET['q'] ?? ''));
$limit = max(3, min(20, (int)($_GET['limit'] ?? 8)));
if ($q === '') {
  chat_json([
    'q' => '',
    'groups' => (object)[],
  ]);
}

$like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
$pdo  = db();
$out  = ['q' => $q, 'groups' => []];

// People (active users, exclude Anton Jr. itself).
try {
  $st = $pdo->prepare(
    "SELECT id, name, email FROM users
     WHERE workspace_id = ? AND IFNULL(is_system, 0) = 0 AND is_active = 1
       AND (name LIKE ? OR email LIKE ?)
     ORDER BY name ASC LIMIT ?"
  );
  $st->bindValue(1, $ws, PDO::PARAM_INT);
  $st->bindValue(2, $like);
  $st->bindValue(3, $like);
  $st->bindValue(4, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();
  if ($rows) {
    $out['groups']['people'] = array_map(function ($r) {
      return [
        'id'    => (int)$r['id'],
        'label' => (string)$r['name'],
        'sub'   => (string)$r['email'],
        'href'  => '/users_management.php',
        'kind'  => 'Person',
      ];
    }, $rows);
  }
} catch (Throwable $e) {}

// Tasks.
try {
  $st = $pdo->prepare(
    "SELECT t.id, t.title, t.status, p.name AS project_name
     FROM tasks t LEFT JOIN projects p ON p.id = t.project_id
     WHERE t.workspace_id = ? AND t.title LIKE ?
     ORDER BY t.updated_at DESC LIMIT ?"
  );
  $st->bindValue(1, $ws, PDO::PARAM_INT);
  $st->bindValue(2, $like);
  $st->bindValue(3, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();
  if ($rows) {
    $out['groups']['tasks'] = array_map(function ($r) {
      return [
        'id'    => (int)$r['id'],
        'label' => (string)$r['title'],
        'sub'   => trim((string)($r['status'] ?? '') . ($r['project_name'] ? ' · ' . $r['project_name'] : '')),
        'href'  => '/task_details.php?id=' . (int)$r['id'],
        'kind'  => 'Task',
      ];
    }, $rows);
  }
} catch (Throwable $e) {}

// Projects.
try {
  $st = $pdo->prepare(
    "SELECT p.id, p.name, c.name AS client_name
     FROM projects p LEFT JOIN clients c ON c.id = p.client_id
     WHERE p.workspace_id = ? AND p.name LIKE ?
     ORDER BY p.updated_at DESC LIMIT ?"
  );
  $st->bindValue(1, $ws, PDO::PARAM_INT);
  $st->bindValue(2, $like);
  $st->bindValue(3, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();
  if ($rows) {
    $out['groups']['projects'] = array_map(function ($r) {
      return [
        'id'    => (int)$r['id'],
        'label' => (string)$r['name'],
        'sub'   => (string)($r['client_name'] ?? ''),
        'href'  => '/projects.php?id=' . (int)$r['id'],
        'kind'  => 'Project',
      ];
    }, $rows);
  }
} catch (Throwable $e) {}

// Clients.
try {
  $st = $pdo->prepare(
    "SELECT id, name FROM clients WHERE workspace_id = ? AND name LIKE ?
     ORDER BY name ASC LIMIT ?"
  );
  $st->bindValue(1, $ws, PDO::PARAM_INT);
  $st->bindValue(2, $like);
  $st->bindValue(3, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();
  if ($rows) {
    $out['groups']['clients'] = array_map(function ($r) {
      return [
        'id'    => (int)$r['id'],
        'label' => (string)$r['name'],
        'sub'   => '',
        'href'  => '/clients.php?id=' . (int)$r['id'],
        'kind'  => 'Client',
      ];
    }, $rows);
  }
} catch (Throwable $e) {}

// Docs.
try {
  $st = $pdo->prepare(
    "SELECT d.id, d.title, p.name AS project_name
     FROM docs d LEFT JOIN projects p ON p.id = d.project_id
     WHERE d.workspace_id = ? AND d.title LIKE ?
     ORDER BY d.updated_at DESC LIMIT ?"
  );
  $st->bindValue(1, $ws, PDO::PARAM_INT);
  $st->bindValue(2, $like);
  $st->bindValue(3, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();
  if ($rows) {
    $out['groups']['docs'] = array_map(function ($r) {
      return [
        'id'    => (int)$r['id'],
        'label' => (string)$r['title'],
        'sub'   => (string)($r['project_name'] ?? ''),
        'href'  => '/doc_edit.php?id=' . (int)$r['id'],
        'kind'  => 'Doc',
      ];
    }, $rows);
  }
} catch (Throwable $e) {}

// Channels.
try {
  $st = $pdo->prepare(
    "SELECT c.id, c.slug, c.topic, c.is_private FROM chat_channels c
     WHERE c.workspace_id = ?
       AND c.archived_at IS NULL
       AND (c.slug LIKE ? OR c.topic LIKE ?)
       AND (
         c.is_private = 0
         OR c.id IN (SELECT channel_id FROM chat_channel_members WHERE user_id = ?)
       )
     ORDER BY c.slug ASC LIMIT ?"
  );
  $st->bindValue(1, $ws, PDO::PARAM_INT);
  $st->bindValue(2, $like);
  $st->bindValue(3, $like);
  $st->bindValue(4, $uid, PDO::PARAM_INT);
  $st->bindValue(5, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();
  if ($rows) {
    $out['groups']['channels'] = array_map(function ($r) {
      $prefix = (int)$r['is_private'] ? '🔒' : '#';
      return [
        'id'    => (int)$r['id'],
        'label' => $prefix . ' ' . (string)$r['slug'],
        'sub'   => (string)($r['topic'] ?? ''),
        'href'  => '/chat/?c=' . urlencode((string)$r['slug']),
        'kind'  => 'Channel',
      ];
    }, $rows);
  }
} catch (Throwable $e) {}

// Messages — reuses the same visibility gate as the chat search endpoint.
try {
  $st = $pdo->prepare(
    "SELECT m.id, m.channel_id, m.conversation_id, m.content, m.created_at,
            u.name AS author_name, c.slug AS channel_slug
     FROM chat_messages m
     JOIN users u ON u.id = m.user_id
     LEFT JOIN chat_channels c ON c.id = m.channel_id
     WHERE m.workspace_id = ?
       AND m.deleted_at IS NULL
       AND m.content LIKE ?
       AND (
         m.channel_id IS NULL
         OR m.channel_id IN (
           SELECT id FROM chat_channels
           WHERE workspace_id = ? AND (is_private = 0 OR id IN (
             SELECT channel_id FROM chat_channel_members WHERE user_id = ?
           ))
         )
       )
       AND (
         m.conversation_id IS NULL
         OR m.conversation_id IN (
           SELECT conversation_id FROM chat_direct_conversation_members WHERE user_id = ?
         )
       )
     ORDER BY m.id DESC LIMIT ?"
  );
  $st->bindValue(1, $ws, PDO::PARAM_INT);
  $st->bindValue(2, $like);
  $st->bindValue(3, $ws, PDO::PARAM_INT);
  $st->bindValue(4, $uid, PDO::PARAM_INT);
  $st->bindValue(5, $uid, PDO::PARAM_INT);
  $st->bindValue(6, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();
  if ($rows) {
    $out['groups']['messages'] = array_map(function ($r) {
      $snippet = trim(strip_tags((string)$r['content']));
      if (mb_strlen($snippet, 'UTF-8') > 120) $snippet = mb_substr($snippet, 0, 120, 'UTF-8') . '…';
      $where = $r['channel_slug']
        ? '#' . (string)$r['channel_slug']
        : '@DM';
      $href = $r['channel_slug']
        ? '/chat/?c=' . urlencode((string)$r['channel_slug']) . '#msg-' . (int)$r['id']
        : '/chat/?d=' . (int)$r['conversation_id'] . '#msg-' . (int)$r['id'];
      return [
        'id'    => (int)$r['id'],
        'label' => $snippet,
        'sub'   => (string)$r['author_name'] . ' · ' . $where,
        'href'  => $href,
        'kind'  => 'Message',
      ];
    }, $rows);
  }
} catch (Throwable $e) {}

chat_json($out);
