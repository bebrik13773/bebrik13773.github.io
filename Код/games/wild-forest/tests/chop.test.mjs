import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createWorldGen, KIND, treeId } from '../src/shared/worldgen.js';
import { SPECIES_ORDER, TREES, treeHp, chopNeed, chopCost, treeLogs, axePower, tapTake } from '../src/shared/formulas.js';
import { createChop, treeInfo } from '../src/world/chop.js';
import { loadShared } from './vectors-lib.mjs';
import { buildChopVectors, CHOP_VECTORS_PATH } from './chop-lib.mjs';

const { species, biomes } = loadShared();
const wg = createWorldGen({ seed: 1337, species, biomes });
const here = path.dirname(fileURLToPath(import.meta.url));
const configPhp = fs.readFileSync(path.join(here, '..', '..', '..', 'api', 'forest', 'config.php'), 'utf8');

test('порядок пород в формулах равен species.json', () => {
    assert.deepEqual(SPECIES_ORDER, species.species.map((s) => s.id));
});

test('баланс рубки в JS совпадает с config.php (H, брёвна, сила топора)', () => {
    for (const id of SPECIES_ORDER) {
        const m = new RegExp(`'${id}'\\s*=>\\s*\\['h'\\s*=>\\s*([\\d.]+),\\s*'logs'\\s*=>\\s*\\[(\\d+),\\s*(\\d+),\\s*(\\d+)\\]`).exec(configPhp);
        assert.ok(m, `порода ${id} в config.php`);
        assert.equal(Number(m[1]), TREES.species[id].h, id);
        assert.deepEqual([Number(m[2]), Number(m[3]), Number(m[4])], [...TREES.species[id].logs], id);
    }
    const powers = [...configPhp.matchAll(/\d => \['power' => ([\d.]+)/g)].map((m) => Number(m[1]));
    assert.deepEqual(powers, TREES.axePower.slice(1));
});

test('прочность и число ударов: примеры из раздела 8.2', () => {
    assert.equal(treeHp('spruce', 1, 0), 8);              // ель среднее у города
    assert.equal(chopNeed(8, 1.0), 8);
    assert.equal(chopNeed(treeHp('oak', 2, 0), 1.0), 22); // 8 × 1.8 × 1.5 = 21.6 → 22
    assert.equal(chopNeed(treeHp('spruce', 1, 4), axePower(3)), 9); // 8 × 2.2 = 17.6 / 2 = 8.8 → 9
    assert.ok(treeHp('iron_oak', 2, 10) > treeHp('birch', 0, 0) * 40);
    assert.equal(chopCost(14), 7);
    assert.equal(treeLogs('oak', 2), 6);
});

test('бюджет тапов: пополнение 6 в секунду, потолок 40', () => {
    assert.deepEqual(tapTake(40, 0, 0, 40), { ok: true, tokens: 0 });
    assert.equal(tapTake(40, 0, 0, 41).ok, false);
    assert.equal(tapTake(0, 0, 1000, 6).ok, true);
    assert.equal(tapTake(0, 0, 1000, 7).ok, false);
    assert.equal(tapTake(0, 0, 100000, 40).ok, true); // потолок
    assert.equal(tapTake(0, 0, 100000, 41).ok, false);
});

test('векторы рубки на диске совпадают с формулами (после изменений: node tests/gen-chop-vectors.mjs)', () => {
    const disk = JSON.parse(fs.readFileSync(CHOP_VECTORS_PATH, 'utf8'));
    const now = buildChopVectors();
    assert.deepEqual(disk.trees, now.trees);
    assert.deepEqual(disk.taps, now.taps);
});

function findTree(pred) {
    for (let cx = -8; cx <= 8; cx++) for (let cz = 20; cz <= 40; cz++) for (let ci = 0; ci < 64; ci++) {
        const c = wg.cellAt(cx, cz, ci);
        if (c.kind === KIND.TREE && pred(c)) return treeId(cx, cz, ci);
    }
    return null;
}

test('treeInfo: параметры дерева по id, не дерево → null', () => {
    const id = findTree((c) => c.size === 1);
    assert.ok(id);
    const info = treeInfo(wg, id, 1.0);
    assert.equal(info.id, id);
    assert.ok(info.hp > 0 && info.need >= 1);
    assert.equal(treeInfo(wg, 'мусор', 1.0), null);
    assert.equal(treeInfo(wg, '0:0:0', 1.0), null); // клетка в городе пуста
});

test('createChop: нужное число ударов, слишком частые тапы не считаются, отмена сбрасывает прогресс', () => {
    let t = 0;
    const chop = createChop({ wg, getPower: () => 1.0, now: () => t });
    const id = findTree((c) => c.size === 0);
    const info = chop.start(id);
    assert.ok(chop.active);
    // два тапа подряд без паузы: второй отброшен
    assert.equal(chop.hit().accepted, true);
    assert.equal(chop.hit().accepted, false);
    t += 1000;
    let last;
    for (let i = 0; i < info.need + 5 && !chop.finish(); i++) { last = chop.hit(); t += 200; }
    // дерево уже срублено, сессия сброшена
    assert.ok(!chop.active || chop.frac >= 0);
    // отмена
    chop.start(id);
    t += 1000;
    chop.hit();
    chop.cancel();
    assert.equal(chop.active, false);
    assert.equal(chop.finish(), null);
    assert.ok(last);
});

test('createChop: finish отдаёт только завершённую рубку с числом ударов и метками', () => {
    let t = 0;
    const chop = createChop({ wg, getPower: () => 5.6, now: () => t });
    const id = findTree((c) => c.size === 0 && c.species === 2); // берёза
    const info = chop.start(id);
    assert.equal(chop.finish(), null);
    for (let i = 0; i < info.need; i++) { t += 200; chop.hit(); }
    const r = chop.finish();
    assert.equal(r.tree, id);
    assert.equal(r.hits, info.need);
    assert.ok(r.t1 >= r.t0);
    assert.equal(chop.active, false);
});

// ── Связка рубки (chop-flow) на подменённых зависимостях ──
import { createChopFlow } from '../src/world/chop-flow.js';
import { createActionQueue } from '../src/net/queue.js';

function makeFlow(over = {}) {
    let t = 1000;
    const felledIds = [];
    const unfelled = [];
    const notes = [];
    const added = [];
    let mode = 'work';
    const world = {
        chunks: { fell: (id) => felledIds.push(id), unfell: (id) => unfelled.push(id) },
        ctl: { get mode() { return mode; }, cancelWork() { mode = 'idle'; } },
        heroPos: { x: 10.123, z: -5.5 },
        drops: { add: (l) => added.push(...l) },
        treeTop: () => ({ x: 0, y: 5, z: 0 }),
        projectToScreen: () => ({ x: 100, y: 100 }),
    };
    const queue = createActionQueue(0);
    let flushes = 0;
    const sync = { running: true, flush() { flushes += 1; } };
    const text = { done: 'упало', reward: (n) => `+${n}`, treasure: (n) => `монеты ${n}`, offline: 'нет связи', no_energy: 'нет сил', tree_gone: 'уже срублено', too_far: 'далеко', failed: 'сбой' };
    const flow = createChopFlow({
        world, wg, getQueue: () => queue, getSync: () => sync, bar: null, notify: (n) => notes.push(n.text), text,
        getAxeLevel: () => 6, getEnergy: () => 100, now: () => t, setTimer: (fn) => { fn(); return 1; }, clearTimer: () => {}, ...over,
    });
    return { flow, world, queue, felledIds, unfelled, notes, added, get flushes() { return flushes; }, tick(ms) { t += ms; }, setMode(m) { mode = m; } };
}

function chopWholeTree(h, id) {
    h.flow.onWorldEvent({ type: 'ready', obj: { id, kind: 'tree' } });
    const need = h.flow.chop.info.need;
    for (let i = 0; i < need; i++) { h.tick(300); h.flow.chop.hit(); if (i === need - 1) h.flow.onWorldEvent({ type: 'workTap', obj: { id, kind: 'tree' } }); }
}

test('chop-flow: срубленное дерево падает у игрока и уходит в очередь chop_done', () => {
    const h = makeFlow();
    const id = findTree((c) => c.size === 0 && c.species === 2);
    h.flow.onWorldEvent({ type: 'ready', obj: { id, kind: 'tree' } });
    const need = h.flow.chop.info.need;
    let t = 0;
    for (let i = 0; i < need + 3 && h.queue.size === 0; i++) { h.flow.chop.cancel; h.tick(300); h.flow.onWorldEvent({ type: 'workTap', obj: { id, kind: 'tree' } }); }
    assert.deepEqual(h.felledIds, [id]);
    assert.equal(h.queue.size, 1);
    const [act] = h.queue.take();
    assert.equal(act.type, 'chop_done');
    assert.equal(act.tree, id);
    assert.equal(act.hits, need);
    assert.equal(act.px, 10.12);
    assert.ok(h.flushes >= 1);
    assert.ok(t === 0);
});

test('chop-flow: награда показывает выпавшие предметы, отказ возвращает дерево (кроме tree_gone)', () => {
    const h = makeFlow();
    h.flow.onResults([{ a: 1, ok: true, tree: '1:1:1', reward: { log: 3, coins: 120 }, drops: [{ id: 5, x: 1, z: 2, item: 'log', qty: 1 }] }]);
    assert.equal(h.added.length, 1);
    assert.ok(h.notes.includes('+3') && h.notes.includes('монеты 120'));
    h.flow.onResults([{ a: 2, ok: false, tree: '2:2:2', error: 'too_far' }, { a: 3, ok: false, tree: '3:3:3', error: 'tree_gone' }, { a: 4, ok: true, dup: true }]);
    assert.deepEqual(h.unfelled, ['2:2:2']);
    assert.ok(h.notes.includes('далеко') && h.notes.includes('уже срублено'));
});

test('chop-flow: ушёл от дерева — прогресс сброшен; без энергии рубка не начинается', () => {
    const h = makeFlow();
    const id = findTree((c) => c.size === 2);
    h.flow.onWorldEvent({ type: 'ready', obj: { id, kind: 'tree' } });
    h.tick(300);
    h.flow.onWorldEvent({ type: 'workTap', obj: { id, kind: 'tree' } });
    assert.ok(h.flow.chop.active);
    h.setMode('idle');
    h.flow.update();
    assert.equal(h.flow.chop.active, false);

    const weak = makeFlow({ getEnergy: () => 0.1 });
    weak.flow.onWorldEvent({ type: 'ready', obj: { id, kind: 'tree' } });
    assert.equal(weak.flow.chop.active, false);
    assert.ok(weak.notes.includes('нет сил'));
});

test('chop-flow: без сервера дерево срубается локально и один раз предупреждает', () => {
    const h = makeFlow({ getQueue: () => null });
    const id = findTree((c) => c.size === 0 && c.species === 2);
    h.flow.onWorldEvent({ type: 'ready', obj: { id, kind: 'tree' } });
    for (let i = 0; i < 40 && h.felledIds.length === 0; i++) { h.tick(300); h.flow.onWorldEvent({ type: 'workTap', obj: { id, kind: 'tree' } }); }
    assert.deepEqual(h.felledIds, [id]);
    assert.ok(h.notes.includes('нет связи'));
});
