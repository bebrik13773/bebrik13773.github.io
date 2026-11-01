import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createNameGen } from '../src/shared/names.js';

const here = path.dirname(fileURLToPath(import.meta.url));
export const NAMES_JSON = path.join(here, '..', 'src', 'shared', 'names.json');
export const NAMES_VECTORS_PATH = path.join(here, 'vectors', 'names.json');
export const loadNamesData = () => JSON.parse(fs.readFileSync(NAMES_JSON, 'utf8'));
const SEEDS = [0, 1, 2, 3, 7, 42, 99, 1000, 65535, 65536, 2147000001, 2147483647, 2147483648, 4000000007, 4294967295];

export function buildNamesVectors() {
    const gen = createNameGen(loadNamesData());
    const rows = [];
    for (const kind of ['village', 'character', 'mentor']) {
        for (let s = 1; s <= 120; s += 1) for (let a = 0; a < 2; a += 1) rows.push([kind, s, a, gen.make(kind, s, a)]);
        for (const s of SEEDS) for (let a = 0; a < 3; a += 1) rows.push([kind, s, a, gen.make(kind, s, a)]);
    }
    return { version: 1, note: 'Золотые имена: [вид, seed, попытка, имя]. Менять только вместе с lib/names.php (node tests/gen-names-vectors.mjs).', names: rows };
}

// Типы окончаний: y -ый/-ая/-ое, o -ой/-ая/-ое, k -ий/-ая/-ое (после г, к, х), s -ий/-яя/-ее, z -ий/-ья/-ье, h -ий/-ая/-ее (после ж, ч, ш, щ)
const ENDS = { y: ['ый', 'ая', 'ое'], o: ['ой', 'ая', 'ое'], k: ['ий', 'ая', 'ое'], s: ['ий', 'яя', 'ее'], z: ['ий', 'ья', 'ье'], h: ['ий', 'ая', 'ее'] };
export function declineAdj(base, type) {
    const e = ENDS[type];
    if (!e) throw new Error(`тип ${type}`);
    if (!base.endsWith(e[0])) throw new Error(`${base} не оканчивается на ${e[0]}`);
    const stem = base.slice(0, -e[0].length);
    return [stem + e[0], stem + e[1], stem + e[2]];
}

