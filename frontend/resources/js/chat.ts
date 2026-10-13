type ChatEvent = {
    event: string;
    data: string;
};

const root = document.querySelector<HTMLElement>('[data-chat]');
const form = root?.querySelector<HTMLFormElement>('[data-chat-form]');
const output = root?.querySelector<HTMLElement>('[data-chat-output]');
const status = root?.querySelector<HTMLElement>('[data-chat-status]');
const submitButton = root?.querySelector<HTMLButtonElement>('[data-chat-submit]');
const cancelButton = root?.querySelector<HTMLButtonElement>('[data-chat-cancel]');

if (root && form && output && status && submitButton && cancelButton) {
    let controller: AbortController | undefined;
    let terminalEventReceived = false;
    let streamReportedError = false;
    let failedExperts = false;
    let seenExperts = new Set<string>();

    const setStatus = (message: string): void => {
        status.textContent = message;
    };

    const handleEvent = (frame: string): void => {
        let eventName = 'message';
        const dataLines: string[] = [];

        frame.split(/\r?\n/).forEach((line) => {
            if (line.startsWith(':')) {
                return;
            }

            const separator = line.indexOf(':');
            const field = separator === -1 ? line : line.slice(0, separator);
            const value = separator === -1 ? '' : line.slice(separator + 1).replace(/^ /, '');

            if (field === 'event') {
                eventName = value;
            } else if (field === 'data') {
                dataLines.push(value);
            }
        });

        if (dataLines.length === 0) {
            return;
        }

        let payload: Record<string, unknown>;
        try {
            payload = JSON.parse(dataLines.join('\n')) as Record<string, unknown>;
        } catch {
            setStatus('Получено некорректное событие от NEXUS AI.');
            return;
        }

        if (eventName === 'token' && typeof payload.delta === 'string') {
            if (typeof payload.expert === 'string' && !seenExperts.has(payload.expert)) {
                seenExperts.add(payload.expert);
                output.textContent += `\n\n${payload.expert}\n`;
            }
            output.textContent += payload.delta;
        } else if (eventName === 'expert_start' && typeof payload.expert === 'string') {
            setStatus(`Отвечает эксперт: ${payload.expert}`);
        } else if (eventName === 'expert_done') {
            setStatus('Эксперт завершил ответ. NEXUS AI собирает результат…');
        } else if (eventName === 'reasoning') {
            return;
        } else if (eventName === 'done') {
            terminalEventReceived = true;
            const summary = payload.summary;
            if (typeof summary === 'object' && summary !== null && 'ok' in summary && 'total' in summary) {
                const result = summary as { ok: unknown; total: unknown };
                if (typeof result.ok === 'number' && typeof result.total === 'number') {
                    setStatus(`Ответ завершён. Экспертов ответило: ${result.ok} из ${result.total}.`);
                } else {
                    setStatus('Ответ завершён.');
                }
            } else {
                setStatus('Ответ завершён.');
            }
        } else if (eventName === 'error') {
            terminalEventReceived = true;
            streamReportedError = true;
            setStatus('NEXUS AI не смог завершить запрос. Попробуйте ещё раз.');
        } else if (eventName === 'expert_error') {
            failedExperts = true;
            setStatus('Один из экспертов не ответил; NEXUS AI продолжает обработку.');
        } else if (eventName === 'start') {
            setStatus('NEXUS AI формирует ответ…');
        }
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (controller) {
            return;
        }

        const formData = new FormData(form);
        const message = formData.get('message');
        const projectId = formData.get('project_id');
        const csrfToken = formData.get('_token');

        if (
            typeof message !== 'string' ||
            typeof projectId !== 'string' ||
            typeof csrfToken !== 'string' ||
            message.trim() === ''
        ) {
            setStatus('Укажите проект и текст сообщения.');
            return;
        }

        controller = new AbortController();
        terminalEventReceived = false;
        streamReportedError = false;
        failedExperts = false;
        seenExperts = new Set<string>();
        output.hidden = false;
        output.textContent = `Вы:\n${message.trim()}\n\nОтвет NEXUS AI:\n`;
        submitButton.disabled = true;
        cancelButton.hidden = false;
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
                body: JSON.stringify({
                    project_id: Number(projectId),
                    message,
                }),
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
                buffer += decoder.decode(value, { stream: !done }).replace(/\r\n/g, '\n');

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
            } else if (!streamReportedError && !failedExperts) {
                setStatus('Поток завершён.');
            }
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                setStatus('Ответ остановлен.');
            } else {
                setStatus(error instanceof Error ? error.message : 'Не удалось связаться с NEXUS AI. Попробуйте ещё раз.');
            }
        } finally {
            controller = undefined;
            submitButton.disabled = false;
            cancelButton.hidden = true;
        }
    });

    cancelButton.addEventListener('click', () => controller?.abort());
}
