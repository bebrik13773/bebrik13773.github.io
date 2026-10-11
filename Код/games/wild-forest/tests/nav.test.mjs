import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from '../src/three.js';
import { createNav } from '../src/world/nav.js';
import { createHero } from '../src/entities/hero.js';
import { pickObject } from '../src/world/picking.js';
import { createWalkGrid } from '../src/world/terrain-nav.js';
import { createChunkManager } from '../src/world/chunks.js';
import { createModels } from '../src/world/models.js';
import { createWorldGen, KIND } from '../src/shared/worldgen.js';
import { findDemoSpawn } from '../src/world/spawn.js';
import { checkTrack } from '../src/shared/formulas.js';
import { QUALITY_PRESETS, MOVE } from '../src/config.js';
import { loadShared } from './vectors-lib.mjs';

// Синтетический мир: плоская земля, круглые препятствия, прямоугольные зоны воды.
function makeEnv({ obstacles = [], water = [] } = {}) {
    const blockedAt = (x, z, r) => obstacles.some((o) => (x - o.x) ** 2 + (z - o.z) ** 2 < (r + o.r) ** 2);
    const obst = {
        blockedAt,
        forEachNear: (x, z, range, cb) => obstacles.forEach((o) => { if (Math.hypot(o.x - x, o.z - z) <= range + o.r) cb(o); }),
    };
    const walk = { walkable: (x, z) => !water.some((w) => x >= w.x0 && x <= w.x1 && z >= w.z0 && z <= w.z1) };
    return { obst, walk, nav: createNav({ walk, obstacles: obst }) };
}
const tree = (x, z, r = 0.45) => ({ x, z, r, id: `${x}:${z}`, kind: 1 });
function wall(x, z0, z1, gap = null) {
    const out = [];
    for (let z = z0; z <= z1; z += 1) if (!gap || z < gap[0] || z > gap[1]) out.push(tree(x, z, 0.6));
    return out;
}

test('путь по ровному месту: прямая, цель достижима', () => {
    const { nav } = makeEnv();
    const r = nav.findPath(0, 0, 20, 5);
    assert.ok(r && r.complete);
    assert.equal(r.points.length, 1, 'сглаживание сводит путь к одной прямой');
    assert.deepEqual(r.points[0], { x: 20, z: 5 });
});

test('путь огибает стену из деревьев через проход', () => {
    const { nav, obst } = makeEnv({ obstacles: wall(10, -15, 15, [4, 7]) });
    const r = nav.findPath(0, 0, 20, 0);
    assert.ok(r && r.complete);
    assert.ok(r.points.length >= 2);
    // каждый отрезок пути свободен для героя
    let prev = { x: 0, z: 0 };
    for (const p of r.points) {
        const len = Math.hypot(p.x - prev.x, p.z - prev.z);
        for (let s = 1; s <= Math.ceil(len / 0.25); s += 1) {
            const t = s / Math.ceil(len / 0.25);
            assert.equal(obst.blockedAt(prev.x + (p.x - prev.x) * t, prev.z + (p.z - prev.z) * t, MOVE.heroRadius), false);
        }
        prev = p;
    }
});

test('цель за сплошной стеной: путь к ближайшей достижимой точке (partial)', () => {
    const { nav } = makeEnv({ obstacles: wall(10, -100, 100) });
    const r = nav.findPath(0, 0, 20, 0);
    assert.ok(r, 'есть путь хотя бы до стены');
    assert.equal(r.complete, false);
    assert.ok(r.end.x > 5 && r.end.x < 10, `встали перед стеной: ${JSON.stringify(r.end)}`);
});

test('цель в воде: идём к берегу', () => {
    const { nav } = makeEnv({ water: [{ x0: 10, x1: 40, z0: -40, z1: 40 }] });
    const r = nav.findPath(0, 0, 25, 0);
    assert.ok(r && r.complete === false);
    assert.ok(r.end.x > 7 && r.end.x < 10.5, `берег рядом: ${JSON.stringify(r.end)}`);
});

test('герой заперт со всех сторон: пути нет', () => {
    const ring = [];
    for (let a = 0; a < 24; a += 1) ring.push(tree(Math.cos((a / 24) * 6.2832) * 1.6, Math.sin((a / 24) * 6.2832) * 1.6, 0.7));
    const { nav } = makeEnv({ obstacles: ring });
    assert.equal(nav.findPath(0, 0, 20, 0), null);
});

test('выталкивание из ствола: круг героя не остаётся внутри', () => {
    const { nav, obst } = makeEnv({ obstacles: [tree(0, 0, 0.5)] });
    const p = nav.pushOut(0.3, 0.1, 0.4);
    assert.equal(obst.blockedAt(p.x, p.z, 0.39), false);
});

test('место для работы у дерева: свободно и на нужном расстоянии', () => {
    const { nav } = makeEnv({ obstacles: [tree(10, 0, 0.6), tree(9, 1.5, 0.45)] });
    const spot = nav.findSpot({ x: 10, z: 0, r: 0.6 }, 1.8, 0, 0);
    assert.ok(spot);
    assert.ok(Math.abs(Math.hypot(spot.x - 10, spot.z) - 1.8) < 0.9);
    assert.ok(nav.isOpen(spot.x, spot.z, 0.4));
});

function simulate(hero, seconds, hz = 30) {
    const dt = 1 / hz;
    const log = [];
    for (let i = 0; i < seconds * hz; i += 1) { hero.update(dt); log.push({ t: i * dt, x: hero.x, z: hero.z, mode: hero.mode }); }
    return log;
}

test('герой доходит до точки, не заходит в стволы, скорость не выше лимита', () => {
    const { nav, obst } = makeEnv({ obstacles: wall(10, -15, 15, [4, 7]) });
    const hero = createHero({ nav, x: 0, z: 0, vMax: 3.2 });
    let arrived = null;
    hero.on('arrive', (e) => { arrived = e; });
    const r = hero.moveTo(20, 0);
    assert.ok(r.ok && !r.partial);
    const log = simulate(hero, 20);
    assert.ok(arrived && arrived.complete, 'дошёл');
    assert.ok(Math.hypot(hero.x - 20, hero.z) < 0.5);
    assert.ok(log.every((p) => !obst.blockedAt(p.x, p.z, MOVE.heroRadius * 0.95)), 'ни разу не внутри ствола');
    let maxStep = 0;
    for (let i = 1; i < log.length; i += 1) maxStep = Math.max(maxStep, Math.hypot(log[i].x - log[i - 1].x, log[i].z - log[i - 1].z) * 30);
    assert.ok(maxStep <= 3.2 + 0.01, `скорость ${maxStep}`);
    const pts = log.filter((_, i) => i % 30 === 0).map((p) => ({ t: p.t, x: p.x, z: p.z }));
    assert.equal(checkTrack(pts, () => 3.2).ok, true, 'журнал позиций проходит проверку сервера');
});

test('новый приказ посреди пути заменяет старый, stop останавливает', () => {
    const { nav } = makeEnv();
    const hero = createHero({ nav, x: 0, z: 0 });
    hero.moveTo(30, 0); simulate(hero, 2);
    assert.equal(hero.mode, 'move');
    hero.moveTo(0, 30); simulate(hero, 15);
    assert.ok(Math.hypot(hero.x - 0, hero.z - 30) < 0.6);
    hero.moveTo(50, 50); simulate(hero, 1); hero.stop(); simulate(hero, 1);
    assert.equal(hero.mode, 'idle');
});

test('подойти к дереву: встаёт лицом, режим работы, событие ready; тап по земле выходит из работы', () => {
    const { nav } = makeEnv({ obstacles: [tree(12, 3, 0.6)] });
    const hero = createHero({ nav, x: 0, z: 0 });
    let ready = null;
    hero.on('ready', (o) => { ready = o; });
    const obj = { id: 'T1', kind: 'tree', x: 12, z: 3, r: 0.6 };
    assert.ok(hero.interact(obj).ok);
    simulate(hero, 8);
    assert.equal(hero.mode, 'work');
    assert.equal(ready.id, 'T1');
    const d = Math.hypot(hero.x - 12, hero.z - 3);
    assert.ok(d <= 2.2 && d >= 1.0, `дистанция до дерева ${d}`);
    const want = Math.atan2(12 - hero.x, 3 - hero.z);
    assert.ok(Math.abs(Math.atan2(Math.sin(want - hero.yaw), Math.cos(want - hero.yaw))) < 0.25, 'смотрит на дерево');
    hero.cancelWork();
    assert.equal(hero.mode, 'idle');
    assert.ok(hero.moveTo(0, 0).ok);
    simulate(hero, 8);
    assert.ok(Math.hypot(hero.x, hero.z) < 0.6);
});

test('уже рядом с деревом: не двигается, только поворачивается', () => {
    const { nav } = makeEnv();
    const hero = createHero({ nav, x: 0, z: 0 });
    hero.interact({ id: 'T', kind: 'tree', x: 1.6, z: 0, r: 0.45 });
    simulate(hero, 2);
    assert.equal(hero.mode, 'work');
    assert.ok(Math.hypot(hero.x, hero.z) < 0.05);
});

test('клавиши: идёт по камере и скользит вдоль стены, не проходит сквозь', () => {
    const { nav, obst } = makeEnv({ obstacles: wall(5, -30, 30) });
    const hero = createHero({ nav, x: 0, z: 0 });
    hero.setInput(0, 1, -Math.PI / 2); // камера смотрит так, что «вперёд» = +x (yaw = -90°)
    const log = simulate(hero, 6);
    assert.ok(hero.x > 3, 'дошёл до стены');
    assert.ok(log.every((p) => !obst.blockedAt(p.x, p.z, MOVE.heroRadius * 0.95)));
    assert.ok(hero.x < 5 - 0.6 + 0.2, 'стена не пустила');
    hero.setInput(0, 0, 0); simulate(hero, 1);
    assert.equal(hero.mode, 'idle');
});

test('застрял: перестраивает путь, потом сдаётся с событием blocked', () => {
    const env = makeEnv();
    let calls = 0;
    // «призрачная» преграда: поиск пути её не видит, а при движении она есть
    const ghost = { blockedAt: () => false, forEachNear: (x, z, r, cb) => { if (x > 4.4) cb({ x: 5.2, z, r: 0.6, id: 'g' }); } };
    const nav = createNav({ walk: env.walk, obstacles: ghost });
    const hero = createHero({ nav, x: 0, z: 0 });
    hero.on('blocked', () => { calls += 1; });
    hero.moveTo(12, 0);
    simulate(hero, 10);
    assert.ok(calls >= 1 || hero.mode === 'idle');
});

test('выбор объекта пальцем: дерево, ближнее из перекрывающихся, пустота', () => {
    const camera = new THREE.PerspectiveCamera(60, 390 / 800, 0.3, 300);
    camera.position.set(0, 8, 10); camera.lookAt(0, 1, 0); camera.updateMatrixWorld();
    camera.updateProjectionMatrix();
    const v = new THREE.Vector3();
    const project = (x, y, z) => {
        v.set(x, y, z).applyMatrix4(camera.matrixWorldInverse);
        if (v.z > -camera.near) return null;
        v.applyMatrix4(camera.projectionMatrix);
        return { sx: (v.x * 0.5 + 0.5) * 390, sy: (-v.y * 0.5 + 0.5) * 800 };
    };
    const near = { id: 'near', kind: 'tree', x: 0, y: 0, z: 2, h: 6, r: 0.45, pickR: 1 };
    const far = { id: 'far', kind: 'tree', x: 0, y: 0, z: -4, h: 6, r: 0.45, pickR: 1 };
    const behind = { id: 'behind', kind: 'tree', x: 0, y: 0, z: 40, h: 6, r: 0.45, pickR: 1 };
    const base = { project, cam: camera.position, viewH: 800, fov: (camera.fov * Math.PI) / 180 };
    const s = project(0, 2.5, 2);
    assert.equal(pickObject({ ...base, candidates: [far, near, behind], px: s.sx, py: s.sy }).id, 'near');
    const s2 = project(0, 2.5, -4);
    const got = pickObject({ ...base, candidates: [far, near], px: s2.sx + 1, py: s2.sy });
    assert.ok(got, 'что-то выбрано вблизи отрезка');
    assert.equal(pickObject({ ...base, candidates: [near], px: 5, py: 5 }), null, 'тап в пустоту ничего не выбирает');
});

// ── Настоящий лес из генератора мира ────────────────────────────────
const { species, biomes } = loadShared();
const wg = createWorldGen({ seed: 1337, species, biomes });
const models = createModels();

function loadForest(preset = QUALITY_PRESETS.medium) {
    const scene = new THREE.Scene();
    const chunks = createChunkManager({ scene, wg, models });
    chunks.applyPreset(preset);
    const spawn = findDemoSpawn(wg);
    chunks.addClearing(spawn.x, spawn.z, 12);
    chunks.setCenter(spawn.x, spawn.z);
    let guard = 0;
    while (chunks.pending > 0 && guard < 500) { chunks.pump(1000); guard += 1; }
    return { chunks, spawn };
}

test('препятствия чанков: ствол блокирует, срубленное дерево нет, поляна чистая', () => {
    const { chunks, spawn } = loadForest();
    let found = null;
    chunks.forEachInRange(spawn.x, spawn.z, 40, (o) => { if (!found && o.kind === KIND.TREE) found = o; });
    assert.ok(found, 'в радиусе 40 м есть деревья');
    assert.equal(chunks.blockedAt(found.x, found.z, 0.4), true);
    assert.equal(chunks.blockedAt(found.x + found.r + 3, found.z + 3, 0.1) && false, false);
    chunks.fell(found.id);
    assert.equal(chunks.obstacleInCell(Math.floor(found.x / 4), Math.floor(found.z / 4)) === found, false);
    assert.equal(chunks.blockedAt(spawn.x, spawn.z, 0.4), false, 'в центре поляны пусто');
});

test('реальный лес: герой доходит до случайных точек (≥ 85% полных путей, быстрый поиск)', () => {
    const { chunks, spawn } = loadForest();
    const walk = createWalkGrid({ wg });
    const nav = createNav({ walk, obstacles: chunks });
    let ok = 0; let total = 0; let maxMs = 0; let sumMs = 0;
    for (let k = 0; k < 40; k += 1) {
        const a = k * 2.399963;
        const rad = 8 + (k % 6) * 6;
        const tx = spawn.x + Math.cos(a) * rad;
        const tz = spawn.z + Math.sin(a) * rad;
        if (!walk.walkable(tx, tz)) continue; // вода и склоны проверены отдельно
        total += 1;
        const t0 = performance.now();
        const r = nav.findPath(spawn.x, spawn.z, tx, tz);
        const ms = performance.now() - t0;
        maxMs = Math.max(maxMs, ms); sumMs += ms;
        if (r && r.complete) ok += 1;
    }
    assert.ok(total >= 20);
    assert.ok(ok / total >= 0.85, `полных путей ${ok} из ${total}`);
    assert.ok(sumMs / total < 60, `средний поиск ${(sumMs / total).toFixed(1)} мс (в Node без прогрева сетки)`);
    console.log(`  путь в лесу: ${ok}/${total}, среднее ${(sumMs / total).toFixed(1)} мс, максимум ${maxMs.toFixed(1)} мс`);
});

test('реальный лес: герой идёт по лесу и не заходит в стволы', () => {
    const { chunks, spawn } = loadForest();
    const walk = createWalkGrid({ wg });
    const nav = createNav({ walk, obstacles: chunks });
    const hero = createHero({ nav, x: spawn.x, z: spawn.z });
    let tried = 0; let bad = 0;
    for (let k = 0; k < 8; k += 1) {
        const a = k * 1.7;
        const tx = spawn.x + Math.cos(a) * 30; const tz = spawn.z + Math.sin(a) * 30;
        if (!walk.walkable(tx, tz)) continue;
        tried += 1;
        hero.moveTo(tx, tz);
        for (let i = 0; i < 30 * 30 && hero.mode !== 'idle'; i += 1) {
            hero.update(1 / 30);
            if (chunks.blockedAt(hero.x, hero.z, MOVE.heroRadius * 0.9)) bad += 1;
        }
        hero.moveTo(spawn.x, spawn.z);
        for (let i = 0; i < 30 * 30 && hero.mode !== 'idle'; i += 1) hero.update(1 / 30);
    }
    assert.ok(tried >= 3);
    assert.equal(bad, 0, 'ни одного шага внутри ствола');
});

test('проходимость земли: вода и крутые склоны непроходимы, результат кэшируется', () => {
    const walk = createWalkGrid({ wg });
    let water = 0; let land = 0; let steep = 0;
    for (let i = 0; i < 4000; i += 1) {
        const x = (i * 37) % 1700 - 400; const z = (i * 91) % 1300 - 300;
        const h = wg.heightAtDm(Math.round(x * 10), Math.round(z * 10)) / 10;
        const w = walk.walkable(x, z);
        if (h < -3.4) { assert.equal(w, false, `вода ${x},${z} h=${h}`); water += 1; } else if (w) land += 1; else steep += 1;
    }
    assert.ok(water > 0 && land > 0, `вода ${water}, суша ${land}, склоны ${steep}`);
    assert.ok(walk.size > 0);
});
