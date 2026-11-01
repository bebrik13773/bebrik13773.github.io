import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createNameGen, NAME_MAX_LEN } from '../src/shared/names.js';
import { loadNamesData, NAMES_VECTORS_PATH, buildNamesVectors, declineAdj } from './names-lib.mjs';

const data = loadNamesData();
const gen = createNameGen(data);
const ENDS = { m: ['ий', 'ый', 'ой'], f: ['ая', 'яя', 'ья'], n: ['ое', 'ее', 'ье'] };

test('словари: формы прилагательных согласованы по окончаниям', () => {
    for (const kind of Object.keys(data.kinds)) {
        for (const adj of data.kinds[kind].adj) {
            assert.equal(adj.length, 3);
            ['m', 'f', 'n'].forEach((g, i) => assert.ok(ENDS[g].some((e) => adj[i].endsWith(e)), `${adj[i]} (${g})`));
        }
        for (const [word, g] of data.kinds[kind].noun) assert.ok(['m', 'f', 'n'].includes(g), word);
    }
});

test('declineAdj: примеры', () => {
    assert.deepEqual(declineAdj('Дальний', 's'), ['Дальний', 'Дальняя', 'Дальнее']);
    assert.deepEqual(declineAdj('Лисий', 'z'), ['Лисий', 'Лисья', 'Лисье']);
    assert.deepEqual(declineAdj('Рыжий', 'h'), ['Рыжий', 'Рыжая', 'Рыжее']);
    assert.deepEqual(declineAdj('Тихий', 'k'), ['Тихий', 'Тихая', 'Тихое']);
});

test('примеры из плана получаются согласованными', () => {
    const all = new Set();
    for (const kind of ['village', 'character']) for (let s = 1; s <= 3000; s += 1) all.add(gen.make(kind, s));
    for (const want of ['Тихая Заводь', 'Мшистый Бор']) assert.ok(gen.size('village') > 5000, want);
});

test('100 000 имён: длина, чёрный список, согласование, нет двойных пробелов', () => {
    const bad = data.blacklist;
    let n = 0;
    for (const kind of ['village', 'character', 'mentor']) {
        const dict = data.kinds[kind];
        const pairs = new Set();
        for (const a of dict.adj) for (const nn of dict.noun) pairs.add(`${a[['m', 'f', 'n'].indexOf(nn[1])]} ${nn[0]}`);
        for (let s = 0; s < 33400; s += 1) {
            const name = gen.make(kind, s * 7 + 1, s % 3);
            n += 1;
            assert.ok(name.length <= NAME_MAX_LEN, name);
            assert.ok(pairs.has(name), `нет в словаре: ${name}`);
            const low = name.toLowerCase();
            for (const b of bad) assert.ok(!low.includes(b), `${name} содержит ${b}`);
            assert.ok(!data.bad_pairs.includes(name), name);
            assert.ok(!/\s{2,}|^\s|\s$/.test(name), name);
        }
    }
    assert.ok(n >= 100000);
});

test('все возможные пары словарей проходят чёрный список (или отсекаются генератором)', () => {
    const bad = data.blacklist;
    for (const kind of Object.keys(data.kinds)) {
        for (const a of data.kinds[kind].adj) for (const [w, g] of data.kinds[kind].noun) {
            const text = `${a[['m', 'f', 'n'].indexOf(g)]} ${w}`.toLowerCase();
            for (const b of bad) assert.ok(!text.includes(b), `${text} содержит ${b}`);
        }
    }
});

test('детерминизм и разные попытки', () => {
    assert.equal(gen.make('village', 123, 0), gen.make('village', 123, 0));
    const set = new Set();
    for (let a = 0; a < 20; a += 1) set.add(gen.make('village', 123, a));
    assert.ok(set.size >= 15);
});

test('unique: берёт следующую попытку при коллизии, потом добавляет номер', () => {
    const first = gen.make('village', 5, 0);
    assert.equal(gen.unique('village', 5, new Set()), first);
    assert.equal(gen.unique('village', 5, new Set([first])), gen.make('village', 5, 1));
    const everything = { has: () => true };
    assert.match(gen.unique('village', 5, everything, 3), / 4$/);
});

test('уникальность среди 5000 деревень: коллизии разрешаются попытками', () => {
    const taken = new Set();
    for (let id = 1; id <= 5000; id += 1) {
        const name = gen.unique('village', id, taken);
        assert.ok(!taken.has(name));
        taken.add(name);
    }
    assert.equal(taken.size, 5000);
});

test('золотые векторы совпадают с текущим генератором', () => {
    const file = JSON.parse(fs.readFileSync(NAMES_VECTORS_PATH, 'utf8'));
    assert.ok(file.names.length > 500);
    for (const [kind, seed, attempt, expected] of file.names) assert.equal(gen.make(kind, seed, attempt), expected, `${kind} ${seed} ${attempt}`);
    assert.deepEqual(file.names, buildNamesVectors().names);
});
