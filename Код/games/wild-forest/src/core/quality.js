import { QUALITY_PRESETS, QUALITY_ORDER, DEFAULT_QUALITY, QUALITY_STORAGE_KEY, LOW_FPS } from '../config.js';

export function isQuality(value) {
    return typeof value === 'string' && Object.prototype.hasOwnProperty.call(QUALITY_PRESETS, value);
}

export function loadQuality(storage) {
    try {
        const store = storage || localStorage;
        const value = store.getItem(QUALITY_STORAGE_KEY);
        if (isQuality(value)) return value;
    } catch (e) { /* хранилище недоступно */ }
    return DEFAULT_QUALITY;
}

export function saveQuality(quality, storage) {
    try {
        (storage || localStorage).setItem(QUALITY_STORAGE_KEY, quality);
    } catch (e) { /* без хранилища выбор живёт до перезагрузки */ }
}

export function getPreset(quality) {
    return QUALITY_PRESETS[isQuality(quality) ? quality : DEFAULT_QUALITY];
}

// Следующее качество вниз или null, если ниже некуда.
export function lowerQuality(quality) {
    const index = QUALITY_ORDER.indexOf(quality);
    return index > 0 ? QUALITY_ORDER[index - 1] : null;
}

// Нужна ли подсказка «перейти ниже»: средний FPS окна меньше порога и есть куда снижать.
export function shouldSuggestLower(avgFps, quality) {
    return Number.isFinite(avgFps) && avgFps > 0 && avgFps < LOW_FPS.threshold && lowerQuality(quality) !== null;
}

// Контроллер: хранит текущее качество, сохраняет выбор, уведомляет подписчиков.
export function createQualityController(initial) {
    let current = isQuality(initial) ? initial : loadQuality();
    const listeners = new Set();
    return {
        get current() { return current; },
        get preset() { return getPreset(current); },
        set(next, { persist = true } = {}) {
            if (!isQuality(next) || next === current) return false;
            const prev = current;
            current = next;
            if (persist) saveQuality(next);
            listeners.forEach((fn) => fn(next, prev));
            return true;
        },
        subscribe(fn) {
            listeners.add(fn);
            return () => listeners.delete(fn);
        },
    };
}
