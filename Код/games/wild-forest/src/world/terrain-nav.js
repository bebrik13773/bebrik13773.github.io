// Проходимость земли (ДЛ-08): вода и крутые склоны. Считается лениво по чанкам на сетке 1 м и кэшируется (рельеф не меняется),
// поэтому поиск пути не вызывает шум высот на каждый шаг. Чистая логика: нужен только генератор мира.
import { CHUNK_M } from '../shared/worldgen.js';
import { MOVE, WORLD } from '../config.js';

const NS = CHUNK_M + 1; // отсчётов высоты на сторону: центры клеток i = 0..32

export function createWalkGrid({ wg, waterLevel = WORLD.waterLevelM, cfg = MOVE, maxChunks = 96 }) {
    const cache = new Map(); // ключ чанка -> Uint8Array(32*32), 1 = можно идти
    const tanMax = Math.tan((cfg.maxSlopeDeg * Math.PI) / 180);
    const minH = waterLevel - cfg.waterTolerance;
    const key = (cx, cz) => (cx + 16384) * 32768 + (cz + 16384);

    const heightM = (x, z) => wg.heightAtDm(Math.round(x * 10), Math.round(z * 10)) / 10;

    function buildChunk(cx, cz) {
        const H = new Float32Array(NS * NS);
        const ox = cx * CHUNK_M + 0.5;
        const oz = cz * CHUNK_M + 0.5;
        for (let j = 0; j < NS; j += 1) for (let i = 0; i < NS; i += 1) H[j * NS + i] = heightM(ox + i, oz + j);
        const out = new Uint8Array(CHUNK_M * CHUNK_M);
        for (let j = 0; j < CHUNK_M; j += 1) {
            for (let i = 0; i < CHUNK_M; i += 1) {
                const h = H[j * NS + i];
                const slope = Math.hypot(H[j * NS + i + 1] - h, H[(j + 1) * NS + i] - h);
                const inside = wg.isInsideWorld(Math.round((ox + i) * 10), Math.round((oz + j) * 10));
                out[j * CHUNK_M + i] = inside && h >= minH && slope <= tanMax ? 1 : 0;
            }
        }
        return out;
    }

    function chunkGrid(cx, cz) {
        const k = key(cx, cz);
        let g = cache.get(k);
        if (g) return g;
        g = buildChunk(cx, cz);
        cache.set(k, g);
        if (cache.size > maxChunks) cache.delete(cache.keys().next().value); // самый старый
        return g;
    }

    return {
        /** Можно ли стоять в точке (метры). */
        walkable(x, z) {
            const fx = Math.floor(x);
            const fz = Math.floor(z);
            const cx = Math.floor(fx / CHUNK_M);
            const cz = Math.floor(fz / CHUNK_M);
            return chunkGrid(cx, cz)[(fz - cz * CHUNK_M) * CHUNK_M + (fx - cx * CHUNK_M)] === 1;
        },
        clear() { cache.clear(); },
        get size() { return cache.size; },
    };
}
