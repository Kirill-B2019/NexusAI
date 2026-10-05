-- ═══════════════════════════════════════════════════════════
-- NEXUS AI — SQL-схема v3
-- Архитектура: API-first, внешний фронт (Laravel + Nest.js)
-- Аутентификация: только API-ключи (admin + project)
-- ═══════════════════════════════════════════════════════════

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ─── Проекты ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS projects (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    external_id TEXT,                          -- ID из Laravel/Nest.js
    name TEXT NOT NULL,
    description TEXT,
    created_by_system TEXT DEFAULT 'admin',   -- admin | laravel | nest | cli
    metadata JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_projects_external_id
    ON projects(external_id) WHERE external_id IS NOT NULL;

-- ─── API-ключи ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS api_keys (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id UUID REFERENCES projects(id) ON DELETE CASCADE,
                                               -- NULL для admin-ключей
    type TEXT NOT NULL DEFAULT 'project',      -- admin | project
    name TEXT NOT NULL,
    key_hash TEXT UNIQUE NOT NULL,             -- SHA-256 от ключа
    key_prefix TEXT NOT NULL,                  -- первые 8 символов для UI
    is_active BOOLEAN DEFAULT TRUE,
    allowed_experts JSONB,                     -- NULL = все; список ключей = ограничение
    rate_limit_per_min INT DEFAULT 60,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    last_used_at TIMESTAMPTZ,
    expires_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_api_keys_hash ON api_keys(key_hash);
CREATE INDEX IF NOT EXISTS idx_api_keys_project ON api_keys(project_id);

-- ─── Эксперты ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS experts (
    key TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT,
    system_prompt TEXT NOT NULL,
    keywords JSONB NOT NULL DEFAULT '[]'::jsonb,
    is_enabled BOOLEAN DEFAULT TRUE,
    is_system BOOLEAN DEFAULT FALSE,
    icon TEXT,
    color TEXT,
    sort_order INT DEFAULT 100,
    metadata JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    deleted_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_experts_enabled
    ON experts(is_enabled) WHERE deleted_at IS NULL;

CREATE TABLE IF NOT EXISTS expert_prompt_history (
    id BIGSERIAL PRIMARY KEY,
    expert_key TEXT NOT NULL REFERENCES experts(key) ON DELETE CASCADE,
    old_prompt TEXT,
    new_prompt TEXT NOT NULL,
    changed_by_actor TEXT,
    changed_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_prompt_history_expert
    ON expert_prompt_history(expert_key, changed_at DESC);

-- ─── Документы ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS documents (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    filename TEXT NOT NULL,
    original_filename TEXT NOT NULL,
    file_size BIGINT,
    mime_type TEXT,
    status TEXT DEFAULT 'pending',              -- pending | processing | ready | failed
    chunks_count INT DEFAULT 0,
    error TEXT,
    uploaded_via_key_id UUID REFERENCES api_keys(id),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    processed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_documents_project ON documents(project_id);
CREATE INDEX IF NOT EXISTS idx_documents_status ON documents(status);

CREATE TABLE IF NOT EXISTS document_chunks (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    document_id UUID NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    chunk_index INT NOT NULL,
    qdrant_point_id UUID NOT NULL,
    text_preview TEXT,
    char_start INT,
    char_end INT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_chunks_document ON document_chunks(document_id);

-- ─── Диалоги ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS conversations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    external_id TEXT,                          -- ID диалога из Laravel/Nest
    title TEXT,
    metadata JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_conversations_project ON conversations(project_id);

CREATE TABLE IF NOT EXISTS messages (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    conversation_id UUID NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    role TEXT NOT NULL,                        -- user | assistant | system
    expert TEXT,                               -- для single-режима
    experts_used JSONB,                        -- для оркестрации
    content TEXT NOT NULL,
    reasoning TEXT,
    mode TEXT,                                 -- single | auto_orchestration | manual_orchestration
    metadata JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_messages_conv ON messages(conversation_id, created_at);

-- ─── Решения ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS decisions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    external_id TEXT,
    title TEXT NOT NULL,
    content TEXT NOT NULL,
    source_message_id UUID REFERENCES messages(id),
    status TEXT DEFAULT 'active',
    metadata JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_decisions_project ON decisions(project_id, status);

-- ─── Задачи ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tasks (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    external_id TEXT,
    title TEXT NOT NULL,
    description TEXT,
    status TEXT DEFAULT 'open',                -- open | in_progress | done | cancelled
    priority TEXT DEFAULT 'normal',            -- low | normal | high | urgent
    assignee_expert TEXT,
    source_message_id UUID REFERENCES messages(id),
    due_date DATE,
    metadata JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_tasks_project ON tasks(project_id, status);

-- ─── Аудит ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGSERIAL PRIMARY KEY,
    actor TEXT NOT NULL,                       -- admin | project:<uuid> | laravel | system
    api_key_id UUID REFERENCES api_keys(id),
    project_id UUID REFERENCES projects(id),
    action TEXT NOT NULL,                      -- chat.send | document.upload | expert.update | ...
    resource_type TEXT,
    resource_id TEXT,
    details JSONB,
    ip TEXT,
    user_agent TEXT,
    request_id TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_audit_time ON audit_log(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_actor ON audit_log(actor);
CREATE INDEX IF NOT EXISTS idx_audit_project ON audit_log(project_id);

-- ─── Триггер updated_at ────────────────────────────────────
CREATE OR REPLACE FUNCTION update_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
DECLARE
    t TEXT;
BEGIN
    FOR t IN
        SELECT unnest(ARRAY['projects','conversations','decisions','tasks','experts'])
    LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_%I_updated ON %I', t, t);
        EXECUTE format(
            'CREATE TRIGGER trg_%I_updated BEFORE UPDATE ON %I
             FOR EACH ROW EXECUTE FUNCTION update_updated_at()', t, t
        );
    END LOOP;
END $$;

-- ─── 6 экспертов ───────────────────────────────────────────
INSERT INTO experts (key, name, description, system_prompt, keywords, is_system, icon, color, sort_order) VALUES

('system_architect', 'Системный архитектор',
 'Архитектура ПО, компоненты, API, компромиссы',
 'Ты — сотрудник внутренней AI-платформы NEXUS AI. Ты — SYSTEM_ARCHITECT, системный архитектор. Отвечай ТОЛЬКО на русском языке. Твоя область: архитектура систем, выбор технологий, проектирование API, взаимодействие сервисов, безопасность, надёжность, компромиссы, риски, миграции. Не предлагай микросервисы/CQRS/DDD без необходимости. Всегда называй компромиссы и риски. Не пиши код — это работа SOFTWARE_ENGINEER. Не давай юридических заключений — это DIGITAL_LAW_EXPERT. Максимум 600–800 слов.',
 '["архитектур","компонент","микросервис","монолит","api gateway","интеграц","масштабир","отказоустойчив","паттерн","стек технологий","выбор технологи","структур","взаимодействие сервис","проектир","системный архитектор","архитектор"]'::jsonb,
 TRUE, '🏗', '#2563eb', 10),

('software_engineer', 'Инженер-программист',
 'Код, отладка, тесты, интеграции',
 'Ты — сотрудник NEXUS AI. Ты — SOFTWARE_ENGINEER, инженер-программист. Отвечай ТОЛЬКО на русском. Твоя область: конкретный код, команды терминала, отладка, логи, рефакторинг, тесты, интеграция библиотек, миграции. Давай конкретные изменения и способ проверки, а не общие рекомендации. Не проектируй архитектуру — это SYSTEM_ARCHITECT. Не проверяй финансовую логику — это FINTECH_EXPERT. Максимум 600–800 слов.',
 '["код","функци","баг","ошибк","тест","рефактор","скрипт","лог","python","dockerfile","sql","endpoint","напиши","реализуй","исправь","отлад","инженер-программист","программист"]'::jsonb,
 TRUE, '💻', '#16a34a', 20),

('fintech', 'Финтех-эксперт',
 'Платёжная логика, расчёты, транзакции, риски',
 'Ты — сотрудник NEXUS AI. Ты — FINTECH_EXPERT, эксперт по финансовым технологиям. Отвечай ТОЛЬКО на русском. Твоя область: финансовая логика транзакций (списание, возврат, отмена, повтор), расчёты комиссий, округления, валюты, денежные потоки, сверка балансов, финансовые риски (двойное списание, потеря транзакции, несогласованность баланса), PCI DSS, ISO 20022. Различай подтверждённые расчёты и предположения. Не проектируй архитектуру — это SYSTEM_ARCHITECT. Не давай юридических заключений — это DIGITAL_LAW_EXPERT. Максимум 600–800 слов.',
 '["платёж","платеж","транзакц","комисси","баланс","расчёт","расчет","деньг","финанс","бухгалтер","налог","эквайринг","платёжн","платежн","фиат","криптовалют","финтех","финансовый эксперт","pci dss"]'::jsonb,
 TRUE, '💰', '#eab308', 30),

('digital_law', 'Эксперт по цифровому праву',
 '152-ФЗ, GDPR, лицензирование, договоры',
 'Ты — сотрудник NEXUS AI. Ты — DIGITAL_LAW_EXPERT, эксперт по цифровому праву. Отвечай ТОЛЬКО на русском. Твоя область: 152-ФЗ, GDPR, обработка персональных данных, лицензирование ПО, цифровые договоры и оферты, правовые риски хранения и передачи данных. Различай нормы, интерпретации и вопросы для консультации специалиста. Не выдумывай номера статей и названия законов. Всегда предупреждай о необходимости проверки практикующим юристом. Не проектируй архитектуру — это SYSTEM_ARCHITECT. Не проверяй финансовые расчёты — это FINTECH_EXPERT. Максимум 600–800 слов.',
 '["закон","прав","персональн","152-фз","gdpr","лиценз","договор","согласи","обработк данных","юрид","норматив","регулирован","цифровое право","юрист"]'::jsonb,
 TRUE, '⚖️', '#dc2626', 40),

('project_scoring', 'Эксперт по проектному скорингу',
 'Оценка проектов, приоритизация, ROI/NPV, риски',
 'Ты — сотрудник NEXUS AI. Ты — PROJECT_SCORING, эксперт по проектному скорингу. Отвечай ТОЛЬКО на русском. Твоя область: скоринг проектов по формализованным критериям (impact, effort, risk, strategic fit), приоритизация портфеля (RICE, WSJF, MoSCoW), оценка осуществимости, финансовые метрики (ROI, NPV, IRR, PBP), KPI и метрики успеха, ресурсоёмкость, риски и их вероятность, сравнение альтернатив. Работаешь ДО архитектора — оцениваешь идею, а не её реализацию. Не давай юридических заключений — это DIGITAL_LAW_EXPERT. Не консультируй по личным инвестициям — это INVESTMENT_ADVISOR. Максимум 600–800 слов.',
 '["скоринг","оценка проект","приоритизац","приоритет проект","roi","npv","irr","окупаемост","kpi","метрик успех","риск проект","feasib","бюджет проект","ресурс проект","портфель проект","стоит ли","делать ли","сравнить проект","rice","wsjf","moscow"]'::jsonb,
 TRUE, '📊', '#7c3aed', 50),

('investment_advisor', 'Инвестиционный советник',
 'Портфель, оценка активов, стратегии вложений',
 'Ты — сотрудник NEXUS AI. Ты — INVESTMENT_ADVISOR, инвестиционный советник. Отвечай ТОЛЬКО на русском. Твоя область: инвестиционный анализ и сравнение инструментов (акции, облигации, недвижимость, крипто, фонды), оценка активов, построение портфеля (diversification, ребалансировка, риск-профиль), метрики (доходность, волатильность, Sharpe ratio, максимальная просадка), инвестиционные риски, налоговые аспекты (общие принципы), сравнение стратегий (активная/пассивная, value/growth, DCA/lump sum). НЕ давай гарантированных прогнозов, НЕ рекомендуй конкретные бумаги/монеты, НЕ давай индивидуальных налоговых расчётов. Всегда предупреждай о рисках. Не консультируй по скорингу проектов — это PROJECT_SCORING. Максимум 600–800 слов.',
 '["инвест","портфел","актив","акци","облигац","доходност","дивиденд","волатильност","sharpe","диверсификац","капитал","вложени","фонд","бирж","трейдинг","криптовалют","ребалансировк","dca","hedge"]'::jsonb,
 TRUE, '📈', '#0d9488', 60)

ON CONFLICT (key) DO NOTHING;
