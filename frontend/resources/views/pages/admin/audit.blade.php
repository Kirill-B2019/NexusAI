<x-app-layout title="Журнал аудита · NEXUS AI">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> СИСТЕМА · ЖУРНАЛ АУДИТА</div>
            <h1>Журнал <span>аудита</span></h1>
            <p class="welcome-copy">Лог действий в системе NEXUS AI. Отслеживайте создание, обновление и удаление сущностей.</p>
        </div>
    </section>

    @if (isset($error) && $error)
        <div class="admin-note project-alert-error" role="alert"><span aria-hidden="true">!</span><p>{{ $error }}</p></div>
    @endif

    {{-- Фильтры --}}
    <section class="panel" style="margin-top:20px">
        <form method="GET" action="{{ route('admin.audit') }}" class="audit-filters">
            <div class="audit-filter-row">
                <div class="field">
                    <label for="filterActor">Исполнитель</label>
                    <input id="filterActor" name="actor" value="{{ e($actor ?? '') }}" placeholder="Email или имя пользователя">
                </div>
                <div class="field">
                    <label for="filterEntityType">Тип сущности</label>
                    <select id="filterEntityType" name="entity_type">
                        <option value="">Все</option>
                        <option value="project" {{ ($entityType ?? '') === 'project' ? 'selected' : '' }}>Проект</option>
                        <option value="expert" {{ ($entityType ?? '') === 'expert' ? 'selected' : '' }}>Эксперт</option>
                        <option value="user" {{ ($entityType ?? '') === 'user' ? 'selected' : '' }}>Пользователь</option>
                        <option value="document" {{ ($entityType ?? '') === 'document' ? 'selected' : '' }}>Документ</option>
                        <option value="conversation" {{ ($entityType ?? '') === 'conversation' ? 'selected' : '' }}>Беседа</option>
                        <option value="message" {{ ($entityType ?? '') === 'message' ? 'selected' : '' }}>Сообщение</option>
                        <option value="decision" {{ ($entityType ?? '') === 'decision' ? 'selected' : '' }}>Решение</option>
                        <option value="task" {{ ($entityType ?? '') === 'task' ? 'selected' : '' }}>Задача</option>
                        <option value="api_key" {{ ($entityType ?? '') === 'api_key' ? 'selected' : '' }}>API ключ</option>
                    </select>
                </div>
                <div class="field">
                    <label for="filterLimit">Лимит</label>
                    <select id="filterLimit" name="limit">
                        <option value="25" {{ ($limit ?? 50) === 25 ? 'selected' : '' }}>25</option>
                        <option value="50" {{ ($limit ?? 50) === 50 ? 'selected' : '' }}>50</option>
                        <option value="100" {{ ($limit ?? 50) === 100 ? 'selected' : '' }}>100</option>
                    </select>
                </div>
                <div class="audit-filter-actions">
                    <button class="button button-primary" type="submit">Фильтр</button>
                    <a href="{{ route('admin.audit') }}" class="button button-secondary">Сброс</a>
                </div>
            </div>
        </form>
    </section>

    <section class="section-heading" style="margin-top:20px">
        <div><span class="eyebrow">NEXUS AI · GET /v1/admin/audit</span><h2>Записи аудита</h2></div>
        @if (! isset($error))
            <span class="directory-count">{{ count($entries) }} записей</span>
        @endif
    </section>

    @if ($entries === [] && ! isset($error))
        <section class="panel empty-state">
            <span class="empty-state-icon" aria-hidden="true">◎</span>
            <strong>Записей нет</strong>
            <p>Журнал аудита пуст или не соответствует фильтрам.</p>
        </section>
    @elseif ($entries !== [])
        <table class="user-table">
            <thead>
                <tr>
                    <th>Время</th>
                    <th>Действие</th>
                    <th>Сущность</th>
                    <th>ID</th>
                    <th>Исполнитель</th>
                    <th>IP</th>
                    <th>Изменения</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entries as $entry)
                    <tr>
                        <td>
                            @if ($entry->createdAt)
                                {{ \Carbon\Carbon::parse($entry->createdAt)->format('d.m.Y H:i:s') }}
                            @else
                                —
                            @endif
                        </td>
                        <td><strong>{{ $entry->actionLabel() }}</strong></td>
                        <td>{{ $entry->entityTypeLabel() }}</td>
                        <td><code style="font-size:11px;color:var(--muted)">{{ Str::limit($entry->entityId ?? '', 20) }}</code></td>
                        <td>{{ $entry->actor ?? '—' }}</td>
                        <td><code style="font-size:11px;color:var(--subtle)">{{ $entry->ipAddress ?? '—' }}</code></td>
                        <td>
                            @if (! empty($entry->changes))
                                <details>
                                    <summary style="cursor:pointer;color:var(--muted);font-size:11px">Показать ({{ count($entry->changes) }})</summary>
                                    <pre style="margin:8px 0 0;padding:8px;background:#141714;border:1px solid #2a302a;border-radius:6px;font-size:11px;overflow-x:auto;color:var(--muted)">{{ json_encode($entry->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-app-layout>

<style>
.audit-filters { padding: 0; }
.audit-filter-row { display: grid; gap: 12px; grid-template-columns: 1fr 1fr auto auto; align-items: end; }
.audit-filter-actions { display: flex; gap: 8px; padding-bottom: 2px; }
</style>
