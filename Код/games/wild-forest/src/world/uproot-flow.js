// Выкорчёвывание пней киркой: тап по пню с киркой в руке, N ударов, действие uproot в очередь, откат при отказе сервера.
// Зависимости передаются снаружи, поэтому логика проверяется в node без браузера.
import { uprootNeed, uprootCost } from '../shared/formulas.js';

const isStump = (obj) => Boolean(obj) && obj.kind === 'stump';
const MIN_FLUSH_GAP_MS = 3200; // сервер принимает sync не чаще раза в 3 секунды

export function createUprootFlow({ world, getQueue, getSync, bar, notify, text, hasPickaxe = () => false, getEnergy = () => null, now = () => Date.now(), setTimer = setTimeout, clearTimer = clearTimeout }) {
    let cur = null;                // { id, size, need, hits, t0 }
    const pending = new Map();     // id пня → число отправленных, но не подтверждённых действий
    let lastFlush = 0;
    let flushTimer = 0;
    let warnedOffline = false;

    function requestFlush() {
        const sync = getSync();
        if (!sync || !sync.running) return;
        clearTimer(flushTimer);
        const wait = Math.max(0, lastFlush + MIN_FLUSH_GAP_MS - now());
        if (wait === 0) { lastFlush = now(); sync.flush(); }
        else flushTimer = setTimer(() => { lastFlush = now(); sync.flush(); }, wait);
    }

    function cancel() { cur = null; if (bar) bar.hide(); }

    function finish() {
        const done = cur;
        cancel();
        world.chunks.uproot(done.id);
        world.ctl.cancelWork();
        notify({ text: text.done });
        const queue = getQueue();
        if (!queue) {
            if (!warnedOffline) { warnedOffline = true; notify({ text: text.offline }); }
            return;
        }
        const pos = world.heroPos;
        queue.push('uproot', { tree: done.id, hits: done.hits, t0: done.t0, t1: now(), px: Math.round(pos.x * 100) / 100, pz: Math.round(pos.z * 100) / 100 });
        pending.set(done.id, (pending.get(done.id) || 0) + 1);
        requestFlush();
    }

    function start(obj) {
        cur = { id: obj.id, size: obj.size | 0, need: uprootNeed(obj.size), hits: 0, t0: now() };
        const energy = getEnergy();
        if (energy !== null && energy + 1e-9 < uprootCost(cur.size)) {
            cancel();
            world.ctl.cancelWork();
            notify({ text: text.no_energy });
        }
    }

    return {
        pending,
        get active() { return Boolean(cur); },
        get hits() { return cur ? cur.hits : 0; },
        /** Событие сцены: 'ready' (дошёл и повернулся к пню) и 'workTap' (удар). */
        onWorldEvent(e) {
            if (!isStump(e.obj)) return;
            if (e.type === 'ready') {
                if (!hasPickaxe()) { world.ctl.cancelWork(); notify({ text: text.need_tool }); return; }
                start(e.obj);
            } else if (e.type === 'workTap') {
                if (!hasPickaxe()) { world.ctl.cancelWork(); cancel(); notify({ text: text.need_tool }); return; }
                if (!cur || cur.id !== e.obj.id) start(e.obj);
                if (!cur) return;
                cur.hits += 1;
                if (cur.hits >= cur.need) finish();
            }
        },
        /** Каждый кадр: шкала над пнём; ушёл от пня — прогресс сбрасывается (накопить по частям нельзя). */
        update() {
            if (!cur) { if (bar) bar.hide(); return; }
            if (world.ctl.mode !== 'work' && world.ctl.mode !== 'turn' && world.ctl.mode !== 'approach') { cancel(); return; }
            if (!bar) return;
            const o = world.ctl.obj;
            const sp = o ? world.projectToScreen(o.x, (o.y || 0) + 0.8, o.z) : null;
            bar.set(cur.hits / cur.need, sp ? { x: sp.sx !== undefined ? sp.sx : sp.x, y: sp.sy !== undefined ? sp.sy : sp.y } : null);
        },
        /** Результаты sync: откат отклонённых действий. */
        onResults(results) {
            for (const r of results) {
                if (r.dup || r.type !== 'uproot' || !r.tree) continue;
                const left = (pending.get(r.tree) || 1) - 1;
                if (left > 0) pending.set(r.tree, left); else pending.delete(r.tree);
                if (r.ok) continue;
                const code = r.error || 'failed';
                // Пень уже выкорчеван другим (или его не было): остаётся выкорчеванным; остальное откатываем.
                if (code !== 'stump_gone' && code !== 'no_stump') world.chunks.unuproot(r.tree);
                notify({ text: text[code] || text.failed });
            }
        },
        dispose() { clearTimer(flushTimer); },
    };
}
