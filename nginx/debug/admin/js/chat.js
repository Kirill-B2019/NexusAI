/**
 * NEXUS AI Admin — Chat (3 режима + RAG + thinking + копирование)
 */

const Chat = {
  _inited: false,
  _sending: false,
  _experts: [],

  // ─── Инициализация ────────────────────────────────────
  async init() {
    if (this._inited) return;

    // Загрузить экспертов
    const result = await API.get('/v1/experts');
    if (result.ok) {
      this._experts = result.data.experts || [];
      this._fillExpertSelects();
    }

    this._inited = true;
    this.onModeChange();
  },

  // ─── Заполнить селекты экспертов ──────────────────────
  _fillExpertSelects() {
    const single = document.getElementById('chat-expert');
    if (single) {
      single.innerHTML = this._experts.map((e) =>
        `<option value="${this._esc(e.key)}">${this._esc(e.icon || '🤖')} ${this._esc(e.name)}</option>`
      ).join('');
    }

    const checkboxes = document.getElementById('chat-experts-checkboxes');
    if (checkboxes) {
      checkboxes.innerHTML = this._experts.map((e) => `
        <label style="display:inline-flex;align-items:center;gap:6px;margin:6px 12px 0 0;text-transform:none;">
          <input type="checkbox" value="${this._esc(e.key)}">
          ${this._esc(e.icon || '🤖')} ${this._esc(e.name)}
        </label>
      `).join('');
    }
  },

  // ─── Переключение режима ──────────────────────────────
  onModeChange() {
    const mode = document.getElementById('chat-mode')?.value || 'auto';
    const singleBlock = document.getElementById('chat-single-block');
    const manualBlock = document.getElementById('chat-manual-block');

    if (singleBlock) singleBlock.classList.toggle('hidden', mode !== 'single');
    if (manualBlock) manualBlock.classList.toggle('hidden', mode !== 'manual');
  },

  // ─── Очистить чат ─────────────────────────────────────
  clear() {
    const box = document.getElementById('chat-box');
    if (box) {
      box.innerHTML = '<div class="muted" style="text-align:center;padding:40px 0;">Введите вопрос</div>';
    }
  },

  // ─── Отправить сообщение ──────────────────────────────
  async send() {
    if (this._sending) return;

    const input = document.getElementById('chat-message');
    const message = input.value.trim();
    if (!message) return;

    const mode = document.getElementById('chat-mode').value;
    const projectId = document.getElementById('chat-project').value.trim() || null;
    const thinking = document.getElementById('chat-thinking').checked;
    const useRag = document.getElementById('chat-rag').checked;

    let expert = null;
    let experts = null;

    if (mode === 'single') {
      expert = document.getElementById('chat-expert').value;
    } else if (mode === 'manual') {
      experts = Array.from(
        document.querySelectorAll('#chat-experts-checkboxes input:checked')
      ).map((c) => c.value);

      if (experts.length === 0) {
        Toast.error('Выберите хотя бы одного эксперта');
        return;
      }
      if (experts.length > 4) {
        Toast.error('Максимум 4 эксперта');
        return;
      }
    }

    // Показать сообщение пользователя
    this._appendUser(message);
    input.value = '';
    this._sending = true;
    const sendBtn = document.getElementById('chat-send-btn');
    if (sendBtn) sendBtn.disabled = true;

    // Индикатор загрузки
    const loadingId = 'chat-loading-' + Date.now();
    const hint = mode === 'manual' || mode === 'auto'
      ? '⏳ Оркестрация (может занять 3-8 минут)...'
      : (thinking ? '⏳ Thinking (2-5 минут)...' : '⏳ Генерация...');
    this._appendRaw(`<div class="chat-msg ai" id="${loadingId}">${hint}</div>`);

    // Формируем payload
    const payload = {
      message,
      thinking,
      use_rag: useRag,
      orchestrate: mode === 'auto',
    };
    if (expert) payload.expert = expert;
    if (experts) payload.experts = experts;
    if (projectId) payload.project_id = projectId;

    // Отправка
    const result = await API.post('/v1/chat', payload);

    // Убрать индикатор
    const loading = document.getElementById(loadingId);
    if (loading) loading.remove();

    if (sendBtn) sendBtn.disabled = false;
    this._sending = false;

    if (!result.ok) {
      this._appendError('Ошибка: ' + result.error);
      return;
    }

    this._renderResponse(result.data, mode);
  },

  // ─── Рендер ответа ────────────────────────────────────
  _renderResponse(data, mode) {
    // Single
    if (data.mode === 'single') {
      let html = '<div class="chat-msg ai">';
      html += '<button class="copy-btn" onclick="Chat.copyMessage(this)">📋 Копировать</button>';

      if (data.reasoning) {
        html += `<details style="margin-bottom:8px;"><summary class="muted" style="cursor:pointer;">💭 Размышления</summary><div class="muted" style="margin-top:8px;white-space:pre-wrap;">${this._esc(data.reasoning)}</div></details>`;
      }

      html += `<div class="content">${this._esc(data.content || '').replace(/\n/g, '<br>')}</div>`;

      // Мета
      const meta = [];
      if (data.expert) meta.push(`эксперт: ${this._esc(data.expert)}`);
      if (data.elapsed_s) meta.push(`${data.elapsed_s}s`);
      if (data.timings && data.timings.predicted_per_second) {
        meta.push(`${data.timings.predicted_per_second.toFixed(2)} tok/s`);
      }
      if (data.rag_used && data.sources) meta.push(`RAG: ${data.sources.length} источников`);

      if (meta.length) {
        html += `<div class="muted mt-8" style="font-size:11px;">${meta.join(' · ')}</div>`;
      }

      html += '</div>';
      this._appendRaw(html);
      return;
    }

    // Orchestrated
    let html = '<div class="chat-msg ai" style="max-width:95%;">';
    html += '<button class="copy-btn" onclick="Chat.copyMessage(this)">📋 Копировать</button>';

    // Мета-шапка
    const meta = [];
    meta.push(`режим: ${this._esc(data.mode)}`);
    if (data.experts_used) meta.push(`эксперты: ${data.experts_used.map((e) => this._esc(e)).join(', ')}`);
    if (data.elapsed_s) meta.push(`${data.elapsed_s}s`);
    if (data.rag_used && data.sources) meta.push(`RAG: ${data.sources.length} источников`);

    html += `<div class="muted" style="font-size:11px;margin-bottom:12px;">${meta.join(' · ')}</div>`;

    // Программный агрегатор — уже готовый markdown
    if (data.aggregated) {
      html += `<div class="content">${this._renderMarkdown(data.aggregated)}</div>`;
    } else if (data.results) {
      // Fallback — по результатам
      data.results.forEach((r) => {
        if (!r.ok) return;
        html += `<div style="margin:16px 0;padding:12px;border-left:3px solid var(--accent);">`;
        html += `<strong>${this._esc(r.expert)}</strong>`;
        html += `<div style="margin-top:8px;">${this._esc(r.content || '').replace(/\n/g, '<br>')}</div>`;
        html += `</div>`;
      });
    }

    html += '</div>';
    this._appendRaw(html);
  },

  // ─── Простой Markdown → HTML ──────────────────────────
  _renderMarkdown(text) {
    if (!text) return '';
    let html = this._esc(text);

    // Заголовки ## и ###
    html = html.replace(/^### (.+)$/gm, '<h3 style="margin:12px 0 8px;font-size:14px;color:var(--accent);">$1</h3>');
    html = html.replace(/^## (.+)$/gm, '<h2 style="margin:16px 0 8px;font-size:16px;">$1</h2>');

    // Жирный
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

    // Inline code
    html = html.replace(/`([^`]+)`/g, '<code style="background:var(--bg-input);padding:2px 6px;border-radius:3px;font-size:12px;">$1</code>');

    // Переносы
    html = html.replace(/\n/g, '<br>');

    return html;
  },

  // ─── Копировать сообщение ─────────────────────────────
  copyMessage(btn) {
    const msg = btn.closest('.chat-msg');
    if (!msg) return;

    // Собираем текст из .content, или из всего блока без кнопки
    const content = msg.querySelector('.content');
    const text = content ? content.innerText : msg.innerText;

    // Убираем префикс "Копировать" если он попал
    const clean = text.replace(/^📋 Копировать\s*/i, '').trim();

    navigator.clipboard.writeText(clean).then(() => {
      const original = btn.textContent;
      btn.textContent = '✓ Скопировано';
      setTimeout(() => { btn.textContent = original; }, 1500);
    }).catch(() => {
      // Fallback
      const textarea = document.createElement('textarea');
      textarea.value = clean;
      document.body.appendChild(textarea);
      textarea.select();
      document.execCommand('copy');
      document.body.removeChild(textarea);
      btn.textContent = '✓ Скопировано';
      setTimeout(() => { btn.textContent = '📋 Копировать'; }, 1500);
    });
  },

  // ─── Вспомогательные ──────────────────────────────────
  _appendUser(text) {
    const box = document.getElementById('chat-box');
    if (!box) return;
    if (box.querySelector('.muted[style*="text-align:center"]')) {
      box.innerHTML = '';
    }
    box.innerHTML += `<div class="chat-msg user">${this._esc(text).replace(/\n/g, '<br>')}</div>`;
    box.scrollTop = box.scrollHeight;
  },

  _appendRaw(html) {
    const box = document.getElementById('chat-box');
    if (!box) return;
    box.innerHTML += html;
    box.scrollTop = box.scrollHeight;
  },

  _appendError(text) {
    const box = document.getElementById('chat-box');
    if (!box) return;
    box.innerHTML += `<div class="chat-msg error">${this._esc(text)}</div>`;
    box.scrollTop = box.scrollHeight;
  },

  _esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  },
};

// ---
// | KB @CerberRus00 - Nexus Invest Team
