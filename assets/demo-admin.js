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
  var settingsTabs = document.querySelector('.wpmft-demo-settings-tabs');
  if (settingsTabs) {
    var links = Array.from(settingsTabs.querySelectorAll('a[href^="#"]'));
    var panels = Array.from(document.querySelectorAll('.wpmft-demo-settings-panels > section'));
    function showSettings(hash) {
      var active = panels.find(function (panel) { return '#' + panel.id === hash; }) || panels[0];
      if (!active) return;
      panels.forEach(function (panel) { panel.hidden = panel !== active; });
      links.forEach(function (link) {
        var selected = link.hash === '#' + active.id;
        link.classList.toggle('is-active', selected);
        if (selected) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
      });
    }
    links.forEach(function (link) {
      link.addEventListener('click', function (event) {
        event.preventDefault();
        showSettings(link.hash);
        window.history.replaceState(null, '', link.hash);
      });
    });
    window.addEventListener('hashchange', function () { showSettings(window.location.hash); });
    showSettings(window.location.hash);
  }
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
