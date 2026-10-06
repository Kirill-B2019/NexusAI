/**
 * NEXUS AI Admin — Audit log
 */

const Audit = {
  // ─── Загрузка списка событий ──────────────────────────
  async load() {
    const tbody = document.getElementById('audit-tbody');
    if (!tbody) return;

    const action = document.getElementById('audit-action')?.value.trim() || '';
    const projectId = document.getElementById('audit-project')?.value.trim() || '';
    const limit = parseInt(document.getElementById('audit-limit')?.value || '50', 10);

    const params = new URLSearchParams();
    if (action) params.set('action', action);
    if (projectId) params.set('project_id', projectId);
    params.set('limit', limit);

    tbody.innerHTML = '<tr><td colspan="6" class="muted">Загрузка...</td></tr>';

    const result = await API.get('/v1/admin/audit?' + params.toString());

    if (!result.ok) {
      tbody.innerHTML = `<tr><td colspan="6" class="text-error">Ошибка: ${this._esc(result.error)}</td></tr>`;
      return;
    }

    const events = result.data.events || [];

    if (events.length === 0) {
      tbody.innerHTML = '<tr><td colspan="6" class="muted">Событий нет</td></tr>';
      return;
    }

    tbody.innerHTML = events.map((e) => {
      const details = e.details
        ? JSON.stringify(e.details).slice(0, 80) + (JSON.stringify(e.details).length > 80 ? '…' : '')
        : '<span class="muted">—</span>';

      return `
        <tr>
          <td class="muted mono" style="white-space:nowrap;">${this._fmtDate(e.created_at)}</td>
          <td><span class="badge ${e.actor === 'admin' ? 'ok' : 'gray'}">${this._esc(e.actor)}</span></td>
          <td class="mono" style="font-size:11px;">${this._esc(e.action)}</td>
          <td class="muted">${this._esc(e.resource_type || '')}${e.resource_id ? ' <span class="mono">' + this._esc(e.resource_id.slice(0, 8)) + '…</span>' : ''}</td>
          <td class="muted mono" style="font-size:11px;">${e.project_id ? this._esc(e.project_id.slice(0, 8)) + '…' : '—'}</td>
          <td class="muted" style="font-size:11px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${this._esc(JSON.stringify(e.details || {}))}">${details}</td>
        </tr>
      `;
    }).join('');
  },

  // ─── Утилиты ──────────────────────────────────────────
  _fmtDate(iso) {
    if (!iso) return '—';
    try {
      return new Date(iso).toLocaleString('ru-RU', {
        day: '2-digit', month: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
      });
    } catch (e) {
      return iso;
    }
  },

  _esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  },
};

// ---
// | KB @CerberRus00 - Nexus Invest Team
