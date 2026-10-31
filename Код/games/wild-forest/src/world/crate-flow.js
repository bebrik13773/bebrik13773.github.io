// Связка ящика-тайника и скупщика на поляне (ДЛ-14): сдать и взять предметы, продать брёвна и рыбу.
// Клиент предлагает, сервер решает: действия crate_put, crate_take и sell уходят в очередь, а настоящее состояние (рюкзак, ящик, монеты)
// приходит в ответе sync. Все зависимости передаются снаружи, поэтому логика проверяется в node без браузера.

import { itemKg } from '../shared/formulas.js';

const MIN_FLUSH_GAP_MS = 3200; // сервер принимает sync не чаще раза в 3 секунды

export function createCrateFlow({ world, getQueue, getSync, crateUi, dialog, inventory = null, notify, text, buyerText, names = {}, isSleeping = () => false, now = () => Date.now(), setTimer = setTimeout, clearTimer = clearTimeout }) {
    let pack = {};          // содержимое рюкзака по данным сервера { item: qty }
    let box = {};           // содержимое ящика
    let cap = 100;          // вместимость ящика (мест)
    let buyerLeft = null;   // сколько монет скупщик ещё заплатит сегодня
    let prices = {};        // цена одной штуки у скупщика (дробная)
    let lastFlush = 0;
    let flushTimer = 0;
    let warnedOffline = false;
    let selling = false;    // идёт диалог скупщика

    const nameOf = (item) => names[item] || item;
    const units = (m) => Object.values(m).reduce((a, b) => a + b, 0);
    const list = (m) => Object.keys(m).filter((k) => m[k] > 0).sort().map((key) => ({ key, qty: m[key] }));

    function paint() {
        crateUi.set({ pack: list(pack), box: list(box), used: units(box), cap });
    }

    function requestFlush() {
        const sync = getSync();
        if (!sync || !sync.running) return;
        clearTimer(flushTimer);
        const wait = Math.max(0, lastFlush + MIN_FLUSH_GAP_MS - now());
        if (wait === 0) { lastFlush = now(); sync.flush(); }
        else flushTimer = setTimer(() => { lastFlush = now(); sync.flush(); }, wait);
    }

    function queueOrWarn(msg) {
        const queue = getQueue();
        if (!queue) {
            if (!warnedOffline) { warnedOffline = true; notify({ text: msg }); }
            return null;
        }
        return queue;
    }

    function heroXZ() {
        const pos = world.heroPos;
        return { px: Math.round(pos.x * 100) / 100, pz: Math.round(pos.z * 100) / 100 };
    }

    /** Перенос предмета: from = 'pack' (в ящик) или 'box' (из ящика в рюкзак). Локально меняем сразу, итог скажет сервер. */
    function move({ from, item, qty }) {
        if (isSleeping()) { notify({ text: text.sleeping }); return false; }
        const queue = queueOrWarn(text.offline);
        if (!queue) return false;
        const src = from === 'pack' ? pack : box;
        const dst = from === 'pack' ? box : pack;
        const have = src[item] || 0;
        let n = Math.min(qty, have);
        if (from === 'pack') n = Math.min(n, Math.max(0, cap - units(box)));
        if (n <= 0) { notify({ text: from === 'pack' ? text.noSpaceBox : text.noItems }); return false; }
        src[item] = have - n;
        dst[item] = (dst[item] || 0) + n;
        // рюкзак на экране обновляем сразу, точные числа придут с сервера
        if (inventory) { if (from === 'pack') inventory.remove(item, n); else inventory.add(item, n, itemKg(item)); }
        paint();
        queue.push(from === 'pack' ? 'crate_put' : 'crate_take', { item, qty: n, ...heroXZ() });
        requestFlush();
        return true;
    }

    /** Продажа скупщику: список [{item, qty}] из рюкзака. */
    function sell(lines) {
        if (isSleeping()) { notify({ text: buyerText.sleeping }); return 0; }
        const queue = queueOrWarn(buyerText.offline);
        if (!queue) return 0;
        let sent = 0;
        for (const l of lines) {
            const qty = Math.min(l.qty, pack[l.item] || 0);
            if (qty <= 0 || !(prices[l.item] > 0)) continue;
            queue.push('sell', { item: l.item, qty, ...heroXZ() });
            pack[l.item] -= qty;
            if (inventory) inventory.remove(l.item, qty);
            sent += 1;
        }
        if (sent) requestFlush();
        return sent;
    }

    function priceLine() {
        return Object.keys(prices).map((k) => buyerText.price(nameOf(k).toLowerCase(), Math.round(prices[k] * 10) / 10)).join(', ');
    }

    async function talkToBuyer() {
        if (selling) return;
        selling = true;
        try {
            const sellable = Object.keys(prices).filter((k) => (pack[k] || 0) > 0);
            const logs = (pack.log || 0) > 0;
            const fish = ['fish_small', 'fish_big'].filter((k) => (pack[k] || 0) > 0);
            const left = buyerLeft === null ? 0 : buyerLeft;
            const choices = [];
            let line;
            if (buyerLeft !== null && left <= 0) line = { text: buyerText.limit };
            else if (!sellable.length) line = { text: buyerText.nothing };
            else {
                choices.push({ id: 'all', label: buyerText.sellAll });
                if (logs && fish.length) { choices.push({ id: 'logs', label: buyerText.sellLogs }); choices.push({ id: 'fish', label: buyerText.sellFish }); }
                choices.push({ id: 'later', label: buyerText.later });
                line = { text: buyerText.greet(priceLine(), left), choices };
            }
            const r = await dialog.say({ speaker: buyerText.speaker, portrait: '🦫', ...line });
            if (!choices.length) return; // реплика без вариантов: продавать нечего или лимит исчерпан
            if (r.id === 'all') sell(sellable.map((item) => ({ item, qty: pack[item] })));
            else if (r.id === 'logs') sell([{ item: 'log', qty: pack.log }]);
            else if (r.id === 'fish') sell(fish.map((item) => ({ item, qty: pack[item] })));
        } finally {
            selling = false;
        }
    }

    return {
        get pack() { return pack; },
        get box() { return box; },
        get buyerLeft() { return buyerLeft; },
        setPrices(p) { prices = { ...(p || {}) }; },
        move,
        sell,
        talkToBuyer,
        /** Событие сцены: бобёр дошёл до ящика или подводы. */
        onWorldEvent(e) {
            if (e.type !== 'ready' || !e.obj) return;
            if (e.obj.kind === 'crate') {
                world.ctl.cancelWork();
                if (isSleeping()) { notify({ text: text.sleeping }); return; }
                if (!getQueue()) { notify({ text: text.offline }); return; }
                paint();
                crateUi.open();
            } else if (e.obj.kind === 'buyer') {
                world.ctl.cancelWork();
                if (isSleeping()) { notify({ text: buyerText.sleeping }); return; }
                if (!getQueue()) { notify({ text: buyerText.offline }); return; }
                talkToBuyer();
            }
        },
        /** Настоящее состояние от сервера (в каждом ответе sync и при входе). */
        onState(st) {
            if (!st) return;
            pack = { ...(st.pack || {}) };
            box = { ...(st.crate || {}) };
            if (Number(st.crate_cap) > 0) cap = Number(st.crate_cap);
            if (st.buyer_left !== undefined) buyerLeft = Number(st.buyer_left);
            paint();
        },
        /** Результаты sync по действиям crate_put, crate_take и sell. */
        onResults(results) {
            let soldN = 0;
            let soldCoins = 0;
            let limited = false;
            for (const r of results) {
                if (r.dup) continue;
                if (r.type === 'crate_put' || r.type === 'crate_take') {
                    const put = r.type === 'crate_put';
                    if (r.ok) {
                        if (r.left > 0) notify({ text: put ? text.partialPut : text.partialTake });
                        continue;
                    }
                    const code = r.error || 'failed';
                    notify({ text: code === 'no_space' ? (put ? text.noSpaceBox : text.noSpacePack) : code === 'no_items' ? text.noItems : code === 'too_far' ? text.far : code === 'sleeping' ? text.sleeping : text.failed });
                } else if (r.type === 'sell') {
                    if (r.ok) {
                        soldN += r.sold;
                        soldCoins += r.coins;
                        if (r.rest > 0) limited = true;
                        if (r.left_today !== undefined) buyerLeft = Number(r.left_today);
                        continue;
                    }
                    const code = r.error || 'failed';
                    if (code === 'daily_limit') { limited = true; buyerLeft = 0; continue; }
                    notify({ text: code === 'too_far' ? buyerText.far : code === 'not_wanted' ? buyerText.notWanted : code === 'no_items' ? buyerText.noItems : code === 'sleeping' ? buyerText.sleeping : buyerText.failed });
                }
            }
            if (soldN > 0) notify({ text: buyerText.sold(soldN, soldCoins), key: 'sold' });
            if (limited) notify({ text: soldN > 0 && buyerLeft > 0 ? buyerText.soldLimit(buyerLeft) : buyerText.limitDone, key: 'sold_limit' });
        },
        dispose() { clearTimer(flushTimer); },
    };
}
