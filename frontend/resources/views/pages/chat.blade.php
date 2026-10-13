<x-app-layout title="AI-студия">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> NEXUS AI · SSE</div>
            <h1>Чат с <span>экспертами</span></h1>
            <p class="welcome-copy">Ответ передаётся через сервер Laravel потоком; ключи API остаются на сервере.</p>
        </div>
    </section>

    @if ($projects->isEmpty())
        <div class="admin-note" role="status">
            <span aria-hidden="true">!</span>
            <p>Для чата нужен назначенный проект с project API key. Попросите администратора выдать доступ.</p>
        </div>
        @can('manage-users')
            <a class="button button-primary" href="{{ route('admin.projects') }}">Назначить проект</a>
        @endcan
    @elseif (! $apiConfigured)
        <div class="admin-note" role="alert">
            <span aria-hidden="true">!</span>
            <p>Не настроен NEXUS_AI_URL. Укажите внутренний адрес API в локальном frontend/.env.</p>
        </div>
    @else
        <section
            class="panel getting-started"
            data-chat
            data-stream-url="{{ route('chat.stream') }}"
        >
            <form class="project-form" data-chat-form>
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <div class="field">
                    <label for="project_id">Проект</label>
                    <select id="project_id" name="project_id" required>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}" @selected((string) request('project') === (string) $project->id)>
                                {{ $project->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="message">Сообщение</label>
                    <textarea id="message" name="message" rows="4" maxlength="32000" required placeholder="Опишите задачу или задайте вопрос..."></textarea>
                </div>
                <div class="modal-actions">
                    <button class="button button-primary" type="submit" data-chat-submit>Отправить запрос</button>
                    <button class="button button-secondary" type="button" data-chat-cancel hidden>Остановить ответ</button>
                    <span class="card-caption" data-chat-status role="status" aria-live="polite"></span>
                </div>
            </form>
            <pre class="chat-output" data-chat-output aria-live="polite" hidden></pre>
        </section>
    @endif
</x-app-layout>
