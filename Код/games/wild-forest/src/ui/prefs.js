// Небольшие настройки интерфейса в localStorage (громкость и т.п.). Без хранилища работают значения по умолчанию.
export const PREF_DEFAULTS = Object.freeze({
    'wf.vol.sfx': 0.8,
    'wf.vol.music': 0.5,
    'wf.autopickup': 1,   // 1 = брёвна подбираются сами рядом с бобром (ДЛ-13)
});

export function clamp01(value, fallback = 0) {
    const n = Number(value);
    if (!Number.isFinite(n)) return fallback;
    return Math.min(1, Math.max(0, n));
}

export function getPref(key, storage = globalThis.localStorage) {
    const fallback = PREF_DEFAULTS[key];
    try {
        const raw = storage && storage.getItem(key);
        if (raw === null || raw === undefined) return fallback;
        return clamp01(raw, fallback);
    } catch (e) {
        return fallback;
    }
}

export function setPref(key, value, storage = globalThis.localStorage) {
    const v = clamp01(value, PREF_DEFAULTS[key]);
    try {
        if (storage) storage.setItem(key, String(v));
    } catch (e) { /* без хранилища значение живёт до перезагрузки */ }
    return v;
}
