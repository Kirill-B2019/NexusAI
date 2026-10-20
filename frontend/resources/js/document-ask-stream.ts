type StreamPayload = Record<string, unknown>;

const form = document.querySelector<HTMLFormElement>('#askDocForm');
const modal = document.querySelector<HTMLDialogElement>('#askDocModal');
const result = document.querySelector<HTMLElement>('#askDocResult');
const errorMessage = document.querySelector<HTMLElement>('#askDocError');

if (form && modal && result && errorMessage) {
    const submitButton = form.querySelector<HTMLButtonElement>('button[type="submit"]');
    const questionInput = form.querySelector<HTMLTextAreaElement>('[name="question"]');
    const expertSelect = form.querySelector<HTMLSelectElement>('[name="expert"]');
    const thinkingInput = form.querySelector<HTMLInputElement>('[name="thinking"]');
    const answerContainer = result.querySelector<HTMLElement>('.ask-result-answer');
    const sourcesDetails = result.querySelector<HTMLDetailsElement>('.ask-result-sources');
    const sourcesList = result.querySelector<HTMLUListElement>('.ask-result-sources-list');
    let controller: AbortController | undefined;
    let terminalEventReceived = false;
    let ragContextFound = true;
    const noContextMessage = (): string => form.dataset.mode === 'analysis'
        ? 'В выбранных документах не найдено релевантных фрагментов.'
        : 'В документе не найдено релевантных фрагментов.';

    if (
        submitButton && questionInput && expertSelect && thinkingInput &&
        answerContainer && sourcesDetails && sourcesList
    ) {
        const showError = (message: string): void => {
            errorMessage.textContent = message;
            errorMessage.hidden = false;
        };

        const renderSource = (value: unknown, index: number): void => {
            if (typeof value !== 'object' || value === null || Array.isArray(value)) {
                return;
            }

            const source = value as StreamPayload;
            const item = document.createElement('li');
            const parts = [
                typeof source.index === 'number' ? `[${source.index}]` : '',
                typeof source.document_name === 'string' ? source.document_name : 'Документ',
                typeof source.chunk_index === 'number' ? `фрагмент ${source.chunk_index + 1}` : '',
                typeof source.score === 'number' ? `релевантность ${source.score.toFixed(3)}` : '',
            ].filter(Boolean);

            item.textContent = parts.length > 1 ? parts.join(' · ') : `Источник ${index + 1}`;
            sourcesList.append(item);
        };

        const createExpertOutput = (key: string): HTMLElement => {
            const section = document.createElement('section');
            section.className = 'document-stream-expert';
            section.dataset.expert = key;

            const heading = document.createElement('h3');
            const selectedOption = Array.from(expertSelect.options).find(option => option.value === key);
            heading.textContent = selectedOption?.textContent?.trim() || key;

            const content = document.createElement('p');
            content.className = 'document-stream-content';

            const copyButton = document.createElement('button');
            copyButton.className = 'button button-secondary answer-copy-button';
            copyButton.type = 'button';
            copyButton.textContent = 'Копировать ответ';
            copyButton.disabled = true;
            copyButton.setAttribute('aria-label', `Скопировать ответ: ${heading.textContent}`);
            copyButton.addEventListener('click', async () => {
                const answer = content.textContent?.trim() ?? '';
                if (!answer) {
                    showError('Пока нет текста ответа для копирования.');
                    return;
                }

                try {
                    await navigator.clipboard.writeText(answer);
                    copyButton.textContent = 'Скопировано';
                    window.setTimeout(() => {
                        copyButton.textContent = 'Копировать ответ';
                    }, 1800);
                } catch {
                    showError('Не удалось скопировать ответ. Проверьте разрешение браузера на доступ к буферу обмена.');
                }
            });
            section.append(heading, content, copyButton);
            answerContainer.append(section);

            return section;
        };

        const getExpertOutput = (key: string): HTMLElement => {
            const existing = answerContainer.querySelector<HTMLElement>(
                `[data-expert="${CSS.escape(key)}"]`,
            );
            return existing ?? createExpertOutput(key);
        };

        const scrollResultToLatest = (): void => {
            result.scrollTop = result.scrollHeight;
        };

        const addReasoning = (key: string, delta: string): void => {
            const section = getExpertOutput(key);
            let details = section.querySelector<HTMLDetailsElement>('.document-stream-reasoning');

            if (!details) {
                details = document.createElement('details');
                details.className = 'document-stream-reasoning';
                const summary = document.createElement('summary');
                summary.textContent = 'Показать ход рассуждений';
                const content = document.createElement('pre');
                details.append(summary, content);
                section.append(details);
            }

            const reasoning = details.querySelector('pre');
            if (reasoning) {
                reasoning.textContent += delta;
            }
        };

        const handleEvent = (frame: string): void => {
            let eventName = 'message';
            const dataLines: string[] = [];

            for (const line of frame.split('\n')) {
                if (line.startsWith(':')) {
                    continue;
                }

                const separator = line.indexOf(':');
                const field = separator === -1 ? line : line.slice(0, separator);
                const value = separator === -1 ? '' : line.slice(separator + 1).replace(/^ /, '');

                if (field === 'event') {
                    eventName = value;
                } else if (field === 'data') {
                    dataLines.push(value);
                }
            }

            if (dataLines.length === 0) {
                return;
            }

            let payload: StreamPayload;
            try {
                const parsed: unknown = JSON.parse(dataLines.join('\n'));
                if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
                    throw new Error('Invalid event payload');
                }
                payload = parsed as StreamPayload;
            } catch {
                showError('NEXUS AI прислал событие в неподдерживаемом формате.');
                terminalEventReceived = true;
                return;
            }

            if (eventName === 'start') {
                ragContextFound = payload.rag_used === true;
                const sources = Array.isArray(payload.sources) ? payload.sources : [];
                sources.forEach(renderSource);
                sourcesDetails.hidden = sourcesList.childElementCount === 0;
                answerContainer.textContent = ragContextFound
                    ? ''
                    : `${noContextMessage()} Ответ не будет основан на тексте документа.`;
            } else if (eventName === 'token' && typeof payload.delta === 'string') {
                if (!ragContextFound) {
                    return;
                }
                const key = typeof payload.expert === 'string' ? payload.expert : 'NEXUS AI';
                const section = getExpertOutput(key);
                const content = section.querySelector<HTMLElement>('.document-stream-content');
                if (content) {
                    content.textContent += payload.delta;
                    const copyButton = section.querySelector<HTMLButtonElement>('.answer-copy-button');
                    if (copyButton) {
                        copyButton.disabled = content.textContent.trim() === '';
                    }
                }
            } else if (eventName === 'reasoning' && typeof payload.delta === 'string') {
                const key = typeof payload.expert === 'string' ? payload.expert : 'NEXUS AI';
                addReasoning(key, payload.delta);
            } else if (eventName === 'expert_error') {
                const expert = typeof payload.expert === 'string' ? payload.expert : 'Советник';
                const detail = typeof payload.error === 'string' ? payload.error : 'ошибка выполнения';
                showError(`${expert}: ${detail}`);
            } else if (eventName === 'no_context') {
                ragContextFound = false;
                answerContainer.textContent = `${noContextMessage()} Уточните вопрос или попробуйте позже.`;
            } else if (eventName === 'error') {
                terminalEventReceived = true;
                showError(typeof payload.error === 'string' ? payload.error : 'NEXUS AI не смог ответить.');
            } else if (eventName === 'done') {
                terminalEventReceived = true;
                if (!ragContextFound) {
                    answerContainer.textContent = `${noContextMessage()} Ответ по содержимому документов не сформирован.`;
                }
            }
            scrollResultToLatest();
        };

        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (controller) {
                return;
            }

            const question = questionInput.value.trim();
            const analysisMode = form.dataset.mode === 'analysis';
            const documentId = form.dataset.documentId;
            const streamUrl = analysisMode ? form.dataset.analysisStreamUrl : form.dataset.streamUrl;
            const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
            let documentIds: string[] = [];

            if (analysisMode) {
                try {
                    const parsed: unknown = JSON.parse(form.dataset.documentIds ?? '');
                    if (Array.isArray(parsed) && parsed.every(id => typeof id === 'string')) {
                        documentIds = parsed;
                    }
                } catch {
                    documentIds = [];
                }
            }

            if (
                !question || !streamUrl || !csrfToken ||
                (analysisMode && (documentIds.length === 0 || documentIds.length > 10)) ||
                (!analysisMode && !documentId)
            ) {
                showError('Не удалось подготовить запрос. Закройте окно и откройте его снова.');
                return;
            }

            submitButton.disabled = true;
            submitButton.textContent = 'Подключаем поток…';
            errorMessage.hidden = true;
            answerContainer.textContent = analysisMode
                ? 'Ищем фрагменты выбранных документов и подключаем поток ответа…'
                : 'Ищем фрагменты документа и подключаем поток ответа…';
            scrollResultToLatest();
            sourcesList.replaceChildren();
            sourcesDetails.hidden = true;
            result.style.display = 'block';
            terminalEventReceived = false;
            ragContextFound = true;

            const requestController = new AbortController();
            controller = requestController;
            try {
                const url = analysisMode
                    ? streamUrl
                    : streamUrl.replace('__DOCUMENT_ID__', encodeURIComponent(documentId ?? ''));
                const body: Record<string, unknown> = {
                    question,
                    expert: expertSelect.value || null,
                    thinking: thinkingInput.checked,
                    rag_top_k: 6,
                    rag_min_score: 0.4,
                };
                if (analysisMode) {
                    body.document_ids = documentIds;
                }
                const response = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: requestController.signal,
                    headers: {
                        Accept: 'text/event-stream',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(body),
                });

                if (response.status === 401 || response.status === 419) {
                    window.location.assign('/');
                    return;
                }
                if (!response.ok) {
                    const error = await response.json().catch(() => null) as { message?: string } | null;
                    throw new Error(error?.message ?? 'Не удалось начать потоковый запрос.');
                }
                if (!response.headers.get('Content-Type')?.toLowerCase().startsWith('text/event-stream')) {
                    throw new Error('Сервер вернул ответ не в формате SSE.');
                }
                if (!response.body) {
                    throw new Error('Браузер не поддерживает чтение потокового ответа.');
                }

                submitButton.textContent = 'Получаем ответ…';
                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    buffer += decoder.decode(value, { stream: !done }).replace(/\r\n/g, '\n').replace(/\r/g, '\n');

                    let delimiter = buffer.indexOf('\n\n');
                    while (delimiter !== -1) {
                        handleEvent(buffer.slice(0, delimiter));
                        buffer = buffer.slice(delimiter + 2);
                        delimiter = buffer.indexOf('\n\n');
                    }

                    if (done) {
                        if (buffer.trim() !== '') {
                            handleEvent(buffer);
                        }
                        break;
                    }
                }

                if (!terminalEventReceived && !requestController.signal.aborted) {
                    showError('Поток завершился до получения полного ответа. Повторите запрос.');
                }
            } catch (error) {
                if (!(error instanceof DOMException && error.name === 'AbortError')) {
                    showError(error instanceof Error ? error.message : 'Не удалось подключиться к NEXUS AI.');
                }
            } finally {
                if (controller === requestController) {
                    controller = undefined;
                }
                submitButton.disabled = false;
                submitButton.textContent = form.dataset.mode === 'analysis' ? 'Начать анализ' : 'Задать вопрос';
            }
        });

        modal.addEventListener('close', () => controller?.abort());

        questionInput.addEventListener('keydown', (event: KeyboardEvent) => {
            if (event.key !== 'Enter' || event.isComposing) {
                return;
            }

            if (event.ctrlKey) {
                event.preventDefault();
                const { selectionStart, selectionEnd, value, maxLength } = questionInput;
                if (maxLength < 0 || value.length - (selectionEnd - selectionStart) < maxLength) {
                    questionInput.setRangeText('\n', selectionStart, selectionEnd, 'end');
                    questionInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
            } else if (!event.metaKey && !event.altKey) {
                event.preventDefault();
                form.requestSubmit();
            }
        });
    }
}
