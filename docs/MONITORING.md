# NEXUS AI — Мониторинг

## Обзор

- **Prometheus** — сбор и хранение метрик (15 дней)
- **Grafana** — визуализация, 2 дашборда
- **Экспортёры:** node-exporter, postgres-exporter, nginx-exporter
- **API** — собственный /metrics endpoint

## Компоненты

| Сервис | Порт | Назначение |
|--------|------|-----------|
| nexus-prometheus | 9090 (внутр.) | TSDB + скрейпинг |
| nexus-grafana | 3000 (внеш.) | UI + дашборды |
| nexus-node-exporter | 9100 | CPU, RAM, диск, сеть хоста |
| nexus-postgres-exporter | 9187 | Метрики БД |
| nexus-nginx-exporter | 9113 | Метрики Nginx |

## Доступ

- **Grafana:** http://31.128.38.96:3000
- **Логин:** admin
- **Пароль:** в .env → `GRAFANA_PASSWORD`

- **Prometheus:** доступен только внутри Docker-сети `nexus-ai_default`

## Дашборды

### NEXUS AI — Overview

http://31.128.38.96:3000/d/nexus-overview

Панели:
- CPU Usage
- RAM Used
- Disk Usage
- Swap Used
- Load Average (1m / 5m / 15m)
- Network Traffic (RX / TX)

### NEXUS AI — API

http://31.128.38.96:3000/d/nexus-api

Панели:
- Requests per second (by endpoint)
- Latency p95 (by endpoint)
- Errors per second (4xx / 5xx)
- Active requests
- Total Requests (24h)
- Error Rate
- Current RPS

## Targets в Prometheus

| Job | Что собирает | Endpoint |
|-----|--------------|----------|
| prometheus | Self-monitoring | localhost:9090 |
| node | Метрики хоста | nexus-node-exporter:9100 |
| postgres | Метрики БД | nexus-postgres-exporter:9187 |
| qdrant | Метрики векторной БД | nexus-qdrant:6333/metrics |
| nginx | Метрики Nginx | nexus-nginx:80/nginx_status |
| nexus-api | Метрики API | nexus-api:8000/metrics |

## Метрики API

FastAPI экспортирует:

- `nexus_http_requests_total{method, endpoint, status}` — счётчик запросов
- `nexus_http_request_duration_seconds{method, endpoint}` — гистограмма длительности
- `nexus_http_requests_in_progress{method}` — активные запросы
- `nexus_chat_requests_total{mode}` — запросы чата (single/auto/manual)
- `nexus_chat_experts_used` — сколько экспертов на запрос
- `nexus_document_uploads_total{status}` — загрузки документов
- `nexus_rag_searches_total{has_results}` — RAG-поиски

Endpoint `/metrics` **не требует** авторизации и **не учитывается** в собственных метриках.

## Типовые запросы

CPU:
    100 - (avg(rate(node_cpu_seconds_total{mode="idle"}[5m])) * 100)

RAM:
    node_memory_MemTotal_bytes - node_memory_MemAvailable_bytes

Disk:
    100 - (node_filesystem_avail_bytes{mountpoint="/"} / node_filesystem_size_bytes{mountpoint="/"} * 100)

RPS API:
    sum by (endpoint) (rate(nexus_http_requests_total[5m]))

Latency p95 API:
    histogram_quantile(0.95, sum by (endpoint, le) (rate(nexus_http_request_duration_seconds_bucket[5m])))

Error Rate:
    sum(rate(nexus_http_requests_total{status=~"[45].."}[1h])) / sum(rate(nexus_http_requests_total[1h])) * 100

## Обновление конфигов

### Prometheus
    nano /opt/nexus-ai/monitoring/prometheus/prometheus.yml
    cd /opt/nexus-ai && sudo docker compose restart nexus-prometheus

### Дашборды
Файлы в /opt/nexus-ai/monitoring/grafana/dashboards/. Grafana обновляет каждые 30 секунд.

### Datasource Grafana
Создан через API Grafana (UID `prometheus`). Provisioning автоматически **не сработал** — если нужно пересоздать, используйте API:

    curl -X POST http://localhost:3000/api/datasources \\
      -u admin:$GRAFANA_PASS \\
      -H "Content-Type: application/json" \\
      -d '{"name":"Prometheus","uid":"prometheus","type":"prometheus","access":"proxy","url":"http://nexus-prometheus:9090","isDefault":true}'

## Retention

- Prometheus: 15 дней
- Volume: nexus-ai_prometheus_data, nexus-ai_grafana_data

## Диагностика

Targets:
    sudo docker run --rm --network nexus-ai_default curlimages/curl -s \\
      http://nexus-prometheus:9090/api/v1/targets \\
      | jq '.data.activeTargets[] | {job: .labels.job, health: .health}'

Метрики API:
    sudo docker run --rm --network nexus-ai_default curlimages/curl -s \\
      http://nexus-api:8000/metrics | head -30

Логи:
    sudo docker compose logs --tail=50 nexus-prometheus
    sudo docker compose logs --tail=50 nexus-grafana

## Пороговые значения для алертов (на будущее)

- CPU > 90% — 5 мин
- RAM > 90% — 5 мин
- Disk > 85% — 1 час
- Error Rate > 5% — 5 мин
- p95 > 30s — 5 мин
- Target down > 2 мин

## Планы

- Telegram-алерты через Alertmanager
- Отдельный дашборд для PostgreSQL
- Отдельный дашборд для Qdrant
- Долгосрочное хранение метрик (Thanos или Victoria Metrics)
