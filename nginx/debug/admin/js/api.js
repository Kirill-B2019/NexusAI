/**
 * NEXUS AI Admin — базовый API-клиент
 * Обёртка над fetch с авторизацией и обработкой ошибок.
 */

const API = (() => {
  // ─── Настройки (из localStorage) ──────────────────────
  function getBaseUrl() {
    return localStorage.getItem('nexus_base_url') || '/api';
  }

  function getApiKey() {
    return localStorage.getItem('nexus_api_key') || '';
  }

  // ─── Базовый запрос ───────────────────────────────────
  async function request(method, path, options = {}) {
    const url = getBaseUrl().replace(/\/$/, '') + path;
    const apiKey = getApiKey();

    const headers = {
      'Accept': 'application/json',
      ...(options.headers || {}),
    };

    if (apiKey) {
      headers['X-API-Key'] = apiKey;
    }

    let body = options.body;
    if (options.json) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(options.json);
    }

    const fetchOptions = {
      method,
      headers,
      body,
    };

    try {
      const response = await fetch(url, fetchOptions);

      // 204 No Content
      if (response.status === 204) {
        return { ok: true, status: 204, data: null };
      }

      const contentType = response.headers.get('content-type') || '';
      let data;

      if (contentType.includes('application/json')) {
        data = await response.json();
      } else {
        data = await response.text();
      }

      if (!response.ok) {
        const errorMsg = (data && data.detail) ? data.detail : `HTTP ${response.status}`;
        return { ok: false, status: response.status, error: errorMsg, data };
      }

      return { ok: true, status: response.status, data };

    } catch (err) {
      return {
        ok: false,
        status: 0,
        error: err.message || 'Network error',
      };
    }
  }

  // ─── HTTP-методы ──────────────────────────────────────
  return {
    get: (path, options = {}) => request('GET', path, options),
    post: (path, json, options = {}) => request('POST', path, { ...options, json }),
    patch: (path, json, options = {}) => request('PATCH', path, { ...options, json }),
    delete: (path, options = {}) => request('DELETE', path, options),

    // Специальные запросы
    upload: async (path, file) => {
      const url = getBaseUrl().replace(/\/$/, '') + path;
      const apiKey = getApiKey();

      const formData = new FormData();
      formData.append('file', file);

      try {
        const response = await fetch(url, {
          method: 'POST',
          headers: { 'X-API-Key': apiKey },
          body: formData,
        });

        const data = await response.json();

        if (!response.ok) {
          return {
            ok: false,
            status: response.status,
            error: data.detail || `HTTP ${response.status}`,
          };
        }

        return { ok: true, status: response.status, data };
      } catch (err) {
        return { ok: false, status: 0, error: err.message };
      }
    },

    // SSE-стрим (генератор событий)
    stream: async function* (path, json) {
      const url = getBaseUrl().replace(/\/$/, '') + path;
      const apiKey = getApiKey();

      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'X-API-Key': apiKey,
          'Content-Type': 'application/json',
          'Accept': 'text/event-stream',
        },
        body: JSON.stringify(json),
      });

      if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(data.detail || `HTTP ${response.status}`);
      }

      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });
        const events = buffer.split('\n\n');
        buffer = events.pop() || '';

        for (const ev of events) {
          const typeMatch = ev.match(/^event: (.+)$/m);
          const dataMatch = ev.match(/^data: (.+)$/m);
          if (!dataMatch) continue;

          yield {
            type: typeMatch ? typeMatch[1] : 'message',
            data: JSON.parse(dataMatch[1]),
          };
        }
      }
    },

    // Вспомогательные
    getBaseUrl,
    getApiKey,
  };
})();

// ---
// | KB @CerberRus00 - Nexus Invest Team
