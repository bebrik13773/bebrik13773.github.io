// Тест-векторы построек (ДЛ-16): tests/vectors/build.json читают JS (node --test) и PHP (tools/forest-selftest.php).
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BUILD_CATALOG, buildCost, buildCoins, slopeDeg, buildStage, refundK, refund, tapPlan, planTake, placeError, normRot, circlesOverlap } from '../src/shared/build.js';

const here = path.dirname(fileURLToPath(import.meta.url));
export const BUILD_VECTORS_PATH = path.join(here, 'vectors', 'build.json');
const r6 = (x) => Math.round(x * 1e6) / 1e6;

export function buildBuildVectors() {
    const costs = BUILD_CATALOG.map((s) => [s.type, buildCost(s), buildCoins(s)]);                        // [тип, ресурсы, монеты]
    const slopes = [];                                                                                   // [высоты, радиус, градусы]
    for (const r of [2.4, 3.2]) for (const hs of [[0, 0, 0, 0, 0], [0, 0.5, 0, 0, 0.2], [1, 1.5, 0.2, 1, 1], [0, 1.2, 0, 0, 0], [3, 0, 0, 0, 0]]) slopes.push([hs, r, r6(slopeDeg(hs, r))]);
    const stages = [];                                                                                   // [прогресс, нужно, стадия]
    for (const [p, n] of [[0, 150], [49, 150], [50, 150], [98, 150], [99, 150], [149, 150], [150, 150], [151, 150], [0, 0]]) stages.push([p, n, buildStage(p, n)]);
    const refunds = [];                                                                                  // [состояние, прогресс, нужно, доля|null]
    for (const [st, p, n] of [['blueprint', 0, 100], ['building', 20, 100], ['building', 21, 100], ['building', 99, 100], ['ready', 100, 100], ['flooded', 5, 100], ['building', 0, 0]]) refunds.push([st, p, n, refundK(st, p, n)]);
    const refundItems = [];                                                                              // [тип, доля, возврат]
    for (const type of ['warehouse', 'house', 'smelter']) for (const k of [1, 0.7, 0.5]) refundItems.push([type, k, refund(BUILD_CATALOG.find((s) => s.type === type), k)]);
    const taps = [];                                                                                     // [хочу, осталось, токенов, энергии, цена, принято, предел]
    for (const [w, left, tok, en, c] of [[10, 100, 40, 100, 0.3], [10, 3, 40, 100, 0.3], [50, 100, 12.9, 100, 0.3], [50, 100, 40, 2.0, 0.3], [5, 100, 0.5, 100, 0.3], [5, 100, 40, 0, 0.3], [20, 100, 5, 1.2, 0.3]]) {
        const t = tapPlan(w, left, tok, en, c);
        taps.push([w, left, tok, en, c, t.n, t.limit]);
    }
    const stocks = [
        { pack: { log: 10, plank: 2 }, crate: { log: 30 } },
        { pack: { log: 10 }, crate: { log: 5, ingot: 4 }, 'bld:12': { log: 100 }, 'bld:3': { plank: 40 } },
        { pack: {} },
        { 'bld:10': { log: 7 }, 'bld:9': { log: 7 }, pack: { log: 1 } },
    ];
    const takes = [];                                                                                    // [запасы, нужно, план|null]
    for (const st of stocks) for (const need of [{ log: 40 }, { log: 15, plank: 6 }, { ingot: 6 }, { log: 14 }]) takes.push([st, need, planTake(st, need)]);
    const base = { level: 5, unlock: 1, total: 0, max_total: 12, same: 0, max_same: 1, inside_world: true, city: false, dist_village: 20, foreign_dist: 1e9, water: false, slope_deg: 3, trees: 0, overlap: false };
    const cases = [
        {}, { level: 0 }, { total: 12 }, { same: 1 }, { inside_world: false }, { city: true }, { dist_village: 60 }, { dist_village: 60.01 }, { foreign_dist: 40 }, { foreign_dist: 39.9 },
        { water: true }, { slope_deg: 12 }, { slope_deg: 12.01 }, { trees: 2 }, { overlap: true }, { water: true, trees: 1, overlap: true }, { level: 0, total: 99 },
    ];
    const places = cases.map((c) => { const full = { ...base, ...c }; return [full, placeError(full)]; });      // [вход, причина|null]
    const rots = [[0], [7], [8], [14], [15], [22], [23], [350], [352], [353], [359], [360], [375], [-15], [-8], [-7], [725]].map(([d]) => [d, normRot(d)]);
    const circles = [[0, 0, 2, 5, 0, 2, 0.4, circlesOverlap(0, 0, 2, 5, 0, 2, 0.4)], [0, 0, 2, 4.4, 0, 2, 0.4, circlesOverlap(0, 0, 2, 4.4, 0, 2, 0.4)], [0, 0, 2, 4.3, 0, 2, 0.4, circlesOverlap(0, 0, 2, 4.3, 0, 2, 0.4)], [1, 1, 3, 1, 1, 3, 0.4, circlesOverlap(1, 1, 3, 1, 1, 3, 0.4)]];
    return {
        version: 1,
        note: 'costs: [тип, ресурсы, монеты]; slopes: [высоты, радиус, градусы]; stages: [прогресс, нужно, стадия]; refunds: [состояние, прогресс, нужно, доля|null]; refund_items: [тип, доля, {items, coins}]; taps: [хочу, осталось, токенов, энергии, цена, принято, предел]; takes: [запасы, нужно, план|null]; places: [вход, причина|null]; rots: [градусы, нормализовано]; circles: [x1, z1, r1, x2, z2, r2, зазор, пересекаются]',
        costs, slopes, stages, refunds, refund_items: refundItems, taps, takes, places, rots, circles,
    };
}
