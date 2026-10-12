<x-app-layout title="Пользователи">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> АДМИНИСТРИРОВАНИЕ</div>
            <h1>Доступ <span>пользователей</span></h1>
            <p class="welcome-copy">Просмотр учётных записей рабочей области и назначенных им ролей.</p>
        </div>
        <span class="section-caption">{{ $users->total() }} ПОЛЬЗОВАТЕЛЕЙ</span>
    </section>

    <div x-data>
        <section class="panel users-panel">
            <div class="panel-heading users-heading">
                <div><span class="eyebrow">СПИСОК ДОСТУПА</span><h2>Участники рабочей области</h2></div>
                <div class="users-heading-actions">
                    <span class="directory-count">Всего: {{ $users->total() }}</span>
                    <button class="button button-primary users-add-button" type="button" @click="$refs.createUserModal.showModal()">
                        Добавить пользователя
                    </button>
                </div>
            </div>
        @if ($users->isEmpty())
            <div class="empty-state">
                <span class="empty-state-icon">◎</span>
                <strong>Пока нет учётных записей</strong>
                <p>Публичная регистрация отключена; дополнительных учётных записей пока нет.</p>
            </div>
        @else
            <div class="table-scroll">
                <table class="user-table">
                    <thead><tr><th>УЧАСТНИК</th><th>РОЛЬ</th><th>ДАТА РЕГИСТРАЦИИ</th><th>СТАТУС</th></tr></thead>
                    <tbody>
                        @foreach ($users as $member)
                            <tr>
                                <td>
                                    <span class="member-cell">
                                        <span class="avatar avatar-table">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($member->name, 0, 1)) }}</span>
                                        <span><strong>{{ $member->name }}</strong><small>{{ $member->email }}</small></span>
                                    </span>
                                </td>
                                <td><span class="role-badge role-{{ $member->role->value }}">{{ $member->role->label() }}</span></td>
                                <td class="joined-cell">{{ $member->created_at->locale(app()->getLocale())->isoFormat('D MMM YYYY') }}</td>
                                <td><span class="member-status"><i></i> АКТИВЕН</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pagination-row">{{ $users->links() }}</div>
        @endif
        </section>

        <dialog
            class="modal-dialog"
            x-ref="createUserModal"
            @click.self="$el.close()"
            @if ($errors->any()) x-init="$nextTick(() => $el.showModal())" @endif
            aria-labelledby="create-user-title"
        >
            <div class="modal-content">
                <header class="modal-header">
                    <div>
                        <span class="eyebrow">УПРАВЛЕНИЕ ДОСТУПОМ</span>
                        <h2 id="create-user-title">Новый пользователь</h2>
                        <p>Добавьте участника и выберите уровень доступа.</p>
                    </div>
                    <button class="modal-close" type="button" aria-label="Закрыть" @click="$refs.createUserModal.close()">×</button>
                </header>
                <form class="modal-form" method="POST" action="{{ route('admin.users.store') }}">
                    @csrf
                    <div class="field">
                        <label for="name">Имя</label>
                        <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required autofocus>
                        @error('name') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="email">Электронная почта</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
                        @error('email') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="role">Роль</label>
                        <select id="role" name="role" required>
                            @foreach (\App\Enums\UserRole::cases() as $role)
                                <option value="{{ $role->value }}" @selected(old('role', \App\Enums\UserRole::User->value) === $role->value)>{{ $role->label() }}</option>
                            @endforeach
                        </select>
                        @error('role') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="modal-footer">
                        <p>Случайный временный пароль будет показан после создания. При первом входе пользователь задаст новый.</p>
                        <div class="modal-actions">
                            <button class="button button-secondary" type="button" @click="$refs.createUserModal.close()">Отмена</button>
                            <button class="button button-primary" type="submit">Создать пользователя</button>
                        </div>
                    </div>
                </form>
            </div>
        </dialog>
    </div>

    @if (session('new_user_credentials'))
        <section class="credential-notice" role="status">
            <div>
                <strong>Учётная запись создана</strong>
                <p>Передайте временный пароль пользователю безопасным способом. При первом входе он должен будет задать новый пароль.</p>
            </div>
            <dl>
                <div><dt>Пользователь</dt><dd>{{ session('new_user_credentials.name') }} · {{ session('new_user_credentials.email') }}</dd></div>
                <div><dt>Временный пароль</dt><dd><code>{{ session('new_user_credentials.password') }}</code></dd></div>
            </dl>
        </section>
    @endif

    <section class="panel admin-create-panel">
        <div class="panel-heading">
            <div><span class="eyebrow">УПРАВЛЕНИЕ ДОСТУПОМ</span><h2>Добавить пользователя</h2></div>
        </div>
        <form class="admin-create-form" method="POST" action="{{ route('admin.users.store') }}">
            @csrf
            <div class="admin-create-grid">
                <div class="field">
                    <label for="name">Имя</label>
                    <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="email">Электронная почта</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
                    @error('email') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="role">Роль</label>
                    <select id="role" name="role" required>
                        @foreach (\App\Enums\UserRole::cases() as $role)
                            <option value="{{ $role->value }}" @selected(old('role', \App\Enums\UserRole::User->value) === $role->value)>{{ $role->label() }}</option>
                        @endforeach
                    </select>
                    @error('role') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="admin-create-actions">
                <p>Система создаст временный пароль; при первом входе пользователь обязан его сменить.</p>
                <button class="button button-primary" type="submit">Создать учётную запись</button>
            </div>
        </form>
    </section>
</x-app-layout>
