/* Optional demo actions always require a second, explicit confirmation. */
(function () {
  'use strict';
  var labels = window.WPMFT_DEMO_ADMIN || {};
  ['import', 'enable', 'disable', 'reset', 'baseline'].forEach(function (action) {
    var form = document.getElementById('wpmft-demo-' + action);
    if (!form) return;
    form.addEventListener('submit', function (event) {
      if (labels[action] && !window.confirm(labels[action])) event.preventDefault();
    });
  });
})();
