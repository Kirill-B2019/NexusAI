# NEXUS AI — UI / Панель управления

Руководство для команды, разрабатывающей интерфейс NEXUS AI
(Laravel + Livewire / Nest.js + React / Vue).

## Философия

NEXUS AI — API-first. Backend не навязывает UI.
Всё, что может понадобиться интерфейсу, доступно через API.
NEXUS AI не знает о пользователях и ролях — это ответственность Laravel.

## Роли и доступ

Роли хранятся в Laravel:
- admin — полный доступ к админ-панели и всем проектам
- user — работа со своими проектами
- viewer — только чтение

Laravel → NEXUS AI проксирует запросы с admin или project API-ключом.
UI никогда не видит API-ключ NEXUS AI напрямую.

## Карта экранов

### Админ-панель (/admin)

    /admin/dashboard         — Сводка: проекты, документы, запросы
    /admin/experts           — Список экспертов, включение/отключение
    /admin/experts/{key}     — Редактор промпта и keywords
    /admin/projects          — Все проекты
    /admin/projects/{id}     — Проект: документы, диалоги, ключи, usage
    /admin/users             — Пользователи (Laravel-сторона)
    /admin/audit             — Журнал аудита с фильтрами
    /admin/system            — System health, все сервисы

### Клиентский UI (/app)

    /app                     — Список проектов пользователя
    /app/projects/{id}/chat  — Чат с выбором экспертов
    /app/projects/{id}/documents  — Документы проекта
    /app/projects/{id}/conversations — История диалогов
    /app/projects/{id}/decisions — Решения
    /app/projects/{id}/tasks — Задачи
    /app/profile             — Профиль

---

## Экраны админ-панели

### /admin/dashboard

Источник: GET /v1/admin/stats

Показывает:
- Карточки: проекты, документы (ready/failed), чанки
- Диалоги, сообщения, решения, задачи
- API-ключи (активные / всего)
- Эксперты (включено / всего)
- Аудит за 24ч (всего / chat-запросов)

Вёрстка: сетка 4×2 с карточками KPI.

### /admin/experts

Источник: GET /v1/experts/all (admin)

Таблица:
| Иконка | Название | Роль | Статус | Keywords | Действия |
|--------|----------|------|--------|----------|----------|
| 🏗 | Системный архитектор | ... | 🟢 вкл | 16 | Ред. / Откл. |
| 💻 | Инженер-программист | ... | 🟢 вкл | 18 | ... |

Действия:
- Тумблер вкл/выкл → POST /v1/experts/{key}/enable | disable
- Иконка «Редактировать» → /admin/experts/{key}
- Удалить (только для is_system=false) → DELETE /v1/experts/{key}

### /admin/experts/{key} — редактор

Источник: GET /v1/experts/{key}

Форма:
- key — readonly
- name — input
- description — textarea
- system_prompt — большая textarea (монопространственный шрифт)
- keywords — список тегов, добавление через Enter
- icon — emoji-picker
- color — color-picker
- sort_order — number
- is_enabled — toggle

Кнопки: Сохранить → PATCH /v1/experts/{key}, Сбросить.

Внизу — история изменений промпта (если добавим в API в будущем).

### /admin/projects

Источник: GET /v1/admin/projects-usage

Таблица:
| Проект | External ID | Документы | Диалоги | Сообщения | Решения | Задачи | Последняя активность |
|--------|-------------|-----------|---------|-----------|---------|--------|----------------------|

Действия:
- Создать проект → POST /v1/projects
- Открыть → /admin/projects/{id}

### /admin/projects/{id}

Табы:
1. **Обзор** — метрики проекта
2. **API-ключи** — GET /v1/projects/{id}/keys
   - Таблица: name, prefix, allowed_experts, last_used_at, действия
   - Создать ключ → POST /v1/projects/{id}/keys
   - Отозвать → DELETE /v1/projects/{id}/keys/{key_id}
3. **Документы** — список документов с фильтром по status
4. **Диалоги** — история
5. **Решения**
6. **Задачи**

### /admin/audit

Источник: GET /v1/admin/audit

Фильтры:
- actor (dropdown: admin, project:<uuid>)
- action (dropdown с wildcard)
- project_id (dropdown)
- from_time / to_time (datetime pickers)

Таблица:
| Время | Actor | Action | Resource | Project | IP | Details |

Пагинация через offset/limit.

### /admin/system

Источник: GET /v1/admin/system-health

Карточки 5 сервисов:
- 🟢 API — ok
- 🟢 Model — ok
- 🟢 Embeddings — ok
- 🟢 PostgreSQL — ok
- 🟢 Qdrant — ok

Кнопка «Обновить».

---

## Экраны клиентского UI

### Чат (главный экран)

Компоновка:

    ┌────────────────┬───────────────────────────┐
    │ Диалоги        │   Чат                     │
    │ (сайдбар)      │                           │
    │ + Новый диалог │   [сообщения]             │
    │                │                           │
    │ Сегодня        │   ─────────────────       │
    │ • API Gateway  │   [textarea] [Эксперты]   │
    │ • Сравнение... │                           │
    │                │                           │
    │ Вчера          │                           │
    │ • ...          │                           │
    └────────────────┴───────────────────────────┘

**Выбор экспертов над textarea:**

    [ ] Авто-выбор         ← по умолчанию
    [ ] 🏗 Архитектор
    [ ] 💻 Программист
    [ ] 💰 Финтех
    [ ] ⚖️ Право
    [ ] 📊 Скоринг
    [ ] 📈 Инвестсоветник

Пользователь либо ставит чекбокс «Авто-выбор», либо отмечает конкретных экспертов (до 4).

**Сообщения:**

Каждое сообщение:
- **user** — справа, синий фон
- **assistant** — слева, серый фон

Ответ от оркестрации — блок с несколькими экспертами:

    🏗 Системный архитектор
    ────────────────────────
    [текст ответа]

    💰 Финтех-эксперт
    ────────────────────────
    [текст ответа]

Под каждым блоком — свёрнутый `💭 Размышления` (если thinking).

**Источники RAG:**
Под ответом, если rag_used=true:

    📎 Источники:
    [1] test-doc.md — chunk 0, score 0.813
    [2] PDF-отчёт — chunk 5, score 0.79

**Стрим:**

Использовать SSE через fetch + ReadableStream:

    const response = await fetch('/api/v1/chat/stream', {
      method: 'POST',
      headers: { 'X-API-Key': apiKey, 'Content-Type': 'application/json' },
      body: JSON.stringify({ message, expert, project_id })
    });

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      const events = buffer.split('\n\n');
      buffer = events.pop();

      for (const ev of events) {
        const type = (ev.match(/^event: (.+)$/m) || [])[1];
        const data = (ev.match(/^data: (.+)$/m) || [])[1];
        if (!data) continue;
        const payload = JSON.parse(data);

        if (type === 'token') {
          appendToActiveExpert(payload.expert, payload.delta);
        } else if (type === 'expert_start') {
          startExpertBlock(payload.expert);
        } else if (type === 'done') {
          finalize(payload);
        }
      }
    }

### /app/projects/{id}/documents

Источник: GET /v1/projects/{id}/documents

Действия:
- Drag & drop загрузка → POST /v1/projects/{id}/documents
- Список с фильтром по status
- Polling каждые 3 сек, если есть pending/processing
- Кнопки: переиндексировать, удалить
- Кнопка «Спросить по документу» → POST /v1/documents/{id}/ask

### /app/projects/{id}/conversations

Источник: GET /v1/projects/{id}/conversations

Список с:
- Названием (автозаголовок из первого сообщения)
- Датой последнего обновления
- Возможностью переименовать → PATCH
- Удалить → DELETE

### /app/projects/{id}/decisions

Таблица:
| Title | Status | Source message | Created |

Статусы: active (зелёный), superseded (жёлтый), archived (серый).

Действия: создать, изменить, удалить.

### /app/projects/{id}/tasks

Kanban или таблица:

    ┌──────────┬──────────┬──────────┬──────────┐
    │ Open (3) │ In Progr.│ Done (1) │ Cancel.  │
    ├──────────┼──────────┼──────────┼──────────┤
    │ задача 1 │ задача 4 │ задача 5 │ задача 7 │
    │ задача 2 │          │          │          │
    │ задача 3 │          │          │          │
    └──────────┴──────────┴──────────┴──────────┘

Drag & drop → PATCH /v1/tasks/{id} {status: "..."}

Приоритет отображается цветом:
- urgent — красный
- high — оранжевый
- normal — серый
- low — зелёный

---

## Дизайн-система

### Цвета экспертов

| Эксперт | Цвет | Иконка |
|---------|------|--------|
| system_architect | #2563eb | 🏗 |
| software_engineer | #16a34a | 💻 |
| fintech | #eab308 | 💰 |
| digital_law | #dc2626 | ⚖️ |
| project_scoring | #7c3aed | 📊 |
| investment_advisor | #0d9488 | 📈 |

Эти цвета приходят из API (GET /v1/experts → .color).
Не хардкодить в UI.

### Статусы

| Статус | Цвет | Описание |
|--------|------|----------|
| ready (документ) | #d4edda | Готов к работе |
| processing | #cce5ff | Обрабатывается |
| pending | #fff3cd | В очереди |
| failed | #f8d7da | Ошибка |

| Статус задачи | Отображение |
|---------------|-------------|
| open | серый |
| in_progress | синий |
| done | зелёный |
| cancelled | серый с зачёркиванием |

### Типографика

- Заголовки: system-ui, bold
- Текст: system-ui, 14px
- Код: монопространственный, 13px
- Отступы: 4, 8, 12, 16, 24 px

### Иконки

Из эмодзи, не нужно тащить icon-pack.

---

## Обработка ошибок в UI

| Код | Что показывать |
|-----|----------------|
| 400 | «Неверный запрос: <detail>» |
| 401 | Редирект на login |
| 403 | «Нет доступа» |
| 404 | «Не найдено» |
| 413 | «Файл больше 50 МБ» |
| 429 | «Слишком много запросов, подождите N сек» |
| 500 | «Ошибка сервера, попробуйте позже» |
| 502 | «AI-модель недоступна» |
| 504 | «Модель не ответила за отведённое время» |

---

## Проксирование через Laravel

UI не общается с NEXUS AI напрямую.

**Laravel роуты:**

    // routes/web.php
    Route::middleware(['auth', 'admin'])->prefix('nexus-proxy')->group(function () {
        Route::get('/admin/stats', [NexusProxyController::class, 'adminStats']);
        Route::get('/admin/experts', [NexusProxyController::class, 'adminExperts']);
        Route::patch('/admin/experts/{key}', [NexusProxyController::class, 'adminExpertUpdate']);
        Route::get('/admin/audit', [NexusProxyController::class, 'adminAudit']);
        Route::get('/admin/system-health', [NexusProxyController::class, 'adminSystemHealth']);
    });

    Route::middleware('auth')->prefix('nexus-proxy')->group(function () {
        Route::post('/chat', [NexusProxyController::class, 'chat']);
        Route::post('/chat/stream', [NexusProxyController::class, 'chatStream']);
        Route::get('/projects/{id}/documents', [NexusProxyController::class, 'listDocuments']);
        Route::post('/projects/{id}/documents', [NexusProxyController::class, 'uploadDocument']);
    });

**NexusProxyController:**

    class NexusProxyController
    {
        public function adminStats()
        {
            return Http::withHeaders(['X-API-Key' => config('services.nexus_ai.admin_key')])
                ->get(config('services.nexus_ai.base_url') . '/v1/admin/stats')
                ->json();
        }

        public function chat(Request $request)
        {
            $project = $request->user()->currentProject();
            return Http::withHeaders(['X-API-Key' => $project->nexus_api_key])
                ->timeout(180)
                ->post(config('services.nexus_ai.base_url') . '/v1/chat', $request->all())
                ->json();
        }
    }

---

## Что уже готово в NEXUS AI (для UI)

- **Отладочный чат-UI** — /debug/chat.html (для разработки, не для пользователей)
- **Отладочный документы-UI** — /debug/documents.html
- **OpenAPI / Swagger** — /api/docs (за admin-ключом)
- **Postman-коллекция** — docs/postman_collection.json (при наличии)

Эти страницы можно использовать как референс при разработке основного UI.

---

## Примеры компонентов

### ExpertSelector (React)

    function ExpertSelector({ experts, value, onChange, allowAuto = true }) {
      return (
        <div className="expert-selector">
          {allowAuto && (
            <label>
              <input
                type="checkbox"
                checked={value === null}
                onChange={() => onChange(null)}
              />
              🤖 Авто-выбор
            </label>
          )}
          {experts.map(e => (
            <label key={e.key} style={{ color: e.color }}>
              <input
                type="checkbox"
                checked={Array.isArray(value) && value.includes(e.key)}
                onChange={(ev) => {
                  const set = new Set(Array.isArray(value) ? value : []);
                  ev.target.checked ? set.add(e.key) : set.delete(e.key);
                  onChange(Array.from(set));
                }}
              />
              {e.icon} {e.name}
            </label>
          ))}
        </div>
      );
    }

### MessageList (React)

    function MessageList({ messages }) {
      return (
        <div className="message-list">
          {messages.map(m => (
            <div key={m.id} className={`message message-${m.role}`}>
              {m.role === 'assistant' && m.experts_used && (
                <div className="experts-tags">
                  {m.experts_used.map(k => (
                    <span key={k} className="tag">{k}</span>
                  ))}
                </div>
              )}
              <div className="content">{m.content}</div>
              {m.metadata?.sources && <SourcesList sources={m.metadata.sources} />}
            </div>
          ))}
        </div>
      );
    }

### SSE-подписка (React hook)

    function useChatStream() {
      const [isStreaming, setIsStreaming] = useState(false);
      const [tokens, setTokens] = useState({});

      const start = useCallback(async (payload, apiKey, onDone) => {
        setIsStreaming(true);
        setTokens({});

        const response = await fetch('/nexus-proxy/chat/stream', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '...' },
          body: JSON.stringify(payload)
        });

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (true) {
          const { done, value } = await reader.read();
          if (done) break;
          buffer += decoder.decode(value, { stream: true });
          const events = buffer.split('\n\n');
          buffer = events.pop();

          for (const ev of events) {
            const t = (ev.match(/^event: (.+)$/m) || [])[1];
            const d = (ev.match(/^data: (.+)$/m) || [])[1];
            if (!d) continue;
            const payload = JSON.parse(d);

            if (t === 'token') {
              setTokens(prev => ({
                ...prev,
                [payload.expert]: (prev[payload.expert] || '') + payload.delta
              }));
            } else if (t === 'done') {
              onDone?.(payload);
            }
          }
        }
        setIsStreaming(false);
      }, []);

      return { isStreaming, tokens, start };
    }

---

## Рекомендации

1. **Стрим — обязательно.** Ответ от эксперта может идти 2-5 минут на CPU. Без стрима пользователь думает, что система зависла.

2. **Показывайте источники RAG.** Это ключевая ценность — ответ на основе документов проекта.

3. **Отключайте RAG для общих вопросов.** В чате — чекбокс RAG.

4. **Разные цвета для экспертов.** Пользователь сразу видит, кто что сказал.

5. **Отдельный экран «Аудит» для админа.** Это журнал всех действий.

6. **Timeout 300 сек минимум на клиенте.** На CPU thinking-ответ может занять 4–5 минут.

7. **Показывайте rate limit.** Заголовки X-RateLimit-Remaining — пользователь видит, сколько осталось.

8. **Логируйте X-Request-ID.** Если что-то упало — по нему можно найти в audit_log.

9. **Проксируйте через Laravel.** Не давайте UI прямой доступ к NEXUS AI — все ключи на сервере.

10. **Кэшируйте список экспертов.** Он редко меняется, можно раз в 5 минут.

---

| KB @CerberRus00 - Nexus Invest Team
