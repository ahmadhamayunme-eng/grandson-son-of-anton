<?php
// chat/includes/composer_partial.php — WYSIWYG composer (Phase 10).
//
// Expects in scope:
//   $composerTarget   array with at least one of:
//                      ['channel_id' => int, 'placeholder' => string]
//                      ['conversation_id' => int, 'placeholder' => string]
//                      ['thread' => true, 'placeholder' => string]
//   $composerId       (optional) id on the wrapper form
//   $composerInputId  (optional) id on the contentEditable div
//   $composerPendId   (optional) id on the pending-files container
//   $composerFileId   (optional) id on the hidden file input

$composerId      = $composerId      ?? 'chatComposer';
$composerInputId = $composerInputId ?? 'chatComposerInput';
$composerPendId  = $composerPendId  ?? 'chatComposerPending';
$composerFileId  = $composerFileId  ?? 'chatComposerFileInput';

$dataChannel = isset($composerTarget['channel_id'])      ? ' data-channel-id="' . (int)$composerTarget['channel_id'] . '"' : '';
$dataConv    = isset($composerTarget['conversation_id']) ? ' data-conversation-id="' . (int)$composerTarget['conversation_id'] . '"' : '';
$dataThread  = !empty($composerTarget['thread']) ? ' data-thread="1"' : '';
$placeholder = (string)($composerTarget['placeholder'] ?? 'Message…');
?>
<div class="composer-wrap">
  <form class="composer" id="<?= h($composerId) ?>" autocomplete="off"<?= $dataChannel . $dataConv . $dataThread ?>>
    <div class="composer-reply-quote" id="<?= h($composerId) ?>-reply" hidden>
      <div class="composer-reply-quote-body">
        <span class="composer-reply-author"></span>
        <span class="composer-reply-text"></span>
      </div>
      <button type="button" class="composer-reply-clear" data-action="clear-reply" title="Clear reply">×</button>
    </div>

    <div class="composer-toolbar">
      <button type="button" class="cb" data-format="bold"   title="Bold (Ctrl+B)"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4h6a3.5 3.5 0 010 7H7zM7 11h7a3.5 3.5 0 010 7H7z"/></svg></button>
      <button type="button" class="cb" data-format="italic" title="Italic (Ctrl+I)"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 4h-9M14 20H5M15 4l-6 16"/></svg></button>
      <button type="button" class="cb" data-format="strike" title="Strikethrough"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h16M16 6a4 4 0 00-4-2c-3 0-4 1.5-4 3.5C8 9.5 9.5 10 11 10.5M8 18c1 1 2 2 5 2 3 0 4-2 4-3.5"/></svg></button>
      <span class="cb-sep"></span>
      <button type="button" class="cb" data-format="link"   title="Link"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 007 0l3-3a5 5 0 00-7-7l-1 1"/><path d="M14 11a5 5 0 00-7 0l-3 3a5 5 0 007 7l1-1"/></svg></button>
      <button type="button" class="cb" data-format="ul"     title="Bulleted list"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6h12M9 12h12M9 18h12M4 6h.01M4 12h.01M4 18h.01"/></svg></button>
      <button type="button" class="cb" data-format="ol"     title="Numbered list"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 6h11M10 12h11M10 18h11M4 6h1v4M4 10h2M6 18H4c0-1 2-2 2-3s-1-1-2-1"/></svg></button>
      <button type="button" class="cb" data-format="quote"  title="Quote"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 8h3v3H7v3a3 3 0 003 3M14 8h3v3h-3v3a3 3 0 003 3"/></svg></button>
      <button type="button" class="cb" data-format="code"   title="Code block"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 6l5 6-5 6M8 18l-5-6 5-6"/></svg></button>
    </div>

    <div class="composer-pending" id="<?= h($composerPendId) ?>" hidden></div>

    <div
      class="composer-input"
      id="<?= h($composerInputId) ?>"
      contenteditable="true"
      data-placeholder="<?= h($placeholder) ?>"
      role="textbox"
      aria-multiline="true"></div>

    <div class="composer-bottom">
      <button type="button" class="cb" data-action="attach-file" data-file-input-id="<?= h($composerFileId) ?>" title="Attach file">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.5l-9 9a5 5 0 11-7-7l9-9a3.5 3.5 0 015 5L10 19.5a2 2 0 01-3-3L15 8"/></svg>
      </button>
      <input type="file" id="<?= h($composerFileId) ?>" data-pending-id="<?= h($composerPendId) ?>" hidden multiple>
      <button type="button" class="cb" data-action="insert-mention" title="Mention someone">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M16 12v2a3 3 0 003-3 9 9 0 10-3.5 7"/></svg>
      </button>
      <button type="button" class="cb" data-action="open-emoji-composer" title="Emoji">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9.5h.01M15 9.5h.01"/></svg>
      </button>
      <span class="spacer"></span>
      <button type="button" class="cb" data-action="open-schedule" title="Schedule send">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
      </button>
      <button type="submit" class="send-btn" disabled>
        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        <span>Send</span>
        <span class="kbd">↵</span>
      </button>
    </div>
  </form>
</div>
