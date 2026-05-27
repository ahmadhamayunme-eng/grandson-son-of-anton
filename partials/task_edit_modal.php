<?php
// partials/task_edit_modal.php — global "Edit Task" modal.
// Included once by partials/footer.php so window.AntonTaskEdit(taskId) is
// available on every page that lists tasks. Backed by task_api.php.
if (!function_exists('csrf_token')) { require_once dirname(__DIR__) . '/lib/helpers.php'; }
?>
<style>
  .te-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 2000; display: none; align-items: flex-start; justify-content: center; padding: 48px 16px; overflow-y: auto; }
  .te-overlay.open { display: flex; }
  .te-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; width: 100%; max-width: 560px; overflow: hidden; box-shadow: 0 24px 60px rgba(0,0,0,0.5); }
  .te-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid var(--border); }
  .te-title { font-size: 16px; font-weight: 600; color: var(--text); }
  .te-close { width: 30px; height: 30px; border-radius: 6px; border: 0; background: none; color: var(--text-muted); font-size: 22px; line-height: 1; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
  .te-close:hover { background: var(--surface-2); color: var(--text); }
  .te-body { padding: 18px; display: flex; flex-direction: column; gap: 14px; max-height: 68vh; overflow-y: auto; }
  .te-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .te-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
  .te-label { font-size: 11.5px; font-weight: 500; color: var(--text-muted); }
  .te-input { width: 100%; background: var(--surface-2); border: 1px solid var(--border); border-radius: 9px; padding: 10px 12px; font-size: 14px; color: var(--text); outline: none; font-family: inherit; transition: border-color 0.12s; }
  .te-input:focus { border-color: var(--accent); background: var(--bg); }
  select.te-input { appearance: none; -webkit-appearance: none; cursor: pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%238a8a8a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 34px; }
  .te-input option { background: #1a1a1a; color: var(--text); }
  .te-textarea { min-height: 92px; resize: vertical; line-height: 1.5; }
  .te-assignees { border: 1px solid var(--border); border-radius: 9px; background: var(--surface-2); max-height: 184px; overflow-y: auto; padding: 4px; }
  .te-assignee { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 7px; cursor: pointer; }
  .te-assignee:hover { background: var(--surface); }
  .te-assignee input { width: 16px; height: 16px; accent-color: var(--accent); flex-shrink: 0; }
  .te-assignee .te-an { font-size: 13px; color: var(--text); }
  .te-assignee .te-ar { font-size: 11px; color: var(--text-dim); margin-left: auto; }
  .te-err { background: var(--danger-soft); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; border-radius: 8px; padding: 10px 12px; font-size: 12.5px; }
  .te-foot { display: flex; gap: 8px; justify-content: flex-end; padding: 14px 18px; border-top: 1px solid var(--border); background: var(--bg); }
  .te-btn { padding: 10px 16px; border-radius: 9px; font-size: 13.5px; font-weight: 500; border: 1px solid var(--border); background: var(--surface-2); color: var(--text); cursor: pointer; font-family: inherit; min-height: 42px; }
  .te-btn:hover { border-color: var(--border-strong); }
  .te-btn-primary { background: var(--accent); color: #1a1400; border-color: var(--accent); font-weight: 600; }
  .te-btn-primary:hover { background: var(--accent-hover); }
  .te-btn[disabled] { opacity: 0.6; cursor: default; }
  .te-loading { padding: 40px 18px; text-align: center; color: var(--text-dim); font-size: 13px; }
  @media (max-width: 768px) {
    .te-overlay { padding: 16px 8px; align-items: flex-end; }
    .te-card { max-width: 100%; border-radius: 16px 16px 0 0; }
    .te-row { grid-template-columns: 1fr; }
    .te-input { font-size: 16px; }
    .te-foot { position: sticky; bottom: 0; }
    .te-btn { flex: 1; justify-content: center; }
  }
</style>

<div class="te-overlay" id="teOverlay" aria-hidden="true">
  <div class="te-card" role="dialog" aria-modal="true" aria-labelledby="teTitle">
    <div class="te-head">
      <span class="te-title" id="teTitle">Edit Task</span>
      <button type="button" class="te-close" data-te-close aria-label="Close">&times;</button>
    </div>
    <form class="te-body" id="teForm" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" id="teId" value="">
      <div class="te-field">
        <label class="te-label" for="teTitleInput">Title</label>
        <input class="te-input" id="teTitleInput" name="title" maxlength="220" required>
      </div>
      <div class="te-field">
        <label class="te-label" for="teDesc">Description</label>
        <textarea class="te-input te-textarea" id="teDesc" name="description" placeholder="Add detail…"></textarea>
      </div>
      <div class="te-row">
        <div class="te-field">
          <label class="te-label" for="teStatus">Status</label>
          <select class="te-input" id="teStatus" name="status"></select>
        </div>
        <div class="te-field">
          <label class="te-label" for="teDue">Due date</label>
          <input class="te-input" id="teDue" name="due_date" type="date">
        </div>
      </div>
      <div class="te-field">
        <label class="te-label" for="tePhase">Phase</label>
        <select class="te-input" id="tePhase" name="phase_id"></select>
      </div>
      <div class="te-field">
        <label class="te-label">Assignees</label>
        <div class="te-assignees" id="teAssignees"></div>
      </div>
      <div class="te-err" id="teErr" hidden></div>
    </form>
    <div class="te-foot">
      <button type="button" class="te-btn" data-te-close>Cancel</button>
      <button type="button" class="te-btn te-btn-primary" id="teSave">Save changes</button>
    </div>
  </div>
</div>

<script>
(function () {
  var overlay = document.getElementById('teOverlay');
  if (!overlay) return;
  var form     = document.getElementById('teForm');
  var elId     = document.getElementById('teId');
  var elTitle  = document.getElementById('teTitleInput');
  var elDesc   = document.getElementById('teDesc');
  var elStatus = document.getElementById('teStatus');
  var elDue    = document.getElementById('teDue');
  var elPhase  = document.getElementById('tePhase');
  var elAssign = document.getElementById('teAssignees');
  var elErr    = document.getElementById('teErr');
  var elSave   = document.getElementById('teSave');

  function esc(s) { return String(s == null ? '' : s); }
  function showErr(msg) { elErr.textContent = msg; elErr.hidden = false; }
  function clearErr() { elErr.hidden = true; elErr.textContent = ''; }

  function open()  { overlay.classList.add('open'); overlay.setAttribute('aria-hidden', 'false'); }
  function close() { overlay.classList.remove('open'); overlay.setAttribute('aria-hidden', 'true'); }

  overlay.addEventListener('click', function (e) {
    if (e.target === overlay || (e.target.closest && e.target.closest('[data-te-close]'))) close();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay.classList.contains('open')) close();
  });

  function fillSelect(sel, items, value, labelKey, valueKey) {
    sel.innerHTML = '';
    items.forEach(function (it) {
      var opt = document.createElement('option');
      if (valueKey) { opt.value = it[valueKey]; opt.textContent = it[labelKey]; }
      else { opt.value = it; opt.textContent = it; }
      sel.appendChild(opt);
    });
    if (value !== undefined && value !== null) sel.value = String(value);
  }

  window.AntonTaskEdit = function (taskId) {
    if (!taskId) return;
    clearErr();
    elSave.disabled = true;
    elTitle.value = ''; elDesc.value = '';
    elAssign.innerHTML = '<div class="te-loading">Loading…</div>';
    open();

    fetch('/task_api.php?action=get&id=' + encodeURIComponent(taskId), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
      .then(function (res) {
        if (!res.ok || !res.data || !res.data.ok) {
          elAssign.innerHTML = '';
          showErr((res.data && res.data.error) || 'Could not load the task.');
          return;
        }
        var d = res.data, t = d.task;
        elId.value = t.id;
        elTitle.value = esc(t.title);
        elDesc.value = esc(t.description);
        fillSelect(elStatus, d.statuses || [], t.status);
        elDue.value = esc(t.due_date);
        fillSelect(elPhase, d.phases || [], t.phase_id, 'name', 'id');

        var picked = {};
        (d.assignees || []).forEach(function (uid) { picked[uid] = true; });
        elAssign.innerHTML = '';
        (d.team || []).forEach(function (m) {
          var row = document.createElement('label');
          row.className = 'te-assignee';
          var cb = document.createElement('input');
          cb.type = 'checkbox'; cb.name = 'assignees[]'; cb.value = m.id;
          if (picked[m.id]) cb.checked = true;
          var nm = document.createElement('span'); nm.className = 'te-an'; nm.textContent = m.name;
          var rl = document.createElement('span'); rl.className = 'te-ar'; rl.textContent = m.role || '';
          row.appendChild(cb); row.appendChild(nm); row.appendChild(rl);
          elAssign.appendChild(row);
        });

        elSave.disabled = !d.can_manage;
        if (!d.can_manage) showErr('You do not have permission to edit this task.');
      })
      .catch(function () {
        elAssign.innerHTML = '';
        showErr('Network error while loading the task.');
      });
  };

  elSave.addEventListener('click', function () {
    clearErr();
    if (!elTitle.value.trim()) { showErr('Title is required.'); elTitle.focus(); return; }
    elSave.disabled = true;
    var prev = elSave.textContent;
    elSave.textContent = 'Saving…';
    var fd = new FormData(form);
    fetch('/task_api.php?action=update', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      body: fd
    })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }, function () { return { ok: false, data: { error: 'Bad response.' } }; }); })
      .then(function (res) {
        elSave.textContent = prev;
        if (!res.ok || !res.data || !res.data.ok) {
          elSave.disabled = false;
          showErr((res.data && res.data.error) || 'Could not save changes.');
          return;
        }
        if (window.AntonToast) { try { window.AntonToast('Task updated', 'success'); } catch (e) {} }
        // Reload so every list/card reflecting this task updates everywhere.
        window.location.reload();
      })
      .catch(function () {
        elSave.textContent = prev;
        elSave.disabled = false;
        showErr('Network error while saving.');
      });
  });

  // Delegated trigger: any element with [data-task-edit="<id>"] opens the modal.
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest ? e.target.closest('[data-task-edit]') : null;
    if (!trigger) return;
    e.preventDefault();
    window.AntonTaskEdit(trigger.getAttribute('data-task-edit'));
  });
})();
</script>
