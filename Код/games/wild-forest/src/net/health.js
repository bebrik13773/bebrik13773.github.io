import { HEALTH_URL, HEALTH_TIMEOUT_MS } from '../config.js';

// Проверка сервера при загрузке. Недоступный сервер не фатален: каркас и лес работают без него.
export async function checkServer() {
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), HEALTH_TIMEOUT_MS) : 0;
    try {
        const response = await fetch(HEALTH_URL, { cache: 'no-store', signal: controller ? controller.signal : undefined });
        if (!response.ok) return { ok: false, reason: `HTTP ${response.status}` };
        const body = await response.json();
        if (!body || body.ok !== true || !body.data) return { ok: false, reason: 'bad_response' };
        return { ok: true, schemaOk: body.data.schema_ok === true, serverMs: Number(body.data.server_ms) || 0 };
    } catch (e) {
        return { ok: false, reason: e && e.name === 'AbortError' ? 'timeout' : 'offline' };
    } finally {
        if (timer) clearTimeout(timer);
    }
}
