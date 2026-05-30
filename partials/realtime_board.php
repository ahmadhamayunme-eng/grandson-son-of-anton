<?php
/**
 * Realtime board updates (item #18). Include on list/board pages. Opens an
 * EventSource onto the existing chat SSE endpoint and, when another user
 * changes a task/project, surfaces a non-intrusive "updated — refresh" toast.
 *
 * Deliberately additive: if EventSource is unavailable or the stream errors,
 * the page is unaffected (it just won't live-update). We avoid fragile DOM
 * patching across the differing card/table layouts and instead let the user
 * pull fresh data on demand.
 */
?>
<script>
  (function(){
    if (!('EventSource' in window)) return;
    var es, notified = false;

    function notify(){
      if (notified) return;            // one prompt per page-life is enough
      notified = true;
      if (window.AntonToast) {
        window.AntonToast('Board updated by a teammate — tap to refresh', 'info', 6000);
      }
      // Make the toast actionable: clicking anywhere on it reloads.
      var host = document.querySelector('.toast-host .toast:last-child');
      if (host) { host.style.cursor = 'pointer'; host.addEventListener('click', function(){ location.reload(); }); }
    }

    try {
      es = new EventSource('chat/api/events.php');
      ['task.updated','task.created','project.updated','project.created'].forEach(function(type){
        es.addEventListener(type, notify);
      });
      es.onerror = function(){ /* browser auto-reconnects; ignore transient drops */ };
    } catch (e) { /* no-op */ }

    window.addEventListener('beforeunload', function(){ if (es) es.close(); });
  })();
</script>
