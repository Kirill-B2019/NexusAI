/**
 * NEXUS AI Admin — Projects + API Keys
 */

const Projects = {
  _list: [],
  _currentProject: null,

  // ─── Загрузка списка проектов ─────────────────────────
  async load() {
    const tbody = document.getElementById('projects-tbody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="5" class="muted">Загрузка...</td></tr>';

    const result = await API.get('/v1/projects');

    if (!result.ok) {
      tbody.innerHTML = `<tr><td colspan="5" class="text-error">Ошибка: ${this._esc(result.error)}</td></tr>`;
      return;
    }

    this._list = result.data.projects || [];

    if (this._list.length === 0) {
      tbody.innerHTML = '<tr><td colspan="5" class="muted">Проектов нет</td></tr>';
      return;
    }

    tbody.innerHTML = this._list.map((p) => `
      <tr>
        <td><span class="mono">${this._esc(p.id.slice(0, 8))}…</span></td>
        <td><strong>${this._esc(p.name)}</strong></td>
        <td>${p.external_id ? `<span class="mono">${this._esc(p.external_id)}</span>` : '<span class="muted">—</span>'}</td>
        <td class="muted">${this._fmtDate(p.created_at)}</td>
        <td>
          <button onclick="Projects.showKeys('${this._esc(p.id)}', '${this._esc(p.name)}')" style="font-size:11px;padding:4px 10px;">🔑 Ключи</button>
          <button onclick="Projects.remove('${this._esc(p.id)}', '${this._esc(p.name)}')" class="danger" style="font-size:11px;padding:4px 10px;">✕</button>
        </td>
      </tr>
    `).join('');
  },

  // ─── Создать проект ───────────────────────────────────
  async create() {
    const name = document.getElementById('project-name').value.trim();
    const external_id = document.getElementById('project-external-id').value.trim();

    if (name.length < 2) {
      Toast.error('Название минимум 2 символа');
      return;
    }

    const payload = { name };
    if (external_id) payload.external_id = external_id;

    const result = await API.post('/v1/projects', payload);

    if (!result.ok) {
      Toast.error('Ошибка: ' + result.error);
      return;
    }

    Toast.success('Проект создан');
    document.getElementById('project-name').value = '';
    document.getElementById('project-external-id').value = '';
    this.load();
  },

  // ─── Удалить проект ───────────────────────────────────
  remove(id, name) {
    Modal.confirm(
      `Удалить проект <strong>${this._esc(name)}</strong>?<br><br>
       <span class="text-error">ВНИМАНИЕ:</span> каскадно удалятся все документы, диалоги, решения и задачи проекта.`,
      async () => {
        const result = await API.delete('/v1/projects/' + id);
        if (!result.ok) {
          Toast.error('Ошибка: ' + result.error);
          return;
        }
        Toast.success('Проект удалён');
        this.load();
      }
    );
  },

  // ─── Показать ключи проекта ───────────────────────────
  async showKeys(projectId, projectName) {
    this._currentProject = projectId;

    document.getElementById('keys-section').classList.remove('hidden');
    document.getElementById('keys-project-name').textContent = '— ' + projectName;

    // Загружаем экспертов для чекбоксов (один раз)
    await this._loadExperts();

    await this._loadKeys();
  },

  closeKeys() {
    document.getElementById('keys-section').classList.add('hidden');
    this._currentProject = null;
  },

  // ─── Загрузка экспертов для чекбоксов ─────────────────
  async _loadExperts() {
    const container = document.getElementById('key-experts-checkboxes');
    if (!container) return;

    // Если уже загружены — пропускаем
    if (container.querySelector('input')) return;

    const result = await API.get('/v1/experts');
    if (!result.ok) {
      container.innerHTML = `<span class="text-error">Ошибка загрузки экспертов: ${this._esc(result.error)}</span>`;
      return;
    }

    const experts = result.data.experts || [];

    container.innerHTML = experts.map((e) => `
      <label style="display:inline-flex;align-items:center;gap:6px;margin:6px 14px 0 0;text-transform:none;font-size:13px;">
        <input type="checkbox" value="${this._esc(e.key)}">
        ${this._esc(e.icon || '🤖')} ${this._esc(e.name)}
      </label>
    `).join('');
  },

  async _loadKeys() {
    const tbody = document.getElementById('keys-tbody');
    if (!tbody || !this._currentProject) return;

    tbody.innerHTML = '<tr><td colspan="7" class="muted">Загрузка...</td></tr>';

    const result = await API.get('/v1/projects/' + this._currentProject + '/keys');

    if (!result.ok) {
      tbody.innerHTML = `<tr><td colspan="7" class="text-error">Ошибка: ${this._esc(result.error)}</td></tr>`;
      return;
    }

    const keys = result.data.keys || [];

    if (keys.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" class="muted">Ключей нет</td></tr>';
      return;
    }

    tbody.innerHTML = keys.map((k) => {
      const allowed = Array.isArray(k.allowed_experts) && k.allowed_experts.length
        ? k.allowed_experts.map((e) => this._esc(e)).join(', ')
        : '<span class="muted">все</span>';
      const lastUsed = k.last_used_at ? this._fmtDate(k.last_used_at) : '<span class="muted">не использован</span>';
      const expires = k.expires_at
        ? this._fmtDate(k.expires_at)
        : '<span class="muted">постоянный</span>';

      return `
        <tr>
          <td><span class="mono">${this._esc(k.key_prefix)}…</span></td>
          <td>${this._esc(k.name)}</td>
          <td class="muted" style="max-width:250px;">${allowed}</td>
          <td>${k.rate_limit_per_min}</td>
          <td class="muted">${lastUsed}</td>
          <td class="muted">${expires}</td>
          <td>
            ${k.is_active
              ? `<button onclick="Projects.revokeKey('${this._esc(k.id)}')" class="danger" style="font-size:11px;padding:4px 10px;">✕ Отозвать</button>`
              : `<span class="badge gray">отозван</span>`}
          </td>
        </tr>
      `;
    }).join('');
  },

  // ─── Rate limit: показать поле «свой» ─────────────────
  onRateLimitChange() {
    const select = document.getElementById('key-rate-limit');
    const customInput = document.getElementById('key-rate-limit-custom');
    if (select && customInput) {
      customInput.classList.toggle('hidden', select.value !== 'custom');
    }
  },

  // ─── Срок действия: показать/скрыть календарь ─────────
  onExpiresChange() {
    const mode = document.getElementById('key-expires-mode').value;
    const customBlock = document.getElementById('key-expires-custom');
    if (customBlock) {
      customBlock.classList.toggle('hidden', mode !== 'custom');
    }
  },

  // ─── Создать ключ ─────────────────────────────────────
  async createKey() {
    if (!this._currentProject) return;

    const name = document.getElementById('key-name').value.trim();
    if (name.length < 2) {
      Toast.error('Название ключа минимум 2 символа');
      return;
    }

    // Allowed experts — из чекбоксов
    const checked = Array.from(
      document.querySelectorAll('#key-experts-checkboxes input:checked')
    ).map((c) => c.value);
    const allowed_experts = checked.length > 0 ? checked : null;

    // Rate limit
    const rateSelect = document.getElementById('key-rate-limit').value;
    const rateLimit = rateSelect === 'custom'
      ? parseInt(document.getElementById('key-rate-limit-custom').value, 10) || 60
      : parseInt(rateSelect, 10);

    // Срок действия
    const expiresMode = document.getElementById('key-expires-mode').value;
    let expires_at = null;

    if (expiresMode === '0') {
      expires_at = null;
    } else if (expiresMode === 'custom') {
      const fromVal = document.getElementById('key-expires-from').value;
      const toVal = document.getElementById('key-expires-to').value;

      if (!toVal) {
        Toast.error('Укажите дату окончания');
        return;
      }

      if (fromVal && new Date(toVal) < new Date(fromVal)) {
        Toast.error('Дата окончания должна быть позже даты начала');
        return;
      }

      const toDate = new Date(toVal + 'T23:59:59');
      expires_at = toDate.toISOString();
    } else {
      const days = parseInt(expiresMode, 10);
      const future = new Date();
      future.setDate(future.getDate() + days);
      expires_at = future.toISOString();
    }

    const payload = {
      name,
      rate_limit_per_min: rateLimit,
    };
    if (allowed_experts) payload.allowed_experts = allowed_experts;
    if (expires_at) payload.expires_at = expires_at;

    const result = await API.post('/v1/projects/' + this._currentProject + '/keys', payload);

    if (!result.ok) {
      Toast.error('Ошибка: ' + result.error);
      return;
    }

    const key = result.data.key;
    const warning = result.data.warning || 'Сохраните ключ — он больше не будет показан';

    // Очистить форму
    document.getElementById('key-name').value = '';
    document.querySelectorAll('#key-experts-checkboxes input:checked')
      .forEach((c) => (c.checked = false));

    this._showKeyModal(key, warning);
    this._loadKeys();
  },

  // ─── Модальное окно с новым ключом ────────────────────
  _showKeyModal(key, warning) {
    const html = `
      <h3>🔑 Новый API-ключ</h3>

      <div class="card" style="background: rgba(197, 245, 66, 0.08); border-color: var(--accent-dim);">
        <div class="card-title text-warn">⚠ ${this._esc(warning)}</div>
      </div>

      <label>Ключ</label>
      <div class="row">
        <input type="text" id="new-key-value" value="${this._esc(key)}" readonly class="mono">
        <button id="new-key-copy" class="success">📋 Копировать</button>
      </div>

      <div class="row mt-16">
        <button onclick="Modal.close()" class="secondary">Закрыть</button>
      </div>
    `;

    Modal.open(html, { closeOnBg: false });

    document.getElementById('new-key-copy').onclick = () => {
      const input = document.getElementById('new-key-value');
      input.select();
      navigator.clipboard.writeText(key).then(() => {
        Toast.success('Скопировано в буфер');
      }).catch(() => {
        document.execCommand('copy');
        Toast.success('Скопировано');
      });
    };
  },

  // ─── Отозвать ключ ────────────────────────────────────
  revokeKey(keyId) {
    Modal.confirm(
      'Отозвать этот ключ?<br><br>Все запросы с ним начнут возвращать 401.',
      async () => {
        const result = await API.delete('/v1/projects/' + this._currentProject + '/keys/' + keyId);
        if (!result.ok) {
          Toast.error('Ошибка: ' + result.error);
          return;
        }
        Toast.success('Ключ отозван');
        this._loadKeys();
      }
    );
  },

  // ─── Утилиты ──────────────────────────────────────────
  _fmtDate(iso) {
    if (!iso) return '—';
    try {
      return new Date(iso).toLocaleString('ru-RU', {
        day: '2-digit', month: '2-digit', year: '2-digit',
        hour: '2-digit', minute: '2-digit',
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
