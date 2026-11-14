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
       2. Visual Archive Explorer Module (ArchiveBrowser)
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
        initArchiveBrowser();
    });
})();
