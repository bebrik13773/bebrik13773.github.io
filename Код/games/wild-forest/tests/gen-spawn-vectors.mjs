// Запуск: node tests/gen-spawn-vectors.mjs  (после изменения алгоритма появления игрока)
import fs from 'node:fs';
import { buildSpawnVectors, SPAWN_VECTORS_PATH } from './spawn-lib.mjs';

const v = buildSpawnVectors();
const rows = (arr) => '[\n' + arr.map((r) => JSON.stringify(r)).join(',\n') + '\n]';
const out = `{\n"version": ${v.version},\n"note": ${JSON.stringify(v.note)},\n"alone": ${rows(v.alone)},\n"chain": ${rows(v.chain)},\n"levels": ${rows(v.levels)},\n"xps": ${rows(v.xps)}\n}\n`;
fs.writeFileSync(SPAWN_VECTORS_PATH, out);
console.log('записано', SPAWN_VECTORS_PATH, 'одиночных:', v.alone.length, 'цепочка:', v.chain.length);
