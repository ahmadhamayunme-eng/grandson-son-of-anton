/* task_attachments.js — attach-on-create uploader for the "New task" popups.
 *
 * Auto-initialises every [data-ta-uploader] on the page. Mirrors the Anton chat
 * composer upload UX: each chosen file uploads immediately to
 * upload_task_attachment_stage.php via XMLHttpRequest (so we can show a real
 * progress bar), and on success a hidden <input name="attachment_ids[]"> is
 * added to the surrounding <form>. When the task is created, the server claims
 * those staged files. No external dependencies. */
(function () {
  'use strict';
  if (window.__taUploaderInit) return;
  window.__taUploaderInit = true;

  // Resolve the endpoint as an ABSOLUTE same-origin URL, derived from where
  // this script itself was loaded (…/styles/task_attachments.js). A relative
  // URL would resolve against the current page path — which breaks on
  // pretty-URL pages and can produce a cross-origin URL that the
  // `connect-src 'self'` CSP blocks, surfacing as an immediate "Network error"
  // with no HTTP response. Mirrors how chat.js posts to an absolute path.
  function endpointUrl() {
    var s = document.querySelector('script[src*="task_attachments.js"]');
    if (s && s.src) {
      var base = s.src.replace(/styles\/task_attachments\.js.*$/, '');
      if (base !== s.src) return base + 'upload_task_attachment_stage.php';
    }
    return new URL('upload_task_attachment_stage.php', document.baseURI).href;
  }
  var ENDPOINT = endpointUrl();
  var MAX_BYTES = 500 * 1024 * 1024; // 500 MB — matches the server cap.

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function formatBytes(n) {
    n = Number(n) || 0;
    if (n >= 1073741824) return (n / 1073741824).toFixed(2) + ' GB';
    if (n >= 1048576)    return (n / 1048576).toFixed(1) + ' MB';
    if (n >= 1024)       return (n / 1024).toFixed(0) + ' KB';
    return n + ' B';
  }
  function csrfFor(form) {
    var meta = document.querySelector('meta[name="csrf-token"]');
    var input = form ? form.querySelector('input[name="csrf"]') : null;
    return (input && input.value) || (meta && meta.getAttribute('content')) || '';
  }

  function uploadFile(uploader, file) {
    var form    = uploader.closest('form');
    var pending = uploader.querySelector('.ta-pending');
    if (!pending) return;
    pending.hidden = false;

    var tempId  = 'ta-' + Math.random().toString(36).slice(2);
    var isImage = file.type && file.type.indexOf('image/') === 0;
    var thumb   = isImage
      ? '<img src="' + URL.createObjectURL(file) + '" alt="">'
      : '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
    var totalLabel = formatBytes(file.size || 0);

    pending.insertAdjacentHTML('beforeend',
      '<div class="pending-file pending-file-uploading" data-tmp-id="' + tempId + '">' +
        '<span class="pending-file-thumb">' + thumb + '</span>' +
        '<span class="pending-file-info">' +
          '<span class="pending-file-name">' + escapeHtml(file.name) + '</span>' +
          '<span class="pending-file-meta">0 B / ' + escapeHtml(totalLabel) + ' (0%)</span>' +
          '<span class="pending-file-bar"><span class="pending-file-bar-fill" style="width:0%"></span></span>' +
        '</span>' +
        '<button type="button" class="pending-file-remove" data-tmp-id="' + tempId + '" aria-label="Remove">×</button>' +
      '</div>');

    function pill()     { return pending.querySelector('[data-tmp-id="' + tempId + '"]'); }
    function setMeta(t) { var p = pill(); if (p) { var m = p.querySelector('.pending-file-meta'); if (m) m.textContent = t; } }
    function setBar(pc) { var p = pill(); if (p) { var f = p.querySelector('.pending-file-bar-fill'); if (f) f.style.width = Math.max(0, Math.min(100, pc)) + '%'; } }
    function markError(msg) {
      var p = pill(); if (!p) return;
      p.classList.remove('pending-file-uploading');
      p.classList.add('pending-file-error');
      setMeta(msg || 'Upload failed');
      var bar = p.querySelector('.pending-file-bar'); if (bar) bar.remove();
    }

    // Client-side guard so an oversized file fails instantly with a clear message.
    if ((file.size || 0) > MAX_BYTES) { markError('File exceeds the 500 MB limit'); return; }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', ENDPOINT, true);
    xhr.setRequestHeader('X-CSRF-Token', csrfFor(form));
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.withCredentials = true;

    xhr.upload.addEventListener('progress', function (e) {
      if (!e.lengthComputable) { setMeta(formatBytes(e.loaded || 0) + ' / ' + totalLabel); return; }
      var pct = Math.round((e.loaded / e.total) * 100);
      setMeta(formatBytes(e.loaded) + ' / ' + formatBytes(e.total) + ' (' + pct + '%)');
      setBar(pct);
    });

    xhr.onload = function () {
      var data = null;
      try { data = JSON.parse(xhr.responseText || '{}'); } catch (e) { data = null; }
      var ok = xhr.status >= 200 && xhr.status < 300;
      if (!ok || !data || !data.file) {
        markError((data && data.message) || 'Upload failed (' + xhr.status + ')');
        return;
      }
      var f = data.file, p = pill();
      if (form) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'attachment_ids[]';
        hidden.value = f.id;
        hidden.setAttribute('data-tmp-id', tempId);
        form.appendChild(hidden);
      }
      if (p) {
        p.classList.remove('pending-file-uploading');
        p.setAttribute('data-file-id', f.id);
        setBar(100);
        setMeta(formatBytes(parseInt(f.size, 10) || 0));
        var bar = p.querySelector('.pending-file-bar'); if (bar) bar.remove();
      }
    };
    xhr.onerror   = function () { markError('Network error'); };
    xhr.onabort   = function () { markError('Upload cancelled'); };
    xhr.ontimeout = function () { markError('Upload timed out'); };

    var fd = new FormData();
    fd.append('file', file);
    xhr.send(fd);
  }

  function removePending(uploader, btn) {
    var pill = btn.closest('.pending-file');
    if (!pill) return;
    var tmpId = pill.getAttribute('data-tmp-id');
    var form  = uploader.closest('form');
    if (form && tmpId) {
      var hidden = form.querySelector('input[name="attachment_ids[]"][data-tmp-id="' + tmpId + '"]');
      if (hidden) hidden.remove();
    }
    var parent = pill.parentElement;
    pill.remove();
    if (parent && parent.children.length === 0) parent.hidden = true;
  }

  function initUploader(uploader) {
    if (uploader.__taInit) return;
    uploader.__taInit = true;

    var input = uploader.querySelector('.ta-file-input');
    var zone  = uploader.querySelector('.ta-dropzone');

    if (input) {
      input.addEventListener('change', function () {
        Array.prototype.slice.call(this.files || []).forEach(function (f) { uploadFile(uploader, f); });
        this.value = '';
      });
    }

    if (zone) {
      // The <label> already opens the picker on click; also support Enter/Space.
      zone.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && input) { e.preventDefault(); input.click(); }
      });
      ['dragenter', 'dragover'].forEach(function (ev) {
        zone.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); zone.classList.add('ta-dragover'); });
      });
      ['dragleave', 'dragend'].forEach(function (ev) {
        zone.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); zone.classList.remove('ta-dragover'); });
      });
      zone.addEventListener('drop', function (e) {
        e.preventDefault(); e.stopPropagation();
        zone.classList.remove('ta-dragover');
        var files = e.dataTransfer && e.dataTransfer.files ? Array.prototype.slice.call(e.dataTransfer.files) : [];
        files.forEach(function (f) { uploadFile(uploader, f); });
      });
    }

    uploader.addEventListener('click', function (e) {
      var rm = e.target.closest('.pending-file-remove');
      if (rm) { e.preventDefault(); removePending(uploader, rm); }
    });
  }

  function initAll() {
    Array.prototype.slice.call(document.querySelectorAll('[data-ta-uploader]')).forEach(initUploader);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
