// Чистая логика обучения (ДЛ-15): шаги наставника, ожидаемые события, выбор цели подсказки. Без DOM и Three.js, тестируется в Node.
// tutorial_step на сервере = сколько шагов пройдено; TUTORIAL_DONE значит «пройдено или пропущено».

export const STEP_IDS = Object.freeze(['hello', 'walk', 'chop', 'pickup', 'weight', 'crate', 'sell', 'energy', 'house', 'dam', 'city', 'bye']);
export const TUTORIAL_DONE = STEP_IDS.length; // совпадает с tutorial.steps в api/forest/config.php
export const WAIT_EVENTS = Object.freeze({ walk: 'moved', chop: 'felled', pickup: 'picked', crate: 'crated', sell: 'sold' });
export const HINT_TARGETS = Object.freeze({ walk: 'tree', chop: 'tree', pickup: 'drop', crate: 'crate', sell: 'buyer' });
export const MOVE_DIST = 4; // шаг «ходьба» засчитывается, когда бобёр прошёл столько метров

export function createTutorial(startStep = 0) {
    let step = Math.max(0, Math.min(TUTORIAL_DONE, Math.trunc(Number(startStep)) || 0));
    return {
        get step() { return step; },
        get done() { return step >= TUTORIAL_DONE; },
        get id() { return step < TUTORIAL_DONE ? STEP_IDS[step] : null; },
        get wait() { return step < TUTORIAL_DONE ? WAIT_EVENTS[STEP_IDS[step]] || null : null; },
        get target() { return step < TUTORIAL_DONE ? HINT_TARGETS[STEP_IDS[step]] || null : null; },
        accepts(event) { return step < TUTORIAL_DONE && WAIT_EVENTS[STEP_IDS[step]] === event; },
        complete() { if (step < TUTORIAL_DONE) step += 1; return step; },
        skip() { step = TUTORIAL_DONE; return step; },
        reset() { step = 0; return step; },
    };
}

/** Событие обучения по результату действия из ответа sync (или null). */
export function eventFromResult(r) {
    if (!r || r.dup || !r.ok) return null;
    if (r.type === 'pickup') return 'picked';
    if (r.type === 'crate_put') return 'crated';
    if (r.type === 'sell') return 'sold';
    if (r.tree && r.type !== 'uproot') return 'felled';
    return null;
}

/** Ближайшая к (x, z) точка из списка [{x, z, ...}] или null. */
export function nearestPoint(list, x, z) {
    let best = null;
    let bestD = Infinity;
    for (const p of list) {
        const d = Math.hypot(p.x - x, p.z - z);
        if (d < bestD) { bestD = d; best = p; }
    }
    return best;
}

/** Точка на экране и признак «за краем»: стрелка прижимается к краю экрана и показывает направление. */
export function clampToScreen(sx, sy, w, h, pad = 28) {
    const x = Math.max(pad, Math.min(w - pad, sx));
    const y = Math.max(pad, Math.min(h - pad, sy));
    return { x, y, off: x !== sx || y !== sy };
}
