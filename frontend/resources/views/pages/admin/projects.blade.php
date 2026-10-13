<x-app-layout title="Проекты">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> АДМИНИСТРИРОВАНИЕ</div>
            <h1>Назначение <span>проектов</span></h1>
            <p class="welcome-copy">Свяжите проект NEXUS с пользователем. Проектный ключ хранится в БД только в зашифрованном виде.</p>
        </div>
    </section>

    @if (session('status'))
        <div class="notice notice-success" role="status">{{ session('status') }}</div>
    @endif

    <section class="panel getting-started">
        <div class="panel-heading">
            <div><span class="eyebrow">НОВОЕ НАЗНАЧЕНИЕ</span><h2>Подключить проект NEXUS AI</h2></div>
        </div>
        <form class="project-form" method="POST" action="{{ route('admin.projects.store') }}">
            @csrf
            <div class="field">
                <label for="owner_user_id">Пользователь</label>
                <select id="owner_user_id" name="owner_user_id" required>
                    <option value="">Выберите пользователя</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected((string) old('owner_user_id') === (string) $user->id)>
                            {{ $user->name }} · {{ $user->email }}
                        </option>
                    @endforeach
                </select>
                @error('owner_user_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="name">Название проекта</label>
                <input id="name" name="name" value="{{ old('name') }}" maxlength="120" required>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="nexus_project_id">UUID проекта NEXUS</label>
                <input id="nexus_project_id" name="nexus_project_id" value="{{ old('nexus_project_id') }}" autocomplete="off" required>
                @error('nexus_project_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="api_key">Project API key</label>
                <input id="api_key" name="api_key" type="password" autocomplete="new-password" required>
                @error('api_key') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <p class="card-caption">Ключ используется только сервером для назначенного проекта и никогда не отображается после сохранения.</p>
            <button class="button button-primary" type="submit">Назначить проект</button>
        </form>
    </section>

    <section class="section-heading">
        <div><span class="eyebrow">ПРИВЯЗАННЫЕ ПРОЕКТЫ</span><h2>Назначения пользователей</h2></div>
        <span class="section-caption">{{ $projects->count() }} ПРОЕКТОВ</span>
    </section>
    <section class="panel">
        @if ($projects->isEmpty())
            <div class="empty-state">
                <span class="empty-state-icon" aria-hidden="true">◎</span>
                <strong>Назначений пока нет</strong>
                <p>Добавьте проект через форму выше.</p>
            </div>
        @else
            <div class="table-scroll">
                <table class="user-table">
                    <thead><tr><th>ПРОЕКТ</th><th>ПОЛЬЗОВАТЕЛЬ</th><th>РОЛЬ</th></tr></thead>
                    <tbody>
                        @foreach ($projects as $project)
                            <tr>
                                <td>{{ $project->name }}</td>
                                <td><span>{{ $project->owner->name }}</span><small>{{ $project->owner->email }}</small></td>
                                <td><span class="role-badge role-{{ $project->owner->role->value }}">{{ $project->owner->role->label() }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-app-layout>
