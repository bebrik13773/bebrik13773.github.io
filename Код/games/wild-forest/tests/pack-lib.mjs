// Тест-векторы рюкзака (ДЛ-13): tests/vectors/pack.json читают JS (node --test) и PHP (tools/forest-selftest.php).
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { itemKg, packKg, packFit, moveLoad, heroSpeed, packCapacity } from '../src/shared/formulas.js';

const here = path.dirname(fileURLToPath(import.meta.url));
export const PACK_VECTORS_PATH = path.join(here, 'vectors', 'pack.json');
const r6 = (x) => Math.round(x * 1e6) / 1e6;

export function buildPackVectors() {
    const items = ['log', 'plank', 'ore', 'ingot', 'stone', 'boulder', 'fish', 'fish_small', 'fish_big', 'seed', 'seed_rare', 'unknown_thing'];
    const kg = items.map((i) => [i, itemKg(i)]);                         // [предмет, кг за штуку]
    const fits = [];                                                     // [вместимость, груз, предмет, штук, влезет]
    for (const cap of [40, 50, 110]) for (const load of [0, 10, 33, 39.9, 40, 45]) for (const item of ['log', 'ore', 'fish', 'seed']) for (const qty of [1, 3, 10, 50])
        fits.push([cap, load, item, qty, packFit(cap, load, item, qty)]);
    const sets = [{ log: 3, plank: 2 }, { fish: 7, ore: 1, seed: 4 }, {}, { log: 5, stone: 1, unknown_thing: 2 }];
    const kgs = sets.map((s) => [s, packKg(s)]);                         // [набор, вес]
    const loads = [                                                      // [набор, действия, груз для проверки скорости]
        [{ log: 5, plank: 2 }, [{ type: 'drop', item: 'log', qty: 2 }], null],
        [{ log: 5 }, [{ type: 'drop', item: 'log', qty: 9 }, { type: 'chop_done' }], null],
        [{ log: 2, ore: 1 }, [{ type: 'drop', item: 'fish', qty: 1 }, { type: 'pickup', drop: 4 }], null],
    ].map(([s, a]) => [s, a, moveLoad(s, a)]);
    const speeds = [];                                                   // [груз, вместимость, скорость м/с]
    for (const cap of [40, 80]) for (const load of [0, 10, 20, 40, 60]) speeds.push([load, cap, r6(heroSpeed({ load, capacity: cap }))]);
    return { version: 1, note: 'kg: [предмет, кг]; fits: [вместимость, груз, предмет, штук, влезет]; kgs: [набор, вес]; loads: [набор, действия, груз]; speeds: [груз, вместимость, м/с]', kg, fits, kgs, loads, speeds, capacities: [1, 2, 8, 9].map((l) => [l, packCapacity(l)]) };
}
