// ДЛ-16: система построек. Запуск: node --test tests/build.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { buildBuildVectors, BUILD_VECTORS_PATH } from './build-lib.mjs';
import { BUILD_CATALOG, BUILD_RULES, buildCost, buildCoins, buildStage, refundK, refund, tapPlan, planTake, placeError, normRot, shortage, slopeDeg, samplePoints } from '../src/shared/build.js';
import { checkSpot } from '../src/world/build-check.js';
import { createBuildFlow, fixedCircles } from '../src/world/build-flow.js';
import { createBuildingView } from '../src/world/building-view.js';
import { applyLightState, startFromState } from '../src/net/apply-state.js';
import { S } from '../src/ui/strings.ru.js';

test('векторы построек: файл совпадает с текущей логикой JS', () => {
    const fresh = JSON.parse(JSON.stringify(buildBuildVectors()));
    const saved = JSON.parse(fs.readFileSync(BUILD_VECTORS_PATH, 'utf8'));
    assert.deepEqual(saved, fresh, 'перегенерируй: node tests/gen-build-vectors.mjs');
});

test('каталог: у каждой постройки есть строки интерфейса, цена и габарит', () => {
    assert.equal(BUILD_CATALOG.length, 9);
    for (const s of BUILD_CATALOG) {
        assert.ok(S.build.names[s.type], s.type);
        assert.ok(s.taps > 0 && s.r > 0 && s.h > 0 && s.unlock >= 1);
    }
    assert.deepEqual(buildCost(BUILD_CATALOG.find((s) => s.type === 'house')), { log: 40 });
    assert.equal(buildCoins(BUILD_CATALOG.find((s) => s.type === 'forge')), 3500);
});

test('стадии: 0–33% фундамент, 33–66% стены, 66–99% крыша, 100% готово', () => {
    assert.equal(buildStage(0, 100), 'foundation');
    assert.equal(buildStage(32, 100), 'foundation');
    assert.equal(buildStage(33, 100), 'walls');
    assert.equal(buildStage(66, 100), 'roof');
    assert.equal(buildStage(99, 100), 'roof');
    assert.equal(buildStage(100, 100), 'ready');
});

test('возврат: 100% до 20%, потом 70%, снос 50%, чужое состояние нельзя', () => {
    assert.equal(refundK('blueprint', 0, 100), 1);
    assert.equal(refundK('building', 20, 100), 1);
    assert.equal(refundK('building', 21, 100), 0.7);
    assert.equal(refundK('ready', 100, 100), 0.5);
    assert.equal(refundK('flooded', 0, 100), null);
    const house = BUILD_CATALOG.find((s) => s.type === 'house');
    assert.deepEqual(refund(house, 0.7), { items: { log: 28 }, coins: 0 });
    assert.deepEqual(refund(house, 0.5), { items: { log: 20 }, coins: 0 });
});

test('тапы: бюджет токенов и энергия ограничивают, не больше оставшегося', () => {
    assert.deepEqual(tapPlan(10, 100, 40, 100, 0.3), { n: 10, limit: 'none' });
    assert.deepEqual(tapPlan(10, 3, 40, 100, 0.3), { n: 3, limit: 'none' });
    assert.deepEqual(tapPlan(50, 100, 12.9, 100, 0.3), { n: 12, limit: 'tokens' });
    assert.deepEqual(tapPlan(50, 100, 40, 2, 0.3), { n: 6, limit: 'energy' });
    assert.deepEqual(tapPlan(5, 100, 40, 0, 0.3), { n: 0, limit: 'energy' });
});

test('списание: рюкзак, ящик, потом склады по возрастанию; не хватает — null', () => {
    const stock = { pack: { log: 10 }, crate: { log: 5 }, 'bld:12': { log: 100 }, 'bld:3': { log: 4 } };
    assert.deepEqual(planTake(stock, { log: 18 }), [['pack', 'log', 10], ['crate', 'log', 5], ['bld:3', 'log', 3]]);
    assert.equal(planTake({ pack: { log: 1 } }, { log: 2 }), null);
});

test('место: порядок причин и границы', () => {
    const ok = { level: 5, unlock: 1, total: 0, max_total: 8, same: 0, max_same: 1, inside_world: true, city: false, dist_village: 60, foreign_dist: 40, water: false, slope_deg: 12, trees: 0, overlap: false };
    assert.equal(placeError(ok), null);
    assert.equal(placeError({ ...ok, dist_village: 60.1 }), 'far_from_village');
    assert.equal(placeError({ ...ok, foreign_dist: 39.9 }), 'foreign');
    assert.equal(placeError({ ...ok, slope_deg: 12.1 }), 'slope');
    assert.equal(placeError({ ...ok, level: 0 }), 'locked');
    assert.equal(placeError({ ...ok, water: true, trees: 3, overlap: true }), 'water');
});

test('поворот кратен 15°, наклон по высотам', () => {
    assert.equal(normRot(352), 345);
    assert.equal(normRot(353), 0);
    assert.equal(normRot(-15), 345);
    assert.ok(Math.abs(slopeDeg([0, 0, 0, 0, 0], 2.8)) < 1e-9);
    assert.ok(slopeDeg([0, 1.2, 0, 0, 0], 2.8) > 11 && slopeDeg([0, 1.2, 0, 0, 0], 2.8) < 13);
    assert.equal(samplePoints(1, 2, 3).length, 5);
    assert.deepEqual(shortage(BUILD_CATALOG[1], { log: 10 }, 0), { log: 30 });
});

function deps(over = {}) {
    return {
        rules: BUILD_RULES, level: 5, village: { x: 0, z: 0 }, buildings: [], fixed: [[0, 8, 0.9]],
        heightAt: () => 0, isWater: () => false, nearObstacles: () => {}, ...over,
    };
}

test('призрак: деревья, вода, пересечения, дальность, пни не мешают', () => {
    const house = BUILD_CATALOG.find((s) => s.type === 'house');
    const none = { total: 0, same: 0 };
    assert.equal(checkSpot(house, 10, 10, none, deps()).ok, true);
    assert.equal(checkSpot(house, 10, 10, none, deps({ nearObstacles: (x, z, r, cb) => cb({ x: 11, z: 10, kind: 'tree' }) })).reason, 'trees');
    assert.equal(checkSpot(house, 10, 10, none, deps({ nearObstacles: (x, z, r, cb) => cb({ x: 10, z: 10, kind: 'stump' }) })).ok, true);
    assert.equal(checkSpot(house, 10, 10, none, deps({ isWater: () => true })).reason, 'water');
    assert.equal(checkSpot(house, 0, 8, none, deps()).reason, 'overlap');
    assert.equal(checkSpot(house, 10, 10, none, deps({ buildings: [{ x: 14, z: 10, r: 2.6 }] })).reason, 'overlap');
    assert.equal(checkSpot(house, 70, 0, none, deps()).reason, 'far_from_village');
    assert.equal(checkSpot(house, 10, 10, none, deps({ heightAt: (x) => x * 0.5 })).reason, 'slope');
    assert.equal(checkSpot(house, 10, 10, { total: 0, same: 1 }, deps()).reason, 'limit_type');
});

test('вид постройки: все типы строятся, стадии прячут части, призрак красится', () => {
    for (const s of BUILD_CATALOG) {
        const v = createBuildingView({ type: s.type, r: s.r, h: s.h });
        v.setStage('foundation', true);
        assert.equal(v.stage, 'foundation');
        v.setStage('ready', false);
        v.setGhost('bad');
        v.tap();
        v.update(0.7);
        v.dispose();
    }
});

function makeFlow({ energy = 100, sleeping = false, online = true, choice = 'yes', list = [] } = {}) {
    const pushed = [];
    const toasts = [];
    const store = new Map(list.map((b) => [b.id, { ...b }]));
    const calls = { ghost: [], taps: [], shown: [], panel: [], removed: [] };
    const buildings = {
        setCatalog() {}, set(l) { store.clear(); l.forEach((b) => store.set(b.id, { ...b })); },
        get: (id) => store.get(id) || null, list: () => Array.from(store.values()), circles: () => [],
        upsert: (b) => store.set(b.id, { ...(store.get(b.id) || {}), ...b }), remove: (id) => { store.delete(id); calls.removed.push(id); },
        showGhost: (...a) => calls.ghost.push(a), hideGhost: () => calls.ghost.push('hide'), tap: (id) => calls.taps.push(id), showProgress: (id, p) => calls.shown.push([id, p]),
    };
    const ui = { catalogOpen: false, showPlacing: (m) => { ui.placingModel = m; }, hidePlacing() { ui.placingModel = null; }, showPanel: (m) => calls.panel.push(m), hidePanel: () => calls.panel.push('hide'), openCatalog: () => { ui.opened = true; }, refreshCatalog() {} };
    const world = { heroPos: { x: 5, z: 5 }, cameraYaw: 0, ctl: { mode: 'work', cancelWork() { calls.cancel = (calls.cancel || 0) + 1; } }, groundY: () => 0, isWater: () => false, chunks: { forEachNear() {} }, buildings, groundFromScreen: (x, y) => ({ x: x / 10, z: y / 10 }) };
    const timers = [];
    const flushes = [];
    const queue = { push(type, data) { pushed.push({ type, ...data }); return pushed.length; } };
    const flow = createBuildFlow({
        world, buildUi: ui, dialog: { say: async () => ({ id: choice }) }, notify: (t) => toasts.push(t.text), text: { ...S.build, itemIcons: {} },
        names: { log: 'Бревно' }, village: { x: 0, z: 0 }, fixed: [],
        getQueue: () => (online ? queue : null), getSync: () => ({ running: true, flush: () => flushes.push(1) }),
        getHave: () => ({ log: 100, plank: 50, ingot: 20 }), getCoins: () => 5000, getEnergy: () => energy, isSleeping: () => sleeping,
        now: () => 1e9, setTimer: (fn) => { timers.push(fn); return timers.length; }, clearTimer: () => {},
    });
    flow.init({ catalog: BUILD_CATALOG, rules: BUILD_RULES, buildings: list, level: 5 });
    return { flow, pushed, toasts, calls, ui, store, timers, flushes };
}

test('поставить: призрак, отправка build_place, ответ сервера создаёт чертёж', () => {
    const t = makeFlow();
    t.flow.pick('house');
    assert.equal(t.flow.placing, true);
    assert.equal(t.ui.placingModel.valid, true);
    t.flow.rotate(1);
    t.flow.onScreenTap(200, 300); // → (20, 30)
    t.flow.confirm();
    assert.equal(t.pushed.length, 1);
    assert.deepEqual(t.pushed[0], { type: 'build_place', btype: 'house', x: 20, z: 30, rot: 15 });
    t.flow.onResults([{ type: 'build_place', ok: true, building: { id: 7, type: 'house', x: 20, z: 30, rot: 15, level: 1, state: 'blueprint', progress: 0, needed: 250, mine: true } }]);
    assert.equal(t.flow.placing, false);
    assert.equal(t.store.get(7).state, 'blueprint');
});

test('отказ сервера при постановке: призрак остаётся, причина в уведомлении', () => {
    const t = makeFlow();
    t.flow.pick('house');
    t.flow.confirm();
    t.flow.onResults([{ type: 'build_place', ok: false, error: 'bad_place', reason: 'trees' }]);
    assert.equal(t.flow.placing, true);
    assert.ok(t.toasts.includes(S.build.reasons.trees));
});

test('не ставит без связи и во сне', () => {
    const off = makeFlow({ online: false });
    off.flow.pick('house');
    off.flow.confirm();
    assert.equal(off.pushed.length, 0);
    assert.ok(off.toasts.includes(S.build.errors.offline));
    const sl = makeFlow({ sleeping: true });
    sl.flow.openCatalog();
    assert.ok(sl.toasts.includes(S.build.errors.sleeping));
});

const BP = { id: 3, type: 'house', x: 10, z: 10, rot: 0, level: 1, state: 'blueprint', progress: 0, needed: 250, mine: true };

test('стройка тапами: пачка уходит одним действием, ответ сервера обновляет прогресс', () => {
    const t = makeFlow({ list: [BP] });
    const obj = { kind: 'building', buildingId: 3 };
    t.flow.onWorldEvent({ type: 'ready', obj });
    for (let i = 0; i < 5; i += 1) t.flow.onWorldEvent({ type: 'workTap', obj });
    assert.equal(t.pushed.length, 0, 'пока копится');
    assert.equal(t.calls.taps.length, 5);
    t.timers.shift()(); // сработал таймер пачки
    assert.deepEqual(t.pushed[0], { type: 'build_tap', building: 3, taps: 5, px: 5, pz: 5 });
    t.flow.onResults([{ type: 'build_tap', ok: true, accepted: 5, limit: 'none', building_id: 3, building: { ...BP, state: 'building', progress: 5 } }]);
    assert.equal(t.store.get(3).progress, 5);
});

test('стройка: энергии нет — тап не принимается', () => {
    const t = makeFlow({ list: [BP], energy: 0.1 });
    const obj = { kind: 'building', buildingId: 3 };
    t.flow.onWorldEvent({ type: 'ready', obj });
    t.flow.onWorldEvent({ type: 'workTap', obj });
    assert.equal(t.calls.taps.length, 0);
    assert.ok(t.toasts.includes(S.build.errors.no_energy));
});

test('достроено: уведомление и опыт, панель закрывается', () => {
    const t = makeFlow({ list: [{ ...BP, progress: 249 }] });
    const obj = { kind: 'building', buildingId: 3 };
    t.flow.onWorldEvent({ type: 'ready', obj });
    t.flow.onWorldEvent({ type: 'workTap', obj });
    t.timers.shift()();
    t.flow.onResults([{ type: 'build_tap', ok: true, accepted: 1, limit: 'none', building_id: 3, xp: 400, building: { ...BP, state: 'ready', progress: 250 } }]);
    assert.ok(t.toasts.some((x) => x.includes('Достроено') && x.includes('+400')));
    assert.equal(t.store.get(3).state, 'ready');
});

test('чужая постройка не достраивается', () => {
    const t = makeFlow({ list: [{ ...BP, mine: false }] });
    t.flow.onWorldEvent({ type: 'ready', obj: { kind: 'building', buildingId: 3 } });
    assert.equal(t.calls.cancel, 1);
});

test('отмена и снос: подтверждение, действие, возврат в уведомлении', async () => {
    const t = makeFlow({ list: [{ ...BP, progress: 100 }] });
    await t.flow.askCancel(3);
    assert.deepEqual(t.pushed[0], { type: 'build_cancel', building: 3 });
    t.flow.onResults([{ type: 'build_cancel', ok: true, removed: 3, building_id: 3, refund: { items: { log: 28 }, coins: 0, k: 0.7, dropped: {} } }]);
    assert.equal(t.store.has(3), false);
    assert.ok(t.toasts.some((x) => x.includes('28')));
    const no = makeFlow({ list: [BP], choice: 'no' });
    await no.flow.askCancel(3);
    assert.equal(no.pushed.length, 0);
    const demo = makeFlow({ list: [{ ...BP, state: 'ready', progress: 250 }] });
    await demo.flow.askDemolish(3);
    assert.deepEqual(demo.pushed[0], { type: 'build_demolish', building: 3 });
});

test('состояние: каталог, правила, постройки и центр деревни доходят до клиента', () => {
    const full = { pos: { x: 1, z: 2, ry: 0 }, spawn: { x: 5, z: 6 }, catalog: [{ type: 'house' }], build_rules: { slope: 12 }, buildings: [{ id: 1 }], state: { level: 4 }, clearings: [] };
    const st = startFromState(full);
    assert.deepEqual(st.spawn, { x: 5, z: 6 });
    assert.equal(st.level, 4);
    assert.equal(st.buildings.length, 1);
    let got = null;
    applyLightState({ energy: 10 }, { hud: { set() {} }, inventory: null, build: { onState: (s) => { got = s; } } });
    assert.equal(got.energy, 10);
    assert.deepEqual(fixedCircles({ village: { crate: { x: 1, z: 1 }, buyer: { x: 2, z: 2 } }, pond: { x: 3, z: 3, r: 4, dam: { x: 9, z: 9 } } }), [[1, 1, 0.9], [2, 2, 1.6], [3, 3, 4.6], [9, 9, 2.4]]);
});
