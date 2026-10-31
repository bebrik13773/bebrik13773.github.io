// ДЛ-14: ящик-тайник и скупщик на поляне (клиентская связка). Запуск: node --test tests/crate.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { createCrateFlow } from '../src/world/crate-flow.js';
import { normalizeVillage, applyLightState } from '../src/net/apply-state.js';

const TEXT = { sleeping: 'сон', offline: 'нет связи', noSpaceBox: 'ящик полон', noSpacePack: 'рюкзак полон', noItems: 'нет такого', far: 'далеко', failed: 'ошибка', partialPut: 'часть в ящик', partialTake: 'часть в рюкзак' };
const BUYER = {
    speaker: 'Скупщик', greet: (p, l) => `цены: ${p}; лимит ${l}`, limit: 'лимит', nothing: 'нечего', sellAll: 'всё', sellLogs: 'брёвна', sellFish: 'рыбу', later: 'пока', price: (n, p) => `${n} ${p}`,
    sold: (n, c) => `продано ${n} за ${c}`, soldLimit: (l) => `осталось ${l}`, limitDone: 'хватит', far: 'далеко', sleeping: 'сон', notWanted: 'не берёт', noItems: 'нет', offline: 'нет связи', failed: 'ошибка',
};

function make({ sleeping = false, online = true, choice = 'all' } = {}) {
    const pushed = [];
    const toasts = [];
    const flushes = [];
    const said = [];
    const inv = { removed: [], added: [], remove(k, n) { this.removed.push([k, n]); }, add(k, n) { this.added.push([k, n]); } };
    let panel = null;
    const crateUi = { set(m) { panel = m; }, open() { crateUi.opened = true; }, opened: false };
    const world = { heroPos: { x: 10.123, z: -5.5 }, ctl: { cancelWork() {} } };
    const queue = { push(type, data) { pushed.push({ type, ...data }); return pushed.length; } };
    const flow = createCrateFlow({
        world, crateUi, inventory: inv, notify: (t) => toasts.push(t.text), text: TEXT, buyerText: BUYER,
        dialog: { say: async (line) => { said.push(line); return { id: choice }; } },
        names: { log: 'Бревно', fish_small: 'Мелкая рыба', fish_big: 'Крупная рыба' },
        getQueue: () => (online ? queue : null), getSync: () => ({ running: true, flush: () => flushes.push(1) }),
        isSleeping: () => sleeping, now: () => 1e9, setTimer: (fn) => fn(), clearTimer: () => {},
    });
    flow.setPrices({ log: 6, fish_small: 10.8, fish_big: 24 });
    return { flow, pushed, toasts, flushes, said, inv, crateUi, get panel() { return panel; } };
}

test('состояние сервера: рюкзак, ящик, вместимость и остаток лимита попадают в панель', () => {
    const t = make();
    t.flow.onState({ pack: { log: 3 }, crate: { log: 5, plank: 1 }, crate_cap: 100, buyer_left: 400 });
    assert.deepEqual(t.panel, { pack: [{ key: 'log', qty: 3 }], box: [{ key: 'log', qty: 5 }, { key: 'plank', qty: 1 }], used: 6, cap: 100 });
    assert.equal(t.flow.buyerLeft, 400);
});

test('сдать в ящик: действие crate_put с позицией, рюкзак на экране обновляется сразу', () => {
    const t = make();
    t.flow.onState({ pack: { log: 4 }, crate: {}, crate_cap: 100, buyer_left: 400 });
    assert.equal(t.flow.move({ from: 'pack', item: 'log', qty: 3 }), true);
    assert.deepEqual(t.pushed, [{ type: 'crate_put', item: 'log', qty: 3, px: 10.12, pz: -5.5 }]);
    assert.deepEqual(t.inv.removed, [['log', 3]]);
    assert.equal(t.flow.pack.log, 1);
    assert.equal(t.flow.box.log, 3);
    assert.equal(t.flushes.length, 1);
});

test('сдать больше, чем есть или влезает: берём сколько можно, при полном ящике отказ', () => {
    const t = make();
    t.flow.onState({ pack: { log: 5 }, crate: { plank: 98 }, crate_cap: 100 });
    t.flow.move({ from: 'pack', item: 'log', qty: 50 });
    assert.equal(t.pushed[0].qty, 2); // влезает только 2 места
    t.flow.onState({ pack: { log: 5 }, crate: { plank: 100 }, crate_cap: 100 });
    assert.equal(t.flow.move({ from: 'pack', item: 'log', qty: 1 }), false);
    assert.equal(t.toasts.at(-1), 'ящик полон');
});

test('взять из ящика: crate_take, предмет добавляется в рюкзак на экране', () => {
    const t = make();
    t.flow.onState({ pack: {}, crate: { log: 4 }, crate_cap: 100 });
    assert.equal(t.flow.move({ from: 'box', item: 'log', qty: 4 }), true);
    assert.equal(t.pushed[0].type, 'crate_take');
    assert.deepEqual(t.inv.added, [['log', 4]]);
});

test('спящий и офлайн ничего не переносят и не продают', () => {
    const s = make({ sleeping: true });
    s.flow.onState({ pack: { log: 1 }, crate: {}, crate_cap: 100, buyer_left: 400 });
    assert.equal(s.flow.move({ from: 'pack', item: 'log', qty: 1 }), false);
    assert.equal(s.flow.sell([{ item: 'log', qty: 1 }]), 0);
    const o = make({ online: false });
    o.flow.onState({ pack: { log: 1 }, crate: {}, crate_cap: 100 });
    assert.equal(o.flow.move({ from: 'pack', item: 'log', qty: 1 }), false);
    assert.equal(o.pushed.length, 0);
});

test('продажа: sell на каждый товар, только то, что берёт скупщик', () => {
    const t = make();
    t.flow.onState({ pack: { log: 10, plank: 2, fish_small: 3 }, crate: {}, crate_cap: 100, buyer_left: 400 });
    const n = t.flow.sell([{ item: 'log', qty: 10 }, { item: 'plank', qty: 2 }, { item: 'fish_small', qty: 3 }]);
    assert.equal(n, 2); // доски скупщик не берёт: цены на них нет
    assert.deepEqual(t.pushed.map((p) => [p.type, p.item, p.qty]), [['sell', 'log', 10], ['sell', 'fish_small', 3]]);
});

test('диалог скупщика: «продать всё» отправляет всё продаваемое, с ценами и лимитом в тексте', async () => {
    const t = make({ choice: 'all' });
    t.flow.onState({ pack: { log: 7, fish_big: 1, plank: 4 }, crate: {}, crate_cap: 100, buyer_left: 250 });
    await t.flow.talkToBuyer();
    assert.match(t.said[0].text, /лимит 250/);
    assert.match(t.said[0].text, /бревно 6/);
    assert.deepEqual(t.pushed.map((p) => [p.item, p.qty]), [['log', 7], ['fish_big', 1]]);
});

test('диалог скупщика: «только брёвна», «пока нет», пустой рюкзак и исчерпанный лимит', async () => {
    const a = make({ choice: 'logs' });
    a.flow.onState({ pack: { log: 2, fish_small: 2 }, crate: {}, crate_cap: 100, buyer_left: 400 });
    await a.flow.talkToBuyer();
    assert.deepEqual(a.pushed.map((p) => p.item), ['log']);
    const b = make({ choice: 'later' });
    b.flow.onState({ pack: { log: 2 }, crate: {}, crate_cap: 100, buyer_left: 400 });
    await b.flow.talkToBuyer();
    assert.equal(b.pushed.length, 0);
    const c = make();
    c.flow.onState({ pack: {}, crate: {}, crate_cap: 100, buyer_left: 400 });
    await c.flow.talkToBuyer();
    assert.equal(c.said[0].text, 'нечего');
    assert.equal(c.said[0].choices, undefined);
    const d = make();
    d.flow.onState({ pack: { log: 3 }, crate: {}, crate_cap: 100, buyer_left: 0 });
    await d.flow.talkToBuyer();
    assert.equal(d.said[0].text, 'лимит');
    assert.equal(d.pushed.length, 0);
});

test('результаты: продано, лимит, отказы переносов', () => {
    const t = make();
    t.flow.onResults([{ a: 1, ok: true, type: 'sell', item: 'log', sold: 66, coins: 396, left_today: 4, rest: 4 }]);
    assert.ok(t.toasts.includes('продано 66 за 396'));
    assert.ok(t.toasts.includes('осталось 4'));
    assert.equal(t.flow.buyerLeft, 4);
    const u = make();
    u.flow.onResults([{ a: 2, ok: false, type: 'sell', item: 'log', error: 'daily_limit', left: 0 }]);
    assert.deepEqual(u.toasts, ['хватит']);
    assert.equal(u.flow.buyerLeft, 0);
    const v = make();
    v.flow.onResults([{ a: 3, ok: false, type: 'crate_put', error: 'no_space' }, { a: 4, ok: false, type: 'crate_take', error: 'no_space' }, { a: 5, ok: false, type: 'crate_put', error: 'too_far' }, { a: 6, ok: true, type: 'crate_put', moved: 2, left: 3 }, { a: 7, ok: true, dup: true }]);
    assert.deepEqual(v.toasts, ['ящик полон', 'рюкзак полон', 'далеко', 'часть в ящик']);
});

test('событие сцены: подход к ящику открывает панель, к подводе начинает разговор, у спящего и офлайн только сообщение', () => {
    const t = make();
    t.flow.onState({ pack: {}, crate: { log: 1 }, crate_cap: 100, buyer_left: 400 });
    t.flow.onWorldEvent({ type: 'ready', obj: { kind: 'crate' } });
    assert.equal(t.crateUi.opened, true);
    t.flow.onWorldEvent({ type: 'ready', obj: { kind: 'buyer' } });
    assert.equal(t.said.length, 1);
    const s = make({ sleeping: true });
    s.flow.onWorldEvent({ type: 'ready', obj: { kind: 'crate' } });
    assert.equal(s.crateUi.opened, false);
    assert.deepEqual(s.toasts, ['сон']);
    const o = make({ online: false });
    o.flow.onWorldEvent({ type: 'ready', obj: { kind: 'buyer' } });
    assert.equal(o.said.length, 0);
});

test('состояние: нормализация поляны и передача ящика в applyLightState', () => {
    assert.equal(normalizeVillage(null), null);
    assert.equal(normalizeVillage({ name: 'Х' }), null);
    const v = normalizeVillage({ name: 'Поляна 7', x: 1, z: 2, crate: { x: 4.5, z: -0.5 }, buyer: { x: -3, z: 5, prices: { log: 6 }, cap: 400 } });
    assert.deepEqual(v.crate, { x: 4.5, z: -0.5 });
    assert.deepEqual(v.buyer, { x: -3, z: 5, prices: { log: 6 }, cap: 400 });
    const got = [];
    applyLightState({ energy: 50, pack: {}, crate: { log: 2 } }, { crate: { onState: (st) => got.push(st.crate) } });
    assert.deepEqual(got, [{ log: 2 }]);
});
