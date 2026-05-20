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
    $files     = $m['files']       ?? [];
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
      <?php // .chat-msg-text holds the rendered markdown HTML — block tags
            // (p, blockquote, pre, ul...) get their own whitespace handling
            // from CSS; no source-code indentation goes inside this div. ?>
      <?php $hasContent = !$deleted && (($m['content_html'] ?? '') !== '' || ($m['content'] ?? '') !== ''); ?>
      <?php if ($deleted): ?>
        <div class="chat-msg-text chat-msg-deleted"><em>This message was deleted.</em></div>
      <?php elseif ($hasContent): ?>
        <?php // data-raw-content carries the raw markdown for the inline editor
              // (Phase 9). Kept as an attribute so the editor doesn't need a
              // server round-trip to fetch the source. ?>
        <div class="chat-msg-text" data-raw-content="<?= h((string)($m['content'] ?? '')) ?>"><?= $m['content_html'] ?? nl2br(h((string)($m['content'] ?? ''))) ?><?php if ($edited): ?> <span class="chat-msg-edited">(edited)</span><?php endif; ?></div>
      <?php endif; ?>
      <?php if (!$deleted && !empty($files)): ?>
        <div class="chat-msg-files">
          <?php foreach ($files as $file):
            $fid   = (int)$file['id'];
            $fname = (string)$file['name'];
            $fmime = (string)($file['mime'] ?? $file['mime_type'] ?? '');
            $fsize = (int)($file['size'] ?? $file['size_bytes'] ?? 0);
            $isImg = !empty($file['is_image']);
          ?>
            <?php if ($isImg): ?>
              <a class="chat-file chat-file-image" href="/chat/api/file.php?id=<?= $fid ?>" target="_blank" rel="noopener noreferrer" title="<?= h($fname) ?>">
                <img src="/chat/api/file.php?id=<?= $fid ?>" alt="<?= h($fname) ?>" loading="lazy">
              </a>
            <?php else: ?>
              <a class="chat-file chat-file-other" href="/chat/api/file.php?id=<?= $fid ?>" target="_blank" rel="noopener noreferrer" download="<?= h($fname) ?>">
                <span class="chat-file-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                </span>
                <span class="chat-file-info">
                  <span class="chat-file-name"><?= h($fname) ?></span>
                  <span class="chat-file-size"><?= h(chat_format_file_size($fsize)) ?></span>
                </span>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
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
        <?php if ((int)$m['user_id'] === (int)$uid): ?>
          <button type="button" class="chat-msg-action" data-action="open-msg-menu" data-msg-id="<?= (int)$m['id'] ?>" title="More">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle></svg>
          </button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
<?php
    $prevAuthor = $m['user_id'];
    $prevTs     = $msgTs;
  endforeach;
endif;
