// Тест-векторы энергии (ДЛ-12): tests/vectors/energy.json читают JS (node --test) и PHP (tools/forest-selftest.php).
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { energyTick } from '../src/shared/formulas.js';

const here = path.dirname(fileURLToPath(import.meta.url));
export const ENERGY_VECTORS_PATH = path.join(here, 'vectors', 'energy.json');
const r6 = (x) => Math.round(x * 1e6) / 1e6;

export function buildEnergyVectors() {
    const ticks = []; // [энергия, макс, dt, путь м, стоял, дом, спал, энергия после, спит после]
    for (const energy of [0, 0.00005, 5, 19.9, 50, 100]) for (const dt of [0, 3, 10, 120, 900]) for (const dist of [0, 10, 100])
        for (const still of [0, 1]) for (const home of [0, 1]) for (const sleeping of [0, 1]) {
            const r = energyTick({ energy, max: 100, dt, dist, still: !!still, home: !!home, sleeping: !!sleeping });
            ticks.push([energy, 100, dt, dist, still, home, sleeping, r6(r.energy), r.sleeping ? 1 : 0]);
        }
    return { version: 1, note: 'ticks: [энергия, макс, dt, путь м, стоял, дом, спал, энергия после, спит после]', ticks };
}
