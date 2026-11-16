/**
 * SCaT Joomla 1.5 to WordPress Content Migrator - Admin Script
 */
(function () {
    'use strict';

    const config = window.scatMigratorData || {
        ajaxUrl: '',
        migratorNonce: '',
        archiveNonce: '',
        archiveUrl: ''
    };

    function formatBytes(bytes) {
        const num = Number(bytes) || 0;
        if (num <= 0) {
            return '0 Б';
        }
        const units = ['Б', 'КБ', 'МБ', 'ГБ'];
        const i = Math.min(Math.floor(Math.log(num) / Math.log(1024)), units.length - 1);
        const val = num / Math.pow(1024, i);
        return (i === 0 ? val.toFixed(0) : val.toFixed(1)) + ' ' + units[i];
    }

    function postAjax(data) {
        const body = new URLSearchParams();
        Object.keys(data).forEach((key) => {
            body.append(key, String(data[key]));
        });

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then((res) => res.json());
    }

    /* ==========================================================================
       1. WebP Converter Module (AdminPage)
       ========================================================================== */
    function initWebpConverter() {
        const card = document.getElementById('scat-webp-converter-card');
        if (!card) {
            return;
        }

        const elSourceCount = document.getElementById('scat-stat-source-count');
        const elSourceSize = document.getElementById('scat-stat-source-size');
        const elWebpCount = document.getElementById('scat-stat-webp-count');
        const elWebpSize = document.getElementById('scat-stat-webp-size');
        const elPendingCount = document.getElementById('scat-stat-pending-count');
        const elSkippedInfo = document.getElementById('scat-stat-skipped-info');
        const elDocsCount = document.getElementById('scat-stat-docs-count');
        const elDocsSize = document.getElementById('scat-stat-docs-size');

        const inputMaxWidth = document.getElementById('scat-max-width');
        const inputQuality = document.getElementById('scat-webp-quality');
        const inputBatchSize = document.getElementById('scat-batch-size');

        const btnScan = document.getElementById('scat-btn-scan');
        const btnConvert = document.getElementById('scat-btn-convert');
        const btnStop = document.getElementById('scat-btn-stop');

        const progressWrap = document.getElementById('scat-progress-wrap');
        const progressLabel = document.getElementById('scat-progress-label');
        const progressPercent = document.getElementById('scat-progress-percent');
        const progressFill = document.getElementById('scat-progress-bar-fill');

        const logDetails = document.getElementById('scat-log-details');
        const logCount = document.getElementById('scat-log-count');
        const logOutput = document.getElementById('scat-log-output');

        let isConverting = false;
        let stopRequested = false;
        let errorEntries = [];
        let pendingTotal = 0;

        function appendLogs(lines) {
            if (!lines || !lines.length) {
                return;
            }
            lines.forEach((line) => errorEntries.push(line));
            logDetails.style.display = 'block';
            logCount.textContent = String(errorEntries.length);
            logOutput.textContent = errorEntries.join('\n');
        }

        function updateStatsUI(stats) {
            elSourceCount.textContent = String(stats.total_source_count || 0);
            elSourceSize.textContent = stats.images_dir_exists
                ? 'Розмір: ' + formatBytes(stats.total_source_bytes)
                : 'Папка joomla-legacy/images не знайдена';

            elWebpCount.textContent = String(stats.converted_count || 0);

            let savingsText = 'Розмір: ' + formatBytes(stats.converted_bytes);
            if (
                stats.total_source_count > 0 &&
                stats.converted_count > 0 &&
                stats.total_source_bytes > 0
            ) {
                const avgSourcePerFile = stats.total_source_bytes / stats.total_source_count;
                const estimatedOrigBytes = avgSourcePerFile * stats.converted_count;
                if (estimatedOrigBytes > stats.converted_bytes) {
                    const savedPct = Math.round((1 - stats.converted_bytes / estimatedOrigBytes) * 100);
                    if (savedPct > 0) {
                        savingsText += ' (економія ~' + savedPct + '%)';
                    }
                }
            }
            elWebpSize.textContent = savingsText;

            pendingTotal = Number(stats.pending_count) || 0;
            elPendingCount.textContent = String(pendingTotal);
            elSkippedInfo.textContent = 'Пропущено битих імен: ' + String(stats.skipped_invalid || 0);

            elDocsCount.textContent = String(stats.docs_count || 0);
            elDocsSize.textContent = stats.docs_dir_exists
                ? 'Розмір: ' + formatBytes(stats.docs_bytes)
                : 'Папка dmdocuments не знайдена';

            btnConvert.disabled = isConverting || pendingTotal <= 0;
        }

        function runScan() {
            if (isConverting) {
                return;
            }

            btnScan.disabled = true;
            btnConvert.disabled = true;
            elSourceSize.textContent = 'Сканування диска...';

            postAjax({
                action: 'scat_migrator_scan_images',
                nonce: config.migratorNonce
            })
                .then((resp) => {
                    btnScan.disabled = false;
                    if (!resp || !resp.success || !resp.data) {
                        const msg = (resp && resp.data && resp.data.message) || 'Помилка сканування архіву.';
                        elSourceSize.textContent = msg;
                        return;
                    }

                    errorEntries = [];
                    logOutput.textContent = '';
                    logCount.textContent = '0';
                    logDetails.style.display = 'none';

                    if (Array.isArray(resp.data.skipped_files) && resp.data.skipped_files.length > 0) {
                        appendLogs(resp.data.skipped_files.map((item) => '[SKIP] ' + item));
                    }

                    updateStatsUI(resp.data);
                })
                .catch(() => {
                    btnScan.disabled = false;
                    elSourceSize.textContent = 'Помилка мережі під час сканування.';
                });
        }

        function setProgress(pct, text) {
            const safePct = Math.max(0, Math.min(100, Number(pct) || 0));
            progressWrap.style.display = 'block';
            progressPercent.textContent = safePct + '%';
            progressFill.style.width = safePct + '%';
            if (text) {
                progressLabel.textContent = text;
            }
        }

        function finishConversion(message) {
            isConverting = false;
            stopRequested = false;
            btnScan.disabled = false;
            btnStop.disabled = true;
            progressLabel.textContent = message;
            runScan();
        }

        function processBatch(offset, convertedSoFar) {
            if (stopRequested) {
                finishConversion('Конвертацію зупинено користувачем.');
                return;
            }

            const batchSize = Math.max(1, Math.min(200, parseInt(inputBatchSize.value, 10) || 25));
            const maxWidth = Math.max(100, Math.min(8000, parseInt(inputMaxWidth.value, 10) || 1920));
            const quality = Math.max(10, Math.min(100, parseInt(inputQuality.value, 10) || 82));

            postAjax({
                action: 'scat_migrator_convert_batch',
                nonce: config.migratorNonce,
                offset: offset,
                batch_size: batchSize,
                max_width: maxWidth,
                quality: quality
            })
                .then((resp) => {
                    if (!resp || !resp.success || !resp.data) {
                        const errMsg = (resp && resp.data && resp.data.message) || 'Помилка пакетної конвертації.';
                        appendLogs(['[ERROR] ' + errMsg]);
                        finishConversion('Зупинено через помилку: ' + errMsg);
                        return;
                    }

                    const data = resp.data;
                    const total = Number(data.total) || 0;
                    const nextOffset = Number(data.next_offset) || 0;
                    const batchConverted = Number(data.converted) || 0;
                    const totalConverted = convertedSoFar + batchConverted;
                    const pct = Number(data.percent) || 0;

                    if (Array.isArray(data.errors) && data.errors.length > 0) {
                        const lines = data.errors.map((err) => '[ERR] ' + err.file + ' — ' + err.reason);
                        appendLogs(lines);
                    }

                    setProgress(
                        pct,
                        'Оброблено: ' + nextOffset + ' із ' + total + ' (сконвертовано: ' + totalConverted + ')'
                    );

                    const remaining = Math.max(0, total - nextOffset);
                    elPendingCount.textContent = String(remaining);

                    if (nextOffset >= total || total === 0) {
                        setProgress(100, 'Готово! Успішно сконвертовано файлів: ' + totalConverted);
                        finishConversion('Готово! Успішно сконвертовано файлів: ' + totalConverted);
                        return;
                    }

                    processBatch(nextOffset, totalConverted);
                })
                .catch(() => {
                    appendLogs(['[ERROR] Мережевий збій під час виконання пакету на позиції ' + offset]);
                    finishConversion('Зупинено через мережеву помилку.');
                });
        }

        btnScan.addEventListener('click', runScan);

        btnConvert.addEventListener('click', function () {
            if (isConverting || pendingTotal <= 0) {
                return;
            }

            isConverting = true;
            stopRequested = false;
            btnScan.disabled = true;
            btnConvert.disabled = true;
            btnStop.disabled = false;

            setProgress(0, 'Запуск пакетної конвертації...');
            processBatch(0, 0);
        });

        btnStop.addEventListener('click', function () {
            if (!isConverting) {
                return;
            }
            stopRequested = true;
            btnStop.disabled = true;
            progressLabel.textContent = 'Зупинка після поточного пакету...';
        });

        runScan();
    }

    /* ==========================================================================
       2. Stage 2: Database Analyzer, Content Migrator & AI Translator (AdminPage)
       ========================================================================== */
    function initContentMigrator() {
        const card = document.getElementById('scat-content-migrator-card');
        if (!card) {
            return;
        }

        const tablesContainer = document.getElementById('scat-db-tables-status');
        const dbActionStatus = document.getElementById('scat-db-action-status');
        const btnLoadSql = document.getElementById('scat-btn-load-sql');
        const btnAnalyzeDb = document.getElementById('scat-btn-analyze-db');
        const btnDropTables = document.getElementById('scat-btn-drop-tables');

        const masterCb = document.getElementById('scat-cats-master-cb');
        const btnSelectAll = document.getElementById('scat-btn-select-all-cats');
        const btnUnselectAll = document.getElementById('scat-btn-unselect-all-cats');
        const catsTbody = document.getElementById('scat-joomla-cats-tbody');

        const selectedArticlesBanner = document.getElementById('scat-selected-articles-banner');
        const selectedArticlesCountEl = document.getElementById('scat-selected-articles-count');
        const btnClearSelectedArticles = document.getElementById('scat-btn-clear-selected-articles');

        const selectWpCat = document.getElementById('scat-target-wp-cat');
        const inputNewCatUk = document.getElementById('scat-new-cat-uk');
        const inputNewCatRu = document.getElementById('scat-new-cat-ru');
        const inputNewCatEn = document.getElementById('scat-new-cat-en');
        const btnCreateArchiveCat = document.getElementById('scat-btn-create-archive-cat');
        const chipButtons = card.querySelectorAll('.scat-chip-btn');

        const selectJfMode = document.getElementById('scat-jf-mode');
        const selectPostType = document.getElementById('scat-post-type');
        const inputTargetLang = document.getElementById('scat-target-lang');
        const selectSlugMode = document.getElementById('scat-slug-mode');
        const inputExcerptLen = document.getElementById('scat-excerpt-length');
        const inputBatchSize = document.getElementById('scat-articles-batch-size');

        const cbFeaturedImage = document.getElementById('scat-set-featured-image');
        const cbStripFirstImg = document.getElementById('scat-strip-first-img');
        const cbSkipExisting = document.getElementById('scat-skip-existing');
        const cbOnlyPublished = document.getElementById('scat-only-published');

        const btnImportArticles = document.getElementById('scat-btn-import-articles');
        const btnStopArticles = document.getElementById('scat-btn-stop-articles');

        const progressWrap = document.getElementById('scat-articles-progress-wrap');
        const progressLabel = document.getElementById('scat-articles-progress-label');
        const progressPercent = document.getElementById('scat-articles-progress-percent');
        const progressFill = document.getElementById('scat-articles-progress-fill');

        const logWrap = document.getElementById('scat-articles-log-wrap');
        const logCountEl = document.getElementById('scat-articles-log-count');
        const logListEl = document.getElementById('scat-articles-log-list');

        // Modal elements
        const modalBackdrop = document.getElementById('scat-category-preview-modal');
        const modalTitle = document.getElementById('scat-modal-title');
        const modalCloseBtn = document.getElementById('scat-modal-close');
        const modalCancelBtn = document.getElementById('scat-modal-cancel');
        const modalApplyBtn = document.getElementById('scat-modal-apply');
        const modalSelectAllBtn = document.getElementById('scat-modal-select-all');
        const modalUnselectAllBtn = document.getElementById('scat-modal-unselect-all');
        const modalMasterCb = document.getElementById('scat-modal-master-cb');
        const modalSummary = document.getElementById('scat-modal-summary');
        const modalTbody = document.getElementById('scat-modal-articles-tbody');

        let isImporting = false;
        let stopImportRequested = false;
        let totalLogItems = 0;
        let selectedArticleIds = [];

        let pendingSlugs = {
            uk: 'archive-news-uk',
            ru: 'archive-news-ru',
            en: 'news-archive-en'
        };

        function showDbStatus(text, isError) {
            dbActionStatus.style.display = 'block';
            dbActionStatus.style.borderLeftColor = isError ? '#b32d2e' : '#2271b1';
            dbActionStatus.style.background = isError ? '#fcf0f1' : '#f0f6fc';
            dbActionStatus.textContent = text;
        }

        function updateSelectedArticlesBanner() {
            if (!selectedArticlesBanner || !selectedArticlesCountEl) {
                return;
            }
            if (selectedArticleIds.length > 0) {
                selectedArticlesCountEl.textContent = String(selectedArticleIds.length);
                selectedArticlesBanner.style.display = 'flex';
            } else {
                selectedArticlesBanner.style.display = 'none';
            }
        }

        if (btnClearSelectedArticles) {
            btnClearSelectedArticles.addEventListener('click', () => {
                selectedArticleIds = [];
                updateSelectedArticlesBanner();
            });
        }

        function updateTablesBadges(tables) {
            if (!tables || !tablesContainer) {
                return;
            }
            tablesContainer.textContent = '';
            Object.keys(tables).forEach((tblName) => {
                const info = tables[tblName];
                const span = document.createElement('span');
                span.className = 'scat-table-badge ' + (info.exists ? 'is-loaded' : 'is-missing');
                span.setAttribute('data-table', tblName);

                const icon = document.createTextNode((info.exists ? '✅ ' : '⚪ '));
                const code = document.createElement('code');
                code.textContent = tblName;
                const colon = document.createTextNode(': ');
                const strong = document.createElement('strong');
                strong.textContent = info.exists ? String(info.rows) + ' рядків' : 'не завантажена';

                span.appendChild(icon);
                span.appendChild(code);
                span.appendChild(colon);
                span.appendChild(strong);
                tablesContainer.appendChild(span);
            });
        }

        function populateCategorySelect(selectEl, wpCategories, selectedId, defaultText) {
            if (!selectEl || !Array.isArray(wpCategories)) {
                return;
            }
            const prevVal = selectedId ? String(selectedId) : selectEl.value;
            selectEl.textContent = '';

            const defaultOpt = document.createElement('option');
            defaultOpt.value = '0';
            defaultOpt.textContent = defaultText;
            selectEl.appendChild(defaultOpt);

            wpCategories.forEach((cat) => {
                const opt = document.createElement('option');
                opt.value = String(cat.term_id);
                let label = cat.name + ' (' + cat.slug + ')';
                if (cat.lang) {
                    label += ' [' + String(cat.lang).toUpperCase() + ']';
                }
                opt.textContent = label;
                if (String(cat.term_id) === prevVal) {
                    opt.selected = true;
                }
                selectEl.appendChild(opt);
            });
        }

        function updateWpCategoriesDropdown(wpCategories, selectedId) {
            populateCategorySelect(
                selectWpCat,
                wpCategories,
                selectedId,
                '— Без прив\'язки до рубрики (за замовчуванням) —'
            );
            populateCategorySelect(
                selectAiCat,
                wpCategories,
                selectedId,
                '— Виберіть рубрику для доперекладу —'
            );
        }

        /* Modal Preview Logic */
        function updateModalApplyButtonCounter() {
            if (!modalTbody || !modalApplyBtn) {
                return;
            }
            const checkedCount = modalTbody.querySelectorAll('.scat-modal-article-cb:checked').length;
            modalApplyBtn.textContent = '✅ Використати вибрані статті (' + checkedCount + ' шт.) для імпорту';
        }

        function closeCategoryModal() {
            if (modalBackdrop) {
                modalBackdrop.style.display = 'none';
            }
        }

        function openCategoryModal(catId, catTitle) {
            if (!modalBackdrop || !modalTbody) {
                return;
            }

            modalTitle.textContent = '👁️ Статті категорії: ' + catTitle + ' (catid=' + catId + ')';
            modalSummary.textContent = 'Завантаження...';
            modalTbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">Завантаження списку статей...</td></tr>';
            if (modalMasterCb) {
                modalMasterCb.checked = false;
            }
            updateModalApplyButtonCounter();
            modalBackdrop.style.display = 'flex';

            postAjax({
                action: 'scat_migrator_preview_category',
                nonce: config.migratorNonce,
                catid: catId
            })
                .then((resp) => {
                    if (!resp || !resp.success || !resp.data) {
                        const msg = (resp && resp.data && resp.data.message) || 'Не вдалося завантажити статті категорії.';
                        modalTbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: #b32d2e;">' + msg + '</td></tr>';
                        return;
                    }

                    const articles = resp.data.articles || [];
                    modalSummary.textContent = 'Всього статей: ' + articles.length;
                    modalTbody.textContent = '';

                    if (articles.length === 0) {
                        modalTbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">У цій категорії немає статей.</td></tr>';
                        updateModalApplyButtonCounter();
                        return;
                    }

                    const selectedLookup = new Set(selectedArticleIds);

                    articles.forEach((art) => {
                        const tr = document.createElement('tr');

                        const tdCb = document.createElement('td');
                        const cb = document.createElement('input');
                        cb.type = 'checkbox';
                        cb.className = 'scat-modal-article-cb';
                        cb.value = String(art.id);
                        cb.checked = selectedLookup.has(Number(art.id)) || (!art.migrated && selectedLookup.size === 0);
                        cb.addEventListener('change', updateModalApplyButtonCounter);
                        tdCb.appendChild(cb);

                        const tdId = document.createElement('td');
                        const codeId = document.createElement('code');
                        codeId.textContent = String(art.id);
                        tdId.appendChild(codeId);

                        const tdDate = document.createElement('td');
                        tdDate.textContent = art.created;

                        const tdOrigTitle = document.createElement('td');
                        tdOrigTitle.textContent = art.title || '—';

                        const tdUkTitle = document.createElement('td');
                        if (art.uk_title) {
                            const strongUk = document.createElement('strong');
                            strongUk.textContent = '🇺🇦 ' + art.uk_title;
                            tdUkTitle.appendChild(strongUk);
                        } else {
                            tdUkTitle.style.color = '#8c8f94';
                            tdUkTitle.textContent = '— (немає перекладу)';
                        }

                        const tdLangs = document.createElement('td');
                        const langsArr = [];
                        if (art.has_uk) {
                            langsArr.push('🇺🇦 UK');
                        }
                        if (art.has_en) {
                            langsArr.push('🇬🇧 EN');
                        }
                        tdLangs.textContent = langsArr.length > 0 ? langsArr.join(' | ') : 'Лише оригінал';

                        const tdStatus = document.createElement('td');
                        if (art.migrated && art.wp_post_id) {
                            const link = document.createElement('a');
                            link.href = art.wp_edit_url || art.wp_url || '#';
                            link.target = '_blank';
                            link.rel = 'noopener noreferrer';
                            link.textContent = '✅ WP #' + art.wp_post_id;
                            tdStatus.appendChild(link);
                        } else {
                            tdStatus.style.color = '#646970';
                            tdStatus.textContent = 'Ще не перенесено';
                        }

                        tr.appendChild(tdCb);
                        tr.appendChild(tdId);
                        tr.appendChild(tdDate);
                        tr.appendChild(tdOrigTitle);
                        tr.appendChild(tdUkTitle);
                        tr.appendChild(tdLangs);
                        tr.appendChild(tdStatus);

                        modalTbody.appendChild(tr);
                    });

                    updateModalApplyButtonCounter();
                })
                .catch(() => {
                    modalTbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: #b32d2e;">Мережева помилка завантаження статей.</td></tr>';
                });
        }

        if (modalCloseBtn) {
            modalCloseBtn.addEventListener('click', closeCategoryModal);
        }
        if (modalCancelBtn) {
            modalCancelBtn.addEventListener('click', closeCategoryModal);
        }
        if (modalBackdrop) {
            modalBackdrop.addEventListener('click', (e) => {
                if (e.target === modalBackdrop) {
                    closeCategoryModal();
                }
            });
        }

        function toggleModalCheckboxes(checked) {
            if (!modalTbody) {
                return;
            }
            modalTbody.querySelectorAll('.scat-modal-article-cb').forEach((cb) => {
                cb.checked = checked;
            });
            if (modalMasterCb) {
                modalMasterCb.checked = checked;
            }
            updateModalApplyButtonCounter();
        }

        if (modalMasterCb) {
            modalMasterCb.addEventListener('change', () => {
                toggleModalCheckboxes(modalMasterCb.checked);
            });
        }
        if (modalSelectAllBtn) {
            modalSelectAllBtn.addEventListener('click', () => toggleModalCheckboxes(true));
        }
        if (modalUnselectAllBtn) {
            modalUnselectAllBtn.addEventListener('click', () => toggleModalCheckboxes(false));
        }

        if (modalApplyBtn) {
            modalApplyBtn.addEventListener('click', () => {
                const picked = [];
                if (modalTbody) {
                    modalTbody.querySelectorAll('.scat-modal-article-cb:checked').forEach((cb) => {
                        const id = parseInt(cb.value, 10);
                        if (id > 0) {
                            picked.push(id);
                        }
                    });
                }
                selectedArticleIds = picked;
                updateSelectedArticlesBanner();
                closeCategoryModal();
            });
        }

        function renderJoomlaCategoriesTable(categories) {
            catsTbody.textContent = '';
            if (!Array.isArray(categories) || categories.length === 0) {
                const tr = document.createElement('tr');
                const td = document.createElement('td');
                td.colSpan = 9;
                td.style.textAlign = 'center';
                td.style.color = '#646970';
                td.textContent = 'Категорії не знайдено або таблиця jos_content порожня.';
                tr.appendChild(td);
                catsTbody.appendChild(tr);
                return;
            }

            categories.forEach((cat) => {
                const tr = document.createElement('tr');

                const tdCb = document.createElement('td');
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'scat-joomla-cat-cb';
                cb.value = String(cat.cat_id);
                tdCb.appendChild(cb);

                const tdId = document.createElement('td');
                const codeId = document.createElement('code');
                codeId.textContent = String(cat.cat_id);
                tdId.appendChild(codeId);

                const tdSec = document.createElement('td');
                tdSec.textContent = cat.section_title + ' (#' + cat.section_id + ')';

                const tdCat = document.createElement('td');
                const strongCat = document.createElement('strong');
                strongCat.textContent = cat.cat_title;
                tdCat.appendChild(strongCat);

                const tdCounts = document.createElement('td');
                tdCounts.textContent =
                    '✅ ' + cat.published_count +
                    ' / 📦 ' + cat.archived_count +
                    ' / 📝 ' + cat.draft_count +
                    ' (всього: ' + cat.total_count + ')';

                const tdDates = document.createElement('td');
                tdDates.textContent = cat.min_date + ' — ' + cat.max_date;

                const tdJf = document.createElement('td');
                const ukCount = Number(cat.jf_uk_count) || 0;
                const enCount = Number(cat.jf_en_count) || 0;
                tdJf.textContent = '🇺🇦 UK: ' + ukCount + ' | 🇬🇧 EN: ' + enCount;

                const tdMigrated = document.createElement('td');
                const pubTotal = Number(cat.published_count) + Number(cat.archived_count);
                tdMigrated.textContent = cat.migrated_wp_count > 0
                    ? '✅ ' + cat.migrated_wp_count + ' / ' + pubTotal
                    : '0 / ' + pubTotal;

                const tdActions = document.createElement('td');
                const btnPreview = document.createElement('button');
                btnPreview.type = 'button';
                btnPreview.className = 'button button-small';
                btnPreview.textContent = '👁️ Статті';
                btnPreview.addEventListener('click', () => {
                    openCategoryModal(cat.cat_id, cat.cat_title);
                });
                tdActions.appendChild(btnPreview);

                tr.appendChild(tdCb);
                tr.appendChild(tdId);
                tr.appendChild(tdSec);
                tr.appendChild(tdCat);
                tr.appendChild(tdCounts);
                tr.appendChild(tdDates);
                tr.appendChild(tdJf);
                tr.appendChild(tdMigrated);
                tr.appendChild(tdActions);

                catsTbody.appendChild(tr);
            });
        }

        function analyzeDatabase() {
            btnAnalyzeDb.disabled = true;
            showDbStatus('Аналіз структури розділів, категорій та статей Joomla...', false);

            postAjax({
                action: 'scat_migrator_analyze_db',
                nonce: config.migratorNonce
            })
                .then((resp) => {
                    btnAnalyzeDb.disabled = false;
                    if (!resp || !resp.success || !resp.data) {
                        const msg = (resp && resp.data && resp.data.message) || 'Не вдалося виконати аналіз БД.';
                        showDbStatus(msg, true);
                        if (resp && resp.data && resp.data.tables) {
                            updateTablesBadges(resp.data.tables);
                        }
                        return;
                    }

                    updateTablesBadges(resp.data.tables);
                    renderJoomlaCategoriesTable(resp.data.categories || []);
                    updateWpCategoriesDropdown(resp.data.wp_categories || []);
                    showDbStatus(
                        'Аналіз завершено! Знайдено груп категорій: ' + (resp.data.categories || []).length,
                        false
                    );
                })
                .catch(() => {
                    btnAnalyzeDb.disabled = false;
                    showDbStatus('Мережева помилка під час аналізу БД.', true);
                });
        }

        btnLoadSql.addEventListener('click', () => {
            if (isImporting) {
                return;
            }
            btnLoadSql.disabled = true;
            btnAnalyzeDb.disabled = true;
            btnDropTables.disabled = true;
            showDbStatus('⏳ Потокове читання SQL-файлу та імпорт 6 таблиць (jos_sections, jos_categories, jos_content, jos_languages, jos_jf_content, jos_docman)... Це може зайняти 10–30 секунд.', false);

            postAjax({
                action: 'scat_migrator_load_sql',
                nonce: config.migratorNonce
            })
                .then((resp) => {
                    btnLoadSql.disabled = false;
                    btnAnalyzeDb.disabled = false;
                    btnDropTables.disabled = false;

                    if (!resp || !resp.success || !resp.data) {
                        const msg = (resp && resp.data && resp.data.message) || 'Помилка завантаження SQL-файлу.';
                        showDbStatus(msg, true);
                        return;
                    }

                    updateTablesBadges(resp.data.tables);
                    showDbStatus(
                        '✅ Таблиці успішно завантажені! Виконано SQL-команд: ' + resp.data.executed_queries,
                        false
                    );
                    analyzeDatabase();
                })
                .catch(() => {
                    btnLoadSql.disabled = false;
                    btnAnalyzeDb.disabled = false;
                    btnDropTables.disabled = false;
                    showDbStatus('Помилка мережі або таймаут під час імпорту SQL.', true);
                });
        });

        btnAnalyzeDb.addEventListener('click', analyzeDatabase);

        btnDropTables.addEventListener('click', () => {
            if (isImporting) {
                return;
            }
            if (!window.confirm('Видалити тимчасові таблиці jos_* (jos_sections, jos_categories, jos_content, jos_languages, jos_jf_content, jos_docman) з бази даних WordPress?')) {
                return;
            }

            btnDropTables.disabled = true;
            postAjax({
                action: 'scat_migrator_drop_tables',
                nonce: config.migratorNonce
            })
                .then((resp) => {
                    btnDropTables.disabled = false;
                    if (resp && resp.success && resp.data) {
                        updateTablesBadges(resp.data.tables);
                        renderJoomlaCategoriesTable([]);
                        showDbStatus('🗑️ Тимчасові таблиці jos_* видалено з бази даних.', false);
                    }
                })
                .catch(() => {
                    btnDropTables.disabled = false;
                    showDbStatus('Не вдалося видалити таблиці.', true);
                });
        });

        function toggleAllCheckboxes(checked) {
            const boxes = catsTbody.querySelectorAll('.scat-joomla-cat-cb');
            boxes.forEach((box) => {
                box.checked = checked;
            });
            if (masterCb) {
                masterCb.checked = checked;
            }
        }

        if (masterCb) {
            masterCb.addEventListener('change', () => {
                toggleAllCheckboxes(masterCb.checked);
            });
        }
        if (btnSelectAll) {
            btnSelectAll.addEventListener('click', () => toggleAllCheckboxes(true));
        }
        if (btnUnselectAll) {
            btnUnselectAll.addEventListener('click', () => toggleAllCheckboxes(false));
        }

        chipButtons.forEach((chip) => {
            chip.addEventListener('click', () => {
                if (inputNewCatUk) {
                    inputNewCatUk.value = chip.getAttribute('data-name-uk') || '';
                }
                if (inputNewCatRu) {
                    inputNewCatRu.value = chip.getAttribute('data-name-ru') || '';
                }
                if (inputNewCatEn) {
                    inputNewCatEn.value = chip.getAttribute('data-name-en') || '';
                }
                pendingSlugs = {
                    uk: chip.getAttribute('data-slug-uk') || '',
                    ru: chip.getAttribute('data-slug-ru') || '',
                    en: chip.getAttribute('data-slug-en') || ''
                };
            });
        });

        [inputNewCatUk, inputNewCatRu, inputNewCatEn].forEach((inp) => {
            if (inp) {
                inp.addEventListener('input', () => {
                    pendingSlugs = { uk: '', ru: '', en: '' };
                });
            }
        });

        btnCreateArchiveCat.addEventListener('click', () => {
            const nameUk = inputNewCatUk ? inputNewCatUk.value.trim() : 'Архів новин';
            const nameRu = inputNewCatRu ? inputNewCatRu.value.trim() : '';
            const nameEn = inputNewCatEn ? inputNewCatEn.value.trim() : '';

            if (nameUk === '') {
                window.alert('Введіть назву рубрики (UK).');
                return;
            }

            btnCreateArchiveCat.disabled = true;

            postAjax({
                action: 'scat_migrator_create_wp_cat',
                nonce: config.migratorNonce,
                name_uk: nameUk,
                name_ru: nameRu,
                name_en: nameEn,
                slug_uk: pendingSlugs.uk,
                slug_ru: pendingSlugs.ru,
                slug_en: pendingSlugs.en
            })
                .then((resp) => {
                    btnCreateArchiveCat.disabled = false;
                    if (!resp || !resp.success || !resp.data) {
                        const msg = (resp && resp.data && resp.data.message) || 'Не вдалося створити рубрики.';
                        showDbStatus(msg, true);
                        return;
                    }

                    updateWpCategoriesDropdown(resp.data.wp_categories || [], resp.data.term_id);
                    showDbStatus(
                        '✅ Зв\'язані рубрики створено та вибрано: ' + (resp.data.summary || ''),
                        false
                    );
                })
                .catch(() => {
                    btnCreateArchiveCat.disabled = false;
                    showDbStatus('Помилка мережі при створенні рубрик.', true);
                });
        });

        function setArticlesProgress(pct, label) {
            const safePct = Math.max(0, Math.min(100, Number(pct) || 0));
            progressWrap.style.display = 'block';
            progressPercent.textContent = safePct + '%';
            progressFill.style.width = safePct + '%';
            if (label) {
                progressLabel.textContent = label;
            }
        }

        function appendLogItemsToContainer(logs, wrapEl, listEl, countEl, counterRef) {
            if (!Array.isArray(logs) || logs.length === 0 || !wrapEl || !listEl || !countEl) {
                return;
            }

            wrapEl.style.display = 'block';
            logs.forEach((item) => {
                counterRef.count++;
                const row = document.createElement('div');
                row.className = 'scat-log-item is-' + (item.status || 'info');

                const textSpan = document.createElement('span');
                textSpan.textContent = '[#' + item.joomla_id + '] ' + item.title + ' — ' + item.message;
                row.appendChild(textSpan);

                if (item.post_id > 0) {
                    const linksBox = document.createElement('span');
                    linksBox.className = 'scat-log-links';

                    if (item.url) {
                        const viewLink = document.createElement('a');
                        viewLink.href = item.url;
                        viewLink.target = '_blank';
                        viewLink.rel = 'noopener noreferrer';
                        viewLink.textContent = '🔗 Переглянути';
                        linksBox.appendChild(viewLink);
                    }

                    if (item.edit_url) {
                        const editLink = document.createElement('a');
                        editLink.href = item.edit_url;
                        editLink.target = '_blank';
                        editLink.rel = 'noopener noreferrer';
                        editLink.textContent = '✏️ Редагувати';
                        linksBox.appendChild(editLink);
                    }

                    row.appendChild(linksBox);
                }

                listEl.prepend(row);
            });

            countEl.textContent = String(counterRef.count);
        }

        function finishArticleImport(message) {
            isImporting = false;
            stopImportRequested = false;
            btnImportArticles.disabled = false;
            btnStopArticles.disabled = true;
            progressLabel.textContent = message;
            analyzeDatabase();
        }

        function runArticlesBatch(selectedCatIds, specificArticleIds, offset, statsAcc, logCounter) {
            if (stopImportRequested) {
                finishArticleImport('Імпорт зупинено користувачем.');
                return;
            }

            const batchSize = Math.max(1, Math.min(100, parseInt(inputBatchSize.value, 10) || 20));
            const excerptLen = Math.max(50, Math.min(1000, parseInt(inputExcerptLen.value, 10) || 270));

            postAjax({
                action: 'scat_migrator_import_articles_batch',
                nonce: config.migratorNonce,
                joomla_cat_ids: JSON.stringify(selectedCatIds),
                joomla_article_ids: JSON.stringify(specificArticleIds),
                target_wp_cat_id: selectWpCat.value || '0',
                post_type: selectPostType.value || 'post',
                target_lang: (inputTargetLang.value || 'uk').trim(),
                jf_mode: selectJfMode ? selectJfMode.value : 'uk_full_plus_existing',
                slug_mode: selectSlugMode.value || 'date',
                excerpt_length: excerptLen,
                set_featured_image: cbFeaturedImage.checked ? '1' : '0',
                strip_first_img_from_body: cbStripFirstImg.checked ? '1' : '0',
                skip_existing: cbSkipExisting.checked ? '1' : '0',
                only_published: cbOnlyPublished.checked ? '1' : '0',
                offset: offset,
                batch_size: batchSize
            })
                .then((resp) => {
                    if (!resp || !resp.success || !resp.data) {
                        const errMsg = (resp && resp.data && resp.data.message) || 'Помилка пакетного імпорту статей.';
                        finishArticleImport('Зупинено через помилку: ' + errMsg);
                        return;
                    }

                    const d = resp.data;
                    statsAcc.imported += Number(d.imported) || 0;
                    statsAcc.skipped += Number(d.skipped) || 0;

                    appendLogItemsToContainer(d.logs || [], logWrap, logListEl, logCountEl, logCounter);

                    const total = Number(d.total) || 0;
                    const nextOffset = Number(d.next_offset) || 0;
                    const pct = Number(d.percent) || 0;

                    setArticlesProgress(
                        pct,
                        'Оброблено статей Joomla: ' + nextOffset + ' із ' + total +
                        ' (створено/оновлено записів WP: ' + statsAcc.imported + ', пропущено: ' + statsAcc.skipped + ')'
                    );

                    if (nextOffset >= total || total === 0) {
                        setArticlesProgress(
                            100,
                            '✅ Готово! Імпортовано записів WP: ' + statsAcc.imported + ', пропущено дублікатів: ' + statsAcc.skipped
                        );
                        finishArticleImport(
                            '✅ Готово! Імпортовано записів WP: ' + statsAcc.imported + ', пропущено дублікатів: ' + statsAcc.skipped
                        );
                        return;
                    }

                    runArticlesBatch(selectedCatIds, specificArticleIds, nextOffset, statsAcc, logCounter);
                })
                .catch(() => {
                    finishArticleImport('Зупинено через мережевий збій на позиції ' + offset);
                });
        }

        btnImportArticles.addEventListener('click', () => {
            if (isImporting) {
                return;
            }

            const checkedBoxes = catsTbody.querySelectorAll('.scat-joomla-cat-cb:checked');
            const selectedCatIds = [];
            checkedBoxes.forEach((box) => {
                selectedCatIds.push(parseInt(box.value, 10));
            });

            if (selectedArticleIds.length === 0 && selectedCatIds.length === 0) {
                window.alert('Будь ласка, позначте галочками категорію Joomla у таблиці 2.2 або виберіть конкретні статті через кнопку «👁️ Статті».');
                return;
            }

            isImporting = true;
            stopImportRequested = false;
            btnImportArticles.disabled = true;
            btnStopArticles.disabled = false;

            const logCounter = { count: 0 };
            logListEl.textContent = '';
            logCountEl.textContent = '0';

            setArticlesProgress(0, 'Запуск пакетного імпорту статей...');
            runArticlesBatch(selectedCatIds, selectedArticleIds.slice(), 0, { imported: 0, skipped: 0 }, logCounter);
        });

        btnStopArticles.addEventListener('click', () => {
            if (!isImporting) {
                return;
            }
            stopImportRequested = true;
            btnStopArticles.disabled = true;
            progressLabel.textContent = 'Зупинка після поточного пакету...';
        });
    }

    /* ==========================================================================
       3. Visual Archive Explorer Module (ArchiveBrowser)
       ========================================================================== */
    function initArchiveBrowser() {
        const wrap = document.querySelector('.scat-archive-wrap');
        if (!wrap) {
            return;
        }

        const tabButtons = wrap.querySelectorAll('.scat-tab-btn');
        const breadcrumbsNav = document.getElementById('scat-archive-breadcrumbs');
        const searchInput = document.getElementById('scat-archive-search');
        const searchClearBtn = document.getElementById('scat-archive-search-clear');
        const statusBox = document.getElementById('scat-archive-status');

        const foldersSection = document.getElementById('scat-archive-folders-section');
        const foldersGrid = document.getElementById('scat-archive-folders');
        const filesHeading = document.getElementById('scat-files-heading');
        const filesCounter = document.getElementById('scat-files-counter');
        const filesGrid = document.getElementById('scat-archive-files');
        const paginationBox = document.getElementById('scat-archive-pagination');
        const toastEl = document.getElementById('scat-toast');

        let currentSource = 'webp';
        let currentPath = '';
        let currentSearch = '';
        let currentPage = 1;
        let searchTimer = null;
        let toastTimer = null;

        function showToast(text) {
            if (!toastEl) {
                return;
            }
            toastEl.textContent = text;
            toastEl.classList.add('is-visible');
            if (toastTimer) {
                clearTimeout(toastTimer);
            }
            toastTimer = setTimeout(() => {
                toastEl.classList.remove('is-visible');
            }, 2200);
        }

        function copyToClipboard(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => {
                    showToast('📋 Скопійовано!');
                });
                return;
            }

            const temp = document.createElement('textarea');
            temp.value = text;
            temp.style.position = 'fixed';
            temp.style.opacity = '0';
            document.body.appendChild(temp);
            temp.select();
            try {
                document.execCommand('copy');
                showToast('📋 Скопійовано!');
            } catch (e) {
                showToast('Не вдалося скопіювати');
            }
            document.body.removeChild(temp);
        }

        function renderBreadcrumbs(crumbs) {
            breadcrumbsNav.textContent = '';

            const rootBtn = document.createElement('button');
            rootBtn.type = 'button';
            rootBtn.className = 'scat-crumb-btn' + (crumbs.length === 0 ? ' is-active' : '');
            rootBtn.textContent = '🏠 Корінь';
            rootBtn.addEventListener('click', () => {
                if (currentPath !== '' || currentSearch !== '') {
                    currentPath = '';
                    currentSearch = '';
                    searchInput.value = '';
                    searchClearBtn.style.display = 'none';
                    currentPage = 1;
                    loadArchive();
                }
            });
            breadcrumbsNav.appendChild(rootBtn);

            crumbs.forEach((crumb, idx) => {
                const sep = document.createElement('span');
                sep.className = 'scat-crumb-sep';
                sep.textContent = '/';
                breadcrumbsNav.appendChild(sep);

                const btn = document.createElement('button');
                btn.type = 'button';
                const isLast = idx === crumbs.length - 1;
                btn.className = 'scat-crumb-btn' + (isLast ? ' is-active' : '');
                btn.textContent = crumb.name;
                btn.addEventListener('click', () => {
                    if (!isLast || currentSearch !== '') {
                        currentPath = crumb.path;
                        currentSearch = '';
                        searchInput.value = '';
                        searchClearBtn.style.display = 'none';
                        currentPage = 1;
                        loadArchive();
                    }
                });
                breadcrumbsNav.appendChild(btn);
            });
        }

        function renderFolders(folders) {
            foldersGrid.textContent = '';
            if (!Array.isArray(folders) || folders.length === 0) {
                foldersSection.style.display = 'none';
                return;
            }

            foldersSection.style.display = 'block';
            folders.forEach((folder) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'scat-folder-card';

                const nameSpan = document.createElement('span');
                nameSpan.className = 'scat-folder-name';
                nameSpan.textContent = '📁 ' + folder.name;
                nameSpan.title = folder.name;

                const countSpan = document.createElement('span');
                countSpan.className = 'scat-folder-count';
                countSpan.textContent = String(folder.item_count || 0);

                btn.appendChild(nameSpan);
                btn.appendChild(countSpan);

                btn.addEventListener('click', () => {
                    currentPath = folder.rel_path;
                    currentPage = 1;
                    loadArchive();
                });

                foldersGrid.appendChild(btn);
            });
        }

        function setImportedButtonState(btn, attachmentId, editUrl, mediaUrl) {
            const link = document.createElement('a');
            link.className = 'button scat-btn-imported';
            link.href = editUrl || mediaUrl || '#';
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = '✅ В Медіатеці (#' + attachmentId + ')';
            btn.replaceWith(link);
        }

        function importFileToMedia(file, btn) {
            btn.disabled = true;
            btn.textContent = '⏳ Імпорт...';

            postAjax({
                action: 'scat_archive_import_to_media',
                nonce: config.archiveNonce,
                source: currentSource,
                rel_path: file.rel_path
            })
                .then((resp) => {
                    if (!resp || !resp.success || !resp.data) {
                        const msg = (resp && resp.data && resp.data.message) || 'Помилка імпорту файлу.';
                        showToast('❌ ' + msg);
                        btn.disabled = false;
                        btn.textContent = '➕ В Медіатеку WP';
                        return;
                    }

                    const d = resp.data;
                    setImportedButtonState(btn, d.attachment_id, d.edit_url, d.url);
                    showToast('✅ Додано в Медіатеку (#' + d.attachment_id + ')');
                })
                .catch(() => {
                    showToast('❌ Мережева помилка під час імпорту.');
                    btn.disabled = false;
                    btn.textContent = '➕ В Медіатеку WP';
                });
        }

        function renderFiles(files, totalFiles) {
            filesGrid.textContent = '';
            filesCounter.textContent = String(totalFiles || 0);
            filesHeading.textContent = currentSearch !== ''
                ? 'Результати пошуку «' + currentSearch + '»'
                : 'Файли';

            if (!Array.isArray(files) || files.length === 0) {
                const empty = document.createElement('p');
                empty.style.color = '#646970';
                empty.textContent = 'У цій папці немає файлів.';
                filesGrid.appendChild(empty);
                return;
            }

            files.forEach((file) => {
                const card = document.createElement('div');
                card.className = 'scat-file-card';

                const preview = document.createElement('div');
                preview.className = 'scat-file-preview';

                if (file.type === 'image') {
                    const img = document.createElement('img');
                    img.src = file.url;
                    img.alt = file.name;
                    img.loading = 'lazy';
                    preview.appendChild(img);
                } else {
                    const docBox = document.createElement('div');
                    docBox.className = 'scat-doc-icon';

                    const emoji = document.createElement('span');
                    emoji.className = 'scat-doc-emoji';
                    emoji.textContent = '📄';

                    const ext = document.createElement('span');
                    ext.className = 'scat-doc-ext';
                    ext.textContent = file.ext || 'DOC';

                    docBox.appendChild(emoji);
                    docBox.appendChild(ext);
                    preview.appendChild(docBox);
                }

                const body = document.createElement('div');
                body.className = 'scat-file-body';

                const title = document.createElement('div');
                title.className = 'scat-file-title';
                title.textContent = file.name;
                title.title = file.rel_path;
                body.appendChild(title);

                if (currentSearch !== '' && file.rel_path !== file.name) {
                    const relInfo = document.createElement('div');
                    relInfo.className = 'scat-file-path';
                    relInfo.textContent = file.rel_path;
                    body.appendChild(relInfo);
                }

                const meta = document.createElement('div');
                meta.className = 'scat-file-meta';

                const sizeSpan = document.createElement('span');
                sizeSpan.textContent = file.size_human;

                const extSpan = document.createElement('span');
                extSpan.textContent = file.ext;

                meta.appendChild(sizeSpan);
                meta.appendChild(extSpan);
                body.appendChild(meta);

                const actions = document.createElement('div');
                actions.className = 'scat-file-actions';

                if (file.imported && file.attachment_id) {
                    const link = document.createElement('a');
                    link.className = 'button scat-btn-imported';
                    link.href = file.edit_url || file.media_url || '#';
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.textContent = '✅ В Медіатеці (#' + file.attachment_id + ')';
                    actions.appendChild(link);
                } else {
                    const importBtn = document.createElement('button');
                    importBtn.type = 'button';
                    importBtn.className = 'button button-primary';
                    importBtn.textContent = '➕ В Медіатеку WP';
                    importBtn.addEventListener('click', () => importFileToMedia(file, importBtn));
                    actions.appendChild(importBtn);
                }

                const copyBtn = document.createElement('button');
                copyBtn.type = 'button';
                copyBtn.className = 'button';
                copyBtn.textContent = '📋 URL';
                copyBtn.addEventListener('click', () => copyToClipboard(file.url));
                actions.appendChild(copyBtn);

                body.appendChild(actions);

                card.appendChild(preview);
                card.appendChild(body);
                filesGrid.appendChild(card);
            });
        }

        function renderPagination(page, totalPages) {
            paginationBox.textContent = '';
            if (totalPages <= 1) {
                return;
            }

            const createPageBtn = (label, targetPage, isActive, isDisabled) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'button scat-page-btn' + (isActive ? ' is-active' : '');
                btn.textContent = label;
                btn.disabled = Boolean(isDisabled);
                if (!isDisabled && !isActive) {
                    btn.addEventListener('click', () => {
                        currentPage = targetPage;
                        loadArchive();
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    });
                }
                return btn;
            };

            paginationBox.appendChild(createPageBtn('« Попередня', page - 1, false, page <= 1));

            for (let p = 1; p <= totalPages; p++) {
                if (
                    p === 1 ||
                    p === totalPages ||
                    (p >= page - 2 && p <= page + 2)
                ) {
                    paginationBox.appendChild(createPageBtn(String(p), p, p === page, false));
                } else if (p === page - 3 || p === page + 3) {
                    const dots = document.createElement('span');
                    dots.textContent = '...';
                    dots.style.padding = '0 4px';
                    paginationBox.appendChild(dots);
                }
            }

            paginationBox.appendChild(createPageBtn('Наступна »', page + 1, false, page >= totalPages));
        }

        function loadArchive() {
            statusBox.style.display = 'none';
            filesGrid.innerHTML = '<p>Завантаження...</p>';

            postAjax({
                action: 'scat_archive_browse',
                nonce: config.archiveNonce,
                source: currentSource,
                path: currentPath,
                search: currentSearch,
                page: currentPage
            })
                .then((resp) => {
                    if (!resp || !resp.success || !resp.data) {
                        const msg = (resp && resp.data && resp.data.message) || 'Не вдалося завантажити вміст папки.';
                        statusBox.textContent = msg;
                        statusBox.style.display = 'block';
                        filesGrid.textContent = '';
                        foldersSection.style.display = 'none';
                        return;
                    }

                    const data = resp.data;
                    if (!data.dir_exists) {
                        statusBox.textContent = currentSource === 'webp'
                            ? 'Папка /wp-content/uploads/scat-content-webp/ ще не створена або порожня. Запустіть конвертацію у розділі «📦 Міграція Joomla -> WP».'
                            : 'Папка /wp-content/uploads/joomla-legacy/dmdocuments/ не знайдена на сервері.';
                        statusBox.style.display = 'block';
                    }

                    renderBreadcrumbs(data.breadcrumbs || []);
                    renderFolders(data.folders || []);
                    renderFiles(data.files || [], data.total_files || 0);
                    renderPagination(data.page || 1, data.total_pages || 1);
                })
                .catch(() => {
                    statusBox.textContent = 'Помилка з\'єднання із сервером.';
                    statusBox.style.display = 'block';
                    filesGrid.textContent = '';
                });
        }

        tabButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const src = btn.getAttribute('data-source') || 'webp';
                if (src === currentSource) {
                    return;
                }
                tabButtons.forEach((b) => b.classList.remove('is-active'));
                btn.classList.add('is-active');

                currentSource = src;
                currentPath = '';
                currentSearch = '';
                currentPage = 1;
                searchInput.value = '';
                searchClearBtn.style.display = 'none';
                loadArchive();
            });
        });

        searchInput.addEventListener('input', () => {
            const val = searchInput.value.trim();
            searchClearBtn.style.display = val !== '' ? 'inline-block' : 'none';

            if (searchTimer) {
                clearTimeout(searchTimer);
            }
            searchTimer = setTimeout(() => {
                currentSearch = val;
                currentPage = 1;
                loadArchive();
            }, 300);
        });

        searchClearBtn.addEventListener('click', () => {
            searchInput.value = '';
            searchClearBtn.style.display = 'none';
            currentSearch = '';
            currentPage = 1;
            loadArchive();
        });

        loadArchive();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initWebpConverter();
        initContentMigrator();
        initArchiveBrowser();
    });
})();
