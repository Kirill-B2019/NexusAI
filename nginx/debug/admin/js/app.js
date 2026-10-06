/**
 * NEXUS AI Admin — навигация, sidebar, статус подключения
 */

const App = {
  _currentPage: null,

  // ─── Инициализация ────────────────────────────────────
  init() {
    this.bindNav();
    this.bindHash();
    this.updateStatus();

    // Первая страница из hash или dashboard
    const hash = location.hash.replace('#', '') || 'dashboard';
    this.navigate(hash);
  },

  // ─── Навигация по клику на ссылки ─────────────────────
  bindNav() {
    document.querySelectorAll('#nav a').forEach((link) => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        const page = link.dataset.page;
        location.hash = '#' + page;
      });
    });
  },

  // ─── Реакция на изменение hash ────────────────────────
  bindHash() {
    window.addEventListener('hashchange', () => {
      const page = location.hash.replace('#', '') || 'dashboard';
      this.navigate(page);
    });
  },

  // ─── Показать страницу ────────────────────────────────
  navigate(page) {
    // Проверка, что такая страница существует
    const target = document.getElementById('page-' + page);
    if (!target) {
      page = 'dashboard';
    }

    // Скрыть все страницы
    document.querySelectorAll('.page').forEach((p) => p.classList.remove('active'));

    // Показать нужную
    document.getElementById('page-' + page).classList.add('active');

    // Подсветить ссылку
    document.querySelectorAll('#nav a').forEach((a) => {
      a.classList.toggle('active', a.dataset.page === page);
    });

    this._currentPage = page;

    // Автозагрузка данных для страницы
    this._autoLoad(page);
  },

  // ─── Автозагрузка данных при переходе ─────────────────
  _autoLoad(page) {
    // Проверяем, что настройки заданы
    if (!API.getApiKey()) {
      return; // без ключа ничего не грузим
    }

    switch (page) {
      case 'dashboard':
        if (typeof Dashboard !== 'undefined') Dashboard.load();
        break;
      case 'experts':
        if (typeof Experts !== 'undefined') Experts.load();
        break;
      case 'projects':
        if (typeof Projects !== 'undefined') Projects.load();
        break;
      case 'chat':
        if (typeof Chat !== 'undefined') Chat.init();
        break;
      case 'metrics':
        if (typeof Metrics !== 'undefined') Metrics.load();
        break;
      case 'settings':
        if (typeof Settings !== 'undefined') Settings.load();
        break;
    }
  },

  // ─── Обновление статуса в сайдбаре ────────────────────
  async updateStatus() {
    const dot = document.getElementById('status-dot');
    const text = document.getElementById('status-text');
    if (!dot || !text) return;

    const apiKey = API.getApiKey();

    if (!apiKey) {
      dot.className = 'dot';
      text.textContent = 'ключ не задан';
      return;
    }

    text.textContent = 'проверка...';
    dot.className = 'dot';

    const result = await API.get('/health');

    if (result.ok) {
      dot.className = 'dot on';
      const baseUrl = API.getBaseUrl();
      const shortUrl = baseUrl.replace(/^https?:\/\//, '').slice(0, 18);
      text.textContent = shortUrl;
      text.title = baseUrl;
    } else {
      dot.className = 'dot off';
      text.textContent = 'offline';
      text.title = result.error || 'Нет соединения';
    }
  },

  // ─── Утилита: получить текущую страницу ───────────────
  getCurrentPage() {
    return this._currentPage;
  },
};

// ---
// | KB @CerberRus00 - Nexus Invest Team
