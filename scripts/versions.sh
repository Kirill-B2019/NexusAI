#!/bin/bash
set -euo pipefail

OUT="/opt/nexus-ai/VERSIONS.md"
DATE=$(date -Iseconds)

{
echo "# NEXUS AI — зафиксированные версии"
echo ""
echo "Дата снятия: $DATE"
echo ""
echo "## Система"
echo "- Ubuntu: $(grep PRETTY_NAME /etc/os-release | cut -d= -f2 | tr -d '\"')"
echo "- Kernel: $(uname -r)"
echo "- Архитектура: $(uname -m)"
echo "- CPU: $(nproc) ядер"
echo "- RAM: $(free -h | awk 'NR==2 {print $2}')"
echo ""
echo "## Docker"
echo "- Docker: $(docker --version)"
echo "- Compose: $(docker compose version --short)"
echo ""
echo "## Контейнеры"
docker compose -f /opt/nexus-ai/docker-compose.yml ps --format "table {{.Service}}\t{{.Image}}\t{{.Status}}"
echo ""
echo "## Модель"
ls -lh /opt/nexus-ai/models/*.gguf 2>/dev/null || echo "не найдена"
echo ""
echo "## SHA-256 модели"
sha256sum /opt/nexus-ai/models/*.gguf 2>/dev/null || echo "n/a"
echo ""
echo "## Эксперты"
grep -o '"[a-z_]*":' /opt/nexus-ai/api/experts.py 2>/dev/null | tr -d '":' | sort -u | sed 's/^/- /'
echo ""
echo "## Файлы (SHA-256)"
cd /opt/nexus-ai
sha256sum docker-compose.yml api/*.py nginx/*.conf 2>/dev/null
echo ""
echo "## Backup"
echo "- Расположение: /opt/nexus-ai/backups/"
echo "- Логи: backup.log, healthcheck.log"
} > "$OUT"

echo "Записано в $OUT"
