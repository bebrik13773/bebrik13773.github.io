import { apiCall } from './api.js';

/**
 * Открытие сессии при загрузке: health → state → (join, если игрока ещё нет).
 * Возвращает { mode: 'online', full } или { mode: 'offline', reason }.
 * reason: 'unauthorized' (нужен вход в аккаунт), 'server' (сервер недоступен), 'schema' (схема обновляется), 'error'.
 */
export async function openSession(call = apiCall) {
    const health = await call('health', { timeoutMs: 4000 });
    if (!health.ok) return { mode: 'offline', reason: 'server', detail: health.error };
    if (health.data.schema_ok !== true) return { mode: 'offline', reason: 'schema' };

    let state = await call('state');
    if (!state.ok && state.error === 'unauthorized') return { mode: 'offline', reason: 'unauthorized' };
    if (!state.ok && state.error === 'bad_state' && state.extra && state.extra.need_join) {
        state = await call('join', { method: 'POST', body: {}, timeoutMs: 15000 });
    }
    if (!state.ok) {
        if (state.error === 'unauthorized') return { mode: 'offline', reason: 'unauthorized' };
        if (state.error === 'forbidden') return { mode: 'offline', reason: 'forbidden' };
        return { mode: 'offline', reason: 'error', detail: state.error };
    }
    return { mode: 'online', full: state.data, serverMs: Number(state.data.server_ms) || 0 };
}
