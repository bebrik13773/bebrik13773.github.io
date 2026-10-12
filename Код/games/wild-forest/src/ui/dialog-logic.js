// Чистая логика диалога без DOM: печать текста по буквам, очередь реплик, выбор по таймеру. Тестируется в Node.

/** Печать текста: reveal(dt) открывает буквы со скоростью cps, skip() показывает всё сразу. Считаем по символам (эмодзи не рвём). */
export function createTypewriter(text, cps = 45) {
    const chars = Array.from(String(text || ''));
    let shown = 0;
    let carry = 0;
    return {
        get total() { return chars.length; },
        get shown() { return shown; },
        get done() { return shown >= chars.length; },
        get text() { return chars.slice(0, shown).join(''); },
        reveal(dtSeconds) {
            if (shown >= chars.length) return false;
            carry += Math.max(0, dtSeconds) * cps;
            const add = Math.floor(carry);
            if (add <= 0) return false;
            carry -= add;
            shown = Math.min(chars.length, shown + add);
            return true;
        },
        skip() {
            const changed = shown < chars.length;
            shown = chars.length;
            return changed;
        },
    };
}

/** Нормализация реплики: не больше 4 вариантов ответа, у каждого есть id и подпись. */
export function normalizeLine(line) {
    const source = line || {};
    const choices = Array.isArray(source.choices)
        ? source.choices
            .filter((c) => c && String(c.label || '').trim())
            .slice(0, 4)
            .map((c, i) => ({ id: c.id !== undefined ? c.id : i, label: String(c.label) }))
        : [];
    const timeoutMs = Number(source.timeoutMs) > 0 ? Number(source.timeoutMs) : 0;
    let defaultId = source.defaultId;
    if (defaultId === undefined && choices.length) defaultId = choices[choices.length - 1].id;
    return {
        speaker: String(source.speaker || ''),
        portrait: String(source.portrait || ''),
        text: String(source.text || ''),
        choices,
        timeoutMs: choices.length ? timeoutMs : 0,
        defaultId,
        cps: Number(source.cps) > 0 ? Number(source.cps) : 45,
    };
}

/** Обратный отсчёт выбора: tick(dt) возвращает true, когда время вышло. Идёт только после того, как текст показан целиком. */
export function createChoiceTimer(timeoutMs) {
    let left = timeoutMs > 0 ? timeoutMs : 0;
    let running = false;
    return {
        get left() { return left; },
        get share() { return timeoutMs > 0 ? left / timeoutMs : 0; },
        start() { running = left > 0; },
        tick(dtSeconds) {
            if (!running) return false;
            left = Math.max(0, left - dtSeconds * 1000);
            if (left === 0) { running = false; return true; }
            return false;
        },
        stop() { running = false; },
    };
}

/** Очередь реплик: следующая показывается, когда предыдущая закрыта. */
export function createLineQueue() {
    const items = [];
    return {
        push(item) { items.push(item); return items.length; },
        shift() { return items.shift(); },
        clear() { const n = items.length; items.length = 0; return n; },
        get size() { return items.length; },
    };
}
