// Запуск: node tests/gen-energy-vectors.mjs  (после изменения формул энергии)
import fs from 'node:fs';
import { buildEnergyVectors, ENERGY_VECTORS_PATH } from './energy-lib.mjs';

const v = buildEnergyVectors();
const rows = (arr) => '[\n' + arr.map((r) => JSON.stringify(r)).join(',\n') + '\n]';
fs.writeFileSync(ENERGY_VECTORS_PATH, `{\n"version": ${v.version},\n"note": ${JSON.stringify(v.note)},\n"ticks": ${rows(v.ticks)}\n}\n`);
console.log('записано', ENERGY_VECTORS_PATH, 'шагов:', v.ticks.length);
