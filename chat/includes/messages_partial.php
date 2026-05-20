<?php
// chat/includes/messages_partial.php — message list rendering (Phase 10).
//
// Expects in scope:
//   $currentMessages   array of decorated message rows
//   $uid               current user id
//   $emptyTitle        (optional) empty-state heading
//   $emptySub          (optional) empty-state subline
//
// Optional flags (default off):
//   $showContext       prepend "in #channel" / "in @DM" per message
//                      (used for global Threads / Inbox / Saved views)
//   $contextHasDays    insert day-divider rows between days

$emptyTitle     = $emptyTitle     ?? '';
$emptySub       = $emptySub       ?? '';
$showContext    = $showContext    ?? false;
$contextHasDays = $contextHasDays ?? true;

if (empty($currentMessages)) {
  if ($emptyTitle !== '' || $emptySub !== ''):
?>
  <div class="msgs-empty">
    <?php if ($emptyTitle !== ''): ?><div class="msgs-empty-title"><?= h($emptyTitle) ?></div><?php endif; ?>
    <?php if ($emptySub   !== ''): ?><div class="msgs-empty-sub"><?= h($emptySub) ?></div><?php endif; ?>
  </div>
<?php
  endif;
} else {

function _chat_day_label(string $datetime): string {
  $ts = strtotime($datetime);
  if ($ts === false) return '';
  $today = strtotime('today');
  if ($ts >= $today)                   return 'Today';
  if ($ts >= $today - 86400)           return 'Yesterday';
  if ($ts >= $today - 6 * 86400)       return date('l', $ts);     // weekday
  if (date('Y', $ts) === date('Y'))    return date('M j', $ts);
  return date('M j, Y', $ts);
}

$prevAuthor = null; $prevTs = 0; $prevDay = '';

foreach ($currentMessages as $m):
  $msgTs   = strtotime($m['created_at']);
  $day     = _chat_day_label($m['created_at']);
  $newDay  = $contextHasDays && $day !== $prevDay;
  $grouped = !$newDay && ($m['user_id'] === $prevAuthor) && (($msgTs - $prevTs) < 300);
  $deleted = $m['deleted_at'] !== null;
  $edited  = $m['edited_at']  !== null;
  $reactions = $m['reactions'] ?? [];
  $replyCt   = (int)($m['reply_count'] ?? 0);
  $files     = $m['files'] ?? [];
  $replyTo   = $m['reply_to'] ?? null;
  $isSaved   = !empty($m['is_saved']);
  $isPinned  = !empty($m['is_pinned']);
  $msgChannelSlug = $m['channel_slug'] ?? null;
  $msgChannelId   = $m['channel_id']   ?? null;
  $msgConvId      = $m['conversation_id'] ?? null;
?>

  <?php if ($newDay): ?>
    <div class="day-divider">
      <span class="line"></span>
      <span class="label"><?= h($day) ?></span>
      <span class="line"></span>
    </div>
  <?php endif; ?>

  <div class="msg-group<?= $isPinned ? ' is-pinned' : '' ?>" data-msg-id="<?= (int)$m['id'] ?>" data-user-id="<?= (int)$m['user_id'] ?>" data-msg-ts="<?= (int)$msgTs ?>">
    <div class="msg-row<?= $grouped ? ' compact' : '' ?>">
      <div class="av-slot">
        <?php if ($grouped): ?>
          <span class="av-time"><?= h(date('H:i', $msgTs)) ?></span>
        <?php else:
          $u = (int)$m['user_id'];
          $name = (string)$m['author_name'];
          $initials = function_exists('user_initials') ? user_initials($name) : strtoupper(substr($name, 0, 2));
        ?>
          <span class="av" data-user-id="<?= $u ?>" data-initials="<?= h($initials) ?>"><?= h($initials) ?><span class="presence dot-presence" data-user-id="<?= $u ?>"></span></span>
        <?php endif; ?>
      </div>
      <div class="msg-col">
        <?php if (!$grouped): ?>
          <div class="msg-meta">
            <span class="msg-author"><?= h($m['author_name']) ?></span>
            <?php if (!empty($m['parent_message_id']) && $showContext): ?><span class="msg-tag tag-reply">REPLY</span><?php endif; ?>
            <span class="msg-time"><?= h(date('H:i', $msgTs)) ?></span>
          </div>
        <?php endif; ?>

        <?php if ($showContext && ($msgChannelSlug || $msgConvId)): ?>
          <div class="msg-context">
            <?php if ($msgChannelSlug): ?>
              <a href="/chat/?c=<?= h(urlencode((string)$msgChannelSlug)) ?>"><span class="ch-link">#<?= h((string)$msgChannelSlug) ?></span></a>
            <?php elseif ($msgConvId): ?>
              <a href="/chat/?d=<?= (int)$msgConvId ?>"><span class="ch-link">@DM</span></a>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($replyTo && is_array($replyTo)): ?>
          <div class="reply-quote">
            <span class="ra"><?= h((string)$replyTo['author_name']) ?>:</span>
            <span class="rb"><?= $replyTo['deleted']
              ? '<em>this message was deleted</em>'
              : (strlen(strip_tags((string)$replyTo['content'])) > 160
                  ? h(mb_substr(strip_tags((string)$replyTo['content']), 0, 160)) . '…'
                  : strip_tags((string)$replyTo['content'])) ?></span>
          </div>
        <?php endif; ?>

        <?php $hasContent = !$deleted && (($m['content_html'] ?? '') !== '' || ($m['content'] ?? '') !== ''); ?>
        <?php if ($deleted): ?>
          <div class="msg-body msg-deleted"><em>This message was deleted.</em></div>
        <?php elseif ($hasContent): ?>
          <div class="msg-body" data-raw-content="<?= h((string)($m['content'] ?? '')) ?>"><?= $m['content_html'] ?? nl2br(h((string)($m['content'] ?? ''))) ?><?php if ($edited): ?> <span class="msg-edited">(edited)</span><?php endif; ?></div>
        <?php endif; ?>

        <?php if (!$deleted && !empty($files)): ?>
          <div class="msg-files">
            <?php foreach ($files as $file):
              $fid   = (int)$file['id'];
              $fname = (string)$file['name'];
              $fmime = (string)($file['mime'] ?? $file['mime_type'] ?? '');
              $fsize = (int)($file['size'] ?? $file['size_bytes'] ?? 0);
              $isImg = !empty($file['is_image']);
            ?>
              <?php if ($isImg): ?>
                <a class="msg-file msg-file-image" href="/chat/api/file.php?id=<?= $fid ?>" target="_blank" rel="noopener noreferrer" title="<?= h($fname) ?>">
                  <img src="/chat/api/file.php?id=<?= $fid ?>" alt="<?= h($fname) ?>" loading="lazy">
                </a>
              <?php else: ?>
                <a class="msg-file msg-file-other" href="/chat/api/file.php?id=<?= $fid ?>" target="_blank" rel="noopener noreferrer" download="<?= h($fname) ?>">
                  <span class="msg-file-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                  <span class="msg-file-info">
                    <span class="msg-file-name"><?= h($fname) ?></span>
                    <span class="msg-file-size"><?= h(chat_format_file_size($fsize)) ?></span>
                  </span>
                </a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($reactions)): ?>
          <div class="reactions" data-msg-id="<?= (int)$m['id'] ?>">
            <?php foreach ($reactions as $rx):
              $idsRaw = (string)($rx['user_ids'] ?? '');
              $idsArr = $idsRaw !== '' ? array_map('intval', explode(',', $idsRaw)) : [];
              $me     = in_array((int)$uid, $idsArr, true);
            ?>
              <button type="button" class="rx<?= $me ? ' mine' : '' ?>" data-action="toggle-reaction" data-msg-id="<?= (int)$m['id'] ?>" data-emoji="<?= h($rx['emoji']) ?>">
                <span class="glyph"><?= h($rx['emoji']) ?></span><span><?= (int)$rx['count'] ?></span>
              </button>
            <?php endforeach; ?>
            <button class="rx-add" data-action="add-reaction" data-msg-id="<?= (int)$m['id'] ?>" title="Add reaction">
              <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9.5h.01M15 9.5h.01"/></svg>
            </button>
          </div>
        <?php endif; ?>

        <?php if ($replyCt > 0): ?>
          <button type="button" class="thread-preview" data-action="open-thread" data-msg-id="<?= (int)$m['id'] ?>">
            <span class="count"><?= $replyCt ?> repl<?= $replyCt === 1 ? 'y' : 'ies' ?></span>
            <span class="last">View thread</span>
          </button>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$deleted): ?>
      <div class="msg-actions">
        <button type="button" data-action="add-reaction" data-msg-id="<?= (int)$m['id'] ?>" title="React">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9.5h.01M15 9.5h.01"/></svg>
        </button>
        <button type="button" class="accent" data-action="open-thread" data-msg-id="<?= (int)$m['id'] ?>" title="Reply in thread">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 17l-5-5 5-5"/><path d="M4 12h11a5 5 0 015 5v3"/></svg>
        </button>
        <button type="button" data-action="open-forward" data-msg-id="<?= (int)$m['id'] ?>" title="Forward">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </button>
        <button type="button" data-action="toggle-save" data-msg-id="<?= (int)$m['id'] ?>" title="<?= $isSaved ? 'Remove from Saved' : 'Save for later' ?>" class="<?= $isSaved ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="<?= $isSaved ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v18l-6-4-6 4z"/></svg>
        </button>
        <?php if ($msgChannelId): ?>
          <button type="button" data-action="toggle-pin" data-msg-id="<?= (int)$m['id'] ?>" title="<?= $isPinned ? 'Unpin from channel' : 'Pin to channel' ?>" class="<?= $isPinned ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="<?= $isPinned ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2l7 7-3 1-5 5-1 5-4-4-6 6 6-6-4-4 5-1 5-5z"/></svg>
          </button>
        <?php endif; ?>
        <button type="button" data-action="reply-quote" data-msg-id="<?= (int)$m['id'] ?>" data-author="<?= h($m['author_name']) ?>" title="Reply to this message">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 8h3v3H7v3a3 3 0 003 3M14 8h3v3h-3v3a3 3 0 003 3"/></svg>
        </button>
        <?php if ((int)$m['user_id'] === (int)$uid): ?>
          <button type="button" data-action="open-msg-menu" data-msg-id="<?= (int)$m['id'] ?>" title="More">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="12" r="1" fill="currentColor"/><circle cx="12" cy="12" r="1" fill="currentColor"/><circle cx="19" cy="12" r="1" fill="currentColor"/></svg>
          </button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

<?php
  $prevAuthor = $m['user_id'];
  $prevTs     = $msgTs;
  $prevDay    = $day;
endforeach;
} // end else
