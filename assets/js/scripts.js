(function () {
    var arcConfig = window.ContentRankGenerator || {};
    var generators = Array.isArray(arcConfig.generators) ? arcConfig.generators : [];
    var defaults = arcConfig.defaults || {
        generator_id: '',
        name: '',
        feed_url: '',
        generation_mode: 'pillar',
        source_post_id: '0',
        source_type: 'keyword_list',
        list_id: '0',
        keyword_list_mode: 'keywords',
        tavily_enabled: '0',
        status: 'active',
        post_type: 'post',
        post_status: 'draft',
        author_id: '0',
        model: '',
        temperature: '0.7',
        max_tokens: '3000',
        content_length_class: 'medium',
        posts_per_run: '1',
        schedule_type: 'interval',
        interval_minutes: '180',
        jitter_minutes: '30',
        daily_start: '',
        daily_end: '',
        image_source_mode: '',
        pexels_query: '',
        source_video_enabled: '0',
        source_content_images_enabled: '1',
        source_content_links_enabled: '1',
        video_selector_class: '',
        image_selector_class: '',
        link_selector_class: '',
        content_image_size: 'medium',
        source_link_phrases: '',
        source_context_exclude_phrases: '',
        source_context_rating_label: 'IMDb',
        source_context_min_rating: '0',
        source_context_keep_unrated: '0',
        seo_enabled: '1',
        generation_language: 'Português do Brasil',
        category_ids: [],
        default_category_id: '0',
        tags_default: [],
        custom_taxonomies: '',
        custom_meta: '',
        prompt_template: '',
        content_prompt_template: '',
        outline_model_key: '',
        keyword_prompt_template: ''
    };
    var editId = parseInt(arcConfig.editId || 0, 10) || 0;
    var settingsModal = document.getElementById('content-rank-settings-modal');
    var settingsBackdrop = document.getElementById('content-rank-settings-backdrop');
    var runsModal = document.getElementById('content-rank-runs-modal');
    var runsBackdrop = document.getElementById('content-rank-runs-backdrop');
    var generatorImportModal = document.getElementById('content-rank-generator-import-modal');
    var generatorImportBackdrop = document.getElementById('content-rank-generator-import-backdrop');
    var manualRunModal = document.getElementById('content-rank-manual-run-modal');
    var manualRunBackdrop = document.getElementById('content-rank-manual-run-backdrop');
    var manualRunTitle = document.getElementById('content-rank-manual-run-title');
    var manualRunSubtitle = document.getElementById('content-rank-manual-run-subtitle');
    var manualRunCount = document.getElementById('content-rank-manual-run-count');
    var manualRunRefresh = document.getElementById('content-rank-manual-run-refresh');
    var manualRunStatus = document.getElementById('content-rank-manual-run-status');
    var manualRunLoading = document.getElementById('content-rank-manual-run-loading');
    var manualRunEmpty = document.getElementById('content-rank-manual-run-empty');
    var manualRunList = document.getElementById('content-rank-manual-run-list');
    var manualRunForm = document.getElementById('content-rank-manual-run-form');
    var modal = document.getElementById('content-rank-generator-modal');
    var backdrop = document.getElementById('content-rank-generator-backdrop');
    var form = document.getElementById('content-rank-generator-form');
    if (!form) {
        return;
    }
    var titleEl = document.getElementById('content-rank-generator-modal-title');
    var submitEl = document.getElementById('content-rank-generator-submit');
    var feedUrlField = form.querySelector('[data-feed-url-field]');
    var listIdField = form.querySelector('[data-list-id-field]');
    var keywordListModeField = form.querySelector('[data-keyword-list-mode-field]');
    var tavilyField = form.querySelector('[data-tavily-field]');
    var tmdbThumbnailField = form.querySelector('[data-tmdb-thumbnail-field]');
    var imageSourceModeEl = form.querySelector('[name="image_source_mode"]');
    var videoSelectorField = form.querySelector('[data-rss-video-selector-field]');
    var sourceMediaToggleField = form.querySelector('[data-rss-source-media-toggle-field]');
    var sourceSelectorsField = form.querySelector('[data-rss-source-selectors-field]');
    var imageSelectorField = form.querySelector('[data-rss-image-selector-field]');
    var linkSelectorField = form.querySelector('[data-rss-link-selector-field]');
    var imageSizeField = form.querySelector('[data-rss-image-size-field]');
    var imageIntervalField = form.querySelector('[data-rss-image-interval-field]');
    var linkPhrasesField = form.querySelector('[data-rss-link-phrases-field]');
    var sourceFiltersField = form.querySelector('[data-rss-source-filters-field]');
    var apiBase = arcConfig.apiBase || '';
    var restNonce = arcConfig.restNonce || '';
    var openModalCount = 0;
    var manualRunCurrentGeneratorId = '';
    var manualRunCurrentGeneratorName = '';
    var manualRunLoadingRequest = null;
    var manualRunGenerationRequest = null;
    var manualRunGenerating = false;

    function byName(name) {
        return form.querySelector('[name="' + name + '"]');
    }

    function setValue(name, value) {
        var el = byName(name);
        if (el) {
            el.value = value !== undefined && value !== null ? value : '';
            if (typeof Event === 'function') {
                el.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
            } else if (document.createEvent) {
                var changeEvent = document.createEvent('Event');
                changeEvent.initEvent('change', true, false);
                el.dispatchEvent(changeEvent);
            }
        }
    }

    function promptLooksLikeRss(text) {
        var value = String(text || '');
        return value.indexOf('Você é um editor jornalístico especializado em reescrever conteúdo de RSS.') !== -1 ||
            value.indexOf('Você é um jornalista de portal focado em SEO e no estilo GEO') !== -1 ||
            value.indexOf('[DIRETRIZES DE ESCRITA E ESTILO (GEO)]') !== -1;
    }

    function promptLooksLikeKeyword(text) {
        return String(text || '').indexOf('Você é um editor de conteúdo especializado em criar artigos originais a partir de planilhas e palavras-chave.') !== -1;
    }

    function getDefaultImageSourceModeForType(sourceType) {
        return isKeywordListSourceType(sourceType) ? 'pexels' : 'rss_or_pexels';
    }

    function isKeywordListSourceType(sourceType) {
        return ['keyword_list', 'spreadsheet'].indexOf(String(sourceType || '')) !== -1;
    }

    function isSpreadsheetSourceType(sourceType) {
        return String(sourceType || '') === 'spreadsheet';
    }

    function normalizeImageSourceModeForType(sourceType, keywordListMode, value) {
        var mode = String(value || '').trim();
        var allowed = ['rss', 'rss_or_pexels', 'rss_or_dalle', 'pexels', 'dalle', 'tmdb_composite'];
        if (allowed.indexOf(mode) === -1) {
            return getDefaultImageSourceModeForType(sourceType, keywordListMode);
        }
        if (isKeywordListSourceType(sourceType)) {
            if (mode === 'rss' || mode === 'rss_or_pexels') {
                return 'pexels';
            }
            if (mode === 'rss_or_dalle') {
                return 'dalle';
            }
        }
        return mode;
    }

    function normalizePromptForSourceType(sourceType, keywordListMode, value) {
        var current = String(value || '').trim();
        if (!current) {
            if (sourceType === 'keyword_list') {
                return String(keywordListMode || 'keywords') === 'url_reference' ? defaults.prompt_template : defaults.keyword_prompt_template;
            }
            return defaults.prompt_template;
        }
        if (isKeywordListSourceType(sourceType)) {
            if (String(keywordListMode || 'keywords') === 'url_reference') {
                if (current === defaults.keyword_prompt_template) {
                    return defaults.prompt_template;
                }
                return current;
            }
            if (current === defaults.prompt_template) {
                return defaults.keyword_prompt_template;
            }
            return current;
        }
        if (current === defaults.keyword_prompt_template) {
            return defaults.prompt_template;
        }
        return current;
    }

    function setMultiSelect(name, values) {
        var el = byName(name);
        if (!el) {
            return;
        }
        var lookup = {};
        (values || []).forEach(function (value) {
            lookup[String(value)] = true;
        });
        Array.prototype.forEach.call(el.options, function (option) {
            option.selected = !!lookup[String(option.value)];
        });
        if (typeof Event === 'function') {
            el.dispatchEvent(new Event('change', {
                bubbles: true
            }));
        } else if (document.createEvent) {
            var changeEvent = document.createEvent('Event');
            changeEvent.initEvent('change', true, false);
            el.dispatchEvent(changeEvent);
        }
    }

    function setCheckboxGroup(name, values) {
        var lookup = {};
        (values || []).forEach(function (value) {
            lookup[String(value)] = true;
        });
        form.querySelectorAll('input[name="' + name + '"]').forEach(function (input) {
            input.checked = !!lookup[String(input.value)];
        });
    }

    function getCheckedValues(name) {
        var values = [];
        form.querySelectorAll('input[name="' + name + '"]').forEach(function (input) {
            if (input.checked) {
                values.push(String(input.value));
            }
        });
        return values;
    }

    function listToText(value) {
        if (Array.isArray(value)) {
            return value.filter(function (item) {
                return String(item || '').trim() !== '';
            }).join(', ');
        }
        return String(value || '');
    }

    function syncDefaultCategoryField() {
        var defaultCategoryField = form.querySelector('[data-default-category-field]');
        var defaultCategoryEl = byName('default_category_id');
        var selectedCategoryInputs = form.querySelectorAll('input[name="category_ids[]"]:checked');
        var selectedCategoryValues = [];
        selectedCategoryInputs.forEach(function (input) {
            selectedCategoryValues.push(String(input.value));
        });
        var showField = selectedCategoryValues.length > 1;

        if (defaultCategoryField) {
            defaultCategoryField.classList.toggle('hidden', !showField);
        }

        if (!defaultCategoryEl) {
            return;
        }

        defaultCategoryEl.innerHTML = '';
        if (!showField) {
            defaultCategoryEl.value = '0';
            return;
        }

        selectedCategoryInputs.forEach(function (input) {
            var option = document.createElement('option');
            option.value = String(input.value);
            option.textContent = input.closest('label') ? input.closest('label').textContent.replace(/\s+/g, ' ').trim() : String(input.value);
            defaultCategoryEl.appendChild(option);
        });

        var currentValue = String(defaultCategoryEl.value || '0');
        if (currentValue === '0' || selectedCategoryValues.indexOf(currentValue) === -1) {
            defaultCategoryEl.value = selectedCategoryValues.length ? selectedCategoryValues[0] : '0';
        }
    }

    function initSelect2Fields() {
        var $ = window.jQuery;
        if (!$ || !$.fn || !$.fn.select2) {
            return;
        }
    }

    function syncSourceFields() {
        var generationModeEl = byName('generation_mode');
        var generationMode = generationModeEl ? generationModeEl.value : 'pillar';
        var sourceTypeEl = byName('source_type');
        var sourceType = sourceTypeEl ? sourceTypeEl.value : 'keyword_list';
        var keywordListModeEl = byName('keyword_list_mode');
        var keywordListMode = keywordListModeEl ? keywordListModeEl.value : 'keywords';
        imageSourceModeEl = byName('image_source_mode');
        var isSatelliteMode = generationMode === 'satellite';
        var isListSource = isKeywordListSourceType(sourceType);
        var isSpreadsheetSource = isSpreadsheetSourceType(sourceType);
        var listSelect = byName('list_id');
        var listSourceLabel = listIdField ? listIdField.querySelector('[data-list-source-label]') : null;
        var listModeLabel = keywordListModeField ? keywordListModeField.querySelector('[data-list-mode-label]') : null;
        var listPlaceholder = listSelect ? listSelect.querySelector('[data-list-placeholder]') : null;

        if (listSourceLabel) {
            listSourceLabel.textContent = isSpreadsheetSource ? 'Planilha' : 'Keyword list';
        }
        if (listModeLabel) {
            listModeLabel.textContent = isSpreadsheetSource ? 'Modo da planilha' : 'Modo da keyword list';
        }
        if (listPlaceholder) {
            listPlaceholder.textContent = isSpreadsheetSource ? 'Selecione uma planilha' : 'Selecione uma keyword list';
        }
        if (listSelect) {
            Array.prototype.forEach.call(listSelect.options, function (option) {
                if (!option.value || !isListSource) {
                    option.hidden = false;
                    option.disabled = false;
                    return;
                }
                var matchesSource = String(option.getAttribute('data-list-source') || '') === String(sourceType);
                option.hidden = !matchesSource;
                option.disabled = !matchesSource;
            });
            var selectedOption = listSelect.options[listSelect.selectedIndex];
            if (selectedOption && selectedOption.hidden) {
                listSelect.value = '0';
            }
        }

        if (sourceTypeEl && sourceTypeEl.parentElement) {
            sourceTypeEl.parentElement.classList.toggle('hidden', isSatelliteMode);
        }

        if (feedUrlField) {
            feedUrlField.classList.toggle('hidden', isSatelliteMode || isListSource);
        }
        if (listIdField) {
            listIdField.classList.toggle('hidden', isSatelliteMode || !isListSource);
        }
        if (keywordListModeField) {
            keywordListModeField.classList.toggle('hidden', isSatelliteMode || !isSpreadsheetSource);
        }
        if (tavilyField) {
            tavilyField.classList.toggle('hidden', isSatelliteMode || sourceType !== 'keyword_list');
        }
        if (tmdbThumbnailField) {
            tmdbThumbnailField.classList.toggle('hidden', !imageSourceModeEl || imageSourceModeEl.value !== 'tmdb_composite');
        }
        var showSourceMediaControls = !isSatelliteMode && (sourceType === 'rss' || (isSpreadsheetSource && keywordListMode === 'url_reference'));
        var sourceContentImagesEnabledEl = byName('source_content_images_enabled');
        var sourceContentLinksEnabledEl = byName('source_content_links_enabled');
        var useSourceContentImages = !sourceContentImagesEnabledEl || String(sourceContentImagesEnabledEl.value || '1') === '1';
        var useSourceContentLinks = !sourceContentLinksEnabledEl || String(sourceContentLinksEnabledEl.value || '1') === '1';

        if (videoSelectorField) {
            videoSelectorField.classList.toggle('hidden', !showSourceMediaControls);
        }
        if (sourceMediaToggleField) {
            sourceMediaToggleField.classList.toggle('hidden', !showSourceMediaControls);
        }
        if (sourceSelectorsField) {
            sourceSelectorsField.classList.toggle('hidden', !showSourceMediaControls || (!useSourceContentImages && !useSourceContentLinks));
        }
        if (imageSelectorField) {
            imageSelectorField.classList.toggle('hidden', !showSourceMediaControls || !useSourceContentImages);
        }
        if (linkSelectorField) {
            linkSelectorField.classList.toggle('hidden', !showSourceMediaControls || !useSourceContentLinks);
        }
        if (imageSizeField) {
            imageSizeField.classList.toggle('hidden', !showSourceMediaControls || !useSourceContentImages);
        }
        if (imageIntervalField) {
            imageIntervalField.classList.toggle('hidden', isSatelliteMode || !isListSource);
        }
        if (linkPhrasesField) {
            linkPhrasesField.classList.toggle('hidden', !showSourceMediaControls || !useSourceContentLinks);
        }
        if (sourceFiltersField) {
            sourceFiltersField.classList.toggle('hidden', !showSourceMediaControls);
        }
        if (imageSourceModeEl) {
            imageSourceModeEl.value = normalizeImageSourceModeForType(sourceType, keywordListMode, imageSourceModeEl.value);
        }

        var promptEl = byName('prompt_template');
        if (promptEl && !isSatelliteMode) {
            promptEl.value = normalizePromptForSourceType(sourceType, keywordListMode, promptEl.value);
        }
    }

    function parseListValue(value) {
        if (Array.isArray(value)) {
            return value;
        }
        if (typeof value === 'string' && value !== '') {
            try {
                var parsed = JSON.parse(value);
                if (Array.isArray(parsed)) {
                    return parsed;
                }
            } catch (e) { }
            return value.split(',').map(function (part) {
                return part.trim();
            }).filter(Boolean);
        }
        return [];
    }

    function parseObjectValue(value) {
        if (value && typeof value === 'object' && !Array.isArray(value)) {
            return value;
        }
        if (typeof value === 'string' && value !== '') {
            try {
                var parsed = JSON.parse(value);
                if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                    return parsed;
                }
            } catch (e) { }
        }
        return {};
    }

    function objectToLines(objectValue) {
        var lines = [];
        Object.keys(objectValue || {}).forEach(function (key) {
            var value = objectValue[key];
            if (Array.isArray(value)) {
                value = value.join(',');
            }
            lines.push(key + '=' + value);
        });
        return lines.join('\n');
    }

    function escapeHtml(value) {
        return String(value === undefined || value === null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getSwalBridge() {
        return window.ContentRankGeneratorSwal || null;
    }

    function showSwalLoading(title, text) {
        var bridge = getSwalBridge();
        if (bridge && typeof bridge.loading === 'function') {
            return bridge.loading(title, text);
        }
        return Promise.resolve({
            isConfirmed: true
        });
    }

    function showSwalSuccess(title, html) {
        var bridge = getSwalBridge();
        if (bridge && typeof bridge.success === 'function') {
            return bridge.success(title, html);
        }
        return Promise.resolve({
            isConfirmed: true
        });
    }

    function showSwalError(message, title) {
        var bridge = getSwalBridge();
        if (bridge && typeof bridge.error === 'function') {
            return bridge.error(message, title);
        }
        return Promise.resolve({
            isConfirmed: true
        });
    }

    function parseJsonPayload(text) {
        var value = text === undefined || text === null ? '' : String(text);
        value = value.replace(/^\uFEFF/, '').trim();
        if (!value) {
            return null;
        }
        try {
            return JSON.parse(value);
        } catch (error) {
            var jsonStart = value.search(/[\{\[]/);
            if (jsonStart > 0) {
                try {
                    return JSON.parse(value.slice(jsonStart));
                } catch (fallbackError) {}
            }
            return {
                success: false,
                message: value || 'Resposta invalida'
            };
        }
    }

    function setManualRunStatus(message, type) {
        if (!manualRunStatus) {
            return;
        }
        if (!message) {
            manualRunStatus.className = 'hidden mb-4 rounded-xl border px-4 py-3 text-sm';
            manualRunStatus.textContent = '';
            return;
        }
        var classes = 'mb-4 rounded-xl border px-4 py-3 text-sm';
        if (type === 'error') {
            classes += ' border-rose-200 bg-rose-50 text-rose-700';
        } else if (type === 'success') {
            classes += ' border-emerald-200 bg-emerald-50 text-emerald-700';
        } else {
            classes += ' border-slate-200 bg-slate-50 text-slate-600';
        }
        manualRunStatus.className = classes;
        manualRunStatus.textContent = message;
    }

    function setManualRunLoading(isLoading) {
        if (manualRunLoading) {
            manualRunLoading.classList.toggle('hidden', !isLoading);
        }
        if (manualRunList) {
            manualRunList.classList.toggle('hidden', isLoading);
        }
        if (manualRunEmpty && !isLoading) {
            manualRunEmpty.classList.add('hidden');
        }
    }

    function setManualRunItems(items) {
        if (!manualRunList) {
            return;
        }

        manualRunList.innerHTML = '';
        if (manualRunEmpty) {
            manualRunEmpty.classList.add('hidden');
        }
        if (manualRunCount) {
            manualRunCount.textContent = String(items.length);
        }

        if (!items.length) {
            if (manualRunEmpty) {
                manualRunEmpty.classList.remove('hidden');
            }
            return;
        }

        items.forEach(function (item) {
            var excerpt = item.excerpt ? escapeHtml(item.excerpt) : '';
            var permalink = item.permalink ? escapeHtml(item.permalink) : '';
            var date = item.date ? escapeHtml(item.date) : '';
            var card = document.createElement('article');
            card.className = 'rounded-2xl border border-slate-200 bg-slate-50 p-5 shadow-sm';
            card.innerHTML = [
                '<div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">',
                '  <div class="min-w-0 flex-1">',
                '    <div class="flex flex-wrap items-center gap-2">',
                '      <h3 class="text-base font-semibold text-slate-950">' + escapeHtml(item.title || '(Sem título)') + '</h3>',
                '      ' + (date ? '<span class="rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-slate-200">' + date + '</span>' : ''),
                '    </div>',
                excerpt ? '    <p class="mt-2 text-sm leading-6 text-slate-600">' + excerpt + '</p>' : '',
                permalink ? '    <a href="' + permalink + '" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex break-all text-sm text-indigo-600 hover:text-indigo-500">' + permalink + '</a>' : '',
                '  </div>',
                '  <div class="flex-shrink-0">',
                '    <button type="button" data-run-item-guid="' + escapeHtml(item.guid || '') + '" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500">Gerar este item</button>',
                '  </div>',
                '</div>'
            ].join('');
            manualRunList.appendChild(card);
        });
    }

    function submitManualRunItem(itemGuid) {
        var generatorId = String(manualRunCurrentGeneratorId || '');
        if (!generatorId || !itemGuid || manualRunGenerating || window.ContentRankGeneratorManualRunInFlight) {
            return;
        }
        if (!apiBase) {
            showSwalError('A URL da API nao foi configurada.', 'Erro');
            return;
        }

        manualRunGenerating = true;
        window.ContentRankGeneratorManualRunInFlight = true;
        setManualRunStatus('Gerando item selecionado...', 'warning');
        if (window.ContentRankGenerationToast && typeof window.ContentRankGenerationToast.start === 'function') {
            window.ContentRankGenerationToast.start([], 'Gerando item selecionado...');
        }
        showSwalLoading('Gerando item...', 'Aguarde enquanto o post e criado.');

        if (manualRunGenerationRequest && manualRunGenerationRequest.abort) {
            manualRunGenerationRequest.abort();
        }
        manualRunGenerationRequest = typeof AbortController !== 'undefined' ? new AbortController() : null;

        var url = apiBase.replace(/\/$/, '') + '/generators/' + encodeURIComponent(generatorId) + '/generate';
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': restNonce
            },
            body: JSON.stringify({
                item_guid: itemGuid
            }),
            signal: manualRunGenerationRequest ? manualRunGenerationRequest.signal : undefined
        }).then(function (response) {
            return response.text().then(function (text) {
                var payload = parseJsonPayload(text);
                return {
                    ok: response.ok,
                    status: response.status,
                    payload: payload
                };
            });
        }).then(function (result) {
            if (!result.ok || !result.payload || !result.payload.success) {
                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Nao foi possivel gerar este item.');
            }

            var payload = result.payload || {};
            var viewLink = String(payload.view_link || payload.permalink || '');
            var editLink = String(payload.edit_link || '');
            var itemTitle = String(payload.item_title || '');
            if (window.ContentRankGenerationToast && typeof window.ContentRankGenerationToast.start === 'function') {
                window.ContentRankGenerationToast.start(payload.post_id ? [payload.post_id] : [], itemTitle || 'Geração iniciada');
            }
            var htmlParts = [];
            if (itemTitle) {
                htmlParts.push('<p class="mb-3 text-sm text-slate-600">' + escapeHtml(itemTitle) + '</p>');
            }
            if (viewLink) {
                htmlParts.push('<a href="' + escapeHtml(viewLink) + '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white no-underline transition hover:bg-indigo-500">Abrir conteudo</a>');
            }
            if (!viewLink && editLink) {
                htmlParts.push('<a href="' + escapeHtml(editLink) + '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 no-underline transition hover:bg-slate-50">Editar post</a>');
            }

            setManualRunStatus('Item gerado com sucesso.', 'success');
            if (getSwalBridge() && typeof getSwalBridge().close === 'function') {
                getSwalBridge().close();
            }
            return showSwalSuccess('Item gerado com sucesso.', htmlParts.join(' ')).then(function () {
                loadManualRunItems(generatorId);
            });
        }).catch(function (error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            setManualRunStatus(error.message || 'Falha ao gerar o item.', 'error');
            if (getSwalBridge() && typeof getSwalBridge().close === 'function') {
                getSwalBridge().close();
            }
            showSwalError(error.message || 'Falha ao gerar o item.', 'Erro');
        }).finally(function () {
            manualRunGenerating = false;
            window.ContentRankGeneratorManualRunInFlight = false;
            if (manualRunGenerationRequest && manualRunGenerationRequest.abort) {
                manualRunGenerationRequest.abort();
            }
            manualRunGenerationRequest = null;
        });
    }

    function loadManualRunItems(generatorId) {
        if (!generatorId) {
            return;
        }
        manualRunCurrentGeneratorId = String(generatorId);
        setManualRunStatus('', '');
        if (manualRunTitle) {
            manualRunTitle.textContent = 'Escolher item';
        }
        if (manualRunSubtitle) {
            manualRunSubtitle.textContent = 'Escolha um item disponível para gerar um post único.';
        }
        setManualRunLoading(true);

        if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
            manualRunLoadingRequest.abort();
        }
        manualRunLoadingRequest = typeof AbortController !== 'undefined' ? new AbortController() : null;

        var url = apiBase.replace(/\/$/, '') + '/generators/' + encodeURIComponent(generatorId) + '/items?limit=30';
        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': restNonce
            },
            signal: manualRunLoadingRequest ? manualRunLoadingRequest.signal : undefined
        }).then(function (response) {
            return response.text().then(function (text) {
                var payload = parseJsonPayload(text);
                return {
                    ok: response.ok,
                    status: response.status,
                    payload: payload
                };
            });
        }).then(function (result) {
            if (!result.ok || !result.payload || !result.payload.success) {
                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Não foi possível carregar os itens do feed.');
            }
            var payload = result.payload;
            manualRunCurrentGeneratorName = payload.generator && payload.generator.name ? String(payload.generator.name) : '';
            if (manualRunTitle) {
                manualRunTitle.textContent = manualRunCurrentGeneratorName ? ('Escolher item: ' + manualRunCurrentGeneratorName) : 'Escolher item';
            }
            if (manualRunSubtitle) {
                manualRunSubtitle.textContent = 'Escolha um item disponível para gerar um post único.';
            }
            setManualRunItems(payload.items || []);
            if (!payload.items || !payload.items.length) {
                if (manualRunEmpty) {
                    manualRunEmpty.classList.remove('hidden');
                }
            }
            if (manualRunCount) {
                manualRunCount.textContent = String((payload.items || []).length);
            }
            setManualRunStatus('', '');
        }).catch(function (error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            if (manualRunList) {
                manualRunList.innerHTML = '';
            }
            if (manualRunEmpty) {
                manualRunEmpty.classList.add('hidden');
            }
            setManualRunStatus(error.message || 'Falha ao carregar os itens do feed.', 'error');
            showSwalError(error.message || 'Falha ao carregar os itens do feed.', 'Erro');
        }).finally(function () {
            setManualRunLoading(false);
        });
    }

    function applyDefaults() {
        setValue('generator_id', defaults.generator_id);
        setValue('name', defaults.name);
        setValue('feed_url', defaults.feed_url);
        setValue('source_type', defaults.source_type);
        setValue('list_id', defaults.list_id);
        setValue('keyword_list_mode', defaults.keyword_list_mode);
        setValue('tavily_enabled', defaults.tavily_enabled);
        setValue('status', defaults.status);
        setValue('post_type', defaults.post_type);
        setValue('post_status', defaults.post_status);
        setValue('author_id', defaults.author_id);
        setValue('model', defaults.model);
        setValue('temperature', defaults.temperature);
        setValue('max_tokens', defaults.max_tokens);
        setValue('content_length_class', defaults.content_length_class);
        setValue('posts_per_run', defaults.posts_per_run);
        setValue('schedule_type', defaults.schedule_type);
        setValue('interval_minutes', defaults.interval_minutes);
        setValue('jitter_minutes', defaults.jitter_minutes);
        setValue('daily_start', defaults.daily_start || '');
        setValue('daily_end', defaults.daily_end || '');
        setValue('image_source_mode', normalizeImageSourceModeForType(defaults.source_type, defaults.keyword_list_mode, defaults.image_source_mode || getDefaultImageSourceModeForType(defaults.source_type, defaults.keyword_list_mode)));
        setValue('tmdb_thumbnail_bg_color', defaults.tmdb_thumbnail_bg_color || '#c91414');
        setValue('pexels_query', defaults.pexels_query);
        setValue('source_video_enabled', defaults.source_video_enabled);
        setValue('source_content_images_enabled', typeof defaults.source_content_images_enabled !== 'undefined' ? defaults.source_content_images_enabled : '1');
        setValue('source_content_links_enabled', typeof defaults.source_content_links_enabled !== 'undefined' ? defaults.source_content_links_enabled : '1');
        setValue('video_selector_class', defaults.video_selector_class);
        setValue('image_selector_class', defaults.image_selector_class);
        setValue('link_selector_class', defaults.link_selector_class);
        setValue('content_image_size', defaults.content_image_size);
        setValue('source_link_phrases', defaults.source_link_phrases);
        setValue('source_context_exclude_phrases', defaults.source_context_exclude_phrases);
        setValue('source_context_rating_label', defaults.source_context_rating_label);
        setValue('source_context_min_rating', defaults.source_context_min_rating);
        setValue('source_context_keep_unrated', defaults.source_context_keep_unrated);
        setValue('seo_enabled', defaults.seo_enabled);
        setValue('generation_language', defaults.generation_language);
        setValue('default_category_id', defaults.default_category_id);
        setCheckboxGroup('category_ids[]', []);
        setValue('tags_default', listToText(defaults.tags_default));
        setValue('custom_taxonomies', defaults.custom_taxonomies);
        setValue('custom_meta', defaults.custom_meta);
        setValue('prompt_template', defaults.prompt_template);
        setValue('content_prompt_template', defaults.content_prompt_template);
        setValue('outline_model_key', defaults.outline_model_key);
        syncDefaultCategoryField();
        syncSourceFields();
        if (titleEl) {
            titleEl.textContent = 'Adicionar gerador';
        }
        if (submitEl) {
            submitEl.textContent = 'Salvar gerador';
        }
    }

    function fillForm(generator) {
        applyDefaults();
        if (!generator) {
            return;
        }

        setValue('generator_id', generator.id);
        setValue('name', generator.name);
        setValue('feed_url', generator.feed_url);
        setValue('source_type', generator.source_type || defaults.source_type);
        setValue('list_id', typeof generator.list_id !== 'undefined' ? String(generator.list_id) : defaults.list_id);
        setValue('keyword_list_mode', generator.keyword_list_mode || defaults.keyword_list_mode);
        setValue('status', generator.status);
        setValue('post_type', generator.post_type);
        setValue('post_status', generator.post_status);
        setValue('author_id', generator.author_id);
        setValue('model', generator.model);
        setValue('temperature', generator.temperature);
        setValue('max_tokens', generator.max_tokens);
        setValue('content_length_class', generator.content_length_class || defaults.content_length_class);
        setValue('posts_per_run', generator.posts_per_run);
        setValue('schedule_type', generator.schedule_type);
        setValue('interval_minutes', generator.interval_minutes);
        setValue('jitter_minutes', generator.jitter_minutes);
        setValue('daily_start', generator.daily_start || '');
        setValue('daily_end', generator.daily_end || '');
        setValue('image_source_mode', normalizeImageSourceModeForType(generator.source_type || defaults.source_type, generator.keyword_list_mode || defaults.keyword_list_mode, generator.image_source_mode || (typeof generator.pexels_enabled !== 'undefined' ? (String(generator.pexels_enabled) === '1' ? 'rss_or_pexels' : 'rss') : defaults.image_source_mode)));
        setValue('tmdb_thumbnail_bg_color', generator.tmdb_thumbnail_bg_color || defaults.tmdb_thumbnail_bg_color || '#c91414');
        setValue('pexels_query', generator.pexels_query || defaults.pexels_query);
        setValue('source_video_enabled', String(typeof generator.source_video_enabled !== 'undefined' ? generator.source_video_enabled : defaults.source_video_enabled));
        setValue('source_content_images_enabled', String(typeof generator.source_content_images_enabled !== 'undefined' ? generator.source_content_images_enabled : defaults.source_content_images_enabled));
        setValue('source_content_links_enabled', String(typeof generator.source_content_links_enabled !== 'undefined' ? generator.source_content_links_enabled : defaults.source_content_links_enabled));
        setValue('video_selector_class', generator.video_selector_class || defaults.video_selector_class);
        setValue('image_selector_class', generator.image_selector_class || defaults.image_selector_class);
        setValue('link_selector_class', generator.link_selector_class || defaults.link_selector_class);
        setValue('content_image_size', generator.content_image_size || defaults.content_image_size);
        setValue('source_link_phrases', generator.source_link_phrases || defaults.source_link_phrases);
        setValue('source_context_exclude_phrases', generator.source_context_exclude_phrases || defaults.source_context_exclude_phrases);
        setValue('source_context_rating_label', generator.source_context_rating_label || defaults.source_context_rating_label);
        setValue('source_context_min_rating', typeof generator.source_context_min_rating !== 'undefined' ? generator.source_context_min_rating : defaults.source_context_min_rating);
        setValue('source_context_keep_unrated', String(typeof generator.source_context_keep_unrated !== 'undefined' ? generator.source_context_keep_unrated : defaults.source_context_keep_unrated));
        setValue('seo_enabled', String(typeof generator.seo_enabled !== 'undefined' ? generator.seo_enabled : defaults.seo_enabled));
        setValue('generation_language', generator.generation_language || defaults.generation_language);
        setCheckboxGroup('category_ids[]', parseListValue(generator.category_ids));
        setValue('default_category_id', typeof generator.default_category_id !== 'undefined' ? String(generator.default_category_id) : defaults.default_category_id);
        setValue('tags_default', listToText(parseListValue(generator.tags_default)));
        setValue('custom_taxonomies', objectToLines(parseObjectValue(generator.custom_taxonomies)));
        setValue('custom_meta', objectToLines(parseObjectValue(generator.custom_meta)));
        setValue('prompt_template', normalizePromptForSourceType(generator.source_type || defaults.source_type, generator.keyword_list_mode || defaults.keyword_list_mode, generator.prompt_template || (generator.source_type === 'keyword_list' ? defaults.keyword_prompt_template : defaults.prompt_template)));
        setValue('content_prompt_template', generator.content_prompt_template || defaults.content_prompt_template);
        setValue('outline_model_key', generator.outline_model_key || defaults.outline_model_key);
        syncDefaultCategoryField();
        syncSourceFields();

        if (titleEl) {
            titleEl.textContent = 'Editar gerador';
        }
        if (submitEl) {
            submitEl.textContent = 'Atualizar gerador';
        }
    }

    var sourceTypeEl = byName('source_type');
    if (sourceTypeEl) {
        sourceTypeEl.addEventListener('change', syncSourceFields);
    }
    var generationModeEl = byName('generation_mode');
    if (generationModeEl) {
        generationModeEl.addEventListener('change', syncSourceFields);
    }
    var keywordListModeEl = byName('keyword_list_mode');
    if (keywordListModeEl) {
        keywordListModeEl.addEventListener('change', syncSourceFields);
    }
    if (imageSourceModeEl) {
        imageSourceModeEl.addEventListener('change', syncSourceFields);
    }
    form.querySelectorAll('input[name="category_ids[]"]').forEach(function (input) {
        input.addEventListener('change', syncDefaultCategoryField);
    });
    var defaultCategoryEl = byName('default_category_id');
    if (defaultCategoryEl) {
        defaultCategoryEl.addEventListener('change', syncDefaultCategoryField);
    }
    var sourceContentImagesEnabledEl = byName('source_content_images_enabled');
    if (sourceContentImagesEnabledEl) {
        sourceContentImagesEnabledEl.addEventListener('change', syncSourceFields);
    }
    var sourceContentLinksEnabledEl = byName('source_content_links_enabled');
    if (sourceContentLinksEnabledEl) {
        sourceContentLinksEnabledEl.addEventListener('change', syncSourceFields);
    }

    initSelect2Fields();

    function syncBodyLock() {
        document.body.classList.toggle('overflow-hidden', openModalCount > 0);
    }

    function openModal(targetModal) {
        if (!targetModal || !targetModal.classList.contains('hidden')) {
            return;
        }
        targetModal.classList.remove('hidden');
        openModalCount++;
        syncBodyLock();
    }

    function closeModal(targetModal) {
        if (!targetModal || targetModal.classList.contains('hidden')) {
            return;
        }
        targetModal.classList.add('hidden');
        openModalCount = Math.max(0, openModalCount - 1);
        syncBodyLock();
    }

    function resetGeneratorForm() {
        form.reset();
        applyDefaults();
    }

    document.querySelectorAll('[data-open-settings-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            openModal(settingsModal);
        });
    });

    document.querySelectorAll('[data-open-runs-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            openModal(runsModal);
        });
    });

    document.querySelectorAll('[data-open-generator-import-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            openModal(generatorImportModal);
        });
    });

    document.querySelectorAll('[data-open-manual-run-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            var generatorId = String(button.getAttribute('data-generator-id') || '');
            var generatorName = String(button.getAttribute('data-generator-name') || '');
            manualRunCurrentGeneratorId = generatorId;
            manualRunCurrentGeneratorName = generatorName;
            if (manualRunSubtitle) {
                manualRunSubtitle.textContent = generatorName ? ('Carregando itens do gerador "' + generatorName + '"...') : 'Carregando itens disponíveis...';
            }
            if (manualRunTitle) {
                manualRunTitle.textContent = 'Escolher item';
            }
            setManualRunStatus('', '');
            setManualRunLoading(true);
            if (manualRunList) {
                manualRunList.innerHTML = '';
            }
            if (manualRunEmpty) {
                manualRunEmpty.classList.add('hidden');
            }
            openModal(manualRunModal);
            loadManualRunItems(generatorId);
        });
    });

    document.querySelectorAll('[data-open-generator-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            fillForm(null);
            openModal(modal);
        });
    });

    document.querySelectorAll('[data-edit-generator-id]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
            var id = String(button.getAttribute('data-edit-generator-id') || '');
            var generator = generators.find(function (item) {
                return String(item.id) === id;
            });
            fillForm(generator || null);
            openModal(modal);
        });
    });

    document.querySelectorAll('[data-close-generator-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal(modal);
            resetGeneratorForm();
        });
    });

    document.querySelectorAll('[data-close-settings-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal(settingsModal);
        });
    });

    document.querySelectorAll('[data-close-runs-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal(runsModal);
        });
    });

    document.querySelectorAll('[data-close-generator-import-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal(generatorImportModal);
        });
    });

    document.querySelectorAll('[data-close-manual-run-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal(manualRunModal);
            setManualRunStatus('', '');
            if (manualRunList) {
                manualRunList.innerHTML = '';
            }
            if (manualRunEmpty) {
                manualRunEmpty.classList.add('hidden');
            }
            if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
                manualRunLoadingRequest.abort();
            }
            if (manualRunGenerationRequest && manualRunGenerationRequest.abort) {
                manualRunGenerationRequest.abort();
            }
        });
    });

    if (backdrop) {
        backdrop.addEventListener('click', function () {
            closeModal(modal);
            resetGeneratorForm();
        });
    }

    if (settingsBackdrop) {
        settingsBackdrop.addEventListener('click', function () {
            closeModal(settingsModal);
        });
    }

    if (runsBackdrop) {
        runsBackdrop.addEventListener('click', function () {
            closeModal(runsModal);
        });
    }

    if (generatorImportBackdrop) {
        generatorImportBackdrop.addEventListener('click', function () {
            closeModal(generatorImportModal);
        });
    }

    if (manualRunBackdrop) {
        manualRunBackdrop.addEventListener('click', function () {
            closeModal(manualRunModal);
            setManualRunStatus('', '');
            if (manualRunList) {
                manualRunList.innerHTML = '';
            }
            if (manualRunEmpty) {
                manualRunEmpty.classList.add('hidden');
            }
            if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
                manualRunLoadingRequest.abort();
            }
            if (manualRunGenerationRequest && manualRunGenerationRequest.abort) {
                manualRunGenerationRequest.abort();
            }
        });
    }

    if (manualRunRefresh) {
        manualRunRefresh.addEventListener('click', function () {
            if (manualRunCurrentGeneratorId) {
                loadManualRunItems(manualRunCurrentGeneratorId);
            }
        });
    }

    if (manualRunList) {
        manualRunList.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('[data-run-item-guid]') : null;
            if (!button) {
                return;
            }
            var itemGuid = String(button.getAttribute('data-run-item-guid') || '');
            if (itemGuid !== '') {
                submitManualRunItem(itemGuid);
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            if (modal && !modal.classList.contains('hidden')) {
                closeModal(modal);
                resetGeneratorForm();
            }
            if (settingsModal && !settingsModal.classList.contains('hidden')) {
                closeModal(settingsModal);
            }
            if (runsModal && !runsModal.classList.contains('hidden')) {
                closeModal(runsModal);
            }
            if (generatorImportModal && !generatorImportModal.classList.contains('hidden')) {
                closeModal(generatorImportModal);
            }
            if (manualRunModal && !manualRunModal.classList.contains('hidden')) {
                closeModal(manualRunModal);
                setManualRunStatus('', '');
                if (manualRunList) {
                    manualRunList.innerHTML = '';
                }
                if (manualRunEmpty) {
                    manualRunEmpty.classList.add('hidden');
                }
                if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
                    manualRunLoadingRequest.abort();
                }
                if (manualRunGenerationRequest && manualRunGenerationRequest.abort) {
                    manualRunGenerationRequest.abort();
                }
            }
        }
    });

    if (editId > 0) {
        var initialGenerator = generators.find(function (item) {
            return String(item.id) === String(editId);
        });
        if (initialGenerator) {
            fillForm(initialGenerator);
            openModal(modal);
        }
    } else {
        applyDefaults();
    }
})();
