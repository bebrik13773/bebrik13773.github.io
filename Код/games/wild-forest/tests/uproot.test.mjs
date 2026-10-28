import test from 'node:test';
import assert from 'node:assert/strict';
import { createUprootFlow } from '../src/world/uproot-flow.js';
import { uprootNeed, uprootCost } from '../src/shared/formulas.js';

function setup({ pickaxe = true, energy = 50 } = {}) {
    const pushed = [];
    const toasts = [];
    const uprooted = [];
    const unuprooted = [];
    let canceled = 0;
    const world = {
        heroPos: { x: 1, z: 2 },
        chunks: { uproot: (id) => uprooted.push(id), unuproot: (id) => unuprooted.push(id) },
        ctl: { mode: 'work', obj: null, cancelWork: () => { canceled += 1; } },
        projectToScreen: () => null,
    };
    const queue = { push: (type, data) => pushed.push({ type, data }) };
    const flow = createUprootFlow({ world, getQueue: () => queue, getSync: () => null, bar: null, notify: (t) => toasts.push(t.text), text: { need_tool: 'tool', done: 'done', no_energy: 'energy', failed: 'failed', too_far: 'far' }, hasPickaxe: () => pickaxe, getEnergy: () => energy });
    return { flow, pushed, toasts, uprooted, unuprooted, get canceled() { return canceled; } };
}
const stump = { id: '3:-2:17', kind: 'stump', x: 5, y: 0, z: 5, size: 1 };

test('формулы: удары и энергия по размеру пня', () => {
    assert.deepEqual([0, 1, 2].map(uprootNeed), [4, 6, 9]);
    assert.equal(uprootCost(1), 6 * 0.4);
});

test('без кирки пень не выкорчёвывается', () => {
    const t = setup({ pickaxe: false });
    t.flow.onWorldEvent({ type: 'ready', obj: stump });
    assert.equal(t.flow.active, false);
    assert.deepEqual(t.toasts, ['tool']);
    assert.equal(t.pushed.length, 0);
});

test('с киркой: нужное число ударов, потом действие uproot в очередь', () => {
    const t = setup();
    t.flow.onWorldEvent({ type: 'ready', obj: stump });
    assert.equal(t.flow.active, true);
    for (let i = 0; i < 5; i += 1) t.flow.onWorldEvent({ type: 'workTap', obj: stump });
    assert.equal(t.pushed.length, 0, 'пока рано');
    t.flow.onWorldEvent({ type: 'workTap', obj: stump });
    assert.equal(t.uprooted.length, 1);
    assert.equal(t.pushed.length, 1);
    assert.equal(t.pushed[0].type, 'uproot');
    assert.equal(t.pushed[0].data.tree, stump.id);
    assert.equal(t.pushed[0].data.hits, 6);
    assert.equal(t.flow.active, false);
});

test('не хватает энергии: не начинаем', () => {
    const t = setup({ energy: 1 });
    t.flow.onWorldEvent({ type: 'ready', obj: stump });
    assert.equal(t.flow.active, false);
    assert.deepEqual(t.toasts, ['energy']);
});

test('отказ сервера возвращает пень, а stump_gone оставляет выкорчеванным', () => {
    const t = setup();
    t.flow.pending.set(stump.id, 2);
    t.flow.onResults([{ type: 'uproot', tree: stump.id, ok: false, error: 'too_far' }]);
    assert.deepEqual(t.unuprooted, [stump.id]);
    t.flow.onResults([{ type: 'uproot', tree: stump.id, ok: false, error: 'stump_gone' }]);
    assert.deepEqual(t.unuprooted, [stump.id], 'второй раз откат не нужен');
});

test('деревья и чужие события игнорируются', () => {
    const t = setup();
    t.flow.onWorldEvent({ type: 'ready', obj: { id: '1:1:1', kind: 'tree' } });
    assert.equal(t.flow.active, false);
});
