type ExpertInfo = {
    name: string;
    icon: string;
    color: string;
};

type SsePayload = Record<string, unknown>;

const root = document.querySelector<HTMLElement>('[data-chat]');
const form = root?.querySelector<HTMLFormElement>('[data-chat-form]');
const output = root?.querySelector<HTMLElement>('[data-chat-output]');
const status = root?.querySelector<HTMLElement>('[data-chat-status]');
const submitButton = root?.querySelector<HTMLButtonElement>('[data-chat-submit]');
const cancelButton = root?.querySelector<HTMLButtonElement>('[data-chat-cancel]');
const clearButton = root?.querySelector<HTMLButtonElement>('[data-chat-clear]');
const projectSelect = root?.querySelector<HTMLSelectElement>('[data-chat-project]');
const modeSelect = root?.querySelector<HTMLSelectElement>('[data-expert-mode-select]');
const singleExpertBlock = root?.querySelector<HTMLElement>('[data-single-expert-block]');
const manualExpertBlock = root?.querySelector<HTMLElement>('[data-manual-expert-block]');
const thinkingCheckbox = root?.querySelector<HTMLInputElement>('[data-chat-thinking]');
const ragCheckbox = root?.querySelector<HTMLInputElement>('[data-chat-rag]');
const messageInput = root?.querySelector<HTMLTextAreaElement>('[name="message"]');

if (
    root && form && output && status && submitButton && cancelButton &&
    clearButton && projectSelect && modeSelect && singleExpertBlock &&
    manualExpertBlock && messageInput
) {
    let controller: AbortController | undefined;
    let currentExpertBlock: HTMLDivElement | null = null;
    let terminalEventReceived = false;
    let failedExperts = false;

    const setStatus = (message: string): void => {
        status.textContent = message;
    };

    const scrollToLatest = (): void => {
        output.scrollTop = output.scrollHeight;
    };

    const appendMessage = (className: string, text: string): HTMLDivElement => {
        const element = document.createElement('div');
        element.className = className;
        element.textContent = text;
        output.appendChild(element);
        scrollToLatest();
        return element;
    };

    const addError = (message: string): void => {
        appendMessage('error', `⚠ ${message}`);
    };

    const getExpertInfo = (key: string): ExpertInfo => {
        const controls = [
            ...Array.from(root.querySelectorAll<HTMLOptionElement>('[data-single-expert-select] option')),
            ...Array.from(root.querySelectorAll<HTMLInputElement>('[data-experts-list] input')),
        ];
        const control = controls.find((candidate) => candidate.value === key);
        const requestedColor = control?.dataset.expertColor;

        return {
            name: control?.dataset.expertName ?? key,
            icon: control?.dataset.expertIcon ?? '✳',
            color: requestedColor && CSS.supports('color', requestedColor) ? requestedColor : 'var(--lime)',
        };
    };

    const updateMode = (): void => {
        singleExpertBlock.hidden = modeSelect.value !== 'single';
        manualExpertBlock.hidden = modeSelect.value !== 'manual';
    };

    const startExpert = (key: string): HTMLDivElement => {
        const expert = getExpertInfo(key);
        const block = document.createElement('div');
        block.className = 'msg expert expert-block';
        block.dataset.expert = key;
        block.style.borderLeftColor = expert.color;

        const name = document.createElement('div');
        name.className = 'expert-name expert-block-name';
        name.style.color = expert.color;
        name.textContent = `${expert.icon} ${expert.name}`;
        block.appendChild(name);

        const content = document.createElement('div');
        content.className = 'expert-content expert-block-content';
        block.appendChild(content);

        const copyButton = document.createElement('button');
        copyButton.className = 'button button-secondary answer-copy-button';
        copyButton.type = 'button';
        copyButton.textContent = 'Копировать ответ';
        copyButton.disabled = true;
        copyButton.setAttribute('aria-label', `Скопировать ответ: ${expert.name}`);
        copyButton.addEventListener('click', async () => {
            const answer = content.textContent?.trim() ?? '';
            if (!answer) {
                setStatus('Пока нет текста ответа для копирования.');
                return;
            }

            try {
                await navigator.clipboard.writeText(answer);
                copyButton.textContent = 'Скопировано';
                setStatus('Ответ скопирован в буфер обмена.');
                window.setTimeout(() => {
                    copyButton.textContent = 'Копировать ответ';
                }, 1800);
            } catch {
                setStatus('Не удалось скопировать ответ. Проверьте разрешение браузера на доступ к буферу обмена.');
            }
        });
        block.appendChild(copyButton);

        const reasoning = document.createElement('details');
        reasoning.className = 'expert-reasoning';
        reasoning.hidden = true;
        const summary = document.createElement('summary');
        summary.textContent = '💭 Размышления';
        const reasoningText = document.createElement('pre');
        reasoning.append(summary, reasoningText);
        block.appendChild(reasoning);

        output.appendChild(block);
        currentExpertBlock = block;
        scrollToLatest();
        return block;
    };

    const getExpertBlock = (key: string): HTMLDivElement => {
        if (!currentExpertBlock || currentExpertBlock.dataset.expert !== key) {
            return startExpert(key);
        }
        return currentExpertBlock;
    };

    const addSources = (sources: unknown): void => {
        if (!Array.isArray(sources) || sources.length === 0) {
            return;
        }

        const container = document.createElement('div');
        container.className = 'sources';
        const title = document.createElement('strong');
        title.textContent = 'Источники:';
        container.appendChild(title);

        for (const source of sources) {
            if (typeof source !== 'object' || source === null || Array.isArray(source)) {
                continue;
            }

            const item = source as Record<string, unknown>;
            const name = typeof item.document_name === 'string'
                ? item.document_name
                : typeof item.document_id === 'string'
                    ? item.document_id.slice(0, 8)
                    : 'документ';
            const details = [
                typeof item.index === 'number' ? `[${item.index}]` : '',
                name,
                typeof item.chunk_index === 'number' ? `фрагмент ${item.chunk_index}` : '',
                typeof item.score === 'number' ? `оценка ${item.score}` : '',
            ].filter(Boolean).join(' — ');

            const line = document.createElement('div');
            line.textContent = details;
            container.appendChild(line);
        }

        if (container.childElementCount > 1) {
            output.appendChild(container);
            scrollToLatest();
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

        let payload: SsePayload;
        try {
            const parsed: unknown = JSON.parse(dataLines.join('\n'));
            if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
                throw new Error('Invalid event payload');
            }
            payload = parsed as SsePayload;
        } catch {
            addError('Получено некорректное событие от NEXUS AI.');
            setStatus('Сервер прислал событие в неподдерживаемом формате.');
            return;
        }

        if (eventName === 'start') {
            const experts = Array.isArray(payload.experts_used)
                ? payload.experts_used.filter((expert): expert is string => typeof expert === 'string')
                : [];
            const mode = typeof payload.mode === 'string' ? payload.mode : 'auto';
            appendMessage(
                'msg meta',
                `Режим: ${mode} · Эксперты: ${experts.length > 0 ? experts.join(', ') : '—'} · RAG: ${payload.rag_used === true ? 'да' : 'нет'}`,
            );
            addSources(payload.sources);
            setStatus('NEXUS AI формирует ответ…');
        } else if (eventName === 'expert_start' && typeof payload.expert === 'string') {
            startExpert(payload.expert);
            setStatus(`Отвечает эксперт: ${getExpertInfo(payload.expert).name}`);
        } else if (eventName === 'token' && typeof payload.delta === 'string') {
            const expertKey = typeof payload.expert === 'string' ? payload.expert : 'nexus-ai';
            const block = getExpertBlock(expertKey);
            const content = block.querySelector<HTMLElement>('.expert-block-content');
            if (content) {
                content.textContent += payload.delta;
                const copyButton = block.querySelector<HTMLButtonElement>('.answer-copy-button');
                if (copyButton) {
                    copyButton.disabled = content.textContent.trim() === '';
                }
                scrollToLatest();
            }
        } else if (eventName === 'reasoning' && typeof payload.delta === 'string') {
            const expertKey = typeof payload.expert === 'string' ? payload.expert : 'nexus-ai';
            const block = getExpertBlock(expertKey);
            const details = block.querySelector<HTMLDetailsElement>('.expert-reasoning');
            const reasoning = details?.querySelector('pre');
            if (details && reasoning) {
                details.hidden = false;
                reasoning.textContent += payload.delta;
                scrollToLatest();
            }
        } else if (eventName === 'expert_done') {
            currentExpertBlock = null;
        } else if (eventName === 'expert_error') {
            failedExperts = true;
            const expert = typeof payload.expert === 'string' ? payload.expert : 'Эксперт';
            const error = typeof payload.error === 'string' ? payload.error : 'ошибка выполнения';
            addError(`${expert}: ${error}`);
            setStatus('Один из экспертов не ответил; NEXUS AI продолжает обработку.');
        } else if (eventName === 'done') {
            terminalEventReceived = true;
            const summary = payload.summary;
            if (
                typeof summary === 'object' && summary !== null && !Array.isArray(summary) &&
                typeof (summary as Record<string, unknown>).ok === 'number' &&
                typeof (summary as Record<string, unknown>).total === 'number'
            ) {
                const result = summary as { ok: number; total: number };
                setStatus(
                    `Ответ завершён. Экспертов ответило: ${result.ok} из ${result.total}${failedExperts ? ' (есть ошибки)' : ''}.`,
                );
            } else {
                setStatus(`Ответ завершён${failedExperts ? ' с ошибками экспертов' : ''}.`);
            }
        } else if (eventName === 'error') {
            terminalEventReceived = true;
            const message = typeof payload.error === 'string' ? payload.error : 'Неизвестная ошибка NEXUS AI.';
            addError(message);
            setStatus('NEXUS AI не смог завершить запрос.');
        }
    };

    modeSelect.addEventListener('change', updateMode);
    updateMode();

    root.querySelector('[data-experts-list]')?.addEventListener('change', (event: Event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.checked) {
            return;
        }

        const selected = root.querySelectorAll<HTMLInputElement>('[data-experts-list] input:checked');
        if (selected.length > 4) {
            input.checked = false;
            setStatus('Можно выбрать не более четырёх экспертов.');
        }
    });

    clearButton.addEventListener('click', () => {
        controller?.abort();
        output.replaceChildren();
        output.hidden = true;
        currentExpertBlock = null;
        setStatus('История чата очищена.');
    });

    cancelButton.addEventListener('click', () => controller?.abort());

    messageInput.addEventListener('keydown', (event: KeyboardEvent) => {
        if (event.key !== 'Enter' || event.isComposing) {
            return;
        }

        if (event.ctrlKey) {
            event.preventDefault();
            const { selectionStart, selectionEnd, value, maxLength } = messageInput;
            if (maxLength < 0 || value.length - (selectionEnd - selectionStart) < maxLength) {
                messageInput.setRangeText('\n', selectionStart, selectionEnd, 'end');
                messageInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        } else if (!event.metaKey && !event.altKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    form.addEventListener('submit', async (event: SubmitEvent) => {
        event.preventDefault();
        if (controller) {
            return;
        }

        const message = messageInput.value.trim();
        const projectId = Number(projectSelect.value);
        const csrfToken = form.querySelector<HTMLInputElement>('input[name="_token"]')?.value;
        const expertMode = modeSelect.value;

        if (!message || !Number.isInteger(projectId) || projectId < 1 || !csrfToken) {
            setStatus('Укажите проект и текст сообщения.');
            return;
        }

        if (!['auto', 'single', 'manual'].includes(expertMode)) {
            setStatus('Выберите корректный режим экспертов.');
            return;
        }

        const body: Record<string, unknown> = {
            project_id: projectId,
            message,
            expert_mode: expertMode,
            use_rag: ragCheckbox?.checked ?? true,
            thinking: thinkingCheckbox?.checked ?? false,
        };

        if (expertMode === 'single') {
            const expert = root.querySelector<HTMLSelectElement>('[data-single-expert-select]')?.value;
            if (!expert) {
                setStatus('Выберите эксперта.');
                return;
            }
            body.expert = expert;
        } else if (expertMode === 'manual') {
            const experts = Array.from(new Set(Array.from(
                root.querySelectorAll<HTMLInputElement>('[data-experts-list] input:checked'),
            ).map((input) => input.value)));
            if (experts.length === 0) {
                setStatus('Выберите хотя бы одного эксперта.');
                return;
            }
            if (experts.length > 4) {
                setStatus('Можно выбрать не более четырёх экспертов.');
                return;
            }
            body.experts = experts;
        }

        output.hidden = false;
        appendMessage('msg user', message);
        messageInput.value = '';
        currentExpertBlock = null;

        controller = new AbortController();
        terminalEventReceived = false;
        failedExperts = false;
        submitButton.disabled = true;
        cancelButton.hidden = false;
        status.classList.add('active');
        setStatus('Подключаемся к NEXUS AI…');

        try {
            const response = await fetch(root.dataset.streamUrl ?? '', {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    Accept: 'text/event-stream',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            });

            if (response.redirected) {
                const redirectUrl = new URL(response.url, window.location.href);
                if (redirectUrl.origin === window.location.origin) {
                    window.location.assign(redirectUrl.href);
                    return;
                }
                throw new Error('Сеанс завершён. Войдите снова и повторите запрос.');
            }

            if (!response.ok) {
                if (response.status === 401 || response.status === 419) {
                    window.location.assign(root.dataset.homeUrl ?? '/');
                    return;
                }
                const error = await response.json().catch(() => null) as { message?: string } | null;
                throw new Error(error?.message ?? 'Не удалось начать запрос к NEXUS AI.');
            }

            if (!response.headers.get('Content-Type')?.toLowerCase().startsWith('text/event-stream')) {
                throw new Error('Сервер вернул ответ в неподдерживаемом формате.');
            }
            if (!response.body) {
                throw new Error('Браузер не поддерживает чтение потокового ответа.');
            }

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

            if (!terminalEventReceived && !controller.signal.aborted) {
                setStatus('Поток прерван до завершения ответа. Повторите запрос.');
            }
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                setStatus('Ответ остановлен.');
            } else {
                const message = error instanceof Error
                    ? error.message
                    : 'Не удалось связаться с NEXUS AI. Попробуйте ещё раз.';
                addError(message);
                setStatus(message);
            }
        } finally {
            controller = undefined;
            submitButton.disabled = false;
            cancelButton.hidden = true;
            status.classList.remove('active');
        }
    });
}
