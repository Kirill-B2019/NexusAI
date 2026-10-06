/**
 * NEXUS AI Admin — Bootstrap
 * Запускается последним, инициализирует всё
 */

(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  ready(function () {
    console.log('[NEXUS AI Admin] Bootstrap started');

    // Проверка зависимостей
    const required = {
      API: typeof API !== 'undefined',
      Toast: typeof Toast !== 'undefined',
      Modal: typeof Modal !== 'undefined',
      Settings: typeof Settings !== 'undefined',
      App: typeof App !== 'undefined',
    };
    const missing = Object.keys(required).filter((k) => !required[k]);

    if (missing.length > 0) {
      console.error('[NEXUS AI Admin] Не загружены модули:', missing);
      return;
    }

    // Загрузка настроек в форму
    Settings.load();

    // Инициализация App
    App.init();

    // Обновление статуса подключения каждые 60 секунд
    setInterval(() => {
      if (App.updateStatus) App.updateStatus();
    }, 60000);

    // Горячие клавиши
    document.addEventListener('keydown', (e) => {
      // Ctrl+K — фокус на чат
      if (e.ctrlKey && e.key === 'k') {
        e.preventDefault();
        if (location.hash === '#chat') {
          const input = document.getElementById('chat-message');
          if (input) input.focus();
        } else {
          location.hash = '#chat';
        }
      }
      // Ctrl+/ — быстрый переход к dashboard
      if (e.ctrlKey && e.key === '/') {
        e.preventDefault();
        location.hash = '#dashboard';
      }
    });

    console.log('[NEXUS AI Admin] Ready. Версия 1.0.0');
    console.log('[NEXUS AI Admin] GitHub: https://github.com/Kirill-B2019/NexusAI');
  });
})();

// ---
// | KB @CerberRus00 - Nexus Invest Team
