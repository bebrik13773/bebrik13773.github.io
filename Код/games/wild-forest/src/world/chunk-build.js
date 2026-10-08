import * as THREE from '../three.js';
import { CHUNK_M, KIND } from '../shared/worldgen.js';
import { hash } from '../shared/rng.js';
import { WORLD } from '../config.js';

// Построение данных чанка (ДЛ-06). Чистая функция без WebGL: возвращает типизированные массивы,
// из которых chunks.js собирает один меш (рельеф, деревья и камни в одной геометрии = один вызов отрисовки).
// Швы между чанками без щелей: вершины на границе считаются в одних и тех же мировых координатах,
// нормали берутся по высотам с запасом в одну вершину за границей чанка.

export const CHUNK_DM = CHUNK_M * 10;
export const SIZE_SCALE = Object.freeze([0.75, 1.0, 1.3]); // малое, среднее, большое дерево

export const chunkKey = (cx, cz) => `${cx}:${cz}`;

const linear = (hex) => { const c = new THREE.Color(hex); return [c.r, c.g, c.b]; };

// Цвета земли по биому (код биома = индекс): вода (дно), скалы, болото, хвойный, лиственный, берёзовая роща.
const BIOME_COLOR = [0x4f7f8c, 0x8c8b84, 0x68763a, 0x35603b, 0x5d9a43, 0x88ad4c].map(linear);
const ROCK_SLOPE = linear(0x7d7468);
const SAND = linear(0xcfc08a);
const SNOW = linear(0xe9f0f3);

const clamp01 = (v) => (v < 0 ? 0 : v > 1 ? 1 : v);

/** Цвет вершины рельефа: биом, крутизна (камень), берег (песок), снежные шапки на высоких скалах, лёгкая пятнистость. */
function writeTerrainColor(out, o, biome, hM, ny, D) {
    let [r, g, b] = BIOME_COLOR[biome];
    if (biome !== 0) {
        const slope = clamp01((0.86 - ny) / 0.22);
        if (slope > 0) { r += (ROCK_SLOPE[0] - r) * slope; g += (ROCK_SLOPE[1] - g) * slope; b += (ROCK_SLOPE[2] - b) * slope; }
        const above = hM - WORLD.waterLevelM;
        if (above < 1.2) {
            const w = clamp01(1 - above / 1.2) * 0.85;
            r += (SAND[0] - r) * w; g += (SAND[1] - g) * w; b += (SAND[2] - b) * w;
        }
        const snow = clamp01((hM - 24) / 4) * 0.9;
        if (snow > 0) { r += (SNOW[0] - r) * snow; g += (SNOW[1] - g) * snow; b += (SNOW[2] - b) * snow; }
    }
    const k = 0.9 + 0.2 * (D / 65535);
    out[o] = r * k; out[o + 1] = g * k; out[o + 2] = b * k;
}

/** Вершины и индексы рельефа. Возвращает { pos, nrm, col, vertsPerSide }. */
function buildTerrain(wg, cx, cz, seg) {
    const n = seg + 3; // сетка высот с запасом по одной вершине со всех сторон (для нормалей на швах)
    const stepDm = CHUNK_DM / seg;
    const stepM = stepDm / 10;
    const H = new Float32Array(n * n);
    const BIO = new Uint8Array(n * n);
    const DD = new Uint16Array(n * n);
    const sample = [0, 0, 0, 0];
    const baseX = cx * CHUNK_DM;
    const baseZ = cz * CHUNK_DM;
    for (let j = 0; j < n; j += 1) {
        const Z = baseZ + (j - 1) * stepDm;
        for (let i = 0; i < n; i += 1) {
            wg.sampleDm(baseX + (i - 1) * stepDm, Z, sample);
            const s = j * n + i;
            H[s] = sample[0] / 10;
            BIO[s] = sample[1];
            DD[s] = sample[3];
        }
    }
    const vs = seg + 1;
    const pos = new Float32Array(vs * vs * 3);
    const nrm = new Float32Array(vs * vs * 3);
    const col = new Float32Array(vs * vs * 3);
    for (let j = 0; j < vs; j += 1) {
        for (let i = 0; i < vs; i += 1) {
            const s = (j + 1) * n + (i + 1);
            const v = (j * vs + i) * 3;
            pos[v] = i * stepM; pos[v + 1] = H[s]; pos[v + 2] = j * stepM;
            const nx = H[s - 1] - H[s + 1];
            const nz = H[s - n] - H[s + n];
            const ny = 2 * stepM;
            const len = Math.hypot(nx, ny, nz);
            nrm[v] = nx / len; nrm[v + 1] = ny / len; nrm[v + 2] = nz / len;
            writeTerrainColor(col, v, BIO[s], H[s], ny / len, DD[s]);
        }
    }
    return { pos, nrm, col, vs };
}

/** Показывать ли дерево при данной плотности (детерминированно по id, чтобы лес не «мигал» между сборками). */
function keepByDensity(seed, cx, cz, ci, density) {
    if (density >= 1) return true;
    return hash(seed, cx, cz, 3000 + ci) % 1000 < density * 1000;
}

function inClearing(clearings, x, z) {
    for (let i = 0; i < clearings.length; i += 1) {
        const c = clearings[i];
        const dx = x - c.x;
        const dz = z - c.z;
        if (dx * dx + dz * dz < c.r * c.r) return true;
    }
    return false;
}

/**
 * Данные чанка.
 * @param wg генератор мира; models результат createModels(); preset пресет качества;
 * @param lod 0 простые деревья, 1 подробные; felled Set id срубленного/добытого; clearings [{x, z, r}] в метрах.
 */
export function buildChunkData({ wg, models, cx, cz, preset, lod = 0, felled, clearings }) {
    const seg = preset.terrainSeg;
    const terrain = buildTerrain(wg, cx, cz, seg);
    const vs = terrain.vs;
    const terrainVerts = vs * vs;
    const terrainIdx = seg * seg * 6;

    const originX = cx * CHUNK_M;
    const originZ = cz * CHUNK_M;
    const cells = wg.chunkCells(cx, cz);
    const instances = [];
    const stumps = [];
    let extraVerts = 0;
    for (const c of cells) {
        if (inClearing(clearings, c.x, c.z)) continue;
        const isTree = c.kind === KIND.TREE;
        if (isTree && !keepByDensity(wg.seed, cx, cz, c.ci, preset.treeDensity)) continue;
        if (felled.has(c.id)) {
            if (isTree) stumps.push({ id: c.id, x: c.x, y: c.y, z: c.z, s: SIZE_SCALE[c.size] });
            continue; // срублено или добыто: самого объекта нет
        }
        const h = hash(wg.seed, cx, cz, 1000 + c.ci);
        let tpl;
        let scale;
        if (isTree) {
            tpl = models.trees[c.species][lod];
            scale = SIZE_SCALE[c.size] * (0.92 + 0.16 * (((h >>> 18) & 255) / 255));
        } else if (c.kind === KIND.BOULDER) {
            tpl = (lod ? models.bouldersHi : models.boulders)[(h >>> 9) & 1];
            scale = 0.7 + 0.8 * (((h >>> 18) & 255) / 255);
        } else {
            tpl = models.ores[(h >>> 9) & 1];
            scale = 0.9 + 0.4 * (((h >>> 18) & 255) / 255);
        }
        instances.push({ c, tpl, scale, h });
        extraVerts += tpl.verts;
    }

    const totalVerts = terrainVerts + extraVerts;
    const pos = new Float32Array(totalVerts * 3);
    const nrm = new Float32Array(totalVerts * 3);
    const col = new Float32Array(totalVerts * 3);
    const index = totalVerts > 65535 ? new Uint32Array(terrainIdx + extraVerts) : new Uint16Array(terrainIdx + extraVerts);
    pos.set(terrain.pos); nrm.set(terrain.nrm); col.set(terrain.col);

    let ii = 0;
    for (let j = 0; j < seg; j += 1) {
        for (let i = 0; i < seg; i += 1) {
            const a = j * vs + i;
            const b = a + 1;
            const c = a + vs;
            const d = c + 1;
            index[ii++] = a; index[ii++] = c; index[ii++] = b;
            index[ii++] = b; index[ii++] = c; index[ii++] = d;
        }
    }

    const trees = new Map(); // id -> { v0, vN, lx, y, lz, wx, wz, sp, size }
    let treeCount = 0;
    let rockCount = 0;
    let vo = terrainVerts;
    for (const { c, tpl, scale, h } of instances) {
        const angle = ((h & 1023) / 1024) * Math.PI * 2;
        const cosA = Math.cos(angle);
        const sinA = Math.sin(angle);
        const isTree = c.kind === KIND.TREE;
        const tint = isTree && !wg.species[c.species].rare ? 0.88 + 0.24 * (((h >>> 10) & 255) / 255) : 1;
        const lx = c.x - originX;
        const lz = c.z - originZ;
        const y = c.y - 0.1; // корень чуть ниже земли, на склоне ствол не «висит»
        const tp = tpl.pos;
        const tn = tpl.nrm;
        const tc = tpl.col;
        for (let k = 0; k < tpl.verts; k += 1) {
            const s3 = k * 3;
            const d3 = (vo + k) * 3;
            const px = tp[s3];
            const pz = tp[s3 + 2];
            pos[d3] = lx + (px * cosA + pz * sinA) * scale;
            pos[d3 + 1] = y + tp[s3 + 1] * scale;
            pos[d3 + 2] = lz + (-px * sinA + pz * cosA) * scale;
            const nx = tn[s3];
            const nz = tn[s3 + 2];
            nrm[d3] = nx * cosA + nz * sinA;
            nrm[d3 + 1] = tn[s3 + 1];
            nrm[d3 + 2] = -nx * sinA + nz * cosA;
            col[d3] = tc[s3] * tint; col[d3 + 1] = tc[s3 + 1] * tint; col[d3 + 2] = tc[s3 + 2] * tint;
            index[ii++] = vo + k;
        }
        if (isTree) {
            treeCount += 1;
            trees.set(c.id, { v0: vo, vN: tpl.verts, lx, y: c.y, lz, wx: c.x, wz: c.z, sp: c.species, size: c.size });
        } else {
            rockCount += 1;
        }
        vo += tpl.verts;
    }

    return { cx, cz, lod, pos, nrm, col, index, vertexCount: totalVerts, trees, stumps, treeCount, rockCount };
}

/** Прячет дерево внутри готового буфера: все его вершины в одну точку (вырожденные треугольники ничего не рисуют). */
export function collapseTree(positions, rec) {
    for (let k = 0; k < rec.vN; k += 1) {
        const o = (rec.v0 + k) * 3;
        positions[o] = rec.lx;
        positions[o + 1] = rec.y - 50;
        positions[o + 2] = rec.lz;
    }
}
