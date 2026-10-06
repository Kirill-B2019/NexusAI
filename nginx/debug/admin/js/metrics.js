/**
 * NEXUS AI Admin — Metrics (system-health, Prometheus targets, raw metrics)
 */

const Metrics = {
  // ─── Общая загрузка ───────────────────────────────────
  async load() {
    await Promise.all([
      this.loadHealth(),
      this.loadRawMetrics(),
    ]);
  },

  // ─── System health ────────────────────────────────────
  async loadHealth() {
    const container = document.getElementById('metrics-health');
    if (!container) return;

    container.innerHTML = '<div class="card"><div class="card-title">Проверка...</div></div>';

    const result = await API.get('/v1/admin/system-health');

    if (!result.ok) {
      container.innerHTML = `<div class="card"><div class="card-title text-error">Ошибка: ${this._esc(result.error)}</div></div>`;
      return;
    }

    const h = result.data;
    const overallOk = h.status === 'ok';

    const services = Object.entries(h.services || {}).map(([name, info]) => {
      const isOk = info.status === 'ok';
      const badge = isOk ? 'ok' : 'err';
      const details = [];
      if (info.model) details.push(this._esc(info.model));
      if (info.code) details.push(`code: ${info.code}`);
      if (info.error) details.push(this._esc(info.error));

      return `
        <tr>
          <td><strong>${this._esc(name)}</strong></td>
          <td><span class="badge ${badge}">${this._esc(info.status)}</span></td>
          <td class="muted">${details.join(' · ')}</td>
        </tr>
      `;
    }).join('');

    container.innerHTML = `
      <div class="card">
        <div class="row" style="align-items:center;">
          <div>
            <div class="card-title">Общий статус</div>
            <div class="card-value ${overallOk ? '' : 'text-error'}" style="font-size:22px;">
              ${overallOk ? '● OK' : '● DEGRADED'}
            </div>
          </div>
          <div style="flex:0 0 auto;">
            <span class="muted">timestamp: ${new Date((h.timestamp || 0) * 1000).toLocaleTimeString('ru-RU')}</span>
          </div>
        </div>
      </div>
      <table>
        <thead>
          <tr>
            <th>Сервис</th>
            <th>Статус</th>
            <th>Детали</th>
          </tr>
        </thead>
        <tbody>${services}</tbody>
      </table>
    `;
  },

  // ─── Prometheus targets ───────────────────────────────
  async loadTargets() {
    const container = document.getElementById('metrics-targets');
    if (!container) return;

    container.innerHTML = '<div class="card"><div class="card-title">Загрузка...</div></div>';

    // Prometheus доступен только внутри Docker-сети,
    // поэтому идём через API — но у нас нет такого эндпоинта.
    // Покажем заглушку со ссылкой.

    container.innerHTML = `
      <div class="card">
        <div class="card-title">Prometheus targets</div>
        <p class="mt-8">Прямой доступ к Prometheus — только внутри Docker-сети.
        Проверить состояние всех targets можно через временный контейнер:</p>
        <pre class="mono mt-8" style="background:var(--bg-input);padding:12px;border-radius:6px;overflow-x:auto;">sudo docker run --rm --network nexus-ai_default \\
  curlimages/curl -s \\
  http://nexus-prometheus:9090/api/v1/targets \\
  | jq '.data.activeTargets[] | {job: .labels.job, health: .health}'</pre>
        <p class="muted mt-8">Ожидаемо: 6 targets up (api, model, embeddings, postgres, qdrant, prometheus)</p>
      </div>
    `;
  },

  // ─── Сырые метрики API ────────────────────────────────
  async loadRawMetrics() {
    const pre = document.getElementById('metrics-raw');
    if (!pre) return;

    pre.textContent = 'Загрузка...';

    try {
      const response = await fetch(API.getBaseUrl().replace(/\/$/, '') + '/metrics');
      if (!response.ok) {
        pre.textContent = `Ошибка: HTTP ${response.status}`;
        return;
      }
      const text = await response.text();
      const lines = text.split('\n').slice(0, 50).join('\n');
      pre.textContent = lines + '\n\n... (всего ' + text.split('\n').length + ' строк)';
    } catch (err) {
      pre.textContent = 'Ошибка: ' + err.message;
    }
  },

  // ─── Утилита ──────────────────────────────────────────
  _esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  },
};

// ---
// | KB @CerberRus00 - Nexus Invest Team

