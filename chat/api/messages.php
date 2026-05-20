<?php
// /chat/api/messages.php — message endpoints (Phase 10).
//
// Content storage in Phase 10 is sanitized HTML produced by the
// contentEditable composer. Legacy markdown rows still render fine via
// chat_render_message_content (which detects "looks like HTML" vs markdown).
//
// Endpoints:
//   GET  ?action=list&channel_id|conversation_id|parent_message_id
//   POST ?action=send    { content (HTML), target, optional reply_to_message_id, files[] }
//   POST ?action=edit    { message_id, content (HTML) }
//   POST ?action=delete  { message_id }
//   POST ?action=forward { source_message_id, target (channel_id or conversation_id), note? }

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = chat_api_require_login();
$ws   = (int)$user['workspace_id'];
$uid  = (int)$user['id'];
$pdo  = db();
$action = (string)($_GET['action'] ?? '');

switch ($action) {
  case 'list':    msg_action_list($pdo, $ws, $uid);
  case 'send':    msg_action_send($pdo, $ws, $uid, $user);
  case 'edit':    msg_action_edit($pdo, $ws, $uid);
  case 'delete':  msg_action_delete($pdo, $ws, $uid);
  case 'forward': msg_action_forward($pdo, $ws, $uid, $user);
  default:        chat_json_error('unknown_action', "Unknown action: $action", 400);
}

function msg_action_list(PDO $pdo, int $ws, int $uid): never {
  $channelId       = (int)($_GET['channel_id']        ?? 0);
  $conversationId  = (int)($_GET['conversation_id']   ?? 0);
  $parentMessageId = (int)($_GET['parent_message_id'] ?? 0);
  $beforeId        = (int)($_GET['before_id']         ?? 0);
  $limit           = max(1, min(200, (int)($_GET['limit'] ?? 50)));

  $modes = ($channelId > 0) + ($conversationId > 0) + ($parentMessageId > 0);
  if ($modes !== 1) chat_json_error('bad_request', 'Specify exactly one of channel_id, conversation_id, parent_message_id.', 400);

  if ($parentMessageId > 0) {
    $stmt = $pdo->prepare('SELECT id, workspace_id, channel_id, conversation_id FROM chat_messages WHERE id = ?');
    $stmt->execute([$parentMessageId]);
    $parent = $stmt->fetch();
    if (!$parent) chat_json_error('not_found', 'Thread parent not found.', 404);
    if ((int)$parent['workspace_id'] !== $ws) chat_json_error('forbidden', '', 403);
    if ($parent['channel_id'] !== null) {
      $stmt = $pdo->prepare('SELECT is_private FROM chat_channels WHERE id = ?');
      $stmt->execute([(int)$parent['channel_id']]);
      $isPrivate = (int)$stmt->fetchColumn();
      if ($isPrivate && !chat_user_is_channel_member((int)$parent['channel_id'], $uid)) chat_json_error('forbidden', '', 403);
    } elseif ($parent['conversation_id'] !== null && !chat_user_is_dm_member((int)$parent['conversation_id'], $uid)) {
      chat_json_error('forbidden', '', 403);
    }

    $sql = 'SELECT m.id, m.user_id, m.content, m.created_at, m.edited_at, m.deleted_at,
                   m.parent_message_id, m.reply_to_message_id, u.name AS author_name
            FROM chat_messages m
            JOIN users u ON u.id = m.user_id
            WHERE m.parent_message_id = ?';
    $params = [$parentMessageId];
    if ($beforeId > 0) { $sql .= ' AND m.id < ?'; $params[] = $beforeId; }
    $sql .= ' ORDER BY m.id ASC LIMIT ' . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $contextChannelId = $parent['channel_id'] !== null ? (int)$parent['channel_id'] : null;
    chat_json([
      'messages'          => chat_decorate_messages_phase10($rows, $ws, $uid, $contextChannelId),
      'parent_message_id' => $parentMessageId,
    ]);
  }

  if ($channelId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM chat_channels WHERE id = ? AND workspace_id = ?');
    $stmt->execute([$channelId, $ws]);
    $ch = $stmt->fetch();
    if (!$ch) chat_json_error('not_found', 'Channel not found.', 404);
    if (!chat_user_can_see_channel($ch, $uid, $ws)) chat_json_error('forbidden', '', 403);
    $whereCol = 'm.channel_id'; $whereId = $channelId;
  } else {
    if (!chat_user_is_dm_member($conversationId, $uid)) chat_json_error('forbidden', '', 403);
    $stmt = $pdo->prepare('SELECT workspace_id FROM chat_direct_conversations WHERE id = ?');
    $stmt->execute([$conversationId]);
    if ((int)$stmt->fetchColumn() !== $ws) chat_json_error('forbidden', '', 403);
    $whereCol = 'm.conversation_id'; $whereId = $conversationId;
  }

  $sql = "SELECT m.id, m.user_id, m.content, m.created_at, m.edited_at, m.deleted_at,
                 m.parent_message_id, m.reply_to_message_id, u.name AS author_name
          FROM chat_messages m
          JOIN users u ON u.id = m.user_id
          WHERE $whereCol = ? AND m.parent_message_id IS NULL";
  $params = [$whereId];
  if ($beforeId > 0) { $sql .= ' AND m.id < ?'; $params[] = $beforeId; }
  $sql .= ' ORDER BY m.id DESC LIMIT ' . (int)$limit;
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = array_reverse($stmt->fetchAll());
  chat_json([
    'messages'        => chat_decorate_messages_phase10($rows, $ws, $uid, $channelId > 0 ? $channelId : null),
    'channel_id'      => $channelId > 0 ? $channelId : null,
    'conversation_id' => $conversationId > 0 ? $conversationId : null,
  ]);
}

function msg_action_send(PDO $pdo, int $ws, int $uid, array $user): never {
  chat_api_require_post();
  chat_api_require_csrf();

  $channelId        = (int)($_POST['channel_id']          ?? 0);
  $conversationId   = (int)($_POST['conversation_id']     ?? 0);
  $parentMessageId  = (int)($_POST['parent_message_id']   ?? 0);
  $replyToMessageId = (int)($_POST['reply_to_message_id'] ?? 0);
  $rawContent       = (string)($_POST['content']          ?? '');

  // Sanitize HTML on the way in (Phase 10).
  $content = chat_sanitize_html($rawContent);

  $rawFileIds = $_POST['file_ids'] ?? [];
  if (!is_array($rawFileIds)) $rawFileIds = [];
  $fileIds = [];
  foreach ($rawFileIds as $r) {
    $n = (int)$r;
    if ($n > 0 && !in_array($n, $fileIds, true)) $fileIds[] = $n;
  }

  // Empty allowed only when there's at least one file attached.
  if (trim($content) === '' && empty($fileIds)) chat_json_error('bad_request', 'Message is empty.', 400);
  if (strlen($content) > 40000) chat_json_error('too_long', 'Message exceeds 40,000 characters.', 400);
  foreach ($fileIds as $fid) {
    if (!chat_user_owns_staged_file($fid, $uid, $ws)) chat_json_error('bad_file', 'One or more files are not yours or already attached.', 400);
  }

  // Thread parent: copy its channel/DM scope.
  if ($parentMessageId > 0) {
    $stmt = $pdo->prepare('SELECT id, workspace_id, channel_id, conversation_id FROM chat_messages WHERE id = ?');
    $stmt->execute([$parentMessageId]);
    $parent = $stmt->fetch();
    if (!$parent || (int)$parent['workspace_id'] !== $ws) chat_json_error('forbidden', '', 403);
    $channelId      = $parent['channel_id']      !== null ? (int)$parent['channel_id']      : 0;
    $conversationId = $parent['conversation_id'] !== null ? (int)$parent['conversation_id'] : 0;
  }
  if (($channelId > 0) === ($conversationId > 0)) chat_json_error('bad_request', 'Specify exactly one of channel_id or conversation_id.', 400);

  if ($channelId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM chat_channels WHERE id = ? AND workspace_id = ?');
    $stmt->execute([$channelId, $ws]);
    $ch = $stmt->fetch();
    if (!$ch) chat_json_error('not_found', 'Channel not found.', 404);
    if (!chat_user_is_channel_member($channelId, $uid)) chat_json_error('not_member', 'Join the channel before posting.', 403);
  } else {
    if (!chat_user_is_dm_member($conversationId, $uid)) chat_json_error('forbidden', '', 403);
    $stmt = $pdo->prepare('SELECT workspace_id FROM chat_direct_conversations WHERE id = ?');
    $stmt->execute([$conversationId]);
    if ((int)$stmt->fetchColumn() !== $ws) chat_json_error('forbidden', '', 403);
  }

  // Validate reply_to (must be in the same channel/DM and not deleted).
  if ($replyToMessageId > 0) {
    $stmt = $pdo->prepare('SELECT channel_id, conversation_id, workspace_id, deleted_at FROM chat_messages WHERE id = ?');
    $stmt->execute([$replyToMessageId]);
    $rep = $stmt->fetch();
    if (!$rep || (int)$rep['workspace_id'] !== $ws || $rep['deleted_at'] !== null) {
      chat_json_error('bad_request', 'Reply target is unavailable.', 400);
    }
    $sameChannel = $channelId > 0 && (int)$rep['channel_id'] === $channelId;
    $sameDm      = $conversationId > 0 && (int)$rep['conversation_id'] === $conversationId;
    if (!$sameChannel && !$sameDm) chat_json_error('bad_request', 'Reply target must be in the same channel or DM.', 400);
  }

  $now = now();
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare(
      'INSERT INTO chat_messages
         (workspace_id, channel_id, conversation_id, parent_message_id, reply_to_message_id, user_id, content, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
      $ws,
      $channelId        > 0 ? $channelId        : null,
      $conversationId   > 0 ? $conversationId   : null,
      $parentMessageId  > 0 ? $parentMessageId  : null,
      $replyToMessageId > 0 ? $replyToMessageId : null,
      $uid, $content, $now,
    ]);
    $messageId = (int)$pdo->lastInsertId();
    chat_parse_and_store_mentions($messageId, $content, $ws);

    $attachedFiles = [];
    if (!empty($fileIds)) {
      $ph  = implode(',', array_fill(0, count($fileIds), '?'));
      $upd = $pdo->prepare("UPDATE chat_files SET message_id = ? WHERE id IN ($ph) AND user_id = ? AND workspace_id = ? AND message_id IS NULL");
      $upd->execute(array_merge([$messageId], $fileIds, [$uid, $ws]));
      $sel = $pdo->prepare("SELECT id, original_name, mime_type, size_bytes FROM chat_files WHERE id IN ($ph) AND message_id = ?");
      $sel->execute(array_merge($fileIds, [$messageId]));
      foreach ($sel->fetchAll() as $f) {
        $attachedFiles[] = [
          'id'       => (int)$f['id'],
          'name'     => $f['original_name'],
          'mime'     => $f['mime_type'],
          'size'     => (int)$f['size_bytes'],
          'is_image' => strpos((string)$f['mime_type'], 'image/') === 0 ? 1 : 0,
        ];
      }
    }

    $payload = [];
    if ($parentMessageId  > 0)  $payload['parent_message_id']   = $parentMessageId;
    if ($replyToMessageId > 0)  $payload['reply_to_message_id'] = $replyToMessageId;
    if (!empty($attachedFiles)) $payload['files'] = $attachedFiles;

    chat_emit_event(
      $ws, 'message',
      $channelId      > 0 ? $channelId      : null,
      $conversationId > 0 ? $conversationId : null,
      $messageId, $uid,
      empty($payload) ? null : $payload
    );

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  chat_json(['message' => [
    'id'                  => $messageId,
    'channel_id'          => $channelId      > 0 ? $channelId      : null,
    'conversation_id'     => $conversationId > 0 ? $conversationId : null,
    'parent_message_id'   => $parentMessageId  > 0 ? $parentMessageId  : null,
    'reply_to_message_id' => $replyToMessageId > 0 ? $replyToMessageId : null,
    'user_id'             => $uid,
    'author_name'         => $user['name'],
    'content'             => $content,
    'content_html'        => $content === '' ? '' : chat_render_message_content($content, $ws),
    'created_at'          => $now,
    'edited_at'           => null,
    'deleted_at'          => null,
    'reactions'           => [],
    'reply_count'         => 0,
    'files'               => $attachedFiles,
    'is_saved'            => 0,
    'is_pinned'           => 0,
    'reply_to'            => null,
  ]], 201);
}

function msg_action_edit(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();

  $messageId = (int)($_POST['message_id'] ?? 0);
  $rawContent = (string)($_POST['content'] ?? '');
  $content = chat_sanitize_html($rawContent);

  if ($messageId <= 0) chat_json_error('bad_request', 'Missing message_id.', 400);
  if (trim($content) === '') chat_json_error('bad_request', 'Message is empty.', 400);
  if (strlen($content) > 40000) chat_json_error('too_long', 'Message exceeds 40,000 characters.', 400);

  $stmt = $pdo->prepare('SELECT * FROM chat_messages WHERE id = ?');
  $stmt->execute([$messageId]);
  $msg = $stmt->fetch();
  if (!$msg)                              chat_json_error('not_found', 'Message not found.', 404);
  if ((int)$msg['workspace_id'] !== $ws)  chat_json_error('forbidden', '', 403);
  if ((int)$msg['user_id']      !== $uid) chat_json_error('forbidden', 'Only the author can edit.', 403);
  if ($msg['deleted_at']        !== null) chat_json_error('gone',      'Cannot edit a deleted message.', 410);

  $now = now();
  $pdo->beginTransaction();
  try {
    $del = $pdo->prepare('DELETE FROM chat_mentions WHERE message_id = ?');
    $del->execute([$messageId]);
    $upd = $pdo->prepare('UPDATE chat_messages SET content = ?, edited_at = ? WHERE id = ?');
    $upd->execute([$content, $now, $messageId]);
    chat_parse_and_store_mentions($messageId, $content, $ws);

    $payload = [];
    if ($msg['parent_message_id'] !== null) $payload['parent_message_id'] = (int)$msg['parent_message_id'];
    chat_emit_event(
      $ws, 'message_edited',
      $msg['channel_id']      !== null ? (int)$msg['channel_id']      : null,
      $msg['conversation_id'] !== null ? (int)$msg['conversation_id'] : null,
      $messageId, $uid,
      empty($payload) ? null : $payload
    );
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  chat_json(['message' => [
    'id'           => $messageId,
    'content'      => $content,
    'content_html' => chat_render_message_content($content, $ws),
    'edited_at'    => $now,
  ]]);
}

function msg_action_delete(PDO $pdo, int $ws, int $uid): never {
  chat_api_require_post();
  chat_api_require_csrf();
  $messageId = (int)($_POST['message_id'] ?? 0);
  if ($messageId <= 0) chat_json_error('bad_request', 'Missing message_id.', 400);

  $stmt = $pdo->prepare('SELECT * FROM chat_messages WHERE id = ?');
  $stmt->execute([$messageId]);
  $msg = $stmt->fetch();
  if (!$msg)                              chat_json_error('not_found', 'Message not found.', 404);
  if ((int)$msg['workspace_id'] !== $ws)  chat_json_error('forbidden', '', 403);
  if ((int)$msg['user_id']      !== $uid) chat_json_error('forbidden', 'Only the author can delete.', 403);
  if ($msg['deleted_at']        !== null) chat_json(['ok' => true, 'message_id' => $messageId]);

  $now = now();
  $pdo->beginTransaction();
  try {
    $upd = $pdo->prepare('UPDATE chat_messages SET deleted_at = ? WHERE id = ?');
    $upd->execute([$now, $messageId]);
    $payload = [];
    if ($msg['parent_message_id'] !== null) $payload['parent_message_id'] = (int)$msg['parent_message_id'];
    chat_emit_event(
      $ws, 'message_deleted',
      $msg['channel_id']      !== null ? (int)$msg['channel_id']      : null,
      $msg['conversation_id'] !== null ? (int)$msg['conversation_id'] : null,
      $messageId, $uid,
      empty($payload) ? null : $payload
    );
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
  chat_json(['ok' => true, 'message_id' => $messageId]);
}

function msg_action_forward(PDO $pdo, int $ws, int $uid, array $user): never {
  chat_api_require_post();
  chat_api_require_csrf();

  $sourceId       = (int)($_POST['source_message_id'] ?? 0);
  $channelId      = (int)($_POST['channel_id']         ?? 0);
  $conversationId = (int)($_POST['conversation_id']    ?? 0);
  $noteRaw        = (string)($_POST['note']            ?? '');

  if ($sourceId <= 0) chat_json_error('bad_request', 'Missing source_message_id.', 400);
  if (($channelId > 0) === ($conversationId > 0)) chat_json_error('bad_request', 'Specify exactly one target.', 400);

  // Load + visibility-check the source.
  $stmt = $pdo->prepare(
    'SELECT m.*, u.name AS author_name, c.slug AS channel_slug
     FROM chat_messages m
     JOIN users u ON u.id = m.user_id
     LEFT JOIN chat_channels c ON c.id = m.channel_id
     WHERE m.id = ?'
  );
  $stmt->execute([$sourceId]);
  $src = $stmt->fetch();
  if (!$src || (int)$src['workspace_id'] !== $ws) chat_json_error('not_found', 'Source message not found.', 404);
  if ($src['channel_id'] !== null) {
    $cs = $pdo->prepare('SELECT * FROM chat_channels WHERE id = ?');
    $cs->execute([(int)$src['channel_id']]);
    $sCh = $cs->fetch();
    if (!$sCh || !chat_user_can_see_channel($sCh, $uid, $ws)) chat_json_error('forbidden', '', 403);
  } elseif ($src['conversation_id'] !== null && !chat_user_is_dm_member((int)$src['conversation_id'], $uid)) {
    chat_json_error('forbidden', '', 403);
  }

  // Target membership.
  if ($channelId > 0 && !chat_user_is_channel_member($channelId, $uid)) chat_json_error('not_member', 'Join the target channel first.', 403);
  if ($conversationId > 0 && !chat_user_is_dm_member($conversationId, $uid)) chat_json_error('forbidden', '', 403);

  // Build the new message HTML. Slack-style: optional user note + a quoted
  // block showing "Forwarded from @author in #channel" + the source body.
  $note = chat_sanitize_html($noteRaw);
  $srcBodyHtml = chat_render_message_content((string)$src['content'], $ws);
  $contextLabel = $src['channel_id'] !== null
    ? ('in #' . h((string)$src['channel_slug']))
    : 'in a direct message';
  $forwardedHtml = '<blockquote><p><em>Forwarded from <strong>'
    . h((string)$src['author_name']) . '</strong> ' . $contextLabel . '</em></p>'
    . $srcBodyHtml
    . '</blockquote>';
  $content = ($note !== '' ? $note : '') . $forwardedHtml;
  $content = chat_sanitize_html($content);

  $now = now();
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare(
      'INSERT INTO chat_messages
         (workspace_id, channel_id, conversation_id, user_id, content, created_at)
       VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
      $ws,
      $channelId      > 0 ? $channelId      : null,
      $conversationId > 0 ? $conversationId : null,
      $uid, $content, $now,
    ]);
    $messageId = (int)$pdo->lastInsertId();
    chat_parse_and_store_mentions($messageId, $content, $ws);

    chat_emit_event(
      $ws, 'message',
      $channelId      > 0 ? $channelId      : null,
      $conversationId > 0 ? $conversationId : null,
      $messageId, $uid,
      ['forwarded_from_message_id' => $sourceId]
    );
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  chat_json(['ok' => true, 'message_id' => $messageId], 201);
}
