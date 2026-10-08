import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from '../src/three.js';
import { createWorldGen, KIND, treeId } from '../src/shared/worldgen.js';
import { buildChunkData, collapseTree, CHUNK_DM } from '../src/world/chunk-build.js';
import { createModels } from '../src/world/models.js';
import { createChunkManager } from '../src/world/chunks.js';
import { findDemoSpawn } from '../src/world/spawn.js';
import { QUALITY_PRESETS } from '../src/config.js';
import { loadShared } from './vectors-lib.mjs';

const { species, biomes } = loadShared();
const wg = createWorldGen({ seed: 1337, species, biomes });
const models = createModels();
const medium = QUALITY_PRESETS.medium;
const empty = { felled: new Set(), clearings: [] };

// Лесной чанк для проверок: первый с хотя бы 15 деревьями вдали от города.
function findForestChunk() {
    for (let cx = 30; cx < 80; cx += 1) {
        const n = wg.chunkCells(cx, 5).filter((c) => c.kind === KIND.TREE).length;
        if (n >= 15) return { cx, cz: 5 };
    }
    throw new Error('нет леса');
}
const { cx: FX, cz: FZ } = findForestChunk();

test('шаблоны моделей: у каждой породы два уровня, все числа конечны, подробная модель не проще простой', () => {
    assert.equal(models.trees.length, species.species.length);
    for (const [i, [lo, hi]] of models.trees.entries()) {
        for (const m of [lo, hi]) {
            assert.ok(m.verts > 0 && m.verts % 3 === 0, `порода ${i}`);
            assert.ok(m.pos.every(Number.isFinite) && m.nrm.every(Number.isFinite) && m.col.every(Number.isFinite));
        }
        assert.ok(hi.verts >= lo.verts, `подробная не проще простой (порода ${i})`);
        assert.ok(hi.verts / 3 <= 260, `бюджет треугольников дерева ${i}: ${hi.verts / 3}`);
    }
});

test('чанк собирается детерминированно, индексы в пределах, координаты конечны', () => {
    const a = buildChunkData({ wg, models, cx: FX, cz: FZ, preset: medium, lod: 1, ...empty });
    const b = buildChunkData({ wg, models, cx: FX, cz: FZ, preset: medium, lod: 1, ...empty });
    assert.deepEqual(a.pos, b.pos);
    assert.deepEqual(a.col, b.col);
    assert.ok(a.treeCount >= 10);
    assert.equal(a.treeCount, a.trees.size);
    let maxIdx = 0;
    for (const v of a.index) if (v > maxIdx) maxIdx = v;
    assert.ok(maxIdx < a.vertexCount);
    assert.ok(a.pos.every(Number.isFinite) && a.nrm.every(Number.isFinite) && a.col.every(Number.isFinite));
    assert.equal(a.index.length % 3, 0);
});

test('швы между чанками без щелей: высоты и нормали на общей границе совпадают на всех качествах', () => {
    for (const q of ['low', 'medium', 'high']) {
        const preset = QUALITY_PRESETS[q];
        const seg = preset.terrainSeg;
        const vs = seg + 1;
        const west = buildChunkData({ wg, models, cx: FX, cz: FZ, preset, lod: 0, ...empty });
        const east = buildChunkData({ wg, models, cx: FX + 1, cz: FZ, preset, lod: 0, ...empty });
        const south = buildChunkData({ wg, models, cx: FX, cz: FZ + 1, preset, lod: 0, ...empty });
        for (let j = 0; j < vs; j += 1) {
            const a = (j * vs + seg) * 3; // восточный край западного чанка
            const b = (j * vs + 0) * 3;   // западный край восточного
            assert.equal(west.pos[a + 1], east.pos[b + 1], `${q}: высота шва x, j=${j}`);
            assert.equal(west.nrm[a], east.nrm[b], `${q}: нормаль шва x, j=${j}`);
            assert.equal(west.nrm[a + 2], east.nrm[b + 2]);
            assert.equal(west.col[a], east.col[b], `${q}: цвет шва x, j=${j}`);
        }
        for (let i = 0; i < vs; i += 1) {
            const a = (seg * vs + i) * 3;
            const b = i * 3;
            assert.equal(west.pos[a + 1], south.pos[b + 1], `${q}: высота шва z, i=${i}`);
            assert.equal(west.nrm[a + 1], south.nrm[b + 1]);
        }
    }
});

test('высота вершины рельефа равна высоте генератора в той же точке', () => {
    const preset = QUALITY_PRESETS.high;
    const d = buildChunkData({ wg, models, cx: FX, cz: FZ, preset, lod: 0, ...empty });
    const vs = preset.terrainSeg + 1;
    const step = CHUNK_DM / preset.terrainSeg;
    for (const [i, j] of [[0, 0], [5, 7], [31, 12], [32, 32]]) {
        const X = FX * CHUNK_DM + i * step;
        const Z = FZ * CHUNK_DM + j * step;
        assert.equal(d.pos[(j * vs + i) * 3 + 1], Math.fround(wg.heightAtDm(X, Z) / 10)); // буфер float32
    }
});

test('срубленное дерево исчезает из чанка и получает пень; расчищенный круг убирает деревья без пней', () => {
    const base = buildChunkData({ wg, models, cx: FX, cz: FZ, preset: medium, lod: 1, ...empty });
    const id = [...base.trees.keys()][0];
    const felled = buildChunkData({ wg, models, cx: FX, cz: FZ, preset: medium, lod: 1, felled: new Set([id]), clearings: [] });
    assert.equal(felled.treeCount, base.treeCount - 1);
    assert.ok(!felled.trees.has(id));
    assert.deepEqual(felled.stumps.map((s) => s.id), [id]);
    assert.ok(felled.vertexCount < base.vertexCount);
    const rec = base.trees.get(id);
    const cleared = buildChunkData({ wg, models, cx: FX, cz: FZ, preset: medium, lod: 1, felled: new Set(), clearings: [{ x: rec.wx, z: rec.wz, r: 3 }] });
    assert.ok(!cleared.trees.has(id));
    assert.equal(cleared.stumps.length, 0);
    assert.ok(cleared.treeCount < base.treeCount);
});

test('collapseTree стягивает вершины дерева в одну точку (вырожденные треугольники)', () => {
    const d = buildChunkData({ wg, models, cx: FX, cz: FZ, preset: medium, lod: 1, ...empty });
    const [id, rec] = [...d.trees.entries()][0];
    collapseTree(d.pos, rec);
    for (let k = 0; k < rec.vN; k += 1) {
        const o = (rec.v0 + k) * 3;
        assert.equal(d.pos[o], Math.fround(rec.lx));
        assert.equal(d.pos[o + 2], Math.fround(rec.lz));
        assert.equal(d.pos[o + 1], Math.fround(rec.y - 50));
    }
    assert.ok(id.includes(':'));
});

test('плотность леса: на низком качестве деревьев меньше, чем на высоком, и выбор стабилен', () => {
    const hi = buildChunkData({ wg, models, cx: FX, cz: FZ, preset: { ...QUALITY_PRESETS.high, terrainSeg: 8 }, lod: 0, ...empty });
    const lo1 = buildChunkData({ wg, models, cx: FX, cz: FZ, preset: QUALITY_PRESETS.low, lod: 0, ...empty });
    const lo2 = buildChunkData({ wg, models, cx: FX, cz: FZ, preset: QUALITY_PRESETS.low, lod: 0, ...empty });
    assert.ok(lo1.treeCount < hi.treeCount);
    assert.deepEqual([...lo1.trees.keys()], [...lo2.trees.keys()]);
    // оставшиеся на низком — подмножество высокого
    for (const id of lo1.trees.keys()) assert.ok(hi.trees.has(id));
});

test('треугольники чанка в бюджете: средний чанк подробный и простой', () => {
    const detailed = buildChunkData({ wg, models, cx: FX, cz: FZ, preset: medium, lod: 1, ...empty });
    const simple = buildChunkData({ wg, models, cx: FX, cz: FZ, preset: medium, lod: 0, ...empty });
    assert.ok(simple.index.length / 3 < detailed.index.length / 3);
    assert.ok(detailed.index.length / 3 < 14000, `подробный чанк ${detailed.index.length / 3} треугольников`);
    assert.ok(simple.index.length / 3 < 7000, `простой чанк ${simple.index.length / 3} треугольников`);
});

test('время построения чанка в Node (ПК-ориентир, не абсолют)', () => {
    const t0 = performance.now();
    for (let i = 0; i < 20; i += 1) buildChunkData({ wg, models, cx: FX + i, cz: FZ, preset: medium, lod: 0, ...empty });
    const ms = (performance.now() - t0) / 20;
    console.log(`  чанк medium: ${ms.toFixed(2)} мс`);
    assert.ok(ms < 40, `${ms} мс`);
});

test('менеджер чанков: кольцо, очередь, выгрузка при ходьбе 3 км, число объектов не растёт', () => {
    const scene = new THREE.Scene();
    const mgr = createChunkManager({ scene, wg, models });
    mgr.applyPreset(medium);
    const spawn = findDemoSpawn(wg);
    mgr.setCenter(spawn.x, spawn.z);
    assert.ok(mgr.pending > 20, 'в очереди чанки кольца');
    let peak = 0;
    let peakChildren = 0;
    for (let step = 0; step <= 3000; step += 20) {
        mgr.setCenter(spawn.x + step, spawn.z + step * 0.3);
        mgr.pump(1000);
        peak = Math.max(peak, mgr.loaded);
        peakChildren = Math.max(peakChildren, scene.children.length);
    }
    const maxChunks = Math.pow(2 * (medium.drawChunks + 0.3 + 1.2) + 1, 2);
    assert.ok(peak <= maxChunks, `пик ${peak} чанков, предел ${maxChunks}`);
    assert.ok(peak >= 60, `кольцо заполнено: ${peak}`);
    assert.ok(peakChildren <= maxChunks + 2, `детей сцены ${peakChildren}`);
    assert.ok(mgr.treeCount > 0);
    mgr.dispose();
    assert.equal(scene.children.length, 0, 'после dispose сцена пуста');
});

test('менеджер: первым строится ближайший чанк, смена подробности ставит перестройку', () => {
    const scene = new THREE.Scene();
    const mgr = createChunkManager({ scene, wg, models });
    mgr.applyPreset(medium);
    mgr.setCenter(1000.5, 1000.5);
    mgr.pump(0.001); // бюджет исчерпан сразу, но один чанк строится всегда
    assert.equal(mgr.loaded, 1);
    assert.ok(mgr.has(31, 31), 'первым построен чанк под героем');
    mgr.pump(10000);
    const c = mgr.chunk(31, 31);
    assert.equal(c.lod, 1);
    mgr.setCenter(1000.5 + 32 * 6, 1000.5);
    assert.ok(mgr.pending > 0, 'у сместившегося кольца есть новые чанки');
    mgr.dispose();
});

test('менеджер: fell() прячет дерево без перестройки чанка и ставит пень; повтор безопасен; хватает пня при перезагрузке чанка', () => {
    const scene = new THREE.Scene();
    const mgr = createChunkManager({ scene, wg, models });
    mgr.applyPreset(medium);
    mgr.setCenter(FX * 32 + 16, FZ * 32 + 16);
    mgr.pump(100000);
    const chunk = mgr.chunk(FX, FZ);
    const before = chunk.data.treeCount;
    const id = [...chunk.data.trees.keys()][0];
    const geometryBefore = chunk.geometry;
    assert.equal(mgr.fell(id), true);
    assert.equal(mgr.chunk(FX, FZ).geometry, geometryBefore, 'чанк не пересоздавался');
    assert.equal(chunk.data.treeCount, before - 1);
    assert.equal(mgr.stumpCount, 1);
    assert.equal(mgr.fell(id), false, 'повтор игнорируется');
    // уехали и вернулись: дерево не вернулось, пень на месте
    mgr.setCenter(FX * 32 + 16 + 32 * 30, FZ * 32 + 16);
    mgr.pump(100000);
    assert.ok(!mgr.has(FX, FZ));
    assert.equal(mgr.stumpCount, 0, 'пни выгруженного чанка освобождены');
    mgr.setCenter(FX * 32 + 16, FZ * 32 + 16);
    mgr.pump(100000);
    assert.ok(!mgr.chunk(FX, FZ).data.trees.has(id));
    assert.equal(mgr.stumpCount, 1);
    mgr.dispose();
});

test('менеджер: смена качества полностью перестраивает мир, а setChanges подмешивает состояние сервера', () => {
    const scene = new THREE.Scene();
    const mgr = createChunkManager({ scene, wg, models });
    mgr.applyPreset(QUALITY_PRESETS.low);
    mgr.setCenter(FX * 32 + 16, FZ * 32 + 16);
    mgr.pump(100000);
    const lowN = mgr.loaded;
    mgr.applyPreset(QUALITY_PRESETS.medium);
    assert.equal(mgr.loaded, 0);
    mgr.pump(100000);
    assert.ok(mgr.loaded > lowN);
    const id = treeId(FX, FZ, wg.chunkCells(FX, FZ).find((c) => c.kind === KIND.TREE).ci);
    mgr.setChanges({ felled: [id], clearings: [] });
    mgr.pump(100000);
    assert.ok(!mgr.chunk(FX, FZ).data.trees.has(id));
    mgr.dispose();
});
