<?php
/**
 * Saved-views bar (item #13). Include from a list page after setting:
 *   $svPage  - the page key ('my_tasks' | 'projects')
 * Renders a dropdown of the user's saved views plus a "Save current view"
 * control. The current view is captured from the live query string in JS so
 * whatever filters are active get saved verbatim and are shareable by URL.
 */
require_once __DIR__ . '/../lib/saved_views.php';
$svPage  = $svPage ?? '';
$svViews = $svPage ? saved_views_list($svPage) : [];
?>
<div class="saved-views-bar" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 14px;">
  <?php if ($svViews): ?>
    <label class="text-muted" style="font-size:12px;" for="savedViewSelect">Saved views:</label>
    <select id="savedViewSelect" class="select" style="max-width:240px;"
            onchange="if(this.value){window.location.search=this.value;}">
      <option value="">— Select a view —</option>
      <?php foreach ($svViews as $v): ?>
        <option value="<?= h('?' . ltrim((string)$v['query_string'], '?')) ?>"><?= h($v['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <form method="post" action="saved_views.php" style="display:inline;" data-no-loading
          onsubmit="return confirm('Delete the selected saved view?');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="page" value="<?= h($svPage) ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" id="savedViewDeleteId" value="">
      <button type="submit" class="btn btn-ghost btn-sm"
              onclick="var s=document.getElementById('savedViewSelect');var o=s.options[s.selectedIndex];document.getElementById('savedViewDeleteId').value=(o&&o.dataset.id)||'';"
              title="Delete selected view">Delete view</button>
    </form>
  <?php endif; ?>

  <form method="post" action="saved_views.php" style="display:inline-flex;gap:6px;align-items:center;" data-no-loading>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="page" value="<?= h($svPage) ?>">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="query_string" id="savedViewQuery" value="">
    <input type="text" name="name" class="input" placeholder="Name this view…" style="max-width:180px;" required>
    <button type="submit" class="btn btn-ghost btn-sm"
            onclick="document.getElementById('savedViewQuery').value=window.location.search;">Save current view</button>
  </form>
</div>
<script>
  // Attach each option's saved id for the delete control.
  (function(){
    var sel = document.getElementById('savedViewSelect');
    if (!sel) return;
    var ids = <?= json_encode(array_map(fn($v) => (int)$v['id'], $svViews)) ?>;
    // options[0] is the placeholder, so saved ids start at index 1.
    for (var i = 0; i < ids.length; i++) {
      if (sel.options[i + 1]) sel.options[i + 1].dataset.id = ids[i];
    }
    // Preselect the option matching the current query string, if any.
    var cur = window.location.search;
    for (var j = 1; j < sel.options.length; j++) {
      if (sel.options[j].value === cur) { sel.selectedIndex = j; break; }
    }
  })();
</script>
