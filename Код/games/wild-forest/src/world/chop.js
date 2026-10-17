// Клиентская рубка (ДЛ-11): локальная шкала дерева, пачка ударов, защита от слишком частых тапов. Чистая логика без three.js и DOM.
// Прогресс по дереву хранится только здесь и сбрасывается при уходе от дерева (накопить удары «по частям» нельзя).
// Итог всегда решает сервер: клиент лишь предлагает действие chop_done.
import { parseTreeId } from '../shared/worldgen.js';
import { SPECIES_ORDER, TREES, treeHp, chopNeed } from '../shared/formulas.js';

/** Параметры дерева по id: { id, species, size, tier, hp, need } или null (нет такого дерева). */
export function treeInfo(wg, id, power) {
    const p = parseTreeId(id);
    if (!p) return null;
    const cell = wg.cellAt(p.cx, p.cz, p.ci);
    if (!cell || cell.kind !== 1) return null;
    const species = SPECIES_ORDER[cell.species];
    const tier = wg.tierAtDm(cell.X, cell.Z);
    const hp = treeHp(species, cell.size, tier);
    return { id, species, size: cell.size, tier, rare: cell.rare, treasure: cell.treasure, hp, need: chopNeed(hp, power), cx: p.cx, cz: p.cz, ci: p.ci };
}

export function createChop({ wg, getPower, now = () => performance.now(), minGapMs = Math.ceil(1000 / TREES.maxTapsPerS) + 5, rand = Math.random }) {
    let cur = null; // { info, hits, t0, t1, last, cum }

    /** Накопленные «веса» ударов для шкалы: каждый удар откусывает от дерева разный кусок, итог всегда ровно 1. */
    function makeCum(need) {
        const n = Math.min(Math.max(1, need), 600);
        const cum = new Float32Array(n + 1);
        for (let i = 1; i <= n; i += 1) cum[i] = cum[i - 1] + 0.45 + 1.1 * rand();
        return cum;
    }

    return {
        /** Начать работу с деревом (бобёр дошёл и повернулся). Прогресс всегда с нуля. */
        start(id) {
            const info = treeInfo(wg, id, getPower());
            cur = info ? { info, hits: 0, t0: 0, t1: 0, last: -1e9, cum: makeCum(info.need) } : null;
            return info;
        },
        /** Один удар. Слишком частые тапы (чаще 6 в секунду) не засчитываются. Возвращает { accepted, hits, need, frac, done }. */
        hit() {
            if (!cur) return { accepted: false, hits: 0, need: 0, frac: 0, done: false };
            const t = now();
            if (t - cur.last < minGapMs) return { accepted: false, hits: cur.hits, need: cur.info.need, frac: cur.hits / cur.info.need, done: false };
            cur.last = t;
            if (cur.hits === 0) cur.t0 = Date.now();
            cur.hits += 1;
            cur.t1 = Date.now();
            const done = cur.hits >= cur.info.need;
            return { accepted: true, hits: cur.hits, need: cur.info.need, frac: Math.min(1, cur.hits / cur.info.need), done };
        },
        /** Завершённая рубка для отправки на сервер; сбрасывает сессию. */
        finish() {
            if (!cur || cur.hits < cur.info.need) return null;
            const r = { tree: cur.info.id, hits: cur.hits, t0: cur.t0, t1: cur.t1, info: cur.info };
            cur = null;
            return r;
        },
        cancel() { cur = null; },
        get active() { return cur !== null; },
        get info() { return cur ? cur.info : null; },
        get frac() { return cur ? Math.min(1, cur.hits / cur.info.need) : 0; },
        get hits() { return cur ? cur.hits : 0; },
        /** Шкала для показа: неровная (удары разной силы), но доходит до 1 ровно на последнем ударе. */
        get shownFrac() {
            if (!cur) return 0;
            const n = cur.cum.length - 1;
            const need = cur.info.need;
            if (cur.hits >= need) return 1;
            const pos = (cur.hits / need) * n;
            const i = Math.min(n - 1, Math.floor(pos));
            const f = cur.cum[i] + (cur.cum[i + 1] - cur.cum[i]) * (pos - i);
            return Math.min(1, f / cur.cum[n]);
        },
    };
}
