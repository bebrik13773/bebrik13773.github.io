import { API_BASE } from '../config.js';

/**
 * Вызов API леса. Никогда не бросает исключение: возвращает { ok, data, error, message, status, extra }.
 * error: код сервера (unauthorized, bad_state, rate_limited…) или 'network' / 'timeout' / 'bad_response'.
 */
export async function apiCall(name, { method = 'GET', body = null, timeoutMs = 6000, fetchImpl = null } = {}) {
    const doFetch = fetchImpl || fetch;
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), timeoutMs) : 0;
    try {
        const init = { method, cache: 'no-store', credentials: 'same-origin', signal: controller ? controller.signal : undefined, headers: {} };
        if (body !== null) {
            init.body = JSON.stringify(body);
            init.headers['Content-Type'] = 'application/json';
        }
        const response = await doFetch(`${API_BASE}${name}.php`, init);
        let json = null;
        try { json = await response.json(); } catch (e) { json = null; }
        if (!json || typeof json !== 'object') return { ok: false, error: 'bad_response', status: response.status };
        if (json.ok === true) return { ok: true, data: json.data || {}, status: response.status };
        return { ok: false, error: String(json.error || 'server_error'), message: json.message || '', extra: json.extra || null, status: response.status };
    } catch (e) {
        return { ok: false, error: e && e.name === 'AbortError' ? 'timeout' : 'network', status: 0 };
    } finally {
        if (timer) clearTimeout(timer);
    }
}
