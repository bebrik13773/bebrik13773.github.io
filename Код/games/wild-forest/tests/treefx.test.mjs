// Дерево под топором, свежие пни и препятствия (#61): всё без браузера, на настоящем менеджере чанков.
import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from '../src/three.js';
import { createWorldGen, KIND } from '../src/shared/worldgen.js';
import { createModels } from '../src/world/models.js';
import { createChunkManager } from '../src/world/chunks.js';
import { createTreeFx } from '../src/world/tree-fx.js';
import { createChop } from '../src/world/chop.js';
import { QUALITY_PRESETS, WORLD, MOVE } from '../src/config.js';
import { loadShared } from './vectors-lib.mjs';

const { species, biomes } = loadShared();
const wg = createWorldGen({ seed: 1337, species, biomes });
const models = createModels();

function forest() {
    for (let cx = 30; cx < 80; cx += 1) {
        const n = wg.chunkCells(cx, 5).filter((c) => c.kind === KIND.TREE).length;
        if (n >= 15) return { cx, cz: 5 };
    }
    throw new Error('нет леса');
}

function setup() {
    const { cx, cz } = forest();
    const scene = new THREE.Scene();
    const mgr = createChunkManager({ scene, wg, models });
    mgr.applyPreset(QUALITY_PRESETS.medium);
    mgr.setCenter(cx * 32 + 16, cz * 32 + 16);
    for (let i = 0; i < 80 && mgr.pending > 0; i += 1) mgr.pump(1000);
    const chunk = mgr.chunk(cx, cz);
    const id = [...chunk.data.trees.keys()][0];
    return { scene, mgr, chunk, id };
}

test('срубленное дерево оставляет пень навсегда: он мешает ходить, цвет по породе', () => {
    const { mgr, chunk, id } = setup();
    const rec = chunk.data.trees.get(id);
    const x = rec.wx; const z = rec.wz;
    assert.equal(mgr.fell(id), true);
    assert.equal(mgr.stumpCount, 1);
    assert.equal(mgr.blockedAt(x, z, 0.1), true, 'пень это препятствие');
    const o = mgr.obstacleInCell(Math.floor(x / 4), Math.floor(z / 4));
    assert.equal(o.kind, 'stump', 'а не ствол: тапом по нему рубить нельзя');
    assert.equal(o.sp, rec.sp, 'порода пня совпадает с породой дерева');
    assert.equal(mgr.stumpInfo()[0].sp, rec.sp);
    mgr.pump(1);
    assert.equal(mgr.stumpCount, 1, 'сам по себе пень не исчезает');
    assert.equal(mgr.blockedAt(x, z, 0.1), true);
});

test('пни разных пород разного цвета', async () => {
    const { createStumpGeometry, STUMP_COLORS } = await import('../src/world/models.js');
    const a = createStumpGeometry(2).getAttribute('color').array;
    const b = createStumpGeometry(6).getAttribute('color').array;
    assert.notDeepEqual(Array.from(a.slice(0, 3)), Array.from(b.slice(0, 3)), 'кора берёзы и ольхи разная');
    assert.equal(STUMP_COLORS.length, 10, 'по цвету на каждую породу');
});

test('пень переживает перестройку чанка и появляется у срубленного при входе; выкорчеванный пропадает навсегда', () => {
    const { mgr, chunk, id } = setup();
    const ids = [...chunk.data.trees.keys()];
    mgr.fell(ids[1]);
    mgr.fell(id);
    assert.equal(mgr.stumpCount, 2);
    mgr.setChanges({ felled: [ids[1], id], clearings: [] }); // перестройка видимого
    for (let i = 0; i < 80 && mgr.pending > 0; i += 1) mgr.pump(1000);
    assert.equal(mgr.stumpCount, 2, 'пни на месте после перестройки');
    assert.equal(mgr.uproot(id), true);
    assert.equal(mgr.stumpCount, 1, 'выкорчеванный убран');
    assert.equal(mgr.uproot(id), false, 'повтор игнорируется');
    mgr.setChanges({ felled: [ids[1], id], clearings: [] });
    for (let i = 0; i < 80 && mgr.pending > 0; i += 1) mgr.pump(1000);
    assert.equal(mgr.stumpCount, 1, 'после перестройки выкорчеванный не вернулся');
    assert.equal(mgr.unuproot(id), true, 'откат отказа сервера');
    for (let i = 0; i < 80 && mgr.pending > 0; i += 1) mgr.pump(1000);
    assert.equal(mgr.stumpCount, 2, 'пень вернулся');
});

test('пруд и плотина непроходимы', () => {
    const { mgr } = setup();
    mgr.addBlocker(100, 100, 5);
    assert.equal(mgr.blockedAt(100, 103, 0.3), true);
    assert.equal(mgr.blockedAt(100, 106, 0.3), false);
    let seen = 0;
    mgr.forEachNear(100, 98, 2, () => { seen += 1; });
    assert.ok(seen >= 1, 'навигация видит пруд как препятствие');
    mgr.clearBlockers();
    assert.equal(mgr.blockedAt(100, 103, 0.3), false);
});

test('дерево под топором: снимается с чанка, дрожит, зарубка растёт, падает и уходит под землю', () => {
    const { scene, mgr, chunk, id } = setup();
    const rec = chunk.data.trees.get(id);
    const fx = createTreeFx({ scene, chunks: mgr, models, groundY: () => 0 });
    const before = chunk.data.treeCount;
    assert.equal(fx.begin(id, rec.wx - 2, rec.wz), true);
    assert.equal(chunk.data.pos[rec.v0 * 3 + 1] < -20, true, 'в буфере чанка дерева нет');
    assert.equal(mgr.obstacleInCell(Math.floor(rec.wx / 4), Math.floor(rec.wz / 4)).kind, KIND.TREE, 'но оно по-прежнему препятствие');
    fx.setProgress(id, 0.5);
    fx.impact(id);
    fx.update(0.016);
    assert.ok(fx.info(id).shake > 0.01);
    // ушёл: дерево возвращается на место
    assert.equal(fx.cancel(id), true);
    assert.equal(fx.active, 0);
    assert.ok(chunk.data.pos[rec.v0 * 3 + 1] > -20, 'дерево вернулось в буфер');
    // срубил: падает, пень сразу
    assert.equal(fx.fall(id, rec.wx - 2, rec.wz), true);
    mgr.fell(id);
    assert.equal(mgr.stumpCount, 1);
    for (let t = 0; t < 1.0; t += 0.02) fx.update(0.02);
    assert.equal(fx.info(id).mode, 'fall');
    for (let t = 0; t < 4; t += 0.02) fx.update(0.02);
    assert.equal(fx.active, 0, 'упало и ушло под землю');
    fx.dispose();
    assert.equal(before, chunk.data.treeCount + 1);
});

test('шкала рубки неровная, но доходит до единицы ровно на последнем ударе', () => {
    let t = 0;
    const cells = wg.chunkCells(forest().cx, 5).filter((c) => c.kind === KIND.TREE);
    const chop = createChop({ wg, getPower: () => 1, now: () => t, rand: (() => { let k = 7; return () => { k = (k * 16807) % 2147483647; return k / 2147483647; }; })() });
    const info = chop.start(cells[0].id);
    assert.ok(info.need > 3);
    let prev = 0; const steps = [];
    for (let i = 0; i < info.need; i += 1) {
        t += 400;
        const r = chop.hit();
        const shown = chop.shownFrac;
        assert.ok(shown >= prev - 1e-9 && shown <= 1 + 1e-9);
        steps.push(shown - prev);
        prev = shown;
        assert.equal(r.done, i === info.need - 1);
    }
    assert.equal(prev, 1);
    const spread = Math.max(...steps) - Math.min(...steps);
    assert.ok(spread > 0.002, `шаги шкалы разные: ${spread}`);
});

test('к дереву подходим вплотную: головка топора достаёт до коры', () => {
    assert.ok(MOVE.chopReach < 0.8 && MOVE.chopReach > 0.3);
});
