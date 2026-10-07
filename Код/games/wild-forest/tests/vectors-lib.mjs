// Построение тест-векторов генерации мира (ДЛ-05). Один и тот же файл проверяют JS (node --test)
// и PHP (tools/forest-selftest.php), поэтому он «золотой»: менять алгоритм можно только вместе с PHP.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { hash, noise2, fbm } from '../src/shared/rng.js';
import { createWorldGen } from '../src/shared/worldgen.js';

const here = path.dirname(fileURLToPath(import.meta.url));
export const SHARED_DIR = path.join(here, '..', 'src', 'shared');
export const VECTORS_PATH = path.join(here, 'vectors', 'worldgen.json');
export const loadShared = () => ({
    species: JSON.parse(fs.readFileSync(path.join(SHARED_DIR, 'species.json'), 'utf8')),
    biomes: JSON.parse(fs.readFileSync(path.join(SHARED_DIR, 'biomes.json'), 'utf8')),
});

const MAIN_SEED = 1337;
const SECOND_SEED = 4000000007; // больше 2^31: проверка беззнаковости сида
const GEN_SEED = 20261004;       // сид самого генератора точек (не мира)

// Детерминированный «случайный» целый в [lo, hi] на основе нашего же хеша.
const rnd = (i, salt, lo, hi) => lo + (hash(GEN_SEED, i, salt, 99) % (hi - lo + 1));

function specialPoints() {
    const pts = [[0, 0], [1, 1], [-1, -1], [0, -1], [-1, 0], [1199, 0], [1200, 0], [-1200, 0], [0, 1200], [0, -1199],
        [319, 319], [320, 320], [-320, -320], [-321, -321], [-1, 319], [319, -1], [6000, 0], [-6000, 0], [14999, 0], [15000, 0],
        [1000000, 1000000], [-1000000, -1000000], [1000000, -1000000], [-1000000, 1000000], [1000001, 0], [0, -1000001],
        [999999, 999999], [-999999, -999999], [5000, 5000], [-5000, 5000], [49999, 0], [50000, 0], [-50001, 0]];
    return pts;
}

function buildPoints(wg, count, salt) {
    const pts = specialPoints().slice(0, count);
    const ranges = [20000, 200000, 1000000, 1100000];
    for (let i = pts.length; i < count; i++) {
        const r = ranges[i % ranges.length];
        pts.push([rnd(i, salt, -r, r), rnd(i, salt + 1, -r, r)]);
    }
    return pts.map(([X, Z]) => {
        const E = wg.fieldE(X, Z), M = wg.fieldM(X, Z), C = wg.fieldC(X, Z), R = wg.fieldR(X, Z), D = wg.fieldD(X, Z);
        return [X, Z, E, M, C, R, D, wg.heightAtDm(X, Z), wg.biomeAtDm(X, Z), wg.tierAtDm(X, Z), wg.zoneAtDm(X, Z), wg.isCityZone(X, Z) ? 1 : 0, wg.isInsideWorld(X, Z) ? 1 : 0];
    });
}

const packCell = (cx, cz, ci, c) => [cx, cz, ci, c.kind, c.ox, c.oz, c.species, c.size, c.rare, c.treasure, c.biome];

function buildCells(wg, count, salt) {
    const out = [];
    const edgeCells = [[0, 0, 0], [-1, -1, 63], [3124, 3124, 63], [-3125, -3125, 0], [3125, 0, 7], [3126, 0, 0], [0, 0, 63], [-4, -4, 0], [3, 3, 9], [-1, 0, 7]];
    for (const [cx, cz, ci] of edgeCells) out.push(packCell(cx, cz, ci, wg.cellAt(cx, cz, ci)));
    for (let i = 0; out.length < count; i++) {
        const cx = rnd(i, salt, -3200, 3200), cz = rnd(i, salt + 1, -3200, 3200), ci = rnd(i, salt + 2, 0, 63);
        out.push(packCell(cx, cz, ci, wg.cellAt(cx, cz, ci)));
    }
    // Целевой поиск редких случаев, чтобы они точно попали в векторы: руда, редкие породы, сокровища, булыжники в лесу.
    const want = { ore: 200, rare: 120, treasure: 60, boulder: 100 };
    const have = { ore: 0, rare: 0, treasure: 0, boulder: 0 };
    for (let i = 0; i < 2000000 && (have.ore < want.ore || have.rare < want.rare || have.treasure < want.treasure || have.boulder < want.boulder); i++) {
        const cx = rnd(i, salt + 10, -3000, 3000), cz = rnd(i, salt + 11, -3000, 3000), ci = rnd(i, salt + 12, 0, 63);
        const c = wg.cellAt(cx, cz, ci);
        let tag = null;
        if (c.kind === 3 && have.ore < want.ore) tag = 'ore';
        else if (c.kind === 1 && c.rare && have.rare < want.rare) tag = 'rare';
        else if (c.kind === 1 && c.treasure && have.treasure < want.treasure) tag = 'treasure';
        else if (c.kind === 2 && c.biome !== 1 && have.boulder < want.boulder) tag = 'boulder';
        if (tag) { have[tag]++; out.push(packCell(cx, cz, ci, c)); }
    }
    return out;
}

export function buildVectors() {
    const { species, biomes } = loadShared();
    const worlds = [];
    for (const [seed, pts, cells, salt] of [[MAIN_SEED, 10000, 10000, 1000], [SECOND_SEED, 1500, 1500, 5000]]) {
        const wg = createWorldGen({ seed, species, biomes });
        worlds.push({ seed, points: buildPoints(wg, pts, salt), cells: buildCells(wg, cells, salt + 100) });
    }
    const seeds = [0, 1, 1337, 123456789, 2147483647, 2147483648, 4000000007, 4294967295];
    const hashes = [];
    for (let i = 0; i < 1500; i++) {
        const seed = seeds[i % seeds.length];
        const x = i < 40 ? (i - 20) * 7919 : rnd(i, 300, -1100000, 1100000);
        const z = i < 40 ? (i - 20) * -104729 : rnd(i, 301, -1100000, 1100000);
        const salt = rnd(i, 302, 0, 200);
        hashes.push([seed, x, z, salt, hash(seed, x, z, salt)]);
    }
    const noises = [];
    const periods = [75, 375, 600, 750, 3000, 4000, 5000, 6000];
    for (let i = 0; i < 1000; i++) {
        const seed = seeds[i % seeds.length];
        const X = rnd(i, 400, -1100000, 1100000), Z = rnd(i, 401, -1100000, 1100000);
        const P = periods[i % periods.length], salt = rnd(i, 402, 0, 50);
        noises.push([seed, X, Z, P, salt, noise2(seed, X, Z, P, salt), fbm(seed, X, Z, P * 8 > 6000 ? 6000 : P * 8, salt)]);
    }
    return {
        version: 1,
        note: 'Золотые тест-векторы генерации мира (ДЛ-05). Создаются командой: node tests/gen-vectors.mjs. Формат записей описан в tests/vectors-lib.mjs.',
        formats: {
            hash: 'seed,x,z,salt,hash',
            noise: 'seed,X,Z,P,salt,noise2,fbm(P*8, максимум 6000)',
            points: 'X,Z,E,M,C,R,D,height,biome,tier,zone,city,inside',
            cells: 'cx,cz,ci,kind,ox,oz,species,size,rare,treasure,biome',
        },
        hashes, noises, worlds,
    };
}
