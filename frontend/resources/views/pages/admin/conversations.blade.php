<x-app-layout title="История разговоров · NEXUS AI">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> УПРАВЛЕНИЕ ПРОЕКТАМИ · ИСТОРИЯ РАЗГОВОРОВ</div>
            <h1>История <span>разговоров</span></h1>
            <p class="welcome-copy">Просмотр истории бесед и сообщений проекта. Сообщения отображаются в хронологическом порядке с указанием авторов и источников RAG.</p>
        </div>
    </section>

    @if (isset($error) && $error)
        <div class="admin-note project-alert-error" role="alert"><span aria-hidden="true">!</span><p>{{ $error }}</p></div>
    @endif

    @if (! isset($showMessages) || ! $showMessages)
        {{-- Список бесед --}}
        <section class="section-heading">
            <div><span class="eyebrow">NEXUS AI · GET /v1/projects/{id}/conversations</span><h2>Беседы проекта</h2></div>
            @if (! isset($error))
                <span class="directory-count">{{ count($conversations) }} бесед</span>
            @endif
        </section>

        @if ($conversations === [] && ! isset($error))
            <section class="panel empty-state">
                <span class="empty-state-icon" aria-hidden="true">◎</span>
                <strong>Бесед пока нет</strong>
                <p>Отправьте первое сообщение в чате — беседа создастся автоматически.</p>
            </section>
        @elseif ($conversations !== [])
            <table class="user-table">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Создана</th>
                        <th>Последнее сообщение</th>
                        <th>Сообщений</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($conversations as $conversation)
                        <tr>
                            <td>
                                <a href="{{ route('admin.conversations.messages', ['conversationId' => $conversation->id, 'project_id' => $nexusProjectId]) }}" class="table-link">
                                    {{ $conversation->formattedTitle() }}
                                </a>
                            </td>
                            <td>
                                @if ($conversation->createdAt)
                                    {{ \Carbon\Carbon::parse($conversation->createdAt)->format('d.m.Y H:i') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if ($conversation->lastMessageAt)
                                    {{ \Carbon\Carbon::parse($conversation->lastMessageAt)->format('d.m.Y H:i') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $conversation->messageCount ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @else
        {{-- Просмотр сообщений беседы --}}
        <nav class="breadcrumb" aria-label="Навигация">
            <a href="{{ route('admin.conversations', ['nexusProjectId' => $nexusProjectId]) }}">Разговоры</a>
            <span class="breadcrumb-sep" aria-hidden="true">/</span>
            <span class="breadcrumb-current">Сообщения</span>
        </nav>

        <section class="section-heading">
            <div><span class="eyebrow">NEXUS AI · GET /v1/conversations/{id}/messages</span><h2>Сообщения беседы</h2></div>
        </section>

        @if ($messages === [])
            <section class="panel empty-state">
                <span class="empty-state-icon" aria-hidden="true">◎</span>
                <strong>Сообщений нет</strong>
                <p>В этой беседе пока нет сообщений.</p>
            </section>
        @else
            <div class="messages-container">
                @foreach ($messages as $message)
                    @php
                        $isUser = $message->isUser();
                        $isAssistant = $message->isAssistant();
                        $roleClass = $isUser ? 'message-user' : ($isAssistant ? 'message-assistant' : 'message-system');
                        $roleLabel = $isUser ? 'Вы' : ($isAssistant ? 'NEXUS AI' : 'Система');
                    @endphp
                    <div class="message-card {{ $roleClass }}">
                        <div class="message-header">
                            <span class="message-role">{{ $roleLabel }}</span>
                            @if ($message->createdAt)
                                <span class="message-timestamp">{{ \Carbon\Carbon::parse($message->createdAt)->format('d.m.Y H:i:s') }}</span>
                            @endif
                        </div>
                        <div class="message-content">{{ $message->content }}</div>
                        @if ($message->hasExpertAttribution() && $isAssistant)
                            <div class="message-experts">
                                @foreach ($message->expertAttribution as $expertKey => $expertData)
                                    @php
                                        $expertName = is_array($expertData) ? ($expertData['name'] ?? $expertKey) : $expertKey;
                                        $expertColor = is_array($expertData) ? ($expertData['color'] ?? null) : null;
                                    @endphp
                                    <span class="expert-chip" {{ $expertColor ? 'style="background:' . e($expertColor) . '22;color:' . e($expertColor) . ';"' : '' }}>
                                        {{ $expertName }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        @if ($message->hasSources())
                            <details class="message-sources">
                                <summary>Источники RAG ({{ count($message->sources) }})</summary>
                                <div class="message-sources-list">
                                    @foreach ($message->sources as $source)
                                        <div class="message-source-item">
                                            <strong>{{ $source['title'] ?? $source['source'] ?? 'Источник' }}</strong>
                                            <p>{{ $source['text'] ?? $source['content'] ?? '' }}</p>
                                            @if (isset($source['score']))
                                                <small class="message-source-score">Релевантность: {{ number_format((float)$source['score'] * 100, 1) }}%</small>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="messages-back">
                <a href="{{ route('admin.conversations', ['nexusProjectId' => $nexusProjectId]) }}" class="button button-secondary">← Назад к беседам</a>
            </div>
        @endif
    @endif
</x-app-layout>

<style>
.messages-container { display: grid; gap: 16px; margin-top: 20px; }
.message-card { padding: 16px; border: 1px solid #2a302a; border-radius: 10px; background: var(--surface); }
.message-user { border-left: 3px solid var(--lime); background: linear-gradient(145deg, rgba(197,245,66,.04), var(--surface)); }
.message-assistant { border-left: 3px solid var(--violet); }
.message-system { border-left: 3px solid var(--subtle); opacity: .8; font-style: italic; }
.message-header { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
.message-role { font-size: 11px; font-weight: 700; color: var(--lime); text-transform: uppercase; letter-spacing: .08em; }
.message-timestamp { font-size: 10px; color: var(--subtle); }
.message-content { color: var(--text); font-size: 13px; line-height: 1.65; white-space: pre-wrap; overflow-wrap: anywhere; }
.message-experts { display: flex; gap: 6px; margin-top: 10px; flex-wrap: wrap; }
.expert-chip { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; }
.message-sources { margin-top: 12px; }
.message-sources summary { font-size: 11px; color: var(--muted); cursor: pointer; }
.message-sources-list { margin-top: 8px; display: grid; gap: 10px; }
.message-source-item { padding: 10px; border: 1px solid #2a302a; border-radius: 6px; background: #141714; }
.message-source-item strong { font-size: 12px; color: var(--text); }
.message-source-item p { margin: 4px 0 0; font-size: 12px; color: var(--muted); line-height: 1.5; }
.message-source-score { color: var(--subtle); font-size: 10px; }
.messages-back { margin-top: 20px; }
.table-link { color: var(--text); text-decoration: none; border-bottom: 1px solid var(--line); transition: border-color .15s; }
.table-link:hover { border-color: var(--lime); color: var(--lime); }
</style>
