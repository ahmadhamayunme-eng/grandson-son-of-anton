<?php
// chat/includes/comment_mirror.php — bidirectional bridge between AntonX
// task comments and chat thread replies on the anchor message (created by
// Convert-to-task or /share task#N).
//
// Wire-up:
//   * AntonX side (task_view.php): after INSERT INTO comments, call
//     chat_mirror_comment_to_thread($taskId, $commentId, $body, $authorUserId).
//     The bridge skips loops via comments.source_kind ('antonx' vs 'chat').
//   * Chat side (api/messages.php): after a thread reply lands, if the
//     thread's parent message is linked to a task, call
//     chat_mirror_thread_reply_to_comment($threadParentId, $messageId,
//     $body, $authorUserId).

require_once __DIR__ . '/helpers.php';

/**
 * AntonX comment added → append a thread reply on the linked anchor message.
 */
function chat_mirror_comment_to_thread(int $taskId, int $commentId, string $body, int $authorUserId, string $sourceKind = 'antonx'): void {
  if ($sourceKind === 'chat') return;   // already came from chat — don't echo
  if ($taskId <= 0 || $commentId <= 0) return;
  try {
    $pdo = db();
    // Find the workspace + the anchor chat message.
    $st = $pdo->prepare('SELECT workspace_id FROM tasks WHERE id = ?');
    $st->execute([$taskId]);
    $ws = (int)$st->fetchColumn();
    if ($ws <= 0) return;

    $anchor = chat_message_for_entity($ws, 'task', $taskId);
    if (!$anchor) return;   // no chat anchor — nothing to mirror to

    // Skip if the anchor is in a channel the author can't post to (rare).
    $anchorStmt = $pdo->prepare('SELECT channel_id, conversation_id FROM chat_messages WHERE id = ?');
    $anchorStmt->execute([$anchor]);
    $anchorRow = $anchorStmt->fetch();
    if (!$anchorRow) return;

    // Author name for the header.
    $au = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $au->execute([$authorUserId]);
    $authorName = (string)($au->fetchColumn() ?: 'Someone');

    $bodyHtml = '<p><strong>' . h($authorName) . '</strong> commented in AntonX:</p>'
              . '<blockquote><p>' . nl2br(h(trim($body))) . '</p></blockquote>';

    // Post as the comment author by writing a regular chat_messages row, but
    // mark it as Anton Jr. since we don't have a chat session for the
    // AntonX user. Either approach works; using Anton Jr. keeps the
    // "system bridge" feel and prevents the chat sidebar from acting like the
    // author posted from chat.
    $newMsgId = chat_post_as_system(
      $ws,
      $anchorRow['channel_id']      !== null ? (int)$anchorRow['channel_id']      : null,
      $anchorRow['conversation_id'] !== null ? (int)$anchorRow['conversation_id'] : null,
      $bodyHtml,
      null,
      $anchor
    );

    // Stamp the comment row with the chat message id so the chat→antonx
    // direction can later avoid duplicating it back.
    if ($newMsgId > 0) {
      try {
        $up = $pdo->prepare('UPDATE comments SET chat_message_id = ? WHERE id = ?');
        $up->execute([$newMsgId, $commentId]);
      } catch (Throwable $e) {}
    }
  } catch (Throwable $e) {
    error_log('[Anton Jr.] chat_mirror_comment_to_thread failed: ' . $e->getMessage());
  }
}

/**
 * Chat thread reply landed → mirror as an AntonX comment on the task linked
 * to the anchor message. Skips if source_kind='chat' would double the post.
 */
function chat_mirror_thread_reply_to_comment(int $anchorMessageId, int $newMessageId, string $rawHtml, int $authorUserId): void {
  if ($anchorMessageId <= 0 || $newMessageId <= 0) return;
  try {
    $pdo = db();
    $link = chat_entity_for_message($anchorMessageId);
    if (!$link || $link['entity_type'] !== 'task') return;
    $taskId = (int)$link['entity_id'];

    // Confirm the task is still in the user's workspace.
    $st = $pdo->prepare('SELECT workspace_id FROM tasks WHERE id = ?');
    $st->execute([$taskId]);
    $ws = (int)$st->fetchColumn();
    if ($ws <= 0) return;

    // Strip HTML to plaintext for the AntonX comments table (which stores
    // body as TEXT with no markup support).
    $plain = trim(strip_tags($rawHtml));
    if ($plain === '') return;

    $ins = $pdo->prepare(
      'INSERT INTO comments (workspace_id, task_id, author_user_id, body, created_at, source_kind, chat_message_id)
       VALUES (?, ?, ?, ?, ?, "chat", ?)'
    );
    $ins->execute([$ws, $taskId, $authorUserId, $plain, now(), $newMessageId]);
  } catch (Throwable $e) {
    error_log('[Anton Jr.] chat_mirror_thread_reply_to_comment failed: ' . $e->getMessage());
  }
}
