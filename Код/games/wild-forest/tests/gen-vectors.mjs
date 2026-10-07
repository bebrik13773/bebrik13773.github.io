// Запуск: node tests/gen-vectors.mjs  (после любого изменения алгоритма генерации мира)
import fs from 'node:fs';
import { buildVectors, VECTORS_PATH } from './vectors-lib.mjs';

const v = buildVectors();
fs.mkdirSync(new URL('./vectors/', import.meta.url), { recursive: true });
// Одна запись на строку: файл читаемый и дружит с git diff.
const lines = [];
const rows = (arr) => '[\n' + arr.map((r) => JSON.stringify(r)).join(',\n') + '\n]';
const out = '{\n"version": ' + v.version + ',\n"note": ' + JSON.stringify(v.note) + ',\n"formats": ' + JSON.stringify(v.formats) + ',\n"hashes": ' + rows(v.hashes) +
    ',\n"noises": ' + rows(v.noises) + ',\n"worlds": [\n' + v.worlds.map((w) => '{"seed": ' + w.seed + ',\n"points": ' + rows(w.points) + ',\n"cells": ' + rows(w.cells) + '}').join(',\n') + '\n]\n}\n';
fs.writeFileSync(VECTORS_PATH, out);
console.log('записано', VECTORS_PATH, (out.length / 1024).toFixed(0) + ' КБ', 'точек:', v.worlds[0].points.length, 'клеток:', v.worlds[0].cells.length);
