<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  (function(){
    var key = window.ANTON_ACTIVE;
    if (!key) return;
    // Mark active in both the drawer sidebar and the bottom tab bar so a
    // page setting window.ANTON_ACTIVE late (e.g. after JS routing) stays
    // in sync everywhere.
    var sel = '#antonNav .nav-item[data-key="' + key + '"], #antonBottomNav .bottom-nav-item[data-key="' + key + '"]';
    document.querySelectorAll(sel).forEach(function(el){ el.classList.add('active'); });
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

  // ===================================================================
  // visualViewport — keyboard inset
  // ===================================================================
  // When the on-screen keyboard opens, window.innerHeight stays the same
  // on iOS but visualViewport.height shrinks. We expose the gap as a
  // CSS variable --keyboard-inset and a body.kb-open class so any fixed
  // bottom element (chat composer, bottom tab bar, FAB) can either sit
  // above the keyboard or hide while it's open.
  (function(){
    var vv = window.visualViewport;
    if (!vv) return;
    var root = document.documentElement;
    var body = document.body;
    function recompute() {
      var inset = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
      // Round to whole px so we don't thrash style on subpixel changes.
      inset = Math.round(inset);
      root.style.setProperty('--keyboard-inset', inset + 'px');
      // Treat anything >100px as "keyboard is open". Below that on Android
      // is usually the URL bar collapsing, which we don't want to react to.
      if (inset > 100) body.classList.add('kb-open');
      else              body.classList.remove('kb-open');
    }
    vv.addEventListener('resize', recompute);
    vv.addEventListener('scroll', recompute);
    recompute();
  })();

  // ===================================================================
  // AntonSheet / AntonConfirm / AntonToast — global UI primitives
  // ===================================================================
  // Mobile-native replacements for window.confirm / window.alert and a
  // shared "long-press menu" surface. Available on every page via
  // window.AntonSheet({...}) / window.AntonConfirm(message, opts).
  (function(){
    var openBackdrop = null;
    var openSheet    = null;
    var dragStartY   = null;

    function closeSheet(onClose) {
      if (!openSheet) return;
      openSheet.classList.remove('open');
      if (openBackdrop) openBackdrop.classList.remove('open');
      document.body.classList.remove('sheet-open');
      var s = openSheet, b = openBackdrop;
      openSheet = null; openBackdrop = null;
      setTimeout(function(){
        if (s && s.parentNode) s.parentNode.removeChild(s);
        if (b && b.parentNode) b.parentNode.removeChild(b);
        if (typeof onClose === 'function') onClose();
      }, 220);
    }

    function buildSheet(opts) {
      closeSheet(); // only one at a time

      var backdrop = document.createElement('div');
      backdrop.className = 'sheet-backdrop';
      var sheet    = document.createElement('div');
      sheet.className = 'sheet';
      sheet.setAttribute('role', 'dialog');
      sheet.setAttribute('aria-modal', 'true');
      if (opts.label) sheet.setAttribute('aria-label', opts.label);

      var grabber = document.createElement('div');
      grabber.className = 'sheet-grabber';
      sheet.appendChild(grabber);

      if (opts.title) {
        var t = document.createElement('div');
        t.className = 'sheet-title';
        t.textContent = opts.title;
        sheet.appendChild(t);
      }
      if (opts.message) {
        var m = document.createElement('div');
        m.className = 'sheet-msg';
        m.textContent = opts.message;
        sheet.appendChild(m);
      }

      var actions = document.createElement('div');
      actions.className = 'sheet-actions';
      (opts.actions || []).forEach(function(a){
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'sheet-action' + (a.tone ? ' ' + a.tone : '');
        if (a.icon) {
          var i = document.createElement('span');
          i.innerHTML = a.icon;
          while (i.firstChild) btn.appendChild(i.firstChild);
        }
        var lbl = document.createElement('span');
        lbl.textContent = a.label;
        btn.appendChild(lbl);
        btn.addEventListener('click', function(){
          closeSheet(function(){ if (a.onClick) a.onClick(); });
        });
        actions.appendChild(btn);
      });
      sheet.appendChild(actions);

      if (opts.cancelLabel !== null) {
        var row = document.createElement('div');
        row.className = 'sheet-cancel-row';
        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'sheet-action';
        cancel.style.justifyContent = 'center';
        cancel.textContent = opts.cancelLabel || 'Cancel';
        cancel.addEventListener('click', function(){
          closeSheet(opts.onCancel);
        });
        row.appendChild(cancel);
        sheet.appendChild(row);
      }

      backdrop.addEventListener('click', function(){ closeSheet(opts.onCancel); });

      // Drag-down-to-dismiss with a 60px threshold.
      sheet.addEventListener('touchstart', function(e){
        if (sheet.scrollTop > 0) return;
        dragStartY = e.touches[0].clientY;
        sheet.style.transition = 'none';
      }, { passive: true });
      sheet.addEventListener('touchmove', function(e){
        if (dragStartY === null) return;
        var dy = e.touches[0].clientY - dragStartY;
        if (dy < 0) { sheet.style.transform = 'translateY(0)'; return; }
        sheet.style.transform = 'translateY(' + dy + 'px)';
      }, { passive: true });
      sheet.addEventListener('touchend', function(e){
        if (dragStartY === null) return;
        var dy = (e.changedTouches[0].clientY) - dragStartY;
        dragStartY = null;
        sheet.style.transition = '';
        sheet.style.transform = '';
        if (dy > 60) closeSheet(opts.onCancel);
      });

      document.body.appendChild(backdrop);
      document.body.appendChild(sheet);
      document.body.classList.add('sheet-open');
      // Trigger CSS transition.
      requestAnimationFrame(function(){
        backdrop.classList.add('open');
        sheet.classList.add('open');
      });
      openSheet = sheet; openBackdrop = backdrop;

      // ESC closes.
      function escHandler(e){
        if (e.key !== 'Escape') return;
        document.removeEventListener('keydown', escHandler);
        closeSheet(opts.onCancel);
      }
      document.addEventListener('keydown', escHandler);
    }

    function antonConfirm(message, opts){
      opts = opts || {};
      return new Promise(function(resolve){
        buildSheet({
          title: opts.title || 'Are you sure?',
          message: message,
          actions: [
            {
              label: opts.confirmLabel || 'Confirm',
              tone: opts.destructive === false ? 'primary' : 'danger',
              onClick: function(){ resolve(true); }
            }
          ],
          cancelLabel: opts.cancelLabel || 'Cancel',
          onCancel: function(){ resolve(false); }
        });
      });
    }

    function antonAlert(message, opts){
      opts = opts || {};
      return new Promise(function(resolve){
        buildSheet({
          title: opts.title || '',
          message: message,
          actions: [
            { label: opts.okLabel || 'OK', tone: 'primary', onClick: function(){ resolve(); } }
          ],
          cancelLabel: null
        });
      });
    }

    // Toast host (lazy-created so pages that never toast don't pay).
    var toastHost = null;
    function showToast(message, tone, duration){
      if (!toastHost) {
        toastHost = document.createElement('div');
        toastHost.className = 'toast-host';
        document.body.appendChild(toastHost);
      }
      var t = document.createElement('div');
      t.className = 'toast' + (tone ? ' ' + tone : '');
      t.textContent = message;
      toastHost.appendChild(t);
      requestAnimationFrame(function(){ t.classList.add('show'); });
      var ms = duration || 2600;
      setTimeout(function(){
        t.classList.remove('show');
        setTimeout(function(){ if (t.parentNode) t.parentNode.removeChild(t); }, 250);
      }, ms);
    }

    window.AntonSheet   = buildSheet;
    window.AntonConfirm = antonConfirm;
    window.AntonAlert   = antonAlert;
    window.AntonToast   = showToast;
    window.AntonSheetClose = closeSheet;
  })();

  // ===================================================================
  // Bottom tab bar — "More" sheet
  // ===================================================================
  // The bottom tab surfaces the 5 most-used destinations; everything else
  // (Clients, Projects, Docs, Logins, Account, Reviews, Submit, Admin,
  // Logout) lives in a sheet that pops up when the user taps "More". The
  // entries are derived directly from the drawer sidebar so the two
  // stay in sync without server changes.
  (function(){
    var btn = document.querySelector('#antonBottomNav [data-action="open-more-sheet"]');
    if (!btn || !window.AntonSheet) return;
    var BOTTOM_PRIMARY = ['dashboard','my-tasks','chat','search'];

    btn.addEventListener('click', function(e){
      e.preventDefault();
      // Build the list of "More" entries from the live sidebar so the
      // role-based visibility is preserved automatically.
      var entries = [];
      document.querySelectorAll('#antonNav .nav-item').forEach(function(a){
        var key = a.getAttribute('data-key') || '';
        if (BOTTOM_PRIMARY.indexOf(key) !== -1) return; // already in tab bar
        var icon = (a.querySelector('svg') || {}).outerHTML || '';
        var label = (a.lastChild && a.lastChild.nodeType === 3)
                      ? a.lastChild.textContent.trim()
                      : a.textContent.trim();
        // Strip any trailing badge text.
        label = label.replace(/\s*\d+\+?\s*$/, '').trim();
        if (!label) return;
        var href = a.getAttribute('href') || '#';
        entries.push({
          label: label,
          icon: icon,
          tone: key === 'logout' ? 'danger' : '',
          onClick: function(){ window.location.href = href; }
        });
      });
      window.AntonSheet({
        title: 'More',
        label: 'More navigation',
        actions: entries,
        cancelLabel: 'Close'
      });
    });
  })();
</script>
</body></html>
