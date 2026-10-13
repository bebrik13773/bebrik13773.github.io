// Выбор места появления игрока (ДЛ-10). Чистая функция, детерминирована по id игрока и номеру попытки.
// Зеркало в api/forest/lib/spawn.php; сверка по tests/vectors/spawn.json. Сервер главный, клиент использует только для тестов.
import { hash } from './rng.js';
import { BIOME } from './worldgen.js';

// Значения совпадают с api/forest/config.php (world.*, spawn.*).
export const SPAWN = Object.freeze({
    minM: 600, maxM: 4000,       // «случайная дальность» от города
    coreMinM: 800, coreMaxM: 2500, coreShare: 70, // 70% попыток в «золотой» зоне
    wideMaxM: 6000,              // расширение диапазона после обычных попыток
    gapM: 150,                   // не ближе к чужому спавну
    ringM: 24,                   // вокруг точки только лес (нет воды и скал)
    flatM: 5, flatRelaxedM: 8,   // допустимый перепад высот в круге, м
    attempts: 30, attemptsWide: 30, attemptsFallback: 200,
    pondDistM: 20, pondRadiusM: 5, pondClearM: 8, clearingM: 12,
    salts: Object.freeze({ angle: 9101, pick: 9102, radius: 9103, pond: 9104 }),
});

const FOREST = new Set([BIOME.CONIFER, BIOME.BROADLEAF, BIOME.BIRCH]);
const RING = 8;
const COS = [];
const SIN = [];
for (let i = 0; i < RING; i += 1) { COS.push(Math.cos((i * Math.PI) / 4)); SIN.push(Math.sin((i * Math.PI) / 4)); }

/** Кандидат номер k: целые метры { x, z, r }. Фаза 0 — обычные попытки, 1 — расширенный диапазон. */
export function spawnCandidate(playerId, k, phase = 0) {
    const S = SPAWN.salts;
    const ang = hash(playerId, k, phase, S.angle) % 3600;
    const pick = hash(playerId, k, phase, S.pick) % 100;
    const rr = hash(playerId, k, phase, S.radius);
    let r;
    if (phase === 0) {
        r = pick < SPAWN.coreShare ? SPAWN.coreMinM + (rr % (SPAWN.coreMaxM - SPAWN.coreMinM + 1)) : SPAWN.minM + (rr % (SPAWN.maxM - SPAWN.minM + 1));
    } else {
        r = SPAWN.minM + (rr % (SPAWN.wideMaxM - SPAWN.minM + 1));
    }
    const a = (ang * Math.PI) / 1800;
    return { x: Math.floor(Math.cos(a) * r + 0.5), z: Math.floor(Math.sin(a) * r + 0.5), r };
}

/** Подходит ли точка: лес вокруг, ровно, внутри мира. */
export function spawnSuitable(wg, x, z, flatM) {
    if (!wg.isInsideWorld(x * 10, z * 10)) return false;
    if (!FOREST.has(wg.biomeAtDm(x * 10, z * 10))) return false;
    let lo = wg.heightAtDm(x * 10, z * 10);
    let hi = lo;
    for (let i = 0; i < RING; i += 1) {
        const px = Math.floor(x + COS[i] * SPAWN.ringM);
        const pz = Math.floor(z + SIN[i] * SPAWN.ringM);
        if (!FOREST.has(wg.biomeAtDm(px * 10, pz * 10))) return false;
        const h = wg.heightAtDm(px * 10, pz * 10);
        if (h < lo) lo = h;
        if (h > hi) hi = h;
    }
    return hi - lo <= flatM * 10; // перепад в дециметрах, целые числа
}

/**
 * Находит место. isFree(x, z, gap) — true, если в радиусе gap нет чужих спавнов (на сервере запрос к БД).
 * Возвращает { x, z, attempt } или null. attempt — сквозной номер попытки (для журнала).
 */
export function findSpawn(wg, playerId, isFree, skip = null) {
    const phases = [
        [0, SPAWN.attempts, SPAWN.flatM],
        [1, SPAWN.attemptsWide, SPAWN.flatRelaxedM],
        [1, SPAWN.attemptsFallback, SPAWN.flatRelaxedM * 2],
    ];
    let n = 0;
    let offset = 0;
    for (const [phase, count, flat] of phases) {
        for (let k = 0; k < count; k += 1) {
            const kk = k + offset;
            n += 1;
            const c = spawnCandidate(playerId, kk, phase);
            if (skip && skip(c.x, c.z)) continue;
            if (!spawnSuitable(wg, c.x, c.z, flat)) continue;
            if (!isFree(c.x, c.z, SPAWN.gapM)) continue;
            return { x: c.x, z: c.z, attempt: n };
        }
        offset += count;
    }
    return null;
}

/** Пруд и плотина рядом с поляной: центр пруда на pondDistM под детерминированным углом, плотина на дальнем от поляны краю. */
export function pondFor(playerId, x, z) {
    const S = SPAWN.salts;
    const deg = hash(playerId, 0, 0, S.pond) % 360;
    const a = (deg * Math.PI) / 180;
    const px = Math.floor(x + Math.cos(a) * SPAWN.pondDistM + 0.5);
    const pz = Math.floor(z + Math.sin(a) * SPAWN.pondDistM + 0.5);
    return { x: px, z: pz, r: SPAWN.pondRadiusM, deg, damX: Math.floor(px + Math.cos(a) * SPAWN.pondRadiusM + 0.5), damZ: Math.floor(pz + Math.sin(a) * SPAWN.pondRadiusM + 0.5) };
}

/** Ключ клетки спавна 150×150 м для уникальности на сервере. */
export function spawnCell(x, z) {
    return `${Math.floor(x / SPAWN.gapM)}:${Math.floor(z / SPAWN.gapM)}`;
}
