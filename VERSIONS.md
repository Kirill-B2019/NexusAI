# NEXUS AI — зафиксированные версии

Дата снятия: 2026-10-06T09:53:40+00:00

## Система
- Ubuntu: Ubuntu 26.04.1 LTS
- Kernel: 7.0.0-38-generic
- Архитектура: x86_64
- CPU: 8 ядер
- RAM: 15Gi

## Docker
- Docker: Docker version 29.8.2, build 7fc2dff
- Compose: 5.6.0

## Контейнеры
SERVICE                   IMAGE                                           STATUS
api                       nexus-ai-api                                    Up 11 minutes
embeddings                nexus-ai-embeddings                             Up 3 hours
nexus-grafana             grafana/grafana:11.3.0                          Up 3 hours
model-server              ghcr.io/ggml-org/llama.cpp:server               Up 15 minutes (healthy)
nginx                     nginx:alpine                                    Up 3 hours
nexus-nginx-exporter      nginx/nginx-prometheus-exporter:1.3.0           Up 3 hours
nexus-node-exporter       prom/node-exporter:v1.8.2                       Up 3 hours
postgres                  postgres:17                                     Up 3 hours
nexus-postgres-exporter   prometheuscommunity/postgres-exporter:v0.16.0   Up 3 hours
nexus-prometheus          prom/prometheus:v2.55.0                         Up 3 hours
qdrant                    qdrant/qdrant:latest                            Up 3 hours

## Модель
-rw-r--r-- 1 root root 2.4G Oct  4 10:41 /opt/nexus-ai/models/Qwen3-4B-Q4_K_M.gguf

## SHA-256 модели
82ce9e838bc04c7a2a08af6a7cda9ecd305c0b5bd93e8d3124cc068a961e8f4a  /opt/nexus-ai/models/Qwen3-4B-Q4_K_M.gguf

## Эксперты
- digital_law
- fintech
- software_engineer
- system_architect

## Файлы (SHA-256)
beb65f32e362243c168de3bc50304d3b63cd0f52108475f21c4bb4db4f920ba4  docker-compose.yml
bdde404c856dd45b9a5a4d717a8d03273a17ec656eadb9ee169ceec9008641ff  api/auth.py
6e71441ded7bb12b872bebdcf02b9aaf43486d28fb9e20ed93fc98b6d23bdbed  api/chunker.py
6d84f5da129e8bcbb906f558241a5bd45360cdf8b3526e9d563d21238fce4ef2  api/embeddings_client.py
ace31193778309195f9f21539ba6d6c73f0dd49917232d4f4a7ee62facd879ba  api/experts.py
f8eccb6be1d01996b6fb86773880093baa22a2c106a738ebda838e9fe8a1bbd7  api/experts_service.py
2125100ae64aebd2766478ce1f960732ca8350dd987c0c96fceb2dd57427b4d1  api/extractors.py
e946b63cd57cd29d4a36e4760e769ecd6779d5d1b314ffa196912fff1a8e6a99  api/main.py
3f55850be337cb5ef075e2d1a70a8bb9cf39fe4d2fb89ca0c987b1f7264f75a0  api/metrics.py
5d19aee24b065c9f24b01180b46a8cbd9929cf223a16a89bb3cd5e4bda26bc0e  api/middleware.py
1c81ab77ffdfa1c2aa3698fc905a3080589068a68e37abe8b62ef09f8e4b513e  api/orchestrator.py
3c6588eec75943f5ffd76f013729ec11be1bd0b57bc5290b70e8f875c5bdebce  api/qdrant_service.py
3cd2c41383f7b32bea7668fbd954fe869c8cdff117ae31aac3810a2502c51ee8  api/rag.py
0357471802019bbcd230854d001647f247de560f7aa88bcbda646d92da7736ea  api/rate_limit.py
91abdfd60ec50248ac2420acc65ff39f02a1903cf56bf47a6e09d68fc4aaef86  nginx/default.conf

## Backup
- Расположение: /opt/nexus-ai/backups/
- Логи: backup.log, healthcheck.log
