<?php
// chat/includes/composer_partial.php — composer template used by the
// channel pane, the DM pane, and the thread panel. Centralises the
// toolbar / file picker / send markup so all three composers behave
// identically.
//
// Expects in scope:
//   $composerId      — id on the <form> (e.g. "chatComposer")
//   $inputId         — id on the <textarea>
//   $pendingId       — id on the pending-files container
//   $fileInputId     — id on the hidden <input type="file">
//   $placeholder     — textarea placeholder
//   $channelId       — (optional) data-channel-id on the form
//   $conversationId  — (optional) data-conversation-id on the form
//   $parentMessageId — (optional) data-parent-message-id on the form
?>
<form
  class="chat-composer"
  id="<?= h($composerId) ?>"
  autocomplete="off"
  <?php if (!empty($channelId)):       ?>data-channel-id="<?= (int)$channelId ?>"<?php endif; ?>
  <?php if (!empty($conversationId)):  ?>data-conversation-id="<?= (int)$conversationId ?>"<?php endif; ?>
  <?php if (!empty($parentMessageId)): ?>data-parent-message-id="<?= (int)$parentMessageId ?>"<?php endif; ?>
>
  <div class="chat-composer-toolbar" data-target="<?= h($inputId) ?>">
    <button type="button" class="chat-composer-fmt" data-format="bold"   title="Bold (Ctrl+B)"><b>B</b></button>
    <button type="button" class="chat-composer-fmt" data-format="italic" title="Italic (Ctrl+I)"><i>I</i></button>
    <button type="button" class="chat-composer-fmt" data-format="strike" title="Strikethrough"><s>S</s></button>
    <button type="button" class="chat-composer-fmt chat-composer-fmt-code" data-format="code" title="Inline code">&lt;/&gt;</button>
    <button type="button" class="chat-composer-fmt" data-format="link"   title="Link">
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
    </button>
  </div>

  <div class="chat-composer-pending" id="<?= h($pendingId) ?>" hidden></div>

  <textarea
    class="chat-composer-input"
    id="<?= h($inputId) ?>"
    name="content"
    placeholder="<?= h($placeholder) ?>"
    rows="1"
    maxlength="40000"></textarea>

  <div class="chat-composer-actions">
    <button type="button" class="chat-composer-attach" data-action="attach-file" data-file-input-id="<?= h($fileInputId) ?>" title="Attach file">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"></path></svg>
    </button>
    <input type="file" id="<?= h($fileInputId) ?>" data-pending-id="<?= h($pendingId) ?>" hidden multiple>
    <button type="submit" class="chat-composer-send" aria-label="Send">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
    </button>
  </div>
</form>
