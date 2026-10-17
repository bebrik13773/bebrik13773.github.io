// Связка рюкзака (ДЛ-13): подбор выпавших предметов (тап или автоподбор), бросание груза, разбор ответов сервера.
// Все зависимости передаются снаружи (мир, очередь, sync, рюкзак на экране, уведомления), поэтому логика проверяется в node без браузера.
import { PACK, packFit, itemKg } from '../shared/formulas.js';

const MIN_FLUSH_GAP_MS = 3200; // сервер принимает sync не чаще раза в 3 секунды
const AUTO_STEP_MS = 250;      // как часто смотрим, не лежит ли что-то под ногами
const AUTO_MAX_PER_STEP = 3;
const AWAY_M = 2.5;            // отошёл от брошенного дальше этого — предмет снова подбирается сам

export function createPackFlow({ world, getQueue, getSync, getInventory, getCapacity = () => 40, notify, text, names = {}, isAuto = () => true, isSleeping = () => false, now = () => Date.now(), setTimer = setTimeout, clearTimer = clearTimeout }) {
    const pending = new Map();   // id предмета → { drop, taken } (отправлено, ответа нет)
    let throwing = 0;
    let lastFlush = 0;
    let flushTimer = 0;
    let lastAuto = 0;
    let lastFullAt = -1e12;
    let warnedOffline = false;

    const nameOf = (item) => names[item] || item;

    function requestFlush() {
        const sync = getSync();
        if (!sync || !sync.running) return;
        clearTimer(flushTimer);
        const wait = Math.max(0, lastFlush + MIN_FLUSH_GAP_MS - now());
        if (wait === 0) { lastFlush = now(); sync.flush(); }
        else flushTimer = setTimer(() => { lastFlush = now(); sync.flush(); }, wait);
    }

    function offline() {
        if (!warnedOffline) { warnedOffline = true; notify({ text: text.offline }); }
    }

    function notifyFull() {
        if (now() - lastFullAt < 3000) return;
        lastFullAt = now();
        notify({ text: text.full });
    }

    /** Поднять предмет с земли. Возвращает true, если действие ушло в очередь. */
    function pickup(dropId, { auto = false } = {}) {
        const d = world.drops.get(dropId);
        if (!d || pending.has(dropId)) return false;
        const queue = getQueue();
        if (!queue) { if (!auto) offline(); return false; }
        const inv = getInventory();
        const fit = packFit(getCapacity(), inv.weight, d.item, d.qty);
        if (fit <= 0) { if (!auto) notifyFull(); else world.drops.setNoAuto(dropId, true); return false; }
        const pos = world.heroPos;
        queue.push('pickup', { drop: dropId, px: Math.round(pos.x * 100) / 100, pz: Math.round(pos.z * 100) / 100 });
        pending.set(dropId, { drop: d, taken: fit });
        world.drops.remove(dropId);                  // сразу убираем с земли
        inv.add(d.item, fit, itemKg(d.item));        // и кладём в рюкзак на экране; точное число скажет сервер
        requestFlush();
        return true;
    }

    /** Бросить предмет из рюкзака на землю. */
    function dropItem(item, qty) {
        const queue = getQueue();
        if (!queue) { offline(); return false; }
        if (isSleeping()) { notify({ text: text.sleeping }); return false; }
        const inv = getInventory();
        const n = inv.remove(item, qty);
        if (n <= 0) return false;
        queue.push('drop', { item, qty: n });
        throwing += 1;
        requestFlush();
        return true;
    }

    return {
        pending,
        pickup,
        dropItem,
        get throwing() { return throwing; },
        /** Событие сцены: бобёр дошёл до лежащего предмета. */
        onWorldEvent(e) {
            if (e.type === 'ready' && e.obj && e.obj.kind === 'drop') {
                world.ctl.cancelWork();
                if (isSleeping()) { notify({ text: text.sleeping }); return; }
                pickup(e.obj.dropId);
            }
        },
        /** Каждый кадр: просроченные исчезают, брёвна рядом подбираются сами. */
        update() {
            const t = now();
            if (t - lastAuto < AUTO_STEP_MS) return;
            lastAuto = t;
            world.drops.expire();
            const pos = world.heroPos;
            let sent = 0;
            const auto = isAuto() && !isSleeping();
            for (const d of world.drops.list()) {
                const dist = Math.hypot(d.x - pos.x, d.z - pos.z);
                if (world.drops.isNoAuto(d.id)) {
                    if (dist > AWAY_M) world.drops.setNoAuto(d.id, false);
                    continue;
                }
                if (!auto || d.item !== 'log' || dist > PACK.autoPickupR || sent >= AUTO_MAX_PER_STEP) continue;
                if (pickup(d.id, { auto: true })) sent += 1;
            }
        },
        /** Результаты sync по действиям pickup и drop. */
        onResults(results) {
            const gained = {};
            for (const r of results) {
                if (r.dup) continue;
                if (r.type === 'pickup') {
                    const p = pending.get(r.drop);
                    pending.delete(r.drop);
                    if (r.ok) {
                        gained[r.item] = (gained[r.item] || 0) + r.taken;
                        // стопка поднята не вся (рюкзак заполнился): остаток снова лежит на земле
                        if (r.left > 0 && p) world.drops.add([{ ...p.drop, qty: r.left }], { noAuto: true });
                        continue;
                    }
                    const code = r.error || 'failed';
                    if (code === 'no_items') { notify({ text: text.gone }); continue; }
                    // Предмет на месте, но подобрать не вышло: возвращаем на землю (сам не подберётся, чтобы не зациклиться).
                    if (p) world.drops.add([p.drop], { noAuto: true });
                    notify({ text: code === 'no_space' ? text.full : code === 'too_far' ? text.far : code === 'sleeping' ? text.sleeping : text.failed });
                } else if (r.type === 'drop') {
                    throwing = Math.max(0, throwing - 1);
                    if (r.ok && r.drop) {
                        world.drops.add([r.drop], { noAuto: true });
                        notify({ text: text.thrown(nameOf(r.drop.item), r.drop.qty) });
                    } else {
                        const code = r.error || 'failed';
                        notify({ text: code === 'rate_limited' ? text.tooMany : code === 'sleeping' ? text.sleeping : text.failed });
                    }
                }
            }
            const keys = Object.keys(gained);
            if (keys.length) notify({ text: keys.map((k) => text.picked(nameOf(k), gained[k])).join(', '), key: 'picked' });
        },
        dispose() { clearTimer(flushTimer); },
    };
}
