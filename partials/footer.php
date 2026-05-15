<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  (function(){
    var key = window.ANTON_ACTIVE;
    if (!key) return;
    var el = document.querySelector('#antonNav .nav-item[data-key="' + key + '"]');
    if (el) el.classList.add('active');
  })();
</script>
</body></html>
