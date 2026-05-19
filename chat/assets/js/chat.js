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

  function buildMessageEl(msg, grouped) {
    var div = document.createElement('div');
    div.className = 'chat-msg' + (grouped ? ' chat-msg-grouped' : '');
    div.setAttribute('data-msg-id', msg.id);
    div.setAttribute('data-user-id', msg.user_id);
    div.setAttribute('data-msg-ts', Math.floor(new Date(msg.created_at.replace(' ', 'T')).getTime() / 1000));

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
    div.innerHTML =
      '<div class="chat-msg-avatar">' + avatar + '</div>' +
      '<div class="chat-msg-body">' + meta +
        '<div class="chat-msg-text">' + nl2br(msg.content) + '</div>' +
      '</div>';
    return div;
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
      if (!text || !CURRENT_CHANNEL_ID) return;

      var sendBtn = composer.querySelector('.chat-composer-send');
      if (sendBtn) sendBtn.disabled = true;

      apiPost('send', 'messages.php', { channel_id: CURRENT_CHANNEL_ID, content: text })
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

  if (window.console && console.log) {
    console.log('[Anton Chat] Phase 2 loaded',
      CURRENT_CHANNEL_ID ? ('channel #' + CURRENT_CHANNEL_SLUG + ' (id ' + CURRENT_CHANNEL_ID + ')') : '(no channel)');
  }
})();
