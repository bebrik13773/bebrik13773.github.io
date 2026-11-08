import test from 'node:test';
import assert from 'node:assert/strict';
import { GARDEN, gardenSlots, slotPos, seedSpecies, stageOf, seedsInPack, leftText, gardenRows } from '../src/shared/garden.js';
import { itemKg } from '../src/shared/formulas.js';
import { saplingHeight } from '../src/world/sapling-view.js';

test('места посадки растут с уровнем сада и не больше кольца', () => {
    assert.equal(gardenSlots(1), 6);
    assert.equal(gardenSlots(2), 10);
    assert.equal(gardenSlots(3), 14);
    assert.equal(gardenSlots(9), GARDEN.ringSlots);
    assert.equal(gardenSlots(0), 6);
});

test('место посадки: кольцо вокруг сада, два знака, как на сервере', () => {
    assert.deepEqual(slotPos(0, 0, 0), { x: 4.8, z: 0 });
    const p = slotPos(10, -5, 7);
    assert.ok(Math.abs(Math.hypot(p.x - 10, p.z + 5) - 4.8) < 0.02);
    const seen = new Set();
    for (let i = 0; i < GARDEN.ringSlots; i += 1) { const q = slotPos(0, 0, i); seen.add(`${q.x}:${q.z}`); }
    assert.equal(seen.size, GARDEN.ringSlots);
});

test('семя: порода по предмету, вес как у семени', () => {
    assert.equal(seedSpecies('seed_oak'), 'oak');
    assert.equal(seedSpecies('seed_'), null);
    assert.equal(seedSpecies('log'), null);
    assert.equal(itemKg('seed_gold_birch'), 0.1);
});

test('стадии роста по доле', () => {
    assert.deepEqual([0, 0.32, 0.33, 0.65, 0.66, 0.99, 1].map(stageOf), [0, 0, 1, 1, 2, 2, 3]);
});

test('семена в рюкзаке: только seed_, по убыванию', () => {
    const l = seedsInPack({ log: 9, seed_oak: 1, seed_birch: 3, seed_pine: 0, plank: 2 });
    assert.deepEqual(l.map((x) => x.item), ['seed_birch', 'seed_oak']);
});

test('время до роста читается по-человечески', () => {
    assert.equal(leftText(30), '1 мин');
    assert.equal(leftText(3600 * 5 + 600), '5 ч 10 мин');
    assert.equal(leftText(86400 * 3 + 3600 * 4), '3 д 4 ч');
});

test('строки окна сада: пустые места, растущее, засохшее, выросшее', () => {
    const saplings = [
        { id: 1, building: 7, slot: 0, species: 'oak', state: 'growing', frac: 0.5, stage: 1, watered: true, big: true, rate: 1, left_s: 1000 },
        { id: 2, building: 7, slot: 1, species: 'pine', state: 'dry', frac: 0.2, stage: 0 },
        { id: 3, building: 7, slot: 2, species: 'birch', state: 'grown', frac: 1, stage: 3, need: 12 },
        { id: 4, building: 99, slot: 3, species: 'oak', state: 'growing', frac: 0.1, stage: 0, left_s: 5 },
    ];
    const rows = gardenRows({ buildingId: 7, level: 1, saplings, age: 100 });
    assert.equal(rows.length, 6);
    assert.deepEqual(rows.map((r) => r.state), ['growing', 'dry', 'grown', 'empty', 'empty', 'empty']);
    assert.equal(rows[0].leftS, 900);
    assert.equal(rows[2].need, 12);
});

test('рост саженца: высота растёт, большое дерево выше среднего', () => {
    assert.ok(saplingHeight(0, false) < saplingHeight(0.5, false));
    assert.ok(saplingHeight(1, false) < saplingHeight(1, true));
    assert.ok(saplingHeight(5, false) <= saplingHeight(1, false) + 1e-9);
});
