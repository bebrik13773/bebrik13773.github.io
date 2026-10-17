// ДЛ-13: рюкзак, вес ноши, подбор и бросание. Запуск: node --test tests/pack.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { PACK, itemKg, packKg, packFit, moveLoad, packCapacity, heroSpeed, moveAllowance } from '../src/shared/formulas.js';
import { PACK_VECTORS_PATH, buildPackVectors } from './pack-lib.mjs';
import { createPackFlow } from '../src/world/pack-flow.js';

test('вес: бревно 7 кг, остальное по таблице, неизвестное 1 кг', () => {
    assert.equal(itemKg('log'), 7);
    assert.equal(itemKg('ore'), 8);
    assert.equal(itemKg('seed'), 0.1);
    assert.equal(itemKg('чудо'), 1);
    assert.equal(packKg({ log: 3, plank: 2 }), 27);
    assert.equal(packKg({}), 0);
});

test('вместимость: 40 кг и +10 за уровень до 8', () => {
    assert.equal(packCapacity(1), 40);
    assert.equal(packCapacity(8), 110);
    assert.equal(packCapacity(99), 110);
});

test('сколько влезет: по весу, не больше запрошенного, 0 при полном', () => {
    assert.equal(packFit(40, 0, 'log', 10), 5);
    assert.equal(packFit(40, 33, 'log', 3), 1);
    assert.equal(packFit(40, 40, 'log', 1), 0);
    assert.equal(packFit(40, 45, 'log', 1), 0);
    assert.equal(packFit(40, 0, 'log', 2), 2);
    assert.equal(packFit(40, 0, 'seed', 50), 50);
});

test('груз для проверки скорости: брошенное в том же пакете вычитается, лишнее не уходит в минус', () => {
    assert.equal(moveLoad({ log: 5, plank: 2 }, [{ type: 'drop', item: 'log', qty: 2 }]), 27);
    assert.equal(moveLoad({ log: 5 }, [{ type: 'drop', item: 'log', qty: 9 }]), 0);
    assert.equal(moveLoad({ log: 2 }, [{ type: 'pickup', drop: 1 }]), 14);
});

test('вес замедляет бобра, а сервер даёт ровно такой допуск (без дорог и сапог)', () => {
    const free = heroSpeed({ load: 0, capacity: 40 });
    const full = heroSpeed({ load: 40, capacity: 40 });
    assert.ok(Math.abs(free - 3.2) < 1e-9);
    assert.ok(Math.abs(full - 3.2 * 0.45) < 1e-9);
    // полный рюкзак на скорости пустого не пройдёт проверку: 8 секунд × 3.2 м/с = 25.6 м, допуск 1.44 × 8 × 1.35 + 3 = 18.5
    assert.ok(moveAllowance(full, 8) < 3.2 * 8);
    assert.ok(moveAllowance(free, 8) > 3.2 * 8);
});

test('векторы pack.json совпадают с формулами (их же читает PHP на selftest)', () => {
    const file = JSON.parse(fs.readFileSync(PACK_VECTORS_PATH, 'utf8'));
    const fresh = JSON.parse(JSON.stringify(buildPackVectors()));
    for (const key of ['kg', 'fits', 'kgs', 'loads', 'speeds', 'capacities']) assert.deepEqual(file[key], fresh[key], key);
    assert.ok(file.fits.length >= 250);
});

// ── Поток подбора и бросания ─────────────────────────────────────────
function harness({ items = [], cap = 40, pos = { x: 0, z: 0 }, auto = true, sleeping = false, withQueue = true } = {}) {
    const drops = new Map();
    const noAuto = new Set();
    const pushed = [];
    const notes = [];
    let flushes = 0;
    const inv = {
        items: items.map((i) => ({ ...i })),
        get weight() { return this.items.reduce((s, i) => s + i.qty * i.kg, 0); },
        add(key, qty, kg) { const it = this.items.find((i) => i.key === key); if (it) it.qty += qty; else this.items.push({ key, qty, kg }); },
        remove(key, qty) { const it = this.items.find((i) => i.key === key); if (!it) return 0; const n = Math.min(it.qty, qty); it.qty -= n; this.items = this.items.filter((i) => i.qty > 0); return n; },
    };
    const world = {
        heroPos: pos,
        ctl: { cancelWork() { world.cancelled = true; } },
        drops: {
            add(list, opts) { for (const d of list) { drops.set(d.id, { ...d }); if (opts && opts.noAuto) noAuto.add(d.id); else noAuto.delete(d.id); } },
            remove(id) { noAuto.delete(id); return drops.delete(id); },
            get: (id) => drops.get(id) || null,
            list: () => [...drops.values()],
            setNoAuto(id, f) { if (f) noAuto.add(id); else noAuto.delete(id); },
            isNoAuto: (id) => noAuto.has(id),
            expire: () => [],
        },
    };
    const queue = withQueue ? { push(type, p) { pushed.push({ type, ...p }); return pushed.length; } } : null;
    const sync = { running: true, flush() { flushes += 1; } };
    let t = 1000000;
    const text = { full: 'полон', picked: (n, q) => `+${q} ${n}`, thrown: (n, q) => `бросил ${n} ${q}`, gone: 'ушло', far: 'далеко', sleeping: 'спит', offline: 'нет связи', tooMany: 'много', failed: 'ошибка' };
    const flow = createPackFlow({ world, getQueue: () => queue, getSync: () => sync, getInventory: () => inv, getCapacity: () => cap, notify: (n) => notes.push(n.text), text, names: { log: 'бревно', ore: 'руда' }, isAuto: () => auto, isSleeping: () => sleeping, now: () => t, setTimer: () => 0, clearTimer: () => {} });
    return { flow, world, inv, pushed, notes, drops, noAuto, get flushes() { return flushes; }, advance(ms) { t += ms; } };
}

test('подбор: действие в очереди, предмет сразу уходит с земли и появляется в рюкзаке', () => {
    const h = harness();
    h.world.drops.add([{ id: 5, x: 0.5, z: 0, item: 'log', qty: 1 }]);
    assert.equal(h.flow.pickup(5), true);
    assert.deepEqual(h.pushed[0], { type: 'pickup', drop: 5, px: 0, pz: 0 });
    assert.equal(h.drops.size, 0);
    assert.equal(h.inv.weight, 7);
    assert.equal(h.flow.pickup(5), false, 'повторно нельзя');
    assert.equal(h.flushes, 1);
});

test('подбор: рюкзак полон, на клиенте отказ без запроса', () => {
    const h = harness({ items: [{ key: 'log', qty: 5, kg: 7 }] });
    h.world.drops.add([{ id: 6, x: 0, z: 0, item: 'log', qty: 1 }]);
    assert.equal(h.flow.pickup(6), false);
    assert.equal(h.pushed.length, 0);
    assert.equal(h.drops.size, 1);
    assert.deepEqual(h.notes, ['полон']);
});

test('автоподбор: берёт брёвна рядом, не берёт далёкие, руду и брошенное', () => {
    const h = harness();
    h.world.drops.add([
        { id: 1, x: 1.0, z: 0, item: 'log', qty: 1 },
        { id: 2, x: 5.0, z: 0, item: 'log', qty: 1 },
        { id: 3, x: 0.5, z: 0, item: 'ore', qty: 1 },
    ]);
    h.world.drops.add([{ id: 4, x: 0.3, z: 0, item: 'log', qty: 1 }], { noAuto: true });
    h.flow.update();
    assert.deepEqual(h.pushed.map((p) => p.drop), [1]);
    assert.equal(h.drops.has(2) && h.drops.has(3) && h.drops.has(4), true);
});

test('автоподбор: выключен или бобёр спит — ничего не берёт; отошёл от брошенного — снова подбирается', () => {
    const off = harness({ auto: false });
    off.world.drops.add([{ id: 1, x: 0.5, z: 0, item: 'log', qty: 1 }]);
    off.flow.update();
    assert.equal(off.pushed.length, 0);

    const h = harness();
    h.world.drops.add([{ id: 9, x: 0.3, z: 0, item: 'log', qty: 1 }], { noAuto: true });
    h.flow.update();
    assert.equal(h.pushed.length, 0);
    h.world.heroPos.x = 6;
    h.advance(400);
    h.flow.update();
    assert.equal(h.noAuto.has(9), false, 'метка снята');
    h.world.heroPos.x = 0.5;
    h.advance(400);
    h.flow.update();
    assert.equal(h.pushed.length, 1);
});

test('бросание: предмет уходит из рюкзака, действие drop в очереди; ответ кладёт предмет на землю без автоподбора', () => {
    const h = harness({ items: [{ key: 'log', qty: 3, kg: 7 }] });
    assert.equal(h.flow.dropItem('log', 2), true);
    assert.deepEqual(h.pushed[0], { type: 'drop', item: 'log', qty: 2 });
    assert.equal(h.inv.weight, 7);
    h.flow.onResults([{ a: 1, type: 'drop', ok: true, drop: { id: 50, x: 1, z: 1, item: 'log', qty: 2, ttl: 900 } }]);
    assert.equal(h.drops.get(50).qty, 2);
    assert.equal(h.noAuto.has(50), true);
    assert.equal(h.flow.throwing, 0);
    assert.match(h.notes.at(-1), /бросил бревно 2/);
});

test('бросание: без связи и без предмета ничего не происходит', () => {
    const off = harness({ items: [{ key: 'log', qty: 1, kg: 7 }], withQueue: false });
    assert.equal(off.flow.dropItem('log', 1), false);
    assert.equal(off.inv.weight, 7);
    const none = harness();
    assert.equal(none.flow.dropItem('log', 1), false);
    assert.equal(none.pushed.length, 0);
    const asleep = harness({ items: [{ key: 'log', qty: 1, kg: 7 }], sleeping: true });
    assert.equal(asleep.flow.dropItem('log', 1), false);
    assert.equal(asleep.inv.weight, 7);
});

test('ответ сервера: успех — одно сообщение; нет предмета — не возвращаем; слишком далеко — возвращаем без автоподбора', () => {
    const h = harness();
    h.world.drops.add([{ id: 1, x: 0.5, z: 0, item: 'log', qty: 1 }, { id: 2, x: 0.6, z: 0, item: 'log', qty: 1 }, { id: 3, x: 0.7, z: 0, item: 'log', qty: 1 }]);
    h.flow.pickup(1); h.flow.pickup(2); h.flow.pickup(3);
    h.flow.onResults([
        { a: 1, type: 'pickup', drop: 1, ok: true, item: 'log', taken: 1, left: 0 },
        { a: 2, type: 'pickup', drop: 2, ok: false, error: 'no_items' },
        { a: 3, type: 'pickup', drop: 3, ok: false, error: 'too_far' },
    ]);
    assert.equal(h.drops.has(1), false);
    assert.equal(h.drops.has(2), false);
    assert.equal(h.drops.has(3), true);
    assert.equal(h.noAuto.has(3), true);
    assert.deepEqual(h.notes, ['ушло', 'далеко', '+1 бревно']);
    assert.equal(h.flow.pending.size, 0);
});

test('частичный подбор: остаток стопки остаётся на земле', () => {
    const h = harness();
    h.world.drops.add([{ id: 7, x: 0.5, z: 0, item: 'ore', qty: 3 }]);
    h.flow.pickup(7);
    h.flow.onResults([{ a: 1, type: 'pickup', drop: 7, ok: true, item: 'ore', taken: 2, left: 1 }]);
    assert.equal(h.drops.get(7).qty, 1);
    assert.equal(h.noAuto.has(7), true);
});

test('тап по предмету: событие ready с видом drop отменяет работу и подбирает', () => {
    const h = harness();
    h.world.drops.add([{ id: 8, x: 1, z: 0, item: 'log', qty: 1 }]);
    h.flow.onWorldEvent({ type: 'ready', obj: { kind: 'drop', dropId: 8 } });
    assert.equal(h.world.cancelled, true);
    assert.equal(h.pushed.length, 1);
    h.flow.onWorldEvent({ type: 'ready', obj: { kind: 'tree', id: '1:1:1' } });
    assert.equal(h.pushed.length, 1);
});

test('константы подбора совпадают с config.php', () => {
    const php = fs.readFileSync(new URL('../../../api/forest/config.php', import.meta.url), 'utf8');
    assert.match(php, new RegExp(`'pickup_r_m'\\s*=>\\s*${PACK.pickupR.toFixed(1)}`));
    assert.match(php, new RegExp(`'auto_pickup_r_m'\\s*=>\\s*${PACK.autoPickupR.toFixed(1)}`));
    assert.match(php, /'weights_kg'.*'plank' => 3.*'ore' => 8.*'ingot' => 5.*'stone' => 10/);
});
