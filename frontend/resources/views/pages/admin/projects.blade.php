<x-app-layout title="Проекты NEXUS AI">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> АДМИНИСТРИРОВАНИЕ · NEXUS AI</div>
            <h1>Управление <span>проектами</span></h1>
            <p class="welcome-copy">Проекты и ключи управляются через серверный API-клиент. Секреты NEXUS AI не передаются в браузер.</p>
        </div>
    </section>

    @if (session('status'))
        <div class="notice notice-success" role="status">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="admin-note project-alert-error" role="alert"><span aria-hidden="true">!</span><p>{{ session('error') }}</p></div>
    @endif
    @if ($error)
        <div class="admin-note project-alert-error" role="alert"><span aria-hidden="true">!</span><p>{{ $error }}</p></div>
    @endif

    <section class="panel project-create-panel">
        <div class="panel-heading">
            <div><span class="eyebrow">НОВЫЙ ПРОЕКТ</span><h2>Создать в NEXUS AI и назначить пользователю</h2></div>
        </div>
        <form class="project-form project-create-form" method="POST" action="{{ route('admin.projects.store') }}">
            @csrf
            <div class="field">
                <label for="owner_user_id">Пользователь</label>
                <select id="owner_user_id" name="owner_user_id" required>
                    <option value="">Выберите пользователя</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected((string) old('owner_user_id') === (string) $user->id)>
                            {{ $user->name }} · {{ $user->email }} · {{ $user->role->label() }}
                        </option>
                    @endforeach
                </select>
                @error('owner_user_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="name">Название</label>
                <input id="name" name="name" value="{{ old('name') }}" minlength="2" maxlength="200" required>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="description">Описание</label>
                <textarea id="description" name="description" rows="2" maxlength="10000">{{ old('description') }}</textarea>
                @error('description') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="metadata">Метаданные (JSON-объект)</label>
                <textarea id="metadata" name="metadata" rows="3" placeholder="{}">{{ old('metadata', '{}') }}</textarea>
                @error('metadata') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <p class="card-caption">После создания Laravel выпустит отдельный project API key через серверный admin endpoint и сохранит его зашифрованным.</p>
            <button class="button button-primary" type="submit">Создать проект</button>
        </form>
    </section>

    <section class="section-heading">
        <div><span class="eyebrow">NEXUS AI · GET /v1/projects</span><h2>Все проекты сервиса</h2></div>
        @if (! $error)
            <span class="directory-count">{{ count($nexusProjects) }} проектов</span>
        @endif
    </section>

    @if ($nexusProjects === [] && ! $error)
        <section class="panel empty-state">
            <span class="empty-state-icon" aria-hidden="true">◎</span>
            <strong>Проектов пока нет</strong>
            <p>Создайте первый проект — после этого назначьте его пользователю.</p>
        </section>
    @elseif ($nexusProjects !== [])
        <div class="project-admin-list">
            @foreach ($nexusProjects as $nexusProject)
                @php
                    $assignment = $assignments->get($nexusProject->id);
                    $metadataJson = $nexusProject->metadata === []
                        ? '{}'
                        : json_encode($nexusProject->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                @endphp
                <article class="panel project-admin-card">
                    <div class="project-admin-heading">
                        <div>
                            <span class="card-overline">NEXUS PROJECT</span>
                            <h3>{{ $nexusProject->name }}</h3>
                            <code class="project-id">{{ $nexusProject->id }}</code>
                        </div>
                        @if ($assignment?->owner)
                            <span class="role-badge role-{{ $assignment->owner->role->value }}">{{ $assignment->owner->role->label() }}</span>
                        @else
                            <span class="directory-count">Не назначен</span>
                        @endif
                    </div>

                    <p class="project-description">{{ $nexusProject->description ?: 'Описание не задано.' }}</p>

                    <div class="project-assignment">
                        @if ($assignment?->owner_user_id !== null)
                            <div class="assignment-owner">
                                <span class="card-overline">ДОСТУП В LARAVEL</span>
                                <strong>{{ $assignment->owner->name }}</strong>
                                <small>{{ $assignment->owner->email }}</small>
                            </div>
                            <form class="project-inline-form" method="POST" action="{{ route('admin.projects.assignment', $nexusProject->id) }}">
                                @csrf
                                @method('PUT')
                                <label class="sr-only" for="owner-{{ $loop->index }}">Назначенный пользователь</label>
                                <select id="owner-{{ $loop->index }}" name="owner_user_id">
                                    <option value="">Снять назначение</option>
                                    @foreach ($users as $user)
                                        <option value="{{ $user->id }}" @selected($assignment->owner_user_id === $user->id)>
                                            {{ $user->name }} · {{ $user->email }}
                                        </option>
                                    @endforeach
                                </select>
                                <button class="button button-secondary" type="submit">Обновить доступ</button>
                            </form>
                        @else
                            <div class="assignment-owner">
                                <span class="card-overline">ДОСТУП В LARAVEL</span>
                                <strong>Пользователи не имеют доступа</strong>
                                <small>Назначьте проект, чтобы создать или восстановить project API key.</small>
                            </div>
                            <form class="project-inline-form" method="POST" action="{{ route('admin.projects.link', $nexusProject->id) }}">
                                @csrf
                                <label class="sr-only" for="link-owner-{{ $loop->index }}">Пользователь для назначения</label>
                                <select id="link-owner-{{ $loop->index }}" name="owner_user_id" required>
                                    <option value="">Выберите пользователя</option>
                                    @foreach ($users as $user)
                                        <option value="{{ $user->id }}">{{ $user->name }} · {{ $user->email }}</option>
                                    @endforeach
                                </select>
                                <button class="button button-primary" type="submit">
                                    {{ $assignment && ! $assignment->api_key ? 'Назначить и выпустить ключ' : 'Назначить и создать ключ' }}
                                </button>
                            </form>
                        @endif
                    </div>

                    @if ($assignment?->pending_revoke_key_id)
                        <div class="admin-note project-alert-error" role="alert">
                            <span aria-hidden="true">!</span>
                            <p>Новый ключ уже используется; отзыв предыдущего ключа ещё не подтверждён.</p>
                            <form method="POST" action="{{ route('admin.projects.keys.retry-revoke', $nexusProject->id) }}">
                                @csrf
                                <button class="button button-secondary" type="submit">Повторить отзыв старого ключа</button>
                            </form>
                        </div>
                    @endif

                    @if ($assignment?->owner_user_id !== null)
                        <div class="project-key-actions">
                            <span class="card-caption">
                                @if ($assignment->api_key)
                                    Project key сохранён зашифрованным · секрет не отображается
                                @else
                                    Ключ отозван или ещё не выпущен; чат недоступен
                                @endif
                            </span>
                            <div class="project-action-buttons">
                                <form method="POST" action="{{ route('admin.projects.keys.rotate', $nexusProject->id) }}">
                                    @csrf
                                    <button class="button button-secondary" type="submit" @disabled($assignment->pending_revoke_key_id)>
                                        {{ $assignment->api_key ? 'Ротировать ключ' : 'Выпустить ключ' }}
                                    </button>
                                </form>
                                @if ($assignment->api_key)
                                    <form method="POST" action="{{ route('admin.projects.keys.revoke', $nexusProject->id) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button button-danger" type="submit" @disabled($assignment->pending_revoke_key_id)>Отозвать ключ</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endif

                    <section class="external-key-section" aria-labelledby="external-keys-title-{{ $loop->index }}">
                        <div class="panel-heading">
                            <div>
                                <span class="eyebrow">ВНЕШНИЕ ПРИЛОЖЕНИЯ</span>
                                <h4 id="external-keys-title-{{ $loop->index }}">Project API keys</h4>
                            </div>
                        </div>
                        <div class="admin-note" role="note">
                            <p>Выпуск новых ключей временно отключён: до настройки одобренной серверной интеграции передачи секретов ключ нельзя безопасно доставить внешнему приложению. Секреты не отображаются в браузере.</p>
                        </div>

                        @if (isset($keyErrors[$nexusProject->id]))
                            <div class="admin-note project-alert-error" role="alert"><span aria-hidden="true">!</span><p>{{ $keyErrors[$nexusProject->id] }}</p></div>
                        @elseif (($keysByProject[$nexusProject->id] ?? []) === [])
                            <section class="empty-state">
                                <strong>Внешние ключи не найдены</strong>
                                <p>Этот список не включает ключ Laravel для внутреннего чата.</p>
                            </section>
                        @else
                            <div class="table-scroll">
                                <table class="user-table">
                                    <thead>
                                        <tr>
                                            <th>ПРИЛОЖЕНИЕ</th>
                                            <th>МАСКИРОВАННЫЙ ПРЕФИКС</th>
                                            <th>ЭКСПЕРТЫ</th>
                                            <th>СРОК</th>
                                            <th>ВЫДАЧА</th>
                                            <th>ПОДКЛЮЧЕНИЕ</th>
                                            <th>ДЕЙСТВИЕ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($keysByProject[$nexusProject->id] as $externalKey)
                                            @php
                                                $keyStatus = $externalKey->status();
                                                $keyStatusLabel = ['active' => 'Активен', 'revoked' => 'Отозван', 'expired' => 'Истёк'][$keyStatus];
                                            @endphp
                                            <tr>
                                                <td>{{ $externalKey->name }}</td>
                                                <td><code>{{ $externalKey->keyPrefix }}…</code></td>
                                                <td>{{ $externalKey->allowedExperts === null ? 'Все эксперты' : implode(', ', $externalKey->allowedExperts) }}</td>
                                                <td>
                                                    @if ($externalKey->expiresAt)
                                                        <time datetime="{{ $externalKey->expiresAt }}">{{ \Illuminate\Support\Carbon::parse($externalKey->expiresAt)->timezone(config('app.timezone'))->format('d.m.Y H:i T') }}</time>
                                                    @else
                                                        Без срока
                                                    @endif
                                                </td>
                                                <td>
                                                    <strong>{{ $keyStatusLabel }}</strong>
                                                    @if ($externalKey->createdAt)
                                                        <small><time datetime="{{ $externalKey->createdAt }}">{{ \Illuminate\Support\Carbon::parse($externalKey->createdAt)->timezone(config('app.timezone'))->format('d.m.Y H:i T') }}</time></small>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if ($externalKey->lastUsedAt)
                                                        Подключался <time datetime="{{ $externalKey->lastUsedAt }}">{{ \Illuminate\Support\Carbon::parse($externalKey->lastUsedAt)->timezone(config('app.timezone'))->format('d.m.Y H:i T') }}</time>
                                                    @else
                                                        Ещё не подключался
                                                    @endif
                                                </td>
                                                <td>
                                                    @if ($externalKey->active)
                                                        <details>
                                                            <summary>Отозвать</summary>
                                                            <form method="POST" action="{{ route('admin.projects.application-keys.revoke', [$nexusProject->id, $externalKey->id]) }}">
                                                                @csrf
                                                                @method('DELETE')
                                                                <div class="field">
                                                                    <label for="confirm-app-key-{{ $loop->parent->index }}-{{ $loop->index }}">Введите точное имя приложения: <strong>{{ $externalKey->name }}</strong></label>
                                                                    <input id="confirm-app-key-{{ $loop->parent->index }}-{{ $loop->index }}" name="confirmation" autocomplete="off" required>
                                                                </div>
                                                                <button class="button button-danger" type="submit">Подтвердить отзыв</button>
                                                            </form>
                                                        </details>
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </section>

                    <details class="project-edit-details">
                        <summary>Редактировать данные проекта</summary>
                        <form class="project-form" method="POST" action="{{ route('admin.projects.update', $nexusProject->id) }}">
                            @csrf
                            @method('PATCH')
                            <div class="field">
                                <label for="edit-name-{{ $loop->index }}">Название</label>
                                <input id="edit-name-{{ $loop->index }}" name="name" value="{{ $nexusProject->name }}" minlength="2" maxlength="200" required>
                            </div>
                            <div class="field">
                                <label for="edit-description-{{ $loop->index }}">Описание</label>
                                <textarea id="edit-description-{{ $loop->index }}" name="description" rows="2" maxlength="10000">{{ $nexusProject->description }}</textarea>
                            </div>
                            <div class="field">
                                <label for="edit-metadata-{{ $loop->index }}">Метаданные (JSON-объект)</label>
                                <textarea id="edit-metadata-{{ $loop->index }}" name="metadata" rows="4" required>{{ $metadataJson }}</textarea>
                            </div>
                            <button class="button button-secondary" type="submit">Сохранить изменения</button>
                        </form>
                    </details>

                    <div class="project-delete-actions" x-data>
                        <button class="button button-danger" type="button" @click="$refs.deleteDialog.showModal()">Удалить проект NEXUS</button>
                        <dialog class="modal-dialog project-delete-dialog" x-ref="deleteDialog" aria-labelledby="delete-title-{{ $loop->index }}">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <div>
                                        <span class="eyebrow">НЕОБРАТИМОЕ ДЕЙСТВИЕ</span>
                                        <h2 id="delete-title-{{ $loop->index }}">Удалить проект?</h2>
                                    </div>
                                    <button class="modal-close" type="button" aria-label="Закрыть" @click="$refs.deleteDialog.close()">×</button>
                                </div>
                                <div class="project-delete-warning" role="alert">
                                    <strong>ВНИМАНИЕ: удаление в NEXUS AI каскадно удалит все документы, разговоры, решения, задачи и связанные данные этого проекта.</strong>
                                    <span>Это действие нельзя отменить. Laravel удалит локальное назначение только после успешного ответа NEXUS AI.</span>
                                </div>
                                <form class="project-form" method="POST" action="{{ route('admin.projects.destroy', $nexusProject->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <div class="field">
                                        <label for="confirm-name-{{ $loop->index }}">Введите точное имя проекта: <strong>{{ $nexusProject->name }}</strong></label>
                                        <input id="confirm-name-{{ $loop->index }}" name="confirmation" autocomplete="off" required>
                                    </div>
                                    <div class="modal-actions">
                                        <button class="button button-secondary" type="button" @click="$refs.deleteDialog.close()">Отмена</button>
                                        <button class="button button-danger" type="submit">Удалить проект и все его данные</button>
                                    </div>
                                </form>
                            </div>
                        </dialog>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if ($error && $assignments->isNotEmpty())
        <section class="section-heading">
            <div><span class="eyebrow">СОХРАНЁННЫЕ НАЗНАЧЕНИЯ</span><h2>Доступы пользователей</h2></div>
        </section>
        <section class="panel">
            <div class="table-scroll">
                <table class="user-table">
                    <thead><tr><th>ПРОЕКТ</th><th>ПОЛЬЗОВАТЕЛЬ</th><th>PROJECT KEY</th></tr></thead>
                    <tbody>
                        @foreach ($assignments as $assignment)
                            <tr>
                                <td>{{ $assignment->name }}</td>
                                <td>{{ $assignment->owner?->name ?? 'Не назначен' }}</td>
                                <td>{{ $assignment->api_key ? 'Зашифрован в БД' : 'Не активен' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-app-layout>
