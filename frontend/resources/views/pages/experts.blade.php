<x-app-layout title="Эксперты · NEXUS AI">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> АДМИНИСТРИРОВАНИЕ · ЭКСПЕРТЫ</div>
            <h1>Каталог <span>экспертов</span></h1>
            <p class="welcome-copy">Управление экспертами NEXUS AI. Каждый эксперт имеет системный промпт, ключевые слова для маршрутизации и ограничения.</p>
        </div>
        @if (session('status'))
            @php $status = session('status'); @endphp
            @if ($status === 'expert_created')
                <div class="notice notice-success" role="status">Эксперт создан.</div>
            @elseif ($status === 'expert_updated')
                <div class="notice notice-success" role="status">Эксперт обновлён.</div>
            @elseif ($status === 'expert_deleted')
                <div class="notice notice-success" role="status">Эксперт удалён.</div>
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
        @if ($error)
            <div class="admin-note project-alert-error" role="alert"><span aria-hidden="true">!</span><p>{{ $error }}</p></div>
        @endif
        @if (! $error)
            <span class="section-caption">{{ count($experts) }} ЭКСПЕРТОВ</span>
        @endif
    </section>

    @if ($error)
        <section class="panel empty-state">
            <span class="empty-state-icon" aria-hidden="true">◎</span>
            <strong>Эксперты недоступны</strong>
            <p>Проверьте, что сервис NEXUS AI запущен и настроен.</p>
        </section>
    @elseif ($experts === [])
        <section class="panel empty-state">
            <span class="empty-state-icon" aria-hidden="true">◎</span>
            <strong>Экспертов пока нет</strong>
            <p>Создайте первого эксперта.</p>
        </section>
    @else
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:20px">
            <section class="section-heading" style="margin-top:0">
                <div><span class="eyebrow">NEXUS AI · GET /v1/experts/all</span><h2>Список экспертов</h2></div>
            </section>
            <button class="button button-primary" type="button" onclick="openCreateModal()">+ Создать эксперта</button>
        </div>

        <section class="overview-grid" aria-label="Эксперты NEXUS AI" style="margin-top:16px">
            @foreach ($experts as $expert)
                <article class="overview-card">
                    <div class="overview-card-top">
                        <span class="card-icon" aria-hidden="true" style="{{ $expert->color ? 'color:' . e($expert->color) : '' }}">
                            {{ $expert->icon ?: '✳' }}
                        </span>
                        <div style="display:flex;gap:8px;align-items:center">
                            @if ($expert->isSystem)
                                <span class="role-chip">СИСТЕМНЫЙ</span>
                            @endif
                            <span class="card-status {{ $expert->enabled ? '' : 'card-status-muted' }}">
                                <i></i>{{ $expert->enabled ? 'ВКЛЮЧЁН' : 'ОТКЛЮЧЁН' }}
                            </span>
                        </div>
                    </div>
                    <strong class="card-value">{{ $expert->name }}</strong>
                    <span class="card-caption">{{ $expert->description ?: 'Описание не задано.' }}</span>
                    <div class="role-chip">Ключ: <span>{{ $expert->key }}</span></div>
                    @if ($expert->keywords !== [])
                        <p class="card-inline-note">
                            {{ implode(' · ', array_slice($expert->keywords, 0, 6)) }}
                        </p>
                    @endif
                    <div style="display:flex;gap:8px;margin-top:12px">
                        <button class="button button-secondary" type="button" data-expert="{{ json_encode($expert) }}" style="flex:1">Изменить</button>
                        @unless ($expert->isSystem)
                            <form method="POST" action="{{ route('admin.experts.destroy', ['expertKey' => $expert->key]) }}" style="flex:1" onsubmit="return confirm('Удалить эксперта «{{ $expert->name }}»?')">
                                @csrf
                                @method('DELETE')
                                <button class="button button-danger" type="submit">Удалить</button>
                            </form>
                        @endunless
                    </div>
                </article>
            @endforeach
        </section>
    @endif

    {{-- Модальное окно: создать эксперта --}}
    <dialog id="createModal" class="modal-dialog" onclick="if(event.target===this)closeCreateModal()">
        <div class="modal-content">
            <div class="panel-heading">
                <div><span class="eyebrow">СОЗДАНИЕ</span><h2>Новый эксперт</h2></div>
                <button class="modal-close" type="button" onclick="closeCreateModal()" aria-label="Закрыть">&times;</button>
            </div>
            <form method="POST" action="{{ route('admin.experts.store') }}" class="project-form">
                @csrf
                <div class="field">
                    <label for="createKey">Ключ</label>
                    <input id="createKey" name="key" value="{{ old('key') }}" minlength="1" maxlength="100" required placeholder="my_custom_expert">
                    @error('key') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="createName">Название</label>
                    <input id="createName" name="name" value="{{ old('name') }}" minlength="1" maxlength="200" required>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="createDescription">Описание</label>
                    <textarea id="createDescription" name="description" rows="3" maxlength="5000">{{ old('description') }}</textarea>
                    @error('description') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="createSystemPrompt">Системный промпт</label>
                    <textarea id="createSystemPrompt" name="system_prompt" rows="8" minlength="20" required>{{ old('system_prompt') }}</textarea>
                    @error('system_prompt') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="createIcon">Иконка</label>
                    <input id="createIcon" name="icon" value="{{ old('icon') }}" maxlength="10" placeholder="✳">
                    @error('icon') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="createColor">Цвет (HEX)</label>
                    <input id="createColor" name="color" value="{{ old('color') }}" maxlength="7" placeholder="#c0a5ff">
                    @error('color') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="createKeywords">Ключевые слова (через запятую)</label>
                    <input id="createKeywords" name="keywords" value="{{ old('keywords') }}" placeholder="архитектура, дизайн, API">
                    @error('keywords') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="createSortOrder">Порядок сортировки</label>
                    <input id="createSortOrder" name="sort_order" type="number" min="0" max="100000" value="{{ old('sort_order', 100) }}" required>
                    @error('sort_order') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="checkbox-label">
                        <input type="hidden" name="is_enabled" value="0">
                        <input type="checkbox" name="is_enabled" value="1" {{ old('is_enabled', true) ? 'checked' : '' }}>
                        <span>Включён</span>
                    </label>
                    @error('is_enabled') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="modal-actions">
                    <button class="button button-primary" type="submit">Создать</button>
                    <button class="button button-secondary" type="button" onclick="closeCreateModal()">Отмена</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- Модальное окно: редактировать эксперта --}}
    <dialog id="editModal" class="modal-dialog" onclick="if(event.target===this)closeEditModal()">
        <div class="modal-content">
            <div class="panel-heading">
                <div><span class="eyebrow">РЕДАКТИРОВАНИЕ</span><h2>Изменить эксперта</h2></div>
                <button class="modal-close" type="button" onclick="closeEditModal()" aria-label="Закрыть">&times;</button>
            </div>
            <form id="editForm" method="POST" class="project-form">
                @csrf
                @method('PATCH')
                <div class="field">
                    <label for="editKey">Ключ</label>
                    <input id="editKey" readonly aria-describedby="editKeyHint">
                    <small class="field-hint" id="editKeyHint">Ключ задаётся при создании и не изменяется.</small>
                </div>
                <div class="field">
                    <label for="editName">Название</label>
                    <input id="editName" name="name" minlength="1" maxlength="200" required>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="editDescription">Описание</label>
                    <textarea id="editDescription" name="description" rows="3" maxlength="5000"></textarea>
                    @error('description') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="editSystemPrompt">Системный промпт</label>
                    <textarea id="editSystemPrompt" name="system_prompt" rows="8" minlength="20" required></textarea>
                    @error('system_prompt') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="editIcon">Иконка</label>
                    <input id="editIcon" name="icon" maxlength="10">
                    @error('icon') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="editColor">Цвет (HEX)</label>
                    <input id="editColor" name="color" maxlength="7">
                    @error('color') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="editKeywords">Ключевые слова (через запятую)</label>
                    <input id="editKeywords" name="keywords">
                    @error('keywords') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="editSortOrder">Порядок сортировки</label>
                    <input id="editSortOrder" name="sort_order" type="number" min="0" max="100000" required>
                    @error('sort_order') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="checkbox-label">
                        <input type="hidden" name="is_enabled" value="0">
                        <input id="editEnabled" type="checkbox" name="is_enabled" value="1">
                        <span>Включён</span>
                    </label>
                    @error('is_enabled') <p class="field-error">{{ $message }}</p> @enderror
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
function openEditModal(expert) {
    document.getElementById('editForm').action = '/admin/experts/' + encodeURIComponent(expert.key);
    document.getElementById('editKey').value = expert.key;
    document.getElementById('editName').value = expert.name;
    document.getElementById('editDescription').value = expert.description || '';
    document.getElementById('editSystemPrompt').value = expert.systemPrompt;
    document.getElementById('editIcon').value = expert.icon || '';
    document.getElementById('editColor').value = expert.color || '';
    document.getElementById('editKeywords').value = (expert.keywords || []).join(', ');
    document.getElementById('editSortOrder').value = expert.sortOrder;
    document.getElementById('editEnabled').checked = expert.enabled === true;
    document.getElementById('editModal').showModal();
}
function closeEditModal() {
    document.getElementById('editModal').close();
}
document.querySelectorAll('[data-expert]').forEach((button) => {
    button.addEventListener('click', () => openEditModal(JSON.parse(button.dataset.expert)));
});
</script>
@endpush
</x-app-layout>
