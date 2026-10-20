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
            <p>Не настроен NEXUS_AI_URL. Укажите адрес NEXUS AI в локальном frontend/.env.</p>
        </div>
    @else
        @can('manage-projects')
            <section
                class="panel chat-panel"
                data-chat
                data-stream-url="{{ route('chat.stream') }}"
                data-home-url="{{ route('home') }}"
            >
                <div class="panel-heading chat-config-heading">
                    <div>
                        <span class="eyebrow">НАСТРОЙКА · NEXUS AI</span>
                        <h2>Параметры запроса</h2>
                    </div>
                </div>

                <div class="chat-config">
                    <div class="field">
                        <label for="project_id">Проект</label>
                        <select id="project_id" name="project_id" data-chat-project required>
                            @foreach ($projects as $project)
                                <option value="{{ $project->id }}" @selected((string) request('project') === (string) $project->id)>
                                    {{ $project->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="expertMode">Режим экспертов</label>
                        <select id="expertMode" data-expert-mode-select>
                            <option value="auto">Автовыбор (роутер)</option>
                            @if (count($experts) > 0)
                                <option value="single">Один эксперт</option>
                                <option value="manual">Ручной список</option>
                            @endif
                        </select>
                    </div>

                    <div class="field" data-single-expert-block hidden>
                        <label for="singleExpert">Выберите эксперта</label>
                        <select id="singleExpert" data-single-expert-select>
                            @foreach ($experts as $expert)
                                <option
                                    value="{{ $expert->key }}"
                                    data-expert-name="{{ $expert->name }}"
                                    data-expert-icon="{{ $expert->icon ?? '✳' }}"
                                    data-expert-color="{{ $expert->color ?? '' }}"
                                >{{ $expert->icon ?? '✳' }} {{ $expert->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field" data-manual-expert-block hidden>
                        <label>Выберите экспертов (максимум 4)</label>
                        <div class="experts-grid" data-experts-list>
                            @foreach ($experts as $expert)
                                <label class="expert-item">
                                    <input
                                        type="checkbox"
                                        name="experts[]"
                                        value="{{ $expert->key }}"
                                        data-expert-name="{{ $expert->name }}"
                                        data-expert-icon="{{ $expert->icon ?? '✳' }}"
                                        data-expert-color="{{ $expert->color ?? '' }}"
                                    >
                                    <span>{{ $expert->icon ?? '✳' }} {{ $expert->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="chat-toolbar">
                        <label class="checkbox-label">
                            <input type="checkbox" data-chat-thinking>
                            <span>Thinking</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" data-chat-rag checked>
                            <span>RAG</span>
                        </label>
                        <button class="button button-secondary" type="button" data-chat-clear>Очистить</button>
                    </div>
                </div>

                <div
                    class="chat-output"
                    data-chat-output
                    role="log"
                    aria-label="Ответ NEXUS AI"
                    aria-live="off"
                    hidden
                ></div>

                <div class="chat-input-panel">
                    <form class="chat-form" data-chat-form>
                        @csrf
                        <div class="field">
                            <label for="message">Сообщение</label>
                            <textarea
                                id="message"
                                name="message"
                                rows="3"
                                maxlength="32000"
                                required
                                placeholder="Введите вопрос..."
                                autofocus
                            ></textarea>
                            <small class="field-hint">Enter — отправить · Ctrl+Enter — новая строка</small>
                        </div>

                        <div class="chat-toolbar">
                            <button class="button button-primary" type="submit" data-chat-submit>Отправить</button>
                            <button class="button button-secondary" type="button" data-chat-cancel hidden>Остановить</button>
                            <span class="chat-status" data-chat-status role="status" aria-live="polite"></span>
                        </div>
                    </form>
                </div>
            </section>
        @else
            <div class="admin-note" role="status">
                <span aria-hidden="true">i</span>
                <p>У вашей роли есть доступ только для чтения. Отправлять сообщения в NEXUS AI нельзя.</p>
            </div>
        @endcan
    @endif
</x-app-layout>
