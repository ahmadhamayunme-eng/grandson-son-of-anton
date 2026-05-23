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

  // Mobile hamburger nav — toggles body.nav-open which drives the sidebar
  // drawer open/close via CSS transforms in anton.css.
  (function(){
    var hamburger = document.getElementById('navHamburger');
    var overlay   = document.getElementById('navOverlay');
    if (!hamburger) return;

    function openNav() {
      document.body.classList.add('nav-open');
      hamburger.setAttribute('aria-expanded', 'true');
    }
    function closeNav() {
      document.body.classList.remove('nav-open');
      hamburger.setAttribute('aria-expanded', 'false');
    }
    function toggleNav() {
      document.body.classList.contains('nav-open') ? closeNav() : openNav();
    }

    hamburger.addEventListener('click', toggleNav);
    if (overlay) overlay.addEventListener('click', closeNav);

    // Close drawer when any nav link is tapped (navigates away)
    document.querySelectorAll('#antonNav .nav-item').forEach(function(link){
      link.addEventListener('click', closeNav);
    });

    // Close drawer on Escape key
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') closeNav();
    });
  })();

  // Table scroll wrapper — wraps every <table> inside .main in a
  // .table-scroll div so tables scroll horizontally on mobile instead
  // of breaking the page layout. Runs on every page automatically.
  (function(){
    var main = document.querySelector('.main');
    if (!main) return;
    main.querySelectorAll('table').forEach(function(table){
      if (table.closest('.table-scroll')) return; // already wrapped
      var wrapper = document.createElement('div');
      wrapper.className = 'table-scroll';
      table.parentNode.insertBefore(wrapper, table);
      wrapper.appendChild(table);
    });
  })();
</script>
</body></html>
