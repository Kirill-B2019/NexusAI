/**
 * NEXUS AI Admin — Experts CRUD
 */

const Experts = {
  _list: [],

  // ─── Загрузка списка ──────────────────────────────────
  async load() {
    const tbody = document.getElementById('experts-tbody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="6" class="muted">Загрузка...</td></tr>';

    const result = await API.get('/v1/experts/all');

    if (!result.ok) {
      tbody.innerHTML = `<tr><td colspan="6" class="text-error">Ошибка: ${this._esc(result.error)}</td></tr>`;
      return;
    }

    this._list = result.data.experts || [];

    if (this._list.length === 0) {
      tbody.innerHTML = '<tr><td colspan="6" class="muted">Экспертов нет</td></tr>';
      return;
    }

    tbody.innerHTML = this._list.map((e) => {
      const enabled = e.is_enabled;
      const badge = enabled ? 'ok' : 'gray';
      const kwCount = Array.isArray(e.keywords) ? e.keywords.length : 0;
      const sys = e.is_system ? '<span class="badge gray" style="margin-left:6px;">sys</span>' : '';

      return `
        <tr>
          <td style="font-size:20px;">${this._esc(e.icon || '🤖')}</td>
          <td>
            <span class="mono">${this._esc(e.key)}</span>
            ${sys}
          </td>
          <td><strong style="color:${this._esc(e.color || '#fff')}">${this._esc(e.name)}</strong></td>
          <td><span class="badge ${badge}">${enabled ? 'вкл' : 'выкл'}</span></td>
          <td><span class="muted">${kwCount}</span></td>
          <td>
            <button onclick="Experts.edit('${this._esc(e.key)}')" style="font-size:11px;padding:4px 10px;">✎</button>
            <button onclick="Experts.toggle('${this._esc(e.key)}', ${!enabled})" style="font-size:11px;padding:4px 10px;" class="secondary">${enabled ? '⏸' : '▶'}</button>
            ${!e.is_system ? `<button onclick="Experts.remove('${this._esc(e.key)}')" class="danger" style="font-size:11px;padding:4px 10px;">✕</button>` : ''}
          </td>
        </tr>
      `;
    }).join('');
  },

  // ─── Открыть форму создания ───────────────────────────
  create() {
    this._form(null);
  },

  // ─── Открыть форму редактирования ─────────────────────
  async edit(key) {
    const result = await API.get('/v1/experts/' + encodeURIComponent(key));
    if (!result.ok) {
      Toast.error('Ошибка загрузки: ' + result.error);
      return;
    }
    this._form(result.data);
  },

  // ─── Форма (создание / редактирование) ────────────────
  _form(expert) {
    const isEdit = !!expert;
    const e = expert || {
      key: '',
      name: '',
      description: '',
      system_prompt: '',
      keywords: [],
      icon: '🤖',
      color: '#c5f542',
      sort_order: 100,
      is_enabled: true,
      metadata: {},
    };

    const kwString = Array.isArray(e.keywords) ? e.keywords.join(', ') : '';

    const html = `
      <h3>${isEdit ? 'Редактировать' : 'Создать'} эксперта</h3>

      <label>Ключ (латиница, a-z0-9_)</label>
      <input type="text" id="ex-key" value="${this._esc(e.key)}" ${isEdit ? 'disabled' : ''} placeholder="cybersec_expert">

      <label>Имя</label>
      <input type="text" id="ex-name" value="${this._esc(e.name)}" placeholder="Эксперт по кибербезопасности">

      <label>Описание</label>
      <input type="text" id="ex-desc" value="${this._esc(e.description || '')}" placeholder="Краткое описание области">

      <label>System prompt</label>
      <textarea id="ex-prompt" style="min-height:200px;">${this._esc(e.system_prompt)}</textarea>

      <label>Keywords (через запятую)</label>
      <input type="text" id="ex-keywords" value="${this._esc(kwString)}" placeholder="безопасность, OWASP, penetration test">

      <div class="row">
        <div>
          <label>Иконка (emoji)</label>
          <input type="text" id="ex-icon" value="${this._esc(e.icon || '')}" maxlength="4">
        </div>
        <div>
          <label>Цвет (hex)</label>
          <input type="text" id="ex-color" value="${this._esc(e.color || '#c5f542')}" maxlength="9">
        </div>
        <div>
          <label>Sort order</label>
          <input type="number" id="ex-sort" value="${e.sort_order || 100}">
        </div>
      </div>

      <label style="display:flex;align-items:center;gap:6px;margin-top:16px;text-transform:none;">
        <input type="checkbox" id="ex-enabled" ${e.is_enabled ? 'checked' : ''}> Включён
      </label>

      <div class="row mt-16">
        <button id="ex-save" class="success">💾 Сохранить</button>
        <button onclick="Modal.close()" class="secondary">Отмена</button>
      </div>
    `;

    Modal.open(html);

    document.getElementById('ex-save').onclick = () => this._save(isEdit ? e.key : null);
  },

  // ─── Сохранение ───────────────────────────────────────
  async _save(editKey) {
    const key = document.getElementById('ex-key').value.trim();
    const name = document.getElementById('ex-name').value.trim();
    const description = document.getElementById('ex-desc').value.trim();
    const system_prompt = document.getElementById('ex-prompt').value.trim();
    const keywordsStr = document.getElementById('ex-keywords').value.trim();
    const icon = document.getElementById('ex-icon').value.trim();
    const color = document.getElementById('ex-color').value.trim();
    const sort_order = parseInt(document.getElementById('ex-sort').value, 10) || 100;
    const is_enabled = document.getElementById('ex-enabled').checked;

    // Валидация
    if (!editKey && !/^[a-z][a-z0-9_]{1,63}$/.test(key)) {
      Toast.error('Ключ: строчные латинские буквы, начиная с буквы, a-z0-9_');
      return;
    }
    if (name.length < 2) {
      Toast.error('Имя минимум 2 символа');
      return;
    }
    if (system_prompt.length < 20) {
      Toast.error('System prompt минимум 20 символов');
      return;
    }

    const keywords = keywordsStr
      ? keywordsStr.split(',').map((s) => s.trim()).filter((s) => s)
      : [];

    const payload = {
      name,
      description: description || null,
      system_prompt,
      keywords,
      icon: icon || null,
      color: color || null,
      sort_order,
      is_enabled,
    };

    document.getElementById('ex-save').disabled = true;
    document.getElementById('ex-save').textContent = 'Сохранение...';

    let result;
    if (editKey) {
      result = await API.patch('/v1/experts/' + encodeURIComponent(editKey), payload);
    } else {
      result = await API.post('/v1/experts', { ...payload, key });
    }

    if (!result.ok) {
      Toast.error('Ошибка: ' + result.error);
      document.getElementById('ex-save').disabled = false;
      document.getElementById('ex-save').textContent = '💾 Сохранить';
      return;
    }

    Toast.success(editKey ? 'Эксперт обновлён' : 'Эксперт создан');
    Modal.close();
    this.load();
  },

  // ─── Включить / отключить ─────────────────────────────
  async toggle(key, enable) {
    const action = enable ? 'enable' : 'disable';
    const result = await API.post('/v1/experts/' + encodeURIComponent(key) + '/' + action);

    if (!result.ok) {
      Toast.error('Ошибка: ' + result.error);
      return;
    }

    Toast.success(enable ? 'Эксперт включён' : 'Эксперт отключён');
    this.load();
  },

  // ─── Удалить ──────────────────────────────────────────
  remove(key) {
    Modal.confirm(`Удалить эксперта <span class="mono">${this._esc(key)}</span>?<br><br>Это soft delete — эксперт останется в БД, но не будет доступен.`, async () => {
      const result = await API.delete('/v1/experts/' + encodeURIComponent(key));

      if (!result.ok) {
        Toast.error('Ошибка: ' + result.error);
        return;
      }

      Toast.success('Эксперт удалён');
      this.load();
    });
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
