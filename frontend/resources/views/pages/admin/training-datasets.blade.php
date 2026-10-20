<x-app-layout title="База знаний · Наборы обучения">
    <section class="welcome-row page-title-row">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> ЭКСПЕРТЫ · БАЗА ЗНАНИЙ</div>
            <h1>Наборы <span>обучения</span></h1>
            <p class="welcome-copy">Загружайте Q/A-партии для экспертов, проверяйте ошибки и дубли, утверждайте версии данных.</p>
        </div>
    </section>

    @if (session('status'))
        @php $status = session('status'); @endphp
        @if ($status === 'training_dataset_batch_created')
            <div class="notice notice-success" role="status">Партия загружена и ожидает проверки.</div>
        @elseif ($status === 'training_dataset_batch_reused')
            <div class="notice notice-success" role="status">Такой файл для этого эксперта уже загружен — открыта существующая партия.</div>
        @elseif ($status === 'training_dataset_release_created')
            <div class="notice notice-success" role="status">Снимок датасета собран@isset($release_example_count): {{ $release_example_count }} примеров@endisset. Он неизменяем.</div>
        @elseif ($status === 'training_dataset_example_updated')
            <div class="notice notice-success" role="status">Строка обновлена, счётчики партии пересчитаны.</div>
        @elseif ($status === 'training_dataset_example_deleted')
            <div class="notice notice-success" role="status">Строка удалена из партии, счётчики пересчитаны.</div>
        @elseif ($status === 'training_dataset_batch_approved')
            <div class="notice notice-success" role="status">Партия утверждена. Snapshot сохранён; обучение не запускалось.</div>
        @elseif ($status === 'training_dataset_batch_rejected')
            <div class="notice notice-success" role="status">Партия отклонена.</div>
        @elseif ($status === 'lora_adapter_uploaded')
            <div class="notice notice-success" role="status">Версия LoRA-адаптера загружена со статусом «кандидат».</div>
        @elseif ($status === 'lora_adapter_reused')
            <div class="notice notice-success" role="status">Такой же файл адаптера уже загружен — открыта существующая версия.</div>
        @elseif ($status === 'lora_adapter_published')
            <div class="notice notice-success" role="status">Адаптер v{{ session('lora_adapter_version', '?') }} опубликован. Применение на сервере модели — шаг оператора (перезапуск nexus-model).</div>
        @elseif ($status === 'lora_adapter_rolled_back')
            <div class="notice notice-success" role="status">Адаптер v{{ session('lora_adapter_version', '?') }} снят с публикации.</div>
        @elseif ($status === 'lora_adapter_deleted')
            <div class="notice notice-success" role="status">Запись адаптера удалена из реестра.</div>
        @elseif ($status === 'training_research_drafted')
            <div class="notice notice-success" role="status">Черновики составлены. Проверьте и при необходимости отредактируйте пары ниже.</div>
        @elseif ($status === 'training_research_cancelled')
            <div class="notice notice-success" role="status">Черновики очищены.</div>
        @elseif ($status === 'training_research_accepted')
            <div class="notice notice-success" role="status">Принятые пары утверждены и включены в новый снимок датасета@isset($release_example_count) ({{ $release_example_count }} пар)@endisset. Обучение на GPU запускается отдельно.</div>
        @elseif ($status === 'training_research_already_approved')
            <div class="notice notice-success" role="status">Эти пары уже были утверждены ранее. Снимок датасета обновлён@isset($release_example_count): {{ $release_example_count }} пар@endisset.</div>
        @elseif ($status === 'training_research_saved_for_review')
            <div class="notice notice-success" role="status">Пары сохранены как партия. В ней есть некорректные строки или возможные дубли — проверьте и утвердите её вручную.</div>
        @elseif ($status === 'training_research_approved_no_release')
            <div class="notice notice-success" role="status">Пары утверждены, но новый снимок датасета собрать не удалось. Соберите снимок вручную ниже.</div>
        @endif
    @endif

    @if ($errors->any())
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

    <section class="panel project-create-panel" aria-labelledby="training-upload-title">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">ЗАГРУЗКА · JSONL / CSV</span>
                <h2 id="training-upload-title">Новая партия вопросов и ответов</h2>
            </div>
        </div>
        <p class="welcome-copy">Одна партия может содержать любое количество примеров до 50 000 строк. Максимальный размер файла — 50 МБ.</p>
        <form class="project-form" method="POST" action="{{ route('admin.training-datasets.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="field">
                <label for="trainingExpert">Эксперт</label>
                <select id="trainingExpert" name="expert" required>
                    <option value="">Выберите эксперта</option>
                    @foreach ($experts as $expert)
                        <option value="{{ $expert->key }}" @selected(old('expert', $selectedExpert) === $expert->key)>
                            {{ $expert->name }}{{ $expert->enabled ? '' : ' (отключён)' }}
                        </option>
                    @endforeach
                </select>
                @error('expert') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="trainingFile">Файл набора</label>
                <input id="trainingFile" name="file" type="file" accept=".jsonl,.csv" required>
                <small class="field-hint">JSONL: поля question и answer (или legacy messages с user и assistant); CSV: обязательные столбцы question и answer. Дополнительные поля/столбцы сохраняются в metadata, system prompt — в metadata.system_prompt.</small>
                @error('file') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button class="button button-primary" type="submit" @disabled($experts === [])>Загрузить и проверить</button>
        </form>
        <p class="section-caption" style="margin-top:14px">Утверждение сохраняет snapshot партии. Ниже собирается неизменяемый release всего утверждённого датасета эксперта. Обучение выполняется на внешнем GPU, готовый .gguf загружается в реестр LoRA-адаптеров ниже.</p>
    </section>

    @if (is_array($stats))
        <section class="section-heading">
            <div><span class="eyebrow">ПОКРЫТИЕ ТЕМ · {{ $stats['expert'] }}</span><h2>Прогресс набора данных</h2></div>
            <span class="directory-count">
                {{ $stats['approved_examples'] }}@if (is_int($stats['target'])) / {{ $stats['target'] }}@endif утверждённых пар
            </span>
        </section>

        @if ($stats['topics'] === [])
            <section class="panel empty-state">
                <strong>Список тем не задан</strong>
                <p>Для этого эксперта цели по темам ещё не описаны в конфигурации тренировочного контура.</p>
            </section>
        @else
            <section class="panel">
                <p class="welcome-copy">Покрытие считается по утверждённым парам. Тема определяется по полю <code>topic</code> в метаданных примера, иначе — по ключевым словам вопроса. Цели по темам заданы в конфигурации тренировочного контура.</p>
                @if ($stats['missing_topics'] !== [])
                    <p class="coverage-missing">Не хватает тем: {{ implode(', ', $stats['missing_topics']) }}</p>
                @endif
                <div class="table-scroll" role="region" aria-label="Покрытие тем" tabindex="0">
                    <table class="user-table coverage-table">
                        <thead>
                            <tr><th>Тема</th><th>Прогресс</th><th>Цель</th><th>Утверждено</th><th>Не хватает</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($stats['topics'] as $topic)
                                <tr>
                                    <td>{{ $topic['key'] }}</td>
                                    <td>
                                        <progress class="coverage-bar" value="{{ min($topic['count'], $topic['target']) }}" max="{{ $topic['target'] }}">{{ $topic['count'] }} из {{ $topic['target'] }}</progress>
                                    </td>
                                    <td>{{ $topic['target'] }}</td>
                                    <td class="coverage-count {{ $topic['missing'] === 0 ? 'coverage-count-done' : '' }}">{{ $topic['count'] }}</td>
                                    <td>{{ $topic['missing'] > 0 ? $topic['missing'] : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif

    <section class="section-heading">
        <div><span class="eyebrow">СБОР ЗНАНИЙ · ВЕБ-ПОИСК</span><h2>Черновики из интернета</h2></div>
    </section>

    <p class="welcome-copy">Поиск по запросу находит источники, а эксперт составляет по ним черновики пар «вопрос/ответ». Отметьте пары, которые принимаете: они сразу утверждаются и попадают в новый снимок датасета, по которому обучается эксперт. Если в партии найдутся ошибки или возможные дубли, она останется на ручную проверку. Поиск и модель работают на сервере, ключи в браузер не попадают.</p>

    <form class="project-form" method="POST" action="{{ route('admin.training-research.draft') }}" style="grid-template-columns:repeat(4,minmax(0,1fr));align-items:end">
        @csrf
        <div class="field">
            <label for="researchExpert">Эксперт</label>
            <select id="researchExpert" name="expert" required>
                <option value="">Выберите эксперта</option>
                @foreach ($experts as $expert)
                    <option value="{{ $expert->key }}" @selected(old('expert', $selectedExpert) === $expert->key)>
                        {{ $expert->name }}{{ $expert->enabled ? '' : ' (отключён)' }}
                    </option>
                @endforeach
            </select>
            @error('expert') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="researchQuery">Поисковый запрос</label>
            <input id="researchQuery" name="query" type="text" maxlength="500" required value="{{ old('query') }}" placeholder="Например: кэширование в Laravel">
            @error('query') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="researchCount">Сколько пар</label>
            <select id="researchCount" name="count">
                @foreach ([3, 5, 10] as $option)
                    <option value="{{ $option }}" @selected((int) old('count', 5) === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="researchTopic">Тема (необязательно)</label>
            <select id="researchTopic" name="topic">
                <option value="">— не указана</option>
                @if (is_array($stats))
                    @foreach ($stats['topics'] as $topic)
                        <option value="{{ $topic['key'] }}" @selected(old('topic') === $topic['key'])>
                            {{ $topic['key'] }}{{ $topic['missing'] === 0 ? ' · покрыта' : '' }}
                        </option>
                    @endforeach
                @endif
            </select>
            @error('topic') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <button class="button button-secondary" type="submit" @disabled($experts === [])>Найти и составить черновики</button>
    </form>
    @if (is_array($stats) && $stats['topics'] !== [])
        <p class="field-hint">Список тем показан для эксперта «{{ $stats['expert'] }}». Метка темы повышает точность покрытия — она сохранится в метаданных пары.</p>
    @endif
    @error('research') <p class="field-error" role="alert">{{ $message }}</p> @enderror

    @if (is_array($researchDrafts) && ($researchDrafts['drafts'] ?? []) !== [])
        <section class="section-heading">
            <div>
                <span class="eyebrow">ЧЕРНОВИКИ · {{ count($researchDrafts['drafts']) }}</span>
                <h2>Проверьте пары перед добавлением</h2>
            </div>
            <span class="directory-count">{{ $researchDrafts['expert'] }} · запрос: {{ $researchDrafts['query'] }}</span>
        </section>

        @if (($researchDrafts['sources'] ?? []) !== [])
            <details class="panel" style="margin-bottom:12px">
                <summary>Источники ({{ count($researchDrafts['sources']) }})</summary>
                <ol>
                    @foreach ($researchDrafts['sources'] as $source)
                        <li><a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer">{{ $source['title'] !== '' ? $source['title'] : $source['url'] }}</a></li>
                    @endforeach
                </ol>
            </details>
        @endif

        <form method="POST" action="{{ route('admin.training-research.store') }}">
            @csrf
            <input type="hidden" name="expert" value="{{ $researchDrafts['expert'] }}">
            <div class="table-scroll" role="region" aria-label="Черновики вопросов и ответов" tabindex="0">
                <table class="user-table">
                    <thead>
                        <tr>
                            <th>Принять</th>
                            <th style="min-width:220px">Вопрос</th>
                            <th style="min-width:280px">Ответ</th>
                            <th>Источник</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($researchDrafts['drafts'] as $index => $draft)
                            <tr>
                                <td style="text-align:center">
                                    <input type="checkbox" name="accepted[]" value="{{ $index }}" checked aria-label="Принять пару {{ $index + 1 }}">
                                </td>
                                <td>
                                    <input type="hidden" name="drafts[{{ $index }}][source_url]" value="{{ $draft['source_url'] ?? '' }}">
                                    <textarea name="drafts[{{ $index }}][question]" rows="3" maxlength="10000" required style="width:100%">{{ old("drafts.$index.question", $draft['question']) }}</textarea>
                                </td>
                                <td>
                                    <textarea name="drafts[{{ $index }}][answer]" rows="4" maxlength="100000" required style="width:100%">{{ old("drafts.$index.answer", $draft['answer']) }}</textarea>
                                </td>
                                <td>
                                    @if (($draft['source_url'] ?? '') !== '')
                                        <a href="{{ $draft['source_url'] }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($draft['source_url'], 40) }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @error('drafts') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            @error('accepted') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            <p class="field-hint" style="margin-top:12px">Отмеченные пары утверждаются автоматически и включаются в новый снимок датасета. Снимите отметку с пар, которые не должны попасть в обучение.</p>
            <div class="modal-actions" style="margin-top:12px">
                <button class="button button-primary" type="submit">Принять и включить в обучение</button>
                <button class="button button-danger" type="submit" form="researchCancelForm">Отменить черновики</button>
            </div>
        </form>
        <form id="researchCancelForm" method="POST" action="{{ route('admin.training-research.cancel') }}">
            @csrf
            @method('DELETE')
        </form>
    @endif

    <section class="section-heading">
        <div><span class="eyebrow">NEXUS AI · RELEASE</span><h2>Снимки датасета эксперта</h2></div>
        <span class="directory-count">{{ $releases['total'] }} снимков</span>
    </section>

    <p class="welcome-copy">Release склеивает все утверждённые партии эксперта в один неизменяемый набор с хешем и параметрами подготовки. Изменить созданный снимок нельзя — только собрать новый.</p>

    <form class="project-form" method="POST" action="{{ route('admin.training-datasets.releases.store') }}" style="grid-template-columns:repeat(2,minmax(0,1fr));align-items:end">
        @csrf
        <div class="field">
            <label for="releaseExpert">Эксперт</label>
            <select id="releaseExpert" name="expert" required>
                <option value="">Выберите эксперта</option>
                @foreach ($experts as $expert)
                    <option value="{{ $expert->key }}" @selected(old('expert', $selectedExpert) === $expert->key)>
                        {{ $expert->name }}{{ $expert->enabled ? '' : ' (отключён)' }}
                    </option>
                @endforeach
            </select>
            @error('expert') <p class="field-error">{{ $message }}</p> @enderror
            @error('release') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <button class="button button-secondary" type="submit" @disabled($experts === [])>Собрать release-снимок</button>
    </form>

    @if ($releases['releases'] === [])
        <section class="panel empty-state">
            <span class="empty-state-icon" aria-hidden="true">◌</span>
            <strong>Снимков пока нет</strong>
            <p>Утвердите партии эксперта, затем соберите release-снимок для обучения.</p>
        </section>
    @else
        <div class="table-scroll" role="region" aria-label="Снимки датасета эксперта" tabindex="0">
            <table class="user-table">
                <thead>
                    <tr>
                        <th>Эксперт</th>
                        <th>Примеров</th>
                        <th>Партий в составе</th>
                        <th>Хеш</th>
                        <th>Формат</th>
                        <th>Создано</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($releases['releases'] as $release)
                        <tr>
                            <td>{{ $release['expert'] }}</td>
                            <td>{{ $release['example_count'] }}</td>
                            <td>{{ count($release['batch_ids'] ?? []) }}</td>
                            <td><code>{{ substr((string) $release['sha256'], 0, 12) }}…</code></td>
                            <td>v{{ $release['format_version'] }}</td>
                            <td>{{ $release['created_at'] ? \Illuminate\Support\Carbon::parse($release['created_at'])->format('d.m.Y H:i') : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <section class="section-heading">
        <div><span class="eyebrow">NEXUS AI · LORA REGISTRY</span><h2>Реестр LoRA-адаптеров</h2></div>
        <span class="directory-count">{{ $adapters['total'] }} версий</span>
    </section>

    <p class="welcome-copy">Каждый обученный адаптер загружается как новая версия (.gguf). Публикация делает версию активной для эксперта (ровно одна на эксперта), откат возвращает предыдущую. Применение на сервере модели — перезапуск nexus-model оператором.</p>

    <section class="panel project-create-panel" aria-labelledby="adapter-upload-title">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">ЗАГРУЗКА АРТЕФАКТА · GGUF</span>
                <h2 id="adapter-upload-title">Новая версия адаптера</h2>
            </div>
        </div>
        <form class="project-form" method="POST" action="{{ route('admin.training-adapters.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="field">
                <label for="adapterExpert">Эксперт</label>
                <select id="adapterExpert" name="expert" required>
                    <option value="">Выберите эксперта</option>
                    @foreach ($experts as $expert)
                        <option value="{{ $expert->key }}" @selected(old('expert', $selectedExpert) === $expert->key)>
                            {{ $expert->name }}{{ $expert->enabled ? '' : ' (отключён)' }}
                        </option>
                    @endforeach
                </select>
                @error('expert') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="adapterBaseModel">Базовая модель</label>
                <input id="adapterBaseModel" name="base_model" type="text" maxlength="100" required
                       value="{{ old('base_model', 'Qwen3-4B') }}">
                @error('base_model') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="adapterRelease">Снимок датасета (необязательно)</label>
                <select id="adapterRelease" name="dataset_release_id">
                    <option value="">Не привязывать</option>
                    @foreach ($releases['releases'] as $release)
                        <option value="{{ $release['id'] }}" @selected(old('dataset_release_id') === $release['id'])>
                            {{ $release['expert'] }} · {{ $release['example_count'] }} примеров · {{ $release['created_at'] ? \Carbon\Carbon::parse($release['created_at'])->format('d.m.Y') : '' }}
                        </option>
                    @endforeach
                </select>
                <small class="field-hint">Снимок указывает, на каких данных обучена версия. Принадлежность выбранному эксперту проверяется на сервере.</small>
                @error('dataset_release_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="adapterFile">Файл адаптера</label>
                <input id="adapterFile" name="file" type="file" accept=".gguf" required>
                <small class="field-hint">Только .gguf, до 256 МБ. Одинаковый файл не заводит дубль-версию.</small>
                @error('file') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="adapterNotes">Заметка (необязательно)</label>
                <input id="adapterNotes" name="notes" type="text" maxlength="4000" value="{{ old('notes') }}">
                @error('notes') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button class="button button-primary" type="submit" @disabled($experts === [])>Загрузить версию</button>
        </form>
        @error('adapter') <p class="field-error" role="alert">{{ $message }}</p> @enderror
    </section>

    @if ($adapters['adapters'] === [])
        <section class="panel empty-state">
            <span class="empty-state-icon" aria-hidden="true">◈</span>
            <strong>Адаптеров пока нет</strong>
            <p>Обучите адаптер на внешнем GPU и загрузите .gguf, чтобы он появился в реестре.</p>
        </section>
    @else
        <div class="table-scroll" role="region" aria-label="Реестр LoRA-адаптеров" tabindex="0">
            <table class="user-table">
                <thead>
                    <tr>
                        <th>Эксперт / версия</th>
                        <th>Статус</th>
                        <th>База</th>
                        <th>Хеш</th>
                        <th>Размер</th>
                        <th>Загружено</th>
                        <th>Действие</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($adapters['adapters'] as $adapter)
                        @php
                            $adapterStatus = match ($adapter['status']) {
                                'published' => 'Опубликован',
                                'archived' => 'Архив',
                                'rolled_back' => 'Откат',
                                default => 'Кандидат',
                            };
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $adapter['expert'] }}</strong>
                                <p style="margin:4px 0 0;color:var(--muted);font-size:12px">v{{ $adapter['version'] }} · {{ $adapter['filename'] }}</p>
                            </td>
                            <td>
                                <span class="card-status {{ $adapter['status'] === 'published' ? 'status-active' : 'status-archived' }}">{{ $adapterStatus }}</span>
                                @if ($adapter['dataset_release_id'])
                                    <small style="color:var(--muted)">снимок: {{ substr($adapter['dataset_release_id'], 0, 8) }}…</small>
                                @endif
                            </td>
                            <td>{{ $adapter['base_model'] }}</td>
                            <td><code>{{ substr((string) $adapter['sha256'], 0, 12) }}…</code></td>
                            <td>{{ round($adapter['size_bytes'] / (1024 * 1024), 1) }} МБ</td>
                            <td>{{ $adapter['created_at'] ? \Carbon\Carbon::parse($adapter['created_at'])->format('d.m.Y H:i') : '—' }}</td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    @if (in_array($adapter['status'], ['candidate', 'rolled_back'], true))
                                        <form method="POST" action="{{ route('admin.training-adapters.publish', ['adapterId' => $adapter['id']]) }}"
                                              onsubmit="return confirm('Опубликовать версию v{{ $adapter['version'] }}? Предыдущая активная версия будет снята.')">
                                            @csrf
                                            <button class="button button-primary" type="submit">Опубликовать</button>
                                        </form>
                                    @endif
                                    @if ($adapter['status'] === 'published')
                                        <form method="POST" action="{{ route('admin.training-adapters.rollback', ['adapterId' => $adapter['id']]) }}"
                                              onsubmit="return confirm('Снять версию v{{ $adapter['version'] }} с публикации? Активного адаптера у эксперта не будет.')">
                                            @csrf
                                            <button class="button button-secondary" type="submit">Откатить</button>
                                        </form>
                                    @endif
                                    @if ($adapter['status'] !== 'published')
                                        <form method="POST" action="{{ route('admin.training-adapters.destroy', ['adapterId' => $adapter['id']]) }}"
                                              onsubmit="return confirm('Удалить запись v{{ $adapter['version'] }} из реестра? Файл будет удалён, если не используется другими версиями.')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="button button-danger" type="submit">Удалить</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @php
        $batchRangeStart = $batches['total'] === 0 ? 0 : $batches['offset'] + 1;
        $batchRangeEnd = min($batches['offset'] + count($batches['batches']), $batches['total']);
        $previousBatchOffset = max(0, $batches['offset'] - $batches['limit']);
        $nextBatchOffset = $batches['offset'] + $batches['limit'];
    @endphp
    <section class="section-heading">
        <div><span class="eyebrow">NEXUS AI · POSTGRESQL</span><h2>История партий</h2></div>
        <span class="directory-count">{{ $batchRangeStart }}–{{ $batchRangeEnd }} из {{ $batches['total'] }} партий</span>
    </section>

    <form method="GET" action="{{ route('admin.training-datasets') }}" class="panel getting-started project-form" style="grid-template-columns:repeat(2,minmax(0,1fr));align-items:end;margin-bottom:12px">
        <div class="field">
            <label for="filterExpert">Эксперт</label>
            <select id="filterExpert" name="expert">
                <option value="">Все эксперты</option>
                @foreach ($experts as $expert)
                    <option value="{{ $expert->key }}" @selected($selectedExpert === $expert->key)>{{ $expert->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="filterStatus">Статус</label>
            <select id="filterStatus" name="status">
                <option value="">Все статусы</option>
                <option value="pending_review" @selected($selectedStatus === 'pending_review')>Ожидает проверки</option>
                <option value="approved" @selected($selectedStatus === 'approved')>Утверждена</option>
                <option value="rejected" @selected($selectedStatus === 'rejected')>Отклонена</option>
            </select>
        </div>
        <button class="button button-secondary" type="submit" style="justify-self:start">Применить фильтры</button>
    </form>

    @if ($batches['batches'] === [])
        <section class="panel empty-state">
            <span class="empty-state-icon" aria-hidden="true">◎</span>
            <strong>Партий пока нет</strong>
            <p>Загрузите JSONL или CSV, чтобы начать собирать обучающие данные экспертов.</p>
        </section>
    @else
        <div class="table-scroll" role="region" aria-label="История наборов обучения" tabindex="0">
            <table class="user-table">
                <thead>
                    <tr>
                        <th>Эксперт / файл</th>
                        <th>Статус</th>
                        <th>Примеры</th>
                        <th>Дубли</th>
                        <th>Загружено</th>
                        <th>Действие</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($batches['batches'] as $batch)
                        @php
                            $batchStatus = match ($batch['status']) {
                                'approved' => 'Утверждена',
                                'rejected' => 'Отклонена',
                                default => 'Ожидает проверки',
                            };
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $batch['expert'] }}</strong>
                                <p style="margin:4px 0 0;color:var(--muted);font-size:12px">{{ $batch['filename'] }}</p>
                            </td>
                            <td><span class="card-status {{ $batch['status'] === 'approved' ? 'status-active' : 'status-archived' }}">{{ $batchStatus }}</span></td>
                            <td>
                                {{ $batch['valid'] }} / {{ $batch['total'] }} готовы
                                @if ($batch['invalid'] > 0)
                                    <small class="field-error">{{ $batch['invalid'] }} ошибок</small>
                                @endif
                            </td>
                            <td>{{ $batch['duplicates'] }}</td>
                            <td>{{ \Carbon\Carbon::parse($batch['created_at'])->format('d.m.Y H:i') }}</td>
                            <td>
                                <a class="button button-secondary" href="{{ route('admin.training-datasets', ['expert' => $selectedExpert, 'status' => $selectedStatus, 'batch' => $batch['id']]) }}">Проверить</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($batches['total'] > $batches['limit'])
        <nav class="modal-actions" aria-label="Страницы партий" style="margin-top:12px">
            @if ($batches['offset'] > 0)
                <a class="button button-secondary" href="{{ route('admin.training-datasets', ['expert' => $selectedExpert, 'status' => $selectedStatus, 'offset' => $previousBatchOffset]) }}">Предыдущие партии</a>
            @endif
            @if ($nextBatchOffset < $batches['total'])
                <a class="button button-secondary" href="{{ route('admin.training-datasets', ['expert' => $selectedExpert, 'status' => $selectedStatus, 'offset' => $nextBatchOffset]) }}">Следующие партии</a>
            @endif
        </nav>
    @endif

    @if ($selectedBatch)
        @php $validation = $selectedBatch['validation'] ?? []; @endphp
        <section class="section-heading">
            <div>
                <span class="eyebrow">ПРОВЕРКА ПАРТИИ · {{ $selectedBatch['id'] }}</span>
                <h2>{{ $selectedBatch['filename'] }}</h2>
            </div>
            <span class="directory-count">{{ $selectedBatch['expert'] }} · {{ $selectedBatch['status'] }}</span>
        </section>

        <section class="panel getting-started" aria-label="Проверка загруженного набора">
            <p>Строк: {{ $selectedBatch['total'] }} · корректных: {{ $selectedBatch['valid'] }} · с ошибками: {{ $selectedBatch['invalid'] }} · возможных дублей: {{ $selectedBatch['duplicates'] }}</p>
            <p>SHA-256 исходного файла: <code>{{ $selectedBatch['sha256'] }}</code></p>

            @php
                $exampleByRow = [];
                foreach (($validation['examples'] ?? []) as $previewExample) {
                    $exampleByRow[$previewExample['row']] = $previewExample;
                }
                $batchIsEditable = $selectedBatch['status'] === 'pending_review';
            @endphp
            @if (($validation['errors'] ?? []) !== [])
                <h3>Ошибки строк</h3>
                <ul>
                    @foreach ($validation['errors'] as $rowError)
                        @php $rowExample = $exampleByRow[$rowError['row']] ?? null; @endphp
                        <li>
                            Строка {{ $rowError['row'] }}: {{ $rowError['message'] }}
                            @if ($batchIsEditable)
                                @if ($rowExample)
                                    @php
                                        $fixedMetadata = is_array($rowExample['metadata'] ?? null)
                                            ? $rowExample['metadata']
                                            : [];
                                        $fixedMetadata['expert'] = $selectedBatch['expert'];
                                    @endphp
                                    <form class="field-hint" method="POST"
                                        action="{{ route('admin.training-datasets.examples.update', ['batchId' => $selectedBatch['id'], 'row' => $rowError['row']]) }}"
                                        style="display:inline-flex;gap:8px;align-items:center;margin-left:8px;vertical-align:middle">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="metadata" value="{{ json_encode($fixedMetadata, JSON_UNESCAPED_UNICODE) ?: '{}' }}">
                                        <input type="hidden" name="expert" value="{{ $selectedBatch['expert'] }}">
                                        <button class="button button-secondary" type="submit">Привязать к {{ $selectedBatch['expert'] }}</button>
                                    </form>
                                @else
                                    <form method="POST"
                                        action="{{ route('admin.training-datasets.examples.update', ['batchId' => $selectedBatch['id'], 'row' => $rowError['row']]) }}"
                                        style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:8px">
                                        @csrf
                                        @method('PATCH')
                                        <div class="field">
                                            <label for="repairQuestion{{ $rowError['row'] }}">Вопрос</label>
                                            <input id="repairQuestion{{ $rowError['row'] }}" name="question" maxlength="10000" required>
                                        </div>
                                        <div class="field">
                                            <label for="repairAnswer{{ $rowError['row'] }}">Ответ</label>
                                            <input id="repairAnswer{{ $rowError['row'] }}" name="answer" maxlength="100000" required>
                                        </div>
                                        <button class="button button-secondary" type="submit">Исправить строку</button>
                                    </form>
                                @endif
                                <form method="POST" class="field-hint"
                                    action="{{ route('admin.training-datasets.examples.destroy', ['batchId' => $selectedBatch['id'], 'row' => $rowError['row']]) }}"
                                    style="display:inline-flex;margin-left:8px;vertical-align:middle"
                                    onsubmit="return confirm('Удалить строку {{ $rowError['row'] }} из партии?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="button button-danger" type="submit">Удалить строку</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if (($validation['duplicates'] ?? []) !== [])
                <h3>Похожие вопросы — проверьте вручную</h3>
                <ul>
                    @foreach ($validation['duplicates'] as $duplicate)
                        <li>
                            Строка {{ $duplicate['row'] }} похожа на строку {{ $duplicate['matches_row'] }}
                            @if (isset($duplicate['matches_batch_id']))
                                из партии <a href="{{ route('admin.training-datasets', ['expert' => $selectedBatch['expert'], 'batch' => $duplicate['matches_batch_id']]) }}">{{ $duplicate['matches_batch_id'] }}</a>
                            @endif
                            @if (isset($duplicate['matches_question']))
                                «{{ $duplicate['matches_question'] }}»
                            @endif
                            (Jaccard {{ $duplicate['similarity'] }}).
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($selectedBatch['status'] === 'pending_review')
                <div class="modal-actions">
                    <form method="POST" action="{{ route('admin.training-datasets.approve', ['batchId' => $selectedBatch['id']]) }}">
                        @csrf
                        @if ($selectedBatch['duplicates'] > 0)
                            <label class="field" style="display:flex;align-items:flex-start;gap:9px;margin-bottom:10px">
                                <input type="checkbox" name="duplicates_reviewed" value="1" required>
                                <span>Я просмотрел {{ $selectedBatch['duplicates'] }} возможных дублей в этой партии и решил утвердить их вместе с набором.</span>
                            </label>
                        @endif
                        <button class="button button-primary" type="submit" @disabled($selectedBatch['invalid'] > 0)>Утвердить snapshot</button>
                    </form>
                    <form method="POST" action="{{ route('admin.training-datasets.reject', ['batchId' => $selectedBatch['id']]) }}" onsubmit="return confirm('Отклонить эту партию?')">
                        @csrf
                        <button class="button button-danger" type="submit">Отклонить</button>
                    </form>
                </div>
                @if ($selectedBatch['invalid'] > 0)
                    <p class="field-error">Утверждение недоступно, пока партия содержит некорректные строки. Исправьте или удалите их в списке «Ошибки строк» — счётчики пересчитаются автоматически.</p>
                @endif
            @else
                <p>Партия уже рассмотрена; её содержимое не изменяется.</p>
            @endif
        </section>

        @if (($validation['examples'] ?? []) !== [])
            @php
                $exampleCount = count($validation['examples']);
                $exampleRangeStart = $exampleOffset + 1;
                $exampleRangeEnd = min($exampleOffset + 50, $exampleCount);
                $previousExampleOffset = max(0, $exampleOffset - 50);
                $nextExampleOffset = $exampleOffset + 50;
            @endphp
            <section class="section-heading">
                <div><span class="eyebrow">Q/A · ПРЕДПРОСМОТР</span><h2>Примеры</h2></div>
                <span class="directory-count">{{ $exampleRangeStart }}–{{ $exampleRangeEnd }} из {{ $exampleCount }}</span>
            </section>
            <div class="table-scroll" role="region" aria-label="Предпросмотр вопросов и ответов" tabindex="0">
                <table class="user-table">
                    <thead>
                        <tr>
                            <th>Строка</th>
                            <th>Вопрос</th>
                            <th>Ответ</th>
                            <th>Проверка</th>
                            @if ($batchIsEditable)<th>Действие</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_slice($validation['examples'], $exampleOffset, 50) as $example)
                            <tr>
                                <td>{{ $example['row'] }}</td>
                                <td>{{ $example['question'] }}</td>
                                <td>{{ $example['answer'] }}</td>
                                <td>
                                    {{ match ($example['status'] ?? 'invalid') {
                                        'ready' => 'Готов',
                                        'duplicate_review' => 'Проверить дубль',
                                        default => 'Ошибка metadata',
                                    } }}
                                    @if (($example['source_format'] ?? '') !== '')
                                        <small style="color:var(--muted)">формат: {{ $example['source_format'] }}</small>
                                    @endif
                                </td>
                                @if ($batchIsEditable)
                                    <td>
                                        <form method="POST"
                                            action="{{ route('admin.training-datasets.examples.destroy', ['batchId' => $selectedBatch['id'], 'row' => $example['row']]) }}"
                                            onsubmit="return confirm('Удалить строку {{ $example['row'] }} из партии?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="button button-danger" type="submit">Удалить</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($exampleCount > 50)
                <nav class="modal-actions" aria-label="Страницы примеров" style="margin-top:12px">
                    @if ($exampleOffset > 0)
                        <a class="button button-secondary" href="{{ route('admin.training-datasets', ['expert' => $selectedExpert, 'status' => $selectedStatus, 'offset' => $batches['offset'], 'batch' => $selectedBatch['id'], 'example_offset' => $previousExampleOffset]) }}">Предыдущие примеры</a>
                    @endif
                    @if ($nextExampleOffset < $exampleCount)
                        <a class="button button-secondary" href="{{ route('admin.training-datasets', ['expert' => $selectedExpert, 'status' => $selectedStatus, 'offset' => $batches['offset'], 'batch' => $selectedBatch['id'], 'example_offset' => $nextExampleOffset]) }}">Следующие примеры</a>
                    @endif
                </nav>
            @endif
        @endif
    @endif
</x-app-layout>
