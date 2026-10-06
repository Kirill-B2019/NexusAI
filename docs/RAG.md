# NEXUS AI — RAG (Retrieval-Augmented Generation)

## Что это

RAG даёт экспертам доступ к вашим документам без переобучения модели.
Документы → чанки → эмбеддинги → Qdrant. При вопросе: поиск top-k → контекст в промпт.

## Пайплайн загрузки

    1. POST /v1/projects/{id}/documents (multipart)
            │
    2. Сохранение файла → /app/data/documents/{project_id}/{doc_id}_{name}
            │
    3. Запись в БД: documents (status='pending')
            │
    4. asyncio.create_task(process_document) — не блокирует API
            │
    5. Извлечение текста (extractors.py)
            │
    6. Чанкинг (chunker.py): 500 символов, overlap 100
            │
    7. Эмбеддинги (embeddings_client.py → nexus-embeddings)
            │
    8. Запись в Qdrant (qdrant_service.py)
            │
    9. Запись чанков в БД: document_chunks
            │
    10. Обновление: documents.status='ready', chunks_count=N

## Пайплайн запроса

    1. POST /v1/chat { message, project_id, use_rag, document_ids }
            │
    2. embed_query(message) → 384-dim вектор
            │
    3. qdrant.search(project_id, vec, top_k, score_threshold, document_ids)
            │
    4. rag.build_context(results) → текст с инструкциями
            │
    5. Контекст добавляется в system_prompt каждому эксперту
            │
    6. LLM отвечает со ссылками [1], [2], ...
            │
    7. В ответе: sources = [{document_name, chunk_index, score, preview}]

## Параметры

| Параметр          | Значение         | Файл                       |
|-------------------|------------------|----------------------------|
| Chunk size        | 500 символов     | chunker.py: CHUNK_SIZE     |
| Chunk overlap     | 100 символов     | chunker.py: CHUNK_OVERLAP  |
| Min chunk         | 50 символов      | chunker.py: MIN_CHUNK_SIZE |
| Max chunks        | 5000             | chunker.py                 |
| Embedding dim     | 384              | qdrant_service.py          |
| Метрика           | Cosine           | коллекция                  |
| Top-K по умолч.   | 4                | rag.py: DEFAULT_TOP_K      |
| Min score         | 0.5              | rag.py: DEFAULT_MIN_SCORE  |
| Max context       | 6000 символов    | rag.py: MAX_CONTEXT_CHARS  |

## Поддерживаемые форматы

| Формат         | Библиотека              | Особенности                  |
|----------------|-------------------------|------------------------------|
| TXT, MD, LOG   | встроенный decode       | UTF-8, CP1251, latin-1       |
| PDF            | pdfplumber              | Таблицы, страницы            |
| DOCX           | python-docx             | Параграфы + таблицы          |
| XLSX, XLSM     | openpyxl                | Все листы                    |
| XLS            | xlrd                    | Excel 97-2003                |
| ODS            | pandas + odfpy          | LibreOffice                  |
| CSV            | встроенный + Sniffer    | Автоопределение разделителя  |
| Код            | как текст               | Python, JS, TS, Go, Rust     |

Лимит файла: 50 МБ.
Лимит через Nginx: 100 МБ.

## Извлечение: sanitize

После извлечения текста вызывается _sanitize():
- Убирает null-байты (\\x00) — PostgreSQL не принимает их в TEXT
- Убирает control-символы кроме \\n, \\r, \\t
- Причина: PDF/DOCX часто содержат битые байты

## Изоляция проектов

Три уровня защиты:

1. API-уровень: require_project_access(ctx, project_id) → 403 при чужом ключе
2. Qdrant-фильтр: must: [project_id = X] жёстко в запросе
3. Файлы: каталог /app/data/documents/{project_id}/

Проверено: проект без документов не видит данные другого проекта.

## Фильтр по документам

Опциональный параметр document_ids в /v1/chat:
- RAG ищет только среди указанных документов
- Использует MatchAny в Qdrant-фильтре
- Полезно для анализа конкретного файла

Отдельный эндпоинт: POST /v1/documents/{id}/ask — всегда в одном документе.

## Удаление и переиндексация

DELETE /v1/documents/{id}:
1. Удаляются точки из Qdrant
2. Удаляется файл с диска
3. Удаляется запись из БД (каскадно document_chunks)

POST /v1/documents/{id}/reindex:
1. Удаляются старые точки из Qdrant
2. Удаляются старые чанки из БД
3. Сброс статуса на pending
4. Запуск process_document заново

Полезно после обновления extractors, chunking, embeddings.

## Производительность (реальные замеры)

Сервер: 12 ГБ RAM, 6 ядер, CPU.

| Операция                            | Время      |
|-------------------------------------|------------|
| Загрузка PDF 432 КБ (17 чанков)     | ~15 сек    |
| Embedding одного запроса            | ~100 мс    |
| Qdrant-поиск (top-4)                | ~5 мс      |
| RAG + ответ эксперта (single)       | 50–150 сек |
| RAG + ответ совета (3 эксперта)     | 4–8 мин    |

## Ограничения и решения

| Ограничение                          | Решение                       |
|--------------------------------------|-------------------------------|
| Большие PDF (>100 страниц)           | Лимит 5000 чанков             |
| Битые PDF (без текстового слоя)      | Нужен OCR (не реализован)     |
| Null-байты                           | _sanitize()                   |
| Таблицы теряют структуру             | pdfplumber + разделитель |    |
| Долгий ответ на CPU                  | thinking: false для простых   |

## Диагностика

Проверка эмбеддингов:

    sudo docker run --rm --network nexus-ai_default curlimages/curl -s \\
      -X POST http://nexus-embeddings:8001/embed \\
      -H "Content-Type: application/json" \\
      -d '{"texts":["тест"],"prefix":"query"}' | jq '.dim'
    # Ожидается: 384

Проверка коллекции Qdrant:

    sudo docker run --rm --network nexus-ai_default curlimages/curl -s \\
      http://nexus-qdrant:6333/collections/project_documents | jq '.result.points_count'

Прямой поиск из контейнера API:

    sudo docker exec nexus-api python3 -c "
    import asyncio, embeddings_client, qdrant_service
    async def test():
        vec = await embeddings_client.embed_query('тест')
        results = qdrant_service.search('PROJECT_ID', vec, top_k=3, score_threshold=0.0)
        print(f'Найдено: {len(results)}')
    asyncio.run(test())
    "

## Файлы

| Файл                         | Назначение                           |
|------------------------------|--------------------------------------|
| api/extractors.py            | Извлечение текста (PDF, DOCX, XLSX)  |
| api/chunker.py               | Разбиение на чанки                   |
| api/embeddings_client.py     | HTTP-клиент к embeddings             |
| api/qdrant_service.py        | Qdrant: upsert, search, delete       |
| api/rag.py                   | Оркестрация RAG: search → context    |
| api/routers/documents.py     | API документов                       |
| embeddings/main.py           | Эмбеддинг-сервис                     |

---

| KB @CerberRus00 - Nexus Invest Team
