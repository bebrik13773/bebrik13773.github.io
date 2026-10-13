import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { BIOME } from '../src/shared/worldgen.js';
import { SPAWN, findSpawn, spawnCandidate, spawnSuitable, pondFor, spawnCell } from '../src/shared/spawn.js';
import { xpToNext, levelFromXp, LEVEL } from '../src/shared/formulas.js';
import { buildSpawnVectors, makeWg, makeOccupancy, SPAWN_VECTORS_PATH } from './spawn-lib.mjs';

const wg = makeWg();
const FOREST = new Set([BIOME.CONIFER, BIOME.BROADLEAF, BIOME.BIRCH]);

test('кандидаты детерминированы и лежат в заданном диапазоне', () => {
    for (let id = 1; id <= 50; id += 1) {
        for (let k = 0; k < 20; k += 1) {
            const a = spawnCandidate(id, k, 0);
            const b = spawnCandidate(id, k, 0);
            assert.deepEqual(a, b);
            assert.ok(a.r >= SPAWN.minM && a.r <= SPAWN.maxM);
            const real = Math.hypot(a.x, a.z);
            assert.ok(Math.abs(real - a.r) < 1.5);
        }
        const w = spawnCandidate(id, 3, 1);
        assert.ok(w.r >= SPAWN.minM && w.r <= SPAWN.wideMaxM);
    }
});

test('найденное место: лес, ровно, не в городе, внутри 600..6000 м', () => {
    for (let id = 1; id <= 80; id += 1) {
        const s = findSpawn(wg, id, () => true);
        assert.ok(s, `игрок ${id}: место найдено`);
        assert.ok(FOREST.has(wg.biomeAt(s.x, s.z)));
        const r = Math.hypot(s.x, s.z);
        assert.ok(r >= SPAWN.minM - 1 && r <= SPAWN.wideMaxM + 1, `r=${r}`);
        assert.ok(!wg.isCityZone(s.x * 10, s.z * 10));
        assert.ok(spawnSuitable(wg, s.x, s.z, SPAWN.flatRelaxedM * 2));
    }
});

test('большинство игроков находят место с первых 30 попыток', () => {
    let first = 0;
    for (let id = 1; id <= 200; id += 1) {
        const s = findSpawn(wg, id, () => true);
        if (s.attempt <= SPAWN.attempts) first += 1;
    }
    assert.ok(first >= 195, `в первой фазе нашли ${first} из 200`);
});

test('два игрока не появляются ближе 150 м и не делят клетку (цепочка из 300)', () => {
    const occ = makeOccupancy();
    for (let id = 1; id <= 300; id += 1) {
        const s = findSpawn(wg, id, (x, z, gap) => occ.isFree(x, z, gap));
        assert.ok(s, `игрок ${id}`);
        for (const o of occ.spots) assert.ok(Math.abs(o.x - s.x) > SPAWN.gapM || Math.abs(o.z - s.z) > SPAWN.gapM, `игрок ${id} рядом с чужим`);
        occ.add(s);
    }
});

test('если всё занято, уходим в расширенные попытки; если совсем нет места, null', () => {
    const first = findSpawn(wg, 7, () => true);
    const blocked = findSpawn(wg, 7, (x, z) => !(x === first.x && z === first.z));
    assert.ok(blocked && (blocked.x !== first.x || blocked.z !== first.z));
    assert.equal(findSpawn(wg, 7, () => false), null);
});

test('пруд в 20 м от поляны, плотина на его краю, ключ клетки с отрицательными числами', () => {
    for (let id = 1; id <= 100; id += 1) {
        const s = findSpawn(wg, id, () => true);
        const p = pondFor(id, s.x, s.z);
        assert.ok(Math.abs(Math.hypot(p.x - s.x, p.z - s.z) - SPAWN.pondDistM) < 1.5);
        assert.ok(Math.abs(Math.hypot(p.damX - p.x, p.damZ - p.z) - SPAWN.pondRadiusM) < 1.5);
        assert.deepEqual(pondFor(id, s.x, s.z), p);
    }
    assert.equal(spawnCell(0, 0), '0:0');
    assert.equal(spawnCell(-1, -1), '-1:-1');
    assert.equal(spawnCell(-150, 149), '-1:0');
    assert.equal(spawnCell(150, -151), '1:-2');
});

test('уровни: кривая 100 × l^1.6, границы и максимум', () => {
    assert.equal(xpToNext(1), 100);
    assert.equal(xpToNext(2), 303);
    assert.equal(levelFromXp(0).level, 1);
    assert.equal(levelFromXp(99).level, 1);
    assert.equal(levelFromXp(100).level, 2);
    assert.deepEqual(levelFromXp(100), { level: 2, into: 0, need: 303 });
    assert.equal(levelFromXp(403).level, 3);
    assert.equal(levelFromXp(402).level, 2);
    const top = levelFromXp(1e12);
    assert.equal(top.level, LEVEL.max);
    assert.equal(top.need, 0);
    let total = 0;
    for (let l = 1; l < LEVEL.max; l += 1) total += xpToNext(l);
    assert.equal(levelFromXp(total).level, LEVEL.max);
    assert.equal(levelFromXp(total - 1).level, LEVEL.max - 1);
});

test('золотые векторы совпадают с текущей реализацией', () => {
    const file = JSON.parse(fs.readFileSync(SPAWN_VECTORS_PATH, 'utf8'));
    assert.deepEqual(JSON.parse(JSON.stringify(buildSpawnVectors())), file);
    assert.ok(file.alone.every((r) => r.spot));
});

test('config.php и клиентские константы спавна совпадают', () => {
    const php = fs.readFileSync(new URL('../../../api/forest/config.php', import.meta.url), 'utf8');
    const num = (name) => Number(new RegExp(`'${name}'\\s*=>\\s*(\\d+)`).exec(php)[1]);
    assert.equal(num('core_min_m'), SPAWN.coreMinM);
    assert.equal(num('core_max_m'), SPAWN.coreMaxM);
    assert.equal(num('core_share'), SPAWN.coreShare);
    assert.equal(num('wide_max_m'), SPAWN.wideMaxM);
    assert.equal(num('ring_m'), SPAWN.ringM);
    assert.equal(num('flat_m'), SPAWN.flatM);
    assert.equal(num('flat_relaxed_m'), SPAWN.flatRelaxedM);
    assert.equal(num('attempts_wide'), SPAWN.attemptsWide);
    assert.equal(num('attempts_fallback'), SPAWN.attemptsFallback);
    assert.equal(num('pond_dist_m'), SPAWN.pondDistM);
    assert.equal(num('pond_radius_m'), SPAWN.pondRadiusM);
    assert.equal(num('spawn_min_gap_m'), SPAWN.gapM);
    assert.equal(num('spawn_attempts'), SPAWN.attempts);
    assert.equal(num('spawn_min_m'), SPAWN.minM);
    assert.equal(num('spawn_max_m'), SPAWN.maxM);
});
