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
  var search = document.getElementById('wpmft-demo-search');
  var list = document.getElementById('wpmft-demo-person-list');
  var empty = document.getElementById('wpmft-demo-empty');
  if (search && list && empty) {
    search.addEventListener('input', function () {
      var term = search.value.trim().toLocaleLowerCase();
      var visible = 0;
      list.querySelectorAll('[data-wpmft-demo-name]').forEach(function (card) {
        card.hidden = !card.dataset.wpmftDemoName.toLocaleLowerCase().includes(term);
        if (!card.hidden) visible += 1;
      });
      empty.hidden = visible > 0;
    });
  }
})();
