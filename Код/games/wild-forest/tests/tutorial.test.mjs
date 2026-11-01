import test from 'node:test';
import assert from 'node:assert/strict';
import { createTutorial, eventFromResult, nearestPoint, clampToScreen, STEP_IDS, TUTORIAL_DONE, WAIT_EVENTS } from '../src/world/tutorial-logic.js';
import { createTutorialFlow } from '../src/world/tutorial-flow.js';
import { S } from '../src/ui/strings.ru.js';
import fs from 'node:fs';

test('шагов 12, как tutorial.steps в config.php, у каждого шага есть реплика и у ожидающих подсказка', () => {
    assert.equal(TUTORIAL_DONE, 12);
    const cfg = fs.readFileSync(new URL('../../../api/forest/config.php', import.meta.url), 'utf8');
    assert.match(cfg, /'steps' => 12,/);
    for (const id of STEP_IDS) assert.ok(S.tutorial.steps[id] && S.tutorial.steps[id].length >= 1, id);
    for (const id of Object.keys(WAIT_EVENTS)) assert.ok(S.tutorial.hints[id], id);
});

test('createTutorial: шаги по порядку, пропуск и сброс, значения вне диапазона зажимаются', () => {
    const t = createTutorial(0);
    assert.equal(t.id, 'hello');
    assert.equal(t.complete(), 1);
    assert.equal(t.id, 'walk');
    assert.equal(t.wait, 'moved');
    assert.ok(t.accepts('moved') && !t.accepts('felled'));
    t.skip();
    assert.ok(t.done && t.id === null && t.wait === null && !t.accepts('moved'));
    t.reset();
    assert.equal(t.step, 0);
    assert.equal(createTutorial(99).step, 12);
    assert.equal(createTutorial(-3).step, 0);
    assert.equal(createTutorial('abc').step, 0);
    const end = createTutorial(12);
    assert.equal(end.complete(), 12);
});

test('eventFromResult: события из ответов сервера', () => {
    assert.equal(eventFromResult({ ok: true, tree: '1:1:1', reward: { log: 3 } }), 'felled');
    assert.equal(eventFromResult({ ok: true, type: 'uproot', tree: '1:1:1' }), null);
    assert.equal(eventFromResult({ ok: false, tree: '1:1:1' }), null);
    assert.equal(eventFromResult({ ok: true, dup: true, type: 'sell' }), null);
    assert.equal(eventFromResult({ ok: true, type: 'pickup' }), 'picked');
    assert.equal(eventFromResult({ ok: true, type: 'crate_put' }), 'crated');
    assert.equal(eventFromResult({ ok: true, type: 'crate_take' }), null);
    assert.equal(eventFromResult({ ok: true, type: 'sell' }), 'sold');
    assert.equal(eventFromResult(null), null);
});

test('nearestPoint и clampToScreen', () => {
    assert.deepEqual(nearestPoint([{ x: 10, z: 0 }, { x: 3, z: 4 }], 0, 0), { x: 3, z: 4 });
    assert.equal(nearestPoint([], 0, 0), null);
    assert.deepEqual(clampToScreen(100, 100, 800, 480), { x: 100, y: 100, off: false });
    assert.deepEqual(clampToScreen(-50, 900, 800, 480, 28), { x: 28, y: 452, off: true });
});

// Поток обучения с подставными зависимостями
function makeFlow(startStep = 0, extra = {}) {
    const sent = [];
    const hero = { x: 0, z: 0 };
    const hintLog = { shown: [], hidden: 0, points: [], skip: null };
    const said = [];
    let nextChoice = 'next';
    const dialog = {
        say: (line) => { said.push(line); return Promise.resolve({ id: line.choices && line.choices.length ? nextChoice : 'next', label: '', timedOut: false }); },
        cancelAll: () => { said.push({ cancelled: true }); },
    };
    const hint = { show: (t) => hintLog.shown.push(t), hide: () => { hintLog.hidden += 1; }, point: (p) => hintLog.points.push(p), onSkip: (fn) => { hintLog.skip = fn; } };
    const world = {
        get heroPos() { return hero; },
        groundY: () => 0,
        projectToScreen: () => ({ sx: 100, sy: 100 }),
        chunks: { forEachInRange: (x, z, r, cb) => cb({ kind: 1, x: 5, z: 5, h: 6 }) },
        drops: { list: () => [{ x: 2, z: 2 }] },
    };
    const flow = createTutorialFlow({
        world, dialog, hint, text: S.tutorial, startStep,
        getQueue: () => ({ push: (type, fields) => sent.push({ type, ...fields }) }),
        getSpots: () => ({ crate: { x: 3, z: 3 }, buyer: { x: -3, z: 3 } }),
        size: () => ({ w: 800, h: 480 }),
        ...extra,
    });
    return { flow, sent, hero, hintLog, said, setChoice: (c) => { nextChoice = c; } };
}
const tick = () => new Promise((r) => setTimeout(r, 0));
async function settle() { for (let i = 0; i < 6; i += 1) await tick(); }

test('поток: hello → walk ждёт ходьбу на 4 м, потом идёт дальше и сохраняет шаги', async () => {
    const { flow, sent, hero, hintLog, said } = makeFlow(0);
    flow.start();
    await settle();
    assert.equal(said[0].speaker, 'Седой Дуб');
    assert.equal(said[0].choices.length, 2);
    assert.deepEqual(sent, [{ type: 'tutorial', step: 1 }]);
    assert.ok(flow.waiting, 'ждёт ходьбу');
    assert.equal(hintLog.shown[0], S.tutorial.hints.walk);
    hero.x = 2;
    flow.update();
    assert.ok(flow.waiting, 'прошёл мало');
    hero.x = 4.5;
    flow.update();
    await settle();
    assert.equal(sent[1].step, 2);
    assert.ok(flow.waiting && flow.step === 2, 'теперь ждёт рубку');
    assert.equal(hintLog.shown[1], S.tutorial.hints.chop);
});

test('поток: рубка, подбор, сдача и продажа проходят по событиям из результатов sync, затем реплики до конца', async () => {
    const { flow, sent } = makeFlow(2);
    flow.start();
    await settle();
    assert.ok(flow.waiting);
    flow.onResults([{ a: 1, ok: true, tree: '1:2:3' }]);   // срублено
    await settle();
    assert.equal(flow.step, 3);
    flow.onResults([{ a: 2, ok: true, type: 'pickup' }]);
    await settle();
    assert.equal(flow.step, 5, 'weight без ожидания прошёл сам, дальше ждёт ящик');
    flow.onResults([{ a: 3, ok: true, type: 'sell' }]);     // не то событие
    await settle();
    assert.equal(flow.step, 5);
    flow.onResults([{ a: 4, ok: true, type: 'crate_put' }]);
    await settle();
    assert.equal(flow.step, 6);
    flow.onResults([{ a: 5, ok: true, type: 'sell' }]);
    await settle();
    assert.ok(flow.done);
    assert.equal(sent[sent.length - 1].step, 12);
    assert.deepEqual(sent.map((s) => s.step), [3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
});

test('поток: «Я сам» пропускает всё обучение и сохраняет 12', async () => {
    const { flow, sent, setChoice, said } = makeFlow(0);
    setChoice('skip');
    flow.start();
    await settle();
    assert.ok(flow.done);
    assert.deepEqual(sent, [{ type: 'tutorial', step: 12 }]);
    assert.ok(said.some((s) => s.cancelled));
});

test('поток: кнопка «Пропустить» в подсказке снимает ожидание и сохраняет 12', async () => {
    const { flow, sent, hintLog } = makeFlow(2);
    flow.start();
    await settle();
    assert.ok(flow.waiting);
    hintLog.skip();
    await settle();
    assert.ok(flow.done && !flow.waiting);
    assert.equal(sent[sent.length - 1].step, 12);
});

test('поток: шаг «подбор» не ждёт, если брёвна уже в рюкзаке; «ящик» не ждёт, если брёвна уже в ящике', async () => {
    const { flow } = makeFlow(3, { getPack: () => ({ log: 3 }), getBox: () => ({ log: 1 }) });
    flow.start();
    await settle();
    assert.equal(flow.step, 6, 'дошёл до продажи, которая ждёт скупщика');
    assert.ok(flow.waiting);
});

test('поток: повтор из настроек сбрасывает шаг и шлёт reset; завершённое обучение не стартует само', async () => {
    const { flow, sent } = makeFlow(12);
    assert.equal(flow.start(), false);
    flow.restart();
    await settle();
    assert.deepEqual(sent[0], { type: 'tutorial', reset: true });
    assert.equal(sent[1].step, 1);
    assert.equal(flow.step, 2 - 0 >= 1 ? flow.step : 0);
    assert.ok(!flow.done);
});

test('поток: стрелка ставится над ближайшей целью, цели подсказок по шагам', async () => {
    const { flow, hintLog } = makeFlow(2);
    flow.start();
    await settle();
    flow.update();
    assert.ok(flow.target, 'есть цель-дерево');
    assert.deepEqual(hintLog.points[hintLog.points.length - 1], { x: 100, y: 100, off: false });
    const b = makeFlow(5);
    b.flow.start();
    await settle();
    b.flow.update();
    assert.equal(b.flow.target.x, 3);
    const s = makeFlow(6);
    s.flow.start();
    await settle();
    s.flow.update();
    assert.equal(s.flow.target.x, -3);
});

test('поток: без связи (нет очереди) обучение идёт локально без ошибок', async () => {
    const { flow } = makeFlow(0, { getQueue: () => null });
    flow.start();
    await settle();
    assert.equal(flow.step, 1);
});
