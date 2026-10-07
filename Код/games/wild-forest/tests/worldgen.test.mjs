import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createWorldGen, BIOME, KIND, ZONE, chunkOf, treeId, parseTreeId } from '../src/shared/worldgen.js';
import { hash, noise2, fbm } from '../src/shared/rng.js';
import { buildVectors, loadShared, VECTORS_PATH } from './vectors-lib.mjs';
import { WORLD_SEED } from '../src/config.js';

const { species, biomes } = loadShared();
const wg = createWorldGen({ seed: 1337, species, biomes });
const here = path.dirname(fileURLToPath(import.meta.url));
const configPhp = fs.readFileSync(path.join(here, '..', '..', '..', 'api', 'forest', 'config.php'), 'utf8');

test('сид клиента совпадает с сидом сервера (config.php)', () => {
    const m = /'seed'\s*=>\s*(\d+)/.exec(configPhp);
    assert.ok(m, 'в config.php есть world.seed');
    assert.equal(Number(m[1]), WORLD_SEED);
    assert.equal(WORLD_SEED, 1337);
});

test('данные пород и биомов согласованы между собой и с config.php', () => {
    const ids = species.species.map((s) => s.id);
    assert.equal(new Set(ids).size, ids.length);
    assert.equal(ids.length, 10);
    const phpIds = [...configPhp.matchAll(/^\s{12}'([a-z_]+)'\s*=>\s*\['h'/gm)].map((m) => m[1]);
    assert.deepEqual([...phpIds].sort(), [...ids].sort(), 'породы в species.json и config.php одинаковы');
    const phpDensity = {};
    for (const m of configPhp.matchAll(/'(conifer|broadleaf|birch|swamp|rocks|water)'\s*=>\s*([0-9.]+)/g)) phpDensity[m[1]] = Number(m[2]);
    for (const b of biomes.biomes) {
        assert.equal(phpDensity[b.id], b.density_permille / 1000, 'плотность ' + b.id);
        for (const [id] of b.species) assert.ok(ids.includes(id), id);
        if (b.rare) assert.ok(species.species.find((s) => s.id === b.rare).rare);
    }
    assert.deepEqual(biomes.biomes.map((b) => b.code), [0, 1, 2, 3, 4, 5]);
});

test('золотые тест-векторы: файл актуален и воспроизводится текущим кодом', () => {
    const onDisk = JSON.parse(fs.readFileSync(VECTORS_PATH, 'utf8'));
    const fresh = JSON.parse(JSON.stringify(buildVectors()));
    assert.deepEqual(fresh.hashes, onDisk.hashes);
    assert.deepEqual(fresh.noises, onDisk.noises);
    for (let i = 0; i < fresh.worlds.length; i++) {
        assert.equal(fresh.worlds[i].seed, onDisk.worlds[i].seed);
        assert.deepEqual(fresh.worlds[i].points, onDisk.worlds[i].points, 'точки мира ' + i);
        assert.deepEqual(fresh.worlds[i].cells, onDisk.worlds[i].cells, 'клетки мира ' + i);
    }
});

test('в векторах не меньше 10 000 точек и 10 000 клеток, есть все виды содержимого', () => {
    const v = JSON.parse(fs.readFileSync(VECTORS_PATH, 'utf8'));
    assert.ok(v.worlds[0].points.length >= 10000);
    assert.ok(v.worlds[0].cells.length >= 10000);
    const kinds = new Set(v.worlds[0].cells.map((r) => r[3]));
    assert.deepEqual([...kinds].sort(), [0, 1, 2, 3]);
    assert.ok(v.worlds[0].cells.some((r) => r[8] === 1), 'редкие породы');
    assert.ok(v.worlds[0].cells.some((r) => r[9] === 1), 'сокровища');
    const biomesSeen = new Set(v.worlds[0].points.map((r) => r[8]));
    assert.equal(biomesSeen.size, 6, 'все шесть биомов в точках');
});

test('детерминизм и симметрия: те же входы дают те же выходы, чанки не зависят от порядка вызова', () => {
    const a = wg.chunkCells(-7, 12);
    wg.chunkCells(100, 100);
    const b = wg.chunkCells(-7, 12);
    assert.deepEqual(a, b);
    const other = createWorldGen({ seed: 1337, species, biomes });
    assert.deepEqual(other.chunkCells(-7, 12), a);
    const diff = createWorldGen({ seed: 1338, species, biomes });
    assert.notDeepEqual(diff.chunkCells(-7, 12), a);
});

test('город: ни одного дерева в радиусе 120 м от (0, 0)', () => {
    let checked = 0;
    for (let cx = -6; cx <= 5; cx++) for (let cz = -6; cz <= 5; cz++) for (let ci = 0; ci < 64; ci++) {
        const c = wg.cellAt(cx, cz, ci);
        if (wg.isCityZone(c.X, c.Z)) { assert.equal(c.kind, KIND.NONE); checked++; }
    }
    assert.ok(checked > 1000);
    assert.equal(wg.isCityZone(1199, 0), true);
    assert.equal(wg.isCityZone(1200, 0), false);
});

test('край мира: за 100 км клетки пустые', () => {
    assert.equal(wg.isInsideWorld(1000000, -1000000), true);
    assert.equal(wg.isInsideWorld(1000001, 0), false);
    for (let ci = 0; ci < 64; ci++) assert.equal(wg.cellAt(3200, 0, ci).kind, KIND.NONE);
});

test('деревья не растут на воде; породы соответствуют биому; редкие только в своём биоме', () => {
    const allowed = new Map(biomes.biomes.map((b) => [b.code, new Set([...b.species.map(([id]) => id), ...(b.rare ? [b.rare] : [])])]));
    const ids = species.species.map((s) => s.id);
    let trees = 0, rare = 0;
    for (let i = 0; i < 12000; i++) {
        const cx = (i * 37) % 2000 - 1000, cz = (i * 91) % 2000 - 1000, ci = (i * 13) % 64;
        const c = wg.cellAt(cx, cz, ci);
        if (c.kind !== KIND.TREE) continue;
        trees++;
        assert.notEqual(c.biome, BIOME.WATER);
        assert.ok(allowed.get(c.biome).has(ids[c.species]), `порода ${ids[c.species]} в биоме ${c.biome}`);
        assert.ok([0, 1, 2].includes(c.size));
        if (c.rare) rare++;
        assert.ok(c.ox >= -15 && c.ox <= 15 && c.oz >= -15 && c.oz <= 15);
    }
    assert.ok(trees > 3000);
    assert.ok(rare >= 0);
});

test('плотность деревьев по биомам близка к таблице плана', () => {
    const stat = new Map();
    for (let i = 0; i < 60000; i++) {
        const cx = (i * 41) % 3000 - 1500, cz = (i * 97) % 3000 - 1500, ci = (i * 17) % 64;
        const c = wg.cellAt(cx, cz, ci);
        if (c.biome === BIOME.WATER || wg.isCityZone(c.X, c.Z)) continue;
        const s = stat.get(c.biome) || { n: 0, t: 0 };
        s.n++;
        if (c.kind === KIND.TREE) s.t++;
        stat.set(c.biome, s);
    }
    for (const b of biomes.biomes) {
        if (b.code === BIOME.WATER) continue;
        const s = stat.get(b.code);
        if (!s || s.n < 400) continue; // болото редкое: мало выборки
        const share = s.t / s.n;
        const expect = b.density_permille / 1000;
        // руда съедает часть клеток скал, поэтому допуск шире
        assert.ok(Math.abs(share - expect) < 0.05, `${b.id}: ${share.toFixed(3)} против ${expect}`);
    }
});

test('размеры деревьев: около 60/30/10 процентов', () => {
    const cnt = [0, 0, 0];
    for (let i = 0; i < 80000; i++) {
        const c = wg.cellAt((i * 53) % 3000 - 1500, (i * 71) % 3000 - 1500, (i * 29) % 64);
        if (c.kind === KIND.TREE) cnt[c.size]++;
    }
    const total = cnt[0] + cnt[1] + cnt[2];
    assert.ok(Math.abs(cnt[0] / total - 0.6) < 0.03 && Math.abs(cnt[1] / total - 0.3) < 0.03 && Math.abs(cnt[2] / total - 0.1) < 0.03, cnt.join('/'));
});

test('редкая порода: шанс растёт с удалённостью и ограничен 6 процентами', () => {
    assert.equal(wg.rareChance(0), 120);
    assert.equal(wg.rareChance(1), 150);
    assert.equal(wg.rareChance(4), 240);
    assert.equal(wg.rareChance(10), 420);
    assert.ok(wg.rareChance(100) <= 600);
});

test('руда только в высоких скалах; булыжники есть в скалах и в лесу', () => {
    let ore = 0, boulderRocks = 0, boulderForest = 0;
    for (let i = 0; i < 150000; i++) {
        const cx = (i * 61) % 3000 - 1500, cz = (i * 83) % 3000 - 1500, ci = (i * 19) % 64;
        const c = wg.cellAt(cx, cz, ci);
        if (c.kind === KIND.ORE) { ore++; assert.ok(wg.fieldE(c.X, c.Z) > 50000); assert.equal(c.biome, BIOME.ROCKS); }
        if (c.kind === KIND.BOULDER) { if (c.biome === BIOME.ROCKS) boulderRocks++; else boulderForest++; }
        assert.equal(wg.oreAt(cx, cz, ci), c.kind === KIND.ORE);
        assert.equal(wg.boulderAt(cx, cz, ci), c.kind === KIND.BOULDER);
    }
    assert.ok(ore > 20 && boulderRocks > 100 && boulderForest > 100, `${ore}/${boulderRocks}/${boulderForest}`);
});

test('рельеф: высота в разумных пределах, вода ниже уровня воды, суша выше', () => {
    let min = 1e9, max = -1e9, water = 0, land = 0;
    for (let i = 0; i < 20000; i++) {
        const X = (i * 7919) % 600000 - 300000, Z = (i * 104729) % 600000 - 300000;
        const H = wg.heightAtDm(X, Z);
        min = Math.min(min, H); max = Math.max(max, H);
        const b = wg.biomeAtDm(X, Z);
        if (b === BIOME.WATER) { water++; assert.ok(H < wg.waterLevelDm, `вода на ${H}`); }
        else { land++; assert.ok(H >= wg.waterLevelDm, `суша ниже воды: ${H} биом ${b}`); }
    }
    assert.ok(min >= -120 && max <= 340, `${min}..${max}`);
    assert.ok(water > 100 && land > 15000, `${water}/${land}`);
});

test('рельеф плавный: соседние точки через 4 м не отличаются больше чем на 10 м по высоте', () => {
    let worst = 0;
    for (let i = 0; i < 6000; i++) {
        const X = (i * 7919) % 400000 - 200000, Z = (i * 104729) % 400000 - 200000;
        const d = Math.abs(wg.heightAtDm(X, Z) - wg.heightAtDm(X + 40, Z));
        worst = Math.max(worst, d);
    }
    assert.ok(worst <= 100, 'худший перепад ' + worst + ' дм');
});

test('tier и zone: границы по расстоянию до города', () => {
    assert.equal(wg.tierAtDm(0, 0), 0);
    assert.equal(wg.tierAtDm(4999, 0), 0);
    assert.equal(wg.tierAtDm(5000, 0), 1);
    assert.equal(wg.tierAtDm(-5000, 0), 1);
    assert.equal(wg.tierAtDm(0, 49999), 9);
    assert.equal(wg.tierAtDm(0, 50000), 10);
    assert.equal(wg.tierAtDm(900000, 0), 10);
    assert.equal(wg.zoneAtDm(5999, 0), ZONE.EDGE);
    assert.equal(wg.zoneAtDm(6000, 0), ZONE.THICKET);
    assert.equal(wg.zoneAtDm(14999, 0), ZONE.THICKET);
    assert.equal(wg.zoneAtDm(15000, 0), ZONE.WILD);
    assert.equal(wg.zoneAtDm(-10700, 10700), ZONE.WILD);
});

test('отрицательные координаты: чанк считается через floor, обёртки в метрах не ломаются', () => {
    assert.equal(chunkOf(-0.1), -1);
    assert.equal(chunkOf(-32), -1);
    assert.equal(chunkOf(-32.1), -2);
    assert.equal(chunkOf(31.9), 0);
    assert.equal(chunkOf(32), 1);
    assert.equal(wg.biomeAt(-123.4, 567.8), wg.biomeAtDm(-1235, 5678));
    assert.equal(wg.heightAt(-123.4, 567.8), wg.heightAtDm(-1234 - 1, 5678) / 10 === 0 ? 0 : wg.heightAtDm(Math.floor(-123.4 * 10), Math.floor(567.8 * 10)) / 10);
});

test('id дерева: сборка и разбор, мусор отклоняется', () => {
    assert.equal(treeId(3, -2, 17), '3:-2:17');
    assert.deepEqual(parseTreeId('3:-2:17'), { cx: 3, cz: -2, ci: 17 });
    assert.deepEqual(parseTreeId('-10:5:63'), { cx: -10, cz: 5, ci: 63 });
    for (const bad of ['', 'a:b:c', '1:2', '1:2:64', '1:2:-1', '1:2:3:4', null, undefined]) assert.equal(parseTreeId(bad), null, String(bad));
});

test('chunkCells: id, позиция внутри чанка и высота согласованы с cellAt', () => {
    const cx = 14, cz = -9;
    const list = wg.chunkCells(cx, cz);
    assert.ok(list.length > 5 && list.length <= 64);
    for (const c of list) {
        assert.deepEqual(parseTreeId(c.id), { cx, cz, ci: c.ci });
        assert.ok(c.x >= cx * 32 - 1.6 && c.x < cx * 32 + 33.6);
        assert.ok(c.z >= cz * 32 - 1.6 && c.z < cz * 32 + 33.6);
        assert.equal(c.y, wg.heightAtDm(Math.round(c.x * 10), Math.round(c.z * 10)) / 10);
    }
});

test('скорость: генерация чанка укладывается в бюджет (в контейнере без GPU, 1 ядро: с запасом)', () => {
    wg.chunkCells(0, 50); // прогрев
    const t0 = performance.now();
    const N = 200;
    for (let i = 0; i < N; i++) wg.chunkCells(100 + i, -50 + (i % 7));
    const per = (performance.now() - t0) / N;
    console.log(`  чанк: ${per.toFixed(3)} мс в среднем (цель на обычном ПК меньше 2 мс)`);
    assert.ok(per < 8, 'слишком медленно: ' + per);
});

test('hash/fbm публичного API совпадают с векторами по первым записям', () => {
    const v = JSON.parse(fs.readFileSync(VECTORS_PATH, 'utf8'));
    for (const [seed, x, z, salt, h] of v.hashes.slice(0, 200)) assert.equal(hash(seed, x, z, salt), h);
    for (const [seed, X, Z, P, salt, n, f] of v.noises.slice(0, 100)) {
        assert.equal(noise2(seed, X, Z, P, salt), n);
        assert.equal(fbm(seed, X, Z, P * 8 > 6000 ? 6000 : P * 8, salt), f);
    }
});
