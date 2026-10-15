// Тест-векторы рубки (ДЛ-11): tests/vectors/chop.json читают JS (node --test) и PHP (tools/forest-selftest.php).
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { SPECIES_ORDER, treeHp, chopNeed, chopCost, treeLogs, axePower, tapTake } from '../src/shared/formulas.js';

const here = path.dirname(fileURLToPath(import.meta.url));
export const CHOP_VECTORS_PATH = path.join(here, 'vectors', 'chop.json');
const r6 = (x) => Math.round(x * 1e6) / 1e6;

export function buildChopVectors() {
    const trees = []; // [порода, размер, tier, уровень топора, hp, ударов, энергия, брёвен]
    for (const sp of SPECIES_ORDER) for (let size = 0; size < 3; size++) for (let tier = 0; tier <= 10; tier++) for (let axe = 1; axe <= 6; axe++) {
        const hp = treeHp(sp, size, tier);
        const need = chopNeed(hp, axePower(axe));
        trees.push([sp, size, tier, axe, r6(hp), need, r6(chopCost(need)), treeLogs(sp, size)]);
    }
    const taps = []; // [токены, ts мс, now мс, хотим, ok, токены после]
    for (const tokens of [0, 5, 20, 40]) for (const dt of [0, 500, 2000, 8000]) for (const want of [1, 10, 25, 40, 41]) {
        const r = tapTake(tokens, 1000, 1000 + dt, want);
        taps.push([tokens, 1000, 1000 + dt, want, r.ok ? 1 : 0, r6(r.tokens)]);
    }
    return { version: 1, note: 'trees: [порода, размер, tier, топор, hp, ударов, энергия, брёвен]; taps: [токены, ts, now, хотим, ok, токены после]', trees, taps };
}
