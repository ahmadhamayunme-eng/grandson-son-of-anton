<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  (function(){
    var key = window.ANTON_ACTIVE;
    if (!key) return;
    var el = document.querySelector('#antonNav .nav-item[data-key="' + key + '"]');
    if (el) el.classList.add('active');
  })();

  // Theme toggle click handler. The initial class on <html> was set by the
  // FOUC-safe inline script in partials/header.php.
  (function(){
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    btn.addEventListener('click', function(){
      var isLight = document.documentElement.classList.toggle('light');
      try { localStorage.setItem('anton-theme', isLight ? 'light' : 'dark'); } catch (e) {}
    });
  })();
</script>
</body></html>
