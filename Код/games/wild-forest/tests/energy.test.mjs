// ДЛ-12: энергия, сон и еда. Формулы, поток еды и сна на клиенте. Запуск: node --test tests/energy.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { ENERGY, energyMax, walkCost, energyRate, energyTick, eatGain } from '../src/shared/formulas.js';
import { ENERGY_VECTORS_PATH, buildEnergyVectors } from './energy-lib.mjs';
import { createEnergyFlow } from '../src/world/energy-flow.js';

test('максимум энергии: 100 + 10 за уровень выносливости до 6', () => {
    assert.equal(energyMax(1), 100);
    assert.equal(energyMax(3), 120);
    assert.equal(energyMax(6), 150);
    assert.equal(energyMax(99), 150);
});

test('расход на ходьбу: 0.015 за метр, погода умножает', () => {
    assert.ok(Math.abs(walkCost(100) - 1.5) < 1e-9);
    assert.ok(Math.abs(walkCost(100, 1.1) - 1.65) < 1e-9);
    assert.equal(walkCost(-5), 0);
});

test('скорость восстановления по состояниям', () => {
    assert.equal(energyRate({ still: true }), 0.1);
    assert.equal(energyRate({ still: false }), 0);
    assert.equal(energyRate({ still: true, home: true }), 1.0);
    assert.equal(energyRate({ sleeping: true }), 0.2);
    assert.equal(energyRate({ sleeping: true, home: true }), 1.0);
});

test('энергия кончилась на ходу: засыпает, дальше сон копит 0.2 в секунду', () => {
    const r = energyTick({ energy: 0.5, max: 100, dt: 10, dist: 100, still: false });
    assert.equal(r.sleeping, true);
    assert.ok(Math.abs(r.energy - 2) < 1e-9); // 0 после ходьбы + 10 с × 0.2
});

test('сон длится до 20: около 100 секунд, затем бобёр просыпается', () => {
    let st = { energy: 0, sleeping: true };
    let t = 0;
    while (st.sleeping && t < 1000) { st = energyTick({ energy: st.energy, max: 100, dt: 10, sleeping: true, still: true }); t += 10; }
    assert.equal(st.sleeping, false);
    assert.ok(t >= 100 && t <= 110, `проснулся через ${t} с`);
});

test('офлайн энергию не копит: за 900 секунд засчитывается только 120', () => {
    const r = energyTick({ energy: 10, max: 100, dt: 900, still: true });
    assert.ok(Math.abs(r.energy - 22) < 1e-9); // 10 + 120 × 0.1
});

test('дом восстанавливает 1.0 в секунду, не выше максимума', () => {
    const r = energyTick({ energy: 60, max: 100, dt: 100, still: true, home: true });
    assert.equal(r.energy, 100);
});

test('еда: рыба даёт 25 и 45, остальное не еда', () => {
    assert.equal(eatGain('fish'), 25);
    assert.equal(eatGain('fish_small'), 25);
    assert.equal(eatGain('fish_big'), 45);
    assert.equal(eatGain('log'), null);
});

test('векторы energy.json совпадают с текущими формулами', () => {
    const file = JSON.parse(fs.readFileSync(ENERGY_VECTORS_PATH, 'utf8'));
    const fresh = buildEnergyVectors();
    assert.deepEqual(file.ticks, fresh.ticks);
    assert.ok(file.ticks.length >= 700);
});

function makeFlow(over = {}) {
    const log = { notes: [], pushed: [], flushes: 0, sleepSet: [], states: [], hud: [], removed: [] };
    let t = 100000;
    const flow = createEnergyFlow({
        getQueue: () => ({ push: (type, p) => { log.pushed.push({ type, ...p }); return log.pushed.length; } }),
        getSync: () => ({ running: true, flush: () => { log.flushes += 1; } }),
        world: { setSleeping: (v) => log.sleepSet.push(v), playBeaver: (n) => log.states.push(n), releaseBeaver: () => log.states.push('release'), ctl: { cancelWork() {} } },
        notify: (n) => log.notes.push(n.text),
        text: { asleep: 'уснул', wake: 'проснулся', ate: (n) => `+${n}`, eat_wait: 'жуём', not_hungry: 'сыт', no_items: 'нет рыбы', failed: 'не вышло', sleeping: 'спит' },
        onEatLocal: (k) => log.removed.push(k),
        now: () => t,
        setTimer: (fn) => { log.timer = fn; return 1; },
        clearTimer: () => {},
        ...over,
    });
    return { flow, log, tick: (ms) => { t += ms; } };
}

test('еда: действие eat уходит в очередь, вторая попытка до паузы отклоняется на клиенте', () => {
    const { flow, log, tick } = makeFlow();
    assert.equal(flow.eat('fish_small'), true);
    assert.deepEqual(log.pushed[0], { type: 'eat', item: 'fish_small' });
    assert.equal(flow.eat('fish_small'), false);
    assert.ok(log.notes.includes('жуём'));
    tick(5100);
    assert.equal(flow.eat('fish_small'), true);
});

test('сон: состояние сервера sleeping включает сон один раз, пробуждение выключает', () => {
    const { flow, log } = makeFlow();
    flow.onState({ sleeping: true });
    flow.onState({ sleeping: true });
    assert.deepEqual(log.sleepSet, [true]);
    assert.deepEqual(log.notes, ['уснул']);
    flow.onState({ sleeping: false });
    assert.deepEqual(log.sleepSet, [true, false]);
    assert.ok(log.notes.includes('проснулся'));
});

test('еда во сне разрешена, ответ сервера с ok показывает прибавку', () => {
    const { flow, log } = makeFlow();
    flow.onState({ sleeping: true });
    assert.equal(flow.eat('fish_big'), true);
    flow.onResults([{ a: 1, ok: true, type: 'eat', gain: 45, energy: 50 }]);
    assert.ok(log.notes.includes('+45'));
});

test('отказ сервера на еду: объясняется текстом', () => {
    const { flow, log } = makeFlow();
    flow.onResults([{ a: 1, ok: false, type: 'eat', error: 'no_items' }]);
    flow.onResults([{ a: 2, ok: false, type: 'eat', error: 'bad_state', full: true }]);
    assert.deepEqual(log.notes, ['нет рыбы', 'сыт']);
});

test('ENERGY совпадает с config.php', () => {
    const cfg = fs.readFileSync(new URL('../../../api/forest/config.php', import.meta.url), 'utf8');
    assert.match(cfg, new RegExp(`'regen_sleep_ps'\\s*=>\\s*${ENERGY.regenSleep}`));
    assert.match(cfg, new RegExp(`'regen_rest_ps'\\s*=>\\s*${ENERGY.regenRest}`));
    assert.match(cfg, new RegExp(`'wake_energy'\\s*=>\\s*${ENERGY.wake}`));
    assert.match(cfg, new RegExp(`'rest_credit_cap_s'\\s*=>\\s*${ENERGY.creditCapS}`));
    assert.match(cfg, new RegExp(`'eat_pause_s'\\s*=>\\s*${ENERGY.eatPauseS}`));
});
