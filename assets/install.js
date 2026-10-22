/* WP My Family Tree: browser installation, deliberately without offline storage. */
(function () {
  'use strict';
  var pending = null;
  var confirmed = false;
  var standalone = window.matchMedia ? window.matchMedia('(display-mode: standalone)') : null;
  function installed() { return confirmed || !!(standalone && standalone.matches) || window.navigator.standalone === true; }
  function changed() { window.dispatchEvent(new Event('wpmft:installchange')); }
  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    pending = event;
    changed();
  });
  window.addEventListener('appinstalled', function () { confirmed = true; pending = null; changed(); });
  if (standalone && standalone.addEventListener) { standalone.addEventListener('change', changed); }
  else if (standalone && standalone.addListener) { standalone.addListener(changed); }
  window.WPMFT_INSTALL = {
    available: function () { return !!pending && !installed(); },
    installed: installed,
    prompt: function () {
      if (!pending || installed()) { return Promise.resolve('unavailable'); }
      var event = pending;
      pending = null;
      changed();
      // Invoke prompt synchronously within the user's click activation.
      try {
        return Promise.resolve(event.prompt()).then(function () { return event.userChoice; }).then(function (choice) {
          changed();
          return choice && choice.outcome === 'accepted' ? 'accepted' : 'dismissed';
        }).catch(function () { changed(); return 'unavailable'; });
      } catch (error) { changed(); return Promise.resolve('unavailable'); }
    }
  };
}());
