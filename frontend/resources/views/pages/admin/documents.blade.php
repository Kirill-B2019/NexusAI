<x-app-layout title="Документы RAG · NEXUS AI">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> УПРАВЛЕНИЕ ПРОЕКТАМИ · RAG ДОКУМЕНТЫ</div>
            <h1>Документы <span>{{ $projectName ?? 'проекта' }}</span></h1>
            <p class="welcome-copy">Загрузка, управление и запросы по документам для RAG-контекста. Поддерживаемые форматы: TXT, MD, PDF, DOCX, XLSX, CSV.</p>
        </div>
    </section>

    @if (session('status'))
        @php $status = session('status'); @endphp
        @if ($status === 'document_uploaded')
            <div class="notice notice-success" role="status">Документ «{{ session('document_name') }}» загружен и обрабатывается.</div>
        @elseif ($status === 'document_reindexing')
            <div class="notice notice-success" role="status">Документ отправлен на переиндексацию.</div>
        @elseif ($status === 'document_deleted')
            <div class="notice notice-success" role="status">Документ удалён.</div>
        @endif
    @endif
    @if (isset($errors) && $errors->any())
        <div class="admin-note project-alert-error" role="alert">
            <span aria-hidden="true">!</span>
            <ul>
                @foreach ($errors->all() as $errorItem)
                    <li>{{ $errorItem }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if ($error ?? null)
        <div class="admin-note project-alert-error" role="alert"><span aria-hidden="true">!</span><p>{{ $error }}</p></div>
    @endif

    <section class="panel document-upload-panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">БАЗА ЗНАНИЙ ПРОЕКТА</span>
                <h2>Добавить документ</h2>
                <p class="field-hint">Файл будет проиндексирован и станет доступен для вопросов после обработки.</p>
            </div>
        </div>
        <form class="document-upload-form" method="POST" action="{{ route('admin.documents.upload', ['nexusProjectId' => $nexusProjectId]) }}" enctype="multipart/form-data">
            @csrf
            <div class="field">
                <label for="file">Файл</label>
                <input id="file" name="file" type="file" accept=".txt,.md,.markdown,.log,.pdf,.docx,.doc,.xlsx,.xlsm,.xls,.ods,.csv,.py,.js,.ts,.json,.yaml,.yml,.sh,.sql,.html,.css,.go,.rs,.java,.c,.cpp,.h,.xml" required>
                @error('file') <p class="field-error">{{ $message }}</p> @enderror
                <small class="field-hint">До 50 МБ · TXT, MD, PDF, DOCX, XLSX, CSV и файлы кода</small>
            </div>
            <button class="button button-primary document-upload-submit" type="submit">Загрузить файл</button>
        </form>
    </section>

    @if ($documents === [] && ! $error)
        <section class="panel document-list-panel">
            <header class="document-list-heading">
                <div>
                    <span class="eyebrow">СОДЕРЖИМОЕ ПРОЕКТА</span>
                    <h2>Документы</h2>
                </div>
                <span class="directory-count">0 документов</span>
            </header>
            <div class="empty-state document-empty-state">
                <span class="empty-state-icon" aria-hidden="true">◎</span>
                <strong>Документов пока нет</strong>
                <p>Добавьте файл выше. После индексации по нему можно будет задавать вопросы.</p>
            </div>
        </section>
    @elseif ($documents !== [])
        <section class="panel document-list-panel" aria-labelledby="document-list-title">
            <header class="document-list-heading">
                <div>
                    <span class="eyebrow">СОДЕРЖИМОЕ ПРОЕКТА</span>
                    <h2 id="document-list-title">Документы</h2>
                    <p class="field-hint">Статус обновляется автоматически. Вопросы и анализ доступны после индексации.</p>
                </div>
                <span class="directory-count" data-document-count>{{ count($documents) }} {{ trans_choice('документ|документа|документов', count($documents)) }}</span>
            </header>
            <nav class="document-filters" aria-label="Фильтр документов">
                <button class="button button-primary" type="button" onclick="filterDocuments('all')" data-filter-btn data-filter-all aria-pressed="true">Все <span>{{ count($documents) }}</span></button>
                <button class="button button-secondary" type="button" onclick="filterDocuments('ready')" data-filter-btn data-filter-ready aria-pressed="false">Готовы <span data-status-count="ready">{{ collect($documents)->where('status', 'ready')->count() }}</span></button>
                <button class="button button-secondary" type="button" onclick="filterDocuments('processing')" data-filter-btn data-filter-processing aria-pressed="false">Обработка <span data-status-count="processing">{{ collect($documents)->where('status', 'processing')->count() }}</span></button>
                <button class="button button-secondary" type="button" onclick="filterDocuments('pending')" data-filter-btn data-filter-pending aria-pressed="false">В очереди <span data-status-count="pending">{{ collect($documents)->where('status', 'pending')->count() }}</span></button>
                <button class="button button-secondary" type="button" onclick="filterDocuments('failed')" data-filter-btn data-filter-failed aria-pressed="false">Ошибки <span data-status-count="failed">{{ collect($documents)->where('status', 'failed')->count() }}</span></button>
            </nav>
            <div class="document-analysis-toolbar">
                <p class="field-hint" data-selection-count>Выбрано 0 из 10 документов для анализа</p>
                <button class="button button-primary" type="button" data-analyze-selected disabled>Анализировать выбранные</button>
            </div>
            <div class="table-scroll document-table-scroll" role="region" aria-label="Список документов проекта" tabindex="0">
                <table class="user-table document-table">
                    <thead>
                        <tr>
                            <th scope="col">АНАЛИЗ</th>
                            <th scope="col">ФАЙЛ</th>
                            <th scope="col">РАЗМЕР</th>
                            <th scope="col">СТАТУС</th>
                            <th scope="col">ФРАГМЕНТЫ</th>
                            <th scope="col">ДОБАВЛЕН</th>
                            <th scope="col" aria-label="Действия"></th>
                        </tr>
                    </thead>
                    <tbody id="documentsList">
            @foreach ($documents as $document)
                @php
                    $statusClass = match ($document->status) {
                        'ready' => 'status-ready',
                        'processing' => 'status-processing',
                        'pending' => 'status-pending',
                        'failed' => 'status-failed',
                        default => 'status-pending',
                    };
                    $statusLabel = match ($document->status) {
                        'ready' => 'Готов',
                        'processing' => 'Обработка',
                        'pending' => 'Ожидание',
                        'failed' => 'Ошибка',
                        default => $document->status,
                    };
                @endphp
                <tr class="document-item"
                     data-status="{{ $document->status }}"
                     data-api-status="{{ $document->status }}"
                     data-document-name="{{ $document->name }}"
                     id="document-{{ $document->id }}">
                    <td>
                        <label class="document-select-label">
                            <input class="document-select-checkbox" type="checkbox" value="{{ $document->id }}"
                                   data-document-select
                                   aria-label="Выбрать документ {{ $document->name }} для анализа"
                                   @disabled(! $document->isReady())>
                        </label>
                    </td>
                    <td class="document-name-cell">
                        <strong>{{ $document->name }}</strong>
                        <small>{{ $document->id }}</small>
                        @if ($document->error)
                            <span class="document-row-error" data-document-error>Ошибка: {{ $document->error }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($document->fileSize !== null)
                            @if ($document->fileSize >= 1048576)
                                {{ number_format($document->fileSize / 1048576, 1, ',', ' ') }} МБ
                            @elseif ($document->fileSize >= 1024)
                                {{ number_format($document->fileSize / 1024, 1, ',', ' ') }} КБ
                            @else
                                {{ $document->fileSize }} Б
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        <span class="card-status {{ $statusClass }}" id="status-{{ $document->id }}" aria-live="polite" data-document-status-label="{{ $document->status }}">
                            @if ($document->isProcessing()) <span class="status-pulse"></span> @endif
                            {{ $statusLabel }}
                        </span>
                    </td>
                    <td data-document-chunks>{{ $document->chunkCount ?? '—' }}</td>
                    <td>
                        @if ($document->uploadedAt)
                            <time datetime="{{ $document->uploadedAt }}">{{ \Carbon\Carbon::parse($document->uploadedAt)->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</time>
                        @else
                            —
                        @endif
                    </td>
                    <td class="document-actions-cell">
                        <div class="document-actions">
                            <button class="button button-secondary js-ask-document" type="button"
                                    data-document-id="{{ $document->id }}"
                                    data-document-name="{{ $document->name }}"
                                    title="{{ $document->status === 'ready' ? 'Задать вопрос по документу' : 'Документ станет доступен после обработки' }}"
                                    @disabled(! $document->isReady())>Вопрос</button>
                            <form method="POST" action="{{ route('admin.documents.reindex', ['documentId' => $document->id]) }}" data-document-reindex-form>
                                @csrf
                                <button class="button button-secondary" type="submit" title="Переиндексировать" @disabled($document->isProcessing())>Обновить индекс</button>
                            </form>
                            <form method="POST" action="{{ route('admin.documents.delete', ['documentId' => $document->id]) }}" onsubmit="return confirm('Удалить документ «{{ $document->name }}»? Будут удалены также его фрагменты и векторы.')">
                                @csrf
                                @method('DELETE')
                                <button class="button button-danger" type="submit" title="Удалить документ" aria-label="Удалить {{ $document->name }}">Удалить</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- Модальное окно: работа с конкретным документом --}}
    <dialog id="askDocModal" class="modal-dialog ask-document-dialog" onclick="if(event.target===this)closeAskModal()">
        <div class="modal-content">
            <div class="panel-heading">
                <div>
                    <span class="eyebrow">РАБОТА С ДОКУМЕНТОМ</span>
                    <h2 id="askDocTitle">Вопрос по документу</h2>
                    <p class="field-hint" id="askDocName"></p>
                </div>
                <button class="modal-close" type="button" onclick="closeAskModal()" aria-label="Закрыть">&times;</button>
            </div>
            <form id="askDocForm" class="project-form ask-document-form"
                  data-mode="question"
                  data-stream-url="{{ route('admin.documents.ask-stream', ['nexusProjectId' => $nexusProjectId, 'documentId' => '__DOCUMENT_ID__']) }}"
                  data-analysis-stream-url="{{ route('admin.documents.analyze-stream', ['nexusProjectId' => $nexusProjectId]) }}">
                <div id="askDocResult" class="ask-result" style="display:none">
                    <div class="ask-result-answer"></div>
                    <details class="ask-result-sources">
                        <summary>Источники RAG</summary>
                        <ul class="ask-result-sources-list"></ul>
                    </details>
                </div>
                <div class="field">
                    <label for="askDocQuestion">Ваш вопрос</label>
                    <textarea id="askDocQuestion" name="question" rows="3" maxlength="8000" required placeholder="Введите вопрос…"></textarea>
                    <small class="field-hint">Enter — отправить · Ctrl+Enter — новая строка</small>
                    @error('question') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="askDocExpert">Советник</label>
                    <select id="askDocExpert" name="expert">
                        <option value="">Автоматический выбор</option>
                        @foreach ($experts as $expert)
                            <option value="{{ $expert->key }}">{{ $expert->icon ? $expert->icon.' ' : '' }}{{ $expert->name }}</option>
                        @endforeach
                    </select>
                    @if ($expertCatalogError)
                        <small class="field-hint">Каталог советников временно недоступен. Можно отправить вопрос с автоматическим выбором.</small>
                    @else
                        <small class="field-hint">Поиск выполняется только по этому документу.</small>
                    @endif
                </div>
                <label class="checkbox-label" for="askDocThinking">
                    <input id="askDocThinking" name="thinking" type="checkbox" value="1">
                    <span>Включить расширенное рассуждение</span>
                </label>
                <p id="askDocError" class="field-error" role="alert" hidden></p>
                <div class="modal-actions">
                    <button class="button button-primary" type="submit">Задать вопрос</button>
                    <button class="button button-secondary" type="button" onclick="closeAskModal()">Отмена</button>
                </div>
            </form>
        </div>
    </dialog>
@push('scripts')
<script>
(function() {
    let currentDocId = null;
    let pollingInterval = null;
    let pollingInProgress = false;
    let activeFilter = 'all';
    const maxAnalysisDocuments = 10;

    function updateSelectionControls() {
        const checkboxes = Array.from(document.querySelectorAll('[data-document-select]'));
        const selected = checkboxes.filter(checkbox => checkbox.checked);
        const analyzeButton = document.querySelector('[data-analyze-selected]');
        const countLabel = document.querySelector('[data-selection-count]');

        checkboxes.forEach(checkbox => {
            const row = checkbox.closest('.document-item');
            const isReady = row && row.dataset.apiStatus === 'ready';
            checkbox.disabled = !isReady || (selected.length >= maxAnalysisDocuments && !checkbox.checked);
            checkbox.title = checkbox.disabled && isReady
                ? 'Можно выбрать не более 10 документов'
                : '';
        });

        if (analyzeButton) analyzeButton.disabled = selected.length === 0;
        if (countLabel) {
            countLabel.textContent = 'Выбрано ' + selected.length + ' из ' + maxAnalysisDocuments + ' документов для анализа';
        }
    }

    function applyDocumentFilter(card) {
        card.style.display = activeFilter === 'all' || card.dataset.status === activeFilter ? '' : 'none';
    }

    // Polling статуса документа
    function updateDocumentStatus(doc) {
        const card = document.getElementById('document-' + doc.id);
        const statusEl = document.getElementById('status-' + doc.id);
        if (!card || !statusEl) return;

        const previousStatus = card.dataset.apiStatus;
        const statusChanged = previousStatus !== doc.status;
        card.dataset.apiStatus = doc.status;
        card.dataset.status = doc.status;
        applyDocumentFilter(card);

        const askButton = card.querySelector('.js-ask-document');
        const statusClass = doc.status === 'ready' ? 'status-ready' :
            (doc.status === 'failed' ? 'status-failed' :
            (doc.status === 'processing' ? 'status-processing' : 'status-pending'));
        const statusLabel = doc.status === 'ready' ? 'Готов' :
            (doc.status === 'failed' ? 'Ошибка' :
            (doc.status === 'pending' ? 'Ожидание' : 'Обработка'));

        statusEl.className = 'card-status ' + statusClass;
        statusEl.replaceChildren();
        if (doc.status === 'processing' || doc.status === 'pending') {
            const pulse = document.createElement('span');
            pulse.className = 'status-pulse';
            statusEl.append(pulse);
        }
        statusEl.append(document.createTextNode(statusLabel));

        const chunks = card.querySelector('[data-document-chunks]');
        if (chunks && Number.isInteger(doc.chunks_count)) {
            chunks.textContent = doc.chunks_count;
        }

        if (askButton) {
            askButton.disabled = doc.status !== 'ready';
            askButton.title = doc.status === 'ready'
                ? 'Задать вопрос по документу'
                : 'Документ станет доступен для вопросов после обработки';
        }

        const selectionCheckbox = card.querySelector('[data-document-select]');
        if (selectionCheckbox && doc.status !== 'ready') {
            selectionCheckbox.checked = false;
        }
        updateSelectionControls();

        const reindexButton = card.querySelector('[data-document-reindex-form] button');
        if (reindexButton) {
            reindexButton.disabled = doc.status === 'pending' || doc.status === 'processing';
            reindexButton.title = reindexButton.disabled
                ? 'Дождитесь завершения обработки документа'
                : 'Переиндексировать';
        }

        const errorMessage = card.querySelector('[data-document-error]');
        if (doc.status === 'failed' && doc.error) {
            if (errorMessage) {
                errorMessage.textContent = 'Ошибка: ' + doc.error;
            } else {
                const message = document.createElement('span');
                message.className = 'document-row-error';
                message.dataset.documentError = '';
                message.textContent = 'Ошибка: ' + doc.error;
                card.querySelector('.document-name-cell')?.append(message);
            }
        } else {
            errorMessage?.remove();
        }

        if (statusChanged) {
            document.querySelectorAll('[data-status-count]').forEach(counter => {
                const status = counter.dataset.statusCount;
                const count = document.querySelectorAll('.document-item[data-status="' + status + '"]').length;
                counter.textContent = count;
            });
        }

        if (statusChanged && (doc.status === 'ready' || doc.status === 'failed')) {
            if (doc.status === 'ready') {
                showNotification('✅ Документ «' + card.dataset.documentName + '» готов к использованию!', 'success');
            } else {
                showNotification('❌ Ошибка обработки документа: ' + (doc.error || 'Неизвестная ошибка'), 'error');
            }
        }
    }

    async function pollDocumentStatuses() {
        if (pollingInProgress) return;

        const processingCards = document.querySelectorAll(
            '.document-item[data-api-status="processing"], .document-item[data-api-status="pending"]'
        );
        if (processingCards.length === 0) {
            clearInterval(pollingInterval);
            pollingInterval = null;
            return;
        }

        pollingInProgress = true;
        try {
            await Promise.all(Array.from(processingCards, async card => {
                const docId = card.id.replace('document-', '');
                const url = '{{ route("admin.documents.status", ["documentId" => "__DOCUMENT_ID__"]) }}'
                    .replace('__DOCUMENT_ID__', encodeURIComponent(docId));
                try {
                    const response = await fetch(url, {
                        method: 'GET',
                        cache: 'no-store',
                        headers: { 'Accept': 'application/json' },
                    });

                    if (!response.ok) {
                        console.error('Document status request failed.', {
                            documentId: docId,
                            status: response.status,
                        });
                        return;
                    }

                    updateDocumentStatus(await response.json());
                } catch (error) {
                    console.error('Document status request failed.', { documentId: docId, error });
                }
            }));
        } finally {
            pollingInProgress = false;
            if (document.querySelectorAll(
                '.document-item[data-api-status="processing"], .document-item[data-api-status="pending"]'
            ).length === 0) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
        }
    }

    window.startPolling = function() {
        if (pollingInterval !== null) return;
        pollDocumentStatuses();
        pollingInterval = setInterval(pollDocumentStatuses, 5000);
    };

    // Уведомления
    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = 'notice notice-' + (type === 'success' ? 'success' : 'project-alert-error');
        notification.textContent = message;

        const container = document.querySelector('.page-content');
        if (container) {
            container.insertBefore(notification, container.firstChild);
            setTimeout(() => notification.remove(), 5000);
        }
    }

    // Фильтрация документов по статусу
    window.filterDocuments = function(filter) {
        const items = document.querySelectorAll('.document-item');
        const buttons = document.querySelectorAll('[data-filter-btn]');
        activeFilter = filter;

        const activeBtn = document.querySelector('[data-filter-' + filter + ']');
        buttons.forEach(button => {
            const isActive = button === activeBtn;
            button.classList.toggle('button-primary', isActive);
            button.classList.toggle('button-secondary', !isActive);
            button.setAttribute('aria-pressed', String(isActive));
        });

        // Фильтруем элементы
        items.forEach(item => {
            applyDocumentFilter(item);
        });
    };

    // Автостарт polling для ожидающих и обрабатываемых документов
    if (document.querySelector(
        '.document-item[data-api-status="processing"], .document-item[data-api-status="pending"]'
    )) startPolling();

    document.querySelectorAll('.js-ask-document').forEach(button => {
        button.addEventListener('click', () => {
            openAskModal(button.dataset.documentId, button.dataset.documentName);
        });
    });

    document.querySelectorAll('[data-document-select]').forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectionControls);
    });
    document.querySelector('[data-analyze-selected]')?.addEventListener('click', () => window.openAnalyzeModal());
    updateSelectionControls();

    window.openAskModal = function(docId, docName) {
        currentDocId = docId;
        document.getElementById('askDocTitle').textContent = 'Вопрос по документу';
        document.getElementById('askDocName').textContent = docName;
        const form = document.getElementById('askDocForm');
        form.reset();
        form.dataset.mode = 'question';
        form.dataset.documentId = docId;
        delete form.dataset.documentIds;
        document.getElementById('askDocQuestion').placeholder = 'Например: кратко изложи основные выводы документа';
        form.querySelector('button[type="submit"]').textContent = 'Задать вопрос';
        document.getElementById('askDocResult').style.display = 'none';
        document.getElementById('askDocError').hidden = true;
        const modal = document.getElementById('askDocModal');
        if (modal) modal.showModal();
    };

    window.openAnalyzeModal = function() {
        const selected = Array.from(document.querySelectorAll('[data-document-select]:checked'));
        if (selected.length === 0 || selected.length > maxAnalysisDocuments) return;

        const names = selected.map(checkbox => checkbox.closest('.document-item')?.dataset.documentName || 'Документ');
        const form = document.getElementById('askDocForm');
        currentDocId = null;
        form.reset();
        form.dataset.mode = 'analysis';
        form.dataset.documentIds = JSON.stringify(selected.map(checkbox => checkbox.value));
        delete form.dataset.documentId;
        document.getElementById('askDocTitle').textContent = 'Анализ документов';
        document.getElementById('askDocName').textContent = names.join(' · ');
        document.getElementById('askDocQuestion').placeholder = 'Например: сравни основные выводы и выдели общие темы';
        form.querySelector('button[type="submit"]').textContent = 'Начать анализ';
        document.getElementById('askDocResult').style.display = 'none';
        document.getElementById('askDocError').hidden = true;
        const modal = document.getElementById('askDocModal');
        if (modal) modal.showModal();
    };

    window.closeAskModal = function() {
        const modal = document.getElementById('askDocModal');
        if (modal) modal.close();
        currentDocId = null;
    };

})();
</script>
@endpush
</x-app-layout>
