// Anton Chat — front-end logic for the channel page.
//
// Phase 2 scope:
//   - Open / close the Create Channel and Browse Channels modals
//   - POST a new message (composer)
//   - Append the user's own newly-sent message to the list
//   - Auto-resize the composer textarea + Enter-to-send
//   - Auto-scroll to bottom on load and on new message
//
// Real-time updates from OTHER users land in Phase 3 (SSE).

(function () {
  'use strict';

  // ===== Boot data =====
  var boot = window.CHAT_BOOT || {};
  var CSRF = boot.csrf || (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var CURRENT_USER_ID = boot.currentUserId || 0;
  var CURRENT_CHANNEL_ID = boot.currentChannelId || null;
  var CURRENT_CHANNEL_SLUG = boot.currentChannelSlug || null;
  var CURRENT_CONVERSATION_ID = boot.currentConversationId || null;

  // ===== Small helpers =====
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function nl2br(s) { return escapeHtml(s).replace(/\n/g, '<br>'); }
  function formatTime(iso) {
    // Match chat_format_message_time() in PHP, roughly.
    var d = new Date(iso.replace(' ', 'T'));
    var now = new Date();
    var sameDay = d.toDateString() === now.toDateString();
    var hh = d.getHours();
    var mm = String(d.getMinutes()).padStart(2, '0');
    var ampm = hh >= 12 ? 'PM' : 'AM';
    hh = hh % 12 || 12;
    if (sameDay) return hh + ':' + mm + ' ' + ampm;
    // For freshly-sent messages it's basically always "today", so the simple
    // path is good enough — older messages come from the server-rendered
    // initial list, which uses the full PHP formatter.
    return d.toLocaleDateString() + ' ' + hh + ':' + mm + ' ' + ampm;
  }
  function initialsOf(name) {
    var parts = String(name || '?').trim().split(/\s+/);
    var first = (parts[0] || '?').charAt(0).toUpperCase();
    var last  = parts.length > 1 ? parts[parts.length - 1].charAt(0).toUpperCase() : (parts[0] || '').charAt(1).toUpperCase() || '';
    return first + last;
  }
  function apiPost(action, file, body) {
    var fd = new FormData();
    Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
    return fetch('/chat/api/' + file + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' },
      body: fd
    }).then(function (r) {
      return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
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
      return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
    });
  }

  // ===== Composer =====
  var composer = $('#chatComposer');
  var input    = $('#chatComposerInput');
  var msgList  = $('#chatMsgs');

  function autoresize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 200) + 'px';
  }

  // Track the previous message's author + timestamp so newly-appended
  // messages match the server-side grouping behaviour (consecutive
  // messages from the same author within 5 min collapse their header).
  function lastMsgInfo() {
    if (!msgList) return null;
    var last = msgList.querySelector('.chat-msg:last-of-type');
    if (!last) return null;
    return {
      userId: parseInt(last.getAttribute('data-user-id'), 10),
      ts:     parseInt(last.getAttribute('data-msg-ts'), 10)
    };
  }

  function buildMessageHtml(msg, grouped) {
    // Produces the same DOM structure as chat/includes/messages_partial.php
    // so server-rendered and JS-appended messages are visually identical
    // (and the same data-action handlers work on both).
    var ts = Math.floor(new Date(String(msg.created_at).replace(' ', 'T')).getTime() / 1000);
    var deleted = msg.deleted_at != null;
    var edited  = msg.edited_at  != null;
    var reactions = msg.reactions || [];
    var replyCt   = parseInt(msg.reply_count, 10) || 0;

    var avatar = '';
    if (!grouped) {
      avatar = '<span class="chat-avatar">' + escapeHtml(initialsOf(msg.author_name)) + '</span>';
    }
    var meta = '';
    if (!grouped) {
      meta = '<div class="chat-msg-meta">'
           +   '<span class="chat-msg-name">' + escapeHtml(msg.author_name) + '</span>'
           +   '<span class="chat-msg-time">' + escapeHtml(formatTime(msg.created_at)) + '</span>'
           + '</div>';
    }

    var textHtml;
    if (deleted) {
      textHtml = '<em>This message was deleted.</em>';
    } else {
      // Prefer server-rendered content_html (handles @mentions etc.). Fall
      // back to plain escape + nl2br if it's a bare message.
      textHtml = msg.content_html || nl2br(msg.content || '');
      if (edited) textHtml += ' <span class="chat-msg-edited">(edited)</span>';
    }

    var reactionsHtml = '';
    if (reactions.length) {
      reactionsHtml = '<div class="chat-reactions" data-msg-id="' + msg.id + '">' +
        reactions.map(function (rx) {
          var idsRaw = String(rx.user_ids || '');
          var idsArr = idsRaw ? idsRaw.split(',').map(function (x) { return parseInt(x, 10); }) : [];
          var me     = idsArr.indexOf(CURRENT_USER_ID) !== -1;
          return '<button type="button" class="chat-reaction' + (me ? ' chat-reaction-mine' : '') + '"'
               + ' data-action="toggle-reaction" data-msg-id="' + msg.id
               + '" data-emoji="' + escapeHtml(rx.emoji) + '">'
               +   '<span class="chat-reaction-emoji">' + escapeHtml(rx.emoji) + '</span>'
               +   '<span class="chat-reaction-count">' + (parseInt(rx.count, 10) || 0) + '</span>'
               + '</button>';
        }).join('') +
      '</div>';
    }

    var replyBtnHtml = '';
    if (replyCt > 0) {
      replyBtnHtml = '<button type="button" class="chat-reply-count" data-action="open-thread" data-msg-id="' + msg.id + '">'
                   +   replyCt + ' repl' + (replyCt === 1 ? 'y' : 'ies')
                   + '</button>';
    }

    var actionsHtml = '';
    if (!deleted) {
      actionsHtml =
        '<div class="chat-msg-actions" aria-hidden="true">'
        +   '<button type="button" class="chat-msg-action" data-action="add-reaction" data-msg-id="' + msg.id + '" title="Add reaction">'
        +     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M8 14s1.5 2 4 2 4-2 4-2"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="15" y1="9" x2="15.01" y2="9"></line></svg>'
        +   '</button>'
        +   '<button type="button" class="chat-msg-action" data-action="open-thread" data-msg-id="' + msg.id + '" title="Reply in thread">'
        +     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>'
        +   '</button>'
        + '</div>';
    }

    return '<div class="chat-msg' + (grouped ? ' chat-msg-grouped' : '') + '"'
         + ' data-msg-id="' + msg.id + '" data-user-id="' + msg.user_id + '" data-msg-ts="' + ts + '">'
         +   '<div class="chat-msg-avatar">' + avatar + '</div>'
         +   '<div class="chat-msg-body">' + meta
         +     '<div class="chat-msg-text' + (deleted ? ' chat-msg-deleted' : '') + '">' + textHtml + '</div>'
         +     reactionsHtml + replyBtnHtml
         +   '</div>'
         +   actionsHtml
         + '</div>';
  }

  function buildMessageEl(msg, grouped) {
    var temp = document.createElement('template');
    temp.innerHTML = buildMessageHtml(msg, grouped);
    return temp.content.firstElementChild;
  }

  function scrollMsgsToBottom() {
    if (!msgList) return;
    msgList.scrollTop = msgList.scrollHeight;
  }

  // On first paint, scroll the message list to the bottom (most-recent).
  if (msgList) scrollMsgsToBottom();
  if (input) {
    autoresize(input);
    // Focus the composer so the user can just type.
    requestAnimationFrame(function () { input.focus(); });
    input.addEventListener('input', function () { autoresize(input); });
    input.addEventListener('keydown', function (e) {
      // Enter = send, Shift+Enter = newline. (Configurable per
      // chat_user_prefs.enter_to_send in a later phase.)
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (composer) composer.dispatchEvent(new Event('submit', { cancelable: true }));
      }
    });
  }

  if (composer) {
    composer.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = (input.value || '').trim();
      if (!text || (!CURRENT_CHANNEL_ID && !CURRENT_CONVERSATION_ID)) return;

      var sendBtn = composer.querySelector('.chat-composer-send');
      if (sendBtn) sendBtn.disabled = true;

      // Route to channel OR DM based on which target is set in CHAT_BOOT.
      var sendBody = { content: text };
      if (CURRENT_CHANNEL_ID)      sendBody.channel_id      = CURRENT_CHANNEL_ID;
      if (CURRENT_CONVERSATION_ID) sendBody.conversation_id = CURRENT_CONVERSATION_ID;

      apiPost('send', 'messages.php', sendBody)
        .then(function (res) {
          if (sendBtn) sendBtn.disabled = false;
          if (!res.ok) {
            alert(res.data && res.data.message ? res.data.message : 'Failed to send.');
            return;
          }
          var msg = res.data.message;
          var prev = lastMsgInfo();
          var grouped = prev && prev.userId === msg.user_id &&
                        ((Math.floor(new Date(msg.created_at.replace(' ', 'T')).getTime() / 1000) - prev.ts) < 300);
          // If the channel was empty, remove the "beginning of #X" placeholder.
          var emptyState = msgList.querySelector('.chat-msgs-empty');
          if (emptyState) emptyState.remove();
          msgList.appendChild(buildMessageEl(msg, grouped));
          input.value = '';
          autoresize(input);
          scrollMsgsToBottom();
          input.focus();
        })
        .catch(function () {
          if (sendBtn) sendBtn.disabled = false;
          alert('Network error — message not sent.');
        });
    });
  }

  // ===== Modal plumbing =====
  function openDialog(id) {
    var d = document.getElementById(id);
    if (!d) return;
    if (typeof d.showModal === 'function') d.showModal();
    else d.setAttribute('open', '');
  }
  function closeDialog(d) {
    if (!d) return;
    if (typeof d.close === 'function') d.close();
    else d.removeAttribute('open');
  }

  // Click on backdrop (the dialog element itself, outside its form) closes.
  $$('dialog.chat-modal').forEach(function (d) {
    d.addEventListener('click', function (e) {
      if (e.target === d) closeDialog(d);
    });
  });

  // Global delegated handler for buttons with data-action.
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-action]');
    if (!t) return;
    var action = t.getAttribute('data-action');
    switch (action) {
      case 'open-create-channel':
        e.preventDefault();
        var errEl = $('#createChannelErr');
        if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
        var form = $('#createChannelForm');
        if (form) form.reset();
        openDialog('modalCreateChannel');
        var slugInput = $('#createChannelSlug');
        if (slugInput) requestAnimationFrame(function () { slugInput.focus(); });
        break;
      case 'open-browse-channels':
        e.preventDefault();
        openDialog('modalBrowseChannels');
        loadBrowseList();
        break;
      case 'close-modal':
        e.preventDefault();
        var dlg = t.closest('dialog');
        closeDialog(dlg);
        break;
      case 'join-channel':
        e.preventDefault();
        joinAndOpen(parseInt(t.getAttribute('data-channel-id'), 10), t.getAttribute('data-channel-slug'), t);
        break;
      case 'open-new-dm':
        e.preventDefault();
        openNewDmModal();
        break;
      case 'add-reaction':
        e.preventDefault();
        openEmojiPicker(t, parseInt(t.getAttribute('data-msg-id'), 10));
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
    }
  });

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
    listEl.innerHTML = '<div class="chat-modal-loading">Loading…</div>';
    apiGet('directory', 'channels.php', {})
      .then(function (res) {
        if (!res.ok) {
          listEl.innerHTML = '<div class="chat-modal-loading">Failed to load channels.</div>';
          return;
        }
        var channels = res.data.channels || [];
        if (!channels.length) {
          listEl.innerHTML = '<div class="chat-modal-loading">No public channels yet. Create one to get started.</div>';
          return;
        }
        listEl.innerHTML = '';
        channels.forEach(function (c) {
          var row = document.createElement('div');
          row.className = 'chat-browse-row';
          var members = parseInt(c.member_count, 10);
          var isMember = parseInt(c.is_member, 10) === 1;
          row.innerHTML =
            '<div class="chat-browse-info">' +
              '<div class="chat-browse-name"><span class="chat-browse-prefix">#</span>' + escapeHtml(c.slug) + '</div>' +
              '<div class="chat-browse-meta">' +
                members + ' member' + (members === 1 ? '' : 's') +
                (c.topic ? ' · ' + escapeHtml(c.topic) : '') +
              '</div>' +
            '</div>' +
            (isMember
              ? '<span class="chat-browse-joined">Joined</span>'
              : '<button type="button" class="chat-btn chat-btn-primary" data-action="join-channel" data-channel-id="' + c.id + '" data-channel-slug="' + escapeHtml(c.slug) + '">Join</button>');
          listEl.appendChild(row);
        });
      })
      .catch(function () {
        listEl.innerHTML = '<div class="chat-modal-loading">Network error.</div>';
      });
  }

  function joinAndOpen(channelId, slug, button) {
    if (!channelId) return;
    if (button) button.disabled = true;
    apiPost('join', 'channels.php', { channel_id: channelId })
      .then(function (res) {
        if (!res.ok) {
          if (button) button.disabled = false;
          alert(res.data && res.data.message ? res.data.message : 'Could not join.');
          return;
        }
        window.location.href = '/chat/?c=' + encodeURIComponent(slug);
      })
      .catch(function () {
        if (button) button.disabled = false;
        alert('Network error — could not join.');
      });
  }

  // ===== New direct message (Phase 4) ================================
  // Modal that lists workspace users and lets the user pick 1 (1-on-1) or
  // 2–7 (group). On submit, POSTs to dms.php; 1-on-1 is idempotent via
  // dm_key, group always creates a new conversation. Navigates to
  // /chat/?d=<id> on success.

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
    listEl.innerHTML = '<div class="chat-modal-loading">Loading…</div>';
    apiGet('people', 'dms.php', {})
      .then(function (res) {
        if (!res.ok) {
          listEl.innerHTML = '<div class="chat-modal-loading">Failed to load.</div>';
          return;
        }
        peopleCache = res.data.people || [];
        renderPeopleList(filter);
      })
      .catch(function () {
        listEl.innerHTML = '<div class="chat-modal-loading">Network error.</div>';
      });
  }

  function renderPeopleList(filter) {
    var listEl = $('#newDmPeopleList');
    if (!listEl || !peopleCache) return;
    var people = peopleCache;
    if (filter) {
      var f = filter.toLowerCase();
      people = people.filter(function (p) { return String(p.name).toLowerCase().indexOf(f) !== -1; });
    }
    if (!people.length) {
      listEl.innerHTML = '<div class="chat-modal-loading">No people match.</div>';
      return;
    }
    listEl.innerHTML = '';
    people.forEach(function (p) {
      var row = document.createElement('label');
      row.className = 'chat-people-row';
      var checked = selectedUserIds.indexOf(p.id) !== -1;
      row.innerHTML =
        '<input type="checkbox" data-user-id="' + p.id + '"' + (checked ? ' checked' : '') + '>' +
        '<span class="chat-people-avatar">' + escapeHtml(initialsOf(p.name)) + '</span>' +
        '<span class="chat-people-name">' + escapeHtml(p.name) + '</span>';
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
      else              meta.textContent = n + ' people selected · group DM (' + (n + 1) + ' total with you)';
    }
    if (btn) {
      btn.disabled = n === 0 || n > 7;
      btn.textContent = n > 1 ? 'Start group DM' : 'Start DM';
    }
  }

  var newDmSearch = $('#newDmSearch');
  if (newDmSearch) {
    newDmSearch.addEventListener('input', function () {
      renderPeopleList(this.value.trim());
    });
  }

  var newDmStartBtn = $('#newDmStartBtn');
  if (newDmStartBtn) {
    newDmStartBtn.addEventListener('click', function () {
      if (!selectedUserIds.length) return;
      var errEl = $('#newDmErr');
      if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
      newDmStartBtn.disabled = true;

      // FormData with user_ids[] entries — same shape PHP expects.
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
          if (errEl) { errEl.textContent = 'Network error. Try again.'; errEl.hidden = false; }
        });
    });
  }

  // ===== Live updates (Phase 3) — SSE with polling fallback ===========
  //
  // EventSource holds a connection to /chat/api/events.php for ~25s, the
  // server closes, and the browser auto-reconnects with a Last-Event-ID
  // header so we resume exactly where we left off. If three SSE attempts
  // fail in quick succession (e.g. a proxy strips the stream), JS quietly
  // switches to JSON polling every 3 seconds against the same endpoint.

  var sse = null;
  var pollTimer = null;
  var sseFailures = 0;
  var sseOpenedAt = 0;
  var lastEventId = parseInt(boot.lastEventId || 0, 10);
  var modeIsPolling = false;

  function isNearBottom(el, threshold) {
    return el.scrollHeight - el.scrollTop - el.clientHeight < (threshold || 40);
  }

  function startSse() {
    if (modeIsPolling) return;
    try {
      sse = new EventSource('/chat/api/events.php?since=' + lastEventId);
    } catch (e) {
      switchToPolling();
      return;
    }
    sseOpenedAt = Date.now();
    sse.addEventListener('open', function () { sseFailures = 0; });
    sse.addEventListener('error', function () {
      // EventSource fires 'error' on real failures AND on the server's
      // normal 25-second close. Distinguish by how long this attempt ran.
      var openMs = Date.now() - sseOpenedAt;
      if (openMs > 5000) {
        // Long enough that this was a healthy session — let the browser
        // reconnect normally.
        sseFailures = 0;
        return;
      }
      sseFailures++;
      if (sseFailures >= 3) {
        try { sse.close(); } catch (e) {}
        switchToPolling();
      }
    });

    // Our server always sets `event: <type>`, so we subscribe per-type.
    // 'message' is also the EventSource default name when no `event:`
    // line is sent, but our server always names it explicitly.
    sse.addEventListener('message',                handleEvent);
    sse.addEventListener('channel_created',        handleEvent);
    sse.addEventListener('channel_member_added',   handleEvent);
    sse.addEventListener('channel_member_removed', handleEvent);
    sse.addEventListener('message_edited',         handleEvent);
    sse.addEventListener('message_deleted',        handleEvent);
  }

  function switchToPolling() {
    if (modeIsPolling) return;
    modeIsPolling = true;
    if (window.console && console.warn) {
      console.warn('[Anton Chat] SSE failed 3 times — falling back to polling');
    }
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
      .catch(function () { /* swallow — next tick will retry */ });
  }

  // Accepts either an EventSource MessageEvent (evt.data is a JSON string)
  // or a pre-parsed event object (from pollOnce). Normalizes and dispatches.
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
      // Other event types received but not rendered yet — Phase 8 wires up
      // unread + presence; dm_created may want a sidebar refresh someday.
      default:
        break;
    }
  }

  function onMessageEvent(ev) {
    // Thread reply? Route to the thread panel (and bump the parent count
    // in the main pane). Identified by parent_message_id in the event
    // payload, which the server sets for thread replies in msg_action_send.
    var parentId = (ev.payload && ev.payload.parent_message_id)
      ? parseInt(ev.payload.parent_message_id, 10) : null;
    if (parentId) {
      onThreadReplyEvent(ev, parentId);
      return;
    }

    // Top-level message: only append to the visible pane if the event is
    // for the current channel OR the current DM conversation.
    if (!msgList) return;
    var eventChannelId = ev.channel_id != null ? parseInt(ev.channel_id, 10) : null;
    var eventConvId    = ev.conversation_id != null ? parseInt(ev.conversation_id, 10) : null;
    var matchesChannel = CURRENT_CHANNEL_ID && eventChannelId === CURRENT_CHANNEL_ID;
    var matchesDm      = CURRENT_CONVERSATION_ID && eventConvId === CURRENT_CONVERSATION_ID;
    if (!matchesChannel && !matchesDm) return;
    // Dedup: if we already have this message in the DOM (e.g. we just sent
    // it ourselves and appended it locally), don't double-render.
    if (msgList.querySelector('[data-msg-id="' + ev.message_id + '"]')) return;

    var msg = sseEventToMsg(ev);

    var wasAtBottom = isNearBottom(msgList, 60);
    var prev = lastMsgInfo();
    var grouped = prev && prev.userId === msg.user_id &&
                  ((Math.floor(new Date(msg.created_at.replace(' ', 'T')).getTime() / 1000) - prev.ts) < 300);

    var empty = msgList.querySelector('.chat-msgs-empty');
    if (empty) empty.remove();
    msgList.appendChild(buildMessageEl(msg, grouped));
    if (wasAtBottom) scrollMsgsToBottom();
  }

  // Convert an SSE message event into a msg object compatible with
  // buildMessageHtml / buildMessageEl.
  function sseEventToMsg(ev) {
    return {
      id:           ev.message_id,
      user_id:      parseInt(ev.message_user_id, 10),
      author_name:  ev.author_name,
      content:      ev.message_content      || '',
      content_html: ev.message_content_html || '',
      created_at:   ev.message_created_at,
      edited_at:    ev.message_edited_at,
      deleted_at:   ev.message_deleted_at,
      reactions:    [],
      reply_count:  0
    };
  }

  // Kick off the live stream.
  startSse();

  // ===== Reactions, threads, mentions (Phase 5) =======================

  // --- Reactions: emoji picker pop-up + toggle endpoint ----------------
  var emojiPicker = null;       // lazily created <emoji-picker> element
  var emojiTargetMsgId = null;  // which message the next pick will attach to

  function openEmojiPicker(button, msgId) {
    emojiTargetMsgId = msgId;
    var popup = $('#chatEmojiPopup');
    if (!popup) return;
    if (!emojiPicker) {
      emojiPicker = document.createElement('emoji-picker');
      emojiPicker.addEventListener('emoji-click', function (e) {
        var emoji = e && e.detail && e.detail.unicode;
        if (!emoji || !emojiTargetMsgId) return;
        toggleReaction(emojiTargetMsgId, emoji);
        closeEmojiPicker();
      });
      popup.appendChild(emojiPicker);
    }
    popup.hidden = false;
    // Position above the clicked button, clamped to viewport.
    var rect = button.getBoundingClientRect();
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
        if (!res.ok) {
          if (window.console) console.warn('reaction toggle failed', res.data);
          return;
        }
        // The endpoint returns the post-toggle reactions list. SSE will
        // also push it to everyone else.
        updateMessageReactions(msgId, res.data.reactions || []);
      });
  }

  // Re-render the .chat-reactions block for a message, given the full
  // reactions array. Adds the block if it didn't exist; removes it if
  // there are no reactions left.
  function updateMessageReactions(msgId, reactions) {
    // Update in both the main pane AND the thread panel if the same msg
    // is visible in both.
    document.querySelectorAll('.chat-msg[data-msg-id="' + msgId + '"]').forEach(function (msgEl) {
      var body = msgEl.querySelector('.chat-msg-body');
      if (!body) return;
      var existing = body.querySelector('.chat-reactions');
      if (!reactions || !reactions.length) { if (existing) existing.remove(); return; }

      var html = reactions.map(function (rx) {
        var idsRaw = String(rx.user_ids || '');
        var idsArr = idsRaw ? idsRaw.split(',').map(function (x) { return parseInt(x, 10); }) : [];
        var me     = idsArr.indexOf(CURRENT_USER_ID) !== -1;
        return '<button type="button" class="chat-reaction' + (me ? ' chat-reaction-mine' : '') + '"'
             + ' data-action="toggle-reaction" data-msg-id="' + msgId
             + '" data-emoji="' + escapeHtml(rx.emoji) + '">'
             +   '<span class="chat-reaction-emoji">' + escapeHtml(rx.emoji) + '</span>'
             +   '<span class="chat-reaction-count">' + (parseInt(rx.count, 10) || 0) + '</span>'
             + '</button>';
      }).join('');

      if (existing) {
        existing.innerHTML = html;
      } else {
        var div = document.createElement('div');
        div.className = 'chat-reactions';
        div.setAttribute('data-msg-id', String(msgId));
        div.innerHTML = html;
        // Insert before .chat-reply-count if it exists, else append to body.
        var replyBtn = body.querySelector('.chat-reply-count');
        if (replyBtn) body.insertBefore(div, replyBtn);
        else body.appendChild(div);
      }
    });
  }

  // --- Threads: side panel, fetch + render replies, send reply ---------
  var threadParentId = null;
  var threadPanel    = $('#chatThread');
  var threadList     = $('#chatThreadList');
  var threadComposer = $('#chatThreadComposer');
  var threadInput    = $('#chatThreadInput');

  function openThread(parentId) {
    if (!threadPanel || !threadList) return;
    threadParentId = parentId;
    threadPanel.hidden = false;
    threadList.innerHTML = '<div class="chat-modal-loading">Loading…</div>';

    // Server returns the *replies* only — render the parent from the
    // main-pane DOM we already have.
    apiGet('list', 'messages.php', { parent_message_id: parentId })
      .then(function (res) {
        if (!res.ok) {
          threadList.innerHTML = '<div class="chat-modal-loading">Could not load thread.</div>';
          return;
        }
        var parentEl = document.querySelector('#chatMsgs .chat-msg[data-msg-id="' + parentId + '"]');
        var parentHtml = parentEl ? parentEl.outerHTML : '<div class="chat-modal-loading">Original message unavailable.</div>';
        var replies = res.data.messages || [];
        var dividerLabel = replies.length === 0
          ? 'No replies yet'
          : (replies.length + ' repl' + (replies.length === 1 ? 'y' : 'ies'));
        var dividerHtml = '<div class="chat-thread-divider"><span>' + escapeHtml(dividerLabel) + '</span></div>';
        var repliesHtml = replies.map(function (m) { return buildMessageHtml(m, false); }).join('');
        threadList.innerHTML = parentHtml + dividerHtml + repliesHtml;
        threadList.scrollTop = threadList.scrollHeight;

        if (threadComposer) threadComposer.setAttribute('data-parent-message-id', String(parentId));
        if (threadInput) {
          autoresize(threadInput);
          requestAnimationFrame(function () { threadInput.focus(); });
        }
      })
      .catch(function () {
        threadList.innerHTML = '<div class="chat-modal-loading">Network error.</div>';
      });
  }

  function closeThread() {
    threadParentId = null;
    if (threadPanel) threadPanel.hidden = true;
    if (threadInput) { threadInput.value = ''; autoresize(threadInput); }
  }

  // When SSE delivers a thread reply: append to the open panel (if it's
  // for the same parent) and bump the parent's "X replies" badge.
  function onThreadReplyEvent(ev, parentId) {
    if (threadParentId === parentId && threadList) {
      var msg = sseEventToMsg(ev);
      if (!threadList.querySelector('.chat-msg[data-msg-id="' + msg.id + '"]')) {
        // Replace "No replies yet" divider if present.
        var divider = threadList.querySelector('.chat-thread-divider');
        if (divider && /No replies yet/.test(divider.textContent)) {
          divider.innerHTML = '<span>1 reply</span>';
        }
        threadList.insertAdjacentHTML('beforeend', buildMessageHtml(msg, false));
        threadList.scrollTop = threadList.scrollHeight;
      }
    }
    bumpReplyCount(parentId);
  }

  function bumpReplyCount(parentId) {
    var parentEl = document.querySelector('#chatMsgs .chat-msg[data-msg-id="' + parentId + '"]');
    if (!parentEl) return;
    var body = parentEl.querySelector('.chat-msg-body');
    if (!body) return;
    var btn = body.querySelector('.chat-reply-count');
    if (btn) {
      var n = (parseInt(btn.textContent, 10) || 0) + 1;
      btn.textContent = n + ' repl' + (n === 1 ? 'y' : 'ies');
    } else {
      var newBtn = document.createElement('button');
      newBtn.type = 'button';
      newBtn.className = 'chat-reply-count';
      newBtn.setAttribute('data-action', 'open-thread');
      newBtn.setAttribute('data-msg-id', String(parentId));
      newBtn.textContent = '1 reply';
      body.appendChild(newBtn);
    }
  }

  // Thread composer submit
  if (threadComposer) {
    threadComposer.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!threadParentId || !threadInput) return;
      var text = (threadInput.value || '').trim();
      if (!text) return;
      var btn = threadComposer.querySelector('.chat-composer-send');
      if (btn) btn.disabled = true;
      apiPost('send', 'messages.php', { parent_message_id: threadParentId, content: text })
        .then(function (res) {
          if (btn) btn.disabled = false;
          if (!res.ok) {
            alert(res.data && res.data.message ? res.data.message : 'Failed to send reply.');
            return;
          }
          var msg = res.data.message;
          // Append locally; SSE echo will be deduped by data-msg-id.
          if (threadList && !threadList.querySelector('.chat-msg[data-msg-id="' + msg.id + '"]')) {
            var divider = threadList.querySelector('.chat-thread-divider');
            if (divider && /No replies yet/.test(divider.textContent)) {
              divider.innerHTML = '<span>1 reply</span>';
            }
            threadList.insertAdjacentHTML('beforeend', buildMessageHtml(msg, false));
            threadList.scrollTop = threadList.scrollHeight;
          }
          threadInput.value = '';
          autoresize(threadInput);
          threadInput.focus();
          bumpReplyCount(threadParentId);
        });
    });
  }
  if (threadInput) {
    threadInput.addEventListener('input', function () { autoresize(threadInput); });
    threadInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        // Let the mention popup intercept first.
        if (mentionPopup && !mentionPopup.hidden && mentionItems.length) return;
        e.preventDefault();
        threadComposer && threadComposer.dispatchEvent(new Event('submit', { cancelable: true }));
      }
    });
  }

  // --- @mention autocomplete: one popup, attached to both composers ----
  var mentionPopup       = $('#chatMentionPopup');
  var mentionAnchorInput = null;
  var mentionItems       = [];
  var mentionActiveIndex = 0;
  var mentionPeopleCache = null;

  function loadMentionPeople(cb) {
    if (mentionPeopleCache) { cb(mentionPeopleCache); return; }
    apiGet('people', 'dms.php', {}).then(function (res) {
      mentionPeopleCache = (res && res.ok && res.data && res.data.people) ? res.data.people : [];
      cb(mentionPeopleCache);
    });
  }

  function detectMentionTrigger(textareaEl) {
    var pos = textareaEl.selectionStart;
    var before = textareaEl.value.substring(0, pos);
    var m = before.match(/(?:^|[^a-z0-9_])@([a-z0-9_-]*)$/i);
    if (!m) return null;
    return { query: m[1].toLowerCase() };
  }

  function specialSub(s) {
    if (s === 'channel')  return 'Everyone in this channel';
    if (s === 'here')     return 'Online members';
    if (s === 'everyone') return 'Everyone in the workspace';
    return '';
  }

  function showMentionPopup(textareaEl, query) {
    if (!mentionPopup) return;
    mentionAnchorInput = textareaEl;
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
      positionMentionPopup(textareaEl);
    });
  }

  function renderMentionPopup() {
    if (!mentionPopup) return;
    mentionPopup.innerHTML = mentionItems.map(function (item, i) {
      var avatarClass = item.special ? 'chat-mention-item-avatar chat-mention-item-avatar-special' : 'chat-mention-item-avatar';
      var avatarText  = item.special ? '@' : initialsOf(item.name.replace(/^@/, ''));
      return '<div class="chat-mention-item' + (i === mentionActiveIndex ? ' active' : '') + '" data-mention-index="' + i + '">'
           +   '<span class="' + avatarClass + '">' + escapeHtml(avatarText) + '</span>'
           +   '<span class="chat-mention-item-name">' + escapeHtml(item.name) + '</span>'
           +   '<span class="chat-mention-item-sub">' + escapeHtml(item.sub || '') + '</span>'
           + '</div>';
    }).join('');
    mentionPopup.hidden = false;
  }

  function positionMentionPopup(textareaEl) {
    if (!mentionPopup) return;
    var rect = textareaEl.getBoundingClientRect();
    mentionPopup.hidden = false;
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
  }

  function insertMention(textareaEl, item) {
    var pos = textareaEl.selectionStart;
    var text = textareaEl.value;
    var before = text.substring(0, pos);
    var after  = text.substring(pos);
    var newBefore = before.replace(/@[a-z0-9_-]*$/i, '@' + item.insert);
    textareaEl.value = newBefore + ' ' + after;
    var caret = newBefore.length + 1;
    textareaEl.selectionStart = textareaEl.selectionEnd = caret;
    hideMentionPopup();
    autoresize(textareaEl);
  }

  function attachMentionHandler(textareaEl) {
    if (!textareaEl) return;
    textareaEl.addEventListener('input', function () {
      var trigger = detectMentionTrigger(this);
      if (trigger) showMentionPopup(this, trigger.query);
      else hideMentionPopup();
    });
    textareaEl.addEventListener('keydown', function (e) {
      if (!mentionPopup || mentionPopup.hidden || mentionItems.length === 0) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        mentionActiveIndex = (mentionActiveIndex + 1) % mentionItems.length;
        renderMentionPopup();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        mentionActiveIndex = (mentionActiveIndex - 1 + mentionItems.length) % mentionItems.length;
        renderMentionPopup();
      } else if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        e.stopPropagation();
        insertMention(this, mentionItems[mentionActiveIndex]);
      } else if (e.key === 'Escape') {
        e.preventDefault();
        hideMentionPopup();
      }
    });
  }

  attachMentionHandler($('#chatComposerInput'));
  attachMentionHandler($('#chatThreadInput'));

  if (mentionPopup) {
    mentionPopup.addEventListener('mousedown', function (e) {
      // mousedown (not click) so we beat the focus loss on the textarea.
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

  // Outside-click + Escape: close emoji picker and mention popup.
  document.addEventListener('mousedown', function (e) {
    var picker = $('#chatEmojiPopup');
    if (picker && !picker.hidden &&
        !picker.contains(e.target) &&
        !e.target.closest('[data-action="add-reaction"]')) {
      closeEmojiPicker();
    }
    if (mentionPopup && !mentionPopup.hidden &&
        !mentionPopup.contains(e.target) && e.target !== mentionAnchorInput) {
      hideMentionPopup();
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeEmojiPicker();
  });

  if (window.console && console.log) {
    var where = CURRENT_CHANNEL_ID
      ? ('channel #' + CURRENT_CHANNEL_SLUG + ' (id ' + CURRENT_CHANNEL_ID + ')')
      : (CURRENT_CONVERSATION_ID
          ? ('DM conversation ' + CURRENT_CONVERSATION_ID)
          : '(no channel)');
    console.log('[Anton Chat] Phase 5 loaded', where);
  }
})();
