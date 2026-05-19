<?php
// chat/includes/messages_partial.php — shared message-list rendering used by
// both the channel and DM branches in chat/index.php, plus the thread panel.
//
// Expects in scope:
//   $currentMessages  array of decorated rows (chat_decorate_messages())
//   $uid              current user id (for the "me_reacted" highlight)
//   $emptyTitle       (optional) empty-state heading
//   $emptySub         (optional) empty-state subline
$emptyTitle = $emptyTitle ?? 'No messages yet.';
$emptySub   = $emptySub   ?? '';
if (empty($currentMessages)): ?>
  <div class="chat-msgs-empty">
    <div class="chat-msgs-empty-title"><?= h($emptyTitle) ?></div>
    <?php if ($emptySub !== ''): ?><div class="chat-msgs-empty-sub"><?= h($emptySub) ?></div><?php endif; ?>
  </div>
<?php else:
  $prevAuthor = null; $prevTs = 0;
  foreach ($currentMessages as $m):
    $msgTs     = strtotime($m['created_at']);
    $grouped   = ($m['user_id'] === $prevAuthor) && (($msgTs - $prevTs) < 300);
    $deleted   = $m['deleted_at'] !== null;
    $edited    = $m['edited_at']  !== null;
    $reactions = $m['reactions']   ?? [];
    $replyCt   = (int)($m['reply_count'] ?? 0);
?>
  <div class="chat-msg<?= $grouped ? ' chat-msg-grouped' : '' ?>" data-msg-id="<?= (int)$m['id'] ?>" data-user-id="<?= (int)$m['user_id'] ?>" data-msg-ts="<?= (int)$msgTs ?>">
    <div class="chat-msg-avatar">
      <?php if (!$grouped): ?>
        <?= user_avatar_html((int)$m['user_id'], $m['author_name'], 'chat-avatar') ?>
      <?php endif; ?>
    </div>
    <div class="chat-msg-body">
      <?php if (!$grouped): ?>
        <div class="chat-msg-meta">
          <span class="chat-msg-name"><?= h($m['author_name']) ?></span>
          <span class="chat-msg-time"><?= h(chat_format_message_time($m['created_at'])) ?></span>
        </div>
      <?php endif; ?>
      <?php // .chat-msg-text uses white-space: pre-wrap, so no whitespace inside the div. ?>
      <div class="chat-msg-text<?= $deleted ? ' chat-msg-deleted' : '' ?>"><?php
        if ($deleted) {
          echo '<em>This message was deleted.</em>';
        } else {
          echo $m['content_html'] ?? nl2br(h((string)($m['content'] ?? '')));
          if ($edited) echo ' <span class="chat-msg-edited">(edited)</span>';
        }
      ?></div>
      <?php if (!empty($reactions)): ?>
        <div class="chat-reactions" data-msg-id="<?= (int)$m['id'] ?>">
          <?php foreach ($reactions as $rx):
            $idsRaw = (string)($rx['user_ids'] ?? '');
            $idsArr = $idsRaw !== '' ? array_map('intval', explode(',', $idsRaw)) : [];
            $me     = in_array((int)$uid, $idsArr, true);
          ?>
            <button type="button"
                    class="chat-reaction<?= $me ? ' chat-reaction-mine' : '' ?>"
                    data-action="toggle-reaction"
                    data-msg-id="<?= (int)$m['id'] ?>"
                    data-emoji="<?= h($rx['emoji']) ?>">
              <span class="chat-reaction-emoji"><?= h($rx['emoji']) ?></span><span class="chat-reaction-count"><?= (int)$rx['count'] ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($replyCt > 0): ?>
        <button type="button" class="chat-reply-count" data-action="open-thread" data-msg-id="<?= (int)$m['id'] ?>"><?= $replyCt ?> repl<?= $replyCt === 1 ? 'y' : 'ies' ?></button>
      <?php endif; ?>
    </div>
    <?php if (!$deleted): ?>
      <div class="chat-msg-actions" aria-hidden="true">
        <button type="button" class="chat-msg-action" data-action="add-reaction" data-msg-id="<?= (int)$m['id'] ?>" title="Add reaction">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M8 14s1.5 2 4 2 4-2 4-2"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="15" y1="9" x2="15.01" y2="9"></line></svg>
        </button>
        <button type="button" class="chat-msg-action" data-action="open-thread" data-msg-id="<?= (int)$m['id'] ?>" title="Reply in thread">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
        </button>
      </div>
    <?php endif; ?>
  </div>
<?php
    $prevAuthor = $m['user_id'];
    $prevTs     = $msgTs;
  endforeach;
endif;
