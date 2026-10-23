/* Administrator-only cache control. No person data or access tokens are stored. */
(function () {
    'use strict';
    function init() {
        var config = window.WPMFT_TOOLBAR;
        var item = document.getElementById('wp-admin-bar-wpmft-cache');
        var link = item && item.querySelector(':scope > a');
        if (!config || !link) return;
        var label = link.querySelector('.ab-label');
        var busy = false;
        var resetTimer;
        var noticeTimer;
        var notice = document.createElement('div');
        notice.className = 'wpmft-cache-notice';
        notice.setAttribute('role', 'status');
        notice.setAttribute('aria-live', 'polite');
        notice.setAttribute('aria-atomic', 'true');
        document.body.appendChild(notice);
        link.setAttribute('role', 'button');
        link.setAttribute('aria-label', config.labels.clear);

        function announce(message, failed) {
            clearTimeout(noticeTimer);
            notice.textContent = message;
            notice.classList.toggle('is-error', Boolean(failed));
            notice.classList.add('is-visible');
            noticeTimer = setTimeout(function () { notice.classList.remove('is-visible'); }, failed ? 10000 : 6500);
        }

        async function clearCache(event) {
            event.preventDefault();
            if (busy) return;
            busy = true;
            clearTimeout(resetTimer);
            item.classList.add('is-clearing');
            item.classList.remove('is-cleared', 'has-error');
            link.setAttribute('aria-disabled', 'true');
            link.setAttribute('aria-busy', 'true');
            link.setAttribute('aria-label', config.labels.busy);
            if (label) label.textContent = config.labels.busy;
            announce(config.labels.busy, false);
            var controller = window.AbortController ? new AbortController() : null;
            var timeout = setTimeout(function () { if (controller) controller.abort(); }, 20000);
            try {
                var response = await fetch(config.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: new URLSearchParams({action: config.action, nonce: config.nonce}).toString(),
                    signal: controller ? controller.signal : undefined
                });
                var result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.data && result.data.message ? result.data.message : config.labels.failed);
                }
                item.classList.add('is-cleared');
                if (label) label.textContent = config.labels.done;
                announce(result.data && result.data.message ? result.data.message : config.labels.done, false);
                document.dispatchEvent(new CustomEvent('wpmft:cache-cleared', {detail: {clearedAt: result.data && result.data.clearedAt}}));
            } catch (error) {
                item.classList.add('has-error');
                if (label) label.textContent = config.labels.idle;
                announce(error && error.name !== 'AbortError' && error.message ? error.message : config.labels.failed, true);
            } finally {
                clearTimeout(timeout);
                busy = false;
                item.classList.remove('is-clearing');
                link.removeAttribute('aria-disabled');
                link.removeAttribute('aria-busy');
                link.setAttribute('aria-label', config.labels.clear);
                resetTimer = setTimeout(function () {
                    item.classList.remove('is-cleared', 'has-error');
                    if (label) label.textContent = config.labels.idle;
                }, 3500);
            }
        }
        link.addEventListener('click', clearCache);
        link.addEventListener('keydown', function (event) {
            if (event.key === ' ') clearCache(event);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
