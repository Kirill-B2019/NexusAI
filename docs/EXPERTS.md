# NEXUS AI — Эксперты

## Обзор

6 системных экспертов. Хранятся в таблице experts, редактируются через API.
Каждый эксперт имеет:
- Уникальный key (например, system_architect)
- system_prompt — его роль и границы
- keywords — для роутера
- is_enabled — участвует ли в оркестрации
- is_system=true — защита от удаления

## Список экспертов

### system_architect — Системный архитектор
- icon: 🏗, color: #2563eb, sort_order: 10
- Область: архитектура систем, выбор технологий, API, взаимодействия сервисов, безопасность, надёжность, компромиссы, риски
- Не делает: код (software_engineer), юридические заключения (digital_law), финансовые расчёты (fintech)

### software_engineer — Инженер-программист
- icon: 💻, color: #16a34a, sort_order: 20
- Область: код, отладка, тесты, рефакторинг, логи, интеграции, миграции, скрипты
- Не делает: архитектуру (system_architect), финансы (fintech), право (digital_law)

### fintech — Финтех-эксперт
- icon: 💰, color: #eab308, sort_order: 30
- Область: финансовая логика, платёжные системы, транзакции, комиссии, риски, PCI DSS, ISO 20022
- Не делает: архитектуру, код, юридические заключения

### digital_law — Цифровое право
- icon: ⚖️, color: #dc2626, sort_order: 40
- Область: 152-ФЗ, GDPR, персональные данные, лицензирование, договоры, оферты, правовые риски
- Не делает: архитектуру, код, финансовые расчёты
- Особенность: всегда предупреждает о необходимости консультации практикующего юриста

### project_scoring — Проектный скоринг
- icon: 📊, color: #7c3aed, sort_order: 50
- Область: скоринг проектов, приоритизация (RICE, WSJF, MoSCoW), ROI/NPV/IRR, KPI, риски, feasibility
- Работает ДО архитектора — оценивает идею
- Не делает: личные инвестиции (investment_advisor), юридические заключения

### investment_advisor — Инвестиционный советник
- icon: 📈, color: #0d9488, sort_order: 60
- Область: инвестиционный анализ, портфель, метрики (Sharpe, волатильность), стратегии, налоги (общие принципы)
- Не делает: гарантированные прогнозы, рекомендации конкретных бумаг, скоринг проектов

## Как добавить нового эксперта

### Через API (admin)
    curl -X POST http://localhost/api/v1/experts \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{
        "key": "cybersec_expert",
        "name": "Эксперт по кибербезопасности",
        "description": "Безопасность приложений и инфраструктуры",
        "system_prompt": "Ты — CYBERSEC_EXPERT, эксперт по кибербезопасности NEXUS AI. ...",
        "keywords": ["безопасность", "cybersecurity", "уязвимость", "OWASP", "penetration test"],
        "icon": "🔒",
        "color": "#1f2937",
        "sort_order": 70
      }'

### Обновление промпта
    curl -X PATCH http://localhost/api/v1/experts/cybersec_expert \
      -H "X-API-Key: $ADMIN_KEY" \
      -H "Content-Type: application/json" \
      -d '{"system_prompt": "Новый промпт..."}'

Изменение промпта сохраняется в expert_prompt_history.

### Включение / отключение
    curl -X POST http://localhost/api/v1/experts/cybersec_expert/disable \
      -H "X-API-Key: $ADMIN_KEY"

Отключённый эксперт не участвует в роутинге, но остаётся в БД.

### Удаление
    curl -X DELETE http://localhost/api/v1/experts/cybersec_expert \
      -H "X-API-Key: $ADMIN_KEY"

Только для is_system=false. Soft delete через deleted_at.

## Правила хорошего промпта

1. Начни с идентичности: "Ты — EXPERT_KEY, эксперт по X в NEXUS AI."
2. Опиши ТОЛЬКО СВОЮ ОБЛАСТЬ — что входит.
3. Опиши ЧЕГО НЕ ДЕЛАТЬ — границы с другими экспертами.
4. Требуй ТОЛЬКО РУССКИЙ.
5. Запрещай представляться без прямого вопроса.
6. Требуй честности: "если данных нет — скажи об этом".
7. Формат ответа: структура, максимум 600-800 слов.

## Ключевые слова (keywords)

keywords используются роутером для автоматического выбора экспертов.
Формат: JSONB-массив строк.

Рекомендации:
- 15-25 ключевых слов
- Разные формы: "платёж", "платеж", "платёжн"
- Английские технические термины: "pci dss", "gdpr"
- Не пересекаться с другими экспертами

## Пересечения (кто к кому направляет)

| Из | В | Когда |
|----|---|-------|
| system_architect | software_engineer | нужен код |
| system_architect | fintech | финансовые расчёты |
| system_architect | digital_law | юридические требования |
| software_engineer | system_architect | архитектурные решения |
| software_engineer | digital_law | юридические требования |
| fintech | system_architect | архитектура платёжной системы |
| fintech | digital_law | регулирование платежей |
| digital_law | system_architect | техническая реализация требований |
| project_scoring | investment_advisor | инвестиционная привлекательность |
| project_scoring | fintech | финансовая модель проекта |
| investment_advisor | digital_law | регулирование инвестиций |
