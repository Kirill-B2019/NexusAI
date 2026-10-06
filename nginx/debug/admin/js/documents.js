/**
 * NEXUS AI Admin — Documents + RAG
 */

const Documents = {
  _list: [],
  _pollTimer: null,

  // ─── Инициализация (заполнить select проектами) ───────
  async init() {
    const select = document.getElementById('doc-project-select');
    if (!select) return;

    // Сохранить текущее значение
    const current = select.value;

    const result = await API.get('/v1/projects');
    if (!result.ok) return;

    const projects = result.data.projects || [];
    select.innerHTML = '<option value="">— выберите проект —</option>' +
      projects.map((p) => `<option value="${this._esc(p.id)}">${this._esc(p.name)}</option>`).join('');

    if (current) select.value = current;

    // Если проект уже выбран — загрузить список
    if (select.value) this.load();
  },

  // ─── Загрузка документов выбранного проекта ───────────
  async load() {
    const select = document.getElementById('doc-project-select');
    const tbody = document.getElementById('documents-tbody');
    if (!select || !tbody) return;

    const projectId = select.value;
    if (!projectId) {
      tbody.innerHTML = '<tr><td colspan="6" class="muted">Выберите проект</td></tr>';
      return;
    }

    tbody.innerHTML = '<tr><td colspan="6" class="muted">Загрузка...</td></tr>';

    const result = await API.get('/v1/projects/' + projectId + '/documents');

    if (!result.ok) {
      tbody.innerHTML = `<tr><td colspan="6" class="text-error">Ошибка: ${this._esc(result.error)}</td></tr>`;
      return;
    }

    this._list = result.data.documents || [];

    if (this._list.length === 0) {
      tbody.innerHTML = '<tr><td colspan="6" class="muted">Документов нет</td></tr>';
      return;
    }

    tbody.innerHTML = this._list.map((d) => {
      const statusClass = {
        pending: 'warn',
        processing: 'warn',
        ready: 'ok',
        failed: 'err',
      }[d.status] || 'gray';

      const size = this._fmtSize(d.file_size);
      const date = this._fmtDate(d.created_at);

      return `
        <tr>
          <td>${this._esc(d.original_filename)}</td>
          <td class="muted">${size}</td>
          <td><span class="badge ${statusClass}">${this._esc(d.status)}</span></td>
          <td>${d.chunks_count}</td>
          <td class="muted">${date}</td>
          <td>
            ${d.status === 'ready' ? `<button onclick="Documents.ask('${this._esc(d.id)}', '${this._esc(d.original_filename)}')" class="success" style="font-size:11px;padding:4px 8px;" title="Спросить">💬</button>` : ''}
            ${d.status === 'failed' ? `<button onclick="Documents.showError('${this._esc(d.id)}')" style="font-size:11px;padding:4px 8px;" title="Показать ошибку">⚠</button>` : ''}
            <button onclick="Documents.reindex('${this._esc(d.id)}')" style="font-size:11px;padding:4px 8px;" title="Переиндексировать">↻</button>
            <button onclick="Documents.remove('${this._esc(d.id)}', '${this._esc(d.original_filename)}')" class="danger" style="font-size:11px;padding:4px 8px;" title="Удалить">✕</button>
          </td>
        </tr>
      `;
    }).join('');

    // Если есть pending/processing — запустить polling
    if (this._list.some((d) => d.status === 'pending' || d.status === 'processing')) {
      this._startPolling();
    }
  },

  // ─── Автообновление статуса ───────────────────────────
  _startPolling() {
    if (this._pollTimer) clearTimeout(this._pollTimer);
    this._pollTimer = setTimeout(() => this.load(), 3000);
  },

  _stopPolling() {
    if (this._pollTimer) {
      clearTimeout(this._pollTimer);
      this._pollTimer = null;
    }
  },

  // ─── Загрузка файла ───────────────────────────────────
  async upload(file) {
    const select = document.getElementById('doc-project-select');
    const progress = document.getElementById('upload-progress');

    if (!select || !select.value) {
      Toast.error('Сначала выберите проект');
      return;
    }

    if (!file) return;

    if (file.size > 50 * 1024 * 1024) {
      Toast.error('Файл больше 50 МБ');
      return;
    }

    progress.textContent = `Загрузка ${file.name} (${this._fmtSize(file.size)})...`;

    const projectId = select.value;
    const result = await API.upload('/v1/projects/' + projectId + '/documents', file);

    // Сбросить input, чтобы можно было загрузить тот же файл повторно
    const fileInput = document.getElementById('doc-file');
    if (fileInput) fileInput.value = '';

    if (!result.ok) {
      progress.textContent = '';
      Toast.error('Ошибка: ' + result.error);
      return;
    }

    progress.textContent = `✓ ${file.name} загружен, обработка запущена`;
    Toast.success('Файл загружен');

    setTimeout(() => { progress.textContent = ''; }, 5000);

    this.load();
  },

  // ─── Показать ошибку обработки ────────────────────────
  async showError(docId) {
    const result = await API.get('/v1/documents/' + docId);
    if (!result.ok) {
      Toast.error('Ошибка загрузки: ' + result.error);
      return;
    }

    const d = result.data;
    Modal.open(`
      <h3>⚠ Ошибка обработки</h3>
      <p class="mt-8"><strong>Файл:</strong> ${this._esc(d.original_filename)}</p>
      <label>Статус</label>
      <input type="text" value="${this._esc(d.status)}" readonly>
      <label>Ошибка</label>
      <textarea readonly style="min-height:150px;">${this._esc(d.error || 'нет данных')}</textarea>
      <div class="row mt-16">
        <button onclick="Modal.close()" class="secondary">Закрыть</button>
      </div>
    `);
  },

  // ─── Переиндексация ───────────────────────────────────
  reindex(docId) {
    Modal.confirm(
      'Переиндексировать документ?<br><br>Старые векторы будут удалены, документ обработается заново.',
      async () => {
        const result = await API.post('/v1/documents/' + docId + '/reindex');
        if (!result.ok) {
          Toast.error('Ошибка: ' + result.error);
          return;
        }
        Toast.success('Переиндексация запущена');
        this.load();
      }
    );
  },

  // ─── Удалить документ ─────────────────────────────────
  remove(docId, filename) {
    Modal.confirm(
      `Удалить <strong>${this._esc(filename)}</strong>?<br><br>
       <span class="text-warn">Будут удалены:</span> файл, чанки, векторы в Qdrant.`,
      async () => {
        const result = await API.delete('/v1/documents/' + docId);
        if (!result.ok) {
          Toast.error('Ошибка: ' + result.error);
          return;
        }
        Toast.success('Документ удалён');
        this.load();
      }
    );
  },

  // ─── Спросить по документу ────────────────────────────
  ask(docId, filename) {
    const html = `
      <h3>💬 Вопрос по документу</h3>
      <p class="muted mt-8">${this._esc(filename)}</p>

      <label>Вопрос</label>
      <textarea id="ask-message" placeholder="Например: о чём этот документ?" style="min-height:80px;"></textarea>

      <label>Эксперт</label>
      <select id="ask-expert">
        <option value="system_architect">🏗 Системный архитектор</option>
        <option value="software_engineer">💻 Инженер-программист</option>
        <option value="fintech">💰 Финтех-эксперт</option>
        <option value="digital_law">⚖️ Цифровое право</option>
        <option value="project_scoring">📊 Скоринг проектов</option>
        <option value="investment_advisor">📈 Инвестсоветник</option>
      </select>

      <div class="row mt-16">
        <button id="ask-submit" class="success">Спросить</button>
        <button onclick="Modal.close()" class="secondary">Отмена</button>
      </div>

      <div id="ask-result" class="mt-16"></div>
    `;

    Modal.open(html, { closeOnBg: false });

    document.getElementById('ask-submit').onclick = () => this._submitAsk(docId);
  },

  async _submitAsk(docId) {
    const message = document.getElementById('ask-message').value.trim();
    const expert = document.getElementById('ask-expert').value;

    if (!message) {
      Toast.error('Введите вопрос');
      return;
    }

    const btn = document.getElementById('ask-submit');
    const result = document.getElementById('ask-result');

    btn.disabled = true;
    btn.textContent = 'Думаем... (~1-2 мин)';

    result.innerHTML = '<div class="muted">⏳ Модель обрабатывает вопрос...</div>';

    const apiResult = await API.post('/v1/documents/' + docId + '/ask', {
      message,
      expert,
      rag_top_k: 6,
      rag_min_score: 0.4,
    });

    btn.disabled = false;
    btn.textContent = 'Спросить';

    if (!apiResult.ok) {
      result.innerHTML = `<div class="text-error">Ошибка: ${this._esc(apiResult.error)}</div>`;
      return;
    }

    const data = apiResult.data;

    if (!data.rag_used) {
      result.innerHTML = `<div class="muted">📄 ${this._esc(data.message || 'Нет данных')}</div>`;
      return;
    }

    const sources = (data.sources || []).map((s) =>
      `<div class="muted">[${s.index}] чанк ${s.chunk_index}, score ${s.score.toFixed(3)}</div>`
    ).join('');

    result.innerHTML = `
      <div class="chat-msg ai" style="max-width:100%;">
        ${this._esc(data.content).replace(/\n/g, '<br>')}
      </div>
      ${sources ? `<div class="mt-8"><strong>Источники:</strong>${sources}</div>` : ''}
    `;
  },

  // ─── Утилиты ──────────────────────────────────────────
  _fmtSize(bytes) {
    if (!bytes) return '—';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
  },

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
