/* My Family Tree admin. Readable source; no build step required. */
(function () {
    'use strict';
    var config = window.WPMFT_ADMIN || {};
    var strings = config.strings || {};
    var catalogs = config.catalogs || {lv: config.translations || {}};
    var dictionary = Object.create(null);
    var reverseDictionary = Object.create(null);
    Object.keys(catalogs).forEach(function (language) {
        Object.keys(catalogs[language]).forEach(function (key) { dictionary[key] = true; reverseDictionary[catalogs[language][key]] = key; });
    });
    function translatedSource(source) { return (catalogs[config.language] || {})[source] || source; }
    function sourceText(text) {
        if (Object.prototype.hasOwnProperty.call(dictionary, text)) return text;
        return reverseDictionary[text] || null;
    }
    function translate(text) {
        var source = sourceText(text);
        return source ? translatedSource(source) : text;
    }
    var sourceStrings = {};
    Object.keys(strings).forEach(function (key) { sourceStrings[key] = sourceText(strings[key]) || strings[key]; });
    var textSources = new WeakMap();
    var attributeSources = new WeakMap();

    // Change labels only. Never replace HTML, form values, names, biography or chosen people.
    function translateLabels(root) {
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        var node;
        while ((node = walker.nextNode())) {
            var parent = node.parentElement;
            if (!parent || parent.closest('script,style,textarea,.wpmft-person-chip,.wpmft-person-results,.wpmft-portrait-preview,[data-wpmft-user-content]')) continue;
            if (parent.tagName === 'OPTION' && parent.parentElement && ['wpmft[category_id]', 'wpmft[page_id]'].includes(parent.parentElement.name) && Number(parent.value) > 0) continue;
            var saved = textSources.get(node);
            if (!saved) {
                var text = node.nodeValue.trim();
                var source = sourceText(text);
                if (!source) continue;
                saved = {source: source, before: node.nodeValue.slice(0, node.nodeValue.indexOf(text)), after: node.nodeValue.slice(node.nodeValue.indexOf(text) + text.length)};
                textSources.set(node, saved);
            }
            node.nodeValue = saved.before + translatedSource(saved.source) + saved.after;
        }
        root.querySelectorAll('[placeholder],[aria-label],[title],input[type="submit"]').forEach(function (element) {
            var saved = attributeSources.get(element) || {};
            ['placeholder', 'aria-label', 'title'].concat(element.matches('input[type="submit"]') ? ['value'] : []).forEach(function (attribute) {
                var source = saved[attribute] || sourceText(element.getAttribute(attribute) || '');
                if (!source) return;
                saved[attribute] = source;
                element.setAttribute(attribute, translatedSource(source));
            });
            attributeSources.set(element, saved);
        });
        root.querySelectorAll('template').forEach(function (template) { translateLabels(template.content); });
    }

    function setLanguage(language) {
        if (!(config.languages || ['en', 'lv']).includes(language)) return;
        config.language = language;
        Object.keys(sourceStrings).forEach(function (key) { strings[key] = translate(sourceStrings[key]); });
        document.querySelectorAll('.wpmft-settings,.wpmft-person-panel,#wpmft-person,.wpmft-biography-heading,#toplevel_page_wpmft .wp-menu-name,#toplevel_page_wpmft .wp-submenu a').forEach(translateLabels);
        document.querySelectorAll('[name="wpmft_admin_lang"]').forEach(function (field) { field.value = language; });
        var titlePrompt = document.getElementById('title-prompt-text');
        if (titlePrompt && document.querySelector('.wpmft-person-panel')) titlePrompt.textContent = translate('Person’s full name');
    }

    function ready() {
        setLanguage(config.language || 'en');
        document.addEventListener('change', function (event) {
            if (event.target.matches('.wpmft-admin-language,.post_lang_choice,#post_lang_choice,select[name="post_lang_choice"]')) setLanguage(event.target.value);
        });
        // Polylang can revert the selection after its confirmation dialog is cancelled.
        if (window.jQuery) window.jQuery(document).on('dialogclose.wpmft', '#pll-dialog', function () {
            setTimeout(function () {
                var choice = document.querySelector('.post_lang_choice,[name="post_lang_choice"]');
                if (choice) setLanguage(choice.value);
            }, 0);
        });
        document.querySelectorAll('.wpmft-settings form').forEach(function (form) {
            if (form.querySelector('[name="wpmft_admin_lang"]')) return;
            var language = document.createElement('input');
            language.type = 'hidden'; language.name = 'wpmft_admin_lang'; language.value = config.language;
            form.append(language);
        });

        var languageRows = document.getElementById('wpmft-language-rows');
        var languageTemplate = document.getElementById('wpmft-language-template');
        var addLanguage = document.getElementById('wpmft-add-language');
        if (languageRows && languageTemplate && addLanguage) {
            var languageIndex = languageRows.children.length;
            addLanguage.addEventListener('click', function () {
                var fragment = languageTemplate.content.cloneNode(true);
                fragment.querySelectorAll('[name]').forEach(function (field) { field.name = field.name.replace('__INDEX__', String(languageIndex)); });
                languageIndex++;
                var row = fragment.querySelector('.wpmft-language-row');
                row.open = true;
                languageRows.append(fragment);
                var code = row.querySelector('input[name$="[slug]"]');
                if (code) code.focus();
            });
            languageRows.addEventListener('input', function (event) {
                var field = event.target;
                if (!field.matches('input[name$="[name]"],input[name$="[slug]"]')) return;
                var row = field.closest('.wpmft-language-row');
                var code = row.querySelector('input[name$="[slug]"]').value.trim();
                var name = row.querySelector('input[name$="[name]"]').value.trim();
                row.querySelector('.wpmft-language-row-title').textContent = name || code || 'New language';
                row.querySelector('summary small').textContent = code;
                var defaults = document.querySelector('[name="wpmft_default_language"]');
                if (defaults && /^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})*$/.test(code)) {
                    var option = Array.from(defaults.options).find(function (item) { return item.value === code; });
                    if (!option) { option = document.createElement('option'); option.value = code; defaults.append(option); }
                    option.textContent = name || code;
                }
            });
        }

        var languageForm = document.getElementById('wpmft-language-tool');
        if (languageForm && languageRows) languageForm.addEventListener('submit', function () {
            var rows = [];
            var fields = [];
            languageRows.querySelectorAll('.wpmft-language-row').forEach(function (row) {
                var entry = {};
                row.querySelectorAll('[name]').forEach(function (field) {
                    var match = field.name.match(/^wpmft_languages\[\d+\]\[([a-z_]+)\]$/);
                    if (!match) return;
                    entry[match[1]] = field.type === 'checkbox' ? (field.checked ? '1' : '') : field.value;
                    fields.push(field);
                });
                rows.push(entry);
            });
            var payload = document.createElement('input');
            payload.type = 'hidden'; payload.name = 'wpmft_languages_json'; payload.value = JSON.stringify(rows);
            languageForm.append(payload);
            fields.forEach(function (field) { field.disabled = true; });
        });

        var classicPerson = document.querySelector('.wpmft-person-editor[data-classic-person="1"]');
        var biography = document.getElementById('content');
        if (classicPerson && biography) {
            var keepBiography = document.createElement('input');
            keepBiography.type = 'hidden'; keepBiography.name = 'wpmft_person[keep_biography]'; keepBiography.value = '1';
            classicPerson.append(keepBiography);
            var initialBiography = biography.value;
            ['input', 'change'].forEach(function (event) {
                biography.addEventListener(event, function () { if (biography.value !== initialBiography) keepBiography.value = '0'; });
            });
            function watchEditor(editor) {
                if (!editor || editor.id !== 'content' || editor.wpmftBiographyWatched) return;
                editor.wpmftBiographyWatched = true;
                editor.on('input change undo redo', function () { if (editor.isDirty()) keepBiography.value = '0'; });
            }
            if (window.jQuery) window.jQuery(document).on('tinymce-editor-init.wpmft', function (event, editor) { watchEditor(editor); });
            if (window.tinymce) watchEditor(window.tinymce.get('content'));
        }

        var portraitButton = document.getElementById('wpmft-choose-portrait');
        var portraitRemove = document.getElementById('wpmft-remove-portrait');
        var portraitInput = document.getElementById('wpmft-portrait-id');
        var portraitPreview = document.getElementById('wpmft-portrait-preview');
        if (portraitButton && portraitRemove && portraitInput && portraitPreview) {
            portraitButton.addEventListener('click', function () {
                if (!window.wp || !wp.media) return;
                var frame = wp.media({title: strings.choosePortrait, button: {text: strings.usePortrait}, library: {type: 'image'}, multiple: false});
                frame.on('open', function () {
                    var id = Number(portraitInput.value);
                    if (id > 0) { var attachment = wp.media.attachment(id); attachment.fetch(); frame.state().get('selection').add(attachment); }
                });
                frame.on('select', function () {
                    var selected = frame.state().get('selection').first();
                    if (!selected) return;
                    var item = selected.toJSON();
                    if (item.type !== 'image') return;
                    var image = document.createElement('img');
                    image.src = item.sizes && item.sizes.medium ? item.sizes.medium.url : item.url;
                    image.alt = item.alt || '';
                    portraitPreview.replaceChildren(image);
                    portraitInput.value = String(item.id);
                    portraitRemove.hidden = false;
                    document.getElementById('wpmft-portrait-status').textContent = '';
                });
                frame.open();
            });
            portraitRemove.addEventListener('click', function () {
                portraitInput.value = '0';
                var placeholder = document.createElement('span');
                placeholder.className = 'dashicons dashicons-admin-users'; placeholder.setAttribute('aria-hidden', 'true');
                portraitPreview.replaceChildren(placeholder);
                portraitRemove.hidden = true;
                document.getElementById('wpmft-portrait-status').textContent = strings.noPortrait;
                portraitButton.focus();
            });
        }

        var tabs = document.querySelector('.wpmft-admin-tabs');
        if (tabs) {
            var links = Array.from(tabs.querySelectorAll('a'));
            var sections = Array.from(document.querySelectorAll('.wpmft-settings-section'));
            function showPanel(hash) {
                var active = sections.find(function (panel) { return '#' + panel.id === hash; }) || sections[0];
                sections.forEach(function (panel) { panel.hidden = panel !== active; });
                var saveBar = document.querySelector('.wpmft-save-bar');
                if (saveBar) saveBar.hidden = ['wpmft-languages', 'wpmft-demo'].includes(active.id);
				var sectionField = document.querySelector('[name="wpmft_section"]');
				if (sectionField) sectionField.value = active.id.replace('wpmft-', '');
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
            window.addEventListener('hashchange', function () { showPanel(window.location.hash); });
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
                        body: new URLSearchParams({action: 'wpmft_admin_protect_media', nonce: config.mediaNonce, wpmft_admin_lang: config.language}).toString()
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
                    body: new URLSearchParams({action: 'wpmft_admin_people', nonce: config.nonce, q: input.value, wpmft_admin_lang: config.language}).toString()
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
                translateLabels(row);
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

        var optimizeButton = document.getElementById('wpmft-optimize-images');
        var pauseOptimization = document.getElementById('wpmft-pause-image-optimization');
        var optimizationStatus = document.getElementById('wpmft-image-optimization-status');
        if (optimizeButton && pauseOptimization && optimizationStatus) {
            var pauseRequested = false, optimizationGeneration = 0;
            var statusController = typeof AbortController === 'function' ? new AbortController() : null;
            function integer(value) { return Number.isSafeInteger(value) && value >= 0; }
            function requestOptimization(values, controller) {
                var body = new URLSearchParams(Object.assign({action: 'wpmft_admin_optimize_media', nonce: config.optimizationNonce, wpmft_admin_lang: config.language}, values));
                var timer;
                var request = fetch(config.ajaxUrl, {method: 'POST', credentials: 'same-origin', body: body, signal: controller ? controller.signal : undefined}).then(function (response) {
                    return response.json().then(function (json) {
                        if (!response.ok || !json || !json.success || !json.data) throw new Error(json && json.data && json.data.message || strings.imagesOptimizationError);
                        return json.data;
                    }, function () { throw new Error(strings.imagesOptimizationError); });
                }, function () { throw new Error(strings.imagesOptimizationError); });
                // A host timeout or broken response must not leave the controls calculating forever.
                return Promise.race([request, new Promise(function (resolve, reject) {
                    timer = setTimeout(function () {
                        if (controller) controller.abort();
                        reject(new Error(strings.imagesOptimizationError));
                    }, 45000);
                })]).finally(function () { clearTimeout(timer); });
            }
            function showOptimization(headline, run) {
                optimizationStatus.textContent = headline;
                if (!run) return;
                var summary = strings.imagesOptimizationProcessed + ' ' + run.processed + ' · ' + strings.imagesOptimizationSkipped + ' ' + run.skipped;
                if (run.remaining !== null) summary += ' · ' + strings.imagesOptimizationUnscanned + ' ' + run.remaining;
                optimizationStatus.append(document.createElement('br'), document.createTextNode(summary));
                run.errors.forEach(function (error) { optimizationStatus.append(document.createElement('br'), document.createTextNode(error)); });
            }
            var statusGeneration = optimizationGeneration;
            optimizationStatus.setAttribute('aria-busy', 'true');
            showOptimization(strings.imagesOptimizationChecking);
            requestOptimization({op: 'status'}, statusController).then(function (data) {
                if (statusGeneration !== optimizationGeneration) return;
                if (!integer(data.pending) || !integer(data.total) || data.pending > data.total) throw new Error(strings.imagesOptimizationCheckError);
                showOptimization(strings.imagesOptimizationPending + ' ' + data.pending + ' / ' + data.total);
            }).catch(function () {
                if (statusGeneration === optimizationGeneration) showOptimization(strings.imagesOptimizationCheckError);
            }).finally(function () {
                if (statusGeneration === optimizationGeneration) optimizationStatus.setAttribute('aria-busy', 'false');
            });
            pauseOptimization.addEventListener('click', function () { pauseRequested = true; pauseOptimization.disabled = true; });
            optimizeButton.addEventListener('click', function () {
                if (optimizeButton.disabled) return;
                optimizationGeneration++;
                if (statusController) statusController.abort();
                var run = {cursor: 0, processed: 0, skipped: 0, remaining: null, errors: []};
                pauseRequested = false;
                optimizeButton.disabled = true;
                pauseOptimization.hidden = false;
                pauseOptimization.disabled = false;
                optimizationStatus.setAttribute('aria-busy', 'true');
                showOptimization(strings.optimizingImages);
                function batch() {
                    if (pauseRequested) { showOptimization(strings.imagesOptimizationPaused, run); return Promise.resolve(); }
                    var controller = typeof AbortController === 'function' ? new AbortController() : null;
                    return requestOptimization({after: run.cursor}, controller).then(function (data) {
                        if (!integer(data.processed) || !integer(data.attempted) || !integer(data.cursor) || !integer(data.remaining) || data.processed > data.attempted || !Array.isArray(data.errors) || data.errors.some(function (error) { return typeof error !== 'string'; })) throw new Error(strings.imagesOptimizationError);
                        data.errors.forEach(function (error) { if (run.errors.indexOf(error) < 0) run.errors.push(error); });
                        if (!data.attempted && data.errors.length) throw new Error(strings.imagesOptimizationError);
                        if ((data.attempted && data.cursor <= run.cursor) || (!data.attempted && data.remaining)) throw new Error(strings.imagesOptimizationError);
                        run.cursor = data.cursor;
                        run.processed += data.processed;
                        run.skipped += data.attempted - data.processed;
                        run.remaining = data.remaining;
                        if (!run.remaining) { showOptimization(run.skipped || run.errors.length ? strings.imagesOptimizationPartial : strings.imagesOptimized, run); return; }
                        if (pauseRequested) { showOptimization(strings.imagesOptimizationPaused, run); return; }
                        showOptimization(strings.optimizingImages, run);
                        return new Promise(function (resolve) { setTimeout(resolve, 150); }).then(batch);
                    });
                }
                batch().catch(function (error) { showOptimization(error.message || strings.imagesOptimizationError, run); }).finally(function () {
                    optimizeButton.disabled = false;
                    pauseOptimization.hidden = true;
                    optimizationStatus.setAttribute('aria-busy', 'false');
                });
            });
        }

        var galleryAddButton = document.getElementById('wpmft-add-gallery');
        var galleryButton = document.getElementById('wpmft-edit-gallery');
        var galleryInput = document.getElementById('wpmft-gallery-ids');
        var galleryPreview = document.getElementById('wpmft-gallery-preview');
        var galleryStatus = document.getElementById('wpmft-gallery-status');
        if (galleryAddButton && galleryButton && galleryInput && galleryPreview && galleryStatus) {
            var galleryFrame;
            var photoDetails = new Map();
            function rememberPhotoDetails() {
                galleryPreview.querySelectorAll('figure[data-id]').forEach(function (figure) {
                    var date = figure.querySelector('[data-gallery-detail="date"]');
                    var story = figure.querySelector('[data-gallery-detail="description"]');
                    photoDetails.set(Number(figure.dataset.id), {date: date ? date.value : '', description: story ? story.value : ''});
                });
            }
            function detailField(id, key, value) {
                var label = document.createElement('label');
                label.className = 'wpmft-field';
                var title = document.createElement('span');
                title.textContent = key === 'date' ? strings.photoDate : strings.photoStory;
                var field = document.createElement(key === 'date' ? 'input' : 'textarea');
                field.name = 'wpmft_person[_wpmft_gallery_details][' + id + '][' + key + ']';
                field.dataset.galleryDetail = key;
                field.value = value || '';
                field.maxLength = key === 'date' ? 100 : 2000;
                if (key === 'date') { field.type = 'text'; field.placeholder = strings.photoDatePlaceholder; }
                else field.rows = 3;
                label.append(title, field);
                return label;
            }
            function ids() {
                return Array.from(new Set(galleryInput.value.split(',').map(Number).filter(function (id) { return Number.isInteger(id) && id > 0; })));
            }
            function setGalleryBusy(busy) {
                galleryAddButton.disabled = busy;
                galleryButton.disabled = busy || !ids().length;
                galleryPreview.setAttribute('aria-busy', String(busy));
                galleryPreview.querySelectorAll('.wpmft-remove-image').forEach(function (button) { button.disabled = busy; });
            }
            function renderImages(attachments, append) {
                rememberPhotoDetails();
                var nextIds = append ? ids() : [];
                if (!append) galleryPreview.replaceChildren();
                attachments.forEach(function (item) {
                    var id = Number(item.id);
                    if (!Number.isInteger(id) || id < 1 || item.type !== 'image' || nextIds.includes(id)) return;
                    nextIds.push(id);
                    var figure = document.createElement('figure');
                    figure.dataset.id = String(id);
                    var image = document.createElement('img');
                    image.src = item.sizes && (item.sizes.medium || item.sizes.thumbnail) ? (item.sizes.medium || item.sizes.thumbnail).url : item.url;
                    image.alt = item.alt || '';
                    image.loading = 'lazy';
                    image.decoding = 'async';
                    var imageWrap = document.createElement('div');
                    imageWrap.className = 'wpmft-gallery-image';
                    imageWrap.append(image);
                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'wpmft-remove-image';
                    remove.textContent = '×';
                    remove.setAttribute('aria-label', strings.removeImage || strings.remove || 'Remove');
                    var details = photoDetails.get(id) || {date: '', description: typeof item.caption === 'string' ? item.caption.replace(/<[^>]*>/g, '') : ''};
                    var caption = document.createElement('figcaption');
                    caption.className = 'wpmft-gallery-details';
                    caption.append(detailField(id, 'date', details.date), detailField(id, 'description', details.description));
                    figure.append(imageWrap, remove, caption);
                    galleryPreview.append(figure);
                });
                galleryInput.value = nextIds.join(',');
                galleryStatus.textContent = '';
                setGalleryBusy(false);
            }
            galleryAddButton.addEventListener('click', function () {
                if (!window.wp || !wp.media) return;
                if (galleryFrame) galleryFrame.dispose();
                var frame = galleryFrame = wp.media({
                    title: strings.addImages, button: {text: strings.addImages},
                    library: {type: 'image'}, multiple: 'add'
                });
                // Adding is deliberately separate from replacing the ordered gallery.
                frame.on('select', function () { renderImages(frame.state().get('selection').toJSON(), true); });
                frame.open();
            });
            galleryButton.addEventListener('click', function () {
                if (!window.wp || !wp.media || galleryButton.disabled) return;
                var selectedIds = ids();
                setGalleryBusy(true);
                galleryStatus.textContent = strings.loadingImages;
                // GalleryEdit filters out models without image metadata when it opens.
                // Load all existing images first; one query also avoids a request per image.
                var existing = wp.media.model.Query.get({type: 'image', post__in: selectedIds, orderby: 'post__in', perPage: -1});
                // Query.fetch forwards failures; the Attachments.more wrapper does not.
                Promise.resolve(existing.fetch({reset: true})).then(function () {
                    var models = selectedIds.map(function (id) { return existing.get(id); });
                    if (models.some(function (model) { return !model || model.get('type') !== 'image'; })) throw new Error('Incomplete gallery');
                    var selection = new wp.media.model.Selection(models, {multiple: true});
                    if (galleryFrame) galleryFrame.dispose();
                    var frame = galleryFrame = wp.media({
                        frame: 'post', state: 'gallery-edit', title: strings.reorderImages,
                        editing: true, multiple: true, selection: selection,
                        library: {type: 'image'}
                    });
                    frame.state('gallery-edit').on('update', function (library) {
                        renderImages(library.toJSON(), false);
                    });
                    galleryStatus.textContent = '';
                    setGalleryBusy(false);
                    frame.open();
                }).catch(function () {
                    // Never replace stored IDs with a partial response or failed load.
                    galleryStatus.textContent = strings.galleryLoadError;
                    setGalleryBusy(false);
                    galleryButton.focus();
                });
            });
            galleryPreview.addEventListener('click', function (event) {
                var remove = event.target.closest('.wpmft-remove-image');
                if (!remove || remove.disabled || !galleryPreview.contains(remove)) return;
                rememberPhotoDetails();
                remove.closest('figure').remove();
                galleryInput.value = Array.from(galleryPreview.querySelectorAll('figure')).map(function (figure) { return figure.dataset.id; }).join(',');
                galleryStatus.textContent = '';
                setGalleryBusy(false);
            });
            setGalleryBusy(false);
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
    else ready();
})();
