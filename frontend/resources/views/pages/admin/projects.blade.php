<x-app-layout title="Проекты NEXUS AI">
    <section class="welcome-row page-title-row">
        <div>
            @if ($selectedProject)
                <a class="project-back-link" href="{{ route('admin.projects') }}">← Все проекты</a>
                <div class="eyebrow"><span class="eyebrow-line"></span> ПРОЕКТ · NEXUS AI</div>
                <h1>{{ $selectedProject->name }}</h1>
                <p class="welcome-copy">Настройки проекта, доступ пользователей и ключи интеграций.</p>
            @else
                <div class="eyebrow"><span class="eyebrow-line"></span> АДМИНИСТРИРОВАНИЕ · NEXUS AI</div>
                <h1>Управление <span>проектами</span></h1>
                <p class="welcome-copy">Выберите проект, чтобы управлять доступом, ключами и данными. Секрет внешнего ключа показывается один раз после выпуска.</p>
            @endif
        </div>
    </section>

    @if (session('status'))
        <div class="notice notice-success" role="status">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div class="admin-note project-alert-error" role="alert"><span aria-hidden="true">!</span><p>{{ session('error') }}</p></div>
    @endif
    @if ($error)
        <div class="admin-note project-alert-error" role="alert"><span aria-hidden="true">!</span><p>{{ $error }}</p></div>
    @endif

    @if ($issuedApplicationKey)
        <section class="one-time-key-panel" aria-labelledby="issued-key-title" aria-describedby="issued-key-warning">
            <div class="one-time-key-heading">
                <span class="one-time-key-icon" aria-hidden="true">✓</span>
                <div>
                    <span class="eyebrow">КЛЮЧ СОЗДАН · ПОКАЗАН ОДИН РАЗ</span>
                    <h2 id="issued-key-title">{{ $issuedApplicationName }}</h2>
                    <p>Проект ID: <code>{{ $issuedProjectId }}</code></p>
                </div>
            </div>
            <p id="issued-key-warning" class="one-time-key-warning">
                Скопируйте ключ и сохраните его в настройках секретов внешнего приложения сейчас. После ухода со страницы он больше не будет доступен; для повторного доступа потребуется отозвать его и выпустить новый.
            </p>
            <div class="one-time-key-copy" x-data="{ copied: false, message: 'Ключ доступен только сейчас. Сохраните его в секретах вашего приложения.' , async copyKey() { try { await navigator.clipboard.writeText(this.$refs.key.value); this.copied = true; this.message = 'Ключ скопирован. Сразу сохраните его в секретах приложения.'; } catch { this.$refs.key.select(); this.copied = false; this.message = 'Автокопирование недоступно: ключ выделен. Скопируйте его вручную и сохраните в секретах приложения.'; } } }">
                <label class="sr-only" for="issued-application-key">Одноразовый API-ключ приложения</label>
                <input id="issued-application-key" x-ref="key" type="text" value="{{ $issuedApplicationKey }}" readonly autocomplete="off" autocapitalize="off" spellcheck="false">
                <button class="button button-primary" type="button" @click="copyKey()">Копировать ключ</button>
                <p class="field-hint" role="status" aria-live="polite" x-text="message"></p>
            </div>
            @if ($issuedSecretReference)
                <p class="one-time-key-reference">Дополнительно сохранён в AWS Secrets Manager: <code>{{ $issuedSecretReference }}</code></p>
            @else
                <p class="one-time-key-reference">AWS-доставка не включена. Настройте хранение скопированного ключа в защищённой конфигурации приложения.</p>
            @endif
        </section>
    @endif

    @if (! $selectedProject)
    <section class="panel project-create-panel">
        <div class="panel-heading">
            <div><span class="eyebrow">НОВЫЙ ПРОЕКТ</span><h2>Создать проект</h2></div>
        </div>
        <details class="project-create-disclosure" @if ($errors->hasAny(['owner_user_id', 'name', 'description', 'metadata'])) open @endif>
            <summary>Создать проект и подключить пользователя к чату</summary>
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
            <p class="card-caption">При создании Laravel автоматически выпустит внутренний ключ чата и сохранит его зашифрованным. Он не предназначен для передачи пользователю или стороннему приложению.</p>
            <button class="button button-primary" type="submit">Создать проект и подключить чат</button>
        </form>
        </details>
    </section>
    @endif

    <section class="section-heading">
        <div>
            @if ($selectedProject)
                <span class="eyebrow">PROJECT ID</span><h2>{{ $selectedProject->id }}</h2>
            @else
                <span class="eyebrow">NEXUS AI · GET /v1/projects</span><h2>Все проекты сервиса</h2>
            @endif
        </div>
        @if (! $error && ! $selectedProject)
            <span class="directory-count">Проектов: {{ count($nexusProjects) }}</span>
        @endif
    </section>

    @if ($nexusProjects === [] && ! $error)
        <section class="panel empty-state">
            <span class="empty-state-icon" aria-hidden="true">◎</span>
            <strong>Проектов пока нет</strong>
            <p>Создайте первый проект, затем выберите его для настройки доступа и интеграций.</p>
        </section>
    @elseif ($nexusProjects !== [])
        <div class="project-admin-list">
            @foreach ($nexusProjects as $nexusProject)
                @php
                    $assignment = $assignments->get($nexusProject->id);
                    $showAssignmentErrors = old('assignment_project_id') === $nexusProject->id;
                    $metadataJson = $nexusProject->metadata === []
                        ? '{}'
                        : json_encode($nexusProject->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                @endphp
                @if (! $selectedProject)
                <article class="panel project-directory-card">
                    <div class="project-directory-main">
                        <div>
                            <span class="card-overline">NEXUS PROJECT</span>
                            <h3>{{ $nexusProject->name }}</h3>
                            <p class="project-description">{{ $nexusProject->description ?: 'Описание не задано.' }}</p>
                            <code class="project-id">{{ $nexusProject->id }}</code>
                        </div>
                        <div class="project-directory-access">
                            @if ($assignment?->owner)
                                <span class="role-badge role-{{ $assignment->owner->role->value }}">{{ $assignment->owner->role->label() }}</span>
                                <strong>{{ $assignment->owner->name }}</strong>
                                <small>{{ $assignment->owner->email }}</small>
                            @else
                                <span class="directory-count">Не назначен</span>
                                <small>Доступ к чату не настроен</small>
                            @endif
                        </div>
                    </div>
                    <a class="button button-primary project-open-button" href="{{ route('admin.projects.show', $nexusProject->id) }}">
                        Открыть настройки <span aria-hidden="true">→</span>
                    </a>
                </article>
                @else
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

                    <div class="project-assignment" x-data>
                        <div class="assignment-owner">
                            <span class="card-overline">ДОСТУП СОТРУДНИКА К ЧАТУ</span>
                            @if ($assignment?->owner)
                                <strong>{{ $assignment->owner->name }}</strong>
                                <small>{{ $assignment->owner->email }} · {{ $assignment->owner->role->label() }}</small>
                            @else
                                <strong>Нет назначенного пользователя</strong>
                                <small>Без назначения пользователи Laravel не имеют доступа к проекту.</small>
                            @endif
                        </div>
                        <button
                            class="button button-secondary assignment-open-button"
                            type="button"
                            @click="$refs.assignmentDialog.showModal()"
                            aria-haspopup="dialog"
                            aria-controls="assignment-dialog-{{ $loop->index }}"
                        >
                            {{ $assignment?->owner_user_id !== null ? 'Изменить доступ' : 'Назначить пользователя и подключить чат' }}
                        </button>

                        <dialog
                            id="assignment-dialog-{{ $loop->index }}"
                            class="modal-dialog"
                            x-ref="assignmentDialog"
                            @click.self="$el.close()"
                            @if ($showAssignmentErrors) x-init="$nextTick(() => $el.showModal())" @endif
                            aria-labelledby="assignment-title-{{ $loop->index }}"
                            aria-describedby="assignment-description-{{ $loop->index }}"
                        >
                            <div class="modal-content">
                                <header class="modal-header">
                                    <div>
                                        <span class="eyebrow">ДОСТУП К ПРОЕКТУ</span>
                                        <h2 id="assignment-title-{{ $loop->index }}">
                                            {{ $assignment?->owner_user_id !== null ? 'Изменить назначение' : 'Назначить пользователя' }}
                                        </h2>
                                        <p id="assignment-description-{{ $loop->index }}">
                                            {{ $nexusProject->name }} · доступ контролируется ролью пользователя в Laravel.
                                        </p>
                                    </div>
                                    <button class="modal-close" type="button" aria-label="Закрыть окно назначения" @click="$refs.assignmentDialog.close()">×</button>
                                </header>
                                @if ($assignment?->owner_user_id !== null)
                                    <form class="modal-form" method="POST" action="{{ route('admin.projects.assignment', $nexusProject->id) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="assignment_project_id" value="{{ $nexusProject->id }}">
                                        <div class="field">
                                            <label for="owner-{{ $loop->index }}">Назначенный пользователь</label>
                                            <select id="owner-{{ $loop->index }}" name="owner_user_id" autofocus>
                                                <option value="">Снять назначение</option>
                                                @foreach ($users as $user)
                                                    <option value="{{ $user->id }}" @selected(($showAssignmentErrors ? old('owner_user_id') : $assignment->owner_user_id) == $user->id)>
                                                        {{ $user->name }} · {{ $user->email }} · {{ $user->role->label() }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @if ($showAssignmentErrors)
                                                @error('owner_user_id') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                                            @endif
                                        </div>
                                        <footer class="modal-footer">
                                            <p>При снятии назначения пользователь потеряет доступ к этому проекту. Сам проект и его данные в NEXUS AI останутся без изменений.</p>
                                            <div class="modal-actions">
                                                <button class="button button-secondary" type="button" @click="$refs.assignmentDialog.close()">Отмена</button>
                                                <button class="button button-primary" type="submit">Сохранить назначение</button>
                                            </div>
                                        </footer>
                                    </form>
                                @else
                                    <form class="modal-form" method="POST" action="{{ route('admin.projects.link', $nexusProject->id) }}">
                                        @csrf
                                        <input type="hidden" name="assignment_project_id" value="{{ $nexusProject->id }}">
                                        <div class="field">
                                            <label for="link-owner-{{ $loop->index }}">Пользователь</label>
                                            <select id="link-owner-{{ $loop->index }}" name="owner_user_id" required autofocus>
                                                <option value="">Выберите пользователя</option>
                                                @foreach ($users as $user)
                                                    <option value="{{ $user->id }}" @selected($showAssignmentErrors && (string) old('owner_user_id') === (string) $user->id)>{{ $user->name }} · {{ $user->email }} · {{ $user->role->label() }}</option>
                                                @endforeach
                                            </select>
                                            @if ($showAssignmentErrors)
                                                @error('owner_user_id') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                                            @endif
                                        </div>
                                        <footer class="modal-footer">
                                            <p>При назначении Laravel создаст project API key на сервере и сохранит его зашифрованным. Сам ключ не показывается в интерфейсе.</p>
                                            <div class="modal-actions">
                                                <button class="button button-secondary" type="button" @click="$refs.assignmentDialog.close()">Отмена</button>
                                                <button class="button button-primary" type="submit">Назначить и подключить чат</button>
                                            </div>
                                        </footer>
                                    </form>
                                @endif
                            </div>
                        </dialog>
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
                                    Внутренний ключ чата активен · хранится зашифрованно, используется Laravel
                                @else
                                    Внутренний ключ чата не активен · чат пользователю недоступен
                                @endif
                            </span>
                            <div class="project-action-buttons">
                                <form method="POST" action="{{ route('admin.projects.keys.rotate', $nexusProject->id) }}">
                                    @csrf
                                    <button class="button button-secondary" type="submit" @disabled($assignment->pending_revoke_key_id)>
                                        {{ $assignment->api_key ? 'Заменить ключ чата' : 'Выпустить ключ чата' }}
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
                                <span class="eyebrow">ВНЕШНИЕ ИНТЕГРАЦИИ</span>
                                <h4 id="external-keys-title-{{ $loop->index }}">API-ключи внешних приложений</h4>
                            </div>
                        </div>
                        <p class="project-description">
                            Это отдельные ключи для серверных интеграций — они не нужны для чата Laravel. Каждый ключ ограничен этим проектом; задайте советников, лимит и срок до выпуска. Права уже выданного ключа изменить нельзя: при необходимости отзовите его и создайте новый.
                        </p>
                        <h5 class="external-key-form-title">Выпустить новый ключ приложения</h5>
                        <form class="project-form external-key-create-form" method="POST" action="{{ route('admin.projects.application-keys.store', $nexusProject->id) }}">
                                @csrf
                                <div class="field">
                                    <label for="application-name-{{ $loop->index }}">Название приложения</label>
                                    <input id="application-name-{{ $loop->index }}" name="name" minlength="2" maxlength="100" value="{{ old('name') }}" required>
                                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                                <div class="field">
                                    <span class="field-label">Разрешённые советники</span>
                                    <div class="external-key-expert-options" role="group" aria-describedby="application-experts-hint-{{ $loop->index }}">
                                        @foreach ($expertNames as $expertKey => $expertName)
                                            <label class="external-key-expert-option" for="application-expert-{{ $loop->parent->index }}-{{ $expertKey }}">
                                                <input id="application-expert-{{ $loop->parent->index }}-{{ $expertKey }}" type="checkbox" name="allowed_experts[]" value="{{ $expertKey }}" @checked(in_array($expertKey, is_array(old('allowed_experts')) ? old('allowed_experts') : [], true))>
                                                <span>{{ $expertName }} <small>{{ $expertKey }}</small></span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('allowed_experts') <p class="field-error">{{ $message }}</p> @enderror
                                    @foreach ($errors->get('allowed_experts.*') as $expertErrors)
                                        @foreach ($expertErrors as $message)
                                            <p class="field-error">{{ $message }}</p>
                                        @endforeach
                                    @endforeach
                                    <small class="field-hint" id="application-experts-hint-{{ $loop->index }}">Без выбора — все советники, в том числе добавленные позднее. Отметьте нужные пункты, чтобы ограничить доступ.</small>
                                </div>
                                <div class="field">
                                    <label for="application-rate-{{ $loop->index }}">Лимит запросов в минуту</label>
                                    <input id="application-rate-{{ $loop->index }}" name="rate_limit_per_min" type="number" min="1" max="10000" value="{{ old('rate_limit_per_min', 60) }}" required>
                                    @error('rate_limit_per_min') <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                                <fieldset class="external-key-period">
                                    <legend>Срок действия (необязательно)</legend>
                                    <div class="external-key-period-fields">
                                        <div class="field">
                                            <label for="application-starts-{{ $loop->index }}">С</label>
                                            <input id="application-starts-{{ $loop->index }}" name="starts_at" type="datetime-local" value="{{ old('starts_at') }}">
                                            @error('starts_at') <p class="field-error">{{ $message }}</p> @enderror
                                        </div>
                                        <div class="field">
                                            <label for="application-expires-{{ $loop->index }}">По</label>
                                            <input id="application-expires-{{ $loop->index }}" name="expires_at" type="datetime-local" value="{{ old('expires_at') }}">
                                            @error('expires_at') <p class="field-error">{{ $message }}</p> @enderror
                                        </div>
                                    </div>
                                    <small class="field-hint">Пустая дата «С» — действует сразу; пустая дата «По» — без срока окончания.</small>
                                </fieldset>
                                @if ($expertCatalogError)
                                    <p class="field-hint" role="status">Каталог советников недоступен. Дождитесь его восстановления, чтобы не выдать ключ с непроверенными ограничениями.</p>
                                @endif
                            <div class="external-key-create-actions">
                                <p class="field-hint">
                                    @if ($keyDeliveryEnabled)
                                        Ключ сохранится в AWS Secrets Manager и будет показан один раз. Скопируйте его в секреты приложения; ARN также появится в результате.
                                    @else
                                        Ключ будет показан один раз после выпуска. Сразу скопируйте его в защищённые настройки внешнего приложения; обновить или открыть повторно нельзя.
                                    @endif
                                </p>
                                <button class="button button-primary" type="submit" @disabled($expertCatalogError)>Выпустить и показать ключ один раз</button>
                            </div>
                        </form>
                        @if ($expertCatalogError)
                            <div class="admin-note" role="status">
                                <p>Не удалось загрузить названия советников; ниже показаны их технические ключи.</p>
                            </div>
                        @endif

                        @if (isset($keyErrors[$nexusProject->id]))
                            <div class="admin-note project-alert-error" role="alert"><span aria-hidden="true">!</span><p>{{ $keyErrors[$nexusProject->id] }}</p></div>
                        @elseif (($keysByProject[$nexusProject->id] ?? []) === [])
                            <section class="empty-state">
                                <strong>Ключей внешних приложений пока нет</strong>
                                <p>Заполните форму выше, чтобы создать первый ключ. Внутренний ключ чата Laravel в этом списке не отображается.</p>
                            </section>
                        @else
                            <div class="table-scroll">
                                <table class="user-table">
                                    <thead>
                                        <tr>
                                            <th>ПРИЛОЖЕНИЕ</th>
                                            <th>МАСКИРОВАННЫЙ ПРЕФИКС</th>
                                            <th>ДОСТУП К СОВЕТНИКАМ</th>
                                            <th>ЛИМИТ</th>
                                            <th>СРОК</th>
                                            <th>ВЫДАЧА</th>
                                            <th>СЕКРЕТ ПРИЛОЖЕНИЯ</th>
                                            <th>ПОДКЛЮЧЕНИЕ</th>
                                            <th>ДЕЙСТВИЕ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($keysByProject[$nexusProject->id] as $externalKey)
                                            @php
                                                $keyStatus = $externalKey->status();
                                                $keyStatusLabel = ['active' => 'Активен', 'scheduled' => 'Запланирован', 'revoked' => 'Отозван', 'expired' => 'Истёк'][$keyStatus];
                                            @endphp
                                            <tr>
                                                <td>{{ $externalKey->name }}</td>
                                                <td><code>{{ $externalKey->keyPrefix }}…</code></td>
                                                <td>
                                                    @if ($externalKey->allowedExperts === null)
                                                        Все советники проекта
                                                    @elseif ($externalKey->allowedExperts === [])
                                                        Нет разрешённых советников
                                                    @else
                                                        {{ collect($externalKey->allowedExperts)->map(fn (string $expertKey): string => $expertNames[$expertKey] ?? $expertKey)->implode(', ') }}
                                                    @endif
                                                </td>
                                                <td>{{ number_format($externalKey->rateLimitPerMinute) }} запросов/мин</td>
                                                <td>
                                                    @if ($externalKey->startsAt)
                                                        <span>С <time datetime="{{ $externalKey->startsAt }}">{{ \Illuminate\Support\Carbon::parse($externalKey->startsAt)->timezone(config('app.timezone'))->format('d.m.Y H:i T') }}</time></span>
                                                    @endif
                                                    @if ($externalKey->expiresAt)
                                                        <span>По <time datetime="{{ $externalKey->expiresAt }}">{{ \Illuminate\Support\Carbon::parse($externalKey->expiresAt)->timezone(config('app.timezone'))->format('d.m.Y H:i T') }}</time></span>
                                                    @else
                                                        <span>По: без срока</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <strong>{{ $keyStatusLabel }}</strong>
                                                    @if ($externalKey->createdAt)
                                                        <small><time datetime="{{ $externalKey->createdAt }}">{{ \Illuminate\Support\Carbon::parse($externalKey->createdAt)->timezone(config('app.timezone'))->format('d.m.Y H:i T') }}</time></small>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if ($applicationSecrets->has($externalKey->id))
                                                        <span>Доставлен в AWS Secrets Manager</span>
                                                        <small><code>{{ $applicationSecrets->get($externalKey->id)->secret_arn }}</code></small>
                                                    @else
                                                        <span>AWS-секрет не связан с этой админкой</span>
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

                    <div class="project-quick-links">
                        <span class="card-overline">БЫСТРЫЕ ССЫЛКИ</span>
                        <div class="project-quick-links-row">
                            <a href="{{ route('admin.documents', ['nexusProjectId' => $nexusProject->id]) }}" class="quick-link-button" title="Документы RAG">
                                <span class="quick-link-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" focusable="false"><path d="M7 3.75h7l4 4v12.5H7a2 2 0 0 1-2-2v-12.5a2 2 0 0 1 2-2Z"/><path d="M14 3.75v4h4M8.5 12h7M8.5 15.5h7"/></svg>
                                </span>
                                <span>Документы</span>
                            </a>
                            <a href="{{ route('admin.conversations', ['nexusProjectId' => $nexusProject->id]) }}" class="quick-link-button" title="История разговоров">
                                <span class="quick-link-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" focusable="false"><path d="M5 5.5A2.5 2.5 0 0 1 7.5 3h10A2.5 2.5 0 0 1 20 5.5v7a2.5 2.5 0 0 1-2.5 2.5h-6l-5 4v-4.5A2.5 2.5 0 0 1 5 12Z"/><path d="M9 8h7M9 11h5"/></svg>
                                </span>
                                <span>Разговоры</span>
                            </a>
                            <a href="{{ route('admin.decisions', ['nexusProjectId' => $nexusProject->id]) }}" class="quick-link-button" title="Решения">
                                <span class="quick-link-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" focusable="false"><path d="M8 4.5h8M9 3h6a1 1 0 0 1 1 1v2H8V4a1 1 0 0 1 1-1Z"/><path d="M7 5.5H5.5A1.5 1.5 0 0 0 4 7v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a1.5 1.5 0 0 0-1.5-1.5H17"/><path d="m8 13 2.2 2.2L16 9.5"/></svg>
                                </span>
                                <span>Решения</span>
                            </a>
                            <a href="{{ route('admin.tasks', ['nexusProjectId' => $nexusProject->id]) }}" class="quick-link-button" title="Задачи">
                                <span class="quick-link-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" focusable="false"><path d="M9 5h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h1"/><path d="M8 3h8v4H8zM7 12h2l1.3 1.5L13 10M15 12h3M7 17h2M15 17h3"/></svg>
                                </span>
                                <span>Задачи</span>
                            </a>
                        </div>
                    </div>

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
                @endif
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
