import test from 'node:test';
import assert from 'node:assert/strict';
import { benchRows, lackOf, levelValue, maxLevelOf, gearOfLevels } from '../src/shared/upgrades.js';

const catalog = {
    levels: {
        axe: { 2: { coins: 400, items: {} }, 3: { coins: 1200, items: { ingot: 5 } } },
        pack: { 2: { coins: 200, items: {} } },
        stamina: { 2: { coins: 250, items: {} } },
        lamp: { 1: { coins: 150, items: {} }, 2: { coins: 700, items: {} } },
    },
    once: { pickaxe: { coins: 250, items: { ingot: 1 } }, boots: { coins: 450, items: { plank: 4 } } },
    lamp_bonus_m: { 1: 12, 2: 24 },
};
const base = { catalog, levels: { axe: 1, pack: 1, stamina: 1, lamp: 0 }, have: {}, coins: 1000 };

test('maxLevelOf берёт максимум из каталога', () => {
    assert.equal(maxLevelOf(catalog, 'axe'), 3);
    assert.equal(maxLevelOf(catalog, 'lamp'), 2);
});
test('levelValue: сила топора, вместимость, энергия, фонарь', () => {
    assert.equal(levelValue('axe', 2, catalog), 1.4);
    assert.equal(levelValue('pack', 2, catalog), 50);
    assert.equal(levelValue('stamina', 3, catalog), 120);
    assert.equal(levelValue('lamp', 0, catalog), 0);
    assert.equal(levelValue('lamp', 2, catalog), 24);
});
test('lackOf считает нехватку монет и предметов', () => {
    assert.deepEqual(lackOf({ coins: 100, items: { ingot: 3 } }, { ingot: 1 }, 40), { coins: 60, ingot: 2 });
    assert.deepEqual(lackOf({ coins: 100, items: {} }, {}, 100), {});
});
test('benchRows: статусы ok, lack, max, owned, full', () => {
    const rows = benchRows({ ...base, levels: { axe: 3, pack: 1, stamina: 1, lamp: 0 }, hotbar: ['axe'], gear: { boots: 1 } });
    const by = Object.fromEntries(rows.map((r) => [r.id, r]));
    assert.equal(by.axe.status, 'max');
    assert.equal(by.pack.status, 'ok');
    assert.equal(by.pack.to, 2);
    assert.equal(by.pack.from, 40);
    assert.equal(by.pack.next, 50);
    assert.equal(by.pickaxe.status, 'lack');
    assert.deepEqual(by.pickaxe.lack, { ingot: 1 });
    assert.equal(by.boots.status, 'owned');
    const full = benchRows({ ...base, have: { ingot: 1 }, hotbar: ['a', 'b', 'c', 'd', 'e', 'f'], slots: 6 });
    assert.equal(full.find((r) => r.id === 'pickaxe').status, 'full');
    const owned = benchRows({ ...base, hotbar: ['axe', 'pickaxe'] });
    assert.equal(owned.find((r) => r.id === 'pickaxe').status, 'owned');
});
test('gearOfLevels отдаёт уровни для модели бобра', () => {
    assert.deepEqual(gearOfLevels({ axe: 3, pack: 4, stamina: 2, lamp: 1 }), { axe: 3, pack: 4, lamp: 1 });
});
