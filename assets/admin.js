/* WP My Family Tree admin. Readable source; no build step required. */
(function () {
    'use strict';
    var config = window.WPMFT_ADMIN || {};
    var strings = config.strings || {};

    function ready() {
        var tabs = document.querySelector('.wpmft-admin-tabs');
        if (tabs) {
            var links = Array.from(tabs.querySelectorAll('a'));
            var sections = Array.from(document.querySelectorAll('.wpmft-settings-section'));
            function showPanel(hash) {
                var active = sections.find(function (panel) { return '#' + panel.id === hash; }) || sections[0];
                sections.forEach(function (panel) { panel.hidden = panel !== active; });
                links.forEach(function (link) {
                    var selected = link.hash === '#' + active.id;
                    link.classList.toggle('is-active', selected);
                    link.setAttribute('aria-current', selected ? 'page' : 'false');
                });
            }
            links.forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    history.replaceState(null, '', link.hash);
                    showPanel(link.hash);
                });
            });
            showPanel(window.location.hash);
        }

        var protectionForm = document.getElementById('wpmft-protect-media-form');
        if (protectionForm) {
            var protectionStatus = document.getElementById('wpmft-media-progress');
            protectionForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var button = protectionForm.querySelector('button');
                button.disabled = true;
                protectionStatus.textContent = strings.protecting;
                function batch() {
                    return fetch(config.ajaxUrl, {
                        method: 'POST', credentials: 'same-origin', cache: 'no-store',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                        body: new URLSearchParams({action: 'wpmft_admin_protect_media', nonce: config.mediaNonce}).toString()
                    }).then(function (response) {
                        if (!response.ok) throw new Error(strings.protectionError);
                        return response.json();
                    }).then(function (json) {
                        if (!json.success) throw new Error(json.data && json.data.message || strings.protectionError);
                        if (json.data.errors && json.data.errors.length) throw new Error(json.data.errors.join(' '));
                        if (json.data.remaining > 0) {
                            protectionStatus.textContent = strings.protecting + ' ' + strings.remaining + ' ' + json.data.remaining;
                            if (!json.data.processed) throw new Error(strings.protectionError);
                            return batch();
                        }
                        protectionStatus.textContent = strings.protected;
                    });
                }
                batch().catch(function (error) {
                    protectionStatus.textContent = error.message || strings.protectionError;
                }).finally(function () { button.disabled = false; });
            });
        }

        function initPersonPicker(picker) {
            if (picker.dataset.initialized === '1') return;
            picker.dataset.initialized = '1';
            var input = picker.querySelector('.wpmft-person-search');
            var hidden = picker.querySelector('input[type="hidden"]');
            var selection = picker.querySelector('.wpmft-person-selection');
            var results = picker.querySelector('.wpmft-person-results');
            var status = picker.querySelector('.wpmft-search-status');
            var multiple = picker.dataset.multiple === '1';
            var timer;
            var controller;
            var sequence = 0;

            function update() {
                hidden.value = Array.from(selection.querySelectorAll('[data-id]')).map(function (chip) { return chip.dataset.id; }).join(',');
            }
            function close() {
                results.hidden = true;
                input.setAttribute('aria-expanded', 'false');
            }
            function choose(person) {
                if (!multiple) selection.replaceChildren();
                if (!Array.from(selection.children).some(function (chip) { return Number(chip.dataset.id) === person.id; })) {
                    var chip = document.createElement('span');
                    chip.className = 'wpmft-person-chip';
                    chip.dataset.id = String(person.id);
                    var label = document.createElement('span');
                    label.textContent = person.title + ' #' + person.id;
                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'wpmft-remove-person';
                    remove.textContent = '×';
                    remove.setAttribute('aria-label', (strings.remove || 'Remove') + ' ' + person.title);
                    chip.append(label, remove);
                    selection.append(chip);
                }
                update();
                input.value = '';
                close();
                input.focus();
            }
            picker.addEventListener('click', function (event) {
                var remove = event.target.closest('.wpmft-remove-person');
                if (!remove) return;
                remove.closest('.wpmft-person-chip').remove();
                update();
                input.focus();
            });
            function search() {
                if (controller) controller.abort();
                controller = new AbortController();
                var current = ++sequence;
                status.textContent = strings.searching || 'Searching…';
                fetch(config.ajaxUrl, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                    headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                    body: new URLSearchParams({action: 'wpmft_admin_people', nonce: config.nonce, q: input.value}).toString()
                }).then(function (response) {
                    if (!response.ok) throw new Error('Search failed');
                    return response.json();
                }).then(function (json) {
                    if (current !== sequence) return;
                    if (!json.success || !Array.isArray(json.data)) throw new Error('Search failed');
                    results.replaceChildren();
                    json.data.forEach(function (person) {
                        var button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'wpmft-person-result';
                        button.setAttribute('role', 'option');
                        button.textContent = person.title + ' #' + person.id;
                        button.addEventListener('click', function () { choose(person); });
                        results.append(button);
                    });
                    if (!json.data.length) {
                        var empty = document.createElement('p');
                        empty.textContent = strings.noResults || 'No family members found.';
                        results.append(empty);
                    }
                    results.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
                    status.textContent = json.data.length ? '' : strings.noResults;
                }).catch(function (error) {
                    if (error.name !== 'AbortError' && current === sequence) status.textContent = strings.searchError || 'Search could not load.';
                });
            }
            input.addEventListener('input', function () {
                clearTimeout(timer);
                if (controller) controller.abort();
                sequence++;
                timer = setTimeout(search, 220);
            });
            input.addEventListener('focus', function () { if (!input.value) search(); });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowDown') {
                    var first = results.querySelector('button');
                    if (first && !results.hidden) { event.preventDefault(); first.focus(); }
                }
                if (event.key === 'Escape') close();
                if (event.key === 'Enter') event.preventDefault();
            });
            results.addEventListener('keydown', function (event) {
                var items = Array.from(results.querySelectorAll('button'));
                var index = items.indexOf(document.activeElement);
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    var next = event.key === 'ArrowDown' ? index + 1 : index - 1;
                    (items[next] || input).focus();
                }
                if (event.key === 'Escape') { close(); input.focus(); }
            });
            picker.addEventListener('focusout', function () {
                setTimeout(function () { if (!picker.contains(document.activeElement)) close(); }, 0);
            });
        }
        document.querySelectorAll('.wpmft-person-picker').forEach(initPersonPicker);

        document.querySelectorAll('.wpmft-partner-editor').forEach(function (editor) {
            var rows = editor.querySelector('.wpmft-partner-rows');
            var template = editor.querySelector('.wpmft-partner-template');
            var add = editor.querySelector('.wpmft-add-partner');
            var status = editor.querySelector('.wpmft-partner-status');
            var next = Number(editor.dataset.nextIndex) || 0;
            if (!rows || !template || !add) return;
            add.addEventListener('click', function () {
                if (rows.children.length >= 50) {
                    status.textContent = strings.relationshipLimit || 'Add no more than 50 partner relationships for one person.';
                    return;
                }
                var fragment = template.content.cloneNode(true);
                fragment.querySelectorAll('*').forEach(function (element) {
                    ['id', 'for', 'name', 'aria-controls'].forEach(function (attribute) {
                        if (element.hasAttribute(attribute)) element.setAttribute(attribute, element.getAttribute(attribute).replace(/__ROW__/g, String(next)));
                    });
                });
                next++;
                var row = fragment.querySelector('.wpmft-partner-row');
                rows.append(fragment);
                row.querySelectorAll('.wpmft-person-picker').forEach(initPersonPicker);
                status.textContent = '';
                row.querySelector('.wpmft-person-search').focus();
            });
            rows.addEventListener('click', function (event) {
                var remove = event.target.closest('.wpmft-remove-partner');
                if (!remove || !rows.contains(remove)) return;
                remove.closest('.wpmft-partner-row').remove();
                status.textContent = '';
                add.focus();
            });
        });

        var galleryButton = document.getElementById('wpmft-edit-gallery');
        var galleryInput = document.getElementById('wpmft-gallery-ids');
        var galleryPreview = document.getElementById('wpmft-gallery-preview');
        if (galleryButton && galleryInput && galleryPreview) {
            function ids() {
                return galleryInput.value.split(',').map(Number).filter(function (id) { return Number.isInteger(id) && id > 0; });
            }
            function renderImages(attachments) {
                galleryPreview.replaceChildren();
                galleryInput.value = attachments.map(function (item) { return item.id; }).join(',');
                attachments.forEach(function (item) {
                    var figure = document.createElement('figure');
                    figure.dataset.id = String(item.id);
                    var image = document.createElement('img');
                    image.src = item.sizes && item.sizes.thumbnail ? item.sizes.thumbnail.url : item.url;
                    image.alt = item.alt || '';
                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'wpmft-remove-image';
                    remove.textContent = '×';
                    remove.setAttribute('aria-label', strings.remove || 'Remove');
                    figure.append(image, remove);
                    galleryPreview.append(figure);
                });
            }
            galleryButton.addEventListener('click', function () {
                if (!window.wp || !wp.media) return;
                var selectedIds = ids();
                // The native gallery editor supports reordering and retains attachment IDs.
                var selection = new wp.media.model.Selection(selectedIds.map(function (id) { return wp.media.attachment(id); }), {multiple: true});
                selection.each(function (attachment) { attachment.fetch(); });
                var frame = wp.media({
                    frame: 'post', state: 'gallery-edit', title: strings.chooseImages,
                    editing: true, multiple: true, selection: selection,
                    library: {type: 'image'}
                });
                frame.on('update', function (library) {
                    renderImages(library.toJSON().filter(function (item) { return item.type === 'image'; }));
                });
                frame.open();
            });
            galleryPreview.addEventListener('click', function (event) {
                var remove = event.target.closest('.wpmft-remove-image');
                if (!remove) return;
                remove.closest('figure').remove();
                galleryInput.value = Array.from(galleryPreview.querySelectorAll('figure')).map(function (figure) { return figure.dataset.id; }).join(',');
            });
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
    else ready();
})();
