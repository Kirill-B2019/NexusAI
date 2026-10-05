# NEXUS AI — Тестирование

## Три уровня тестов

| Уровень | Файл | Что проверяет | Время |
|---------|------|---------------|-------|
| Smoke | scripts/smoke.sh | Система жива и отвечает | 5 сек |
| Unit | tests/unit/*.py | Логика модулей | 1 сек |
| Regression | scripts/regression.sh | Качество ответов LLM | 30–60 мин |

---

## Smoke-тесты

Запускаются автоматически в 08:00 через cron.

Ручной запуск:

    /opt/nexus-ai/scripts/smoke.sh

Проверяет 19 пунктов:
- Публичные эндпоинты (health, version)
- Аутентификация (401 без ключа, 200 с ключом)
- Эксперты (≥6)
- Проекты
- OpenAPI защита
- Админ-эндпоинты
- System health (все 5 сервисов)
- Rate limit headers

Выход: 0 — всё ок, 1 — есть проблемы.

---

## Unit-тесты (pytest)

### Структура

    tests/unit/
    ├── test_auth.py        — хеш ключей, AuthContext
    ├── test_chunker.py     — разбиение текста
    ├── test_extractors.py  — извлечение и sanitize
    └── test_rate_limit.py  — счётчики rate limit

### Запуск

Тесты запускаются **внутри контейнера API**:

    # Копируем тесты в контейнер
    sudo docker exec nexus-api rm -rf /app/tests_unit
    sudo docker cp /opt/nexus-ai/tests/unit nexus-api:/app/tests_unit

    # Запуск
    sudo docker exec nexus-api sh -c "cd /app && python3 -m pytest tests_unit/ -v"

    # Все 22 теста должны пройти за 1 секунду

### Дополнение

При изменении модулей:

1. Обновить тесты в /opt/nexus-ai/tests/unit/
2. Скопировать в контейнер
3. Запустить

---

## Regression-тесты

20 фиксированных вопросов к разным экспертам. Проверяет, что модель отвечает корректно.

### Файл

    /opt/nexus-ai/tests/regression.json

Структура:

    {
      "cases": [
        {
          "id": "arch_001",
          "expert": "system_architect",
          "message": "Что такое API Gateway?",
          "expected_keywords": ["api gateway", "точк", "вход"],
          "max_elapsed_s": 180
        }
      ]
    }

### Запуск

**Полный прогон:**

    /opt/nexus-ai/scripts/regression.sh

Займёт 30–60 минут.

**Тестовый прогон (3 кейса):**

    jq '{version, description, cases: .cases[0:3]}' \
      /opt/nexus-ai/tests/regression.json > /tmp/test.json

    TESTS_FILE=/tmp/test.json /opt/nexus-ai/scripts/regression.sh

**Один кейс:**

    jq '{version, description, cases: [.cases[] | select(.id == "arch_001")]}' \
      /opt/nexus-ai/tests/regression.json > /tmp/one.json

    TESTS_FILE=/tmp/one.json /opt/nexus-ai/scripts/regression.sh

### Отчёты

    /opt/nexus-ai/tests/reports/
    ├── regression_2026-10-05_11-13-15.json   — структурированный
    └── regression_2026-10-05_11-13-15.log    — текстовый

Последний отчёт:

    ls -t /opt/nexus-ai/tests/reports/*.json | head -1 | xargs jq '.summary'

### Использование для LoRA

Regression-набор — **baseline качества**. Порядок:

1. Прогнать regression ДО обучения LoRA → сохранить отчёт
2. Обучить LoRA-адаптер
3. Прогнать regression ПОСЛЕ → сравнить
4. Если качество выросло — LoRA оставить, иначе вернуться

---

## Нагрузочные тесты

Для проверки rate limit:

    # 65 запросов с project-ключом (лимит 60/мин)
    RATE_KEY=$(cat /tmp/rk2.json | jq -r '.key')
    for i in $(seq 1 65); do
      code=$(curl -s -o /dev/null -w "%{http_code}" \
        -H "X-API-Key: $RATE_KEY" http://localhost/api/v1/experts)
      echo -n "$code "
    done
    echo ""

Ожидаемо: 60 × 200, 5 × 429.

---

## CI/CD (в будущем)

Когда появится репозиторий:

1. **Pre-commit:** `bash -n` + `python3 -m ast` на всех файлах
2. **CI pipeline (GitHub Actions):**
   - Smoke-тесты
   - Pytest
   - Regression (только на релизных PR)
3. **Deploy:** через SSH + docker compose

---

## Метрики качества

| Метрика | Baseline (сейчас) | Цель после LoRA |
|---------|-------------------|-----------------|
| Smoke pass rate | 100% (19/19) | 100% |
| Unit pass rate | 100% (22/22) | 100% |
| Regression pass | ? / 20 | ≥ 18 / 20 |
| Среднее время ответа (single) | 60–120 сек | ≤ 90 сек |
| Среднее время (orchestration, 3 эксперта) | 240–400 сек | ≤ 300 сек |
| Утечка английского | редко | 0 |
| Точность попадания в роль | средняя | высокая |

После обучения LoRA на Этапе 8 — сравнить с baseline.

---

## Что НЕ покрыто тестами

- Извлечение из PDF/DOCX/XLSX — только базовый тест MIME
- Полный RAG-пайплайн — покрыт smoke-тестами косвенно
- Оркестрация с 3+ экспертами — есть 1 regression case
- SSE-стрим — не покрыт автоматическими тестами
- Обработка исключений БД — не покрыто

Покрытие со временем можно расширить.
