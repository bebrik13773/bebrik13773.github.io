// Тест-векторы появления игрока (ДЛ-10). Файл tests/vectors/spawn.json читают JS (node --test) и PHP (tools/forest-selftest.php).
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createWorldGen } from '../src/shared/worldgen.js';
import { findSpawn, pondFor, spawnCell } from '../src/shared/spawn.js';
import { xpToNext, levelFromXp } from '../src/shared/formulas.js';
import { loadShared } from './vectors-lib.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
export const SPAWN_VECTORS_PATH = path.join(here, 'vectors', 'spawn.json');

export function makeWg(seed = 1337) {
    const { species, biomes } = loadShared();
    return createWorldGen({ seed, species, biomes });
}

/** Занятость как на сервере: чужой спавн ближе gap (по рамке) занят, клетка 150 м занята. */
export function makeOccupancy() {
    const spots = [];
    const cells = new Set();
    return {
        spots,
        isFree(x, z, gap) {
            for (const s of spots) if (Math.abs(s.x - x) <= gap && Math.abs(s.z - z) <= gap) return false;
            const c = spawnCell(x, z);
            if (cells.has(c)) return false;
            cells.add(c);
            return true;
        },
        add(s) { spots.push(s); },
    };
}

export function buildSpawnVectors() {
    const wg = makeWg();
    const alone = [];
    const ids = [];
    for (let i = 1; i <= 120; i += 1) ids.push(i);
    ids.push(2147483653, 4000000000);
    for (const id of ids) {
        const spot = findSpawn(wg, id, () => true);
        alone.push({ id, spot, pond: spot ? pondFor(id, spot.x, spot.z) : null });
    }
    // Цепочка из 60 игроков подряд: каждый видит спавны предыдущих.
    const occ = makeOccupancy();
    const chain = [];
    for (let id = 1; id <= 60; id += 1) {
        const spot = findSpawn(wg, id, (x, z, gap) => occ.isFree(x, z, gap));
        if (spot) occ.add(spot);
        chain.push({ id, spot });
    }
    const levels = [];
    for (let l = 1; l <= 12; l += 1) levels.push({ level: l, next: xpToNext(l) });
    const xps = [0, 99, 100, 101, 402, 1000, 5000, 100000, 10000000].map((xp) => ({ xp, ...levelFromXp(xp) }));
    return { version: 1, note: 'ДЛ-10: спавн, пруд, уровни. Менять только вместе с lib/spawn.php и lib/formulas.php', alone, chain, levels, xps };
}
