// Запуск: node tests/gen-pack-vectors.mjs  (после изменения формул рюкзака)
import fs from 'node:fs';
import { buildPackVectors, PACK_VECTORS_PATH } from './pack-lib.mjs';

const v = buildPackVectors();
const rows = (arr) => '[\n' + arr.map((r) => JSON.stringify(r)).join(',\n') + '\n]';
const keys = ['kg', 'fits', 'kgs', 'loads', 'speeds', 'capacities'];
fs.writeFileSync(PACK_VECTORS_PATH, `{\n"version": ${v.version},\n"note": ${JSON.stringify(v.note)},\n` + keys.map((k) => `"${k}": ${rows(v[k])}`).join(',\n') + '\n}\n');
console.log('записано', PACK_VECTORS_PATH, 'посадок:', v.fits.length);
