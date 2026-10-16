import test from 'node:test';
import assert from 'node:assert/strict';
import { createActionQueue } from '../src/net/queue.js';
import { createSync } from '../src/net/sync.js';
import { openSession } from '../src/net/session.js';
import { packToItems, applyLightState, startFromState } from '../src/net/apply-state.js';

test('очередь: номера растут, take повторяет неподтверждённые, ack убирает', () => {
    const q = createActionQueue(5);
    assert.equal(q.push('x'), 6);
    assert.equal(q.push('y'), 7);
    let b = q.take();
    assert.deepEqual(b.map((x) => x.a), [6, 7]);
    q.push('z');
    b = q.take(); // 6 и 7 не подтверждены: уходят снова вместе с 8
    assert.deepEqual(b.map((x) => x.a), [6, 7, 8]);
    q.ack([{ a: 6 }, { a: 7 }]);
    assert.deepEqual(q.take().map((x) => x.a), [8]);
    q.ack([{ a: 8 }]);
    assert.equal(q.size, 0);
    assert.equal(q.lastA, 8);
});

test('очередь: лимит пакета и порядок', () => {
    const q = createActionQueue(0, 3);
    for (let i = 0; i < 5; i += 1) q.push('t');
    assert.deepEqual(q.take().map((x) => x.a), [1, 2, 3]);
    q.ack([{ a: 1 }, { a: 2 }, { a: 3 }]);
    assert.deepEqual(q.take().map((x) => x.a), [4, 5]);
});

function harness(responses) {
    const timers = [];
    const calls = [];
    const events = { state: [], correct: [], status: [] };
    const queue = createActionQueue(0);
    const sync = createSync({
        call: async (name, opts) => { calls.push({ name, body: opts.body }); const r = responses.shift(); return typeof r === 'function' ? r(opts) : r; },
        queue,
        getPos: () => ({ x: 1, z: 2, ry: 0 }),
        onState: (s) => events.state.push(s),
        onCorrect: (p) => events.correct.push(p),
        onStatus: (s) => events.status.push(s),
        setTimer: (fn, ms) => { timers.push({ fn, ms }); return timers.length; },
        clearTimer: () => {},
        now: () => 1000,
    });
    return { sync, queue, timers, calls, events };
}
const ok = (extra = {}) => ({ ok: true, data: { results: [], state: { energy: 90 }, next_sync_ms: 8000, server_ms: 1500, ...extra } });

test('sync: отправляет позицию, применяет состояние, планирует по next_sync_ms', async () => {
    const h = harness([ok({ next_sync_ms: 15000 })]);
    h.sync.start(3000);
    assert.equal(h.timers[0].ms, 3000);
    await h.sync.flush();
    assert.equal(h.calls[0].name, 'sync');
    assert.deepEqual(h.calls[0].body.pos, { x: 1, z: 2, ry: 0 });
    assert.equal(h.calls[0].body.seq, 1);
    assert.equal(h.events.state[0].energy, 90);
    assert.equal(h.timers.at(-1).ms, 15000);
    assert.equal(h.sync.status, 'online');
    assert.equal(h.sync.offsetMs, 500);
});

test('sync: correct_pos доходит до клиента, результаты снимают действия с очереди', async () => {
    const h = harness([ok({ correct_pos: { x: 5, z: 6 }, results: [{ a: 1, ok: true }] })]);
    h.queue.push('t');
    h.sync.start();
    await h.sync.flush();
    assert.deepEqual(h.events.correct[0], { x: 5, z: 6 });
    assert.equal(h.queue.size, 0);
});

test('sync: при сбое сети действия остаются, пауза растёт и ограничена', async () => {
    const fail = { ok: false, error: 'network' };
    const h = harness([fail, fail, fail, fail, fail, ok()]);
    h.queue.push('t');
    h.sync.start();
    const waits = [];
    for (let i = 0; i < 5; i += 1) { await h.sync.flush(); waits.push(h.timers.at(-1).ms); }
    assert.deepEqual(waits, [8000, 16000, 30000, 30000, 30000]);
    assert.equal(h.queue.size, 1);
    assert.equal(h.sync.status, 'offline');
    await h.sync.flush();
    assert.equal(h.sync.status, 'online');
    assert.equal(h.calls.at(-1).body.actions.length, 1); // то же действие отправлено повторно
});

test('sync: rate_limited ждёт retry_ms, unauthorized останавливает', async () => {
    const h = harness([{ ok: false, error: 'rate_limited', extra: { retry_ms: 4500 } }, { ok: false, error: 'unauthorized' }]);
    h.sync.start();
    await h.sync.flush();
    assert.equal(h.timers.at(-1).ms, 4500);
    assert.equal(h.sync.running, true);
    await h.sync.flush();
    assert.equal(h.sync.running, false);
    assert.equal(h.sync.status, 'unauthorized');
});

const full = { pos: { x: 10, z: -20, ry: 0 }, clearings: [{ x: 10, z: -20, r: 12 }], pond: { x: 30, z: -20, r: 5 }, state: { energy: 100 } };

test('сессия: онлайн, повторно входит через join, офлайн-причины', async () => {
    const seq = (map) => async (name) => map[name]();
    let r = await openSession(seq({ health: () => ({ ok: true, data: { schema_ok: true } }), state: () => ({ ok: true, data: full }) }));
    assert.equal(r.mode, 'online');

    r = await openSession(seq({
        health: () => ({ ok: true, data: { schema_ok: true } }),
        state: () => ({ ok: false, error: 'bad_state', extra: { need_join: true } }),
        join: () => ({ ok: true, data: { ...full, created: true } }),
    }));
    assert.equal(r.mode, 'online');
    assert.equal(r.full.created, true);

    r = await openSession(seq({ health: () => ({ ok: false, error: 'network' }) }));
    assert.deepEqual([r.mode, r.reason], ['offline', 'server']);
    r = await openSession(seq({ health: () => ({ ok: true, data: { schema_ok: false } }) }));
    assert.equal(r.reason, 'schema');
    r = await openSession(seq({ health: () => ({ ok: true, data: { schema_ok: true } }), state: () => ({ ok: false, error: 'unauthorized' }) }));
    assert.equal(r.reason, 'unauthorized');
    r = await openSession(seq({ health: () => ({ ok: true, data: { schema_ok: true } }), state: () => ({ ok: false, error: 'bad_state', extra: { need_join: true } }), join: () => ({ ok: false, error: 'server_error' }) }));
    assert.equal(r.reason, 'error');
});

test('состояние сервера переносится в HUD, рюкзак и сцену', () => {
    const hud = { got: null, set(p) { this.got = p; } };
    const inv = { cap: 0, items: null, setCapacity(c) { this.cap = c; }, setItems(i) { this.items = i; } };
    applyLightState({ energy: 71.2, energy_max: 110, coins: 340, weight: 18.5, capacity: 50, pack: { log: 4, fish_small: 2 } }, { hud, inventory: inv });
    assert.deepEqual(hud.got, { energy: 71.2, energyMax: 110, sleeping: false, coins: 340, packKg: 18.5, packCap: 50 });
    assert.equal(inv.cap, 50);
    assert.deepEqual(inv.items.map((i) => [i.key, i.qty, i.edible]), [['log', 4, false], ['fish_small', 2, true]]);
    assert.deepEqual(packToItems({}), []);
    const st = startFromState(full);
    assert.deepEqual([st.x, st.z, st.clearings.length, st.pond.r], [10, -20, 1, 5]);
    assert.equal(startFromState(null), null);
});
