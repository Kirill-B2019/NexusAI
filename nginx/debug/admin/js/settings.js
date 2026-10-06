/**
 * NEXUS AI Admin — Settings + UI утилиты (Toast, Modal)
 */

// ═══════════════════════════════════════════════════════════
// Toast — всплывающие уведомления
// ═══════════════════════════════════════════════════════════
const Toast = {
  _timer: null,

  show(message, type = 'info', duration = 3000) {
    const el = document.getElementById('toast');
    if (!el) return;

    el.textContent = message;
    el.className = 'toast show ' + (type === 'error' ? 'error' : type === 'success' ? 'success' : '');
    el.style.pointerEvents = 'auto';

    clearTimeout(this._timer);
    this._timer = setTimeout(() => {
      el.classList.remove('show');
      el.style.pointerEvents = 'none';
    }, duration);
  },

  success(msg) { this.show(msg, 'success'); },
  error(msg) { this.show(msg, 'error', 5000); },
};

// ═══════════════════════════════════════════════════════════
// Modal — модальные окна
// ═══════════════════════════════════════════════════════════
const Modal = {
  _current: null,

  open(html, options = {}) {
    this.close();

    const root = document.getElementById('modal-root');
    const bg = document.createElement('div');
    bg.className = 'modal-bg';
    bg.innerHTML = `<div class="modal">${html}</div>`;

    // Закрытие по клику на фон
    bg.addEventListener('click', (e) => {
      if (e.target === bg && options.closeOnBg !== false) {
        this.close();
      }
    });

    // Закрытие по Esc
    const escHandler = (e) => {
      if (e.key === 'Escape') this.close();
    };
    document.addEventListener('keydown', escHandler);
    bg._escHandler = escHandler;

    root.appendChild(bg);
    this._current = bg;

    // Фокус на первое поле
    const firstInput = bg.querySelector('input, textarea, select');
    if (firstInput) setTimeout(() => firstInput.focus(), 50);

    return bg;
  },

  close() {
    if (this._current) {
      if (this._current._escHandler) {
        document.removeEventListener('keydown', this._current._escHandler);
      }
      this._current.remove();
      this._current = null;
    }
  },

  confirm(message, onConfirm) {
    const id = 'confirm-' + Date.now();
    this.open(`
      <h3>Подтверждение</h3>
      <p class="mt-16">${message}</p>
      <div class="row mt-16">
        <button id="${id}-yes" class="danger">Да</button>
        <button id="${id}-no" class="secondary">Отмена</button>
      </div>
    `);
    document.getElementById(`${id}-yes`).onclick = () => {
      this.close();
      onConfirm();
    };
    document.getElementById(`${id}-no`).onclick = () => this.close();
  },
};

// ═══════════════════════════════════════════════════════════
// Settings — форма настроек
// ═══════════════════════════════════════════════════════════
const Settings = {
  // Загрузить сохранённые значения в форму
  load() {
    const baseUrl = document.getElementById('set-base-url');
    const apiKey = document.getElementById('set-api-key');

    if (baseUrl) baseUrl.value = localStorage.getItem('nexus_base_url') || '/api';
    if (apiKey) apiKey.value = localStorage.getItem('nexus_api_key') || '';
  },

  // Сохранить в localStorage
  save() {
    const baseUrl = document.getElementById('set-base-url').value.trim();
    const apiKey = document.getElementById('set-api-key').value.trim();

    if (!baseUrl) {
      Toast.error('Base URL обязателен');
      return;
    }

    if (!apiKey) {
      Toast.error('API-ключ обязателен');
      return;
    }

    localStorage.setItem('nexus_base_url', baseUrl);
    localStorage.setItem('nexus_api_key', apiKey);

    Toast.success('Настройки сохранены');
    this._showResult('Сохранено. Base URL: ' + baseUrl, 'ok');

    // Обновить статус в сайдбаре
    if (typeof App !== 'undefined' && App.updateStatus) {
      App.updateStatus();
    }
  },

  // Проверить соединение
  async test() {
    this._showResult('Проверка...', '');

    const result = await API.get('/health');

    if (!result.ok) {
      this._showResult('❌ ' + result.error, 'error');
      Toast.error('Не удалось подключиться: ' + result.error);
      return;
    }

    // Дополнительно — проверка ключа
    const keyCheck = await API.get('/v1/experts');

    if (!keyCheck.ok) {
      this._showResult('⚠ Соединение есть, но ключ не принят: ' + keyCheck.error, 'warn');
      Toast.error('Ключ не принят: ' + keyCheck.error);
      return;
    }

    const count = (keyCheck.data && keyCheck.data.experts) ? keyCheck.data.experts.length : 0;
    this._showResult(`✅ Соединение OK. Экспертов доступно: ${count}`, 'ok');
    Toast.success('Соединение установлено');
  },

  // Очистить настройки
  clear() {
    Modal.confirm('Удалить сохранённые настройки?', () => {
      localStorage.removeItem('nexus_base_url');
      localStorage.removeItem('nexus_api_key');
      this.load();
      this._showResult('Настройки очищены', '');
      Toast.success('Очищено');
      if (typeof App !== 'undefined' && App.updateStatus) {
        App.updateStatus();
      }
    });
  },

  // Показать результат
  _showResult(message, type) {
    const el = document.getElementById('settings-result');
    if (!el) return;
    el.textContent = message;
    el.className = 'mt-8 ' + (
      type === 'error' ? 'text-error' :
      type === 'warn' ? 'text-warn' :
      type === 'ok' ? 'text-accent' : 'muted'
    );
  },
};

// ---
// | KB @CerberRus00 - Nexus Invest Team
