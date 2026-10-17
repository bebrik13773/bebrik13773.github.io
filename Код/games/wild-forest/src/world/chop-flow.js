// Связка рубки (ДЛ-11): события мира → клиентская шкала → действие chop_done в очередь → разбор ответа сервера.
// Все зависимости передаются снаружи (мир, очередь, sync, шкала, уведомления), поэтому логика проверяется в node без браузера.
import { createChop } from './chop.js';
import { TREES, axePower, chopCost } from '../shared/formulas.js';

const isTree = (obj) => Boolean(obj) && (obj.kind === 'tree' || obj.kind === 1); // сцена отдаёт kind строкой
const MIN_FLUSH_GAP_MS = 3200; // сервер принимает sync не чаще раза в 3 секунды

export function createChopFlow({ world, wg, getQueue, getSync, bar, notify, text, getAxeLevel = () => 1, getEnergy = () => null, now = () => Date.now(), setTimer = setTimeout, clearTimer = clearTimeout, powerOf = axePower }) {
    const chop = createChop({ wg, getPower: () => powerOf(getAxeLevel()), now });
    const pending = new Map(); // id дерева → число отправленных, но не подтверждённых рубок
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

    function finishTree() {
        const done = chop.finish();
        if (!done) return;
        const pos0 = world.heroPos;
        if (world.fx) world.fx.fall(done.tree, pos0.x, pos0.z); // дерево падает от бобра, пень появится сразу
        world.chunks.fell(done.tree);
        world.ctl.cancelWork();
        if (bar) bar.hide();
        notify({ text: text.done });
        const queue = getQueue();
        if (!queue) {
            if (!warnedOffline) { warnedOffline = true; notify({ text: text.offline }); }
            return;
        }
        const pos = world.heroPos;
        queue.push('chop_done', { tree: done.tree, hits: done.hits, t0: done.t0, t1: done.t1, px: Math.round(pos.x * 100) / 100, pz: Math.round(pos.z * 100) / 100 });
        pending.set(done.tree, (pending.get(done.tree) || 0) + 1);
        requestFlush();
    }

    return {
        chop,
        pending,
        /** Событие сцены: 'ready' (дошёл и повернулся к объекту) и 'workTap' (удар). */
        onWorldEvent(e) {
            if (e.type === 'ready' && isTree(e.obj)) {
                const info = chop.start(e.obj.id);
                if (!info) return;
                const energy = getEnergy();
                if (energy !== null && energy + 1e-9 < chopCost(info.need)) {
                    chop.cancel();
                    world.ctl.cancelWork();
                    notify({ text: text.no_energy });
                }
            } else if (e.type === 'workTap' && isTree(e.obj)) {
                if (!chop.active) chop.start(e.obj.id);
                const r = chop.hit();
                if (r.done) finishTree();
            }
        },
        /** Каждый кадр: шкала над деревом; ушёл от дерева — прогресс сбрасывается (накопить по частям нельзя). */
        update() {
            if (!chop.active) { if (bar) bar.hide(); return; }
            if (world.ctl.mode !== 'work' && world.ctl.mode !== 'turn' && world.ctl.mode !== 'approach') { chop.cancel(); if (bar) bar.hide(); return; }
            if (!bar) return;
            const anchor = world.treeAnchor ? world.treeAnchor(chop.info.id) : null;
            const top = anchor || world.treeTop(chop.info.id);
            const sp = top ? world.projectToScreen(top.x, anchor ? top.y : top.y + 0.4, top.z) : null;
            const shown = chop.shownFrac;
            if (world.fx) world.fx.setProgress(chop.info.id, shown);
            bar.set(shown, sp ? { x: sp.sx !== undefined ? sp.sx : sp.x, y: sp.sy !== undefined ? sp.sy : sp.y } : null);
        },
        /** Результаты sync: награды и откат отклонённых рубок. */
        onResults(results) {
            for (const r of results) {
                if (r.dup || !r.tree) continue;
                const left = (pending.get(r.tree) || 1) - 1;
                if (left > 0) pending.set(r.tree, left); else pending.delete(r.tree);
                if (r.ok) {
                    if (r.drops && world.drops) world.drops.add(r.drops);
                    const logs = r.reward && r.reward.log ? r.reward.log : 0;
                    if (logs) notify({ text: text.reward(logs) });
                    if (r.reward && r.reward.coins) notify({ text: text.treasure(r.reward.coins) });
                } else {
                    const code = r.error || 'failed';
                    // Дерево уже срублено другим (или его не было): остаётся срубленным; остальное — возвращаем.
                    if (code !== 'tree_gone' && code !== 'no_tree') world.chunks.unfell(r.tree);
                    notify({ text: text[code] || text.failed });
                }
            }
        },
        dispose() { clearTimer(flushTimer); },
    };
}

export { TREES };
