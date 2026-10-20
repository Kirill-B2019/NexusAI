<x-app-layout title="Решения · NEXUS AI">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> УПРАВЛЕНИЕ ПРОЕКТАМИ · РЕШЕНИЯ</div>
            <h1>Решения <span>проекта</span></h1>
            <p class="welcome-copy">Управление архитектурными и бизнес-решениями проекта. Каждое решение отслеживает статус и историю изменений.</p>
        </div>
    </section>

    @if (session('status'))
        @php $status = session('status'); @endphp
        @if ($status === 'decision_created')
            <div class="notice notice-success" role="status">Решение создано.</div>
        @elseif ($status === 'decision_updated')
            <div class="notice notice-success" role="status">Решение обновлено.</div>
        @elseif ($status === 'decision_deleted')
            <div class="notice notice-success" role="status">Решение удалено.</div>
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
    @if (isset($error) && $error)
        <div class="admin-note project-alert-error" role="alert"><span aria-hidden="true">!</span><p>{{ $error }}</p></div>
    @endif

    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:20px">
        <section class="section-heading" style="margin-top:0">
            <div><span class="eyebrow">NEXUS AI · GET /v1/projects/{id}/decisions</span><h2>Список решений</h2></div>
            @if (! isset($error))
                <span class="directory-count">{{ count($decisions) }} решений</span>
            @endif
        </section>
        <button class="button button-primary" type="button" onclick="openCreateModal()">+ Создать решение</button>
    </div>

    @if ($decisions === [] && ! isset($error))
        <section class="panel empty-state">
            <span class="empty-state-icon" aria-hidden="true">◎</span>
            <strong>Решений пока нет</strong>
            <p>Создайте первое решение для проекта.</p>
        </section>
    @elseif ($decisions !== [])
        <div class="table-scroll" role="region" aria-label="Список решений проекта" tabindex="0">
        <table class="user-table">
            <thead>
                <tr>
                    <th>Название</th>
                    <th>Статус</th>
                    <th>Создано</th>
                    <th>Обновлено</th>
                    <th style="width:140px">Действия</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($decisions as $decision)
                    @php
                        $statusClass = match ($decision->status) {
                            'active' => 'status-active',
                            'superseded' => 'status-archived',
                            'archived' => 'status-archived',
                            default => 'status-active',
                        };
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $decision->title }}</strong>
                            @if ($decision->content)
                                <p style="margin:4px 0 0;color:var(--muted);font-size:12px;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $decision->content }}</p>
                            @endif
                        </td>
                        <td><span class="card-status {{ $statusClass }}">{{ $decision->statusLabel() }}</span></td>
                        <td>
                            @if ($decision->createdAt)
                                {{ \Carbon\Carbon::parse($decision->createdAt)->format('d.m.Y') }}
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if ($decision->updatedAt)
                                {{ \Carbon\Carbon::parse($decision->updatedAt)->format('d.m.Y') }}
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            <button class="button button-secondary" type="button" onclick='openEditModal({{ json_encode($decision) }})'>Изменить</button>
                            <form method="POST" action="{{ route('admin.decisions.destroy', ['decisionId' => $decision->id]) }}" style="display:inline" onsubmit="return confirm('Удалить решение «{{ $decision->title }}»?')">
                                @csrf
                                @method('DELETE')
                                <button class="button button-danger" type="submit" style="margin-left:6px">Удалить</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif

    {{-- Модальное окно: создать решение --}}
    <dialog id="createModal" class="modal-dialog" onclick="if(event.target===this)closeCreateModal()">
        <div class="modal-content">
            <div class="panel-heading">
                <div><span class="eyebrow">СОЗДАНИЕ</span><h2>Новое решение</h2></div>
                <button class="modal-close" type="button" onclick="closeCreateModal()" aria-label="Закрыть">&times;</button>
            </div>
            <form method="POST" action="{{ route('admin.decisions.store', ['nexusProjectId' => $nexusProjectId]) }}" class="project-form">
                @csrf
                <div class="field">
                    <label for="createTitle">Название</label>
                    <input id="createTitle" name="title" value="{{ old('title') }}" minlength="2" maxlength="300" required>
                    @error('title') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="createContent">Содержание</label>
                    <textarea id="createContent" name="content" rows="3" maxlength="10000" required>{{ old('content') }}</textarea>
                    @error('content') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="modal-actions">
                    <button class="button button-primary" type="submit">Создать</button>
                    <button class="button button-secondary" type="button" onclick="closeCreateModal()">Отмена</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- Модальное окно: редактировать решение --}}
    <dialog id="editModal" class="modal-dialog" onclick="if(event.target===this)closeEditModal()">
        <div class="modal-content">
            <div class="panel-heading">
                <div><span class="eyebrow">РЕДАКТИРОВАНИЕ</span><h2>Изменить решение</h2></div>
                <button class="modal-close" type="button" onclick="closeEditModal()" aria-label="Закрыть">&times;</button>
            </div>
            <form id="editForm" method="POST" class="project-form">
                @csrf
                @method('PATCH')
                <div class="field">
                    <label for="editTitle">Название</label>
                    <input id="editTitle" name="title" minlength="2" maxlength="300" required>
                    @error('title') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="editContent">Содержание</label>
                    <textarea id="editContent" name="content" rows="3" maxlength="10000"></textarea>
                    @error('content') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="editStatus">Статус</label>
                    <select id="editStatus" name="status">
                        <option value="active">Активно</option>
                        <option value="superseded">Заменено</option>
                        <option value="archived">Архив</option>
                    </select>
                    @error('status') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="modal-actions">
                    <button class="button button-primary" type="submit">Сохранить</button>
                    <button class="button button-secondary" type="button" onclick="closeEditModal()">Отмена</button>
                </div>
            </form>
        </div>
    </dialog>
@push('scripts')
<script>
function openCreateModal() {
    document.getElementById('createModal').showModal();
}
function closeCreateModal() {
    document.getElementById('createModal').close();
}
function openEditModal(decision) {
    document.getElementById('editForm').action = '/admin/decisions/' + decision.id;
    document.getElementById('editTitle').value = decision.title;
    document.getElementById('editContent').value = decision.content || '';
    document.getElementById('editStatus').value = decision.status;
    document.getElementById('editModal').showModal();
}
function closeEditModal() {
    document.getElementById('editModal').close();
}
</script>
@endpush
</x-app-layout>
