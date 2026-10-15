// Запуск: node tests/gen-chop-vectors.mjs  (после изменения формул рубки)
import fs from 'node:fs';
import { buildChopVectors, CHOP_VECTORS_PATH } from './chop-lib.mjs';

const v = buildChopVectors();
const rows = (arr) => '[\n' + arr.map((r) => JSON.stringify(r)).join(',\n') + '\n]';
fs.writeFileSync(CHOP_VECTORS_PATH, `{\n"version": ${v.version},\n"note": ${JSON.stringify(v.note)},\n"trees": ${rows(v.trees)},\n"taps": ${rows(v.taps)}\n}\n`);
console.log('записано', CHOP_VECTORS_PATH, 'деревьев:', v.trees.length, 'тапов:', v.taps.length);
