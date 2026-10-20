<x-app-layout title="Задачи · NEXUS AI">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> УПРАВЛЕНИЕ ПРОЕКТАМИ · ЗАДАЧИ</div>
            <h1>Задачи <span>проекта</span></h1>
            <p class="welcome-copy">Управление задачами проекта. Отслеживайте статус, исполнителей и сроки выполнения.</p>
        </div>
    </section>

    @if (session('status'))
        @php $status = session('status'); @endphp
        @if ($status === 'task_created')
            <div class="notice notice-success" role="status">Задача создана.</div>
        @elseif ($status === 'task_updated')
            <div class="notice notice-success" role="status">Задача обновлена.</div>
        @elseif ($status === 'task_deleted')
            <div class="notice notice-success" role="status">Задача удалена.</div>
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
            <div><span class="eyebrow">NEXUS AI · GET /v1/projects/{id}/tasks</span><h2>Список задач</h2></div>
            @if (! isset($error))
                <span class="directory-count">{{ count($tasks) }} задач</span>
            @endif
        </section>
        <button class="button button-primary" type="button" onclick="openCreateModal()">+ Создать задачу</button>
    </div>

    @if ($tasks === [] && ! isset($error))
        <section class="panel empty-state">
            <span class="empty-state-icon" aria-hidden="true">◎</span>
            <strong>Задач пока нет</strong>
            <p>Создайте первую задачу для проекта.</p>
        </section>
    @elseif ($tasks !== [])
        <div class="table-scroll" role="region" aria-label="Список задач проекта" tabindex="0">
        <table class="user-table">
            <thead>
                <tr>
                    <th>Название</th>
                    <th>Статус</th>
                    <th>Исполнитель</th>
                    <th>Срок</th>
                    <th>Создано</th>
                    <th style="width:140px">Действия</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tasks as $task)
                    @php
                        $statusClass = match ($task->status) {
                            'open' => 'status-open',
                            'in_progress' => 'status-in_progress',
                            'done' => 'status-done',
                            'cancelled' => 'status-archived',
                            default => 'status-open',
                        };
                        $isOverdue = $task->isOverdue();
                    @endphp
                    <tr {{ $isOverdue ? 'style="background:rgba(255,143,135,.04)"' : '' }}>
                        <td>
                            <strong>{{ $task->title }}</strong>
                            @if ($task->description)
                                <p style="margin:4px 0 0;color:var(--muted);font-size:12px;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $task->description }}</p>
                            @endif
                            @if ($isOverdue)
                                <span class="card-status status-failed" style="margin-left:6px;font-size:10px">Просрочена</span>
                            @endif
                        </td>
                        <td><span class="card-status {{ $statusClass }}">{{ $task->statusLabel() }}</span></td>
                        <td>{{ $task->assigneeExpert ?? '—' }}</td>
                        <td>
                            @if ($task->dueDate)
                                <span {{ $isOverdue ? 'style="color:var(--danger)"' : '' }}>
                                    {{ \Carbon\Carbon::parse($task->dueDate)->format('d.m.Y') }}
                                </span>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if ($task->createdAt)
                                {{ \Carbon\Carbon::parse($task->createdAt)->format('d.m.Y') }}
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            <button class="button button-secondary" type="button" onclick='openEditModal({{ json_encode($task) }})'>Изменить</button>
                            <form method="POST" action="{{ route('admin.tasks.destroy', ['taskId' => $task->id]) }}" style="display:inline" onsubmit="return confirm('Удалить задачу «{{ $task->title }}»?')">
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

    {{-- Модальное окно: создать задачу --}}
    <dialog id="createModal" class="modal-dialog" onclick="if(event.target===this)closeCreateModal()">
        <div class="modal-content">
            <div class="panel-heading">
                <div><span class="eyebrow">СОЗДАНИЕ</span><h2>Новая задача</h2></div>
                <button class="modal-close" type="button" onclick="closeCreateModal()" aria-label="Закрыть">&times;</button>
            </div>
            <form method="POST" action="{{ route('admin.tasks.store', ['nexusProjectId' => $nexusProjectId]) }}" class="project-form">
                @csrf
                <div class="field">
                    <label for="createTitle">Название</label>
                    <input id="createTitle" name="title" value="{{ old('title') }}" minlength="2" maxlength="300" required>
                    @error('title') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="createDescription">Описание</label>
                    <textarea id="createDescription" name="description" rows="3" maxlength="10000">{{ old('description') }}</textarea>
                    @error('description') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="createAssignee">Ключ эксперта-исполнителя</label>
                    <input id="createAssignee" name="assignee_expert" value="{{ old('assignee_expert') }}" maxlength="255" placeholder="Например, architect">
                    @error('assignee_expert') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="createDueDate">Срок</label>
                    <input id="createDueDate" name="due_date" type="date" value="{{ old('due_date') }}">
                    @error('due_date') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="modal-actions">
                    <button class="button button-primary" type="submit">Создать</button>
                    <button class="button button-secondary" type="button" onclick="closeCreateModal()">Отмена</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- Модальное окно: редактировать задачу --}}
    <dialog id="editModal" class="modal-dialog" onclick="if(event.target===this)closeEditModal()">
        <div class="modal-content">
            <div class="panel-heading">
                <div><span class="eyebrow">РЕДАКТИРОВАНИЕ</span><h2>Изменить задачу</h2></div>
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
                    <label for="editDescription">Описание</label>
                    <textarea id="editDescription" name="description" rows="3" maxlength="10000"></textarea>
                    @error('description') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="editStatus">Статус</label>
                    <select id="editStatus" name="status">
                        <option value="open">Открыта</option>
                        <option value="in_progress">В работе</option>
                        <option value="done">Выполнена</option>
                        <option value="cancelled">Отменена</option>
                    </select>
                    @error('status') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="editAssignee">Ключ эксперта-исполнителя</label>
                    <input id="editAssignee" name="assignee_expert" maxlength="255" placeholder="Например, architect">
                    @error('assignee_expert') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="editDueDate">Срок</label>
                    <input id="editDueDate" name="due_date" type="date">
                    @error('due_date') <p class="field-error">{{ $message }}</p> @enderror
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
function openEditModal(task) {
    document.getElementById('editForm').action = '/admin/tasks/' + task.id;
    document.getElementById('editTitle').value = task.title;
    document.getElementById('editDescription').value = task.description || '';
    document.getElementById('editStatus').value = task.status;
    document.getElementById('editAssignee').value = task.assigneeExpert || '';
    document.getElementById('editDueDate').value = task.dueDate || '';
    document.getElementById('editModal').showModal();
}
function closeEditModal() {
    document.getElementById('editModal').close();
}
</script>
@endpush
</x-app-layout>
