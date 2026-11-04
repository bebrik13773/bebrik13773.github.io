// Запуск: node tests/gen-build-vectors.mjs  (после изменения правил построек)
import fs from 'node:fs';
import { buildBuildVectors, BUILD_VECTORS_PATH } from './build-lib.mjs';

const v = buildBuildVectors();
const rows = (arr) => '[\n' + arr.map((r) => JSON.stringify(r)).join(',\n') + '\n]';
const keys = ['costs', 'slopes', 'stages', 'refunds', 'refund_items', 'taps', 'takes', 'places', 'rots', 'circles'];
fs.writeFileSync(BUILD_VECTORS_PATH, `{\n"version": ${v.version},\n"note": ${JSON.stringify(v.note)},\n` + keys.map((k) => `"${k}": ${rows(v[k])}`).join(',\n') + '\n}\n');
console.log('записано', BUILD_VECTORS_PATH, 'мест:', v.places.length);
