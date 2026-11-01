// Запуск: node tests/gen-names-vectors.mjs  (после изменения names.json или алгоритма)
import fs from 'node:fs';
import { buildNamesVectors, NAMES_VECTORS_PATH } from './names-lib.mjs';

const v = buildNamesVectors();
fs.writeFileSync(NAMES_VECTORS_PATH, `{\n"version": ${v.version},\n"note": ${JSON.stringify(v.note)},\n"names": [\n${v.names.map((r) => JSON.stringify(r)).join(',\n')}\n]\n}\n`);
console.log('записано', NAMES_VECTORS_PATH, 'имён:', v.names.length);
