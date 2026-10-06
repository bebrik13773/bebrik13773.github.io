import { ERRORS } from '../config.js';

// Журнал ошибок клиента: кольцевой буфер в памяти и в localStorage.
// Старый журнал кликера завязан на авторизацию и IndexedDB-пачки, поэтому здесь отдельный лёгкий:
// записи уйдут на сервер вместе с `sync` (ДЛ-10) и в тикеты поддержки (ДЛ-39).
const ring = [];

export function logClient(kind, message, extra) {
    const entry = {
        t: Date.now(),
        kind: String(kind),
        msg: String(message || '').slice(0, 300),
    };
    if (extra) entry.extra = String(extra).slice(0, 600);
    ring.push(entry);
    while (ring.length > ERRORS.ringSize) ring.shift();
    try {
        localStorage.setItem(ERRORS.storageKey, JSON.stringify(ring));
    } catch (e) { /* журнал в памяти остаётся */ }
    return entry;
}

export function getClientLog() {
    return ring.slice();
}
