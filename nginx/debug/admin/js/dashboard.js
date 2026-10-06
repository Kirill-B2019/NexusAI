/**
 * NEXUS AI Admin — Dashboard
 * Stats + system-health + быстрые ссылки
 */

const Dashboard = {
  // ─── Загрузка всего ───────────────────────────────────
  async load() {
    await Promise.all([
      this.loadStats(),
      this.loadHealth(),
    ]);
  },

  // ─── Stats ────────────────────────────────────────────
  async loadStats() {
    const grid = document.getElementById('stats-grid');
    if (!grid) return;

    grid.innerHTML = '<div class="card"><div class="card-title">Загрузка...</div></div>';

    const result = await API.get('/v1/admin/stats');

    if (!result.ok) {
      grid.innerHTML = `<div class="card"><div class="card-title text-error">Ошибка: ${this._esc(result.error)}</div></div>`;
      return;
    }

    const s = result.data;

    const cards = [
      { title: 'Проекты', value: s.projects, accent: true },
      { title: 'Документы', value: `${s.documents.ready} / ${s.documents.total}`, sub: s.documents.failed ? `${s.documents.failed} failed` : null },
      { title: 'Чанки', value: s.chunks },
      { title: 'Диалоги', value: s.conversations },
      { title: 'Сообщения', value: s.messages },
      { title: 'Решения', value: s.decisions },
      { title: 'Задачи', value: s.tasks },
      { title: 'Эксперты', value: `${s.experts.enabled} / ${s.experts.total}` },
      { title: 'API-ключи', value: `${s.api_keys.active} / ${s.api_keys.total}` },
      { title: 'Событий за 24ч', value: s.audit_24h.total, sub: `chat: ${s.audit_24h.chat_requests}` },
    ];

    grid.innerHTML = cards.map((c) => `
      <div class="card">
        <div class="card-title">${c.title}</div>
        <div class="card-value">${this._esc(c.value)}</div>
        ${c.sub ? `<div class="muted mt-8">${this._esc(c.sub)}</div>` : ''}
      </div>
    `).join('');
  },

  // ─── System health ────────────────────────────────────
  async loadHealth() {
    const container = document.getElementById('system-health');
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

  // ─── Утилита: escape HTML ─────────────────────────────
  _esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  },
};

// ---
// | KB @CerberRus00 - Nexus Invest Team
