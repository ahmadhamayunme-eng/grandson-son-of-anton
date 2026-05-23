// Anton Chat — front-end logic (Phase 10 design migration).
//
// Pulls together every interactive piece of the chat module:
//
//   * WYSIWYG composer (contentEditable + execCommand toolbar)
//   * SSE live updates with JSON-poll fallback
//   * Reactions, threads, @mention autocomplete
//   * File uploads, message edit/delete, message kebab menu
//   * Phase-10 additions: save (bookmark), pin to channel, forward,
//     reply-quote, send-later (scheduled messages), details panel,
//     unified inbox / threads / saved views, 4-state presence
//   * Cmd+K palette, search modal, preferences, theme toggle (theme
//     toggle is wired separately in index.php inline script).
//
// Convention: anything reading the DOM uses the design's class names
// (.msg-group, .msg-body, .side-item, .composer-input, etc.). All API
// calls go through apiPost / apiGet which thread the CSRF token.

(function () {
  'use strict';

  // ===== Boot data =====
  var boot                   = window.CHAT_BOOT || {};
  var CSRF                   = boot.csrf || (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var CURRENT_USER_ID        = boot.currentUserId || 0;
  var CURRENT_USER_NAME      = boot.currentUserName || '';
  var CURRENT_CHANNEL_ID     = boot.currentChannelId || null;
  var CURRENT_CHANNEL_SLUG   = boot.currentChannelSlug || null;
  var CURRENT_CONVERSATION_ID= boot.currentConversationId || null;
  var CURRENT_TAB            = boot.currentTab || 'messages';
  var CURRENT_VIEW           = boot.currentView || '';

  // ===== Small helpers =====
  function $(sel, root)  { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  // Toast helper — uses the global AntonToast sheet primitive from
  // partials/footer.php when available; falls back to native alert() so
  // dev / standalone embeds still surface the message. tone: 'error' |
  // 'success' | undefined.
  function notify(message, tone) {
    if (window.AntonToast) { window.AntonToast(message, tone); return; }
    alert(message);
  }
  // Detect coarse pointer (touch) once. The mobile breakpoint is 768px to
  // match anton.css and chat.css; this matters for the long-press menu
  // and the hover-action toolbar fallback.
  var IS_TOUCH = (window.matchMedia && window.matchMedia('(pointer: coarse)').matches)
              || ('ontouchstart' in window);
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function nl2br(s) { return escapeHtml(s).replace(/\n/g, '<br>'); }
  function stripHtml(s) {
    var tmp = document.createElement('div');
    tmp.innerHTML = String(s || '');
    return (tmp.textContent || tmp.innerText || '').trim();
  }
  function pad2(n) { return n < 10 ? '0' + n : String(n); }
  function formatBytes(bytes) {
    if (bytes < 1024)                return bytes + ' B';
    if (bytes < 1024 * 1024)         return (bytes / 1024).toFixed(1) + ' KB';
    if (bytes < 1024 * 1024 * 1024)  return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' GB';
  }
  function formatHm(iso) {
    // 24-hour HH:MM, to match server-side chat_format_message_time().
    var d = new Date(String(iso).replace(' ', 'T'));
    return pad2(d.getHours()) + ':' + pad2(d.getMinutes());
  }
  function initialsOf(name) {
    var parts = String(name || '?').trim().split(/\s+/);
    var a = (parts[0] || '?').charAt(0).toUpperCase();
    var b = parts.length > 1 ? parts[parts.length - 1].charAt(0).toUpperCase() : '';
    return a + b;
  }
  function dayLabel(iso) {
    var d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d)) return '';
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var ts = d.getTime();
    if (ts >= today.getTime()) return 'Today';
    if (ts >= today.getTime() - 86400000) return 'Yesterday';
    if (ts >= today.getTime() - 6 * 86400000) {
      return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()] + 'day'.substring(0); // unused
    }
    var sameYear = d.getFullYear() === today.getFullYear();
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return sameYear
      ? months[d.getMonth()] + ' ' + d.getDate()
      : months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
  }

  function apiPost(action, file, body) {
    var fd = new FormData();
    Object.keys(body || {}).forEach(function (k) {
      var v = body[k];
      if (Array.isArray(v)) {
        v.forEach(function (item) { fd.append(k + '[]', item); });
      } else if (v !== undefined && v !== null) {
        fd.append(k, v);
      }
    });
    return fetch('/chat/api/' + file + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
      body: fd
    }).then(function (r) {
      return r.json().then(function (d) { return { ok: r.ok, status: r.status, data: d }; }, function () {
        return { ok: false, status: r.status, data: { message: 'Bad response.' } };
      });
    });
  }
  function apiGet(action, file, query) {
    var q = Object.keys(query || {}).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]);
    }).join('&');
    return fetch('/chat/api/' + file + '?action=' + encodeURIComponent(action) + (q ? '&' + q : ''), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (r) {
      return r.json().then(function (d) { return { ok: r.ok, status: r.status, data: d }; }, function () {
        return { ok: false, status: r.status, data: { message: 'Bad response.' } };
      });
    });
  }

  // ===== State =====
  var unreadCounts  = (boot.unreadCounts && typeof boot.unreadCounts === 'object')
                       ? boot.unreadCounts : { channels: {}, dms: {} };
  var mentionCounts = (boot.mentionCounts && typeof boot.mentionCounts === 'object')
                       ? boot.mentionCounts : {};
  var presenceMap   = boot.presenceMap || {};       // { userId: 'active'|'idle'|'offline'|'dnd' }
  var statusMap     = (boot.statusMap && typeof boot.statusMap === 'object')
                       ? boot.statusMap : {};       // { userId: { emoji, text } }
  var userPrefs     = boot.prefs || { enter_to_send: 1, notify_dm: 1, notify_mention: 1, dnd: 0 };
  var enterToSend   = parseInt(userPrefs.enter_to_send, 10) !== 0;

  // Composer scope — set when user clicks reply-quote on a message.
  var replyQuoteState = { msgId: null, author: null, preview: null };

  // ===== Modal plumbing =====
  function openDialog(id) {
    var d = (typeof id === 'string') ? document.getElementById(id) : id;
    if (!d) return;
    if (typeof d.showModal === 'function') { try { d.showModal(); } catch (e) { d.setAttribute('open', ''); } }
    else d.setAttribute('open', '');
    return d;
  }
  function closeDialog(d) {
    if (!d) return;
    if (typeof d === 'string') d = document.getElementById(d);
    if (!d) return;
    if (typeof d.close === 'function') { try { d.close(); } catch (e) { d.removeAttribute('open'); } }
    else d.removeAttribute('open');
  }
  // Backdrop click closes (click target === dialog itself, i.e. outside .cx-modal-form).
  $$('dialog.cx-modal').forEach(function (d) {
    d.addEventListener('click', function (e) {
      if (e.target === d) closeDialog(d);
    });
  });

  // ===== Composer (WYSIWYG contentEditable) =====
  // The composer is .composer-input — a contentEditable div. The submit
  // button lives in the same .composer form. Toolbar buttons carry
  // data-format="..." (bold/italic/strike/link/ul/ol/quote/code).
  //
  // We extract message HTML from the composer on submit, run a quick
  // sanity strip locally (the server re-sanitizes with DOMDocument), then
  // POST as content=... to messages.php.

  var mainComposer       = $('#chatComposer');
  var mainInput          = $('#chatComposerInput');
  var mainReplyBar       = $('#chatComposer-reply');
  var mainReplyAuthor    = mainReplyBar ? mainReplyBar.querySelector('.composer-reply-author') : null;
  var mainReplyTextEl    = mainReplyBar ? mainReplyBar.querySelector('.composer-reply-text')   : null;
  var msgList            = $('#chatMsgs');

  var threadComposer = $('#chatThreadComposer');
  var threadInput    = $('#chatThreadInput');

  function placeholderUpdate(input) {
    if (!input) return;
    // "Logically empty": no text + no images. Strip dangling <br>/<p><br></p>
    // so the CSS :empty::before placeholder fires reliably.
    var text = input.textContent.replace(/​/g, '').trim();
    var hasImg = !!input.querySelector('img');
    var hasReal = !!input.querySelector('a, code, blockquote, ul, ol, table');
    var empty = text === '' && !hasImg && !hasReal;
    if (empty && input.innerHTML !== '') input.innerHTML = '';
    var form = input.closest('.composer');
    if (form) {
      var sendBtn = form.querySelector('.send-btn');
      if (sendBtn) sendBtn.disabled = empty && !form.dataset.hasFiles;
    }
  }

  function extractComposerHtml(input) {
    if (!input) return '';
    // Pull innerHTML, normalise whitespace, drop trailing <br>.
    var html = input.innerHTML
      .replace(/<div><br\s*\/?><\/div>/g, '<br>')        // empty new line block
      .replace(/<div>(.*?)<\/div>/g, '<p>$1</p>')        // contentEditable wraps lines
      .replace(/&nbsp;/g, ' ')
      .replace(/\s+$/g, '');
    // Strip a lone trailing <br>.
    html = html.replace(/(<br\s*\/?>)+$/i, '');
    return html.trim();
  }

  function clearComposer(input) {
    if (!input) return;
    input.innerHTML = '';
    placeholderUpdate(input);
  }

  // -------- contentEditable toolbar --------
  function execFormat(format, input) {
    if (!input) return;
    input.focus();
    try {
      switch (format) {
        case 'bold':   document.execCommand('bold');          break;
        case 'italic': document.execCommand('italic');        break;
        case 'strike': wrapInline(input, 's'); break;  // Browsers emit <strike> via execCommand; wrap as <s> directly so the server-side allowlist keeps it.
        case 'ul':     document.execCommand('insertUnorderedList'); break;
        case 'ol':     document.execCommand('insertOrderedList');   break;
        case 'quote':  document.execCommand('formatBlock', false, 'BLOCKQUOTE'); break;
        case 'code':   wrapInline(input, 'code'); break;
        case 'link':   insertLink(input); break;
      }
    } catch (e) { /* old browsers; ignore */ }
    placeholderUpdate(input);
  }

  function wrapInline(input, tag) {
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return;
    var range = sel.getRangeAt(0);
    if (range.collapsed) {
      // Insert empty tag + put cursor inside.
      var node = document.createElement(tag);
      node.textContent = '​';
      range.insertNode(node);
      range.setStart(node, 1);
      range.setEnd(node, 1);
      sel.removeAllRanges();
      sel.addRange(range);
    } else {
      var contents = range.extractContents();
      var wrap = document.createElement(tag);
      wrap.appendChild(contents);
      range.insertNode(wrap);
      sel.removeAllRanges();
      var r2 = document.createRange();
      r2.selectNodeContents(wrap);
      sel.addRange(r2);
    }
  }

  function insertLink(input) {
    var sel = window.getSelection();
    var url = prompt('Link URL:');
    if (!url) return;
    url = url.trim();
    if (!/^(https?:\/\/|mailto:|\/)/i.test(url)) url = 'https://' + url;
    if (sel && sel.rangeCount && !sel.getRangeAt(0).collapsed) {
      document.execCommand('createLink', false, url);
      // Force target=_blank on the inserted anchor.
      var nodes = input.querySelectorAll('a[href]');
      nodes.forEach(function (a) { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
    } else {
      // No selection — insert as text "label" with link.
      var label = prompt('Link text:', url) || url;
      var anchor = document.createElement('a');
      anchor.href = url;
      anchor.target = '_blank';
      anchor.rel = 'noopener noreferrer';
      anchor.textContent = label;
      var range = (sel && sel.rangeCount) ? sel.getRangeAt(0) : null;
      if (range) {
        range.insertNode(anchor);
        range.setStartAfter(anchor);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
      } else {
        input.appendChild(anchor);
      }
    }
  }

  // Delegate toolbar clicks.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-format]');
    if (!btn) return;
    var form = btn.closest('.composer');
    if (!form) return;
    var input = form.querySelector('.composer-input');
    e.preventDefault();
    execFormat(btn.getAttribute('data-format'), input);
  });

  // Composer input event — keep send button state + close mention popup.
  function attachComposerInput(input) {
    if (!input) return;
    placeholderUpdate(input);
    input.addEventListener('input', function () {
      placeholderUpdate(input);
      handleMentionInput(input);
    });
    input.addEventListener('focus', function () { placeholderUpdate(input); });
    input.addEventListener('keydown', function (e) {
      // Mention popup steals certain keys.
      if (mentionPopup && !mentionPopup.hidden && mentionItems.length && mentionAnchorInput === input) {
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          mentionActiveIndex = (mentionActiveIndex + 1) % mentionItems.length;
          renderMentionPopup();
          return;
        }
        if (e.key === 'ArrowUp') {
          e.preventDefault();
          mentionActiveIndex = (mentionActiveIndex - 1 + mentionItems.length) % mentionItems.length;
          renderMentionPopup();
          return;
        }
        if (e.key === 'Enter' || e.key === 'Tab') {
          e.preventDefault();
          e.stopPropagation();
          insertMention(input, mentionItems[mentionActiveIndex]);
          return;
        }
        if (e.key === 'Escape') { e.preventDefault(); hideMentionPopup(); return; }
      }

      // Format shortcuts.
      if (e.ctrlKey || e.metaKey) {
        var k = (e.key || '').toLowerCase();
        if (k === 'b') { e.preventDefault(); execFormat('bold',   input); return; }
        if (k === 'i') { e.preventDefault(); execFormat('italic', input); return; }
      }

      // Enter-to-send behaviour.
      if (e.key !== 'Enter') return;
      var form = input.closest('.composer');
      if (!form) return;
      if (enterToSend) {
        if (e.shiftKey) return;             // newline
        e.preventDefault();
        form.dispatchEvent(new Event('submit', { cancelable: true }));
      } else {
        if (!(e.metaKey || e.ctrlKey)) return;
        e.preventDefault();
        form.dispatchEvent(new Event('submit', { cancelable: true }));
      }
    });
  }
  attachComposerInput(mainInput);
  attachComposerInput(threadInput);

  // -------- send (main + thread + reply) --------
  function sendFromForm(form) {
    if (!form) return;
    var input    = form.querySelector('.composer-input');
    var content  = extractComposerHtml(input);
    var pendingId= form.querySelector('.composer-pending') ? form.querySelector('.composer-pending').id : '';
    var fileIds  = pendingId ? pendingFilesFor(pendingId) : [];
    if (!content && fileIds.length === 0) return;

    var body = { content: content };

    // Target: thread? channel? DM?
    var isThread = form.getAttribute('data-thread') === '1';
    if (isThread) {
      // Thread reply — parent set by openThread().
      var pmId = parseInt(form.getAttribute('data-parent-message-id') || '0', 10);
      if (!pmId) return;
      body.parent_message_id = pmId;
    } else if (form.getAttribute('data-channel-id')) {
      body.channel_id = form.getAttribute('data-channel-id');
    } else if (form.getAttribute('data-conversation-id')) {
      body.conversation_id = form.getAttribute('data-conversation-id');
    } else {
      return;
    }

    if (fileIds.length) body.file_ids = fileIds;
    if (replyQuoteState.msgId && !isThread) body.reply_to_message_id = replyQuoteState.msgId;

    var sendBtn = form.querySelector('.send-btn');
    if (sendBtn) sendBtn.disabled = true;

    apiPost('send', 'messages.php', body)
      .then(function (res) {
        if (sendBtn) sendBtn.disabled = false;
        if (!res.ok) {
          notify(res.data && res.data.message ? res.data.message : 'Failed to send.', 'error');
          return;
        }
        var msg = res.data.message;
        if (isThread) {
          appendThreadMessage(msg);
          bumpReplyCount(parseInt(form.getAttribute('data-parent-message-id'), 10));
        } else {
          appendMainMessage(msg);
          clearReplyQuote();
        }
        clearComposer(input);
        if (pendingId) clearPending(pendingId);
        input.focus();
      })
      .catch(function () {
        if (sendBtn) sendBtn.disabled = false;
        notify('Network error — message not sent.', 'error');
      });
  }

  if (mainComposer) {
    mainComposer.addEventListener('submit', function (e) {
      e.preventDefault();
      sendFromForm(mainComposer);
    });
  }
  if (threadComposer) {
    threadComposer.addEventListener('submit', function (e) {
      e.preventDefault();
      sendFromForm(threadComposer);
    });
  }

  // ===== Reply-quote =====
  function startReplyQuote(msgId, authorName) {
    if (!mainComposer || !mainReplyBar) return;
    var msgEl = document.querySelector('#chatMsgs .msg-group[data-msg-id="' + msgId + '"]');
    var body = msgEl ? msgEl.querySelector('.msg-body') : null;
    var preview = body ? stripHtml(body.innerHTML).slice(0, 160) : '';
    replyQuoteState = { msgId: msgId, author: authorName, preview: preview };
    if (mainReplyAuthor) mainReplyAuthor.textContent = (authorName || '') + ':';
    if (mainReplyTextEl) mainReplyTextEl.textContent = preview || '(message)';
    mainReplyBar.hidden = false;
    if (mainInput) mainInput.focus();
  }
  function clearReplyQuote() {
    replyQuoteState = { msgId: null, author: null, preview: null };
    if (mainReplyBar) mainReplyBar.hidden = true;
  }

  // ===== Message rendering (DOM build) =====
  function buildMessageHtml(msg, grouped, opts) {
    opts = opts || {};
    var ts = Math.floor(new Date(String(msg.created_at).replace(' ', 'T')).getTime() / 1000);
    var deleted = msg.deleted_at != null;
    var edited  = msg.edited_at  != null;
    var reactions = msg.reactions || [];
    var replyCt   = parseInt(msg.reply_count, 10) || 0;
    var files     = msg.files || [];
    var isSaved   = !!msg.is_saved;
    var isPinned  = !!msg.is_pinned;
    var inChannel = msg.channel_id != null;

    var avHtml;
    if (grouped) {
      avHtml = '<span class="av-time">' + escapeHtml(formatHm(msg.created_at)) + '</span>';
    } else {
      var initials = initialsOf(msg.author_name);
      var presence = presenceMap[msg.user_id] || 'offline';
      var avColor  = (parseInt(msg.user_id, 10) || 0) % 8;
      avHtml = '<span class="av" data-user-id="' + msg.user_id + '" data-av-color="' + avColor + '" data-initials="' + escapeHtml(initials) + '">'
             +   escapeHtml(initials)
             +   '<span class="presence dot-presence presence-' + presence + '" data-user-id="' + msg.user_id + '"></span>'
             + '</span>';
    }

    var metaHtml = '';
    if (!grouped) {
      metaHtml = '<div class="msg-meta">'
               +   '<span class="msg-author">' + escapeHtml(msg.author_name) + '</span>'
               +   '<span class="msg-status-emoji" data-status-user-id="' + msg.user_id + '" hidden></span>'
               +   '<span class="msg-time">'   + escapeHtml(formatHm(msg.created_at)) + '</span>'
               + '</div>';
    }

    // Reply-to quote (inline, above body).
    var replyToHtml = '';
    if (msg.reply_to && typeof msg.reply_to === 'object') {
      var rt = msg.reply_to;
      var rtText = rt.deleted ? '<em>this message was deleted</em>' : stripHtml(rt.content || '').slice(0, 160);
      replyToHtml = '<div class="reply-quote">'
                  +   '<span class="ra">' + escapeHtml(rt.author_name || '') + ':</span>'
                  +   '<span class="rb">' + (rt.deleted ? rtText : escapeHtml(rtText)) + '</span>'
                  + '</div>';
    }

    // Content.
    var bodyHtml = '';
    if (deleted) {
      bodyHtml = '<div class="msg-body msg-deleted"><em>This message was deleted.</em></div>';
    } else {
      var inner = msg.content_html || nl2br(msg.content || '');
      if (inner !== '' && inner !== '<br>') {
        if (edited) inner += ' <span class="msg-edited">(edited)</span>';
        bodyHtml = '<div class="msg-body" data-raw-content="' + escapeHtml(msg.content || '') + '">' + inner + '</div>';
      }
    }

    // Files.
    var filesHtml = '';
    if (!deleted && files.length) {
      filesHtml = '<div class="msg-files">' + files.map(function (f) {
        var fname = escapeHtml(f.name);
        if (f.is_image) {
          return '<a class="msg-file msg-file-image" href="/chat/api/file.php?id=' + f.id
               + '" target="_blank" rel="noopener noreferrer" title="' + fname + '">'
               +   '<img src="/chat/api/file.php?id=' + f.id + '" alt="' + fname + '" loading="lazy">'
               + '</a>';
        }
        var size = parseInt(f.size, 10) || 0;
        return '<a class="msg-file msg-file-other" href="/chat/api/file.php?id=' + f.id
             + '" target="_blank" rel="noopener noreferrer" download="' + fname + '">'
             +   '<span class="msg-file-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>'
             +   '<span class="msg-file-info">'
             +     '<span class="msg-file-name">' + fname + '</span>'
             +     '<span class="msg-file-size">' + escapeHtml(formatBytes(size)) + '</span>'
             +   '</span>'
             + '</a>';
      }).join('') + '</div>';
    }

    // Reactions.
    var reactionsHtml = '';
    if (reactions.length) {
      reactionsHtml = '<div class="reactions" data-msg-id="' + msg.id + '">' +
        reactions.map(function (rx) {
          var idsRaw = String(rx.user_ids || '');
          var idsArr = idsRaw ? idsRaw.split(',').map(function (x) { return parseInt(x, 10); }) : [];
          var me = idsArr.indexOf(CURRENT_USER_ID) !== -1;
          return '<button type="button" class="rx' + (me ? ' mine' : '') + '"'
               + ' data-action="toggle-reaction" data-msg-id="' + msg.id
               + '" data-emoji="' + escapeHtml(rx.emoji) + '">'
               +   '<span class="glyph">' + escapeHtml(rx.emoji) + '</span>'
               +   '<span>' + (parseInt(rx.count, 10) || 0) + '</span>'
               + '</button>';
        }).join('') +
        '<button class="rx-add" data-action="add-reaction" data-msg-id="' + msg.id + '" title="Add reaction">' +
          '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9.5h.01M15 9.5h.01"/></svg>' +
        '</button>' +
      '</div>';
    }

    // Reply preview ("View thread").
    var replyPreviewHtml = '';
    if (replyCt > 0) {
      replyPreviewHtml = '<button type="button" class="thread-preview" data-action="open-thread" data-msg-id="' + msg.id + '">'
                       +   '<span class="count">' + replyCt + ' repl' + (replyCt === 1 ? 'y' : 'ies') + '</span>'
                       +   '<span class="last">View thread</span>'
                       + '</button>';
    }

    // Hover toolbar.
    var actionsHtml = '';
    if (!deleted) {
      actionsHtml = '<div class="msg-actions">'
        +   '<button type="button" data-action="add-reaction" data-msg-id="' + msg.id + '" title="React">'
        +     '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9.5h.01M15 9.5h.01"/></svg>'
        +   '</button>'
        +   '<button type="button" class="accent" data-action="open-thread" data-msg-id="' + msg.id + '" title="Reply in thread">'
        +     '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 17l-5-5 5-5"/><path d="M4 12h11a5 5 0 015 5v3"/></svg>'
        +   '</button>'
        +   '<button type="button" data-action="open-forward" data-msg-id="' + msg.id + '" title="Forward">'
        +     '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>'
        +   '</button>'
        +   '<button type="button" data-action="toggle-save" data-msg-id="' + msg.id + '" title="' + (isSaved ? 'Remove from Saved' : 'Save for later') + '" class="' + (isSaved ? 'active' : '') + '">'
        +     '<svg viewBox="0 0 24 24" width="15" height="15" fill="' + (isSaved ? 'currentColor' : 'none') + '" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v18l-6-4-6 4z"/></svg>'
        +   '</button>'
        + (inChannel
            ? ('<button type="button" data-action="toggle-pin" data-msg-id="' + msg.id + '" title="' + (isPinned ? 'Unpin' : 'Pin') + '" class="' + (isPinned ? 'active' : '') + '">'
            +   '<svg viewBox="0 0 24 24" width="15" height="15" fill="' + (isPinned ? 'currentColor' : 'none') + '" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2l7 7-3 1-5 5-1 5-4-4-6 6 6-6-4-4 5-1 5-5z"/></svg>'
            + '</button>')
            : '')
        +   '<button type="button" data-action="reply-quote" data-msg-id="' + msg.id + '" data-author="' + escapeHtml(msg.author_name) + '" title="Reply to this message">'
        +     '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 8h3v3H7v3a3 3 0 003 3M14 8h3v3h-3v3a3 3 0 003 3"/></svg>'
        +   '</button>'
        +   '<button type="button" data-action="open-convert-task" data-msg-id="' + msg.id + '" title="Create task from this message">'
        +     '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>'
        +   '</button>'
        + (msg.user_id === CURRENT_USER_ID
            ? ('<button type="button" data-action="open-msg-menu" data-msg-id="' + msg.id + '" title="More">'
            +   '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><circle cx="5" cy="12" r="1" fill="currentColor"/><circle cx="12" cy="12" r="1" fill="currentColor"/><circle cx="19" cy="12" r="1" fill="currentColor"/></svg>'
            + '</button>')
            : '')
        + '</div>';
    }

    var groupCls = 'msg-group' + (isPinned ? ' is-pinned' : '');
    var rowCls   = 'msg-row'   + (grouped  ? ' compact'   : '');

    return '<div class="' + groupCls + '" data-msg-id="' + msg.id + '" data-user-id="' + msg.user_id + '" data-msg-ts="' + ts + '">'
         +   '<div class="' + rowCls + '">'
         +     '<div class="av-slot">' + avHtml + '</div>'
         +     '<div class="msg-col">'
         +       metaHtml
         +       replyToHtml
         +       bodyHtml
         +       filesHtml
         +       reactionsHtml
         +       replyPreviewHtml
         +     '</div>'
         +   '</div>'
         +   actionsHtml
         + '</div>';
  }

  function buildMessageEl(msg, grouped) {
    var tmpl = document.createElement('template');
    tmpl.innerHTML = buildMessageHtml(msg, grouped);
    return tmpl.content.firstElementChild;
  }

  function scrollMsgsToBottom() {
    if (!msgList) return;
    msgList.scrollTop = msgList.scrollHeight;
  }
  if (msgList) scrollMsgsToBottom();
  // Auto-focus the composer on desktop only — on mobile that would pop the
  // keyboard on every page visit, covering the messages the user actually
  // wanted to read.
  if (mainInput && !IS_TOUCH) requestAnimationFrame(function () { mainInput.focus(); });

  function isNearBottom(el, threshold) {
    if (!el) return true;
    return el.scrollHeight - el.scrollTop - el.clientHeight < (threshold || 40);
  }
  function lastMsgInfo() {
    if (!msgList) return null;
    var groups = msgList.querySelectorAll('.msg-group');
    if (!groups.length) return null;
    var last = groups[groups.length - 1];
    return {
      userId: parseInt(last.getAttribute('data-user-id'), 10),
      ts:     parseInt(last.getAttribute('data-msg-ts'), 10)
    };
  }
  function appendMainMessage(msg) {
    if (!msgList) return;
    var empty = msgList.querySelector('.msgs-empty, .cx-empty');
    if (empty) empty.remove();
    var prev = lastMsgInfo();
    var msgTs = Math.floor(new Date(String(msg.created_at).replace(' ', 'T')).getTime() / 1000);
    var grouped = !!(prev && prev.userId === msg.user_id && (msgTs - prev.ts) < 300);
    var wasAtBottom = isNearBottom(msgList, 60);
    msgList.appendChild(buildMessageEl(msg, grouped));
    applyStatusEmojis();
    if (wasAtBottom) scrollMsgsToBottom();
  }

  // ===== Live updates: SSE + JSON-poll fallback =====
  var sse = null;
  var pollTimer = null;
  var sseFailures = 0;
  var sseOpenedAt = 0;
  var lastEventId = parseInt(boot.lastEventId || 0, 10);
  var modeIsPolling = false;

  function startSse() {
    if (modeIsPolling) return;
    try {
      sse = new EventSource('/chat/api/events.php?since=' + lastEventId);
    } catch (e) {
      switchToPolling();
      return;
    }
    sseOpenedAt = Date.now();
    sse.addEventListener('open',  function () { sseFailures = 0; });
    sse.addEventListener('error', function () {
      var openMs = Date.now() - sseOpenedAt;
      if (openMs > 5000) { sseFailures = 0; return; }
      sseFailures++;
      if (sseFailures >= 3) {
        try { sse.close(); } catch (e) {}
        switchToPolling();
      }
    });

    ['message', 'channel_created', 'channel_member_added', 'channel_member_removed',
     'message_edited', 'message_deleted', 'reaction_added', 'reaction_removed',
     'message_pinned', 'message_unpinned'].forEach(function (n) {
      sse.addEventListener(n, handleEvent);
    });
  }
  function switchToPolling() {
    if (modeIsPolling) return;
    modeIsPolling = true;
    pollTimer = setInterval(pollOnce, 3000);
    pollOnce();
  }
  function pollOnce() {
    fetch('/chat/api/events.php?poll=1&since=' + lastEventId, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    }).then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !Array.isArray(data.events)) return;
        data.events.forEach(function (ev) { handleEvent(ev); });
        if (typeof data.last_event_id === 'number') {
          lastEventId = Math.max(lastEventId, data.last_event_id);
        }
      })
      .catch(function () { /* next tick */ });
  }

  function handleEvent(evt) {
    var ev;
    if (evt && typeof evt.data === 'string') {
      try { ev = JSON.parse(evt.data); } catch (e) { return; }
    } else {
      ev = evt;
    }
    if (!ev || !ev.event_type) return;
    if (ev.event_id) lastEventId = Math.max(lastEventId, parseInt(ev.event_id, 10));

    switch (ev.event_type) {
      case 'message':
        onMessageEvent(ev);
        break;
      case 'reaction_added':
      case 'reaction_removed':
        if (ev.message_id && ev.payload && Array.isArray(ev.payload.reactions)) {
          updateMessageReactions(parseInt(ev.message_id, 10), ev.payload.reactions);
        }
        break;
      case 'message_edited':
        if (ev.message_id) {
          applyEditedMessage(parseInt(ev.message_id, 10), {
            content:      ev.message_content      || '',
            content_html: ev.message_content_html || ''
          });
        }
        break;
      case 'message_deleted':
        if (ev.message_id) applyDeletedMessage(parseInt(ev.message_id, 10));
        break;
      case 'message_pinned':
        applyPinState(parseInt(ev.message_id, 10), true);
        break;
      case 'message_unpinned':
        applyPinState(parseInt(ev.message_id, 10), false);
        break;
      default:
        break;
    }
  }

  function sseEventToMsg(ev) {
    return {
      id:             ev.message_id,
      user_id:        parseInt(ev.message_user_id, 10),
      author_name:    ev.author_name,
      content:        ev.message_content      || '',
      content_html:   ev.message_content_html || '',
      created_at:     ev.message_created_at,
      edited_at:      ev.message_edited_at,
      deleted_at:     ev.message_deleted_at,
      parent_message_id:   ev.message_parent_id || null,
      reply_to_message_id: ev.message_reply_to_id || null,
      channel_id:     ev.channel_id      != null ? parseInt(ev.channel_id, 10)      : null,
      conversation_id:ev.conversation_id != null ? parseInt(ev.conversation_id, 10) : null,
      reactions:      [],
      reply_count:    0,
      is_saved:       0,
      is_pinned:      0,
      reply_to:       null,
      files: Array.isArray(ev.message_files) ? ev.message_files
            : (ev.payload && Array.isArray(ev.payload.files) ? ev.payload.files : [])
    };
  }

  function onMessageEvent(ev) {
    var parentId = (ev.payload && ev.payload.parent_message_id)
      ? parseInt(ev.payload.parent_message_id, 10) : null;

    if (!parentId) maybeNotify(ev);

    if (parentId) { onThreadReplyEvent(ev, parentId); return; }

    var eventChannelId = ev.channel_id      != null ? parseInt(ev.channel_id, 10)      : null;
    var eventConvId    = ev.conversation_id != null ? parseInt(ev.conversation_id, 10) : null;
    var msgUserId      = parseInt(ev.message_user_id, 10);

    var matchesChannel = CURRENT_CHANNEL_ID      && eventChannelId === CURRENT_CHANNEL_ID;
    var matchesDm      = CURRENT_CONVERSATION_ID && eventConvId    === CURRENT_CONVERSATION_ID;
    var inThisView     = matchesChannel || matchesDm;

    if (msgUserId !== CURRENT_USER_ID) {
      if (inThisView && !document.hidden) {
        markReadOnServer(matchesChannel
          ? { channel_id: CURRENT_CHANNEL_ID }
          : { conversation_id: CURRENT_CONVERSATION_ID });
      } else {
        if (eventChannelId) bumpChannelUnread(eventChannelId);
        else if (eventConvId) bumpDmUnread(eventConvId);
      }
    }

    if (!msgList || !inThisView || CURRENT_TAB !== 'messages') return;
    if (msgList.querySelector('[data-msg-id="' + ev.message_id + '"]')) return;

    var msg = sseEventToMsg(ev);
    appendMainMessage(msg);
  }

  startSse();

  // ===== Reactions =====
  var emojiPicker = null;
  var emojiTargetMsgId = null;

  function openEmojiPickerForMsg(button, msgId) {
    emojiTargetMsgId = msgId;
    openEmojiPicker(button, function (emoji) {
      if (!emoji || !emojiTargetMsgId) return;
      toggleReaction(emojiTargetMsgId, emoji);
      closeEmojiPicker();
    });
  }

  function openEmojiPicker(anchor, onPick) {
    var popup = $('#chatEmojiPopup');
    if (!popup) return;
    if (!emojiPicker) {
      emojiPicker = document.createElement('emoji-picker');
      popup.appendChild(emojiPicker);
    }
    emojiPicker.onemojiClick = null;
    // emoji-picker-element fires "emoji-click".
    emojiPicker.addEventListener('emoji-click', onClick);
    function onClick(e) {
      var em = e && e.detail && e.detail.unicode;
      emojiPicker.removeEventListener('emoji-click', onClick);
      if (typeof onPick === 'function') onPick(em);
    }
    popup.hidden = false;
    var rect = anchor.getBoundingClientRect();
    var ph = popup.offsetHeight || 360;
    var top = rect.top - ph - 6;
    if (top < 8) top = rect.bottom + 6;
    popup.style.top  = Math.max(8, top) + 'px';
    popup.style.left = Math.max(8, Math.min(window.innerWidth - 332, rect.left)) + 'px';
  }
  function closeEmojiPicker() {
    var popup = $('#chatEmojiPopup');
    if (popup) popup.hidden = true;
    emojiTargetMsgId = null;
  }

  function toggleReaction(msgId, emoji) {
    if (!msgId || !emoji) return;
    apiPost('toggle', 'reactions.php', { message_id: msgId, emoji: emoji })
      .then(function (res) {
        if (!res.ok) return;
        updateMessageReactions(msgId, res.data.reactions || []);
      });
  }
  function updateMessageReactions(msgId, reactions) {
    document.querySelectorAll('.msg-group[data-msg-id="' + msgId + '"]').forEach(function (el) {
      var col = el.querySelector('.msg-col');
      if (!col) return;
      var existing = col.querySelector('.reactions');

      if (!reactions || !reactions.length) {
        if (existing) existing.remove();
        return;
      }
      var html = reactions.map(function (rx) {
        var idsRaw = String(rx.user_ids || '');
        var idsArr = idsRaw ? idsRaw.split(',').map(function (x) { return parseInt(x, 10); }) : [];
        var me = idsArr.indexOf(CURRENT_USER_ID) !== -1;
        return '<button type="button" class="rx' + (me ? ' mine' : '') + '"'
             + ' data-action="toggle-reaction" data-msg-id="' + msgId
             + '" data-emoji="' + escapeHtml(rx.emoji) + '">'
             +   '<span class="glyph">' + escapeHtml(rx.emoji) + '</span>'
             +   '<span>' + (parseInt(rx.count, 10) || 0) + '</span>'
             + '</button>';
      }).join('') +
        '<button class="rx-add" data-action="add-reaction" data-msg-id="' + msgId + '" title="Add reaction">' +
          '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9.5h.01M15 9.5h.01"/></svg>' +
        '</button>';
      if (existing) {
        existing.innerHTML = html;
      } else {
        var div = document.createElement('div');
        div.className = 'reactions';
        div.setAttribute('data-msg-id', String(msgId));
        div.innerHTML = html;
        var pre = col.querySelector('.thread-preview');
        if (pre) col.insertBefore(div, pre);
        else col.appendChild(div);
      }
    });
  }

  // ===== Threads =====
  var threadParentId = null;
  var threadPanel    = $('#chatThread');
  var threadList     = $('#chatThreadList');

  function openThread(parentId) {
    if (!threadPanel || !threadList) return;
    // Thread and details share the right column — close details when thread opens.
    if (cxApp && cxApp.classList.contains('with-details')) cxApp.classList.remove('with-details');
    threadParentId = parentId;
    threadPanel.hidden = false;
    if (cxApp) cxApp.classList.add('with-thread');
    threadList.innerHTML = '<div class="cx-modal-loading">Loading…</div>';
    apiGet('list', 'messages.php', { parent_message_id: parentId })
      .then(function (res) {
        if (!res.ok) {
          threadList.innerHTML = '<div class="cx-modal-loading">Could not load thread.</div>';
          return;
        }
        var parentEl = document.querySelector('#chatMsgs .msg-group[data-msg-id="' + parentId + '"]');
        var parentHtml = parentEl ? parentEl.outerHTML : '<div class="cx-modal-loading">Original message unavailable.</div>';
        var replies = res.data.messages || [];
        var dividerLabel = replies.length === 0
          ? 'No replies yet'
          : (replies.length + ' repl' + (replies.length === 1 ? 'y' : 'ies'));
        var dividerHtml = '<div class="cx-thread-divider"><span>' + escapeHtml(dividerLabel) + '</span></div>';
        var repliesHtml = replies.map(function (m) { return buildMessageHtml(m, false); }).join('');
        threadList.innerHTML = parentHtml + dividerHtml + repliesHtml;
        threadList.scrollTop = threadList.scrollHeight;
        if (threadComposer) threadComposer.setAttribute('data-parent-message-id', String(parentId));
        if (threadInput) requestAnimationFrame(function () { threadInput.focus(); });
      })
      .catch(function () {
        threadList.innerHTML = '<div class="cx-modal-loading">Network error.</div>';
      });
  }
  function closeThread() {
    threadParentId = null;
    if (threadPanel) threadPanel.hidden = true;
    if (cxApp) cxApp.classList.remove('with-thread');
    if (threadInput) { threadInput.innerHTML = ''; placeholderUpdate(threadInput); }
  }
  function appendThreadMessage(msg) {
    if (!threadList) return;
    if (threadList.querySelector('.msg-group[data-msg-id="' + msg.id + '"]')) return;
    var divider = threadList.querySelector('.cx-thread-divider');
    if (divider && /No replies yet/.test(divider.textContent)) {
      divider.innerHTML = '<span>1 reply</span>';
    }
    threadList.insertAdjacentHTML('beforeend', buildMessageHtml(msg, false));
    threadList.scrollTop = threadList.scrollHeight;
  }
  function onThreadReplyEvent(ev, parentId) {
    if (threadParentId === parentId && threadList) {
      var msg = sseEventToMsg(ev);
      appendThreadMessage(msg);
    }
    bumpReplyCount(parentId);
  }
  function bumpReplyCount(parentId) {
    var parentEl = document.querySelector('#chatMsgs .msg-group[data-msg-id="' + parentId + '"]');
    if (!parentEl) return;
    var col = parentEl.querySelector('.msg-col');
    if (!col) return;
    var btn = col.querySelector('.thread-preview');
    if (btn) {
      var cntEl = btn.querySelector('.count');
      var n = cntEl ? (parseInt(cntEl.textContent, 10) || 0) + 1 : 1;
      if (cntEl) cntEl.textContent = n + ' repl' + (n === 1 ? 'y' : 'ies');
    } else {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'thread-preview';
      b.setAttribute('data-action', 'open-thread');
      b.setAttribute('data-msg-id', String(parentId));
      b.innerHTML = '<span class="count">1 reply</span><span class="last">View thread</span>';
      col.appendChild(b);
    }
  }

  // ===== @mention autocomplete (works on contentEditable + textarea) =====
  var mentionPopup       = $('#chatMentionPopup');
  var mentionAnchorInput = null;
  var mentionItems       = [];
  var mentionActiveIndex = 0;
  var mentionPeopleCache = null;
  var mentionTriggerRange= null;   // a Range that selects the "@xxx" being typed

  function loadMentionPeople(cb) {
    if (mentionPeopleCache) { cb(mentionPeopleCache); return; }
    apiGet('people', 'dms.php', {}).then(function (res) {
      mentionPeopleCache = (res && res.ok && res.data && res.data.people) ? res.data.people : [];
      cb(mentionPeopleCache);
    });
  }

  function specialSub(s) {
    if (s === 'channel')  return 'Everyone in this channel';
    if (s === 'here')     return 'Online members';
    if (s === 'everyone') return 'Everyone in the workspace';
    return '';
  }

  // Determine if the caret in a contentEditable is inside an "@xxx" trigger;
  // if so return { query, range } where range covers "@xxx" so we can replace it.
  function detectMentionTriggerCE(input) {
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return null;
    var range = sel.getRangeAt(0);
    if (!input.contains(range.startContainer)) return null;
    if (!range.collapsed) return null;
    var node = range.startContainer;
    if (node.nodeType !== 3) return null;          // text nodes only
    var text = node.textContent.slice(0, range.startOffset);
    var m = /(^|[^a-z0-9_])@([a-z0-9_-]*)$/i.exec(text);
    if (!m) return null;
    var triggerStart = m.index + m[1].length;       // position of "@"
    var newRange = document.createRange();
    newRange.setStart(node, triggerStart);
    newRange.setEnd(node, range.startOffset);
    return { query: m[2].toLowerCase(), range: newRange };
  }

  function handleMentionInput(input) {
    var trigger = detectMentionTriggerCE(input);
    if (!trigger) { hideMentionPopup(); return; }
    showMentionPopup(input, trigger.query, trigger.range);
  }

  function showMentionPopup(input, query, range) {
    if (!mentionPopup) return;
    mentionAnchorInput = input;
    mentionTriggerRange = range;
    loadMentionPeople(function (people) {
      var items = [];
      ['channel', 'here', 'everyone'].forEach(function (sp) {
        if (sp.indexOf(query) === 0) {
          items.push({ id: 'sp:' + sp, name: '@' + sp, sub: specialSub(sp), insert: sp, special: true });
        }
      });
      people.forEach(function (p) {
        var first = String(p.name || '').split(/\s+/)[0].toLowerCase();
        if (first && first.indexOf(query) === 0) {
          items.push({ id: p.id, name: p.name, sub: '@' + first, insert: first, special: false });
        }
      });
      mentionItems = items.slice(0, 8);
      mentionActiveIndex = 0;
      if (!mentionItems.length) { hideMentionPopup(); return; }
      renderMentionPopup();
      positionMentionPopup(range);
    });
  }
  function renderMentionPopup() {
    if (!mentionPopup) return;
    mentionPopup.innerHTML = mentionItems.map(function (item, i) {
      var aClass = item.special ? 'cx-mention-item-avatar cx-mention-item-avatar-special' : 'cx-mention-item-avatar';
      var aText  = item.special ? '@' : initialsOf(item.name.replace(/^@/, ''));
      return '<div class="cx-mention-item' + (i === mentionActiveIndex ? ' active' : '') + '" data-mention-index="' + i + '">'
           +   '<span class="' + aClass + '">' + escapeHtml(aText) + '</span>'
           +   '<span class="cx-mention-item-name">' + escapeHtml(item.name) + '</span>'
           +   '<span class="cx-mention-item-sub">' + escapeHtml(item.sub || '') + '</span>'
           + '</div>';
    }).join('');
    mentionPopup.hidden = false;
  }
  function positionMentionPopup(range) {
    if (!mentionPopup || !range) return;
    var rect = range.getBoundingClientRect();
    if (!rect || (rect.width === 0 && rect.height === 0)) {
      // Range had no glyph (start of new line); fall back to input rect.
      if (mentionAnchorInput) rect = mentionAnchorInput.getBoundingClientRect();
    }
    requestAnimationFrame(function () {
      var ph = mentionPopup.offsetHeight;
      mentionPopup.style.top  = Math.max(8, rect.top - ph - 6) + 'px';
      mentionPopup.style.left = Math.max(8, rect.left) + 'px';
    });
  }
  function hideMentionPopup() {
    if (mentionPopup) mentionPopup.hidden = true;
    mentionAnchorInput = null;
    mentionItems = [];
    mentionTriggerRange = null;
  }
  function insertMention(input, item) {
    if (!input || !item) return;
    if (mentionTriggerRange) {
      // Replace "@xxx" with the mention text + trailing space.
      var range = mentionTriggerRange;
      range.deleteContents();
      var textNode = document.createTextNode('@' + item.insert + ' ');
      range.insertNode(textNode);
      // Move caret to end of inserted node.
      var sel = window.getSelection();
      sel.removeAllRanges();
      var r2 = document.createRange();
      r2.setStart(textNode, textNode.length);
      r2.setEnd(textNode, textNode.length);
      sel.addRange(r2);
    } else {
      // Fallback: just append (shouldn't usually happen).
      input.appendChild(document.createTextNode('@' + item.insert + ' '));
    }
    hideMentionPopup();
    placeholderUpdate(input);
  }

  if (mentionPopup) {
    mentionPopup.addEventListener('mousedown', function (e) {
      var t = e.target.closest('[data-mention-index]');
      if (!t || !mentionAnchorInput) return;
      e.preventDefault();
      var idx = parseInt(t.getAttribute('data-mention-index'), 10);
      if (idx >= 0 && idx < mentionItems.length) {
        var anchor = mentionAnchorInput;
        insertMention(anchor, mentionItems[idx]);
        anchor.focus();
      }
    });
  }

  // ===== File uploads =====
  var pendingFilesByPendingId = {};
  function pendingFilesFor(pendingId) {
    if (!pendingFilesByPendingId[pendingId]) pendingFilesByPendingId[pendingId] = [];
    return pendingFilesByPendingId[pendingId];
  }
  function clearPending(pendingId) {
    var el = document.getElementById(pendingId);
    if (el) { el.innerHTML = ''; el.hidden = true; }
    pendingFilesByPendingId[pendingId] = [];
    var form = el ? el.closest('.composer') : null;
    if (form) { delete form.dataset.hasFiles; placeholderUpdate(form.querySelector('.composer-input')); }
  }
  function uploadFile(file, pendingId) {
    var pendingEl = document.getElementById(pendingId);
    if (!pendingEl) return;
    pendingEl.hidden = false;
    var form = pendingEl.closest('.composer');
    if (form) { form.dataset.hasFiles = '1'; placeholderUpdate(form.querySelector('.composer-input')); }

    var tempId = 'tmp-' + Math.random().toString(36).slice(2);
    var isImage = file.type && file.type.indexOf('image/') === 0;
    var thumbHtml = isImage
      ? '<img src="' + URL.createObjectURL(file) + '" alt="">'
      : '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
    var totalLabel = formatBytes(file.size || 0);

    pendingEl.insertAdjacentHTML('beforeend',
      '<div class="pending-file pending-file-uploading" data-tmp-id="' + tempId + '">' +
        '<span class="pending-file-thumb">' + thumbHtml + '</span>' +
        '<span class="pending-file-info">' +
          '<span class="pending-file-name">' + escapeHtml(file.name) + '</span>' +
          '<span class="pending-file-meta">0 B / ' + escapeHtml(totalLabel) + ' (0%)</span>' +
          '<span class="pending-file-bar"><span class="pending-file-bar-fill" style="width:0%"></span></span>' +
        '</span>' +
        '<button type="button" class="pending-file-remove" data-action="remove-pending-file" data-tmp-id="' + tempId + '" data-pending-id="' + pendingId + '">×</button>' +
      '</div>');

    // Real upload progress requires XMLHttpRequest — fetch() can't expose
    // upload.onprogress events. We keep the rest of the API contract identical
    // (X-CSRF-Token header, multipart with one "file" field, JSON response).
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/chat/api/upload.php', true);
    xhr.setRequestHeader('X-CSRF-Token', CSRF);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.withCredentials = true;

    function pill() { return pendingEl.querySelector('[data-tmp-id="' + tempId + '"]'); }
    function setMeta(text) {
      var p = pill(); if (!p) return;
      var meta = p.querySelector('.pending-file-meta');
      if (meta) meta.textContent = text;
    }
    function setBar(pct) {
      var p = pill(); if (!p) return;
      var fill = p.querySelector('.pending-file-bar-fill');
      if (fill) fill.style.width = Math.max(0, Math.min(100, pct)) + '%';
    }
    function markError(msg) {
      var p = pill(); if (!p) return;
      p.classList.remove('pending-file-uploading');
      p.classList.add('pending-file-error');
      setMeta(msg || 'Upload failed');
      var bar = p.querySelector('.pending-file-bar');
      if (bar) bar.remove();
    }

    xhr.upload.addEventListener('progress', function (e) {
      if (!e.lengthComputable) {
        setMeta(formatBytes(e.loaded || 0) + ' / ' + totalLabel);
        return;
      }
      var pct = Math.round((e.loaded / e.total) * 100);
      setMeta(formatBytes(e.loaded) + ' / ' + formatBytes(e.total) + ' (' + pct + '%)');
      setBar(pct);
    });

    xhr.onload = function () {
      var data = null;
      try { data = JSON.parse(xhr.responseText || '{}'); } catch (err) { data = null; }
      var ok = xhr.status >= 200 && xhr.status < 300;
      var p = pill();
      if (!ok || !data || !data.file) {
        markError((data && data.message) || 'Upload failed (' + xhr.status + ')');
        return;
      }
      var f = data.file;
      pendingFilesFor(pendingId).push(f.id);
      if (p) {
        p.classList.remove('pending-file-uploading');
        p.setAttribute('data-file-id', f.id);
        setBar(100);
        setMeta(formatBytes(parseInt(f.size, 10) || 0));
        var bar = p.querySelector('.pending-file-bar');
        if (bar) bar.remove();
        var rm = p.querySelector('.pending-file-remove');
        if (rm) rm.setAttribute('data-file-id', f.id);
      }
    };
    xhr.onerror   = function () { markError('Network error'); };
    xhr.onabort   = function () { markError('Upload cancelled'); };
    xhr.ontimeout = function () { markError('Upload timed out'); };

    var fd = new FormData();
    fd.append('file', file);
    xhr.send(fd);
  }
  function removePendingFile(btn) {
    var pill = btn.closest('.pending-file');
    if (!pill) return;
    var pendingId = btn.getAttribute('data-pending-id');
    var fileId    = parseInt(btn.getAttribute('data-file-id'), 10);
    if (pendingId && fileId) {
      pendingFilesByPendingId[pendingId] = pendingFilesFor(pendingId).filter(function (x) { return x !== fileId; });
    }
    var parent = pill.parentElement;
    pill.remove();
    if (parent && parent.children.length === 0) {
      parent.hidden = true;
      var form = parent.closest('.composer');
      if (form) { delete form.dataset.hasFiles; placeholderUpdate(form.querySelector('.composer-input')); }
    }
  }
  ['chatComposerFileInput', 'chatThreadFileInput'].forEach(function (id) {
    var input = document.getElementById(id);
    if (!input) return;
    var pendingId = input.getAttribute('data-pending-id');
    input.addEventListener('change', function () {
      var files = Array.prototype.slice.call(this.files || []);
      files.forEach(function (f) { uploadFile(f, pendingId); });
      this.value = '';
    });
  });

  // ===== Outside-click + Esc to close popups =====
  document.addEventListener('mousedown', function (e) {
    var picker = $('#chatEmojiPopup');
    if (picker && !picker.hidden &&
        !picker.contains(e.target) &&
        !e.target.closest('[data-action="add-reaction"]') &&
        !e.target.closest('[data-action="open-emoji-composer"]') &&
        !e.target.closest('[data-action="pick-status-emoji"]')) {
      closeEmojiPicker();
    }
    if (mentionPopup && !mentionPopup.hidden &&
        !mentionPopup.contains(e.target) && e.target !== mentionAnchorInput) {
      hideMentionPopup();
    }
    if (msgMenuEl && !msgMenuEl.hidden &&
        !msgMenuEl.contains(e.target) &&
        !e.target.closest('[data-action="open-msg-menu"]')) {
      closeMsgMenu();
    }
    if (channelMenuEl && !channelMenuEl.hidden &&
        !channelMenuEl.contains(e.target) &&
        !e.target.closest('[data-action="open-channel-menu"]')) {
      closeChannelMenu();
    }
    if (userMenuEl && !userMenuEl.hidden &&
        !userMenuEl.contains(e.target) &&
        !e.target.closest('[data-action="open-user-menu"]')) {
      closeUserMenu();
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeEmojiPicker();
      closeMsgMenu();
      closeChannelMenu();
      closeUserMenu();
      hideMentionPopup();
    }
  });

  // ===== Presence (4-state) =====
  function pingPresence() {
    if (document.hidden) return;
    fetch('/chat/api/presence.php?action=ping', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': CSRF }
    }).catch(function () {});
  }
  pingPresence();
  setInterval(pingPresence, 30000);

  function applyPresenceDots() {
    var classes = ['presence-active', 'presence-idle', 'presence-offline', 'presence-dnd'];
    document.querySelectorAll('.dot-presence[data-user-id]').forEach(function (el) {
      var id = parseInt(el.getAttribute('data-user-id'), 10);
      var state = (id === CURRENT_USER_ID && parseInt(userPrefs.dnd, 10))
        ? 'dnd'
        : (presenceMap[id] || 'offline');
      classes.forEach(function (c) { el.classList.remove(c); });
      el.classList.add('presence-' + state);
    });
  }
  applyPresenceDots();
  function refreshPresence() {
    apiGet('list', 'presence.php', {}).then(function (res) {
      if (!res.ok || !res.data) return;
      if (typeof res.data.presence === 'object') {
        presenceMap = res.data.presence;
        applyPresenceDots();
      }
      if (typeof res.data.statuses === 'object' && res.data.statuses) {
        statusMap = res.data.statuses;
        applyStatusEmojis();
      }
    }).catch(function () {});
  }
  setInterval(refreshPresence, 30000);

  // ===== Custom status (emoji + text shown next to names everywhere) =====
  function applyStatusEmojis() {
    document.querySelectorAll('[data-status-user-id]').forEach(function (el) {
      var id = parseInt(el.getAttribute('data-status-user-id'), 10);
      var s = statusMap[id];
      if (s && s.emoji) {
        el.textContent = s.emoji;
        el.title = s.text || '';
        el.hidden = false;
      } else {
        el.textContent = '';
        el.title = '';
        el.hidden = true;
      }
    });
  }
  applyStatusEmojis();

  // ===== Unread badges (sidebar) =====
  function setUnreadBadge(rowSelector, count, isMention) {
    var row = document.querySelector(rowSelector);
    if (!row) return;
    var existing = row.querySelector('.badge');
    if (count <= 0) {
      if (existing) existing.remove();
      row.classList.remove('unread');
      return;
    }
    var text = count > 99 ? '99+' : String(count);
    row.classList.add('unread');
    var cls = 'badge' + (isMention ? ' mention' : '');
    if (existing) {
      existing.className = cls;
      existing.textContent = text;
    } else {
      var span = document.createElement('span');
      span.className = cls;
      span.textContent = text;
      row.appendChild(span);
    }
  }
  function bumpChannelUnread(channelId) {
    var count = (unreadCounts.channels[channelId] || 0) + 1;
    unreadCounts.channels[channelId] = count;
    var hasMention = (mentionCounts[channelId] || 0) > 0;
    setUnreadBadge('.side-item[data-channel-id="' + channelId + '"]', count, hasMention);
  }
  function bumpDmUnread(conversationId) {
    var count = (unreadCounts.dms[conversationId] || 0) + 1;
    unreadCounts.dms[conversationId] = count;
    setUnreadBadge('.side-item[data-conversation-id="' + conversationId + '"]', count, false);
  }
  function markReadOnServer(target) {
    var fd = new FormData();
    if (target.channel_id)      fd.append('channel_id',      target.channel_id);
    if (target.conversation_id) fd.append('conversation_id', target.conversation_id);
    fetch('/chat/api/state.php?action=mark_read', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': CSRF },
      body: fd
    }).catch(function () {});
  }

  // ===== Browser notifications =====
  if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
    try { Notification.requestPermission(); } catch (e) {}
  }
  function maybeNotify(ev) {
    if (typeof Notification === 'undefined') return;
    if (Notification.permission !== 'granted') return;
    if (parseInt(userPrefs.dnd, 10)) return;     // Do Not Disturb suppresses all
    var msgUserId = parseInt(ev.message_user_id, 10);
    if (!msgUserId || msgUserId === CURRENT_USER_ID) return;

    var inThisView = false;
    if (ev.channel_id      && parseInt(ev.channel_id, 10)      === CURRENT_CHANNEL_ID)       inThisView = true;
    if (ev.conversation_id && parseInt(ev.conversation_id, 10) === CURRENT_CONVERSATION_ID)  inThisView = true;
    if (inThisView && !document.hidden) return;

    var isDM = ev.conversation_id != null;
    var html = ev.message_content_html || '';
    var mentionsMe = isDM ||
      html.indexOf('data-user-id="' + CURRENT_USER_ID + '"') !== -1 ||
      html.indexOf('class="chat-mention chat-mention-special"') !== -1;
    if (!mentionsMe) return;
    if (isDM && !parseInt(userPrefs.notify_dm, 10)) return;
    if (!isDM && !parseInt(userPrefs.notify_mention, 10)) return;

    var title = (ev.author_name ? ev.author_name : 'New message') + ' — Anton Chat';
    var body  = stripHtml(html || ev.message_content || '');
    if (body.length > 140) body = body.substring(0, 140) + '…';

    try {
      var n = new Notification(title, {
        body:  body,
        icon:  '/partials/antonx-favicon.png',
        tag:   'chat-' + (ev.event_id || Date.now())
      });
      n.onclick = function () {
        window.focus();
        if (ev.channel_id && ev.channel_slug) {
          window.location.href = '/chat/?c=' + encodeURIComponent(ev.channel_slug);
        } else if (ev.conversation_id) {
          window.location.href = '/chat/?d=' + ev.conversation_id;
        }
        n.close();
      };
    } catch (e) {}
  }

  // ===== Inline edit / delete =====
  var msgMenuEl       = $('#chatMsgMenu');
  var msgMenuTargetId = null;
  function openMsgMenu(button, msgId) {
    if (!msgMenuEl) return;
    msgMenuTargetId = msgId;
    var msgEl   = document.querySelector('.msg-group[data-msg-id="' + msgId + '"]');
    var hasText = !!(msgEl && msgEl.querySelector('.msg-body:not(.msg-deleted)'));
    var editBtn = msgMenuEl.querySelector('[data-action="edit-message"]');
    if (editBtn) editBtn.style.display = hasText ? '' : 'none';
    msgMenuEl.hidden = false;
    var rect = button.getBoundingClientRect();
    var mh   = msgMenuEl.offsetHeight || 80;
    var top  = rect.bottom + 4;
    if (top + mh > window.innerHeight - 8) top = rect.top - mh - 4;
    var left = Math.min(window.innerWidth - msgMenuEl.offsetWidth - 8, rect.right - msgMenuEl.offsetWidth);
    msgMenuEl.style.top  = Math.max(8, top) + 'px';
    msgMenuEl.style.left = Math.max(8, left) + 'px';
  }
  function closeMsgMenu() {
    if (msgMenuEl) msgMenuEl.hidden = true;
    msgMenuTargetId = null;
  }

  function enterEditMode(msgId) {
    closeMsgMenu();
    var msgEl = document.querySelector('.msg-group[data-msg-id="' + msgId + '"]');
    if (!msgEl) return;
    var textEl = msgEl.querySelector('.msg-body:not(.msg-deleted)');
    if (!textEl || textEl.querySelector('.msg-edit')) return;

    var raw = textEl.getAttribute('data-raw-content') || textEl.innerHTML || '';
    textEl.setAttribute('data-original-html', textEl.innerHTML);
    textEl.innerHTML =
      '<div class="msg-edit">' +
        '<div class="msg-edit-input composer-input" contenteditable="true">' + raw + '</div>' +
        '<div class="msg-edit-actions">' +
          '<button type="button" class="cx-btn" data-action="cancel-edit" data-msg-id="' + msgId + '">Cancel</button>' +
          '<button type="button" class="cx-btn cx-btn-primary" data-action="save-edit" data-msg-id="' + msgId + '">Save</button>' +
        '</div>' +
      '</div>';
    var editInput = textEl.querySelector('.msg-edit-input');
    if (editInput) {
      editInput.focus();
      // Place caret at the end.
      var range = document.createRange();
      range.selectNodeContents(editInput);
      range.collapse(false);
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
      editInput.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); cancelEdit(msgId); }
        else if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
          e.preventDefault();
          saveEdit(msgId);
        }
      });
    }
  }
  function cancelEdit(msgId) {
    var textEl = document.querySelector('.msg-group[data-msg-id="' + msgId + '"] .msg-body');
    if (!textEl) return;
    var orig = textEl.getAttribute('data-original-html');
    if (orig !== null) {
      textEl.innerHTML = orig;
      textEl.removeAttribute('data-original-html');
    }
  }
  function saveEdit(msgId) {
    var msgEl = document.querySelector('.msg-group[data-msg-id="' + msgId + '"]');
    if (!msgEl) return;
    var textEl = msgEl.querySelector('.msg-body');
    var editInput = textEl && textEl.querySelector('.msg-edit-input');
    if (!editInput) return;
    var newContent = editInput.innerHTML.trim();
    if (!newContent) { cancelEdit(msgId); return; }
    var saveBtn = textEl.querySelector('[data-action="save-edit"]');
    if (saveBtn) saveBtn.disabled = true;
    apiPost('edit', 'messages.php', { message_id: msgId, content: newContent })
      .then(function (res) {
        if (!res.ok) {
          if (saveBtn) saveBtn.disabled = false;
          notify(res.data && res.data.message ? res.data.message : 'Failed to save.', 'error');
          return;
        }
        applyEditedMessage(msgId, res.data.message);
      })
      .catch(function () {
        if (saveBtn) saveBtn.disabled = false;
        notify('Network error.', 'error');
      });
  }
  function applyEditedMessage(msgId, msg) {
    document.querySelectorAll('.msg-group[data-msg-id="' + msgId + '"]').forEach(function (msgEl) {
      var textEl = msgEl.querySelector('.msg-body');
      if (!textEl) return;
      textEl.setAttribute('data-raw-content', msg.content || '');
      textEl.removeAttribute('data-original-html');
      textEl.classList.remove('msg-deleted');
      textEl.innerHTML = (msg.content_html || nl2br(msg.content || '')) +
                         ' <span class="msg-edited">(edited)</span>';
    });
  }
  function applyDeletedMessage(msgId) {
    document.querySelectorAll('.msg-group[data-msg-id="' + msgId + '"]').forEach(function (msgEl) {
      var col = msgEl.querySelector('.msg-col');
      if (!col) return;
      var existing = col.querySelector('.msg-body');
      if (existing) {
        existing.className = 'msg-body msg-deleted';
        existing.innerHTML = '<em>This message was deleted.</em>';
        existing.removeAttribute('data-raw-content');
        existing.removeAttribute('data-original-html');
      } else {
        col.insertAdjacentHTML('afterbegin', '<div class="msg-body msg-deleted"><em>This message was deleted.</em></div>');
      }
      ['.reactions', '.thread-preview', '.msg-files', '.reply-quote'].forEach(function (sel) {
        var el = col.querySelector(sel);
        if (el) el.remove();
      });
      var actions = msgEl.querySelector('.msg-actions');
      if (actions) actions.remove();
    });
  }

  // ===== Pin/Save updates from events =====
  function applyPinState(msgId, pinned) {
    document.querySelectorAll('.msg-group[data-msg-id="' + msgId + '"]').forEach(function (el) {
      if (pinned) el.classList.add('is-pinned'); else el.classList.remove('is-pinned');
      var pinBtn = el.querySelector('[data-action="toggle-pin"]');
      if (pinBtn) {
        pinBtn.classList.toggle('active', pinned);
        pinBtn.title = pinned ? 'Unpin' : 'Pin';
        var svg = pinBtn.querySelector('svg');
        if (svg) svg.setAttribute('fill', pinned ? 'currentColor' : 'none');
      }
    });
  }
  function applySaveState(msgId, saved) {
    document.querySelectorAll('.msg-group[data-msg-id="' + msgId + '"]').forEach(function (el) {
      var btn = el.querySelector('[data-action="toggle-save"]');
      if (!btn) return;
      btn.classList.toggle('active', saved);
      btn.title = saved ? 'Remove from Saved' : 'Save for later';
      var svg = btn.querySelector('svg');
      if (svg) svg.setAttribute('fill', saved ? 'currentColor' : 'none');
    });
  }

  // ===== Channel header kebab (Leave channel) =====
  var channelMenuEl        = $('#chatChannelMenu');
  var channelMenuChannelId = null;
  function openChannelMenu(button, channelId) {
    if (!channelMenuEl) return;
    channelMenuChannelId = channelId;
    channelMenuEl.hidden = false;
    var rect = button.getBoundingClientRect();
    var mh   = channelMenuEl.offsetHeight || 50;
    var top  = rect.bottom + 4;
    if (top + mh > window.innerHeight - 8) top = rect.top - mh - 4;
    var left = Math.min(window.innerWidth - channelMenuEl.offsetWidth - 8, rect.right - channelMenuEl.offsetWidth);
    channelMenuEl.style.top  = Math.max(8, top) + 'px';
    channelMenuEl.style.left = Math.max(8, left) + 'px';
  }
  function closeChannelMenu() {
    if (channelMenuEl) channelMenuEl.hidden = true;
    channelMenuChannelId = null;
  }
  function leaveCurrentChannel() {
    if (!channelMenuChannelId) return;
    var cid = channelMenuChannelId;
    closeChannelMenu();
    // AntonConfirm returns a Promise<boolean>. On older pages (no global
    // sheet primitive loaded) fall back to the native confirm so the
    // function keeps working.
    var ask = window.AntonConfirm
      ? window.AntonConfirm('Leave this channel? You can rejoin any time.', { confirmLabel: 'Leave', destructive: true })
      : Promise.resolve(window.confirm('Leave this channel?'));
    ask.then(function (yes) {
      if (!yes) return;
      apiPost('leave', 'channels.php', { channel_id: cid }).then(function (res) {
        if (!res.ok) {
          notify(res.data && res.data.message ? res.data.message : 'Could not leave.', 'error');
          return;
        }
        window.location.href = '/chat/';
      });
    });
  }

  // ===== User profile menu (workspace header click) =====
  var userMenuEl = $('#chatUserMenu');
  function openUserMenu(anchor) {
    if (!userMenuEl) return;
    userMenuEl.hidden = false;
    var rect = anchor.getBoundingClientRect();
    var mh = userMenuEl.offsetHeight || 120;
    var top = rect.bottom + 4;
    if (top + mh > window.innerHeight - 8) top = rect.top - mh - 4;
    var left = rect.left;
    if (left + userMenuEl.offsetWidth + 8 > window.innerWidth) {
      left = window.innerWidth - userMenuEl.offsetWidth - 8;
    }
    userMenuEl.style.top  = Math.max(8, top) + 'px';
    userMenuEl.style.left = Math.max(8, left) + 'px';
  }
  function closeUserMenu() { if (userMenuEl) userMenuEl.hidden = true; }

  // ===== Status modal =====
  var statusForm = $('#statusForm');
  var statusEmojiBtn = $('#statusEmojiBtn');
  var statusEmojiValue = $('#statusEmojiValue');
  var statusTextInput = $('#statusTextInput');
  var statusExpiresSelect = $('#statusExpiresSelect');

  function openStatusModal() {
    closeUserMenu();
    // Seed with current values from prefs.
    if (statusEmojiValue) statusEmojiValue.textContent = userPrefs.status_emoji || '😀';
    if (statusTextInput)  statusTextInput.value        = userPrefs.status_text  || '';
    if (statusExpiresSelect) statusExpiresSelect.value = '0';
    var err = $('#statusErr');
    if (err) { err.hidden = true; err.textContent = ''; }
    openDialog('modalStatus');
    if (statusTextInput) requestAnimationFrame(function () { statusTextInput.focus(); });
  }
  function toggleStatusEmojiGrid() {
    var grid = $('#statusEmojiGrid');
    if (!grid) return;
    grid.hidden = !grid.hidden;
  }
  function setStatusEmoji(emoji) {
    if (!emoji || !statusEmojiValue) return;
    statusEmojiValue.textContent = emoji;
    var grid = $('#statusEmojiGrid');
    if (grid) grid.hidden = true;
  }
  function applyStatusPreset(btn) {
    var emoji = btn.getAttribute('data-emoji') || '';
    var text  = btn.getAttribute('data-text')  || '';
    var exp   = btn.getAttribute('data-expires') || '0';
    if (statusEmojiValue) statusEmojiValue.textContent = emoji;
    if (statusTextInput)  statusTextInput.value = text;
    if (statusExpiresSelect) statusExpiresSelect.value = exp;
  }
  function submitStatus(e) {
    e.preventDefault();
    var emoji = (statusEmojiValue && statusEmojiValue.textContent || '').trim();
    var text  = (statusTextInput && statusTextInput.value || '').trim();
    var exp   = parseInt(statusExpiresSelect && statusExpiresSelect.value || '0', 10) || 0;
    if (!emoji) {
      var err = $('#statusErr');
      if (err) { err.textContent = 'Pick an emoji first.'; err.hidden = false; }
      return;
    }
    var btn = statusForm.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    apiPost('set', 'status.php', { emoji: emoji, text: text, expires_in: exp })
      .then(function (res) {
        if (btn) btn.disabled = false;
        if (!res.ok) {
          var err = $('#statusErr');
          if (err) { err.textContent = (res.data && res.data.message) || 'Could not save.'; err.hidden = false; }
          return;
        }
        var s = res.data.status || { emoji: emoji, text: text };
        userPrefs.status_emoji = s.emoji;
        userPrefs.status_text  = s.text;
        statusMap[CURRENT_USER_ID] = { emoji: s.emoji, text: s.text || '' };
        applyStatusEmojis();
        closeDialog('modalStatus');
      })
      .catch(function () {
        if (btn) btn.disabled = false;
        var err = $('#statusErr');
        if (err) { err.textContent = 'Network error.'; err.hidden = false; }
      });
  }
  function clearStatus() {
    apiPost('clear', 'status.php', {}).then(function (res) {
      if (!res.ok) return;
      userPrefs.status_emoji = null;
      userPrefs.status_text  = null;
      delete statusMap[CURRENT_USER_ID];
      applyStatusEmojis();
      closeDialog('modalStatus');
    });
  }
  if (statusForm) statusForm.addEventListener('submit', submitStatus);

  // ===== Sidebar collapse + section collapse =====
  var cxApp = $('.cx-app');
  var SIDEBAR_KEY = 'anton-chat-sidebar';
  try {
    if (localStorage.getItem(SIDEBAR_KEY) === '1' && cxApp) cxApp.classList.add('sidebar-collapsed');
  } catch (e) {}
  function toggleSidebar() {
    if (!cxApp) return;
    var collapsed = cxApp.classList.toggle('sidebar-collapsed');
    try { localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0'); } catch (e) {}
  }
  function toggleSection(name) {
    if (!name) return;
    document.querySelectorAll('.side-section').forEach(function (sec) {
      var headBtn = sec.querySelector('[data-section="' + name + '"]');
      if (!headBtn) return;
      sec.classList.toggle('collapsed');
      try { localStorage.setItem('anton-chat-sec-' + name, sec.classList.contains('collapsed') ? '1' : '0'); } catch (e) {}
    });
  }
  // Restore section state.
  ['channels', 'dms'].forEach(function (name) {
    try {
      if (localStorage.getItem('anton-chat-sec-' + name) === '1') {
        document.querySelectorAll('.side-section').forEach(function (sec) {
          if (sec.querySelector('[data-section="' + name + '"]')) sec.classList.add('collapsed');
        });
      }
    } catch (e) {}
  });

  // ===== Details panel =====
  function toggleDetails() {
    if (!cxApp) return;
    // Close the thread panel first — details + thread share the right column.
    if (cxApp.classList.contains('with-thread')) {
      cxApp.classList.remove('with-thread');
      if (threadPanel) threadPanel.hidden = true;
      threadParentId = null;
    }
    cxApp.classList.toggle('with-details');
    if (cxApp.classList.contains('with-details') && currentDetailsTab === 'pinned') {
      loadDetailsPinned();
    }
  }
  var currentDetailsTab = 'about';
  function switchDetailsTab(name) {
    if (!name) return;
    currentDetailsTab = name;
    $$('.details-tab').forEach(function (t) {
      var on = t.getAttribute('data-details-tab') === name;
      t.classList.toggle('active', on);
    });
    $$('.details-pane').forEach(function (p) {
      p.hidden = p.getAttribute('data-details-pane') !== name;
    });
    if (name === 'pinned') loadDetailsPinned();
  }
  function loadDetailsPinned() {
    if (!CURRENT_CHANNEL_ID) return;
    var list = $('#detailsPinnedList');
    if (!list) return;
    list.innerHTML = '<div class="cx-modal-loading">Loading…</div>';
    apiGet('list', 'pinned.php', { channel_id: CURRENT_CHANNEL_ID }).then(function (res) {
      if (!res.ok) { list.innerHTML = '<div class="cx-modal-loading">Could not load pins.</div>'; return; }
      var msgs = res.data.messages || [];
      if (!msgs.length) {
        list.innerHTML = '<div class="cx-empty-sub">Nothing pinned yet.</div>';
        return;
      }
      list.innerHTML = msgs.map(function (m) { return buildMessageHtml(m, false); }).join('');
    });
  }

  // ===== Cmd+K palette =====
  var cmdkResults = $('#cmdkResults');
  var cmdkInput   = $('#cmdkInput');
  var cmdkItems   = [];
  var cmdkActiveIndex = 0;

  function buildCmdkItems() {
    var items = [];
    document.querySelectorAll('.side-item[data-channel-id]').forEach(function (el) {
      var nameEl = el.querySelector('.name');
      var glyphEl = el.querySelector('.ch-glyph');
      items.push({
        kind: 'Channel',
        name: nameEl ? nameEl.textContent.trim() : '',
        prefix: glyphEl ? '#' : '🔒',
        href: el.getAttribute('href')
      });
    });
    document.querySelectorAll('.side-item[data-conversation-id]').forEach(function (el) {
      var nameEl = el.querySelector('.name');
      items.push({
        kind: 'DM',
        name: nameEl ? nameEl.textContent.trim() : '',
        prefix: '@',
        href: el.getAttribute('href')
      });
    });
    // Quick commands.
    items.push({ kind: 'View', name: 'Threads', prefix: '↩', href: '/chat/?view=threads' });
    items.push({ kind: 'View', name: 'Inbox',   prefix: '✉', href: '/chat/?view=inbox' });
    items.push({ kind: 'View', name: 'Saved',   prefix: '🔖', href: '/chat/?view=saved' });
    return items;
  }
  function fuzzyScore(query, text) {
    if (!query) return 1;
    query = query.toLowerCase();
    text  = (text || '').toLowerCase();
    var direct = text.indexOf(query);
    if (direct !== -1) return 1000 - direct;
    var qi = 0, score = 0;
    for (var ti = 0; ti < text.length && qi < query.length; ti++) {
      if (text[ti] === query[qi]) { qi++; score++; }
    }
    return qi === query.length ? score : 0;
  }
  function openCmdk() {
    openDialog('modalCmdk');
    if (cmdkInput) {
      cmdkInput.value = '';
      requestAnimationFrame(function () { cmdkInput.focus(); });
    }
    renderCmdk('');
  }
  var cmdkAllAntonInflight = null;
  var cmdkAllAntonDebounce = null;
  function renderCmdk(query) {
    var all = buildCmdkItems();
    var matches;
    if (!query) {
      matches = all.slice(0, 15);
    } else {
      matches = all
        .map(function (i) { return { item: i, score: fuzzyScore(query, i.name) }; })
        .filter(function (x) { return x.score > 0; })
        .sort(function (a, b) { return b.score - a.score; })
        .slice(0, 15)
        .map(function (x) { return x.item; });
    }
    cmdkItems = matches;
    cmdkActiveIndex = 0;
    if (!cmdkResults) return;
    // Render the local matches first, then async-fetch the unified search to
    // append "All of Anton" groups (People, Tasks, Projects, Clients, Docs,
    // Messages) below the local list.
    cmdkResults.innerHTML = renderCmdkLocalSection(matches) + renderCmdkLoadingSection(query);
    cmdkRenderActive();
    if (query) scheduleCmdkAllSearch(query);
  }

  function renderCmdkLocalSection(matches) {
    if (!matches.length) {
      return '<div class="cmdk-section-label">In this chat</div><div class="cmdk-section-label">No matches.</div>';
    }
    var groups = {};
    matches.forEach(function (m, i) {
      if (!groups[m.kind]) groups[m.kind] = [];
      groups[m.kind].push({ item: m, idx: i });
    });
    var html = '';
    Object.keys(groups).forEach(function (k) {
      html += '<div class="cmdk-section-label">' + escapeHtml(k) + '</div>';
      groups[k].forEach(function (entry) {
        html += '<a class="cmdk-item' + (entry.idx === 0 ? ' active' : '') + '"'
              + ' href="' + escapeHtml(entry.item.href) + '" data-cmdk-index="' + entry.idx + '">'
              + '<span class="prefix">' + escapeHtml(entry.item.prefix) + '</span>'
              + '<span class="name">' + escapeHtml(entry.item.name) + '</span>'
              + '<span class="kind">' + escapeHtml(entry.item.kind) + '</span>'
              + '</a>';
      });
    });
    return html;
  }

  function renderCmdkLoadingSection(q) {
    if (!q) return '';
    return '<div class="cmdk-section-label cmdk-allanton-label">All of Anton · searching…</div>';
  }

  function cmdkRenderActive() {
    $$('.cmdk-item').forEach(function (el) {
      var i = parseInt(el.getAttribute('data-cmdk-index'), 10);
      if (i === cmdkActiveIndex) el.classList.add('active');
      else el.classList.remove('active');
    });
  }

  function scheduleCmdkAllSearch(q) {
    clearTimeout(cmdkAllAntonDebounce);
    cmdkAllAntonDebounce = setTimeout(function () { runCmdkAllSearch(q); }, 200);
  }
  function runCmdkAllSearch(q) {
    if (cmdkAllAntonInflight && typeof cmdkAllAntonInflight.abort === 'function') {
      try { cmdkAllAntonInflight.abort(); } catch (e) {}
    }
    var ctrl = (typeof AbortController === 'function') ? new AbortController() : null;
    cmdkAllAntonInflight = ctrl;
    fetch('/chat/api/search_all.php?q=' + encodeURIComponent(q) + '&limit=6', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: ctrl ? ctrl.signal : undefined
    }).then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || ctrl !== cmdkAllAntonInflight) return;
        renderCmdkAllAnton(data);
      }).catch(function (err) {
        if (err && err.name === 'AbortError') return;
      });
  }
  function renderCmdkAllAnton(data) {
    if (!cmdkResults) return;
    var groups = data.groups || {};
    var kinds  = ['people', 'tasks', 'projects', 'clients', 'docs', 'channels', 'messages'];
    var nameMap = {
      people: 'People', tasks: 'Tasks', projects: 'Projects', clients: 'Clients',
      docs: 'Docs', channels: 'Channels', messages: 'Messages'
    };
    // Append items to cmdkItems so keyboard nav covers them too.
    var startIdx = cmdkItems.length;
    var html = '';
    var anyShown = false;
    kinds.forEach(function (k) {
      var list = groups[k] || [];
      if (!list.length) return;
      anyShown = true;
      html += '<div class="cmdk-section-label">' + escapeHtml(nameMap[k]) + '</div>';
      list.forEach(function (it) {
        cmdkItems.push({ kind: nameMap[k], name: it.label, prefix: '', href: it.href });
        html += '<a class="cmdk-item" href="' + escapeHtml(it.href) + '" data-cmdk-index="' + startIdx + '">'
              + '<span class="prefix">↪</span>'
              + '<span class="name">' + escapeHtml(it.label) + '</span>'
              + '<span class="kind">' + escapeHtml(it.sub || nameMap[k]) + '</span>'
              + '</a>';
        startIdx++;
      });
    });
    // Replace the "searching…" loading line.
    var loading = cmdkResults.querySelector('.cmdk-allanton-label');
    if (loading) loading.outerHTML = anyShown ? html : '<div class="cmdk-section-label">All of Anton · no matches.</div>';
    else cmdkResults.insertAdjacentHTML('beforeend', html);
  }
  function updateCmdkActive() {
    $$('.cmdk-item').forEach(function (el) {
      var i = parseInt(el.getAttribute('data-cmdk-index'), 10);
      if (i === cmdkActiveIndex) {
        el.classList.add('active');
        if (el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
      } else {
        el.classList.remove('active');
      }
    });
  }
  if (cmdkInput) {
    cmdkInput.addEventListener('input', function () { renderCmdk(this.value.trim()); });
    cmdkInput.addEventListener('keydown', function (e) {
      if (!cmdkItems.length) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        cmdkActiveIndex = (cmdkActiveIndex + 1) % cmdkItems.length;
        updateCmdkActive();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        cmdkActiveIndex = (cmdkActiveIndex - 1 + cmdkItems.length) % cmdkItems.length;
        updateCmdkActive();
      } else if (e.key === 'Enter') {
        e.preventDefault();
        var item = cmdkItems[cmdkActiveIndex];
        if (item && item.href) window.location.href = item.href;
      } else if (e.key === 'Escape') {
        e.preventDefault();
        closeDialog('modalCmdk');
      }
    });
  }
  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      openCmdk();
    }
  });

  // ===== Preferences =====
  var prefsForm = $('#prefsForm');
  function openPrefsModal() {
    if (prefsForm) {
      prefsForm.elements['enter_to_send'].checked  = parseInt(userPrefs.enter_to_send,  10) !== 0;
      prefsForm.elements['notify_dm'].checked      = parseInt(userPrefs.notify_dm,      10) !== 0;
      prefsForm.elements['notify_mention'].checked = parseInt(userPrefs.notify_mention, 10) !== 0;
      prefsForm.elements['dnd'].checked            = parseInt(userPrefs.dnd,            10) !== 0;
    }
    var err = $('#prefsErr');
    if (err) { err.hidden = true; err.textContent = ''; }
    openDialog('modalPrefs');
  }
  if (prefsForm) {
    prefsForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = prefsForm.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      apiPost('update', 'prefs.php', {
        enter_to_send:  prefsForm.elements['enter_to_send'].checked  ? '1' : '0',
        notify_dm:      prefsForm.elements['notify_dm'].checked      ? '1' : '0',
        notify_mention: prefsForm.elements['notify_mention'].checked ? '1' : '0',
        dnd:            prefsForm.elements['dnd'].checked            ? '1' : '0'
      }).then(function (res) {
        if (btn) btn.disabled = false;
        if (!res.ok) {
          var err = $('#prefsErr');
          if (err) { err.textContent = (res.data && res.data.message) || 'Save failed.'; err.hidden = false; }
          return;
        }
        userPrefs   = res.data.prefs || userPrefs;
        enterToSend = parseInt(userPrefs.enter_to_send, 10) !== 0;
        applyPresenceDots();
        closeDialog('modalPrefs');
      }).catch(function () {
        if (btn) btn.disabled = false;
        var err = $('#prefsErr');
        if (err) { err.textContent = 'Network error.'; err.hidden = false; }
      });
    });
  }

  // ===== Create channel =====
  var createForm = $('#createChannelForm');
  if (createForm) {
    createForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var slug      = createForm.elements['slug'].value.trim();
      var topic     = createForm.elements['topic'].value.trim();
      var isPrivate = createForm.elements['is_private'].checked ? '1' : '';
      var errEl     = $('#createChannelErr');
      var submitBtn = createForm.querySelector('button[type="submit"]');
      if (submitBtn) submitBtn.disabled = true;
      apiPost('create', 'channels.php', { slug: slug, topic: topic, is_private: isPrivate })
        .then(function (res) {
          if (submitBtn) submitBtn.disabled = false;
          if (!res.ok) {
            if (errEl) {
              errEl.textContent = (res.data && res.data.message) || 'Could not create the channel.';
              errEl.hidden = false;
            }
            return;
          }
          window.location.href = '/chat/?c=' + encodeURIComponent(res.data.channel.slug);
        })
        .catch(function () {
          if (submitBtn) submitBtn.disabled = false;
          if (errEl) { errEl.textContent = 'Network error. Try again.'; errEl.hidden = false; }
        });
    });
  }

  // ===== Browse channels =====
  function loadBrowseList() {
    var listEl = $('#browseChannelsList');
    if (!listEl) return;
    listEl.innerHTML = '<div class="cx-modal-loading">Loading…</div>';
    apiGet('directory', 'channels.php', {})
      .then(function (res) {
        if (!res.ok) { listEl.innerHTML = '<div class="cx-modal-loading">Failed to load channels.</div>'; return; }
        var channels = res.data.channels || [];
        if (!channels.length) {
          listEl.innerHTML = '<div class="cx-modal-loading">No public channels yet.</div>';
          return;
        }
        listEl.innerHTML = '';
        channels.forEach(function (c) {
          var row = document.createElement('div');
          row.className = 'cx-browse-row';
          var members = parseInt(c.member_count, 10);
          var isMember = parseInt(c.is_member, 10) === 1;
          row.innerHTML =
            '<div class="cx-browse-info">' +
              '<div class="cx-browse-name"><span class="cx-browse-prefix">#</span>' + escapeHtml(c.slug) + '</div>' +
              '<div class="cx-browse-meta">' +
                members + ' member' + (members === 1 ? '' : 's') +
                (c.topic ? ' · ' + escapeHtml(c.topic) : '') +
              '</div>' +
            '</div>' +
            (isMember
              ? '<span class="cx-browse-joined">Joined</span>'
              : '<button type="button" class="cx-btn cx-btn-primary" data-action="join-channel" data-channel-id="' + c.id + '" data-channel-slug="' + escapeHtml(c.slug) + '">Join</button>');
          listEl.appendChild(row);
        });
      })
      .catch(function () { listEl.innerHTML = '<div class="cx-modal-loading">Network error.</div>'; });
  }
  function joinAndOpen(channelId, slug, button) {
    if (!channelId) return;
    if (button) button.disabled = true;
    apiPost('join', 'channels.php', { channel_id: channelId })
      .then(function (res) {
        if (!res.ok) {
          if (button) button.disabled = false;
          notify(res.data && res.data.message ? res.data.message : 'Could not join.', 'error');
          return;
        }
        window.location.href = '/chat/?c=' + encodeURIComponent(slug);
      })
      .catch(function () {
        if (button) button.disabled = false;
        notify('Network error.', 'error');
      });
  }

  // ===== New DM modal =====
  var peopleCache = null;
  var selectedUserIds = [];
  function openNewDmModal() {
    selectedUserIds = [];
    peopleCache = null;
    updateNewDmFooter();
    var errEl = $('#newDmErr');
    if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
    var search = $('#newDmSearch');
    if (search) search.value = '';
    openDialog('modalNewDm');
    loadPeopleList('');
    if (search) requestAnimationFrame(function () { search.focus(); });
  }
  function loadPeopleList(filter) {
    var listEl = $('#newDmPeopleList');
    if (!listEl) return;
    if (peopleCache !== null) { renderPeopleList(filter); return; }
    listEl.innerHTML = '<div class="cx-modal-loading">Loading…</div>';
    apiGet('people', 'dms.php', {})
      .then(function (res) {
        if (!res.ok) { listEl.innerHTML = '<div class="cx-modal-loading">Failed to load.</div>'; return; }
        peopleCache = res.data.people || [];
        renderPeopleList(filter);
      })
      .catch(function () { listEl.innerHTML = '<div class="cx-modal-loading">Network error.</div>'; });
  }
  function renderPeopleList(filter) {
    var listEl = $('#newDmPeopleList');
    if (!listEl || !peopleCache) return;
    var people = peopleCache;
    if (filter) {
      var f = filter.toLowerCase();
      people = people.filter(function (p) { return String(p.name).toLowerCase().indexOf(f) !== -1; });
    }
    if (!people.length) { listEl.innerHTML = '<div class="cx-modal-loading">No people match.</div>'; return; }
    listEl.innerHTML = '';
    people.forEach(function (p) {
      var row = document.createElement('label');
      row.className = 'cx-people-row';
      var checked = selectedUserIds.indexOf(p.id) !== -1;
      row.innerHTML =
        '<input type="checkbox" data-user-id="' + p.id + '"' + (checked ? ' checked' : '') + '>' +
        '<span class="cx-people-avatar">' + escapeHtml(initialsOf(p.name)) + '</span>' +
        '<span class="cx-people-name">' + escapeHtml(p.name) + '</span>';
      var cb = row.querySelector('input[type=checkbox]');
      cb.addEventListener('change', function () {
        var uid = parseInt(this.getAttribute('data-user-id'), 10);
        if (this.checked) {
          if (selectedUserIds.indexOf(uid) === -1) selectedUserIds.push(uid);
        } else {
          selectedUserIds = selectedUserIds.filter(function (x) { return x !== uid; });
        }
        updateNewDmFooter();
      });
      listEl.appendChild(row);
    });
  }
  function updateNewDmFooter() {
    var meta = $('#newDmSelectedCount');
    var btn  = $('#newDmStartBtn');
    var n = selectedUserIds.length;
    if (meta) {
      if (n === 0)      meta.textContent = 'No one selected';
      else if (n === 1) meta.textContent = '1 person selected · 1-on-1 DM';
      else              meta.textContent = n + ' people selected · group DM (' + (n + 1) + ' total)';
    }
    if (btn) {
      btn.disabled = n === 0 || n > 7;
      btn.textContent = n > 1 ? 'Start group DM' : 'Start DM';
    }
  }
  var newDmSearch = $('#newDmSearch');
  if (newDmSearch) {
    newDmSearch.addEventListener('input', function () { renderPeopleList(this.value.trim()); });
  }
  var newDmStartBtn = $('#newDmStartBtn');
  if (newDmStartBtn) {
    newDmStartBtn.addEventListener('click', function () {
      if (!selectedUserIds.length) return;
      var errEl = $('#newDmErr');
      if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
      newDmStartBtn.disabled = true;
      var fd = new FormData();
      selectedUserIds.forEach(function (uid) { fd.append('user_ids[]', uid); });
      fetch('/chat/api/dms.php?action=create', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
        body: fd
      }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
        .then(function (res) {
          if (!res.ok) {
            newDmStartBtn.disabled = false;
            if (errEl) { errEl.textContent = (res.data && res.data.message) || 'Could not start the DM.'; errEl.hidden = false; }
            return;
          }
          window.location.href = '/chat/?d=' + res.data.conversation.id;
        })
        .catch(function () {
          newDmStartBtn.disabled = false;
          if (errEl) { errEl.textContent = 'Network error.'; errEl.hidden = false; }
        });
    });
  }

  // ===== Search modal =====
  var searchContextLoaded = false;
  var searchDebounceTimer = null;
  var searchInflight = null;
  function openSearchModal() {
    openDialog('modalSearch');
    if (!searchContextLoaded) loadSearchContext();
    var input = $('#searchInput');
    if (input) requestAnimationFrame(function () { input.focus(); });
  }
  function loadSearchContext() {
    apiGet('context', 'search.php', {}).then(function (res) {
      if (!res.ok) return;
      var d = res.data || {};
      var fromSel = $('#searchFrom');
      var inSel   = $('#searchIn');
      if (fromSel) {
        var opts = ['<option value="">Anyone</option>'];
        (d.people || []).forEach(function (p) {
          opts.push('<option value="' + p.id + '">' + escapeHtml(p.name) + '</option>');
        });
        fromSel.innerHTML = opts.join('');
      }
      if (inSel) {
        var html = ['<option value="">Everywhere</option>'];
        if ((d.channels || []).length) {
          html.push('<optgroup label="Channels">');
          d.channels.forEach(function (c) {
            var prefix = parseInt(c.is_private, 10) ? '🔒 ' : '# ';
            html.push('<option value="c:' + c.id + '">' + escapeHtml(prefix + c.slug) + '</option>');
          });
          html.push('</optgroup>');
        }
        if ((d.conversations || []).length) {
          html.push('<optgroup label="Direct Messages">');
          d.conversations.forEach(function (c) {
            html.push('<option value="d:' + c.id + '">' + escapeHtml(c.display) + '</option>');
          });
          html.push('</optgroup>');
        }
        inSel.innerHTML = html.join('');
      }
      searchContextLoaded = true;
    });
  }
  function scheduleSearch() {
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(runSearch, 220);
  }
  function runSearch() {
    var resultsEl = $('#searchResults');
    if (!resultsEl) return;
    var q       = ($('#searchInput')   || {}).value || '';
    var from    = ($('#searchFrom')    || {}).value || '';
    var inSel   = ($('#searchIn')      || {}).value || '';
    var after   = ($('#searchAfter')   || {}).value || '';
    var before  = ($('#searchBefore')  || {}).value || '';
    var hasFile = ($('#searchHasFile') || {}).checked;
    var qs = { q: q.trim() };
    if (from)    qs.from   = from;
    if (after)   qs.after  = after;
    if (before)  qs.before = before;
    if (hasFile) qs.has_file = 1;
    if (inSel) {
      var m = /^([cd]):(\d+)$/.exec(inSel);
      if (m) {
        if (m[1] === 'c') qs.channel_id      = m[2];
        else              qs.conversation_id = m[2];
      }
    }
    if (!qs.q && !qs.from && !qs.channel_id && !qs.conversation_id && !qs.after && !qs.before && !qs.has_file) {
      resultsEl.innerHTML = '<div class="cx-search-empty">Start typing or pick a filter.</div>';
      return;
    }
    resultsEl.innerHTML = '<div class="cx-modal-loading">Searching…</div>';
    if (searchInflight && typeof searchInflight.abort === 'function') {
      try { searchInflight.abort(); } catch (e) {}
    }
    var ctrl = (typeof AbortController === 'function') ? new AbortController() : null;
    searchInflight = ctrl;
    var url = '/chat/api/search.php?action=query&' + Object.keys(qs).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(qs[k]);
    }).join('&');
    fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: ctrl ? ctrl.signal : undefined
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (ctrl !== searchInflight) return;
        if (!data) { resultsEl.innerHTML = '<div class="cx-search-error">Search failed.</div>'; return; }
        renderSearchResults(data);
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        resultsEl.innerHTML = '<div class="cx-search-error">Network error.</div>';
      });
  }
  function renderSearchResults(data) {
    var resultsEl = $('#searchResults');
    if (!resultsEl) return;
    var results = data.results || [];
    if (!results.length) {
      resultsEl.innerHTML = '<div class="cx-search-empty">No messages match.</div>';
      return;
    }
    resultsEl.innerHTML = results.map(buildSearchResultHtml).join('');
  }
  function buildSearchResultHtml(r) {
    var contextHtml;
    if (r.channel_id) {
      var prefix = parseInt(r.channel_is_private, 10) ? '🔒' : '#';
      contextHtml = '<span class="cx-search-result-context">' + escapeHtml(prefix) + escapeHtml(String(r.channel_slug || '')) + '</span>';
    } else if (r.conversation_id) {
      contextHtml = '<span class="cx-search-result-context">@' + escapeHtml(String(r.dm_display || 'DM')) + '</span>';
    } else { contextHtml = ''; }
    var time = escapeHtml(formatHm(r.created_at));
    var threadBadge = r.parent_message_id ? '<span class="cx-search-result-badge">Thread</span>' : '';
    var fileBadge   = parseInt(r.has_file, 10) ? '<span class="cx-search-result-badge">Has file</span>' : '';
    var href;
    if (r.channel_id) {
      var targetId = r.parent_message_id || r.id;
      href = '/chat/?c=' + encodeURIComponent(String(r.channel_slug || '')) + '#msg-' + targetId;
    } else {
      var targetId2 = r.parent_message_id || r.id;
      href = '/chat/?d=' + r.conversation_id + '#msg-' + targetId2;
    }
    var content = r.content_html || nl2br(r.content || '');
    return '<a class="cx-search-result" href="' + escapeHtml(href) + '" data-msg-id="' + r.id + '">'
         +   '<div class="cx-search-result-meta">'
         +     contextHtml
         +     ' <span>·</span> '
         +     '<span>' + time + '</span>'
         +     threadBadge + fileBadge
         +   '</div>'
         +   '<div>'
         +     '<span class="cx-search-result-author">' + escapeHtml(r.author_name) + '</span>'
         +     '<span class="cx-search-result-text">' + content + '</span>'
         +   '</div>'
         + '</a>';
  }
  var sInput = $('#searchInput'), sFrom = $('#searchFrom'), sIn = $('#searchIn'),
      sAfter = $('#searchAfter'), sBefore = $('#searchBefore'), sHasFile = $('#searchHasFile');
  [sInput, sFrom, sIn, sAfter, sBefore, sHasFile].forEach(function (el) {
    if (!el) return;
    var ev = (el === sInput) ? 'input' : 'change';
    el.addEventListener(ev, scheduleSearch);
  });

  // ===== Schedule modal =====
  var scheduleForm = $('#scheduleForm');
  function openScheduleModal() {
    if (!CURRENT_CHANNEL_ID && !CURRENT_CONVERSATION_ID) {
      notify('Open a channel or DM first.', 'error');
      return;
    }
    if (!mainInput) return;
    var content = extractComposerHtml(mainInput);
    if (!content) { notify('Type a message first, then schedule it.', 'error'); return; }
    var err = $('#scheduleErr');
    if (err) { err.hidden = true; err.textContent = ''; }
    var inputDt = $('#scheduleAt');
    if (inputDt) {
      var d = new Date(Date.now() + 60 * 60 * 1000);   // default +1h
      d.setSeconds(0, 0);
      var iso = d.getFullYear() + '-' + pad2(d.getMonth()+1) + '-' + pad2(d.getDate()) +
                'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
      inputDt.value = iso;
    }
    openDialog('modalSchedule');
  }
  if (scheduleForm) {
    scheduleForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!mainInput) return;
      var content = extractComposerHtml(mainInput);
      if (!content) return;
      var when = $('#scheduleAt').value;
      var body = { content: content, scheduled_for: when };
      if (CURRENT_CHANNEL_ID)      body.channel_id      = CURRENT_CHANNEL_ID;
      if (CURRENT_CONVERSATION_ID) body.conversation_id = CURRENT_CONVERSATION_ID;
      if (replyQuoteState.msgId)   body.reply_to_message_id = replyQuoteState.msgId;
      var btn = scheduleForm.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      apiPost('create', 'scheduled.php', body)
        .then(function (res) {
          if (btn) btn.disabled = false;
          if (!res.ok) {
            var err = $('#scheduleErr');
            if (err) { err.textContent = (res.data && res.data.message) || 'Could not schedule.'; err.hidden = false; }
            return;
          }
          clearComposer(mainInput);
          clearReplyQuote();
          closeDialog('modalSchedule');
        })
        .catch(function () {
          if (btn) btn.disabled = false;
          var err = $('#scheduleErr');
          if (err) { err.textContent = 'Network error.'; err.hidden = false; }
        });
    });
  }

  // ===== Forward modal =====
  var forwardForm = $('#forwardForm');
  var forwardTargetSel = $('#forwardTarget');
  var forwardSourceId  = null;
  function openForwardModal(msgId) {
    forwardSourceId = msgId;
    var err = $('#forwardErr');
    if (err) { err.hidden = true; err.textContent = ''; }
    if ($('#forwardNote')) $('#forwardNote').value = '';
    populateForwardTargets();
    openDialog('modalForward');
  }
  function populateForwardTargets() {
    if (!forwardTargetSel) return;
    if (forwardTargetSel.dataset.populated) return;
    forwardTargetSel.dataset.populated = '1';
    var opts = ['<option value="">Choose channel or DM…</option>'];
    var channels = [];
    var dms = [];
    document.querySelectorAll('.side-item[data-channel-id]').forEach(function (el) {
      var nameEl = el.querySelector('.name');
      channels.push({ id: el.getAttribute('data-channel-id'), name: nameEl ? nameEl.textContent.trim() : '' });
    });
    document.querySelectorAll('.side-item[data-conversation-id]').forEach(function (el) {
      var nameEl = el.querySelector('.name');
      dms.push({ id: el.getAttribute('data-conversation-id'), name: nameEl ? nameEl.textContent.trim() : '' });
    });
    if (channels.length) {
      opts.push('<optgroup label="Channels">');
      channels.forEach(function (c) {
        opts.push('<option value="c:' + c.id + '"># ' + escapeHtml(c.name) + '</option>');
      });
      opts.push('</optgroup>');
    }
    if (dms.length) {
      opts.push('<optgroup label="Direct Messages">');
      dms.forEach(function (d) {
        opts.push('<option value="d:' + d.id + '">@ ' + escapeHtml(d.name) + '</option>');
      });
      opts.push('</optgroup>');
    }
    forwardTargetSel.innerHTML = opts.join('');
  }
  // ===== Convert message → task =====
  var convertTaskForm    = $('#convertTaskForm');
  var convertTaskCtxLoaded = false;
  var convertTaskCtx       = { projects: [], phases: [], people: [] };

  function openConvertTaskModal(msgId) {
    var hidden = $('#convertTaskMessageId');
    if (hidden) hidden.value = String(msgId);
    if ($('#convertTaskTitle')) {
      var msgEl = document.querySelector('.msg-group[data-msg-id="' + msgId + '"] .msg-body');
      var raw = msgEl ? (msgEl.textContent || '').trim() : '';
      // Seed the title with up to 100 chars of the message body.
      $('#convertTaskTitle').value = raw.length > 100 ? raw.slice(0, 97) + '…' : raw;
    }
    var err = $('#convertTaskErr');
    if (err) { err.hidden = true; err.textContent = ''; }
    if (!convertTaskCtxLoaded) {
      loadConvertTaskContext();
    } else {
      // Pre-select the channel's project if we know it; otherwise leave default.
      preselectProjectByChannel();
    }
    openDialog('modalConvertTask');
    setTimeout(function () { if ($('#convertTaskTitle')) $('#convertTaskTitle').focus(); }, 0);
  }

  function loadConvertTaskContext() {
    apiGet('task_form_context', 'convert.php', {}).then(function (res) {
      if (!res.ok || !res.data) return;
      convertTaskCtx = {
        projects: res.data.projects || [],
        phases:   res.data.phases   || [],
        people:   res.data.people   || []
      };
      convertTaskCtxLoaded = true;
      renderConvertTaskSelects();
    });
  }

  function renderConvertTaskSelects() {
    var pSel = $('#convertTaskProject');
    var hSel = $('#convertTaskPhase');
    var aSel = $('#convertTaskAssignees');
    if (pSel) {
      pSel.innerHTML = '<option value="">Choose a project…</option>' +
        convertTaskCtx.projects.map(function (p) {
          return '<option value="' + p.id + '">' + escapeHtml(String(p.name || '')) + '</option>';
        }).join('');
      pSel.onchange = function () {
        var pid = parseInt(this.value, 10) || 0;
        var phases = convertTaskCtx.phases.filter(function (ph) { return parseInt(ph.project_id, 10) === pid; });
        if (hSel) {
          hSel.innerHTML = '<option value="">Auto (first phase)</option>' + phases.map(function (ph) {
            return '<option value="' + ph.id + '">' + escapeHtml(String(ph.name || '')) + '</option>';
          }).join('');
        }
      };
    }
    if (aSel) {
      aSel.innerHTML = convertTaskCtx.people.map(function (p) {
        return '<option value="' + p.id + '">' + escapeHtml(String(p.name || '')) + '</option>';
      }).join('');
    }
    preselectProjectByChannel();
  }

  function preselectProjectByChannel() {
    // If the current channel slug looks like "proj-<slug>", try to match the
    // project so the user doesn't have to pick it manually.
    if (!CURRENT_CHANNEL_SLUG || !convertTaskCtxLoaded) return;
    var m = /^proj-(.+)$/.exec(String(CURRENT_CHANNEL_SLUG));
    if (!m) return;
    var slug = m[1];
    var match = convertTaskCtx.projects.find(function (p) {
      return String(p.name || '').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '') === slug;
    });
    if (match && $('#convertTaskProject')) {
      $('#convertTaskProject').value = String(match.id);
      $('#convertTaskProject').dispatchEvent(new Event('change'));
    }
  }

  if (convertTaskForm) {
    convertTaskForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var body = {
        message_id:  $('#convertTaskMessageId').value,
        title:       $('#convertTaskTitle').value.trim(),
        project_id:  $('#convertTaskProject').value,
        phase_id:    $('#convertTaskPhase').value,
        description: $('#convertTaskDescription').value.trim(),
        due_date:    $('#convertTaskDue').value
      };
      var aSel = $('#convertTaskAssignees');
      if (aSel) {
        body.assignees = Array.prototype.slice.call(aSel.selectedOptions).map(function (o) { return o.value; });
      }
      var btn = convertTaskForm.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      apiPost('message_to_task', 'convert.php', body).then(function (res) {
        if (btn) btn.disabled = false;
        if (!res.ok) {
          var err = $('#convertTaskErr');
          if (err) { err.textContent = (res.data && res.data.message) || 'Could not create the task.'; err.hidden = false; }
          return;
        }
        closeDialog('modalConvertTask');
        // Optimistically append the tracker footer to the message in the DOM.
        var msgEl = document.querySelector('.msg-group[data-msg-id="' + body.message_id + '"] .msg-body');
        if (msgEl && !msgEl.querySelector('.chat-tracked')) {
          msgEl.insertAdjacentHTML('beforeend',
            '<p class="chat-tracked">📌 Tracked in <a href="' + res.data.task_url + '">task#' + res.data.task_id + '</a></p>');
        }
      }).catch(function () {
        if (btn) btn.disabled = false;
        var err = $('#convertTaskErr');
        if (err) { err.textContent = 'Network error.'; err.hidden = false; }
      });
    });
  }

  if (forwardForm) {
    forwardForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!forwardSourceId) return;
      var target = $('#forwardTarget').value;
      var note   = $('#forwardNote').value || '';
      var m = /^([cd]):(\d+)$/.exec(target);
      if (!m) {
        var err = $('#forwardErr');
        if (err) { err.textContent = 'Pick a target.'; err.hidden = false; }
        return;
      }
      var body = { source_message_id: forwardSourceId, note: note };
      if (m[1] === 'c') body.channel_id = m[2]; else body.conversation_id = m[2];
      var btn = forwardForm.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      apiPost('forward', 'messages.php', body)
        .then(function (res) {
          if (btn) btn.disabled = false;
          if (!res.ok) {
            var err = $('#forwardErr');
            if (err) { err.textContent = (res.data && res.data.message) || 'Could not forward.'; err.hidden = false; }
            return;
          }
          closeDialog('modalForward');
          if (m[1] === 'c') {
            // Try to find slug from sidebar.
            var slugEl = document.querySelector('.side-item[data-channel-id="' + m[2] + '"] .name');
            if (slugEl) window.location.href = '/chat/?c=' + encodeURIComponent(slugEl.textContent.trim());
          } else {
            window.location.href = '/chat/?d=' + m[2];
          }
        })
        .catch(function () {
          if (btn) btn.disabled = false;
          var err = $('#forwardErr');
          if (err) { err.textContent = 'Network error.'; err.hidden = false; }
        });
    });
  }

  // ===== Save / pin toggles =====
  function toggleSave(msgId) {
    apiPost('toggle', 'saved.php', { message_id: msgId }).then(function (res) {
      if (!res.ok) return;
      applySaveState(msgId, !!res.data.is_saved);
    });
  }
  function togglePin(msgId) {
    apiPost('toggle', 'pinned.php', { message_id: msgId }).then(function (res) {
      if (!res.ok) {
        if (res.data && res.data.message) notify(res.data.message);
        return;
      }
      applyPinState(msgId, !!res.data.is_pinned);
    });
  }

  // ===== Insert mention button (composer toolbar) =====
  function insertMentionAtCursor(input) {
    if (!input) return;
    input.focus();
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) {
      input.appendChild(document.createTextNode('@'));
    } else {
      var range = sel.getRangeAt(0);
      range.deleteContents();
      var node = document.createTextNode('@');
      range.insertNode(node);
      range.setStartAfter(node);
      range.collapse(true);
      sel.removeAllRanges();
      sel.addRange(range);
    }
    placeholderUpdate(input);
    // Trigger mention popup right away.
    handleMentionInput(input);
  }
  function openComposerEmojiPicker(button, input) {
    openEmojiPicker(button, function (emoji) {
      if (!emoji || !input) { closeEmojiPicker(); return; }
      input.focus();
      var sel = window.getSelection();
      if (sel && sel.rangeCount) {
        var range = sel.getRangeAt(0);
        range.deleteContents();
        var node = document.createTextNode(emoji);
        range.insertNode(node);
        range.setStartAfter(node);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
      } else {
        input.appendChild(document.createTextNode(emoji));
      }
      placeholderUpdate(input);
      closeEmojiPicker();
    });
  }

  // ===== Details tab clicks =====
  document.addEventListener('click', function (e) {
    var t = e.target.closest('.details-tab[data-details-tab]');
    if (!t) return;
    switchDetailsTab(t.getAttribute('data-details-tab'));
  });

  // ===== Master delegated click handler =====
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-action]');
    if (!t) return;
    var action = t.getAttribute('data-action');
    var msgId;
    switch (action) {
      case 'open-create-channel': {
        e.preventDefault();
        var errEl = $('#createChannelErr');
        if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
        var form = $('#createChannelForm');
        if (form) form.reset();
        openDialog('modalCreateChannel');
        var slugInput = $('#createChannelSlug');
        if (slugInput) requestAnimationFrame(function () { slugInput.focus(); });
        break;
      }
      case 'open-browse-channels':
        e.preventDefault();
        openDialog('modalBrowseChannels');
        loadBrowseList();
        break;
      case 'close-modal':
        e.preventDefault();
        closeDialog(t.closest('dialog'));
        break;
      case 'join-channel':
        e.preventDefault();
        joinAndOpen(parseInt(t.getAttribute('data-channel-id'), 10), t.getAttribute('data-channel-slug'), t);
        break;
      case 'open-new-dm':
        e.preventDefault();
        openNewDmModal();
        break;
      case 'open-search':
        e.preventDefault();
        openSearchModal();
        break;
      case 'open-cmdk':
        e.preventDefault();
        openCmdk();
        break;
      case 'open-prefs':
        e.preventDefault();
        openPrefsModal();
        break;
      case 'add-reaction':
        e.preventDefault();
        openEmojiPickerForMsg(t, parseInt(t.getAttribute('data-msg-id'), 10));
        break;
      case 'toggle-reaction':
        e.preventDefault();
        toggleReaction(parseInt(t.getAttribute('data-msg-id'), 10), t.getAttribute('data-emoji'));
        break;
      case 'open-thread':
        e.preventDefault();
        openThread(parseInt(t.getAttribute('data-msg-id'), 10));
        break;
      case 'close-thread':
        e.preventDefault();
        closeThread();
        break;
      case 'toggle-save':
        e.preventDefault();
        toggleSave(parseInt(t.getAttribute('data-msg-id'), 10));
        break;
      case 'toggle-pin':
        e.preventDefault();
        togglePin(parseInt(t.getAttribute('data-msg-id'), 10));
        break;
      case 'open-forward':
        e.preventDefault();
        openForwardModal(parseInt(t.getAttribute('data-msg-id'), 10));
        break;
      case 'open-convert-task':
        e.preventDefault();
        openConvertTaskModal(parseInt(t.getAttribute('data-msg-id'), 10));
        break;
      case 'poll-vote': {
        e.preventDefault();
        var pid = parseInt(t.getAttribute('data-poll-id'), 10);
        var oid = parseInt(t.getAttribute('data-option-idx'), 10);
        apiPost('vote', 'polls.php', { poll_id: pid, option_idx: oid }).then(function (res) {
          if (!res.ok) return;
          // Update counts + bars in place for every option inside this poll.
          var poll = document.querySelector('.chat-poll[data-poll-id="' + pid + '"]');
          if (!poll) return;
          var total = res.data.total || 0;
          poll.querySelectorAll('.chat-poll-opt').forEach(function (opt) {
            var i = parseInt(opt.getAttribute('data-option-idx'), 10);
            var n = (res.data.counts && res.data.counts[i]) || 0;
            var pct = total > 0 ? Math.round((n / total) * 100) : 0;
            var bar = opt.querySelector('.chat-poll-bar');
            var cnt = opt.querySelector('.chat-poll-count');
            if (bar) bar.style.width = pct + '%';
            if (cnt) cnt.textContent = String(n);
          });
          var meta = poll.querySelector('.chat-poll-meta');
          if (meta) meta.textContent = total + ' vote' + (total === 1 ? '' : 's');
        });
        break;
      }
      case 'reply-quote':
        e.preventDefault();
        startReplyQuote(parseInt(t.getAttribute('data-msg-id'), 10), t.getAttribute('data-author') || '');
        break;
      case 'clear-reply':
        e.preventDefault();
        clearReplyQuote();
        break;
      case 'open-schedule':
        e.preventDefault();
        openScheduleModal();
        break;
      case 'toggle-details':
        e.preventDefault();
        toggleDetails();
        break;
      case 'toggle-section':
        e.preventDefault();
        toggleSection(t.getAttribute('data-section'));
        break;
      case 'toggle-sidebar':
        e.preventDefault();
        toggleSidebar();
        break;
      case 'attach-file': {
        e.preventDefault();
        var inputId = t.getAttribute('data-file-input-id');
        var inputEl = inputId ? document.getElementById(inputId) : null;
        if (inputEl) inputEl.click();
        break;
      }
      case 'remove-pending-file':
        e.preventDefault();
        removePendingFile(t);
        break;
      case 'open-emoji-composer': {
        e.preventDefault();
        var form = t.closest('.composer');
        var input = form ? form.querySelector('.composer-input') : null;
        openComposerEmojiPicker(t, input);
        break;
      }
      case 'insert-mention': {
        e.preventDefault();
        var form2 = t.closest('.composer');
        var input2 = form2 ? form2.querySelector('.composer-input') : null;
        insertMentionAtCursor(input2);
        break;
      }
      case 'open-msg-menu':
        e.preventDefault();
        e.stopPropagation();
        openMsgMenu(t, parseInt(t.getAttribute('data-msg-id'), 10));
        break;
      case 'edit-message':
        e.preventDefault();
        if (msgMenuTargetId) enterEditMode(msgMenuTargetId);
        break;
      case 'delete-message':
        e.preventDefault();
        var did = msgMenuTargetId;
        closeMsgMenu();
        if (!did) break;
        (window.AntonConfirm
          ? window.AntonConfirm('Delete this message? This can\'t be undone.', { confirmLabel: 'Delete', destructive: true })
          : Promise.resolve(window.confirm('Delete this message? This can\'t be undone.'))
        ).then(function (yes) {
          if (!yes) return;
          apiPost('delete', 'messages.php', { message_id: did }).then(function (res) {
            if (!res.ok) notify(res.data && res.data.message ? res.data.message : 'Failed to delete.', 'error');
            else applyDeletedMessage(did);
          });
        });
        break;
      case 'cancel-edit':
        e.preventDefault();
        cancelEdit(parseInt(t.getAttribute('data-msg-id'), 10));
        break;
      case 'save-edit':
        e.preventDefault();
        saveEdit(parseInt(t.getAttribute('data-msg-id'), 10));
        break;
      case 'open-channel-menu':
        e.preventDefault();
        e.stopPropagation();
        openChannelMenu(t, parseInt(t.getAttribute('data-channel-id'), 10));
        break;
      case 'leave-channel':
        e.preventDefault();
        leaveCurrentChannel();
        break;
      case 'open-user-menu':
        e.preventDefault();
        e.stopPropagation();
        openUserMenu(t);
        break;
      case 'open-status-modal':
        e.preventDefault();
        openStatusModal();
        break;
      case 'toggle-status-emoji-grid':
        e.preventDefault();
        toggleStatusEmojiGrid();
        break;
      case 'clear-status':
        e.preventDefault();
        clearStatus();
        break;
      default:
        break;
    }
  });

  // Status preset chips don't have data-action — wire them up separately.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.status-preset');
    if (!btn) return;
    e.preventDefault();
    applyStatusPreset(btn);
  });
  // Inline emoji-grid cells inside the status modal.
  document.addEventListener('click', function (e) {
    var cell = e.target.closest('.status-emoji-cell');
    if (!cell) return;
    e.preventDefault();
    setStatusEmoji(cell.getAttribute('data-emoji') || '');
  });

  // ===== Hash scroll (#msg-N) — used by search results =====
  function scrollToHashMessage() {
    var hash = String(window.location.hash || '');
    var m = /^#msg-(\d+)$/.exec(hash);
    if (!m) return;
    var el = document.querySelector('.msg-group[data-msg-id="' + m[1] + '"]');
    if (!el || !msgList) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('msg-highlight');
    setTimeout(function () { el.classList.remove('msg-highlight'); }, 1800);
  }
  if (window.location.hash) setTimeout(scrollToHashMessage, 60);

  if (window.console && console.log) {
    var where = CURRENT_CHANNEL_ID
      ? ('channel #' + CURRENT_CHANNEL_SLUG + ' (id ' + CURRENT_CHANNEL_ID + ')')
      : (CURRENT_CONVERSATION_ID
          ? ('DM conversation ' + CURRENT_CONVERSATION_ID)
          : (CURRENT_VIEW ? ('view: ' + CURRENT_VIEW) : '(no channel)'));
    console.log('[Anton Chat] Phase 10 loaded · ' + where);
  }

  // ===================================================================
  // MOBILE — long-press action sheet, jump-to-latest, keyboard scroll
  // ===================================================================
  // On a touch device, the desktop hover-action toolbar (.msg-actions) is
  // invisible. We replace it with an iMessage-style long-press: hold for
  // 380ms on any .msg-group → bottom sheet with react / reply / save /
  // pin / forward / create-task / delete. The actions are derived from
  // the same data-action attributes the hover toolbar uses, so server-
  // side behavior and ACL stay identical.

  if (IS_TOUCH && msgList && window.AntonSheet) {
    var LP_DELAY = 380;
    var lpTimer = null;
    var lpTarget = null;
    var lpStartX = 0, lpStartY = 0;
    var LP_TOLERANCE = 12; // px movement before cancelling the long-press

    function cancelLongPress() {
      if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; }
      if (lpTarget) { lpTarget.classList.remove('msg-longpress'); lpTarget = null; }
    }

    // Touch handlers attached to the scroll container; delegate to the
    // nearest .msg-group so dynamically appended messages work.
    msgList.addEventListener('touchstart', function (e) {
      var grp = e.target.closest('.msg-group');
      if (!grp || grp.classList.contains('day-divider')) return;
      // Ignore long-press if the touch started inside an interactive child
      // (reaction pill, thread preview, file link) — those have their own
      // semantics and the user is targeting them, not the message.
      if (e.target.closest('a, button, .reactions, .thread-preview, .msg-file, .rx-add')) return;
      lpTarget = grp;
      lpStartX = e.touches[0].clientX;
      lpStartY = e.touches[0].clientY;
      lpTimer = setTimeout(function () {
        if (!lpTarget) return;
        // Haptic feedback when supported (Android Chrome).
        if (navigator.vibrate) { try { navigator.vibrate(8); } catch (_) {} }
        openMessageActionSheet(lpTarget);
        lpTimer = null;
      }, LP_DELAY);
    }, { passive: true });

    msgList.addEventListener('touchmove', function (e) {
      if (!lpTimer) return;
      var dx = e.touches[0].clientX - lpStartX;
      var dy = e.touches[0].clientY - lpStartY;
      if (Math.abs(dx) > LP_TOLERANCE || Math.abs(dy) > LP_TOLERANCE) {
        cancelLongPress();
      }
    }, { passive: true });

    msgList.addEventListener('touchend',    cancelLongPress);
    msgList.addEventListener('touchcancel', cancelLongPress);

    function openMessageActionSheet(grp) {
      var mid    = parseInt(grp.getAttribute('data-msg-id'), 10);
      var ownMsg = parseInt(grp.getAttribute('data-user-id'), 10) === CURRENT_USER_ID;
      var isSaved   = !!(grp.querySelector('[data-action="toggle-save"].active'));
      var isPinned  = !!(grp.querySelector('[data-action="toggle-pin"].active'));
      var inChannel = !!(CURRENT_CHANNEL_ID); // pin only makes sense in channels
      var actions = [
        {
          label: 'React',
          icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9.5h.01M15 9.5h.01"/></svg>',
          onClick: function () {
            var addBtn = grp.querySelector('[data-action="add-reaction"]');
            if (addBtn) addBtn.click();
          }
        },
        {
          label: 'Reply in thread',
          icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M9 17l-5-5 5-5"/><path d="M4 12h11a5 5 0 015 5v3"/></svg>',
          onClick: function () { openThread(mid); }
        },
        {
          label: 'Quote in reply',
          icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M7 8h3v3H7v3a3 3 0 003 3M14 8h3v3h-3v3a3 3 0 003 3"/></svg>',
          onClick: function () {
            var quoteBtn = grp.querySelector('[data-action="reply-quote"]');
            if (quoteBtn) quoteBtn.click();
          }
        },
        {
          label: isSaved ? 'Remove from Saved' : 'Save for later',
          icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M6 3h12v18l-6-4-6 4z"/></svg>',
          onClick: function () {
            apiPost(isSaved ? 'unsave' : 'save', 'saved.php', { message_id: mid });
          }
        },
        {
          label: 'Forward',
          icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>',
          onClick: function () {
            // The desktop hover-action triggers a click on the forward
            // button which opens the forward modal scoped to this msg.
            var fwd = grp.querySelector('[data-action="open-forward"]');
            if (fwd) fwd.click();
          }
        },
        {
          label: 'Create task',
          icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>',
          onClick: function () {
            var ct = grp.querySelector('[data-action="open-convert-task"]');
            if (ct) ct.click();
          }
        }
      ];
      if (inChannel) {
        actions.push({
          label: isPinned ? 'Unpin from channel' : 'Pin to channel',
          icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M15 2l7 7-3 1-5 5-1 5-4-4-6 6 6-6-4-4 5-1 5-5z"/></svg>',
          onClick: function () {
            apiPost(isPinned ? 'unpin' : 'pin', 'pinned.php', { message_id: mid });
          }
        });
      }
      // Copy message text — purely client-side, doesn't hit the server.
      actions.push({
        label: 'Copy text',
        icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>',
        onClick: function () {
          var body = grp.querySelector('.msg-body');
          var txt = body ? (body.getAttribute('data-raw-content') || body.textContent || '').trim() : '';
          if (!txt) return;
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(txt).then(function () { notify('Copied'); }, function () { notify('Copy failed', 'error'); });
          } else {
            // Legacy fallback for older mobile browsers.
            var ta = document.createElement('textarea');
            ta.value = txt; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); notify('Copied'); }
            catch (_) { notify('Copy failed', 'error'); }
            document.body.removeChild(ta);
          }
        }
      });
      if (ownMsg) {
        actions.push({
          label: 'Edit message',
          icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
          onClick: function () { enterEditMode(mid); }
        });
        actions.push({
          label: 'Delete message',
          tone: 'danger',
          icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>',
          onClick: function () {
            window.AntonConfirm('Delete this message? This can\'t be undone.', { confirmLabel: 'Delete', destructive: true }).then(function (yes) {
              if (!yes) return;
              apiPost('delete', 'messages.php', { message_id: mid }).then(function (res) {
                if (!res.ok) notify(res.data && res.data.message ? res.data.message : 'Failed to delete.', 'error');
                else applyDeletedMessage(mid);
              });
            });
          }
        });
      }
      window.AntonSheet({
        title: 'Message',
        label: 'Message actions',
        actions: actions,
        cancelLabel: 'Cancel'
      });
    }
  }

  // -------------------- Jump-to-latest FAB --------------------
  // When the user scrolls up and a new message arrives, the message list
  // auto-scroll stays put (so they're not yanked away from what they're
  // reading). To get back, a "Jump to latest" button floats just above
  // the composer. Tapping it returns to the bottom and clears the flag.
  if (msgList) {
    var jumpBtn = document.createElement('button');
    jumpBtn.type = 'button';
    jumpBtn.className = 'cx-jump-latest';
    jumpBtn.setAttribute('aria-label', 'Jump to latest');
    jumpBtn.innerHTML = '<span class="new-dot"></span> Jump to latest';
    // Anchor relative to .cx-main so it floats above the composer.
    var mainEl = document.querySelector('.cx-main');
    if (mainEl) {
      // Ensure .cx-main is positioned for absolute children.
      if (getComputedStyle(mainEl).position === 'static') {
        mainEl.style.position = 'relative';
      }
      // Position above the composer: the composer-wrap sits at the bottom.
      // 16px above; on mobile the composer is fixed, so anchor to viewport
      // using fixed positioning with bottom = composer height (~80) + 8.
      jumpBtn.style.position = 'fixed';
      jumpBtn.style.bottom = 'calc(96px + var(--keyboard-inset, 0px) + var(--sai-bottom))';
      mainEl.appendChild(jumpBtn);
    }

    function showJump() { jumpBtn.classList.add('show'); }
    function hideJump() { jumpBtn.classList.remove('show'); }
    function nearBottom() {
      return msgList.scrollHeight - msgList.scrollTop - msgList.clientHeight < 80;
    }

    msgList.addEventListener('scroll', function () {
      if (nearBottom()) hideJump();
    }, { passive: true });

    // Wrap appendMainMessage so we can flag pending-new when not at
    // bottom. The function exists higher in this IIFE so we monkey-patch
    // via window.__originalAppend — keeps the existing call sites intact.
    var origAppend = typeof appendMainMessage === 'function' ? appendMainMessage : null;
    if (origAppend) {
      window.__cxAppendWrap = function (msg) {
        var wasNear = nearBottom();
        origAppend(msg);
        if (!wasNear) showJump();
      };
      // We can't actually rebind the local `appendMainMessage` inside
      // the closure from here. Instead, we listen on the DOM: when a
      // new .msg-group is added while we're not near bottom, flash the
      // button. MutationObserver is cheap for this use case.
      var obs = new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          var m = muts[i];
          if (m.type === 'childList' && m.addedNodes.length) {
            for (var j = 0; j < m.addedNodes.length; j++) {
              var n = m.addedNodes[j];
              if (n.nodeType === 1 && n.classList && n.classList.contains('msg-group')) {
                if (!nearBottom()) showJump();
                return;
              }
            }
          }
        }
      });
      obs.observe(msgList, { childList: true });
    }

    jumpBtn.addEventListener('click', function () {
      msgList.scrollTop = msgList.scrollHeight;
      hideJump();
    });
  }

  // -------------------- Keyboard-aware composer focus --------------------
  // When the user taps the composer on iOS, the keyboard opens and we
  // need to scroll the message list to the bottom (so the most recent
  // message stays visible above the keyboard, not hidden behind it).
  if (mainInput && msgList) {
    mainInput.addEventListener('focus', function () {
      // Defer one frame so visualViewport has already shrunk and CSS
      // has applied the keyboard inset to the composer.
      setTimeout(function () {
        if (msgList.scrollHeight - msgList.scrollTop - msgList.clientHeight < 200) {
          msgList.scrollTop = msgList.scrollHeight;
        }
      }, 60);
    });
  }
})();
